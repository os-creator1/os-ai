<?php

namespace Tests\Feature\ExternalSite;

use App\Jobs\ExternalSite\CrawlExternalSite;
use App\Library\Acquisition\AcquisitionPurposeManager;
use App\Library\ExternalSite\ExternalSiteAuditRunner;
use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\ExternalSite\Fixtures\FixtureExternalSiteTransport;
use App\Library\ExternalSite\Fixtures\FixtureSite;
use App\Models\AcquisitionPurpose;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Acquisition\Support\InstallsEducationNiches;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The Website module for a Business that keeps its existing website: the first
 * choice, the external Overview / Audit / Pages / Settings, the absence of every
 * hosted-only control, re-crawl and URL change, and tenancy.
 */
class ExternalWebsiteHttpTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['external_site_audit.driver' => 'fake', 'external_site_audit.request_delay_ms' => 0]);
        $this->app->forgetInstance(ExternalSiteConfig::class);
        $this->app->forgetInstance(FixtureExternalSiteTransport::class);
    }

    private function url(string $name, Workspace $workspace, Business $business, array $extra = []): string
    {
        return route('customer.workspaces.businesses.website.'.$name, array_merge([$workspace->uid, $business->uid], $extra));
    }

    /** @return array{0: Business, 1: Workspace} a signed-in owner whose Business chose "use my existing website" and was crawled */
    private function crawledExternalBusiness(): array
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => 'studio-fixture.example'])
            ->assertRedirect($this->url('external.overview', $workspace, $business));

        $crawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        app(ExternalSiteAuditRunner::class)->run($crawl);

        return [$business->fresh(), $workspace];
    }

    public function test_choosing_the_existing_website_saves_the_address_through_the_business_and_starts_a_check(): void
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => 'Studio-Fixture.example/classes?x=1'])->assertRedirect();

        $business = $business->fresh();
        $this->assertSame('external', $business->website_mode);
        $this->assertSame('https://studio-fixture.example/', rtrim((string) $business->website_url, '/').'/', 'Only the site origin is kept; the address lives in businesses.website_url.');
        $this->assertSame('studio-fixture.example', $business->canonical_domain, 'The canonical domain stays consistent because the write used the Business update seam.');
        Queue::assertPushed(CrawlExternalSite::class, 1);
        $this->assertSame(1, ExternalSiteCrawl::query()->where('business_id', $business->id)->count());
    }

    public function test_a_refused_address_is_explained_and_nothing_is_stored_or_crawled(): void
    {
        Queue::fake();
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        DB::table('businesses')->where('id', $business->id)->update(['website_url' => null]);

        foreach (['http://127.0.0.1', 'localhost', 'http://[::1]/', '192.168.1.5', 'file:///etc/passwd', 'ftp://example.com', 'user:pass@example.com', ''] as $bad) {
            $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'external', 'website_url' => $bad])
                ->assertRedirect()->assertSessionHas('status', 'error');
        }

        $this->assertNull($business->fresh()->website_mode);
        Queue::assertNotPushed(CrawlExternalSite::class);
    }

    public function test_do_this_later_sets_nothing_up_and_the_choices_stay_available(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'none'])->assertRedirect($this->url('show', $workspace, $business));

        $this->get($this->url('show', $workspace, $business))->assertOk()->assertSee('data-role="chose-later"', false)->assertSee('Build my website')->assertSee('Connect existing website');
        $this->assertSame('none', $business->fresh()->website_mode);
    }

    public function test_the_external_overview_leads_with_what_to_fix_next(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $html = $this->get($this->url('show', $workspace, $business))->assertRedirect($this->url('external.overview', $workspace, $business));
        $html = $this->get($this->url('external.overview', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('External website', $html);
        $this->assertStringContainsString('What should you fix next?', $html);
        $this->assertStringContainsString('data-role="fix-next-cta"', $html);
        $this->assertStringContainsString('Review affected pages', $html);
        foreach (['Pages found', 'Critical issues', 'Search issues', 'Broken links', 'Search visibility'] as $label) {
            $this->assertStringContainsString($label, $html);
        }
        $this->assertStringContainsString('Search engines can find your site', $html);
        $this->assertStringContainsString('Last checked', $html);
        $this->assertStringContainsString('Check again now', $html);
        // The most severe issue is the unreachable page.
        $this->assertStringContainsString('Page could not be loaded', $html);
    }

    public function test_there_is_no_hosted_only_control_anywhere_in_the_external_module(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $forbidden = ['Publish', 'Change template', 'Website look', 'Connect domain', 'Connect a domain', 'Rebuild', 'Regenerate', 'Edit page', '>Fix<', 'Apply fix'];

        foreach (['external.overview', 'external.audit', 'external.pages', 'external.settings'] as $name) {
            $html = $this->get($this->url($name, $workspace, $business))->assertOk()->getContent();

            foreach ($forbidden as $word) {
                $this->assertStringNotContainsString($word, $html, "$name must not show [$word].");
            }

            $this->assertStringContainsString('data-role="external-nav"', $html);
            $this->assertSame(['overview', 'audit', 'pages', 'settings'], $this->navKeys($html));
        }
    }

    /** @return list<string> */
    private function navKeys(string $html): array
    {
        preg_match_all('/data-nav="([a-z]+)"/', $html, $m);

        return $m[1];
    }

    public function test_the_audit_lists_issues_with_affected_pages_open_links_and_how_to_fix(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $html = $this->get($this->url('external.audit', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('data-rule="duplicate_seo_title"', $html);
        $this->assertStringContainsString('data-rule="page_not_reachable"', $html);
        $this->assertStringContainsString('Show me how to fix this', $html);
        $this->assertStringContainsString('Open page', $html);
        $this->assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('MotionGrove cannot edit your website', $html);

        $filtered = $this->get($this->url('external.audit', $workspace, $business, ['rule' => 'h1_missing']))->assertOk()->getContent();
        $this->assertStringContainsString('data-rule="h1_missing"', $filtered);
        $this->assertStringNotContainsString('data-rule="duplicate_seo_title"', $filtered);
    }

    public function test_the_pages_screen_and_a_page_detail_show_state_findings_and_a_title_suggestion(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();

        $html = $this->get($this->url('external.pages', $workspace, $business))->assertOk()->getContent();
        $this->assertSame(7, substr_count($html, 'data-role="page-row"'));
        $this->assertStringContainsString('Hidden from search', $html, '/birthday is marked noindex');
        $this->assertStringContainsString('Could not load', $html, '/old-page is a 404');
        $this->assertStringContainsString('Can be found', $html);

        $crawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        $classes = $crawl->pages()->where('url', FixtureSite::BASE.'/classes')->firstOrFail();

        $detail = $this->get($this->url('external.page', $workspace, $business, ['pageId' => $classes->id]))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="copy-title"', $detail);
        $this->assertStringContainsString('Classes | ', $detail, 'A mechanical suggestion built from the page address and the business name.');
        $this->assertStringContainsString('Page title used on more than one page', $detail);
        $this->assertStringContainsString('Open page', $detail);
        $this->assertStringContainsString('Show me how to fix this', $detail);
    }

    public function test_recrawl_is_requested_without_a_url_and_is_throttled_and_deduplicated(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        Queue::fake();

        $this->post($this->url('external.crawl', $workspace, $business), ['website_url' => 'http://169.254.169.254/'])
            ->assertRedirect()->assertSessionHas('status', 'info');
        Queue::assertNotPushed(CrawlExternalSite::class);

        DB::table('external_site_crawls')->where('business_id', $business->id)->update(['created_at' => now()->subHour()]);
        $this->post($this->url('external.crawl', $workspace, $business))->assertRedirect()->assertSessionHas('status', 'success');
        Queue::assertPushed(CrawlExternalSite::class, 1);
        $this->assertStringContainsString('www', 'www');

        // The request never named a URL: the new crawl is of the stored address only.
        $this->assertSame('https://studio-fixture.example/', ExternalSiteCrawl::query()->where('business_id', $business->id)->latest('id')->value('start_url'));

        $this->post($this->url('external.crawl', $workspace, $business))->assertRedirect()->assertSessionHas('status', 'info');
        Queue::assertPushed(CrawlExternalSite::class, 1);
    }

    public function test_changing_the_address_starts_a_new_check_and_keeps_the_external_mode(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        Queue::fake();
        DB::table('external_site_crawls')->where('business_id', $business->id)->update(['created_at' => now()->subHour()]);

        $this->post($this->url('external.settings.update', $workspace, $business), ['website_url' => 'www.studio-fixture.example'])->assertRedirect($this->url('external.settings', $workspace, $business));

        $business = $business->fresh();
        $this->assertSame('external', $business->website_mode);
        $this->assertStringContainsString('studio-fixture.example', (string) $business->website_url);
        $this->assertGreaterThanOrEqual(1, ExternalSiteCrawl::query()->where('business_id', $business->id)->where('trigger', 'url_change')->count());

        $this->post($this->url('external.settings.update', $workspace, $business), ['website_url' => 'http://localhost/'])->assertSessionHas('status', 'error');
        $this->assertStringContainsString('studio-fixture.example', (string) $business->fresh()->website_url);
    }

    public function test_a_business_that_builds_with_motiongrove_never_sees_the_external_screens(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'hosted']);

        foreach (['external.overview', 'external.audit', 'external.pages', 'external.settings'] as $name) {
            $this->get($this->url($name, $workspace, $business))->assertRedirect($this->url('show', $workspace, $business));
        }
    }

    public function test_another_business_cannot_read_this_businesss_external_audit(): void
    {
        [$business, $workspace] = $this->crawledExternalBusiness();
        $crawl = ExternalSiteCrawl::query()->where('business_id', $business->id)->firstOrFail();
        $page = $crawl->pages()->firstOrFail();

        [$otherCustomer, $other, $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($otherCustomer);
        $this->post($this->url('mode.choose', $otherWorkspace, $other), ['mode' => 'external', 'website_url' => 'studio-fixture.example']);

        // My page id through THEIR business resolves to nothing; my business through their session is a 404.
        $this->get($this->url('external.page', $otherWorkspace, $other, ['pageId' => $page->id]))->assertNotFound();
        $this->get($this->url('external.overview', $workspace, $business))->assertNotFound();
    }

    public function test_a_core_style_member_without_the_website_capability_is_refused(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer, []);

        $this->get($this->url('external.overview', $workspace, $business))->assertStatus(401);
        $this->post($this->url('mode.choose', $workspace, $business), ['mode' => 'none'])->assertStatus(401);
    }

    public function test_a_hosted_business_with_an_existing_website_is_unchanged(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->createWebsite($business);

        // No stored mode, but a Website record exists: it is hosted, with no choice screen and no redirect.
        $response = $this->get($this->url('show', $workspace, $business));
        $this->assertNotSame(404, $response->getStatusCode());
        $response->assertDontSee('Use my existing website');
    }
}
