<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads Module V1 (contract 24 §8, M10) — the provider-neutral full Ads
 * capability `ads_module` is packaged wherever the plan catalog already
 * packages `google_ads_module` (Growth and Agency today). Plan rows for the
 * legacy keys are untouched: they stay valid synonyms resolved by
 * App\Library\Ads\AdsFeatureAccess.
 *
 * A NEW migration is mechanically required: 2026_08_13_120007 already ran in
 * every environment and is insert-if-missing. Query-builder only, no Eloquent
 * dependency, copying that migration's idempotent insert-if-missing idiom: a
 * (catalog, feature_key) pair already present is never re-inserted or
 * overwritten, and no catalog row is ever invented. The tier set is DERIVED
 * from the rows holding google_ads_module (no plan name appears here).
 *
 * down() is a deliberate NON-DESTRUCTIVE no-op, as in the migrations it
 * copies: this migration only ever adds rows.
 */
return new class extends Migration
{
    private const FEATURE_KEY = 'ads_module';

    private const SOURCE_FEATURE_KEY = 'google_ads_module';

    public function up(): void
    {
        $now = now();

        $catalogIds = DB::table('workspace_plan_features')
            ->where('feature_key', self::SOURCE_FEATURE_KEY)
            ->pluck('workspace_plan_catalog_id')
            ->unique()
            ->all();

        foreach ($catalogIds as $catalogId) {
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
