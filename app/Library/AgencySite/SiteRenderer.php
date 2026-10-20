<?php

namespace App\Library\AgencySite;

use App\Models\AgencySite;

/**
 * White Label — renders an Agency site page through the MotionGrove marketing views, then applies the Agency's
 * palette. Used for the hosted public site, the authenticated draft preview and the publish-time render check,
 * so the three can never disagree about what a page looks like.
 */
final class SiteRenderer
{
    /** The design's palette, and how each colour is derived from the Agency's brand colour. */
    private const BRAND = '#B4514A';

    private const INK = '#181413';

    public function __construct(
        private readonly SiteContentBuilder $builder,
        private readonly SiteContextFactory $contexts,
    ) {
    }

    public function site(AgencySite $site, array $values, ?string $previewWorkspaceUid = null): AgencyMarketingSite
    {
        return $this->builder->site($values, $this->contexts->make($site, $values, $previewWorkspaceUid));
    }

    /** @return string|null the HTML, or null when this site does not offer that page */
    public function page(AgencySite $site, array $values, string $page, ?string $previewWorkspaceUid = null): ?string
    {
        if (! in_array($page, SiteSchema::PAGES, true)) {
            return null;
        }

        $mg = $this->site($site, $values, $previewWorkspaceUid);

        if ($page !== 'home' && ! $mg->pageVisible($page)) {
            return null;
        }

        $html = view('marketing.pages.'.$page, ['mg' => $mg, 'page' => $page, 'meta' => $mg->meta($page)])->render();
        $html = $this->colourize($html, $mg->context());

        return $mg->context()->preview ? $this->previewBanner($html) : $html;
    }

    /** marketing.css with the Agency's colours. */
    public function css(SiteContext $context): string
    {
        $css = (string) file_get_contents(public_path('marketing/css/marketing.css'));

        return $this->colourize($css, $context);
    }

    public function colourize(string $text, SiteContext $context): string
    {
        $primary = strtolower($context->primaryColor);
        $map = [
            '#b4514a' => $primary,
            '#9e443e' => self::mix($primary, '#000000', 0.12),
            '#8f3b35' => self::mix($primary, '#000000', 0.25),
            '#f6e7e4' => self::mix($primary, '#ffffff', 0.88),
            '#fbe9e6' => self::mix($primary, '#ffffff', 0.9),
            '#e8a49c' => self::mix($primary, '#ffffff', 0.5),
        ];

        if ($context->secondaryColor !== null && self::luminance($context->secondaryColor) < 0.12) {
            $map['#181413'] = strtolower($context->secondaryColor);
        }

        return str_ireplace(array_keys($map), array_values($map), $text);
    }

    private function previewBanner(string $html): string
    {
        $banner = '<div role="status" style="position:fixed;left:0;right:0;bottom:0;z-index:99999;background:#181413;color:#fff;font:600 13px/1.4 system-ui,sans-serif;padding:8px 14px;text-align:center">Draft preview — this is not published</div>';

        return str_replace('</body>', $banner.'</body>', $html);
    }

    /** WCAG contrast of white text on the colour, for the publish checklist. */
    public static function contrastWithWhite(string $hex): float
    {
        return 1.05 / (self::luminance($hex) + 0.05);
    }

    public static function luminance(string $hex): float
    {
        $channels = array_map(function (string $pair): float {
            $v = hexdec($pair) / 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($hex, '#'), 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function mix(string $hex, string $with, float $weight): string
    {
        $a = array_map('hexdec', str_split(ltrim($hex, '#'), 2));
        $b = array_map('hexdec', str_split(ltrim($with, '#'), 2));

        return sprintf('#%02x%02x%02x', ...array_map(fn ($i) => (int) round($a[$i] * (1 - $weight) + $b[$i] * $weight), [0, 1, 2]));
    }
}
