<?php

namespace Tests\Feature\GoogleAds\Http;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Ads\AdsFeatureAccess;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Navigation\CustomerMenuBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * The sidebar "Ads" group. Meta Ads V1 (contract 24 §8, M1) reshaped it from
 * Google's eight children to ONE parent with the provider children Overview
 * (cross-channel), Google, Meta; Google's own pages moved to the provider
 * sub-navigation in the shared `_header` (still asserted below via data-nav).
 * A child is shown only when its route exists AND the Business holds an Ads
 * entitlement (basic OR the full module under any key — AdsFeatureAccess),
 * so Core and Growth/Agency see the same three children; the Overview and
 * Meta children appear once the UI lane registers their routes.
 */
class AdsNavigationTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    /**
     * The sidebar children: the cross-channel Overview, Google and Meta (all routed).
     *
     * @return array<int, string>
     */
    private function expectedChildren(): array
    {
        return ['ads-overview', 'ads-google', 'ads-meta'];
    }

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

    public function test_every_ads_feature_key_is_in_the_menus_entitlement_snapshot(): void
    {
        // Meta Ads V1: all three full-module keys (ads_module + both legacy
        // synonyms) must be listed or the bulk snapshot fails closed on them.
        foreach (['ads_basic_visibility', 'google_ads_module', 'ads_module', 'meta_ads_module'] as $key) {
            $this->assertContains($key, CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES);
        }

        $this->assertSame([], array_diff(AdsFeatureAccess::gatedFeatureKeys(), CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES));
    }

    public function test_a_core_business_sees_the_provider_children(): void
    {
        $keys = $this->adsKeysFor(WorkspacePlanTier::Core);

        $this->assertSame(['ads', ...$this->expectedChildren()], $keys);
    }

    public function test_a_growth_business_sees_the_same_provider_children(): void
    {
        $keys = $this->adsKeysFor(WorkspacePlanTier::Growth);

        $this->assertSame(['ads', ...$this->expectedChildren()], $keys, 'Overview (when routed), Google, Meta (when routed).');
    }

    public function test_an_agency_business_sees_the_same_children_as_growth(): void
    {
        $this->assertSame(
            $this->adsKeysFor(WorkspacePlanTier::Growth),
            $this->adsKeysFor(WorkspacePlanTier::Agency),
        );
    }

    public function test_a_business_with_no_ads_feature_sees_no_ads_entry(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        // Every key AdsFeatureAccess recognises: ads_module and meta_ads_module
        // now also open the surface, so "no Ads feature" disables all of them.
        foreach (AdsFeatureAccess::anyAdsKeys() as $featureKey) {
            app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::from($featureKey), (int) $customer->user_id, 'Ads navigation test.');
        }

        $keys = $this->menuKeys($this->home()->assertOk()->getContent());

        foreach (['ads', 'ads-overview', 'ads-google', 'ads-meta'] as $key) {
            $this->assertNotContains($key, $keys);
        }
    }

    public function test_denying_the_whole_module_leaves_the_basic_visibility_children(): void
    {
        [$customer, $business] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        foreach (AdsFeatureAccess::fullModuleKeys() as $featureKey) {
            app(EntitlementManager::class)->disableBusinessFeature($business, PlatformFeature::from($featureKey), (int) $customer->user_id, 'Ads navigation test.');
        }

        $keys = array_values(array_filter($this->menuKeys($this->home()->assertOk()->getContent()), fn ($k) => str_starts_with($k, 'ads')));

        $this->assertSame(['ads', ...$this->expectedChildren()], $keys);
    }

    public function test_the_ads_group_needs_a_view_permission(): void
    {
        $permissions = array_values(array_diff($this->allCustomerPermissions(), ['view_google_ads', 'view_meta_ads']));

        $this->assertSame([], $this->adsKeysFor(WorkspacePlanTier::Growth, $permissions));
    }

    public function test_each_child_needs_its_own_view_permission(): void
    {
        $withoutGoogle = array_values(array_diff($this->allCustomerPermissions(), ['view_google_ads']));

        $this->assertNotContains('ads-google', $this->adsKeysFor(WorkspacePlanTier::Growth, $withoutGoogle), 'Google needs view_google_ads');
    }

    public function test_manage_alone_does_not_expose_the_ads_group(): void
    {
        $this->assertSame([], $this->adsKeysFor(WorkspacePlanTier::Growth, ['manage_google_ads', 'manage_meta_ads']));
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

        // The Google Overview lights the Google child. The parent's own link is
        // the cross-channel Overview when its route exists, else the Google Overview.
        $this->assertContains('ads-google', $this->activeMenuKeys($overview));
        $this->assertNotContains('ads-overview', $this->activeMenuKeys($overview));
        $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.ads.overview', [$workspace->uid, $business->uid]) . '"', $overview);

        // Every Google page keeps the Google child lit (its pages are the provider sub-navigation now).
        foreach (['budget', 'settings', 'campaigns.index', 'keywords.index', 'search-terms.index', 'leads.index', 'recommendations.index'] as $page) {
            $html = $this->get(route('customer.workspaces.businesses.ads.' . $page, [$workspace->uid, $business->uid]))->assertOk()->getContent();
            $this->assertContains('ads-google', $this->activeMenuKeys($html), "[{$page}] lights the Google menu child");
            $this->assertNotContains('ads-overview', $this->activeMenuKeys($html));
        }
    }

    public function test_the_provider_switcher_lists_overview_google_and_meta(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $html = $this->get(route('customer.workspaces.businesses.ads.index', [$workspace->uid, $business->uid]))->assertOk()->getContent();

        $this->assertStringContainsString('data-role="ads-provider-nav"', $html);

        foreach (['overview', 'google', 'meta'] as $provider) {
            $this->assertStringContainsString('data-provider="' . $provider . '"', $html);
        }
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
