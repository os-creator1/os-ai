<?php

namespace App\Library\Usage;

/**
 * M3 contract §14 — the outcome of the atomic claim UPDATE, so calling code
 * (ProcessPaymentProviderEvent) can distinguish "claimed, proceed" from
 * "not claimable" without a second query.
 */
final readonly class WebhookClaimResult
{
    public function __construct(
        public bool $claimed,
        public int $attempts,
    ) {
    }
}
