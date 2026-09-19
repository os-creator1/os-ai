<?php

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Usage\PlatformFeatureUsageClassificationBackfillIncompleteException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 16 §8/§11, Sub-slice A — the usage classification
 * row for the new PlatformFeature::PackagesProducts case.
 *
 * A NEW migration is mechanically required:
 * 2026_08_16_120008_backfill_platform_feature_usage_classifications.php has
 * already run in every environment and skips feature_keys already present,
 * so editing it cannot add a new row. Without this migration a FRESH
 * migrate would reach that one, find packages_products unclassified, and
 * throw PlatformFeatureUsageClassificationBackfillIncompleteException (that
 * migration's own completeness check) — the exact scenario
 * 2026_09_09_120005_backfill_google_business_profile_usage_classification.php
 * already solved for GoogleBusinessProfileModule; this migration copies
 * that precedent's idiom exactly.
 *
 * Query-builder only, is_metered=false, active_rate_id=null,
 * updated_by_user_id=null (system migration). Packages & Products has no
 * metered usage of any kind (Contract 16 §11 — no payment/wallet side
 * effect in this slice); a later slice that decides to price it is the one
 * that changes these fields.
 *
 * down() is a non-destructive no-op, mirroring both precedents' own
 * established rationale.
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
