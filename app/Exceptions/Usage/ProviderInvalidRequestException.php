<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * M3 contract §8 — thrown by StripePaymentProviderGateway when the
 * provider rejects a request as malformed/invalid. Message is the
 * provider's own non-sensitive parameter-level error description, never a
 * raw payload or secret.
 */
class ProviderInvalidRequestException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct("Payment provider rejected the request as invalid: {$reason}");
    }
}
