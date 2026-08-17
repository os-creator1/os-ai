<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M3 contract §8 — thrown by StripePaymentProviderGateway on a transient
 * network/API-availability failure (connection error, 5xx, timeout).
 */
class ProviderApiUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payment provider API is currently unavailable.');
    }
}
