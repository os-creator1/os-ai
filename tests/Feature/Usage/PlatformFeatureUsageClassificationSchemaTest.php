<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\PlatformFeature;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §6.4/§6.5 — platform_feature_usage_classifications
 * (UNIQUE feature_key) and platform_feature_usage_classification_transitions
 * schema; the transitions table has zero rows after M1's own backfill
 * (migration 8), since it is never written by any M1 code path.
 */
class PlatformFeatureUsageClassificationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_seeded_exactly_one_row_per_platform_feature(): void
    {
        $this->assertSame(
            count(PlatformFeature::cases()),
            DB::table('platform_feature_usage_classifications')->count(),
        );

        foreach (PlatformFeature::cases() as $feature) {
            $this->assertDatabaseHas('platform_feature_usage_classifications', [
                'feature_key' => $feature->value,
                'is_metered' => false,
                'active_rate_id' => null,
            ]);
        }
    }

    public function test_feature_key_is_unique(): void
    {
        $this->expectException(QueryException::class);
        DB::table('platform_feature_usage_classifications')->insert([
            'feature_key' => PlatformFeature::Crm->value,
            'is_metered' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_classification_transitions_table_has_zero_rows_after_m1_backfill(): void
    {
        $this->assertSame(0, DB::table('platform_feature_usage_classification_transitions')->count());
    }

    public function test_classification_transitions_schema_accepts_a_full_row(): void
    {
        $id = DB::table('platform_feature_usage_classification_transitions')->insertGetId([
            'feature_key' => 'crm',
            'from_is_metered' => false,
            'to_is_metered' => true,
            'from_active_rate_id' => null,
            'to_active_rate_id' => null,
            'actor_user_id' => 1,
            'reason' => 'Test transition.',
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('platform_feature_usage_classification_transitions', ['id' => $id]);

        $columns = DB::getSchemaBuilder()->getColumnListing('platform_feature_usage_classification_transitions');
        $this->assertNotContains('updated_at', $columns);
    }
}
