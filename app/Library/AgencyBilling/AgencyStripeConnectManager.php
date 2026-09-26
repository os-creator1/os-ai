<?php

namespace App\Library\AgencyBilling;

use App\Enums\AgencyBilling\AgencyStripeConnectionStatus;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Models\AgencyStripeConnection;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Lane C §C5.1 — connecting the Stripe account that receives ONE Agency's SaaS
 * revenue.
 *
 * THE SHAPE IS LANE B'S, PROVEN, AND DELIBERATELY NOT ITS CODE. Contract 17's
 * `StripeConnectManager` established this exact sequence — create the account,
 * hand back a single-use hosted onboarding link, re-read capabilities rather
 * than trusting a stored flag, compare-and-set the local row, disconnect
 * without deleting history — and it works. What lane C may not do is reuse its
 * ROWS: a `BusinessStripeConnection` is lane B's commercial identity, and
 * making it the implicit authority for Agency subscription revenue is exactly
 * the conflation §C1 forbids. The same physical Stripe account may serve both
 * purposes; the records stay separate so no lane-B document payment can ever
 * be read as Agency SaaS revenue.
 *
 * NO NETWORK UNDER A LOCK (§C4). Every provider call happens outside every
 * transaction, and the local write is a compare-and-set afterwards.
 *
 * AUTHORIZATION IS THE AGENCY WORKSPACE OWNER'S ALONE, asserted here rather
 * than in a controller so no future call site can skip it. Not Admin, not
 * Staff: this is the account that receives the Agency's money, and Blueprint
 * §2 reserves financial configuration to the owner while §26 keeps Staff out of
 * billing entirely. Agency team membership grants client MANAGEMENT, never
 * revenue configuration.
 */
final class AgencyStripeConnectManager
{
    /**
     * Task 3 — fail-closed freshness at the actual financial boundary. How
     * long a chargeable connection's STORED readiness may be trusted before
     * a real financial operation (checkout, plan-price creation/publish,
     * upgrade/downgrade) re-verifies it against the provider, rather than
     * relying on an arbitrarily stale Active row that has since been
     * revoked or gone Incompatible. See chargeableConnection() below.
     */
    private const CHARGE_READINESS_FRESHNESS_SECONDS = 900;

    public function __construct(private readonly AgencyStripeGateway $gateway)
    {
    }

    /** Statuses that mean "this is the Agency's current connection". */
    private static function currentStatuses(): array
    {
        return [
            AgencyStripeConnectionStatus::Pending->value,
            AgencyStripeConnectionStatus::Onboarding->value,
            AgencyStripeConnectionStatus::Active->value,
            AgencyStripeConnectionStatus::Restricted->value,
            AgencyStripeConnectionStatus::Incompatible->value,
        ];
    }

