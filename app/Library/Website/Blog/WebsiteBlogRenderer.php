<?php

namespace App\Library\Website\Blog;

use App\Enums\Seo\ArticleIntent;
use App\Library\Seo\Content\ArticleMarkdown;
use App\Library\Website\Media\WebsiteMediaPayload;
use App\Library\Website\Seo\WebsiteArticleStructuredData;
use App\Library\Website\Seo\WebsiteSocialMetadata;
use App\Library\Website\WebsitePublicEntitlementGate;
use App\Library\Website\WebsiteSearchVisibility;
use App\Models\Website;
use App\Models\WebsiteArticle;
use App\Models\WebsiteArticleSlugHistory;
use App\Models\WebsiteRevision;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * SEO Content Engine V1 — renders /blog and /blog/{slug} through the ONE Website renderer.
 *
 * There is no second public site. This class gathers an article or the article list into the same
 * variable shape the Website's page renderer already takes, and hands it to the same layout
 * (`public.website.page`): the template's header, footer, typography, CTA, brand, responsive images and
 * canonical host all come from that layout and its composer, so a blog page is a Website page of the
 * template it is on. The layout gets one extra variable, `$blog`, which tells it to put the blog index or
 * the article where a page's sections would go.
 *
 * What it reads:
 *   - the PUBLISHED Website revision (cached the same way as pages) for navigation, brand, and the pages an
 *     article may link to — so an unpublished Website serves no blog at all;
 *   - `website_articles` rows with status Published, scoped to this Website. A draft, scheduled or archived
 *     article is never loaded by any public path (`WebsiteArticle::published()`).
 *
 * Indexing, decided in one place (`indexable()`): the surface must be the custom domain, the Website's own
 * Home page must be open to search (WebsiteSearchVisibility::siteOpenToSearch — the owner's Website-wide
 * choice), and the article must not be noindex. An article can only narrow indexing, never widen it.
 *
 * Makes no AI call and no provider call; reading the blog is pure database + view.
 */
class WebsiteBlogRenderer
{
    public const PER_PAGE = 9;

