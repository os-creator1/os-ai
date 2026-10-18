<?php

namespace App\Library\GoogleAds\Attribution;

use App\Library\GoogleAds\GoogleAdsConfig;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Google Ads Module V1 contract §10 — the first-party attribution cookie pair.
 *
 * Encryption is Laravel's own EncryptCookies (these names are deliberately NOT
 * in its $except list): a cookie that fails to decrypt arrives here as null.
 * Whatever does decrypt is still untrusted and is re-validated field by field.
 * No visitor id, IP address or user agent is ever put in a cookie.
 */
final class AttributionCookie
{
    public const FIRST = 'bos_at_first';

    public const LAST = 'bos_at_last';

    /** A decrypted body larger than this is garbage, not ours. */
    private const MAX_BODY_BYTES = 4096;

    /** HttpOnly, SameSite=Lax, Secure on https, host-only (no Domain), lifetime from config. */
    public static function make(string $name, AttributionParameters $touch, bool $secure): Cookie
    {
        $days = (new GoogleAdsConfig)->attributionCookieDays();

        return new Cookie(
            $name,
            (string) json_encode($touch->toCookiePayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            CarbonImmutable::now()->addDays($days),
            '/',
            null,
            $secure,
            true,
            false,
            Cookie::SAMESITE_LAX,
        );
    }

    /** The validated touch in the named cookie, or null when absent, forged, expired or malformed. Never throws. */
    public static function read(Request $request, string $name): ?AttributionParameters
    {
        try {
            $body = $request->cookies->get($name);

            if (! is_string($body) || $body === '' || strlen($body) > self::MAX_BODY_BYTES) {
                return null;
            }

            $payload = json_decode($body, true, 4);
            if (! is_array($payload)) {
                return null;
            }

            $touch = AttributionParameters::fromPayload($payload);
            $captured = $touch->capturedAt();

            if (! $touch->hasTouch() || $captured === null || ! self::plausible($captured)) {
                return null;
            }

            return $touch;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Written by this server within the cookie lifetime: not in the future, not older than the lifetime (+1 day grace). */
    private static function plausible(CarbonImmutable $captured): bool
    {
        $now = CarbonImmutable::now();
        $days = (new GoogleAdsConfig)->attributionCookieDays();

        return $captured->lessThanOrEqualTo($now->addMinutes(5)) && $captured->greaterThanOrEqualTo($now->subDays($days + 1));
    }
}
