<?php

namespace App\Library\PlatformBilling\Offers;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Library\PlatformBilling\BillingCycle;
use App\Library\PlatformBilling\PlatformStripeGateway;
use App\Library\PlatformBilling\PlatformSubscriptionSnapshot;
use App\Library\PlatformBilling\SchedulePhase;
use App\Models\PlatformPrivateOfferRedemption;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconciles a Private Offer with PROVIDER TRUTH.
 *
 * This is where "a URL alone never activates anything" is made structural: an
 * offer redemption becomes `redeemed` only here, only after a provider-confirmed
 * subscription snapshot says the customer's Checkout produced a live
 * subscription, and only for the redemption that subscription is linked to.
 * Opening a link, accepting terms and being redirected back all do nothing to
 * this state.
 *
 * Called after the shared finalizer has applied a snapshot, from every path
 * that learns provider state — the Checkout return, the webhook consumer and the
 * scheduled sweep — and idempotent, so a replayed webhook converges on the same
 * state rather than repeating work. The database half runs first and needs no
 * network; the one provider call (putting a temporary promotion on a Stripe
 * schedule) happens afterwards, outside every transaction, and is retried by
 * the sweep if it fails.
 */
final class OfferReconciler
{
    public function __construct(
        private readonly PlatformStripeGateway $gateway,
        private readonly TrialLedger $trials,
        private readonly SubscriptionTermsHistory $history,
    ) {
    }

    /** Entry point for the manager, after the finalizer. */
    public function afterProviderApply(PlatformSubscription $subscription, PlatformSubscriptionSnapshot $snapshot): void
    {
        $this->recordTrialIfStarted($subscription, $snapshot);

        if ($subscription->offer_redemption_id === null) {
            return;
        }

        $redemption = $this->confirmRedemption($subscription, $snapshot);

        if ($redemption === null) {
            return;
        }

        $this->observe($subscription->refresh(), $redemption->refresh(), $snapshot);

        $this->driveSchedule($redemption->refresh());
    }

    /**
     * The provider has confirmed a trial for this subscription: it is USED, for
     * good, whatever happens to the subscription afterwards.
     */
    private function recordTrialIfStarted(PlatformSubscription $subscription, PlatformSubscriptionSnapshot $snapshot): void
    {
        if ($subscription->trial_days_snapshot === null) {
            return;
        }

        $confirmed = $snapshot->status === PlatformSubscriptionStatus::Trialing
            || ($snapshot->trialEndsAt !== null && $snapshot->status->grantsAccess())
            || $snapshot->status === PlatformSubscriptionStatus::Active;

        if (! $confirmed) {
            return;
        }

        $email = User::query()
            ->whereKey(Workspace::query()->whereKey($subscription->workspace_id)->value('owner_user_id'))
            ->value('email');

        if ($email === null) {
            return;
        }

        $this->trials->grant(
            $subscription,
            (string) $email,
            $subscription->offer_redemption_id === null ? 'public' : 'offer',
        );
    }

