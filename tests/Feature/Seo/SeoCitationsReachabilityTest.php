<?php

namespace Tests\Feature\Seo;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\Entitlement\PlatformFeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Seo\Concerns\CreatesSeoCitationFixtures;
use Tests\TestCase;

/**
 * Citations V1 — the page is genuinely customer-reachable on the production
 * code path. Deliberately does NOT bind the entitlement-bypass controller that
 * the other Citations suites use, so every request here runs the real
 * entitlement step. SeoModule (Growth + Agency) already gates Citations,
 * Reviews and the audit; nothing in this lane adds a feature key or changes
 * packaging, and Search Console — the unfinished child — has no route at all.
 */
class SeoCitationsReachabilityTest extends TestCase
{
    use RefreshDatabase;
    use CreatesSeoCitationFixtures;

    private function reviewsUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.seo.reviews.index', [$workspace->uid, $business->uid]);
    }

    public function test_seo_module_is_available_so_citations_is_not_fail_closed(): void
    {
        $this->assertTrue(PlatformFeatureRegistry::isAvailable('seo_module'));
        $this->assertTrue(PlatformFeatureRegistry::isAvailable('seo_basic_visibility'));
    }

    public function test_a_growth_owner_reaches_citations_and_reviews_without_any_bypass(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publicStorefront($business, 'Main Storefront');
        $this->authenticateAsSeoCustomer($customer);

        $citations = $this->get($this->citationsUrl($workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Essential listings', $citations);
        $this->assertStringContainsString('data-directory="apple_business"', $citations);

        $this->get($this->reviewsUrl($workspace, $business))->assertOk();
    }

    public function test_a_core_business_is_refused_citations_and_reviews_as_a_plain_404(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $this->publicStorefront($business, 'Main Storefront');
        $this->authenticateAsSeoCustomer($customer);

        $this->get($this->citationsUrl($workspace, $business))->assertNotFound();
        $this->get($this->reviewsUrl($workspace, $business))->assertNotFound();
    }

    public function test_a_growth_owner_without_view_seo_is_refused_even_with_the_entitlement(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Growth);
        $this->publicStorefront($business, 'Main Storefront');
        $this->authenticateAsSeoCustomer($customer, ['manage_seo']);

        $this->get($this->citationsUrl($workspace, $business))->assertUnauthorized();
    }

    public function test_the_write_routes_run_the_real_entitlement_step_too(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant(WorkspacePlanTier::Core);
        $location = $this->publicStorefront($business, 'Main Storefront');
        $this->authenticateAsSeoCustomer($customer);

        $this->put($this->citationUpdateUrl($workspace, $business, (string) $location->uid, 'bing_places'), $this->citationInput())->assertNotFound();
        $this->post(route('customer.workspaces.businesses.seo.citations.applicability', [$workspace->uid, $business->uid, $location->uid, 'bing_places']), ['applicable' => 0])->assertNotFound();
        $this->post(route('customer.workspaces.businesses.seo.citations.custom.store', [$workspace->uid, $business->uid, $location->uid]), $this->citationInput(['name' => 'X']))->assertNotFound();
    }

    public function test_search_console_the_unfinished_child_has_no_customer_route(): void
    {
        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('search-console', $route->uri());
        }
    }
}
