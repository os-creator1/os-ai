<?php

namespace App\Library\Seo;

/**
 * Contract 18 §8.5 / §10.7 — the single link-safety boundary for
 * user-supplied citation URLs.
 *
 * The platform NEVER fetches a listing_url (GBP §31). It only ever renders it
 * as a link, and a link is dangerous for exactly two reasons this class
 * closes: a non-https scheme (javascript:, data:, http:, protocol-relative,
 * mailto:...) and a URL that misleads the reader about where it goes
 * (https://trusted.example@evil.example — userinfo — or hostnames with
 * spaces, backslashes or control characters).
 *
 * It is applied twice, deliberately: on WRITE (so an unsafe URL is refused
 * and never stored) and again on RENDER (so anything that reached the
 * database by another route still cannot become a clickable link).
 *
 * The rel attribute every external link must carry is a constant here so the
 * view and the tests share one definition.
 */
final class SeoLinkSafety
{
    public const MAX_LENGTH = 2048;

    /** Contract §8.5 — the only permitted rel for an external listing link. */
    public const EXTERNAL_REL = 'noopener noreferrer nofollow';

    /**
     * The URL unchanged if — and only if — it is a safe absolute https URL;
     * otherwise null. Never "repairs" a URL: a value that is nearly safe is
     * still refused.
     */
    public static function safeHttpsUrl(?string $url): ?string
    {
        if ($url === null || $url === '' || $url !== trim($url) || strlen($url) > self::MAX_LENGTH) {
            return null;
        }

        // Control characters, whitespace, backslashes and non-ASCII bytes
        // (IDN hosts must arrive punycoded) are how a URL says one thing and
        // parses as another.
        if (preg_match('/[\x00-\x20\x7F-\xFF\\\\]/', $url) === 1) {
            return null;
        }

        // The scheme separator must be exactly "https://" — parse_url is
        // lenient about "https:example.com" and "https:/example.com".
        if (stripos($url, 'https://') !== 0) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = $parts['host'] ?? '';

        // A registrable-looking hostname: dot-separated labels of letters,
        // digits and inner hyphens, at least two labels. No IP literals, no
        // bare "localhost".
        if (preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $host) !== 1
            || preg_match('/\A[0-9.]+\z/', $host) === 1) {
            return null;
        }

        return $url;
    }

    public static function isSafeHttpsUrl(?string $url): bool
    {
        return self::safeHttpsUrl($url) !== null;
    }
}
