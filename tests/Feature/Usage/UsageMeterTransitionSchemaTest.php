<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use App\Repositories\Contracts\UsageMeterTransitionRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 Amendment 1 §C, Slice 1 EXPAND — usage_meter_transitions full
 * schema (plain meter FK, same-meter from/to active-rate composite FKs)
 * and UsageMeterTransitionRepository's append-only authority. No legacy
 * writer exists for this table (activateMetering() has no production
 * caller and no test exercises it), and no real row is ever seeded here.
 */
class UsageMeterTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function usdCurrencyId(): int
    {
        return Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
    }

    private function insertMeter(array $overrides = []): string
    {
        $meterKey = $overrides['meter_key'] ?? ('crm.meter.' . uniqid());
        $now = now();

        DB::table('usage_meters')->insert(array_merge([
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
        ], $overrides));

        return $meterKey;
    }

    /**
     * $version defaults to 1 but must be given a distinct value by any
     * caller that creates more than one 'crm' rate within the same test —
     * business_usage_rates_feature_key_version_unique (Slice 1's
     * intentionally retained legacy constraint, RFC-005 Amendment 1 §D)
     * is feature-wide, not meter-local, so two rates for different
     * sibling meters under the same feature_key still collide unless
     * given distinct versions.
     */
    private function insertRateForMeter(string $meterKey, int $currencyId, int $version = 1): int
    {
        return DB::table('business_usage_rates')->insertGetId([
            'meter_key' => $meterKey,
            'version' => $version,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up',
            'currency_id' => $currencyId,
            'created_by_user_id' => 1,
            'created_at' => now(),
        ]);
    }

    public function test_usage_meter_transitions_table_is_empty_by_default(): void
    {
        $this->assertSame(0, DB::table('usage_meter_transitions')->count());
    }

    public function test_meter_key_fk_rejects_unknown_meter(): void
    {
        $this->expectException(QueryException::class);
        DB::table('usage_meter_transitions')->insert([
            'meter_key' => 'nonexistent.meter.key',
            'from_is_metered' => false,
            'to_is_metered' => true,
            'from_active_rate_id' => null,
            'to_active_rate_id' => null,
            'actor_user_id' => 1,
            'reason' => 'Test transition.',
            'created_at' => now(),
        ]);
    }

    public function test_from_active_rate_composite_fk_rejects_rate_belonging_to_a_different_meter(): void
    {
        $currencyId = $this->usdCurrencyId();
        $meterA = $this->insertMeter(['meter_key' => 'crm.meter.a', 'currency_id' => $currencyId]);
        $meterB = $this->insertMeter(['meter_key' => 'crm.meter.b', 'currency_id' => $currencyId]);
        $rateOfB = $this->insertRateForMeter($meterB, $currencyId);

        $this->expectException(QueryException::class);
        DB::table('usage_meter_transitions')->insert([
            'meter_key' => $meterA,
            'from_is_metered' => false,
            'to_is_metered' => true,
            'from_active_rate_id' => $rateOfB,
            'to_active_rate_id' => $rateOfB,
            'actor_user_id' => 1,
            'reason' => 'Test transition.',
            'created_at' => now(),
        ]);
    }

    public function test_to_active_rate_composite_fk_rejects_rate_belonging_to_a_different_meter(): void
    {
        $currencyId = $this->usdCurrencyId();
        $meterA = $this->insertMeter(['meter_key' => 'crm.meter.a2', 'currency_id' => $currencyId]);
        $meterB = $this->insertMeter(['meter_key' => 'crm.meter.b2', 'currency_id' => $currencyId]);
        $rateOfA = $this->insertRateForMeter($meterA, $currencyId, 1);
        $rateOfB = $this->insertRateForMeter($meterB, $currencyId, 2);

        $this->expectException(QueryException::class);
        DB::table('usage_meter_transitions')->insert([
            'meter_key' => $meterA,
            'from_is_metered' => false,
            'to_is_metered' => true,
            'from_active_rate_id' => $rateOfA,
            'to_active_rate_id' => $rateOfB,
            'actor_user_id' => 1,
            'reason' => 'Test transition.',
            'created_at' => now(),
        ]);
    }

    public function test_null_active_rate_ids_are_accepted(): void
    {
        $meterKey = $this->insertMeter();

        $id = DB::table('usage_meter_transitions')->insertGetId([
            'meter_key' => $meterKey,
            'from_is_metered' => false,
            'to_is_metered' => true,
            'from_active_rate_id' => null,
            'to_active_rate_id' => null,
            'actor_user_id' => 1,
            'reason' => 'Test transition with no rate yet.',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('usage_meter_transitions', [
            'id' => $id,
            'from_active_rate_id' => null,
            'to_active_rate_id' => null,
        ]);
    }

    public function test_matching_meter_and_rate_pair_is_accepted(): void
    {
        $currencyId = $this->usdCurrencyId();
        $meterKey = $this->insertMeter(['currency_id' => $currencyId]);
        $rateId = $this->insertRateForMeter($meterKey, $currencyId);

        $id = DB::table('usage_meter_transitions')->insertGetId([
            'meter_key' => $meterKey,
            'from_is_metered' => false,
            'to_is_metered' => true,
            'from_active_rate_id' => $rateId,
            'to_active_rate_id' => $rateId,
            'actor_user_id' => 1,
            'reason' => 'Test transition with matching rate.',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('usage_meter_transitions', ['id' => $id, 'meter_key' => $meterKey]);
    }

    public function test_repository_is_append_only(): void
    {
        $repository = app(UsageMeterTransitionRepository::class);

        $this->assertFalse(method_exists($repository, 'update'));
        $this->assertFalse(method_exists($repository, 'delete'));
    }

    public function test_repository_create_persists_a_transition(): void
    {
        $meterKey = $this->insertMeter();
        $repository = app(UsageMeterTransitionRepository::class);

        $transition = $repository->create([
            'meter_key' => $meterKey,
            'from_is_metered' => false,
            'to_is_metered' => true,
            'from_active_rate_id' => null,
            'to_active_rate_id' => null,
            'actor_user_id' => 1,
            'reason' => 'Created via repository.',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('usage_meter_transitions', ['id' => $transition->id]);
    }
}
