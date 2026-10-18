<?php

namespace App\Exceptions\GoogleAds;

use RuntimeException;

/**
 * Google Ads Module V1 contract §2 / §14 — the ONLY way a provider failure
 * travels through Google Ads code. Mirrors GoogleBusinessProfileProviderException
 * (that class is GBP-scoped and deliberately not generalised).
 *
 * It carries a CLOSED CLASSIFICATION and nothing else: no provider message,
 * no HTTP body and no upstream exception. The classification values are the
 * product-neutral ledger vocabulary (BusinessGoogleOperation::FAILURE_*)
 * plus `not_found` and `validation`; all fit failure_classification
 * (varchar(32)).
 *
 * `ambiguous` is NOT derived from the classification: only a timeout or a
 * dropped connection AFTER a mutate request was sent is ambiguous (the write
 * may or may not have happened → ledger `unknown`, never replayed). The same
 * timeout on a READ is a plain failure.
 */
final class GoogleAdsProviderException extends RuntimeException
{
    public const INVALID_GRANT = 'invalid_grant';

    public const ACCESS_DENIED = 'access_denied';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const TIMEOUT = 'timeout';

    public const UNEXPECTED_RESPONSE = 'unexpected_response';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const NOT_FOUND = 'not_found';

    public const VALIDATION = 'validation';

    /**
     * @var array<int, string>
     */
    public const CLASSIFICATIONS = [
        self::INVALID_GRANT,
        self::ACCESS_DENIED,
        self::RATE_LIMITED,
        self::PROVIDER_UNAVAILABLE,
        self::TIMEOUT,
        self::UNEXPECTED_RESPONSE,
        self::BUDGET_EXHAUSTED,
        self::NOT_FOUND,
        self::VALIDATION,
    ];

    private function __construct(
        public readonly string $classification,
        private readonly bool $ambiguous = false,
    ) {
        parent::__construct($classification);
    }

    public static function invalidGrant(): self
    {
        return new self(self::INVALID_GRANT);
    }

    public static function accessDenied(): self
    {
        return new self(self::ACCESS_DENIED);
    }

    public static function rateLimited(): self
    {
        return new self(self::RATE_LIMITED);
    }

    public static function providerUnavailable(): self
    {
        return new self(self::PROVIDER_UNAVAILABLE);
    }

    /**
     * @param  bool  $afterMutateSent  true only when a mutate request had
     *                                 already left this process: the outcome is then unknown.
     */
    public static function timeout(bool $afterMutateSent = false): self
    {
        return new self(self::TIMEOUT, $afterMutateSent);
    }

    /**
     * @param  bool  $afterMutateSent  true when a mutate returned 2xx but its
     *                                 body was unusable: the write probably happened, so the outcome is unknown.
     */
    public static function unexpectedResponse(bool $afterMutateSent = false): self
    {
        return new self(self::UNEXPECTED_RESPONSE, $afterMutateSent);
    }

    /** OUR per-Business hourly budget refused the call; zero requests were made. */
    public static function budgetExhausted(): self
    {
        return new self(self::BUDGET_EXHAUSTED);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /** Google (or our own pre-flight check) rejected the request as invalid. */
    public static function validation(): self
    {
        return new self(self::VALIDATION);
    }

    /** A rate-limited call is deferred, never a failure and never retried inline (§5, §14). */
    public function isDeferrable(): bool
    {
        return in_array($this->classification, [self::RATE_LIMITED, self::BUDGET_EXHAUSTED], true);
    }

    /** The write may or may not have reached Google: ledger `unknown`, never auto-replayed (§6). */
    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    public function isRevocation(): bool
    {
        return $this->classification === self::INVALID_GRANT;
    }

    /**
     * Plain-language text for a safe error state. Never includes anything
     * the provider said.
     */
    public function userMessage(): string
    {
        return match ($this->classification) {
            self::INVALID_GRANT => 'Google has revoked this connection. Reconnect to continue.',
            self::ACCESS_DENIED => 'Google denied access for this account.',
            self::RATE_LIMITED => 'Google is rate limiting requests right now. Please try again shortly.',
            self::PROVIDER_UNAVAILABLE => 'Google Ads is temporarily unavailable.',
            self::TIMEOUT => $this->ambiguous
                ? 'The request to Google timed out. Its outcome is unknown; it will be confirmed on the next refresh.'
                : 'The request to Google timed out. Please try again shortly.',
            self::BUDGET_EXHAUSTED => 'This business has reached its hourly limit for Google requests. Please try again later.',
            self::NOT_FOUND => 'Google could not find that item. It may have been removed in Google Ads.',
            self::VALIDATION => 'Google rejected that request.',
            default => 'Google returned an unexpected response.',
        };
    }
}
