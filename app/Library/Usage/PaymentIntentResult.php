<?php

namespace App\Library\Usage;

/**
 * M3 contract §8/§11 — the gateway's normalized return shape for
 * createOffSessionPaymentIntent()/retrievePaymentIntent().
 */
final readonly class PaymentIntentResult
{
    public function __construct(
        public string $providerPaymentIntentId,
        public string $status,
        public ?string $clientSecret,
        public int $amountMinorUnits,
        public string $currencyCode,
    ) {
    }
}
