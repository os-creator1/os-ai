<?php

namespace Tests\Feature\Website\Public;

use App\Enums\Business\BusinessStatus;
use App\Enums\Website\WebsiteDomainCertificateStatus;
use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Website\Domains\DomainProvisioningException;
use App\Library\Website\Domains\WebsiteDomainService;
use App\Library\Website\WebsiteFormPresets;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessLocation;
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

        $response = $this->get('http://'.$domain->domain.'/sitemap.xml');
        $response->assertOk();
        $this->assertStringContainsString('https://sitemap-domain.test/about', $response->getContent());
        $this->assertStringNotContainsString($website->public_id, $response->getContent());
    }

    public function test_sitemap_excludes_pages_marked_noindex(): void
    {
        // Search Central's own sitemap guidance: include only the URLs
        // you want to see in search results. A page the owner marked
        // noindex is never a URL this sitemap should ask Google to
        // discover, even though the platform-path sitemap (a different,
        // never-indexable surface) still lists every page regardless.
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'about');
        $this->subPage($website, 'hidden', ['noindex' => true]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'sitemap-noindex.test');

        $response = $this->get('http://'.$domain->domain.'/sitemap.xml');

        $response->assertOk();
        $this->assertStringContainsString('https://sitemap-noindex.test/about', $response->getContent());
        $this->assertStringNotContainsString('https://sitemap-noindex.test/hidden', $response->getContent());
    }

    public function test_canonical_tag_self_references_the_active_domain_and_survives_a_pages_own_noindex(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        $this->subPage($website, 'private-page', ['noindex' => true]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'canonical-self.test');

        $this->get('http://'.$domain->domain.'/')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://canonical-self.test/">', false);

        // Canonical answers "what is the one URL for this content",
        // which is independent of whether THIS page is indexable —
        // Google treats the two as compatible, not contradictory.
        $this->get('http://'.$domain->domain.'/private-page')
            ->assertOk()
            ->assertSee('<link rel="canonical" href="https://canonical-self.test/private-page">', false)
            ->assertSee('noindex, follow', false);
    }

    public function test_local_business_structured_data_uses_only_confirmed_visible_facts(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'email' => 'hello@example.test']);
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'public_address' => true,
            'hours' => [
                'monday' => [['open' => '09:00', 'close' => '17:00']],
                'tuesday' => [],
            ],
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        // A published `contact_details` section is what makes phone/
        // email/address genuinely visible on the site — without one,
        // JSON-LD must omit them regardless of the saved Business/
        // Location facts (see the dedicated visibility tests below).
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-confirmed.test');

        $response = $this->get('http://'.$domain->domain.'/');

        $response->assertOk()->assertSee('application/ld+json', false);
        $jsonLd = $this->extractJsonLd($response->getContent());
        $this->assertSame('LocalBusiness', $jsonLd['@type']);
        $this->assertSame($business->name, $jsonLd['name']);
        $this->assertSame('https://jsonld-confirmed.test/', $jsonLd['url']);
        $this->assertSame('+15550001234', $jsonLd['telephone']);
        $this->assertSame('hello@example.test', $jsonLd['email']);
        $this->assertSame('123 Main St', $jsonLd['address']['streetAddress']);
        $this->assertSame('Austin', $jsonLd['address']['addressLocality']);
        // No component on the published site ever presents opening
        // hours, so structured data never claims them either.
        $this->assertArrayNotHasKey('openingHoursSpecification', $jsonLd);
        $this->assertArrayNotHasKey('aggregateRating', $jsonLd);
        $this->assertArrayNotHasKey('review', $jsonLd);
    }

    public function test_local_business_structured_data_reflects_only_the_published_snapshot_not_live_changes(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'email' => 'hello@example.test']);
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'region' => 'TX',
            'postal_code' => '78701',
            'country_code' => 'US',
            'public_address' => true,
            'hours' => [
                'monday' => [['open' => '09:00', 'close' => '17:00']],
                'tuesday' => [],
            ],
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-immutable.test');

        // Live changes AFTER publishing, with no republish — the public
        // page must keep showing the snapshot taken at publish time, not
        // these new values, exactly like every other published fact.
        $business->update(['phone' => '+15559998888', 'email' => 'changed@example.test']);
        $location->update([
            'address_line_1' => '999 Other Ave',
            'city' => 'Dallas',
            'region' => 'TX',
            'postal_code' => '75201',
        ]);

        $jsonLd = $this->extractJsonLd($this->get('http://'.$domain->domain.'/')->getContent());
        $encoded = json_encode($jsonLd);

        $this->assertSame('+15550001234', $jsonLd['telephone']);
        $this->assertSame('hello@example.test', $jsonLd['email']);
        $this->assertSame('123 Main St', $jsonLd['address']['streetAddress']);
        $this->assertSame('Austin', $jsonLd['address']['addressLocality']);
        $this->assertSame('78701', $jsonLd['address']['postalCode']);
        $this->assertArrayNotHasKey('openingHoursSpecification', $jsonLd);

        $this->assertStringNotContainsString('+15559998888', $encoded);
        $this->assertStringNotContainsString('changed@example.test', $encoded);
        $this->assertStringNotContainsString('999 Other Ave', $encoded);
        $this->assertStringNotContainsString('Dallas', $encoded);
        $this->assertStringNotContainsString('75201', $encoded);
    }

    /**
     * Two different rules meet here (contract §7.3 vs §7.5): removing
     * phone/email live, with no republish, does NOT retroactively hide
     * them — they stay frozen from publish time like every other
     * ordinary fact. Revoking address privacy (`public_address`) is the
     * one exception: it suppresses the address immediately, on the very
     * next request, unlike phone/email.
     */
    public function test_local_business_structured_data_freezes_phone_and_email_but_immediately_suppresses_a_revoked_address(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'email' => 'hello@example.test']);
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'public_address' => true,
            'hours' => [
                'monday' => [['open' => '09:00', 'close' => '17:00']],
            ],
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-removed.test');

        // Removed/revoked after publishing, with no republish.
        $business->update(['phone' => null, 'email' => null]);
        $location->update(['public_address' => false, 'hours' => []]);

        $jsonLd = $this->extractJsonLd($this->get('http://'.$domain->domain.'/')->getContent());

        $this->assertSame('+15550001234', $jsonLd['telephone']);
        $this->assertSame('hello@example.test', $jsonLd['email']);
        $this->assertArrayNotHasKey('address', $jsonLd);
        $this->assertArrayNotHasKey('openingHoursSpecification', $jsonLd);
    }

    public function test_local_business_structured_data_never_reveals_a_private_address(): void
    {
        [, $business] = $this->entitledTenant();
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'city' => 'Austin',
            'public_address' => false,
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        // A published contact_details section DOES choose to display the
        // address — this test proves the SEO privacy predicate
        // (GoogleBusinessProfileReadMask::addressPermittedForLocation())
        // still blocks it from structured data even so, since a
        // service-area location's address is never safe to broadcast in
        // machine-readable metadata regardless of the owner's page-level
        // display choice.
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-private.test');

        $jsonLd = $this->extractJsonLd($this->get('http://'.$domain->domain.'/')->getContent());

        $this->assertArrayNotHasKey('address', $jsonLd);
        $this->assertArrayNotHasKey('openingHoursSpecification', $jsonLd);
    }

    public function test_local_business_structured_data_omits_phone_when_its_show_phone_toggle_is_off(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'email' => 'hello@example.test']);
        $website = $this->createWebsite($business);
        $this->homePage($website, [
            'sections' => [$this->section('hero'), $this->section('contact_details', ['show_phone' => false])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-hidden-phone.test');

        $jsonLd = $this->extractJsonLd($this->get('http://'.$domain->domain.'/')->getContent());

        $this->assertArrayNotHasKey('telephone', $jsonLd);
        $this->assertSame('hello@example.test', $jsonLd['email']);
    }

    public function test_local_business_structured_data_omits_email_when_its_show_email_toggle_is_off(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'email' => 'hello@example.test']);
        $website = $this->createWebsite($business);
        $this->homePage($website, [
            'sections' => [$this->section('hero'), $this->section('contact_details', ['show_email' => false])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-hidden-email.test');

        $jsonLd = $this->extractJsonLd($this->get('http://'.$domain->domain.'/')->getContent());

        $this->assertSame('+15550001234', $jsonLd['telephone']);
        $this->assertArrayNotHasKey('email', $jsonLd);
    }

    public function test_local_business_structured_data_omits_address_when_its_show_address_toggle_is_off(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'email' => 'hello@example.test']);
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'public_address' => true,
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, [
            'sections' => [$this->section('hero'), $this->section('contact_details', ['show_address' => false])],
        ]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-hidden-address.test');

        $jsonLd = $this->extractJsonLd($this->get('http://'.$domain->domain.'/')->getContent());

        $this->assertSame('+15550001234', $jsonLd['telephone']);
        $this->assertArrayNotHasKey('address', $jsonLd);
    }

    public function test_local_business_structured_data_omits_all_contact_facts_when_no_contact_details_section_is_published(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234', 'email' => 'hello@example.test']);
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'public_address' => true,
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        // Hero only — no contact_details section anywhere on the
        // published site, so nothing displays a phone/email/address.
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-no-contact-section.test');

        $response = $this->get('http://'.$domain->domain.'/');

        $response->assertOk()->assertSee('application/ld+json', false);
        $jsonLd = $this->extractJsonLd($response->getContent());

        $this->assertSame($business->name, $jsonLd['name']);
        $this->assertArrayNotHasKey('telephone', $jsonLd);
        $this->assertArrayNotHasKey('email', $jsonLd);
        $this->assertArrayNotHasKey('address', $jsonLd);
        $this->assertArrayNotHasKey('openingHoursSpecification', $jsonLd);
    }

    public function test_local_business_structured_data_is_absent_from_a_noindexed_page(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234']);
        $website = $this->createWebsite($business);
        $this->homePage($website, ['noindex' => true]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'jsonld-noindex.test');

        $this->get('http://'.$domain->domain.'/')
            ->assertOk()
            ->assertDontSee('application/ld+json', false);
    }

    /**
     * The visible `contact_details` HTML must apply the exact same
     * address-privacy predicate the LocalBusiness JSON-LD gate already
     * uses — a service-area location's address is never safe to publish
     * just because the owner checked "Show address".
     */
    public function test_contact_details_html_withholds_a_private_address_even_when_show_address_is_checked(): void
    {
        [, $business] = $this->entitledTenant();
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'service_area',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'public_address' => false,
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'contact-private-address.test');

        $this->get('http://'.$domain->domain.'/')
            ->assertOk()
            ->assertSee('website-contact-details', false)
            ->assertDontSee('123 Main St')
            ->assertDontSee('Austin');
    }

    public function test_contact_details_html_shows_a_permitted_address_when_show_address_is_checked(): void
    {
        [, $business] = $this->entitledTenant();
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'public_address' => true,
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'contact-permitted-address.test');

        $this->get('http://'.$domain->domain.'/')
            ->assertOk()
            ->assertSee('123 Main St')
            ->assertSee('Austin');
    }

    /**
     * Contract §7.5 — address privacy is the one narrow exception to
     * §7.3's usual "next publish" freeze: revoking `public_address`,
     * with no republish, must suppress the address on the VERY NEXT
     * public request, in both visible HTML and LocalBusiness JSON-LD —
     * never wait for a republish, since revealing a withdrawn address is
     * a privacy incident, not ordinary staleness.
     */
    public function test_contact_details_address_privacy_revoked_without_republishing_is_suppressed_on_the_next_request(): void
    {
        [, $business] = $this->entitledTenant();
        $business->update(['phone' => '+15550001234']);
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'public_address' => true,
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $domain = $this->activeDomain($website, 'contact-revoked-address.test');

        // Initial publication: permitted, so it appears in both places.
        $firstResponse = $this->get('http://'.$domain->domain.'/');
        $firstResponse->assertOk()->assertSee('123 Main St');
        $this->assertSame('123 Main St', $this->extractJsonLd($firstResponse->getContent())['address']['streetAddress']);

        // Revoked with no republish — must disappear on the very next
        // request, from HTML and JSON-LD alike. Phone (an ordinary,
        // non-privacy resolved value) is unaffected and keeps showing.
        $location->update(['public_address' => false]);

        $response = $this->get('http://'.$domain->domain.'/');
        $response->assertOk()->assertDontSee('123 Main St')->assertSee('+15550001234');
        $this->assertArrayNotHasKey('address', $this->extractJsonLd($response->getContent()));
    }

    /**
     * Contract §7.5 — the live gate applies no matter WHICH revision is
     * currently served: rolling back to an older revision that was
     * itself published while the address was still permitted must not
     * resurrect it once privacy has since been revoked. Revision rows
     * stay untouched; only what a response is built from is redacted.
     */
    public function test_contact_details_address_privacy_revocation_survives_a_rollback_to_an_older_permitted_revision(): void
    {
        [, $business] = $this->entitledTenant();
        $location = BusinessLocation::create([
            'business_id' => $business->id,
            'service_mode' => 'storefront',
            'address_line_1' => '123 Main St',
            'city' => 'Austin',
            'public_address' => true,
        ]);
        $location->is_primary = true;
        $location->save();
        $website = $this->createWebsite($business);
        $this->homePage($website, ['sections' => [$this->section('hero'), $this->section('contact_details')]]);
        $publisher = app(WebsitePublisher::class);
        $firstRevision = $publisher->publish($website, $this->platformAdminId());

        $this->subPage($website, 'about');
        $publisher->publish($website->fresh(), $this->platformAdminId());
        $domain = $this->activeDomain($website, 'contact-rollback-address.test');

        $location->update(['public_address' => false]);
        $publisher->rollback($website, $firstRevision->uid);

        // $firstRevision's OWN frozen snapshot still carries the address
        // (it was permitted when that revision was published) — the
        // live gate must withhold it anyway, on this now-current
        // revision, without ever mutating that revision row.
        $this->get('http://'.$domain->domain.'/')->assertOk()->assertDontSee('123 Main St');
        $this->assertSame('123 Main St', $firstRevision->fresh()->snapshot['website']['localBusiness']['address']['line1']);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractJsonLd(string $html): array
    {
        $this->assertMatchesRegularExpression('#<script type="application/ld\+json">(.+?)</script>#s', $html);
        preg_match('#<script type="application/ld\+json">(.+?)</script>#s', $html, $matches);

        return json_decode($matches[1], true);
    }

    public function test_the_platforms_own_host_is_unaffected_by_custom_domain_resolution(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $this->activeDomain($website, 'unrelated-active-domain.test');

        // The platform's own path-based route is still reachable (it is not
        // swallowed by custom-domain resolution), and with an Active domain it
        // sends visitors and crawlers to the one canonical address.
        $this->get(route('public.website.home', $website->public_id))
            ->assertStatus(301)
            ->assertRedirect('https://unrelated-active-domain.test/');
    }

    public function test_the_platform_path_still_renders_noindex_when_no_domain_is_active(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());

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
        $provisioner->shouldReceive('requestCertificate')->andReturn('ref-1');
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

    public function test_a_domain_stuck_removing_after_a_failed_forge_deletion_stops_serving_immediately(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);
        app(WebsitePublisher::class)->publish($website, $this->platformAdminId());
        $website->refresh();

        $this->fakeDnsVerifier(true);
        $provisioner = $this->fakeDomainProvisioner();
        $provisioner->shouldReceive('requestCertificate')->andReturn('ref-1');
        $provisioner->shouldReceive('certificateStatus')->andReturn(WebsiteDomainCertificateStatus::Active);

        $domain = $this->activateViaService($website, 'stuck-removing.test');

        $this->get('http://'.$domain->domain.'/')->assertOk()->assertSee('Welcome');

        // Forge refuses (or cannot be reached for) the deletion — the
        // domain row is deliberately NOT deleted (see
        // WebsiteDomainService::remove()'s own docblock), but this must
        // still be exactly as unreachable as a genuinely removed one on
        // the very next request.
        $provisioner->shouldReceive('detachDomain')->once()
            ->andThrow(new DomainProvisioningException('Forge did not respond.'));

        app(WebsiteDomainService::class)->remove($website, $domain);

        $domain->refresh();
        $this->assertSame(WebsiteDomainStatus::Removing, $domain->status);

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
        $provisioner->shouldReceive('requestCertificate')->andReturn('ref-1');
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
        // Forms V1: a form carries its Location, and a form with none accepts nothing.
        \App\Models\BusinessLocation::create(['business_id' => $business->id, 'name' => 'Main', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $website = $this->createWebsite($business);
        $form = $website->forms()->create([
            'business_id' => $website->business_id,
            'type' => WebsiteForm::TYPE_QUOTE_REQUEST,
            'name' => 'Quote Request',
            'fields' => WebsiteFormPresets::photoBoothQuoteRequest(),
            'submit_label' => 'Send',
            'location_id' => \App\Models\BusinessLocation::where('business_id', $business->id)->value('id'),
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
