<?php

namespace Database\Seeders;

use App\Models\WebsiteTemplate;
use Illuminate\Database\Seeder;

/**
 * Website Generator + Local SEO Completion. Seeds the exact four
 * operator-owned visual templates the product direction requires.
 * Internal `key`s are stable and customer-invisible; `display_name` is
 * neutral and customer-friendly (never a competitor's name). Each
 * template's presentation-variant values are drawn broadly from one of
 * four live photo-booth-rental reference sites' overall visual grammar
 * (dark high-contrast hero + teal accent; warm narrative serif
 * editorial; restrained gold/charcoal tiered-package luxury; punchy
 * red/blue event-type-picker conversion-first) — never their copy,
 * photos, logos, reviews, or literal CSS/markup. See docs/automation/
 * WEBSITE-GENERATOR-SEO-COMPLETION-NOTE.md for the exact mapping.
 *
 * `page_manifest.pages` lists every page TYPE a template supports and
 * which of the 10 existing WebsiteSectionType values that type may use
 * — WebsitePageStrategy (not this manifest) decides how many actual
 * pages of each type a given Business gets (one per real service, one
 * per eligible real location, etc.).
 */
class WebsiteTemplateSeeder extends Seeder
{
    public function run(): void
    {
        foreach (self::templates() as $attributes) {
            WebsiteTemplate::updateOrCreate(['key' => $attributes['key']], $attributes);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function templates(): array
    {
        $manifest = self::sharedPageManifest();

        return [
            [
                'key' => 'photo_booth_modern',
                'niche_key' => 'photo_booth_service',
                'display_name' => 'Modern',
                'description' => 'High-contrast, confident, and direct — a bold dark hero with a strong first impression.',
                'theme' => [
                    'font' => 'system',
                    'primary_color' => '#0ea5b0',
                    'secondary_color' => '#0b1220',
                    'button_style' => 'rounded',
                    'content_width' => '1160px',
                    'header_variant' => 'modern',
                    'footer_variant' => 'modern',
                ],
                'page_manifest' => $manifest,
                'manifest_version' => 1,
                'is_active' => true,
            ],
            [
                'key' => 'photo_booth_editorial',
                'niche_key' => 'photo_booth_service',
                'display_name' => 'Editorial',
                'description' => 'Warm, narrative-led, and generously spaced — serif headlines and a considered pace.',
                'theme' => [
                    'font' => 'serif',
                    'primary_color' => '#b6562c',
                    'secondary_color' => '#2b241d',
                    'button_style' => 'outline',
                    'content_width' => '1000px',
                    'header_variant' => 'editorial',
                    'footer_variant' => 'editorial',
                ],
                'page_manifest' => $manifest,
                'manifest_version' => 1,
                'is_active' => true,
            ],
            [
                'key' => 'photo_booth_luxury',
                'niche_key' => 'photo_booth_service',
                'display_name' => 'Luxury',
                'description' => 'Restrained and elegant — a muted gold and charcoal palette built around tiered packages.',
                'theme' => [
                    'font' => 'display',
                    'primary_color' => '#a8874f',
                    'secondary_color' => '#14110c',
                    'button_style' => 'pill',
                    'content_width' => '1080px',
                    'header_variant' => 'luxury',
                    'footer_variant' => 'luxury',
                ],
                'page_manifest' => $manifest,
                'manifest_version' => 1,
                'is_active' => true,
            ],
            [
                'key' => 'photo_booth_conversion',
                'niche_key' => 'photo_booth_service',
                'display_name' => 'Conversion',
                'description' => 'Punchy and direct — event-type quick-picks and a clear numbered booking process.',
                'theme' => [
                    'font' => 'system',
                    'primary_color' => '#ef4444',
                    'secondary_color' => '#111827',
                    'button_style' => 'solid',
                    'content_width' => '1120px',
                    'header_variant' => 'conversion',
                    'footer_variant' => 'conversion',
                ],
                'page_manifest' => $manifest,
                'manifest_version' => 1,
                'is_active' => true,
            ],
        ];
    }

    /**
     * SEO architecture must not depend on which template a customer
     * picked (task instruction) — all four templates therefore share
     * the exact same page-type/section-type manifest; only `theme`
     * differs between them.
     */
    private static function sharedPageManifest(): array
    {
        return [
            'pages' => [
                ['page_type' => 'home', 'is_home' => true, 'allowed_section_types' => ['hero', 'services', 'image_text', 'testimonials', 'faq', 'cta', 'contact_details', 'gallery'], 'default_section_order' => ['hero', 'services', 'image_text', 'testimonials', 'faq', 'cta', 'contact_details']],
                ['page_type' => 'services_overview', 'is_home' => false, 'allowed_section_types' => ['hero', 'services', 'cta', 'contact_details'], 'default_section_order' => ['hero', 'services', 'contact_details']],
                ['page_type' => 'service_detail', 'is_home' => false, 'allowed_section_types' => ['hero', 'text', 'image_text', 'services', 'testimonials', 'faq', 'cta', 'contact_details'], 'default_section_order' => ['hero', 'text', 'image_text', 'faq', 'cta', 'contact_details']],
                ['page_type' => 'packages', 'is_home' => false, 'allowed_section_types' => ['hero', 'services', 'cta', 'contact_details'], 'default_section_order' => ['hero', 'services', 'contact_details']],
                ['page_type' => 'about', 'is_home' => false, 'allowed_section_types' => ['hero', 'text', 'testimonials', 'contact_details'], 'default_section_order' => ['hero', 'text', 'testimonials', 'contact_details']],
                ['page_type' => 'faq', 'is_home' => false, 'allowed_section_types' => ['hero', 'faq', 'contact_details'], 'default_section_order' => ['hero', 'faq', 'contact_details']],
                ['page_type' => 'gallery', 'is_home' => false, 'allowed_section_types' => ['hero', 'gallery', 'cta', 'contact_details'], 'default_section_order' => ['hero', 'gallery', 'contact_details']],
                ['page_type' => 'contact', 'is_home' => false, 'allowed_section_types' => ['hero', 'contact_details', 'form'], 'default_section_order' => ['hero', 'contact_details', 'form']],
                ['page_type' => 'location', 'is_home' => false, 'allowed_section_types' => ['hero', 'text', 'services', 'faq', 'cta', 'contact_details'], 'default_section_order' => ['hero', 'text', 'services', 'faq', 'contact_details']],
                // Website Builder redesign — only planned when the Business
                // has at least one real BusinessBackdrop (WebsitePageStrategy::
                // backdropsEligible()); the 'backdrops' section itself is
                // always built server-side, never by AI.
                ['page_type' => 'backdrops', 'is_home' => false, 'allowed_section_types' => ['hero', 'backdrops', 'cta', 'contact_details'], 'default_section_order' => ['hero', 'backdrops', 'contact_details']],
                // Website Builder redesign — only planned when the owner's
                // questionnaire answers included one; the 'custom_section'
                // content itself is applied server-side from that answer,
                // never written by the main guided-generation AI call.
                ['page_type' => 'custom_section', 'is_home' => false, 'allowed_section_types' => ['hero', 'custom_section', 'cta', 'contact_details'], 'default_section_order' => ['hero', 'custom_section', 'contact_details']],
            ],
        ];
    }
}
