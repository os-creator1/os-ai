<?php

namespace App\Library\AgencyProspecting;

/**
 * Runtime pass — Correction 2's shared URL-safety seam. Two responsibilities
 * that both flow from the same hard invariant ("AI-authored text can never
 * supply an arbitrary URL/host, and the only URL the responder ever sends
 * is the server-configured booking_url"):
 *
 * 1. sanitizeAiText() strips every URL-like token from AI-generated text —
 *    deliberately broader than a bare `https?://` match, since a model can
 *    just as easily emit `www.example.com`, a bare `example.com/path`,
 *    `mailto:`, or an arbitrary `scheme://` destination. This is
 *    intentionally a blunt, deterministic filter (never a URL parser/
 *    crawler) — over-stripping incidental text is an acceptable, bounded
 *    cost for never letting an AI-authored destination reach an outbound
 *    SMS.
 * 2. isValidHttpUrl() is the one gate a configured booking_url must pass
 *    before the responder is ever allowed to append it — http/https only,
 *    never ftp/javascript/mailto/a custom scheme, even if such a value
 *    somehow entered the database outside the normal validated form.
 */
final class AgencyProspectUrlPolicy
{
    public static function sanitizeAiText(string $text): string
    {
        $pattern = '/\b(?:[a-z][a-z0-9+.\-]*:\/\/\S+|mailto:\S+|www\.\S+|[a-z0-9][a-z0-9-]*(?:\.[a-z0-9-]+)*\.[a-z]{2,}(?:\/\S*)?)/i';

        $stripped = preg_replace($pattern, '', $text) ?? $text;

        return trim(preg_replace('/\s{2,}/', ' ', $stripped) ?? $stripped);
    }

    public static function isValidHttpUrl(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        if (! preg_match('#^https?://#i', $url)) {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
