<?php

namespace Tests\Feature\Theme;

use App\Library\Theme\ThemeColorDerivationService;
use Tests\TestCase;

/**
 * Design System M2 Slice 1 contract §6.6/§9 item 78. Implementation-time
 * verification requirement: deriving from the Factory default Primary
 * must reproduce the approved Hover/Active/Soft-background values within
 * a small, stated tolerance before the same formula is trusted for
 * arbitrary owner-chosen colors.
 */
class ThemeColorDerivationServiceTest extends TestCase
{
    private ThemeColorDerivationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ThemeColorDerivationService();
    }

    public function test_factory_primary_derivation_reproduces_the_approved_values_within_tolerance(): void
    {
        $variants = $this->service->deriveVariants('#B5524C');

        $this->assertHexWithinTolerance('#A83D38', $variants['hover']);
        $this->assertHexWithinTolerance('#980E0E', $variants['active']);
        $this->assertHexWithinTolerance('#F4E6E4', $variants['soft_bg']);
        $this->assertHexWithinTolerance('#E3C3BF', $variants['border']);
    }

    public function test_deriveFullTokenSet_produces_every_documented_token_key(): void
    {
        $tokens = $this->service->deriveFullTokenSet($this->factoryInputTokens());

        foreach (['color-primary', 'color-secondary', 'color-text-primary', 'color-canvas', 'color-border',
                  'color-status-success', 'color-status-success-soft-bg', 'color-chart-1', 'color-chart-8',
                  'color-chart-neutral', 'color-chart-positive', 'color-chart-negative'] as $key) {
            $this->assertArrayHasKey($key, $tokens, "Missing derived token key: {$key}");
        }
    }

    public function test_deriveFullTokenSet_never_sees_or_merges_overrides(): void
    {
        // The service only ever computes the base derived set; callers
        // apply overrides_json AFTER calling this (§6.6's own
        // override-preservation rule, enforced by PlatformThemeManager).
        $tokens = $this->service->deriveFullTokenSet($this->factoryInputTokens());

        $this->assertArrayNotHasKey('overrides', $tokens);
    }

    public function test_foregroundOn_picks_white_or_dark_text_by_contrast_never_a_third_color(): void
    {
        $lightBg = $this->service->foregroundOn('#FFFFFF');
        $darkBg = $this->service->foregroundOn('#000000');

        $this->assertContains($lightBg, ['#FFFFFF', '#262522']);
        $this->assertContains($darkBg, ['#FFFFFF', '#262522']);
        $this->assertSame('#262522', $lightBg);
        $this->assertSame('#FFFFFF', $darkBg);
    }

    private function assertHexWithinTolerance(string $expected, string $actual, int $perChannelTolerance = 20): void
    {
        $e = $this->hexToRgb($expected);
        $a = $this->hexToRgb($actual);

        foreach ([0, 1, 2] as $i) {
            $this->assertLessThanOrEqual(
                $perChannelTolerance,
                abs($e[$i] - $a[$i]),
                "Channel {$i} differs by more than {$perChannelTolerance}: expected {$expected}, got {$actual}"
            );
        }
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    /**
     * @return array<string, string>
     */
    private function factoryInputTokens(): array
    {
        return [
            'primary' => '#B5524C', 'secondary' => '#8A6F67', 'canvas' => '#F7F6F2', 'sidebar' => '#F2F0EB',
            'surface' => '#FFFFFF', 'surface_secondary' => '#FBFAF7', 'input' => '#FFFFFF', 'border' => '#E5E1DA',
            'status_success' => '#28C76F', 'status_warning' => '#FF9F43', 'status_danger' => '#EA5455',
            'status_info' => '#00CFE8', 'status_pending' => '#B58B46',
            'chart_1' => '#B5524C', 'chart_2' => '#647C78', 'chart_3' => '#4E79A7', 'chart_4' => '#59A14F',
            'chart_5' => '#F28E2B', 'chart_6' => '#B07AA1', 'chart_7' => '#EDC948', 'chart_8' => '#76B7B2',
            'chart_neutral' => '#A8A29A',
        ];
    }
}
