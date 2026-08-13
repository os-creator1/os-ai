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
     *
     * up() is idempotent under step rollback/reapply (M1 correction round
     * 2): each tier and each plan-feature mapping is inserted only if
     * missing — never re-inserted or overwritten — so a rerun after down()
     * never clears a later legitimate price/currency value on an existing
     * catalog row and never creates a duplicate row. No insertOrIgnore()/
     * INSERT IGNORE is used anywhere; every ordinary database error still
     * propagates normally.
     */
    return new class extends Migration {
        private const CATALOG = [
            [
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
            ],
            [
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
            ],
            [
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
            ],
        ];

        public function up(): void
        {
            $now = now();

            $catalogIds = [];

            foreach (self::CATALOG as $tierRow) {
                $existingId = DB::table('workspace_plan_catalog')->where('tier', $tierRow['tier'])->value('id');

                if ($existingId !== null) {
                    // Reuse the existing row exactly as-is — never
                    // overwritten, so a later legitimate price/currency
                    // value already set on it (outside this migration) is
                    // never cleared by a rerun.
                    $catalogIds[$tierRow['tier']] = (int) $existingId;

                    continue;
                }

                $catalogIds[$tierRow['tier']] = DB::table('workspace_plan_catalog')->insertGetId(array_merge($tierRow, [
                    'created_at' => $now,
                    'updated_at' => $now,
                ]));
            }

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

            $matrix = ['core' => $coreFeatures, 'growth' => $growthFeatures, 'agency' => $agencyFeatures];

            foreach ($matrix as $tier => $featureKeys) {
                $catalogId = $catalogIds[$tier];

                $existingFeatureKeys = DB::table('workspace_plan_features')
                    ->where('workspace_plan_catalog_id', $catalogId)
                    ->pluck('feature_key')
                    ->all();

                $missingFeatureKeys = array_diff($featureKeys, $existingFeatureKeys);

                if ($missingFeatureKeys === []) {
                    continue;
                }

                $rows = [];

                foreach ($missingFeatureKeys as $featureKey) {
                    $rows[] = [
                        'workspace_plan_catalog_id' => $catalogId,
                        'feature_key' => $featureKey,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('workspace_plan_features')->insert($rows);
            }
        }

        public function down(): void
        {
            // Intentionally a non-destructive no-op. Migration 8's backfill
            // rows deliberately survive its own down() (RFC-004 §25.2,
            // mirroring RFC-003 migration 5's identical rationale) — a
            // backfilled workspace_plan_assignments row's
            // workspace_plan_catalog_id foreign key RESTRICTs deletion of
            // the catalog row it references. If this migration's down()
            // deleted workspace_plan_features then workspace_plan_catalog
            // rows (as an earlier version of this file did), a rollback run
            // after a real backfill would successfully delete the
            // packaging matrix, then fail deleting the still-referenced
            // Core catalog row, leaving the database partially rolled back.
            // Physical removal of these tables is migrations 1-6's own
            // down() responsibility during a complete rollback; this
            // migration only ever adds rows, never removes them.
        }
    };
