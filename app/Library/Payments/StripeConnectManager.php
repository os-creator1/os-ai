<?php

namespace App\Library\Payments;

use App\Enums\Documents\StripeConnectionStatus;
use App\Exceptions\Payments\StripeConnectException;
use App\Models\Business;
use App\Models\BusinessStripeConnection;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 17 §12.D / §5.7 — the lane-B connected-account
 * lifecycle: connect, resume onboarding, sync capabilities, disconnect.
 *
 * CONNECTIONS ARE HISTORICAL RECORDS. A Business may accumulate any number of
 * rows over its lifetime and holds at most ONE live one. That is enforced by
 * the database — `active_business_id` is a STORED generated column yielding
 * business_id only in the four live states, with a UNIQUE key — and this
 * class never writes that column. Disconnecting makes a row terminal, which
 * frees the slot; connecting again creates a NEW row. `stripe_account_id` is
 * NEVER rewritten on an existing row, so a historical payment's connection id
 * keeps meaning exactly what it meant (§5.7).
 *
 * NO PROVIDER CALL UNDER A LOCK OR INSIDE A TRANSACTION (§7). Every method
 * here is the same three-step shape: a short committed transaction for local
 * intent, the network call outside any transaction, then a second short
 * transaction to apply the result. A test asserts DB::transactionLevel() is 0
 * at every gateway call.
 *
 * CAPABILITY SYNC IS OPTIMISTIC (§12.D). The apply step is a compare-and-set
 * on `lock_version` scoped to the row AND its Business AND a live status, so
 * two concurrent syncs cannot interleave into a half-applied state, and a
 * sync can never revive a disconnected row.
 *
 * OWNER-ONLY (§6.2). Establishing or terminating a Business's Stripe
 * relationship is financial consent, so it belongs to an owner rather than to
 * staff. Ownership is re-derived from persistence on every call; nothing is
 * taken from the request.
 */
final class StripeConnectManager
{
    public function __construct(private readonly StripeConnectGateway $gateway)
    {
    }

    /** Whether the platform key is live — bound into every OAuth attempt. */
    public function platformIsLive(): bool
    {
        return $this->gateway->platformIsLive();
    }

    /**
     * "Connect existing Stripe account" — the Stripe-hosted OAuth URL for a
     * Business that holds no live connection. The caller supplies the random
     * `state` (it also stores the binding); the account is chosen by the owner
     * on Stripe's own page and never typed in or supplied by the browser.
     *
     * @throws StripeConnectException
     */
    public function oauthAuthorizeUrl(int $actorUserId, Business $business, string $state, string $redirectUri): string
    {
        \App\Library\NicheBlueprint\Safety\BlueprintSafetyGuard::check('provider_oauth');

        $business = $this->ownedBusiness($actorUserId, $business);

        if ($this->liveConnection($business) !== null) {
            throw StripeConnectException::alreadyConnected();
        }

        return $this->gateway->oauthAuthorizeUrl($state, $redirectUri);
    }

