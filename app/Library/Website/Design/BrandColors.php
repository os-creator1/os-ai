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

    /** A style attribute value of CSS custom properties, or '' when there is no usable brand colour. */
    public static function inlineStyle(?string $value): string
    {
        $tokens = self::tokens($value);

        if ($tokens === null) {
            return '';
        }

        return '--wd-accent:' . $tokens['accent'] . ';--wd-accent-ink:' . $tokens['accent_ink']
            . ';--wd-accent-text:' . $tokens['accent_text'] . ';--wd-accent-glow:' . $tokens['accent_glow'] . ';';
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
