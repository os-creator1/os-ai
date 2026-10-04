<?php

namespace App\Exceptions\MetaAds;

use RuntimeException;

/**
 * Meta Ads Module V1 — the OAuth / driver configuration is unusable.
 * Thrown BEFORE any database write, nonce, ledger row or provider call.
 *
 * Carries a closed reason code and NEVER a credential value. operatorMessage()
 * is for logs only; customerMessage() is the only text a customer may see.
 */
final class MetaConfigurationException extends RuntimeException
{
    public const MISSING_APP_ID = 'missing_app_id';

    public const MISSING_APP_SECRET = 'missing_app_secret';

    public const MISSING_REDIRECT = 'missing_redirect';

    public const REDIRECT_NOT_HTTPS = 'redirect_not_https';

    public const FAKE_DRIVER_IN_PRODUCTION = 'fake_driver_in_production';

    private function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function missingAppId(): self
    {
        return new self(self::MISSING_APP_ID);
    }

    public static function missingAppSecret(): self
    {
        return new self(self::MISSING_APP_SECRET);
    }

    public static function missingRedirect(): self
    {
        return new self(self::MISSING_REDIRECT);
    }

    public static function redirectNotHttps(): self
    {
        return new self(self::REDIRECT_NOT_HTTPS);
    }

    public static function fakeDriverInProduction(): self
    {
        return new self(self::FAKE_DRIVER_IN_PRODUCTION);
    }

    /** Operator-only: names the setting, never its value. */
    public function operatorMessage(): string
    {
        return match ($this->reason) {
            self::MISSING_APP_ID => 'Meta Ads is not configured: META_ADS_APP_ID is not set.',
            self::MISSING_APP_SECRET => 'Meta Ads is not configured: META_ADS_APP_SECRET is not set.',
            self::MISSING_REDIRECT => 'Meta Ads is not configured: META_ADS_REDIRECT_URI is not set.',
            self::FAKE_DRIVER_IN_PRODUCTION => 'Meta Ads is misconfigured: META_ADS_DRIVER=fake is refused in production.',
            default => 'Meta Ads is misconfigured: META_ADS_REDIRECT_URI must use HTTPS.',
        };
    }

    /** Names no setting and no operator instruction. */
    public function customerMessage(): string
    {
        return "Meta connections aren't available right now. This is something we need to fix on our side — we've been notified.";
    }
}
