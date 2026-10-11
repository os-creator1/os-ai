<?php

namespace App\Library\PlatformBilling\Offers;

use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionTermSnapshot;
use Carbon\CarbonInterface;

/**
 * Append-only history of the commercial terms a subscription has been on.
 *
 * The subscription row itself only ever holds the CURRENT snapshot; this is how
 * "what was this customer paying in March, and under which offer?" stays
 * answerable after a promotion ends, a plan changes or an offer is revoked.
 */
final class SubscriptionTermsHistory
{
    public const CHECKOUT_STARTED = 'checkout_started';
    public const OFFER_REDEEMED = 'offer_redeemed';
    public const PROMOTION_ENDED = 'promotion_ended';
    public const BILLING_CHANGE = 'billing_change';
    public const PLAN_CHANGE = 'plan_change';
    public const RESUBSCRIBE = 'resubscribe';

    /** @param array<string, mixed> $terms */
    public function record(
        PlatformSubscription $subscription,
        string $source,
        array $terms,
        ?int $redemptionId = null,
        ?CarbonInterface $effectiveAt = null,
    ): PlatformSubscriptionTermSnapshot {
        $row = new PlatformSubscriptionTermSnapshot();
        $row->forceFill([
            'platform_subscription_id' => $subscription->id,
            'redemption_id' => $redemptionId,
            'source' => $source,
            'terms' => $terms,
            'effective_at' => $effectiveAt ?? now(),
        ])->save();

        return $row;
    }

    /** The subscription's own current commercial snapshot, as a history entry. @return array<string, mixed> */
    public function currentTermsOf(PlatformSubscription $subscription): array
    {
        return [
            'workspace_plan_catalog_id' => $subscription->workspace_plan_catalog_id,
            'provider_price_id' => $subscription->provider_price_id,
            'price' => $subscription->price_snapshot,
            'currency_code' => $subscription->currency_code,
            'billing_cycle' => $subscription->billing_cycle_snapshot,
            'trial_days' => $subscription->trial_days_snapshot,
            'offer_redemption_id' => $subscription->offer_redemption_id,
        ];
    }
}
