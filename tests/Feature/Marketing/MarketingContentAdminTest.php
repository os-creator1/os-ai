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

    /**
     * Reads the raw bytes of a fake generated image. Must hold the fake
     * UploadedFile in a local variable rather than chaining
     * ->getRealPath() inline — otherwise it can be garbage-collected (and
     * its backing temp file removed) before file_get_contents() runs.
     */
    private function fakeImageBytes(int $width = 400, int $height = 300): string
    {
        $file = UploadedFile::fake()->image('poster.png', $width, $height);

        return file_get_contents($file->getRealPath());
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

    public function test_owner_can_add_a_testimonial_with_only_a_youtube_link_and_no_poster(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Jamie Rivera',
            'business_context_label' => 'Feedback from an earlier photo booth business',
            'video_url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'is_visible' => '1',
        ])->assertRedirect(route('admin.marketing-content.index'));

        $testimonial = MarketingTestimonial::query()->firstOrFail();
        $this->assertNull($testimonial->poster_image_path);
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $testimonial->video_url);
        $this->assertSame('dQw4w9WgXcQ', $testimonial->youtubeVideoId());
    }

    public function test_a_malformed_youtube_link_is_rejected_even_with_no_poster_required(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Jamie Rivera',
            'business_context_label' => 'Feedback from an earlier photo booth business',
            'video_url' => 'https://www.youtube.com/watch?v=tooshort',
        ])->assertSessionHasErrors('video_url');

        $this->assertDatabaseCount('marketing_testimonials', 0);
    }

    public function test_a_non_youtube_video_link_still_requires_a_poster(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Jamie Rivera',
            'business_context_label' => 'Feedback from an earlier photo booth business',
            'video_url' => 'https://videos.example.test/feedback.mp4',
        ])->assertSessionHasErrors('poster_image');

        $this->assertDatabaseCount('marketing_testimonials', 0);
    }

    /**
     * Review correction: MarketingTestimonialAssetService names poster
     * files by content hash, so re-uploading identical bytes produces the
     * SAME path as the one already saved. The old code deleted "the
     * previous path" unconditionally after saving the new one, which — when
     * they are the same path — deleted the file the row still points at.
     */
    public function test_reuploading_the_identical_poster_image_does_not_break_it(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $bytes = $this->fakeImageBytes(400, 300);

        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Jamie Rivera',
            'business_context_label' => 'Feedback from an earlier photo booth business',
            'poster_image' => UploadedFile::fake()->createWithContent('poster-a.png', $bytes),
        ])->assertRedirect(route('admin.marketing-content.index'));

        $testimonial = MarketingTestimonial::query()->firstOrFail();
        $originalPath = $testimonial->poster_image_path;
        $this->assertFileExists(public_path($originalPath));

        // Re-upload of the byte-identical image under a different filename —
        // the content hash, and therefore the stored path, is unchanged.
        $this->post(route('admin.marketing-content.testimonials.update', $testimonial), [
            'name' => 'Jamie Rivera',
            'business_context_label' => 'Feedback from an earlier photo booth business',
            'poster_image' => UploadedFile::fake()->createWithContent('poster-b.png', $bytes),
        ])->assertRedirect(route('admin.marketing-content.index'));

        $testimonial->refresh();
        $this->assertSame($originalPath, $testimonial->poster_image_path);
        $this->assertFileExists(public_path($originalPath));
    }

    /**
     * Review correction: two testimonials that happen to share the exact
     * same uploaded photo (identical bytes, identical hashed filename)
     * must not have that file deleted out from under the surviving one.
     */
    public function test_deleting_one_of_two_testimonials_sharing_a_poster_keeps_the_others_image(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $bytes = $this->fakeImageBytes(400, 300);

        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Testimonial A',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image' => UploadedFile::fake()->createWithContent('poster-a.png', $bytes),
        ])->assertRedirect();
        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Testimonial B',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image' => UploadedFile::fake()->createWithContent('poster-b.png', $bytes),
        ])->assertRedirect();

        $a = MarketingTestimonial::query()->where('name', 'Testimonial A')->firstOrFail();
        $b = MarketingTestimonial::query()->where('name', 'Testimonial B')->firstOrFail();
        $this->assertSame($a->poster_image_path, $b->poster_image_path);
        $sharedPath = $a->poster_image_path;

        $this->delete(route('admin.marketing-content.testimonials.destroy', $a))
            ->assertRedirect(route('admin.marketing-content.index'));

        $this->assertDatabaseMissing('marketing_testimonials', ['id' => $a->id]);
        $b->refresh();
        $this->assertSame($sharedPath, $b->poster_image_path);
        $this->assertFileExists(public_path($sharedPath));
    }

    /**
     * Same sharing hazard, on the update path: replacing one testimonial's
     * poster with a different photo must not delete the shared file the
     * other testimonial still uses.
     */
    public function test_replacing_a_shared_poster_on_one_testimonial_keeps_the_others_image(): void
    {
        $this->actingAsAdmin(['access backend', 'general settings']);

        $sharedBytes = $this->fakeImageBytes(400, 300);
        $newBytes = $this->fakeImageBytes(500, 400);

        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Testimonial A',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image' => UploadedFile::fake()->createWithContent('poster-a.png', $sharedBytes),
        ])->assertRedirect();
        $this->post(route('admin.marketing-content.testimonials.store'), [
            'name' => 'Testimonial B',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image' => UploadedFile::fake()->createWithContent('poster-b.png', $sharedBytes),
        ])->assertRedirect();

        $a = MarketingTestimonial::query()->where('name', 'Testimonial A')->firstOrFail();
        $b = MarketingTestimonial::query()->where('name', 'Testimonial B')->firstOrFail();
        $sharedPath = $a->poster_image_path;

        $this->post(route('admin.marketing-content.testimonials.update', $a), [
            'name' => 'Testimonial A',
            'business_context_label' => 'Feedback from an earlier business',
            'poster_image' => UploadedFile::fake()->createWithContent('poster-new.png', $newBytes),
        ])->assertRedirect(route('admin.marketing-content.index'));

        $a->refresh();
        $b->refresh();
        $this->assertNotSame($sharedPath, $a->poster_image_path);
        $this->assertFileExists(public_path($a->poster_image_path));
        $this->assertSame($sharedPath, $b->poster_image_path);
        $this->assertFileExists(public_path($sharedPath));
    }
}
