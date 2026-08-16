<?php

namespace Tests\Feature\Usage;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §11.7 — exact schema shape for
 * business_payer_transitions: append-only, plain business_id FK,
 * from/to_instrument_id nullable with no FK at M2 (deferred), mandatory
 * reason.
 */
class BusinessPayerTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_business_id_restricts_deletion_while_transition_exists(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('business_payer_transitions')->insert([
            'business_id' => $business->id,
            'from_payer_type' => 'workspace',
            'to_payer_type' => 'business',
            'actor_user_id' => 1,
            'reason' => 'Test.',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }

    public function test_multiple_transitions_are_allowed_for_the_same_business(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('business_payer_transitions')->insert([
            'business_id' => $business->id,
            'from_payer_type' => 'workspace',
            'to_payer_type' => 'business',
            'actor_user_id' => 1,
            'reason' => 'First.',
            'created_at' => now(),
        ]);

        DB::table('business_payer_transitions')->insert([
            'business_id' => $business->id,
            'from_payer_type' => 'business',
            'to_payer_type' => 'workspace',
            'actor_user_id' => 1,
            'reason' => 'Second.',
            'created_at' => now(),
        ]);

        $this->assertSame(2, DB::table('business_payer_transitions')->where('business_id', $business->id)->count());
    }

    public function test_reason_is_not_nullable(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->expectException(QueryException::class);
        DB::table('business_payer_transitions')->insert([
            'business_id' => $business->id,
            'from_payer_type' => 'workspace',
            'to_payer_type' => 'business',
            'actor_user_id' => 1,
            'reason' => null,
            'created_at' => now(),
        ]);
    }
}
