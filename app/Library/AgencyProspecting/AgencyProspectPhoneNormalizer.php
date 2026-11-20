<?php

namespace App\Library\AgencyProspecting;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Runtime pass — the single canonical phone-normalization seam for Agency
 * Prospecting, resolving the foundation pass's explicitly-deferred phone
 * representation gap. Reuses the existing libphonenumber dependency
 * (already used by App\Rules\Phone for shape validation) rather than
 * inventing a parser.
 *
 * No default region/country is ever assumed — a number must already carry
 * an explicit country calling code (a leading "+", or a bare digit string
 * whose leading digits are themselves a real calling code) or normalization
 * fails. This is deliberately stricter than App\Rules\Phone's own looser
 * validation, and is used identically for: AgencyProspect creation, an
 * Agency Prospecting channel's sender number, and both Twilio/Telnyx
 * inbound From/To fields — one seam, never duplicated per call site.
 *
 * Canonical stored form: digits only, no leading "+", no formatting —
 * e.g. "+1 555 123 4567" -> "15551234567".
 */
final class AgencyProspectPhoneNormalizer
{
    /**
     * @return string|null the canonical digits-only form, or null if the
     *                      input cannot be parsed as an explicit
     *                      international number.
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        if ($trimmed === '') {
            return null;
        }

        // Strip everything except digits and a leading "+" — libphonenumber
        // itself tolerates internal spaces/dashes/parens, but this keeps
        // the "does it carry an explicit country code" decision explicit
        // and independent of incidental formatting characters.
        $stripped = preg_replace('/[^\d+]/', '', $trimmed);

        if ($stripped === '' || $stripped === '+') {
            return null;
        }

        $candidate = str_starts_with($stripped, '+')
            ? '+' . ltrim(substr($stripped, 1), '+')
            : '+' . $stripped;

        try {
            $util = PhoneNumberUtil::getInstance();
            // A null default region forces libphonenumber to require the
            // leading "+"/calling code already present in $candidate —
            // it will never guess a region for a number that doesn't
            // carry one, matching the "no default country guessing"
            // requirement exactly.
            $parsed = $util->parse($candidate, null);

            if (! $util->isValidNumber($parsed)) {
                return null;
            }

            return ltrim($util->format($parsed, PhoneNumberFormat::E164), '+');
        } catch (NumberParseException) {
            return null;
        }
    }

    /**
     * Same canonical output as {@see normalize()}, but for a number WITHOUT a country code and only when
     * the caller passes an explicit, user-confirmed ISO region ("US", "GB"…). Numbers that already carry
     * a "+" or a "00" international prefix are parsed exactly as normalize() does. libphonenumber keeps
     * every meaningful national prefix (e.g. an Italian leading 0); nothing is stripped by hand.
     */
    public static function normalizeForRegion(?string $raw, ?string $region): ?string
    {
        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $trimmed = trim($raw);

        if (str_starts_with($trimmed, '+') || str_starts_with($trimmed, '00')) {
            return self::normalize(str_starts_with($trimmed, '00') ? '+' . substr($trimmed, 2) : $trimmed);
        }

        if ($region === null || ! preg_match('/^[A-Za-z]{2}$/', $region)) {
            return null;
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse($trimmed, strtoupper($region));

            return $util->isValidNumber($parsed) ? ltrim($util->format($parsed, PhoneNumberFormat::E164), '+') : null;
        } catch (NumberParseException) {
            return null;
        }
    }
}
