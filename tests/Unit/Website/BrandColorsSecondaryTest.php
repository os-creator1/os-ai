<?php

namespace Tests\Unit\Website;

use App\Library\Website\Design\BrandColors;
use PHPUnit\Framework\TestCase;

class BrandColorsSecondaryTest extends TestCase
{
    public function test_without_a_secondary_colour_the_style_is_unchanged(): void
    {
        $this->assertStringNotContainsString('--wd-secondary', BrandColors::inlineStyle('#336699'));
        $this->assertSame(BrandColors::inlineStyle('#336699'), BrandColors::inlineStyle('#336699', 'nope'));
    }

    public function test_a_secondary_colour_adds_contrast_safe_tokens_and_keeps_the_primary(): void
    {
        $style = BrandColors::inlineStyle('#336699', '#fac815');

        $this->assertStringContainsString('--wd-accent:#336699', $style);
        $this->assertStringContainsString('--wd-secondary:#fac815', $style);
        $this->assertStringContainsString('--wd-secondary-ink:#000000', $style);
    }
}
