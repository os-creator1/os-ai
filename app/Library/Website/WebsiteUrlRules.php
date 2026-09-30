<?php

namespace App\Library\Website;

/**
 * Website Generation + Hosting Slice A contract §25/§29. An explicit
 * ALLOWLIST (never a denylist) for every user- or AI-authored URL field
 * on a Website section (hero/cta buttons). Only https, tel, and mailto
 * are permitted — plain http is deliberately excluded (a stricter
 * default than legacy surfaces, chosen because this is a new,
 * greenfield feature with no legacy plain-http requirement).
 * javascript:, data:, file:, vbscript:, and every other scheme are
 * rejected outright.
 *
 * Website Generator + Local SEO Completion adds exactly one bounded
 * extension: an optional `$allowInternalPath` parameter (default
 * `false`, so every existing single-argument call site is behaviorally
 * unchanged). A caller that opts in also accepts a root-relative,
 * same-site path — e.g. `/photo-booth-services` — which is what the
 * deterministic internal-link engine (a service/location page's own
 * "See all services" / "Get a quote" CTA buttons,
 * WebsiteStarterDraftService::createServiceDetailPage()/
 * createLocationPage()) needs to link to another page on the same
 * Website without knowing that Website's eventual canonical domain at
 * generation time. Never a full URL with a host, never a scheme, never
 * `//` (which a browser resolves as protocol-relative to a DIFFERENT
 * host, not this one).
 */
final class WebsiteUrlRules
{
    public static function isValid(string $url, bool $allowInternalPath = false): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        if (str_starts_with($url, 'tel:')) {
            return strlen($url) > 4;
        }

        if (str_starts_with($url, 'mailto:')) {
            return strlen($url) > 7 && str_contains($url, '@');
        }

        if ($allowInternalPath && self::isValidInternalPath($url)) {
            return true;
        }

        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    private static function isValidInternalPath(string $url): bool
    {
        if (! str_starts_with($url, '/') || str_starts_with($url, '//')) {
            return false;
        }

        if (str_contains($url, ':') || str_contains($url, '..') || strlen($url) > 200) {
            return false;
        }

        return (bool) preg_match('#^/[a-z0-9/_-]*$#i', $url);
    }
}
