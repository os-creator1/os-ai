<?php

namespace App\Library\Website\Blog;

use App\Models\Website;

/**
 * SEO Content Engine V1 — WHERE the blog is being rendered, and so what its addresses are.
 *
 * The same blog renders on three surfaces, exactly like a Website page does:
 *   - custom   the Website's Active primary custom domain: the only surface search engines may index;
 *   - platform the platform path /sites/{public_id}/...: always noindex; canonical points at the custom
 *              domain when there is one;
 *   - preview  the owner's article preview: always noindex, no canonical.
 *
 * One value object answers every "what is the URL of ..." question, so the renderer, the sitemap and the
 * navigation can never disagree about an address, and no Preview URL can leak into a canonical or sitemap.
 */
final class WebsiteBlogSurface
{
    public const CUSTOM = 'custom';
    public const PLATFORM = 'platform';
    public const PREVIEW = 'preview';

    private function __construct(
        public readonly string $kind,
        public readonly Website $website,
        private readonly ?string $domain,
    ) {
    }

    public static function custom(Website $website, string $domain): self
    {
        return new self(self::CUSTOM, $website, $domain);
    }

    public static function platform(Website $website): self
    {
        return new self(self::PLATFORM, $website, $website->activePrimaryDomain()?->domain);
    }

    public static function preview(Website $website): self
    {
        return new self(self::PREVIEW, $website, null);
    }

    /** Only the custom domain may be indexed; every other surface is noindex whatever the article says. */
    public function mayIndex(): bool
    {
        return $this->kind === self::CUSTOM;
    }

    public function isPreview(): bool
    {
        return $this->kind === self::PREVIEW;
    }

    public function homeUrl(): string
    {
        return $this->kind === self::CUSTOM
            ? 'https://' . $this->domain . '/'
            : route('public.website.home', $this->website->public_id);
    }

    /** @param  array{is_home?: bool, slug?: ?string}  $page */
    public function pageUrl(array $page): string
    {
        if ($page['is_home'] ?? false) {
            return $this->homeUrl();
        }

        return $this->kind === self::CUSTOM
            ? 'https://' . $this->domain . '/' . $page['slug']
            : route('public.website.page', [$this->website->public_id, $page['slug']]);
    }

    public function blogUrl(int $page = 1): string
    {
        $base = $this->kind === self::CUSTOM
            ? 'https://' . $this->domain . '/blog'
            : route('public.website.blog.index', $this->website->public_id);

        return $page > 1 ? $base . '?page=' . $page : $base;
    }

    public function articleUrl(string $slug): string
    {
        return $this->kind === self::CUSTOM
            ? 'https://' . $this->domain . '/blog/' . $slug
            : route('public.website.blog.show', [$this->website->public_id, $slug]);
    }

    /**
     * The canonical address an article or the index must declare: always on the Website's canonical host
     * (never a Preview or platform-path URL), or null when the Website has no live custom domain.
     */
    public function canonicalBlogUrl(int $page = 1): ?string
    {
        if ($this->kind === self::PREVIEW || $this->domain === null) {
            return null;
        }

        $base = 'https://' . $this->domain . '/blog';

        return $page > 1 ? $base . '?page=' . $page : $base;
    }

    public function canonicalArticleUrl(string $slug): ?string
    {
        if ($this->kind === self::PREVIEW || $this->domain === null) {
            return null;
        }

        return 'https://' . $this->domain . '/blog/' . $slug;
    }

    public function canonicalPageUrl(array $page): ?string
    {
        if ($this->kind === self::PREVIEW || $this->domain === null) {
            return null;
        }

        return ($page['is_home'] ?? false) ? 'https://' . $this->domain . '/' : 'https://' . $this->domain . '/' . $page['slug'];
    }
}
