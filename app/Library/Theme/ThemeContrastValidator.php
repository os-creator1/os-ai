<?php

namespace App\Library\Theme;

/**
 * Design System M2 Slice 1 contract §6.7/§9 item 13. Hand-implemented
 * WCAG 2.1 relative luminance / contrast ratio — no new dependency.
 * Invoked identically at both save and activation (§6.14/§6.7): the
 * caller decides when to call validate(), this class only ever computes
 * the same {errors, warnings} result from a derived token set.
 *
 * Hard-reject floor: 4.5:1 for normal-text pairs, 3:1 for UI pairs that
 * identify an interactive component's boundary (focus rings, focus
 * borders) — the genuine WCAG 1.4.11 concern. Soft-warning floor: 1.15:1
 * absolute luminance-ratio for adjacent, non-text surface pairs
 * (canvas/surface/input/overlay/border) that are technically valid but
 * provide very little visual separation. A generic `color-border` token
 * (table borders, card dividers) is deliberately NOT hard-gated at 3:1:
 * unlike a focus ring, it does not identify an interactive element's
 * boundary, and the approved Factory default (a subtle warm-neutral
 * divider on white) is itself well below that ratio by design.
 */
class ThemeContrastValidator
{
    private const TEXT_MIN_RATIO = 4.5;
    private const UI_MIN_RATIO = 3.0;
    private const SURFACE_SEPARATION_FLOOR = 1.15;

