<?php

namespace App\Library\Seo\Content;

use App\Enums\Seo\ArticleStatus;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\WebsiteArticle;

/**
 * SEO Content Engine V1 — deterministic internal-link suggestions for an article or an opportunity.
 * No AI, no provider call.
 *
 * A suggestion is a link the owner may add; it is never inserted for them. Every link is a STABLE REFERENCE
 * (a page uid or an article uid — see ArticleMarkdown) and only ever points at something a visitor can reach:
 *
 *  - a PAGE of this Business that is in the published snapshot and not noindex (ArticleSiteInventory
 *    `linkable`) — so never a Preview URL, an unpublished or draft page, a noindex page, or another
 *    Business's page;
 *  - an ARTICLE of this Business that is Published and not noindex — never a draft, scheduled or archived
 *    article. References are by uid, so a retired slug (a "stale alias") can never be suggested.
 *
 * What is suggested, in order, at most MAX links:
 *   1. the page the article/topic supports;
 *   2. the packages page (a reader who has finished a cost or planning article needs the next step);
 *   3. the location page for a city the topic names (or the article's own location);
 *   4. the contact page;
 *   5. up to three related published articles: the same supported page, or a shared distinguishing subject
 *      word (a core word that is not common to the Business's own service pages or its name).
 */
final class ArticleInternalLinkSuggester
{
    public const MAX = 7;
    private const MAX_RELATED_ARTICLES = 3;

    public function __construct(
        private readonly ArticleSiteInventory $inventory,
        private readonly ArticleSupportPageResolver $resolver,
    ) {
    }

    /**
     * @return array<int, array{type: string, uid: string, title: string, anchor: string}>
     */
    public function suggest(Business $business, ?WebsiteArticle $article, ?string $supportsPageUid, ?string $topic): array
    {
        $article = $article !== null && (int) $article->business_id === (int) $business->id ? $article : null;

        return $this->forPages(
            $business,
            $this->inventory->pages($business),
            $this->publishedArticles($business),
            $supportsPageUid ?? $article?->supports_page_uid,
            $topic ?? ($article !== null ? ($article->primary_topic ?: $article->title) : null),
            $article?->id,
            $article?->business_location_id,
        );
    }

    /**
     * The same suggestion, from pages and published articles the caller has already loaded (the opportunity
     * engine builds many at once and must not repeat the queries).
     *
     * @param  array<int, array<string, mixed>>  $pages  ArticleSiteInventory pages
     * @param  array<int, array{id: int, uid: string, title: string, topic: string, supports_page_uid: ?string}>  $publishedArticles  see publishedArticles()
     * @return array<int, array{type: string, uid: string, title: string, anchor: string}>
     */
    public function forPages(Business $business, array $pages, array $publishedArticles, ?string $supportsPageUid, ?string $topic, ?int $excludeArticleId = null, ?int $locationId = null): array
    {
        $linkable = ArticleSupportPageResolver::linkable($pages);
        $links = [];
        $add = function (string $type, array $item, string $anchor) use (&$links): void {
            $key = $type . ':' . $item['uid'];

            if (! isset($links[$key])) {
                $links[$key] = ['type' => $type, 'uid' => (string) $item['uid'], 'title' => (string) $item['title'], 'anchor' => $anchor];
            }
        };

        $supporting = null;

        if ($supportsPageUid !== null && $supportsPageUid !== '') {
            foreach ($linkable as $page) {
                if ($page['uid'] === $supportsPageUid) {
                    $supporting = $page;
                    $add('page', $page, $this->anchorFor($page));
                }
            }
        }

        foreach ($linkable as $page) {
            if ($page['kind'] === ArticleSiteInventory::KIND_PACKAGES) {
                $add('page', $page, $this->anchorFor($page));
                break;
            }
        }

        $locationPage = $this->resolver->pageForCityInText($linkable, strtolower((string) $topic));

        if ($locationPage === null && $locationId !== null) {
            $city = BusinessLocation::query()->where('business_id', $business->id)->whereKey($locationId)->value('city');
            $locationPage = $city ? $this->resolver->pageForCity($linkable, (string) $city) : null;
        }

        if ($locationPage !== null) {
            $add('page', $locationPage, $this->anchorFor($locationPage));
        }

        foreach ($linkable as $page) {
            if ($page['kind'] === ArticleSiteInventory::KIND_CONTACT) {
                $add('page', $page, $this->anchorFor($page));
                break;
            }
        }

        foreach ($this->relatedArticles($pages, $publishedArticles, $supporting['uid'] ?? $supportsPageUid, $topic, $excludeArticleId, $business) as $related) {
            $add('article', $related, (string) $related['title']);
        }

        return array_slice(array_values($links), 0, self::MAX);
    }

