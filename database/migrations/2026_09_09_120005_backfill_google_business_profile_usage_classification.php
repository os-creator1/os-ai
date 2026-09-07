<?php

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Usage\PlatformFeatureUsageClassificationBackfillIncompleteException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GBP Slice A contract §2.3/§29.2 — the usage classification row for the
 * new PlatformFeature case.
 *
 * A NEW migration is mechanically required:
 * 2026_08_16_120008_backfill_platform_feature_usage_classifications.php has
 * already run and skips feature_keys already present, so editing it cannot
 * add a new row. Without this migration a FRESH migrate would reach that
 * one, find google_business_profile_module unclassified, and throw
 * PlatformFeatureUsageClassificationBackfillIncompleteException (that
 * migration, lines 51-58).
 *
 * Copies 2026_08_16_120008's idiom exactly, including its completeness
 * check: query-builder only, is_metered=false, active_rate_id=null,
 * updated_by_user_id=null (system migration), insert-if-missing.
 *
 * GBP Slice A is NOT metered. It performs read-only Google calls against
 * the customer's own quota-bearing Cloud project and consumes no billable
 * platform AI or messaging usage (contract §7 of the implementation brief:
 * "Respect existing usage/budget architecture without inventing billable
 * AI usage").
 *
 * down() is a non-destructive no-op, mirroring 2026_08_16_120008's own
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
