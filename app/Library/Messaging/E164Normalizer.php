<?php

namespace App\Library\Messaging;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Slice 3 §4.9 — the single canonical phone-number representation for
 * managed messaging.
 *
 * Deliberately separate from AgencyProspectPhoneNormalizer, which returns a
 * digits-only form for its own storage convention: this slice's schema
 * stores true E.164 (leading "+"), and there is exactly one representation
 * per number, never a second differently-formatted copy (§4.2).
 *
 * A null default region forces libphonenumber to require an explicit
 * calling code — a number without one is refused rather than guessed at
 * (T-MSG-8).
 */
final class E164Normalizer
{
    /**
     * @return string|null canonical E.164 including the leading "+", or null
     *                     when the input is not an explicit, valid
     *                     international number
     */
    public static function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $stripped = preg_replace('/[^\d+]/', '', trim($raw));

        if ($stripped === null || $stripped === '' || $stripped === '+') {
            return null;
        }

        $candidate = str_starts_with($stripped, '+')
            ? '+' . ltrim(substr($stripped, 1), '+')
            : '+' . $stripped;

        try {
            $util = PhoneNumberUtil::getInstance();
            $parsed = $util->parse($candidate, null);

            if (! $util->isValidNumber($parsed)) {
                return null;
            }

            return $util->format($parsed, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            return null;
        }
    }
}
