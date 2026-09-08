<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessLocationLifecycleState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Business\Concerns\CreatesLocationCapacityFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A — T-LOC-7, T-LOC-8 and the §23.3 migration
 * semantics.
 *
 * These assert the SHAPE and the DATA the additive migration produced, plus
 * the two fail-closed rollback guards. They deliberately do not shell out
 * to `migrate:rollback` inside a RefreshDatabase transaction — the
 * forward/rollback/forward cycle is executed separately as an operational
 * check and reported with its output. What is asserted here is every
 * property that can be proven deterministically in-process.
 */
class BusinessLocationMigrationTest extends TestCase
{
    use CreatesLocationCapacityFixtures;
    use RefreshDatabase;

    /** The additive columns exist with the contracted names. */
    public function test_the_additive_capacity_columns_exist(): void
    {
        foreach ([
            'location_slot_included',
            'location_slot_max',
            'unlimited_location_slots',
            'additional_location_slot_price_ratio',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('workspace_plan_catalog', $column),
                "workspace_plan_catalog.{$column} must exist."
            );
        }

        foreach (['additional_location_slots', 'grandfathered_location_slots'] as $column) {
            $this->assertTrue(Schema::hasColumn('businesses', $column), "businesses.{$column} must exist.");
        }

        foreach (['lifecycle_state', 'archived_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('business_locations', $column), "business_locations.{$column} must exist.");
        }

        $this->assertTrue(Schema::hasColumn('workspace_entitlement_transitions', 'payload'));
    }

    /** Laravel SoftDeletes was deliberately NOT used (contract §23.2 step 2a). */
    public function test_business_locations_does_not_use_soft_deletes(): void
    {
        $this->assertFalse(
            Schema::hasColumn('business_locations', 'deleted_at'),
            'Archiving must be a lifecycle state, not a soft delete: a soft-deleted row would vanish from '
            . 'default queries and from the business_google_locations relationship.'
        );
    }

    /** The seeded capacities match the authorized product model exactly. */
    public function test_the_seeded_capacities_match_the_authorized_model(): void
    {
        $rows = DB::table('workspace_plan_catalog')->get()->keyBy('tier');

        foreach (['core', 'growth'] as $tier) {
            $this->assertSame(1, (int) $rows[$tier]->business_slot_included, "{$tier} holds exactly one Business.");
            $this->assertSame(1, (int) $rows[$tier]->business_slot_max);
            $this->assertSame(0, (int) $rows[$tier]->unlimited_business_slots);

            $this->assertSame(3, (int) $rows[$tier]->location_slot_included);
            $this->assertSame(5, (int) $rows[$tier]->location_slot_max);
            $this->assertSame(0, (int) $rows[$tier]->unlimited_location_slots);
            $this->assertSame('0.5000', (string) $rows[$tier]->additional_location_slot_price_ratio);
        }

        $this->assertSame(1, (int) $rows['agency']->unlimited_business_slots);
        $this->assertSame(1, (int) $rows['agency']->unlimited_location_slots);
        $this->assertNull($rows['agency']->location_slot_max);
        $this->assertNull($rows['agency']->additional_location_slot_price_ratio);
    }

    /** Core/Growth retail prices are NOT invented by this slice. */
    public function test_core_and_growth_prices_remain_undecided(): void
    {
        foreach (['core', 'growth'] as $tier) {
            $row = DB::table('workspace_plan_catalog')->where('tier', $tier)->first();

            $this->assertNull($row->price, 'Slice 1A must not invent a Core/Growth retail price.');
            $this->assertNull($row->currency_id);
        }
    }

    /** T-LOC-7 — every pre-existing location defaults to active. */
    public function test_every_pre_existing_location_defaults_to_active(): void
    {
        [, $business] = $this->locationTenant();
        $location = $this->seedLocation($business, 'Pre-existing', true);

        $this->assertSame(BusinessLocationLifecycleState::Active, $location->lifecycle_state);
        $this->assertNull($location->archived_at);
    }

    /**
     * T-LOC-8 — the grandfathering backfill is idempotent and records the
     * exact per-Business counts in an immutable payload.
     *
     * The migration itself runs once; this exercises the backfill RULE the
     * migration applies, on data created after it, by invoking the same
     * computation and asserting no duplicate transition appears.
     */
    public function test_grandfathering_records_exact_per_business_counts_and_is_idempotent(): void
    {
        [, $business, $workspace] = $this->locationTenant();

        // A pre-existing over-capacity Business: 5 active on a 3-included tier.
        $this->seedActiveLocations($business, 5);

        $this->backfillGrandfathering();

        $business->refresh();
        $this->assertSame(2, (int) $business->grandfathered_location_slots, 'Excess above the included three is complimentary.');

        $transitions = DB::table('workspace_entitlement_transitions')
            ->where('workspace_id', $workspace->id)
            ->where('transition_type', 'location_capacity_grandfathered')
            ->get();

        $this->assertCount(1, $transitions);

        $payload = json_decode((string) $transitions->first()->payload, true);
        $this->assertSame(
            2,
            $payload['grandfathered_location_slots_by_business_id'][(string) $business->id]
                ?? $payload['grandfathered_location_slots_by_business_id'][$business->id]
                ?? null,
            'The immutable payload must name the affected Business and its exact count.'
        );

        // Idempotent: re-running writes no duplicate row and no new count.
        $this->backfillGrandfathering();

        $this->assertSame(2, (int) $business->refresh()->grandfathered_location_slots);
        $this->assertSame(
            1,
            (int) DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $workspace->id)
                ->where('transition_type', 'location_capacity_grandfathered')
                ->count()
        );
    }

