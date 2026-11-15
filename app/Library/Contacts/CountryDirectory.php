<?php

namespace App\Library\Contacts;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberType;
use libphonenumber\PhoneNumberUtil;
use libphonenumber\ValidationResult;

/**
 * Countries for the phone-number picker, and country-aware validation of a
 * number typed against a chosen country. Nothing here invents a rule: names
 * and calling codes come from ICU and libphonenumber (already the project's
 * phone library, see ContactPhone), validity is libphonenumber's verdict.
 */
final class CountryDirectory
{
    /** Shown first in the picker, in this order. */
    public const COMMON = ['US', 'GB', 'CA', 'AU', 'LT', 'DE', 'FR', 'ES', 'IT', 'PL', 'MX', 'IN'];

    public const DEFAULT_REGION = 'US';

    /**
     * @return list<array{iso: string, name: string, code: string, flag: string, example: string, common: bool}>
     */
    public static function all(): array
    {
        $util = PhoneNumberUtil::getInstance();
        $list = [];

        foreach ($util->getSupportedRegions() as $iso) {
            $code = $util->getCountryCodeForRegion($iso);

            if ($code === 0) {
                continue;
            }

            $example = $util->getExampleNumberForType($iso, PhoneNumberType::MOBILE) ?? $util->getExampleNumber($iso);

            $list[] = [
                'iso' => $iso,
                'name' => self::name($iso),
                'code' => '+' . $code,
                'flag' => self::flag($iso),
                'example' => $example !== null ? $util->format($example, PhoneNumberFormat::NATIONAL) : '',
                'common' => in_array($iso, self::COMMON, true),
            ];
        }

        usort($list, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $list;
    }

    public static function name(string $iso): string
    {
        $name = class_exists(\Locale::class) ? \Locale::getDisplayRegion('-' . $iso, 'en') : '';

        return $name !== '' && $name !== $iso ? $name : $iso;
    }

    /** Regional-indicator emoji; renders as the two letters where flags are unsupported. */
    public static function flag(string $iso): string
    {
        $iso = strtoupper($iso);

        if (! preg_match('/^[A-Z]{2}$/', $iso)) {
            return '';
        }

        return mb_chr(0x1F1E6 + ord($iso[0]) - 65) . mb_chr(0x1F1E6 + ord($iso[1]) - 65);
    }

    public static function isRegion(?string $iso): bool
    {
        return in_array(strtoupper((string) $iso), PhoneNumberUtil::getInstance()->getSupportedRegions(), true);
    }

    /**
     * Validate $input as a number of $region.
     *
     * @return array{ok: true, digits: string, e164: string, display: string, region: string}|array{ok: false, error: string}
     */
    public static function validate(?string $input, ?string $region): array
    {
        $util = PhoneNumberUtil::getInstance();
        $region = strtoupper((string) $region);
        $input = trim((string) $input);

        if ($input === '') {
            return ['ok' => false, 'error' => 'Enter a phone number.'];
        }

        if (! self::isRegion($region)) {
            return ['ok' => false, 'error' => 'Choose a country for this number.'];
        }

        if (preg_match('/[^\d\s\-().+]/', $input) === 1) {
            return ['ok' => false, 'error' => 'A phone number can only contain digits, spaces and + - ( ).'];
        }

        $countryName = self::name($region);
        $example = $util->getExampleNumber($region);
        $hint = $example !== null ? ' Example: ' . $util->format($example, PhoneNumberFormat::NATIONAL) . '.' : '';

        try {
            $number = $util->parse($input, $region);
        } catch (NumberParseException) {
            return ['ok' => false, 'error' => 'That does not look like a phone number.' . $hint];
        }

        // An explicit "+<code>" may name another country; it must still be a real number.
        $effective = $util->getRegionCodeForNumber($number) ?? $region;

        if (! $util->isValidNumber($number)) {
            $reason = $util->isPossibleNumberWithReason($number);
            $error = match ($reason) {
                ValidationResult::TOO_SHORT => 'That number is too short for ' . $countryName . '.',
                ValidationResult::TOO_LONG => 'That number is too long for ' . $countryName . '.',
                default => 'That is not a valid ' . $countryName . ' phone number.',
            };

            return ['ok' => false, 'error' => $error . $hint];
        }

        $e164 = $util->format($number, PhoneNumberFormat::E164);

        return [
            'ok' => true,
            'digits' => ltrim($e164, '+'),
            'e164' => $e164,
            'display' => $util->format($number, PhoneNumberFormat::INTERNATIONAL),
            'region' => $effective,
        ];
    }
}
