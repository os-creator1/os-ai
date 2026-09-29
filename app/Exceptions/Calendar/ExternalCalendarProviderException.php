<?php

namespace App\Exceptions\Calendar;

use RuntimeException;

/**
 * Implementation Contract 15 §11 (mirroring GoogleBusinessProfileProviderException)
 * — the ONLY way a Google/Outlook provider failure travels through this
 * slice's code.
 *
 * Carries a CLOSED CLASSIFICATION and nothing else — no provider message, no
 * HTTP body, no response payload, no upstream exception attached. A raw
 * provider error must never reach a log, an exception context or a view.
 */
final class ExternalCalendarProviderException extends RuntimeException
{
    public const FAILURE_INVALID_GRANT = 'invalid_grant';

    public const FAILURE_ACCESS_DENIED = 'access_denied';

    public const FAILURE_RATE_LIMITED = 'rate_limited';

    public const FAILURE_PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const FAILURE_TIMEOUT = 'timeout';

    public const FAILURE_UNEXPECTED_RESPONSE = 'unexpected_response';

    public const FAILURE_CURSOR_INVALID = 'cursor_invalid';

    /**
     * Review correction, §11/§12.F renewal semantics — the provider has no
     * in-place renewal for this kind of registration (Google push channels
     * mechanically never do), or reports the specific registration id as
     * gone (Microsoft Graph 404 on `PATCH /subscriptions/{id}`). The only
     * correct response to this classification is a fresh
     * registerNotifications() call — never a retry of the same renewal.
     */
    public const FAILURE_REGISTRATION_NOT_FOUND = 'registration_not_found';

    private function __construct(public readonly string $classification)
    {
        parent::__construct($classification);
    }

    public static function invalidGrant(): self
    {
        return new self(self::FAILURE_INVALID_GRANT);
    }

    public static function accessDenied(): self
    {
        return new self(self::FAILURE_ACCESS_DENIED);
    }

    public static function rateLimited(): self
    {
        return new self(self::FAILURE_RATE_LIMITED);
    }

    public static function providerUnavailable(): self
    {
        return new self(self::FAILURE_PROVIDER_UNAVAILABLE);
    }

    public static function timeout(): self
    {
        return new self(self::FAILURE_TIMEOUT);
    }

    public static function unexpectedResponse(): self
    {
        return new self(self::FAILURE_UNEXPECTED_RESPONSE);
    }

    /**
     * §5.6/§7.6 — the provider reports the stored sync_cursor as no longer
     * valid (Google's 410 Gone on events.list, Graph's resyncRequired delta
     * response). The caller must fall back to a full sync rather than trust
     * a cursor the provider has disowned; it never retries the same cursor.
     */
    public static function cursorInvalid(): self
    {
        return new self(self::FAILURE_CURSOR_INVALID);
    }

    public static function registrationNotFound(): self
    {
        return new self(self::FAILURE_REGISTRATION_NOT_FOUND);
    }

    /** §9.8-equivalent — an authorization the provider has invalidated. */
    public function isRevocation(): bool
    {
        return $this->classification === self::FAILURE_INVALID_GRANT;
    }

    public function userMessage(): string
    {
        return match ($this->classification) {
            self::FAILURE_INVALID_GRANT => 'This calendar connection has been revoked. Reconnect to continue.',
            self::FAILURE_ACCESS_DENIED => 'The provider denied access for this account.',
            self::FAILURE_RATE_LIMITED => 'The provider is rate limiting requests right now. Please try again shortly.',
            self::FAILURE_PROVIDER_UNAVAILABLE => 'The calendar provider is temporarily unavailable.',
            self::FAILURE_TIMEOUT => 'The request to the calendar provider timed out. Its outcome is unknown; please try again shortly.',
            self::FAILURE_CURSOR_INVALID => 'The calendar sync needs to restart from a full read.',
            self::FAILURE_REGISTRATION_NOT_FOUND => 'The calendar notification registration needs to be recreated.',
            default => 'The calendar provider returned an unexpected response.',
        };
    }
}
