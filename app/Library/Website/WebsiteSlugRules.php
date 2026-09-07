<?php

namespace App\Library\Website;

/**
 * Website Generation + Hosting Slice A contract §6.3/§6.4. Server-side
 * slug validation — the ONLY source of truth for what a page slug may
 * be, regardless of what produced the candidate value (a manual entry or
 * a Str::slug() editor convenience). Non-conforming input, including any
 * non-ASCII Unicode, is rejected outright — never silently
 * transliterated.
 */
final class WebsiteSlugRules
{
    public const MAX_LENGTH = 80;

    private const PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * Permanently reserved — a page may never use one of these as its
     * slug, independent of route-registration order (contract §6.4).
     * `sitemap` is itself a public route segment; the rest are reserved
     * defensively for future platform routes under the same
     * /sites/{public_id}/... prefix.
     */
    public const RESERVED = [
        'sitemap',
        'robots',
        'robots.txt',
        'preview',
        'admin',
        'api',
        'assets',
        'home',
        '_website',
    ];

    public static function isValid(string $slug): bool
    {
        if ($slug === '' || strlen($slug) > self::MAX_LENGTH) {
            return false;
        }

        if (in_array($slug, self::RESERVED, true)) {
            return false;
        }

        return preg_match(self::PATTERN, $slug) === 1;
    }
}
