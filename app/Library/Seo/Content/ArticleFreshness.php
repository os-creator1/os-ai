<?php

namespace App\Library\Seo\Content;

use App\Enums\Catalog\CatalogItemLifecycleState;
use App\Models\Business;
use App\Models\CatalogItem;
use App\Models\WebsiteArticle;
use Carbon\CarbonInterface;

/**
 * SEO Content Engine V1 — which PUBLISHED articles are worth a second look, and why. Deterministic and
 * read-only: it never rewrites an article, never calls an AI or a provider, and its messages only say
 * "review" or "update" — never that doing so will change a ranking.
 *
 * Reasons (stable codes the UI and Growth facts rely on):
 *
 *   old                      Nothing has changed the article's words and nobody has marked it reviewed for
 *                            more than OLD_AFTER_DAYS. "Last touched" is the later of the article's own
 *                            modified date (ArticleManager keeps `content_updated_at` for real text edits,
 *                            else `published_at`) and `last_reviewed_at`, so "Mark as reviewed" clears it.
 *
 *   catalog_changed          A Package/Product the article quotes (website_articles.referenced_catalog_uids,
 *                            recorded by ArticleManager) is now missing or archived, or was edited after the
 *                            article was last touched. Prices and inclusions are the facts most likely to
 *                            have gone out of date.
 *
 *   supported_page_changed   The page the article supports (supports_page_uid) is no longer in the
 *                            PUBLISHED website snapshot, or is now hidden from search engines. Deliberately
 *                            NOT "the site was republished since": every site publish would then flag every
 *                            article, which is noise the owner learns to ignore. When there is no published
 *                            snapshot at all this check is skipped (nothing can be judged against it).
 */
final class ArticleFreshness
{
    public const OLD_AFTER_DAYS = 365;

    public const REASON_OLD = 'old';
    public const REASON_CATALOG_CHANGED = 'catalog_changed';
    public const REASON_SUPPORTED_PAGE_CHANGED = 'supported_page_changed';

    /** Bounded read: a Business is not expected to have more live articles than this. */
    private const ARTICLE_CAP = 500;

    public function __construct(private readonly ArticleSiteInventory $inventory)
    {
    }

    /**
     * @return array<int, array{article_uid: string, title: string, reasons: array<int, string>, messages: array<int, string>}>
     */
    public function forBusiness(Business $business, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $articles = WebsiteArticle::query()
            ->where('business_id', $business->id)
            ->published()
            ->orderBy('id')
            ->limit(self::ARTICLE_CAP)
            ->get();

        if ($articles->isEmpty()) {
            return [];
        }

        $catalogUids = $articles->flatMap(fn (WebsiteArticle $a) => (array) ($a->referenced_catalog_uids ?? []))->unique()->values()->all();

        $items = $catalogUids === []
            ? collect()
            : CatalogItem::query()->where('business_id', $business->id)->whereIn('uid', $catalogUids)->get()->keyBy('uid');

        $pages = collect($this->inventory->pages($business))->keyBy('uid');

        $out = [];

        foreach ($articles as $article) {
            $reasons = [];
            $messages = [];

            $touched = $this->lastTouched($article);

            if ($touched->lessThan($now->copy()->subDays(self::OLD_AFTER_DAYS))) {
                $reasons[] = self::REASON_OLD;
                $messages[] = 'This article has not been updated or reviewed in over a year. Review it and update anything that has changed.';
            }

            $changed = $this->changedCatalogNames($article, $items, $touched);

            if ($changed !== []) {
                $reasons[] = self::REASON_CATALOG_CHANGED;
                $messages[] = 'A package or product this article mentions has changed: ' . implode(', ', $changed) . '. Review the article so it still matches your current offer.';
            }

            $pageMessage = $this->supportedPageProblem($article, $pages);

            if ($pageMessage !== null) {
                $reasons[] = self::REASON_SUPPORTED_PAGE_CHANGED;
                $messages[] = $pageMessage;
            }

            if ($reasons !== []) {
                $out[] = [
                    'article_uid' => (string) $article->uid,
                    'title' => (string) $article->title,
                    'reasons' => $reasons,
                    'messages' => $messages,
                ];
            }
        }

        return $out;
    }

    /** The later of the article's modified date and its last review. */
    private function lastTouched(WebsiteArticle $article): CarbonInterface
    {
        $modified = $article->dateModified();

        return $article->last_reviewed_at !== null && $article->last_reviewed_at->greaterThan($modified)
            ? $article->last_reviewed_at
            : $modified;
    }

    /**
     * @param  \Illuminate\Support\Collection<string, CatalogItem>  $items
     * @return array<int, string> names, bounded
     */
    private function changedCatalogNames(WebsiteArticle $article, $items, CarbonInterface $reference): array
    {
        $names = [];

        foreach ((array) ($article->referenced_catalog_uids ?? []) as $uid) {
            $item = $items->get($uid);

            if ($item === null) {
                // The item no longer exists at all: the article names something that is not on offer.
                $names[] = 'a package that is no longer in your catalog';

                continue;
            }

            $archived = $item->lifecycle_state === CatalogItemLifecycleState::Archived;
            $editedSince = $item->updated_at !== null && $item->updated_at->greaterThan($reference);

            if ($archived && $editedSince) {
                $names[] = (string) $item->name . ' (no longer offered)';
            } elseif ($editedSince && ! $archived) {
                $names[] = (string) $item->name;
            }
        }

        return array_slice(array_values(array_unique($names)), 0, 5);
    }

    /** @param  \Illuminate\Support\Collection<string, array<string, mixed>>  $pages */
    private function supportedPageProblem(WebsiteArticle $article, $pages): ?string
    {
        $uid = (string) $article->supports_page_uid;

        // No supported page chosen, or no published website to judge against.
        if ($uid === '' || $pages->isEmpty()) {
            return null;
        }

        $page = $pages->get($uid);

        if ($page === null) {
            return 'The page this article supports is no longer in your published website. Review which page it should support.';
        }

        if ($page['noindex']) {
            return 'The page this article supports is now hidden from search engines. Review whether the article still supports a page people can find.';
        }

        return null;
    }
}
