<?php

namespace App\Exceptions\Payments;

use RuntimeException;

/**
 * The Stripe account is already claimed by another live connection (another
 * Business, an Agency, or an in-flight revocation). Carries no account id.
 */
final class StripeAccountClaimedException extends RuntimeException
{
    public static function make(): self
    {
        return new self('The Stripe account is already claimed by another connection.');
    }
}
