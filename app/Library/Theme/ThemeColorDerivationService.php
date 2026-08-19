<?php

namespace App\Library\Theme;

/**
 * Design System M2 Slice 1 contract §6.6/§9 item 12.
 *
 * Deterministic HSL-space derivation: hover = HSL(H, S+8.5, L*0.87);
 * active = HSL(H, S*2, L*0.646); soft-background/border = linear RGB
 * blend toward white at t=0.85/t=0.64 respectively. These exact
 * constants were reverse-derived from the approved default palette
 * (Primary #B5524C -> Hover #A83D38 / Active #980E0E / Soft-bg #F4E6E4
 * / Border #E3C3BF) and verified to reproduce every one of those four
 * values within a handful of hex units per channel — well inside the
 * contract's own stated ΔE00 < 2 "imperceptible difference" tolerance
 * (asserted by ThemeColorDerivationServiceTest, §9 item 78).
 *
 * Foreground-on-color always picks between the fixed dark text token
 * and white by contrast ratio (ThemeContrastValidator), never a third
 * candidate — the standard, well-known safe-foreground-picking rule.
 *
 * Override preservation (§6.6): callers apply overrides_json AFTER
 * calling deriveFullTokenSet() here — this service only ever computes
 * the full derived set from bases; it never sees or merges overrides,
 * keeping the "compute, then override" order enforced structurally by
 * PlatformThemeManager's own orchestration, not duplicated in here.
 */
class ThemeColorDerivationService
{
    private const TEXT_PRIMARY = '#262522';
    private const TEXT_MUTED = '#6F6D67';
    private const TEXT_INVERSE = '#FFFFFF';

    public function deriveFullTokenSet(array $inputTokens): array
    {
        $primary = $inputTokens['primary'];
        $secondary = $inputTokens['secondary'];
        $canvas = $inputTokens['canvas'];
        $sidebar = $inputTokens['sidebar'];
        $surface = $inputTokens['surface'];
        $surfaceSecondary = $inputTokens['surface_secondary'];
        $inputBg = $inputTokens['input'];
        $border = $inputTokens['border'];
        $header = $inputTokens['header'] ?? null;

        $primaryVariants = $this->deriveVariants($primary);
        $secondaryVariants = $this->deriveVariants($secondary);

        $statuses = [
            'success' => $inputTokens['status_success'],
            'warning' => $inputTokens['status_warning'],
            'danger' => $inputTokens['status_danger'],
            'info' => $inputTokens['status_info'],
            'pending' => $inputTokens['status_pending'],
        ];

        $tokens = [
            // Brand & interaction
            'color-primary' => $primary,
            'color-primary-hover' => $primaryVariants['hover'],
            'color-primary-active' => $primaryVariants['active'],
            'color-primary-soft-bg' => $primaryVariants['soft_bg'],
            'color-primary-border' => $primaryVariants['border'],
            'color-secondary' => $secondary,
            'color-secondary-hover' => $secondaryVariants['hover'],
            'color-secondary-active' => $secondaryVariants['active'],
            'color-secondary-soft-bg' => $secondaryVariants['soft_bg'],
            'color-accent' => $secondary,
            'color-link' => $primary,
            'color-link-hover' => $primaryVariants['hover'],
            'color-focus-ring' => $primary,
            'color-selection' => $primaryVariants['soft_bg'],
            'color-button-primary-bg' => $primary,
            'color-button-secondary-bg' => $secondary,
            'color-button-text' => $this->foregroundOn($primary),
            'color-nav-hover' => $primaryVariants['soft_bg'],
            'color-nav-active' => $primary,

            // Text & icons (fixed Milestone-1 tokens; Advanced-overridable)
            'color-text-primary' => self::TEXT_PRIMARY,
            'color-text-secondary' => $this->blendToward(self::TEXT_PRIMARY, '#FFFFFF', 0.30),
            'color-text-muted' => self::TEXT_MUTED,
            'color-text-inverse' => self::TEXT_INVERSE,
            'color-text-disabled' => $this->blendToward(self::TEXT_MUTED, '#FFFFFF', 0.45),
            'color-icon-default' => self::TEXT_PRIMARY,
            'color-icon-muted' => self::TEXT_MUTED,
            'color-icon-inverse' => self::TEXT_INVERSE,

            // Backgrounds & surfaces
            'color-canvas' => $canvas,
            'color-sidebar' => $sidebar,
            'color-header' => $header ?? $primaryVariants['soft_bg'],
            'color-surface' => $surface,
            'color-surface-secondary' => $surfaceSecondary,
            'color-dropdown' => $surface,
            'color-input-bg' => $inputBg,
            'color-table-header' => $surfaceSecondary,
            'color-table-row' => $surface,
            'color-row-hover' => $primaryVariants['soft_bg'],
            'color-chat-canvas' => $canvas,
            'color-chat-bubble-in' => $surfaceSecondary,
            'color-chat-bubble-out' => $primary,
            'color-chat-composer' => $surface,
            'color-modal-surface' => $surface,
            'color-overlay' => $this->rgbaFromHex(self::TEXT_PRIMARY, 0.5),
            'color-tooltip-surface' => self::TEXT_PRIMARY,
            'color-tooltip-text' => self::TEXT_INVERSE,
            'color-popover-surface' => $surface,

            // Borders, depth, elevation
            'color-border' => $border,
            'color-border-subtle' => $this->blendToward($border, '#FFFFFF', 0.5),
            'color-border-strong' => $this->blendToward($border, self::TEXT_PRIMARY, 0.35),
            'color-input-border' => $border,
            'color-active-border' => $primary,
            'color-focus-border' => $primary,
            'color-shadow-tint' => $this->rgbaFromHex(self::TEXT_PRIMARY, 0.08),
            'color-elevated-shadow-tint' => $this->rgbaFromHex(self::TEXT_PRIMARY, 0.16),
        ];

        foreach ($statuses as $key => $base) {
            $variants = $this->deriveVariants($base);
            $tokens["color-status-{$key}"] = $base;
            $tokens["color-status-{$key}-text"] = $this->foregroundOn($base);
            $tokens["color-status-{$key}-border"] = $variants['border'];
            $tokens["color-status-{$key}-icon"] = $base;
            $tokens["color-status-{$key}-soft-bg"] = $variants['soft_bg'];
        }

        // Charts
        for ($i = 1; $i <= 8; $i++) {
            $tokens["color-chart-{$i}"] = $inputTokens["chart_{$i}"];
        }
        $tokens['color-chart-neutral'] = $inputTokens['chart_neutral'] ?? self::TEXT_MUTED;
        $tokens['color-chart-grid'] = $this->blendToward($border, '#FFFFFF', 0.4);
        $tokens['color-chart-axis'] = self::TEXT_MUTED;
        $tokens['color-chart-tooltip-bg'] = $tokens['color-tooltip-surface'];
        $tokens['color-chart-tooltip-text'] = $tokens['color-tooltip-text'];
        $tokens['color-chart-positive'] = $statuses['success'];
        $tokens['color-chart-negative'] = $statuses['danger'];

        return $tokens;
    }

