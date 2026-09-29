<?php

namespace App\Exceptions\Calendar;

use RuntimeException;

/**
 * Implementation Contract 15 §5.5/§28 (mirroring GoogleBusinessProfileConfigurationException)
 * — the external calendar OAuth configuration for a given provider is
 * incomplete or does not match the fixed callback this application serves.
 *
 * Thrown BEFORE any database state change, any nonce issue and any provider
 * call, so a misconfigured deployment never leaves a half-built connection
 * behind or sends a provider a redirect_uri it will reject.
 */
final class ExternalCalendarConfigurationException extends RuntimeException
{
    public const MISSING_CLIENT_ID = 'missing_client_id';

    public const MISSING_CLIENT_SECRET = 'missing_client_secret';

    public const MISSING_REDIRECT = 'missing_redirect';

    public const REDIRECT_MISMATCH = 'redirect_mismatch';

    public const REDIRECT_NOT_HTTPS = 'redirect_not_https';

    private function __construct(public readonly string $provider, public readonly string $reason)
    {
        parent::__construct($provider . ':' . $reason);
    }

    public static function missingClientId(string $provider): self
    {
        return new self($provider, self::MISSING_CLIENT_ID);
    }

    public static function missingClientSecret(string $provider): self
    {
        return new self($provider, self::MISSING_CLIENT_SECRET);
    }

    public static function missingRedirect(string $provider): self
    {
        return new self($provider, self::MISSING_REDIRECT);
    }

    public static function redirectMismatch(string $provider): self
    {
        return new self($provider, self::REDIRECT_MISMATCH);
    }

    public static function redirectNotHttps(string $provider): self
    {
        return new self($provider, self::REDIRECT_NOT_HTTPS);
    }

    /** Operator-only diagnostic — never customer-facing, never logs a credential value. */
    public function operatorMessage(): string
    {
        return match ($this->reason) {
            self::MISSING_CLIENT_ID => "External calendar ({$this->provider}) is not configured: client id is not set.",
            self::MISSING_CLIENT_SECRET => "External calendar ({$this->provider}) is not configured: client secret is not set.",
            self::MISSING_REDIRECT => "External calendar ({$this->provider}) is not configured: redirect is not set.",
            self::REDIRECT_MISMATCH => "External calendar ({$this->provider}) is misconfigured: redirect does not match this application's callback URL.",
            default => "External calendar ({$this->provider}) is misconfigured: redirect must use HTTPS.",
        };
    }

    public function customerMessage(): string
    {
        return "Calendar connections aren't available right now. This is something we need to fix on our side — we've been notified.";
    }
}
