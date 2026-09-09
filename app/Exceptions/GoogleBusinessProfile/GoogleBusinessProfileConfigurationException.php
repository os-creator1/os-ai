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
     * Security Remediation Slice 0 §16.A.4 (D-21) — renamed from
     * userMessage(). This method's audience is the OPERATOR only: it names
     * the setting, never its value, so a screenshot of an operator's own
     * diagnostic tooling discloses no credential. The name `userMessage()`
     * was itself the defect — it read as customer-safe and was routed into
     * a customer flash by GoogleBusinessProfileController. Callers must log
     * this, never flash it. See customerMessage() for what a customer may
     * see.
     */
    public function operatorMessage(): string
    {
        return match ($this->reason) {
            self::MISSING_CLIENT_ID => 'Google Business Profile is not configured: GOOGLE_BUSINESS_PROFILE_CLIENT_ID is not set.',
            self::MISSING_CLIENT_SECRET => 'Google Business Profile is not configured: GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET is not set.',
            self::MISSING_REDIRECT => 'Google Business Profile is not configured: GOOGLE_BUSINESS_PROFILE_REDIRECT is not set.',
            self::REDIRECT_MISMATCH => 'Google Business Profile is misconfigured: GOOGLE_BUSINESS_PROFILE_REDIRECT does not match this application\'s callback URL.',
            default => 'Google Business Profile is misconfigured: GOOGLE_BUSINESS_PROFILE_REDIRECT must use HTTPS.',
        };
    }

    /**
     * Security Remediation Slice 0 §16.A.4 (D-21) — the customer-safe
     * message, §14.2's exact required copy. Names no environment variable,
     * no configuration key, and no operator instruction, for any reason:
     * a customer must never learn which integration setting is wrong, only
     * that something needs the operator's attention and that they've been
     * notified.
     */
    public function customerMessage(): string
    {
        return "Google connections aren't available right now. This is something we need to fix on our side — we've been notified.";
    }
}
