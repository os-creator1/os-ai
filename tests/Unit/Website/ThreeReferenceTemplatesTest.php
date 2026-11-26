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
}
