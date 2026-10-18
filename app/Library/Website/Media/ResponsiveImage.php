<?php

namespace App\Library\Website\Media;

use Illuminate\Support\HtmlString;

/**
 * Website V1 final — the one <img> every public Website surface renders
 * (hero, logo, services, gallery, image+text, backdrops, custom section; all
 * four templates and legacy sites). Given an asset payload it emits `src`,
 * `srcset`, `sizes`, explicit `width`/`height` (no layout shift) and the
 * loading strategy: the hero is eager with high fetch priority, everything
 * else lazy and async-decoded.
 *
 * Asset payload: `url`, `alt_text`, optional `width`/`height` of the
 * original, and optional `variants` — a list of `{w, h, url}` (WebP
 * derivatives, see ImageVariants). A payload with no variants (an older
 * asset or an older published revision) renders the original, exactly as
 * before, plus whatever dimensions are known.
 */
final class ResponsiveImage
{
    /** The widest variant used as the plain `src` fallback (browsers without srcset). */
    private const FALLBACK_WIDTH = 1280;

    /**
     * @param  array<string, mixed>  $asset
     * @param  array{eager?: bool, priority?: bool, class?: string, alt?: ?string}  $options  priority: high fetch priority (hero only); alt: null = the asset's own alt text, '' = decorative
     */
    public static function tag(array $asset, string $sizes, array $options = []): HtmlString
    {
        $variants = array_values(array_filter((array) ($asset['variants'] ?? []), fn ($v) => is_array($v) && ! empty($v['url']) && ! empty($v['w'])));
        usort($variants, fn ($a, $b) => $a['w'] <=> $b['w']);

        $src = (string) ($asset['url'] ?? '');
        $width = isset($asset['width']) ? (int) $asset['width'] : null;
        $height = isset($asset['height']) ? (int) $asset['height'] : null;
        $srcset = null;

        if ($variants !== []) {
            $chosen = $variants[0];
            foreach ($variants as $variant) {
                if ($variant['w'] <= self::FALLBACK_WIDTH) {
                    $chosen = $variant;
                }
            }

            $src = (string) $chosen['url'];
            $width = (int) $chosen['w'];
            $height = (int) ($chosen['h'] ?? 0) ?: null;
            $srcset = implode(', ', array_map(fn ($v) => $v['url'] . ' ' . $v['w'] . 'w', $variants));
        }

        $eager = (bool) ($options['eager'] ?? false);
        $alt = array_key_exists('alt', $options) && $options['alt'] !== null ? (string) $options['alt'] : (string) ($asset['alt_text'] ?? '');

        $attributes = ['src' => $src];

        if ($srcset !== null) {
            $attributes['srcset'] = $srcset;
            $attributes['sizes'] = $sizes;
        }

        $attributes['alt'] = $alt;

        if ($width && $height) {
            $attributes['width'] = (string) $width;
            $attributes['height'] = (string) $height;
        }

        if (! $eager) {
            $attributes['loading'] = 'lazy';
        }

        // Only the hero (the page's largest paint) asks for high priority; an eager logo must not compete with it.
        if (! empty($options['priority'])) {
            $attributes['fetchpriority'] = 'high';
        }

        $attributes['decoding'] = 'async';

        if (! empty($options['class'])) {
            $attributes['class'] = (string) $options['class'];
        }

        $html = '<img';
        foreach ($attributes as $name => $value) {
            $html .= ' ' . $name . '="' . e($value) . '"';
        }

        return new HtmlString($html . '>');
    }

    /**
     * The same payload as a list of `{w,h,url}` for a stored original, from
     * the derivatives on disk. Empty when none exist.
     *
     * @return array<int, array{h: int, w: int, url: string}>
     */
    public static function variantsFor(string $relativePath): array
    {
        return array_map(
            fn (array $v) => ['h' => $v['h'], 'w' => $v['w'], 'url' => asset($v['path'])],
            app(ImageVariants::class)->existing($relativePath)
        );
    }
}