    /**
     * Completes the OAuth flow. The account id is the one Stripe returned for
     * the one-time code — never anything the browser sent — and the account is
     * independently re-read from Stripe to learn charge/payout capability.
     *
     * Idempotent: a retry that finds THIS Business already live on the SAME
     * account returns that row without creating another. A different account,
     * or one live elsewhere (another Business or an Agency lane), is refused.
     *
     * @param  bool  $expectedLive  the mode recorded when the attempt started
     *
     * @throws StripeConnectException
     */
    public function connectExisting(int $actorUserId, Business $business, string $authorizationCode, bool $expectedLive): BusinessStripeConnection
    {
        \App\Library\NicheBlueprint\Safety\BlueprintSafetyGuard::check('provider_oauth');

        $business = $this->ownedBusiness($actorUserId, $business);

        if ($this->liveConnection($business) !== null) {
            throw StripeConnectException::alreadyConnected();
        }

        // ---- network, outside every transaction and lock (§7) -------------
        try {
            $grant = $this->gateway->exchangeOAuthCode($authorizationCode);
        } catch (StripeConnectException $e) {
            throw $e->reason === StripeConnectException::PROVIDER_FAILED ? StripeConnectException::oauthRejected() : $e;
        }

        if ($grant->stripeAccountId === '' || ! str_starts_with($grant->stripeAccountId, 'acct_')) {
            throw StripeConnectException::oauthRejected();
        }

        // We need to act for the account, so anything short of read_write is unusable.
        if ($grant->scope !== 'read_write') {
            $this->revokeIfUnused($grant->stripeAccountId);
            throw StripeConnectException::accountNotEligible();
        }

        if ($grant->livemode !== $expectedLive || $grant->livemode !== $this->gateway->platformIsLive()) {
            $this->revokeIfUnused($grant->stripeAccountId);
            throw StripeConnectException::modeMismatch();
        }

        $snapshot = $this->gateway->retrieveAccount($grant->stripeAccountId);

        if ($snapshot->stripeAccountId !== $grant->stripeAccountId
            || ($snapshot->accountType !== null && $snapshot->accountType !== 'standard')) {
            $this->revokeIfUnused($grant->stripeAccountId);
            throw StripeConnectException::accountNotEligible();
        }

        // ---- apply locally ---------------------------------------------------
        // Conflicts are NOT revoked: the grant is shared with whoever already
        // holds this account, so revoking would sever their working connection.
        return DB::transaction(function () use ($business, $grant, $snapshot, $actorUserId) {
            $locked = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();

            $existing = $this->liveConnection($locked);

            if ($existing !== null) {
                if ($existing->stripe_account_id === $grant->stripeAccountId) {
                    return $existing;
                }

                throw StripeConnectException::alreadyConnected();
            }

            if ($this->accountHeldElsewhere($grant->stripeAccountId)) {
                throw StripeConnectException::accountInUse();
            }

            $connection = new BusinessStripeConnection([
                'business_id' => $locked->id,
                'stripe_account_id' => $grant->stripeAccountId,
            ]);

            $connection->forceFill($this->providerColumns($snapshot, $this->statusFor($snapshot)) + [
                'connection_method' => 'oauth',
                'connected_by_user_id' => $actorUserId,
                'connected_at' => now(),
                'last_synced_at' => now(),
                'lock_version' => 0,
            ]);

            try {
                $connection->save();
            } catch (\Illuminate\Database\QueryException $e) {
                // unique(active_stripe_account_id): a concurrent callback won.
                if ((string) $e->getCode() === '23000') {
                    throw StripeConnectException::accountInUse();
                }

                throw $e;
            }

            return $connection;
        });
    }