    /** T-LOC-8 — the backfill charges nothing and archives nothing. */
    public function test_grandfathering_charges_nothing_and_archives_nothing(): void
    {
        [, $business] = $this->locationTenant();
        $this->seedActiveLocations($business, 5);

        $this->backfillGrandfathering();

        $this->assertSame(5, $this->activeLocationCount($business), 'No location may be archived by the backfill.');
        $this->assertDatabaseCount('business_usage_ledger_entries', 0);
        $this->assertDatabaseCount('business_usage_reservations', 0);
    }

    // -----------------------------------------------------------------
    // CORRECTION ROUND 3 — the rollback preflight fails closed and is
    // non-destructive.
    //
    // Every case below refuses BEFORE any mutation, so these run safely
    // inside RefreshDatabase: down() executes only SELECTs on the refusal
    // path and reaches no DDL. The pristine rollback-and-replay cycle,
    // which does execute DDL, lives in BusinessLocationRollbackTest.
    // -----------------------------------------------------------------

    /** Rollback refuses while any Business holds paid capacity. */
    public function test_rollback_refuses_while_a_paid_allocation_exists(): void
    {
        [, $business] = $this->locationTenant();
        $this->setAdditionalLocationSlots($business, 1);

        $this->assertRollbackRefusedWithoutChangingAnything('hold paid additional location slots');
    }

    /** Rollback refuses while any Business holds complimentary excess. */
    public function test_rollback_refuses_while_a_grandfathered_allocation_exists(): void
    {
        [, $business] = $this->locationTenant();
        $this->setGrandfatheredLocationSlots($business, 2);

        $this->assertRollbackRefusedWithoutChangingAnything('complimentary grandfathered location slots');
    }

    /** Rollback refuses while any location is archived. */
    public function test_rollback_refuses_while_an_archived_location_exists(): void
    {
        [, $business] = $this->locationTenant();
        $this->seedLocation($business, 'Closed', false, BusinessLocationLifecycleState::Archived);

        $this->assertRollbackRefusedWithoutChangingAnything('Archived physical locations exist');
    }

