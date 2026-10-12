<?php

namespace App\Library\PlatformBilling;

/**
 * Remembers the signed email-verification link a signed-out customer clicked, so
 * that signing in (and passing 2FA) completes it instead of stranding them.
 *
 * Only a link whose signature already verified is stored, only its path+query
 * (never a host), and it is only handed back if it still looks exactly like our
 * own verification route. It is re-validated (signature, expiry, identity) by
 * the verification route itself when it is followed, so nothing here bypasses
 * those checks. It is never logged.
 */
final class PendingVerification
{
    public const KEY = 'pending_verification_uri';

    public static function remember(string $requestUri): void
    {
        if (self::valid($requestUri)) {
            session()->put(self::KEY, $requestUri);
        }
    }

    public static function uri(): ?string
    {
        $uri = session(self::KEY);

        return is_string($uri) && self::valid($uri) ? $uri : null;
    }

    public static function forget(): void
    {
        session()->forget(self::KEY);
    }

    private static function valid(string $uri): bool
    {
        $path = preg_quote(route('verification.verify', ['id' => 'ID', 'hash' => 'HASH'], false), '#');
        $path = str_replace(['ID', 'HASH'], ['\d+', '[A-Fa-f0-9]{40}'], $path);

        return preg_match('#\A' . $path . '\?[A-Za-z0-9=&%._~+-]+\z#', $uri) === 1;
    }
}