    /**
     * reserved -> redeemed, ONLY on provider-confirmed subscription state, and
     * only for the redemption this subscription is linked to.
     */
    private function confirmRedemption(PlatformSubscription $subscription, PlatformSubscriptionSnapshot $snapshot): ?PlatformPrivateOfferRedemption
    {
        if (! $snapshot->status->grantsAccess()) {
            return PlatformPrivateOfferRedemption::query()->find($subscription->offer_redemption_id);
        }

        return DB::transaction(function () use ($subscription, $snapshot): ?PlatformPrivateOfferRedemption {
            $redemption = PlatformPrivateOfferRedemption::query()
                ->whereKey($subscription->offer_redemption_id)
                ->lockForUpdate()
                ->first();

            if ($redemption === null || (int) $redemption->workspace_id !== (int) $subscription->workspace_id) {
                return null;
            }

            if ($redemption->state === PlatformPrivateOfferRedemption::STATE_REDEEMED) {
                return $redemption;
            }

            // A reservation that lapsed or was revoked while the customer was
            // paying is honoured: the money moved against terms they accepted.
            // Anything else released (superseded, declined) is not resurrected.
            if ($redemption->state === PlatformPrivateOfferRedemption::STATE_RELEASED
                && ! in_array($redemption->release_reason, ['expired', 'revoked', 'cancelled'], true)) {
                return null;
            }

            // The subscription must be the one this redemption's Checkout made.
            if ($redemption->provider_checkout_session_id !== null
                && $subscription->provider_checkout_session_id !== null
                && (string) $redemption->provider_checkout_session_id !== (string) $subscription->provider_checkout_session_id) {
                return null;
            }

            $terms = $redemption->terms_snapshot;
            $cycle = (string) ($terms['cycle'] ?? BillingCycle::EVERY_28_DAYS);
            $trialDays = $terms['trial_days'] ?? null;

            $paidStart = $trialDays !== null
                ? ($snapshot->trialEndsAt ?? CarbonImmutable::now())
                : ($snapshot->periodStart ?? CarbonImmutable::now());

            $promoPeriods = $terms['promotion']['periods'] ?? null;
            $hasLaterPhase = count(array_filter($terms['phases'] ?? [], static fn (array $p): bool => $p['kind'] !== 'trial')) > 1;

            $redemption->forceFill([
                'state' => PlatformPrivateOfferRedemption::STATE_REDEEMED,
                'active_slot' => 'ws:' . $redemption->workspace_id,
                'released_at' => null,
                'release_reason' => null,
                'redeemed_at' => $redemption->redeemed_at ?? now(),
                'platform_subscription_id' => $subscription->id,
                'provider_subscription_id' => $snapshot->providerSubscriptionId,
                'trial_granted' => $trialDays !== null,
                'promo_ends_at' => ($hasLaterPhase && $promoPeriods !== null)
                    ? BillingCycle::addPeriods($paidStart, $cycle, (int) $promoPeriods)
                    : null,
                'schedule_state' => $hasLaterPhase
                    ? PlatformPrivateOfferRedemption::SCHEDULE_PENDING
                    : PlatformPrivateOfferRedemption::SCHEDULE_NONE,
                'last_reconciled_at' => now(),
            ])->save();

            $this->history->record(
                $subscription,
                SubscriptionTermsHistory::OFFER_REDEEMED,
                [...$this->history->currentTermsOf($subscription), 'offer_terms' => $terms, 'redemption_uid' => $redemption->uid],
                $redemption->id,
            );

            return $redemption;
        });
    }

    /**
     * Keeps the local record true to what the provider is doing, and makes any
     * divergence visible rather than silently "fixing" it.
     */
    private function observe(PlatformSubscription $subscription, PlatformPrivateOfferRedemption $redemption, PlatformSubscriptionSnapshot $snapshot): void
    {
        if ($redemption->state !== PlatformPrivateOfferRedemption::STATE_REDEEMED) {
            return;
        }

        $byPriceId = [];

        foreach ($redemption->phases() as $phase) {
            if (! empty($phase['price_id'])) {
                $byPriceId[(string) $phase['price_id']] = (int) $phase['price_minor'];
            }
        }

        $previousPriceId = $redemption->terms_snapshot['previous_price_id'] ?? null;

        if ($previousPriceId !== null) {
            $byPriceId[(string) $previousPriceId] = (int) ($redemption->terms_snapshot['previous_price_minor'] ?? 0);
        }

        DB::transaction(function () use ($subscription, $redemption, $snapshot, $byPriceId): void {
            $sub = PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $row = PlatformPrivateOfferRedemption::query()->whereKey($redemption->id)->lockForUpdate()->firstOrFail();

            // The subscription moved off the offer on its own (a plan change
            // ends private pricing explicitly and clears this link first).
            if ((int) $sub->offer_redemption_id !== (int) $row->id) {
                return;
            }

            $sub->forceFill(['provider_schedule_id' => $snapshot->scheduleId])->save();

            $priceId = $snapshot->providerPriceId;
            $drift = $priceId !== null && $byPriceId !== [] && ! array_key_exists($priceId, $byPriceId);

            if ($priceId !== null && isset($byPriceId[$priceId])) {
                $currency = (string) ($row->terms_snapshot['currency_code'] ?? $sub->currency_code);
                $expected = PlatformMoney::toDecimal($byPriceId[$priceId], $currency);

                if ((string) $sub->price_snapshot !== $expected) {
                    $endedPromotion = $priceId === ($row->standard_price_id ?? null) && $priceId !== ($row->initial_price_id ?? null);

                    $sub->forceFill(['price_snapshot' => $expected])->save();

                    $this->history->record(
                        $sub,
                        $endedPromotion ? SubscriptionTermsHistory::PROMOTION_ENDED : SubscriptionTermsHistory::BILLING_CHANGE,
                        [...$this->history->currentTermsOf($sub), 'redemption_uid' => $row->uid],
                        $row->id,
                    );
                }
            }

            $row->forceFill([
                'reconcile_state' => $drift ? 'drift' : 'ok',
                'last_reconciled_at' => now(),
            ])->save();

            if ($drift) {
                Log::warning('A subscription on a private offer is on a Price its accepted terms do not contain', [
                    'redemption_id' => $row->id,
                    'platform_subscription_id' => $sub->id,
                ]);
            }
        });
    }

