<?php

namespace App\Library\ExternalSite;

use RuntimeException;

/**
 * A refused or failed external fetch. The `code` is a short, stable,
 * machine-readable reason (never a response body, header or secret) and is the
 * only thing recorded about a failure.
 */
final class ExternalSiteException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $message = '')
    {
        parent::__construct($message !== '' ? $message : $reason);
    }
}
