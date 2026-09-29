<?php

namespace App\Exceptions\Calendar;

use RuntimeException;

/**
 * Implementation Contract 15 §5.5 (mirroring GoogleBusinessProfileConcurrencyException)
 * — a connection state transition or token write lost its optimistic-lock
 * race: the conditional `WHERE lock_version = ?` update affected zero rows,
 * so another writer changed the connection first.
 *
 * The caller must NOT emit a success event and must NOT retry blindly —
 * carries no state values and no provider data, a pure "you lost, re-read"
 * signal.
 */
final class ExternalCalendarConcurrencyException extends RuntimeException
{
    public function __construct(public readonly int $connectionId)
    {
        parent::__construct('external_calendar_connection_conflict');
    }

    public function userMessage(): string
    {
        return 'This calendar connection was changed by another request. Reload the page and try again.';
    }
}
