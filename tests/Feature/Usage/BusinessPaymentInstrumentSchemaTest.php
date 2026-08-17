<?php

namespace Tests\Feature\Usage;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §21.2 — business_payment_instruments exact schema
 * shape: the composite (provider_customer_id, provider) -> (id, provider)
 * FK rejects a provider-mismatched insert at the database level;
 * UNIQUE(provider, provider_payment_method_id).
 */
class BusinessPaymentInstrumentSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createProviderCustomer(int $businessId): int
    {
        return DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe',
            'business_id' => $businessId,
            'provider_customer_id' => 'cus_'.$businessId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_provider_and_provider_payment_method_id_is_unique(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $providerCustomerId = $this->createProviderCustomer($business->id);

        DB::table('business_payment_instruments')->insert([
            'provider_customer_id' => $providerCustomerId,
            'provider' => 'stripe',
            'provider_payment_method_id' => 'pm_dup',
            'type' => 'card',
            'is_default' => true,
            'status' => 'active',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_payment_instruments')->insert([
            'provider_customer_id' => $providerCustomerId,
            'provider' => 'stripe',
            'provider_payment_method_id' => 'pm_dup',
            'type' => 'card',
            'is_default' => false,
            'status' => 'active',
            'created_at' => now(),
        ]);
    }

    public function test_composite_fk_rejects_provider_mismatch(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $providerCustomerId = $this->createProviderCustomer($business->id);

        // (provider_customer_id, provider) must resolve to exactly one
        // payment_provider_customers(id, provider) row — a mismatched
        // provider string is a schema-level impossibility.
        $this->expectException(QueryException::class);
        DB::table('business_payment_instruments')->insert([
            'provider_customer_id' => $providerCustomerId,
            'provider' => 'not_a_real_provider',
            'provider_payment_method_id' => 'pm_mismatch',
            'type' => 'card',
            'is_default' => true,
            'status' => 'active',
            'created_at' => now(),
        ]);
    }

    public function test_no_raw_card_number_column_exists(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('business_payment_instruments');

        foreach (['card_number', 'cvc', 'pan'] as $forbidden) {
            $this->assertNotContains($forbidden, $columns);
        }
    }
}
