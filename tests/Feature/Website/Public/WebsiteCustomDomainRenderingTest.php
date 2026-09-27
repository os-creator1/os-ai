<?php

namespace Tests\Feature\Website\Public;

use App\Enums\Business\BusinessStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\WebsitePublisher;
use App\Models\Website;
use App\Models\WebsiteDomain;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Website Generation + Hosting Slice B (custom domains, contract §40).
 * App\Http\Middleware\ResolveCustomDomainWebsite is a GLOBAL middleware,
 * so these requests go through the real HTTP kernel exactly like a real
 * visitor's would — including App\Http\Middleware\TrustHosts, which
 * every test here depends on trusting the fixture domain it creates.
 *
 * Every domain row here is created directly at Active status: the
 * verification/provisioning lifecycle itself is WebsiteDomainTest's
 * concern, not this file's.
 */
class WebsiteCustomDomainRenderingTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private function activeDomain(Website $website, string $domain, bool $primary = true): WebsiteDomain
    {
        return $website->domains()->create([
            'domain' => $domain,
            'is_primary' => $primary,
            'status' => WebsiteDomainStatus::Active,
            'verification_token' => 'token',
            'verified_at' => now(),
            'activated_at' => now(),
        ]);
    }

    public function test_an_active_primary_domain_serves_the_published_website(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'custom-domain-home.test');

        $home = $this->get('http://'.$domain->domain.'/');
        $home->assertOk()
            ->assertSee('Welcome')
            ->assertSee('index, follow', false)
            ->assertHeader('X-Robots-Tag', 'index, follow');

        $this->get('http://'.$domain->domain.'/about')
            ->assertOk()
            ->assertSee('About us');
    }

    public function test_a_page_with_its_own_noindex_stays_noindex_even_on_an_active_domain(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'private-page', ['noindex' => true]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'noindex-page.test');

        $this->get('http://'.$domain->domain.'/private-page')
            ->assertOk()
            ->assertSee('noindex, follow', false)
            ->assertHeader('X-Robots-Tag', 'noindex, follow');
    }

    public function test_an_alias_domain_redirects_to_the_primary_domain(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $primary = $this->activeDomain($website, 'canonical-domain.test', true);
        $alias = $this->activeDomain($website, 'alias-domain.test', false);

        $this->get('http://'.$alias->domain.'/about')
            ->assertRedirect('https://'.$primary->domain.'/about')
            ->assertStatus(301);
    }

    public function test_a_domain_that_is_not_yet_active_does_not_serve_the_website(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->domains()->create([
            'domain' => 'still-provisioning.test',
            'is_primary' => true,
            'status' => WebsiteDomainStatus::Provisioning,
            'verification_token' => 'token',
        ]);

        // Falls all the way through to normal routing — the platform's
        // own root route still exists for any host (routing is
        // host-agnostic), but a path no platform route registers
        // 404s exactly as it would on the platform's own domain,
        // proving this domain never got a chance to serve anything.
        $this->get('http://still-provisioning.test/this-path-matches-no-platform-route')->assertNotFound();
    }

    public function test_an_inactive_business_is_not_served_even_on_an_active_domain(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'inactive-business.test');

        DB::table('businesses')->where('id', $business->id)->update(['status' => BusinessStatus::Inactive->value]);

        $this->get('http://'.$domain->domain.'/')->assertNotFound();
    }

    public function test_sitemap_uses_domain_based_urls_on_a_custom_domain(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'sitemap-domain.test');

        $response = $this->get('http://'.$domain->domain.'/sitemap');
        $response->assertOk();
        $this->assertStringContainsString('https://sitemap-domain.test/about', $response->getContent());
        $this->assertStringNotContainsString($website->public_id, $response->getContent());
    }

    public function test_the_platforms_own_host_is_unaffected_by_custom_domain_resolution(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $this->activeDomain($website, 'unrelated-active-domain.test');

        // The platform's own path-based route still works exactly as
        // before, unaffected by any active custom domain existing.
        $this->get(route('public.website.home', $website->public_id))
            ->assertOk()
            ->assertSee('Welcome')
            ->assertHeader('X-Robots-Tag', 'noindex, follow');
    }
}
