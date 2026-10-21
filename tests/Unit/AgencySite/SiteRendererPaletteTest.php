<?php

namespace Tests\Unit\AgencySite;

use App\Library\AgencySite\SiteContext;
use App\Library\AgencySite\SiteRenderer;
use Tests\TestCase;

/**
 * The Agency's colours replace the platform's source palette in ONE pass: a colour that was just written is never
 * substituted again, whatever the Agency chose.
 */
class SiteRendererPaletteTest extends TestCase
{
    private function context(string $primary, ?string $secondary = null): SiteContext
    {
        return new SiteContext('Brand', null, $primary, $secondary, null, null, null, null, null, null, [], [], null, 'both', 'both',
            fn () => '/', fn () => '/', '/login', '/site.css');
    }

    /** Independent of the renderer: the expected tint/shade of a colour. */
    private function mix(string $hex, string $with, float $weight): string
    {
        $a = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $b = array_map('hexdec', str_split(ltrim($with, '#'), 2));

        return sprintf('#%02x%02x%02x', ...array_map(fn ($i) => (int) round($a[$i] * (1 - $weight) + $b[$i] * $weight), [0, 1, 2]));
    }

    private function colourize(string $text, SiteContext $context): string
    {
        return app(SiteRenderer::class)->colourize($text, $context);
    }

    public function test_an_ordinary_brand_colour_maps_every_source_token_exactly(): void
    {
        $out = $this->colourize('#b4514a|#9e443e|#8f3b35|#f6e7e4|#fbe9e6|#e8a49c|#181413', $this->context('#1a73e8'));

        $this->assertSame(implode('|', [
            '#1a73e8', $this->mix('#1a73e8', '#000000', 0.12), $this->mix('#1a73e8', '#000000', 0.25),
            $this->mix('#1a73e8', '#ffffff', 0.88), $this->mix('#1a73e8', '#ffffff', 0.9), $this->mix('#1a73e8', '#ffffff', 0.5), '#181413',
        ]), $out);
    }

    public function test_a_primary_equal_to_a_later_source_token_is_not_replaced_a_second_time(): void
    {
        foreach (['#9e443e', '#8f3b35', '#f6e7e4', '#fbe9e6', '#e8a49c'] as $primary) {
            $out = $this->colourize('a:#b4514a b:#9e443e', $this->context($primary));

            $this->assertSame('a:'.$primary.' b:'.$this->mix($primary, '#000000', 0.12), $out, "primary {$primary}");
        }
    }

    public function test_a_dark_secondary_replaces_only_the_platforms_dark_and_never_the_new_primary(): void
    {
        // Primary chosen equal to the platform's dark token: it must stay, while the real dark becomes the secondary.
        $out = $this->colourize('#B4514A and #181413', $this->context('#181413', '#0a0a0a'));
        $this->assertSame('#181413 and #0a0a0a', $out);

        // A light secondary is ignored (it would make text unreadable).
        $this->assertSame('#1a73e8 #181413', $this->colourize('#b4514a #181413', $this->context('#1a73e8', '#f0f0f0')));
    }

    public function test_matching_stays_case_insensitive_and_text_around_colours_is_untouched(): void
    {
        $out = $this->colourize('color:#B4514A;background:#F6E7E4;border:1px solid #e8A49C', $this->context('#1a73e8'));

        $this->assertSame('color:#1a73e8;background:'.$this->mix('#1a73e8', '#ffffff', 0.88).';border:1px solid '.$this->mix('#1a73e8', '#ffffff', 0.5), $out);
    }

    public function test_the_real_stylesheet_ends_up_with_exactly_the_intended_palette(): void
    {
        $css = app(SiteRenderer::class)->css($this->context('#9e443e', '#0a0a0a'));

        $this->assertStringNotContainsString('#b4514a', strtolower($css), 'no original primary left');
        $this->assertStringNotContainsString('#181413', strtolower($css), 'the dark token became the secondary');
        $this->assertStringContainsString('#9e443e', $css, 'the chosen primary is present');
        // The primary's own darker shade is the 12% variant of the CHOSEN colour, not of a colour derived twice.
        $this->assertStringContainsString($this->mix('#9e443e', '#000000', 0.12), $css);
    }
}
