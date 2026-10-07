<?php

namespace App\Library\Website\Seo;

use Illuminate\Support\Str;

/**
 * SEO V1 final — the ONE place a public Website page's <head> text is
 * decided, so the owner preview, the platform path and a custom domain
 * (all rendered by public.website.page) can never disagree.
 *
 * Rules, each a defect the SEO audit found in the old inline Blade:
 *  - the rendered <title> never repeats the Business name: the starter
 *    generator already writes "Services | Acme", and the layout used to
 *    append " — Acme" again ("Services | Acme — Acme");
 *  - a page with no real description gets NO description / og:description
 *    tag at all (an empty content="" is worse than none, and a site-wide
 *    fallback would repeat one description on every page);
 *  - og:url exists only where a canonical exists and always equals it;
 *  - og:image is only ever the owner's own hero or logo, never a fallback.
 */
final class WebsiteHeadMeta
{
    /** Titles longer than this are usually truncated in results. */
    public const TITLE_SOFT_LIMIT = 70;

    private const CARD_MIN_WIDTH = 1200;

    /**
     * @param  array<string, mixed>  $page  the page as a snapshot array (title, seo, is_home …)
     * @param  array<string, mixed>  $websiteMeta  snapshot['website'] (name, theme …)
     * @param  array<string, array<string, mixed>>  $assetsByUid
     * @param  array<int, array{type?: string, data?: array<string, mixed>}>  $sections  the page's sections (its own hero photo is the first og:image candidate)
     * @param  bool  $isPreview  the owner's Preview carries only title/description, never social extras
     * @return array{title: string, description: ?string, canonical: ?string, og: array<string, string>, twitter: array<string, string>, lang: string}
     */
    public function build(array $page, array $websiteMeta, ?string $canonicalUrl, array $assetsByUid, array $sections = [], bool $isPreview = false): array
    {
        $siteName = trim((string) ($websiteMeta['name'] ?? ''));
        $seo = (array) ($page['seo'] ?? []);

        $title = self::title((string) ($seo['seo_title'] ?? ''), (string) ($page['title'] ?? ''), $siteName);
        $description = self::description($seo['meta_description'] ?? null);
        $canonical = $canonicalUrl !== null && $canonicalUrl !== '' ? $canonicalUrl : null;

        $og = ['og:title' => $title];

        if ($description !== null) {
            $og['og:description'] = $description;
        }

        $twitter = [];

        if (! $isPreview) {
            $og['og:type'] = 'website';

            if ($siteName !== '') {
                $og['og:site_name'] = $siteName;
            }

            if ($canonical !== null) {
                $og['og:url'] = $canonical;
            }

            $image = $this->shareImage($sections, (array) ($websiteMeta['theme'] ?? []), $assetsByUid);

            if ($image !== null) {
                $og['og:image'] = $image['url'];

                if ($image['width'] && $image['height']) {
                    $og['og:image:width'] = (string) $image['width'];
                    $og['og:image:height'] = (string) $image['height'];
                }

                if ($image['alt'] !== '') {
                    $og['og:image:alt'] = $image['alt'];
                }

                $twitter['twitter:image'] = $image['url'];
            }

            $twitter = ['twitter:card' => $image !== null ? 'summary_large_image' : 'summary'] + $twitter;
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'og' => $og,
            'twitter' => $twitter,
            'lang' => 'en',
        ];
    }
    /**
     * "<seo title or page title> | <Business name>", unless the Business
     * name is already part of it (then it is used as written).
     */
    public static function title(string $seoTitle, string $pageTitle, string $siteName): string
    {
        $base = trim($seoTitle) !== '' ? trim($seoTitle) : trim($pageTitle);
        $siteName = trim($siteName);

        if ($siteName === '') {
            return $base;
        }

        if ($base === '') {
            return $siteName;
        }

        if (mb_stripos($base, $siteName) !== false) {
            return $base;
        }

        // The name is appended only while the whole title still fits in a search result; a long
        // page title is left whole rather than cut or pushed past the limit.
        $withName = $base.' | '.$siteName;

        return mb_strlen($withName) <= self::TITLE_SOFT_LIMIT ? $withName : $base;
    }

    public static function description(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        // Whitespace only: the owner's text is never rewritten (Blade escapes it on output).
        $clean = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $clean === '' ? null : $clean;
    }

    /**
     * The page's own hero photo, else the owner's Hero image — a Business-owned file the page already
     * uses and that really exists on disk, so a social card never points at a broken URL. The smallest
     * derivative wide enough for a card (>= 1200px) is preferred, else the widest, else the original.
     * No usable image: no og:image (and the small "summary" card).
     *
     * @param  array<int, array{type?: string, data?: array<string, mixed>}>  $sections
     * @param  array<string, mixed>  $theme
     * @param  array<string, array<string, mixed>>  $assetsByUid
     * @return array{url: string, width: ?int, height: ?int, alt: string}|null
     */
    private function shareImage(array $sections, array $theme, array $assetsByUid): ?array
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
            $picked = is_array($asset) ? $this->pick($asset) : null;

            if ($picked !== null) {
                return $picked + ['alt' => Str::limit(trim((string) ($asset['alt_text'] ?? '')), 120, '')];
            }
        }

        return null;
    }

    /** @return ?array{url: string, width: ?int, height: ?int} */
    private function pick(array $asset): ?array
    {
        $variants = array_values(array_filter((array) ($asset['variants'] ?? []), fn ($v) => is_array($v) && ! empty($v['url']) && ! empty($v['w'])));
        usort($variants, fn ($a, $b) => $a['w'] <=> $b['w']);

        $chosen = null;
        foreach ($variants as $variant) {
            if ($variant['w'] >= self::CARD_MIN_WIDTH) {
                $chosen = $variant;
                break;
            }
        }
        $chosen ??= $variants === [] ? null : end($variants);

        $options = [];
        if ($chosen !== null) {
            $options[] = ['url' => (string) $chosen['url'], 'width' => (int) $chosen['w'], 'height' => isset($chosen['h']) ? (int) $chosen['h'] : null];
        }
        $options[] = ['url' => (string) ($asset['url'] ?? ''), 'width' => isset($asset['width']) ? (int) $asset['width'] : null, 'height' => isset($asset['height']) ? (int) $asset['height'] : null];

        foreach ($options as $option) {
            if (self::existsOnDisk($option['url'])) {
                return $option;
            }
        }

        return null;
    }

    /**
     * The best absolute URL for an asset payload (a card-sized derivative, else the original), used by
     * structured data for the logo.
     *
     * @param  array<string, mixed>  $asset
     */
    public static function bestUrl(array $asset): ?string
    {
        $picked = (new self())->pick($asset);

        return $picked['url'] ?? null;
    }

    /** True only for an absolute URL whose file is really on disk under /images. */
    private static function existsOnDisk(string $url): bool
    {
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return false;
        }

        $path = ltrim((string) parse_url($url, PHP_URL_PATH), '/');

        return str_starts_with($path, 'images/') && ! str_contains($path, '..') && is_file(public_path($path));
    }
}