<?php

namespace Tests\Feature\Website\Setup;

use App\Library\Website\WebsiteAiGenerationClient;
use App\Models\QuestionnaireResponse;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesGuidedWizard;
use Tests\TestCase;

/**
 * The redesigned pre-generation Review page for a guided setup: real saved data in compact sections, honest photo
 * status (a draft never needs photos; publishing does), working Edit links that return to the page, and the same
 * generate operation behind every generate button.
 */
class WebsiteReviewPageTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesGuidedWizard;

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

    private function editUrl(string $step): string
    {
        return $this->url('step', $step) . '?return=review';
    }

    public function test_the_review_shows_the_saved_answers_in_sections_with_working_edit_links(): void
    {
        $this->tenant(['name' => 'Glow Booths'], ['city' => 'Irvine', 'region' => 'CA']);
        $this->completeGuidedSetup();
        $this->uploadGalleryPhotos(3);

        $page = $this->get($this->url('review'))->assertOk();

        $page->assertSee('Review your answers');
        $page->assertSee("You're almost there.", false);
        foreach (['rv-glance', 'rv-look', 'rv-about', 'rv-services', 'rv-content', 'rv-contact'] as $section) {
            $page->assertSee('id="' . $section . '"', false);
            $page->assertSee('data-rv-nav="' . $section . '"', false);
        }

        // Real saved values — never invented.
        foreach (['Open-Air Photo Booth', 'Mirror Photo Booth', 'Weddings', 'Corporate Events', 'Essential', 'Signature', 'Extra hours', 'Floral wall', 'Irvine, CA', 'Playful', 'Request a quote', 'Fixed package pricing', 'Digital photo sharing'] as $saved) {
            $page->assertSee($saved);
        }
        $page->assertSee('data-testid="review-packages"', false);
        $page->assertSee('499.00');
        $page->assertSee('data-count="booths">2<', false);
        $page->assertSee('data-count="events">2<', false);
        $page->assertSee('data-count="packages">2<', false);
        $page->assertSee('data-count="backdrops">1<', false);
        $page->assertSee('data-count="photos">', false);

        // Every Edit link opens the right screen and asks to come back here.
        foreach (['business_name', 'base_location', 'service_area_cities', 'booth_services', 'event_types', 'packages', 'addons', 'backdrops', 'gallery', 'faq_items', 'pricing_strategy', 'offers_backdrops', 'primary_cta', 'contact_form_fields', 'photo_rights', 'brand_personality', 'about_story', 'advantages', 'testimonials'] as $step) {
            $page->assertSee('href="' . $this->editUrl($step) . '"', false);
            $this->get($this->editUrl($step))->assertOk()->assertSee('name="return_to" value="review"', false)->assertSee('Back to review');
        }
    }

    public function test_the_photo_checklist_is_live_and_a_draft_can_be_generated_without_the_publish_photos(): void
    {
        $this->tenant(['name' => 'Glow Booths'], ['city' => 'Irvine', 'region' => 'CA']);
        $this->completeGuidedSetup([
            'backdrops' => fn () => $this->continueStep('backdrops', ['items' => [['key' => 'b1', 'name' => 'Velvet curtain', 'category' => 'backdrop', 'availability' => '1']]]),
            'gallery' => null,
        ]);

        $page = $this->get($this->url('review'))->assertOk();

        $page->assertSee('Photos needed to publish');
        $page->assertSee('Homepage hero photo');
        $page->assertSee('Open-Air Photo Booth');
        $page->assertSee('Velvet curtain');
        $page->assertSee('aria-label="Add photo: Velvet curtain"', false);
        $page->assertSee('href="' . $this->editUrl('backdrops') . '"', false);
        $page->assertSee('aria-label="Add photo: Homepage hero photo"', false);
        $page->assertSee('href="' . $this->editUrl('gallery') . '"', false);
        $this->assertSame(2, substr_count($page->getContent(), 'data-done="0"'), 'Hero and the photo-less backdrop are missing; both booths have photos.');
        $this->assertSame(2, substr_count($page->getContent(), 'data-done="1"'));
        $page->assertSee('needed to publish (not to generate)');
        $page->assertSee('Ready to generate');
        $page->assertDontSee('Not ready to generate');

        // The two generate buttons are the same authorised operation.
        $this->assertSame(2, substr_count($page->getContent(), '<form method="POST" data-generation-form'));
        $this->assertSame(2, substr_count($page->getContent(), 'action="' . $this->url('generate') . '"'));

        // Missing publish photos never block the draft.
        $this->post($this->url('generate'), ['answers_revision' => $this->wizardSession()->answers_revision])
            ->assertRedirect(route('customer.workspaces.businesses.website.preview', [$this->workspace->uid, $this->business->uid]));
        $this->assertGreaterThan(0, Website::where('business_id', $this->business->id)->firstOrFail()->pages()->count());
    }

    public function test_an_edit_started_from_the_review_returns_to_it_and_keeps_every_other_answer(): void
    {
        $this->tenant(['name' => 'Glow Booths'], ['city' => 'Irvine', 'region' => 'CA']);
        $this->completeGuidedSetup();

        $before = $this->wizardSession();
        $payload = ['items' => [
            ['key' => 'e-w', 'name' => 'Weddings', 'description' => 'Wedding fun.', 'preset' => 'weddings', 'visible' => '1'],
            ['key' => 'e-g', 'name' => 'Graduations', 'description' => 'Graduate.', 'preset' => 'graduations', 'visible' => '1'],
        ], 'answers_revision' => $before->answers_revision, '__present' => ['event_types']];

        // Without the return flag the wizard keeps walking forward as before.
        $this->jpost($this->url('autosave', 'event_types'), $payload)->assertOk()->assertJsonPath('next_url', fn ($url) => $url !== $this->url('review'));

        $second = $this->wizardSession();
        $payload['answers_revision'] = $second->answers_revision;
        $this->jpost($this->url('autosave', 'event_types'), $payload + ['return_to' => 'review'])
            ->assertOk()
            ->assertJsonPath('next_url', $this->url('review'))
            ->assertJsonPath('full_page', true);

        $after = QuestionnaireResponse::findOrFail($before->id);
        $this->assertEquals('Graduations', collect($after->answers['event_types'])->pluck('name')->last());

        foreach ($before->answers as $key => $value) {
            if ($key !== 'event_types') {
                $this->assertEquals($value, $after->answers[$key], "{$key} is untouched by editing event types.");
            }
        }

        $this->get($this->url('review'))->assertSee('Graduations');
    }

    public function test_the_design_choice_persists_and_exactly_one_design_is_selected(): void
    {
        $this->tenant(['name' => 'Glow Booths'], ['city' => 'Irvine', 'region' => 'CA']);
        $this->completeGuidedSetup();
        $before = $this->wizardSession();

        $page = $this->get($this->url('review'))->assertOk();
        foreach (['photo_booth_modern', 'photo_booth_editorial', 'photo_booth_luxury', 'photo_booth_conversion'] as $key) {
            $page->assertSee('data-template-card="' . $key . '"', false);
        }
        foreach (['Bold Event', 'Classic Gold', 'Midnight Gold', 'Party Luxe'] as $label) {
            $page->assertSee($label);
        }
        $this->assertSame(3, substr_count($page->getContent(), 'data-template-use'), 'The selected design has no "Use this design" button.');
        $this->assertSame(4, substr_count($page->getContent(), 'rel="noopener" aria-label="Open full preview'));

        $this->post(route('customer.workspaces.businesses.website.look.update', [$this->workspace->uid, $this->business->uid]), ['template_key' => 'photo_booth_luxury'])->assertRedirect()->assertSessionHasNoErrors();

        $again = $this->get($this->url('review'))->assertOk();
        $this->assertSame(1, substr_count($again->getContent(), 'data-testid="template-selected"'));
        $this->assertMatchesRegularExpression('/rv-template\s+is-selected\s*" data-template-card="photo_booth_luxury"/', $again->getContent());
        $this->assertEquals($before->answers, $this->wizardSession()->answers, 'Choosing a design never touches an answer.');
    }
}
