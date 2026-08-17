<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M3 contract §8 — thrown by StripePaymentProviderGateway when the
 * configured API key is rejected by the provider. Never carries the key
 * itself, or any part of it, in the message.
 */
class ProviderAuthenticationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Payment provider rejected the configured API key.');
    }
}
