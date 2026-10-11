<?php

namespace App\Library\PlatformBilling\Offers;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\PlatformBilling\BillingCycle;
use App\Library\PlatformBilling\CheckoutSessionResult;
use App\Library\PlatformBilling\CheckoutTerms;
use App\Library\PlatformBilling\PlatformStripeGateway;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Library\PlatformBilling\SchedulePhase;
use App\Library\PlatformOwner\PlatformAdminAuditLog;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use App\Library\Workspace\AccountFrameAccess;
use App\Models\Currency;
use App\Models\PlatformPrivateOffer;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The customer's side of Private Offers — and the only place an offer turns
 * into money moving.
 *
 * THE SEQUENCE a customer goes through, and what each step is allowed to do:
 *
 *   open link      read-only. Shows the terms. Changes nothing at all.
 *   accept terms   RESERVES one redemption slot (atomically, under a row lock)
 *                  and freezes the exact terms the customer was shown. Still
 *                  nothing is paid and nothing is entitled.
 *   Checkout       Stripe-hosted. The payment method is always collected.
 *   provider says  only a provider-confirmed subscription snapshot — via the
 *   "paid"         shared finalizer and OfferReconciler — redeems the offer,
 *                  starts the trial and grants entitlements.
 *
 * So a URL alone is never enough to activate a Workspace, grant entitlements or
 * mark an invoice paid; and a Workspace's trial starts only after the provider
 * confirms Checkout, never when the offer is created or opened.
 *
 * SAFETY, in one place:
 *   - the token is looked up by SHA-256, so lookup reveals nothing and a
 *     database leak yields no links;
 *   - an email-bound offer needs the VERIFIED email of the signed-in user to
 *     match exactly, and a Workspace-bound offer needs the user to hold billing
 *     authority over exactly that Workspace — the target Workspace is never read
 *     from the request;
 *   - capacity is counted under a lock on the offer row, and "one claim per
 *     Workspace per offer" is a unique key, so concurrent accepts cannot
 *     over-redeem or double-claim;
 *   - the accepted terms are re-derived on the server and compared by hash to
 *     what the customer was shown; if anything moved (including trial
 *     eligibility), they must accept again.
 */
final class PrivateOfferRedemptionManager
{
    /** Checkout window. Stripe allows 30 minutes to 24 hours. */
    private const CHECKOUT_MINUTES = 60;

    /** The reservation outlives the Checkout window slightly, so a payment landing at the very end is still honoured. */
    private const RESERVATION_GRACE_MINUTES = 10;

    public function __construct(
        private readonly PlatformSubscriptionManager $subscriptions,
        private readonly PlatformPriceProvisioner $prices,
        private readonly OfferTermsCalculator $calculator,
        private readonly TrialLedger $trials,
        private readonly PlatformStripeGateway $gateway,
        private readonly SubscriptionTermsHistory $history,
        private readonly OfferReconciler $reconciler,
        private readonly PrivateOfferManager $offers,
        private readonly WorkspaceMembershipRepository $memberships,
        private readonly PlatformAdminAuditLog $audit,
        private readonly PlatformOwnerAuthority $authority,
    ) {
    }

    public function findByToken(string $token): ?PlatformPrivateOffer
    {
        // A token is 43 URL-safe characters. Anything else cannot be one, and
        // is refused before it touches the database.
        if (! preg_match('/\A[A-Za-z0-9_-]{32,64}\z/', $token)) {
            return null;
        }

        return PlatformPrivateOffer::query()
            ->with(['catalog', 'currency'])
            ->where('token_hash', PlatformPrivateOffer::hashToken($token))
            ->first();
    }

    // =====================================================================
    // Read model for the customer's page
    // =====================================================================

