<?php

    use App\Enums\Entitlement\PlatformFeature;
    use App\Exceptions\Usage\PlatformFeatureUsageClassificationBackfillIncompleteException;
    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\DB;

    /**
     * RFC-005 §14.1, M1 contract §9.1 — a plain data-operation migration
     * (query-builder-only, no Eloquent model dependency), seeding one row
     * per existing PlatformFeature case, is_metered=false,
     * active_rate_id=null. updated_by_user_id is null (system migration —
     * mirrors migration 4's nullable resolution of this column).
     *
     * Idempotent under rerun: a feature_key already classified is never
     * re-inserted or overwritten. down() is a non-destructive no-op,
     * mirroring RFC-004 migration 7's own established rationale — the
     * classification rows this migration adds are read (never referenced
     * by a child FK) by later code, so there is no destructive-rollback
     * risk here, but the same conservative discipline is applied anyway
     * for consistency with this repository's own precedent.
     */
    return new class extends Migration {
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
            // Intentionally a non-destructive no-op — mirrors RFC-004
            // migration 7's own established rationale (M1 contract §9.4).
        }
    };
