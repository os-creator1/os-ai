<?php

namespace App\Library\Assistant;

/**
 * Opaque handles for records the model may need to POINT at (a waiting conversation, a contact) without being told who
 * they are. A handle is an HMAC of the record uid, bound to this Business and this actor, so it identifies nothing to the
 * provider, cannot be guessed, and means nothing in another Business, for another user, or outside this application.
 * The server resolves a handle by recomputing it over the records THIS actor may see — never by trusting it as an id.
 */
final class AssistantHandles
{
    public static function for(AssistantContext $context, string $kind, string $uid): string
    {
        $key = (string) config('app.key');

        return 'h_' . substr(hash_hmac('sha256', $kind . '|' . $context->business->id . '|' . $context->user->id . '|' . $uid, $key), 0, 12);
    }

    /** First name only (or null when the record has no real name) — the most a draft needs to be polite. */
    public static function firstName(?string $name, ?string $phone = null): ?string
    {
        $name = trim((string) $name);

        if ($name === '' || ($phone !== null && preg_replace('/\D+/', '', $name) === preg_replace('/\D+/', '', $phone)) || preg_match('/^[\d\s+()\-]+$/', $name) === 1) {
            return null;
        }

        return mb_substr(preg_split('/\s+/', $name)[0], 0, 30);
    }

    /** "John S." — enough to tell two people apart in conversation, not to identify them. */
    public static function shortLabel(?string $name, ?string $phone = null): string
    {
        $first = self::firstName($name, $phone);

        if ($first === null) {
            return 'a contact without a name';
        }

        $parts = preg_split('/\s+/', trim((string) $name));

        return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr($parts[count($parts) - 1], 0, 1)) . '.' : $first;
    }
}
