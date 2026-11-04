<?php

namespace Tests\Unit\Website;

use App\Library\Website\Design\BrandColors;
use App\Library\Website\Design\WebsiteLookService;
use PHPUnit\Framework\TestCase;

class BrandColorsPaletteTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../public/css/website-design.css';

    public function test_a_theme_without_overrides_emits_no_palette_style_or_classes(): void
    {
        $theme = ['brand_color' => '#336699', 'secondary_brand_color' => '#fac815', 'dark_bg_color' => 'nope'];

        $this->assertSame('', BrandColors::paletteStyle($theme));
        $this->assertSame('', BrandColors::paletteClasses($theme));
        $this->assertSame(BrandColors::inlineStyle('#336699', '#fac815'), BrandColors::themeStyle($theme));
    }

    public function test_the_dark_background_is_independent_of_primary_and_secondary(): void
    {
        $dark = BrandColors::themeStyle(['dark_bg_color' => '#3b0a45']);
        $all = BrandColors::themeStyle(['brand_color' => '#ff5500', 'secondary_brand_color' => '#0a7d5a', 'dark_bg_color' => '#3b0a45']);

        $this->assertStringContainsString('--wd-dark:#3b0a45', $dark);
        $this->assertStringNotContainsString('--wd-accent:', $dark);
        $this->assertStringContainsString('--wd-dark:#3b0a45', $all);
        $this->assertStringContainsString('--wd-accent:#ff5500', $all);
        $this->assertStringContainsString('--wd-secondary:#0a7d5a', $all);
        $this->assertSame('wd-pal-dark', BrandColors::paletteClasses(['dark_bg_color' => '#3b0a45']));
    }

    /** @dataProvider backgrounds */
    public function test_every_custom_background_gets_readable_ink_and_muted_ink(string $hex): void
    {
        $keys = ['dark_bg_color' => 'dark', 'footer_bg_color' => 'footer', 'header_bg_color' => 'header', 'announce_bg_color' => 'announce'];

        foreach ($keys as $key => $name) {
            $style = BrandColors::paletteStyle([$key => $hex, 'brand_color' => '#e85d3f']);
            $ink = $this->token($style, $name === 'dark' ? 'dark-ink' : $name . '-ink');
            $muted = $this->token($style, $name === 'dark' ? 'dark-muted' : $name . '-muted');

            foreach ([$ink, $muted] as $color) {
                if (str_starts_with($color, 'var(')) {
                    // Only ever the design's own ink, and only on a clearly light background.
                    $this->assertGreaterThanOrEqual(7.0, BrandColors::contrast($hex, '#111111'), "$name $hex");

                    continue;
                }

                $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $color, "$name $hex");
                $this->assertGreaterThanOrEqual(4.5, BrandColors::contrast($color, $hex), "$name $hex $color");
            }
        }
    }

    public static function backgrounds(): array
    {
        return array_map(fn (string $c) => [$c], ['#061633', '#3b0a45', '#000000', '#ffffff', '#f3f5f9', '#fac815', '#7a7a7a', '#d9a520']);
    }

    public function test_a_light_background_keeps_the_designs_own_ink_and_text_colour_is_made_readable(): void
    {
        $this->assertStringContainsString('--wd-tint-ink:var(--wd-ink)', BrandColors::paletteStyle(['tint_color' => '#fff4e5']));

        $style = BrandColors::paletteStyle(['tint_color' => '#fff4e5', 'text_color' => '#bbbbbb']);
        $this->assertGreaterThanOrEqual(4.5, BrandColors::contrast($this->token($style, 'ink'), '#ffffff'));
        $this->assertGreaterThanOrEqual(4.5, BrandColors::contrast($this->token($style, 'tint-ink'), '#fff4e5'));
        $this->assertStringContainsString('--wd-tint-card:#ffffff', $style);
    }

    public function test_a_dark_tint_gets_light_ink_and_dark_cards(): void
    {
        $style = BrandColors::paletteStyle(['tint_color' => '#18181b']);

        $this->assertStringContainsString('--wd-tint-ink:#ffffff', $style);
        $this->assertStringContainsString('--wd-tint-card:rgba(255,255,255,0.08)', $style);
    }

    public function test_every_palette_colour_survives_a_template_switch(): void
    {
        $theme = ['logo_asset_uid' => 'x', 'template_only' => 'y', 'brand_color' => '#111111'];

        foreach (BrandColors::PALETTE_KEYS as $key) {
            $theme[$key] = '#123456';
        }

        $kept = WebsiteLookService::ownerTokens($theme);

        foreach (BrandColors::PALETTE_KEYS as $key) {
            $this->assertSame('#123456', $kept[$key], $key);
        }

        $this->assertArrayNotHasKey('template_only', $kept);
    }

    public function test_the_stylesheet_ties_every_template_surface_to_a_configurable_token(): void
    {
        $css = file_get_contents(self::CSS);

        // Every footer variant, whichever design uses it, takes its background from the footer/dark tokens.
        preg_match_all('/^(\.wd-footer[\w-]*) \{[^}]*?background: ([^;]+);/m', $css, $m, PREG_SET_ORDER);
        $this->assertGreaterThanOrEqual(4, count($m));

        foreach ($m as [, $selector, $background]) {
            $this->assertStringContainsString('var(--wd-footer-bg', $background, $selector);
        }

        // Navy / zinc literals that used to bypass the dark token are gone from the dark-surface rules.
        foreach (['.wd-hero-split {', '.wd-luxury .wd-dd {', '.wd-packages-pop .wd-card-featured {'] as $needle) {
            $line = $this->line($css, $needle);
            $this->assertStringContainsString('var(--wd-dark', $line, $needle);
        }

        foreach (['.wd-header {' => '--wd-header-bg', '.wd-announce {' => '--wd-announce-bg', 'body.wd {' => 'body.wd'] as $needle => $token) {
            $this->assertStringContainsString($token, $this->line($css, $needle), $needle);
        }

        $this->assertStringContainsString('background: var(--wd-page-bg)', $css);

        foreach (['footer', 'surface', 'tint', 'page', 'header', 'announce'] as $name) {
            $this->assertStringContainsString('.wd-pal-' . $name, $css, $name);
        }

        foreach (['modern', 'editorial', 'luxury', 'conversion'] as $design) {
            $this->assertStringContainsString('.wd-' . $design, $css);
        }
    }

    private function token(string $style, string $name): string
    {
        $this->assertSame(1, preg_match('/--wd-' . preg_quote($name, '/') . ':([^;]+);/', $style, $m), $name);

        return $m[1];
    }

    private function line(string $css, string $needle): string
    {
        foreach (explode("\n", $css) as $line) {
            if (str_starts_with(ltrim($line), $needle)) {
                return $line;
            }
        }

        $this->fail('Missing rule ' . $needle);
    }
}
