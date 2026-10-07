<?php

namespace App\Library\Contacts;

use App\Models\Business;
use App\Models\BusinessLocation;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumber;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * The ONE phone normalization used whenever a Contact is matched or created
 * from a phone number: public booking, Website/standalone Forms, manual and
 * API contact creation, and inbound-message Contact resolution (STOP, the
 * message-received trigger).
 *
 * STORED FORM. `contacts.phone` has always held digits only, with the country
 * code and no "+" (the Contacts model strips `+ - ( )` and spaces). That form
 * is kept, so no historical row is ever rewritten: the canonical value of an
 * international number is simply its E.164 digits, which for every number that
 * was already entered internationally is byte-identical to what was stored.
 *
 * WHAT IS RESOLVED, AND WHAT IS NEVER GUESSED.
 *   - "+1 (415) 555-1234"  → 14155551234   explicit country code, no context needed
 *   - "+44 20 7946 0958"   → 442079460958  international numbers are preserved as-is
 *   - "415-555-1234" with a US region → 14155551234
 *   - "415-555-1234" with NO region   → 4155551234   (the legacy digits, untouched:
 *     which country it belongs to is genuinely unknown, so it is not matched to
 *     anything it was not already equal to)
 *
 * The region is supplied by the caller from the Business / Location the Contact
 * belongs to (regionFor()). It is only ever a hint for numbers written WITHOUT
 * a country code, and only counts when libphonenumber says the result is a
 * valid number there.
 *
 * MATCHING. candidates() returns every stored form an equivalent number may
 * already have, so a lookup finds a historical row written as "4155551234"
 * as well as a new one written as "14155551234". Callers must still scope the
 * query to the Business (and Location) FIRST; these strings only decide
 * equivalence inside that scope, never across Businesses.
 */
final class ContactPhone
{
    /** The digits `contacts.phone` has always stored: `+ - ( )` and spaces removed. */
    public static function legacyDigits(?string $raw): string
    {
        return trim(str_replace(['+', '-', '(', ')', ' '], '', trim((string) $raw)));
    }

    /**
     * The canonical stored form of $raw (see class docblock).
     */
    public static function canonical(?string $raw, ?string $defaultRegion = null): string
    {
        $legacy = self::legacyDigits($raw);

        if ($legacy === '') {
            return '';
        }

        $parsed = self::parse((string) $raw, $legacy, $defaultRegion);

        return $parsed === null
            ? $legacy
            : ltrim(PhoneNumberUtil::getInstance()->format($parsed, PhoneNumberFormat::E164), '+');
    }

    /**
     * Every stored form an equivalent number may carry, canonical first.
     *
     * @return list<string>
     */
    public static function candidates(?string $raw, ?string $defaultRegion = null): array
    {
        $legacy = self::legacyDigits($raw);

        if ($legacy === '') {
            return [];
        }

        $forms = [self::canonical($raw, $defaultRegion), $legacy];
        $parsed = self::parse((string) $raw, $legacy, $defaultRegion);
        $region = self::validRegion($defaultRegion);

        // A number that is local to the Business's own country may have been
        // stored, before this existed, in its national form. Only then — with
        // no region there is nothing to anchor that equivalence to.
        if ($parsed !== null && $region !== null
            && PhoneNumberUtil::getInstance()->getRegionCodeForNumber($parsed) === $region) {
            $forms[] = PhoneNumberUtil::getInstance()->getNationalSignificantNumber($parsed);
        }

        return array_values(array_unique(array_filter($forms, static fn (string $form): bool => $form !== '')));
    }

    /**
     * The ISO region a Contact's un-prefixed phone numbers belong to: the
     * Location's country, else the Business's. Null when neither is a real
     * region — then nothing is ever resolved by guesswork.
     */
    public static function regionFor(?Business $business, ?BusinessLocation $location = null): ?string
    {
        return self::validRegion($location?->country_code) ?? self::validRegion($business?->country_code);
    }

    private static function validRegion(?string $region): ?string
    {
        $region = strtoupper(trim((string) $region));

        return $region !== '' && in_array($region, PhoneNumberUtil::getInstance()->getSupportedRegions(), true)
            ? $region
            : null;
    }

    private static function parse(string $raw, string $legacy, ?string $defaultRegion): ?PhoneNumber
    {
        $util = PhoneNumberUtil::getInstance();
        $trimmed = trim($raw);

        try {
            // Explicit international form: the country code is stated, no
            // context is needed and none is used.
            if (str_starts_with($trimmed, '+')) {
                $number = $util->parse($trimmed, null);

                return $util->isValidNumber($number) ? $number : null;
            }

            $region = self::validRegion($defaultRegion);

            if ($region !== null) {
                $number = $util->parse($trimmed, $region);

                if ($util->isValidNumber($number)) {
                    return $number;
                }

                // Digits that already begin with another country code
                // ("442079460958" in a US Business) are the long-standing
                // stored convention, so they are read that way — never
                // forced into the Business's own country.
                $international = $util->parse('+' . $legacy, null);

                return $util->isValidNumber($international) ? $international : null;
            }
        } catch (NumberParseException) {
            return null;
        }

        return null;
    }
}
