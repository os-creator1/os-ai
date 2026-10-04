<?php

namespace Tests\Feature\Ads;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Entitlement\PlatformFeatureRegistry;
use App\Library\Navigation\CustomerMenuBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Meta Ads V1 (contract 24 §8, M10) — AdsFeatureAccess is the ONE place that
 * answers "does this Business have the full Ads capability":
 * ads_module OR google_ads_module OR meta_ads_module. Compatibility with
 * plans/overrides that only know the legacy keys is pinned here.
 */
class AdsFeatureAccessTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private function access(): AdsFeatureAccess
    {
        return app(AdsFeatureAccess::class);
    }

    /**
     * A Growth Business whose plan row set holds EXACTLY the given full-module
     * keys (ads_basic_visibility is left as packaged).
     *
     * @param  list<string>  $fullModuleKeys
     * @return array{0: \App\Models\Workspace, 1: \App\Models\Business}
     */
    private function tenantWithFullModuleKeys(array $fullModuleKeys): array
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        $catalogId = DB::table('workspace_plan_catalog')->where('tier', WorkspacePlanTier::Growth->value)->value('id');

        DB::table('workspace_plan_features')
            ->where('workspace_plan_catalog_id', $catalogId)
            ->whereIn('feature_key', AdsFeatureAccess::fullModuleKeys())
            ->delete();

        foreach ($fullModuleKeys as $key) {
            DB::table('workspace_plan_features')->insert([
                'workspace_plan_catalog_id' => $catalogId,
                'feature_key' => $key,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$workspace->fresh(), $business->fresh()];
    }

    public function test_the_enum_and_registry_declare_the_neutral_key_and_keep_both_legacy_keys(): void
    {
        $this->assertSame('ads_module', PlatformFeature::AdsModule->value);
        $this->assertSame('google_ads_module', PlatformFeature::GoogleAdsModule->value);
        $this->assertSame('meta_ads_module', PlatformFeature::MetaAdsModule->value);

        foreach (AdsFeatureAccess::anyAdsKeys() as $key) {
            $this->assertTrue(PlatformFeatureRegistry::isAvailable($key), "[{$key}] must be Available");
        }

        $this->assertSame(['ads_module', 'google_ads_module', 'meta_ads_module'], AdsFeatureAccess::fullModuleKeys());
        $this->assertSame(['ads_basic_visibility'], AdsFeatureAccess::basicVisibilityKeys());
        $this->assertTrue(AdsFeatureAccess::isImplementedAndAvailable());
    }

    public function test_every_key_is_in_the_menu_snapshot_so_the_bulk_path_never_fails_closed(): void
    {
        $this->assertSame([], array_diff(AdsFeatureAccess::gatedFeatureKeys(), CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES));
    }

    public function test_a_plan_with_only_the_legacy_google_key_has_the_full_module(): void
    {
        [$workspace, $business] = $this->tenantWithFullModuleKeys(['google_ads_module']);

        $this->assertTrue($this->access()->hasFullModule($workspace, $business, 0));
        $this->assertTrue($this->access()->hasBasicVisibility($workspace, $business, 0));
        $this->assertTrue($this->access()->hasAnyAds($workspace, $business, 0));
    }

    public function test_a_plan_with_only_the_legacy_meta_key_has_the_full_module(): void
    {
        [$workspace, $business] = $this->tenantWithFullModuleKeys(['meta_ads_module']);

        $this->assertTrue($this->access()->hasFullModule($workspace, $business, 0));
    }

    public function test_a_plan_with_only_the_new_key_has_the_full_module(): void
    {
        [$workspace, $business] = $this->tenantWithFullModuleKeys(['ads_module']);

        $this->assertTrue($this->access()->hasFullModule($workspace, $business, 0));
    }

    public function test_a_plan_with_none_of_the_full_module_keys_has_basic_visibility_only(): void
    {
        [$workspace, $business] = $this->tenantWithFullModuleKeys([]);

        $this->assertFalse($this->access()->hasFullModule($workspace, $business, 0));
        $this->assertTrue($this->access()->hasBasicVisibility($workspace, $business, 0));
        $this->assertTrue($this->access()->hasAnyAds($workspace, $business, 0));
    }

    public function test_a_core_plan_has_basic_visibility_and_not_the_full_module(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);

        $this->assertFalse($this->access()->hasFullModule($workspace, $business, 0));
        $this->assertTrue($this->access()->hasBasicVisibility($workspace, $business, 0));
    }

    public function test_the_packaging_migration_gave_growth_and_agency_the_neutral_key_and_core_never(): void
    {
        $keysFor = fn (string $tier): array => DB::table('workspace_plan_features')
            ->join('workspace_plan_catalog', 'workspace_plan_catalog.id', '=', 'workspace_plan_features.workspace_plan_catalog_id')
            ->where('workspace_plan_catalog.tier', $tier)
            ->pluck('workspace_plan_features.feature_key')
            ->all();

        $this->assertContains('ads_module', $keysFor('growth'));
        $this->assertContains('ads_module', $keysFor('agency'));
        $this->assertNotContains('ads_module', $keysFor('core'));
    }

    public function test_the_packaging_migration_is_idempotent_and_derives_tiers_from_the_google_key(): void
    {
        $count = fn (): int => DB::table('workspace_plan_features')->where('feature_key', 'ads_module')->count();
        $before = $count();

        $this->assertSame(2, $before);

        (require database_path('migrations/2026_10_29_200001_seed_ads_module_plan_packaging.php'))->up();
        (require database_path('migrations/2026_10_29_200001_seed_ads_module_plan_packaging.php'))->up();

        $this->assertSame($before, $count());
    }

    public function test_an_override_under_the_legacy_google_key_grants_the_full_module_to_a_core_business(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->assertFalse($this->access()->hasFullModule($workspace, $business, 0));

        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace,
            PlatformFeature::GoogleAdsModule,
            WorkspaceEntitlementOverrideState::Allow,
            $this->platformAdminId(),
            'Compatibility test: override under the legacy key.',
        );

        $this->assertTrue($this->access()->hasFullModule($workspace->fresh(), $business->fresh(), 0));
    }

    public function test_an_override_under_the_new_key_grants_the_full_module_to_a_core_business(): void
    {
        [, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);

        app(EntitlementManager::class)->createOrChangeOverride(
            $workspace,
            PlatformFeature::AdsModule,
            WorkspaceEntitlementOverrideState::Allow,
            $this->platformAdminId(),
            'Compatibility test: override under the neutral key.',
        );

        $this->assertTrue($this->access()->hasFullModule($workspace->fresh(), $business->fresh(), 0));
    }

    public function test_a_business_level_disable_of_every_key_removes_the_full_module_but_not_basic_visibility_alone(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);

        foreach (AdsFeatureAccess::fullModuleKeys() as $key) {
            app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::from($key), (int) $customer->user_id, 'Compatibility test.');
        }

        $this->assertFalse($this->access()->hasFullModule($workspace->fresh(), $business->fresh(), 0));
        $this->assertTrue($this->access()->hasBasicVisibility($workspace->fresh(), $business->fresh(), 0));
    }

    public function test_a_mismatched_workspace_and_business_is_simply_not_entitled(): void
    {
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $otherWorkspace = $this->createIndependentWorkspaceBusiness()['workspace'];

        $this->assertFalse($this->access()->hasAnyAds($otherWorkspace, $business, 0));
    }
}
