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
 */
final class WebsiteUrlRules
{
    public static function isValid(string $url): bool
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

        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
