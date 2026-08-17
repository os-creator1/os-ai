<?php

namespace App\Library\Usage;

/**
 * M3 contract §8 — the gateway's normalized return shape for
 * createOrRetrieveCustomer(). Never a raw Stripe\Customer object.
 */
final readonly class ProviderCustomerResult
{
    public function __construct(
        public string $providerCustomerId,
        public bool $wasCreated,
    ) {
    }
}
