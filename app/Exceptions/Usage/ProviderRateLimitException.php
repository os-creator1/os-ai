<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M3 contract §8 — thrown by StripePaymentProviderGateway when the
 * provider rejects a call for exceeding its own rate limit.
 */
class ProviderRateLimitException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payment provider rate limit exceeded.');
    }
}
