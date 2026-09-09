<?php

namespace Tests\Support;

/**
 * One canonical shape for comparing decoded JSON that a storage layer may
 * legitimately have reordered.
 *
 * WHY THIS EXISTS. MySQL's native `json` type does not preserve the key
 * order of a stored object — it normalises keys by length, then
 * lexicographically:
 *
 *   SELECT CAST('{"type":"text","data":{}}' AS JSON);
 *   -- {"data": {}, "type": "text"}
 *
 * So an assertSame() between an in-memory fixture array and the value
 * read back from a `json` column compares two arrays that describe
 * exactly the same document but disagree about key order, and fails for
 * a reason that has nothing to do with what the test is proving.
 *
 * Canonicalising is NOT a weakening. Objects are unordered by definition,
 * so ordering them makes the comparison correct rather than lenient:
 *
 *   * associative arrays are key-sorted, recursively;
 *   * LISTS KEEP THEIR ORDER, because a JSON array IS ordered and a
 *     reordered list is a genuine difference this must still catch;
 *   * scalars are untouched, so any value difference — a changed string,
 *     a missing key, an extra key, null vs "" — still compares as
 *     different.
 */
final class CanonicalJson
{
    public static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = array_map(static fn ($item) => self::canonicalize($item), $value);

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
