<?php

namespace App\Exceptions\Usage;

use RuntimeException;

/**
 * Thrown by UsageWalletManager::reserve()/commit()/release() when a
 * Business has no wallet — either initialization never ran, or a prior
 * initialization failed (RFC-005 M1 contract §8 item 0). Every wallet/
 * accounting operation for that Business fails closed this way until
 * initialization succeeds.
 */
class UsageWalletNotFoundException extends RuntimeException
{
    public function __construct(public readonly int $businessId)
    {
        parent::__construct("No business_usage_wallets row exists for Business #{$businessId}.");
    }
}
