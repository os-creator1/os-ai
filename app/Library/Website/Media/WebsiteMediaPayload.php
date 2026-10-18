<?php

namespace App\Library\Website\Media;

use App\Enums\Website\WebsiteAssetPurpose;
use App\Models\WebsiteAsset;

/**
 * Website V1 final — builds the media payload every renderer receives
 * (preview, template preview, and the frozen published snapshot): the
 * original's URL / alt / dimensions plus its responsive variants.
 *
 * Key order follows MySQL's JSON order (length, then alphabetical) because a
 * snapshot is compared byte-for-byte after a database round trip.
 */
final class WebsiteMediaPayload
{
    public function __construct(private readonly ImageVariants $variants) {}

    /**
     * @param  bool  $ensure  generate the derivatives first when the asset has none (an asset uploaded
     *                        before responsive images existed) — additive and idempotent, never touching the original
     * @return array{uid: string, url: string, width: ?int, height: ?int, alt_text: ?string, variants: array<int, array{h: int, w: int, url: string}>}
     */
    public function forAsset(WebsiteAsset $asset, bool $ensure = false): array
    {
        if ($ensure && $this->variants->isSupported($asset->path) && $this->variants->existing($asset->path) === []) {
            $this->variants->generate($asset->path, self::stepsFor($asset->purpose));
        }

        return [
            'uid' => $asset->uid,
            'url' => $asset->url(),
            'width' => $asset->width !== null ? (int) $asset->width : null,
            'height' => $asset->height !== null ? (int) $asset->height : null,
            'alt_text' => $asset->alt_text,
            'variants' => ResponsiveImage::variantsFor($asset->path),
        ];
    }

    /**
     * Backdrop sections carry their pictures as plain URLs (Business-owned
     * images). This adds each picture's dimensions and variants, by the same
     * file convention, so backdrops are responsive too. A URL that is not one
     * of our stored images is left exactly as it was.
     *
     * @param  array<int, array<string, mixed>>  $sections
     * @return array<int, array<string, mixed>>
     */
    public function enrichSections(array $sections): array
    {
        foreach ($sections as $sectionIndex => $section) {
            if (($section['type'] ?? null) !== 'backdrops') {
                continue;
            }

            foreach ($section['data']['items'] ?? [] as $itemIndex => $item) {
                foreach ($item['images'] ?? [] as $imageIndex => $image) {
                    $path = $this->relativePathOf((string) ($image['url'] ?? ''));

                    if ($path === null) {
                        continue;
                    }

                    $info = is_file(public_path($path)) ? @getimagesize(public_path($path)) : false;
                    $enriched = $image;

                    if ($info !== false) {
                        $enriched['width'] = $info[0];
                        $enriched['height'] = $info[1];
                    }

                    $enriched['variants'] = ResponsiveImage::variantsFor($path);
                    $sections[$sectionIndex]['data']['items'][$itemIndex]['images'][$imageIndex] = $enriched;
                }
            }
        }

        return $sections;
    }

    /** A small URL for admin thumbnails: the narrowest derivative, else the original. */
    public static function thumbUrl(WebsiteAsset $asset): string
    {
        return ResponsiveImage::variantsFor($asset->path)[0]['url'] ?? $asset->url();
    }

    /** @return array<int, int> */
    public static function stepsFor(?WebsiteAssetPurpose $purpose): array
    {
        return $purpose === WebsiteAssetPurpose::Logo ? ImageVariants::LOGO_WIDTHS : ImageVariants::WIDTHS;
    }

    private function relativePathOf(string $url): ?string
    {
        $base = rtrim(asset(''), '/') . '/';
        $path = str_starts_with($url, $base) ? substr($url, strlen($base)) : ltrim($url, '/');
        $path = strtok($path, '?') ?: '';

        return str_starts_with($path, 'images/') && ! str_contains($path, '..') ? $path : null;
    }
}
