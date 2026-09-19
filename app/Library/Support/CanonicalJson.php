<?php

declare(strict_types=1);

namespace App\Library\Support;

use App\Library\Support\Exceptions\CanonicalJsonUnavailableException;
use App\Library\Support\Exceptions\UnsupportedCanonicalValueException;
use Normalizer;
use stdClass;

/**
 * The domain-neutral canonical JSON primitive. Recursively sorts associative-
 * array ("map") keys by byte order and NFC-normalizes strings. It never
 * reorders or deduplicates a JSON list, never trims/collapses whitespace, and
 * never lowercases a string — every one of those is the caller's
 * responsibility, applied before a value ever reaches this class.
 *
 * EXTRACTED from App\Library\Opportunity\CanonicalJson (Implementation
 * Contract 17 §5.3.2/§12.A), BEHAVIOR-PRESERVINGLY, so that Payments &
 * Contracts can hash frozen commercial content without depending semantically
 * on the Opportunity domain. The algorithm is byte-for-byte the one the
 * Opportunity Engine has always used (fingerprinting RFC-002 §26, action
 * hashing §28): only the namespace and the exception base classes differ.
 * Opportunity keeps its own class name and its own exception types through a
 * thin adapter (App\Library\Opportunity\CanonicalJson) that delegates here and
 * translates the two neutral exceptions back into their Opportunity
 * counterparts, so every existing Opportunity caller and test is unchanged.
 *
 * Rules (relied on by the Contract 17 content_hash definition):
 *  - map keys recursively sorted by byte order;
 *  - list order and duplicates preserved;
 *  - strings NFC-normalized (requires ext-intl; fails loudly without it);
 *  - integers stay integers, floats keep their fractional zero;
 *  - non-finite floats and any non-scalar/array type are REFUSED, never
 *    coerced;
 *  - JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION
 *    | JSON_THROW_ON_ERROR.
 */
final class CanonicalJson
{
    /**
     * @throws UnsupportedCanonicalValueException
     * @throws CanonicalJsonUnavailableException
     */
    public static function encode(mixed $value): string
    {
        return json_encode(
            self::canonicalize($value),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR
        );
    }

    private static function canonicalize(mixed $value): mixed
    {
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => self::canonicalizeFloat($value),
            is_string($value) => self::canonicalizeString($value),
            is_array($value) => self::canonicalizeArray($value),
            default => throw new UnsupportedCanonicalValueException(
                'Unsupported value of type [' . get_debug_type($value) . '] cannot be canonicalized.'
            ),
        };
    }

    private static function canonicalizeFloat(float $value): float
    {
        if (! is_finite($value)) {
            throw new UnsupportedCanonicalValueException('Non-finite float values (NAN/INF/-INF) cannot be canonicalized.');
        }

        return $value;
    }

    /**
     * NFC-normalizes the string. Deliberately does not trim, collapse
     * internal whitespace, or lowercase — that is the caller's contract to
     * apply first. Fails loudly if ext-intl's Normalizer is not available,
     * rather than silently skipping normalization or falling back to an
     * approximate algorithm.
     */
    private static function canonicalizeString(string $value): string
    {
        if (! class_exists(Normalizer::class)) {
            throw new CanonicalJsonUnavailableException(
                'The intl extension (Normalizer) is required for canonical JSON string normalization but is not available in this runtime.'
            );
        }

        $normalized = Normalizer::normalize($value, Normalizer::FORM_C);

        if ($normalized === false) {
            throw new UnsupportedCanonicalValueException('String value could not be Unicode-normalized to NFC.');
        }

        return $normalized;
    }

    /**
     * A PHP list (sequential integer keys starting at 0) is preserved as a
     * JSON array, order and duplicates unchanged. Anything else is a map:
     * keys are sorted by byte order and the result is built as a stdClass
     * rather than an associative array, so json_encode always emits a JSON
     * object even when the sorted keys happen to be sequential integers
     * (PHP would otherwise silently re-encode such an array as a JSON list).
     */
    private static function canonicalizeArray(array $value): array|object
    {
        if (array_is_list($value)) {
            return array_map(self::canonicalize(...), $value);
        }

        ksort($value, SORT_STRING);

        $canonicalized = new stdClass();

        foreach ($value as $key => $item) {
            $canonicalized->{(string) $key} = self::canonicalize($item);
        }

        return $canonicalized;
    }
}
