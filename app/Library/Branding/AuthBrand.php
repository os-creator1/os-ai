<?php

namespace App\Library\Branding;

/**
 * Customer Experience Slice 2 — the safe presentation model an
 * authentication screen renders. It carries display strings and
 * already-validated public asset paths only: no model, no Workspace uid,
 * no configuration value, no filesystem path (contract §18, Slice 2 brief
 * §3/§9).
 */
final class AuthBrand
{
    /**
     * @param array<string, string> $tokens permitted CSS custom properties
     *                                       (name => validated value)
     * @param list<string>          $areas   the product areas named on the
     *                                       neutral panel
     */
    public function __construct(
        public readonly string $displayName,
        public readonly string $mark,
        public readonly AuthBrandSource $source,
        public readonly ?string $logoSrc = null,
        public readonly ?string $logoAlt = null,
        public readonly ?string $illustrationSrc = null,
        public readonly ?string $tagline = null,
        public readonly array $tokens = [],
        public readonly array $areas = [],
    ) {
    }

    public function isAgency(): bool
    {
        return $this->source === AuthBrandSource::Agency;
    }

    public function isNeutral(): bool
    {
        return $this->source === AuthBrandSource::Neutral;
    }

    public function hasLogo(): bool
    {
        return $this->logoSrc !== null;
    }

    public function hasIllustration(): bool
    {
        return $this->illustrationSrc !== null;
    }

    /**
     * The inline `style` attribute value for the permitted tokens, or null
     * when none applies. Names and values were validated by the presenter;
     * this never carries arbitrary CSS.
     */
    public function tokenStyle(): ?string
    {
        if ($this->tokens === []) {
            return null;
        }

        $declarations = [];

        foreach ($this->tokens as $name => $value) {
            $declarations[] = $name . ':' . $value;
        }

        return implode(';', $declarations);
    }
}
