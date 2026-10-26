<?php

namespace Tests\Feature\Website;

use App\Enums\Business\BusinessServiceStatus;
use App\Library\Website\WebsitePageStrategy;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteAsset;
use App\Models\WebsiteTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Event-type pages (weddings, corporate events, parties): planned from the saved event types, only when the owner described them
 * in their own words, at clean addresses, without competing with a booth page and without silently displacing a booth page.
 */
class WebsiteEventPagesPlanTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;
    use RefreshDatabase;

    private const DESCRIBED = [
        'Weddings' => 'Two booths side by side for receptions, set up before the first dance and run by an attendant.',
        'Corporate Events' => 'Branded overlays and lead capture for conferences, launches and holiday parties.',
        'Parties' => 'Birthdays, graduations and private celebrations, with props and instant sharing.',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    private function booths(object $business, array $names): void
    {
        foreach ($names as $i => $name) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'description' => "What the {$name} is and what it includes.", 'status' => BusinessServiceStatus::Active->value, 'sort_order' => $i]);
        }
    }

    /** @param array<string, ?string> $events name => description */
    private function events(object $business, array $events): void
    {
        $i = 0;
        foreach ($events as $name => $description) {
            BusinessService::create(['business_id' => $business->id, 'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name), 'kind' => BusinessService::KIND_EVENT, 'description' => $description, 'status' => BusinessServiceStatus::Active->value, 'sort_order' => 100 + $i++]);
        }
    }

    private function explain(object $business, int $galleryPhotos = 0, ?WebsiteTemplate $template = null): array
    {
        $website = Website::where('business_id', $business->id)->first() ?? $this->createWebsite($business);
        for ($i = 0; $i < $galleryPhotos; $i++) {
            WebsiteAsset::forceCreate(['website_id' => $website->id, 'disk' => 'public', 'path' => "images/e{$i}.png", 'mime_type' => 'image/png', 'size' => 1, 'width' => 1, 'height' => 1, 'content_hash' => str_repeat((string) $i, 64), 'purpose' => 'gallery']);
        }

        return app(WebsitePageStrategy::class)->planWithExplanation($business, $template ?? WebsiteTemplate::findActiveOrFail('photo_booth_modern'), $website);
    }

    private function eventPages(array $result): array
    {
        return array_values(array_filter($result['plan'], fn ($page) => $page['page_type'] === 'event_detail'));
    }

    public function test_described_event_types_get_their_own_pages_at_clean_addresses_in_the_owners_order(): void
    {
        [, $business] = $this->entitledTenant();
        $this->booths($business, ['Digital Photo Booth', 'Polaroid Photo Booth']);
        $this->events($business, self::DESCRIBED);

        $events = $this->eventPages($this->explain($business));

        $this->assertSame(['wedding-photo-booth-rental', 'corporate-photo-booth-rental', 'party-photo-booth-rental'], array_column($events, 'slug'));
        $this->assertSame(['Weddings', 'Corporate Events', 'Parties'], array_column($events, 'title'), 'The page title is the saved name; the heading and title tag are built from it.');
        $this->assertSame(['event:'], array_unique(array_map(fn ($e) => substr($e['page_key'], 0, 6), $events)));
        foreach ($events as $event) {
            $this->assertNotEmpty($event['entity']['service_uid']);
            $this->assertSame('event', $event['entity']['kind']);
            $this->assertNotEmpty($event['allowed_section_types']);
        }
    }

    public function test_an_event_type_without_the_owners_own_description_stays_a_card_and_the_owner_is_told_what_to_add(): void
    {
        [, $business] = $this->entitledTenant();
        $this->booths($business, ['Digital Photo Booth']);
        $this->events($business, [
            'Weddings' => self::DESCRIBED['Weddings'],
            'Birthdays' => null,
            'Graduations' => 'Capture the milestone with a photo experience that lets graduates, families and friends celebrate together.',
        ]);

        $result = $this->explain($business);
        $decisions = collect($result['decisions'])->where('type', 'event_detail')->keyBy('title');

        $this->assertSame(['wedding-photo-booth-rental'], array_column($this->eventPages($result), 'slug'));
        $this->assertTrue($decisions['Weddings']['included']);
        $this->assertFalse($decisions['Birthdays']['included']);
        $this->assertStringContainsString('You have not described Birthdays yet', $decisions['Birthdays']['reason']);
        $this->assertFalse($decisions['Graduations']['included']);
        $this->assertStringContainsString('example sentence the setup offered', $decisions['Graduations']['reason']);
        $this->assertStringContainsString('Describe in a few sentences', $decisions['Graduations']['reason']);
    }

    public function test_an_event_page_never_competes_with_a_booth_page_for_the_same_search(): void
    {
        [, $business] = $this->entitledTenant();
        $this->booths($business, ['Wedding Photo Booth', 'Digital Photo Booth']);
        $this->events($business, self::DESCRIBED);

        $result = $this->explain($business);
        $decisions = collect($result['decisions'])->where('type', 'event_detail')->keyBy('title');

        $this->assertFalse($decisions['Weddings']['included']);
        $this->assertStringContainsString('Your "Wedding Photo Booth" page already targets this search', $decisions['Weddings']['reason']);
        $this->assertTrue($decisions['Corporate Events']['included']);
        $this->assertContains('service-wedding-photo-booth', array_column($result['plan'], 'slug'), 'The booth page is untouched.');
    }

    public function test_event_pages_are_capped_and_the_cap_is_explained(): void
    {
        [, $business] = $this->entitledTenant();
        $this->booths($business, ['Digital Photo Booth']);
        $this->events($business, self::DESCRIBED + ['Brand Activations' => 'Branded experiences for launches.', 'Reunions' => 'Family reunions and anniversaries.']);

        $result = $this->explain($business);
        $decisions = collect($result['decisions'])->where('type', 'event_detail')->keyBy('title');

        $this->assertCount(WebsitePageStrategy::MAX_EVENT_PAGES, $this->eventPages($result));
        $this->assertFalse($decisions['Reunions']['included']);
        $this->assertStringContainsString('at most 4 event pages', $decisions['Reunions']['reason']);
    }

    public function test_a_full_page_budget_is_resolved_by_a_stated_rule_and_never_displaces_a_booth_page(): void
    {
        [, $business] = $this->entitledTenant();
        $this->booths($business, ['Open-Air Photo Booth', 'Mirror Photo Booth', '360 Photo Booth', 'Enclosed Photo Booth', 'Glam Photo Booth', 'Roaming Photo Booth']);
        $this->events($business, self::DESCRIBED + ['Brand Activations' => 'Branded experiences for launches.']);

        $result = $this->explain($business, 6);
        $slugs = array_column($result['plan'], 'slug');
        $decisions = collect($result['decisions'])->keyBy('key');

        $this->assertLessThanOrEqual(WebsitePageStrategy::MAX_TOTAL_PAGES, count($result['plan']));
        foreach (['service-open-air-photo-booth', 'service-mirror-photo-booth', 'service-360-photo-booth', 'service-enclosed-photo-booth', 'service-glam-photo-booth'] as $core) {
            $this->assertContains($core, $slugs, "{$core}: the first five booths are never displaced by an event page.");
        }
        $this->assertCount(WebsitePageStrategy::MAX_EVENT_PAGES, $this->eventPages($result));

        // Whatever the budget could not hold is named, with the order it was given out in.
        $left = collect($result['decisions'])->where('included', false)->whereIn('type', ['gallery', 'faq', 'backdrops'])->filter(fn ($d) => str_starts_with($d['reason'], 'Not planned: the 14-page limit'));
        $this->assertNotEmpty($left, 'With this much on the site the budget does leave something out.');
        foreach ($left as $decision) {
            $this->assertStringContainsString('event page', $decision['reason'], "{$decision['title']}: says the event pages were planned first.");
        }
        $this->assertSame('Event pages for the event types you described (up to 4, in your order)', $result['budget']['priority'][2]);
        $this->assertNotEmpty($decisions['gallery']['reason']);
    }

    public function test_a_template_seeded_before_event_pages_existed_still_plans_them_and_one_without_booth_pages_plans_none(): void
    {
        [, $business] = $this->entitledTenant();
        $this->booths($business, ['Digital Photo Booth']);
        $this->events($business, self::DESCRIBED);

        $legacy = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $legacy->page_manifest = ['pages' => array_values(array_filter($legacy->page_manifest['pages'], fn ($page) => $page['page_type'] !== 'event_detail'))];
        $this->assertCount(3, $this->eventPages($this->explain($business, 0, $legacy)), 'The booth-page section list is used, so no re-seed is needed.');

        $bare = WebsiteTemplate::findActiveOrFail('photo_booth_modern');
        $bare->page_manifest = ['pages' => array_values(array_filter($bare->page_manifest['pages'], fn ($page) => ! in_array($page['page_type'], ['event_detail', 'service_detail'], true)))];
        $this->assertSame([], $this->eventPages($this->explain($business, 0, $bare)));
    }
}
