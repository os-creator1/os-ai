<?php

namespace App\Library\ExternalSite;

use App\Enums\Seo\SeoAuditSeverity;
use App\Library\Seo\SeoAuditEvaluator;
use App\Library\Seo\SeoAuditFindingDraft;
use App\Library\Seo\SeoAuditRuleRegistry;
use App\Models\ExternalSiteCrawl;
use App\Models\ExternalSitePage;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * External Website Audit Mode V1 — runs ONE queued crawl: crawl, store the page
 * values, hand them (as normalised facts) to the SAME SeoAuditEvaluator the hosted
 * audit uses, store its findings, and summarise.
 *
 * It owns persistence only. Every decision about a page lives in the one
 * evaluator and every word in the one registry; every decision about a URL lives
 * in the UrlGuard. It writes exactly the three external_site_* tables.
 *
 * Idempotent per crawl row: only a `queued` crawl is claimed (atomically, to
 * `running`), so a redelivered job or a second worker does nothing. A crash leaves
 * a `running` row that the manager's staleness check later fails. Failure records
 * a short reason CODE only — never an exception message, response body or header.
 */
final class ExternalSiteAuditRunner
{
    public function __construct(
        private readonly ExternalSiteCrawler $crawler,
        private readonly SeoAuditEvaluator $evaluator,
        private readonly ExternalSiteConfig $config,
    ) {
    }

    public function run(ExternalSiteCrawl $crawl): ExternalSiteCrawl
    {
        $claimed = ExternalSiteCrawl::query()
            ->whereKey($crawl->id)
            ->where('status', ExternalSiteCrawl::QUEUED)
            ->update(['status' => ExternalSiteCrawl::RUNNING, 'started_at' => now()]);

        if ($claimed !== 1) {
            return $crawl->refresh();
        }

        try {
            $outcome = $this->crawler->crawl((string) $crawl->start_url);
        } catch (Throwable) {
            return $this->fail($crawl, 'crawler_error');
        }

        if ($outcome->failureCode !== null && $outcome->pages === []) {
            return $this->fail($crawl, $outcome->failureCode, $outcome->indexability, $outcome->discovered);
        }

        try {
            DB::transaction(fn () => $this->store($crawl, $outcome));
        } catch (Throwable) {
            return $this->fail($crawl, 'storage_error');
        }

        $this->prune((int) $crawl->business_id);

        return $crawl->refresh();
    }

    private function store(ExternalSiteCrawl $crawl, CrawlOutcome $outcome): void
    {
        $now = now();
        $rows = [];
        $seen = [];

        foreach ($outcome->pages as $page) {
            $hash = sha1($page->url);

            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $facts = $page->facts;

            $rows[] = [
                'crawl_id' => $crawl->id,
                'business_id' => $crawl->business_id,
                'url_hash' => $hash,
                'url' => mb_substr($page->url, 0, 2048),
                'http_status' => $page->status > 0 ? $page->status : null,
                'error_code' => $page->error === null || str_starts_with((string) $page->error, 'http_') ? null : mb_substr((string) $page->error, 0, 48),
                'content_type' => null,
                'title' => $facts?->title,
                'meta_description' => $facts?->metaDescription,
                'canonical_url' => $facts?->canonicalUrl === null ? null : mb_substr($facts->canonicalUrl, 0, 2048),
                'noindex' => ($facts?->noindex ?? false) || $page->xRobotsNoindex,
                'h1_count' => $facts?->h1Count,
                'internal_link_count' => $facts === null ? 0 : min(65535, count($facts->links)),
                'broken_link_count' => min(65535, $page->brokenLinks),
                'image_count' => min(65535, $facts?->imageCount ?? 0),
                'images_missing_alt' => min(65535, $facts?->imagesMissingAlt ?? 0),
                'has_open_graph' => $facts?->hasOpenGraph ?? false,
                'has_json_ld' => $facts?->hasJsonLd ?? false,
                'word_count' => $facts?->wordCount ?? 0,
                'fetched_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 100) as $chunk) {
            ExternalSitePage::query()->insert($chunk);
        }

        $pages = ExternalSitePage::query()->where('crawl_id', $crawl->id)->orderBy('id')->get();
        $drafts = $this->evaluator->evaluateFacts(ExternalSiteAuditSource::factsFor($pages));

        $counts = [SeoAuditSeverity::Critical->value => 0, SeoAuditSeverity::Warning->value => 0, SeoAuditSeverity::Info->value => 0];
        $findingRows = [];

        foreach ($drafts as $draft) {
            $counts[$draft->severity()->value]++;
            $findingRows[] = $this->findingRow($crawl, $draft, $now);
        }

        foreach (array_chunk($findingRows, 200) as $chunk) {
            DB::table('external_site_findings')->insert($chunk);
        }

        ExternalSiteCrawl::query()->whereKey($crawl->id)->update([
            'status' => ExternalSiteCrawl::COMPLETED,
            'indexability' => $outcome->indexability,
            'rule_set_version' => SeoAuditRuleRegistry::VERSION,
            'pages_discovered' => min(65535, $outcome->discovered),
            'pages_fetched' => count($rows),
            'broken_links' => min(65535, (int) $pages->sum('broken_link_count')),
            'critical_count' => $counts[SeoAuditSeverity::Critical->value],
            'warning_count' => $counts[SeoAuditSeverity::Warning->value],
            'info_count' => $counts[SeoAuditSeverity::Info->value],
            'failure_code' => $outcome->truncated ? 'limit_reached' : null,
            'finished_at' => $now,
        ]);
    }

    /** @return array<string, mixed> */
    private function findingRow(ExternalSiteCrawl $crawl, SeoAuditFindingDraft $draft, \DateTimeInterface $now): array
    {
        return [
            'crawl_id' => $crawl->id,
            'page_id' => $draft->pageUid === null ? null : (int) $draft->pageUid,
            'rule_key' => $draft->ruleKey,
            'severity' => $draft->severity()->value,
            'facts' => json_encode($draft->facts),
            'created_at' => $now,
        ];
    }

    private function fail(ExternalSiteCrawl $crawl, string $code, ?string $indexability = null, int $discovered = 0): ExternalSiteCrawl
    {
        ExternalSiteCrawl::query()->whereKey($crawl->id)->update([
            'status' => ExternalSiteCrawl::FAILED,
            'failure_code' => mb_substr($code, 0, 48),
            'indexability' => $indexability,
            'pages_discovered' => min(65535, $discovered),
            'finished_at' => now(),
        ]);

        return $crawl->refresh();
    }

    /** Keeps the latest N crawls per Business; older ones go with their pages and findings (cascade). */
    private function prune(int $businessId): void
    {
        $doomed = ExternalSiteCrawl::query()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->skip($this->config->retainedCrawls())
            ->take(100)
            ->pluck('id')
            ->all();

        if ($doomed !== []) {
            ExternalSiteCrawl::query()->whereIn('id', $doomed)->delete();
        }
    }
}