    /**
     * What the offer page should show THIS viewer. Pure read: opening a link
     * changes nothing.
     *
     * `state`:
     *   unavailable       revoked, expired or fully used
     *   needs_login       a guest
     *   needs_verification  email-bound, and the viewer's email is unverified
     *   not_for_you       the viewer is not who the offer is for
     *   needs_account     signed in, but no Workspace to put the plan on yet
     *   subscribed        already has a subscription (a new-subscription offer)
     *   ready             terms can be accepted
     *   in_progress       this viewer already accepted and is paying
     *   redeemed          this viewer already redeemed it
     *
     * @return array<string, mixed>
     */
    public function present(PlatformPrivateOffer $offer, ?User $viewer): array
    {
        $view = [
            'offer' => $offer, 'state' => 'unavailable', 'terms' => null, 'workspace' => null,
            'redemption' => null, 'message' => null, 'requires_acceptance' => true,
        ];

        if ($offer->isRevoked() || $offer->isExpired()) {
            return $view;
        }

        if ($viewer === null) {
            $view['state'] = 'needs_login';
            // A guest sees the generic terms, assuming any trial is available.
            $view['terms'] = $this->safeTerms($offer, null, true);

            return $view;
        }

        try {
            $this->assertIdentity($offer, $viewer);
        } catch (PlatformBillingException $e) {
            $view['state'] = $e->reason === PlatformBillingException::OFFER_EMAIL_UNVERIFIED ? 'needs_verification' : 'not_for_you';
            $view['message'] = $e->customerMessage();

            return $view;
        }

        $workspace = $this->workspaceFor($offer, $viewer);

        if ($workspace === null) {
            $view['state'] = $offer->target_workspace_id !== null ? 'not_for_you' : 'needs_account';

            if ($view['state'] === 'needs_account') {
                $view['terms'] = $this->safeTerms($offer, null, true);
            }

            return $view;
        }

        $view['workspace'] = $workspace;
        $subscription = PlatformSubscription::query()->where('workspace_id', $workspace->id)->first();

        $own = PlatformPrivateOfferRedemption::query()
            ->where('platform_private_offer_id', $offer->id)
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('active_slot')
            ->first();

        if ($own !== null) {
            $view['redemption'] = $own;

            if ($own->state === PlatformPrivateOfferRedemption::STATE_REDEEMED) {
                $view['state'] = 'redeemed';
                $view['terms'] = OfferTerms::fromArray($own->terms_snapshot);

                return $view;
            }
        }

        if (! $offer->isBillingChange()) {
            if ($subscription !== null && $this->isLive($subscription)) {
                $view['state'] = 'subscribed';

                return $view;
            }

            if ($own === null && $this->capacityUsed($offer) >= $offer->redemption_limit) {
                return $view;
            }

            $eligible = $this->trials->eligible($workspace, (string) $this->ownerEmail($workspace), $subscription?->provider_customer_id);
            $view['terms'] = $this->safeTerms($offer, $workspace, $eligible);
            $view['state'] = $view['terms'] === null ? 'unavailable' : 'ready';

            if ($own !== null && $own->state === PlatformPrivateOfferRedemption::STATE_RESERVED && $own->reserved_until->isFuture()) {
                $view['state'] = 'in_progress';
            }

            return $view;
        }

        // ---- billing change ------------------------------------------------
        $existing = $this->existingTermsOf($subscription);

        if ($existing === null) {
            $view['state'] = 'unavailable';

            return $view;
        }

        $terms = $this->safeTerms($offer, $workspace, false, $existing);
        $view['terms'] = $terms;
        $view['state'] = $terms === null ? 'unavailable' : 'ready';
        $view['requires_acceptance'] = $terms === null ? true : $this->calculator->requiresAcceptance($terms);

        return $view;
    }

    // =====================================================================
    // New subscription: accept -> Checkout
    // =====================================================================

