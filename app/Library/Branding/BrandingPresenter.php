<?php

namespace App\Library\Branding;

use Illuminate\Support\Facades\Cache;

/**
 * Design System M2 Platform Branding contract §6.4/§11 item 9. The single
 * rendering seam every branding Blade component (§6.5) reads through —
 * no Blade view resolves this class or any repository/manager directly.
 * Mirrors App\Library\Theme\PlatformThemeManager's own
 * Cache::rememberForever + DB::afterCommit invalidation pattern (§6.13 of
 * the Design System M2 contract).
 */
class BrandingPresenter
{
    public const CACHE_KEY = 'platform_branding:resolved';

    private const AUTH_ILLUSTRATION_FALLBACK = [
        'light' => 'images/pages/login-v2.svg',
        'dark' => 'images/pages/login-v2-dark.svg',
    ];

    private const INSTALLER_ILLUSTRATION_FALLBACK = [
        'light' => 'images/pages/create-account.svg',
        'dark' => 'images/pages/create-account.svg',
    ];

    /**
     * §6.4: resolution order — the owner-configured value for the exact
     * requested variant/background combination, else the owner-configured
     * full, light-background logo, else null (the calling component
     * renders the deterministic text/SVG mark instead, §6.5).
     *
     * @return array{src: ?string, alt: string, isFallback: bool}
     */
    public function logo(string $variant = 'full', string $background = 'light'): array
    {
        $config = $this->resolved();
        $alt = $config['name'];

        $key = $this->logoConfigKey($variant, $background);
        $exact = $config[$key] ?? null;

        if (! empty($exact)) {
            return ['src' => $exact, 'alt' => $alt, 'isFallback' => false];
        }

        if (! empty($config['logo'])) {
            return ['src' => $config['logo'], 'alt' => $alt, 'isFallback' => false];
        }

        return ['src' => null, 'alt' => $alt, 'isFallback' => true];
    }

    private function logoConfigKey(string $variant, string $background): string
    {
        if ($variant === 'compact') {
            return 'logo_compact';
        }

        if ($background === 'dark') {
            return 'logo_dark';
        }

        return 'logo';
    }

    /**
     * §6.4: never empty — config('app.favicon') itself always defaults to
     * the bundled SVG (§5), so this is the floor, not a second fallback
     * layer.
     */
    public function favicon(): string
    {
        $config = $this->resolved();

        return $config['favicon'];
    }

    /**
     * §6.4: $surface is 'auth' or 'installer'. Owner-configured value,
     * else the appropriate existing bundled Vuexy illustration for that
     * exact surface — never a broken reference.
     *
     * @return array{src: string, alt: string}
     */
    public function illustration(string $surface, string $background = 'light'): array
    {
        $config = $this->resolved();
        $key = $surface === 'installer' ? 'installer_illustration' : 'auth_illustration';
        $configured = $config[$key] ?? null;

        if (! empty($configured)) {
            return ['src' => $configured, 'alt' => $config['name']];
        }

        $fallbackTable = $surface === 'installer'
            ? self::INSTALLER_ILLUSTRATION_FALLBACK
            : self::AUTH_ILLUSTRATION_FALLBACK;

        $src = $background === 'dark' ? $fallbackTable['dark'] : $fallbackTable['light'];

        return ['src' => $src, 'alt' => $config['name']];
    }

    /**
     * §6.4: configured value, else config('app.name') — never empty,
     * since config('app.name') itself always has a real value (§5).
     */
    public function footerCompanyName(): string
    {
        $config = $this->resolved();

        return ! empty($config['footer_company_name']) ? $config['footer_company_name'] : $config['name'];
    }

    /**
     * §6.4: composes "© {year} {company}. {wording}" — wording omitted
     * entirely, not rendered as a stray period, if the owner has not set
     * one. $currentYear is computed at render time on every call, never
     * stored, never owner-editable as a literal value.
     */
    public function footerCopyrightLine(): string
    {
        $config = $this->resolved();
        $year = now()->year;
        $company = $this->footerCompanyName();
        $wording = trim((string) ($config['footer_copyright_text'] ?? ''));

        $line = "© {$year} {$company}.";

        if ($wording !== '') {
            $line .= " {$wording}";
        }

        return $line;
    }

    /**
     * @return array{name: string, logo: ?string, logo_compact: ?string, logo_dark: ?string, favicon: string, auth_illustration: ?string, installer_illustration: ?string, footer_company_name: ?string, footer_copyright_text: ?string}
     */
    private function resolved(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return [
                'name' => config('app.name'),
                'logo' => config('app.logo'),
                'logo_compact' => config('app.logo_compact'),
                'logo_dark' => config('app.logo_dark'),
                'favicon' => config('app.favicon'),
                'auth_illustration' => config('app.auth_illustration'),
                'installer_illustration' => config('app.installer_illustration'),
                'footer_company_name' => config('app.footer_company_name'),
                'footer_copyright_text' => config('app.footer_copyright_text'),
            ];
        });
    }
}
