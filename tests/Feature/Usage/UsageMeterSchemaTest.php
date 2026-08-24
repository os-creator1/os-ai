<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Amendment 1 §B, Slice 1 EXPAND — usage_meters full schema
 * (Business/currency FKs, restrictOnDelete, same-meter active-rate
 * composite FK) and UsageMeterRepository's create()/update() authority
 * (validation, immutable-field enforcement). No real UsageMeter row is
 * ever seeded — every fixture here is created and torn down inside this
 * test's own transaction.
 */
class UsageMeterSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function usdCurrencyId(): int
    {
        return Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
    }

    /**
     * A genuine, disposable actor User — usage_meters.updated_by_user_id
     * has no database FK (the merged contract explicitly forbids adding
     * one), so UsageMeterRepository::create()/update() enforce actor
     * existence at the application layer instead. Every successful
     * repository call in this file uses a real User id, never an
     * unexplained literal.
     */
    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    private function insertMeter(array $overrides = []): array
    {
        $meterKey = $overrides['meter_key'] ?? ('crm.meter.' . uniqid());
        $now = now();

        $attributes = array_merge([
            'meter_key' => $meterKey,
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $this->usdCurrencyId(),
            'is_metered' => false,
            'active_rate_id' => null,
            'description' => 'Slice 1 schema test fixture meter.',
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides);

        DB::table('usage_meters')->insert($attributes);

        return $attributes;
    }

    private function insertRateForMeter(string $meterKey, int $currencyId): int
    {
        return DB::table('business_usage_rates')->insertGetId([
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
    }

    public function test_usage_meters_table_is_empty_by_default(): void
    {
        $this->assertSame(0, DB::table('usage_meters')->count());
    }

    public function test_meter_key_is_unique(): void
    {
        $this->insertMeter(['meter_key' => 'crm.unique.meter']);

        $this->expectException(QueryException::class);
        $this->insertMeter(['meter_key' => 'crm.unique.meter']);
    }

    public function test_business_id_restricts_deletion_while_meter_scoped_to_it(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->insertMeter(['business_id' => $business->id]);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }

    public function test_business_id_null_is_accepted_as_global_meter(): void
    {
        $meter = $this->insertMeter(['business_id' => null]);

        $this->assertDatabaseHas('usage_meters', ['meter_key' => $meter['meter_key'], 'business_id' => null]);
    }

    public function test_currency_id_restricts_deletion_while_meter_references_it(): void
    {
        $currencyId = $this->usdCurrencyId();
        $this->insertMeter(['currency_id' => $currencyId]);

        $this->expectException(QueryException::class);
        DB::table('currencies')->where('id', $currencyId)->delete();
    }

    public function test_active_rate_composite_fk_rejects_rate_belonging_to_a_different_meter(): void
    {
        $currencyId = $this->usdCurrencyId();
        $this->insertMeter(['meter_key' => 'crm.meter.a', 'currency_id' => $currencyId]);
        $this->insertMeter(['meter_key' => 'crm.meter.b', 'currency_id' => $currencyId]);
        $rateOfB = $this->insertRateForMeter('crm.meter.b', $currencyId);

        $this->expectException(QueryException::class);
        DB::table('usage_meters')->where('meter_key', 'crm.meter.a')->update(['active_rate_id' => $rateOfB]);
    }

    public function test_active_rate_composite_fk_accepts_rate_belonging_to_the_same_meter(): void
    {
        $currencyId = $this->usdCurrencyId();
        $meter = $this->insertMeter(['currency_id' => $currencyId]);
        $rateId = $this->insertRateForMeter($meter['meter_key'], $currencyId);

        DB::table('usage_meters')->where('meter_key', $meter['meter_key'])->update(['active_rate_id' => $rateId]);

        $this->assertDatabaseHas('usage_meters', ['meter_key' => $meter['meter_key'], 'active_rate_id' => $rateId]);
    }

    public function test_repository_create_rejects_invalid_platform_feature(): void
    {
        $repository = app(UsageMeterRepository::class);

        $this->expectException(InvalidArgumentException::class);
        $repository->create([
            'meter_key' => 'invalid.feature.meter',
            'feature_key' => 'not_a_real_feature',
            'business_id' => null,
            'currency_id' => $this->usdCurrencyId(),
            'description' => 'Should be rejected.',
            'updated_by_user_id' => 1,
        ]);
    }

    public function test_repository_create_rejects_empty_description(): void
    {
        $repository = app(UsageMeterRepository::class);

        $this->expectException(InvalidArgumentException::class);
        $repository->create([
            'meter_key' => 'empty.description.meter',
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $this->usdCurrencyId(),
            'description' => '',
            'updated_by_user_id' => 1,
        ]);
    }

    public function test_repository_create_rejects_missing_actor(): void
    {
        $repository = app(UsageMeterRepository::class);

        $this->expectException(InvalidArgumentException::class);
        $repository->create([
            'meter_key' => 'missing.actor.meter',
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $this->usdCurrencyId(),
            'description' => 'Valid description.',
            'updated_by_user_id' => null,
        ]);
    }

    /**
     * A positive integer alone is not a genuine actor — usage_meters has
     * no FK on updated_by_user_id, so the repository itself must reject
     * an ID with no corresponding User row.
     */
    public function test_repository_create_rejects_nonexistent_actor(): void
    {
        $repository = app(UsageMeterRepository::class);
        $this->assertNull(User::query()->find(999999), 'Test assumption violated: user 999999 must not exist.');

        $this->expectException(InvalidArgumentException::class);
        $repository->create([
            'meter_key' => 'nonexistent.actor.meter',
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $this->usdCurrencyId(),
            'description' => 'Valid description.',
            'updated_by_user_id' => 999999,
        ]);
    }

    public function test_repository_create_succeeds_with_valid_attributes(): void
    {
        $repository = app(UsageMeterRepository::class);

        $meter = $repository->create([
            'meter_key' => 'valid.meter.' . uniqid(),
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $this->usdCurrencyId(),
            'description' => 'Valid description.',
            'updated_by_user_id' => $this->createActorUserId(),
        ]);

        $this->assertDatabaseHas('usage_meters', ['id' => $meter->id]);
    }

    public function test_repository_update_cannot_mutate_immutable_fields(): void
    {
        $repository = app(UsageMeterRepository::class);
        $currencyId = $this->usdCurrencyId();
        $otherCurrencyId = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€', 'status' => true])->id;

        $meter = $repository->create([
            'meter_key' => 'immutable.meter.' . uniqid(),
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Valid description.',
            'updated_by_user_id' => $this->createActorUserId(),
        ]);

        $originalMeterKey = $meter->meter_key;
        $originalFeatureKey = $meter->feature_key;
        $originalBusinessId = $meter->business_id;
        $originalCurrencyId = $meter->currency_id;

        $updated = $repository->update($meter, [
            'meter_key' => 'attempted.override',
            'feature_key' => 'conversations',
            'business_id' => 999999,
            'currency_id' => $otherCurrencyId,
            'description' => 'Updated description.',
        ]);

        $this->assertSame($originalMeterKey, $updated->meter_key);
        $this->assertSame($originalFeatureKey, $updated->feature_key);
        $this->assertSame($originalBusinessId, $updated->business_id);
        $this->assertSame($originalCurrencyId, $updated->currency_id);
        $this->assertSame('Updated description.', $updated->description);
    }

    public function test_repository_update_can_mutate_authorized_fields(): void
    {
        $repository = app(UsageMeterRepository::class);
        $currencyId = $this->usdCurrencyId();
        $creatingActorId = $this->createActorUserId();
        $rotatingActorId = $this->createActorUserId();

        $meter = $repository->create([
            'meter_key' => 'mutable.meter.' . uniqid(),
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Original description.',
            'updated_by_user_id' => $creatingActorId,
        ]);
        $rateId = $this->insertRateForMeter($meter->meter_key, $currencyId);

        $updated = $repository->update($meter, [
            'active_rate_id' => $rateId,
            'is_metered' => true,
            'description' => 'Rotated rate.',
            'updated_by_user_id' => $rotatingActorId,
        ]);

        $this->assertSame($rateId, $updated->active_rate_id);
        $this->assertTrue($updated->is_metered);
        $this->assertSame('Rotated rate.', $updated->description);
        $this->assertSame($rotatingActorId, $updated->updated_by_user_id);
        $this->assertNotSame($creatingActorId, $rotatingActorId);
    }

    /**
     * RFC-005 Amendment 1 Slice 2 CUTOVER §5.2 — the new row-locking
     * finder added to this repository for setActiveRate()/
     * activateMetering(). Genuine row-lock contention is separately
     * proven by UsageWalletManagerSetActiveRateConcurrencyTest.php's
     * OS-process technique; this is the plain functional-resolution
     * check.
     */
    public function test_find_for_update_by_meter_key_returns_the_matching_meter(): void
    {
        $repository = app(UsageMeterRepository::class);
        $meter = $repository->create([
            'meter_key' => 'find.for.update.' . uniqid(),
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $this->usdCurrencyId(),
            'description' => 'Valid description.',
            'updated_by_user_id' => $this->createActorUserId(),
        ]);

        $found = DB::transaction(fn () => $repository->findForUpdateByMeterKey($meter->meter_key));

        $this->assertNotNull($found);
        $this->assertSame($meter->id, $found->id);
    }

    public function test_find_for_update_by_meter_key_returns_null_for_unknown_key(): void
    {
        $repository = app(UsageMeterRepository::class);

        $found = DB::transaction(fn () => $repository->findForUpdateByMeterKey('does.not.exist.' . uniqid()));

        $this->assertNull($found);
    }

    /**
     * RFC-005 Amendment 1 §O — every meter_key column across all six
     * affected tables must resolve to identical VARCHAR(128) with
     * compatible collation, at Slice 1's own nullability state.
     */
    public function test_all_six_meter_key_columns_are_varchar_128_with_compatible_collation(): void
    {
        $tables = [
            'usage_meters',
            'usage_meter_transitions',
            'business_usage_rates',
            'business_usage_rate_activations',
            'business_usage_reservations',
            'business_usage_ledger_entries',
        ];

        $rawRows = DB::table('information_schema.columns')
            ->select('table_name', 'character_maximum_length', 'collation_name', 'is_nullable')
            ->where('table_schema', DB::getDatabaseName())
            ->where('column_name', 'meter_key')
            ->whereIn('table_name', $tables)
            ->get();

        // information_schema metadata property casing is not portable
        // across MySQL/PDO configurations — some environments return
        // TABLE_NAME/COLLATION_NAME/etc. in uppercase regardless of how
        // the query itself is written. Normalize every row to lowercase
        // keys, and the table_name value to lowercase, before asserting.
        $rows = $rawRows
            ->map(fn ($row) => array_change_key_case((array) $row, CASE_LOWER))
            ->keyBy(fn (array $row) => strtolower((string) $row['table_name']));

        $this->assertCount(6, $rows, 'Expected all six tables to have a meter_key column.');

        $expectedCollation = $rows[$tables[0]]['collation_name'];

        foreach ($tables as $table) {
            $this->assertTrue($rows->has($table), "Missing meter_key column on {$table}.");
            $this->assertEquals(128, (int) $rows[$table]['character_maximum_length'], "meter_key on {$table} is not VARCHAR(128).");
            $this->assertEquals($expectedCollation, $rows[$table]['collation_name'], "meter_key collation mismatch on {$table}.");
        }

        $this->assertSame('NO', $rows['usage_meters']['is_nullable']);
        $this->assertSame('NO', $rows['usage_meter_transitions']['is_nullable']);
        $this->assertSame('YES', $rows['business_usage_rates']['is_nullable']);
        $this->assertSame('YES', $rows['business_usage_rate_activations']['is_nullable']);
        $this->assertSame('YES', $rows['business_usage_reservations']['is_nullable']);
        $this->assertSame('YES', $rows['business_usage_ledger_entries']['is_nullable']);
    }
}
