<?php

namespace App\Library\ExternalSite;

use App\Enums\Seo\SeoAuditSeverity;
use App\Library\Seo\SeoAuditRuleRegistry;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Models\ExternalSiteFinding;
use App\Models\ExternalSitePage;
use Illuminate\Support\Collection;

/**
 * External Website Audit Mode V1 — the read model behind Website > Overview /
 * Audit / Pages for an existing-website Business. Read-only, Business-scoped, a
 * constant number of queries whatever the page or finding counts (crawl, pages,
 * findings). It reads stored rows only: it never fetches a URL.
 */
final class ExternalWebsiteReader
{
    public function latest(Business $business): ?ExternalSiteCrawl
    {
        return ExternalSiteCrawl::query()->where('business_id', $business->id)->latest('id')->first();
    }

    /** The newest crawl that finished successfully: the one the Audit and Pages screens describe. */
    public function latestCompleted(Business $business): ?ExternalSiteCrawl
    {
        return ExternalSiteCrawl::query()->where('business_id', $business->id)->where('status', ExternalSiteCrawl::COMPLETED)->latest('id')->first();
    }

    /** @return Collection<int, ExternalSiteCrawl> newest first */
    public function history(Business $business, int $limit = 5): Collection
    {
        return ExternalSiteCrawl::query()->where('business_id', $business->id)->latest('id')->limit($limit)->get();
    }

    /**
     * Findings grouped by rule: severity first, then the number of pages affected.
     *
     * @return list<array{rule_key: string, severity: SeoAuditSeverity, title: string, description: string, site_level: bool, count: int, pages: list<array{id: int, path: string, url: string, description: string}>}>
     */
    public function groups(ExternalSiteCrawl $crawl): array
    {
        $findings = ExternalSiteFinding::query()->where('crawl_id', $crawl->id)->orderBy('id')->get();
        $pages = ExternalSitePage::query()->where('crawl_id', $crawl->id)->get()->keyBy('id');
        $groups = [];

        foreach ($findings as $finding) {
            $key = (string) $finding->rule_key;

            if (! SeoAuditRuleRegistry::has($key)) {
                continue;
            }

            $groups[$key] ??= [
                'rule_key' => $key,
                'severity' => SeoAuditRuleRegistry::severityFor($key),
                'title' => SeoAuditRuleRegistry::titleFor($key),
                'description' => $finding->description(),
                'site_level' => SeoAuditRuleRegistry::isSiteLevel($key),
                'count' => 0,
                'pages' => [],
            ];

            $page = $finding->page_id === null ? null : $pages->get($finding->page_id);

            if ($page !== null) {
                $groups[$key]['pages'][] = ['id' => (int) $page->id, 'path' => $page->displayPath(), 'url' => (string) $page->url, 'description' => $finding->description()];
            }

            $groups[$key]['count']++;
        }

        $weight = fn (SeoAuditSeverity $s): int => match ($s) {SeoAuditSeverity::Critical => 3, SeoAuditSeverity::Warning => 2, SeoAuditSeverity::Info => 1};
        $list = array_values($groups);
        usort($list, fn (array $a, array $b): int => [$weight($b['severity']), $b['count']] <=> [$weight($a['severity']), $a['count']]);

        return $list;
    }

    /**
     * The one thing to fix next: the most severe, most widespread issue.
     *
     * @return array<string, mixed>|null
     */
    public function topIssue(ExternalSiteCrawl $crawl): ?array
    {
        return $this->groups($crawl)[0] ?? null;
    }

    /**
     * Pages with their own issue counts.
     *
     * @return Collection<int, ExternalSitePage> each with `issue_count` and `worst` (SeoAuditSeverity|null) set
     */
    public function pages(ExternalSiteCrawl $crawl): Collection
    {
        $pages = ExternalSitePage::query()->where('crawl_id', $crawl->id)->orderBy('id')->get();
        $byPage = ExternalSiteFinding::query()->where('crawl_id', $crawl->id)->whereNotNull('page_id')->get()->groupBy('page_id');

        return $pages->each(function (ExternalSitePage $page) use ($byPage): void {
            $mine = $byPage->get($page->id, collect());
            $page->setAttribute('issue_count', $mine->count());
            $page->setAttribute('worst', $mine->isEmpty() ? null : $mine->map(fn ($f) => $f->severity())->sortByDesc(fn (SeoAuditSeverity $s) => match ($s) {SeoAuditSeverity::Critical => 3, SeoAuditSeverity::Warning => 2, SeoAuditSeverity::Info => 1})->first());
        });
    }

    /** @return array{page: ExternalSitePage, findings: Collection<int, ExternalSiteFinding>}|null */
    public function page(ExternalSiteCrawl $crawl, int $pageId): ?array
    {
        $page = ExternalSitePage::query()->where('crawl_id', $crawl->id)->whereKey($pageId)->first();

        if ($page === null) {
            return null;
        }

        return ['page' => $page, 'findings' => ExternalSiteFinding::query()->where('crawl_id', $crawl->id)->where('page_id', $page->id)->orderBy('id')->get()];
    }

    /**
     * How a page appears to search engines, in the owner's words.
     */
    public static function pageState(ExternalSitePage $page): string
    {
        $loaded = $page->error_code === null && (int) $page->http_status >= 200 && (int) $page->http_status < 400;

        return match (true) {
            ! $loaded => 'Could not load',
            (bool) $page->noindex => 'Hidden from search',
            default => 'Can be found',
        };
    }
}
