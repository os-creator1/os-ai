<?php

namespace App\Enums\Usage;

/**
 * Stripe only, v1 (RFC-005 §26, M3 contract §21). Reused by
 * payment_provider_customers.provider, business_payment_instruments.provider,
 * payment_provider_events.provider.
 */
enum PaymentProvider: string
{
    case Stripe = 'stripe';
}
