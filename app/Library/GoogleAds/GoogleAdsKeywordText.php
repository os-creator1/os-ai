<?php

namespace App\Library\GoogleAds;

/**
 * Google Ads Module V1 contract §2 — keyword text rules: at most 80
 * characters and at most 10 words (and, here, no control characters).
 * The provider clients and the Fake apply it before any request, so an
 * invalid negative is a local `validation` failure with zero calls made.
 */
final class GoogleAdsKeywordText
{
    public const MAX_LENGTH = 80;

    public const MAX_WORDS = 10;

    /**
     * Trimmed, single-spaced text, or null when empty / too long / too many
     * words / containing control characters.
     */
    public static function normalize(string $text): ?string
    {
        $collapsed = preg_replace('/\s+/u', ' ', trim($text));

        if (! is_string($collapsed) || $collapsed === '' || preg_match('/[\x00-\x1F\x7F]/', $collapsed) === 1) {
            return null;
        }

        if (mb_strlen($collapsed) > self::MAX_LENGTH || count(explode(' ', $collapsed)) > self::MAX_WORDS) {
            return null;
        }

        return $collapsed;
    }
}
