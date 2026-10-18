<?php

namespace App\Library\ExternalSite;

/**
 * Tells "the website returned an error" apart from "a security layer turned our
 * crawler away".
 *
 * A 403 or 429 answered to a polite, identified crawler is the CDN or firewall
 * (Cloudflare, Sucuri, Akamai, Imperva ...) deciding it does not want automated
 * visitors. It says NOTHING about whether the website works for people, so it must
 * never be reported as an outage, a missing page or a hundred missing-metadata
 * problems. This class classifies a response; what the audit then does with it is
 * decided elsewhere. It never tries to get past the protection.
 *
 * Pure: a FetchResult in, a short reason code (or null) out. The reason codes are
 * stored and shown, so they are stable.
 */
final class BotProtectionDetector
{
    public const RATE_LIMITED = 'rate_limited';

    public const FORBIDDEN = 'forbidden';

    public const CHALLENGE = 'challenge_page';

    /** Lowercase fragments that identify a bot-challenge or firewall page. */
    private const CHALLENGE_MARKERS = [
        'cf-chl-', 'challenge-platform', 'just a moment...', 'checking your browser before accessing', 'attention required! | cloudflare',
        'cloudflare ray id', '_incapsula_resource', 'request unsuccessful. incapsula', 'sucuri website firewall', 'access denied</title>',
        'px-captcha', 'captcha-delivery.com', 'datadome', 'please verify you are a human', 'enable javascript and cookies to continue',
        'perimeterx', 'akamai reference', 'errors.edgesuite.net',
    ];

    /** Markers specific enough to identify a challenge page even when it is answered with 200. */
    private const STRONG_MARKERS = ['just a moment...', 'checking your browser before accessing', 'attention required! | cloudflare', 'enable javascript and cookies to continue', 'px-captcha', 'captcha-delivery.com', 'please verify you are a human'];

    /** @return ?string a reason code when the response is a crawler-blocking one, null when it is an ordinary answer */
    public static function assess(FetchResult $result): ?string
    {
        if ($result->error !== null) {
            return null;
        }

        $status = $result->status;
        $mitigated = strtolower((string) $result->header('cf-mitigated'));

        if ($status === 429) {
            return self::RATE_LIMITED;
        }

        // On an error status ANY marker is enough. On a 200 the same words appear in the plumbing of perfectly normal
        // pages (Cloudflare injects a challenge script into many), so only a short page carrying a STRONG marker counts.
        $markers = in_array($status, [200, 202], true)
            ? (strlen($result->body) < 60_000 && self::hasMarker($result->body, self::STRONG_MARKERS))
            : in_array($status, [401, 403, 429, 503], true) && self::hasMarker($result->body, self::CHALLENGE_MARKERS);

        if ($mitigated === 'challenge' || $markers) {
            return self::CHALLENGE;
        }

        if ($status === 401 || $status === 403) {
            return self::FORBIDDEN;
        }

        if ($status === 503 && $result->header('retry-after') !== null) {
            return self::RATE_LIMITED;
        }

        return null;
    }

    /** @param list<string> $markers */
    private static function hasMarker(string $body, array $markers): bool
    {
        if ($body === '') {
            return false;
        }

        $head = strtolower(substr($body, 0, 20_000));

        foreach ($markers as $marker) {
            if (str_contains($head, $marker)) {
                return true;
            }
        }

        return false;
    }
}
