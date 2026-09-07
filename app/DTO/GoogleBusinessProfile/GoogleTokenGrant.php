<?php

namespace App\DTO\GoogleBusinessProfile;

/**
 * GBP Slice A contract §20.3 — the result of an authorization-code
 * exchange. Carries the refresh token exactly once, in memory, on its way
 * to the encrypted column; it is never logged, serialized or rendered.
 */
final readonly class GoogleTokenGrant
{
    public function __construct(
        public ?string $refreshToken,
        public string $accessToken,
        public int $expiresInSeconds,
        public string $grantedScopes,
    ) {
    }
}
