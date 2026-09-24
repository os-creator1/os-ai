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
                'lock_version' => 0,
            ])->save();

            return $connection;
        });

        return $this->onboardingLink($connection, $refreshUrl, $returnUrl);
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
     * Asked before every checkout and every plan change; never cached.
     */
    public function isChargeReady(Workspace $agencyWorkspace): bool
    {
        return $this->liveConnection($agencyWorkspace)?->canCharge() === true;
    }

    /**
     * The connection a charge must run through, or a refusal that says which
     * of the two problems it is — not connected at all, versus connected but
     * not yet able to take money.
     *
     * @throws AgencyBillingException
     */
    public function chargeableConnection(Workspace $agencyWorkspace): AgencyStripeConnection
    {
        $connection = $this->liveConnection($agencyWorkspace)
            ?? throw AgencyBillingException::because(AgencyBillingException::NO_CONNECTION);

        if (! $connection->canCharge()) {
            throw AgencyBillingException::because(AgencyBillingException::CONNECTION_NOT_READY);
        }

        return $connection;
    }

    /**
     * Resolve the Agency connection that owns one connected account id. Used by
     * webhook intake (§C4.1) to prove an inbound Connect event belongs to an
     * Agency we know about, before anything is resolved or applied.
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
            'charges_enabled' => $snapshot->chargesEnabled,
            'payouts_enabled' => $snapshot->payoutsEnabled,
            'details_submitted' => $snapshot->detailsSubmitted,
            'requirements_disabled_reason' => $snapshot->requirementsDisabledReason,
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
