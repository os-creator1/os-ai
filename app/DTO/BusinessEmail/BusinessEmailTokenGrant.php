<?php

namespace App\DTO\BusinessEmail;

/**
 * The normalized result of a provider token exchange. The access token is
 * request-lifetime only; the refresh token is the one durable secret and is
 * encrypted by the account manager before it is stored. Never log or
 * serialize this object.
 */
final class BusinessEmailTokenGrant
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?string $grantedScopes,
        public readonly ?string $accountEmail = null,
        public readonly ?string $accountId = null,
        public readonly ?string $displayName = null,
    ) {
    }

    public function __debugInfo(): array
    {
        return ['accountEmail' => $this->accountEmail, 'accountId' => $this->accountId];
    }
}
