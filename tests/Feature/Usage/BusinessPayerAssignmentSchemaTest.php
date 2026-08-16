<?php

namespace Tests\Feature\Usage;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §11.6 — exact schema shape for
 * business_payer_assignments: UNIQUE(business_id),
 * effective_payment_instrument_id nullable with no FK at M2 (deferred).
 */
class BusinessPayerAssignmentSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_business_id_is_unique(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('business_payer_assignments')->insert([
            'business_id' => $business->id,
            'payer_type' => 'workspace',
            'effective_payment_instrument_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_payer_assignments')->insert([
            'business_id' => $business->id,
            'payer_type' => 'business',
            'effective_payment_instrument_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_effective_payment_instrument_id_has_no_fk_at_m2(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        // No business_payment_instruments table exists at M2 — an
        // arbitrary integer must be insertable without a schema-level FK
        // violation (deferred FK, mirroring M1's own funding_attempt_id
        // precedent).
        $id = DB::table('business_payer_assignments')->insertGetId([
            'business_id' => $business->id,
            'payer_type' => 'workspace',
            'effective_payment_instrument_id' => 999999,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $row = DB::table('business_payer_assignments')->find($id);
        $this->assertSame(999999, (int) $row->effective_payment_instrument_id);
    }

    public function test_business_id_restricts_deletion_while_assignment_exists(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('business_payer_assignments')->insert([
            'business_id' => $business->id,
            'payer_type' => 'workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }
}
