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

    /**
     * §6.10/§9 item 82. `color-status-success` etc. never appear as
     * literal source text: the SCSS builds them via
     * `@each $status, $var-base in (success: 'success', ...) { ... }`
     * interpolating `color-status-#{$var-base}` (contract §9 item 36's
     * own documented pattern). A plain substring search on the .scss
     * SOURCE therefore can't see the post-compile output and would
     * either fail honestly (as it did) or require compiling SCSS, which
     * this suite has no tooling for. Instead this proves the loop
     * itself is correct at the source level: exactly the four required
     * status keys drive the loop, each key's map value equals the key
     * itself (so the emitted token name is never silently renamed away
     * from the semantic status), and the loop body actually
     * interpolates that same loop variable into the
     * `--color-status-*` custom-property pattern -- which together are
     * sufficient to prove every one of the four families is genuinely
     * runtime-token-backed, without needing a SCSS compiler.
     */
    public function test_runtime_bindings_covers_every_status_color_family(): void
    {
        $source = file_get_contents(base_path('resources/scss/base/tokens/_runtime-bindings.scss'));

        $this->assertSame(
            1,
            preg_match_all('/@each\s+\$status\s*,\s*\$var-base\s+in\s*\(([^)]*)\)\s*\{/', $source, $loopMatches),
            'Expected exactly one @each status-family loop in _runtime-bindings.scss.'
        );

        $mapBody = $loopMatches[1][0];
        $pairCount = preg_match_all('/([a-z]+)\s*:\s*\'([a-z]+)\'/', $mapBody, $pairMatches, PREG_SET_ORDER);
        $this->assertGreaterThan(0, $pairCount, 'The @each loop map must declare at least one status => token-name pair.');

        $keys = array_map(fn (array $pair) => $pair[1], $pairMatches);
        sort($keys);
        $this->assertSame(
            ['danger', 'info', 'success', 'warning'],
            $keys,
            'The @each loop must drive exactly the four required status families -- no more, no fewer.'
        );

        foreach ($pairMatches as [$whole, $key, $value]) {
            $this->assertSame(
                $key,
                $value,
                "Status \"{$key}\" must map to itself (\"{$key}\" => '{$key}'), so the emitted --color-status-{$key} token name is never silently renamed."
            );
        }

        $this->assertStringContainsString(
            'color-status-#{$var-base}',
            $source,
            'The loop body must interpolate the same $var-base driving the loop into the --color-status-* custom-property pattern.'
        );
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
