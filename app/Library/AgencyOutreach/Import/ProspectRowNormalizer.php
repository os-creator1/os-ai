<?php

namespace App\Library\AgencyOutreach\Import;

use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;

/**
 * One CSV row → normalised prospect values + the data-quality issues found. Pure (no database), so the
 * original values are always kept alongside by the caller and nothing is ever overwritten here.
 */
final class ProspectRowNormalizer
{
    public const ISSUE_NO_PHONE = 'missing_phone';
    public const ISSUE_BAD_PHONE = 'invalid_phone';
    public const ISSUE_NEEDS_REGION = 'needs_region';
    public const ISSUE_SOCIAL_AS_WEBSITE = 'social_as_website';
    public const ISSUE_ADDRESS_IN_HOURS = 'address_in_hours';
    public const ISSUE_BAD_RATING = 'invalid_rating';
    public const ISSUE_BAD_REVIEWS = 'invalid_review_count';
    public const ISSUE_AMBIGUOUS_LOCATION = 'ambiguous_location';
    public const ISSUE_SIMILAR_CLIENT = 'similar_client';
    public const ISSUE_NO_NAME = 'missing_name';

    public const SOCIAL_HOSTS = ['instagram.com', 'facebook.com', 'fb.com', 'fb.me', 'tiktok.com', 'twitter.com', 'x.com', 'linktr.ee',
        'linkedin.com', 'youtube.com', 'pinterest.com', 'yelp.com', 'threads.net', 'wa.me', 'snapchat.com', 'beacons.ai'];

