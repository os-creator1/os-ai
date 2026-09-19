<?php

namespace Tests\Feature\Coo\Context;

use App\Enums\Coo\CooInsightKind;
use App\Enums\Coo\CooInsightOrigin;
use App\Enums\Coo\CooScope;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Coo\Context\AuthorizationScopeFingerprint;
use App\Library\Coo\Context\CooContextEnvelope;
use App\Models\CooInsight;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Coo\Insight\Concerns\CreatesCooInsightFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 19 §8 / §5.2 / §5.9, sub-slice 19.A — the shapes a
 * `coo_insights` row may take, and the write-once attribution that keeps its
 * audit honest.
 *
 * Two distinct guarantees are covered here, and they are deliberately not the
 * same guarantee:
 *
 *  - the SCHEMA admits all three scopes, including a Platform row with no
 *    tenant at all, so a fabricated Workspace or Business is never needed
 *    later (R-19);
 *  - the WRITER refuses everything except Business scope, because 19.A is the
 *    context/cache foundation and the code that composes Agency and Platform
 *    facts is 19.H's.
 */
class CooInsightScopeInvariantsTest extends TestCase
{
    use CreatesCooInsightFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpCooInsights();
    }

    // =================================================================
    // Envelope invariants
    // =================================================================

    public function test_a_business_or_agency_envelope_requires_both_a_workspace_and_a_business(): void
    {
        foreach ([CooScope::Business, CooScope::Agency] as $scope) {
            $this->assertThrows(
                fn () => new CooContextEnvelope($scope, null, 7, [], [], actorUserId: 3),
                InvalidArgumentException::class,
            );

            $this->assertThrows(
                fn () => new CooContextEnvelope($scope, 5, null, [], [], actorUserId: 3),
                InvalidArgumentException::class,
            );
        }
    }

    public function test_a_platform_envelope_refuses_a_fabricated_tenant(): void
    {
        $this->assertThrows(
            fn () => new CooContextEnvelope(CooScope::Platform, 5, 7, [], [], actorUserId: 3),
            InvalidArgumentException::class,
        );

        $platform = new CooContextEnvelope(CooScope::Platform, null, null, [], [], actorUserId: 3);

        $this->assertNull($platform->workspaceId);
        $this->assertNull($platform->businessId);
    }

    public function test_an_envelope_is_either_human_initiated_or_a_background_generation_never_both(): void
    {
        $this->assertThrows(
            fn () => new CooContextEnvelope(CooScope::Business, 5, 7, [], [], actorUserId: 3, audienceUserId: 4),
            InvalidArgumentException::class,
        );
    }

    public function test_an_envelope_may_be_narrowed_to_a_location_it_authorises_and_never_to_another(): void
    {
        $envelope = new CooContextEnvelope(CooScope::Business, 5, 7, [1, 2], [], actorUserId: 3);

        $pinned = $envelope->pinnedTo(2);

        $this->assertSame(2, $pinned->businessLocationId);
        $this->assertNotSame($envelope->authorizationScopeFingerprint, $pinned->authorizationScopeFingerprint);

        $this->assertThrows(fn () => $envelope->pinnedTo(99), InvalidArgumentException::class);
    }

    // =================================================================
    // Writer invariants
    // =================================================================

    public function test_a_business_scope_row_is_written_and_carries_its_scope_columns(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $insight = $this->cachedInsight($business);

        $this->assertSame(CooScope::Business, $insight->scope);
        $this->assertSame(CooInsightOrigin::System, $insight->origin);
        $this->assertSame((int) $business->id, $insight->business_id);
        $this->assertSame((int) $business->workspace_id, $insight->workspace_id);
        $this->assertTrue(AuthorizationScopeFingerprint::looksComputed((string) $insight->authorization_scope_fingerprint));
    }

    public function test_the_writer_refuses_an_agency_or_platform_row_until_sub_slice_19h(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        foreach ([CooScope::Agency, CooScope::Platform] as $scope) {
            $this->assertThrows(
                fn () => $this->cachedInsight($business, ['scope' => $scope->value]),
                InvalidArgumentException::class,
            );
        }

        $this->assertSame(0, CooInsight::query()->count());
    }

    public function test_the_writer_refuses_business_scope_without_tenancy(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->assertThrows(fn () => $this->cachedInsight($business, ['business_id' => null]), InvalidArgumentException::class);
        $this->assertThrows(fn () => $this->cachedInsight($business, ['workspace_id' => null]), InvalidArgumentException::class);
    }

    public function test_a_system_row_may_not_claim_an_actor_and_an_on_demand_row_must_name_one(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $this->assertThrows(
            fn () => $this->cachedInsight($business, ['actor_user_id' => (int) $customer->user_id]),
            InvalidArgumentException::class,
        );

        $this->assertThrows(
            fn () => $this->cachedInsight($business, ['origin' => CooInsightOrigin::OnDemand->value]),
            InvalidArgumentException::class,
        );
    }

    public function test_the_writer_refuses_the_retirement_sentinel_or_an_invented_fingerprint(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        foreach ([AuthorizationScopeFingerprint::RETIRED, 'not-a-fingerprint', ''] as $value) {
            $this->assertThrows(
                fn () => $this->cachedInsight($business, ['authorization_scope_fingerprint' => $value]),
                InvalidArgumentException::class,
            );
        }
    }

    // =================================================================
    // Schema readiness, proved without the writer
    // =================================================================

    /**
     * §8 — the columns must already admit a Platform row so 19.H never needs a
     * fabricated tenant. Written through the query builder deliberately: this
     * asserts what the SCHEMA allows, while the test above asserts what the
     * WRITER allows, and the two are different guarantees.
     */
    public function test_the_schema_admits_a_platform_shaped_row_with_no_tenant_at_all(): void
    {
        $id = DB::table('coo_insights')->insertGetId($this->rawRow([
            'scope' => CooScope::Platform->value,
            'workspace_id' => null,
            'business_id' => null,
        ]));

        $row = DB::table('coo_insights')->find($id);

        $this->assertNull($row->workspace_id);
        $this->assertNull($row->business_id);
        $this->assertSame(CooScope::Platform->value, $row->scope);
    }

    /**
     * §8 — MySQL treats NULLs as DISTINCT in a UNIQUE index, so the key is
     * built over the STORED generated surrogates instead. Two tenant-less rows
     * that differ only by authorization scope must coexist; two identical ones
     * must not.
     */
    public function test_the_unique_key_still_prevents_duplicates_when_tenancy_and_subject_are_null(): void
    {
        $shared = [
            'scope' => CooScope::Platform->value,
            'workspace_id' => null,
            'business_id' => null,
            'subject_type' => null,
            'subject_id' => null,
            'actor_user_id' => null,
        ];

        DB::table('coo_insights')->insert($this->rawRow($shared + ['authorization_scope_fingerprint' => str_repeat('a', 64)]));
        DB::table('coo_insights')->insert($this->rawRow($shared + ['authorization_scope_fingerprint' => str_repeat('b', 64)]));

        $this->assertSame(2, DB::table('coo_insights')->count(), 'Different authorization scopes are different rows.');

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('coo_insights')->insert($this->rawRow($shared + ['authorization_scope_fingerprint' => str_repeat('a', 64)]));
    }

    public function test_policy_version_is_part_of_the_unique_identity(): void
    {
        $shared = ['authorization_scope_fingerprint' => str_repeat('c', 64), 'scope' => CooScope::Platform->value, 'workspace_id' => null, 'business_id' => null];

        DB::table('coo_insights')->insert($this->rawRow($shared + ['policy_version' => 1]));
        DB::table('coo_insights')->insert($this->rawRow($shared + ['policy_version' => 2]));

        $this->assertSame(2, DB::table('coo_insights')->count(), 'Two policy versions of the same answer no longer collide.');
    }

    /** @param array<string, mixed> $overrides */
    private function rawRow(array $overrides = []): array
    {
        $now = Carbon::now();

        return array_merge([
            'uid' => (string) Str::uuid(),
            'scope' => CooScope::Business->value,
            'origin' => CooInsightOrigin::System->value,
            'business_id' => null,
            'workspace_id' => null,
            'business_location_id' => null,
            'actor_user_id' => null,
            'audience_user_id' => null,
            'view_as_session_id' => null,
            'kind' => CooInsightKind::PerformanceDiagnosis->value,
            'subject_type' => null,
            'subject_id' => null,
            'period_key' => 'this_month_2026-09',
            'signal_fingerprint' => str_repeat('f', 64),
            'authorization_scope_fingerprint' => str_repeat('a', 64),
            'facts_snapshot' => json_encode(['facts' => []]),
            'output' => json_encode(['statements' => []]),
            'prompt_version' => 1,
            'policy_version' => 1,
            'model_route' => 'routine',
            'provider_model' => null,
            'ai_usage_ledger_entry_id' => null,
            'generated_at' => $now,
            'expires_at' => $now->copy()->addDay(),
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);
    }
}
