<?php

namespace App\Library\GoogleAds\Reporting;

/**
 * Google Ads Module V1 — validates the list-page query parameters (sort, dir,
 * page, filters) against the readers' own whitelists. An invalid or unknown
 * value silently becomes the default: a hand-edited URL never errors and
 * never reaches SQL (the readers whitelist again, this just keeps the page's
 * own links and highlighted controls truthful).
 */
final class GoogleAdsListInput
{
    public const MAX_PAGE = 10000;

    /** Sorts that read naturally A to Z; everything else defaults to highest first. */
    private const TEXT_SORTS = ['name', 'keyword', 'term', 'campaign', 'status'];

    /** A period key, or null (the default period) for anything that is not a string. */
    public static function period(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /** @param  array<int, string>  $allowed */
    public static function sort(mixed $value, array $allowed, string $default = 'spend'): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    public static function direction(mixed $value, string $sort): string
    {
        if (is_string($value) && in_array(strtolower($value), ['asc', 'desc'], true)) {
            return strtolower($value);
        }

        return in_array($sort, self::TEXT_SORTS, true) ? 'asc' : 'desc';
    }

    public static function page(mixed $value): int
    {
        if (! is_string($value) && ! is_int($value)) {
            return 1;
        }

        return ctype_digit((string) $value) ? max(1, min(self::MAX_PAGE, (int) $value)) : 1;
    }

    /**
     * @param  array<int, string>  $allowed
     */
    public static function choice(mixed $value, array $allowed): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, $allowed, true) ? $value : null;
    }

    /**
     * A campaign filter, kept only when it is one of this account's own
     * campaigns (so a foreign / unknown uid is "no filter", not an error).
     *
     * @param  array<string, string>  $options  uid => name
     */
    public static function campaign(mixed $value, array $options): ?string
    {
        return is_string($value) && isset($options[$value]) ? $value : null;
    }

    /**
     * The query string a page's action forms carry so the owner returns to the
     * same filtered view: only whitelisted keys, scalar values, short.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function returnQuery(array $query): array
    {
        $kept = [];

        foreach (['period', 'sort', 'dir', 'status', 'class', 'campaign', 'page'] as $key) {
            $value = $query[$key] ?? null;

            if (is_string($value) && $value !== '' && strlen($value) <= 64 && preg_match('/\A[A-Za-z0-9_\-]+\z/', $value) === 1) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}
