<?php

namespace Tests\Feature\Theme;

use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.12/§9 item 80. theme-tokens.js is
 * the single token-reading implementation with one global namespace, and
 * every chart-bearing view has moved off the old hardcoded Vuexy purple
 * onto that namespace, with the semantic delivery-status object left
 * untouched (§9 item 48).
 */
class ChartTokenContentTest extends TestCase
{
    private const CHART_VIEWS = [
        'resources/views/admin/dashboard.blade.php',
        'resources/views/customer/dashboard.blade.php',
        'resources/views/customer/Reports/charts.blade.php',
        'resources/views/customer/Reports/analyze.blade.php',
        'resources/views/admin/Reports/overview.blade.php',
        'resources/views/admin/Reports/dashboard.blade.php',
        'resources/views/customer/Campaigns/overview.blade.php',
        'resources/views/customer/Automations/overview.blade.php',
    ];

    public function test_theme_tokens_js_exposes_a_single_global_namespace(): void
    {
        $source = file_get_contents(base_path('resources/js/core/theme-tokens.js'));

        $this->assertStringContainsString('window.PlatformTheme', $source);
        $this->assertStringContainsString('chartPalette', $source);
        $this->assertStringContainsString('getComputedStyle', $source);
    }

    public function test_no_chart_view_contains_the_hardcoded_legacy_purple(): void
    {
        foreach (self::CHART_VIEWS as $relativePath) {
            $source = file_get_contents(base_path($relativePath));
            $this->assertStringNotContainsStringIgnoringCase('7367F0', $source, "{$relativePath} still hardcodes the legacy Vuexy purple.");
        }
    }

    public function test_every_chart_view_now_references_the_shared_token_namespace(): void
    {
        foreach (self::CHART_VIEWS as $relativePath) {
            $source = file_get_contents(base_path($relativePath));
            $this->assertStringContainsString('PlatformTheme', $source, "{$relativePath} does not reference PlatformTheme.");
        }
    }

    public function test_campaigns_overview_leaves_the_semantic_delivery_status_object_untouched(): void
    {
        $source = file_get_contents(base_path('resources/views/customer/Campaigns/overview.blade.php'));

        foreach (['delivered', 'enroute', 'expired', 'undelivered', 'rejected', 'accepted', 'skipped', 'failed'] as $status) {
            $this->assertMatchesRegularExpression(
                "/{$status}\\s*:\\s*['\"]#[0-9A-Fa-f]{3,8}['\"]/",
                $source,
                "The semantic delivery-status key \"{$status}\" must remain a literal hex value, untouched."
            );
        }
    }

    public function test_theme_tokens_js_is_wired_before_app_js(): void
    {
        $mix = file_get_contents(base_path('webpack.mix.js'));
        $tokensPos = strpos($mix, 'core/theme-tokens.js');
        $appPos = strpos($mix, "core/app.js'");

        $this->assertNotFalse($tokensPos);
        $this->assertNotFalse($appPos);
        $this->assertLessThan($appPos, $tokensPos, 'theme-tokens.js must be registered before app.js.');
    }
}
