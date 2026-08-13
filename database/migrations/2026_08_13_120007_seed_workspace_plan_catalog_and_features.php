<?php

    use Illuminate\Database\Migrations\Migration;
    use Illuminate\Support\Facades\DB;

    /**
     * RFC-004 §12.2/§12.3/§25.1 step G — a plain data-operation migration
     * (query-builder-only, no Eloquent model dependency), seeding exactly
     * the three workspace_plan_catalog tiers and the exact plan-feature
     * packaging matrix. No monetary price is invented (§12.5) — price and
     * currency_id are seeded null/null for every tier.
     *
     * Packaging is independent of PlatformFeatureRegistry's availability
     * lock (§11) — a Planned feature (e.g. prospect_outreach) still
     * receives its packaging row here; §12.2 states this is a valid,
     * honest seed row, not a promise of current executability.
     */
    return new class extends Migration {
        public function up(): void
        {
            $now = now();

            $coreId = DB::table('workspace_plan_catalog')->insertGetId([
                'tier' => 'core',
                'display_name' => 'Core',
                'price' => null,
                'currency_id' => null,
                'billing_cycle' => 'monthly',
                'business_slot_included' => 3,
                'business_slot_max' => 5,
                'unlimited_business_slots' => false,
                'additional_business_slot_price_ratio' => 0.5000,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $growthId = DB::table('workspace_plan_catalog')->insertGetId([
                'tier' => 'growth',
                'display_name' => 'Growth',
                'price' => null,
                'currency_id' => null,
                'billing_cycle' => 'monthly',
                'business_slot_included' => 3,
                'business_slot_max' => 5,
                'unlimited_business_slots' => false,
                'additional_business_slot_price_ratio' => 0.5000,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $agencyId = DB::table('workspace_plan_catalog')->insertGetId([
                'tier' => 'agency',
                'display_name' => 'Agency',
                'price' => null,
                'currency_id' => null,
                'billing_cycle' => 'monthly',
                'business_slot_included' => 3,
                'business_slot_max' => null,
                'unlimited_business_slots' => true,
                'additional_business_slot_price_ratio' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $coreFeatures = [
                'crm', 'conversations', 'calendar', 'forms', 'automations',
                'website_generation', 'ai_coo_basic', 'seo_basic_visibility', 'ads_basic_visibility',
            ];
            $growthFeatures = array_merge($coreFeatures, [
                'seo_module', 'google_ads_module', 'meta_ads_module',
            ]);
            $agencyFeatures = array_merge($growthFeatures, [
                'white_label', 'agency_package_capabilities', 'prospect_outreach',
            ]);

            $rows = [];

            foreach ([$coreId => $coreFeatures, $growthId => $growthFeatures, $agencyId => $agencyFeatures] as $catalogId => $featureKeys) {
                foreach ($featureKeys as $featureKey) {
                    $rows[] = [
                        'workspace_plan_catalog_id' => $catalogId,
                        'feature_key' => $featureKey,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            DB::table('workspace_plan_features')->insert($rows);
        }

        public function down(): void
        {
            DB::table('workspace_plan_features')->delete();
            DB::table('workspace_plan_catalog')->delete();
        }
    };