    /** @param array<string,string> $row @param array<string,?string> $mapping header => field */
    public static function isEmpty(array $row): bool
    {
        foreach ($row as $v) {
            if (trim((string) $v) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string>  $row
     * @param  array<string,?string>  $mapping
     * @return array{values: array<string,mixed>, issues: list<string>, phone: ?string}
     */
    public static function normalize(array $row, array $mapping, ?string $region): array
    {
        $raw = [];

        foreach ($mapping as $header => $field) {
            if ($field !== null) {
                $raw[$field] = trim((string) ($row[$header] ?? ''));
            }
        }

        $issues = [];
        $v = [];

        $v['business_name'] = self::clean($raw['business_name'] ?? '');
        if ($v['business_name'] === null) {
            $issues[] = self::ISSUE_NO_NAME;
        }

        // Phone: never guessed. A number with no country code needs a confirmed region.
        $phone = null;
        $rawPhone = $raw['phone'] ?? '';
        $digits = preg_replace('/\D/', '', $rawPhone) ?? '';

        if ($rawPhone === '' || $digits === '') {
            $issues[] = self::ISSUE_NO_PHONE;
        } else {
            $explicit = str_starts_with(trim($rawPhone), '+') || str_starts_with(trim($rawPhone), '00');
            $phone = AgencyProspectPhoneNormalizer::normalizeForRegion($rawPhone, $region);

            if ($phone === null) {
                $issues[] = (! $explicit && $region === null) ? self::ISSUE_NEEDS_REGION : self::ISSUE_BAD_PHONE;
            }
        }

        $v['business_type'] = self::clean($raw['business_type'] ?? '');
        $v['contact_name'] = self::clean($raw['contact_name'] ?? '');
        $v['email'] = isset($raw['email']) && filter_var($raw['email'], FILTER_VALIDATE_EMAIL) ? strtolower($raw['email']) : null;
        $v['source'] = self::clean($raw['source'] ?? '');

        // Rating 0–5, review count ≥ 0. Invalid values are dropped with an issue, never coerced.
        $v['rating'] = null;
        if (($raw['rating'] ?? '') !== '') {
            $r = str_replace(',', '.', preg_replace('/[^\d.,]/', '', $raw['rating']) ?? '');
            if (is_numeric($r) && (float) $r >= 0 && (float) $r <= 5) {
                $v['rating'] = round((float) $r, 2);
            } else {
                $issues[] = self::ISSUE_BAD_RATING;
            }
        }

        $v['review_count'] = null;
        if (($raw['review_count'] ?? '') !== '') {
            $count = self::parseReviewCount($raw['review_count']);

            if ($count !== null) {
                $v['review_count'] = $count;
            } else {
                $issues[] = self::ISSUE_BAD_REVIEWS;
            }
        }

        // Website vs social profile.
        $website = null;
        $social = null;
        $url = self::cleanUrl($raw['website'] ?? '');

        if ($url !== null) {
            if (self::isSocial($url)) {
                $social = $url;
                $issues[] = self::ISSUE_SOCIAL_AS_WEBSITE;
            } else {
                $website = $url;
            }
        }

        $v['website'] = $website;
        $v['social_url'] = $social;

        $flag = mb_strtolower($raw['has_website'] ?? '');
        $hasWebsite = match (true) {
            in_array($flag, ['yes', 'y', 'true', '1', 'website'], true) => true,
            in_array($flag, ['no', 'n', 'false', '0', 'no website'], true) => false,
            default => null,
        };
        // A social page is not a website. A real URL wins; a social-only link means "no website"; else the
        // explicit Yes/No column; else unknown (null) - never invented.
        $v['has_website'] = $website !== null ? true : ($social !== null ? false : $hasWebsite);

        $v['maps_url'] = self::cleanUrl($raw['maps_url'] ?? '');

        // Address misplaced in the hours column.
        $address = self::clean($raw['address'] ?? '');
        $hours = self::clean($raw['opening_hours'] ?? '');

        if ($hours !== null && self::looksLikeAddress($hours) && ! self::looksLikeHours($hours)) {
            $issues[] = self::ISSUE_ADDRESS_IN_HOURS;

            if ($address === null) {
                $address = $hours;
            }

            $hours = null;
        }

        $v['address'] = $address;
        $v['opening_hours'] = $hours;

        // City / state: explicit columns win; else confidently parsed "…, City, ST 12345" (US-style) only.
        $city = self::clean($raw['city'] ?? '');
        $state = self::clean($raw['state'] ?? '');

        if ($address !== null && ($city === null || $state === null)) {
            if (preg_match('/,\s*([^,\d]{2,40}),\s*([A-Z]{2})\s+\d{5}(?:-\d{4})?\b/u', $address, $m)) {
                $city ??= trim($m[1]);
                $state ??= $m[2];
            } elseif ($city === null && $state === null) {
                $issues[] = self::ISSUE_AMBIGUOUS_LOCATION;
            }
        }

        $v['city'] = $city;
        $v['state'] = $state;
        $v['country'] = self::clean($raw['country'] ?? '');

        foreach (['latitude' => 90, 'longitude' => 180] as $axis => $limit) {
            $x = $raw[$axis] ?? '';
            $v[$axis] = $x !== '' && is_numeric($x) && abs((float) $x) <= $limit ? (float) $x : null;
        }

        return ['values' => $v, 'issues' => array_values(array_unique($issues)), 'phone' => $phone];
    }

    /**
     * "86", "(86)", "1,234", "1.2k", "2K", "3.5M" → integers. Anything else (a decimal without a suffix such as
     * "1.234", multiple suffixes, words, ranges, negatives) is rejected as null, never guessed and never reduced to
     * its digits: the multiplier is applied or the value is refused.
     */
    public static function parseReviewCount(string $raw): ?int
    {
        $v = trim(str_replace(["\u{00A0}", ' '], '', $raw));

        if (preg_match('/^\((.*)\)$/', $v, $m) === 1) {
            $v = $m[1];
        }

        if (preg_match('/^(\d{1,3}(?:,\d{3})+|\d+)(\.\d+)?([kKmM])?$/', $v, $m) !== 1) {
            return null;
        }

        $suffix = strtolower($m[3] ?? '');
        $decimal = $m[2] ?? '';

        if ($suffix === '' && $decimal !== '') {
            return null;
        }

        $number = (float) (str_replace(',', '', $m[1]) . $decimal);
        $value = $number * match ($suffix) { 'k' => 1_000, 'm' => 1_000_000, default => 1 };

        return $value <= 100_000_000 ? (int) round($value) : null;
    }

    public static function isSocial(string $url): bool
    {
        $host = mb_strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        foreach (self::SOCIAL_HOSTS as $social) {
            if ($host === $social || str_ends_with($host, '.' . $social)) {
                return true;
            }
        }

        return false;
    }

    public static function looksLikeAddress(string $v): bool
    {
        return (bool) preg_match('/^\s*\d{1,6}\s+[\p{L}0-9.\' -]+\b(st|street|ave|avenue|rd|road|blvd|boulevard|dr|drive|ln|lane|way|ct|court|pl|place|hwy|highway|pkwy|parkway)\b/iu', $v)
            || (bool) preg_match('/,\s*[A-Z]{2}\s+\d{5}\b/', $v);
    }

    public static function looksLikeHours(string $v): bool
    {
        return (bool) preg_match('/\b(mon|tue|wed|thu|fri|sat|sun|open|closed|24 hours|am|pm)\b/i', $v)
            && (bool) preg_match('/\d{1,2}(:\d{2})?\s?(am|pm)|closed|open 24/i', $v);
    }

    public static function normaliseName(string $name): string
    {
        $n = mb_strtolower($name);
        $n = preg_replace('/\b(llc|inc|co|corp|ltd|the|and|&)\b/u', ' ', $n) ?? $n;

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $n) ?? $n);
    }

    /** Similar but not identical names (substring or high similarity). */
    public static function namesSimilar(string $a, string $b): bool
    {
        $a = self::normaliseName($a);
        $b = self::normaliseName($b);

        if ($a === '' || $b === '' || $a === $b) {
            return $a === $b && $a !== '';
        }

        similar_text($a, $b, $pct);

        return $pct >= 78 || (min(strlen($a), strlen($b)) >= 8 && (str_contains($a, $b) || str_contains($b, $a)));
    }

    private static function clean(string $v): ?string
    {
        $v = trim(preg_replace('/\s+/u', ' ', $v) ?? $v);

        return $v === '' ? null : mb_substr($v, 0, 255);
    }

    private static function cleanUrl(string $v): ?string
    {
        $v = trim($v);

        if ($v === '' || preg_match('/\s/', $v)) {
            return null;
        }

        if (! preg_match('~^https?://~i', $v)) {
            if (! preg_match('~^[\w.-]+\.[a-z]{2,}(/.*)?$~i', $v)) {
                return null;
            }

            $v = 'https://' . $v;
        }

        return mb_substr($v, 0, 2048);
    }
}
