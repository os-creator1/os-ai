<?php

namespace Tests\Feature\Website\Public;

use App\Enums\Business\BusinessStatus;
use App\Enums\Website\WebsiteDomainCertificateStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\Domains\WebsiteDomainService;
use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsitePublisher;
use App\Models\Website;
use App\Models\WebsiteDomain;
use App\Models\WebsiteForm;
use App\Models\WebsiteFormSubmission;
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

    /**
     * Drives a domain to Active through the REAL WebsiteDomainService
     * (DnsVerifier/ForgeDomainProvisioner faked), so its cache-
     * invalidation side effects actually fire — a row created directly
     * via activeDomain() above never goes through the service and so
     * never exercises the invalidation this file is proving.
     */
    private function activateViaService(Website $website, string $hostname): WebsiteDomain
    {
        $service = app(WebsiteDomainService::class);
        $domain = $service->attach($website, $hostname);
        $domain = $service->checkVerification($domain);
        $domain = $service->provisionCertificate($domain);

        return $service->checkCertificate($domain);
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

    public function test_a_removed_domain_stops_serving_the_former_website_on_the_very_next_request(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        // WebsitePublisher::publish() re-fetches its own locked copy and
        // never mutates the caller's instance — attach() below checks
        // published_revision_id, so this instance must be refreshed.
        $website->refresh();

        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('requestCertificateForDomains')->andReturn('ref-1');
        $provisioner->shouldReceive('certificateStatus')->andReturn(WebsiteDomainCertificateStatus::Active);

        $domain = $this->activateViaService($website, 'to-be-removed.test');

        $this->get('http://'.$domain->domain.'/')->assertOk()->assertSee('Welcome');

        app(WebsiteDomainService::class)->remove($website, $domain);

        // Nothing here is cached: the very next request for the same
        // Host finds no matching Active WebsiteDomain row and falls
        // through to normal, host-agnostic routing — which has a root
        // route (redirecting a guest visitor), so this proves the fall-
        // through with a path no platform route registers at all,
        // mirroring test_a_domain_that_is_not_yet_active_does_not_serve_the_website.
        $this->get('http://'.$domain->domain.'/this-path-matches-no-platform-route')->assertNotFound();
    }

    public function test_activation_is_trusted_and_served_on_the_very_next_request(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->refresh();

        // Warms App\Http\Middleware\TrustHosts's own cache BEFORE this
        // domain exists — its cached active-domain list is stale
        // (missing this domain) by the time activation happens below.
        $this->get(route('public.website.home', $website->public_id))->assertOk();

        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('requestCertificateForDomains')->andReturn('ref-1');
        $provisioner->shouldReceive('certificateStatus')->andReturn(WebsiteDomainCertificateStatus::Active);

        $domain = $this->activateViaService($website, 'freshly-active.test');

        // If TrustHosts's stale cache were still in effect, this Host
        // would be untrusted and the request would never reach this
        // middleware's own render() at all — this must succeed on the
        // VERY NEXT request, not after the cache's own TTL lapses.
        $this->get('http://'.$domain->domain.'/')->assertOk()->assertSee('Welcome');
    }

    public function test_reassigning_primary_takes_effect_on_the_very_next_request(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $primary = $this->activeDomain($website, 'was-primary.test', true);
        $secondary = $this->activeDomain($website, 'will-be-primary.test', false);

        $this->get('http://'.$secondary->domain.'/')
            ->assertRedirect('https://'.$primary->domain.'/');

        app(WebsiteDomainService::class)->makePrimary($website, $secondary);

        // No cache anywhere sits between a request and this lookup, so
        // both roles fully flip on the very next request for each host.
        $this->get('http://'.$secondary->domain.'/')->assertOk()->assertSee('Welcome');
        $this->get('http://'.$primary->domain.'/')
            ->assertRedirect('https://'.$secondary->domain.'/');
    }

    public function test_post_to_the_platform_login_route_is_refused_on_a_custom_domain(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $this->activeDomain($website, 'login-post-domain.test');

        $this->post('http://login-post-domain.test/login', [
            'email' => 'someone@example.test',
            'password' => 'whatever',
        ])->assertNotFound();
    }

    public function test_get_to_the_platform_login_route_is_refused_on_a_custom_domain(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $this->activeDomain($website, 'login-get-domain.test');

        // Not because of an explicit login-route block — a matched
        // custom domain only ever serves ITS OWN pages, and no page has
        // the slug "login", so this 404s exactly like any other unknown
        // path would.
        $this->get('http://login-get-domain.test/login')->assertNotFound();
    }

    public function test_post_to_a_customer_action_route_is_refused_on_a_custom_domain(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->activeDomain($website, 'customer-action-domain.test');
        $this->authenticateAsCustomer($customer);

        // Captured BEFORE any request runs: route() roots its URL on the
        // last request's own host once one has been handled, and the
        // custom-domain request below would otherwise silently poison
        // this second, platform-host URL too.
        $platformUrl = route('customer.workspaces.businesses.website.publish', [$workspace->uid, $business->uid]);
        $path = parse_url($platformUrl, PHP_URL_PATH);

        $this->post('http://customer-action-domain.test'.$path)->assertNotFound();

        // The very same route, hit on the platform's own host, succeeds
        // — proving the 404 above is the custom-domain allowlist at
        // work, not some unrelated failure (missing auth, bad params).
        $this->post($platformUrl)->assertRedirect();
    }

    public function test_post_to_a_webhook_route_is_refused_on_a_custom_domain(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->activeDomain($website, 'webhook-domain.test');

        // Captured BEFORE any request runs — see the identical note in
        // test_post_to_a_customer_action_route_is_refused_on_a_custom_domain().
        $platformUrl = route('public.business-payments.webhook');
        $path = parse_url($platformUrl, PHP_URL_PATH);

        $this->post('http://webhook-domain.test'.$path)->assertNotFound();

        // On the platform's own host the identical request reaches the
        // controller and fails signature verification (400) — never
        // 404 — proving the 404 above is attributable to the
        // custom-domain middleware, not to some other rejection.
        $this->post($platformUrl)->assertStatus(400);
    }

    public function test_the_websites_own_quote_form_submission_still_works_on_a_custom_domain(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $form = $website->forms()->create([
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Send',
        ]);
        $home = $this->homePage($website, [
            'sections' => [$this->section('hero'), $this->section('form', ['form_uid' => $form->uid])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'form-submit-domain.test');

        $path = parse_url(
            route('public.website.form.submit', [$website->public_id, $form->uid, $home->uid]),
            PHP_URL_PATH
        );

        $this->post('http://'.$domain->domain.$path, [
            'name' => 'Jane Visitor',
            'phone' => '5551234567',
        ])->assertRedirect();

        $this->assertSame(1, WebsiteFormSubmission::where('website_form_id', $form->id)->count());
    }
}
