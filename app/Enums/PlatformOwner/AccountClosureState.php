<?php

namespace App\Enums\PlatformOwner;

/**
 * The lifecycle of a Platform Owner account deletion request.
 *
 *  - Closing: access is already blocked; provider or local cleanup is still
 *    outstanding (or failed and is waiting for a retry). NOT a success state.
 *  - Closed:  everything that could charge or run has been stopped, and the
 *    account has been retired — but protected financial/provider/audit records
 *    are retained, so the user row and its Workspaces still exist, locked.
 *  - Deleted: the account and every account-owned row are gone; only this
 *    tombstone remains.
 */
enum AccountClosureState: string
{
    case Closing = 'closing';
    case Closed = 'closed';
    case Deleted = 'deleted';

    public function isFinal(): bool
    {
        return $this !== self::Closing;
    }

    public function label(): string
    {
        return match ($this) {
            self::Closing => 'Pending cancellation',
            self::Closed => 'Closed — records retained',
            self::Deleted => 'Deleted',
        };
    }
}
