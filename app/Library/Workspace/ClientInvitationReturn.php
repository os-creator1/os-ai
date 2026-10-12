<?php

namespace App\Library\Workspace;

/**
 * Remembers which Client Invitation a signed-out visitor opened, so signing in
 * (or verifying a new account's email) returns them to that same invitation.
 *
 * The invitation id and secret are held ONLY in the server-side session, are
 * format-checked, and are only ever turned back into this application's own
 * claim route: there is no user-supplied redirect target, nothing in a query
 * string, and nothing for an external site to receive. Nothing here relaxes the
 * claim's own checks: the confirmation page still validates the invitation and
 * the signed-in user's email exactly as before.
 */
final class ClientInvitationReturn
{
    public const KEY = 'pending_client_invitation';

    public static function remember(string $uid, string $token): void
    {
        if (self::valid($uid, $token)) {
            session()->put(self::KEY, ['uid' => $uid, 'token' => $token]);
        }
    }

    /** The claim URL to return to, once; the memory is consumed so it can never loop. */
    public static function pull(): ?string
    {
        $pending = session()->pull(self::KEY);

        if (! is_array($pending) || ! self::valid((string) ($pending['uid'] ?? ''), (string) ($pending['token'] ?? ''))) {
            return null;
        }

        return route('client-invitations.claim', ['uid' => $pending['uid'], 'token' => $pending['token']]);
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
    }

    private static function valid(string $uid, string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9-]{8,64}\z/', $uid) === 1
            && preg_match('/\A[A-Za-z0-9_-]{16,128}\z/', $token) === 1;
    }
}
