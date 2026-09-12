<?php

namespace Tests\Feature\Business;

use App\Library\Workspace\WorkspaceManager;
use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — the additive capacity migration
 * (contract §23.2, §23.3; RFC-004 §33.5–§33.6). T-LOC-8, T-BIZ-2 at
 * migration time, plus forward, rollback, replay and idempotence.
 *
 * Deliberately does NOT use RefreshDatabase: the migration performs real
 * DDL, which implicitly commits in MySQL. Each test moves the schema to the
 * state it needs, commits its own fixture rows, and tearDown() removes
 * those rows and restores the fully migrated schema unconditionally — every
 * other test file assumes it — mirroring UsageMeterBackfillPreflightTest.
 */
class BusinessLocationCapacityMigrationTest extends TestCase
{
    private const MIGRATION = '2026_09_15_100001_add_physical_location_capacity_and_lifecycle.php';

    private array $userIds = [];

    private array $workspaceIds = [];

    private array $currencyIds = [];

    private array $providerCustomerIds = [];

    protected function tearDown(): void
    {
        try {
            DB::table('additional_business_slot_agreements')->whereIn('workspace_id', $this->workspaceIds)->delete();
            DB::table('payment_provider_customers')->whereIn('id', $this->providerCustomerIds)->delete();
            DB::table('currencies')->whereIn('id', $this->currencyIds)->delete();

            $businessIds = DB::table('businesses')->whereIn('workspace_id', $this->workspaceIds)->pluck('id');
            DB::table('business_locations')->whereIn('business_id', $businessIds)->delete();
            DB::table('businesses')->whereIn('id', $businessIds)->delete();
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->workspaceIds)->delete();
            DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->workspaceIds)->delete();
            DB::table('workspaces')->whereIn('id', $this->workspaceIds)->delete();
            DB::table('customers')->whereIn('user_id', $this->userIds)->delete();
            DB::table('users')->whereIn('id', $this->userIds)->delete();
        } finally {
            $this->restoreMigratedSchema();
        }

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Fresh install
    // ------------------------------------------------------------------

    public function test_a_fresh_install_carries_the_corrected_catalog_and_the_additive_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('workspace_plan_catalog', ['location_slot_included', 'location_slot_max', 'unlimited_location_slots', 'additional_location_slot_price_ratio']));
        $this->assertTrue(Schema::hasColumns('businesses', ['additional_location_slots', 'grandfathered_location_slots']));
        $this->assertTrue(Schema::hasColumns('business_locations', ['lifecycle_state', 'archived_at']));
        $this->assertTrue(Schema::hasColumn('workspace_entitlement_transitions', 'payload'));
        $this->assertFalse(Schema::hasColumn('business_locations', 'deleted_at'), 'Archiving is a state, never SoftDeletes.');

        $core = DB::table('workspace_plan_catalog')->where('tier', 'core')->first();
        $this->assertSame([1, 1, 0], [(int) $core->business_slot_included, (int) $core->business_slot_max, (int) $core->unlimited_business_slots]);
        $this->assertNull($core->additional_business_slot_price_ratio);
        $this->assertSame([3, 5, 0, '0.5000'], [(int) $core->location_slot_included, (int) $core->location_slot_max, (int) $core->unlimited_location_slots, $core->additional_location_slot_price_ratio]);
        $this->assertNull($core->price, 'No Core/Growth price is invented.');

        $agency = DB::table('workspace_plan_catalog')->where('tier', 'agency')->first();
        $this->assertSame(1, (int) $agency->unlimited_business_slots);
        $this->assertSame(1, (int) $agency->unlimited_location_slots);
        $this->assertNull($agency->location_slot_max);
    }

    // ------------------------------------------------------------------
    // Forward: grandfathering before tightening (T-LOC-8, T-BIZ-2)
    // ------------------------------------------------------------------

    public function test_forward_grandfathers_existing_data_and_keeps_every_business_and_location(): void
    {
        $this->migration()->down();
        $fixture = $this->legacyFixture();
        $locationIdsBefore = DB::table('business_locations')->whereIn('business_id', $fixture['all_business_ids'])->orderBy('id')->pluck('id')->all();

        $this->migration()->up();

        $this->assertSame($locationIdsBefore, DB::table('business_locations')->whereIn('business_id', $fixture['all_business_ids'])->orderBy('id')->pluck('id')->all(), 'No location row was deleted.');
        $this->assertSame(0, DB::table('business_locations')->whereIn('business_id', $fixture['all_business_ids'])->where('lifecycle_state', '!=', 'active')->count(), 'Every existing location is active.');
        $this->assertSame(3, DB::table('businesses')->where('workspace_id', $fixture['core_workspace'])->count(), 'No Business was deleted.');

        $this->assertSame(2, $this->grandfathered($fixture['core_big']), 'Five active on Core: two above the three included.');
        $this->assertSame(0, $this->grandfathered($fixture['core_small']));
        $this->assertSame(0, $this->grandfathered($fixture['core_empty']));
        $this->assertSame(0, $this->grandfathered($fixture['agency_business']), 'Agency is unlimited.');
        $this->assertSame(0, (int) DB::table('businesses')->whereIn('id', $fixture['all_business_ids'])->sum('additional_location_slots'), 'Nothing is owed retroactively.');

        $rows = DB::table('workspace_entitlement_transitions')->where('transition_type', 'capacity_grandfathered')->whereIn('workspace_id', $this->workspaceIds)->get();
        $this->assertCount(1, $rows, 'One row per affected Workspace; the Agency and in-capacity Workspaces are unaffected.');
        $row = $rows->first();
        $payload = json_decode($row->payload, true);
        $this->assertSame($fixture['core_workspace'], (int) $row->workspace_id);
        $this->assertNull($row->actor_user_id, 'System provenance.');
        $this->assertSame('slice_1a_capacity_correction_v1', $payload['source']);
        $this->assertTrue($payload['businesses']['grandfathered_over_capacity']);
        $this->assertSame(3, $payload['businesses']['count']);
        // MySQL normalizes JSON object key order, so compare by key.
        $this->assertEquals([['business_id' => $fixture['core_big'], 'active_locations_before' => 5, 'active_locations_after' => 5, 'included' => 3, 'additional_location_slots' => 0, 'grandfathered_location_slots' => 2]], $payload['locations']);

        foreach ($fixture['all_business_ids'] as $businessId) {
            $owner = (int) DB::table('businesses')->where('id', $businessId)->value('customer_id');
            $this->assertTrue(app(WorkspaceManager::class)->userCanAccessBusiness($owner, Business::findOrFail($businessId)), "Business {$businessId} stays reachable.");
        }
    }

    public function test_the_backfill_is_idempotent(): void
    {
        $this->migration()->down();
        $fixture = $this->legacyFixture();
        $migration = $this->migration();
        $migration->up();

        $snapshot = fn () => [
            DB::table('businesses')->whereIn('id', $fixture['all_business_ids'])->orderBy('id')->pluck('grandfathered_location_slots', 'id')->all(),
            DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->workspaceIds)->count(),
        ];
        $first = $snapshot();

        $migration->backfillGrandfathering();
        $migration->backfillGrandfathering();

        $this->assertSame($first, $snapshot(), 'Re-invoking the backfill writes the same counts and no duplicate row.');
    }

    // ------------------------------------------------------------------
    // Rollback and replay
    // ------------------------------------------------------------------

    public function test_a_pristine_rollback_succeeds_and_a_replay_converges(): void
    {
        $this->migration()->down();
        $fixture = $this->legacyFixture();
        $this->migration()->up();
        $afterFirstUp = $this->grandfatheredMap($fixture);

        // baseline → migrate → no runtime changes → rollback MUST succeed.
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('businesses', 'grandfathered_location_slots'));
        $this->assertFalse(Schema::hasColumn('business_locations', 'lifecycle_state'));
        $this->assertFalse(Schema::hasColumn('workspace_entitlement_transitions', 'payload'));
        $this->assertFalse(Schema::hasColumn('workspace_plan_catalog', 'location_slot_included'));
        $this->assertSame(0, DB::table('workspace_entitlement_transitions')->where('transition_type', 'capacity_grandfathered')->count(), 'Only the migration-owned rows were removed.');
        $this->assertSame(1, DB::table('workspace_entitlement_transitions')->where('workspace_id', $fixture['core_workspace'])->where('transition_type', 'plan_assigned')->count(), 'Genuine history is untouched.');

        $core = DB::table('workspace_plan_catalog')->where('tier', 'core')->first();
        $this->assertSame([3, 5, '0.5000'], [(int) $core->business_slot_included, (int) $core->business_slot_max, $core->additional_business_slot_price_ratio], 'The Milestone 1 values are restored exactly.');
        $this->assertSame(8, DB::table('business_locations')->where('business_id', $fixture['agency_business'])->count(), 'Every location survives the rollback.');

        $this->migration()->up();

        $this->assertSame($afterFirstUp, $this->grandfatheredMap($fixture), 'The replay converges on the same state.');
        $this->assertSame(1, DB::table('workspace_entitlement_transitions')->where('transition_type', 'capacity_grandfathered')->whereIn('workspace_id', $this->workspaceIds)->count());
    }

    public function test_rollback_refuses_while_an_archived_location_exists_and_changes_nothing(): void
    {
        $fixture = $this->migratedFixture();
        DB::table('business_locations')->where('business_id', $fixture['core_small'])->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);

        $this->assertRollbackRefused('archived locations exist');
    }

    public function test_rollback_refuses_while_a_runtime_allocation_exists(): void
    {
        $fixture = $this->migratedFixture();
        DB::table('businesses')->where('id', $fixture['core_small'])->update(['additional_location_slots' => 1]);

        $this->assertRollbackRefused('additional-location allocations exist');
    }

    public function test_rollback_refuses_when_a_grandfathered_allowance_changed_at_runtime(): void
    {
        $fixture = $this->migratedFixture();
        DB::table('businesses')->where('id', $fixture['core_big'])->update(['grandfathered_location_slots' => 1]);

        $this->assertRollbackRefused('grandfathered location allowances changed');
    }

    public function test_rollback_refuses_when_runtime_locations_exceed_what_the_old_schema_can_bound(): void
    {
        $fixture = $this->migratedFixture();
        $this->insertLocations($fixture['core_small'], 3, 'Runtime');

        $this->assertRollbackRefused('more active locations than the old schema can bound');
    }

    public function test_rollback_never_deletes_runtime_audit_history(): void
    {
        $fixture = $this->migratedFixture();
        DB::table('workspace_entitlement_transitions')->insert([
            'workspace_id' => $fixture['core_workspace'],
            'transition_type' => 'additional_location_slots_changed',
            'actor_user_id' => $this->userIds[0],
            'payload' => json_encode(['source' => 'platform_admin_allocation']),
            'created_at' => now(),
        ]);

        $this->assertRollbackRefused('runtime audit row');
        $this->assertSame(1, DB::table('workspace_entitlement_transitions')->where('transition_type', 'additional_location_slots_changed')->where('workspace_id', $fixture['core_workspace'])->count());
    }

    public function test_rollback_never_overwrites_an_operator_catalog_edit(): void
    {
        $this->migratedFixture();
        $original = DB::table('workspace_plan_catalog')->where('tier', 'growth')->value('location_slot_max');
        DB::table('workspace_plan_catalog')->where('tier', 'growth')->update(['location_slot_max' => 6]);

        try {
            $this->assertRollbackRefused('[growth].location_slot_max');
            $this->assertSame(6, (int) DB::table('workspace_plan_catalog')->where('tier', 'growth')->value('location_slot_max'), 'The operator edit is untouched.');
        } finally {
            DB::table('workspace_plan_catalog')->where('tier', 'growth')->update(['location_slot_max' => $original]);
        }
    }

    // ------------------------------------------------------------------
    // Forward preflights
    // ------------------------------------------------------------------

    public function test_up_aborts_before_any_change_while_a_live_additional_business_slot_agreement_exists(): void
    {
        $this->migration()->down();
        $fixture = $this->legacyFixture();
        $agreementId = $this->insertAgreement($fixture['core_workspace'], 'completed');

        try {
            $this->migration()->up();
            $this->fail('A live agreement must stop the migration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString("agreement {$agreementId}", $e->getMessage());
            $this->assertStringContainsString('separate Billing remediation', $e->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('businesses', 'additional_location_slots'), 'No DDL ran.');
        $this->assertSame(3, (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('business_slot_included'), 'No catalog value changed.');
        $this->assertSame('completed', DB::table('additional_business_slot_agreements')->where('id', $agreementId)->value('state'), 'The agreement is not cancelled or touched.');

        DB::table('additional_business_slot_agreements')->where('id', $agreementId)->update(['state' => 'canceled']);
        $this->migration()->up();
        $this->assertTrue(Schema::hasColumn('businesses', 'additional_location_slots'), 'A terminal agreement does not block.');
    }

    public function test_up_aborts_when_the_business_capacity_is_not_the_milestone_1_value(): void
    {
        $this->migration()->down();
        DB::table('workspace_plan_catalog')->where('tier', 'core')->update(['business_slot_max' => 6]);

        try {
            $this->migration()->up();
            $this->fail('An unexpected starting value must stop the migration.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('[core].business_slot_max', $e->getMessage());
        } finally {
            DB::table('workspace_plan_catalog')->where('tier', 'core')->update(['business_slot_max' => 5]);
        }

        $this->assertFalse(Schema::hasColumn('businesses', 'additional_location_slots'), 'No DDL ran.');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function migration(): object
    {
        return require database_path('migrations/' . self::MIGRATION);
    }

    private function restoreMigratedSchema(): void
    {
        DB::table('additional_business_slot_agreements')->whereIn('workspace_id', $this->workspaceIds)->delete();

        if (! Schema::hasColumn('businesses', 'additional_location_slots')) {
            DB::table('workspace_plan_catalog')->whereIn('tier', ['core', 'growth'])->update([
                'business_slot_included' => 3, 'business_slot_max' => 5, 'unlimited_business_slots' => false, 'additional_business_slot_price_ratio' => '0.5000',
            ]);
            $this->migration()->up();
        }
    }

    private function migratedFixture(): array
    {
        $this->migration()->down();
        $fixture = $this->legacyFixture();
        $this->migration()->up();

        return $fixture;
    }

    private function assertRollbackRefused(string $expectedReason): void
    {
        $ownedRowsBefore = DB::table('workspace_entitlement_transitions')->where('transition_type', 'capacity_grandfathered')->count();

        try {
            $this->migration()->down();
            $this->fail('The rollback must refuse.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nothing was changed', $e->getMessage());
            $this->assertStringContainsString($expectedReason, $e->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('businesses', 'grandfathered_location_slots'), 'No column was dropped.');
        $this->assertTrue(Schema::hasColumn('business_locations', 'lifecycle_state'));
        $this->assertSame(1, (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('business_slot_max'), 'No catalog value was restored.');
        $this->assertSame($ownedRowsBefore, DB::table('workspace_entitlement_transitions')->where('transition_type', 'capacity_grandfathered')->count(), 'No audit row was deleted.');
    }

    /**
     * Pre-migration data, inserted with plain query-builder rows (no model
     * events, no listeners): a Core Workspace already holding three
     * Businesses — one with five locations — an Agency Business with eight
     * locations, and an in-capacity Growth Business.
     */
    private function legacyFixture(): array
    {
        $coreWorkspace = $this->insertWorkspace('core');
        $coreBig = $this->insertBusiness($coreWorkspace, 'Legacy Big');
        $coreSmall = $this->insertBusiness($coreWorkspace, 'Legacy Small');
        $coreEmpty = $this->insertBusiness($coreWorkspace, 'Legacy Empty');
        $this->insertLocations($coreBig, 5, 'Big');
        $this->insertLocations($coreSmall, 1, 'Small');

        $agencyWorkspace = $this->insertWorkspace('agency');
        $agencyBusiness = $this->insertBusiness($agencyWorkspace, 'Agency Client');
        $this->insertLocations($agencyBusiness, 8, 'Agency');

        $growthWorkspace = $this->insertWorkspace('growth');
        $growthBusiness = $this->insertBusiness($growthWorkspace, 'Growth Co');
        $this->insertLocations($growthBusiness, 2, 'Growth');

        return [
            'core_workspace' => $coreWorkspace,
            'core_big' => $coreBig,
            'core_small' => $coreSmall,
            'core_empty' => $coreEmpty,
            'agency_business' => $agencyBusiness,
            'all_business_ids' => [$coreBig, $coreSmall, $coreEmpty, $agencyBusiness, $growthBusiness],
        ];
    }

    private function insertWorkspace(string $tier): int
    {
        $userId = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Legacy', 'last_name' => 'Owner',
            'email' => 'slice1a-migration-' . uniqid('', true) . '@example.test', 'status' => true, 'is_admin' => false,
            'is_customer' => true, 'active_portal' => 'customer', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->userIds[] = $userId;
        DB::table('customers')->insert(['uid' => (string) Str::uuid(), 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);

        $workspaceId = DB::table('workspaces')->insertGetId([
            'uid' => (string) Str::uuid(), 'name' => "Legacy {$tier}", 'owner_user_id' => $userId, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->workspaceIds[] = $workspaceId;

        DB::table('workspace_plan_assignments')->insert([
            'workspace_id' => $workspaceId,
            'workspace_plan_catalog_id' => DB::table('workspace_plan_catalog')->where('tier', $tier)->value('id'),
            'status' => 'active', 'is_complimentary' => true, 'complimentary_reason' => 'Slice 1A migration fixture.',
            'additional_business_slots' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_entitlement_transitions')->insert([
            'workspace_id' => $workspaceId, 'transition_type' => 'plan_assigned', 'actor_user_id' => null,
            'to_plan_catalog_id' => DB::table('workspace_plan_catalog')->where('tier', $tier)->value('id'),
            'reason' => 'Slice 1A migration fixture.', 'created_at' => now(),
        ]);

        return $workspaceId;
    }

    private function insertBusiness(int $workspaceId, string $name): int
    {
        return DB::table('businesses')->insertGetId([
            'uid' => (string) Str::uuid(),
            'customer_id' => DB::table('workspaces')->where('id', $workspaceId)->value('owner_user_id'),
            'workspace_id' => $workspaceId,
            'name' => $name, 'industry' => 'photo_booth_service', 'country_code' => 'US',
            'timezone' => 'America/New_York', 'currency_code' => 'USD', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function insertLocations(int $businessId, int $count, string $prefix): void
    {
        $hasPrimary = DB::table('business_locations')->where('business_id', $businessId)->where('is_primary', true)->exists();

        for ($i = 1; $i <= $count; $i++) {
            DB::table('business_locations')->insert([
                'uid' => (string) Str::uuid(), 'business_id' => $businessId, 'name' => "{$prefix} {$i}",
                'service_mode' => 'storefront', 'city' => 'Springfield', 'region' => 'IL', 'country_code' => 'US',
                'public_address' => false, 'is_primary' => ! $hasPrimary && $i === 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function insertAgreement(int $workspaceId, string $state): int
    {
        $currencyId = DB::table('currencies')->insertGetId(['uid' => (string) Str::uuid(), 'name' => 'Slice 1A Dollar', 'code' => 'ZZA', 'format' => '$', 'status' => true, 'created_at' => now(), 'updated_at' => now()]);
        $this->currencyIds[] = $currencyId;
        $providerCustomerId = DB::table('payment_provider_customers')->insertGetId(['provider' => 'stripe', 'workspace_id' => $workspaceId, 'provider_customer_id' => 'cus_slice1a_' . uniqid(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->providerCustomerIds[] = $providerCustomerId;

        return DB::table('additional_business_slot_agreements')->insertGetId([
            'workspace_id' => $workspaceId, 'current_allocation_count' => 0, 'target_allocation_count' => 1, 'paid_delta' => 1,
            'price_per_slot_micro_snapshot' => 1, 'total_amount_micro_snapshot' => 1, 'currency_id_snapshot' => $currencyId,
            'ratio_snapshot' => '0.5000', 'plan_catalog_id_snapshot' => DB::table('workspace_plan_catalog')->where('tier', 'core')->value('id'),
            'plan_tier_snapshot' => 'core', 'requesting_customer_user_id' => $this->userIds[0], 'requesting_customer_email_snapshot' => 'owner@example.test',
            'provider_customer_id' => $providerCustomerId, 'payment_method_display_snapshot' => 'Card', 'local_idempotency_key' => 'slice1a-' . uniqid('', true),
            'state' => $state, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function grandfathered(int $businessId): int
    {
        return (int) DB::table('businesses')->where('id', $businessId)->value('grandfathered_location_slots');
    }

    private function grandfatheredMap(array $fixture): array
    {
        return DB::table('businesses')->whereIn('id', $fixture['all_business_ids'])->orderBy('id')->pluck('grandfathered_location_slots', 'id')->map(fn ($v) => (int) $v)->all();
    }
}
