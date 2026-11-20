<?php

namespace App\Library\Website\Blog;

use App\Library\Seo\Content\ArticleMarkdown;
use App\Models\Website;
use App\Models\WebsiteArticle;
use Illuminate\Support\Str;

/**
 * The published articles a commercial page of a website points to ("Helpful guides"), resolved at render time to their CURRENT addresses.
 *
 * Only articles that are published (never a draft, a scheduled one or an archived one), indexable (an article the owner kept out of search is
 * not promoted) and the website's own are ever returned, and only when the site has a live Blog (the navigation entry for it is the proof, and
 * is also where the surface-correct address of the blog comes from: preview, platform path or custom domain). A page no article supports gets
 * nothing — except the HOME page, which, like the benchmark's, shows the newest guides so a visitor can find the blog from the front door.
 * The owner's Preview shows none (articles are public content of the live site).
 */
final class WebsiteRelatedArticles
{
    public const MAX = 3;

    /**
     * @param  array<int, array<string, mixed>>  $navigationPages  carries the blog entry once an article is live
     * @return array{heading: string, items: array<int, array{title: string, url: string, excerpt: string}>}
     */
    public function forPage(?Website $website, mixed $page, array $navigationPages, bool $isPreview): array
    {
        $none = ['heading' => 'Helpful guides', 'items' => []];
        $uid = is_object($page) ? ($page->uid ?? null) : null;
        $blog = collect($navigationPages)->firstWhere('slug', 'blog');

        if ($isPreview || $website === null || ! is_string($uid) || $uid === '' || $blog === null || empty($blog['url'])) {
            return $none;
        }

        $isHome = is_object($page) && ! empty($page->is_home);
        $base = rtrim((string) $blog['url'], '/');

        $query = fn () => WebsiteArticle::query()->published()->where('website_id', $website->id)->where('noindex', false);

        // The home page promises the newest guides, so its list is globally newest-first even when an older article explicitly supports home.
        // Other pages show only the guides that explicitly support that page.
        $articles = $isHome
            ? $query()->orderByDesc('published_at')->limit(self::MAX)->get()
            : $query()->where('supports_page_uid', $uid)->orderByDesc('published_at')->limit(self::MAX)->get();

        return [
            'heading' => $isHome ? 'Latest guides' : 'Helpful guides',
            'items' => $articles->take(self::MAX)->map(fn (WebsiteArticle $article) => [
                'title' => (string) $article->title,
                'url' => $base . '/' . $article->slug,
                'excerpt' => $this->excerpt($article),
            ])->values()->all(),
        ];
    }

    private function excerpt(WebsiteArticle $article): string
    {
        $excerpt = trim((string) $article->excerpt);

        return $excerpt !== '' ? Str::limit($excerpt, 170) : Str::limit(ArticleMarkdown::plainText((string) $article->body), 170);
    }
}
