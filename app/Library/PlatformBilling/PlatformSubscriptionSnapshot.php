<?php

namespace App\Library\PlatformBilling;

use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use Carbon\CarbonInterface;

/**
 * Implementation Contract 21 §5/§6 — the normalized value object that crosses
 * lane A's provider boundary.
 *
 * Nothing Stripe-shaped travels past this: no `Stripe\Subscription`, no raw
 * payload, no provider status string, no API key. Everything downstream — the
 * finalizer, the manager, the read models — speaks only these fields and the
 * local status enum, which is what keeps the domain model from quietly
 * becoming Stripe's object graph (§6).
 *
 * `periodStart`/`periodEnd` are normalized by the gateway. In the current
 * Stripe API those values live on the SUBSCRIPTION ITEM, while older pinned
 * API versions expose them on the subscription itself; resolving that
 * difference is the gateway's job, not the domain's, so callers never have to
 * know which shape the provider answered with.
 */
final readonly class PlatformSubscriptionSnapshot
{
    public function __construct(
        public string $providerSubscriptionId,
        public string $providerCustomerId,
        public PlatformSubscriptionStatus $status,
        public ?string $providerPriceId = null,
        public ?CarbonInterface $periodStart = null,
        public ?CarbonInterface $periodEnd = null,
        public ?CarbonInterface $trialEndsAt = null,
        public bool $cancelAtPeriodEnd = false,
        public ?CarbonInterface $canceledAt = null,
        public ?CarbonInterface $endedAt = null,
        /** Our own durable row UID, echoed back through provider metadata. */
        public ?string $operationId = null,
    ) {
    }
}
