<?php

namespace App\Exceptions\MetaAds;

use RuntimeException;

/**
 * Meta Ads Module V1 contract 24 §7 — a request the mutation service refused
 * BEFORE opening any ledger operation or calling Meta. `reason` is a closed
 * snake_case code; the message is own-vocabulary and safe to show.
 *
 * HTTP mapping for controllers:
 *   not found (target / account)  -> 404  (also covers a foreign Business's uid)
 *   not entitled                  -> 404  (fails closed without the full Ads module)
 *   forbidden (permission)        -> 403
 *   connection not usable         -> 409  (reconnect / read-only connection)
 *   validation                    -> 422
 */
abstract class MetaAdsMutationException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    abstract public function httpStatus(): int;
}
