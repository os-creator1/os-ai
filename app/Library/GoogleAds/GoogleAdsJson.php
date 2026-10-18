<?php

namespace App\Library\GoogleAds;

/**
 * Google Ads Module V1 — the single place every rule for treating Google Ads
 * JSON as UNTRUSTED INPUT lives (counterpart of GoogleProviderValueNormalizer
 * for the Business Profile client).
 *
 * Every rule is a REJECTION, never a repair: a value that does not match
 * becomes null and the caller decides whether that invalidates the record.
 *
 * REST/JSON facts that matter here (contract §2): 64-bit integers
 * (`id`, `clicks`, `impressions`, `costMicros`, `amountMicros`…) arrive as
 * JSON STRINGS; doubles (`conversions`, `conversionsValue`) arrive as JSON
 * numbers; enums arrive as upper-case strings; absent fields are omitted.
 */
final class GoogleAdsJson
{
    /**
     * int64 from a JSON string or number. Overflow, a fraction, leading
     * zeros, whitespace or any non-integer text yields null.
     */
    public static function int64(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (! is_string($value) || preg_match('/\A-?\d+\z/', $value) !== 1) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT);

        return $parsed === false ? null : $parsed;
    }

    /** A non-negative int64, or null. */
    public static function unsignedInt64(mixed $value): ?int
    {
        $parsed = self::int64($value);

        return $parsed !== null && $parsed >= 0 ? $parsed : null;
    }

    /**
     * An entity id (campaign / ad group / criterion / budget) as a string
     * of 1..20 digits, or null. Ids are never cast to int: they are opaque.
     */
    public static function id(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        return is_string($value) && preg_match('/\A\d{1,20}\z/', $value) === 1 ? $value : null;
    }

    /**
     * A double (or numeric string) as an exact 6-place decimal string, ready
     * for DECIMAL(20,6). Null for anything non-numeric. Absent is null,
     * which is NOT zero.
     */
    public static function decimal(mixed $value): ?string
    {
        if (is_int($value)) {
            return bcadd((string) $value, '0', 6);
        }

        if (is_float($value)) {
            return is_nan($value) || is_infinite($value) ? null : number_format($value, 6, '.', '');
        }

        if (is_string($value) && preg_match('/\A-?\d+(\.\d+)?\z/', $value) === 1) {
            return bcadd($value, '0', 6);
        }

        return null;
    }

    public static function string(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : mb_substr($trimmed, 0, $maxLength);
    }

    public static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /** `YYYY-MM-DD` that is a real calendar date, else null. */
    public static function date(mixed $value): ?string
    {
        if (! is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $value : null;
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

    /** The trailing id of a resource name such as `customers/1/campaignBudgets/9`. */
    public static function resourceTail(mixed $resourceName): ?string
    {
        if (! is_string($resourceName) || $resourceName === '') {
            return null;
        }

        $slash = strrpos($resourceName, '/');

        return self::id($slash === false ? $resourceName : substr($resourceName, $slash + 1));
    }
}
