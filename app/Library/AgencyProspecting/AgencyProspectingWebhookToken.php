<?php

namespace App\Library\AgencyProspecting;

/**
 * Runtime pass — makes the inbound webhook URL unguessable via a
 * deterministic, application-key-backed HMAC over the channel's own uid,
 * per the task's explicit "at minimum" authorization. Never stored: the
 * token is recomputed on demand both when displaying the webhook URL and
 * when verifying an inbound request, so there is nothing here to leak,
 * rotate, or keep in sync. A bare sequential database ID is never exposed
 * in the URL — only the channel's opaque uid plus this derived token.
 */
final class AgencyProspectingWebhookToken
{
    public static function forChannel(string $channelUid): string
    {
        return hash_hmac('sha256', $channelUid, (string) config('app.key'));
    }

    public static function isValid(string $channelUid, string $suppliedToken): bool
    {
        return hash_equals(self::forChannel($channelUid), $suppliedToken);
    }
}
