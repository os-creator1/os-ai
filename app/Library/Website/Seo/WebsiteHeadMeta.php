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

    /**
     * @param  array<string, mixed>  $page  the page as a snapshot array (title, seo, is_home …)
     * @param  array<string, mixed>  $websiteMeta  snapshot['website'] (name, theme …)
     * @param  array<string, array<string, mixed>>  $assetsByUid
     * @return array{title: string, description: ?string, canonical: ?string, og: array<string, string>, twitter: array<string, string>, lang: string}
     */
    public function build(array $page, array $websiteMeta, ?string $canonicalUrl, array $assetsByUid): array
    {
        $siteName = trim((string) ($websiteMeta['name'] ?? ''));
        $seo = (array) ($page['seo'] ?? []);

        $title = self::title((string) ($seo['seo_title'] ?? ''), (string) ($page['title'] ?? ''), $siteName);
        $description = self::description($seo['meta_description'] ?? null);

        $og = ['og:title' => $title];

        if ($description !== null) {
            $og['og:description'] = $description;
        }

        $og['og:type'] = 'website';

        if ($siteName !== '') {
            $og['og:site_name'] = $siteName;
        }

        if ($canonicalUrl !== null && $canonicalUrl !== '') {
            $og['og:url'] = $canonicalUrl;
        }

        $image = $this->shareImage($websiteMeta, $assetsByUid);

        if ($image !== null) {
            $og['og:image'] = $image['url'];

            if ($image['alt'] !== '') {
                $og['og:image:alt'] = $image['alt'];
            }
        }

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonicalUrl !== null && $canonicalUrl !== '' ? $canonicalUrl : null,
            'og' => $og,
            'twitter' => ['twitter:card' => $image !== null ? 'summary_large_image' : 'summary'],
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
     * The owner's hero image, else their logo — an image the Business owns
     * and published; never a platform placeholder. Prefers the widest
     * derivative up to 1280px (a share card never needs the multi-MB
     * original).
     *
     * @param  array<string, mixed>  $websiteMeta
     * @param  array<string, array<string, mixed>>  $assetsByUid
     * @return array{url: string, alt: string}|null
     */
    private function shareImage(array $websiteMeta, array $assetsByUid): ?array
    {
        $theme = (array) ($websiteMeta['theme'] ?? []);

        foreach (['hero_asset_uid', 'logo_asset_uid'] as $key) {
            $uid = $theme[$key] ?? null;
            $asset = $uid !== null ? ($assetsByUid[$uid] ?? null) : null;

            if (! is_array($asset)) {
                continue;
            }

            $url = self::bestUrl($asset);

            if ($url !== null) {
                return ['url' => $url, 'alt' => Str::limit(trim((string) ($asset['alt_text'] ?? '')), 120, '')];
            }
        }

        return null;
    }

    /**
     * The best absolute URL to share for an asset payload: the widest derivative up to 1280px, else the original.
     *
     * @param  array<string, mixed>  $asset
     */
    public static function bestUrl(array $asset): ?string
    {
        $best = null;

        foreach ((array) ($asset['variants'] ?? []) as $variant) {
            if (! is_array($variant) || empty($variant['url']) || empty($variant['w'])) {
                continue;
            }

            if ($variant['w'] <= 1280 && ($best === null || $variant['w'] > $best['w'])) {
                $best = $variant;
            }
        }

        $url = $best['url'] ?? ($asset['url'] ?? null);

        return is_string($url) && preg_match('#^https?://#i', $url) === 1 ? $url : null;
    }
}
