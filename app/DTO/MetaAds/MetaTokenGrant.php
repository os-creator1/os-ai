<?php

namespace App\DTO\MetaAds;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Meta Ads Module V1 contract §3 — the LONG-LIVED user token produced by the
 * server-side code -> short-lived -> fb_exchange_token exchange.
 *
 * It carries a secret: __debugInfo() redacts it so a stray dump / exception
 * context never prints it. Only the connection manager persists it (encrypted).
 *
 * `expiresAt` is null only when Meta sent no `expires_in` (callers treat that
 * as "unknown expiry" and re-verify instead of assuming it never expires).
 */
final readonly class MetaTokenGrant
{
    /**
     * @param  ?array<int, string>  $scopes  granted scopes when the exchange reported them
     */
    public function __construct(
        public string $accessToken,
        public ?CarbonImmutable $expiresAt = null,
        public ?array $scopes = null,
    ) {
        if (trim($accessToken) === '') {
            throw new InvalidArgumentException('Meta access token must not be empty.');
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['accessToken' => '[redacted]', 'expiresAt' => $this->expiresAt, 'scopes' => $this->scopes];
    }
}
