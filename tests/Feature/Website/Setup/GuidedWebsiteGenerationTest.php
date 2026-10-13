<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\WebsiteAiGenerationClient;
use App\Library\Website\WebsiteHealthChecker;
use App\Library\Website\WebsiteMediaRequirements;
use App\Library\Website\WebsitePublisher;
use App\Models\BusinessService;
use App\Models\Website;
use App\Models\WebsiteGuidedGenerationAttempt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * A guided setup must end in a COMPLETE commercial website without any AI call,
 * with real photographs attached by id, defaults that work, and a publish gate
 * that refuses a site missing the photos of what it shows.
 */
class GuidedWebsiteGenerationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();

        // The guided builder never calls AI: any call would be a spend this site does not need.
        $ai = \Mockery::mock(WebsiteAiGenerationClient::class);
        $ai->shouldNotReceive('complete');
        $ai->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $ai->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $ai);
    }

    protected function generate(): Website
    {
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $this->business->uid]));

        return Website::where('business_id', $this->business->id)->firstOrFail();
    }

    /** @return array<string, array<int, array<string, mixed>>> sections of the Home page keyed by type (a list per type) */
    private function home(Website $website): array
    {
        $by = [];
        foreach ($website->pages()->where('is_home', true)->firstOrFail()->sections as $section) {
            $by[$section['type']][] = $section['data'];
        }

        return $by;
    }

    protected function fullSetup(array $business = [], array $overrides = []): array
    {
        $this->tenant($business, ['city' => 'Irvine', 'region' => 'CA']);
        $photos = $this->completeGuidedSetup($overrides);
        $this->uploadGalleryPhotos(6);

        return $photos;
    }

    public function test_a_guided_setup_generates_a_complete_homepage_with_every_section_populated_and_no_ai_call(): void
    {
        $this->fullSetup();
        $website = $this->generate();

        $this->assertSame(WebsiteGuidedGenerationAttempt::STATUS_SUCCEEDED, $website->guidedGenerationAttempts()->latest('id')->value('status'));
        $home = $this->home($website);

        foreach (['hero', 'services', 'gallery', 'backdrops', 'text', 'faq', 'cta', 'contact_details'] as $type) {
            $this->assertArrayHasKey($type, $home, "The homepage has a {$type} section.");
        }

        $this->assertCount(1, $home['hero']);
        $this->assertCount(1, $home['cta'], 'Exactly one closing call to action — never duplicated.');
        $this->assertSame('Glow Booths', $home['hero'][0]['heading']);
        $this->assertNotEmpty($home['hero'][0]['subheading']);
        $this->assertNotEmpty($home['hero'][0]['background_image'], 'The hero shows the owner\'s chosen cover photo.');
        $this->assertSame('Request a quote', $home['hero'][0]['primary_cta']['label']);

        $headings = array_map(fn ($d) => $d['heading'] ?? '', $home['services']);
        $this->assertSame(['Our photo booths', 'Packages', 'Events we cover', 'Why choose Glow Booths'], $headings, 'Booths, packages, events and the confirmed advantages — in that order, each from real facts.');

        // Nothing is an empty shell: every list has items, every text has a body.
        foreach ($home['services'] as $section) {
            $this->assertNotEmpty($section['items']);
            foreach ($section['items'] as $item) {
                $this->assertNotSame('', trim($item['name']));
            }
        }
        foreach ($home['text'] as $section) {
            $this->assertGreaterThan(40, mb_strlen($section['body']));
        }
        $this->assertCount(6, $home['faq'][0]['items']);
        $this->assertGreaterThanOrEqual(3, count($home['gallery'][0]['items']));
        $this->assertSame('Floral wall', $home['backdrops'][0]['items'][0]['name']);
        $this->assertNotEmpty($home['backdrops'][0]['items'][0]['images'][0]['url']);
    }

    public function test_the_other_pages_are_complete_too(): void
    {
        $this->fullSetup();
        $website = $this->generate();

        $slugs = $website->pages()->pluck('slug')->all();
        foreach (['services', 'packages', 'photo-booth-about', 'photo-booth-faq', 'photo-booth-contact', 'gallery', 'backdrops'] as $slug) {
            $this->assertContains($slug, $slugs);
        }
        $this->assertNotContains(true, array_map(fn ($s) => str_starts_with((string) $s, 'serving-'), $slugs), 'Service areas never become city SEO pages from this step.');

        foreach ($website->pages as $page) {
            $types = array_column($page->sections, 'type');
            $this->assertSame(1, count(array_keys($types, 'hero')), "{$page->title} has exactly one main heading.");
            $this->assertGreaterThan(1, count($types), "{$page->title} has real content beyond its heading.");
        }

        $contact = $website->pages()->where('slug', 'photo-booth-contact')->firstOrFail();
        $this->assertContains('form', array_column($contact->sections, 'type'), 'The quote form is on the contact page.');
        $faqPage = $website->pages()->where('slug', 'photo-booth-faq')->firstOrFail();
        $faqItems = collect($faqPage->sections)->firstWhere('type', 'faq')['data']['items'];
        $this->assertCount(6, $faqItems);
    }

    public function test_service_photos_attach_by_id_and_a_package_falls_back_to_text_only_or_a_linked_booth_photo(): void
    {
        $photos = $this->fullSetup();
        $website = $this->generate();
        $home = $this->home($website);

        $open = BusinessService::where('source_questionnaire_item_key', 'k-open')->firstOrFail();
        $mirror = BusinessService::where('source_questionnaire_item_key', 'k-mirror')->firstOrFail();

        $assets = $website->assets()->get()->keyBy('uid');
        $boothCards = collect($home['services'][0]['items'])->keyBy('name');
        $openAsset = $assets[$boothCards['Open-Air Photo Booth']['image']];
        $mirrorAsset = $assets[$boothCards['Mirror Photo Booth']['image']];

        $this->assertSame($photos['booth_photos']['k-open'], $openAsset->path, 'Each booth card shows its own photo.');
        $this->assertSame($photos['booth_photos']['k-mirror'], $mirrorAsset->path);
        $this->assertSame('service_mirror', $openAsset->purpose->value);
        $this->assertSame($open->images()->first()->id, $openAsset->source_business_service_image_id);

        // Packages: "Essential" is linked to the open-air booth → its photo; "Signature" has nothing → text-only card.
        $packages = collect($home['services'][1]['items'])->keyBy('name');
        $this->assertSame($photos['booth_photos']['k-open'], $assets[$packages['Essential']['image']]->path, 'A linked package uses that booth\'s photo.');
        $this->assertArrayNotHasKey('image', $packages['Signature'], 'A package with no photo and no link is a text-only card — no image slot at all.');
        $this->assertArrayNotHasKey('link_service_key', $packages['Essential'], 'The pairing key is internal and never stored on the page.');

        // The service detail page uses the SERVICE's own photo, never a gallery photo.
        $detail = $website->pages()->where('slug', 'service-' . \Illuminate\Support\Str::slug($open->name))->first();
        $imageText = $detail === null ? null : collect($detail->sections)->firstWhere('type', 'image_text');
        if ($imageText !== null) {
            $this->assertSame($photos['booth_photos']['k-open'], $assets[$imageText['data']['image']]->path);
        }
        $this->assertSame(0, $website->assets()->where('purpose', 'gallery')->whereIn('path', array_values($photos['booth_photos']))->count(), 'Service photos never leak into the gallery pool.');
    }

    public function test_a_quote_only_business_gets_services_and_a_quote_section_but_no_invented_packages_or_prices(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $this->completeGuidedSetup([
            'pricing_strategy' => fn () => $this->continueStep('pricing_strategy', ['value' => 'custom_quote']),
            'packages' => null,
        ]);
        $this->uploadGalleryPhotos(3);
        $website = $this->generate();
        $home = $this->home($website);

        $headings = array_map(fn ($d) => $d['heading'] ?? '', array_merge($home['services'], $home['text']));
        $this->assertContains('Our photo booths', $headings);
        $this->assertContains('Custom quotes', $headings);
        $this->assertNotContains('Packages', $headings);
        $this->assertNull($website->pages()->where('slug', 'packages')->first(), 'No packages page without packages.');

        $json = json_encode($website->pages->pluck('sections'));
        $this->assertStringNotContainsString('price_label', $json, 'No price is shown for quote-only pricing.');
        $this->assertStringContainsString('custom quote', mb_strtolower($json));
    }

    public function test_a_single_booth_business_still_gets_a_complete_site(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $photo = $this->uploadPhoto('only');
        $this->completeGuidedSetup([
            'booth_services' => fn () => $this->continueStep('booth_services', ['items' => [['key' => 'solo', 'name' => 'Open-Air Photo Booth', 'description' => 'Our one booth.', 'visible' => '1', 'image_path' => $photo]]]),
            'offers_backdrops' => fn () => $this->continueStep('offers_backdrops', ['value' => '0']),
            'backdrops' => null,
            'addons' => null,
        ]);
        $this->uploadGalleryPhotos(3);
        $website = $this->generate();
        $home = $this->home($website);

        $this->assertCount(1, $home['services'][0]['items']);
        $this->assertArrayNotHasKey('backdrops', $home, 'No backdrop section when the owner offers none.');
        $this->assertCount(1, $home['cta']);
        $this->assertEmpty(app(WebsiteMediaRequirements::class)->missing($website));
    }

    // -------------------------------------------------------------- the publish gate

    public function test_publishing_is_blocked_until_every_shown_booth_and_backdrop_and_the_homepage_have_real_photos(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $backdropless = $this->uploadPhoto('mirror');
        $this->completeGuidedSetup([
            'booth_services' => fn () => $this->continueStep('booth_services', ['items' => [
                ['key' => 'k-open', 'name' => 'Open-Air Photo Booth', 'description' => 'No photo yet.', 'visible' => '1'],
                ['key' => 'k-mirror', 'name' => 'Mirror Photo Booth', 'description' => 'Has one.', 'visible' => '1', 'image_path' => $backdropless],
            ]]),
            'backdrops' => fn () => $this->continueStep('backdrops', ['items' => [['key' => 'b1', 'name' => 'Floral wall', 'category' => 'backdrop', 'availability' => '1']]]),
            'gallery' => fn () => $this->continueStep('gallery', ['value' => '0']),
        ]);

        // A draft can be generated with no photos at all…
        $website = $this->generate();
        $this->assertTrue(app(WebsiteMediaRequirements::class)->appliesTo($website));

        $titles = array_column(app(WebsiteMediaRequirements::class)->missing($website), 'title');
        $this->assertContains('Homepage cover photo', $titles);
        $this->assertContains('Photo needed: Open-Air Photo Booth', $titles);
        $this->assertContains('Photo needed: Floral wall', $titles);
        $this->assertNotContains('Photo needed: Mirror Photo Booth', $titles);
        $this->assertCount(3, $titles, 'Packages need no photo of their own.');

        // …but the server refuses to publish it, saying exactly what is missing.
        try {
            app(WebsitePublisher::class)->publish($website, (int) $this->customer->user_id);
            $this->fail('Publishing must be blocked.');
        } catch (ValidationException $e) {
            $this->assertCount(3, $e->errors()['photos']);
            $this->assertStringContainsString('Open-Air Photo Booth', implode(' ', $e->errors()['photos']));
        }
        $this->assertNull($website->fresh()->published_revision_id);

        // The checklist the Studio shows names each one and links to where it is added.
        $check = collect(app(WebsiteHealthChecker::class)->check($website->fresh(), ['answers' => 'https://example.test/edit-setup'])['checks'])->firstWhere('key', 'required_photos');
        $this->assertSame('fail', $check['status']);
        $this->assertCount(3, $check['items']);
        $this->assertStringContainsString('step=', $check['action']['url']);
    }

    public function test_hiding_a_booth_or_removing_a_backdrop_satisfies_the_gate_and_then_publishing_works(): void
    {
        $this->tenant([], ['city' => 'Irvine', 'region' => 'CA']);
        $photo = $this->uploadPhoto('mirror');
        $this->completeGuidedSetup([
            'booth_services' => fn () => $this->continueStep('booth_services', ['items' => [
                ['key' => 'k-open', 'name' => 'Open-Air Photo Booth', 'description' => 'No photo yet.', 'visible' => '1'],
                ['key' => 'k-mirror', 'name' => 'Mirror Photo Booth', 'description' => 'Has one.', 'visible' => '1', 'image_path' => $photo],
            ]]),
            'offers_backdrops' => fn () => $this->continueStep('offers_backdrops', ['value' => '0']),
            'backdrops' => null,
        ]);
        $this->uploadGalleryPhotos(3);
        $website = $this->generate();

        $this->assertSame(['Photo needed: Open-Air Photo Booth'], array_column(app(WebsiteMediaRequirements::class)->missing($website), 'title'));

        // Hide the booth that has no photo (the other way out), rebuild the draft from the answers, publish.
        BusinessService::where('source_questionnaire_item_key', 'k-open')->update(['website_visible' => false]);
        $this->assertSame([], app(WebsiteMediaRequirements::class)->missing($website->fresh()));

        $revision = app(WebsitePublisher::class)->publish($website->fresh(), (int) $this->customer->user_id);
        $this->assertSame($revision->id, $website->fresh()->published_revision_id);
    }

    public function test_the_policy_never_applies_to_a_website_that_was_not_built_by_the_guided_setup(): void
    {
        [, $business] = $this->entitledTenant();
        $website = $this->createWebsite($business);
        $this->homePage($website);

        $this->assertFalse(app(WebsiteMediaRequirements::class)->appliesTo($website));
        $this->assertSame([], app(WebsiteMediaRequirements::class)->missing($website));
    }

    public function test_an_edited_answer_never_overwrites_a_published_revision_or_its_images(): void
    {
        $this->fullSetup();
        $website = $this->generate();
        $revision = app(WebsitePublisher::class)->publish($website, (int) $this->customer->user_id);
        $snapshot = $revision->fresh()->snapshot;

        // Change an answer afterwards (edit mode) and rebuild: the live revision is untouched.
        $this->get(route('customer.workspaces.businesses.website.edit-setup', [$this->workspace->uid, $this->business->uid]))->assertRedirect();
        $this->continueStep('brand_personality', ['value' => 'bold'])->assertOk();

        $this->assertSame($snapshot, $revision->fresh()->snapshot, 'A published revision is a frozen snapshot.');
        $this->assertSame($revision->id, $website->fresh()->published_revision_id);
        $this->assertSame(0, $website->assets()->whereNotNull('first_published_at')->count() === 0 ? 1 : 0, 'Assets used by the live revision are marked published and kept.');
    }
}
