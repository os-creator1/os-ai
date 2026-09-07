<?php

namespace App\Library\GoogleBusinessProfile;

use Normalizer;

/**
 * GBP Slice A contract §22.3 — the EXACT per-field normalization rules,
 * stated once so the comparator cannot paraphrase them.
 *
 * Every rule here is deliberately conservative: it removes only
 * differences the contract names, and never "helpfully" strips
 * punctuation, legal suffixes, or URL paths. A rule that guesses produces
 * confident wrong answers, which is worse than a Mismatch the user can
 * read.
 */
final class GoogleBusinessProfileComparisonRules
{
    /**
     * Contract §22.3, business name: trim; collapse internal whitespace;
     * Unicode NFC; case-insensitive compare. NO punctuation stripping and
     * NO legal-suffix stripping.
     *
     * ext-intl is a hard composer requirement of this application, but the
     * guard keeps the rule total rather than fatal if Normalizer is ever
     * unavailable.
     */
    public static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', trim($value));

        if (! is_string($collapsed) || $collapsed === '') {
            return null;
        }

        if (class_exists(Normalizer::class)) {
            $normalized = Normalizer::normalize($collapsed, Normalizer::FORM_C);

            if (is_string($normalized) && $normalized !== '') {
                $collapsed = $normalized;
            }
        }

        return mb_strtolower($collapsed);
    }

    /**
     * Contract §22.3, phone: strip every character outside 0-9 and a
     * single leading '+'. NO region inference and NO libphonenumber
     * dependency — this compares digits, nothing more.
     */
    public static function phone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $hasPlus = str_starts_with($trimmed, '+');
        $digits = preg_replace('/\D+/', '', $trimmed);

        if (! is_string($digits) || $digits === '') {
            return null;
        }

        return ($hasPlus ? '+' : '') . $digits;
    }

    /**
     * Contract §22.3, website: lowercase scheme and host; strip a trailing
     * '/'; strip a leading 'www.'; KEEP path, query and fragment; compare
     * exactly. http vs https is a MISMATCH, not a match — that is the
     * whole point of keeping the scheme.
     */
    public static function websiteUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $parts = parse_url($trimmed);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            // Not an absolute URL — compare the raw text rather than
            // pretending it parsed.
            return rtrim(mb_strtolower($trimmed), '/');
        }

        $host = mb_strtolower($parts['host']);

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $rebuilt = mb_strtolower($parts['scheme']) . '://' . $host;

        if (isset($parts['port'])) {
            $rebuilt .= ':' . $parts['port'];
        }

        $rebuilt .= $parts['path'] ?? '';

        if (isset($parts['query'])) {
            $rebuilt .= '?' . $parts['query'];
        }

        if (isset($parts['fragment'])) {
            $rebuilt .= '#' . $parts['fragment'];
        }

        return rtrim($rebuilt, '/');
    }

    /**
     * Contract §22.3, lat/long: round BOTH to 4 decimal places (~11 m) and
     * compare. A difference beyond that is a Mismatch, not an error.
     */
    public static function coordinate(float|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return number_format((float) $value, 4, '.', '');
    }
}
