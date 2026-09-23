<?php

namespace App\Library\PlatformBilling;

/**
 * Implementation Contract 21 §11 — the normalized provider Price facts that
 * cross lane A's boundary.
 *
 * No `Stripe\Price` object ever leaves StripeApiPlatformGateway (§5), so
 * everything the parity check needs is stated here explicitly. Field meanings
 * are Stripe's own, verified against the current Price reference:
 *
 *   active         "whether the price can be used for new purchases"
 *   currency       three-letter ISO code, LOWERCASE at the provider
 *   unitAmount     "the unit amount in the smallest currency unit"
 *   recurring      `type` is one of `one_time` or `recurring`
 *   interval       `recurring.interval`: day | week | month | year
 *   intervalCount  `recurring.interval_count`
 *   livemode       true in live mode, false in test mode
 */
final readonly class ProviderPriceSnapshot
{
    public function __construct(
        public string $id,
        public bool $active,
        public string $currency,
        public ?int $unitAmount,
        public bool $recurring,
        public ?string $interval = null,
        public ?int $intervalCount = null,
        public bool $livemode = false,
    ) {
    }
}
