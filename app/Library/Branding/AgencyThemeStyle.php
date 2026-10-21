<?php

namespace App\Library\Branding;

use App\Library\Theme\ThemeColorDerivationService;
use Illuminate\Http\Request;

/**
 * White Label — the Agency's colours as the SAME design tokens the platform theme already uses.
 *
 * Every customer-facing page is built from `--color-*` tokens (resources/scss/base/tokens), and the Platform Owner's
 * theme preset overrides them with one <style> block. A managed client of an Agency gets one more block after it,
 * carrying only the brand and interaction tokens derived from the Agency's primary (and optional accent) colour, so
 * every module — CRM, messaging, calendar, forms, websites, SEO, automations, payments, settings — recolours without
 * any module knowing about white label.
 *
 * Deliberately NOT touched: the status tokens (success / warning / danger / info / pending), text, surfaces and
 * borders. Errors and warnings keep their accessible semantic colours whatever the brand is.
 *
 * Authority is ClientChromeBrand's (persisted relationship, enabled brand, eligible Agency, entitlement); this class
 * only turns an already-normalised #rrggbb pair into CSS, so no stored value can reach the page unvalidated.
 */
final class AgencyThemeStyle
{
    /** Minimum contrast of a text/link colour on a white surface (WCAG AA for large text and UI components). */
    private const MIN_LINK_CONTRAST = 4.5;

    public function __construct(
        private readonly ClientChromeBrand $brand,
        private readonly ThemeColorDerivationService $colours,
    ) {
    }

    public function forRequest(Request $request): ?string
    {
        $chrome = $this->brand->current($request);

        if ($chrome === null || $chrome['accent'] === null) {
            return null;
        }

        return $this->block($chrome['accent'], $chrome['secondary']);
    }

    public function block(string $primary, ?string $secondary): string
    {
        $variants = $this->colours->deriveVariants($primary);
        $link = $this->readableOnWhite($primary);
        $linkHover = $this->readableOnWhite($variants['hover']);

        $tokens = [
            'color-primary' => $primary,
            'color-primary-hover' => $variants['hover'],
            'color-primary-active' => $variants['active'],
            'color-primary-soft-bg' => $variants['soft_bg'],
            'color-primary-border' => $variants['border'],
            'color-link' => $link,
            'color-link-hover' => $linkHover,
            'color-focus-ring' => $primary,
            'color-selection' => $variants['soft_bg'],
            'color-button-primary-bg' => $primary,
            'color-button-text' => $this->colours->foregroundOn($primary),
            'color-nav-hover' => $variants['soft_bg'],
            'color-nav-active' => $primary,
            'color-row-hover' => $variants['soft_bg'],
            'color-active-border' => $primary,
            'color-focus-border' => $primary,
            'color-chat-bubble-out' => $primary,
        ];

        if ($secondary !== null) {
            $secondaryVariants = $this->colours->deriveVariants($secondary);
            $tokens += [
                'color-secondary' => $secondary,
                'color-secondary-hover' => $secondaryVariants['hover'],
                'color-secondary-active' => $secondaryVariants['active'],
                'color-secondary-soft-bg' => $secondaryVariants['soft_bg'],
                'color-accent' => $secondary,
                'color-button-secondary-bg' => $secondary,
            ];
        }

        $css = '';

        foreach ($tokens as $name => $value) {
            $css .= '--'.$name.':'.$value.';';
        }

        return '<style id="agency-theme-overrides">:root{'.$css.'}</style>';
    }

    /**
     * The palette generated from the Agency's colours, for the Branding tab: exactly the values `block()` applies.
     *
     * @return list<array{label: string, hex: string, note: ?string}>
     */
    public function palette(string $primary, ?string $secondary): array
    {
        $variants = $this->colours->deriveVariants($primary);
        $buttonText = $this->colours->foregroundOn($primary);
        $link = $this->readableOnWhite($primary);
        $out = [
            ['label' => 'Primary (buttons, active items)', 'hex' => $primary, 'note' => 'Button text: '.$buttonText],
            ['label' => 'Hover', 'hex' => $variants['hover'], 'note' => null],
            ['label' => 'Pressed', 'hex' => $variants['active'], 'note' => null],
            ['label' => 'Soft background', 'hex' => $variants['soft_bg'], 'note' => null],
            ['label' => 'Links', 'hex' => $link, 'note' => $link === $primary ? null : 'Darkened so links stay readable on white'],
        ];

        if ($secondary !== null) {
            $out[] = ['label' => 'Accent', 'hex' => $secondary, 'note' => null];
        }

        return $out;
    }

    /** Darken a colour until it is readable as text on white, so a pale brand never makes links invisible. */
    private function readableOnWhite(string $hex): string
    {
        $candidate = $hex;

        for ($i = 0; $i < 8; $i++) {
            if ($this->contrastWithWhite($candidate) >= self::MIN_LINK_CONTRAST) {
                return $candidate;
            }

            $candidate = $this->colours->blendToward($candidate, '#000000', 0.2);
        }

        return $candidate;
    }

    private function contrastWithWhite(string $hex): float
    {
        return \App\Library\AgencySite\SiteRenderer::contrastWithWhite($hex);
    }
}
