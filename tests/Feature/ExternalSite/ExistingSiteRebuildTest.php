<?php

namespace Tests\Feature\ExternalSite;

use App\Enums\Website\WebsiteMode;
use App\Library\ExternalSite\Audit\ExternalAuditCatalog;
use App\Library\ExternalSite\Rebuild\ExternalRebuildPlanner;
use App\Models\Business;
use App\Models\ExternalRebuildPlan;
use App\Models\ExternalSiteCrawl;
use App\Models\ExternalSitePage;
use App\Models\Website;
use App\Models\WebsitePage;
use App\Models\Workspace;
use Database\Seeders\WebsiteTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * Existing website -> MotionGrove-hosted website. The plan is built from a crawl that already exists (no network, no AI),
 * the owner confirms it, and nothing ever publishes, switches a domain, changes the live address or deletes audit history.
 */
class ExistingSiteRebuildTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private const SITE = 'https://photobooth-fixture.example';

    /** The old URL inventory: the plan must use THESE paths, never invented ones. */
    private const INVENTORY = [
        ['/', 'Photo Booth Rental Toronto | Fixture Booths', 'Photo booth rentals across Toronto and the GTA.'],
        ['/corporate-photo-booth-rental-toronto/', 'Corporate Photo Booth Rental Toronto', 'Branded photo booths for company events in Toronto.'],
        ['/wedding-photo-booth-rental-toronto/', 'Wedding Photo Booth Rental Toronto', 'The best photo booth, 5 stars, 10+ years of weddings.'],
        ['/party-photo-booth-rental-toronto', 'Party Photo Booth Rental Toronto', 'Birthday and party photo booths.'],
        ['/photo-booth-rental-etobicoke/', 'Photo Booth Rental Etobicoke', 'Photo booths serving Etobicoke events.'],
        ['/gallery/', 'Gallery and Backdrops', 'See our gallery.'],
        ['/contact-us/', 'Check Availability', 'Ask about your date.'],
        ['/privacy-policy/', 'Privacy Policy', 'How we handle your information.'],
        ['/old-landing-page/', 'Old Landing Page', 'Something else.'],
    ];

    /** @return array{0: Business, 1: Workspace, 2: ExternalSiteCrawl} */
    private function tenantWithCrawl(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        DB::table('businesses')->where('id', $business->id)->update(['website_url' => self::SITE.'/', 'website_mode' => 'external']);
        $business = $business->fresh();
        $this->authenticateAsCustomer($customer);

        $crawl = new ExternalSiteCrawl();
        $crawl->forceFill([
            'uid' => (string) Str::uuid(), 'business_id' => $business->id, 'start_url' => self::SITE.'/', 'host' => 'photobooth-fixture.example',
            'status' => ExternalSiteCrawl::COMPLETED, 'rule_set_version' => ExternalAuditCatalog::VERSION, 'trigger' => 'manual', 'pages_audited' => count(self::INVENTORY),
        ])->save();

        foreach (self::INVENTORY as [$path, $title, $meta]) {
            $this->page($crawl, $business, $path, $title, $meta);
        }
        // Not plannable: a 404, a noindex page and a parameter variant.
        $this->page($crawl, $business, '/gone/', 'Gone', '', 404);
        $this->page($crawl, $business, '/hidden/', 'Hidden', '', 200, true);
        $this->page($crawl, $business, '/gallery/?page=2', 'Gallery 2', '');

        return [$business, $workspace, $crawl];
    }

    private function page(ExternalSiteCrawl $crawl, Business $business, string $path, string $title, string $meta, int $status = 200, bool $noindex = false): ExternalSitePage
    {
        $url = self::SITE.$path;
        $page = new ExternalSitePage();
        $page->forceFill([
            'crawl_id' => $crawl->id, 'business_id' => $business->id, 'url_hash' => sha1($url.$status), 'url' => $url, 'kind' => 'html', 'http_status' => $status,
            'title' => $title, 'meta_description' => $meta, 'noindex' => $noindex, 'fetched_at' => now(),
        ])->save();

        return $page;
    }

    private function u(string $name, Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.website.existing.'.$name, [$workspace->uid, $business->uid]);
    }

    private function rows(Business $business): array
    {
        return collect(ExternalRebuildPlan::where('business_id', $business->id)->firstOrFail()->pages)->keyBy('path')->all();
    }

    public function test_planner_classifies_the_existing_inventory_and_leaves_facts_unconfirmed(): void
    {
        [$business, , $crawl] = $this->tenantWithCrawl();

        $built = (new ExternalRebuildPlanner())->build(ExternalSitePage::where('crawl_id', $crawl->id)->get());
        $rows = collect($built['pages'])->keyBy('path');

        $this->assertSame(count(self::INVENTORY), count($built['pages']));
        $this->assertSame(3, $built['excluded']);
        $this->assertSame(['home', 'corporate', 'wedding', 'party', 'area', 'gallery', 'contact', 'legal', 'other'], collect(self::INVENTORY)->map(fn ($i) => $rows[trim($i[0], '/')]['role'])->all());
        $this->assertSame('Etobicoke', $rows['photo-booth-rental-etobicoke']['area']);
        // Only the old site's own paths appear.
        $this->assertEqualsCanonicalizing(collect(self::INVENTORY)->map(fn ($i) => trim($i[0], '/'))->all(), $rows->keys()->all());
        // Facts are candidates, never confirmed, and a claim-bearing description is flagged, not trusted.
        $this->assertSame([], array_filter($built['facts'], fn ($f) => $f['confirmed']));
        $this->assertNotEmpty($rows['wedding-photo-booth-rental-toronto']['claim_flags']);
        $this->assertSame([], $rows['corporate-photo-booth-rental-toronto']['claim_flags']);
    }

    public function test_preparing_and_confirming_a_plan_touches_nothing_live_and_makes_no_request(): void
    {
        Http::fake();
        [$business, $workspace, $crawl] = $this->tenantWithCrawl();

        $this->post($this->u('prepare', $workspace, $business))->assertSessionHas('status', 'success');
        $plan = ExternalRebuildPlan::where('business_id', $business->id)->firstOrFail();
        $this->assertSame('draft', $plan->status);
        $this->assertSame($crawl->id, (int) $plan->crawl_id);

        $this->get($this->u('show', $workspace, $business))->assertOk()->assertSee('stays live and untouched')->assertSee('/corporate-photo-booth-rental-toronto', false);

        $this->post($this->u('confirm', $workspace, $business), ['facts' => ['area:etobicoke' => '1'], 'pages' => []])->assertSessionHas('status', 'success');

        $plan->refresh();
        $this->assertSame('confirmed', $plan->status);
        $this->assertTrue(collect($plan->facts)->firstWhere('key', 'area:etobicoke')['confirmed']);
        $this->assertFalse(collect($plan->facts)->firstWhere('key', 'event:corporate')['confirmed']);
        Http::assertNothingSent();
        $this->assertSame(self::SITE.'/', Business::find($business->id)->website_url);
        $this->assertSame(0, Website::where('business_id', $business->id)->count());
        $this->assertSame(1, ExternalSiteCrawl::where('business_id', $business->id)->count());
    }

    public function test_starting_requires_a_confirmed_plan_and_only_switches_the_mode_never_the_address(): void
    {
        [$business, $workspace] = $this->tenantWithCrawl();
        $this->post($this->u('prepare', $workspace, $business));

        $this->post($this->u('start', $workspace, $business))->assertSessionHas('status', 'error');
        $this->assertSame(WebsiteMode::External->value, Business::find($business->id)->getRawOriginal('website_mode'));

        $this->post($this->u('confirm', $workspace, $business), []);
        $this->post($this->u('start', $workspace, $business))->assertRedirect(route('customer.workspaces.businesses.website.setup.start', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $fresh = Business::find($business->id);
        $this->assertSame(WebsiteMode::Hosted->value, $fresh->getRawOriginal('website_mode'));
        $this->assertSame(self::SITE.'/', $fresh->website_url, 'The live address is never changed.');
        $this->assertSame(1, ExternalSiteCrawl::where('business_id', $business->id)->count(), 'Audit history is kept.');
        $this->assertSame('started', ExternalRebuildPlan::where('business_id', $business->id)->firstOrFail()->status);
    }

    public function test_the_redirect_plan_preserves_matches_and_never_invents_a_target(): void
    {
        [$business, $workspace] = $this->tenantWithCrawl();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->post($this->u('prepare', $workspace, $business));
        $this->post($this->u('confirm', $workspace, $business), ['facts' => [], 'pages' => $this->includeAll($business)]);

        // A draft whose generated slugs differ from the old paths.
        $website = $this->draftWebsite($business, [
            ['', 'Home', true], ['gallery', 'Gallery', false], ['photo-booth-contact', 'Contact', false],
            ['service-corporate-events', 'Corporate Events', false], ['service-weddings-wedding', 'Wedding Photo Booth', false],
            ['serving-etobicoke', 'Serving Etobicoke', false], ['services', 'Services', false],
        ]);
        $website->forceFill(['custom_redirects' => ['legacy-owner-redirect' => 'services']])->save();

        $this->post($this->u('apply', $workspace, $business))->assertSessionHas('status', 'success');
        $website->refresh();

        $this->assertEquals([
            'legacy-owner-redirect' => 'services',
            'corporate-photo-booth-rental-toronto' => 'service-corporate-events',
            'wedding-photo-booth-rental-toronto' => 'service-weddings-wedding',
            'photo-booth-rental-etobicoke' => 'serving-etobicoke',
            'contact-us' => 'photo-booth-contact',
        ], $website->custom_redirects, 'Matches redirect; gallery is a kept URL; legal, party and "other" are left for the owner; the owner\'s own redirect survives.');
        $this->assertNull($website->published_revision_id, 'Applying a plan never publishes.');

        $row = collect(ExternalRebuildPlan::where('business_id', $business->id)->firstOrFail()->pages)->pluck('path')->all();
        $this->assertContains('privacy-policy', $row);
        $this->assertArrayNotHasKey('privacy-policy', $website->custom_redirects);
        $this->assertArrayNotHasKey('party-photo-booth-rental-toronto', $website->custom_redirects);
        $this->assertArrayNotHasKey('gallery', $website->custom_redirects);
    }

    public function test_a_claim_bearing_description_is_never_copied_and_a_safe_one_is_only_copied_when_chosen(): void
    {
        [$business, $workspace] = $this->tenantWithCrawl();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->post($this->u('prepare', $workspace, $business));

        $pages = $this->includeAll($business);
        foreach ($pages as $key => &$p) {
            $p['keep_seo'] = '1';
        }
        unset($p);
        $this->post($this->u('confirm', $workspace, $business), ['pages' => $pages]);

        $website = $this->draftWebsite($business, [['', 'Home', true], ['service-corporate-events', 'Corporate Events', false], ['service-weddings-wedding', 'Wedding Photo Booth', false]]);
        $this->post($this->u('apply', $workspace, $business));

        $corporate = WebsitePage::where('website_id', $website->id)->where('slug', 'service-corporate-events')->firstOrFail();
        $wedding = WebsitePage::where('website_id', $website->id)->where('slug', 'service-weddings-wedding')->firstOrFail();
        $this->assertSame('Corporate Photo Booth Rental Toronto', $corporate->seo_title);
        $this->assertNotSame('Wedding Photo Booth Rental Toronto', $wedding->seo_title);
        $this->assertStringNotContainsString('5 stars', (string) $wedding->meta_description);
    }

    public function test_an_old_path_that_is_another_drafts_address_is_a_conflict_not_a_redirect(): void
    {
        [$business, $workspace] = $this->tenantWithCrawl();
        $this->seed(WebsiteTemplateSeeder::class);
        $this->post($this->u('prepare', $workspace, $business));
        $this->post($this->u('confirm', $workspace, $business), ['pages' => $this->includeAll($business, ['old-landing-page' => 'services'])]);

        // The draft already uses the old landing page's path for something else.
        $website = $this->draftWebsite($business, [['', 'Home', true], ['services', 'Services', false], ['old-landing-page', 'Something new', false]]);
        $this->post($this->u('apply', $workspace, $business));

        $this->assertArrayNotHasKey('old-landing-page', (array) $website->fresh()->custom_redirects);
    }

    public function test_the_plan_is_tenant_isolated(): void
    {
        [$business, $workspace] = $this->tenantWithCrawl();
        $this->post($this->u('prepare', $workspace, $business));
        $this->post($this->u('confirm', $workspace, $business), []);

        // A second, unrelated owner.
        [$otherCustomer, $otherBusiness, $otherWorkspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($otherCustomer);

        $this->get($this->u('show', $otherWorkspace, $otherBusiness))->assertOk()->assertDontSee('/corporate-photo-booth-rental-toronto', false);
        $this->assertNull(ExternalRebuildPlan::where('business_id', $otherBusiness->id)->first());

        // Reaching into the first tenant's business through the second tenant's workspace is refused.
        foreach (['show' => 'get', 'prepare' => 'post', 'confirm' => 'post', 'start' => 'post', 'apply' => 'post'] as $name => $verb) {
            $url = route('customer.workspaces.businesses.website.existing.'.$name, [$otherWorkspace->uid, $business->uid]);
            $this->{$verb}($url)->assertStatus(404);
        }
        $this->assertSame('confirmed', ExternalRebuildPlan::where('business_id', $business->id)->firstOrFail()->status);
        $this->assertSame(WebsiteMode::External->value, Business::find($business->id)->getRawOriginal('website_mode'));
    }

    public function test_preparing_without_a_finished_audit_fails_safely(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);

        $this->post($this->u('prepare', $workspace, $business))->assertSessionHas('status', 'error');
        $this->assertNull(ExternalRebuildPlan::where('business_id', $business->id)->first());
        $this->post($this->u('start', $workspace, $business))->assertSessionHas('status', 'error');
        $this->post($this->u('apply', $workspace, $business))->assertSessionHas('status', 'error');
    }

    /** @return array<string, array<string, string>> */
    private function includeAll(Business $business, array $targets = []): array
    {
        $out = [];
        foreach ((array) ExternalRebuildPlan::where('business_id', $business->id)->firstOrFail()->pages as $page) {
            $out[$page['key']] = ['include' => '1', 'target' => $targets[$page['path']] ?? 'auto'];
        }

        return $out;
    }

    /** @param list<array{0: string, 1: string, 2: bool}> $pages [slug, title, is_home] */
    private function draftWebsite(Business $business, array $pages): Website
    {
        DB::table('businesses')->where('id', $business->id)->update(['website_mode' => 'hosted']);
        $this->post(route('customer.workspaces.businesses.website.store', [$business->workspace->uid, $business->uid]), ['template_key' => 'photo_booth_modern']);
        $website = Website::where('business_id', $business->id)->firstOrFail();
        // Start from exactly the pages this test names, not the starter draft's.
        WebsitePage::where('website_id', $website->id)->delete();

        foreach ($pages as $i => [$slug, $title, $home]) {
            WebsitePage::create(['website_id' => $website->id, 'title' => $title, 'slug' => $slug === '' ? null : $slug, 'is_home' => $home, 'sections' => [], 'sort_order' => $i]);
        }

        return $website;
    }
}
