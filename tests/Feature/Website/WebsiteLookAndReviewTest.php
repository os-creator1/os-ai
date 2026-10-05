<?php

namespace Tests\Feature\Website;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Library\Website\Design\WebsiteDesigns;
use App\Models\Website;
use App\Models\WebsiteAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Website\Concerns\CreatesWebsiteFixtures;
use Tests\Feature\Website\Concerns\DrivesSmartWizard;
use Tests\TestCase;

/**
 * Website V1 final — the Review screen's template choice (four REAL
 * previews, changeable without losing an answer), the brand colour / logo /
 * hero image, the real file-picker upload path (persistence, preview,
 * alt text, invalid and foreign files), the plan summary, and the
 * post-generation rule: a generated website changes layout only through the
 * rebuild flow.
 */
class WebsiteLookAndReviewTest extends TestCase
{
    use RefreshDatabase;
    use CreatesWebsiteFixtures;
    use DrivesSmartWizard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSmartWizard();
    }

    /** @return array{0: \App\Models\Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: Website} */
    private function atReview(): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $this->completeV2Setup($workspace, $business);

        return [$customer, $business, $workspace, Website::where('business_id', $business->id)->sole()];
    }

    private function look(object $workspace, object $business, array $payload)
    {
        return $this->post($this->wizardUrl($workspace, $business, 'look.update'), $payload);
    }

    public function test_the_review_screen_shows_four_real_template_previews_with_the_default_selected(): void
    {
        [, $business, $workspace, $website] = $this->atReview();

        $response = $this->get($this->wizardUrl($workspace, $business, 'setup.review'))->assertOk();

        $response->assertSee('data-testid="look-panel"', false);
        foreach (WebsiteDesigns::all() as $templateKey => $design) {
            $response->assertSee('Template ' . $design->number, false);
            $response->assertSee($design->label);
            // The preview is the real renderer for this business (embedded, so styled with no extra request).
            $response->assertSee('wd wd-' . $design->key, false);
            $response->assertSee('data-template-card="' . $templateKey . '"', false);
        }
        $response->assertSee('Snap Booth Co');
        $response->assertSee('Open full preview');
        $this->assertSame('photo_booth_modern', $website->template_key, 'Template 1 is the default.');
        $this->assertSame(1, substr_count($response->getContent(), 'data-testid="template-selected"'));
    }

    public function test_the_review_screen_explains_the_website_plan(): void
    {
        [, $business, $workspace] = $this->atReview();

        $response = $this->get($this->wizardUrl($workspace, $business, 'setup.review'))->assertOk();

        $response->assertSee('data-testid="website-plan"', false);
        $response->assertSee('Your website plan');
        $response->assertSee('pages will be built');
        $response->assertSee('Service areas saved 3 / local pages planned 3');
        $response->assertSee('Open Air Booth');
        $response->assertSee('Weddings');
        $response->assertSee('Gallery');
        $response->assertSee('Add at least 6 gallery photos to get a Gallery page.');
    }

    public function test_changing_the_template_on_review_never_touches_an_answer(): void
    {
        [, $business, $workspace, $website] = $this->atReview();
        $before = $this->activeResponse($business);

        $this->look($workspace, $business, ['template_key' => 'photo_booth_conversion'])->assertRedirect()->assertSessionHas('status', 'success');

        $this->assertSame('photo_booth_conversion', $website->fresh()->template_key);
        $this->assertSame('#f4a28f', $website->fresh()->theme['primary_color'], 'The template\'s own theme is applied.');
        $after = $this->activeResponse($business);
        $this->assertSame($before->id, $after->id, 'No second setup is created.');
        $this->assertEquals($before->answers, $after->answers);
        $this->assertSame($before->answers_revision, $after->answers_revision);
        $this->assertSame(0, $website->pages()->count());

        $this->get($this->wizardUrl($workspace, $business, 'setup.review'))->assertSee('data-testid="template-selected"', false);
    }

    public function test_an_unknown_template_is_refused(): void
    {
        [, $business, $workspace, $website] = $this->atReview();

        $this->look($workspace, $business, ['template_key' => 'does_not_exist'])->assertSessionHasErrors('template_key');
        $this->assertSame('photo_booth_modern', $website->fresh()->template_key);
    }

    public function test_the_brand_colour_is_validated_normalised_and_clearable(): void
    {
        [, $business, $workspace, $website] = $this->atReview();

        foreach (['red', 'javascript:alert(1)', '#12', 'url(x)'] as $bad) {
            $this->look($workspace, $business, ['brand_color' => $bad])->assertSessionHasErrors('brand_color');
            $this->assertArrayNotHasKey('brand_color', $website->fresh()->theme);
        }

        $this->look($workspace, $business, ['brand_color' => '#F50'])->assertSessionHasNoErrors();
        $this->assertSame('#ff5500', $website->fresh()->theme['brand_color']);

        $this->look($workspace, $business, ['brand_color' => ''])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('brand_color', $website->fresh()->theme);
    }

    public function test_a_logo_picked_from_a_file_input_is_stored_previewed_given_alt_text_and_replaceable(): void
    {
        [, $business, $workspace, $website] = $this->atReview();

        $this->look($workspace, $business, ['logo' => $this->fakeImageUpload('logo.png'), 'logo_alt' => 'Snap Booth Co logo'])->assertSessionHasNoErrors();

        $asset = WebsiteAsset::where('website_id', $website->id)->where('purpose', WebsiteAssetPurpose::Logo->value)->sole();
        $this->assertSame('Snap Booth Co logo', $asset->alt_text);
        $this->assertSame($asset->uid, $website->fresh()->theme['logo_asset_uid']);
        $this->assertFileExists(public_path($asset->path));
        $this->assertSame(0, $website->assets()->where('purpose', WebsiteAssetPurpose::Gallery->value)->count(), 'A logo is never a gallery photo.');

        $review = $this->get($this->wizardUrl($workspace, $business, 'setup.review'));
        $review->assertSee('data-testid="look-logo-preview"', false);
        $review->assertSee($asset->url(), false);

        // Replacing it removes the unpublished old file.
        $this->look($workspace, $business, ['logo' => $this->fakeImageUpload('logo2.png', $this->validPngBytes() . 'x'), 'logo_alt' => 'New logo']);
        $replacement = WebsiteAsset::where('website_id', $website->id)->where('purpose', WebsiteAssetPurpose::Logo->value)->sole();
        $this->assertNotSame($asset->uid, $replacement->uid);
        $this->assertFileDoesNotExist(public_path($asset->path));
        $this->assertNull(WebsiteAsset::where('uid', $asset->uid)->first());

        $this->look($workspace, $business, ['remove_logo' => '1']);
        $this->assertArrayNotHasKey('logo_asset_uid', $website->fresh()->theme);
        $this->assertSame(0, WebsiteAsset::where('website_id', $website->id)->where('purpose', WebsiteAssetPurpose::Logo->value)->count());

        @unlink(public_path($replacement->path));
    }

    public function test_a_hero_image_is_stored_with_its_own_purpose_and_alt_text(): void
    {
        [, $business, $workspace, $website] = $this->atReview();

        $this->look($workspace, $business, ['hero' => $this->fakeImageUpload('hero.png'), 'hero_alt' => 'Guests laughing in a mirror booth'])->assertSessionHasNoErrors();

        $asset = WebsiteAsset::where('website_id', $website->id)->where('purpose', WebsiteAssetPurpose::Hero->value)->sole();
        $this->assertSame('Guests laughing in a mirror booth', $asset->alt_text);
        $this->assertSame($asset->uid, $website->fresh()->theme['hero_asset_uid']);

        @unlink(public_path($asset->path));
    }

    public function test_an_invalid_file_is_rejected_and_nothing_is_stored(): void
    {
        [, $business, $workspace, $website] = $this->atReview();

        $notAnImage = UploadedFile::fake()->createWithContent('logo.png', 'this is plain text, not an image');
        $this->look($workspace, $business, ['logo' => $notAnImage])->assertSessionHasErrors('logo');

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->look($workspace, $business, ['hero' => $svg])->assertSessionHasErrors('hero');

        $this->assertSame(0, $website->assets()->count());
        $this->assertArrayNotHasKey('logo_asset_uid', $website->fresh()->theme);
        $this->assertArrayNotHasKey('hero_asset_uid', $website->fresh()->theme);
    }

    public function test_another_tenant_can_neither_change_the_look_nor_see_the_preview_nor_touch_a_foreign_asset(): void
    {
        [, $business, $workspace, $website] = $this->atReview();
        $this->look($workspace, $business, ['hero' => $this->fakeImageUpload('hero.png')]);
        $heroUid = $website->fresh()->theme['hero_asset_uid'];

        [$intruder] = $this->entitledTenant();
        $this->authenticateAsCustomer($intruder);

        $this->look($workspace, $business, ['brand_color' => '#000000'])->assertNotFound();
        $this->get($this->wizardUrl($workspace, $business, 'template-preview', ['photo_booth_modern']))->assertNotFound();
        $this->post($this->wizardUrl($workspace, $business, 'setup.gallery.update', [$heroUid]), ['alt_text' => 'hijack'])->assertNotFound();
        $this->assertArrayNotHasKey('brand_color', $website->fresh()->theme);

        @unlink(public_path(WebsiteAsset::where('uid', $heroUid)->value('path')));
    }

    public function test_the_faq_step_offers_the_niches_common_questions_without_writing_any_answer(): void
    {
        [, $business, $workspace] = $this->atReview();

        $response = $this->get($this->wizardUrl($workspace, $business, 'setup.step', ['faq_items']))->assertOk();

        $response->assertSee('data-testid="faq-suggestions"', false);
        $response->assertSee('How far in advance should I book?');
        $response->assertSee('data-faq-suggestion=', false);
        $response->assertDontSee('Book at least');
        $this->assertSame([], \App\Library\Website\Setup\NicheFaqSuggestions::for('plumbing_service'), 'A niche with no defaults offers none.');
    }

    public function test_the_full_template_preview_renders_the_owners_business_in_that_template(): void
    {
        [, $business, $workspace] = $this->atReview();

        $this->get($this->wizardUrl($workspace, $business, 'template-preview', ['photo_booth_luxury']))
            ->assertOk()
            ->assertSee('wd wd-luxury', false)
            ->assertSee('Snap Booth Co')
            ->assertSee('Sample preview')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        $this->get($this->wizardUrl($workspace, $business, 'template-preview', ['nope']))->assertNotFound();
    }

    public function test_after_generation_the_layout_changes_only_through_the_rebuild_flow(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $website = app(\App\Library\Website\WebsiteStarterDraftService::class)->createShellFromTemplate($business, \App\Models\WebsiteTemplate::findActiveOrFail('photo_booth_modern'));
        $this->homePage($website);

        // Brand tokens are safe to change at any time...
        $this->look($workspace, $business, ['brand_color' => '#123456'])->assertSessionHasNoErrors();
        $this->assertSame('#123456', $website->fresh()->theme['brand_color']);

        // ...but a built website's template is not swapped from the look form.
        $this->look($workspace, $business, ['template_key' => 'photo_booth_luxury'])->assertSessionHasErrors('template_key');
        $this->assertSame('photo_booth_modern', $website->fresh()->template_key);

        // The rebuild flow shows four real previews and an unmissable layout-change warning.
        $form = $this->get($this->wizardUrl($workspace, $business, 'rebuild.form'))->assertOk();
        $form->assertSee('data-testid="layout-change-warning"', false);
        $form->assertSee("A different template changes your website's layout.", false);
        foreach (WebsiteDesigns::all() as $design) {
            $form->assertSee('wd wd-' . $design->key, false);
        }
        $form->assertSee('data-testid="template-current"', false);

        // It must be confirmed.
        $this->post($this->wizardUrl($workspace, $business, 'rebuild'), ['template_key' => 'photo_booth_luxury', 'mode' => 'look_only'])->assertSessionHasErrors('confirm_rebuild');
        $this->assertSame('photo_booth_modern', $website->fresh()->template_key);

        // "Change the look only" keeps every page and the owner's tokens.
        $this->post($this->wizardUrl($workspace, $business, 'rebuild'), ['template_key' => 'photo_booth_luxury', 'mode' => 'look_only', 'confirm_rebuild' => '1'])->assertRedirect()->assertSessionHas('status', 'success');
        $fresh = $website->fresh();
        $this->assertSame('photo_booth_luxury', $fresh->template_key);
        $this->assertSame(1, $fresh->pages()->count());
        $this->assertSame('#123456', $fresh->theme['brand_color']);
        $this->assertNull($fresh->published_revision_id, 'Nothing is published by a template change.');
    }

    public function test_studio_shows_the_template_the_look_card_and_website_health(): void
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->authenticateAsCustomer($customer);
        $website = app(\App\Library\Website\WebsiteStarterDraftService::class)->createShellFromTemplate($business, \App\Models\WebsiteTemplate::findActiveOrFail('photo_booth_editorial'));
        $this->homePage($website, ['seo_title' => 'Home', 'meta_description' => 'Welcome']);

        $this->get($this->wizardUrl($workspace, $business, 'studio.show'))
            ->assertOk()
            ->assertSee('data-testid="studio-look"', false)
            ->assertSee('Template 2')
            ->assertSee('Classic Gold')
            ->assertSee('Change template or rebuild')
            ->assertSee('data-testid="website-health"', false)
            ->assertSee('SEO &amp; Website Health', false);
    }
}
