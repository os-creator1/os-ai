<?php

namespace Tests\Unit\Coo;

use App\Enums\Coo\CooScope;
use App\Library\Coo\Context\AuthorizationScopeFingerprint;
use App\Library\Coo\Context\CooCapabilityKeys;
use PHPUnit\Framework\TestCase;

/**
 * Implementation Contract 19 §5.8, sub-slice 19.A — the cache identity that
 * decides whether a cached COO answer belongs to one authorization set.
 *
 * Pure unit coverage of the fingerprint itself: what changes it, what does
 * not, and what may never be inside it (R-20…R-24).
 */
class AuthorizationScopeFingerprintTest extends TestCase
{
    private const LOCATIONS = [1, 2];

    private const CAPS = ['view_reports'];

    public function test_the_same_authorization_set_always_produces_the_same_bytes(): void
    {
        $this->assertSame($this->fingerprint(), $this->fingerprint());
    }

    public function test_location_order_and_duplicates_never_change_the_identity(): void
    {
        $this->assertSame(
            $this->fingerprint(locations: [1, 2]),
            $this->fingerprint(locations: [2, 1, 2]),
            'The authorized SET is what matters, not the order a repository handed it over in.',
        );
    }

    /**
     * R-23, the case this whole mechanism exists for: two staff members with
     * different multi-Location subsets both present a null Location pin, so
     * without this they would share a cache entry.
     */
    public function test_two_different_location_subsets_never_collide(): void
    {
        $this->assertNotSame($this->fingerprint(locations: [1, 2]), $this->fingerprint(locations: [2, 3]));
    }

    public function test_an_all_locations_actor_differs_from_a_subset_actor(): void
    {
        $this->assertNotSame($this->fingerprint(locations: [1, 2, 3]), $this->fingerprint(locations: [1, 2]));
    }

    /** R-24 — losing a capability strands the richer cached answer at once. */
    public function test_removing_a_consulted_capability_changes_the_identity(): void
    {
        $this->assertNotSame($this->fingerprint(caps: ['view_reports']), $this->fingerprint(caps: []));
    }

    public function test_a_capability_outside_the_consulted_vocabulary_cannot_widen_the_identity(): void
    {
        $this->assertSame(
            $this->fingerprint(caps: []),
            $this->fingerprint(caps: ['not_consulted_by_fact_composition']),
            'A caller cannot smuggle a key fact composition never reads into the identity.',
        );
    }

    /** §6.4 — a View-As read can never reuse an ordinary read's cache entry. */
    public function test_a_view_as_target_changes_the_identity(): void
    {
        $this->assertNotSame($this->fingerprint(), $this->fingerprint(viewAsTargetBusinessId: 77));
    }

    public function test_pinning_a_location_changes_the_identity(): void
    {
        $this->assertNotSame($this->fingerprint(), $this->fingerprint(pinnedLocationId: 2));
    }

    public function test_tenancy_and_scope_are_part_of_the_identity(): void
    {
        $this->assertNotSame($this->fingerprint(), $this->fingerprint(workspaceId: 99));
        $this->assertNotSame($this->fingerprint(), $this->fingerprint(businessId: 99));
        $this->assertNotSame(
            $this->fingerprint(),
            AuthorizationScopeFingerprint::compute(CooScope::Agency, 5, 7, self::LOCATIONS, self::CAPS),
        );
    }

    /**
     * R-21 — every input is an identifier or a permission key. Asserted over
     * the canonical document itself, so a future field cannot quietly smuggle
     * a secret in.
     */
    public function test_the_canonical_document_contains_no_secret_or_session_value(): void
    {
        $document = AuthorizationScopeFingerprint::document(CooScope::Business, 5, 7, self::LOCATIONS, self::CAPS, 77, 2);

        $this->assertSame(
            ['v', 'scope', 'workspace_id', 'business_id', 'location_ids', 'capability_keys', 'view_as_target_business_id', 'pinned_location_id'],
            array_keys($document),
            'The document is exactly §5.8s fields — nothing more may be folded in.',
        );

        $flattened = json_encode($document);

        foreach (['token', 'session', 'cookie', 'csrf', 'secret', 'password', 'api_key'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $flattened);
        }

        $this->assertSame(CooCapabilityKeys::CONSULTED, array_values(array_intersect(CooCapabilityKeys::CONSULTED, $document['capability_keys'])));
    }

    public function test_a_computed_fingerprint_is_lowercase_hex_and_is_never_the_retirement_sentinel(): void
    {
        $fingerprint = $this->fingerprint();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $fingerprint);
        $this->assertNotSame(AuthorizationScopeFingerprint::RETIRED, $fingerprint);
        $this->assertTrue(AuthorizationScopeFingerprint::looksComputed($fingerprint));
        $this->assertFalse(AuthorizationScopeFingerprint::looksComputed(AuthorizationScopeFingerprint::RETIRED));
        $this->assertFalse(AuthorizationScopeFingerprint::looksComputed('not-a-fingerprint'));
    }

    /**
     * The retirement sentinel must be unreachable by computation, which is
     * exactly why an empty authorization set is still safe to fingerprint.
     */
    public function test_even_an_empty_authorization_set_produces_a_real_fingerprint(): void
    {
        $empty = AuthorizationScopeFingerprint::compute(CooScope::Business, 5, 7, [], []);

        $this->assertTrue(AuthorizationScopeFingerprint::looksComputed($empty));
        $this->assertNotSame($this->fingerprint(), $empty);
    }

    /**
     * @param  array<int, int>  $locations
     * @param  array<int, string>  $caps
     */
    private function fingerprint(
        array $locations = self::LOCATIONS,
        array $caps = self::CAPS,
        int $workspaceId = 5,
        int $businessId = 7,
        ?int $viewAsTargetBusinessId = null,
        ?int $pinnedLocationId = null,
    ): string {
        return AuthorizationScopeFingerprint::compute(
            CooScope::Business,
            $workspaceId,
            $businessId,
            $locations,
            $caps,
            $viewAsTargetBusinessId,
            $pinnedLocationId,
        );
    }
}