    /**
     * Rollback refuses rather than deleting immutable audit history — and
     * the rows are still there afterwards.
     */
    public function test_rollback_refuses_and_does_not_delete_audit_history(): void
    {
        [, $business] = $this->locationTenant();

        DB::table('workspace_entitlement_transitions')->insert([
            'workspace_id' => $business->workspace_id,
            'transition_type' => 'location_capacity_grandfathered',
            'actor_user_id' => null,
            'reason' => 'Audit evidence that must survive a refused rollback.',
            'payload' => json_encode(['grandfathered_location_slots_by_business_id' => [(int) $business->id => 2]]),
            'created_at' => now(),
        ]);

        $this->assertRollbackRefusedWithoutChangingAnything('immutable');

        $this->assertSame(
            1,
            (int) DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $business->workspace_id)
                ->where('transition_type', 'location_capacity_grandfathered')
                ->count(),
            'A refused rollback must never delete audit evidence.'
        );
    }

    /** Rollback refuses when an operator has edited the catalog. */
    public function test_rollback_refuses_when_an_operator_edited_the_catalog(): void
    {
        DB::table('workspace_plan_catalog')->where('tier', 'growth')->update(['location_slot_max' => 9]);

        $this->assertRollbackRefusedWithoutChangingAnything('no longer holds the physical-location capacity');

        $this->assertSame(
            9,
            (int) DB::table('workspace_plan_catalog')->where('tier', 'growth')->value('location_slot_max'),
            "A refused rollback must not touch the operator's edit."
        );
    }

    /** The Business-slot half of the catalog compare-and-swap still holds. */
    public function test_rollback_refuses_when_an_operator_edited_the_business_slot_values(): void
    {
        DB::table('workspace_plan_catalog')->where('tier', 'core')->update(['business_slot_max' => 4]);

        $this->assertRollbackRefusedWithoutChangingAnything('Business-slot values');

        $this->assertSame(
            4,
            (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('business_slot_max'),
            "A refused rollback must not overwrite the operator's edit."
        );
    }

    /**
     * Runs down(), requires it to refuse for the expected reason, and
     * proves the refusal changed absolutely nothing: every column this
     * migration added is still present and the catalog still holds the
     * corrected Business-slot values.
     */
    private function assertRollbackRefusedWithoutChangingAnything(string $expectedReason): void
    {
        $migration = require database_path('migrations/2026_09_10_120003_add_physical_location_capacity_and_lifecycle.php');

        try {
            $migration->down();
            $this->fail('down() must refuse rather than destroy meaningful state.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($expectedReason, $exception->getMessage());
            $this->assertStringContainsString('Nothing has been changed.', $exception->getMessage());
        }

        foreach ([
            'workspace_plan_catalog' => ['location_slot_included', 'location_slot_max', 'unlimited_location_slots', 'additional_location_slot_price_ratio'],
            'businesses' => ['additional_location_slots', 'grandfathered_location_slots'],
            'business_locations' => ['lifecycle_state', 'archived_at'],
            'workspace_entitlement_transitions' => ['payload'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertTrue(
                    Schema::hasColumn($table, $column),
                    "A refused rollback must not drop {$table}.{$column}."
                );
            }
        }
    }

    /**
     * Re-applies the migration's own backfill rule. Mirrors the private
     * backfillGrandfathering() in
     * 2026_09_10_120001_add_physical_location_capacity_and_lifecycle.php.
     */
    private function backfillGrandfathering(): void
    {
        $assignments = DB::table('workspace_plan_assignments')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_assignments.workspace_plan_catalog_id')
            ->get([
                'workspace_plan_assignments.workspace_id',
                'workspace_plan_catalog.location_slot_included',
                'workspace_plan_catalog.unlimited_location_slots',
            ])
            ->keyBy('workspace_id');

        $affectedByWorkspace = [];

        foreach (DB::table('businesses')->get(['id', 'workspace_id']) as $business) {
            $assignment = $assignments->get($business->workspace_id);

            if ($assignment === null || (bool) $assignment->unlimited_location_slots) {
                continue;
            }

            $active = (int) DB::table('business_locations')
                ->where('business_id', $business->id)
                ->where('lifecycle_state', BusinessLocationLifecycleState::Active->value)
                ->count();

            $excess = max(0, $active - (int) $assignment->location_slot_included);

            if ($excess === 0) {
                continue;
            }

            DB::table('businesses')->where('id', $business->id)->update(['grandfathered_location_slots' => $excess]);
            $affectedByWorkspace[$business->workspace_id][(int) $business->id] = $excess;
        }

        foreach ($affectedByWorkspace as $workspaceId => $counts) {
            $exists = DB::table('workspace_entitlement_transitions')
                ->where('workspace_id', $workspaceId)
                ->where('transition_type', 'location_capacity_grandfathered')
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('workspace_entitlement_transitions')->insert([
                'workspace_id' => $workspaceId,
                'transition_type' => 'location_capacity_grandfathered',
                'actor_user_id' => null,
                'reason' => 'Slice 1A physical-location capacity correction.',
                'payload' => json_encode(['grandfathered_location_slots_by_business_id' => $counts]),
                'created_at' => now(),
            ]);
        }
    }
}
