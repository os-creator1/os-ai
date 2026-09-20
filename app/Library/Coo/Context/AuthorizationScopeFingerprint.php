<?php

namespace App\Library\Coo\Context;

use App\Enums\Coo\CooScope;
use App\Library\Coo\Insight\CooInsightFacts;

/**
 * Implementation Contract 19 §5.8 — the cache identity of a COO answer.
 *
 * WHY THIS EXISTS. "`business_location_id` is NULL" was going to mean "all the
 * Locations this actor may read". It cannot: two staff users can hold
 * different multi-Location subsets and different capability sets while both
 * present a null Location pin, so a null pin identifies nothing. A cached
 * insight generated under one authorization set must never become readable
 * under another merely because the bucketed aggregates happened to fingerprint
 * the same way. This class is that missing identity.
 *
 * WHAT GOES IN, exactly (§5.8's own document, in this order after
 * canonicalisation): the scope, the tenancy, the actor's *authorized* Location
 * set, the consulted capability keys, the View-As target, and the pinned
 * Location.
 *
 *  - R-20: authorization inputs only. No display text, no customer-typed
 *    value, nothing mutable by copy.
 *  - R-21: no session token, cookie, CSRF value or API key. Every input is an
 *    identifier or a permission key, and the test asserts that over the
 *    canonical document itself.
 *  - R-22: callers recompute this from the live envelope on every read and
 *    compare. A stored value is never trusted as proof of authorization.
 *  - R-23: `location_ids` is the authorized SET, not a display filter, so an
 *    owner with every Location and a staff member with a subset differ even
 *    when the visible numbers are identical.
 *  - R-24: `capability_keys` is CooCapabilityKeys::CONSULTED filtered to what
 *    the actor holds, so losing a capability strands the richer row at once.
 *
 * Canonicalisation reuses CooInsightFacts::canonicalJson() — the same
 * key-sorted, byte-stable encoder the signal fingerprint already uses. No
 * second canonical encoder (Contract 19 R-0).
 */
final class AuthorizationScopeFingerprint
{
    /**
     * The fingerprint document's schema version. Bumping it retires every
     * cached row at once, deliberately and visibly, because no live
     * fingerprint can equal one computed under a different version.
     */
    public const VERSION = 1;

    /**
     * The sentinel written to rows that existed before authorization scope
     * did (§8's retirement backfill). It is 64 zeros, which no SHA-256 of a
     * non-empty document has ever produced and which therefore can never equal
     * a computed fingerprint — so a retired row is unreachable by fingerprint
     * as well as by `invalidated_at`.
     */
    public const RETIRED = '0000000000000000000000000000000000000000000000000000000000000000';

    private function __construct()
    {
    }

    /**
     * @param  array<int, int>  $authorizedLocationIds  every Location the actor
     *   (or, for a background generation, the declared audience) may read
     * @param  array<int, string>  $capabilityKeys  the subset of
     *   CooCapabilityKeys::CONSULTED the actor actually holds
     */
    public static function compute(
        CooScope $scope,
        ?int $workspaceId,
        ?int $businessId,
        array $authorizedLocationIds,
        array $capabilityKeys,
        ?int $viewAsTargetBusinessId = null,
        ?int $pinnedLocationId = null,
    ): string {
        return hash('sha256', CooInsightFacts::canonicalJson(self::document(
            $scope,
            $workspaceId,
            $businessId,
            $authorizedLocationIds,
            $capabilityKeys,
            $viewAsTargetBusinessId,
            $pinnedLocationId,
        )));
    }

    /**
     * The exact canonical input document, exposed so a test can assert what is
     * — and is not — in it (R-21) without reimplementing this class.
     *
     * @param  array<int, int>  $authorizedLocationIds
     * @param  array<int, string>  $capabilityKeys
     * @return array<string, mixed>
     */
    public static function document(
        CooScope $scope,
        ?int $workspaceId,
        ?int $businessId,
        array $authorizedLocationIds,
        array $capabilityKeys,
        ?int $viewAsTargetBusinessId = null,
        ?int $pinnedLocationId = null,
    ): array {
        return [
            'v' => self::VERSION,
            'scope' => $scope->value,
            'workspace_id' => $workspaceId,
            'business_id' => $businessId,
            'location_ids' => self::normaliseLocationIds($authorizedLocationIds),
            'capability_keys' => self::normaliseCapabilityKeys($capabilityKeys),
            'view_as_target_business_id' => $viewAsTargetBusinessId,
            'pinned_location_id' => $pinnedLocationId,
        ];
    }

    /**
     * Sorted ascending, de-duplicated, re-indexed as a list — so the same
     * authorization set always produces the same bytes no matter what order
     * the repository handed it over in.
     *
     * @param  array<int|string, mixed>  $ids
     * @return array<int, int>
     */
    public static function normaliseLocationIds(array $ids): array
    {
        $normalised = array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $ids)));

        sort($normalised, SORT_NUMERIC);

        return $normalised;
    }

    /**
     * Sorted, de-duplicated, re-indexed as a list, and restricted to the
     * consulted vocabulary — a caller can never widen the fingerprint's
     * permission surface by passing a key fact composition does not read.
     *
     * @param  array<int|string, mixed>  $keys
     * @return array<int, string>
     */
    public static function normaliseCapabilityKeys(array $keys): array
    {
        $normalised = array_values(array_unique(array_filter(
            array_map(static fn (mixed $key): string => (string) $key, $keys),
            static fn (string $key): bool => in_array($key, CooCapabilityKeys::CONSULTED, true),
        )));

        sort($normalised, SORT_STRING);

        return $normalised;
    }

    /** True for a value this class could have produced — 64 lowercase hex characters. */
    public static function looksComputed(string $value): bool
    {
        return $value !== self::RETIRED && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }
}
