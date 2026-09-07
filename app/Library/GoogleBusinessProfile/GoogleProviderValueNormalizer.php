<?php

namespace App\Library\GoogleBusinessProfile;

/**
 * GBP Slice A contract §14.5 / §20.3 — the single place every rule for
 * treating Google responses as UNTRUSTED INPUT lives.
 *
 * The contract states these rules once and requires them at DTO
 * construction; centralising them here means they cannot drift between
 * GoogleAccountSummary, GoogleLocationCandidate and GoogleLocationProfile
 * (narrow addition D-2, reported with this slice — a helper inside the
 * contract's own app/Library/GoogleBusinessProfile namespace, not a new
 * concept).
 *
 * Every rule below is a REJECTION, never a repair: a value that does not
 * match becomes null and the caller decides whether that invalidates the
 * whole record. Nothing is cast, coerced or "fixed up".
 */
final class GoogleProviderValueNormalizer
{
    /**
     * Contract §20.3 — a non-string where a string is expected becomes
     * null, never a cast. Empty and whitespace-only both become null so
     * that comparison rule 2 (absence is never Match) behaves correctly.
     */
    public static function string(mixed $value, int $maxLength): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, $maxLength);
    }

    public static function boolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    /**
     * Google returns latitude/longitude as JSON numbers. An int is a valid
     * JSON number for a whole-degree coordinate, so both are accepted;
     * anything else (including a numeric STRING) is rejected rather than
     * cast.
     */
    public static function float(mixed $value): ?float
    {
        if (is_float($value)) {
            return $value;
        }

        return is_int($value) ? (float) $value : null;
    }

    public static function integer(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * Contract §20.3 — regionCode must match ^[A-Z]{2}$, else null.
     */
    public static function regionCode(mixed $value): ?string
    {
        $candidate = self::string($value, 8);

        if ($candidate === null) {
            return null;
        }

        return preg_match('/^[A-Z]{2}$/', $candidate) === 1 ? $candidate : null;
    }

    /**
     * Contract §14.5 / §20.3 — the URL scheme allowlist. HTTPS ONLY.
     *
     * A value that is not an absolute https URL becomes null and is never
     * rendered. Note this allowlist governs what we DISPLAY; nothing in
     * Slice A ever fetches any of these URLs server-side (§14.4, §31,
     * test T-URL-2).
     */
    public static function httpsUrl(mixed $value, int $maxLength = 2048): ?string
    {
        $candidate = self::string($value, $maxLength);

        if ($candidate === null) {
            return null;
        }

        $parts = parse_url($candidate);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }

        return $candidate;
    }

    /**
     * Contract §20.3 / §14.4 — a Google resource name must match its exact
     * documented shape. A non-matching value makes the whole record
     * DISCARDED by the caller, never repaired.
     *
     * These two patterns are also what keeps the real HTTP client from
     * ever contacting a caller-controlled path: every endpoint it builds
     * is a compile-time constant host plus a resource name validated here.
     */
    public static function accountResourceName(mixed $value): ?string
    {
        $candidate = self::string($value, 191);

        if ($candidate === null) {
            return null;
        }

        return preg_match('/^accounts\/[A-Za-z0-9_-]+$/', $candidate) === 1 ? $candidate : null;
    }

    public static function locationResourceName(mixed $value): ?string
    {
        $candidate = self::string($value, 191);

        if ($candidate === null) {
            return null;
        }

        return preg_match('/^locations\/[A-Za-z0-9_-]+$/', $candidate) === 1 ? $candidate : null;
    }

    /**
     * Contract §20.3 — a value that must be one of a closed documented
     * set, else null. Used for OpenInfo.status and
     * ServiceAreaBusiness.businessType, so an unrecognised future Google
     * value degrades to "not shown" rather than being displayed verbatim.
     *
     * @param  array<int, string>  $allowed
     */
    public static function enumValue(mixed $value, array $allowed, int $maxLength = 64): ?string
    {
        $candidate = self::string($value, $maxLength);

        if ($candidate === null) {
            return null;
        }

        return in_array($candidate, $allowed, true) ? $candidate : null;
    }

    /**
     * Reads a nested key path out of a decoded provider array without
     * assuming any level exists or is an array.
     *
     * @param  array<int, string>  $path
     */
    public static function dig(array $payload, array $path): mixed
    {
        $cursor = $payload;

        foreach ($path as $segment) {
            if (! is_array($cursor) || ! array_key_exists($segment, $cursor)) {
                return null;
            }

            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    /**
     * @return array<int, mixed>
     */
    public static function listOf(mixed $value, int $maxItems): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_slice(array_values($value), 0, $maxItems);
    }
}
