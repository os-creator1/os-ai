<?php

namespace Tests\Feature\GoogleAds\Http;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\CustomerMenuBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Google Ads Module V1 — the sidebar "Ads" group (contract 23 §7): a Core
 * Business sees Overview and Settings only, a Growth Business sees every
 * child whose route exists, a Business with neither feature sees nothing, the
 * parent needs `view_google_ads`, and the entry sits directly after SEO.
 *
 * Every child is shown only when its route exists AND the Business holds the
 * entitlement it needs (the same item()/route-existence rule every entry uses):
 * Core sees Overview and Settings; Growth/Agency see all eight.
 */
class AdsNavigationTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    private const ADS_CHILDREN = ['ads-overview', 'ads-campaigns', 'ads-keywords', 'ads-search-terms', 'ads-leads', 'ads-budget', 'ads-recommendations', 'ads-settings'];

    /** @return array<int, string> */
    private function adsKeysFor(WorkspacePlanTier $tier, ?array $permissions = null): array
    {
        [$customer] = $this->tenant($tier);
        $this->authenticateAs($customer, $permissions);

        return array_values(array_filter(
            $this->menuKeys($this->home()->assertOk()->getContent()),
            fn (string $key): bool => $key === 'ads' || str_starts_with($key, 'ads-'),
        ));
    }

    public function test_both_ads_features_are_in_the_menus_entitlement_snapshot(): void
    {
        $this->assertContains('ads_basic_visibility', CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES);
        $this->assertContains('google_ads_module', CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES);
        $this->assertNotContains('meta_ads_module', CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES);
    }

    public function test_a_core_business_sees_only_overview_and_settings(): void
    {
        $keys = $this->adsKeysFor(WorkspacePlanTier::Core);

        $this->assertSame(['ads', 'ads-overview', 'ads-settings'], $keys);
    }

    public function test_a_growth_business_sees_every_ads_page(): void
    {
        $keys = $this->adsKeysFor(WorkspacePlanTier::Growth);

        $this->assertSame(['ads', ...self::ADS_CHILDREN], $keys, 'Overview, Campaigns, Keywords, Search terms, Leads, Budget, Recommendations, Settings.');
    }

    public function test_an_agency_business_sees_the_same_children_as_growth(): void
    {
        $this->assertSame(
            $this->adsKeysFor(WorkspacePlanTier::Growth),
            $this->adsKeysFor(WorkspacePlanTier::Agency),
        );
    }

    public function test_a_business_with_neither_ads_feature_sees_no_ads_entry(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        foreach ([PlatformFeature::AdsBasicVisibility, PlatformFeature::GoogleAdsModule] as $feature) {
            app(EntitlementManager::class)->disableBusinessFeature($business, $feature, (int) $customer->user_id, 'Ads navigation test.');
        }

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['ads', ...self::ADS_CHILDREN] as $key) {
            $this->assertNotContains($key, $keys);
        }
    }

    public function test_denying_only_the_module_leaves_overview_and_settings(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::GoogleAdsModule, (int) $customer->user_id, 'Ads navigation test.');

        $keys = array_values(array_filter($this->menuKeys($this->home()->assertOk()->getContent()), fn ($k) => str_starts_with($k, 'ads')));

        $this->assertSame(['ads', 'ads-overview', 'ads-settings'], $keys);
    }

    public function test_the_ads_group_needs_the_view_permission(): void
    {
        $permissions = array_values(array_diff($this->allCustomerPermissions(), ['view_google_ads']));

        $this->assertSame([], $this->adsKeysFor(WorkspacePlanTier::Growth, $permissions));
    }

    public function test_manage_alone_does_not_expose_the_ads_group(): void
    {
        $this->assertSame([], $this->adsKeysFor(WorkspacePlanTier::Growth, ['manage_google_ads']));
    }

    public function test_the_ads_entry_sits_directly_after_seo(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());
        $topLevel = array_values(array_filter($keys, fn (string $k): bool => in_array($k, ['seo', 'ads'], true)));

        $this->assertSame(['seo', 'ads'], $topLevel);
    }

    public function test_the_ads_parent_links_to_the_overview_and_the_active_state_follows_the_page(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $overview = $this->get(route('customer.workspaces.businesses.ads.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertContains('ads-overview', $this->activeMenuKeys($overview));
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.ads.index', [$workspace->uid, $business->uid]) . '"', $overview);

        $budget = $this->get(route('customer.workspaces.businesses.ads.budget', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertContains('ads-budget', $this->activeMenuKeys($budget));
        $this->assertNotContains('ads-overview', $this->activeMenuKeys($budget));

        foreach (['campaigns' => 'ads-campaigns', 'keywords' => 'ads-keywords', 'search-terms' => 'ads-search-terms', 'leads' => 'ads-leads', 'recommendations' => 'ads-recommendations'] as $page => $key) {
            $html = $this->get(route('customer.workspaces.businesses.ads.' . $page . '.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();
            $this->assertContains($key, $this->activeMenuKeys($html), "[{$page}] lights its own menu child");
            $this->assertNotContains('ads-overview', $this->activeMenuKeys($html));
        }

        $settings = $this->get(route('customer.workspaces.businesses.ads.settings', [$workspace->uid, $business->uid]))->assertOk()->getContent();
        $this->assertContains('ads-settings', $this->activeMenuKeys($settings));
    }

    public function test_the_in_page_navigation_offers_only_what_the_business_may_open(): void
    {
        [$coreCustomer, $coreBusiness, $coreWorkspace] = $this->tenant(WorkspacePlanTier::Core, 'Core Studio', 'Core Account');
        $this->authenticateAs($coreCustomer);

        $core = $this->get(route('customer.workspaces.businesses.ads.index', [$coreWorkspace->uid, $coreBusiness->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('data-nav="overview"', $core);
        $this->assertStringContainsString('data-nav="settings"', $core);
        $this->assertStringNotContainsString('data-nav="budget"', $core);
        foreach (['campaigns', 'keywords', 'search-terms', 'leads', 'recommendations'] as $page) {
            $this->assertStringNotContainsString('data-nav="' . $page . '"', $core, "Core is never offered [{$page}]");
        }

        [$growthCustomer, $growthBusiness, $growthWorkspace] = $this->tenant(WorkspacePlanTier::Growth, 'Growth Studio', 'Growth Account');
        $this->authenticateAs($growthCustomer);

        $growth = $this->get(route('customer.workspaces.businesses.ads.index', [$growthWorkspace->uid, $growthBusiness->uid]))->assertOk()->getContent();
        $this->assertStringContainsString('data-nav="budget"', $growth);
        foreach (['campaigns', 'keywords', 'search-terms', 'leads', 'recommendations'] as $page) {
            $this->assertStringContainsString('data-nav="' . $page . '"', $growth, "Growth is offered [{$page}]");
        }
    }
}
