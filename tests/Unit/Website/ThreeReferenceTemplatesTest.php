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
        $this->assertStringContainsString('--wd-t-bg: #ffffff;', $matches[1]);
        $this->assertStringContainsString('--wd-t-ink: #000000;', $matches[1]);
        $this->assertStringContainsString('--wd-t-muted: #333333;', $matches[1]);
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
            'packages_heading', 'gallery_heading', 'reviews_heading', 'areas_heading', 'faq_heading', 'closing_heading',
            'announcement_text', 'hero_eyebrow', 'founder_name', 'google_rating', 'google_review_count'];

        foreach ($keys as $key) {
            $this->assertTrue($steps->has($key), "Missing questionnaire step {$key}");
            $this->assertFalse($steps[$key]['required'], "{$key} must stay optional so older flows and blank answers keep deterministic defaults");
            $this->assertSame('answers', $steps[$key]['target_module']);
        }

        $this->assertSame(['center', 'top', 'bottom', 'left', 'right'], array_keys($steps['hero_focus']['options']));
        foreach ($keys as $key) {
            $this->assertArrayNotHasKey('screen', $steps[$key], 'one question per page outside the basics');
        }
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

    public function test_inquiry_label_normalisation_applies_only_to_reference_designs(): void
    {
        $resolver = app(\App\Library\Website\Design\WebsiteCtaResolver::class);
        $authored = ['label' => 'Check Availability', 'url' => '/photo-booth-contact'];
        $siteCta = ['kind' => 'contact_page', 'label' => 'Contact us', 'url' => '/contact-here'];
        $pageUrls = ['photo-booth-contact' => '/contact-here'];

        // A canonical template keeps the button its owner/generator wrote, whatever the site CTA is.
        $this->assertSame(['label' => 'Check Availability', 'url' => '/contact-here'], $resolver->sectionCta($authored, $siteCta, $pageUrls));
        // A reference design never advertises Check Availability unless the resolved site CTA itself is the live-form CTA.
        $this->assertSame(['label' => 'Contact us', 'url' => '/contact-here'], $resolver->sectionCta($authored, $siteCta, $pageUrls, true));
        $live = ['kind' => 'form', 'label' => 'Check Availability', 'url' => '/contact-here'];
        $this->assertSame(['label' => 'Check Availability', 'url' => '/contact-here'], $resolver->sectionCta($authored, $live, $pageUrls, true));
    }

    public function test_greenbelt_featured_package_card_is_never_white_text_on_white(): void
    {
        $css = (string) file_get_contents(public_path('css/website-design.css'));
        $this->assertSame(1, preg_match('/\.wd-reference-greenbelt \.wd-ref-packages \.wd-card-featured\s*\{([^}]+)\}/', $css, $card));
        $this->assertStringContainsString('background: #2a1d45;', $card[1]);
        $this->assertStringContainsString('color: #ffffff;', $card[1]);
        $this->assertSame(1, preg_match('/\.wd-reference-greenbelt \.wd-ref-packages \.wd-card-featured p[^{]*\{([^}]+)\}/', $css, $text));
        $this->assertStringContainsString('color: #ffffff;', $text[1], 'description and price must stay light on the dark featured card');
    }

    public function test_greenbelt_founder_panel_uses_dark_text_on_the_peach_background(): void
    {
        $css = (string) file_get_contents(public_path('css/website-design.css'));
        $this->assertStringContainsString('.wd-reference-greenbelt .wd-band.wd-ref-founder .wd-section-title', $css);
        $this->assertSame(1, preg_match('/\.wd-reference-greenbelt \.wd-band\.wd-ref-founder \.wd-section-title[^{]*\{([^}]+)\}/', $css, $m));
        $this->assertStringContainsString('color: #2a1d45;', $m[1], 'founder heading and story are dark plum on peach (white on #f4a28f is ~2:1)');
        $this->assertStringNotContainsString('wd-ref-founder { background: #f4a28f; color: #fff; }', $css);
    }

    public function test_addon_bands_show_a_readable_price_and_no_per_item_button(): void
    {
        $css = (string) file_get_contents(public_path('css/website-design.css'));
        $this->assertStringContainsString('.wd-reference-booths .wd-band.wd-ref-addons .wd-card .wd-btn', $css);
        $this->assertSame(1, preg_match('/\.wd-reference-booths \.wd-band\.wd-ref-addons \.website-service-price[^{]*\{([^}]+)\}/', $css, $m));
        $this->assertStringContainsString('color: #ffffff;', $m[1], 'the add-on price must be light on the navy Booths band');
    }

    public function test_tablet_keeps_the_reference_structure_and_phones_reset_the_desktop_card_placement(): void
    {
        $css = (string) file_get_contents(public_path('css/website-design.css'));
        // Tablet (700-959px): the split hero stays side by side for Kaleb and Greenbelt, as on the references.
        $this->assertMatchesRegularExpression('/@media \(min-width: 700px\) and \(max-width: 959px\)\s*\{[^@]*wd-reference-kaleb \.wd-hero-is-split \.wd-hero-grid/s', $css);
        // Phones: the desktop "image col 1 / text col 2" placement must be reset or the image collapses to a dot at 320px.
        $this->assertMatchesRegularExpression('/@media \(max-width: 699px\)\s*\{[^@]*wd-reference-kaleb \.wd-ref-booths \.wd-card:nth-child\(even\) > :not\(img\):not\(picture\)[^{]*\{[^}]*grid-column: 1;/s', $css);
        // The inline header only starts where it fits (the nav lists measure 624-675px).
        $this->assertStringContainsString('@media (min-width: 920px) and (max-width: 959px)', $css);
    }
}
