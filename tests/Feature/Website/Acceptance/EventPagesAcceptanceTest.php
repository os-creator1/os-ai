<?php

namespace Tests\Feature\Website\Acceptance;

use App\Enums\Website\WebsiteDomainStatus;
use App\Library\Seo\Content\ArticleCannibalizationGuard;
use App\Library\Seo\Content\ArticleSiteInventory;
use App\Library\Seo\Content\ArticleSupportPageResolver;
use App\Library\Website\Seo\WebsiteSeoReview;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsitePublisher;
use App\Library\Website\WebsiteSearchVisibility;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * Event-specific commercial pages, benchmarked on the Transparent Photo Booths structure (wedding, corporate and party photo
 * booth rental, plus the Digital and Polaroid booths) in a disposable site built by the real guided generator, published to a
 * customer domain and read over HTTP. Events the owner did not describe in their own words get no page.
 */
class EventPagesAcceptanceTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;
    use RefreshDatabase;

    private const DOMAIN = 'event-pages.test';

    private const WEDDING = 'Two booths side by side for receptions, set up before the first dance and run by an attendant. Guests get a private online gallery afterwards.';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();

        $ai = \Mockery::mock(WebsiteAiGenerationClient::class);
        $ai->shouldNotReceive('complete');
        $ai->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $ai->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $ai);
    }

    private function publishedSite(): Website
    {
        $this->tenant(['name' => 'Glow Booths'], ['city' => 'Spokane', 'region' => 'WA']);
        $digital = $this->uploadPhoto('digital');
        $polaroid = $this->uploadPhoto('polaroid');

        $this->completeGuidedSetup([
            'base_location' => fn () => $this->continueStep('base_location', ['value' => ['city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US']]),
            'booth_services' => fn () => $this->continueStep('booth_services', ['items' => [
                ['key' => 'k-digital', 'name' => 'Digital Photo Booth', 'description' => 'A touch-screen booth with unlimited captures and instant sharing by text, email or QR code.', 'visible' => '1', 'image_path' => $digital],
                ['key' => 'k-polaroid', 'name' => 'Polaroid Photo Booth', 'description' => 'An instant-print booth that fits on a table or a wall and hands guests a real Polaroid.', 'visible' => '1', 'image_path' => $polaroid],
            ]]),
            'event_types' => fn () => $this->continueStep('event_types', ['items' => [
                ['key' => 'e-w', 'name' => 'Weddings', 'description' => self::WEDDING, 'visible' => '1'],
                ['key' => 'e-c', 'name' => 'Corporate Events', 'description' => 'Branded overlays, lead capture and instant sharing for conferences, product launches and holiday parties.', 'visible' => '1'],
                ['key' => 'e-p', 'name' => 'Parties', 'description' => 'Birthdays, graduations and private celebrations, with a prop box and a flat rate for the whole booking.', 'visible' => '1'],
                // Still the setup's example sentence: not something the owner confirmed.
                ['key' => 'e-b', 'name' => 'Baby Showers', 'description' => 'Celebrate the new arrival with a photo experience everyone at the shower can enjoy.', 'preset' => 'baby_showers', 'visible' => '1'],
            ]]),
            'faq_items' => fn () => $this->continueStep('faq_items', ['items' => [
                ['question' => 'Can the booth run during a wedding reception?', 'answer' => 'Yes. We set up before the first dance and the attendant stays until the end of your booking.'],
                ['question' => 'How much space do you need?', 'answer' => 'About ten feet by ten feet and one outlet.'],
            ]]),
        ]);
        $this->uploadGalleryPhotos(6);
        Website::where('business_id', $this->business->id)->update(['template_key' => 'photo_booth_modern']);

        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();

        app(WebsiteSearchVisibility::class)->release($website);
        app(WebsitePublisher::class)->publish($website->fresh(), $this->platformAdminId());
        $website->domains()->create(['domain' => self::DOMAIN, 'is_primary' => true, 'status' => WebsiteDomainStatus::Active, 'verification_token' => 't', 'verified_at' => now(), 'activated_at' => now()]);

        return $website->fresh();
    }

    private function html(string $path): string
    {
        return $this->get('http://' . self::DOMAIN . $path)->assertOk()->getContent();
    }

    private function mainLinks(string $html): array
    {
        preg_match('#<main.*?</main>#s', $html, $main);
        preg_match_all('#<a [^>]*href="([^"]+)"#', $main[0] ?? '', $links);

        return $links[1];
    }

    private function jsonLd(string $html, string $type): ?array
    {
        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
        foreach ($matches[1] as $json) {
            $decoded = json_decode($json, true);
            foreach (isset($decoded['@graph']) ? $decoded['@graph'] : [$decoded] as $item) {
                if (($item['@type'] ?? null) === $type) {
                    return $item;
                }
            }
        }

        return null;
    }

    public function test_described_event_types_become_distinct_commercial_pages_and_the_example_one_does_not(): void
    {
        $website = $this->publishedSite();
        $pages = $website->pages()->get()->keyBy('slug');

        foreach (['wedding-photo-booth-rental', 'corporate-photo-booth-rental', 'party-photo-booth-rental'] as $slug) {
            $this->assertTrue($pages->has($slug), "{$slug} is generated.");
            $this->assertSame('event', $pages[$slug]->page_role);
            $this->assertNotEmpty($pages[$slug]->page_intent['service_uid']);
        }
        $this->assertFalse($pages->has('baby-shower-photo-booth-rental'), 'An unchanged example sentence earns no page.');
        $this->assertSame(['service', 'service'], array_values($pages->where('page_role', 'service')->pluck('page_role')->all()), 'The two booths keep their own pages.');
        $this->assertLessThanOrEqual(14, $pages->count());
        $this->assertSame(array_values($pages->pluck('slug')->all()), array_values(array_unique($pages->pluck('slug')->all())), 'No duplicate addresses.');

        // Title, H1, description and canonical of each page.
        $expect = ['wedding-photo-booth-rental' => 'Wedding', 'corporate-photo-booth-rental' => 'Corporate', 'party-photo-booth-rental' => 'Party'];
        $descriptions = [];
        foreach ($expect as $slug => $label) {
            $html = $this->html('/' . $slug);
            preg_match('#<title>(.*?)</title>#s', $html, $title);
            $this->assertSame("{$label} Photo Booth Rental in Spokane | Glow Booths", html_entity_decode($title[1]), "{$slug}: title");
            preg_match_all('#<h1[^>]*>(.*?)</h1>#s', $html, $h1);
            $this->assertCount(1, $h1[1], "{$slug}: one H1");
            $this->assertSame("{$label} photo booth rental in Spokane", trim(strip_tags($h1[1][0])));
            preg_match('#<meta name="description" content="([^"]*)"#', $html, $d);
            $description = html_entity_decode($d[1] ?? '');
            $this->assertMatchesRegularExpression('/[.!?]$/', $description);
            $this->assertStringContainsString("<link rel=\"canonical\" href=\"https://" . self::DOMAIN . "/{$slug}\">", $html);
            $descriptions[] = $description;
        }
        $this->assertCount(3, array_unique($descriptions), 'Differentiated descriptions.');
        $this->assertStringContainsString('Two booths side by side for receptions', $descriptions[0], 'Built from the owner\'s own wording, not boilerplate.');

        // Each page says only what the owner said: their wording, their booths, their own matching FAQ answer.
        $wedding = $this->html('/wedding-photo-booth-rental');
        $this->assertStringContainsString('Two booths side by side for receptions', $wedding);
        $this->assertStringContainsString('Can the booth run during a wedding reception?', $wedding);
        $this->assertStringNotContainsString('How much space do you need?', $wedding, 'An answer that is not about weddings is not shown as if it were.');
        $this->assertStringNotContainsString('Can the booth run during a wedding reception?', $this->html('/corporate-photo-booth-rental'));
        foreach (['Digital Photo Booth', 'Polaroid Photo Booth'] as $booth) {
            $this->assertStringContainsString($booth, $wedding);
        }
        foreach (['reviews', 'award', 'years of experience', 'venues we'] as $invented) {
            $this->assertStringNotContainsStringIgnoringCase($invented, $wedding);
        }
    }

    public function test_the_pages_are_linked_into_the_site_the_sitemap_and_the_structured_data(): void
    {
        $website = $this->publishedSite();
        $home = $this->html('/');
        $mainLinks = $this->mainLinks($home);

        // Home -> events -> booths, packages and each other.
        foreach (['wedding', 'corporate', 'party'] as $label) {
            $this->assertContains('https://' . self::DOMAIN . "/{$label}-photo-booth-rental", $mainLinks, "The home page links to the {$label} page from its content.");
        }
        $this->assertStringNotContainsString('baby-shower', implode(' ', $mainLinks));
        $links = $this->mainLinks($this->html('/wedding-photo-booth-rental'));
        foreach ($website->pages()->where('page_role', 'service')->pluck('slug') as $boothSlug) {
            $this->assertContains('https://' . self::DOMAIN . '/' . $boothSlug, $links, 'An event page links to the booths.');
        }
        $this->assertContains('https://' . self::DOMAIN . '/corporate-photo-booth-rental', $links, 'Other occasions are linked.');
        $this->assertContains('https://' . self::DOMAIN . '/packages', $links, 'The way on to packages.');

        // Navigation: under Services in the header and in the footer column.
        $this->assertStringContainsString('href="https://' . self::DOMAIN . '/wedding-photo-booth-rental"', preg_replace('#<main.*?</main>#s', '', $home), 'Header/footer navigation carries the event pages.');

        // Sitemap: every page, with canonical customer-domain URLs.
        $sitemap = $this->get('http://' . self::DOMAIN . '/sitemap.xml')->assertOk()->getContent();
        preg_match_all('#<loc>(.*?)</loc>#', $sitemap, $locs);
        foreach (['wedding', 'corporate', 'party'] as $label) {
            $this->assertContains('https://' . self::DOMAIN . "/{$label}-photo-booth-rental", $locs[1]);
        }
        $this->assertCount($website->pages()->count(), $locs[1]);

        // Structured data: the breadcrumb runs Home > Services > the page; one stable business entity.
        $page = $this->html('/wedding-photo-booth-rental');
        $breadcrumb = $this->jsonLd($page, 'BreadcrumbList');
        $this->assertSame(['Home', 'Services', 'Weddings'], array_column($breadcrumb['itemListElement'], 'name'));
        $this->assertSame('https://' . self::DOMAIN . '/#business', $this->jsonLd($page, 'LocalBusiness')['@id']);
    }

    public function test_the_page_intent_map_and_the_blog_treat_them_as_event_pages_not_booths_or_places(): void
    {
        $website = $this->publishedSite();
        $inventory = collect(app(ArticleSiteInventory::class)->pages($this->business))->keyBy('slug');

        $this->assertSame('event', $inventory['wedding-photo-booth-rental']['kind']);
        $this->assertTrue($inventory['wedding-photo-booth-rental']['money']);
        $this->assertSame('service', $inventory->first(fn ($p) => str_starts_with((string) $p['slug'], 'service-'))['kind']);
        $this->assertSame([], $inventory->filter(fn ($p) => $p['kind'] === 'location')->all(), 'No event page is mistaken for a place page.');

        // An article for the same search is a conflict; a different question supports the page; the resolver finds the page.
        $guard = app(ArticleCannibalizationGuard::class);
        $this->assertTrue($guard->check($this->business, ['wedding photo booth rental spokane'])->strong());
        $this->assertFalse($guard->check($this->business, ['how much does a wedding photo booth cost'])->strong());
        $support = app(ArticleSupportPageResolver::class)->resolve(array_values($inventory->all()), null, 'wedding photo booth timeline checklist');
        $this->assertSame('wedding-photo-booth-rental', $support['slug']);

        // The publish-time review: the three event pages are different enough and none competes with another.
        $review = collect(app(WebsiteSeoReview::class)->review($website, $website->pages()->get(), $this->business))->keyBy('key');
        $this->assertArrayNotHasKey('seo_duplicate_intent', $review->all());
        $this->assertArrayNotHasKey('seo_repeated_copy', $review->all());
        $this->assertArrayNotHasKey('seo_contextual_links', $review->all());
    }

    public function test_an_already_published_site_is_untouched_by_new_event_types(): void
    {
        $website = $this->publishedSite();
        $frozen = json_encode(WebsiteRevision::find($website->published_revision_id)->snapshot);
        $home = $this->html('/');
        $sitemap = $this->get('http://' . self::DOMAIN . '/sitemap.xml')->getContent();

        // The owner describes another event type afterwards: nothing changes until they rebuild and publish again.
        BusinessService::create(['business_id' => $this->business->id, 'name' => 'Proms', 'slug' => 'proms', 'kind' => BusinessService::KIND_EVENT, 'description' => 'Prom nights with a flat rate.', 'status' => 'active', 'sort_order' => 200]);

        $this->assertSame($frozen, json_encode(WebsiteRevision::find($website->published_revision_id)->snapshot));
        $this->assertSame($home, $this->html('/'));
        $this->assertSame($sitemap, $this->get('http://' . self::DOMAIN . '/sitemap.xml')->getContent());
        $this->get('http://' . self::DOMAIN . '/prom-photo-booth-rental')->assertNotFound();
    }
}
