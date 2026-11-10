<?php

namespace Tests\Feature\Website;

use App\Library\Website\WebsiteHealthChecker;
use App\Models\BusinessLocation;
use App\Models\BusinessService;
use App\Models\WebsiteAsset;
use App\Models\WebsitePage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\TestCase;

/**
 * The review half of the publish-time quality gate: owner-judgement warnings that name the page, never a block, reported
 * only when the problem is actually present in the draft.
 */
class WebsiteSeoReviewTest extends TestCase
{
    use CreatesWebsiteFixtures;
    use RefreshDatabase;

    private $business;

    private $website;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->business] = $this->entitledTenant();
        (new BusinessLocation())->forceFill([
            'business_id' => $this->business->id, 'is_primary' => true, 'service_mode' => 'service_area', 'name' => 'Main', 'city' => 'Spokane', 'region' => 'WA', 'country_code' => 'US',
            'service_area_cities' => ['Cheney, WA', 'Liberty Lake, WA'],
        ])->save();
        BusinessService::create(['business_id' => $this->business->id, 'name' => 'Digital Photo Booth', 'slug' => 'digital', 'status' => 'active', 'sort_order' => 1]);
        $this->website = $this->createWebsite($this->business);
    }

    private function page(string $slug, string $title, array $sections, array $attributes = []): WebsitePage
    {
        return WebsitePage::create(array_merge([
            'website_id' => $this->website->id, 'title' => $title, 'slug' => $slug, 'is_home' => false, 'sections' => $sections,
            'seo_title' => $title, 'meta_description' => 'A complete sentence about ' . $title . '.', 'noindex' => false, 'sort_order' => 1,
        ], $attributes));
    }

    private function text(string $heading, string $body): array
    {
        return ['type' => 'text', 'data' => ['heading' => $heading, 'body' => $body]];
    }

    /** @return array<string, array<string, mixed>> the review entries by key */
    private function review(): array
    {
        $checks = app(WebsiteHealthChecker::class)->check($this->website->fresh(), ['pages' => '/p', 'photos' => '/ph'])['checks'];

        return collect($checks)->filter(fn ($check) => str_starts_with($check['key'], 'seo_'))->keyBy('key')->all();
    }

    public function test_a_clean_site_has_no_search_review_warnings_except_missing_proof(): void
    {
        $this->homePage($this->website, ['sections' => [$this->section('hero'), ['type' => 'gallery', 'data' => ['heading' => 'Photos', 'items' => [['image' => 'x']]]]]]);

        $this->assertSame([], $this->review());
    }

    public function test_two_pages_for_the_same_place_and_a_page_for_the_base_city_are_named(): void
    {
        $this->homePage($this->website, ['sections' => [$this->section('hero'), ['type' => 'testimonials', 'data' => ['items' => []]]]]);
        $this->page('serving-cheney', 'Photo Booth Rental in Cheney', [$this->text('Cheney', 'Local words one.')], ['page_role' => 'location', 'page_intent' => ['place' => 'Cheney']]);
        $this->page('cheney-photo-booths', 'Cheney Photo Booths', [$this->text('Cheney', 'Other local words.')], ['page_role' => 'location', 'page_intent' => ['place' => 'Cheney']]);
        $this->page('serving-spokane', 'Photo Booth Rental in Spokane', [$this->text('Spokane', 'Words.')], ['page_role' => 'location', 'page_intent' => ['place' => 'Spokane']]);

        $items = $this->review()['seo_duplicate_intent']['items'];

        $this->assertContains('Photo Booth Rental in Cheney and Cheney Photo Booths target the same search', $items);
        $this->assertContains('Photo Booth Rental in Spokane targets Spokane, which your home page already targets', $items);
        $this->assertSame('warn', $this->review()['seo_duplicate_intent']['status'], 'A warning, never a block.');
        $this->assertSame(['label' => 'Review pages', 'url' => '/p'], $this->review()['seo_duplicate_intent']['action']);
    }

    public function test_place_pages_that_only_swap_the_city_name_are_reported_as_repeating_each_other(): void
    {
        $this->homePage($this->website, ['sections' => [$this->section('hero'), ['type' => 'gallery', 'data' => ['items' => [['image' => 'x']]]]]]);
        $body = fn (string $city) => "We bring our photo booths to events in {$city}. Choose the booth that fits your guests, your venue and your style, and we will confirm availability for your date. Every booking includes an attendant, lighting, unlimited sharing and custom print designs, so you can enjoy the event while your guests enjoy the booth.";
        $this->page('serving-cheney', 'Photo Booth Rental in Cheney', [$this->text('Photo booths in Cheney', $body('Cheney'))], ['page_role' => 'location', 'page_intent' => ['place' => 'Cheney']]);
        $this->page('serving-liberty-lake', 'Photo Booth Rental in Liberty Lake', [$this->text('Photo booths in Liberty Lake', $body('Liberty Lake'))], ['page_role' => 'location', 'page_intent' => ['place' => 'Liberty Lake']]);

        $items = $this->review()['seo_repeated_copy']['items'];

        $this->assertCount(1, $items);
        $this->assertStringContainsString('Photo Booth Rental in Cheney and Photo Booth Rental in Liberty Lake share about', $items[0]);
    }

    public function test_a_commercial_page_with_no_way_on_to_another_page_is_named_and_a_linked_one_is_not(): void
    {
        $this->homePage($this->website, ['sections' => [$this->section('hero'), ['type' => 'gallery', 'data' => ['items' => [['image' => 'x']]]]]]);
        $this->page('serving-cheney', 'Photo Booth Rental in Cheney', [$this->text('Cheney', 'Words.'), ['type' => 'cta', 'data' => ['heading' => 'Book', 'buttons' => [['label' => 'Contact', 'url' => '/photo-booth-contact']]]]], ['page_role' => 'location', 'page_intent' => ['place' => 'Cheney']]);
        $this->page('serving-liberty-lake', 'Photo Booth Rental in Liberty Lake', [$this->text('Liberty Lake', 'Other words.'), ['type' => 'services', 'data' => ['items' => [['name' => 'Digital Photo Booth', 'service_uid' => 'u-1']]]]], ['page_role' => 'location', 'page_intent' => ['place' => 'Liberty Lake']]);
        $this->page('photo-booth-contact', 'Contact', [$this->text('Contact', 'Call us.')]);

        $this->assertSame(['Photo Booth Rental in Cheney'], $this->review()['seo_contextual_links']['items']);
    }

    public function test_repeated_photo_descriptions_cut_off_descriptions_and_missing_proof_are_reported(): void
    {
        $this->homePage($this->website);
        $this->page('about', 'About', [$this->text('About', 'Us.')], ['meta_description' => 'Transparent Photo Booth provides luxury photo booth rentals throughout Liberty...']);
        foreach (range(1, 4) as $n) {
            WebsiteAsset::create(['website_id' => $this->website->id, 'disk' => 'public', 'path' => "images/websites/x/{$n}.jpg", 'mime_type' => 'image/jpeg', 'size' => 10, 'alt_text' => 'Guests posing at a photo booth event']);
        }
        WebsiteAsset::create(['website_id' => $this->website->id, 'disk' => 'public', 'path' => 'images/websites/x/5.jpg', 'mime_type' => 'image/jpeg', 'size' => 10, 'alt_text' => 'Brides at the mirror booth']);

        $review = $this->review();

        $this->assertSame(['"guests posing at a photo booth event" is the description of 4 photos'], $review['seo_repeated_alt']['items']);
        $this->assertSame(['About'], $review['seo_cut_off_description']['items']);
        $this->assertArrayHasKey('seo_proof', $review);
        $this->assertSame('Review photos', $review['seo_repeated_alt']['action']['label']);
    }

    private function article($website, string $title, array $attributes = []): \App\Models\WebsiteArticle
    {
        $article = (new \App\Models\WebsiteArticle())->forceFill(array_merge([
            'business_id' => $website->business_id, 'website_id' => $website->id, 'title' => $title, 'slug' => \Illuminate\Support\Str::slug($title),
            'status' => 'published', 'noindex' => false, 'published_at' => now(), 'body' => 'Plain text.',
        ], $attributes));
        $article->save();

        return $article;
    }

    private function siteWithHome(): void
    {
        $this->homePage($this->website, ['sections' => [$this->section('hero'), ['type' => 'gallery', 'data' => ['heading' => 'Photos', 'items' => [['image' => 'x']]]]]]);
    }

    public function test_articles_pointing_at_pages_the_draft_still_has_cause_no_warning(): void
    {
        $this->siteWithHome();
        $page = $this->page('service-digital', 'Digital', [$this->section('hero')]);
        $this->article($this->website, 'Booth tips', ['supports_page_uid' => $page->uid, 'body' => 'See [our booth](page:' . $page->uid . ').']);

        $this->assertArrayNotHasKey('seo_article_links', $this->review());
    }

    public function test_an_article_supporting_a_removed_page_is_named_without_changing_anything(): void
    {
        $this->siteWithHome();
        $article = $this->article($this->website, 'Booth tips', ['supports_page_uid' => '11111111-1111-4111-8111-111111111111']);
        $before = [\App\Models\WebsiteArticle::query()->get()->toArray(), $this->website->pages()->count(), $this->website->fresh()->toArray()];

        $review = $this->review();

        $this->assertSame('warn', $review['seo_article_links']['status']);
        $this->assertStringContainsString('"Booth tips" supports a page that is not in this draft', $review['seo_article_links']['items'][0]);
        $this->assertStringContainsString('11111111-1111-4111-8111-111111111111', $review['seo_article_links']['items'][0]);
        $this->assertSame($before, [\App\Models\WebsiteArticle::query()->get()->toArray(), $this->website->pages()->count(), $this->website->fresh()->toArray()], 'Checking changes no article, page or website.');
        $this->assertSame('11111111-1111-4111-8111-111111111111', $article->fresh()->supports_page_uid);
    }

    public function test_a_broken_markdown_page_reference_is_named(): void
    {
        $this->siteWithHome();
        $page = $this->page('service-digital', 'Digital', [$this->section('hero')]);
        $this->article($this->website, 'Planning guide', ['body' => "Good: [booth](page:{$page->uid}). Gone: [old](page:22222222-2222-4222-8222-222222222222)."]);

        $items = $this->review()['seo_article_links']['items'];

        $this->assertCount(1, $items);
        $this->assertStringContainsString('"Planning guide" links to a page that is not in this draft (reference 22222222-2222-4222-8222-222222222222)', $items[0]);
    }

    public function test_sites_without_published_articles_and_unpublished_or_other_business_articles_are_unchanged(): void
    {
        $this->siteWithHome();
        $this->assertSame([], $this->review(), 'No articles: no review entry at all.');

        $this->article($this->website, 'A draft', ['status' => 'draft', 'published_at' => null, 'supports_page_uid' => '33333333-3333-4333-8333-333333333333']);
        $this->assertArrayNotHasKey('seo_article_links', $this->review(), 'An unpublished article is not live, so nothing breaks.');

        [, $other] = $this->entitledTenant();
        $otherWebsite = $this->createWebsite($other);
        $this->article($otherWebsite, 'Someone else', ['supports_page_uid' => '44444444-4444-4444-8444-444444444444', 'body' => '[x](page:55555555-5555-4555-8555-555555555555)']);

        $this->assertArrayNotHasKey('seo_article_links', $this->review(), 'Another business\'s articles are never read or reported.');
    }
}
