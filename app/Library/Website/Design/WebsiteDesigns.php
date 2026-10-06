<?php

namespace App\Library\Website\Design;

/**
 * Website V1 final — the registry of the four canonical designs.
 *
 * Template 1..4 are the four reference sites the product direction names, in
 * the order given:
 *  1. boothstothemax.com        -> "Bold Event"    (photo_booth_modern)
 *  2. kalebhillphotobooth.com   -> "Classic Gold"  (photo_booth_editorial)
 *  3. ritzybooths.com           -> "Midnight Gold" (photo_booth_luxury)
 *  4. greenbeltphotography.com  -> "Party Luxe"    (photo_booth_conversion)
 *
 * The stable `template_key`s are the existing website_templates rows, so no
 * data migration is needed and an already-created website keeps its template.
 * A published site resolves its design from the theme frozen in its revision
 * (`theme.header_variant`), so a later registry change never re-skins a live
 * site until its owner publishes again.
 */
final class WebsiteDesigns
{
    /** @var array<string, WebsiteDesign>|null */
    private static ?array $designs = null;

    public const DEFAULT_TEMPLATE_KEY = 'photo_booth_modern';

    /**
     * @return array<string, WebsiteDesign> keyed by template key
     */
    public static function all(): array
    {
        return self::$designs ??= self::build();
    }

    public static function forTemplateKey(?string $templateKey): ?WebsiteDesign
    {
        return $templateKey === null ? null : (self::all()[$templateKey] ?? null);
    }

    /**
     * Resolves a design from what a render path actually has: a Website's
     * own template key (preview), or the theme frozen into a published
     * revision (public / custom domain). Null = a legacy, non-template site
     * that keeps the original generic chrome.
     *
     * @param  array<string, mixed>  $theme
     */
    public static function resolve(?string $templateKey, array $theme): ?WebsiteDesign
    {
        // The theme comes first: a published site renders from the theme
        // frozen into its revision, so switching the draft's template later
        // never re-skins the live site until the owner publishes again.
        $variant = (string) ($theme['header_variant'] ?? '');

        foreach (self::all() as $design) {
            if ($design->key === $variant) {
                return $design;
            }
        }

        return self::forTemplateKey($templateKey);
    }

    /**
     * @return array<string, WebsiteDesign>
     */
    private static function build(): array
    {
        return [
            'photo_booth_modern' => new WebsiteDesign(
                key: 'modern',
                templateKey: 'photo_booth_modern',
                number: 1,
                label: 'Bold Event',
                tagline: 'Navy and gold. A full-width photo hero, big uppercase headlines and one clear booking button.',
                bestFor: 'Weddings, corporate events and a polished, confident first impression.',
                variants: [
                    'header' => 'split',
                    'hero' => 'fullbleed',
                    'services' => 'tiles',
                    'packages' => 'ruled',
                    'testimonials' => 'plain',
                    'gallery' => 'grid',
                    'footer' => 'columns',
                    'accent' => 'bold',
                ],
                tones: ['hero' => 'dark', 'services' => 'light', 'packages' => 'tint', 'gallery' => 'light', 'image_text' => 'light', 'text' => 'light', 'testimonials' => 'dark', 'cta' => 'accent', 'faq' => 'light', 'contact_details' => 'dark', 'form' => 'light', 'backdrops' => 'light', 'custom_section' => 'light'],
                homeOrder: ['hero', 'services', 'gallery', 'image_text', 'text', 'cta', 'testimonials', 'faq', 'contact_details'],
                swatch: ['#061633', '#fac815'],
                announcementBar: true,
            ),
            'photo_booth_editorial' => new WebsiteDesign(
                key: 'editorial',
                templateKey: 'photo_booth_editorial',
                number: 2,
                label: 'Classic Gold',
                tagline: 'Clean white, black and gold. A centered logo, elegant serif headlines and calm, spacious sections.',
                bestFor: 'Elegant events and a refined, trustworthy look.',
                variants: [
                    'header' => 'centered',
                    'hero' => 'centered',
                    'services' => 'rows',
                    'packages' => 'hairline',
                    'testimonials' => 'plain',
                    'gallery' => 'strip',
                    'footer' => 'centered',
                    'accent' => 'serif',
                ],
                tones: ['hero' => 'light', 'services' => 'light', 'packages' => 'tint', 'gallery' => 'light', 'image_text' => 'light', 'text' => 'light', 'testimonials' => 'light', 'cta' => 'dark', 'faq' => 'light', 'contact_details' => 'dark', 'form' => 'light', 'backdrops' => 'light', 'custom_section' => 'light'],
                homeOrder: ['hero', 'image_text', 'text', 'services', 'gallery', 'cta', 'testimonials', 'faq', 'contact_details'],
                swatch: ['#ffffff', '#fac815'],
                announcementBar: true,
            ),
            'photo_booth_luxury' => new WebsiteDesign(
                key: 'luxury',
                templateKey: 'photo_booth_luxury',
                number: 3,
                label: 'Midnight Gold',
                tagline: 'Near-black with one champagne-gold accent, rounded pill buttons and a sticky header.',
                bestFor: 'Upscale, premium-feeling brands.',
                variants: [
                    'header' => 'sticky',
                    'hero' => 'split',
                    'services' => 'stacked',
                    'packages' => 'cards',
                    'testimonials' => 'cards',
                    'gallery' => 'masonry',
                    'footer' => 'midnight',
                    'accent' => 'serif',
                ],
                tones: ['hero' => 'dark', 'services' => 'dark', 'packages' => 'tint', 'gallery' => 'dark', 'image_text' => 'tint', 'text' => 'dark', 'testimonials' => 'light', 'cta' => 'accent', 'faq' => 'tint', 'contact_details' => 'dark', 'form' => 'dark', 'backdrops' => 'dark', 'custom_section' => 'dark'],
                homeOrder: ['hero', 'image_text', 'services', 'text', 'testimonials', 'gallery', 'cta', 'faq', 'contact_details'],
                swatch: ['#0a0a0a', '#d9a520'],
                announcementBar: false,
            ),
            'photo_booth_conversion' => new WebsiteDesign(
                key: 'conversion',
                templateKey: 'photo_booth_conversion',
                number: 4,
                label: 'Party Luxe',
                tagline: 'Lavender and peach with a deep plum and coral accent. Heavy headlines, a floating pill menu and event-style tiles.',
                bestFor: 'Parties, celebrations and an approachable, high-energy brand.',
                variants: [
                    'header' => 'pill',
                    'hero' => 'split-soft',
                    'services' => 'event-tiles',
                    'packages' => 'pop',
                    'testimonials' => 'cards',
                    'gallery' => 'masonry',
                    'footer' => 'soft',
                    'accent' => 'light-contrast',
                ],
                tones: ['hero' => 'tint', 'services' => 'dark', 'packages' => 'light', 'gallery' => 'light', 'image_text' => 'tint', 'text' => 'light', 'testimonials' => 'dark', 'cta' => 'accent', 'faq' => 'tint', 'contact_details' => 'light', 'form' => 'tint', 'backdrops' => 'light', 'custom_section' => 'tint'],
                homeOrder: ['hero', 'services', 'image_text', 'testimonials', 'cta', 'gallery', 'text', 'faq', 'contact_details'],
                swatch: ['#2a1d45', '#f4a28f'],
                announcementBar: true,
            ),
        ];
    }
}
