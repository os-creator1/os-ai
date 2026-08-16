<?php

namespace Tests\Feature\Usage;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §11.2 — exact schema shape for
 * platform_feature_usage_safety_limits: UNIQUE(feature_key), NOT NULL
 * max_monthly_limit_micro (safe because M2 inserts zero rows).
 */
class PlatformFeatureUsageSafetyLimitSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_key_is_unique(): void
    {
        $now = now();

        DB::table('platform_feature_usage_safety_limits')->insert([
            'feature_key' => 'crm',
            'max_monthly_limit_micro' => 1_000_000,
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->expectException(QueryException::class);
        DB::table('platform_feature_usage_safety_limits')->insert([
            'feature_key' => 'crm',
            'max_monthly_limit_micro' => 2_000_000,
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_max_monthly_limit_micro_is_not_nullable(): void
    {
        $this->expectException(QueryException::class);

        DB::table('platform_feature_usage_safety_limits')->insert([
            'feature_key' => 'crm',
            'max_monthly_limit_micro' => null,
            'updated_by_user_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_table_is_empty_at_m2_launch(): void
    {
        $this->assertSame(0, DB::table('platform_feature_usage_safety_limits')->count());
    }
}
