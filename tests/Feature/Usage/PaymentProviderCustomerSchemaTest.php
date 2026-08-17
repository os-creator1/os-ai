<?php

namespace Tests\Feature\Usage;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §21.1 — payment_provider_customers exact schema
 * shape: UNIQUE(provider, provider_customer_id), UNIQUE(id, provider),
 * generated active_business_id/active_workspace_id columns that free
 * their unique-index slot once a customer is detached.
 */
class PaymentProviderCustomerSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_provider_and_provider_customer_id_is_unique(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('payment_provider_customers')->insert([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_dup',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $other = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());

        $this->expectException(QueryException::class);
        DB::table('payment_provider_customers')->insert([
            'provider' => 'stripe',
            'business_id' => $other->id,
            'provider_customer_id' => 'cus_dup',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_one_active_provider_customer_per_business(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('payment_provider_customers')->insert([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_first',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('payment_provider_customers')->insert([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_second',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_detach_then_recreate_is_allowed(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $firstId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_first',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('payment_provider_customers')->where('id', $firstId)->update(['status' => 'detached']);

        // The generated active_business_id column now nulls out for the
        // detached row, freeing the unique-index slot for a new row.
        $secondId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_second',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNotSame($firstId, $secondId);
    }

    public function test_business_id_restricts_deletion_while_referenced(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('payment_provider_customers')->insert([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_restrict',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }
}
