<?php

namespace App\Library\Website\Design;

/**
 * Website V1 final — maps the owner's ONE brand colour onto a design's safe
 * tokens. The owner never picks a palette or a text colour: they pick a
 * brand colour, and this class derives every colour that has to stay
 * readable from it (WCAG contrast, never trusted from the input):
 *
 *  - accent        the brand colour itself (buttons, rules, accents)
 *  - accent_ink    text ON the accent (dark or white, whichever is readable)
 *  - accent_text   the accent as TEXT on a light background (darkened until AA)
 *  - accent_glow   the accent as TEXT on a dark background (lightened until AA)
 *
 * A value that is not a plain #rgb/#rrggbb hex is ignored (null), so the
 * design's own accent is used and nothing owner-typed ever reaches a style
 * attribute unvalidated.
 */
final class BrandColors
{
    /**
     * The owner's optional palette overrides (theme keys), each a plain hex. Primary and secondary have their own keys
     * (brand_color / secondary_brand_color); these are the rest: the shared dark colour and the advanced surfaces.
     */
    public const PALETTE_KEYS = [
        'dark_bg_color', 'footer_bg_color', 'surface_color', 'tint_color', 'page_bg_color',
        'header_bg_color', 'announce_bg_color', 'text_color', 'muted_color',
    ];

    private const LIGHT_SURFACE = '#ffffff';

    private const DARK_SURFACE = '#111111';

    private const AA = 4.5;

