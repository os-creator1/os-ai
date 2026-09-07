<?php

namespace App\Exceptions\GoogleBusinessProfile;

use RuntimeException;

/**
 * GBP Slice A contract §28 — the OAuth configuration is incomplete or does
 * not match the fixed callback this application actually serves.
 *
 * Correction pass item 7. Thrown BEFORE any database state change, any
 * nonce issue, any ledger row and any provider call, so a misconfigured
 * deployment can never leave a half-built connection behind or send Google
 * a redirect_uri it will reject.
 *
 * It carries a closed reason code and NEVER a credential value: the
 * operator message names which setting is wrong, never what it contains.
 */
final class GoogleBusinessProfileConfigurationException extends RuntimeException
{
    public const MISSING_CLIENT_ID = 'missing_client_id';

    public const MISSING_CLIENT_SECRET = 'missing_client_secret';

    public const MISSING_REDIRECT = 'missing_redirect';

    public const REDIRECT_MISMATCH = 'redirect_mismatch';

    public const REDIRECT_NOT_HTTPS = 'redirect_not_https';

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

    /**
     * A safe operator-facing message. It names the setting, never its
     * value, so a screenshot of this page discloses no credential.
     */
    public function userMessage(): string
    {
        return match ($this->reason) {
            self::MISSING_CLIENT_ID => 'Google Business Profile is not configured: GOOGLE_BUSINESS_PROFILE_CLIENT_ID is not set.',
            self::MISSING_CLIENT_SECRET => 'Google Business Profile is not configured: GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET is not set.',
            self::MISSING_REDIRECT => 'Google Business Profile is not configured: GOOGLE_BUSINESS_PROFILE_REDIRECT is not set.',
            self::REDIRECT_MISMATCH => 'Google Business Profile is misconfigured: GOOGLE_BUSINESS_PROFILE_REDIRECT does not match this application\'s callback URL.',
            default => 'Google Business Profile is misconfigured: GOOGLE_BUSINESS_PROFILE_REDIRECT must use HTTPS.',
        };
    }
}
