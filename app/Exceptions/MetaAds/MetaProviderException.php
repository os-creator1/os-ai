<?php

namespace App\Exceptions\MetaAds;

use RuntimeException;

/**
 * Meta Ads Module V1 contract §2 / §6 / §7 — the ONLY way a provider failure
 * travels through Meta Ads code (mirror of GoogleAdsProviderException).
 *
 * It carries a CLOSED CLASSIFICATION plus three provider facts that are safe
 * by construction: Graph's numeric error `code`, numeric `error_subcode` and
 * the `fbtrace_id` (validated to a short token charset; support quotes it to
 * Meta). It NEVER carries the provider's message, an HTTP body, a URL or a
 * token. The classification values fit varchar(32).
 *
 * `ambiguous` is NOT derived from the classification: only a timeout, a
 * dropped connection, a 5xx / transient failure or an unusable 2xx AFTER a
 * mutate request was sent is ambiguous (the write may or may not have
 * happened -> ledger `unknown`, never replayed).
 */
final class MetaProviderException extends RuntimeException
{
    public const INVALID_TOKEN = 'invalid_token';

    public const TOKEN_EXPIRED = 'token_expired';

    public const ACCESS_DENIED = 'access_denied';

    public const RATE_LIMITED = 'rate_limited';

    public const PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const TIMEOUT = 'timeout';

    public const UNEXPECTED_RESPONSE = 'unexpected_response';

    public const BUDGET_EXHAUSTED = 'budget_exhausted';

    public const NOT_FOUND = 'not_found';

    public const VALIDATION = 'validation';

    /** @var array<int, string> */
    public const CLASSIFICATIONS = [
        self::INVALID_TOKEN,
        self::TOKEN_EXPIRED,
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
        private readonly bool $revocation = false,
        public readonly ?int $providerCode = null,
        public readonly ?int $providerSubcode = null,
        public readonly ?string $fbtraceId = null,
        public readonly ?int $regainSeconds = null,
    ) {
        parent::__construct($classification);
    }

    /**
     * Graph error 190. Subcodes 463 / 467 (expired / invalidated session) are
     * `token_expired`; 458 (app not authorised) is additionally a revocation;
     * every other 190 (incl. 460) is a plain `invalid_token`.
     */
    public static function invalidToken(?int $subcode = null, ?string $fbtraceId = null): self
    {
        $classification = in_array($subcode, [463, 467], true) ? self::TOKEN_EXPIRED : self::INVALID_TOKEN;

        return new self($classification, false, $subcode === 458, 190, $subcode, self::safeTrace($fbtraceId));
    }

    public static function tokenExpired(?string $fbtraceId = null): self
    {
        return new self(self::TOKEN_EXPIRED, false, false, 190, 463, self::safeTrace($fbtraceId));
    }

    public static function accessDenied(?int $code = null, ?int $subcode = null, ?string $fbtraceId = null): self
    {
        return new self(self::ACCESS_DENIED, false, false, $code, $subcode, self::safeTrace($fbtraceId));
    }

    /** @param  ?int  $regainSeconds  Meta's estimated time to regain access, when it said */
    public static function rateLimited(?int $code = null, ?string $fbtraceId = null, ?int $regainSeconds = null): self
    {
        return new self(self::RATE_LIMITED, false, false, $code, null, self::safeTrace($fbtraceId), $regainSeconds);
    }

    public static function providerUnavailable(?int $code = null, ?string $fbtraceId = null): self
    {
        return new self(self::PROVIDER_UNAVAILABLE, false, false, $code, null, self::safeTrace($fbtraceId));
    }

    /**
     * @param  bool  $afterMutateSent  true only when a mutate request had already
     *                                 left this process: the outcome is then unknown.
     */
    public static function timeout(bool $afterMutateSent = false, ?int $code = null, ?string $fbtraceId = null): self
    {
        return new self(self::TIMEOUT, $afterMutateSent, false, $code, null, self::safeTrace($fbtraceId));
    }

    /**
     * @param  bool  $afterMutateSent  true when a mutate returned 2xx (or an unclassifiable
     *                                 status) with an unusable body: the write may have happened.
     */
    public static function unexpectedResponse(bool $afterMutateSent = false, ?string $fbtraceId = null): self
    {
        return new self(self::UNEXPECTED_RESPONSE, $afterMutateSent, false, null, null, self::safeTrace($fbtraceId));
    }

    /** OUR per-Business hourly budget refused the call; zero requests were made. */
    public static function budgetExhausted(): self
    {
        return new self(self::BUDGET_EXHAUSTED);
    }

    public static function notFound(?int $code = null, ?string $fbtraceId = null): self
    {
        return new self(self::NOT_FOUND, false, false, $code, null, self::safeTrace($fbtraceId));
    }

    /** Meta (or our own pre-flight check) rejected the request as invalid. */
    public static function validation(?int $code = null, ?string $fbtraceId = null): self
    {
        return new self(self::VALIDATION, false, false, $code, null, self::safeTrace($fbtraceId));
    }

    /** A rate-limited call is deferred, never a failure and never retried inline (§6). */
    public function isDeferrable(): bool
    {
        return in_array($this->classification, [self::RATE_LIMITED, self::BUDGET_EXHAUSTED], true);
    }

    /** The write may or may not have reached Meta: ledger `unknown`, never auto-replayed (§7). */
    public function isAmbiguous(): bool
    {
        return $this->ambiguous;
    }

    /** Meta says the app was de-authorised (190 / 458): connection becomes `revoked`. */
    public function isRevocation(): bool
    {
        return $this->revocation;
    }

    /** The token is dead (invalid or expired): connection becomes `expired` / `revoked`. */
    public function isTokenFailure(): bool
    {
        return in_array($this->classification, [self::INVALID_TOKEN, self::TOKEN_EXPIRED], true);
    }

    /**
     * Plain-language text for a safe error state. Never includes anything
     * the provider said.
     */
    public function userMessage(): string
    {
        return match ($this->classification) {
            self::INVALID_TOKEN => $this->revocation
                ? 'Meta says this app is no longer authorised. Reconnect to continue.'
                : 'Meta no longer accepts this connection. Reconnect to continue.',
            self::TOKEN_EXPIRED => 'Your Meta connection has expired. Reconnect to continue.',
            self::ACCESS_DENIED => 'Meta denied access for this account.',
            self::RATE_LIMITED => 'Meta is rate limiting requests right now. Please try again shortly.',
            self::PROVIDER_UNAVAILABLE => 'Meta Ads is temporarily unavailable.',
            self::TIMEOUT => $this->ambiguous
                ? 'The request to Meta timed out. Its outcome is unknown; it will be confirmed on the next refresh.'
                : 'The request to Meta timed out. Please try again shortly.',
            self::BUDGET_EXHAUSTED => 'This business has reached its hourly limit for Meta requests. Please try again later.',
            self::NOT_FOUND => 'Meta could not find that item. It may have been removed in Meta Ads Manager.',
            self::VALIDATION => 'Meta rejected that request.',
            default => 'Meta returned an unexpected response.',
        };
    }

    private static function safeTrace(?string $fbtraceId): ?string
    {
        return $fbtraceId !== null && preg_match('/\A[A-Za-z0-9_+\/=-]{1,64}\z/', $fbtraceId) === 1
            ? $fbtraceId
            : null;
    }
}
