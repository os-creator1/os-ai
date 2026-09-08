<?php

namespace App\Library\Branding;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Customer Experience Slice 2 — the one seam every authentication screen
 * reads its identity through (contract §9.1, Slice 2 brief §3).
 *
 * Precedence: an authorized Agency white-label brand for this request
 * (AgencyBrandResolver) → the platform owner's configured branding
 * (config/app through BrandingPresenter) → the neutral "AI Business OS"
 * identity. The neutral identity is a finished typographic panel, not a
 * missing asset: it references no image at all, so no `login-v2*.svg`
 * or other inherited illustration can ever be the effective default.
 *
 * Every asset reference is normalized here: only a relative path under
 * `images/branding/` with a permitted extension that exists in the
 * public directory is ever emitted; anything else silently becomes "no
 * image" and is never echoed. Only two CSS custom properties may be
 * customized, and only with a validated 6-digit hex value.
 */
class AuthBrandPresenter
{
    public const PRODUCT_NAME = 'AI Business OS';

    public const NEUTRAL_TAGLINE = 'Sign in to run your business from one place.';

    /** The product areas the merged customer shell actually offers. */
    public const NEUTRAL_AREAS = [
        'Contacts',
        'Conversations',
        'Campaigns',
        'Automations',
        'Website',
        'Google Business Profile',
        'Analytics',
    ];

    private const ASSET_PREFIX = 'images/branding/';

    private const ASSET_EXTENSIONS = ['png', 'jpg', 'jpeg', 'webp', 'svg', 'gif'];

    /** The only custom properties a brand may set (Slice 2 brief §3). */
    private const PERMITTED_TOKENS = ['--auth-brand-accent', '--auth-brand-accent-contrast'];

    public function __construct(
        private readonly AgencyBrandResolver $agencyBrands,
        private readonly BrandingPresenter $platform,
    ) {
    }

    public function for(Request $request): AuthBrand
    {
        $agency = $this->agencyBrands->resolve($request);

        if ($agency !== null) {
            return $this->fromAgency($agency);
        }

        return $this->platformOrNeutral();
    }

    public function neutral(): AuthBrand
    {
        return new AuthBrand(
            displayName: self::PRODUCT_NAME,
            mark: $this->markFor(self::PRODUCT_NAME),
            source: AuthBrandSource::Neutral,
            tagline: self::NEUTRAL_TAGLINE,
            areas: self::NEUTRAL_AREAS,
        );
    }

    private function fromAgency(AgencyBrand $agency): AuthBrand
    {
        $name = $this->cleanText($agency->displayName, 80) ?? self::PRODUCT_NAME;
        $logo = $this->publicAssetOrNull($agency->logoPath);
        $accent = $this->hexColorOrNull($agency->accentColor);

        return new AuthBrand(
            displayName: $name,
            mark: $this->markFor($name),
            source: AuthBrandSource::Agency,
            logoSrc: $logo,
            logoAlt: $logo !== null ? $name : null,
            tagline: $this->cleanText($agency->tagline, 160),
            tokens: $accent !== null ? [self::PERMITTED_TOKENS[0] => $accent] : [],
        );
    }

    private function platformOrNeutral(): AuthBrand
    {
        $name = $this->cleanText((string) config('app.name'), 80);
        $illustration = $this->publicAssetOrNull($this->platform->illustration('auth')['src'] ?? null);

        if ($name === null || ($name === self::PRODUCT_NAME && $illustration === null)) {
            return $this->neutral();
        }

        return new AuthBrand(
            displayName: $name,
            mark: $this->markFor($name),
            source: AuthBrandSource::Platform,
            illustrationSrc: $illustration,
            tagline: self::NEUTRAL_TAGLINE,
            areas: self::NEUTRAL_AREAS,
        );
    }

    /**
     * One or two initials for the typographic mark ("AI Business OS" → "AB").
     */
    private function markFor(string $name): string
    {
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';

        foreach ($words as $word) {
            $first = Str::upper(Str::substr($word, 0, 1));

            if ($first !== '' && preg_match('/[\p{L}\p{N}]/u', $first) === 1) {
                $letters .= $first;
            }

            if (Str::length($letters) === 2) {
                break;
            }
        }

        return $letters !== '' ? $letters : 'A';
    }

    /**
     * Plain text only: tags stripped, control characters removed,
     * whitespace collapsed, bounded length. Null when nothing remains.
     */
    private function cleanText(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
        $text = preg_replace('/[\x00-\x1F\x7F]/u', '', $text) ?? '';

        if ($text === '') {
            return null;
        }

        return Str::limit($text, $maxLength, '');
    }

    /**
     * A stored asset reference is emitted only when it is a relative path
     * inside the branding directory, carries a permitted extension, has
     * no traversal segment, and names an existing public file. The
     * rejected value is never returned or logged with the response.
     */
    private function publicAssetOrNull(?string $path): ?string
    {
        if ($path === null) {
            return null;
        }

        $path = trim(str_replace('\\', '/', $path));

        if ($path === ''
            || ! str_starts_with($path, self::ASSET_PREFIX)
            || str_contains($path, '..')
            || str_contains($path, '//')
            || str_contains($path, ':')
            || preg_match('/^[A-Za-z0-9_\-.\/]+$/', $path) !== 1) {
            return null;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (! in_array($extension, self::ASSET_EXTENSIONS, true)) {
            return null;
        }

        return is_file(public_path($path)) ? $path : null;
    }

    private function hexColorOrNull(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtolower(trim($value));

        return preg_match('/^#[0-9a-f]{6}$/', $value) === 1 ? $value : null;
    }
}
