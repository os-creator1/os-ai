<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\UsageMeterBackfillIncompleteException;
use App\Exceptions\Usage\UsageMeterRollbackVersionCollisionException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Amendment 1 Slice 3 CONTRACT §9, proofs 35 and 40 — the global
 * forward preflight (migration 2026_08_24_120001's up()) and the global
 * rollback Preflights A/B (migration 2026_08_24_120003's down()),
 * exercised by loading and invoking the actual migration files directly
 * (the same mechanism Laravel's migrator uses for anonymous-class
 * migrations), mirroring WorkspaceEntitlementBackfillMigrationTest's own
 * established pattern.
 *
 * Deliberately does NOT use RefreshDatabase: every migration under test
 * performs genuine DDL (ALTER TABLE), which causes an implicit MySQL
 * commit that a RefreshDatabase transaction cannot roll back. Instead,
 * every test explicitly transitions the schema to the state it needs and
 * explicitly restores the final Slice 3 schema before finishing, and
 * tearDown() restores it again unconditionally as a backstop, so no other
 * test file in the suite (every one of which assumes the final Slice 3
 * schema is already in place) is ever left looking at a mid-migration
 * schema.
 */
class UsageMeterBackfillPreflightTest extends TestCase
{
    use CreatesBusinessTestData;

    private array $createdUserIds = [];

    private array $createdBusinessIds = [];

    private array $createdWorkspaceIds = [];

    private ?int $createdCurrencyId = null;

    protected function tearDown(): void
    {
        // Fixture-row cleanup is best-effort and must never be allowed to
        // prevent schema restoration below (Correction Round 1): if any
        // individual delete here throws for a reason a given test didn't
        // anticipate, restoreFinalSliceThreeSchema() still runs via
        // finally. Leaving even one of the three tables outside its
        // final Slice 3 shape would silently corrupt every other test
        // file in the suite — none of them re-run migrations; they all
        // simply trust whatever schema this process already migrated.
        try {
            if ($this->createdBusinessIds !== []) {
                DB::table('business_usage_ledger_entries')->whereIn('business_id', $this->createdBusinessIds)->delete();
                DB::table('business_usage_reservations')->whereIn('business_id', $this->createdBusinessIds)->delete();
                DB::table('business_usage_wallets')->whereIn('business_id', $this->createdBusinessIds)->delete();
                DB::table('businesses')->whereIn('id', $this->createdBusinessIds)->delete();
            }

            if ($this->createdWorkspaceIds !== []) {
                DB::table('workspace_entitlement_transitions')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
                DB::table('workspace_plan_assignments')->whereIn('workspace_id', $this->createdWorkspaceIds)->delete();
                DB::table('workspaces')->whereIn('id', $this->createdWorkspaceIds)->delete();
            }

            if ($this->createdCurrencyId !== null) {
                $rateIds = DB::table('business_usage_rates')->where('currency_id', $this->createdCurrencyId)->pluck('id');
                $meterKeys = DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->pluck('meter_key');
                DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->update(['active_rate_id' => null]);
                DB::table('usage_meter_transitions')->whereIn('meter_key', $meterKeys)->delete();
                DB::table('business_usage_rate_activations')->whereIn('rate_id', $rateIds)->delete();
                DB::table('business_usage_rates')->where('currency_id', $this->createdCurrencyId)->delete();
                DB::table('usage_meters')->where('currency_id', $this->createdCurrencyId)->delete();
                DB::table('currencies')->where('id', $this->createdCurrencyId)->delete();
            }

            if ($this->createdUserIds !== []) {
                DB::table('users')->whereIn('id', $this->createdUserIds)->delete();
            }
        } finally {
            $this->restoreFinalSliceThreeSchema();
        }

        parent::tearDown();
    }

    private function migration1(): object
    {
        return require database_path('migrations/2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php');
    }

    private function migration2(): object
    {
        return require database_path('migrations/2026_08_24_120002_tighten_and_contract_business_usage_rate_activations_table.php');
    }

    private function migration3(): object
    {
        return require database_path('migrations/2026_08_24_120003_tighten_meter_key_on_business_usage_reservations_table.php');
    }

    /**
     * Slice 3's own final shape is the schema every other test file in
     * this suite assumes. Correction Round 1: this used to be a single
     * hasColumn() check gating all three migrations together — if any
     * earlier test left the schema in a genuinely MIXED state (e.g.
     * business_usage_rates already tightened but
     * business_usage_rate_activations or business_usage_reservations
     * still in Slice 2 shape, which is exactly what happens if a test
     * assertion fails between two migration calls and aborts the test
     * body before its own remaining restoration steps run), that single
     * check could see business_usage_rates already final and skip
     * everything else, permanently stranding the other two tables in a
     * broken intermediate shape for the rest of the suite. Each table is
     * now checked and restored independently, and any leftover
     * NULL-meter_key test debris (never real identity — this test
     * class's entire purpose is seeding exactly that debris, never
     * production data) is swept up first so a prior test's own cleanup
     * bug can never cascade into every later test's preflight failing
     * too.
     */
    private function restoreFinalSliceThreeSchema(): void
    {
        $ratesNeedsMigration = DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key');
        $activationsNeedsMigration = DB::getSchemaBuilder()->hasColumn('business_usage_rate_activations', 'feature_key');
        $reservationsNeedsMigration = ($this->reservationsMeterKeyColumn()?->Null ?? 'NO') === 'YES';

        if (! $ratesNeedsMigration && ! $activationsNeedsMigration && ! $reservationsNeedsMigration) {
            return;
        }

        // Debris-only sweep (never real identity): a NULL-meter_key row
        // left behind by an earlier test's own incomplete cleanup would
        // otherwise make either the global forward preflight (migration
        // 1) or a plain nullable(false)->change() call (migrations 2/3,
        // which have no preflight of their own) fail with the same raw
        // SQL error, on whichever of the three tables still needs its
        // own migration. Dependents of any about-to-be-deleted rate are
        // removed first (rate_id is RESTRICT on both
        // business_usage_rate_activations and business_usage_reservations),
        // regardless of the dependent row's own meter_key value.
        if ($ratesNeedsMigration) {
            $orphanRateIds = DB::table('business_usage_rates')->whereNull('meter_key')->pluck('id');
            if ($orphanRateIds->isNotEmpty()) {
                DB::table('usage_meters')->whereIn('active_rate_id', $orphanRateIds)->update(['active_rate_id' => null]);
                DB::table('business_usage_rate_activations')->whereIn('rate_id', $orphanRateIds)->delete();
                DB::table('business_usage_reservations')->whereIn('rate_id', $orphanRateIds)->delete();
                DB::table('business_usage_rates')->whereNull('meter_key')->delete();
            }
        }
        if ($activationsNeedsMigration) {
            DB::table('business_usage_rate_activations')->whereNull('meter_key')->delete();
        }
        if ($reservationsNeedsMigration) {
            DB::table('business_usage_reservations')->whereNull('meter_key')->delete();
        }

        if ($ratesNeedsMigration) {
            $this->migration1()->up();
        }
        if ($activationsNeedsMigration) {
            $this->migration2()->up();
        }
        if ($reservationsNeedsMigration) {
            $this->migration3()->up();
        }
    }

    private function reservationsMeterKeyColumn(): ?object
    {
        return collect(DB::select(
            "SHOW COLUMNS FROM business_usage_reservations WHERE Field = 'meter_key'"
        ))->first();
    }

    /**
     * Laravel runs a batch's down() methods in the exact reverse of its
     * up() order (RFC-005 Amendment 1 Slice 3 CONTRACT §6) — reservations,
     * then activations, then rates.
     */
    private function revertToSliceTwoSchema(): void
    {
        $this->migration3()->down();
        $this->migration2()->down();
        $this->migration1()->down();
    }

    private function usdCurrencyId(): int
    {
        if ($this->createdCurrencyId === null) {
            $this->createdCurrencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        }

        return $this->createdCurrencyId;
    }

    private function createActorUserId(): int
    {
        $id = DB::table('users')->insertGetId([
            'uid' => (string) Str::uuid(), 'first_name' => 'Preflight', 'last_name' => 'Actor',
            'email' => 'preflight-actor'.uniqid().'@example.test', 'status' => true, 'is_admin' => true,
            'is_customer' => false, 'active_portal' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->createdUserIds[] = $id;

        return $id;
    }

    private function createMeter(string $meterKey, string $featureKey, int $currencyId, int $actorId): void
    {
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey,
            'feature_key' => $featureKey,
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Slice 3 preflight fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);
    }

    // ------------------------------------------------------------------
    // Proof 35 — forward global preflight.
    // ------------------------------------------------------------------

    public function test_forward_preflight_blocks_all_ddl_when_rates_has_a_null_meter_key(): void
    {
        $this->revertToSliceTwoSchema();
        $currencyId = $this->usdCurrencyId();

        DB::table('business_usage_rates')->insert([
            'feature_key' => 'crm',
            'version' => 1,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up',
            'currency_id' => $currencyId,
            'created_by_user_id' => 1,
            'created_at' => now(),
        ]);

        try {
            $this->migration1()->up();
            $this->fail('Expected UsageMeterBackfillIncompleteException.');
        } catch (UsageMeterBackfillIncompleteException $e) {
            $this->assertSame('business_usage_rates', $e->table);
            $this->assertSame(1, $e->remainingCount);
        }

        // Zero DDL anywhere — every table is still in Slice 2 shape.
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('business_usage_rate_activations', 'feature_key'));

        DB::table('business_usage_rates')->where('currency_id', $currencyId)->delete();
    }

    public function test_forward_preflight_blocks_all_ddl_when_activations_has_a_null_meter_key(): void
    {
        $this->revertToSliceTwoSchema();
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.act-null.'.uniqid();
        $now = now();

        // usage_meters is never touched by any Slice 3 migration, so it
        // is safe to populate directly regardless of rates'/activations'
        // current schema shape.
        DB::table('usage_meters')->insert([
            'meter_key' => $meterKey, 'feature_key' => 'crm', 'business_id' => null,
            'currency_id' => $currencyId, 'is_metered' => false, 'active_rate_id' => null,
            'description' => 'Preflight fixture meter.', 'updated_by_user_id' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // The rate itself has a populated meter_key, so it passes rates'
        // own check — isolating this test to activations' NULL row.
        $rateId = DB::table('business_usage_rates')->insertGetId([
            'feature_key' => 'crm',
            'meter_key' => $meterKey,
            'version' => 1,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up',
            'currency_id' => $currencyId,
            'created_by_user_id' => 1,
            'created_at' => now(),
        ]);

        DB::table('business_usage_rate_activations')->insert([
            'feature_key' => 'crm',
            'rate_id' => $rateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Preflight fixture.',
            'created_at' => now(),
        ]);

        try {
            $this->migration1()->up();
            $this->fail('Expected UsageMeterBackfillIncompleteException.');
        } catch (UsageMeterBackfillIncompleteException $e) {
            $this->assertSame('business_usage_rate_activations', $e->table);
            $this->assertSame(1, $e->remainingCount);
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('business_usage_rate_activations', 'feature_key'));

        DB::table('business_usage_rate_activations')->where('rate_id', $rateId)->delete();
        DB::table('business_usage_rates')->where('id', $rateId)->delete();
    }

    public function test_forward_preflight_blocks_all_ddl_when_reservations_has_a_null_meter_key(): void
    {
        $this->revertToSliceTwoSchema();
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.res-null.'.uniqid();

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->createdBusinessIds[] = $business->id;
        $this->createdWorkspaceIds[] = $business->workspace_id;

        $now = now();
        $walletId = DB::table('business_usage_wallets')->insertGetId([
            'business_id' => $business->id, 'currency_id' => $currencyId,
            'available_balance_micro' => 0, 'reserved_balance_micro' => 0, 'debt_balance_micro' => 0,
            'spend_period_key' => '2026-08', 'spend_period_start_utc' => $now, 'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => '2026-08', 'recharge_period_start_utc' => $now, 'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        // usage_meters is never touched by any Slice 3 migration; the
        // rate itself carries a populated meter_key so it (and
        // activations, which we don't insert here at all) pass their own
        // checks, isolating this test to reservations' NULL row.
        DB::table('usage_meters')->insert([
            'meter_key' => $meterKey, 'feature_key' => 'crm', 'business_id' => null,
            'currency_id' => $currencyId, 'is_metered' => false, 'active_rate_id' => null,
            'description' => 'Preflight fixture meter.', 'updated_by_user_id' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $rateId = DB::table('business_usage_rates')->insertGetId([
            'feature_key' => 'crm', 'meter_key' => $meterKey, 'version' => 1, 'retail_rate_micro' => 1000, 'provider_cost_micro' => 500,
            'unit_label' => 'per message', 'rounding_rule' => 'round_half_up', 'currency_id' => $currencyId,
            'created_by_user_id' => 1, 'created_at' => $now,
        ]);

        DB::table('business_usage_reservations')->insert([
            'business_id' => $business->id, 'wallet_id' => $walletId, 'feature_key' => 'crm',
            'period_key' => '2026-08', 'status' => 'pending', 'reserved_amount_micro' => 1000,
            'rate_id' => $rateId, 'rate_version' => 1, 'retail_rate_micro' => 1000, 'provider_cost_micro' => 500,
            'rounding_rule' => 'round_half_up', 'idempotency_key' => 'idem-'.uniqid(), 'correlation_key' => 'corr-'.uniqid(),
            'reserved_at' => $now, 'expires_at' => $now->clone()->addMinutes(30),
        ]);

        try {
            $this->migration1()->up();
            $this->fail('Expected UsageMeterBackfillIncompleteException.');
        } catch (UsageMeterBackfillIncompleteException $e) {
            $this->assertSame('business_usage_reservations', $e->table);
            $this->assertSame(1, $e->remainingCount);
        }

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('business_usage_rate_activations', 'feature_key'));
    }

    // ------------------------------------------------------------------
    // Proof 40 — rollback global Preflights A and B.
    // ------------------------------------------------------------------

    /**
     * Failure mode A — an unresolved meter_key on business_usage_rates.
     * The composite (meter_key, currency_id) -> usage_meters FK forbids
     * inserting this directly, so a genuinely valid row is created first
     * and then orphaned with foreign key checks briefly disabled — the
     * same established technique used by Slice 2's own defensive-only
     * rate-integrity test, for constructing an otherwise database-
     * unreachable state.
     */
    public function test_rollback_preflight_a_blocks_all_ddl_when_a_rate_meter_key_is_unresolved(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.orphan.'.uniqid();
        $this->createMeter($meterKey, 'crm', $currencyId, $actorId);
        $rate = app(UsageWalletManager::class)->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_usage_rates')->where('id', $rate->id)->update(['meter_key' => 'nonexistent.meter.'.uniqid()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        try {
            $this->migration3()->down();
            $this->fail('Expected UsageMeterBackfillIncompleteException.');
        } catch (UsageMeterBackfillIncompleteException $e) {
            $this->assertSame('business_usage_rates', $e->table);
            $this->assertSame(1, $e->remainingCount);
        }

        // Zero rollback DDL anywhere — still final Slice 3 shape.
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('business_usage_rate_activations', 'feature_key'));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_usage_rates')->where('id', $rate->id)->update(['meter_key' => $meterKey]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Failure mode A — an unresolved meter_key on
     * business_usage_rate_activations, same orphaning technique.
     */
    public function test_rollback_preflight_a_blocks_all_ddl_when_an_activation_meter_key_is_unresolved(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.orphan-act.'.uniqid();
        $this->createMeter($meterKey, 'crm', $currencyId, $actorId);
        app(UsageWalletManager::class)->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        $activationId = DB::table('business_usage_rate_activations')->where('meter_key', $meterKey)->value('id');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_usage_rate_activations')->where('id', $activationId)->update(['meter_key' => 'nonexistent.meter.'.uniqid()]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        try {
            $this->migration3()->down();
            $this->fail('Expected UsageMeterBackfillIncompleteException.');
        } catch (UsageMeterBackfillIncompleteException $e) {
            $this->assertSame('business_usage_rate_activations', $e->table);
            $this->assertSame(1, $e->remainingCount);
        }

        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('business_usage_rate_activations', 'feature_key'));

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('business_usage_rate_activations')->where('id', $activationId)->update(['meter_key' => $meterKey]);
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    /**
     * Failure mode B — two sibling meters sharing one feature_key have
     * each legitimately received a rate at the same version number
     * (the meter-local allocator's own intended post-Slice-3 behavior,
     * naturally reached here since each meter independently starts its
     * own version sequence at 1). Recreating
     * business_usage_rates_feature_key_version_unique over that data is
     * impossible without a collision.
     */
    public function test_rollback_preflight_b_blocks_all_ddl_on_sibling_meter_version_collision(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createActorUserId();
        $featureKey = 'crm';
        $meterKeyA = 'crm.collide.a.'.uniqid();
        $meterKeyB = 'crm.collide.b.'.uniqid();
        $this->createMeter($meterKeyA, $featureKey, $currencyId, $actorId);
        $this->createMeter($meterKeyB, $featureKey, $currencyId, $actorId);

        $manager = app(UsageWalletManager::class);
        $rateA = $manager->setActiveRate($meterKeyA, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        $rateB = $manager->setActiveRate($meterKeyB, '2000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');

        // Both independently allocated version 1 — legal only under the
        // meter-local allocator, and exactly the state Preflight B exists
        // to detect.
        $this->assertSame(1, $rateA->version);
        $this->assertSame(1, $rateB->version);

        try {
            $this->migration3()->down();
            $this->fail('Expected UsageMeterRollbackVersionCollisionException.');
        } catch (UsageMeterRollbackVersionCollisionException $e) {
            $this->assertSame($featureKey, $e->featureKey);
            $this->assertSame(1, $e->version);
            $this->assertSame(2, $e->collidingRowCount);
        }

        // Zero rollback DDL anywhere — no feature_key re-add, no legacy
        // index recreation, nothing tightened or loosened.
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key'));
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('business_usage_rate_activations', 'feature_key'));
        $reservationColumn = collect(DB::select(
            "SHOW COLUMNS FROM business_usage_reservations WHERE Field = 'meter_key'"
        ))->first();
        $this->assertSame('NO', $reservationColumn->Null);
    }

    /**
     * Success path — no unresolved meter, no version collision: rollback
     * restores Slice 2's schema exactly, then is restored back to final
     * Slice 3 shape for the next test.
     */
    public function test_rollback_restores_slice_2_schema_exactly_when_both_preflights_pass(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createActorUserId();
        $meterKey = 'crm.clean.'.uniqid();
        $this->createMeter($meterKey, 'crm', $currencyId, $actorId);
        $rate = app(UsageWalletManager::class)->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        $activation = DB::table('business_usage_rate_activations')->where('meter_key', $meterKey)->first();

        $this->migration3()->down();
        $this->migration2()->down();
        $this->migration1()->down();

        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key'));
        $this->assertTrue(DB::getSchemaBuilder()->hasColumn('business_usage_rate_activations', 'feature_key'));

        $rateColumn = collect(DB::select("SHOW COLUMNS FROM business_usage_rates WHERE Field = 'feature_key'"))->first();
        $this->assertSame('NO', $rateColumn->Null);
        $activationColumn = collect(DB::select("SHOW COLUMNS FROM business_usage_rate_activations WHERE Field = 'feature_key'"))->first();
        $this->assertSame('NO', $activationColumn->Null);

        $indexes = collect(DB::select('SHOW INDEX FROM business_usage_rates'))->pluck('Key_name')->unique();
        $this->assertContains('business_usage_rates_feature_key_version_unique', $indexes);
        $activationIndexes = collect(DB::select('SHOW INDEX FROM business_usage_rate_activations'))->pluck('Key_name')->unique();
        $this->assertContains('business_usage_rate_activations_feature_key_index', $activationIndexes);

        $this->assertDatabaseHas('business_usage_rates', ['id' => $rate->id, 'feature_key' => 'crm', 'meter_key' => $meterKey]);
        $this->assertDatabaseHas('business_usage_rate_activations', ['id' => $activation->id, 'feature_key' => 'crm', 'meter_key' => $meterKey]);

        // Explicit restore back to final shape (tearDown() would also
        // catch this, but the test proves the round trip works cleanly).
        $this->migration1()->up();
        $this->migration2()->up();
        $this->migration3()->up();

        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('business_usage_rates', 'feature_key'));
        $this->assertDatabaseHas('business_usage_rates', ['id' => $rate->id, 'meter_key' => $meterKey]);
    }
}
