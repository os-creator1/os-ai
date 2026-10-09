<?php

namespace App\Library\ExternalSite;

/**
 * RFC 3986-style resolution of a (possibly relative) reference against a base
 * URL, plus the normalisation the crawler keys pages by. Pure string work; it
 * judges nothing — every result still goes through the UrlGuard.
 */
final class UrlResolver
{
    /** @return ?string an absolute http(s) URL without a fragment, or null when the reference is unusable */
    public static function resolve(string $base, string $reference): ?string
    {
        $reference = trim($reference);

        if ($reference === '' || preg_match('/[\x00-\x1f\x7f\\\\]/', $reference) === 1) {
            return null;
        }

        // Scheme-relative, absolute and relative references.
        if (str_starts_with($reference, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME);
            $reference = (is_string($scheme) ? $scheme : 'https').':'.$reference;
        }

        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $reference) === 1) {
            return self::normalize($reference);
        }

        $b = parse_url($base);

        if ($b === false || ! isset($b['scheme'], $b['host'])) {
            return null;
        }

        $origin = $b['scheme'].'://'.$b['host'].(isset($b['port']) ? ':'.$b['port'] : '');

        if (str_starts_with($reference, '#')) {
            return self::normalize($base);
        }

        if (str_starts_with($reference, '?')) {
            return self::normalize($origin.($b['path'] ?? '/').$reference);
        }

        if (str_starts_with($reference, '/')) {
            return self::normalize($origin.$reference);
        }

        $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/') ?? '/';

        return self::normalize($origin.$dir.$reference);
    }

    /** Lowercases scheme and host, drops the fragment and default port, removes dot segments, keeps the query. */
    public static function normalize(string $url): ?string
    {
        $p = parse_url(trim($url));

        if ($p === false || ! isset($p['scheme'], $p['host'])) {
            return null;
        }

        $scheme = strtolower($p['scheme']);

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $host = strtolower(rtrim($p['host'], '.'));
        $port = $p['port'] ?? null;
        $default = ($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80);
        $path = self::removeDotSegments(($p['path'] ?? '') === '' ? '/' : $p['path']);

        return $scheme.'://'.$host.($port !== null && ! $default ? ':'.$port : '').$path.(isset($p['query']) && $p['query'] !== '' ? '?'.$p['query'] : '');
    }

    private static function removeDotSegments(string $path): string
    {
        $out = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                array_pop($out);
            } elseif ($segment !== '.') {
                $out[] = $segment;
            }
        }

        $joined = implode('/', $out);

        return str_starts_with($joined, '/') ? $joined : '/'.$joined;
    }
}
