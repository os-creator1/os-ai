<?php

namespace App\Library\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\ViewAs\ViewAsManager;
use App\Library\Workspace\AccountFrameAccess;
use App\Library\Workspace\AgencyClientRelationshipManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencySaasPlan;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use App\Repositories\Contracts\WorkspacePlanAssignmentRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lane C §C6/§C7 — lane C's domain manager: offering a plan, taking the
 * client's own consent, confirming from provider truth, changing plans,
 * cancelling and coming back.
 *
 * WHAT THIS CLASS IS NOT. It is not a second entitlement authority. Every
 * entitlement effect goes through EntitlementManager, and every lifecycle
 * effect goes through AgencyClientSubscriptionFinalizer, which in turn goes
 * through EntitlementManager's existing writers. This class owns the commercial
 * record and the provider conversation, nothing else.
 *
 * TWO DIFFERENT AUTHORITIES, DELIBERATELY SEPARATED (§C6):
 *
 *   The AGENCY OWNER may OFFER a plan. That is a proposal. It snapshots terms,
 *   reaches no provider, moves no money and grants no access.
 *
 *   Only the CLIENT may turn that offer into a subscription. The Agency owner
 *   cannot, Agency Staff cannot, a Platform administrator cannot, and a View As
 *   actor cannot — because financial consent belongs to whoever is being
 *   charged, and every one of those actors would be consenting on somebody
 *   else's behalf with somebody else's card.
 *
 * NO NETWORK UNDER A LOCK (§C4). Every method that touches the provider commits
 * its durable local row first and calls Stripe afterwards, outside every
 * transaction.
 *
 * EVERY PROVIDER CALL IS SCOPED TO THE AGENCY'S CONNECTED ACCOUNT, taken from
 * the subscription's own row rather than from a request, so no caller can point
 * one Agency's operation at another Agency's account.
 */
final class AgencyClientSubscriptionManager
{
    public const CHANGE_UPGRADED = 'upgraded';
    public const CHANGE_DOWNGRADE_SCHEDULED = 'downgrade_scheduled';

    public const PENDING_UPGRADE = 'upgrade';
    public const PENDING_DOWNGRADE = 'downgrade';
    public const PENDING_RESUBSCRIBE = 'resubscribe';

    /** The documented portal deep link for replacing the default payment method. */
    public const PORTAL_FLOW_PAYMENT_METHOD = 'payment_method_update';

    /** How many times a contended checkout re-resolves before giving up. */
    private const MAX_CHECKOUT_ROUNDS = 3;

    /** Product tier ordering, so a change has a direction. */
    private const TIER_RANK = ['core' => 1, 'growth' => 2, 'agency' => 3];

    public function __construct(
        private readonly AgencyStripeGateway $gateway,
        private readonly AgencyStripeConnectManager $connections,
        private readonly AgencyClientSubscriptionFinalizer $finalizer,
        private readonly AgencyClientRelationshipManager $relationships,
        private readonly EntitlementManager $entitlements,
        private readonly WorkspacePlanAssignmentRepository $assignments,
        private readonly WorkspaceMembershipRepository $memberships,
        private readonly ViewAsManager $viewAs,
    ) {
    }

    // =====================================================================
    // Reads
    // =====================================================================

    public function findForClientWorkspace(Workspace $clientWorkspace): ?AgencyClientSubscription
    {
        return AgencyClientSubscription::query()
            ->where('client_workspace_id', $clientWorkspace->id)
            ->first();
    }

    /** @return \Illuminate\Support\Collection<int, AgencyClientSubscription> */
    public function forAgency(Workspace $agencyWorkspace)
    {
        return AgencyClientSubscription::query()
            ->where('agency_workspace_id', $agencyWorkspace->id)
            ->orderByDesc('id')
            ->get();
    }

    // =====================================================================
    // §C6 — the Agency's proposal
    // =====================================================================

    /**
     * §C6 — OFFER a plan to a managed client.
     *
     * A PROPOSAL, NOT A SUBSCRIPTION. It writes an `offered` row with the terms
     * snapshotted at this moment, and reaches no provider at all: no customer
     * is created, no session is opened, no card is touched, nothing is owed.
     * The client sees it, and only the client can act on it.
     *
     * @throws AgencyBillingException
     */
    public function offer(
        int $actorUserId,
        Workspace $agencyWorkspace,
        Workspace $clientWorkspace,
        AgencySaasPlan $plan,
    ): AgencyClientSubscription {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);
        $this->assertNotViewingAs($actorUserId);
        $this->assertActiveRelationship($agencyWorkspace, $clientWorkspace);

        if ((int) $plan->agency_workspace_id !== (int) $agencyWorkspace->id) {
            throw AgencyBillingException::because(AgencyBillingException::NO_ACTIVE_RELATIONSHIP);
        }

