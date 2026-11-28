<?php

namespace Tests\Feature\Website\Acceptance;

use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\Support\WebsiteAcceptance\PhotoBoothFixture;
use Tests\TestCase;

/**
 * The signature components of the three reference designs, through the real guided questionnaire and the deterministic blueprint (no AI
 * call): Kaleb's awards strip and photo-led booth rows, Booths' two-photo intro collage, Greenbelt's event ticker and photo-tile strip.
 * Owner-supplied media only; every component must degrade to nothing (or a single image) when the media is missing, never repeat a photo to
 * fill a composition, and never leak into the canonical templates. Fixture business and fixture images: NOT client acceptance.
 */
final class ReferenceSignatureComponentsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;

    private string $publicRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();

        $this->publicRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ref-signature-' . uniqid();
        mkdir($this->publicRoot, 0755, true);
        $this->app->usePublicPath($this->publicRoot);

        $ai = \Mockery::mock(WebsiteAiGenerationClient::class);
        $ai->shouldNotReceive('complete');
        $ai->shouldReceive('lastCallWasBudgetExhausted')->andReturn(false);
        $ai->shouldReceive('lastRefusalReason')->andReturn(null);
        $this->app->instance(WebsiteAiGenerationClient::class, $ai);
    }

    protected function tearDown(): void
    {
        if ($this->publicRoot !== '' && is_dir($this->publicRoot)) {
            exec('rm -rf ' . escapeshellarg($this->publicRoot));
        }
        parent::tearDown();
    }

    protected function uploadPhoto(string $name = 'photo', string $unique = ''): string
    {
        $response = $this->jpost($this->url('backdrop-image.upload'), ['photo' => PhotoBoothFixture::jpeg(900, 700, $name, crc32($name . $unique) % 1000), 'name' => $name]);
        $response->assertOk();

        return (string) $response->json('path');
    }

    /** @return array<int, string> asset uids; the first is the homepage cover */
    private function uploadGallery(int $count, ?string $categoryTag = null, bool $cover = true): array
    {
        $uids = [];
        for ($i = 0; $i < $count; $i++) {
            $payload = ['photos' => [PhotoBoothFixture::jpeg($categoryTag === 'award_badge' ? 400 : 1600, $categoryTag === 'award_badge' ? 400 : 1067, ($categoryTag ?? 'event') . '-' . $i, 300 + $i + ($categoryTag === null ? 0 : 50))]];
            if ($categoryTag !== null) {
                $payload['category_tag'] = $categoryTag;
            }
            $response = $this->withHeaders(['Accept' => 'application/json'])->post($this->url('gallery.upload'), $payload);
            $response->assertOk();
            $uids[] = (string) $response->json('assets.0.uid');
        }
        if ($cover && $uids !== []) {
            $this->jpost(route('customer.workspaces.businesses.website.setup.gallery.update', [$this->workspace->uid, $this->business->uid, $uids[0]]), ['is_cover' => '1'])->assertOk();
        }

        return $uids;
    }

    /** @return array{0: Website, 1: array<int, array<string, mixed>>} */
    private function generate(string $templateKey, int $photos = 6, int $badges = 0, array $setup = [], ?string $announcement = null): array
    {
        $this->tenant(['name' => 'Signature Fixture'], ['city' => 'Boise', 'region' => 'ID']);
        $this->completeGuidedSetup($setup);
        $gallery = $photos > 0 ? $this->uploadGallery($photos) : [];
        if ($badges > 0) {
            $this->badgeUids = $this->uploadGallery($badges, 'award_badge', false);
        }
        $this->galleryUids = $gallery;
        if ($announcement !== null) {
            $this->continueStep('announcement_text', ['value' => $announcement])->assertOk();
        }
        Website::where('business_id', $this->business->id)->update(['template_key' => $templateKey]);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();

        return [$website, $website->pages()->where('is_home', true)->firstOrFail()->sections];
    }

    /** @var array<int, string> */
    private array $badgeUids = [];

    /** @var array<int, string> */
    private array $galleryUids = [];

    private function homeHtml(Website $website): string
    {
        $page = $website->pages()->where('is_home', true)->firstOrFail();

        return $this->get(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $this->business->uid, $page->uid]))->assertOk()->getContent();
    }

    /** Set DUMP_OUT=/dir to write every page's rendered preview HTML and its images (used for the screenshot comparison). */
    private function dumpIfRequested(string $templateKey, Website $website): void
    {
        $out = getenv('DUMP_OUT');
        if (! is_string($out) || $out === '') {
            return;
        }

        $dir = $out . '/' . $templateKey;
        @mkdir($dir, 0755, true);
        foreach ($website->pages()->get() as $page) {
            $response = $this->get(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $this->business->uid, $page->uid]));
            file_put_contents($dir . '/' . ($page->is_home ? 'home' : preg_replace('/[^a-z0-9]+/i', '-', (string) $page->slug)) . '.html', $response->getContent());
        }
        exec('cp -r ' . escapeshellarg($this->publicRoot) . '/images ' . escapeshellarg($dir) . '/images 2>&1');
    }

    private function section(array $sections, string $ref): ?array
    {
        return collect($sections)->first(fn ($s) => ($s['data']['ref'] ?? null) === $ref);
    }

    // ------------------------------------------------------------------ Kaleb

    public function test_kaleb_awards_strip_shows_only_the_owners_badge_images_and_keeps_them_out_of_the_gallery_and_hero(): void
    {
        [$website, $sections] = $this->generate('photo_booth_reference_kaleb', 6, 3);

        $strip = $this->section($sections, 'badges');
        $this->assertNotNull($strip, 'the awards strip is missing');
        $this->assertSame('gallery', $strip['type']);
        $this->assertEqualsCanonicalizing($this->badgeUids, collect($strip['data']['items'])->pluck('image')->all());
        $this->assertSame('hero', $sections[0]['type']);
        $this->assertSame('badges', $sections[1]['data']['ref'], 'the strip sits directly under the hero');

        // Badge images are never the hero, never in the photo gallery, and the text facts strip is not stamped on top of them.
        $this->assertNotContains($sections[0]['data']['background_image'] ?? null, $this->badgeUids);
        $galleryItems = collect($sections)->where('type', 'gallery')->reject(fn ($s) => ($s['data']['ref'] ?? null) === 'badges')->flatMap(fn ($s) => collect($s['data']['items'])->pluck('image'))->all();
        $this->assertSame([], array_values(array_intersect($galleryItems, $this->badgeUids)));
        $this->assertNull(collect($sections)->firstWhere('type', 'trust_strip'));

        $html = $this->homeHtml($website);
        $this->assertStringContainsString('wd-ref-badges', $html);
    }

    public function test_award_badges_never_become_the_hero_image_fallback_when_there_are_no_photos(): void
    {
        [$website, $sections] = $this->generate('photo_booth_reference_kaleb', 0, 3);
        $this->assertNotNull($this->section($sections, 'badges'));

        $html = $this->homeHtml($website);
        $main = (int) strpos($html, 'id="wd-main"');
        $beforeStrip = substr($html, $main, (int) strpos($html, 'wd-ref-badges', $main) - $main);
        // Nothing above the awards strip (the hero) may be a Website gallery asset: a badge logo is not photography (the hero may still use the business's own photo).
        $this->assertStringNotContainsString('/images/websites/', $beforeStrip);
        $this->assertStringContainsString('/images/websites/', substr($html, (int) strpos($html, 'wd-ref-badges')), 'the badges themselves still render');
    }

    public function test_kaleb_without_badge_images_has_no_awards_strip_and_invents_nothing(): void
    {
        [, $sections] = $this->generate('photo_booth_reference_kaleb', 6, 0);

        $this->assertNull($this->section($sections, 'badges'));
        $this->assertSame([], collect($sections)->where('type', 'gallery')->filter(fn ($s) => ($s['data']['ref'] ?? null) === 'badges')->all());
    }

    public function test_kaleb_booth_rows_show_the_booths_own_extra_photos_and_never_gallery_photos(): void
    {
        $this->tenant(['name' => 'Signature Fixture'], ['city' => 'Boise', 'region' => 'ID']);
        $this->completeGuidedSetup(['booth_services' => fn () => $this->continueStep('booth_services', ['items' => [
            ['key' => 'k-mirror', 'name' => 'Mirror Photo Booth', 'description' => 'An interactive mirror.', 'preset' => 'mirror', 'visible' => '1',
                'image_path' => $this->uploadPhoto('mirror-cover'), 'extra_image_paths' => [$this->uploadPhoto('mirror-extra-1'), $this->uploadPhoto('mirror-extra-2')]],
            ['key' => 'k-open', 'name' => 'Open-Air Photo Booth', 'description' => 'A modern open booth.', 'preset' => 'open_air', 'visible' => '1', 'image_path' => $this->uploadPhoto('open-cover')],
        ]])]);
        $this->uploadGallery(6);
        $this->badgeUids = $this->uploadGallery(3, 'award_badge', false);
        Website::where('business_id', $this->business->id)->update(['template_key' => 'photo_booth_reference_kaleb']);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();
        $website = Website::where('business_id', $this->business->id)->firstOrFail();
        $sections = $website->pages()->where('is_home', true)->firstOrFail()->sections;

        $booths = $this->section($sections, 'booths');
        $this->assertTrue($booths['data']['editorial']);
        $mirror = collect($booths['data']['items'])->firstWhere('name', 'Mirror Photo Booth');
        $open = collect($booths['data']['items'])->firstWhere('name', 'Open-Air Photo Booth');
        $this->assertCount(1, $mirror['extra_images'], 'one of the booth\'s own extra photos beside its main photo (the reference pairs two)');
        $this->assertNotContains($mirror['image'], $mirror['extra_images']);
        $this->assertArrayNotHasKey('extra_images', $open, 'a booth with no extra photos gets none (never a gallery photo)');
        $this->assertSame([], array_values(array_intersect($mirror['extra_images'], $this->galleryUids)));

        $html = $this->homeHtml($website);
        $this->assertSame(1, substr_count($html, 'wd-card-media-stack'), 'only the booth with extra photos renders the stack');
        $this->assertNotNull($this->section($sections, 'badges'));
        $this->dumpIfRequested('photo_booth_reference_kaleb', $website);
    }

    // ------------------------------------------------------------------ Booths

    public function test_booths_intro_collage_uses_two_distinct_photos_and_a_hashtag_from_the_business_name(): void
    {
        [$website, $sections] = $this->generate('photo_booth_reference_booths', 6);

        $intro = $this->section($sections, 'intro');
        $this->assertTrue($intro['data']['collage']);
        $this->assertNotEmpty($intro['data']['image']);
        $this->assertNotEmpty($intro['data']['image_secondary']);
        $this->assertNotSame($intro['data']['image'], $intro['data']['image_secondary'], 'the same photo is never repeated to fill the collage');
        $this->assertNotSame($sections[0]['data']['background_image'] ?? null, $intro['data']['image_secondary']);
        $this->assertSame('#GLOWBOOTHS', $intro['data']['accent_tag'], 'derived from the canonical business name');

        $html = $this->homeHtml($website);
        $this->assertStringContainsString('wd-collage', $html);
        $this->assertStringContainsString('wd-collage-tag', $html);
        $this->dumpIfRequested('photo_booth_reference_booths', $website);
    }

    /** @return array<string, array{0: int}> */
    public static function scarcePhotoCounts(): array
    {
        return ['one photo' => [1], 'two photos (hero + one)' => [2]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scarcePhotoCounts')]
    public function test_booths_collage_falls_back_to_a_single_photo_when_two_distinct_photos_are_not_available(int $photos): void
    {
        [$website, $sections] = $this->generate('photo_booth_reference_booths', $photos);

        $intro = $this->section($sections, 'intro');
        $this->assertArrayNotHasKey('image_secondary', $intro['data'], 'no second photo, and certainly not a duplicate');
        $this->assertStringNotContainsString('website-image-text-media wd-collage', $this->homeHtml($website), 'no collage markup');
    }

    // ---------------------------------------------------------------- Greenbelt

    public function test_greenbelt_ticker_lists_the_real_event_types_with_an_aria_hidden_loop_copy(): void
    {
        [$website, $sections] = $this->generate('photo_booth_reference_greenbelt', 6, 0, [], 'Now booking 2026 and 2027');

        $ticker = $this->section($sections, 'marquee');
        $this->assertSame('trust_strip', $ticker['type']);
        $this->assertSame(['Weddings', 'Corporate Events'], $ticker['data']['facts'], 'exactly the owner\'s event types, in order');

        $html = $this->homeHtml($website);
        $this->assertStringContainsString('data-testid="event-marquee"', $html);
        $this->assertSame(1, substr_count($html, 'wd-marquee-copy'), 'one hidden loop copy');
        $this->assertMatchesRegularExpression('/<ul class="wd-marquee-list wd-marquee-copy" aria-hidden="true">/', $html);
        // The text facts strip is not stamped in addition.
        $this->assertSame(1, substr_count($html, 'website-trust-strip'));
        $this->dumpIfRequested('photo_booth_reference_greenbelt', $website);
    }

    public function test_greenbelt_has_no_ticker_with_fewer_than_two_event_types(): void
    {
        [, $sections] = $this->generate('photo_booth_reference_greenbelt', 6, 0, ['event_types' => fn () => $this->continueStep('event_types', ['items' => [
            ['key' => 'e-w', 'name' => 'Weddings', 'description' => 'Wedding fun.', 'preset' => 'weddings', 'visible' => '1'],
        ]])]);

        $this->assertNull($this->section($sections, 'marquee'), 'a single category is not a ticker');
    }

    public function test_greenbelt_promo_strip_gets_a_photo_tile_that_is_not_the_hero(): void
    {
        [$website, $sections] = $this->generate('photo_booth_reference_greenbelt', 6, 0, [], 'Now booking 2026 and 2027');

        $strip = $this->section($sections, 'strip');
        $this->assertSame('cta', $strip['type']);
        $this->assertSame('Now booking 2026 and 2027', $strip['data']['heading'], 'the owner\'s own announcement text');
        $this->assertNotEmpty($strip['data']['image']);
        $this->assertNotSame($sections[0]['data']['background_image'] ?? null, $strip['data']['image']);
        $this->assertNotEmpty($strip['data']['buttons'], 'a functional CTA');

        $html = $this->homeHtml($website);
        $this->assertStringContainsString('wd-cta-has-photo', $html);
        $this->assertStringContainsString('wd-cta-photo', $html);
    }

    public function test_greenbelt_promo_strip_has_no_photo_tile_when_there_is_no_spare_photo(): void
    {
        [$website, $sections] = $this->generate('photo_booth_reference_greenbelt', 1, 0, [], 'Now booking 2026 and 2027');

        $this->assertArrayNotHasKey('image', $this->section($sections, 'strip')['data']);
        $this->assertStringNotContainsString('wd-cta-has-photo', $this->homeHtml($website));
    }

    // ---------------------------------------------------------- compatibility

    public function test_canonical_templates_are_unaffected_by_the_signature_components(): void
    {
        [$website, $sections] = $this->generate('photo_booth_modern', 6, 2);

        $json = json_encode($sections);
        foreach (['"ref":"badges"', '"ref":"marquee"', 'image_secondary', '"collage"', 'extra_images', '"editorial"', 'accent_tag'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $json, "{$needle} leaked into a canonical template");
        }

        $html = $this->homeHtml($website);
        foreach (['wd-ref-badges', 'wd-marquee', 'wd-collage', 'wd-card-media-stack', 'wd-cta-has-photo'] as $class) {
            $this->assertStringNotContainsString($class, $html);
        }
        // Even there, badge-category images never become the hero or a gallery photo.
        $this->assertNotContains($sections[0]['data']['background_image'] ?? null, $this->badgeUids);
    }

    public function test_publish_snapshot_collects_the_new_image_references(): void
    {
        $method = new \ReflectionMethod(\App\Library\Website\WebsiteSnapshotBuilder::class, 'assetUidsIn');
        $method->setAccessible(true);
        $builder = app(\App\Library\Website\WebsiteSnapshotBuilder::class);

        $this->assertEqualsCanonicalizing(['a', 'b'], $method->invoke($builder, \App\Enums\Website\WebsiteSectionType::ImageText, ['image' => 'a', 'image_secondary' => 'b']));
        $this->assertSame(['c'], $method->invoke($builder, \App\Enums\Website\WebsiteSectionType::Cta, ['image' => 'c']));
        $this->assertEqualsCanonicalizing(['m', 'x1', 'x2'], $method->invoke($builder, \App\Enums\Website\WebsiteSectionType::Services, ['items' => [['image' => 'm', 'extra_images' => ['x1', 'x2']]]]));
    }
}
