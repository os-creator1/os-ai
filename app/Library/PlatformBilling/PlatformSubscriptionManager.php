<?php

namespace App\Library\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Implementation Contract 21 §7/§10 — lane A's domain manager: starting a
 * subscription, confirming it from provider truth, changing plans, and
 * cancelling.
 *
 * WHAT THIS CLASS IS NOT. It is not a second entitlement authority. Every
 * entitlement effect goes through EntitlementManager (§4), and every
 * lifecycle effect goes through PlatformSubscriptionFinalizer, which in turn
 * goes through EntitlementManager's existing writers (§3.3). This class owns
 * the commercial record and the provider conversation, nothing else.
 *
 * NO NETWORK UNDER A LOCK (§5). Every method that touches the provider commits
 * its durable local row first and calls Stripe afterwards, outside every
 * transaction — the same discipline Contract 17 §7.2 arrived at.
 *
 * DURABLE IDENTITY BEFORE THE PROVIDER CALL. The local row, its UID and its
 * `platform-subscription:{uid}` idempotency key are written in ONE insert
 * before Stripe is contacted, so an uncertain response can be re-driven
 * against the same row and the same key instead of opening a second checkout
 * or creating a second subscription.
 *
 * NOTHING IS PAID UNTIL THE PROVIDER SAYS SO (§7). A local row starts life
 * `pending`; only a provider-confirmed snapshot moves it, and only the
 * finalizer may do that.
 */
final class PlatformSubscriptionManager
{
    /**
     * Product tier ordering (Blueprint §21). It lives here rather than on
     * WorkspacePlanTier because that enum is deliberately identity-only, and
     * because "which direction is this change" is a commercial question, not
     * part of a tier's identity.
     */
    private const TIER_RANK = [
        'core' => 1,
        'growth' => 2,
        'agency' => 3,
    ];

    public const CHANGE_UPGRADED = 'upgraded';
    public const CHANGE_DOWNGRADE_SCHEDULED = 'downgrade_scheduled';

    public function __construct(
        private readonly PlatformStripeGateway $gateway,
        private readonly PlatformSubscriptionFinalizer $finalizer,
        private readonly EntitlementManager $entitlements,
    ) {
    }

    /**
     * §7 — open hosted checkout for one Workspace on one tier.
     *
     * @throws PlatformBillingException
     */
    public function startCheckout(
        Workspace $workspace,
        WorkspacePlanCatalog $catalog,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult {
        if (! $catalog->isSellable()) {
            throw PlatformBillingException::because(
                (bool) $catalog->available_for_signup && (bool) $catalog->is_active
                    ? PlatformBillingException::TIER_NOT_PRICED
                    : PlatformBillingException::TIER_NOT_AVAILABLE
            );
        }

        // ---- durable local row, committed, no network --------------------
        $subscription = DB::transaction(function () use ($workspace, $catalog) {
            $existing = PlatformSubscription::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $this->isLive($existing)) {
                throw PlatformBillingException::because(PlatformBillingException::ALREADY_SUBSCRIBED);
            }

            // A Workspace that never completed checkout, or whose previous
            // subscription ended, re-drives its own row rather than
            // accumulating a second one that could disagree about which is
            // current. `unique(workspace_id)` makes that structural.
            $row = $existing ?? new PlatformSubscription([
                'workspace_id' => $workspace->id,
            ]);

            $row->fill([
                'workspace_plan_catalog_id' => $catalog->id,
                // §6/§8 — the commercial terms are SNAPSHOTTED here, so a
                // later catalog edit cannot rewrite what this customer bought.
                'price_snapshot' => $catalog->price,
                'currency_id' => $catalog->currency_id,
                'currency_code' => $catalog->currency?->code,
                'billing_cycle_snapshot' => (string) $catalog->billing_cycle,
                'trial_days_snapshot' => $catalog->configuredTrialDays(),
            ]);

            if (! $row->exists) {
                // ONE INSERT carrying the complete durable identity: the UID
                // is minted here so `local_idempotency_key`, which is derived
                // from it and is UNIQUE, is part of the same statement.
                $row->generateUid();
                $row->local_idempotency_key = PlatformSubscription::idempotencyKeyFor((string) $row->uid);
                $row->status = PlatformSubscriptionStatus::Pending->value;
            }

            $row->provider_price_id = $catalog->provider_price_id;
            $row->save();

            return $row->refresh();
        });

        // ---- provider call, OUTSIDE every transaction and lock -----------
        $result = $this->gateway->createSubscriptionCheckout(
            providerPriceId: (string) $catalog->provider_price_id,
            clientReferenceId: (string) $subscription->uid,
            idempotencyKey: (string) $subscription->local_idempotency_key,
            successUrl: $successUrl,
            cancelUrl: $cancelUrl,
            customerEmail: $customerEmail,
            trialDays: $subscription->trial_days_snapshot,
            existingCustomerId: $subscription->provider_customer_id,
        );

        $subscription->forceFill([
            'provider_checkout_session_id' => $result->sessionId,
            'provider_customer_id' => $result->customerId ?? $subscription->provider_customer_id,
        ])->save();

        return $result;
    }

