<?php

namespace App\Library\MetaAds\Reporting;

use App\Library\GoogleAds\Reporting\GoogleAdsListInput;

/**
 * Meta Ads Module V1 — validates the list-page query parameters (period,
 * sort, dir, page, status, campaign, ad set) against the readers' own
 * whitelists. An invalid or unknown value silently becomes the default: a
 * hand-edited URL never errors and never reaches SQL (the readers whitelist
 * again; this keeps the page's own links and highlighted controls truthful).
 *
 * The provider-neutral primitives (period / page / choice) are reused from
 * GoogleAdsListInput by composition; sort direction is re-implemented because
 * Meta's text sorts differ (name, status, campaign, ad_set).
 */
final class MetaAdsListInput
{
    public const STATUSES = ['active', 'paused', 'archived', 'deleted'];

    /** Sorts that read naturally A to Z; everything else defaults to highest first. */
    private const TEXT_SORTS = ['name', 'status', 'campaign', 'ad_set'];

    public static function period(mixed $value): ?string
    {
        return GoogleAdsListInput::period($value);
    }

    /** @param  array<int, string>  $allowed */
    public static function sort(mixed $value, array $allowed, string $default = 'spend'): string
    {
        return GoogleAdsListInput::sort($value, $allowed, $default);
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
        return GoogleAdsListInput::page($value);
    }

    /** One of STATUSES (lower-case) or null (= all). */
    public static function status(mixed $value): ?string
    {
        return GoogleAdsListInput::choice($value, self::STATUSES);
    }

    /**
     * A campaign / ad set uid filter, kept only when it is one of the
     * account's own (a foreign or unknown uid is "no filter", not an error).
     *
     * @param  array<string, string>  $options  uid => name
     */
    public static function entity(mixed $value, array $options): ?string
    {
        return GoogleAdsListInput::campaign($value, $options);
    }

    /**
     * The query string an action form carries so the owner returns to the
     * same filtered view: only whitelisted keys, short scalar values.
     *
     * @param  array<string, mixed>  $query
     * @return array<string, string>
     */
    public static function returnQuery(array $query): array
    {
        $kept = [];

        foreach (['period', 'sort', 'dir', 'status', 'campaign', 'ad_set', 'page'] as $key) {
            $value = $query[$key] ?? null;

            if (is_string($value) && $value !== '' && strlen($value) <= 64 && preg_match('/\A[A-Za-z0-9_\-]+\z/', $value) === 1) {
                $kept[$key] = $value;
            }
        }

        return $kept;
    }
}
