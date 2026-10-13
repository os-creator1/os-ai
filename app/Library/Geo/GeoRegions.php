<?php

namespace App\Library\Geo;

/**
 * Region helpers for the two countries whose regions are customarily written
 * as short codes (US states, Canadian provinces). GeoNames stores Canada's
 * admin1 as numbers, which are mapped here; every other country is matched on
 * country alone and displayed with its country name.
 */
final class GeoRegions
{
    private const US = [
        'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas', 'CA' => 'California',
        'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware', 'DC' => 'District of Columbia', 'FL' => 'Florida',
        'GA' => 'Georgia', 'HI' => 'Hawaii', 'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
        'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine', 'MD' => 'Maryland',
        'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota', 'MS' => 'Mississippi', 'MO' => 'Missouri',
        'MT' => 'Montana', 'NE' => 'Nebraska', 'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey',
        'NM' => 'New Mexico', 'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
        'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island', 'SC' => 'South Carolina',
        'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas', 'UT' => 'Utah', 'VT' => 'Vermont',
        'VA' => 'Virginia', 'WA' => 'Washington', 'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
    ];

    /** GeoNames' numeric Canadian admin1 codes. */
    private const CA_GEONAMES = [
        '01' => 'AB', '02' => 'BC', '03' => 'MB', '04' => 'NB', '05' => 'NL', '07' => 'NS',
        '08' => 'ON', '09' => 'PE', '10' => 'QC', '11' => 'SK', '12' => 'YT', '13' => 'NT', '14' => 'NU',
    ];

    private const CA = [
        'AB' => 'Alberta', 'BC' => 'British Columbia', 'MB' => 'Manitoba', 'NB' => 'New Brunswick',
        'NL' => 'Newfoundland and Labrador', 'NS' => 'Nova Scotia', 'ON' => 'Ontario', 'PE' => 'Prince Edward Island',
        'QC' => 'Quebec', 'SK' => 'Saskatchewan', 'YT' => 'Yukon', 'NT' => 'Northwest Territories', 'NU' => 'Nunavut',
    ];

    private function __construct()
    {
    }

    /** The short code to show for a GeoNames admin1 value, or null when the country is not written that way. */
    public static function displayCode(string $countryCode, string $admin1): ?string
    {
        return match ($countryCode) {
            'US' => isset(self::US[$admin1]) ? $admin1 : null,
            'CA' => self::CA_GEONAMES[$admin1] ?? null,
            default => null,
        };
    }

    /**
     * Whether the country's regions are matched at all (US/CA). Elsewhere a
     * free-text region cannot be reliably compared to GeoNames' codes, so the
     * country alone identifies a place.
     */
    public static function regionsAreMatchable(string $countryCode): bool
    {
        return $countryCode === 'US' || $countryCode === 'CA';
    }

    /**
     * Resolves what an owner typed ("California", "ca", "CA") to the GeoNames
     * admin1 value for that country, or null when it is not recognisable.
     */
    public static function toAdmin1(string $countryCode, ?string $region): ?string
    {
        $region = trim((string) $region);
        if ($region === '') {
            return null;
        }

        $needle = GeoText::fold($region);

        if ($countryCode === 'US') {
            foreach (self::US as $code => $name) {
                if ($needle === GeoText::fold($code) || $needle === GeoText::fold($name)) {
                    return $code;
                }
            }
        }

        if ($countryCode === 'CA') {
            $reverse = array_flip(self::CA_GEONAMES);
            foreach (self::CA as $code => $name) {
                if ($needle === GeoText::fold($code) || $needle === GeoText::fold($name)) {
                    return $reverse[$code];
                }
            }
        }

        return null;
    }

    public static function countryName(string $countryCode): string
    {
        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-' . $countryCode, 'en');

            if (is_string($name) && $name !== '' && $name !== $countryCode) {
                return $name;
            }
        }

        return $countryCode;
    }
}
