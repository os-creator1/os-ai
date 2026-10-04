<?php

namespace App\Exceptions\GoogleAds;

use RuntimeException;

/**
 * A Google Ads connection state transition lost its optimistic-lock race
 * (the conditional `WHERE lock_version = ?` update affected zero rows). The
 * caller must not emit a success outcome and must not retry blindly.
 */
final class GoogleAdsConcurrencyException extends RuntimeException
{
    public function __construct(public readonly int $connectionId)
    {
        parent::__construct('google_ads_connection_conflict');
    }

    public function userMessage(): string
    {
        return 'This Google connection was changed by another request. Reload the page and try again.';
    }
}