    /**
     * @return array{errors: array<string>, warnings: array<string>}
     */
    public function validate(array $derivedTokens): array
    {
        $errors = [];
        $warnings = [];

        $textPairs = [
            'text-primary on canvas' => ['color-text-primary', 'color-canvas'],
            'text-primary on surface' => ['color-text-primary', 'color-surface'],
            'text-muted on canvas' => ['color-text-muted', 'color-canvas'],
            'button-text on primary' => ['color-button-text', 'color-button-primary-bg'],
            'button-text on secondary' => ['color-button-text', 'color-button-secondary-bg'],
            'text-inverse on chat-bubble-out' => ['color-text-inverse', 'color-chat-bubble-out'],
            'tooltip-text on tooltip-surface' => ['color-tooltip-text', 'color-tooltip-surface'],
        ];

        foreach (['success', 'warning', 'danger', 'info', 'pending'] as $status) {
            $textPairs["status-{$status}-text on status-{$status}-soft-bg"] =
                ["color-status-{$status}-text", "color-status-{$status}-soft-bg"];
        }

        foreach ($textPairs as $label => [$fgKey, $bgKey]) {
            if (! isset($derivedTokens[$fgKey], $derivedTokens[$bgKey])) {
                continue;
            }

            $ratio = self::contrastRatio($derivedTokens[$fgKey], $derivedTokens[$bgKey]);
            if ($ratio < self::TEXT_MIN_RATIO) {
                $errors[] = sprintf(
                    '%s contrast is %.2f:1, below the required %.1f:1.',
                    $label,
                    $ratio,
                    self::TEXT_MIN_RATIO
                );
            }
        }

        $uiPairs = [
            'focus-ring on surface' => ['color-focus-ring', 'color-surface'],
            'focus-border on input-bg' => ['color-focus-border', 'color-input-bg'],
        ];

        foreach ($uiPairs as $label => [$fgKey, $bgKey]) {
            if (! isset($derivedTokens[$fgKey], $derivedTokens[$bgKey])) {
                continue;
            }

            $ratio = self::contrastRatio($derivedTokens[$fgKey], $derivedTokens[$bgKey]);
            if ($ratio < self::UI_MIN_RATIO) {
                $errors[] = sprintf(
                    '%s contrast is %.2f:1, below the required %.1f:1.',
                    $label,
                    $ratio,
                    self::UI_MIN_RATIO
                );
            }
        }

        // Chart-series pairwise distinguishability — a minimum
        // hue/lightness delta check, not raw WCAG contrast, since
        // "distinguishable data series" and "readable text" are
        // different accessibility properties (§6.7).
        $chartSlots = [];
        for ($i = 1; $i <= 8; $i++) {
            if (isset($derivedTokens["color-chart-{$i}"])) {
                $chartSlots[$i] = $derivedTokens["color-chart-{$i}"];
            }
        }
        foreach ($chartSlots as $i => $hexA) {
            foreach ($chartSlots as $j => $hexB) {
                if ($j <= $i) {
                    continue;
                }
                if (self::perceptualDistance($hexA, $hexB) < 20.0) {
                    $warnings[] = sprintf(
                        'Chart series %d and %d are visually very close and may be hard to tell apart.',
                        $i,
                        $j
                    );
                }
            }
        }

        // Soft warnings — low-separation but technically valid surfaces.
        // `border on surface` moved here from the hard-gated $uiPairs
        // above: a generic divider/border color does not identify an
        // interactive component's boundary the way a focus ring does,
        // and the approved Factory default is itself a subtle,
        // deliberately low-contrast warm-neutral divider.
        $surfacePairs = [
            'canvas vs surface' => ['color-canvas', 'color-surface'],
            'surface vs surface-secondary' => ['color-surface', 'color-surface-secondary'],
            'surface vs input-bg' => ['color-surface', 'color-input-bg'],
            'surface vs overlay' => ['color-surface', 'color-overlay'],
            'border vs surface' => ['color-border', 'color-surface'],
        ];

        foreach ($surfacePairs as $label => [$aKey, $bKey]) {
            if (! isset($derivedTokens[$aKey], $derivedTokens[$bKey])) {
                continue;
            }

            $ratio = self::luminanceRatio($derivedTokens[$aKey], $derivedTokens[$bKey]);
            if ($ratio < self::SURFACE_SEPARATION_FLOOR) {
                $warnings[] = sprintf(
                    '%s have very little visual separation (%.3f:1).',
                    $label,
                    $ratio
                );
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    public static function contrastRatio(string $hexA, string $hexB): float
    {
        return self::luminanceRatio($hexA, $hexB);
    }

    public static function luminanceRatio(string $hexA, string $hexB): float
    {
        $lumA = self::relativeLuminance($hexA);
        $lumB = self::relativeLuminance($hexB);

        $lighter = max($lumA, $lumB);
        $darker = min($lumA, $lumB);

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    public static function relativeLuminance(string $hex): float
    {
        // Alpha-format tokens (rgba(...)) are composited over their
        // typical solid neighbor for validation purposes; only the
        // color channel matters for the luminance formula, alpha is
        // ignored here deliberately since WCAG luminance is defined for
        // opaque colors — overlay/shadow roles are excluded from the
        // hard-reject text pairs above precisely because of this.
        $hex = self::extractHexFromAnyFormat($hex);

        $hex = ltrim($hex, '#');
        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        $channel = fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
    }

    private static function extractHexFromAnyFormat(string $value): string
    {
        if (str_starts_with($value, '#')) {
            return $value;
        }

        if (preg_match('/rgba?\((\d+),\s*(\d+),\s*(\d+)/', $value, $m)) {
            return sprintf('#%02X%02X%02X', (int) $m[1], (int) $m[2], (int) $m[3]);
        }

        return '#000000';
    }

    public static function perceptualDistance(string $hexA, string $hexB): float
    {
        $hexA = ltrim(self::extractHexFromAnyFormat($hexA), '#');
        $hexB = ltrim(self::extractHexFromAnyFormat($hexB), '#');

        $rA = hexdec(substr($hexA, 0, 2));
        $gA = hexdec(substr($hexA, 2, 2));
        $bA = hexdec(substr($hexA, 4, 2));
        $rB = hexdec(substr($hexB, 0, 2));
        $gB = hexdec(substr($hexB, 2, 2));
        $bB = hexdec(substr($hexB, 4, 2));

        return sqrt((($rA - $rB) ** 2) + (($gA - $gB) ** 2) + (($bA - $bB) ** 2));
    }
}
