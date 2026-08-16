<?php

namespace Tests\Feature\Usage;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §11.1 — exact schema shape for
 * business_feature_usage_limits: UNIQUE(business_id, feature_key),
 * nullable monthly_limit_micro, restrictOnDelete() on business_id.
 */
class BusinessFeatureUsageLimitSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function insertLimit(int $businessId, string $featureKey, array $overrides = []): int
    {
        $now = now();

        return DB::table('business_feature_usage_limits')->insertGetId(array_merge([
            'business_id' => $businessId,
            'feature_key' => $featureKey,
            'monthly_limit_micro' => null,
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ], $overrides));
    }

    public function test_monthly_limit_micro_is_nullable_and_defaults_to_null(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $id = $this->insertLimit($business->id, 'crm');

        $row = DB::table('business_feature_usage_limits')->find($id);
        $this->assertNull($row->monthly_limit_micro);
    }

    public function test_business_id_and_feature_key_are_jointly_unique(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->insertLimit($business->id, 'crm');

        $this->expectException(QueryException::class);
        $this->insertLimit($business->id, 'crm');
    }

    public function test_same_business_can_have_limits_for_different_features(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $this->insertLimit($business->id, 'crm');
        $this->insertLimit($business->id, 'conversations');

        $this->assertSame(2, DB::table('business_feature_usage_limits')->where('business_id', $business->id)->count());
    }

    public function test_business_id_restricts_deletion_while_limit_exists(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->insertLimit($business->id, 'crm');

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }
}