    /**
     * §C3.1 — the Agency's one current connection, whatever its readiness, or
     * null. Disconnected rows are history and are never returned here.
     */
    public function liveConnection(Workspace $agencyWorkspace): ?AgencyStripeConnection
    {
        return AgencyStripeConnection::query()
            ->where('agency_workspace_id', $agencyWorkspace->id)
            ->whereIn('status', self::currentStatuses())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Every connection this Agency has ever had, newest first. Disconnecting
     * preserves history; it never deletes.
     *
     * @return Collection<int, AgencyStripeConnection>
     */
    public function history(Workspace $agencyWorkspace): Collection
    {
        return AgencyStripeConnection::query()
            ->where('agency_workspace_id', $agencyWorkspace->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * §C5.1 — create the Agency's connected account and return the
     * Stripe-hosted onboarding URL.
     *
     * @throws AgencyBillingException
     */
    public function connect(int $actorUserId, Workspace $agencyWorkspace, string $country, ?string $email, string $refreshUrl, string $returnUrl): string
    {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);

        // A cheap pre-check so the ordinary "already connected" case never
        // creates an orphaned Stripe account. The authoritative check is the
        // one under the Workspace lock below.
        if ($this->liveConnection($agencyWorkspace) !== null) {
            throw AgencyBillingException::because(AgencyBillingException::ALREADY_CONNECTED);
        }

        // ---- network, outside every transaction and lock ------------------
        $snapshot = $this->gateway->createAccount($country, $email, (string) $agencyWorkspace->uid);

        // ---- apply locally -------------------------------------------------
        $connection = DB::transaction(function () use ($agencyWorkspace, $snapshot, $actorUserId) {
            $locked = Workspace::query()->whereKey($agencyWorkspace->id)->lockForUpdate()->firstOrFail();

            // §C3.1's "one active connection per Agency" invariant. MySQL
            // cannot express a partial unique index, so the Workspace row lock
            // is what serialises two simultaneous connect attempts.
            if ($this->liveConnection($locked) !== null) {
                throw AgencyBillingException::because(AgencyBillingException::ALREADY_CONNECTED);
            }

            $connection = new AgencyStripeConnection([
                'agency_workspace_id' => $locked->id,
                'stripe_account_id' => $snapshot->stripeAccountId,
            ]);

            $connection->forceFill($this->providerColumns($snapshot, AgencyStripeConnectionStatus::Onboarding) + [
                'connected_by_user_id' => $actorUserId,
                'connected_at' => now(),
                'last_synced_at' => now(),
                'lock_version' => 0,
            ])->save();

            return $connection;
        });

        return $this->onboardingLink($connection, $refreshUrl, $returnUrl);
    }

    /**
     * "Connect existing Stripe account" — the Stripe-hosted OAuth
     * authorization URL. The account itself is never typed in by hand; the
     * Agency owner authenticates on Stripe's own page and Stripe redirects
     * back with a one-time code, which `connectExisting()` below exchanges.
     *
     * @throws AgencyBillingException
     */
    public function oauthAuthorizeUrl(int $actorUserId, Workspace $agencyWorkspace, string $state, string $redirectUri): string
    {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);

        if ($this->liveConnection($agencyWorkspace) !== null) {
            throw AgencyBillingException::because(AgencyBillingException::ALREADY_CONNECTED);
        }

        return $this->gateway->oauthAuthorizeUrl($state, $redirectUri);
    }

    /**
     * "Connect existing Stripe account" — completes the OAuth flow
     * `oauthAuthorizeUrl()` started. The exchanged account's REAL, current
     * Stripe state is independently re-read (`retrieveAccount()`, never
     * trusted from the token-exchange response alone) and judged by the
     * exact same fail-closed readiness policy `statusFor()` applies to a
     * brand-new account — an existing account brought in through OAuth can
     * carry any commercial configuration its own history gave it, so this is
     * the one place that decides whether it may ever be enabled for Agency
     * customer payments. An incompatible account is still recorded (for a
     * truthful history and an explicit reason on screen), but
     * `AgencyStripeConnectionStatus::Incompatible` can never charge — see
     * that case's own docblock for why this can never self-heal into Active
     * the way Restricted can.
     *
     * @throws AgencyBillingException
     */
    public function connectExisting(int $actorUserId, Workspace $agencyWorkspace, string $authorizationCode): AgencyStripeConnection
    {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);

        if ($this->liveConnection($agencyWorkspace) !== null) {
            throw AgencyBillingException::because(AgencyBillingException::ALREADY_CONNECTED);
        }

        // ---- network, outside every transaction and lock -------------------
        $connectedAccountId = $this->gateway->exchangeOAuthCode($authorizationCode);
        $snapshot = $this->gateway->retrieveAccount($connectedAccountId);

        // ---- apply locally ---------------------------------------------------
        return DB::transaction(function () use ($agencyWorkspace, $connectedAccountId, $snapshot, $actorUserId) {
            $locked = Workspace::query()->whereKey($agencyWorkspace->id)->lockForUpdate()->firstOrFail();

            if ($this->liveConnection($locked) !== null) {
                throw AgencyBillingException::because(AgencyBillingException::ALREADY_CONNECTED);
            }

            $connection = new AgencyStripeConnection([
                'agency_workspace_id' => $locked->id,
                'stripe_account_id' => $connectedAccountId,
            ]);

            $connection->forceFill($this->providerColumns($snapshot, $this->statusFor($snapshot)) + [
                'connected_by_user_id' => $actorUserId,
                'connected_at' => now(),
                // The snapshot above IS a fresh provider read taken this
                // instant, so recording it as already-synced avoids an
                // immediately-redundant re-verification the very next time
                // chargeableConnection() is asked for this connection.
                'last_synced_at' => now(),
                'lock_version' => 0,
            ])->save();

            return $connection;
        });
    }

    /**
     * §C5.1 — a fresh hosted onboarding link for a connection that has not
     * finished. Account links are single-use, so resuming always mints a new
     * one rather than storing the old URL.
     *
     * @throws AgencyBillingException
     */
    public function resumeOnboarding(int $actorUserId, Workspace $agencyWorkspace, string $refreshUrl, string $returnUrl): string
    {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);

        $connection = $this->liveConnection($agencyWorkspace)
            ?? throw AgencyBillingException::because(AgencyBillingException::NO_CONNECTION);