    /**
     * `account.application.deauthorized` — the owner revoked this platform at
     * Stripe. Local-only (no provider call is possible): the live row for that
     * exact account becomes terminal so nothing new can be charged through it.
     * Idempotent, and never touches an Agency row or another account.
     */
    public function markRevokedByProvider(string $stripeAccountId): void
    {
        if (trim($stripeAccountId) === '') {
            return;
        }

        DB::transaction(function () use ($stripeAccountId) {
            $connection = BusinessStripeConnection::query()
                ->where('stripe_account_id', $stripeAccountId)
                ->whereIn('status', self::liveStatuses())
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                return;
            }

            $connection->forceFill([
                'status' => StripeConnectionStatus::Disconnected->value,
                'disconnected_at' => now(),
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'requirements_disabled_reason' => 'provider_revoked_access',
                'lock_version' => (int) $connection->lock_version + 1,
            ])->save();
        });
    }

    /** Live on any Business, or on an Agency payment lane. */
    private function accountHeldElsewhere(string $stripeAccountId): bool
    {
        if (BusinessStripeConnection::query()
            ->where('stripe_account_id', $stripeAccountId)
            ->whereIn('status', self::liveStatuses())
            ->exists()) {
            return true;
        }

        return \App\Models\AgencyStripeConnection::query()
            ->where('stripe_account_id', $stripeAccountId)
            ->where('status', '!=', \App\Enums\AgencyBilling\AgencyStripeConnectionStatus::Disconnected->value)
            ->exists();
    }

    /**
     * Best-effort revocation of an OAuth grant. Skipped whenever the account
     * is live anywhere here, because the grant is per account — revoking it
     * would break that other connection.
     */
    private function revokeIfUnused(string $stripeAccountId, bool $throwOnFailure = false): void
    {
        if ($this->accountHeldElsewhere($stripeAccountId)) {
            return;
        }

        try {
            $this->gateway->deauthorizeAccount($stripeAccountId);
        } catch (StripeConnectException $e) {
            \Illuminate\Support\Facades\Log::warning('Business Stripe OAuth: could not revoke a grant.');

            if ($throwOnFailure) {
                throw $e;
            }
        }
    }

    /**
     * The live connection for a Business, or null. "Live" is exactly the four
     * non-terminal states the generated column recognises.
     */
    public function liveConnection(Business $business): ?BusinessStripeConnection
    {
        return BusinessStripeConnection::query()
            ->where('business_id', $business->id)
            ->whereIn('status', self::liveStatuses())
            ->first();
    }

    /**
     * Every connection this Business has ever had, newest first — the
     * historical record §5.7 requires to remain intact.
     *
     * @return \Illuminate\Support\Collection<int, BusinessStripeConnection>
     */
    public function history(Business $business)
    {
        return BusinessStripeConnection::query()
            ->where('business_id', $business->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * §12.D — create the Business's connected account and return the
     * Stripe-hosted onboarding URL.
     *
     * @throws StripeConnectException
     */
    public function connect(int $actorUserId, Business $business, string $refreshUrl, string $returnUrl): string
    {
        \App\Library\NicheBlueprint\Safety\BlueprintSafetyGuard::check('provider_oauth');

        $business = $this->ownedBusiness($actorUserId, $business);

        // Cheap pre-check so the ordinary "already connected" case never
        // creates an orphaned Stripe account. The authoritative check is the
        // one under the lock below, plus unique(active_business_id) beneath
        // that.
        if ($this->liveConnection($business) !== null) {
            throw StripeConnectException::alreadyConnected();
        }

        // ---- network, outside every transaction and lock (§7) -------------
        $snapshot = $this->gateway->createAccount(
            $this->countryFor($business),
            null,
            (string) $business->uid,
        );

        // ---- apply locally -------------------------------------------------
        $connection = DB::transaction(function () use ($business, $snapshot) {
            $locked = Business::query()->whereKey($business->id)->lockForUpdate()->firstOrFail();

            if ($this->liveConnection($locked) !== null) {
                throw StripeConnectException::alreadyConnected();
            }

            $connection = new BusinessStripeConnection([
                'business_id' => $locked->id,
                'stripe_account_id' => $snapshot->stripeAccountId,
            ]);

            // Provider truth is not mass-assignable on the model by design.
            $connection->forceFill($this->providerColumns($snapshot, StripeConnectionStatus::Onboarding) + [
                'connected_at' => now(),
                'lock_version' => 0,
            ])->save();

            return $connection;
        });

        return $this->onboardingLink($connection, $refreshUrl, $returnUrl);
    }

    /**
     * §12.D — a fresh Stripe-hosted onboarding link for a connection that has
     * not finished. Account links are single-use, so resuming always mints a
     * new one rather than storing the old URL.
     *
     * @throws StripeConnectException
     */
    public function resumeOnboarding(int $actorUserId, Business $business, string $refreshUrl, string $returnUrl): string
    {
        \App\Library\NicheBlueprint\Safety\BlueprintSafetyGuard::check('provider_oauth');

        $business = $this->ownedBusiness($actorUserId, $business);
        $connection = $this->liveConnection($business);

        if ($connection === null) {
            throw StripeConnectException::notConnected();
        }

        return $this->onboardingLink($connection, $refreshUrl, $returnUrl);
    }

    /**
     * §11.4 / §12.D — re-read capability state from the provider and apply it
     * optimistically. Readiness is rechecked, never assumed.
     *
     * @throws StripeConnectException
     */
    public function syncFromProvider(int $actorUserId, Business $business): BusinessStripeConnection
    {
        $business = $this->ownedBusiness($actorUserId, $business);
        $connection = $this->liveConnection($business);

        if ($connection === null) {
            throw StripeConnectException::notConnected();
        }

        $observedVersion = (int) $connection->lock_version;

        // ---- network, outside every transaction and lock (§7) -------------
        // The account id comes from OUR row, never from the request.
        $snapshot = $this->gateway->retrieveAccount((string) $connection->stripe_account_id);

        // ---- compare-and-set ----------------------------------------------
        $applied = BusinessStripeConnection::query()
            ->whereKey($connection->id)
            ->where('business_id', $business->id)
            ->where('lock_version', $observedVersion)
            ->whereIn('status', self::liveStatuses())
            ->update($this->providerColumns($snapshot, $this->statusFor($snapshot)) + [
                'lock_version' => $observedVersion + 1,
                'last_synced_at' => now(),
                'updated_at' => now(),
            ]);

        // 0 rows means a concurrent sync or a disconnect won the race. Its
        // result stands; we simply return the current truth rather than
        // overwriting a newer observation with an older one.
        return $connection->refresh();
    }

    /**
     * §5.7 — disconnect makes the CURRENT row terminal, which frees
     * active_business_id for a future connection. It rewrites no identifier
     * and deletes nothing: every historical row, and every payment's
     * reference to one, keeps its exact meaning.
     *
     * No provider call at all: the connected account continues to exist and
     * belongs to the Business, not to us.
     *
     * @throws StripeConnectException
     */
    public function disconnect(int $actorUserId, Business $business): BusinessStripeConnection
    {
        $business = $this->ownedBusiness($actorUserId, $business);

        $disconnected = DB::transaction(function () use ($business) {
            $connection = BusinessStripeConnection::query()
                ->where('business_id', $business->id)
                ->whereIn('status', self::liveStatuses())
                ->lockForUpdate()
                ->first();

            if ($connection === null) {
                throw StripeConnectException::notConnected();
            }

            $connection->forceFill([
                'status' => StripeConnectionStatus::Disconnected->value,
                'disconnected_at' => now(),
                'charges_enabled' => false,
                'payouts_enabled' => false,
                'lock_version' => (int) $connection->lock_version + 1,
            ])->save();

            return $connection->refresh();
        });

        // An OAuth-linked account also carries OUR grant at Stripe: revoke it,
        // outside the transaction. The Stripe account itself is never deleted.
        // The local disconnect stands even if revocation fails; the transient
        // (never persisted) `oauth_revocation` attribute lets the UI say so.
        if ($disconnected->connection_method === 'oauth') {
            try {
                $this->revokeIfUnused((string) $disconnected->stripe_account_id, throwOnFailure: true);
                $disconnected->setAttribute('oauth_revocation', 'revoked');
            } catch (StripeConnectException) {
                $disconnected->setAttribute('oauth_revocation', 'failed');
            }
        }

        return $disconnected;
    }

    /**
     * §11.4 — is this Business ready to be charged on right now? Sub-slice E
     * asks this before it creates any PaymentIntent; nothing in D charges.
     */
    public function isChargeReady(Business $business): bool
    {
        $connection = $this->liveConnection($business);

        return $connection !== null
            && $connection->status === StripeConnectionStatus::Active
            && (bool) $connection->charges_enabled;
    }

    /**
     * @throws StripeConnectException
     */
    private function onboardingLink(BusinessStripeConnection $connection, string $refreshUrl, string $returnUrl): string
    {
        // The account id is read from our own row — a caller cannot pass one.
        return $this->gateway->createOnboardingLink(
            (string) $connection->stripe_account_id,
            $refreshUrl,
            $returnUrl,
        );
    }

    /**
     * §5.7's derived status, from provider capability truth alone.
     */
    private function statusFor(ConnectedAccountSnapshot $snapshot): StripeConnectionStatus
    {
        if (! $snapshot->detailsSubmitted) {
            return StripeConnectionStatus::Onboarding;
        }

        return $snapshot->chargesEnabled
            ? StripeConnectionStatus::Active
            : StripeConnectionStatus::Restricted;
    }

    /**
     * The exact §5.7 columns provider truth may write — and no others. In
     * particular `stripe_account_id` is absent: it is set once, at creation,
     * and never rewritten.
     *
     * @return array<string, mixed>
     */
    private function providerColumns(ConnectedAccountSnapshot $snapshot, StripeConnectionStatus $status): array
    {
        return [
            'status' => $status->value,
            'charges_enabled' => $snapshot->chargesEnabled,
            'payouts_enabled' => $snapshot->payoutsEnabled,
            'details_submitted' => $snapshot->detailsSubmitted,
            'requirements_disabled_reason' => $snapshot->requirementsDisabledReason,
            'requirements_due' => json_encode($snapshot->requirementsDue),
            'default_currency' => $snapshot->defaultCurrency,
        ];
    }

    /**
     * §6.2 — owner-only, re-derived from persistence. The Business's own
     * owning customer, or the owner of the Workspace that holds it; an
     * ordinary Admin/Staff membership is deliberately NOT equivalent, because
     * this is financial consent rather than day-to-day operation.
     *
     * The Business is re-read here, so a caller cannot hand in a detached or
     * tampered model and have its ids believed.
     *
     * @throws StripeConnectException
     */
    private function ownedBusiness(int $actorUserId, Business $business): Business
    {
        $current = Business::query()->find($business->id);

        if ($current === null) {
            throw StripeConnectException::notOwner();
        }

        if ((int) $current->customer_id === $actorUserId) {
            return $current;
        }

        $workspace = $current->workspace_id === null ? null : Workspace::query()->find($current->workspace_id);

        if ($workspace !== null && (int) $workspace->owner_user_id === $actorUserId) {
            return $current;
        }

        throw StripeConnectException::notOwner();
    }

    private function countryFor(Business $business): string
    {
        $country = strtoupper(trim((string) ($business->country_code ?? '')));

        return preg_match('/\A[A-Z]{2}\z/', $country) === 1 ? $country : 'US';
    }

    /**
     * @return array<int, string>
     */
    private static function liveStatuses(): array
    {
        // Exactly the four states the DB's generated column treats as live.
        return [
            StripeConnectionStatus::Pending->value,
            StripeConnectionStatus::Onboarding->value,
            StripeConnectionStatus::Active->value,
            StripeConnectionStatus::Restricted->value,
        ];
    }
}