    private const SNAPSHOT_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly WebsitePublicEntitlementGate $gate,
        private readonly WebsiteMediaPayload $media,
        private readonly WebsiteSocialMetadata $social,
        private readonly WebsiteArticleStructuredData $schema,
    ) {
    }

    // ------------------------------------------------------------------ public entry points

    public function index(Website $website, WebsiteBlogSurface $surface, int $page = 1): Response
    {
        $snapshot = $this->publishedSnapshotOrAbort($website);

        $query = WebsiteArticle::query()->published()->where('website_id', $website->id);
        $total = (clone $query)->count();
        abort_if($total === 0, 404);

        $last = max(1, (int) ceil($total / self::PER_PAGE));
        abort_if($page < 1 || $page > $last, 404);

        $articles = $query->with('featuredAsset')
            ->orderByDesc('published_at')->orderByDesc('id')
            ->forPage($page, self::PER_PAGE)->get();

        $open = WebsiteSearchVisibility::siteOpenToSearch($snapshot);
        $hasIndexable = WebsiteArticle::query()->published()->where('website_id', $website->id)->where('noindex', false)->exists();
        $indexable = $surface->mayIndex() && $open && $hasIndexable;

        $name = (string) ($snapshot['website']['name'] ?? $website->name);
        $canonical = $surface->canonicalBlogUrl($page);

        $trail = [['name' => 'Home', 'url' => $surface->homeUrl()], ['name' => 'Blog', 'url' => $surface->blogUrl()]];

        return $this->respond($website, $snapshot, $surface, [
            'title' => 'Blog',
            'seo_title' => $name . ' Blog: guides and tips',
            'meta_description' => 'Guides, ideas and planning advice from ' . $name . '.',
            'noindex' => ! $indexable,
            'canonical' => $canonical,
            'indexable' => $indexable,
            'social' => $this->social->forArticle($name, $canonical, null, null, null),
            'jsonLd' => $indexable ? $this->schema->breadcrumbs($trail) : null,
            'articleJsonLd' => null,
            'blog' => [
                'mode' => 'index',
                'heading' => $name . ' Blog',
                'intro' => 'Guides, ideas and planning advice from ' . $name . '.',
                'cards' => $articles->map(fn (WebsiteArticle $a) => $this->card($a, $surface))->all(),
                'page' => $page,
                'last_page' => $last,
                'prev_url' => $page > 1 ? $surface->blogUrl($page - 1) : null,
                'next_url' => $page < $last ? $surface->blogUrl($page + 1) : null,
                'trail' => $trail,
            ],
        ], 'blog');
    }

    /**
     * 200 with the article, a 301 to its current address when the slug was changed, or a 404.
     */
    public function article(Website $website, WebsiteBlogSurface $surface, string $slug): Response|\Illuminate\Http\RedirectResponse
    {
        $snapshot = $this->publishedSnapshotOrAbort($website);

        $article = WebsiteArticle::query()->published()->where('website_id', $website->id)->where('slug', $slug)->with('featuredAsset')->first();

        if ($article === null) {
            $old = WebsiteArticleSlugHistory::query()->where('website_id', $website->id)->where('slug', $slug)->first();
            $current = $old !== null
                ? WebsiteArticle::query()->published()->where('website_id', $website->id)->whereKey($old->website_article_id)->first()
                : null;

            abort_if($current === null, 404);

            return redirect()->away($surface->articleUrl($current->slug), 301);
        }

        return $this->articleResponse($website, $snapshot, $surface, $article);
    }

    /** The owner's preview of ANY article (even a draft): same layout, always noindex, with a banner. */
    public function renderArticlePreview(Website $website, WebsiteArticle $article): Response
    {
        abort_unless((int) $article->website_id === (int) $website->id, 404);

        return $this->articleResponse($website, $this->previewSnapshot($website), WebsiteBlogSurface::preview($website), $article->loadMissing('featuredAsset'));
    }

    /**
     * Absolute URLs this surface adds to its sitemap: the blog index (only when it is itself indexable) and
     * every published, indexable article. Empty unless the site is open to search.
     *
     * @return array<int, string>
     */
    public function sitemapUrls(Website $website, WebsiteBlogSurface $surface, array $snapshot): array
    {
        if (! WebsiteSearchVisibility::siteOpenToSearch($snapshot)) {
            return [];
        }

        $slugs = WebsiteArticle::query()->published()->where('website_id', $website->id)->where('noindex', false)
            ->orderByDesc('published_at')->pluck('slug')->all();

        if ($slugs === []) {
            return [];
        }

        return array_merge([$surface->blogUrl()], array_map(fn (string $slug) => $surface->articleUrl($slug), $slugs));
    }

    /** The "Blog" navigation entry, or null when the site has no published article yet. */
    public function navigationEntry(Website $website, WebsiteBlogSurface $surface): ?array
    {
        if (! WebsiteArticle::query()->published()->where('website_id', $website->id)->exists()) {
            return null;
        }

        return ['uid' => 'blog', 'title' => 'Blog', 'is_home' => false, 'slug' => 'blog', 'has_form' => false, 'url' => $surface->blogUrl()];
    }

    // ------------------------------------------------------------------ internals

    private function articleResponse(Website $website, array $snapshot, WebsiteBlogSurface $surface, WebsiteArticle $article): Response
    {
        // Publisher / author identity is the Business itself (real configuration), not the site's display name.
        $name = trim((string) $website->business?->name) !== '' ? trim((string) $website->business->name) : (string) ($snapshot['website']['name'] ?? $website->name);
        $open = WebsiteSearchVisibility::siteOpenToSearch($snapshot);
        $indexable = $surface->mayIndex() && $open && ! $article->noindex && $article->isPublished();
        $canonical = $surface->canonicalArticleUrl($article->slug);

        $image = $article->featuredAsset !== null ? $this->media->forAsset($article->featuredAsset) : null;
        $socialImage = $image;
        $logoUid = $snapshot['website']['theme']['logo_asset_uid'] ?? null;
        $logo = $logoUid !== null ? collect($snapshot['assets'] ?? [])->firstWhere('uid', $logoUid) : null;

        $trail = [
            ['name' => 'Home', 'url' => $surface->homeUrl()],
            ['name' => 'Blog', 'url' => $surface->blogUrl()],
            ['name' => $article->title, 'url' => $surface->articleUrl($article->slug)],
        ];

        $schemaCanonical = $canonical ?? $surface->articleUrl($article->slug);
        $schemaImage = $image !== null && ! empty($image['url']) ? ['url' => (string) $image['url']] : null;
        $schemaLogo = is_array($logo) && ! empty($logo['url']) ? ['url' => (string) $logo['url']] : null;

        $html = ArticleMarkdown::toHtml($article->body, $this->refResolver($website, $surface, $snapshot, $article));

        $supports = $this->supportedPage($article, $snapshot, $surface);

        return $this->respond($website, $snapshot, $surface, [
            'title' => $article->title,
            'seo_title' => $article->seo_title,
            'meta_description' => trim((string) ($article->meta_description ?: $article->excerpt)),
            'noindex' => ! $indexable,
            'canonical' => $canonical,
            'indexable' => $indexable,
            'social' => $this->social->forArticle($name, $canonical, $socialImage, $article->published_at?->toIso8601String(), $article->dateModified()?->toIso8601String()),
            'jsonLd' => $indexable ? $this->schema->breadcrumbs($trail) : null,
            'articleJsonLd' => $indexable ? $this->schema->blogPosting($article, $name, $schemaCanonical, $schemaImage, $schemaLogo) : null,
            'blog' => [
                'mode' => 'article',
                'article' => [
                    'title' => $article->title,
                    'excerpt' => $article->excerpt,
                    'html' => $html,
                    'author' => trim((string) $article->author_name) !== '' ? trim((string) $article->author_name) : $name,
                    'published_at' => $article->published_at,
                    'modified_at' => $article->dateModified(),
                    'intent' => ArticleIntent::tryFrom((string) $article->search_intent)?->label(),
                    'image' => $image,
                ],
                'supports' => $supports,
                'related' => $this->related($website, $article, $surface),
                'trail' => $trail,
                'blog_url' => $surface->blogUrl(),
                'packages_url' => $this->pageUrlBySlug($snapshot, 'packages', $surface),
                'is_preview' => $surface->isPreview(),
                'is_published' => $article->isPublished(),
            ],
        ], 'blog');
    }

    /**
     * @param  array<string, mixed>  $page  title, seo_title, meta_description, noindex, canonical, indexable, social, jsonLd, articleJsonLd, blog
     */
    private function respond(Website $website, array $snapshot, WebsiteBlogSurface $surface, array $page, string $slug): Response
    {
        $assetsByUid = collect($snapshot['assets'] ?? [])->keyBy('uid')->all();

        $navigation = $this->navigationPages($website, $snapshot, $surface);

        $response = response()->view('public.website.page', [
            'website' => $website,
            'websiteMeta' => $snapshot['website'] ?? ['name' => $website->name, 'theme' => $website->theme ?? []],
            'page' => (object) [
                'uid' => 'blog',
                'title' => $page['title'],
                'slug' => $slug,
                'is_home' => false,
                'seo' => (object) ['seo_title' => $page['seo_title'], 'meta_description' => $page['meta_description'], 'noindex' => $page['noindex']],
            ],
            'sections' => [],
            'assetsByUid' => $assetsByUid,
            'formsByUid' => [],
            'isPreview' => $surface->isPreview(),
            'previewBannerText' => 'Preview — this article is not live on your website',
            'allowIndexing' => $surface->mayIndex(),
            'canonicalUrl' => $page['canonical'],
            'localBusinessJsonLd' => null,
            'breadcrumbJsonLd' => $page['jsonLd'],
            'faqJsonLd' => null,
            'articleJsonLd' => $page['articleJsonLd'],
            'socialMetaOverride' => $surface->isPreview() ? null : $page['social'],
            'navigationPages' => $navigation,
            'blog' => $page['blog'],
        ]);

        return $response->header('X-Robots-Tag', $page['indexable'] ? 'index, follow' : 'noindex, follow');
    }

    /**
     * The same navigation list the page renderers build (every snapshot page, addressed for this surface),
     * plus the Blog entry when there is one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function navigationPages(Website $website, array $snapshot, WebsiteBlogSurface $surface): array
    {
        $pages = collect($snapshot['pages'] ?? [])->map(fn ($candidate) => [
            'uid' => $candidate['uid'],
            'title' => $candidate['title'],
            'is_home' => $candidate['is_home'],
            'slug' => $candidate['slug'] ?? null,
            'has_form' => collect($candidate['sections'] ?? [])->contains(fn ($section) => ($section['type'] ?? null) === 'form'),
            'url' => $surface->pageUrl($candidate),
        ])->all();

        $blog = $this->navigationEntry($website, $surface) ?? ($surface->isPreview() ? ['uid' => 'blog', 'title' => 'Blog', 'is_home' => false, 'slug' => 'blog', 'has_form' => false, 'url' => $surface->blogUrl()] : null);

        if ($blog !== null) {
            $pages[] = $blog;
        }

        return $pages;
    }

    /**
     * Turns `page:<uid>` / `article:<uid>` references in a body into this surface's addresses. A target that
     * is not a linkable page of THIS published Website, or not a published indexable article of THIS Website,
     * resolves to nothing and renders as plain text.
     */
    private function refResolver(Website $website, WebsiteBlogSurface $surface, array $snapshot, WebsiteArticle $article): callable
    {
        $pages = collect($snapshot['pages'] ?? [])->keyBy('uid');

        $articleUids = collect(ArticleMarkdown::internalRefs($article->body))->where('type', 'article')->pluck('uid')->unique()->values()->all();
        $articles = $articleUids === [] ? collect() : WebsiteArticle::query()->published()
            ->where('website_id', $website->id)->where('noindex', false)->whereIn('uid', $articleUids)->get(['uid', 'slug'])->keyBy('uid');

        return function (string $type, string $uid) use ($pages, $articles, $surface): ?string {
            if ($type === 'page') {
                $page = $pages->get($uid);

                return $page !== null && ! ($page['seo']['noindex'] ?? false) ? $surface->pageUrl($page) : null;
            }

            $target = $articles->get($uid);

            return $target !== null ? $surface->articleUrl($target->slug) : null;
        };
    }

    /** @return array{title: string, url: string}|null */
    private function supportedPage(WebsiteArticle $article, array $snapshot, WebsiteBlogSurface $surface): ?array
    {
        if ($article->supports_page_uid === null) {
            return null;
        }

        $page = collect($snapshot['pages'] ?? [])->firstWhere('uid', $article->supports_page_uid);

        if ($page === null || ($page['seo']['noindex'] ?? false) || ($page['is_home'] ?? false)) {
            return null;
        }

        return ['title' => (string) $page['title'], 'url' => $surface->pageUrl($page)];
    }

    private function pageUrlBySlug(array $snapshot, string $slug, WebsiteBlogSurface $surface): ?string
    {
        $page = collect($snapshot['pages'] ?? [])->firstWhere('slug', $slug);

        return $page !== null && ! ($page['seo']['noindex'] ?? false) ? $surface->pageUrl($page) : null;
    }

    /** @return array<int, array<string, mixed>> */
    private function related(Website $website, WebsiteArticle $article, WebsiteBlogSurface $surface): array
    {
        return WebsiteArticle::query()->published()->where('website_id', $website->id)->where('noindex', false)
            ->where('id', '!=', $article->id)
            ->orderByRaw('CASE WHEN supports_page_uid <=> ? THEN 0 ELSE 1 END', [$article->supports_page_uid])
            ->orderByDesc('published_at')
            ->with('featuredAsset')
            ->limit(3)->get()
            ->map(fn (WebsiteArticle $a) => $this->card($a, $surface))->all();
    }

    /** @return array<string, mixed> */
    private function card(WebsiteArticle $article, WebsiteBlogSurface $surface): array
    {
        $excerpt = trim((string) $article->excerpt);

        if ($excerpt === '') {
            $excerpt = Str::limit(ArticleMarkdown::plainText($article->body), 170);
        }

        return [
            'title' => $article->title,
            'excerpt' => $excerpt,
            'url' => $surface->articleUrl($article->slug),
            'published_at' => $article->published_at,
            'intent' => ArticleIntent::tryFrom((string) $article->search_intent)?->label(),
            'image' => $article->featuredAsset !== null ? $this->media->forAsset($article->featuredAsset) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function publishedSnapshotOrAbort(Website $website): array
    {
        abort_unless($this->gate->allows($website), 404);

        $snapshot = Cache::remember(
            "website_public_{$website->public_id}_v{$website->published_revision_id}",
            self::SNAPSHOT_CACHE_TTL_SECONDS,
            fn () => WebsiteRevision::find($website->published_revision_id)?->snapshot,
        );

        abort_unless(is_array($snapshot), 404);

        return $snapshot;
    }

    /** The published snapshot when there is one, else a side-effect-free outline of the draft site. */
    private function previewSnapshot(Website $website): array
    {
        $published = $website->published_revision_id !== null ? WebsiteRevision::find($website->published_revision_id)?->snapshot : null;

        if (is_array($published)) {
            return $published;
        }

        return [
            'website' => ['name' => $website->name, 'theme' => $website->theme ?? []],
            'pages' => $website->pages()->orderBy('sort_order')->orderBy('id')->get()->map(fn ($p) => [
                'uid' => $p->uid, 'slug' => $p->slug, 'is_home' => (bool) $p->is_home, 'title' => $p->title,
                'seo' => ['seo_title' => $p->seo_title, 'meta_description' => $p->meta_description, 'noindex' => (bool) $p->noindex],
                'sections' => [],
            ])->all(),
            'assets' => [],
            'forms' => [],
        ];
    }
}
