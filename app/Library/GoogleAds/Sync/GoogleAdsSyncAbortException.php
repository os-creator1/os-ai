<?php

namespace App\Library\GoogleAds\Sync;

use RuntimeException;

/**
 * A stage decided the run must stop cleanly with a safe failure code
 * (e.g. `currency_changed`). Distinct from GoogleAdsProviderException (Google
 * said no) and from programming errors (which are never converted).
 */
final class GoogleAdsSyncAbortException extends RuntimeException
{
    public function __construct(public readonly string $failureCode)
    {
        parent::__construct($failureCode);
    }
}
