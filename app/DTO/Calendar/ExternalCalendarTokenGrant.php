<?php

namespace App\DTO\Calendar;

/**
 * Implementation Contract 15 §5.5 — the result of an OAuth code/refresh-token
 * exchange. `accessToken` is REQUEST-LIFETIME ONLY and must never be
 * persisted; `refreshToken` is the only value the connection manager stores,
 * encrypted, and only on the initial code exchange (a refresh-token exchange
 * does not necessarily return a new one — providers may omit it).
 */
final class ExternalCalendarTokenGrant
{
    public function __construct(
        public readonly string $accessToken,
        public readonly ?string $refreshToken,
        public readonly ?string $grantedScopes,
        public readonly ?string $externalAccountEmail,
    ) {
    }
}
