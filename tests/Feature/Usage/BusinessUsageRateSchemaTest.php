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
}
