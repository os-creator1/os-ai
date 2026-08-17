<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.10/§9 item 82. The critical fix:
 * real, grep-evidenced Bootstrap-native selectors rebound to
 * var(--color-*), wired into the token import chain.
 */
class RuntimeBindingsContentTest extends TestCase
{
    public function test_runtime_bindings_file_exists_and_targets_the_evidenced_btn_primary_gap(): void
    {
        $source = file_get_contents(base_path('resources/scss/base/tokens/_runtime-bindings.scss'));

        $this->assertStringContainsString('.btn-primary', $source);
        $this->assertStringContainsString('var(--color-primary)', $source);
        $this->assertStringContainsString('!important', $source);
    }

    public function test_runtime_bindings_covers_every_status_color_family(): void
    {
        $source = file_get_contents(base_path('resources/scss/base/tokens/_runtime-bindings.scss'));

        foreach (['success', 'warning', 'danger', 'info'] as $status) {
            $this->assertStringContainsString("color-status-{$status}", $source);
        }
    }

    public function test_tokens_scss_imports_runtime_bindings_after_colors_and_typography(): void
    {
        $source = file_get_contents(base_path('resources/scss/base/tokens.scss'));

        $colorsPos = strpos($source, "'tokens/colors'");
        $typographyPos = strpos($source, "'tokens/typography'");
        $runtimePos = strpos($source, "'tokens/runtime-bindings'");

        $this->assertNotFalse($colorsPos);
        $this->assertNotFalse($typographyPos);
        $this->assertNotFalse($runtimePos);
        $this->assertGreaterThan($colorsPos, $runtimePos);
        $this->assertGreaterThan($typographyPos, $runtimePos);
    }

    public function test_no_locked_palette_every_binding_uses_a_css_custom_property_not_a_literal_hex(): void
    {
        $source = file_get_contents(base_path('resources/scss/base/tokens/_runtime-bindings.scss'));

        // Strip comments (which legitimately describe hex values in prose)
        // before checking that no rule body hardcodes a literal color.
        $withoutComments = preg_replace('#//.*#', '', $source);

        $this->assertDoesNotMatchRegularExpression('/:\s*#[0-9A-Fa-f]{3,8}\s*!important/', $withoutComments);
    }
}