    /**
     * @return array{hover: string, active: string, soft_bg: string, border: string}
     */
    public function deriveVariants(string $hex): array
    {
        [$h, $s, $l] = $this->hexToHsl($hex);

        return [
            'hover' => $this->hslToHex($h, min(100, $s + 8.5), $l * 0.87),
            'active' => $this->hslToHex($h, min(100, $s * 2), $l * 0.646),
            'soft_bg' => $this->blendToward($hex, '#FFFFFF', 0.85),
            'border' => $this->blendToward($hex, '#FFFFFF', 0.64),
        ];
    }

    public function foregroundOn(string $backgroundHex): string
    {
        $contrastDark = ThemeContrastValidator::contrastRatio(self::TEXT_PRIMARY, $backgroundHex);
        $contrastLight = ThemeContrastValidator::contrastRatio('#FFFFFF', $backgroundHex);

        return $contrastLight >= $contrastDark ? '#FFFFFF' : self::TEXT_PRIMARY;
    }

    public function blendToward(string $fromHex, string $towardHex, float $ratio): string
    {
        [$r1, $g1, $b1] = $this->hexToRgb($fromHex);
        [$r2, $g2, $b2] = $this->hexToRgb($towardHex);

        $r = $r1 + $ratio * ($r2 - $r1);
        $g = $g1 + $ratio * ($g2 - $g1);
        $b = $b1 + $ratio * ($b2 - $b1);

        return $this->rgbToHex($r, $g, $b);
    }

    public function rgbaFromHex(string $hex, float $alpha): string
    {
        [$r, $g, $b] = $this->hexToRgb($hex);

        return sprintf('rgba(%d, %d, %d, %s)', $r, $g, $b, $alpha);
    }

    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    private function rgbToHex(float $r, float $g, float $b): string
    {
        $clamp = fn (float $v) => (int) round(max(0, min(255, $v)));

        return sprintf('#%02X%02X%02X', $clamp($r), $clamp($g), $clamp($b));
    }

    private function hexToHsl(string $hex): array
    {
        [$r, $g, $b] = $this->hexToRgb($hex);
        $r /= 255;
        $g /= 255;
        $b /= 255;

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $l = ($max + $min) / 2;

        if ($max === $min) {
            $h = $s = 0.0;
        } else {
            $d = $max - $min;
            $s = $l > 0.5 ? $d / (2 - $max - $min) : $d / ($max + $min);

            $h = match ($max) {
                $r => ($g - $b) / $d + ($g < $b ? 6 : 0),
                $g => ($b - $r) / $d + 2,
                default => ($r - $g) / $d + 4,
            };
            $h /= 6;
        }

        return [$h * 360, $s * 100, $l * 100];
    }

    private function hslToHex(float $h, float $s, float $l): string
    {
        $h /= 360;
        $s /= 100;
        $l /= 100;

        if ($s == 0.0) {
            $r = $g = $b = $l;
        } else {
            $hue2rgb = function (float $p, float $q, float $t) {
                if ($t < 0) {
                    $t += 1;
                }
                if ($t > 1) {
                    $t -= 1;
                }
                if ($t < 1 / 6) {
                    return $p + ($q - $p) * 6 * $t;
                }
                if ($t < 1 / 2) {
                    return $q;
                }
                if ($t < 2 / 3) {
                    return $p + ($q - $p) * (2 / 3 - $t) * 6;
                }

                return $p;
            };

            $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
            $p = 2 * $l - $q;

            $r = $hue2rgb($p, $q, $h + 1 / 3);
            $g = $hue2rgb($p, $q, $h);
            $b = $hue2rgb($p, $q, $h - 1 / 3);
        }

        return $this->rgbToHex($r * 255, $g * 255, $b * 255);
    }
}
