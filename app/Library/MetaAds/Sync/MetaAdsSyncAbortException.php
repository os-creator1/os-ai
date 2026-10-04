<?php

namespace App\Library\MetaAds\Sync;

use RuntimeException;

/**
 * A stage (or the coordinator) decided the run must stop cleanly with a safe
 * failure code (e.g. `account_changed`). Distinct from MetaProviderException
 * (Meta said no) and from programming errors (which are never converted).
 */
final class MetaAdsSyncAbortException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct($failureCode);
    }
}
