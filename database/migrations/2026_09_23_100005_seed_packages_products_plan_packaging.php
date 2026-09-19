<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 16 §11, Sub-slice A — Packages & Products is
 * packaged in ALL THREE plan tiers (Core, Growth, Agency), per Blueprint
 * §21. Unlike Google Business Profile (Growth/Agency only, Core
 * deliberately excluded), no tier is excluded here.
 *
 * A NEW migration is mechanically required, mirroring
 * 2026_09_09_120004_seed_google_business_profile_plan_packaging.php's own
 * documented rationale exactly: 2026_08_13_120007_seed_workspace_plan_catalog_and_features.php
 * has already run in every environment and is insert-if-missing, so a new
 * feature key requires its own migration, never an edit to that one.
 *
 * Query-builder only, no Eloquent model dependency, copying that
 * precedent's idempotent insert-if-missing idiom exactly: a (catalog,
 * feature_key) pair already present is never re-inserted or overwritten.
 *
 * Packaging existing here is independent of, and does not by itself
 * change, implementation availability — PlatformFeatureRegistry's
 * Planned/Available flip (Sub-slice E) is the separate, code-backed
 * authority that actually gates execution (Contract 16 §11).
 *
 * down() is a deliberate NON-DESTRUCTIVE no-op, matching both cited
 * precedents' own rationale: this migration only ever adds rows.
 */
return new class extends Migration
{
    private const FEATURE_KEY = 'packages_products';

    /** Contract 16 §11 — all three tiers, no exclusion. */
    private const ENTITLED_TIERS = ['core', 'growth', 'agency'];

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
