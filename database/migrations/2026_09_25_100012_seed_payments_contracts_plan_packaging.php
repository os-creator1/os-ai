<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 17 §6.4/§12.A, Sub-slice A — Payments & Contracts is
 * packaged in ALL THREE plan tiers (Core, Growth, Agency), per Blueprint §21
 * (line 434: "Forms/Questionnaires, Packages & Products, Payments & Contracts"
 * are carried by every tier). No tier is excluded.
 *
 * A NEW migration is mechanically required, exactly as
 * 2026_09_23_100005_seed_packages_products_plan_packaging.php documents:
 * 2026_08_13_120007_seed_workspace_plan_catalog_and_features.php has already
 * run in every environment and is insert-if-missing, so a new feature key
 * needs its own migration, never an edit to that one.
 *
 * Query-builder only, idempotent insert-if-missing: a (catalog, feature_key)
 * pair already present is never re-inserted or overwritten, and a catalog tier
 * that does not exist in this environment is skipped, never invented.
 *
 * Packaging is independent of, and does not by itself change, implementation
 * availability — PlatformFeatureRegistry's Planned/Available flip (Sub-slice
 * G) is the separate, code-backed authority that gates execution. Until that
 * flip, EntitlementManager refuses the feature for a fully entitled Business
 * with `platform_feature_unavailable`.
 *
 * down() is a deliberate NON-DESTRUCTIVE no-op, matching the cited precedent.
 */
return new class extends Migration
{
    private const FEATURE_KEY = 'payments_contracts';

    /** Contract 17 §6.4 / Blueprint §21 — all three tiers, no exclusion. */
    private const ENTITLED_TIERS = ['core', 'growth', 'agency'];

    public function up(): void
    {
        $now = now();

        foreach (self::ENTITLED_TIERS as $tier) {
            $catalogId = DB::table('workspace_plan_catalog')->where('tier', $tier)->value('id');

            if ($catalogId === null) {
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
