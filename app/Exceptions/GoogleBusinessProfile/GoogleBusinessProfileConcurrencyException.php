<?php

namespace App\Exceptions\GoogleBusinessProfile;

use RuntimeException;

/**
 * GBP Slice A contract §11.1.3 — a connection state transition or token
 * write LOST AN OPTIMISTIC-LOCK RACE: the conditional
 * `WHERE lock_version = ?` update affected zero rows, so another writer
 * changed the connection first.
 *
 * Correction pass item 3. The caller must NOT emit a success event, must
 * NOT record a successful ledger outcome, and must NOT retry blindly —
 * the winning writer already moved the connection somewhere this caller
 * did not expect.
 *
 * Carries no state values and no provider data: it is a pure
 * "you lost, re-read" signal.
 */
final class GoogleBusinessProfileConcurrencyException extends RuntimeException
{
    public function __construct(public readonly int $connectionId)
    {
        parent::__construct('google_business_profile_connection_conflict');
    }

    public function userMessage(): string
    {
        return 'This Google connection was changed by another request. Reload the page and try again.';
    }
}