        return $this->onboardingLink($connection, $refreshUrl, $returnUrl);
    }

    /**
     * §C5.1 — re-read capability state from the provider and apply it
     * optimistically. Readiness is rechecked, never assumed from a flag that
     * may be older than the operation about to rely on it.
     *
     * @throws AgencyBillingException
     */
    public function syncFromProvider(int $actorUserId, Workspace $agencyWorkspace): AgencyStripeConnection
    {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);

        $connection = $this->liveConnection($agencyWorkspace)
            ?? throw AgencyBillingException::because(AgencyBillingException::NO_CONNECTION);

        return $this->refreshFromProvider($connection, $agencyWorkspace);
    }

    /**
     * Task 3 — the shared network-then-compare-and-set step behind BOTH
     * syncFromProvider() (owner-initiated, from the status page) and
     * chargeableConnection()'s own freshness re-check below (financial-
     * boundary-initiated). One implementation, so a controller-compatibility
     * or status-mapping fix made here is never made in only one of the two
     * places that need it.
     */
    private function refreshFromProvider(AgencyStripeConnection $connection, Workspace $agencyWorkspace): AgencyStripeConnection
    {
        $observedVersion = (int) $connection->lock_version;

        // ---- network, outside every transaction and lock ------------------
        // The account id comes from OUR row, never from the request, so a
        // caller cannot point a sync at somebody else's account.
        $snapshot = $this->gateway->retrieveAccount((string) $connection->stripe_account_id);

        // ---- compare-and-set ----------------------------------------------
        AgencyStripeConnection::query()
            ->whereKey($connection->id)
            ->where('agency_workspace_id', $agencyWorkspace->id)
            ->where('lock_version', $observedVersion)
            ->whereIn('status', self::currentStatuses())
            ->update($this->providerColumns($snapshot, $this->statusFor($snapshot)) + [
                'lock_version' => $observedVersion + 1,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]);

        // Zero rows updated means a concurrent sync or a disconnect won the
        // race. Its result stands; we return current truth rather than
        // overwriting a newer observation with an older one.
        return $connection->refresh();
    }

    /**
     * §C5.1 — disconnect makes the CURRENT row terminal, freeing the Agency to
     * connect a different account later. It rewrites no identifier and deletes
     * nothing, so every historical row — and every subscription's reference to
     * one — keeps its exact meaning.
     *
     * NO PROVIDER CALL, AND DELIBERATELY NO CANCELLATION OF EXISTING CLIENT
     * SUBSCRIPTIONS. Those are billing relationships between the Agency and its
     * own customers, on the Agency's own Stripe account. We do not have the
     * right to end them on the Agency's behalf, and doing so silently would be
     * a financial action nobody here is authorized to take. What disconnecting
     * does do is refuse every NEW lane-C charge immediately, and leave each
     * client's own lifecycle exactly where it is until real provider truth
     * moves it.
     *
     * Task 2, REVIEWED FOR OAUTH-CONNECTED ACCOUNTS SPECIFICALLY — this
     * method is identical regardless of how the connection was made
     * (createAccount() or the OAuth connectExisting() above), because the
     * SAFETY property it exists for (canCharge() becomes false immediately)
     * is identical either way. It intentionally does not also call Stripe's
     * `oauth/deauthorize` — that would revoke THIS PLATFORM's OAuth grant at
     * Stripe for an account the Agency may still want reachable from their
     * own Dashboard, and is a real business decision (not this app's alone)
     * whether local disconnect should also sever platform-level API access
     * versus leaving that to the Agency's own Stripe settings or to a
     * genuine `account.application.deauthorized` event (markRevokedByProvider()
     * above) if they choose to revoke it themselves.
     *
     * @throws AgencyBillingException
     */
    public function disconnect(int $actorUserId, Workspace $agencyWorkspace): AgencyStripeConnection
    {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);

        return DB::transaction(function () use ($agencyWorkspace, $actorUserId) {
            $connection = AgencyStripeConnection::query()
                ->where('agency_workspace_id', $agencyWorkspace->id)
                ->whereIn('status', self::currentStatuses())
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                throw AgencyBillingException::because(AgencyBillingException::NO_CONNECTION);
            }

            $connection->forceFill([
                'status' => AgencyStripeConnectionStatus::Disconnected->value,
                'disconnected_by_user_id' => $actorUserId,
                'disconnected_at' => now(),
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'lock_version' => (int) $connection->lock_version + 1,
            ])->save();

            return $connection->refresh();
        });
    }

    /**
     * §C5.1 — may a NEW lane-C charge be taken for this Agency right now?
     * Deliberately LOCAL-ONLY (no provider call) — this answers page
     * renders and status displays, which must stay cheap; the boundary that
     * actually spends money is chargeableConnection() below, which re-
     * verifies freshness itself.
     */
    public function isChargeReady(Workspace $agencyWorkspace): bool
    {
        return $this->liveConnection($agencyWorkspace)?->canCharge() === true;
    }

    /**
     * The connection a charge must run through, or a refusal that says which
     * of the two problems it is — not connected at all, versus connected but
     * not yet able to take money. Re-verifies against the provider itself
     * when the stored readiness is stale (Task 3) — never on every page
     * render (that stays isChargeReady() above), only at this financial
     * boundary, and at most once per call here regardless of how many
     * checks the caller's own operation makes afterwards.
     *
     * @throws AgencyBillingException
     */
    public function chargeableConnection(Workspace $agencyWorkspace): AgencyStripeConnection
    {
        $connection = $this->liveConnection($agencyWorkspace)
            ?? throw AgencyBillingException::because(AgencyBillingException::NO_CONNECTION);

        if ($this->isStaleForCharging($connection)) {
            $connection = $this->refreshFromProvider($connection, $agencyWorkspace);
        }

        if (! $connection->canCharge()) {
            throw AgencyBillingException::because(AgencyBillingException::CONNECTION_NOT_READY);
        }

        return $connection;
    }

    private function isStaleForCharging(AgencyStripeConnection $connection): bool
    {
        return $connection->last_synced_at === null
            || $connection->last_synced_at->lt(now()->subSeconds(self::CHARGE_READINESS_FRESHNESS_SECONDS));
    }

    /**
     * Task 2 — `account.application.deauthorized`: the Agency revoked THIS
     * PLATFORM's access to their Stripe account, at Stripe, not from inside
     * this application. Provider-initiated, so there is no owner actor to
     * assert and no provider call to make (revoked access means we may no
     * longer be authorized to make one) — this only updates the local row,
     * which is what stops any NEW charge from ever being initiated through
     * it again. Same non-interference posture as disconnect(): existing
     * client subscriptions are left exactly where they are, and nothing is
     * deleted — only marked Disconnected, preserving history.
     *
     * Idempotent: a second delivery of the same event (or one that arrives
     * after the Agency has already disconnected locally, or already
     * reconnected a DIFFERENT account) finds no row to touch and is a no-op
     * — `stripe_account_id` names the specific account Stripe revoked, and
     * only a row for THAT exact account, still in a current status, is
     * ever affected.
     */
    public function markRevokedByProvider(string $connectedAccountId): void
    {
        if (trim($connectedAccountId) === '') {
            return;
        }

        DB::transaction(function () use ($connectedAccountId) {
            $connection = AgencyStripeConnection::query()
                ->where('stripe_account_id', $connectedAccountId)
                ->whereIn('status', self::currentStatuses())
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return;
            }

            $connection->forceFill([
                'status' => AgencyStripeConnectionStatus::Disconnected->value,
                'disconnected_by_user_id' => null,
                'disconnected_at' => now(),
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'requirements_disabled_reason' => 'provider_revoked_access',
                'lock_version' => (int) $connection->lock_version + 1,
            ])->save();
        });
    }

    /**
     * The Agency's CURRENT connection for one account id — the one authorized
     * to take new charges, subject to its own readiness.
     *
     * Deliberately NOT what webhook resolution uses: see
     * `findOwningConnectionForEvent()` for why those are two different
     * questions.
     */
    public function findByConnectedAccountId(string $connectedAccountId): ?AgencyStripeConnection
    {
        if (trim($connectedAccountId) === '') {
            return null;
        }

        return AgencyStripeConnection::query()
            ->where('stripe_account_id', $connectedAccountId)
            ->whereIn('status', self::currentStatuses())
            ->orderByDesc('id')
            ->first();
    }

    /**
     * §C4.1 — WHICH AGENCY DOES THIS EVENT BELONG TO? Including connections
     * that have since been disconnected.
     *
     * TWO DIFFERENT QUESTIONS, AND CONFLATING THEM BROKE A REAL CUSTOMER.
     *
     *   A. "May this connection take a NEW charge?" — `chargeableConnection()`,
     *      strict, current statuses only, `charges_enabled` re-read from the
     *      provider. A disconnected account must never sell anything again.
     *
     *   B. "Whose is this event?" — this method. `disconnect()` deliberately
     *      does NOT cancel the agency's existing client subscriptions, because
     *      ending somebody's billing relationships on their behalf is not ours
     *      to do. Those subscriptions keep renewing, failing and cancelling at
     *      the provider, and every one of those events names the account that
     *      has since been disconnected. Resolving identity through the CURRENT
     *      list meant those events failed as "unknown account" and each
     *      affected client's independent lifecycle silently stopped updating —
     *      a client could be charged, or cancelled, and the product would never
     *      learn of it.
     *
     * THIS GRANTS NOTHING. It answers ownership and stops. It does not
     * reconnect, re-enable or resurrect the connection, it does not make it
     * chargeable, and it is not consulted by any path that sells. The job then
     * proves, independently, that the event's account matches the stored
     * subscription's account, that the subscription belongs to THIS agency,
     * that the provider identities line up, and that the subscription is not a
     * retired one — so an arbitrary Stripe account named in an event still
     * resolves to nothing.
     *
     * A disconnected account that the agency has also revoked at Stripe will
     * fail later, when provider truth cannot be retrieved, and fails CLOSED
     * with a reason code rather than being taken from the payload.
     */
    public function findOwningConnectionForEvent(string $connectedAccountId): ?AgencyStripeConnection
    {
        if (trim($connectedAccountId) === '') {
            return null;
        }

        return AgencyStripeConnection::query()
            ->where('stripe_account_id', $connectedAccountId)
            // Newest first: if an agency ever reconnected the same account, the
            // current row wins, and a historical one still answers when it is
            // all there is.
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @throws AgencyBillingException
     */
    private function onboardingLink(AgencyStripeConnection $connection, string $refreshUrl, string $returnUrl): string
    {
        return $this->gateway->createOnboardingLink(
            (string) $connection->stripe_account_id,
            $refreshUrl,
            $returnUrl,
        );
    }

    /**
     * Provider truth, normalized into columns. Never mass-assignable on the
     * model, so only this class can write it.
     *
     * @return array<string, mixed>
     */
    private function providerColumns(AgencyAccountSnapshot $snapshot, AgencyStripeConnectionStatus $status): array
    {
        return [
            'status' => $status->value,
            // An Incompatible account must never read as chargeable through
            // this column either — canCharge() checks both the status AND
            // this flag, and a controller mismatch means this platform
            // cannot safely rely on the provider's own charges_enabled
            // value for its intended purpose.
            'charges_enabled' => $status === AgencyStripeConnectionStatus::Incompatible ? false : $snapshot->chargesEnabled,
            'payouts_enabled' => $snapshot->payoutsEnabled,
            'details_submitted' => $snapshot->detailsSubmitted,
            // Reused for BOTH provider requirement codes AND a controller
            // incompatibility code — both answer the identical operator
            // question ("why can't this account charge yet"), and both are
            // machine codes, never provider prose.
            'requirements_disabled_reason' => $status === AgencyStripeConnectionStatus::Incompatible
                ? $snapshot->incompatibilityReason()
                : $snapshot->requirementsDisabledReason,
            'default_currency' => $snapshot->defaultCurrency,
        ];
    }

    /**
     * The provider's capability state, read as a lane-C status.
     *
     * `charges_enabled` is the only thing that makes an account Active here,
     * because taking money is the only thing lane C needs it for. An account
     * that has submitted its details but is held by a requirement is
     * Restricted, not Active — and an operator can see exactly which
     * requirement code from `requirements_disabled_reason`.
     */
    private function statusFor(AgencyAccountSnapshot $snapshot): AgencyStripeConnectionStatus
    {
        // Checked FIRST, and unconditionally on `chargesEnabled` — the
        // objective's own fail-closed policy: an account that CAN charge but
        // does so on the wrong commercial terms (this platform paying
        // Stripe's fees, or bearing payment-loss liability, or the Agency
        // never being handed the full Dashboard) must never read as Active,
        // and never will on its own, because `stripe_dashboard.type` cannot
        // change after account creation.
        if (! $snapshot->isCompatibleController()) {
            return AgencyStripeConnectionStatus::Incompatible;
        }

        if ($snapshot->chargesEnabled) {
            return AgencyStripeConnectionStatus::Active;
        }

        if ($snapshot->requirementsDisabledReason !== null) {
            return AgencyStripeConnectionStatus::Restricted;
        }

        return $snapshot->detailsSubmitted
            ? AgencyStripeConnectionStatus::Restricted
            : AgencyStripeConnectionStatus::Onboarding;
    }

    /**
     * §C5.1 — THE authorization rule for every lane-C revenue configuration
     * action, stated once, here, rather than in each controller.
     *
     * @throws AgencyBillingException
     */
    private function assertAgencyOwner(int $actorUserId, Workspace $agencyWorkspace): void
    {
        if ((int) $agencyWorkspace->owner_user_id !== $actorUserId) {
            throw AgencyBillingException::because(AgencyBillingException::CONSENT_NOT_AUTHORIZED);
        }
    }
}
