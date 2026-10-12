<?php

namespace App\Library\PlatformBilling;

/**
 * Optional, additive parameters for a hosted subscription Checkout.
 *
 * Every field is something the PLATFORM decided, never something a browser
 * supplied. Kept as a value object so `createSubscriptionCheckout()` does not
 * grow another positional argument per feature.
 */
final readonly class CheckoutOptions
{
    /**
     * @param  int|null  $expiresAt  unix time at which the hosted session stops being payable (Stripe allows 30 min – 24 h out)
     * @param  array<string, string>  $subscriptionMetadata  merged into `subscription_data.metadata`; never carries personal data
     * @param  array<string, string>  $sessionMetadata  placed on the Checkout Session itself
     */
    public function __construct(
        public ?int $expiresAt = null,
        public array $subscriptionMetadata = [],
        public array $sessionMetadata = [],
    ) {
    }
}
