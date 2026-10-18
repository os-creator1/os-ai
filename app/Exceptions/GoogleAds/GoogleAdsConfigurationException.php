<?php

namespace App\Exceptions\GoogleAds;

use RuntimeException;

/**
 * Google Ads Module V1 — the OAuth / driver configuration is unusable.
 * Thrown BEFORE any database write, nonce, ledger row or provider call.
 *
 * Carries a closed reason code and NEVER a credential value. operatorMessage()
 * is for logs only; customerMessage() is the only text a customer may see.
 */
final class GoogleAdsConfigurationException extends RuntimeException
{
    public const MISSING_CLIENT_ID = 'missing_client_id';

    public const MISSING_CLIENT_SECRET = 'missing_client_secret';

    public const MISSING_REDIRECT = 'missing_redirect';

    public const REDIRECT_MISMATCH = 'redirect_mismatch';

    public const REDIRECT_NOT_HTTPS = 'redirect_not_https';

    public const FAKE_DRIVER_IN_PRODUCTION = 'fake_driver_in_production';

    private function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public static function missingClientId(): self
    {
        return new self(self::MISSING_CLIENT_ID);
    }

    public static function missingClientSecret(): self
    {
        return new self(self::MISSING_CLIENT_SECRET);
    }

    public static function missingRedirect(): self
    {
        return new self(self::MISSING_REDIRECT);
    }

    public static function redirectMismatch(): self
    {
        return new self(self::REDIRECT_MISMATCH);
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
            self::MISSING_CLIENT_ID => 'Google Ads is not configured: GOOGLE_ADS_CLIENT_ID is not set.',
            self::MISSING_CLIENT_SECRET => 'Google Ads is not configured: GOOGLE_ADS_CLIENT_SECRET is not set.',
            self::MISSING_REDIRECT => 'Google Ads is not configured: GOOGLE_ADS_REDIRECT is not set.',
            self::REDIRECT_MISMATCH => 'Google Ads is misconfigured: GOOGLE_ADS_REDIRECT does not match this application\'s callback URL.',
            self::FAKE_DRIVER_IN_PRODUCTION => 'Google Ads is misconfigured: GOOGLE_ADS_DRIVER=fake is refused in production.',
            default => 'Google Ads is misconfigured: GOOGLE_ADS_REDIRECT must use HTTPS.',
        };
    }

    /** Names no setting and no operator instruction. */
    public function customerMessage(): string
    {
        return "Google connections aren't available right now. This is something we need to fix on our side — we've been notified.";
    }
}
