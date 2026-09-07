<?php

namespace App\Enums\GoogleBusinessProfile;

/**
 * GBP Slice A contract §10.1 — the connection state machine. The valid
 * transitions are enumerated in transitionsTo() and nowhere else; a
 * transition absent from that table is a programming error and throws
 * (contract §10.1: "must throw, never silently no-op").
 *
 * `pending -> active` is the ONLY transition that may store a refresh
 * token. Every `* -> disconnected` must null the token in the same
 * transaction that sets the state (contract §13.5).
 */
enum GoogleConnectionState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Revoked = 'revoked';
    case Disconnected = 'disconnected';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Disconnected],
            self::Active => [self::Revoked, self::Disconnected],
            self::Revoked => [self::Pending, self::Disconnected],
            self::Disconnected => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitionsTo(), true);
    }
}
