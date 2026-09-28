<?php

namespace Tests\Feature\Marketing;

use App\Models\MarketingFaq;
use App\Models\MarketingTestimonial;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Settings\Concerns\SettingsTestHelpers;
use Tests\TestCase;

/**
 * Public Marketing Homepage contract — the one small, safe admin surface
 * for owner-editable homepage copy. Gated by the existing 'general
 * settings' ability, same as branding (SettingsTestHelpers is that
 * suite's own established actingAsAdmin()/permission fixture pattern).
 */
class MarketingContentAdminTest extends TestCase
{
    use RefreshDatabase;
    use SettingsTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();

        $this->consumeSuperAdminId();
        $this->ensureRequiredAppConfigRowsExist();
    }

    public function test_index_requires_the_general_settings_ability(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.marketing-content.index'))->assertUnauthorized();
    }

    public function test_owner_can_view_the_marketing_content_page(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->get(route('admin.marketing-content.index'))->assertOk();
    }

    public function test_owner_can_save_the_hero_copy(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post(route('admin.marketing-content.hero.update'), [
            'hero_headline' => 'New headline',
            'hero_subheadline' => 'New subheadline',
        ])->assertRedirect(route('admin.marketing-content.index'));

        $this->assertDatabaseHas('marketing_content_settings', [
            'hero_headline' => 'New headline',
            'hero_subheadline' => 'New subheadline',
        ]);
    }

    public function test_owner_can_add_edit_and_remove_a_faq(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post(route('admin.marketing-content.faqs.store'), [
            'question' => 'Do you support Photo Booth businesses?',
            'answer' => 'Yes.',
            'is_visible' => '1',
        ])->assertRedirect(route('admin.marketing-content.index'));

        $faq = MarketingFaq::query()->firstOrFail();
        $this->assertSame('Do you support Photo Booth businesses?', $faq->question);
        $this->assertTrue($faq->is_visible);

        $this->put(route('admin.marketing-content.faqs.update', $faq), [
            'question' => 'Updated question?',
            'answer' => 'Updated answer.',
            'is_visible' => '0',
        ])->assertRedirect(route('admin.marketing-content.index'));

        $faq->refresh();
        $this->assertSame('Updated question?', $faq->question);
        $this->assertFalse($faq->is_visible);

        $this->delete(route('admin.marketing-content.faqs.destroy', $faq))
            ->assertRedirect(route('admin.marketing-content.index'));

        $this->assertDatabaseMissing('marketing_faqs', ['id' => $faq->id]);
    }

    public function test_a_new_testimonial_requires_a_poster_image(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Jamie Rivera',
            'business_context_label' => 'Feedback from an earlier photo booth business',
        ])->assertSessionHasErrors('poster_image');

        $this->assertDatabaseCount('marketing_testimonials', 0);
    }

    public function test_owner_can_add_and_remove_a_testimonial_with_a_poster_image(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $poster = UploadedFile::fake()->image('poster.png', 400, 300);

        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Jamie Rivera',
            'business_context_label' => 'Feedback from an earlier photo booth business',
            'is_visible' => '1',
            'poster_image' => $poster,
        ])->assertRedirect(route('admin.marketing-content.index'));

        $testimonial = MarketingTestimonial::query()->firstOrFail();
        $this->assertSame('Jamie Rivera', $testimonial->name);
        $this->assertNotNull($testimonial->poster_image_path);
        $this->assertFileExists(public_path($testimonial->poster_image_path));

        $storedPath = public_path($testimonial->poster_image_path);

        $this->delete(route('admin.marketing-content.testimonials.destroy', $testimonial))
            ->assertRedirect(route('admin.marketing-content.index'));

        $this->assertDatabaseMissing('marketing_testimonials', ['id' => $testimonial->id]);
        $this->assertFileDoesNotExist($storedPath);
    }
}