    public static function normalize(?string $value): ?string
    {
        $value = strtolower(trim((string) $value));

        if (preg_match('/^#([0-9a-f]{3})$/', $value, $m)) {
            $value = '#' . $m[1][0] . $m[1][0] . $m[1][1] . $m[1][1] . $m[1][2] . $m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }

    /**
     * @return array{accent: string, accent_ink: string, accent_text: string, accent_glow: string}|null
     */
    public static function tokens(?string $value): ?array
    {
        $hex = self::normalize($value);

        if ($hex === null) {
            return null;
        }

        return [
            'accent' => $hex,
            // Pure black, not near-black: for a colour of mid luminance neither #111 nor white reaches AA, black always does.
            'accent_ink' => self::contrast($hex, '#000000') >= self::contrast($hex, '#ffffff') ? '#000000' : '#ffffff',
            'accent_text' => self::adjust($hex, self::LIGHT_SURFACE, darken: true),
            'accent_glow' => self::adjust($hex, self::DARK_SURFACE, darken: false),
        ];
    }

    /**
     * A style attribute value of CSS custom properties, or '' when there is no usable brand colour. The optional
     * secondary colour adds --wd-secondary* tokens (same derived-contrast rules); without one nothing is emitted,
     * so a site that never set it renders exactly as before.
     */
    public static function inlineStyle(?string $value, ?string $secondary = null): string
    {
        $tokens = self::tokens($value);
        $style = $tokens === null ? '' : '--wd-accent:' . $tokens['accent'] . ';--wd-accent-ink:' . $tokens['accent_ink']
            . ';--wd-accent-text:' . $tokens['accent_text'] . ';--wd-accent-glow:' . $tokens['accent_glow'] . ';';

        $second = self::tokens($secondary);

        if ($second !== null) {
            $style .= '--wd-secondary:' . $second['accent'] . ';--wd-secondary-ink:' . $second['accent_ink']
                . ';--wd-secondary-text:' . $second['accent_text'] . ';--wd-secondary-glow:' . $second['accent_glow'] . ';';
        }

        return $style;
    }

    /**
     * Everything the owner customised, as one style attribute value: primary, secondary, then the palette overrides.
     * Nothing is emitted for a colour that is not set, so a website without overrides renders exactly as its design.
     *
     * @param  ?array<string, mixed>  $theme
     */
    public static function themeStyle(?array $theme): string
    {
        $theme ??= [];

        return self::inlineStyle($theme['brand_color'] ?? null, $theme['secondary_brand_color'] ?? null) . self::paletteStyle($theme);
    }

    /**
     * The body classes that switch on the palette-override CSS blocks (one per customised colour).
     *
     * @param  ?array<string, mixed>  $theme
     */
    public static function paletteClasses(?array $theme): string
    {
        $classes = [];

        foreach (self::PALETTE_KEYS as $key) {
            if (self::normalize($theme[$key] ?? null) !== null) {
                $classes[] = 'wd-pal-' . str_replace('_', '-', preg_replace('/_(bg_)?color$/', '', $key));
            }
        }

        return implode(' ', $classes);
    }

    /**
     * Palette-override custom properties. Every background the owner picks also gets readable ink, muted ink, a line
     * colour and an accent-as-text colour derived from it (WCAG, never trusted from the input).
     *
     * @param  array<string, mixed>  $theme
     */
    public static function paletteStyle(array $theme): string
    {
        $c = [];

        foreach (self::PALETTE_KEYS as $key) {
            $hex = self::normalize($theme[$key] ?? null);

            if ($hex !== null) {
                $c[$key] = $hex;
            }
        }

        if ($c === []) {
            return '';
        }

        $primary = self::normalize($theme['brand_color'] ?? null);
        $out = '';
        $set = function (string $name, string $value) use (&$out): void {
            $out .= '--wd-' . $name . ':' . $value . ';';
        };

        // Body text colours are checked against every LIGHT surface they can land on (white unless the owner changed it).
        $refs = ['#ffffff'];

        foreach (['surface_color', 'tint_color'] as $surface) {
            if (isset($c[$surface]) && ! self::isDark($c[$surface])) {
                $refs[] = $c[$surface];
            }
        }

        $text = isset($c['text_color']) ? self::readableOnAll($c['text_color'], $refs) : null;
        $muted = isset($c['muted_color']) ? self::readableOnAll($c['muted_color'], $refs) : null;

        if ($text !== null) {
            $set('ink', $text);
        }

        if ($muted !== null) {
            $set('muted', $muted);
        }

        if (isset($c['dark_bg_color'])) {
            $dark = $c['dark_bg_color'];
            $d = self::derive($dark, null, null, $primary);
            $set('dark', $dark);
            $set('dark-ink', $d['ink']);
            $set('dark-muted', $d['muted']);
            $set('dark-line', $d['line']);
            $set('dark-2', self::mix($dark, $d['is_dark'] ? '#ffffff' : '#000000', 0.08));
            $set('card-dark', self::alpha($d['ink_hex'], 0.07));

            if ($d['accent'] !== 'var(--wd-accent-glow)') {
                $set('accent-glow', $d['accent']);
            }

            if (($second = self::normalize($theme['secondary_brand_color'] ?? null)) !== null) {
                $set('secondary-glow', self::adjust($second, $dark, darken: ! $d['is_dark']));
            }
        }

        if (isset($c['surface_color'])) {
            $d = self::derive($c['surface_color'], $text, $muted, $primary);
            $set('light', $c['surface_color']);
            $set('surface-ink', $d['ink']);
            $set('surface-muted', $d['muted']);
            $set('surface-line', $d['line']);
            $set('surface-accent', $d['accent']);
        }

        if (isset($c['tint_color'])) {
            $d = self::derive($c['tint_color'], $text, $muted, $primary);
            $set('tint', $c['tint_color']);
            $set('tint-2', $c['tint_color']);
            $set('tint-ink', $d['ink']);
            $set('tint-muted', $d['muted']);
            $set('tint-line', $d['line']);
            $set('tint-accent', $d['accent']);
            $set('tint-card', $d['is_dark'] ? 'rgba(255,255,255,0.08)' : '#ffffff');
            $set('tint-card-ink', $d['is_dark'] ? '#ffffff' : 'var(--wd-ink)');
        }

        if (isset($c['page_bg_color'])) {
            $d = self::derive($c['page_bg_color'], $text, $muted, $primary);
            $set('page-bg', $c['page_bg_color']);
            $set('page-ink', $d['ink']);
        }

        foreach (['header' => 'header_bg_color', 'announce' => 'announce_bg_color', 'footer' => 'footer_bg_color'] as $name => $key) {
            if (! isset($c[$key])) {
                continue;
            }

            $d = self::derive($c[$key], $text, $muted, $primary);
            $set($name . '-bg', $c[$key]);
            $set($name . '-ink', $d['ink']);
            $set($name . '-muted', $d['muted']);
            $set($name . '-line', $d['line']);
            $set($name . '-accent', $d['accent']);
        }

        return $out;
    }

    /**
     * Readable foreground set for a custom background. A light background keeps the design's own ink (or the owner's
     * text colour) when that is already readable; otherwise black or white, whichever contrasts more.
     *
     * @return array{ink: string, ink_hex: string, muted: string, line: string, accent: string, is_dark: bool}
     */
    private static function derive(string $bg, ?string $textPref, ?string $mutedPref, ?string $primary): array
    {
        $dark = self::isDark($bg);

        if ($textPref !== null && self::contrast($textPref, $bg) >= self::AA) {
            $ink = $inkHex = $textPref;
        } elseif (! $dark && $textPref === null && self::contrast($bg, '#111111') >= 7.0) {
            $ink = 'var(--wd-ink)';
            $inkHex = '#111111';
        } else {
            $ink = $inkHex = self::contrast($bg, '#000000') >= self::contrast($bg, '#ffffff') ? '#000000' : '#ffffff';
        }

        if ($mutedPref !== null && self::contrast($mutedPref, $bg) >= self::AA) {
            $muted = $mutedPref;
        } elseif ($ink === 'var(--wd-ink)' && self::contrast($bg, '#5a4a78') >= self::AA) {
            $muted = 'var(--wd-muted)';
        } else {
            $muted = self::mix($inkHex, $bg, 0.3);

            if (self::contrast($muted, $bg) < self::AA) {
                $muted = $inkHex;
            }
        }

        $accent = $primary !== null
            ? self::adjust($primary, $bg, darken: ! $dark)
            : ($dark ? 'var(--wd-accent-glow)' : 'var(--wd-accent-text)');

        return [
            'ink' => $ink,
            'ink_hex' => $inkHex,
            'muted' => $muted,
            'line' => $ink === 'var(--wd-ink)' ? 'var(--wd-line)' : self::alpha($inkHex, 0.18),
            'accent' => $accent,
            'is_dark' => $dark,
        ];
    }

    private static function isDark(string $hex): bool
    {
        return self::contrast($hex, '#ffffff') > self::contrast($hex, '#111111');
    }

    /** Darkens $hex until it reaches AA on every light reference surface. @param list<string> $refs */
    private static function readableOnAll(string $hex, array $refs): string
    {
        $color = $hex;

        for ($i = 0; $i < 40; $i++) {
            $worst = min(array_map(fn (string $ref): float => self::contrast($color, $ref), $refs));

            if ($worst >= self::AA) {
                break;
            }

            $color = self::mix($color, '#000000', 0.08);
        }

        return $color;
    }

    private static function alpha(string $hex, float $a): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $a . ')';
    }

    public static function contrast(string $foreground, string $background): float
    {
        $a = self::luminance($foreground);
        $b = self::luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function adjust(string $hex, string $surface, bool $darken): string
    {
        $color = $hex;

        for ($i = 0; $i < 40 && self::contrast($color, $surface) < self::AA; $i++) {
            $color = self::mix($color, $darken ? '#000000' : '#ffffff', 0.08);
        }

        return $color;
    }

    private static function mix(string $hex, string $with, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($hex);
        [$r2, $g2, $b2] = self::rgb($with);

        return sprintf('#%02x%02x%02x', (int) round($r1 + ($r2 - $r1) * $amount), (int) round($g1 + ($g2 - $g1) * $amount), (int) round($b1 + ($b2 - $b1) * $amount));
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (int $channel): float {
            $s = $channel / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
