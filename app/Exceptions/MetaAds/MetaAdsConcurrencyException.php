<?php

namespace App\Exceptions\MetaAds;

use RuntimeException;

/**
 * A Meta connection state transition lost its optimistic-lock race (the
 * conditional `WHERE lock_version = ?` update affected zero rows). The caller
 * must not emit a success outcome and must not retry blindly.
 */
final class MetaAdsConcurrencyException extends RuntimeException
{
    public function __construct(public readonly int $connectionId)
    {
        parent::__construct('meta_ads_connection_conflict');
    }

    public function userMessage(): string
    {
        return 'This Meta connection was changed by another request. Reload the page and try again.';
    }
}