    /**
     * Tells the provider about a temporary promotion's end, exactly once.
     * Provider call, so: no transaction open. A failure leaves the schedule
     * `pending` for the sweep; it never undoes the redemption.
     */
    public function driveSchedule(PlatformPrivateOfferRedemption $redemption): void
    {
        if ($redemption->state !== PlatformPrivateOfferRedemption::STATE_REDEEMED
            || $redemption->schedule_state !== PlatformPrivateOfferRedemption::SCHEDULE_PENDING
            || $redemption->offer?->isBillingChange()) {
            return;
        }

        $subscription = PlatformSubscription::query()->find($redemption->platform_subscription_id);

        if ($subscription === null
            || blank($subscription->provider_subscription_id)
            || (int) $subscription->offer_redemption_id !== (int) $redemption->id
            || ! in_array($subscription->status, [PlatformSubscriptionStatus::Trialing, PlatformSubscriptionStatus::Active, PlatformSubscriptionStatus::PastDue], true)) {
            return;
        }

        $paid = array_values(array_filter($redemption->phases(), static fn (array $p): bool => $p['kind'] !== 'trial'));

        if (count($paid) < 2 || empty($paid[0]['price_id']) || empty($paid[1]['price_id']) || $redemption->promo_ends_at === null) {
            $this->markSchedule($redemption, PlatformPrivateOfferRedemption::SCHEDULE_FAILED);

            return;
        }

        // One worker at a time per redemption: the Checkout return and the
        // webhook can both arrive together, and the loser must not mistake the
        // winner's schedule for somebody else's.
        $lock = Cache::lock('offer-schedule:' . $redemption->id, 60);

        if (! $lock->get()) {
            return;
        }

        try {
            $redemption->refresh();

            if ($redemption->schedule_state !== PlatformPrivateOfferRedemption::SCHEDULE_PENDING) {
                return;
            }

            $current = $this->gateway->retrieveSubscription((string) $subscription->provider_subscription_id);

            if ($current->scheduleId !== null && $current->scheduleId === $redemption->provider_schedule_id) {
                $this->markSchedule($redemption, PlatformPrivateOfferRedemption::SCHEDULE_APPLIED, $current->scheduleId, $subscription);

                return;
            }

            if ($current->scheduleId !== null) {
                // Not ours: something else is managing this subscription. Do not
                // fight it; surface it.
                $this->markSchedule($redemption, PlatformPrivateOfferRedemption::SCHEDULE_FAILED);

                return;
            }

            if ($redemption->promo_ends_at->lessThanOrEqualTo(now())) {
                // The promotion's window closed before we could schedule it
                // (a long outage): go straight to the standard price, without
                // a proration line.
                $snapshot = $this->gateway->changeSubscriptionPrice(
                    (string) $subscription->provider_subscription_id,
                    (string) $paid[1]['price_id'],
                    prorate: false,
                    idempotencyKey: 'offer-catchup:' . $redemption->uid,
                );
                $this->markSchedule($redemption, PlatformPrivateOfferRedemption::SCHEDULE_NONE);
                $this->observe($subscription->refresh(), $redemption->refresh(), $snapshot);

                return;
            }

            $snapshot = $this->gateway->applyPriceSchedule(
                (string) $subscription->provider_subscription_id,
                [
                    new SchedulePhase((string) $paid[0]['price_id'], CarbonImmutable::instance($redemption->promo_ends_at)),
                    new SchedulePhase((string) $paid[1]['price_id']),
                ],
                'offer-schedule:' . $redemption->uid,
            );
            $this->markSchedule($redemption, PlatformPrivateOfferRedemption::SCHEDULE_APPLIED, $snapshot->scheduleId, $subscription);
        } catch (Throwable $e) {
            Log::warning('Could not put a private-offer promotion on the provider schedule yet; it stays pending', [
                'redemption_id' => $redemption->id,
                'exception' => class_basename($e),
            ]);
        } finally {
            $lock->release();
        }
    }

