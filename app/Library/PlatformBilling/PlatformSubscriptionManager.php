<?php

namespace App\Library\PlatformBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientSubscription;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

    /**
     * §10 — the three kinds of DURABLE PLAN-CHANGE OPERATION.
     *
     * An operation is persisted BEFORE the provider is touched and cleared
     * only once provider truth proves the target Price is live. `upgrade` and
     * `resubscribe` reach the provider immediately; `downgrade` is parked until
     * the period boundary and reaches it from the scheduled sweep.
     */
    public const PENDING_UPGRADE = 'upgrade';
    public const PENDING_DOWNGRADE = 'downgrade';
    public const PENDING_RESUBSCRIBE = 'resubscribe';

    /** How many times a contended checkout re-resolves before giving up. */
    private const MAX_CHECKOUT_ROUNDS = 3;

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

        // ---- 1. durable local row, committed, no network -----------------
        $subscription = DB::transaction(function () use ($workspace, $catalog) {
            $existing = PlatformSubscription::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();

            if ($existing !== null && $this->isLive($existing)) {
                throw PlatformBillingException::because(PlatformBillingException::ALREADY_SUBSCRIBED);
            }

            if ($existing !== null) {
                return $existing;
            }

            // A Workspace that never completed checkout, or whose previous
            // subscription ended, re-drives its own row rather than
            // accumulating a second one that could disagree about which is
            // current. `unique(workspace_id)` makes that structural.
            //
            // ONE INSERT carrying the complete durable identity: the UID is
            // minted here so `local_idempotency_key`, which is derived from it
            // and is UNIQUE, is part of the same statement.
            $row = new PlatformSubscription(['workspace_id' => $workspace->id]);
            $row->generateUid();
            $row->local_idempotency_key = PlatformSubscription::idempotencyKeyFor((string) $row->uid);
            $row->status = PlatformSubscriptionStatus::Pending->value;
            $row->billing_cycle_snapshot = (string) $catalog->billing_cycle;
            $row->workspace_plan_catalog_id = $catalog->id;
            $row->save();

            return $row->refresh();
        });

        return $this->driveCheckout($subscription, $catalog, $customerEmail, $successUrl, $cancelUrl);
    }

    /**
     * §7/§10.4 — RE-SUBSCRIBE an account whose subscription has fully ended.
     *
     * A Canceled or IncompleteExpired customer has no provider relationship
     * left to change, so `requestPlanChange()` correctly refuses them. Without
     * this path that refusal was a dead end: the page still offered
     * upgrade/downgrade controls that could only ever fail, and a customer who
     * wanted to come back had nowhere to go.
     *
     * THE ACCOUNT IS REUSED, NOT REBUILT. The same Workspace, the same
     * Business and the same Locations, and the same local subscription row —
     * because `unique(workspace_id)` makes one row per Workspace structural,
     * and because provisioning a second account would strand every piece of
     * the customer's data behind the old one.
     *
     * OLD PROVIDER HISTORY IS RETIRED, NOT ERASED. The finished provider
     * subscription id moves to `retired_provider_subscription_ids`, where it
     * stays for audit AND, more importantly, where the finalizer's cross-check
     * can refuse it: a late `customer.subscription.deleted` for the OLD
     * subscription must never be able to cancel the NEW one it knows nothing
     * about. Nulling the identifier without recording it would have made those
     * two subscriptions indistinguishable.
     *
     * NOTHING IS MARKED PAID. The row keeps its ended status until the provider
     * confirms the new subscription; §7's "no fabricated Active state" is why
     * the status is left alone here rather than optimistically reset.
     *
     * @throws PlatformBillingException
     */
    public function startResubscribeCheckout(
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

        $subscription = $this->findForWorkspace($workspace);

        if ($subscription === null || ! self::hasEnded($subscription)) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_NOT_PERMITTED);
        }

        if (self::agencyIsBillingWorkspace($workspace)) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_NOT_PERMITTED);
        }

        $subscription = $this->retireEndedSubscription($subscription, $catalog);

        return $this->driveCheckout($subscription, $catalog, $customerEmail, $successUrl, $cancelUrl);
    }

    /**
     * Hands the ended provider subscription over to history and records the
     * tier this customer is coming back on as a DURABLE OPERATION, so the
     * canonical entitlement moves to the newly purchased tier when — and only
     * when — the provider confirms it.
     */
    private function retireEndedSubscription(PlatformSubscription $subscription, WorkspacePlanCatalog $catalog): PlatformSubscription
    {
        return DB::transaction(function () use ($subscription, $catalog): PlatformSubscription {
            $locked = PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            $retired = $locked->retiredProviderSubscriptionIds();

            if ($locked->provider_subscription_id !== null) {
                $retired[] = (string) $locked->provider_subscription_id;
            }

            $locked->forceFill([
                'retired_provider_subscription_ids' => array_values(array_unique($retired)),
                'provider_subscription_id' => null,
                // The completed session of the previous life is not this
                // subscription's any more; leaving it would make the attempt
                // resolver refuse the customer with "already paid".
                'provider_checkout_session_id' => null,
                'checkout_attempt_uid' => null,
                'checkout_attempt_price_id' => null,
                // Supersede anything currently in flight (§7's CAS token).
                'checkout_attempt_generation' => (int) $locked->checkout_attempt_generation + 1,
                'cancel_at_period_end' => false,
                // §10 — the tier being bought, as a durable operation. The
                // entitlement moves only on provider confirmation.
                'pending_operation_uid' => (string) Str::uuid(),
                'pending_kind' => self::PENDING_RESUBSCRIBE,
                'pending_plan_catalog_id' => $catalog->id,
                'pending_price_id' => $catalog->provider_price_id,
                'pending_price_snapshot' => $catalog->price,
                'pending_currency_id' => $catalog->currency_id,
                'pending_currency_code' => $catalog->currency?->code,
                'pending_billing_cycle' => (string) $catalog->billing_cycle,
                'pending_effective_at' => null,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * §7 — resolve the attempt, create the session, and prove we are still the
     * current attempt when we persist it.
     *
     * BOUNDED, never unbounded recursion: a request that keeps losing the race
     * gives up rather than spinning.
     *
     * @throws PlatformBillingException
     */
    private function driveCheckout(
        PlatformSubscription $subscription,
        WorkspacePlanCatalog $catalog,
        string $customerEmail,
        string $successUrl,
        string $cancelUrl,
    ): CheckoutSessionResult {
        for ($round = 0; $round < self::MAX_CHECKOUT_ROUNDS; $round++) {
            $subscription = $this->resolveCheckoutAttempt($subscription->refresh(), $catalog);
            $attemptUid = (string) $subscription->checkout_attempt_uid;

            // The idempotency key is the ATTEMPT's, not the subscription's. Two
            // calls for the same attempt carry identical parameters, so Stripe
            // returns the original session; a deliberate new attempt carries a
            // new key, which is the only way a different Price may legally be
            // sent.
            $result = $this->gateway->createSubscriptionCheckout(
                providerPriceId: (string) $catalog->provider_price_id,
                clientReferenceId: (string) $subscription->uid,
                idempotencyKey: (string) $subscription->checkoutAttemptKey(),
                successUrl: $successUrl,
                cancelUrl: $cancelUrl,
                customerEmail: $customerEmail,
                trialDays: $subscription->trial_days_snapshot,
                existingCustomerId: $subscription->provider_customer_id,
            );

            // Only the session id is recorded, and only if THIS attempt is
            // still the current one.
            //
            // `provider_customer_id` is DELIBERATELY not taken from the
            // checkout result: it becomes authoritative when the finalizer
            // reads it off the confirmed subscription. Writing it here would
            // change the parameters the next call to this attempt sends —
            // Stripe's idempotency layer "compares incoming parameters to
            // those of the original request and errors if they're not the
            // same" — so an honest retry of one uncertain request would be
            // rejected by the provider.
            if ($this->recordSessionForAttempt($subscription, $attemptUid, $result->sessionId)) {
                return $result;
            }

            // Somebody replaced this attempt while we were talking to Stripe.
            // The session we just created is now an ORPHAN that would still be
            // payable, so retire it before trying again — this is the case the
            // "at most one payable session" invariant would otherwise lose.
            $this->expireQuietly($result->sessionId);
        }

        throw PlatformBillingException::because(PlatformBillingException::CHECKOUT_CONTENDED);
    }

    /**
     * The compare-and-swap that makes a created session durable: the row is
     * locked, and the write happens ONLY if the attempt we created it for is
     * still current.
     */
    private function recordSessionForAttempt(PlatformSubscription $subscription, string $attemptUid, string $sessionId): bool
    {
        return DB::transaction(function () use ($subscription, $attemptUid, $sessionId): bool {
            $locked = PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ((string) $locked->checkout_attempt_uid !== $attemptUid) {
                return false;
            }

            $locked->forceFill(['provider_checkout_session_id' => $sessionId])->save();

            return true;
        });
    }

    /**
     * Retiring an orphan must never mask the real outcome of the caller's
     * operation: if the provider refuses the expire (a race where somebody
     * else already expired it, say), that is not this request's problem.
     */
    private function expireQuietly(string $sessionId): void
    {
        try {
            $this->gateway->expireCheckoutSession($sessionId);
        } catch (Throwable $e) {
            Log::warning('PlatformSubscriptionManager could not expire a superseded checkout session', [
                'exception' => class_basename($e),
            ]);
        }
    }

    /**
     * §7 — CHECKOUT ATTEMPT IDENTITY.
     *
     * Stripe's idempotency layer "compares incoming parameters to those of the
     * original request and errors if they're not the same", and a key may be
     * pruned after 24 hours. One key per subscription therefore models a RETRY
     * of one uncertain request correctly and a DELIBERATE SECOND ATTEMPT
     * incorrectly — a customer who opens Growth checkout, cancels, and picks
     * Agency would resend one key with a different Price.
     *
     * Minting a random key per click is worse: two payable Checkout Sessions
     * for one Workspace means two possible subscriptions.
     *
     * So:
     *
     *   A. SAME PRICE, attempt already exists → the SAME attempt key. Identical
     *      parameters, so Stripe returns the original session. This is the
     *      uncertain-response retry, and it is free.
     *
     *   B. DIFFERENT PRICE (or no attempt yet) → the previous session is first
     *      proven terminal or EXPIRED at the provider, and only then is a new
     *      durable attempt minted. At most one payable session exists at any
     *      moment, by construction rather than by timing.
     *
     *   C. PREVIOUS SESSION ALREADY COMPLETE → refuse. The customer has paid;
     *      starting a second checkout would be a second subscription. The
     *      webhook or the success endpoint converges that account instead.
     *
     * @throws PlatformBillingException
     */
    private function resolveCheckoutAttempt(PlatformSubscription $subscription, WorkspacePlanCatalog $catalog): PlatformSubscription
    {
        for ($round = 0; $round < self::MAX_CHECKOUT_ROUNDS; $round++) {
            $subscription->refresh();

            // THE CAS TOKEN. Everything below is decided against the world as
            // it looked at this instant; the mint will refuse to commit if the
            // world moved on, which is the whole point.
            $observedGeneration = (int) $subscription->checkout_attempt_generation;

            $priceId = (string) $catalog->provider_price_id;
            $hasAttempt = $subscription->checkout_attempt_uid !== null;
            $samePrice = (string) $subscription->checkout_attempt_price_id === $priceId;

            if ($subscription->provider_checkout_session_id !== null) {
                $session = $this->gateway->retrieveCheckoutSession((string) $subscription->provider_checkout_session_id);

                if ($session->status === 'complete') {
                    throw PlatformBillingException::because(PlatformBillingException::CHECKOUT_ALREADY_COMPLETED);
                }

                // A — the same request, retried. Reuse the attempt as is.
                if ($hasAttempt && $samePrice && $session->status === 'open') {
                    return $subscription;
                }

                // B — a deliberate replacement. Retire the old session FIRST,
                // so a stale tab cannot still pay for the plan the customer
                // just left. Quietly, because a concurrent replacement may
                // legitimately have expired it a moment ago.
                if ($session->status === 'open') {
                    $this->expireQuietly($session->sessionId);
                }
            } elseif ($hasAttempt && $samePrice) {
                // An attempt exists but no session id was ever recorded: the
                // creation response was lost. Re-drive the SAME key, which is
                // exactly what Stripe's idempotency is for.
                return $subscription;
            }

            $minted = $this->mintCheckoutAttempt($subscription, $catalog, $observedGeneration);

            if ($minted !== null) {
                return $minted;
            }

            // Another request advanced the attempt while we were inspecting
            // provider state. Its decision is now the current one, so start
            // over from the new database state rather than overwriting it.
        }

        throw PlatformBillingException::because(PlatformBillingException::CHECKOUT_CONTENDED);
    }

    /**
     * A NEW durable attempt, with the commercial terms SNAPSHOTTED onto it
     * (§6/§8) so a later catalog edit cannot rewrite what this customer is
     * about to buy.
     *
     * COMPARE-AND-SWAP on `checkout_attempt_generation`. The row lock alone was
     * never enough: the decision to replace was taken against provider state
     * read OUTSIDE the lock, so two concurrent requests could both decide to
     * replace the same session and both mint. Proving the generation is
     * unchanged is what makes "at most one payable session per Workspace" true
     * under concurrency rather than only in sequence.
     *
     * @return PlatformSubscription|null null when this caller was superseded
     */
    private function mintCheckoutAttempt(
        PlatformSubscription $subscription,
        WorkspacePlanCatalog $catalog,
        int $observedGeneration,
    ): ?PlatformSubscription {
        return DB::transaction(function () use ($subscription, $catalog, $observedGeneration): ?PlatformSubscription {
            $locked = PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ((int) $locked->checkout_attempt_generation !== $observedGeneration) {
                return null;
            }

            $locked->forceFill([
                'checkout_attempt_uid' => (string) Str::uuid(),
                'checkout_attempt_price_id' => $catalog->provider_price_id,
                'checkout_attempt_started_at' => now(),
                'checkout_attempt_generation' => $observedGeneration + 1,
                // The retired session is no longer this subscription's.
                'provider_checkout_session_id' => null,
                'workspace_plan_catalog_id' => $catalog->id,
                'price_snapshot' => $catalog->price,
                'currency_id' => $catalog->currency_id,
                'currency_code' => $catalog->currency?->code,
                'billing_cycle_snapshot' => (string) $catalog->billing_cycle,
                'trial_days_snapshot' => $catalog->configuredTrialDays(),
                'provider_price_id' => $catalog->provider_price_id,
            ])->save();

            return $locked->refresh();
        });
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
        $kind = $isUpgrade ? self::PENDING_UPGRADE : self::PENDING_DOWNGRADE;

        // ---- 1. THE DURABLE OPERATION, WRITTEN BEFORE STRIPE IS TOUCHED ---
        //
        // The previous shape called the provider and then wrote locally. A
        // lost HTTP response, or any exception between the two, left the
        // customer PAYING for the new tier while RECEIVING the old one, with
        // nothing able to repair it: the webhook finalizer mirrors provider
        // price and status, and had no idea which local tier that price was
        // supposed to mean. Recording the intent first is what turns that gap
        // into an operation that converges.
        $subscription = $this->openPlanChangeOperation(
            $subscription,
            $target,
            $kind,
            $isUpgrade ? now() : $subscription->current_period_end,
        );

        if (! $isUpgrade) {
            // §10.2 — parked, not applied. No provider call at all today, and
            // the COMMERCIAL TERMS ARE SNAPSHOTTED NOW: a customer who
            // scheduled today's Core price must not be moved onto a repriced
            // Core when the boundary arrives weeks later.
            return self::CHANGE_DOWNGRADE_SCHEDULED;
        }

        // ---- 2. the provider, outside every transaction ------------------
        //
        // The key is the OPERATION's, never the target catalog id: the same
        // catalog can later carry a different immutable Stripe Price, and
        // Stripe errors when one key is reused with different parameters.
        $snapshot = $this->gateway->changeSubscriptionPrice(
            (string) $subscription->provider_subscription_id,
            (string) $subscription->pending_price_id,
            prorate: true,
            idempotencyKey: (string) $subscription->planChangeKey(),
        );

        // ---- 3. converge, through the SHARED finalizer -------------------
        //
        // Deliberately the same seam a later webhook uses. It widens the
        // entitlement only when provider truth shows the target Price actually
        // active, so a provider call that did not take effect cannot hand this
        // customer a tier they are not being charged for.
        $this->finalizer->apply($subscription->refresh(), $snapshot);

        return self::CHANGE_UPGRADED;
    }

    /**
     * §10 — persist ONE durable plan-change operation: the target catalog, the
     * target provider Price, the target commercial terms, and a unique
     * operation identity that is also the provider idempotency key's source.
     *
     * AN IN-FLIGHT OPERATION IS NOT SILENTLY REPLACED. One that may already
     * have reached the provider — an immediate upgrade, a re-subscribe, or a
     * downgrade whose boundary has passed — is either RE-DRIVEN when the
     * target is the same (which is exactly what an idempotency key is for) or
     * refused when it is not. Overwriting it would leave the provider on one
     * Price and the local record converging towards another.
     *
     * @throws PlatformBillingException
     */
    private function openPlanChangeOperation(
        PlatformSubscription $subscription,
        WorkspacePlanCatalog $target,
        string $kind,
        ?\Carbon\CarbonInterface $effectiveAt,
    ): PlatformSubscription {
        return DB::transaction(function () use ($subscription, $target, $kind, $effectiveAt): PlatformSubscription {
            $locked = PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();

            if ($locked->pending_operation_uid !== null && self::isInFlight($locked)) {
                if ((int) $locked->pending_plan_catalog_id !== (int) $target->id) {
                    throw PlatformBillingException::because(PlatformBillingException::CHANGE_IN_PROGRESS);
                }

                // Same target, same operation, same key: a retry.
                return $locked;
            }

            $locked->forceFill([
                'pending_operation_uid' => (string) Str::uuid(),
                'pending_kind' => $kind,
                'pending_plan_catalog_id' => $target->id,
                'pending_price_id' => $target->provider_price_id,
                // §10.1 — the terms AGREED AT REQUEST TIME.
                'pending_price_snapshot' => $target->price,
                'pending_currency_id' => $target->currency_id,
                'pending_currency_code' => $target->currency?->code,
                'pending_billing_cycle' => (string) $target->billing_cycle,
                'pending_effective_at' => $effectiveAt,
            ])->save();

            return $locked->refresh();
        });
    }

    /**
     * Whether a pending operation may already have been sent to the provider.
     * A scheduled downgrade whose boundary has not arrived has not been, so it
     * is still the customer's to change their mind about.
     */
    private static function isInFlight(PlatformSubscription $subscription): bool
    {
        if ($subscription->pending_kind !== self::PENDING_DOWNGRADE) {
            return true;
        }

        return $subscription->pending_effective_at !== null
            && $subscription->pending_effective_at->lessThanOrEqualTo(now());
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
     * EVERYTHING SENT TO THE PROVIDER COMES FROM THE OPERATION, NOT FROM
     * TODAY'S CATALOG. The Price, the amount, the currency and the cycle are
     * the ones the customer agreed to when they REQUESTED the downgrade. A
     * customer who scheduled a 97.00 Core plan gets 97.00 Core at the boundary,
     * even if the Platform Owner has since repriced that tier to 147.00 — and
     * because the idempotency key belongs to the operation rather than to the
     * catalog, a repriced tier can never collide with a key already spent on
     * different parameters.
     *
     * LOCAL STATE MOVES ONLY THROUGH THE SHARED FINALIZER, which requires
     * provider truth to show the target Price active. A provider call that
     * succeeded while the local write failed therefore converges on the next
     * sweep or on the next webhook, rather than being lost.
     *
     * Bounded, per-row transactions, and a Throwable on one row logged without
     * aborting the batch — the SweepExpiredOpportunitySnoozes convention the
     * repository already uses for scheduled work.
     */
    public function applyDuePendingPlanChanges(int $limit): int
    {
        $candidates = PlatformSubscription::query()
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
                if (blank($subscription->pending_price_id) || blank($subscription->provider_subscription_id)) {
                    continue;
                }

                // No proration: the customer already paid for the period that
                // just ended, and the cheaper tier simply starts now.
                $snapshot = $this->gateway->changeSubscriptionPrice(
                    (string) $subscription->provider_subscription_id,
                    (string) $subscription->pending_price_id,
                    prorate: false,
                    idempotencyKey: (string) $subscription->planChangeKey(),
                );

                if ($this->finalizer->apply($subscription->refresh(), $snapshot) === PlatformSubscriptionFinalizer::APPLIED) {
                    $applied++;
                }
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

    /**
     * §10.4 — TERMINALLY ENDED, as distinct from never-started.
     *
     * `pending` is also "not live", but it is a checkout that has not finished
     * yet, not a relationship that is over; offering that customer "start
     * again" would be offering them a second checkout for the one they are
     * already in.
     */
    /**
     * §2 / Lane C §C6.1 — ONE paid authority per Workspace, in both directions.
     *
     * Lane C already refuses to enrol a client whose lane-A subscription is
     * live. This is the mirror image: a Workspace whose previous platform
     * subscription ended, and which its managing Agency now bills through lane
     * C, must not "start again" on lane A as well — the customer would be
     * charged twice and two lifecycles would drive one canonical assignment.
     *
     * A lane-C row is READ here, and only read, purely to refuse. Lane A
     * creates, mutates and owns nothing of lane C's, and no App\Library\
     * AgencyBilling code is referenced. An `offered` row (a proposal nobody has
     * accepted) and an ended one compete with nothing.
     */
    public static function agencyIsBillingWorkspace(Workspace $workspace): bool
    {
        return AgencyClientSubscription::query()
            ->where('client_workspace_id', $workspace->id)
            ->whereNotIn('status', [
                AgencyClientSubscriptionStatus::Offered->value,
                AgencyClientSubscriptionStatus::Canceled->value,
                AgencyClientSubscriptionStatus::IncompleteExpired->value,
            ])
            ->exists();
    }

    public static function hasEnded(PlatformSubscription $subscription): bool
    {
        return in_array($subscription->status, [
            PlatformSubscriptionStatus::Canceled,
            PlatformSubscriptionStatus::IncompleteExpired,
        ], true);
    }

    private static function rankOf(WorkspacePlanCatalog $catalog): int
    {
        return self::TIER_RANK[$catalog->tier->value] ?? 0;
    }
}
