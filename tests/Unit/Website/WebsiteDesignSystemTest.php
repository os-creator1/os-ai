<?php

namespace Tests\Unit\Website;

use App\Library\Website\Design\BrandColors;
use App\Library\Website\Design\WebsiteDesigns;
use App\Library\Website\Design\WebsiteNavigationBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Website V1 final — the pure pieces of the template system: the four
 * canonical designs, safe brand-colour tokens (contrast), the home-page
 * section order a template owns, and the compact navigation that is kept
 * separate from the page tree.
 */
class WebsiteDesignSystemTest extends TestCase
{
    public function test_there_are_exactly_four_designs_numbered_one_to_four_one_per_template_key(): void
    {
        $designs = WebsiteDesigns::all();

        $this->assertSame(['photo_booth_modern', 'photo_booth_editorial', 'photo_booth_luxury', 'photo_booth_conversion'], array_keys($designs));
        $this->assertSame([1, 2, 3, 4], array_values(array_map(fn ($d) => $d->number, $designs)));
        $this->assertSame(['modern', 'editorial', 'luxury', 'conversion'], array_values(array_map(fn ($d) => $d->key, $designs)));
    }

    public function test_the_four_designs_differ_structurally_not_just_by_colour(): void
    {
        $designs = array_values(WebsiteDesigns::all());

        foreach (['header', 'hero', 'services', 'packages', 'footer'] as $surface) {
            $values = array_map(fn ($d) => $d->variant($surface), $designs);
            $this->assertCount(4, array_unique($values), "Every design owns its own '{$surface}' layout.");
        }

        $this->assertCount(4, array_unique(array_map(fn ($d) => implode(',', $d->homeOrder), $designs)), 'Every design owns its own home section order.');
    }

    public function test_a_published_site_resolves_its_design_from_the_frozen_theme_before_the_live_template_key(): void
    {
        // The theme in the published snapshot says "luxury"; the draft has since been switched to modern.
        $this->assertSame('photo_booth_luxury', WebsiteDesigns::resolve('photo_booth_modern', ['header_variant' => 'luxury'])->templateKey);
        $this->assertSame('photo_booth_modern', WebsiteDesigns::resolve('photo_booth_modern', [])->templateKey);
        $this->assertNull(WebsiteDesigns::resolve(null, ['header_variant' => 'bold']), 'A legacy non-template site keeps the generic chrome.');
    }

    public function test_home_sections_follow_the_templates_own_order_and_unknown_types_keep_their_place_after(): void
    {
        $sections = [['type' => 'faq'], ['type' => 'contact_details'], ['type' => 'hero'], ['type' => 'testimonials'], ['type' => 'services'], ['type' => 'gallery'], ['type' => 'cta'], ['type' => 'mystery']];

        $modern = array_column(WebsiteDesigns::forTemplateKey('photo_booth_modern')->orderHomeSections($sections), 'type');
        $party = array_column(WebsiteDesigns::forTemplateKey('photo_booth_conversion')->orderHomeSections($sections), 'type');

        $this->assertSame(['hero', 'services', 'gallery', 'cta', 'testimonials', 'faq', 'contact_details', 'mystery'], $modern);
        $this->assertSame(['hero', 'services', 'testimonials', 'cta', 'gallery', 'faq', 'contact_details', 'mystery'], $party);
        $this->assertSame('hero', $party[0]);
        $this->assertSame('mystery', end($party));
        $this->assertNotSame($modern, $party);
    }

    public function test_the_accent_word_is_escaped_and_never_raw_html(): void
    {
        $design = WebsiteDesigns::forTemplateKey('photo_booth_modern');

        $html = (string) $design->accentHeading('Luma <script>alert(1)</script> Booths');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('<span class="wd-accent-word">Booths</span>', $html);
        $this->assertSame('Solo', (string) $design->accentHeading('Solo'));
    }

    public function test_brand_colour_tokens_are_always_readable_whatever_the_owner_picks(): void
    {
        foreach (['#ffff00', '#000000', '#ff0000', '#1a56db', '#fac815', '#ffffff', '#102030'] as $brand) {
            $tokens = BrandColors::tokens($brand);

            $this->assertGreaterThanOrEqual(4.5, BrandColors::contrast($tokens['accent_ink'], $tokens['accent']), "Text on a {$brand} button must be readable.");
            $this->assertGreaterThanOrEqual(4.5, BrandColors::contrast($tokens['accent_text'], '#ffffff'), "{$brand} as text on white must be readable.");
            $this->assertGreaterThanOrEqual(4.5, BrandColors::contrast($tokens['accent_glow'], '#111111'), "{$brand} as text on a dark band must be readable.");
        }
    }

