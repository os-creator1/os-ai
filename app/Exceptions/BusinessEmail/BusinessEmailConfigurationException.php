<?php

namespace App\Exceptions\BusinessEmail;

use RuntimeException;

/**
 * The OAuth configuration for a provider is incomplete or does not match the
 * fixed callback this application serves. Thrown BEFORE any row, nonce or
 * provider call, so a misconfigured deployment leaves nothing behind.
 */
final class BusinessEmailConfigurationException extends RuntimeException
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

    public static function for(string $provider, string $reason): self
    {
        return new self($provider, $reason);
    }

    /** Operator-only diagnostic — never customer-facing; never a credential value. */
    public function operatorMessage(): string
    {
        return sprintf('Business Email OAuth for [%s] is misconfigured: %s.', $this->provider, $this->reason);
    }

    public function customerMessage(): string
    {
        return 'Email connection is not available right now. Please contact support.';
    }
}
