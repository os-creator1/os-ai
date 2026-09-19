<?php

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Usage\PlatformFeatureUsageClassificationBackfillIncompleteException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 17 §6.4/§12.A, Sub-slice A — the usage
 * classification row for the new PlatformFeature::PaymentsContracts case.
 *
 * A NEW migration is mechanically required:
 * 2026_08_16_120008_backfill_platform_feature_usage_classifications.php has
 * already run in every environment and skips feature_keys already present, so
 * editing it cannot add a new row — and on a FRESH migrate it would reach that
 * one, find payments_contracts unclassified, and throw
 * PlatformFeatureUsageClassificationBackfillIncompleteException (its own
 * completeness check). This copies the idiom of
 * 2026_09_23_100004_backfill_packages_products_usage_classification.php
 * exactly (itself a copy of the GBP precedent).
 *
 * is_metered=false, active_rate_id=null: Payments & Contracts has NO metered
 * usage and creates NO wallet side effect (Contract 17 §11.3 — delivery is
 * email; lane-B money never touches lane D). This row is a classification
 * fact, not a wallet interaction.
 *
 * down() is a non-destructive no-op, matching both cited precedents.
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