    public function test_brand_colour_input_is_validated_and_short_hex_is_expanded(): void
    {
        $this->assertSame('#aabbcc', BrandColors::normalize('#ABC'));
        $this->assertSame('#1a56db', BrandColors::normalize(' #1A56DB '));

        foreach (['red', 'javascript:alert(1)', '#12345', '#gggggg', 'url(x)', ''] as $bad) {
            $this->assertNull(BrandColors::normalize($bad), "'{$bad}' is not a brand colour.");
        }

        $this->assertSame('', BrandColors::inlineStyle('expression(alert(1))'));
        $this->assertStringContainsString('--wd-accent:#1a56db', BrandColors::inlineStyle('#1a56db'));
    }

    public function test_navigation_stays_compact_with_fourteen_pages_and_leaves_no_page_orphaned(): void
    {
        $pages = [['uid' => 'home', 'title' => 'Luma Photo Booth Co', 'is_home' => true, 'slug' => null, 'url' => '/']];
        $add = function (string $uid, string $title, string $slug) use (&$pages) {
            $pages[] = ['uid' => $uid, 'title' => $title, 'is_home' => false, 'slug' => $slug, 'url' => '/' . $slug];
        };
        $add('s', 'Services Overview', 'services');
        $add('p', 'Pricing', 'packages');
        $add('g', 'Gallery', 'gallery');
        $add('a', 'About us', 'photo-booth-about');
        $add('f', 'Frequently asked questions', 'photo-booth-faq');
        $add('c', 'Contact Luma', 'photo-booth-contact');
        foreach (['360 Photo Booth' => 'service-360-photo-booth', 'Mirror Photo Booth' => 'service-mirror-photo-booth', 'Open-Air Photo Booth' => 'service-open-air-photo-booth'] as $title => $slug) {
            $add($slug, $title, $slug);
        }
        $add('a1', 'Serving Chicago', 'serving-chicago');
        $add('a2', 'Serving Evanston', 'serving-evanston');
        $add('a3', 'Serving Oak Park', 'serving-oak-park');
        $add('custom', 'Red Carpet Experience', 'red-carpet-experience');
        $this->assertCount(14, $pages);

        $nav = (new WebsiteNavigationBuilder())->build($pages, 'a1');

        $labels = array_column($nav['primary'], 'title');
        $this->assertSame(['Home', 'Services', 'Packages', 'Gallery', 'Locations', 'About', 'Contact'], $labels, 'Canonical labels, never the AI page titles.');
        $this->assertLessThanOrEqual(7, count($nav['primary']));

        $services = collect($nav['primary'])->firstWhere('title', 'Services');
        $this->assertSame(['360 Photo Booth', 'Mirror Photo Booth', 'Open-Air Photo Booth'], array_column($services['children'], 'title'));

        $locations = collect($nav['primary'])->firstWhere('title', 'Locations');
        $this->assertSame(['Chicago', 'Evanston', 'Oak Park'], array_column($locations['children'], 'title'), "'Serving ' is dropped from a dropdown label.");
        $this->assertTrue($locations['current'], 'A dropdown containing the current page is itself current.');

        // No orphan: every page is a primary item, a dropdown child, or a footer link.
        $reachable = [];
        foreach ($nav['primary'] as $item) {
            $item['url'] !== null && $reachable[] = $item['url'];
            foreach ($item['children'] as $child) {
                $reachable[] = $child['url'];
            }
        }
        foreach ($nav['footer'] as $links) {
            foreach ($links as $link) {
                $reachable[] = $link['url'];
            }
        }
        foreach ($pages as $page) {
            $this->assertContains($page['url'], $reachable, "{$page['title']} must stay reachable.");
        }
    }

    public function test_a_single_page_site_has_no_navigation_to_show(): void
    {
        $nav = (new WebsiteNavigationBuilder())->build([['uid' => 'home', 'title' => 'X', 'is_home' => true, 'slug' => null, 'url' => '/']]);

        $this->assertFalse($nav['has_pages']);
    }
}
