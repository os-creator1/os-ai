<?php

namespace App\Library\MetaAds;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Meta Ads Module V1 — the single place every rule for treating Graph JSON as
 * UNTRUSTED INPUT lives. Every rule is a REJECTION, never a repair: a value
 * that does not match becomes null and the caller decides whether that
 * invalidates the record.
 *
 * Graph facts that matter here: ids are digit STRINGS; counters (impressions,
 * clicks, reach, action values) arrive as digit strings; spend / budgets are
 * strings too; `frequency` is a decimal string; enums are upper-case strings.
 */
final class MetaAdsJson
{
    /** An entity id as a string of 1..20 digits (an `act_` prefix is stripped), or null. */
    public static function id(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        if (str_starts_with($value, 'act_')) {
            $value = substr($value, 4);
        }

        return preg_match('/\A\d{1,20}\z/', $value) === 1 ? $value : null;
    }

    /** A non-negative int from a digit string or int, else null. */
    public static function unsignedInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A\d{1,18}\z/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }

    public static function string(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }

    /** An upper-case enum token such as ACTIVE / WITH_ISSUES, else null. */
    public static function enum(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Z][A-Z0-9_]{0,39}\z/', $value) === 1 ? $value : null;
    }

    /** A decimal (string or number) as float, else null. Not for money. */
    public static function ratio(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return is_finite((float) $value) && $value >= 0 ? (float) $value : null;
        }

        return is_string($value) && preg_match('/\A\d+(\.\d+)?\z/', $value) === 1 ? (float) $value : null;
    }

    /** `YYYY-MM-DD` that is a real calendar date, else null. */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
    }

    public static function dateTime(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    /** A paging cursor (opaque base64-ish token), else null. */
    public static function cursor(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9+\/=_-]{1,1024}\z/', $value) === 1 ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $path
     */
    public static function dig(array $data, array $path): mixed
    {
        $current = $data;

        foreach ($path as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }
}
