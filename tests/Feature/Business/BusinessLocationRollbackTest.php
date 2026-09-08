<?php

namespace Tests\Feature\Business;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Customer Experience Slice 1A, correction round 3 — the PRISTINE
 * rollback-and-replay cycle.
 *
 * This is the one rollback case that actually executes DDL, so it
 * deliberately does NOT use RefreshDatabase: MySQL DDL causes an implicit
 * commit, and running it inside RefreshDatabase's transaction would leave
 * the surrounding suite in an undefined state. The five fail-closed
 * refusal cases never reach DDL and live in BusinessLocationMigrationTest.
 *
 * The cycle asserted here is exactly the one the contract requires to stay
 * available: forward migration → safe pristine rollback → forward replay,
 * ending with the schema and the seeded values back as they started.
 *
 * setUp() establishes the pristine precondition on the disposable test
 * database before the cycle runs, so a row left behind by an earlier test
 * class cannot make this test report a false refusal.
 */
class BusinessLocationRollbackTest extends TestCase
{
    private const MIGRATION = 'migrations/2026_09_10_120003_add_physical_location_capacity_and_lifecycle.php';

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasColumn('businesses', 'additional_location_slots')) {
            $this->artisan('migrate', ['--force' => true])->run();
        }

        $this->makeRollbackPreconditionPristine();
    }

    protected function tearDown(): void
    {
        // Never leave the suite without the Slice 1A schema, whatever
        // happened above.
        if (! Schema::hasColumn('businesses', 'additional_location_slots')) {
            $this->migration()->up();
        }

        parent::tearDown();
    }

    /**
     * A pristine rollback reverses cleanly, and the forward replay puts
     * everything back.
     */
    public function test_a_pristine_rollback_reverses_and_replays_cleanly(): void
    {
        $migration = $this->migration();

        // ---- Forward state, as up() left it. ----
        $this->assertSlice1AColumnsExist(true);
        $this->assertSame(1, (int) DB::table('workspace_plan_catalog')->where('tier', 'core')->value('business_slot_included'));

        // ---- Rollback. ----
        $migration->down();

        $this->assertSlice1AColumnsExist(false);

        // The historical M1 Business-slot values are restored exactly.
        foreach (['core', 'growth'] as $tier) {
            $row = DB::table('workspace_plan_catalog')->where('tier', $tier)->first(['business_slot_included', 'business_slot_max']);

            $this->assertSame(3, (int) $row->business_slot_included, "{$tier} must be restored to the M1 included value.");
            $this->assertSame(5, (int) $row->business_slot_max, "{$tier} must be restored to the M1 max value.");
        }

        // ---- Forward replay. ----
        $this->migration()->up();

        $this->assertSlice1AColumnsExist(true);

        foreach (['core', 'growth'] as $tier) {
            $row = DB::table('workspace_plan_catalog')->where('tier', $tier)->first([
                'business_slot_included',
                'business_slot_max',
                'location_slot_included',
                'location_slot_max',
                'unlimited_location_slots',
                'additional_location_slot_price_ratio',
            ]);

            $this->assertSame(1, (int) $row->business_slot_included);
            $this->assertSame(1, (int) $row->business_slot_max);
            $this->assertSame(3, (int) $row->location_slot_included);
            $this->assertSame(5, (int) $row->location_slot_max);
            $this->assertSame(0, (int) $row->unlimited_location_slots);
            $this->assertSame('0.5000', (string) $row->additional_location_slot_price_ratio);
        }

        $agency = DB::table('workspace_plan_catalog')->where('tier', 'agency')->first([
            'location_slot_max',
            'unlimited_location_slots',
            'additional_location_slot_price_ratio',
        ]);

        $this->assertNull($agency->location_slot_max);
        $this->assertSame(1, (int) $agency->unlimited_location_slots);
        $this->assertNull($agency->additional_location_slot_price_ratio);

        // And the replay left no Core/Growth retail price behind.
        foreach (['core', 'growth'] as $tier) {
            $this->assertNull(
                DB::table('workspace_plan_catalog')->where('tier', $tier)->value('price'),
                'The replay must not invent a retail price.'
            );
        }
    }

    private function assertSlice1AColumnsExist(bool $expected): void
    {
        foreach ([
            'workspace_plan_catalog' => ['location_slot_included', 'location_slot_max', 'unlimited_location_slots', 'additional_location_slot_price_ratio'],
            'businesses' => ['additional_location_slots', 'grandfathered_location_slots'],
            'business_locations' => ['lifecycle_state', 'archived_at'],
            'workspace_entitlement_transitions' => ['payload'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $this->assertSame(
                    $expected,
                    Schema::hasColumn($table, $column),
                    ($expected ? 'Expected' : 'Did not expect') . " {$table}.{$column} to exist."
                );
            }
        }
    }

    /**
     * Establishes the pristine precondition on the disposable test
     * database. This is test support, not product behaviour: production
     * rollback never clears state — it refuses, which is exactly what
     * BusinessLocationMigrationTest proves.
     */
    private function makeRollbackPreconditionPristine(): void
    {
        DB::table('businesses')->update([
            'additional_location_slots' => 0,
            'grandfathered_location_slots' => 0,
        ]);

        DB::table('business_locations')->where('lifecycle_state', 'archived')->delete();

        DB::table('workspace_entitlement_transitions')
            ->whereIn('transition_type', ['location_capacity_grandfathered', 'additional_location_slots_changed'])
            ->delete();

        DB::table('workspace_entitlement_transitions')->whereNotNull('payload')->update(['payload' => null]);

        // The catalog exactly as up() seeded it.
        DB::table('workspace_plan_catalog')->whereIn('tier', ['core', 'growth'])->update([
            'business_slot_included' => 1,
            'business_slot_max' => 1,
            'location_slot_included' => 3,
            'location_slot_max' => 5,
            'unlimited_location_slots' => false,
            'additional_location_slot_price_ratio' => 0.5000,
        ]);

        DB::table('workspace_plan_catalog')->where('tier', 'agency')->update([
            'location_slot_included' => 3,
            'location_slot_max' => null,
            'unlimited_location_slots' => true,
            'additional_location_slot_price_ratio' => null,
        ]);
    }

    private function migration(): object
    {
        return require database_path(self::MIGRATION);
    }
}
