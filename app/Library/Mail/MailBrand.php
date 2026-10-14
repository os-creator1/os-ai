<?php

namespace App\Library\Mail;

use App\Library\Website\Design\BrandColors;

/**
 * The identity one transactional email renders: who it is from, in what colour,
 * and what the footer says. Every field is already normalised here, so the mail
 * Blade components only ever escape-and-print it.
 *
 * Presentation only. Which brand applies is decided by MailBrandResolver from the
 * notification's own subject (the Business a document belongs to, the Agency that
 * invited) — never from the signed-in user or the request. The From address is NOT
 * part of the brand: Business-owned mail is still sent from the platform's
 * authenticated transactional identity.
 */
final class MailBrand
{
    public const PLATFORM_ACCENT = '#b4483d';

    private const BUNDLED_DEFAULT_LOGO = 'images/branding/default-logo.svg';

    private const NEUTRAL_INK = '#ffffff';

    public readonly string $accent;
    public readonly string $accentInk;
    public readonly string $accentText;
    public readonly string $accentTint;

    private function __construct(
        public readonly string $name,
        ?string $accent,
        public readonly ?string $logoUrl,
        /** 'platform' | 'business' | 'agency' */
        public readonly string $kind,
        /** @var array<int, string> plain-text footer lines (escaped on output) */
        public readonly array $footerLines,
        public readonly ?string $homeUrl = null,
    ) {
        $tokens = BrandColors::tokens($accent) ?? BrandColors::tokens(self::PLATFORM_ACCENT);

        $this->accent = $tokens['accent'];
        $this->accentInk = $tokens['accent_ink'];
        $this->accentText = $tokens['accent_text'];
        $this->accentTint = self::tint($tokens['accent']);
    }

    public static function platform(): self
    {
        $name = trim((string) config('app.name')) ?: 'MotionGrove';

        return new self(
            name: $name,
            accent: self::PLATFORM_ACCENT,
            logoUrl: self::platformLogoUrl(),
            kind: 'platform',
            footerLines: ['© ' . date('Y') . ' ' . $name . '. All rights reserved.'],
            homeUrl: (string) config('app.url') ?: null,
        );
    }

    public static function business(string $businessName, ?string $accent, ?string $logoUrl): self
    {
        $platform = trim((string) config('app.name')) ?: 'MotionGrove';
        $name = self::clean($businessName) ?? $platform;

        return new self(
            name: $name,
            accent: $accent,
            logoUrl: self::emailSafeLogo($logoUrl),
            kind: 'business',
            footerLines: [
                'Sent by ' . $name . '. ' . $platform . ' securely delivers this notification on behalf of ' . $name . '.',
                '© ' . date('Y') . ' ' . $platform . '. All rights reserved.',
            ],
        );
    }

    /** Agency white-label identity: the Agency's own name, colour and logo. */
    public static function agency(string $displayName, ?string $accent, ?string $logoUrl): self
    {
        $platform = trim((string) config('app.name')) ?: 'MotionGrove';
        $name = self::clean($displayName) ?? $platform;

        return new self(
            name: $name,
            accent: $accent,
            logoUrl: self::emailSafeLogo($logoUrl),
            kind: 'agency',
            footerLines: [
                'Sent by ' . $name . ' through ' . $platform . '.',
                '© ' . date('Y') . ' ' . $platform . '. All rights reserved.',
            ],
        );
    }

    /** One or two letters for the monogram tile shown when there is no usable logo. */
    public function initials(): string
    {
        $parts = preg_split('/\s+/u', trim($this->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $letters = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $letters !== '' ? $letters : '•';
    }

    /**
     * The owner's own platform logo (Platform Settings → config('app.logo')) as an email-safe
     * absolute HTTPS raster URL, or null. The stored value is a relative public path, and may be
     * an SVG that mail clients cannot render; in that case a same-named PNG beside it is used.
     * The bundled neutral default is never a platform identity, and nothing is invented.
     */
    public static function platformLogoUrl(): ?string
    {
        $path = ltrim(trim((string) config('app.logo')), '/');

        if ($path === '' || $path === self::BUNDLED_DEFAULT_LOGO) {
            return null;
        }

        // An owner logo is a relative public path written by Platform Settings. Only a file that
        // really exists is ever referenced; an SVG is replaced by an existing same-named PNG.
        if (! preg_match('#^https?://#i', $path)) {
            if (str_contains($path, '..')) {
                return null;
            }

            if (preg_match('/\.svg$/i', $path)) {
                $path = substr($path, 0, -4) . '.png';
            }

            if (! preg_match('/\.(png|jpe?g|gif)$/i', $path) || ! is_file(public_path($path))) {
                return null;
            }
        }

        if (! preg_match('#^https?://#i', $path)) {
            // Built from configuration, not asset()/request(): mail is rendered from queued jobs
            // with no request, so the URL generator's root cannot be trusted to be the public host.
            $root = rtrim((string) (config('app.asset_url') ?: config('app.url')), '/');
            $path = $root . '/' . $path;
        }

        return self::emailSafeLogo(self::absoluteHttps($path));
    }

    private static function absoluteHttps(string $url): string
    {
        $appUrl = (string) config('app.url');

        return str_starts_with($appUrl, 'https://') && str_starts_with($url, 'http://')
            ? 'https://' . substr($url, 7)
            : $url;
    }

    /** Absolute HTTPS raster image only: Gmail/Outlook do not render SVG, and http images are blocked or flagged. */
    public static function emailSafeLogo(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '' || ! str_starts_with($url, 'https://') || preg_match('/[\s"\'<>]/', $url) === 1) {
            return null;
        }

        $path = strtolower((string) parse_url($url, PHP_URL_PATH));

        return preg_match('/\.(png|jpe?g|gif)$/', $path) === 1 ? $url : null;
    }

    private static function clean(?string $value): ?string
    {
        $value = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, 80);
    }

    /** The colour at ~10% over white, for the icon tile and details panel accent. */
    private static function tint(string $hex): string
    {
        $mix = static fn (int $c): int => (int) round($c + (255 - $c) * 0.9);

        return sprintf('#%02x%02x%02x', $mix(hexdec(substr($hex, 1, 2))), $mix(hexdec(substr($hex, 3, 2))), $mix(hexdec(substr($hex, 5, 2))));
    }
}
