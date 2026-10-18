<?php

namespace App\Library\Seo\Content;

use App\Enums\Seo\ArticleStatus;
use App\Models\Business;
use App\Models\WebsiteArticle;

/**
 * SEO Content Engine V1 — the lightweight content-cluster view: each money page (the "pillar") with the articles
 * that support it and the opportunities that would. A cluster is nothing more than `article.supports_page_uid`;
 * there is no cluster table. Pages that have nothing supporting them yet are listed separately so the owner can see
 * where the gaps are. Read-only, deterministic, no AI.
 */
final class ArticleContentPlan
{
    public function __construct(
        private readonly ArticleSiteInventory $inventory,
        private readonly ArticleOpportunityEngine $opportunities,
    ) {
    }

    /**
     * @return array{clusters: array<int, array{page: array{uid: string, title: string, kind: string, slug: ?string}, articles: array<int, array{uid: string, title: string, status: string}>, suggested: array<int, string>}>, unsupported_pages: array<int, array{uid: string, title: string, kind: string, slug: ?string}>}
     */
    public function forBusiness(Business $business): array
    {
        $pages = array_values(array_filter($this->inventory->pages($business), fn (array $p) => $p['money'] && $p['linkable']));
        $opportunities = $this->opportunities->forBusiness($business);

        $articles = WebsiteArticle::query()->where('business_id', $business->id)
            ->where('status', '!=', ArticleStatus::Archived->value)
            ->whereNotNull('supports_page_uid')
            ->orderByDesc('updated_at')->get(['uid', 'title', 'status', 'supports_page_uid']);

        $clusters = [];
        $unsupported = [];

        foreach ($pages as $page) {
            $supporting = $articles->where('supports_page_uid', $page['uid'])->map(fn (WebsiteArticle $a) => [
                'uid' => (string) $a->uid, 'title' => (string) $a->title, 'status' => $a->status->value,
            ])->values()->all();

            $suggested = array_values(array_map(
                fn (array $o) => $o['key'],
                array_filter($opportunities, fn (array $o) => $o['supports_page_uid'] === $page['uid'] && $o['status'] === 'not_covered'),
            ));

            $descriptor = ['uid' => $page['uid'], 'title' => $page['title'], 'kind' => $page['kind'], 'slug' => $page['slug']];

            if ($supporting === []) {
                $unsupported[] = $descriptor;
            }

            if ($supporting !== [] || $suggested !== []) {
                $clusters[] = ['page' => $descriptor, 'articles' => $supporting, 'suggested' => $suggested];
            }
        }

        return ['clusters' => $clusters, 'unsupported_pages' => $unsupported];
    }
}