    /**
     * §12/§8.5 — resolve a completed checkout from PROVIDER TRUTH.
     *
     * A browser returning to the success URL proves nothing; this re-reads the
     * session and then the subscription from Stripe, and hands the result to
     * the shared finalizer. It is safe to call repeatedly — the finalizer is
     * idempotent — which is what lets both the return URL and the webhook use
     * it without racing.
     *
     * @throws PlatformBillingException
     */
    public function confirmCheckoutSession(string $sessionId): ?PlatformSubscription
    {
        $session = $this->gateway->retrieveCheckoutSession($sessionId);

        if ($session->clientReferenceId === null || $session->subscriptionId === null) {
            // Nothing confirmed yet — §7 forbids inventing a paid state from
            // an incomplete session.
            return null;
        }

        $subscription = PlatformSubscription::query()
            ->where('uid', $session->clientReferenceId)
            ->first();

        if ($subscription === null) {
            return null;
        }

        $snapshot = $this->gateway->retrieveSubscription($session->subscriptionId);
        $this->finalizer->apply($subscription, $snapshot);

        return $subscription->refresh();
    }

    /**
     * §12 — apply one provider subscription id through the shared finalizer.
     * Used by the webhook consumer, which resolves the local row itself and
     * therefore does not need the checkout session.
     *
     * @throws PlatformBillingException
     */
    public function applyProviderSubscription(
        PlatformSubscription $subscription,
        string $providerSubscriptionId,
        ?string $eventId = null,
        ?\Carbon\CarbonInterface $eventCreatedAt = null,
    ): string {
        $snapshot = $this->gateway->retrieveSubscription($providerSubscriptionId);

        return $this->finalizer->apply($subscription, $snapshot, $eventId, $eventCreatedAt);
    }

