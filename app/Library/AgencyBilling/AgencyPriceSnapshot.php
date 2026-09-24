<?php

namespace App\Library\AgencyBilling;

/**
 * Lane C §C5.2 — one Stripe Price, as read from the AGENCY's connected account.
 *
 * `livemode` travels because a live Price on a test-mode platform (or the
 * reverse) is a configuration error that must be refused, not a curiosity.
 */
final readonly class AgencyPriceSnapshot
{
    public function __construct(
        public string $id,
        public bool $active,
        public string $currency,
        public ?int $unitAmount,
        public bool $recurring,
        public ?string $interval,
        public ?int $intervalCount,
        public bool $livemode,
    ) {
    }
}
