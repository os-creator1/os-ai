<?php

namespace App\Exceptions\GoogleAds;

use RuntimeException;

/**
 * Google Ads Module V1 contract §3 — account selection failed CLOSED.
 *
 * `NOT_A_CANDIDATE` is the 404 case: the customer id is not in the
 * freshly-derived server-side candidate set (foreign, stale, or invented).
 * The reason is a closed code; no customer id or provider text is carried.
 */
final class GoogleAdsAccountSelectionException extends RuntimeException
{
    public const NOT_CONNECTED = 'not_connected';

    public const INVALID_CUSTOMER_ID = 'invalid_customer_id';

    public const NOT_A_CANDIDATE = 'not_a_candidate';

    public const MANAGER_NOT_SELECTABLE = 'manager_not_selectable';

    private function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function notConnected(): self
    {
        return new self(self::NOT_CONNECTED);
    }

    public static function invalidCustomerId(): self
    {
        return new self(self::INVALID_CUSTOMER_ID);
    }

    public static function notACandidate(): self
    {
        return new self(self::NOT_A_CANDIDATE);
    }

    public static function managerNotSelectable(): self
    {
        return new self(self::MANAGER_NOT_SELECTABLE);
    }

    public function customerMessage(): string
    {
        return match ($this->reason) {
            self::NOT_CONNECTED => 'Connect Google Ads before choosing an account.',
            self::MANAGER_NOT_SELECTABLE => 'That is a manager account. Choose one of the advertising accounts under it.',
            default => 'That Google Ads account is not available to this connection.',
        };
    }
}