        if (! $plan->isSellable()) {
            throw AgencyBillingException::because(AgencyBillingException::PLAN_NOT_SELLABLE);
        }

        // The Agency's account must be able to take money before a client is
        // invited to pay into it.
        $connection = $this->connections->chargeableConnection($agencyWorkspace);

        $this->assertClientEligible($clientWorkspace);

        return DB::transaction(function () use ($agencyWorkspace, $clientWorkspace, $plan, $connection, $actorUserId): AgencyClientSubscription {
            $existing = AgencyClientSubscription::query()
                ->where('client_workspace_id', $clientWorkspace->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $existing->status->isLive()) {
                throw AgencyBillingException::because(AgencyBillingException::CLIENT_ALREADY_SUBSCRIBED);
            }

            $row = $existing ?? new AgencyClientSubscription([
                'agency_workspace_id' => $agencyWorkspace->id,
                'client_workspace_id' => $clientWorkspace->id,
            ]);

            if ($existing === null) {
                $row->generateUid();
                $row->local_idempotency_key = AgencyClientSubscription::idempotencyKeyFor((string) $row->uid);
            }

            // RE-OFFERING TO A CLIENT WHOSE PREVIOUS SUBSCRIPTION ENDED MUST
            // RETIRE IT FIRST.
            //
            // One row per client means a customer who cancelled and is being
            // offered a fresh plan reuses it — and the row still names the
            // provider subscription of the life that ended. Leaving it there
            // made the new purchase unconfirmable: the finalizer's cross-check
            // compares the stored subscription id against the one the provider
            // just created, sees a mismatch, and correctly refuses. The client
            // would pay and never be activated.
            //
            // Retiring it keeps the audit trail AND keeps the guard honest: a
            // late event from the old subscription is still refused, now
            // because it is on the retired list rather than because it happens
            // to be the current id.
            $retired = $row->retiredProviderSubscriptionIds();

            if ($existing !== null
                && $existing->status->isTerminal()
                && $existing->provider_subscription_id !== null) {
                $retired[] = (string) $existing->provider_subscription_id;
            }

            // A re-offer replaces the TERMS of an unpaid proposal. It never
            // touches a live subscription — that case threw above.
            $row->forceFill([
                'retired_provider_subscription_ids' => array_values(array_unique($retired)),
                'provider_subscription_id' => null,
                'agency_workspace_id' => $agencyWorkspace->id,
                'agency_saas_plan_id' => $plan->id,
                'agency_stripe_connection_id' => $connection->id,
                'connected_account_id' => $connection->stripe_account_id,
                'status' => AgencyClientSubscriptionStatus::Offered->value,
                // §C3.4 — the terms AS OFFERED. A later catalog edit cannot
                // rewrite what this client was shown and agreed to.
                'price_snapshot' => $plan->price,
                'currency_id' => $plan->currency_id,
                'currency_code' => $plan->currency_code,
                'billing_cycle_snapshot' => $plan->billing_cycle,
                'trial_days_snapshot' => $plan->configuredTrialDays(),
                'offered_by_user_id' => $actorUserId,
                'offered_at' => now(),
                // A fresh proposal starts a fresh attempt.
                'checkout_attempt_uid' => null,
                'checkout_attempt_price_id' => null,
                'provider_checkout_session_id' => null,
                'checkout_attempt_generation' => (int) ($row->checkout_attempt_generation ?? 0) + 1,
            ])->save();

            return $row->refresh();
        });
    }

    /**
     * §C6 — withdraw an offer the client has not acted on. A live subscription
     * is never withdrawn this way: ending one is a cancellation, and only the
     * payer may ask for that.
     *
     * @throws AgencyBillingException
     */
    public function withdrawOffer(int $actorUserId, Workspace $agencyWorkspace, Workspace $clientWorkspace): void
    {
        $this->assertAgencyOwner($actorUserId, $agencyWorkspace);
        $this->assertNotViewingAs($actorUserId);

        $subscription = $this->findForClientWorkspace($clientWorkspace);

        if ($subscription === null || ! $subscription->isOffer()) {
            throw AgencyBillingException::because(AgencyBillingException::CHANGE_NOT_PERMITTED);
        }

        if ((int) $subscription->agency_workspace_id !== (int) $agencyWorkspace->id) {
            throw AgencyBillingException::because(AgencyBillingException::NO_ACTIVE_RELATIONSHIP);
        }

        $subscription->delete();
    }

    // =====================================================================
    // §C6 — the CLIENT's own consent
    // =====================================================================

    /**
     * §C6 — the client authorizes payment and hosted Checkout opens on the
     * AGENCY's connected account.
     *
     * THIS IS THE CONSENT BOUNDARY. `assertClientFinancialAuthority()` refuses
     * the Agency, Agency Staff, a Platform administrator and any stranger;
     * `assertNotViewingAs()` refuses a View As actor even when they would
     * otherwise qualify. Between them, nobody but the payer can start a charge
     * against the payer's card.
     *
     * @throws AgencyBillingException
     */
    public function startCheckout(
        int $actorUserId,
        Workspace $clientWorkspace,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
    ): AgencyCheckoutSessionResult {
        $this->assertClientFinancialAuthority($actorUserId, $clientWorkspace);
        $this->assertNotViewingAs($actorUserId);

        $subscription = $this->findForClientWorkspace($clientWorkspace)
            ?? throw AgencyBillingException::because(AgencyBillingException::NO_SUBSCRIPTION);

        if ($subscription->status->isLive()) {
            throw AgencyBillingException::because(AgencyBillingException::CLIENT_ALREADY_SUBSCRIBED);
        }

        $agencyWorkspace = Workspace::query()->findOrFail($subscription->agency_workspace_id);
        $this->assertActiveRelationship($agencyWorkspace, $clientWorkspace);

        $plan = AgencySaasPlan::query()->find($subscription->agency_saas_plan_id);

        if ($plan === null || ! $plan->isSellable()) {
            throw AgencyBillingException::because(AgencyBillingException::PLAN_NOT_SELLABLE);
        }

        // Re-checked at the moment of the charge, never cached from the offer.
        $connection = $this->connections->chargeableConnection($agencyWorkspace);

        // Record the consent BEFORE the provider is touched, so who agreed and
        // when is durable even if the session is abandoned.
        $subscription = DB::transaction(function () use ($subscription, $connection, $actorUserId): AgencyClientSubscription {
            $locked = AgencyClientSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => AgencyClientSubscriptionStatus::Pending->value,
                'agency_stripe_connection_id' => $connection->id,
                'connected_account_id' => $connection->stripe_account_id,
                'consented_by_user_id' => $actorUserId,
                'consented_at' => $locked->consented_at ?? now(),
            ])->save();

            return $locked->refresh();
        });

        return $this->driveCheckout($subscription, $plan, (string) $connection->stripe_account_id, $customerEmail, $successUrl, $cancelUrl);
    }

    /**
     * §C7 — resolve a completed checkout from PROVIDER TRUTH.
     *
     * A browser returning to the success URL proves nothing; this re-reads the
     * session and then the subscription from the Agency's account and hands the
     * result to the shared finalizer. Safe to call repeatedly, which is what
     * lets the return URL and the webhook both use it without racing.
     *
     * @throws AgencyBillingException
     */
    public function confirmCheckoutSession(AgencyClientSubscription $subscription, string $sessionId): ?AgencyClientSubscription
    {
        $accountId = (string) $subscription->connected_account_id;

        if ($accountId === '') {
            throw AgencyBillingException::because(AgencyBillingException::NO_CONNECTION);
        }

        $session = $this->gateway->retrieveCheckoutSession($accountId, $sessionId);

        if ($session->clientReferenceId === null || $session->subscriptionId === null) {
            // Nothing confirmed yet — §C6 forbids inventing a paid state from
            // an incomplete session.
            return null;
        }

        if ($session->clientReferenceId !== (string) $subscription->uid) {
            throw AgencyBillingException::because(AgencyBillingException::NO_SUBSCRIPTION);
        }

        $snapshot = $this->gateway->retrieveSubscription($accountId, $session->subscriptionId);
        $this->finalizer->apply($subscription, $snapshot);

        return $subscription->refresh();
    }

    /**
     * §C7 — apply one provider subscription id through the shared finalizer.
     * Used by the webhook consumer, which resolves the local row itself.
     *
     * @throws AgencyBillingException
     */
    public function applyProviderSubscription(
        AgencyClientSubscription $subscription,
        string $providerSubscriptionId,
        string $connectedAccountId,
        ?string $eventId = null,
        ?\Carbon\CarbonInterface $eventCreatedAt = null,
    ): string {
        $snapshot = $this->gateway->retrieveSubscription($connectedAccountId, $providerSubscriptionId);

        return $this->finalizer->apply($subscription, $snapshot, $eventId, $eventCreatedAt);
    }

    // =====================================================================
    // §C7 — changing, cancelling, coming back
    // =====================================================================

    /**
     * §C7 — upgrade is immediate, downgrade is at the period boundary.
     *
     * THE CLIENT ASKS, because the client pays. An upgrade bills them more
     * today, so the Agency cannot impose one.
     *
     * @return self::CHANGE_* the direction actually taken
     *
     * @throws AgencyBillingException
     */
    public function requestPlanChange(int $actorUserId, Workspace $clientWorkspace, AgencySaasPlan $target): string
    {
        $this->assertClientFinancialAuthority($actorUserId, $clientWorkspace);
        $this->assertNotViewingAs($actorUserId);

        $subscription = $this->liveSubscriptionFor($clientWorkspace);
        $agencyWorkspace = Workspace::query()->findOrFail($subscription->agency_workspace_id);

        if ((int) $target->agency_workspace_id !== (int) $agencyWorkspace->id || ! $target->isSellable()) {
            throw AgencyBillingException::because(AgencyBillingException::PLAN_NOT_SELLABLE);
        }

        if ((int) $subscription->agency_saas_plan_id === (int) $target->id) {
            throw AgencyBillingException::because(AgencyBillingException::CHANGE_NOT_PERMITTED);
        }

        $current = AgencySaasPlan::query()->findOrFail($subscription->agency_saas_plan_id);
        $isUpgrade = self::rankOf($target) > self::rankOf($current);

        $subscription = $this->openPlanChangeOperation(
            $subscription,
            $target,
            $isUpgrade ? self::PENDING_UPGRADE : self::PENDING_DOWNGRADE,
            $isUpgrade ? now() : $subscription->current_period_end,
        );

        if (! $isUpgrade) {
            // Parked, not applied. No provider call today, and the terms are
            // SNAPSHOTTED NOW so an Agency repricing the plan next week cannot
            // change what this client agreed to.
            return self::CHANGE_DOWNGRADE_SCHEDULED;
        }

        $connection = $this->connections->chargeableConnection($agencyWorkspace);

        $snapshot = $this->gateway->changeSubscriptionPrice(
            (string) $connection->stripe_account_id,
            (string) $subscription->provider_subscription_id,
            (string) $subscription->pending_price_id,
            prorate: true,
            idempotencyKey: (string) $subscription->planChangeKey(),
        );

        $this->finalizer->apply($subscription->refresh(), $snapshot);

        return self::CHANGE_UPGRADED;
    }

    /**
     * §C7 — cancellation preserves access through the already-paid period.
     *
     * @throws AgencyBillingException
     */
    public function requestCancellation(int $actorUserId, Workspace $clientWorkspace): AgencyClientSubscription
    {
        $this->assertClientFinancialAuthority($actorUserId, $clientWorkspace);
        $this->assertNotViewingAs($actorUserId);

        $subscription = $this->liveSubscriptionFor($clientWorkspace);

        $snapshot = $this->gateway->setCancelAtPeriodEnd(
            (string) $subscription->connected_account_id,
            (string) $subscription->provider_subscription_id,
            true,
        );
        $this->finalizer->apply($subscription, $snapshot);

        return $subscription->refresh();
    }

    /**
     * §C7 — undo a scheduled cancellation while the period is still running.
     *
     * @throws AgencyBillingException
     */
    public function resumeSubscription(int $actorUserId, Workspace $clientWorkspace): AgencyClientSubscription
    {
        $this->assertClientFinancialAuthority($actorUserId, $clientWorkspace);
        $this->assertNotViewingAs($actorUserId);

        $subscription = $this->liveSubscriptionFor($clientWorkspace);

        if (! (bool) $subscription->cancel_at_period_end) {
            throw AgencyBillingException::because(AgencyBillingException::CHANGE_NOT_PERMITTED);
        }

        $snapshot = $this->gateway->setCancelAtPeriodEnd(
            (string) $subscription->connected_account_id,
            (string) $subscription->provider_subscription_id,
            false,
        );
        $this->finalizer->apply($subscription, $snapshot);

        return $subscription->refresh();
    }

    /**
     * §C7 — start again after the subscription has fully ended.
     *
     * The same Workspace, Business and Locations; a NEW provider subscription,
     * because a canceled Stripe subscription cannot be revived by changing its
     * Price. The finished provider subscription is RETIRED rather than erased,
     * so a late event from the previous life cannot lock the account the client
     * has just paid to restart.
     *
     * @throws AgencyBillingException
     */
    public function startResubscribeCheckout(
        int $actorUserId,
        Workspace $clientWorkspace,
        AgencySaasPlan $plan,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
    ): AgencyCheckoutSessionResult {
        $this->assertClientFinancialAuthority($actorUserId, $clientWorkspace);
        $this->assertNotViewingAs($actorUserId);

        $subscription = $this->findForClientWorkspace($clientWorkspace)
            ?? throw AgencyBillingException::because(AgencyBillingException::NO_SUBSCRIPTION);

        if (! $subscription->status->isTerminal()) {
            throw AgencyBillingException::because(AgencyBillingException::CHANGE_NOT_PERMITTED);
        }

        $agencyWorkspace = Workspace::query()->findOrFail($subscription->agency_workspace_id);
        $this->assertActiveRelationship($agencyWorkspace, $clientWorkspace);

        if ((int) $plan->agency_workspace_id !== (int) $agencyWorkspace->id || ! $plan->isSellable()) {
            throw AgencyBillingException::because(AgencyBillingException::PLAN_NOT_SELLABLE);
        }

        $connection = $this->connections->chargeableConnection($agencyWorkspace);

        $subscription = $this->retireEndedSubscription($subscription, $plan, $connection->id, (string) $connection->stripe_account_id, $actorUserId);

        return $this->driveCheckout($subscription, $plan, (string) $connection->stripe_account_id, $customerEmail, $successUrl, $cancelUrl);
    }

    /**
     * §C8 — the Agency-hosted Stripe Billing Portal, which is how a client
     * fixes or replaces the card the Agency charges.
     *
     * CARD DETAILS NEVER REACH THIS APPLICATION. The client types their card on
     * Stripe's own page; all we hold is the URL to send them to.
     *
     * @throws AgencyBillingException
     */
    public function billingPortalUrl(int $actorUserId, Workspace $clientWorkspace, string $returnUrl, ?string $flow = null): string
    {
        $this->assertClientFinancialAuthority($actorUserId, $clientWorkspace);
        $this->assertNotViewingAs($actorUserId);

        $subscription = $this->findForClientWorkspace($clientWorkspace);

        if ($subscription === null
            || blank($subscription->provider_customer_id)
            || blank($subscription->connected_account_id)) {
            throw AgencyBillingException::because(AgencyBillingException::NO_SUBSCRIPTION);
        }

        return $this->gateway->createBillingPortalSession(
            (string) $subscription->connected_account_id,
            (string) $subscription->provider_customer_id,
            $returnUrl,
            $flow,
        );
    }

    /**
     * §C7 — apply downgrades whose period boundary has arrived.
     *
     * EVERYTHING SENT TO THE PROVIDER COMES FROM THE OPERATION, not from
     * today's catalog: the Price, the amount, the currency and the cycle are
     * the ones the client agreed to when they REQUESTED the change. Local state
     * moves only through the shared finalizer, which requires provider truth,
     * so a provider success with a local failure converges on the next sweep.
     */
    public function applyDuePendingPlanChanges(int $limit): int
    {
        $candidates = AgencyClientSubscription::query()
            ->whereNotNull('pending_operation_uid')
            ->where('pending_kind', self::PENDING_DOWNGRADE)
            ->whereNotNull('pending_effective_at')
            ->where('pending_effective_at', '<=', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $applied = 0;

        foreach ($candidates as $subscription) {
            try {
                if (blank($subscription->pending_price_id)
                    || blank($subscription->provider_subscription_id)
                    || blank($subscription->connected_account_id)) {
                    continue;
                }

                $snapshot = $this->gateway->changeSubscriptionPrice(
                    (string) $subscription->connected_account_id,
                    (string) $subscription->provider_subscription_id,
                    (string) $subscription->pending_price_id,
                    prorate: false,
                    idempotencyKey: (string) $subscription->planChangeKey(),
                );

                if ($this->finalizer->apply($subscription->refresh(), $snapshot) === AgencyClientSubscriptionFinalizer::APPLIED) {
                    $applied++;
                }
            } catch (Throwable $e) {
                Log::error('AgencyClientSubscriptionManager::applyDuePendingPlanChanges failed for a subscription', [
                    'agency_client_subscription_id' => $subscription->id,
                    'exception' => class_basename($e),
                ]);
            }
        }

        return $applied;
    }

    // =====================================================================
    // §C7 — checkout attempt machinery
    // =====================================================================

    /**
     * Resolve the attempt, create the session, and prove we are still the
     * current attempt when we persist it. BOUNDED, never unbounded recursion.
     *
     * @throws AgencyBillingException
     */
    private function driveCheckout(
        AgencyClientSubscription $subscription,
        AgencySaasPlan $plan,
        string $connectedAccountId,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
    ): AgencyCheckoutSessionResult {
        for ($round = 0; $round < self::MAX_CHECKOUT_ROUNDS; $round++) {
            $subscription = $this->resolveCheckoutAttempt($subscription->refresh(), $plan, $connectedAccountId);
            $attemptUid = (string) $subscription->checkout_attempt_uid;

            $result = $this->gateway->createSubscriptionCheckout(
                connectedAccountId: $connectedAccountId,
                providerPriceId: (string) $plan->provider_price_id,
                clientReferenceId: (string) $subscription->uid,
                idempotencyKey: (string) $subscription->checkoutAttemptKey(),
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                customerEmail: $customerEmail,
                trialDays: $subscription->trial_days_snapshot,
                existingCustomerId: $subscription->provider_customer_id,
            );

            if ($this->recordSessionForAttempt($subscription, $attemptUid, $result->sessionId)) {
                return $result;
            }

            // Somebody replaced this attempt while we were talking to Stripe.
            // The session just created would still be payable, so retire it.
            $this->expireQuietly($connectedAccountId, $result->sessionId);
        }

        throw AgencyBillingException::because(AgencyBillingException::CHECKOUT_CONTENDED);
    }

    /**
     * §C7 — at most one payable session per client subscription, by
     * construction rather than by timing.
     *
     * @throws AgencyBillingException
     */
    private function resolveCheckoutAttempt(
        AgencyClientSubscription $subscription,
        AgencySaasPlan $plan,
        string $connectedAccountId,
    ): AgencyClientSubscription {
        for ($round = 0; $round < self::MAX_CHECKOUT_ROUNDS; $round++) {
            $subscription->refresh();

            // The CAS token: everything below is decided against the world as
            // it looked at this instant.
            $observedGeneration = (int) $subscription->checkout_attempt_generation;

            $priceId = (string) $plan->provider_price_id;
            $hasAttempt = $subscription->checkout_attempt_uid !== null;
            $samePrice = (string) $subscription->checkout_attempt_price_id === $priceId;

            if ($subscription->provider_checkout_session_id !== null) {
                $session = $this->gateway->retrieveCheckoutSession(
                    $connectedAccountId,
                    (string) $subscription->provider_checkout_session_id,
                );

                if ($session->status === 'complete') {
                    throw AgencyBillingException::because(AgencyBillingException::CHECKOUT_ALREADY_COMPLETED);
                }

                // The same request, retried: reuse the attempt as is.
                if ($hasAttempt && $samePrice && $session->status === 'open') {
                    return $subscription;
                }

                // A deliberate replacement: retire the old session first, so a
                // stale tab cannot still pay for the plan just left behind.
                if ($session->status === 'open') {
                    $this->expireQuietly($connectedAccountId, $session->sessionId);
                }
            } elseif ($hasAttempt && $samePrice) {
                // An attempt exists but no session id was recorded: the
                // creation response was lost. Re-drive the SAME key.
                return $subscription;
            }

            $minted = $this->mintCheckoutAttempt($subscription, $plan, $observedGeneration);

            if ($minted !== null) {
                return $minted;
            }

            // Another request advanced the attempt while we inspected provider
            // state. Its decision is now current, so start over from the new
            // database state rather than overwriting it.
        }

        throw AgencyBillingException::because(AgencyBillingException::CHECKOUT_CONTENDED);
    }

    /**
     * A NEW durable attempt, compare-and-swapped on
     * `checkout_attempt_generation`. The row lock alone was never enough: the
     * decision to replace is taken against provider state read OUTSIDE the
     * lock, so two concurrent requests could both decide to replace and both
     * mint.
     *
     * @return AgencyClientSubscription|null null when this caller was superseded
     */
    private function mintCheckoutAttempt(
        AgencyClientSubscription $subscription,
        AgencySaasPlan $plan,
        int $observedGeneration,
    ): ?AgencyClientSubscription {
        return DB::transaction(function () use ($subscription, $plan, $observedGeneration): ?AgencyClientSubscription {
            $locked = AgencyClientSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->checkout_attempt_generation !== $observedGeneration) {
                return null;
            }

            $locked->forceFill([
                'checkout_attempt_uid' => (string) Str::uuid(),
                'checkout_attempt_price_id' => $plan->provider_price_id,
                'checkout_attempt_started_at' => now(),
                'checkout_attempt_generation' => $observedGeneration + 1,
                // The retired session is no longer this subscription's.
                'provider_checkout_session_id' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * The compare-and-swap that makes a created session durable: the row is
     * locked, and the write happens ONLY if the attempt it was created for is
     * still current.
     */
    private function recordSessionForAttempt(AgencyClientSubscription $subscription, string $attemptUid, string $sessionId): bool
    {
        return DB::transaction(function () use ($subscription, $attemptUid, $sessionId): bool {
            $locked = AgencyClientSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ((string) $locked->checkout_attempt_uid !== $attemptUid) {
                return false;
            }

            $locked->forceFill(['provider_checkout_session_id' => $sessionId])->save();

            return true;
        });
    }

    /**
     * Retiring an orphan must never mask the real outcome of the caller's
     * operation: if the provider refuses the expire — a race where somebody
     * else already expired it, say — that is not this request's problem.
     */
    private function expireQuietly(string $connectedAccountId, string $sessionId): void
    {
        try {
            $this->gateway->expireCheckoutSession($connectedAccountId, $sessionId);
        } catch (Throwable $e) {
            Log::warning('AgencyClientSubscriptionManager could not expire a superseded checkout session', [
                'exception' => class_basename($e),
            ]);
        }
    }

    /**
     * Hand the ended provider subscription over to history and record the plan
     * the client is coming back on as a durable operation.
     */
    private function retireEndedSubscription(
        AgencyClientSubscription $subscription,
        AgencySaasPlan $plan,
        int $connectionId,
        string $connectedAccountId,
        int $actorUserId,
    ): AgencyClientSubscription {
        return DB::transaction(function () use ($subscription, $plan, $connectionId, $connectedAccountId, $actorUserId): AgencyClientSubscription {
            $locked = AgencyClientSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $retired = $locked->retiredProviderSubscriptionIds();

            if ($locked->provider_subscription_id !== null) {
                $retired[] = (string) $locked->provider_subscription_id;
            }

            $locked->forceFill([
                'retired_provider_subscription_ids' => array_values(array_unique($retired)),
                'provider_subscription_id' => null,
                // The completed session of the previous life is not this
                // subscription's any more.
                'provider_checkout_session_id' => null,
                'checkout_attempt_uid' => null,
                'checkout_attempt_price_id' => null,
                'checkout_attempt_generation' => (int) $locked->checkout_attempt_generation + 1,
                'cancel_at_period_end' => false,
                'agency_stripe_connection_id' => $connectionId,
                'connected_account_id' => $connectedAccountId,
                'consented_by_user_id' => $actorUserId,
                'consented_at' => now(),
                // The plan being bought, as a durable operation. The
                // entitlement moves only on provider confirmation.
                'pending_operation_uid' => (string) Str::uuid(),
                'pending_kind' => self::PENDING_RESUBSCRIBE,
                'pending_plan_id' => $plan->id,
                'pending_price_id' => $plan->provider_price_id,
                'pending_price_snapshot' => $plan->price,
                'pending_currency_id' => $plan->currency_id,
                'pending_currency_code' => $plan->currency_code,
                'pending_billing_cycle' => $plan->billing_cycle,
                'pending_effective_at' => null,
                // The attempt's own terms, so the new Checkout carries the
                // plan the client just chose.
                'trial_days_snapshot' => $plan->configuredTrialDays(),
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * §C7 — persist ONE durable plan-change operation before the provider is
     * touched. An in-flight operation is never silently replaced: the same
     * target re-drives it, a different target is refused.
     *
     * @throws AgencyBillingException
     */
    private function openPlanChangeOperation(
        AgencyClientSubscription $subscription,
        AgencySaasPlan $target,
        string $kind,
        ?\Carbon\CarbonInterface $effectiveAt,
    ): AgencyClientSubscription {
        return DB::transaction(function () use ($subscription, $target, $kind, $effectiveAt): AgencyClientSubscription {
            $locked = AgencyClientSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($locked->pending_operation_uid !== null && self::isInFlight($locked)) {
                if ((int) $locked->pending_plan_id !== (int) $target->id) {
                    throw AgencyBillingException::because(AgencyBillingException::CHANGE_IN_PROGRESS);
                }

                return $locked;
            }

            $locked->forceFill([
                'pending_operation_uid' => (string) Str::uuid(),
                'pending_kind' => $kind,
                'pending_plan_id' => $target->id,
                'pending_price_id' => $target->provider_price_id,
                // The terms AGREED AT REQUEST TIME.
                'pending_price_snapshot' => $target->price,
                'pending_currency_id' => $target->currency_id,
                'pending_currency_code' => $target->currency_code,
                'pending_billing_cycle' => $target->billing_cycle,
                'pending_effective_at' => $effectiveAt,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Whether a pending operation may already have been sent to the provider. A
     * scheduled downgrade whose boundary has not arrived has not been, so it is
     * still the client's to change their mind about.
     */
    private static function isInFlight(AgencyClientSubscription $subscription): bool
    {
        if ($subscription->pending_kind !== self::PENDING_DOWNGRADE) {
            return true;
        }

        return $subscription->pending_effective_at !== null
            && $subscription->pending_effective_at->lessThanOrEqualTo(now());
    }

    // =====================================================================
    // Authority (§C5.1 / §C6)
    // =====================================================================

    /**
     * §C6.1 — may this client be sold an Agency plan at all?
     *
     * The refusals are explicit and named, because the alternative — quietly
     * creating a second paid authority for one Workspace — is how a customer
     * ends up charged twice for one account. There is deliberately no automatic
     * migration from lane A: cancelling somebody's direct subscription on their
     * behalf is a financial action nobody in this system is authorized to take.
     *
     * @throws AgencyBillingException
     */
    public function assertClientEligible(Workspace $clientWorkspace): void
    {
        $assignment = $this->assignments->findByWorkspaceId((int) $clientWorkspace->id);

        if ($assignment !== null && (bool) $assignment->is_complimentary) {
            throw AgencyBillingException::because(AgencyBillingException::CLIENT_IS_COMPLIMENTARY);
        }

        // A LANE-A row is READ here, and only read, purely to refuse. Lane C
        // creates, mutates and owns nothing of lane A's — but it must be able
        // to see that the client is already paying us directly, because that is
        // exactly the collision this check exists to prevent.
        $platform = PlatformSubscription::query()
            ->where('workspace_id', $clientWorkspace->id)
            ->first();

        if ($platform !== null && $platform->status->grantsAccess()) {
            throw AgencyBillingException::because(AgencyBillingException::CLIENT_HAS_PLATFORM_SUBSCRIPTION);
        }

        $existing = $this->findForClientWorkspace($clientWorkspace);

        if ($existing !== null && $existing->status->isLive()) {
            throw AgencyBillingException::because(AgencyBillingException::CLIENT_ALREADY_SUBSCRIBED);
        }
    }

    /**
     * §C5.1 — revenue configuration and offers belong to the Agency Workspace
     * OWNER. Not Admin, not Staff: Agency team membership grants client
     * MANAGEMENT (Blueprint §28), never authority over the Agency's money.
     *
     * @throws AgencyBillingException
     */
    private function assertAgencyOwner(int $actorUserId, Workspace $agencyWorkspace): void
    {
        if ((int) $agencyWorkspace->owner_user_id !== $actorUserId) {
            throw AgencyBillingException::because(AgencyBillingException::CONSENT_NOT_AUTHORIZED);
        }
    }

    /**
     * §C6 — THE CONSENT BOUNDARY. Only the client's own owner, or an active
     * Admin of the client Workspace with account-frame authority, may authorize
     * a charge against the client's card.
     *
     * This deliberately refuses the Agency owner and Agency Staff even though
     * they can manage this client in every other respect: managing somebody's
     * account is not the same as agreeing to pay for it.
     *
     * @throws AgencyBillingException
     */
    private function assertClientFinancialAuthority(int $actorUserId, Workspace $clientWorkspace): void
    {
        if ((int) $clientWorkspace->owner_user_id === $actorUserId) {
            return;
        }

        $membership = $this->memberships->findByWorkspaceAndUser($clientWorkspace, $actorUserId);

        if ($membership === null
            || ! $membership->is_active
            || $membership->role !== WorkspaceMembershipRole::Admin
            || ! AccountFrameAccess::membershipAllows($membership)) {
            throw AgencyBillingException::because(AgencyBillingException::CONSENT_NOT_AUTHORIZED);
        }
    }

    /**
     * §C6 — a View As session can never fabricate financial consent.
     *
     * Structural rather than conventional: the refusal lives here, in the
     * manager, so it holds for every call site including any future one, and
     * does not depend on a route being remembered in a middleware list.
     *
     * @throws AgencyBillingException
     */
    private function assertNotViewingAs(int $actorUserId): void
    {
        $actor = User::query()->find($actorUserId);

        if ($actor !== null && $this->viewAs->current($actor) !== null) {
            throw AgencyBillingException::because(AgencyBillingException::CONSENT_THROUGH_VIEW_AS);
        }
    }

    /**
     * §C6 — the Agency↔Client management relationship is the canonical
     * authorization link (Addendum §2) and is asked of its own manager, never
     * re-derived here.
     *
     * @throws AgencyBillingException
     */
    private function assertActiveRelationship(Workspace $agencyWorkspace, Workspace $clientWorkspace): void
    {
        $relationship = $this->relationships->findActiveForClientWorkspace((int) $clientWorkspace->id);

        if ($relationship === null
            || (int) $relationship->agency_workspace_id !== (int) $agencyWorkspace->id) {
            throw AgencyBillingException::because(AgencyBillingException::NO_ACTIVE_RELATIONSHIP);
        }
    }

    /**
     * @throws AgencyBillingException
     */
    private function liveSubscriptionFor(Workspace $clientWorkspace): AgencyClientSubscription
    {
        $subscription = $this->findForClientWorkspace($clientWorkspace);

        if ($subscription === null
            || $subscription->provider_subscription_id === null
            || ! $subscription->status->isLive()) {
            throw AgencyBillingException::because(AgencyBillingException::NO_SUBSCRIPTION);
        }

        return $subscription;
    }

    private static function rankOf(AgencySaasPlan $plan): int
    {
        return self::TIER_RANK[$plan->tier->value] ?? 0;
    }
}
