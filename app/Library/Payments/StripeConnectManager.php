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

        return DB::transaction(function () use ($business) {
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
