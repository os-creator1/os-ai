<?php

namespace App\Library\Calendar\ExternalCalendar;

/**
 * Implementation Contract 15 §11 (mirroring AgencyProspectingWebhookToken) —
 * makes the inbound webhook URL unguessable via a deterministic,
 * application-key-backed HMAC over the connection's own uid and provider.
 *
 * Never stored: recomputed on demand both when registering the webhook URL
 * with the provider and when verifying an inbound request, so there is
 * nothing to leak, rotate, or keep in sync — and no schema change is needed
 * to hold it (Contract 15.F's schema is explicitly closed: none new).
 *
 * A bare connection id or uid alone is never sufficient to authenticate a
 * webhook: the token must also match, computed from app.key, which no
 * external caller can derive.
 */
final class ExternalCalendarWebhookToken
{
    public static function forConnection(string $connectionUid, string $provider): string
    {
        return hash_hmac('sha256', $provider . ':' . $connectionUid, (string) config('app.key'));
    }

    public static function isValid(string $connectionUid, string $provider, string $suppliedToken): bool
    {
        return hash_equals(self::forConnection($connectionUid, $provider), $suppliedToken);
    }
}
