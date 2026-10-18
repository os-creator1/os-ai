<?php

namespace App\Library\MetaAds;

/**
 * Meta Ads Module V1 contract §5 — builds the short, owner-readable ad set
 * targeting line SERVER-SIDE from Meta's `targeting` object, e.g.
 * "Ages 25–54 · Women · US, Texas, Austin". Only the age range, genders and a
 * few location names are read; the raw spec is never stored. Provider text is
 * length-capped and stripped of control characters. Result <= 255 chars, or
 * null when nothing usable is present.
 */
final class MetaAdsTargetingSummary
{
    private const MAX_LENGTH = 255;

    private const MAX_LOCATIONS = 3;

    /**
     * @param  array<string, mixed>  $targeting
     */
    public static function build(array $targeting): ?string
    {
        $parts = [];

        $min = MetaAdsJson::unsignedInt($targeting['age_min'] ?? null);
        $max = MetaAdsJson::unsignedInt($targeting['age_max'] ?? null);

        if ($min !== null && $max !== null && $min >= 13 && $max >= $min && $max <= 65) {
            $parts[] = 'Ages ' . $min . '–' . $max . ($max === 65 ? '+' : '');
        } elseif ($min !== null && $min >= 13 && $min <= 65) {
            $parts[] = 'Ages ' . $min . '+';
        }

        $genders = array_values(array_unique(array_filter(
            is_array($targeting['genders'] ?? null) ? $targeting['genders'] : [],
            static fn ($g): bool => in_array($g, [1, 2], true),
        )));

        if (count($genders) === 1) {
            $parts[] = $genders[0] === 1 ? 'Men' : 'Women';
        }

        $locations = self::locations(is_array($targeting['geo_locations'] ?? null) ? $targeting['geo_locations'] : []);

        if ($locations !== []) {
            $shown = array_slice($locations, 0, self::MAX_LOCATIONS);
            $extra = count($locations) - count($shown);
            $parts[] = implode(', ', $shown) . ($extra > 0 ? ' +' . $extra . ' more' : '');
        }

        if ($parts === []) {
            return null;
        }

        return mb_substr(implode(' · ', $parts), 0, self::MAX_LENGTH);
    }

    /**
     * @param  array<string, mixed>  $geo
     * @return array<int, string>
     */
    private static function locations(array $geo): array
    {
        $names = [];

        foreach (is_array($geo['countries'] ?? null) ? $geo['countries'] : [] as $country) {
            if (is_string($country) && preg_match('/\A[A-Z]{2}\z/', $country) === 1) {
                $names[] = $country;
            }
        }

        foreach (['regions', 'cities'] as $key) {
            foreach (is_array($geo[$key] ?? null) ? $geo[$key] : [] as $place) {
                $name = is_array($place) ? self::text($place['name'] ?? null) : null;

                if ($name !== null) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value));

        return $clean === '' ? null : mb_substr($clean, 0, 40);
    }
}
