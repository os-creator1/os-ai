<?php

namespace App\Library\Website\Seo;

/**
 * Website V1 closure — the Open Graph / Twitter tags a published page carries beyond its title and
 * description: og:type, og:site_name, og:url (only when there is a canonical address), og:image (+ size)
 * and twitter:card.
 *
 * og:image is only ever a Business-owned photo this page already uses — the page's own hero photo, else
 * the owner's Hero image — and only when its file really exists on disk, so a social card never points
 * at a broken URL. The widest derivative up to 1920px is preferred (cards want ~1200px); an image with
 * no derivatives falls back to its original. With no usable image there is no og:image and the card is
 * the small "summary" kind.
 */
final class WebsiteSocialMetadata
{
    private const PREFERRED_MIN_WIDTH = 1200;

    /**
     * @param  array<int, array{type?: string, data?: array<string, mixed>}>  $sections
     * @param  array<string, array<string, mixed>>  $assetsByUid  the snapshot's assets (url, width, height, variants)
     * @param  array<string, mixed>  $theme
     * @return array{type: string, site_name: string, url: ?string, image: ?array{url: string, width: ?int, height: ?int, alt: ?string}, card: string}
     */
    public function build(string $siteName, ?string $canonicalUrl, array $sections, array $assetsByUid, array $theme): array
    {
        $image = $this->image($sections, $assetsByUid, $theme);

        return [
            'type' => 'website',
            'site_name' => $siteName,
            'url' => $canonicalUrl !== null && $canonicalUrl !== '' ? $canonicalUrl : null,
            'image' => $image,
            'card' => $image !== null ? 'summary_large_image' : 'summary',
        ];
    }

    /** @return ?array{url: string, width: ?int, height: ?int, alt: ?string} */
    private function image(array $sections, array $assetsByUid, array $theme): ?array
    {
        $candidates = [];

        foreach ($sections as $section) {
            if (($section['type'] ?? null) === 'hero' && ! empty($section['data']['background_image'])) {
                $candidates[] = (string) $section['data']['background_image'];
                break;
            }
        }

        if (! empty($theme['hero_asset_uid'])) {
            $candidates[] = (string) $theme['hero_asset_uid'];
        }

        foreach ($candidates as $uid) {
            $asset = $assetsByUid[$uid] ?? null;

            if (! is_array($asset)) {
                continue;
            }

            $picked = $this->pick($asset);

            if ($picked !== null) {
                return $picked + ['alt' => ($asset['alt_text'] ?? null) ?: null];
            }
        }

        return null;
    }

    /** @return ?array{url: string, width: ?int, height: ?int} */
    private function pick(array $asset): ?array
    {
        $variants = array_values(array_filter((array) ($asset['variants'] ?? []), fn ($v) => is_array($v) && ! empty($v['url']) && ! empty($v['w'])));
        usort($variants, fn ($a, $b) => $a['w'] <=> $b['w']);

        // The smallest variant that is wide enough for a card, else the widest there is.
        $chosen = null;
        foreach ($variants as $variant) {
            if ($variant['w'] >= self::PREFERRED_MIN_WIDTH) {
                $chosen = $variant;
                break;
            }
        }
        $chosen ??= $variants === [] ? null : end($variants);

        foreach (array_filter([$chosen !== null ? ['url' => $chosen['url'], 'width' => (int) $chosen['w'], 'height' => isset($chosen['h']) ? (int) $chosen['h'] : null] : null, ['url' => (string) ($asset['url'] ?? ''), 'width' => isset($asset['width']) ? (int) $asset['width'] : null, 'height' => isset($asset['height']) ? (int) $asset['height'] : null]]) as $option) {
            if ($this->exists($option['url'])) {
                return $option;
            }
        }

        return null;
    }

    /** True only for an absolute URL whose file is really on disk under /images. */
    private function exists(string $url): bool
    {
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }

        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return str_starts_with($path, 'images/') && ! str_contains($path, '..') && is_file(public_path($path));
    }
}
