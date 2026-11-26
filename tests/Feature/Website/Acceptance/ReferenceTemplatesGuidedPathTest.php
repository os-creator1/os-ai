<?php

namespace Tests\Feature\Website\Acceptance;

use App\Library\Website\Setup\ServiceAreaDetails;
use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * The three reference designs through the REAL guided (v3) questionnaire and the deterministic Photo Booth blueprint — no AI
 * call. Fixture business, fixture photos, isolated tenant: this proves the answer → section mapping and section order. It is
 * NOT authentic client-account acceptance and NOT a pixel-parity claim.
 *
 * Set DUMP_OUT=/some/dir to also write each generated page's rendered preview HTML and its images for screenshot comparison.
 */
final class ReferenceTemplatesGuidedPathTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;

    private string $publicRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedGuidedWizard();

        $this->publicRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ref-guided-' . uniqid();
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

    /** @return array<string, array{0: string, 1: string}> */
    public static function templates(): array
    {
        return [
            'kaleb' => ['photo_booth_reference_kaleb', 'Fixture Magic Mirror Co'],
            'booths' => ['photo_booth_reference_booths', 'Fixture DSLR Booths'],
            'greenbelt' => ['photo_booth_reference_greenbelt', 'Fixture Luxury Rentals'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('templates')]
    public function test_reference_template_maps_questionnaire_answers_to_its_fixed_homepage(string $templateKey, string $name): void
    {
        $this->tenant(['name' => $name], ['city' => 'Boise', 'region' => 'ID']);
        $this->completeGuidedSetup();
        $this->uploadGalleryPhotos(6);

        $headline = 'Headline from the owner for ' . $name;
        foreach ([
            'hero_headline' => $headline,
            'hero_subtitle' => 'Supporting line typed by the owner.',
            'hero_eyebrow' => 'Owner tagline',
            'hero_focus' => 'top',
            'announcement_text' => 'Now booking 2026 and 2027',
            'founder_name' => 'Casey',
            'google_rating' => '4.9',
            'google_review_count' => '31',
            'services_heading' => 'Our booth line-up',
            'faq_heading' => 'Common questions',
        ] as $key => $value) {
            $this->continueStep($key, ['value' => $value])->assertOk();
        }
        $this->continueStep('testimonials', ['items' => [
            ['quote' => 'Fixture review one.', 'author_name' => 'A. Person', 'author_title' => 'Wedding'],
            ['quote' => 'Fixture review two.', 'author_name' => 'B. Person', 'author_title' => 'Corporate'],
        ]])->assertOk();

        $session = $this->wizardSession();
        $session->answers = array_merge((array) $session->answers, [ServiceAreaDetails::ANSWER_KEY => [
            'costa-mesa' => ['name' => 'Costa Mesa, CA', 'venues' => ['Fixture Hall One', 'Fixture Hall Two']],
        ]]);
        $session->save();

        Website::where('business_id', $this->business->id)->update(['template_key' => $templateKey]);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();

        $website = Website::where('business_id', $this->business->id)->firstOrFail();
        $home = $website->pages()->where('is_home', true)->firstOrFail();
        $sections = $home->sections;

        $hero = $sections[0];
        $this->assertSame('hero', $hero['type']);
        $this->assertSame($headline, $hero['data']['heading']);
        $this->assertSame('Supporting line typed by the owner.', $hero['data']['subheading']);
        $this->assertSame('Owner tagline', $hero['data']['eyebrow']);
        $this->assertSame('top', $hero['data']['image_focus']);
        $this->assertSame('Now booking 2026 and 2027', $hero['data']['announcement']);

        // Every page of the site carries the announcement through its own hero (published pages stay self-consistent).
        foreach ($website->pages()->get() as $page) {
            $pageHero = collect($page->sections)->firstWhere('type', 'hero');
            $this->assertSame('Now booking 2026 and 2027', $pageHero['data']['announcement'] ?? null, "Page {$page->slug} lost the announcement");
        }

        $reviews = collect($sections)->firstWhere('type', 'testimonials');
        $this->assertSame(['rating' => '4.9', 'count' => 31, 'source' => 'Google'], $reviews['data']['badge']);

        if ($templateKey !== 'photo_booth_reference_kaleb') {
            $this->assertStringContainsString('Casey', (string) collect($sections)->firstWhere('data.ref', 'founder')['data']['heading']);
        }
        if ($templateKey === 'photo_booth_reference_greenbelt') {
            $venues = collect($sections)->firstWhere('data.ref', 'venues');
            $this->assertSame(['Fixture Hall One', 'Fixture Hall Two'], collect($venues['data']['items'])->pluck('name')->all());
        }

        // Home section order is fixed by the template (recorded so a change is deliberate, not accidental).
        fwrite(STDERR, "ORDER {$templateKey}: " . collect($sections)->map(fn ($s) => $s['type'] . (isset($s['data']['ref']) ? ':' . $s['data']['ref'] : ''))->implode(' > ') . "\n");

        $this->dumpIfRequested($templateKey, $website);
    }

    public function test_no_badge_founder_or_venues_are_invented_when_the_owner_gave_none(): void
    {
        $this->tenant(['name' => 'Plain Booths'], ['city' => 'Boise', 'region' => 'ID']);
        $this->completeGuidedSetup();
        $this->uploadGalleryPhotos(6);
        $this->continueStep('testimonials', ['items' => [['quote' => 'Fixture review.', 'author_name' => 'A. Person', 'author_title' => 'Client']]])->assertOk();
        $this->continueStep('google_rating', ['value' => '5.0'])->assertOk(); // a rating without a count is never shown

        Website::where('business_id', $this->business->id)->update(['template_key' => 'photo_booth_reference_greenbelt']);
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])->assertRedirect();

        $sections = Website::where('business_id', $this->business->id)->firstOrFail()->pages()->where('is_home', true)->firstOrFail()->sections;
        $this->assertNull(collect($sections)->firstWhere('type', 'testimonials')['data']['badge'] ?? null);
        $this->assertNull(collect($sections)->firstWhere('data.ref', 'founder'));
        $this->assertNull(collect($sections)->firstWhere('data.ref', 'venues'));
        $this->assertArrayNotHasKey('announcement', collect($sections)->firstWhere('type', 'hero')['data']);
    }

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
}
