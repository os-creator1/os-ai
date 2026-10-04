<?php

namespace App\Library\GoogleAds\Mutations;

use RuntimeException;

/**
 * Google Ads Module V1 contract §6 — a request the mutation service refused
 * BEFORE opening any ledger operation or calling Google. `reason` is a closed
 * snake_case code; the message is own-vocabulary and safe to show.
 *
 * Suggested HTTP mapping for controllers:
 *   not found (target / account)  -> 404  (also covers a foreign Business's uid)
 *   not entitled                  -> 404  (contract §7: fails closed without the module)
 *   forbidden (permission)        -> 403
 *   connection not usable         -> 409  (offer reconnect / settings)
 *   validation                    -> 422
 */
abstract class GoogleAdsMutationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    abstract public function httpStatus(): int;
}
