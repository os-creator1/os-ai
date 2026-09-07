<?php

namespace App\Exceptions\GoogleBusinessProfile;

use App\Models\BusinessGoogleOperation;
use RuntimeException;

/**
 * GBP Slice A contract §25.11 / §27 / security criterion G-10 — the ONLY
 * way a provider failure travels through GBP code.
 *
 * It carries a CLOSED CLASSIFICATION and nothing else. There is
 * deliberately no provider message, no HTTP body, no response payload and
 * no upstream exception attached: a raw provider error must never reach a
 * log, an exception context, an audit row or a view (Google publishes no
 * error taxonomy for these APIs, and its bodies can echo request content).
 *
 * The constructor message is the classification itself, so even an
 * unexpected framework-level log of this exception discloses nothing
 * beyond the bucket name.
 */
final class GoogleBusinessProfileProviderException extends RuntimeException
{
    private function __construct(public readonly string $classification)
    {
        parent::__construct($classification);
    }

    public static function invalidGrant(): self
    {
        return new self(BusinessGoogleOperation::FAILURE_INVALID_GRANT);
    }

    public static function accessDenied(): self
    {
        return new self(BusinessGoogleOperation::FAILURE_ACCESS_DENIED);
    }

    public static function rateLimited(): self
    {
        return new self(BusinessGoogleOperation::FAILURE_RATE_LIMITED);
    }

    public static function providerUnavailable(): self
    {
        return new self(BusinessGoogleOperation::FAILURE_PROVIDER_UNAVAILABLE);
    }

    public static function timeout(): self
    {
        return new self(BusinessGoogleOperation::FAILURE_TIMEOUT);
    }

    public static function unexpectedResponse(): self
    {
        return new self(BusinessGoogleOperation::FAILURE_UNEXPECTED_RESPONSE);
    }

    /**
     * Contract §24.5 — a rate-limited call is recorded as `deferred`, not
     * `failed`, and leaves last_synced_at unchanged so the next sweep
     * naturally retries.
     */
    public function isDeferrable(): bool
    {
        return $this->classification === BusinessGoogleOperation::FAILURE_RATE_LIMITED;
    }

    /**
     * Contract §24.6 — a timeout, or a connection dropped after the
     * request was sent, is ambiguous: the call may or may not have
     * reached Google. It is recorded as `unknown` and never blindly
     * replayed.
     */
    public function isAmbiguous(): bool
    {
        return $this->classification === BusinessGoogleOperation::FAILURE_TIMEOUT;
    }

    /**
     * Contract §9.8 — an authorization that Google has invalidated.
     * Transitions the connection to `revoked`, with no retry storm.
     */
    public function isRevocation(): bool
    {
        return $this->classification === BusinessGoogleOperation::FAILURE_INVALID_GRANT;
    }

    /**
     * Plain-language text for the §25.11 safe error state. Never includes
     * anything the provider said.
     */
    public function userMessage(): string
    {
        return match ($this->classification) {
            BusinessGoogleOperation::FAILURE_INVALID_GRANT => 'Google has revoked this connection. Reconnect to continue.',
            BusinessGoogleOperation::FAILURE_ACCESS_DENIED => 'Google denied access for this account.',
            BusinessGoogleOperation::FAILURE_RATE_LIMITED => 'Google is rate limiting requests right now. Please try again shortly.',
            BusinessGoogleOperation::FAILURE_PROVIDER_UNAVAILABLE => 'Google Business Profile is temporarily unavailable.',
            BusinessGoogleOperation::FAILURE_TIMEOUT => 'The request to Google timed out. Its outcome is unknown; please try again shortly.',
            default => 'Google returned an unexpected response.',
        };
    }
}
