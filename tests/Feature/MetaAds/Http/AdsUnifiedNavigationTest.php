<?php

namespace Tests\Feature\MetaAds\Http;

use App\Enums\Entitlement\WorkspacePlanTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Meta Ads Module V1 (contract 24 §8, M1) — ONE sidebar parent "Ads" whose
 * children are Overview (cross-channel), Google and Meta; no sidebar item is
 * named after a provider's product; each child lights on its own pages; each
 * child needs its own view capability.
 */
class AdsUnifiedNavigationTest extends TestCase
{
    use CreatesCustomerContextFixtures;
    use RefreshDatabase;

    /** @return array<int, string> */
    private function adsKeys(string $html): array
    {
        return array_values(array_filter($this->menuKeys($html), fn (string $key): bool => $key === 'ads' || str_starts_with($key, 'ads-')));
    }

    public function test_there_is_one_ads_parent_with_the_overview_google_and_meta_children(): void
    {
        foreach ([WorkspacePlanTier::Core, WorkspacePlanTier::Growth, WorkspacePlanTier::Agency] as $tier) {
            [$customer] = $this->tenant($tier, 'Studio ' . $tier->value, 'Account ' . $tier->value);
            $this->authenticateAs($customer);

            $html = $this->home()->assertOk()->getContent();

            $this->assertSame(['ads', 'ads-overview', 'ads-google', 'ads-meta'], $this->adsKeys($html), "[{$tier->value}] sees the one Ads parent and its three children");
            $this->assertSame(1, count(array_keys($this->menuKeys($html), 'ads', true)), 'exactly one Ads parent');
        }
    }

    public function test_no_sidebar_item_is_named_after_a_provider_product(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $text = $this->shellText($this->home()->assertOk()->getContent());

        foreach (['Meta Ads', 'Facebook Ads', 'Instagram Ads', 'Google Ads'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $text, "[{$forbidden}] must not be a sidebar item");
        }
    }

    public function test_the_parent_links_to_the_cross_channel_overview_and_each_child_to_its_own_page(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $base = [$workspace->uid, $business->uid];

        $html = $this->home()->assertOk()->getContent();

        foreach (['overview', 'index', 'meta.index'] as $name) {
            $this->assertStringContainsString('href="' . route('customer.workspaces.businesses.ads.' . $name, $base) . '"', $html, "[{$name}] is linked from the sidebar");
        }
    }

    public function test_each_child_lights_on_its_own_pages_only(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $base = [$workspace->uid, $business->uid];

        $overview = $this->get(route('customer.workspaces.businesses.ads.overview', $base))->assertOk()->getContent();
        $this->assertContains('ads-overview', $this->activeMenuKeys($overview));
        $this->assertNotContains('ads-google', $this->activeMenuKeys($overview));
        $this->assertNotContains('ads-meta', $this->activeMenuKeys($overview));

        foreach (['meta.index', 'meta.settings', 'meta.campaigns.index', 'meta.ad-sets.index', 'meta.ads.index', 'meta.recommendations.index', 'meta.leads.index'] as $page) {
            $html = $this->get(route('customer.workspaces.businesses.ads.' . $page, $base))->assertOk()->getContent();
            $this->assertContains('ads-meta', $this->activeMenuKeys($html), "[{$page}] lights the Meta child");
            $this->assertNotContains('ads-google', $this->activeMenuKeys($html), "[{$page}] does not light Google");
            $this->assertNotContains('ads-overview', $this->activeMenuKeys($html));
        }

        foreach (['index', 'settings', 'campaigns.index', 'leads.index'] as $page) {
            $html = $this->get(route('customer.workspaces.businesses.ads.' . $page, $base))->assertOk()->getContent();
            $this->assertContains('ads-google', $this->activeMenuKeys($html), "[{$page}] lights the Google child");
            $this->assertNotContains('ads-meta', $this->activeMenuKeys($html));
        }
    }

    public function test_the_provider_switcher_row_appears_on_every_provider_page(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);
        $base = [$workspace->uid, $business->uid];

        foreach (['overview' => 'overview', 'index' => 'google', 'meta.index' => 'meta', 'meta.settings' => 'meta', 'settings' => 'google'] as $page => $active) {
            $html = $this->get(route('customer.workspaces.businesses.ads.' . $page, $base))->assertOk()->getContent();

            $this->assertStringContainsString('data-role="ads-provider-nav"', $html);
            foreach (['overview', 'google', 'meta'] as $provider) {
                $this->assertStringContainsString('data-provider="' . $provider . '"', $html);
            }
            $this->assertMatchesRegularExpression('/btn-primary[^>]*aria-current="page"[^>]*\s+data-provider="' . $active . '"/', $html, "[{$page}] marks [{$active}] as the active channel");
        }
    }

    public function test_each_child_needs_its_own_view_permission(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);

        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['view_meta_ads'])));
        $withoutMeta = $this->adsKeys($this->home()->assertOk()->getContent());
        $this->assertSame(['ads', 'ads-overview', 'ads-google'], $withoutMeta);

        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['view_google_ads'])));
        $withoutGoogle = $this->adsKeys($this->home()->assertOk()->getContent());
        $this->assertSame(['ads', 'ads-overview', 'ads-meta'], $withoutGoogle);

        $this->authenticateAs($customer, array_values(array_diff($this->allCustomerPermissions(), ['view_google_ads', 'view_meta_ads'])));
        $this->assertSame([], $this->adsKeys($this->home()->assertOk()->getContent()));
    }
}
