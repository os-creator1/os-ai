<?php

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Usage\PlatformFeatureUsageClassificationBackfillIncompleteException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEO Keyword Rank Tracking V1 — classification row for PlatformFeature::SeoRankTracking
 * (is_metered=false). Required so a fresh migrate's completeness check passes; same idiom as
 * 2026_09_25_100011_backfill_payments_contracts_usage_classification.php. down() is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $existingFeatureKeys = DB::table('platform_feature_usage_classifications')->pluck('feature_key')->all();

        $rows = [];

        foreach (PlatformFeature::cases() as $feature) {
            if (in_array($feature->value, $existingFeatureKeys, true)) {
                continue;
            }

            $rows[] = [
                'feature_key' => $feature->value,
                'is_metered' => false,
                'active_rate_id' => null,
                'updated_by_user_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('platform_feature_usage_classifications')->insert($rows);
        }

        $remainingUnclassifiedCount = count(PlatformFeature::cases())
            - DB::table('platform_feature_usage_classifications')
                ->whereIn('feature_key', array_map(fn (PlatformFeature $f) => $f->value, PlatformFeature::cases()))
                ->count();

        if ($remainingUnclassifiedCount > 0) {
            throw new PlatformFeatureUsageClassificationBackfillIncompleteException($remainingUnclassifiedCount);
        }
    }

    public function down(): void
    {
        // Intentionally a non-destructive no-op — see the class docblock.
    }
};
