<?php

namespace App\Exceptions\MetaAds;

use RuntimeException;

/**
 * Meta Ads Module V1 contract 24 §4 — account selection failed CLOSED.
 *
 * `NOT_A_CANDIDATE` is the 404 case: the ad account id is not in the
 * freshly-derived server-side candidate set (foreign, stale or invented).
 * The reason is a closed code; no account id or provider text is carried.
 */
final class MetaAdsAccountSelectionException extends RuntimeException
{
    public const NOT_A_CANDIDATE = 'not_a_candidate';

    public const INVALID_ACCOUNT_ID = 'invalid_account_id';

    public const NOT_CONNECTED = 'not_connected';

    public const SYNC_RUNNING = 'sync_running';

    public const NOT_SELECTABLE = 'not_selectable';

    private function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function notACandidate(): self
    {
        return new self(self::NOT_A_CANDIDATE);
    }

    public static function invalidAccountId(): self
    {
        return new self(self::INVALID_ACCOUNT_ID);
    }

    public static function notConnected(): self
    {
        return new self(self::NOT_CONNECTED);
    }

    public static function syncRunning(): self
    {
        return new self(self::SYNC_RUNNING);
    }

    public static function notSelectable(): self
    {
        return new self(self::NOT_SELECTABLE);
    }

    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::NOT_CONNECTED => 'Connect Meta before choosing an ad account.',
            self::SYNC_RUNNING => 'An update is running; try again in a minute.',
            self::NOT_SELECTABLE => 'That ad account is not active in Meta, so it cannot be used here.',
            default => 'That ad account is not available to this connection.',
        };
    }
}
