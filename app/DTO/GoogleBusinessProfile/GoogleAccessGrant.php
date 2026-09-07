<?php

namespace App\DTO\GoogleBusinessProfile;

/**
 * GBP Slice A contract §9.7/§20.3 — a request-lifetime access token
 * derived from the encrypted refresh token. Never persisted:
 * business_google_connections has no access-token column by design.
 */
final readonly class GoogleAccessGrant
{
    public function __construct(
        public string $accessToken,
        public int $expiresInSeconds,
    ) {
    }
}
