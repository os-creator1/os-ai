<?php

namespace Tests\Feature\Website\GuidedGeneration;

use App\Library\Website\GuidedGeneration\GuidedGenerationOutputValidator;
use App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Regression: the guided prompt never described the section wire format, so models returned
 * flat/free-form sections and every page failed "pages.N.sections.N" validation. The prompt now
 * states the exact {"type","data"} shape; the validators stay strict. No database, no AI calls.
 */
class GuidedGenerationSectionSchemaTest extends TestCase
{
    private function plan(): array
    {
        $allowed = ['hero', 'text', 'image_text', 'services', 'faq', 'cta', 'contact_details'];

        return [
            ['page_key' => 'home', 'page_type' => 'home', 'is_home' => true, 'slug' => null, 'title' => 'Home', 'allowed_section_types' => $allowed, 'entity' => null],
            ['page_key' => 'contact', 'page_type' => 'contact', 'is_home' => false, 'slug' => 'photo-booth-contact', 'title' => 'Contact', 'allowed_section_types' => ['hero', 'contact_details'], 'entity' => null],
        ];
    }

    private function page(string $key, string $title, array $sections): array
    {
        return ['page_key' => $key, 'title' => $title, 'seo_title' => $title . ' | Kaleb Hill', 'meta_description' => 'About the ' . $title . ' page.', 'sections' => $sections];
    }

    private function hero(string $heading): array
    {
        return ['type' => 'hero', 'data' => ['heading' => $heading, 'subheading' => 'Photo booths', 'primary_cta' => ['label' => 'Contact', 'url' => '/photo-booth-contact'], 'secondary_cta' => null]];
    }

    private function errors(array $pages): string
    {
        try {
            app(GuidedGenerationOutputValidator::class)->validate($pages, $this->plan());
        } catch (ValidationException $e) {
            return json_encode($e->errors());
        }

        return '';
    }

    public function test_prompt_states_the_type_data_envelope_and_only_the_allowed_schemas(): void
    {
        $text = implode("\n", GuidedWebsiteGenerationClient::sectionFormatInstructions($this->plan()));

        $this->assertStringContainsString('{"type": <section type>, "data": {...}}', $text);
        $this->assertStringContainsString('exactly one "hero"', $text);
        foreach (['hero', 'text', 'image_text', 'services', 'faq', 'cta', 'contact_details'] as $type) {
            $this->assertStringContainsString('{"type":"' . $type . '"', $text);
        }
        // Types the AI may never author are never described.
        foreach (['gallery', 'video', 'form', 'backdrops', 'custom_section', 'testimonials'] as $type) {
            $this->assertStringNotContainsString('{"type":"' . $type . '"', $text);
        }
    }

    public function test_a_batch_in_the_documented_shape_passes_validation(): void
    {
        $pages = [
            $this->page('home', 'Home', [
                $this->hero('Photo booths for your event'),
                ['type' => 'text', 'data' => ['heading' => 'Welcome', 'body' => 'We bring a photo booth to your event.']],
                ['type' => 'image_text', 'data' => ['heading' => 'Our booths', 'body' => 'Fun for every guest.', 'image' => null, 'image_position' => 'left']],
                ['type' => 'services', 'data' => ['heading' => 'Services', 'items' => [['name' => 'Photo booth rental', 'description' => 'Hourly rental.', 'price_label' => null]]]],
                ['type' => 'faq', 'data' => ['heading' => null, 'items' => [['question' => 'Do you travel?', 'answer' => 'Ask us about your area.']]]],
                ['type' => 'cta', 'data' => ['heading' => 'Book now', 'body' => null, 'buttons' => [['label' => 'Contact us', 'url' => '/photo-booth-contact']]]],
            ]),
            $this->page('contact', 'Contact', [
                $this->hero('Contact us'),
                ['type' => 'contact_details', 'data' => ['show_phone' => true, 'show_email' => true, 'show_address' => false]],
            ]),
        ];

        $this->assertSame('', $this->errors($pages));
    }

    public function test_flat_sections_without_a_data_object_are_rejected(): void
    {
        $pages = [
            $this->page('home', 'Home', [$this->hero('Home'), ['type' => 'text', 'heading' => 'Welcome', 'body' => 'Flat, not nested.']]),
            $this->page('contact', 'Contact', [$this->hero('Contact')]),
        ];

        $this->assertStringContainsString('pages.0.sections.1', $this->errors($pages));
    }

    public function test_wrong_field_names_unknown_types_and_ai_asset_references_are_rejected(): void
    {
        $wrongField = [
            $this->page('home', 'Home', [$this->hero('Home'), ['type' => 'text', 'data' => ['heading' => 'Hi', 'content' => 'wrong field name']]]),
            $this->page('contact', 'Contact', [$this->hero('Contact')]),
        ];
        $this->assertStringContainsString('pages.0.sections.1', $this->errors($wrongField));

        $unknown = [
            $this->page('home', 'Home', [$this->hero('Home'), ['type' => 'banner', 'data' => ['heading' => 'x']]]),
            $this->page('contact', 'Contact', [$this->hero('Contact')]),
        ];
        $this->assertStringContainsString('pages.0.sections.1', $this->errors($unknown));

        $asset = [
            $this->page('home', 'Home', [$this->hero('Home'), ['type' => 'image_text', 'data' => ['body' => 'x', 'image' => 'some-asset-uid', 'image_position' => 'left']]]),
            $this->page('contact', 'Contact', [$this->hero('Contact')]),
        ];
        $this->assertStringContainsString('pages.0.sections.1', $this->errors($asset));
    }
}
