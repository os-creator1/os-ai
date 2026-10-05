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
        // Real files/directories under public/: the web server answers these
        // before PHP ever runs, so a page with one of these slugs would be
        // unreachable yet listed in the sitemap.
        'css',
        'css-rtl',
        'fonts',
        'images',
        'installer',
        'js',
        'main',
        'vendors',
        'voice',
        'storage',
        'favicon',
    ];

    /** The pages the generator always names itself; a generated slug must never land on one. */
    public const GENERATED_FIXED = ['services', 'packages', 'gallery', 'backdrops', 'photo-booth-about', 'photo-booth-faq', 'photo-booth-contact'];

    /**
     * A generated slug ("service-<name>", "serving-<area>") that is always valid: lowercase,
     * hyphenated, at most MAX_LENGTH characters, never ending in a hyphen, and never empty
     * (a name with no letters or digits, such as an emoji, gets $fallback instead of a bare prefix).
     * A name that makes the slug too long is cut — never allowed to fail the whole generation.
     */
    public static function bounded(string $prefix, string $name, string $fallback = 'page'): string
    {
        $part = \Illuminate\Support\Str::slug($name);
        $slug = $prefix.($part !== '' ? $part : $fallback);

        if (strlen($slug) > self::MAX_LENGTH) {
            $slug = rtrim(substr($slug, 0, self::MAX_LENGTH), '-');
        }

        return $slug;
    }

    /**
     * The slug of the owner's extra (custom) section page. It is derived from the owner's title, but
     * a title such as "Gallery" or "Services" must not collide with a fixed page or a reserved word.
     */
    public static function customSectionSlug(string $title): string
    {
        $slug = self::bounded('', $title, 'more');

        if (in_array($slug, self::GENERATED_FIXED, true) || ! self::isValid($slug)) {
            $slug = self::bounded('info-', $title, 'more');
        }

        return $slug;
    }

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
