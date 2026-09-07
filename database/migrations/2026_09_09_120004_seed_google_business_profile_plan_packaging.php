<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * GBP Slice A contract §2.1/§29.1 — the binding human packaging decision:
 * Google Business Profile is included in GROWTH and AGENCY only. CORE is
 * EXCLUDED. There is no separately priced add-on, and no price is
 * invented anywhere (workspace_plan_catalog already seeds price/currency
 * null for every tier).
 *
 * A NEW migration is mechanically required (contract §2.3):
 * 2026_08_13_120007_seed_workspace_plan_catalog_and_features.php has
 * already run in every environment and is insert-if-missing, so editing
 * its $growthFeatures/$agencyFeatures constants would never re-run.
 *
 * Query-builder only, no Eloquent model dependency, copying that
 * migration's idempotent insert-if-missing idiom exactly: a (catalog,
 * feature_key) pair already present is never re-inserted and never
 * overwritten. No insertOrIgnore()/INSERT IGNORE is used anywhere; every
 * ordinary database error still propagates normally.
 *
 * down() is a deliberate NON-DESTRUCTIVE no-op, matching
 * 2026_08_13_120007's own documented rationale: a backfilled
 * workspace_plan_assignments row's workspace_plan_catalog_id foreign key
 * RESTRICTs catalog deletion, so a partial rollback would leave the
 * database half-migrated. This migration only ever adds rows.
 */
return new class extends Migration
{
    /**
     * Contract §2.2 — the persisted feature key, derived mechanically from
     * PlatformFeature's own module naming (seo_module, google_ads_module,
     * meta_ads_module).
     */
    private const FEATURE_KEY = 'google_business_profile_module';

    /**
     * Contract §2.1 — Growth and Agency only. 'core' is deliberately
     * absent: no row is inserted for it, and no row is deleted from it.
     */
    private const ENTITLED_TIERS = ['growth', 'agency'];

    public function up(): void
    {
        $now = now();

        foreach (self::ENTITLED_TIERS as $tier) {
            $catalogId = DB::table('workspace_plan_catalog')->where('tier', $tier)->value('id');

            if ($catalogId === null) {
                // The catalog tier does not exist in this environment.
                // Nothing to package against; never invent a catalog row.
                continue;
            }

            $alreadyPackaged = DB::table('workspace_plan_features')
                ->where('workspace_plan_catalog_id', $catalogId)
                ->where('feature_key', self::FEATURE_KEY)
                ->exists();

            if ($alreadyPackaged) {
                continue;
            }

            DB::table('workspace_plan_features')->insert([
                'workspace_plan_catalog_id' => (int) $catalogId,
                'feature_key' => self::FEATURE_KEY,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Intentionally a non-destructive no-op — see the class docblock.
    }
};
