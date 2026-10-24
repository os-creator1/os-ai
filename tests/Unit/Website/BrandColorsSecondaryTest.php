<?php

namespace Tests\Unit\Website;

use App\Library\Website\Design\BrandColors;
use PHPUnit\Framework\TestCase;

class BrandColorsSecondaryTest extends TestCase
{
    public function test_a_secondary_colour_sets_the_dark_surface_with_readable_ink(): void
    {
        $style = BrandColors::inlineStyle('#FAC815', '#232323');

        $this->assertStringContainsString('--wd-accent:#fac815;', $style);
        $this->assertStringContainsString('--wd-dark:#232323;--wd-dark-ink:#ffffff;', $style);
        $this->assertStringContainsString('--wd-announce-bg:#232323;', $style);
    }

    public function test_a_light_secondary_gets_dark_ink(): void
    {
        $tokens = BrandColors::secondaryTokens('#ffffee');

        $this->assertSame('#000000', $tokens['dark_ink']);
        $this->assertGreaterThanOrEqual(4.5, BrandColors::contrast($tokens['dark_muted'], $tokens['dark']));
    }

    public function test_the_editorial_announcement_bar_keeps_the_accent(): void
    {
        $this->assertStringNotContainsString('--wd-announce-bg', BrandColors::inlineStyle('#FAC815', '#232323', false));
    }

    public function test_an_invalid_secondary_is_ignored(): void
    {
        $this->assertNull(BrandColors::secondaryTokens('javascript:alert(1)'));
        $this->assertSame('', BrandColors::inlineStyle(null, 'red;background:url(x)'));
    }
}