    /**
     * The customer explicitly accepts the terms; a hosted Checkout is opened.
     *
     * `$returnUrls` receives the reservation (whose uid the return URLs carry —
     * never the secret link token) and answers [success url, cancel url].
     *
     * @param  callable(PlatformPrivateOfferRedemption): array{0: string, 1: string}  $returnUrls
     *
     * @throws PlatformBillingException
     */
    public function accept(
        PlatformPrivateOffer $offer,
        User $actor,
        string $acceptedTermsHash,
        callable $returnUrls,
    ): CheckoutSessionResult {
        if ($offer->isBillingChange()) {
            throw PlatformBillingException::because(PlatformBillingException::OFFER_UNAVAILABLE);
        }

        $this->assertRedeemable($offer);
        $this->assertIdentity($offer, $actor);

        $workspace = $this->workspaceFor($offer, $actor)
            ?? throw PlatformBillingException::because(
                $offer->target_workspace_id !== null
                    ? PlatformBillingException::OFFER_NOT_FOR_YOU
                    : PlatformBillingException::NO_SUBSCRIPTION
            );

        $subscription = PlatformSubscription::query()->where('workspace_id', $workspace->id)->first();

        if ($subscription !== null && $this->isLive($subscription)) {
            throw PlatformBillingException::because(PlatformBillingException::ALREADY_SUBSCRIBED);
        }

        // Say "used up" plainly, before any trial is reserved or terms compared.
        $holdsASlot = PlatformPrivateOfferRedemption::query()
            ->where('platform_private_offer_id', $offer->id)
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('active_slot')
            ->exists();

        if (! $holdsASlot && $this->capacityUsed($offer) >= $offer->redemption_limit) {
            throw PlatformBillingException::because(PlatformBillingException::OFFER_FULLY_REDEEMED);
        }

        if (PlatformSubscriptionManager::agencyIsBillingWorkspace($workspace)) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_NOT_PERMITTED);
        }

        $catalog = $offer->catalog;
        $currency = $this->currencyOf($offer);
        $ownerEmail = (string) $this->ownerEmail($workspace);

        // ---- the trial: reserved first, so the terms below reflect the truth --
        $trialEligible = true;

        if ($offer->trial_days !== null) {
            $trialEligible = $this->trials->reserve($workspace, $ownerEmail, 'offer');
        }

        $terms = $this->termsOrFail($offer, $workspace, $trialEligible);

        if (! hash_equals($terms->hash(), $acceptedTermsHash)) {
            // What they were shown is not what would be charged (the offer
            // changed, a trial became unavailable, a price moved). They must
            // look again; nothing was reserved on their behalf.
            if ($offer->trial_days !== null && $trialEligible) {
                $this->trials->release($workspace);
            }

            throw PlatformBillingException::because(PlatformBillingException::OFFER_TERMS_CHANGED);
        }

        // ---- verified Stripe Prices (provider calls, no transaction open) ----
        $terms = $this->withProvisionedPrices($terms, $catalog, $currency);

        // ---- the atomic reservation ------------------------------------------
        try {
            $redemption = $this->reserve($offer, $workspace, $actor, $terms);
        } catch (PlatformBillingException $e) {
            if ($offer->trial_days !== null) {
                $this->trials->release($workspace);
            }

            throw $e;
        }

        // ---- Checkout --------------------------------------------------------
        $paid = $terms->paidPhases();
        // DERIVED FROM THE RESERVATION, never from "now": a re-submitted accept
        // must send Stripe byte-identical parameters for the same idempotency
        // key, and the reservation's end is the one stable instant.
        $expiresAt = $redemption->reserved_until->copy()->subMinutes(self::RESERVATION_GRACE_MINUTES);

        $checkoutTerms = new CheckoutTerms(
            catalog: $catalog,
            providerPriceId: (string) $paid[0]['price_id'],
            priceSnapshot: PlatformMoney::toDecimal((int) $paid[0]['price_minor'], $terms->currencyCode()),
            trialDays: $terms->trialDays(),
            redemption: $redemption,
            expiresAt: $expiresAt->getTimestamp(),
        );

        [$successUrl, $cancelUrl] = $returnUrls($redemption);

        $ended = $subscription !== null && PlatformSubscriptionManager::hasEnded($subscription);

        $result = $ended
            ? $this->subscriptions->startResubscribeCheckout($workspace, $catalog, $ownerEmail, $successUrl, $cancelUrl, $checkoutTerms)
            : $this->subscriptions->startCheckout($workspace, $catalog, $ownerEmail, $successUrl, $cancelUrl, $checkoutTerms);

        if (! $this->recordSession($redemption, $result->sessionId)) {
            // The offer was revoked (or the reservation released) while we were
            // talking to Stripe. The session just created must never be payable.
            try {
                $this->gateway->expireCheckoutSession($result->sessionId);
            } catch (\Throwable) {
                // Already unpayable, or the sweep will retire it.
            }

            throw PlatformBillingException::because(PlatformBillingException::OFFER_UNAVAILABLE);
        }

        return $result;
    }

    /**
     * The customer is back from Checkout. Provider truth decides — not the
     * redirect.
     *
     * @return string the redemption's state afterwards
     *
     * @throws PlatformBillingException
     */
    public function handleReturn(PlatformPrivateOfferRedemption $redemption, User $actor): string
    {
        $this->assertCanBill($redemption->workspace, $actor);

        if (! blank($redemption->provider_checkout_session_id)) {
            $this->subscriptions->confirmCheckoutSession((string) $redemption->provider_checkout_session_id);
        }

        return (string) $redemption->refresh()->state;
    }

    /** The customer backed out of Checkout. A paid Checkout is never released. */
    public function handleCancel(PlatformPrivateOfferRedemption $redemption, User $actor): void
    {
        $this->assertCanBill($redemption->workspace, $actor);

        $this->offers->retireUnpaid($redemption, 'cancelled');
    }

    // =====================================================================
    // Existing subscription: billing change
    // =====================================================================

    /**
     * The customer accepts new terms on their EXISTING subscription.
     *
     * @throws PlatformBillingException
     */
    public function acceptBillingChange(PlatformPrivateOffer $offer, User $actor, string $acceptedTermsHash): PlatformPrivateOfferRedemption
    {
        $this->assertRedeemable($offer);
        $this->assertIdentity($offer, $actor);

        $workspace = $this->workspaceFor($offer, $actor)
            ?? throw PlatformBillingException::because(PlatformBillingException::OFFER_NOT_FOR_YOU);

        return $this->applyBillingChange($offer, $workspace, $actor, $acceptedTermsHash, byOwner: false);
    }

    /**
     * The Platform Owner applies a change that cannot cost the customer more
     * than they pay today, so does not need their acceptance. Anything that
     * could raise the price at any point is refused here — it needs the
     * customer's yes.
     *
     * @throws PlatformBillingException
     */
    public function applyBillingChangeAsOwner(PlatformPrivateOffer $offer, int $adminUserId, string $reason): PlatformPrivateOfferRedemption
    {
        $this->authority->assertAdministrator($adminUserId);

        if (mb_strlen(trim($reason)) < 3) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_NOT_PERMITTED);
        }

        $this->assertRedeemable($offer);

        $workspace = $offer->workspace ?? throw PlatformBillingException::because(PlatformBillingException::OFFER_UNAVAILABLE);
        $subscription = PlatformSubscription::query()->where('workspace_id', $workspace->id)->first();
        $existing = $this->existingTermsOf($subscription) ?? throw PlatformBillingException::because(PlatformBillingException::NO_SUBSCRIPTION);

        $terms = $this->termsOrFail($offer, $workspace, false, $existing);

        if ($this->calculator->requiresAcceptance($terms)) {
            throw PlatformBillingException::because(PlatformBillingException::OFFER_NOT_FOR_YOU);
        }

        $admin = User::query()->findOrFail($adminUserId);
        $redemption = $this->applyBillingChange($offer, $workspace, $admin, $terms->hash(), byOwner: true);

        $this->audit->record($adminUserId, 'private_offer.billing_change_applied', 'private_offer', (string) $offer->uid,
            'Billing change applied without customer acceptance: ' . $offer->label, $reason);

        return $redemption;
    }

    /** Whether this change can never cost the customer more, so the owner may apply it directly. */
    public function ownerCanApplyWithoutAcceptance(PlatformPrivateOffer $offer): bool
    {
        if (! $offer->isBillingChange() || $offer->status() !== 'active' || $offer->target_workspace_id === null) {
            return false;
        }

        $workspace = Workspace::query()->find($offer->target_workspace_id);
        $subscription = $workspace === null ? null : PlatformSubscription::query()->where('workspace_id', $workspace->id)->first();
        $existing = $this->existingTermsOf($subscription);

        if ($workspace === null || $existing === null) {
            return false;
        }

        $terms = $this->safeTerms($offer, $workspace, false, $existing);

        return $terms !== null && ! $this->calculator->requiresAcceptance($terms);
    }

    /** @throws PlatformBillingException */
    private function applyBillingChange(PlatformPrivateOffer $offer, Workspace $workspace, User $actor, string $acceptedTermsHash, bool $byOwner): PlatformPrivateOfferRedemption
    {
        $subscription = PlatformSubscription::query()->where('workspace_id', $workspace->id)->first();
        $existing = $this->existingTermsOf($subscription)
            ?? throw PlatformBillingException::because(PlatformBillingException::NO_SUBSCRIPTION);

        if ((int) $subscription->workspace_plan_catalog_id !== (int) $offer->workspace_plan_catalog_id) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_NOT_PERMITTED);
        }

        $catalog = $offer->catalog;
        $currency = $this->currencyOf($offer);

        $terms = $this->termsOrFail($offer, $workspace, false, $existing);

        if (! hash_equals($terms->hash(), $acceptedTermsHash)) {
            throw PlatformBillingException::because(PlatformBillingException::OFFER_TERMS_CHANGED);
        }

        // PROVIDER TRUTH before changing anything: the subscription must still
        // be the one these terms were calculated against.
        $live = $this->gateway->retrieveSubscription((string) $subscription->provider_subscription_id);

        if ($live->cancelAtPeriodEnd
            || ! in_array($live->status, [PlatformSubscriptionStatus::Active, PlatformSubscriptionStatus::Trialing], true)
            || (string) $live->providerPriceId !== (string) $subscription->provider_price_id) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_IN_PROGRESS);
        }

        // The change starts where the PROVIDER says the current period ends,
        // not where our last-synced copy says it did. (Dates are not part of
        // the terms hash, so the customer's acceptance still stands.)
        $effective = ($live->trialEndsAt !== null && $live->trialEndsAt->isFuture()) ? $live->trialEndsAt : $live->periodEnd;

        if ($effective === null || $effective->lessThanOrEqualTo(now())) {
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_IN_PROGRESS);
        }

        $terms = $this->termsOrFail($offer, $workspace, false, [
            'amount_minor' => $existing['amount_minor'],
            'effective_at' => $effective,
        ]);

        $terms = $this->withProvisionedPrices($terms, $catalog, $currency)
            ->withExtra(['previous_price_id' => (string) $subscription->provider_price_id, 'applied_by_owner' => $byOwner]);

        $paid = $terms->paidPhases();

        if (count($paid) === 1 && (string) $paid[0]['price_id'] === (string) $subscription->provider_price_id) {
            // Same Price as today and nothing after it: there is nothing to change.
            throw PlatformBillingException::because(PlatformBillingException::CHANGE_NOT_PERMITTED);
        }

        $redemption = $this->reserve($offer, $workspace, $actor, $terms, ttlMinutes: 24 * 60);

        $this->pushBillingChange($redemption, $subscription, $terms);

        return $redemption->refresh();
    }

    /**
     * Puts the agreed Price sequence on the live subscription. Re-drivable: a
     * redemption left `reserved` by a provider failure is retried with the SAME
     * idempotency key by the next accept or the sweep.
     */
    private function pushBillingChange(PlatformPrivateOfferRedemption $redemption, PlatformSubscription $subscription, OfferTerms $terms): void
    {
        $paid = $terms->paidPhases();
        $effectiveAt = CarbonImmutable::parse((string) $paid[0]['starts_at']);

        $phases = [new SchedulePhase((string) $subscription->provider_price_id, $effectiveAt)];

        foreach ($paid as $phase) {
            $phases[] = new SchedulePhase(
                (string) $phase['price_id'],
                $phase['ends_at'] === null ? null : CarbonImmutable::parse((string) $phase['ends_at']),
            );
        }

        // A previous private agreement (and its schedule) is ended, explicitly,
        // because Stripe allows one schedule per subscription.
        $this->reconciler->endPrivatePricing($subscription);

        $snapshot = $this->gateway->applyPriceSchedule(
            (string) $subscription->provider_subscription_id,
            $phases,
            'offer-change:' . $redemption->uid,
        );

        $promoEnds = null;

        if (count($paid) > 1) {
            $promoEnds = CarbonImmutable::parse((string) $paid[0]['ends_at']);
        }

        DB::transaction(function () use ($redemption, $subscription, $snapshot, $terms, $paid, $promoEnds, $effectiveAt): void {
            $row = PlatformPrivateOfferRedemption::query()->whereKey($redemption->id)->lockForUpdate()->firstOrFail();

            $row->forceFill([
                'state' => PlatformPrivateOfferRedemption::STATE_REDEEMED,
                'redeemed_at' => now(),
                'platform_subscription_id' => $subscription->id,
                'provider_subscription_id' => $snapshot->providerSubscriptionId,
                'provider_schedule_id' => $snapshot->scheduleId,
                'schedule_state' => PlatformPrivateOfferRedemption::SCHEDULE_APPLIED,
                'promo_ends_at' => $promoEnds,
                'last_reconciled_at' => now(),
            ])->save();

            $sub = PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $sub->forceFill(['offer_redemption_id' => $row->id, 'provider_schedule_id' => $snapshot->scheduleId])->save();

            $this->history->record(
                $sub,
                SubscriptionTermsHistory::BILLING_CHANGE,
                [...$this->history->currentTermsOf($sub), 'offer_terms' => $terms->toArray(), 'redemption_uid' => $row->uid],
                $row->id,
                $effectiveAt,
            );
        });
    }

    /** Re-drives billing changes a provider failure left half-done. */
    public function sweepUnappliedBillingChanges(int $limit = 50): int
    {
        $done = 0;

        PlatformPrivateOfferRedemption::query()
            ->where('state', PlatformPrivateOfferRedemption::STATE_RESERVED)
            ->whereHas('offer', fn ($q) => $q->where('kind', PlatformPrivateOffer::KIND_BILLING_CHANGE))
            ->where('reserved_until', '>', now())
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (PlatformPrivateOfferRedemption $r) use (&$done): void {
                $subscription = PlatformSubscription::query()->where('workspace_id', $r->workspace_id)->first();

                if ($subscription === null) {
                    return;
                }

                try {
                    $this->pushBillingChange($r, $subscription, OfferTerms::fromArray($r->terms_snapshot));
                    $done++;
                } catch (\Throwable $e) {
                    report($e);
                }
            });

        return $done;
    }

    // =====================================================================
    // Internals
    // =====================================================================

    /** @throws PlatformBillingException */
    private function assertRedeemable(PlatformPrivateOffer $offer): void
    {
        if ($offer->isRevoked() || $offer->isExpired()) {
            throw PlatformBillingException::because(PlatformBillingException::OFFER_UNAVAILABLE);
        }
    }

    /**
     * Who may redeem. Email-bound: the VERIFIED email must match exactly.
     * Workspace-bound: checked separately by workspaceFor(). Both set: both.
     *
     * @throws PlatformBillingException
     */
    private function assertIdentity(PlatformPrivateOffer $offer, User $actor): void
    {
        if ($offer->customer_email === null) {
            return;
        }

        if (! hash_equals((string) $offer->customer_email, EmailIdentity::normalize((string) $actor->email))) {
            throw PlatformBillingException::because(PlatformBillingException::OFFER_NOT_FOR_YOU);
        }

        // Matching is not enough on its own: anybody can REGISTER with an email
        // address they do not control. Only a verified one proves it is theirs.
        if (! $actor->hasVerifiedEmail()) {
            throw PlatformBillingException::because(PlatformBillingException::OFFER_EMAIL_UNVERIFIED);
        }
    }

    /**
     * The Workspace the offer applies to — derived from the offer and the
     * signed-in user, NEVER from anything the request supplied. Null when the
     * user has no standing over the offer's Workspace, or owns none yet.
     */
    private function workspaceFor(PlatformPrivateOffer $offer, User $actor): ?Workspace
    {
        if ($offer->target_workspace_id !== null) {
            $workspace = Workspace::query()->find($offer->target_workspace_id);

            return $workspace !== null && $this->canBill($workspace, (int) $actor->id) ? $workspace : null;
        }

        return Workspace::query()->where('owner_user_id', $actor->id)->orderBy('id')->first();
    }

    private function canBill(Workspace $workspace, int $userId): bool
    {
        if ((int) $workspace->owner_user_id === $userId) {
            return true;
        }

        $membership = $this->memberships->findByWorkspaceAndUser($workspace, $userId);

        return $membership !== null
            && $membership->is_active
            && $membership->role === WorkspaceMembershipRole::Admin
            && AccountFrameAccess::membershipAllows($membership);
    }

    /** @throws PlatformBillingException */
    private function assertCanBill(?Workspace $workspace, User $actor): void
    {
        if ($workspace === null || ! $this->canBill($workspace, (int) $actor->id)) {
            throw PlatformBillingException::because(PlatformBillingException::OFFER_NOT_FOR_YOU);
        }
    }

    private function ownerEmail(Workspace $workspace): ?string
    {
        return User::query()->whereKey($workspace->owner_user_id)->value('email');
    }

    private function isLive(PlatformSubscription $subscription): bool
    {
        return ! in_array($subscription->status, [
            PlatformSubscriptionStatus::Pending,
            PlatformSubscriptionStatus::Canceled,
            PlatformSubscriptionStatus::IncompleteExpired,
        ], true);
    }

    private function currencyOf(PlatformPrivateOffer $offer): Currency
    {
        return $offer->currency ?? Currency::query()->findOrFail($offer->currency_id);
    }

    /** Live reservations and completed redemptions — everything occupying a slot. */
    private function capacityUsed(PlatformPrivateOffer $offer): int
    {
        return PlatformPrivateOfferRedemption::query()
            ->where('platform_private_offer_id', $offer->id)
            ->whereNotNull('active_slot')
            ->where(function ($q): void {
                $q->where('state', PlatformPrivateOfferRedemption::STATE_REDEEMED)
                    ->orWhere(fn ($r) => $r->where('state', PlatformPrivateOfferRedemption::STATE_RESERVED)->where('reserved_until', '>', now()));
            })
            ->count();
    }

    /** @return array{amount_minor: int, effective_at: \Carbon\CarbonInterface}|null */
    private function existingTermsOf(?PlatformSubscription $subscription): ?array
    {
        if ($subscription === null
            || blank($subscription->provider_subscription_id)
            || ! in_array($subscription->status, [PlatformSubscriptionStatus::Active, PlatformSubscriptionStatus::Trialing], true)
            || (bool) $subscription->cancel_at_period_end) {
            return null;
        }

        $effective = ($subscription->trial_ends_at !== null && $subscription->trial_ends_at->isFuture())
            ? $subscription->trial_ends_at
            : $subscription->current_period_end;

        $minor = $subscription->price_snapshot === null || $subscription->currency_code === null
            ? null
            : PlatformMoney::toMinor((string) $subscription->price_snapshot, (string) $subscription->currency_code);

        if ($effective === null || $minor === null) {
            return null;
        }

        return ['amount_minor' => $minor, 'effective_at' => $effective];
    }

    /** @param array{amount_minor: int, effective_at: \Carbon\CarbonInterface}|null $existing */
    private function safeTerms(PlatformPrivateOffer $offer, ?Workspace $workspace, bool $trialEligible, ?array $existing = null): ?OfferTerms
    {
        try {
            return $this->calculator->calculate(
                $offer, $offer->catalog, (string) $this->currencyOf($offer)->code, $trialEligible, now(), $existing,
            );
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array{amount_minor: int, effective_at: \Carbon\CarbonInterface}|null  $existing
     *
     * @throws PlatformBillingException
     */
    private function termsOrFail(PlatformPrivateOffer $offer, Workspace $workspace, bool $trialEligible, ?array $existing = null): OfferTerms
    {
        return $this->safeTerms($offer, $workspace, $trialEligible, $existing)
            ?? throw PlatformBillingException::because(PlatformBillingException::OFFER_UNAVAILABLE);
    }

    /**
     * Creates / verifies the Stripe Price for every paid amount in the terms.
     *
     * @throws PlatformBillingException
     */
    private function withProvisionedPrices(OfferTerms $terms, \App\Models\WorkspacePlanCatalog $catalog, Currency $currency): OfferTerms
    {
        $ids = [];

        foreach ($terms->requiredAmountsMinor() as $amount) {
            $ids[$amount] = $this->prices->priceIdFor($catalog, $amount, (string) $currency->code);
        }

        return $terms->withPriceIds($ids);
    }

    /**
     * The atomic step. Under a lock on the offer row: re-check the offer, honour
     * an identical in-flight reservation (idempotent re-submit), replace a
     * stale one, count capacity, and claim the slot — the unique
     * (offer, active_slot) key being the backstop for "one claim per Workspace".
     *
     * @throws PlatformBillingException
     */
    private function reserve(PlatformPrivateOffer $offer, Workspace $workspace, User $actor, OfferTerms $terms, int $ttlMinutes = self::CHECKOUT_MINUTES + self::RESERVATION_GRACE_MINUTES): PlatformPrivateOfferRedemption
    {
        try {
            return DB::transaction(function () use ($offer, $workspace, $actor, $terms, $ttlMinutes): PlatformPrivateOfferRedemption {
                $locked = PlatformPrivateOffer::query()->whereKey($offer->id)->lockForUpdate()->firstOrFail();

                if ($locked->isRevoked() || $locked->isExpired()) {
                    throw PlatformBillingException::because(PlatformBillingException::OFFER_UNAVAILABLE);
                }

                $mine = PlatformPrivateOfferRedemption::query()
                    ->where('platform_private_offer_id', $locked->id)
                    ->where('workspace_id', $workspace->id)
                    ->whereNotNull('active_slot')
                    ->lockForUpdate()
                    ->first();

                if ($mine !== null) {
                    if ($mine->state === PlatformPrivateOfferRedemption::STATE_REDEEMED) {
                        throw PlatformBillingException::because(PlatformBillingException::OFFER_FULLY_REDEEMED);
                    }

                    if ($mine->terms_hash === $terms->hash() && $mine->reserved_until->isFuture()) {
                        // The very same acceptance, submitted again.
                        return $mine;
                    }

                    $mine->forceFill([
                        'state' => PlatformPrivateOfferRedemption::STATE_RELEASED,
                        'active_slot' => null,
                        'released_at' => now(),
                        'release_reason' => 'superseded',
                    ])->save();
                }

                if ($this->capacityUsed($locked) >= $locked->redemption_limit) {
                    throw PlatformBillingException::because(PlatformBillingException::OFFER_FULLY_REDEEMED);
                }

                $paid = $terms->paidPhases();

                $row = new PlatformPrivateOfferRedemption();
                $row->forceFill([
                    'platform_private_offer_id' => $locked->id,
                    'workspace_id' => $workspace->id,
                    'actor_user_id' => $actor->id,
                    'state' => PlatformPrivateOfferRedemption::STATE_RESERVED,
                    'active_slot' => 'ws:' . $workspace->id,
                    'terms_snapshot' => $terms->toArray(),
                    'terms_hash' => $terms->hash(),
                    'accepted_at' => now(),
                    'accepted_email' => EmailIdentity::normalize((string) $actor->email),
                    'reserved_until' => now()->addMinutes($ttlMinutes),
                    'initial_price_id' => $paid[0]['price_id'] ?? null,
                    'standard_price_id' => $paid[count($paid) - 1]['price_id'] ?? null,
                    'schedule_state' => PlatformPrivateOfferRedemption::SCHEDULE_NONE,
                ])->save();

                return $row->refresh();
            });
        } catch (QueryException $e) {
            // The unique (offer, active_slot) key refused a concurrent duplicate
            // claim that slipped between our read and our insert.
            if ((string) ($e->errorInfo[0] ?? '') === '23000') {
                throw PlatformBillingException::because(PlatformBillingException::OFFER_FULLY_REDEEMED);
            }

            throw $e;
        }
    }

    /** False when the reservation is no longer ours to attach a session to. */
    private function recordSession(PlatformPrivateOfferRedemption $redemption, string $sessionId): bool
    {
        return DB::transaction(function () use ($redemption, $sessionId): bool {
            $row = PlatformPrivateOfferRedemption::query()->whereKey($redemption->id)->lockForUpdate()->firstOrFail();

            if ($row->state !== PlatformPrivateOfferRedemption::STATE_RESERVED) {
                // Redeemed already counts as fine (an idempotent re-submit that
                // reused the paid session); released does not.
                return $row->state === PlatformPrivateOfferRedemption::STATE_REDEEMED;
            }

            $subscription = PlatformSubscription::query()->where('workspace_id', $row->workspace_id)->first();

            $row->forceFill([
                'provider_checkout_session_id' => $sessionId,
                'platform_subscription_id' => $subscription?->id,
            ])->save();

            return true;
        });
    }
}
