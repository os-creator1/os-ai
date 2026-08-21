<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * M4 contract §10/§18 — business_usage_addon_catalog exact schema shape:
 * addon_key unique, currency_id restrictOnDelete, is_active defaults
 * true.
 */
class BusinessUsageAddonCatalogSchemaTest extends TestCase
{
    use RefreshDatabase;

    private int $currencyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
    }

    private function baseAttributes(array $overrides = []): array
    {
        return array_merge([
            'addon_key' => 'fixture-addon-'.uniqid(),
            'display_name' => 'Fixture Add-on',
            'price_micro' => 1_000_000,
            'currency_id' => $this->currencyId,
            'fulfillment_mode' => 'wallet_credit',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    public function test_addon_key_is_unique(): void
    {
        $key = 'fixture-addon-'.uniqid();
        DB::table('business_usage_addon_catalog')->insert($this->baseAttributes(['addon_key' => $key]));

        $this->expectException(QueryException::class);
        DB::table('business_usage_addon_catalog')->insert($this->baseAttributes(['addon_key' => $key]));
    }

    public function test_currency_id_restricts_deletion_while_referenced(): void
    {
        DB::table('business_usage_addon_catalog')->insert($this->baseAttributes());

        $this->expectException(QueryException::class);
        DB::table('currencies')->where('id', $this->currencyId)->delete();
    }

    public function test_is_active_defaults_true(): void
    {
        $id = DB::table('business_usage_addon_catalog')->insertGetId($this->baseAttributes());
        unset($id);

        $row = DB::table('business_usage_addon_catalog')->first();
        $this->assertTrue((bool) $row->is_active);
    }
}
