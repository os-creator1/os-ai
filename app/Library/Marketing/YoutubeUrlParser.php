<?php

namespace App\Library\Marketing;

/**
 * Safe, narrow parsing of a YouTube URL down to its 11-character video ID —
 * the one piece of untrusted admin input this app turns into an embed
 * request. Deliberately conservative: only a handful of known YouTube
 * hosts and URL shapes are recognized, and the extracted ID is always
 * re-validated against YouTube's own ID character set before use, so a
 * malformed or hostile string can never reach the embed/thumbnail URLs
 * this class builds.
 */
final class YoutubeUrlParser
{
    private const ID_PATTERN = '/^[A-Za-z0-9_-]{11}$/';

    private const HOSTS = [
        'youtube.com',
        'm.youtube.com',
        'youtube-nocookie.com',
    ];

    public static function isYoutubeUrl(string $url): bool
    {
        return self::normalizedHost($url) !== null;
    }

    /**
     * Returns the 11-character video ID, or null when the URL is not a
     * recognized YouTube shape or does not carry a validly-shaped ID.
     */
    public static function extractVideoId(string $url): ?string
    {
        $host = self::normalizedHost($url);

        if ($host === null) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $path = $parts['path'] ?? '';
        $id = null;

        if ($host === 'youtu.be') {
            $segments = array_values(array_filter(explode('/', $path)));
            $id = $segments[0] ?? null;
        } else {
            if (preg_match('#^/(embed|shorts)/([^/]+)#', $path, $matches) === 1) {
                $id = $matches[2];
            } else {
                parse_str($parts['query'] ?? '', $query);
                $id = $query['v'] ?? null;
            }
        }

        if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
            return null;
        }

        return $id;
    }

    /**
     * YouTube's own documented thumbnail URL pattern — no upload, no
     * mirroring, just a reference to an image YouTube already serves for
     * every public video.
     */
    public static function thumbnailUrl(string $videoId): string
    {
        return "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
    }

    /**
     * The privacy-enhanced embed host, never youtube.com directly.
     */
    public static function noCookieEmbedUrl(string $videoId): string
    {
        return "https://www.youtube-nocookie.com/embed/{$videoId}?autoplay=1&rel=0";
    }

    private static function normalizedHost(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);
        $host = preg_replace('/^www\./', '', $host);

        if ($host === 'youtu.be') {
            return 'youtu.be';
        }

        return in_array($host, self::HOSTS, true) ? $host : null;
    }
}
