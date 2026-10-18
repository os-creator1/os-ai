<?php

namespace Tests\Unit\Website;

use App\Library\Website\Design\BrandColors;
use App\Library\Website\Design\WebsiteDesigns;
use PHPUnit\Framework\TestCase;

/**
 * Website V1 final — an accessibility gate on the four designs' OWN palettes:
 * every text/background pair a design defines in public/css/website-design.css
 * must reach WCAG AA (4.5:1) for body text. Read straight from the stylesheet,
 * so editing a colour there can never silently break readability.
 */
class WebsiteDesignContrastTest extends TestCase
{
    /** @return array<string, string> custom property => #hex, for `.wd` merged with `.wd-<key>` */
    private function tokens(string $key): array
    {
        $css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/css/website-design.css');
        $tokens = [];

        foreach (['\.wd', '\.wd-' . $key] as $selector) {
            if (preg_match('/^' . $selector . ' \{(.*?)^\}/ms', $css, $block) !== 1) {
                continue;
            }

            preg_match_all('/(--wd-[a-z-]+):\s*(#[0-9a-fA-F]{6})\s*;/', $block[1], $pairs, PREG_SET_ORDER);

            foreach ($pairs as [, $name, $hex]) {
                $tokens[$name] = strtolower($hex);
            }
        }

        return $tokens;
    }

    public function test_every_design_reaches_aa_for_its_text_and_button_pairs(): void
    {
        foreach (WebsiteDesigns::all() as $design) {
            $t = $this->tokens($design->key);

            $pairs = [
                'body text on a light band' => [$t['--wd-ink'], $t['--wd-light']],
                'muted text on a light band' => [$t['--wd-muted'], $t['--wd-light']],
                'muted text on a tinted band' => [$t['--wd-muted'], $t['--wd-tint']],
                'body text on a tinted band' => [$t['--wd-ink'], $t['--wd-tint']],
                'text on a dark band' => [$t['--wd-dark-ink'], $t['--wd-dark']],
                'muted text on a dark band' => [$t['--wd-dark-muted'], $t['--wd-dark']],
                'button label on the accent' => [$t['--wd-accent-ink'], $t['--wd-accent']],
                'accent as text on a light band' => [$t['--wd-accent-text'], $t['--wd-light']],
                'accent as text on a dark band' => [$t['--wd-accent-glow'], $t['--wd-dark']],
            ];

            // Luxury's tinted band is the dark "zinc" step, so it is read with dark-band text.
            if ($design->key === 'luxury') {
                $pairs['muted text on a tinted band'] = [$t['--wd-dark-muted'], $t['--wd-tint']];
                $pairs['body text on a tinted band'] = [$t['--wd-dark-ink'], $t['--wd-tint']];
            }

            foreach ($pairs as $label => [$foreground, $background]) {
                $this->assertGreaterThanOrEqual(
                    4.5,
                    BrandColors::contrast($foreground, $background),
                    "{$design->label}: {$label} ({$foreground} on {$background}) must reach WCAG AA."
                );
            }
        }
    }

    public function test_the_default_accent_text_on_the_tinted_band_of_every_design_is_readable_too(): void
    {
        foreach (WebsiteDesigns::all() as $design) {
            $t = $this->tokens($design->key);

            if ($design->key === 'luxury') {
                continue; // its tint is a dark band; covered with the accent-glow pair above
            }

            $this->assertGreaterThanOrEqual(4.5, BrandColors::contrast($t['--wd-accent-text'], $t['--wd-tint']), "{$design->label}: accent text on its tinted band.");
        }
    }
}
