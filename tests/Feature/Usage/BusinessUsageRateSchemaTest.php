<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use App\Repositories\Contracts\BusinessUsageRateRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §6.2/§6.3 — business_usage_rates (fully immutable,
 * no updated_at) and business_usage_rate_activations schema/constraints,
 * updated for RFC-005 Amendment 1 Slice 3 CONTRACT's final schema
 * contraction: feature_key is dropped from both tables, meter_key is
 * NOT NULL on both, and the sole remaining uniqueness is
 * UNIQUE(meter_key, version) (proofs 36/38).
 */
class BusinessUsageRateSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function usdCurrencyId(): int
    {
        return Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
    }

    /**
     * RFC-005 Amendment 1 §B/§D — a disposable UsageMeter, never
     * seeded/real, for exercising the final meter-based rate identity.
     */
    private function insertMeter(int $currencyId, array $overrides = []): string
    {
        $meterKey = $overrides['meter_key'] ?? ('crm.meter.' . uniqid());
        $now = now();

        DB::table('usage_meters')->insert(array_merge([
            'meter_key' => $meterKey,
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'is_metered' => false,
            'active_rate_id' => null,
            'description' => 'Slice 3 schema test fixture meter.',
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $meterKey;
    }

    private function insertRate(string $meterKey, int $currencyId, array $overrides = []): int
    {
        return DB::table('business_usage_rates')->insertGetId(array_merge([
            'meter_key' => $meterKey,
            'version' => 1,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up',
            'currency_id' => $currencyId,
            'created_by_user_id' => 1,
            'created_at' => now(),
        ], $overrides));
    }

    public function test_rate_table_has_no_updated_at_column(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('business_usage_rates');
        $this->assertNotContains('updated_at', $columns);
        $this->assertContains('created_at', $columns);
    }

    /**
     * Proof 36 — after Slice 3, business_usage_rates no longer has a
     * feature_key column, and its legacy unique index is gone.
     */
    public function test_rate_feature_key_column_and_legacy_unique_index_are_gone(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('business_usage_rates');
        $this->assertNotContains('feature_key', $columns);

        $indexes = collect(DB::select('SHOW INDEX FROM business_usage_rates'))
            ->pluck('Key_name')
            ->unique();
        $this->assertNotContains('business_usage_rates_feature_key_version_unique', $indexes);
        $this->assertContains('business_usage_rates_meter_key_version_unique', $indexes);
    }

    /**
     * Proof 36 — same, for business_usage_rate_activations.
     */
    public function test_activation_feature_key_column_and_legacy_index_are_gone(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('business_usage_rate_activations');
        $this->assertNotContains('feature_key', $columns);

        $indexes = collect(DB::select('SHOW INDEX FROM business_usage_rate_activations'))
            ->pluck('Key_name')
            ->unique();
        $this->assertNotContains('business_usage_rate_activations_feature_key_index', $indexes);
    }

    /**
     * Proof 38 (rates/activations portion) — meter_key is NOT NULL,
     * VARCHAR(128), on both tables after Slice 3.
     */
    public function test_rate_and_activation_meter_key_are_not_null_varchar_128(): void
    {
        foreach (['business_usage_rates', 'business_usage_rate_activations'] as $table) {
            $column = collect(DB::select("SHOW COLUMNS FROM {$table} WHERE Field = 'meter_key'"))->first();
            $this->assertNotNull($column, "{$table}.meter_key column is missing.");
            $this->assertSame('NO', $column->Null, "{$table}.meter_key must be NOT NULL after Slice 3.");
            $this->assertStringContainsString('varchar(128)', strtolower($column->Type));
        }
    }

    public function test_rate_meter_key_not_null_is_enforced(): void
    {
        $this->expectException(QueryException::class);
        DB::table('business_usage_rates')->insert([
            'version' => 1,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up',
            'currency_id' => $this->usdCurrencyId(),
            'created_by_user_id' => 1,
            'created_at' => now(),
        ]);
    }

    public function test_activation_meter_key_not_null_is_enforced(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);
        $rateId = $this->insertRate($meterKey, $usdId);

        $this->expectException(QueryException::class);
        DB::table('business_usage_rate_activations')->insert([
            'rate_id' => $rateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Test activation.',
            'created_at' => now(),
        ]);
    }

    /**
     * Final uniqueness is meter-local: two different meters may each
     * hold "version 1" with zero collision — the direct schema-level
     * counterpart to proof 41's behavioral demonstration.
     */
    public function test_meter_key_and_version_are_unique_together(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);
        $this->insertRate($meterKey, $usdId, ['version' => 1]);

        $this->expectException(QueryException::class);
        $this->insertRate($meterKey, $usdId, ['version' => 1]);
    }

    public function test_two_different_meters_may_share_the_same_version_number(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKeyA = $this->insertMeter($usdId);
        $meterKeyB = $this->insertMeter($usdId);

        $idA = $this->insertRate($meterKeyA, $usdId, ['version' => 1]);
        $idB = $this->insertRate($meterKeyB, $usdId, ['version' => 1]);

        $this->assertNotSame($idA, $idB);
        $this->assertDatabaseHas('business_usage_rates', ['id' => $idA, 'meter_key' => $meterKeyA, 'version' => 1]);
        $this->assertDatabaseHas('business_usage_rates', ['id' => $idB, 'meter_key' => $meterKeyB, 'version' => 1]);
    }

    public function test_two_versions_of_the_same_meter_are_allowed(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);
        $id1 = $this->insertRate($meterKey, $usdId, ['version' => 1]);
        $id2 = $this->insertRate($meterKey, $usdId, ['version' => 2]);

        $this->assertNotSame($id1, $id2);
        $this->assertSame(2, DB::table('business_usage_rates')->where('meter_key', $meterKey)->count());
    }

    public function test_rate_activation_requires_a_reason(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);
        $rateId = $this->insertRate($meterKey, $usdId);

        $activationId = DB::table('business_usage_rate_activations')->insertGetId([
            'meter_key' => $meterKey,
            'rate_id' => $rateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Test activation.',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('business_usage_rate_activations', ['id' => $activationId, 'rate_id' => $rateId]);
    }

    public function test_rate_id_restricts_deletion_while_activation_exists(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);
        $rateId = $this->insertRate($meterKey, $usdId);

        DB::table('business_usage_rate_activations')->insert([
            'meter_key' => $meterKey,
            'rate_id' => $rateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Test activation.',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_usage_rates')->where('id', $rateId)->delete();
    }

    public function test_rate_meter_currency_composite_fk_rejects_mismatched_currency(): void
    {
        $usdId = $this->usdCurrencyId();
        $eurId = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€', 'status' => true])->id;
        $meterKey = $this->insertMeter($usdId);

        $this->expectException(QueryException::class);
        $this->insertRate($meterKey, $usdId, ['currency_id' => $eurId]);
    }

    public function test_rate_meter_currency_composite_fk_accepts_matching_currency(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);

        $rateId = $this->insertRate($meterKey, $usdId, ['currency_id' => $usdId]);

        $this->assertDatabaseHas('business_usage_rates', ['id' => $rateId, 'meter_key' => $meterKey]);
    }

    public function test_rate_meter_currency_composite_fk_rejects_unknown_meter_key(): void
    {
        $this->expectException(QueryException::class);
        $this->insertRate('nonexistent.meter.key', $this->usdCurrencyId());
    }

    public function test_activation_plain_meter_fk_rejects_unknown_meter_key(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);
        $rateId = $this->insertRate($meterKey, $usdId);

        $this->expectException(QueryException::class);
        DB::table('business_usage_rate_activations')->insert([
            'meter_key' => 'nonexistent.meter.key',
            'rate_id' => $rateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Test activation.',
            'created_at' => now(),
        ]);
    }

    public function test_activation_meter_rate_composite_fk_rejects_mismatched_pair(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKeyA = $this->insertMeter($usdId);
        $meterKeyB = $this->insertMeter($usdId);
        $unrelatedRateId = $this->insertRate($meterKeyA, $usdId); // belongs to meter A

        $this->expectException(QueryException::class);
        DB::table('business_usage_rate_activations')->insert([
            'meter_key' => $meterKeyB,
            'rate_id' => $unrelatedRateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Test activation.',
            'created_at' => now(),
        ]);
    }

    public function test_activation_meter_rate_composite_fk_accepts_matching_pair(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);
        $rateId = $this->insertRate($meterKey, $usdId);

        $activationId = DB::table('business_usage_rate_activations')->insertGetId([
            'meter_key' => $meterKey,
            'rate_id' => $rateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Test activation.',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('business_usage_rate_activations', [
            'id' => $activationId,
            'meter_key' => $meterKey,
            'rate_id' => $rateId,
        ]);
    }

    /**
     * RFC-005 Amendment 1 Slice 2 CUTOVER §5.3 — findByMeterAndVersion()
     * resolves by meter_key. Unaffected by Slice 3.
     */
    public function test_repository_find_by_meter_and_version_resolves_by_meter_key(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);
        $rateId = $this->insertRate($meterKey, $usdId, ['version' => 7]);

        $found = app(BusinessUsageRateRepository::class)->findByMeterAndVersion($meterKey, 7);

        $this->assertNotNull($found);
        $this->assertSame($rateId, $found->id);
    }

    public function test_repository_find_by_meter_and_version_returns_null_for_unknown_meter(): void
    {
        $found = app(BusinessUsageRateRepository::class)->findByMeterAndVersion('unknown.meter.' . uniqid(), 1);

        $this->assertNull($found);
    }

    /**
     * RFC-005 Amendment 1 Slice 3 CONTRACT §5.2 — latestVersionForMeter()
     * is strictly meter-scoped, unaffected by a sibling meter's own
     * version history.
     */
    public function test_repository_latest_version_for_meter_ignores_sibling_meters(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKeyA = $this->insertMeter($usdId);
        $meterKeyB = $this->insertMeter($usdId);
        $this->insertRate($meterKeyA, $usdId, ['version' => 1]);
        $this->insertRate($meterKeyA, $usdId, ['version' => 2]);
        $this->insertRate($meterKeyB, $usdId, ['version' => 1]);

        $repository = app(BusinessUsageRateRepository::class);

        $this->assertSame(2, $repository->latestVersionForMeter($meterKeyA));
        $this->assertSame(1, $repository->latestVersionForMeter($meterKeyB));
    }

    public function test_repository_latest_version_for_meter_returns_zero_for_unknown_meter(): void
    {
        $this->assertSame(0, app(BusinessUsageRateRepository::class)->latestVersionForMeter('unknown.meter.' . uniqid()));
    }
}
