<?php

namespace App\Library\Usage;

/**
 * M3 contract §8 — the gateway's normalized return shape for a resolved
 * PaymentMethod. Never a raw PAN/CVC — brand/last-four/expiry only.
 */
final readonly class PaymentMethodResult
{
    public function __construct(
        public string $providerPaymentMethodId,
        public string $providerCustomerId,
        public string $type,
        public ?string $brand,
        public ?string $lastFour,
        public ?int $expiryMonth,
        public ?int $expiryYear,
    ) {
    }
}
