<?php

namespace App\Library\Payments;

/**
 * The normalized result of a Stripe Standard-account OAuth code exchange.
 * Carries NO access or refresh token: the gateway drops them, because every
 * lane-B call authenticates with the platform key + Stripe-Account header.
 */
final readonly class OAuthGrant
{
    public function __construct(
        public string $stripeAccountId,
        public string $scope,
        public bool $livemode,
    ) {
    }
}
