<?php

namespace App\Enums\MetaAds;

/**
 * Contract 24 §3 — the Meta connection state machine. Valid transitions are
 * enumerated in transitionsTo() and nowhere else.
 *
 * Meta has no refresh token, so re-authorising is the renewal path:
 * `active -> active` is the re-auth completion that replaces the token and
 * expiry; `expired` / `revoked` / `disconnected` go back through `pending`.
 * Every move away from `active` (other than active -> active) must null the
 * stored token in the same transaction.
 */
enum MetaConnectionState: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Revoked = 'revoked';
    case Disconnected = 'disconnected';

    /**
     * @return array<int, self>
     */
    public function transitionsTo(): array
    {
        return match ($this) {
            self::Pending => [self::Active, self::Disconnected],
            self::Active => [self::Active, self::Expired, self::Revoked, self::Disconnected],
            self::Expired => [self::Pending, self::Disconnected],
            self::Revoked => [self::Pending, self::Disconnected],
            self::Disconnected => [self::Pending],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->transitionsTo(), true);
    }
}