    /** The scheduled retry for promotions the provider has not yet been told about. */
    public function sweepPendingSchedules(int $limit = 50): int
    {
        $done = 0;

        PlatformPrivateOfferRedemption::query()
            ->where('state', PlatformPrivateOfferRedemption::STATE_REDEEMED)
            ->where('schedule_state', PlatformPrivateOfferRedemption::SCHEDULE_PENDING)
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (PlatformPrivateOfferRedemption $redemption) use (&$done): void {
                $this->driveSchedule($redemption);

                if ($redemption->refresh()->schedule_state !== PlatformPrivateOfferRedemption::SCHEDULE_PENDING) {
                    $done++;
                }
            });

        return $done;
    }

    /**
     * A plan change ENDS private pricing — explicitly, after the customer has
     * confirmed it. The provider schedule is released first (Stripe will not
     * edit the items of a scheduled subscription), then the local agreement is
     * closed, leaving the history intact.
     */
    public function endPrivatePricing(PlatformSubscription $subscription): void
    {
        if ($subscription->offer_redemption_id === null && blank($subscription->provider_schedule_id)) {
            return;
        }

        if (! blank($subscription->provider_schedule_id)) {
            $this->gateway->releaseSchedule((string) $subscription->provider_schedule_id);
        }

        DB::transaction(function () use ($subscription): void {
            $sub = PlatformSubscription::query()->whereKey($subscription->id)->lockForUpdate()->firstOrFail();
            $redemptionId = $sub->offer_redemption_id;

            $sub->forceFill(['offer_redemption_id' => null, 'provider_schedule_id' => null])->save();

            if ($redemptionId !== null) {
                PlatformPrivateOfferRedemption::query()->whereKey($redemptionId)->update([
                    'reconcile_state' => 'ended',
                    'schedule_state' => PlatformPrivateOfferRedemption::SCHEDULE_NONE,
                    'last_reconciled_at' => now(),
                ]);
            }
        });
    }

    /** A superseded Checkout attempt gives its reservation back. */
    public function releaseIfSuperseded(?int $previousRedemptionId, ?int $currentRedemptionId): void
    {
        if ($previousRedemptionId === null || $previousRedemptionId === $currentRedemptionId) {
            return;
        }

        DB::transaction(function () use ($previousRedemptionId): void {
            $row = PlatformPrivateOfferRedemption::query()->whereKey($previousRedemptionId)->lockForUpdate()->first();

            if ($row !== null && $row->state === PlatformPrivateOfferRedemption::STATE_RESERVED) {
                $row->forceFill([
                    'state' => PlatformPrivateOfferRedemption::STATE_RELEASED,
                    'active_slot' => null,
                    'released_at' => now(),
                    'release_reason' => 'superseded',
                ])->save();
            }
        });
    }

    private function markSchedule(
        PlatformPrivateOfferRedemption $redemption,
        string $state,
        ?string $scheduleId = null,
        ?PlatformSubscription $subscription = null,
    ): void {
        DB::transaction(function () use ($redemption, $state, $scheduleId, $subscription): void {
            PlatformPrivateOfferRedemption::query()->whereKey($redemption->id)->update([
                'schedule_state' => $state,
                'provider_schedule_id' => $scheduleId ?? $redemption->provider_schedule_id,
                'last_reconciled_at' => now(),
            ]);

            if ($subscription !== null && $scheduleId !== null) {
                PlatformSubscription::query()->whereKey($subscription->id)->update(['provider_schedule_id' => $scheduleId]);
            }
        });
    }
}
