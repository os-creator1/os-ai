<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SEO Keyword Rank Tracking V1 — SeoRankTracking is packaged in Core, Growth and Agency.
 * Insert-if-missing, query-builder only, same idiom as
 * 2026_09_25_100012_seed_payments_contracts_plan_packaging.php. Numeric limits per tier live
 * in config/seo.php. down() is a no-op.
 */
return new class extends Migration
{
    private const FEATURE_KEY = 'seo_rank_tracking';

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
