<?php

namespace Tests\Feature\Usage;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §11.3 — exact schema shape for
 * business_usage_limit_transitions: append-only, business_id nullable
 * (only for platform_safety_limit rows), restrictOnDelete().
 */
class BusinessUsageLimitTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_business_id_is_nullable_for_platform_safety_limit_rows(): void
    {
        $id = DB::table('business_usage_limit_transitions')->insertGetId([
            'business_id' => null,
            'limit_type' => 'platform_safety_limit',
            'feature_key' => 'crm',
            'from_value_micro' => null,
            'to_value_micro' => 1_000_000,
            'actor_user_id' => 1,
            'reason' => 'Test.',
            'created_at' => now(),
        ]);

        $row = DB::table('business_usage_limit_transitions')->find($id);
        $this->assertNull($row->business_id);
    }

    public function test_business_id_restricts_deletion_while_transition_exists(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('business_usage_limit_transitions')->insert([
            'business_id' => $business->id,
            'limit_type' => 'business_spend_cap',
            'feature_key' => null,
            'from_value_micro' => null,
            'to_value_micro' => 1_000_000,
            'actor_user_id' => 1,
            'reason' => 'Test.',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }

    public function test_reason_is_not_nullable(): void
    {
        $this->expectException(QueryException::class);

        DB::table('business_usage_limit_transitions')->insert([
            'business_id' => null,
            'limit_type' => 'platform_safety_limit',
            'feature_key' => 'crm',
            'from_value_micro' => null,
            'to_value_micro' => 1_000_000,
            'actor_user_id' => 1,
            'reason' => null,
            'created_at' => now(),
        ]);
    }
}
