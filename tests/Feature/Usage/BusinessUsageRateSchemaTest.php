<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §6.2/§6.3 — business_usage_rates (fully immutable,
 * no updated_at) and business_usage_rate_activations schema/constraints.
 */
class BusinessUsageRateSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function usdCurrencyId(): int
    {
        return Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
    }

    private function insertRate(array $overrides = []): int
    {
        return DB::table('business_usage_rates')->insertGetId(array_merge([
            'feature_key' => 'crm',
            'version' => 1,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up',
            'currency_id' => $this->usdCurrencyId(),
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

    public function test_feature_key_and_version_are_unique_together(): void
    {
        $this->insertRate(['feature_key' => 'crm', 'version' => 1]);

        $this->expectException(QueryException::class);
        $this->insertRate(['feature_key' => 'crm', 'version' => 1]);
    }

    public function test_two_versions_of_the_same_feature_are_allowed(): void
    {
        $id1 = $this->insertRate(['feature_key' => 'crm', 'version' => 1]);
        $id2 = $this->insertRate(['feature_key' => 'crm', 'version' => 2]);

        $this->assertNotSame($id1, $id2);
        $this->assertSame(2, DB::table('business_usage_rates')->where('feature_key', 'crm')->count());
    }

    public function test_rate_activation_requires_a_reason(): void
    {
        $rateId = $this->insertRate();

        $activationId = DB::table('business_usage_rate_activations')->insertGetId([
            'feature_key' => 'crm',
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
        $rateId = $this->insertRate();

        DB::table('business_usage_rate_activations')->insert([
            'feature_key' => 'crm',
            'rate_id' => $rateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Test activation.',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_usage_rates')->where('id', $rateId)->delete();
    }

    /**
     * RFC-005 Amendment 1 §B/§D, Slice 1 EXPAND — a disposable UsageMeter,
     * never seeded/real, for exercising the new meter FKs on rates and
     * activations.
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
            'description' => 'Slice 1 schema test fixture meter.',
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));

        return $meterKey;
    }

    public function test_rate_meter_key_null_is_accepted(): void
    {
        $rateId = $this->insertRate();

        $this->assertDatabaseHas('business_usage_rates', ['id' => $rateId, 'meter_key' => null]);
    }

    public function test_rate_meter_currency_composite_fk_rejects_mismatched_currency(): void
    {
        $usdId = $this->usdCurrencyId();
        $eurId = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€', 'status' => true])->id;
        $meterKey = $this->insertMeter($usdId);

        $this->expectException(QueryException::class);
        $this->insertRate(['meter_key' => $meterKey, 'currency_id' => $eurId]);
    }

    public function test_rate_meter_currency_composite_fk_accepts_matching_currency(): void
    {
        $usdId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter($usdId);

        $rateId = $this->insertRate(['meter_key' => $meterKey, 'currency_id' => $usdId]);

        $this->assertDatabaseHas('business_usage_rates', ['id' => $rateId, 'meter_key' => $meterKey]);
    }

    public function test_rate_meter_currency_composite_fk_rejects_unknown_meter_key(): void
    {
        $this->expectException(QueryException::class);
        $this->insertRate(['meter_key' => 'nonexistent.meter.key']);
    }

    public function test_activation_meter_key_null_is_accepted(): void
    {
        $rateId = $this->insertRate();

        $activationId = DB::table('business_usage_rate_activations')->insertGetId([
            'feature_key' => 'crm',
            'rate_id' => $rateId,
            'activated_at' => now(),
            'activated_by_user_id' => 1,
            'reason' => 'Test activation.',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('business_usage_rate_activations', ['id' => $activationId, 'meter_key' => null]);
    }

    public function test_activation_plain_meter_fk_rejects_unknown_meter_key(): void
    {
        $rateId = $this->insertRate();

        $this->expectException(QueryException::class);
        DB::table('business_usage_rate_activations')->insert([
            'feature_key' => 'crm',
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
        $meterKey = $this->insertMeter($usdId);
        $unrelatedRateId = $this->insertRate(); // no meter_key — belongs to no meter

        $this->expectException(QueryException::class);
        DB::table('business_usage_rate_activations')->insert([
            'feature_key' => 'crm',
            'meter_key' => $meterKey,
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
        $rateId = $this->insertRate(['meter_key' => $meterKey, 'currency_id' => $usdId]);

        $activationId = DB::table('business_usage_rate_activations')->insertGetId([
            'feature_key' => 'crm',
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
}
