<?php

namespace App\Library\PlatformBilling\Offers;

/**
 * Remembers which offer link a signed-out visitor was looking at, so signing in,
 * signing up or verifying an email can bring them back to it.
 *
 * Only the token is stored (server-side session) and it is only ever used to
 * BUILD our own `offers.show` URL — it is never echoed as a redirect target —
 * so this cannot become an open redirect.
 */
final class OfferReturn
{
    public const KEY = 'pending_offer_token';

    public static function remember(string $token): void
    {
        if (self::valid($token)) {
            session()->put(self::KEY, $token);
        }
    }

    public static function token(): ?string
    {
        $token = session(self::KEY);

        return is_string($token) && self::valid($token) ? $token : null;
    }

    public static function url(): ?string
    {
        $token = self::token();

        return $token === null ? null : route('offers.show', ['token' => $token]);
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
    }

    private static function valid(string $token): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{32,64}\z/', $token) === 1;
    }
}
