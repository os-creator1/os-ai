<?php

namespace Tests\Unit\Website;

use App\Library\Website\Design\WebsiteDesigns;
use App\Models\WebsiteTemplate;
use Database\Seeders\WebsiteTemplateSeeder;
use Tests\TestCase;

/**
 * Three additive, deterministic Photo Booth reference families. They are
 * never added to all() or substituted for the published legacy designs.
 */
final class ThreeReferenceTemplatesTest extends TestCase
{
    private const EXPECTED = [
        'photo_booth_reference_kaleb' => ['reference-kaleb', 'modern'],
        'photo_booth_reference_booths' => ['reference-booths', 'modern'],
        'photo_booth_reference_greenbelt' => ['reference-greenbelt', 'conversion'],
    ];

    public function test_reference_variants_are_seeded_with_bounded_themes_and_existing_designs_are_unchanged(): void
    {
        $this->assertCount(4, WebsiteDesigns::all());
        $templates = array_column(WebsiteTemplateSeeder::templates(), null, 'key');

        foreach (self::EXPECTED as $templateKey => [$variant, $base]) {
            $this->assertArrayHasKey($templateKey, $templates);
            $this->assertTrue($templates[$templateKey]['is_active']);
            $this->assertSame('photo_booth_service', $templates[$templateKey]['niche_key']);
            $this->assertSame($variant, $templates[$templateKey]['theme']['header_variant']);
            $this->assertSame($variant, $templates[$templateKey]['theme']['footer_variant']);
            $this->assertContains($variant, WebsiteTemplate::ALLOWED_THEME_VALUES['header_variant']);

            $design = WebsiteDesigns::forTemplateKey($templateKey);
            $this->assertSame($variant, $design->key);
            $this->assertSame($base, $design->variant('visual_base'));
            $this->assertSame('authored', $design->variant('home_sequence'));
            $this->assertSame($templateKey, WebsiteDesigns::resolve('photo_booth_modern', ['header_variant' => $variant])->templateKey);
            $this->assertArrayNotHasKey($templateKey, WebsiteDesigns::all());
        }

        $this->assertSame('photo_booth_editorial', WebsiteDesigns::resolve('photo_booth_editorial', ['header_variant' => 'editorial'])->templateKey);
        $this->assertSame('photo_booth_emerald', WebsiteDesigns::resolve('photo_booth_emerald', ['header_variant' => 'emerald'])->templateKey);
    }

    public function test_kaleb_light_testimonial_band_defines_dark_foreground_and_muted_text(): void
    {
        $css = file_get_contents(public_path('css/website-design.css'));
        $this->assertNotFalse($css);
        $this->assertSame(1, preg_match('/\\.wd-reference-kaleb \\.wd-band-testimonials\\s*\\{([^}]+)\\}/s', $css, $matches));
        $this->assertStringContainsString('--wd-t-bg: #f9f4e8;', $matches[1]);
        $this->assertStringContainsString('--wd-t-ink: #1c1b19;', $matches[1]);
        $this->assertStringContainsString('--wd-t-muted: #555046;', $matches[1]);
        $this->assertStringContainsString('--wd-a: #846200;', $matches[1]);
    }

    public function test_reference_home_preserves_interleaved_blocks_and_pins_trust_strip_below_hero(): void
    {
        $sections = [
            ['type' => 'hero'],
            ['type' => 'image_text'],
            ['type' => 'services', 'data' => ['role' => 'events']],
            ['type' => 'text'],
            ['type' => 'testimonials'],
            ['type' => 'services', 'data' => ['role' => 'packages']],
            ['type' => 'faq'],
            ['type' => 'cta'],
        ];

        foreach (array_keys(self::EXPECTED) as $key) {
            $design = WebsiteDesigns::forTemplateKey($key);
            $this->assertSame($sections, $design->orderHomeSections($sections), $key);

            $withStrip = $sections;
            $withStrip[] = ['type' => 'trust_strip'];
            $ordered = $design->orderHomeSections($withStrip);

            $this->assertSame(['hero', 'trust_strip', 'image_text', 'services', 'text', 'testimonials', 'services', 'faq', 'cta'], array_column($ordered, 'type'));
        }
    }

    public function test_questionnaire_collects_independent_hero_and_section_heading_answers_all_optional(): void
    {
        $steps = collect(\Database\Seeders\PhotoboothWebsiteSetupQuestionnaireV3Seeder::steps())->keyBy('key');
        $keys = ['hero_headline', 'hero_subtitle', 'hero_focus', 'intro_heading', 'services_heading', 'features_heading', 'events_heading',
            'packages_heading', 'gallery_heading', 'reviews_heading', 'areas_heading', 'faq_heading', 'closing_heading'];

        foreach ($keys as $key) {
            $this->assertTrue($steps->has($key), "Missing questionnaire step {$key}");
            $this->assertFalse($steps[$key]['required'], "{$key} must stay optional so older flows and blank answers keep deterministic defaults");
            $this->assertSame('answers', $steps[$key]['target_module']);
        }

        $this->assertSame(['center', 'top', 'bottom', 'left', 'right'], array_keys($steps['hero_focus']['options']));
        $this->assertSame('hero_copy', $steps['hero_headline']['screen']);
        $this->assertSame('section_headings', $steps['faq_heading']['screen']);
    }

    public function test_reference_css_uses_the_measured_reference_values(): void
    {
        $css = (string) file_get_contents(public_path('css/website-design.css'));
        $this->assertStringContainsString('--wd-btn-radius: 75px;', $css);          // Kaleb pill buttons
        $this->assertStringContainsString('--wd-announce-bg: #fac815;', $css);      // Kaleb yellow announcement bar
        $this->assertStringContainsString('linear-gradient(90deg, #188bf6, #00c9ff)', $css); // Booths gradient buttons
        $this->assertStringContainsString('--wd-head-weight: 900;', $css);          // Greenbelt heavy uppercase headings
        $this->assertStringContainsString('--wd-btn-radius: 9999px;', $css);        // Greenbelt peach pills
    }
}
