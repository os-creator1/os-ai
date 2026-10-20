<?php

namespace Tests\Unit\Appearance;

use App\Library\Appearance\Appearance;
use PHPUnit\Framework\TestCase;

class AppearanceTest extends TestCase
{
    public function test_only_light_dark_and_system_are_supported(): void
    {
        $this->assertSame(['light', 'dark', 'system'], Appearance::values());
    }

    public function test_supported_values_are_kept_and_everything_else_is_the_light_default(): void
    {
        foreach (['light', 'dark', 'system'] as $value) {
            $this->assertTrue(Appearance::isValid($value));
            $this->assertSame($value, Appearance::normalize($value));
        }

        foreach ([null, '', 'Dark', 'DARK', ' dark', 'auto', 'purple', '<script>', 0, 1, true, ['dark']] as $value) {
            $this->assertFalse(Appearance::isValid($value), var_export($value, true));
            $this->assertSame('light', Appearance::normalize($value), var_export($value, true));
        }
    }

    public function test_a_guest_is_light_and_adds_no_html_class(): void
    {
        $this->assertSame('light', Appearance::forUser(null));
        $this->assertSame('', Appearance::htmlClass(null));
    }
}
