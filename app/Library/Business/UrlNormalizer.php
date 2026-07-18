<?php

namespace App\Library\Business;

use InvalidArgumentException;

/**
 * Pure, local URL normalization for Business Core profile links. Performs no
 * remote requests (RFC-001 §11.3, §26 — no URL fetching, preventing SSRF).
 */
class UrlNormalizer
{
    /**
     * @throws InvalidArgumentException when the URL has a non-HTTP(S) scheme,
     *                                   embeds credentials, or has no host.
     */
    public function normalize(?string $url): ?string
    {
        $url = is_string($url) ? trim($url) : null;

        if ($url === null || $url === '') {
            return null;
        }

        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            throw new InvalidArgumentException('The URL is malformed.');
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException('Only HTTP and HTTPS URLs are permitted.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new InvalidArgumentException('URLs with embedded credentials are not permitted.');
        }

        $host = strtolower($parts['host']);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        if ($path === '/') {
            $path = '';
        }

        $query = ! empty($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = ! empty($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $scheme . '://' . $host . $port . $path . $query . $fragment;
    }

    /**
     * Derive the canonical domain (lower-case host, no `www.`) from an
     * already-normalized URL. Returns null for blank/absent input.
     */
    public function canonicalDomain(?string $normalizedUrl): ?string
    {
        if ($normalizedUrl === null || $normalizedUrl === '') {
            return null;
        }

        $host = parse_url($normalizedUrl, PHP_URL_HOST);

        if (empty($host)) {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }
}
