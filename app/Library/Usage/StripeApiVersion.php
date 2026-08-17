<?php

namespace App\Library\Usage;

/**
 * M3 contract §19/§23 item 41 — the single, explicit, pinned Stripe API
 * version string StripePaymentProviderGateway requests on every call,
 * kept out of the gateway class itself for easy test-time reference.
 * Sourced from config('services.stripe.api_version') — this class exists
 * only to give that value a stable, typed accessor.
 */
final class StripeApiVersion
{
    public static function current(): string
    {
        return (string) config('services.stripe.api_version');
    }
}