    /**
     * This Business's published, indexable articles — the only articles that may be linked to.
     *
     * @return array<int, array{id: int, uid: string, title: string, topic: string, supports_page_uid: ?string}>
     */
    public function publishedArticles(Business $business): array
    {
        return WebsiteArticle::query()
            ->where('business_id', $business->id)
            ->where('status', ArticleStatus::Published->value)
            ->whereNotNull('published_at')
            ->where('noindex', false)
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get(['id', 'uid', 'title', 'primary_topic', 'supports_page_uid'])
            ->map(fn (WebsiteArticle $a) => [
                'id' => (int) $a->id,
                'uid' => (string) $a->uid,
                'title' => (string) $a->title,
                'topic' => (string) ($a->primary_topic ?: $a->title),
                'supports_page_uid' => $a->supports_page_uid !== null ? (string) $a->supports_page_uid : null,
            ])
            ->all();
    }

    /**
     * @param  array<int, array<string, mixed>>  $pages
     * @param  array<int, array{id: int, uid: string, title: string, topic: string, supports_page_uid: ?string}>  $articles
     * @return array<int, array{id: int, uid: string, title: string, topic: string, supports_page_uid: ?string}>
     */
    private function relatedArticles(array $pages, array $articles, ?string $supportsPageUid, ?string $topic, ?int $excludeArticleId, Business $business): array
    {
        if ($articles === [] || ($topic === null && $supportsPageUid === null)) {
            return [];
        }

        $common = $this->commonTokens($pages, $business);
        $topicCore = $topic !== null ? array_diff(ArticleTopicSignature::of($topic)['core'], $common) : [];
        $scored = [];

        foreach ($articles as $i => $candidate) {
            if ($excludeArticleId !== null && $candidate['id'] === $excludeArticleId) {
                continue;
            }

            $shared = count(array_intersect($topicCore, array_diff(ArticleTopicSignature::of($candidate['topic'])['core'], $common)));
            $samePage = $supportsPageUid !== null && $candidate['supports_page_uid'] === $supportsPageUid;

            if ($shared === 0 && ! $samePage) {
                continue;
            }

            // Newest first among equals: $articles is already ordered by published_at desc.
            $scored[] = ['score' => $shared * 2 + ($samePage ? 1 : 0), 'order' => $i, 'article' => $candidate];
        }

        usort($scored, fn (array $a, array $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_map(fn (array $s) => $s['article'], array_slice($scored, 0, self::MAX_RELATED_ARTICLES));
    }

    /**
     * Words that say nothing about WHICH subject an article is about: the Business's own name, and the words
     * shared by at least half of its service pages ("photo", "booth", "rental").
     *
     * @param  array<int, array<string, mixed>>  $pages
     * @return array<int, string>
     */
    private function commonTokens(array $pages, Business $business): array
    {
        $common = ArticleTopicSignature::of((string) $business->name)['core'];
        $services = array_values(array_filter($pages, fn (array $p) => $p['kind'] === ArticleSiteInventory::KIND_SERVICE));

        if (count($services) >= 2) {
            $count = [];

            foreach ($services as $page) {
                foreach (ArticleTopicSignature::of($page['title'])['core'] as $token) {
                    $count[$token] = ($count[$token] ?? 0) + 1;
                }
            }

            foreach ($count as $token => $n) {
                if ($n * 2 >= count($services)) {
                    $common[] = (string) $token;
                }
            }
        }

        return array_values(array_unique($common));
    }

    /** @param  array<string, mixed>  $page */
    private function anchorFor(array $page): string
    {
        return $page['kind'] === ArticleSiteInventory::KIND_CONTACT ? 'contact us' : (string) $page['title'];
    }
}