    /**
     * §10.2 — UPGRADE IS IMMEDIATE, DOWNGRADE IS AT PERIOD END.
     *
     * Upgrade: the provider price changes now and bills the difference, and
     * the V1 plan assignment changes now, so the customer gets what they just
     * paid for immediately.
     *
     * Downgrade: nothing changes today. The target tier is parked on the local
     * row with the date it becomes effective (the current period end), and the
     * scheduled command applies it at the boundary. The customer keeps the
     * tier they have already paid for until then, and NO customer data is
     * deleted when the tier finally changes — components become inactive, per
     * Blueprint §16/§21 and the existing capacity rules.
     *
     * @return self::CHANGE_* the direction actually taken
     *
     * @throws PlatformBillingException
     */
    public function requestPlanChange(Workspace $workspace, WorkspacePlanCatalog $target, int $ownerUserId): string
    {
        $subscription = $this->liveSubscriptionFor($workspace);

        if (! $target->is_active || $target->price === null || blank($target->provider_price_id)) {
            throw PlatformBillingException::because(PlatformBillingException::TIER_NOT_PRICED);
        }

        $current = WorkspacePlanCatalog::query()->findOrFail($subscription->workspace_plan_catalog_id);

        if ((int) $current->id === (int) $target->id) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_NOT_PERMITTED);
        }

        $isUpgrade = self::rankOf($target) > self::rankOf($current);

        if (! $isUpgrade) {
            // §10.2 — parked, not applied. No provider call at all today.
            $subscription->forceFill([
                'pending_plan_catalog_id' => $target->id,
                'pending_effective_at' => $subscription->current_period_end,
            ])->save();

            return self::CHANGE_DOWNGRADE_SCHEDULED;
        }

        // Provider first: the entitlement must not widen before the money does.
        $snapshot = $this->gateway->changeSubscriptionPrice(
            (string) $subscription->provider_subscription_id,
            (string) $target->provider_price_id,
            prorate: true,
            idempotencyKey: (string) $subscription->local_idempotency_key . ':upgrade:' . $target->id,
        );

        $subscription->forceFill([
            'workspace_plan_catalog_id' => $target->id,
            'provider_price_id' => $target->provider_price_id,
            // §10.1 — the terms published at the moment of the change.
            'price_snapshot' => $target->price,
            'currency_id' => $target->currency_id,
            'currency_code' => $target->currency?->code,
            'billing_cycle_snapshot' => (string) $target->billing_cycle,
            'pending_plan_catalog_id' => null,
            'pending_effective_at' => null,
        ])->save();

        $this->entitlements->changePlanFromVerifiedSubscription(
            $workspace,
            $target->tier,
            $ownerUserId,
            (string) $subscription->uid,
            (string) $subscription->provider_subscription_id,
            'platform_subscription_upgrade',
        );

        $this->finalizer->apply($subscription->refresh(), $snapshot);

        return self::CHANGE_UPGRADED;
    }

    /**
     * §10.3 — cancellation preserves access through the already-paid period.
     * Provider state and local state say the same thing, and the actual end of
     * service arrives as a provider event, never as a local guess.
     *
     * @throws PlatformBillingException
     */
    public function requestCancellation(Workspace $workspace): PlatformSubscription
    {
        $subscription = $this->liveSubscriptionFor($workspace);

        $snapshot = $this->gateway->setCancelAtPeriodEnd((string) $subscription->provider_subscription_id, true);
        $this->finalizer->apply($subscription, $snapshot);

        return $subscription->refresh();
    }

    /**
     * §10.3 — undo a scheduled cancellation while the period is still running.
     *
     * @throws PlatformBillingException
     */
    public function resumeSubscription(Workspace $workspace): PlatformSubscription
    {
        $subscription = $this->liveSubscriptionFor($workspace);

        if (! (bool) $subscription->cancel_at_period_end) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_NOT_PERMITTED);
        }

        $snapshot = $this->gateway->setCancelAtPeriodEnd((string) $subscription->provider_subscription_id, false);
        $this->finalizer->apply($subscription, $snapshot);

        return $subscription->refresh();
    }

    /**
     * §10.2 — apply downgrades whose period boundary has arrived.
     *
     * Bounded, per-row transactions, and a Throwable on one row logged without
     * aborting the batch — the SweepExpiredOpportunitySnoozes convention the
     * repository already uses for scheduled work.
     */
    public function applyDuePendingPlanChanges(int $limit): int
    {
        $candidates = PlatformSubscription::query()
            ->whereNotNull('pending_plan_catalog_id')
            ->whereNotNull('pending_effective_at')
            ->where('pending_effective_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $applied = 0;

        foreach ($candidates as $subscription) {
            try {
                $target = WorkspacePlanCatalog::query()->find($subscription->pending_plan_catalog_id);
                $workspace = Workspace::query()->find($subscription->workspace_id);

                if ($target === null || $workspace === null || blank($target->provider_price_id)) {
                    continue;
                }

                // No proration: the customer already paid for the period that
                // just ended, and the cheaper tier simply starts now.
                $snapshot = $this->gateway->changeSubscriptionPrice(
                    (string) $subscription->provider_subscription_id,
                    (string) $target->provider_price_id,
                    prorate: false,
                    idempotencyKey: (string) $subscription->local_idempotency_key . ':downgrade:' . $target->id,
                );

                $subscription->forceFill([
                    'workspace_plan_catalog_id' => $target->id,
                    'provider_price_id' => $target->provider_price_id,
                    'price_snapshot' => $target->price,
                    'currency_id' => $target->currency_id,
                    'currency_code' => $target->currency?->code,
                    'billing_cycle_snapshot' => (string) $target->billing_cycle,
                    'pending_plan_catalog_id' => null,
                    'pending_effective_at' => null,
                ])->save();

                $this->entitlements->changePlanFromVerifiedSubscription(
                    $workspace,
                    $target->tier,
                    (int) $workspace->owner_user_id,
                    (string) $subscription->uid,
                    (string) $subscription->provider_subscription_id,
                    'platform_subscription_downgrade',
                );

                $this->finalizer->apply($subscription->refresh(), $snapshot);
                $applied++;
            } catch (Throwable $e) {
                Log::error('PlatformSubscriptionManager::applyDuePendingPlanChanges failed for a subscription', [
                    'platform_subscription_id' => $subscription->id,
                    'exception' => class_basename($e),
                ]);
            }
        }

        return $applied;
    }

    /**
     * §7 — the V1 plan assignment for a newly confirmed subscription.
     *
     * Called by signup AFTER the provider has confirmed, never before, and
     * never with `is_complimentary` — §3.4's fabricated complimentary Core is
     * exactly what this replaces.
     */
    public function assignPlanFromConfirmedSubscription(
        Workspace $workspace,
        PlatformSubscription $subscription,
        WorkspacePlanTier $tier,
        int $ownerUserId,
    ): void {
        $this->entitlements->assignFirstPlanFromVerifiedSubscription(
            $workspace,
            $tier,
            $ownerUserId,
            (string) $subscription->uid,
            (string) ($subscription->provider_subscription_id ?? $subscription->provider_checkout_session_id),
            // §8 — the trial end is the SNAPSHOT, so a later catalog change
            // cannot rewrite this subscriber's trial.
            $subscription->trial_ends_at,
            'platform_subscription_signup',
        );
    }

    public function findForWorkspace(Workspace $workspace): ?PlatformSubscription
    {
        return PlatformSubscription::query()->where('workspace_id', $workspace->id)->first();
    }

    /**
     * §4 — the Stripe-hosted Billing Portal, which is how a subscriber fixes
     * or replaces a payment method after a failure.
     *
     * CARD DETAILS NEVER REACH THIS APPLICATION. The customer types their card
     * on Stripe's own page; all we ever hold is the URL to send them to. That
     * is why this returns a string and takes no card-shaped argument.
     *
     * @throws PlatformBillingException
     */
    public function billingPortalUrl(Workspace $workspace, string $returnUrl, ?string $flow = null): string
    {
        $subscription = $this->findForWorkspace($workspace);

        if ($subscription === null || blank($subscription->provider_customer_id)) {
            throw PlatformBillingException::because(PlatformBillingException::NO_SUBSCRIPTION);
        }

        return $this->gateway->createBillingPortalSession(
            (string) $subscription->provider_customer_id,
            $returnUrl,
            $flow,
        );
    }

    /** The documented portal deep link for replacing the default payment method. */
    public const PORTAL_FLOW_PAYMENT_METHOD = 'payment_method_update';

    /**
     * @throws PlatformBillingException
     */
    private function liveSubscriptionFor(Workspace $workspace): PlatformSubscription
    {
        $subscription = $this->findForWorkspace($workspace);

        if ($subscription === null
            || $subscription->provider_subscription_id === null
            || ! $this->isLive($subscription)) {
            throw PlatformBillingException::because(PlatformBillingException::NO_SUBSCRIPTION);
        }

        return $subscription;
    }

    /**
     * "Live" means the provider relationship still exists — including
     * `past_due`, which is a delinquent subscription rather than an absent
     * one, and which Blueprint §27 keeps usable through Grace.
     */
    private function isLive(PlatformSubscription $subscription): bool
    {
        return ! in_array($subscription->status, [
            PlatformSubscriptionStatus::Pending,
            PlatformSubscriptionStatus::Canceled,
            PlatformSubscriptionStatus::IncompleteExpired,
        ], true);
    }

    private static function rankOf(WorkspacePlanCatalog $catalog): int
    {
        return self::TIER_RANK[$catalog->tier->value] ?? 0;
    }
}
