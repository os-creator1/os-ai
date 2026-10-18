<?php

namespace App\Library\ExternalSite;

use App\Library\ExternalSite\Audit\ExternalAuditCatalog;
use App\Library\ExternalSite\Audit\ExternalAuditContextFactory;
use App\Library\ExternalSite\Audit\ExternalAuditEvaluator;
use App\Library\ExternalSite\Audit\ExternalAuditResult;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Models\ExternalSitePlanItem;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * External Website Audit — runs ONE queued crawl: crawl, store what was observed,
 * hand the observations to the deterministic ExternalAuditEvaluator, store its
 * findings, summarise, and release the Business's crawl lock.
 *
 * It owns persistence and the lifecycle only. Every decision about a page lives in
 * the evaluator, every sentence in the ExternalAuditCatalog, every decision about a
 * URL in the UrlGuard.
 *
 * Lifecycle and honesty:
 *   - only a `queued` crawl is claimed (atomically, to `running`), so a redelivered
 *     job or a second worker does nothing;
 *   - every URL refreshes the heartbeat and the live counters the Overview polls;
 *   - between URLs the crawl looks at its own row: if it was cancelled (or failed as
 *     stale) the work stops and the partial result is discarded;
 *   - the result is `completed` only when everything eligible within the budget was
 *     audited; a budget/time limit or a refusal by a firewall makes it `partial`
 *     with the reason; nothing useful makes it `failed` with a reason that says
 *     whether the website or the crawler was at fault;
 *   - a new run NEVER overwrites an earlier one: each crawl keeps its own pages,
 *     links and findings, and only the bulky detail of runs beyond the retention
 *     window is pruned (their summary rows stay).
 *
 * Failure records a short reason CODE only — never an exception message, response
 * body or header.
 */
final class ExternalSiteAuditRunner
{
    public function __construct(
        private readonly ExternalSiteCrawler $crawler,
        private readonly ExternalAuditEvaluator $evaluator,
        private readonly ExternalAuditContextFactory $contexts,
        private readonly ExternalSiteConfig $config,
    ) {
    }

    public function run(ExternalSiteCrawl $crawl): ExternalSiteCrawl
    {
        $claimed = ExternalSiteCrawl::query()
            ->whereKey($crawl->id)
            ->where('status', ExternalSiteCrawl::QUEUED)
            ->update(['status' => ExternalSiteCrawl::RUNNING, 'stage' => 'connecting', 'started_at' => now(), 'heartbeat_at' => now()]);

        if ($claimed !== 1) {
            return $crawl->refresh();
        }

        $business = Business::query()->find($crawl->business_id);

        if ($business === null) {
            return $this->fail($crawl, 'business_missing');
        }

        try {
            $context = $this->contexts->forBusiness($business);

            $outcome = $this->crawler->crawl(
                (string) $crawl->start_url,
                fn (array $p) => $this->heartbeat($crawl, $p),
                fn (): bool => ExternalSiteCrawl::query()->whereKey($crawl->id)->where('status', ExternalSiteCrawl::RUNNING)->doesntExist(),
                $context->terms(),
            );
        } catch (Throwable) {
            return $this->fail($crawl, 'crawler_error');
        }

        // Cancelled, or failed as stale, while we were working: the row is no longer ours to complete.
        if (ExternalSiteCrawl::query()->whereKey($crawl->id)->where('status', ExternalSiteCrawl::RUNNING)->doesntExist()) {
            return $crawl->refresh();
        }

        try {
            $audited = $outcome->audited();
            $result = $audited === [] ? null : $this->evaluator->evaluate($outcome, $context);

            DB::transaction(fn () => $this->store($crawl, $outcome, $result));
        } catch (Throwable) {
            return $this->fail($crawl, 'storage_error');
        }

        if ($crawl->refresh()->hasResults()) {
            $this->verifyPlanItems($business, $crawl, $result);
        }

        $this->prune((int) $crawl->business_id);

        return $crawl->refresh();
    }

    /** Progress + liveness, written only while the crawl is still ours (a cancelled row is not touched). */
    private function heartbeat(ExternalSiteCrawl $crawl, array $progress): void
    {
        ExternalSiteCrawl::query()->whereKey($crawl->id)->where('status', ExternalSiteCrawl::RUNNING)->update([
            'stage' => $progress['stage'],
            'progress_discovered' => min(65535, (int) $progress['discovered']),
            'progress_checked' => min(65535, (int) $progress['checked']),
            'heartbeat_at' => now(),
        ]);
    }

    private function store(ExternalSiteCrawl $crawl, CrawlOutcome $outcome, ?ExternalAuditResult $result): void
    {
        $now = now();
        $analysis = $result?->analysis ?? [];
        $brokenBySource = [];

        foreach ($result?->findings ?? [] as $finding) {
            if ($finding->ruleKey === 'broken_internal_link' && $finding->pageUrl !== null) {
                $brokenBySource[sha1($finding->pageUrl)] = ($brokenBySource[sha1($finding->pageUrl)] ?? 0) + 1;
            }
        }

        $rows = [];
        $seen = [];

        foreach ($outcome->pages as $page) {
            $hash = sha1($page->url);

            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $facts = $page->facts;
            $info = $analysis[(string) UrlResolver::pageKey($page->url)] ?? null;
            $httpError = $page->error === null || str_starts_with((string) $page->error, 'http_');

            $rows[] = [
                'crawl_id' => $crawl->id,
                'business_id' => $crawl->business_id,
                'url_hash' => $hash,
                'url' => mb_substr($page->url, 0, 2048),
                'kind' => $page->kind,
                'final_url' => $page->finalUrl === null ? null : mb_substr($page->finalUrl, 0, 2048),
                'redirect_chain' => $page->chain === [] ? null : json_encode(array_slice($page->chain, 0, 6)),
                'depth' => $page->depth === null ? null : min(255, $page->depth),
                'source' => $page->source,
                'in_sitemap' => $page->inSitemap,
                'meta_robots' => $facts?->metaRobots,
                'x_robots' => $page->xRobots === null ? null : mb_substr($page->xRobots, 0, 255),
                'indexable' => $info['indexable'] ?? null,
                'importance' => $info['importance'] ?? Audit\ExternalPageImportance::classify($page->url),
                'inlink_count' => min(65535, (int) ($info['inlinks'] ?? 0)),
                'evidence' => json_encode($this->pageEvidence($page, $info)),
                'http_status' => $page->status > 0 ? $page->status : null,
                'error_code' => $httpError ? null : mb_substr((string) $page->error, 0, 48),
                'content_type' => $page->contentType === null ? null : mb_substr($page->contentType, 0, 100),
                'title' => $facts?->title,
                'meta_description' => $facts?->metaDescription,
                'canonical_url' => $facts?->canonicalUrl === null ? null : mb_substr($facts->canonicalUrl, 0, 2048),
                'noindex' => ($facts?->noindex ?? false) || $page->xRobotsNoindex,
                'h1_count' => $facts?->h1Count,
                'internal_link_count' => $facts === null ? 0 : min(65535, count($facts->links)),
                'broken_link_count' => min(65535, $brokenBySource[$hash] ?? 0),
                'image_count' => min(65535, $facts?->imageCount ?? 0),
                'images_missing_alt' => min(65535, $facts?->imagesMissingAlt ?? 0),
                'has_open_graph' => $facts?->hasOpenGraph ?? false,
                'has_json_ld' => $facts?->hasJsonLd ?? false,
                'word_count' => $facts?->wordCount ?? 0,
                'fetched_at' => $page->kind === CrawledPage::SKIPPED || $page->kind === CrawledPage::EXCLUDED || $page->kind === CrawledPage::ROBOTS_DISALLOWED ? null : $now,
            ];
        }

        foreach (array_chunk($rows, 50) as $chunk) {
            DB::table('external_site_pages')->insert($chunk);
        }

        $idByHash = DB::table('external_site_pages')->where('crawl_id', $crawl->id)->pluck('id', 'url_hash')->all();
        $idByKey = [];

        foreach ($outcome->pages as $page) {
            $key = UrlResolver::pageKey($page->url);

            if ($key !== null && isset($idByHash[sha1($page->url)])) {
                $idByKey[$key] = $idByHash[sha1($page->url)];
            }
        }

        // ---- links (bounded per page and per crawl) ----
        $linkRows = [];
        $perPage = [];

        foreach ($result?->edges ?? [] as $edge) {
            $sourceId = $idByHash[sha1($edge['source'])] ?? null;

            if ($sourceId === null || count($linkRows) >= $this->config->maxLinksPerCrawl() || ($perPage[$sourceId] ?? 0) >= $this->config->maxLinksPerPage()) {
                continue;
            }

            $perPage[$sourceId] = ($perPage[$sourceId] ?? 0) + 1;
            $linkRows[] = [
                'crawl_id' => $crawl->id,
                'source_page_id' => $sourceId,
                'target_url' => mb_substr($edge['target'], 0, 2048),
                'target_hash' => sha1((string) UrlResolver::pageKey($edge['target'])),
                'anchor' => $edge['anchor'] === '' ? null : mb_substr($edge['anchor'], 0, 191),
                'nofollow' => $edge['nofollow'],
            ];
        }

        foreach (array_chunk($linkRows, 200) as $chunk) {
            DB::table('external_site_links')->insert($chunk);
        }

        // ---- findings ----
        $findingRows = [];
        $keys = [];

        foreach ($result?->findings ?? [] as $draft) {
            $findingKey = $draft->findingKey();

            if (isset($keys[$findingKey])) {
                continue;
            }

            $keys[$findingKey] = true;
            $pageId = $draft->pageUrl === null ? null : ($idByHash[sha1($draft->pageUrl)] ?? ($idByKey[(string) UrlResolver::pageKey($draft->pageUrl)] ?? null));

            $findingRows[] = [
                'crawl_id' => $crawl->id,
                'page_id' => $pageId,
                'finding_key' => $findingKey,
                'rule_key' => $draft->ruleKey,
                'group_key' => $draft->groupKey === '' ? null : substr($draft->groupKey, 0, 40),
                'category' => ExternalAuditCatalog::category($draft->ruleKey),
                'kind' => ExternalAuditCatalog::kind($draft->ruleKey),
                'severity' => match ($draft->priority) {'critical' => 'critical', 'high', 'medium' => 'warning', default => 'info'},
                'priority' => $draft->priority,
                'confidence' => $draft->confidence,
                'facts' => null,
                'evidence' => json_encode($draft->evidence + ['importance' => $draft->importance]),
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($findingRows, 100) as $chunk) {
            DB::table('external_site_findings')->insert($chunk);
        }

        // ---- the crawl summary ----
        $coverage = $result?->coverage ?? $this->fallbackCoverage($outcome);
        $summary = $result?->summary ?? ['priority' => array_fill_keys(ExternalAuditCatalog::PRIORITIES, 0), 'kinds' => ['problem' => 0, 'improvement' => 0, 'opportunity' => 0, 'info' => 0], 'issue_groups' => 0, 'rows' => 0, 'pages_affected' => 0, 'broken_destinations' => 0, 'broken_link_instances' => 0, 'pages_audited' => 0];
        $priority = $summary['priority'];
        $auditedCount = (int) ($coverage['audited'] ?? 0);
        $fetched = 0;

        foreach ($outcome->pages as $page) {
            $fetched += in_array($page->kind, [CrawledPage::HTML, CrawledPage::REDIRECT, CrawledPage::ERROR, CrawledPage::BLOCKED, CrawledPage::UNREACHABLE, CrawledPage::NON_HTML], true) ? 1 : 0;
        }

        $status = $auditedCount === 0 ? ExternalSiteCrawl::FAILED : (($outcome->truncated || ($coverage['blocked'] ?? 0) > 0 || ($coverage['unreachable'] ?? 0) > 0) ? ExternalSiteCrawl::PARTIAL : ExternalSiteCrawl::COMPLETED);
        $connection = $outcome->connection;

        $finishReason = match (true) {
            $auditedCount === 0 => null,
            $outcome->finishReason === CrawlOutcome::FINISH_PAGE_LIMIT => 'page_limit',
            $outcome->finishReason === CrawlOutcome::FINISH_TIME_LIMIT => 'time_limit',
            ($coverage['blocked'] ?? 0) > 0 => 'some_pages_blocked',
            ($coverage['unreachable'] ?? 0) > 0 => 'some_pages_unreachable',
            $outcome->truncated => 'page_limit',
            default => 'completed',
        };

        ExternalSiteCrawl::query()->whereKey($crawl->id)->where('status', ExternalSiteCrawl::RUNNING)->update([
            'status' => $status,
            'stage' => 'done',
            'indexability' => $outcome->indexability,
            'connection_state' => $connection?->state,
            'connection_http_status' => $connection?->httpStatus,
            'final_url' => $connection?->finalUrl === null ? null : mb_substr($connection->finalUrl, 0, 2048),
            'rule_set_version' => ExternalAuditCatalog::VERSION,
            'pages_discovered' => min(65535, (int) ($coverage['discovered'] ?? 0)),
            'pages_fetched' => min(65535, $fetched),
            'pages_audited' => min(65535, $auditedCount),
            'progress_discovered' => min(65535, (int) ($coverage['discovered'] ?? 0)),
            'progress_checked' => min(65535, $fetched),
            'broken_links' => min(65535, (int) ($summary['broken_destinations'] ?? 0)),
            'critical_count' => (int) ($priority['critical'] ?? 0),
            'warning_count' => (int) ($priority['high'] ?? 0) + (int) ($priority['medium'] ?? 0),
            'info_count' => (int) ($priority['low'] ?? 0) + (int) ($priority['info'] ?? 0),
            'coverage' => json_encode($coverage + ['robots' => $this->robotsSummary($outcome), 'sitemaps' => $this->sitemapSummary($outcome)]),
            'summary' => json_encode($summary),
            'failure_code' => $status === ExternalSiteCrawl::FAILED ? mb_substr($this->failureCodeFor($outcome), 0, 48) : null,
            'finish_reason' => $finishReason,
            'finished_at' => $now,
            'heartbeat_at' => $now,
            'active_lock' => null,
        ]);
    }

    /**
     * What was observed about one URL, bounded (about 6 KB at most): the evidence the Pages screen shows.
     *
     * @param  array<string, mixed>|null  $info
     * @return array<string, mixed>
     */
    private function pageEvidence(CrawledPage $page, ?array $info): array
    {
        $base = ['reason' => $page->reason, 'chain' => array_slice($page->chain, 0, 6)];
        $f = $page->facts;

        if ($f === null) {
            return $base;
        }

        return $base + [
            'title_tags' => $f->titleCount,
            'canonical_all' => array_slice($f->canonicalAll, 0, 4),
            'canonical_status' => $info['canonical_status'] ?? null,
            'h1_texts' => $f->h1Texts,
            'empty_headings' => $f->emptyHeadings,
            'headings' => array_slice($f->headings, 0, 40),
            'images' => array_slice(array_map(fn (array $i): array => ['src' => mb_substr($i['src'], 0, 300), 'alt' => $i['alt'] === null ? null : mb_substr($i['alt'], 0, 100), 'decorative' => $i['decorative']], $f->images), 0, 30),
            'image_total' => $f->imageCount,
            'structured_data' => array_slice($f->structuredData, 0, 8),
            'phones' => $f->phones,
            'has_tel_link' => $f->hasTelLink,
            'has_mailto_link' => $f->hasMailtoLink,
            'form_count' => $f->formCount,
            'has_address_element' => $f->hasAddressElement,
            'has_map_embed' => $f->hasMapEmbed,
            'has_viewport' => $f->hasViewport,
            'lang' => $f->lang,
            'og_title' => $f->hasOgTitle,
            'og_image' => $f->hasOgImage,
            'scripts' => $f->scriptCount,
            'stylesheets' => $f->stylesheetCount,
            'insecure_resources' => array_slice($f->insecureResources, 0, 8),
            'nofollow_all' => $f->nofollowAll,
            'broken_links_out' => null,
        ];
    }

    /** @return array<string, mixed> */
    private function fallbackCoverage(CrawlOutcome $outcome): array
    {
        $c = ['audited' => 0, 'redirected' => 0, 'errors' => 0, 'blocked' => 0, 'unreachable' => 0, 'robots_disallowed' => 0, 'excluded' => 0, 'skipped' => 0, 'omitted' => $outcome->omitted];

        foreach ($outcome->pages as $page) {
            $bucket = match ($page->kind) {
                CrawledPage::HTML => 'audited',
                CrawledPage::REDIRECT => 'redirected',
                CrawledPage::ERROR => 'errors',
                CrawledPage::BLOCKED => 'blocked',
                CrawledPage::UNREACHABLE => 'unreachable',
                CrawledPage::ROBOTS_DISALLOWED => 'robots_disallowed',
                CrawledPage::SKIPPED => 'skipped',
                default => 'excluded',
            };
            $c[$bucket]++;
        }

        $c['discovered'] = count($outcome->pages) + $outcome->omitted;
        $c['eligible_html'] = $c['audited'] + $c['errors'] + $c['blocked'] + $c['unreachable'] + $c['skipped'];
        $c['finish_reason'] = $outcome->finishReason;

        return $c;
    }

    /** @return array<string, mixed> */
    private function robotsSummary(CrawlOutcome $outcome): array
    {
        $r = $outcome->robots;

        return $r === null ? [] : ['found' => $r->found, 'status' => $r->status, 'readable' => $r->readable, 'blocks_whole_site' => $r->blocksWholeSite, 'sitemaps' => array_slice($r->sitemaps, 0, 5)];
    }

    /** @return list<array<string, mixed>> */
    private function sitemapSummary(CrawlOutcome $outcome): array
    {
        return array_map(fn (SitemapReport $s): array => ['url' => mb_substr($s->url, 0, 300), 'status' => $s->status, 'kind' => $s->kind, 'urls' => $s->urlCount, 'declared' => $s->declaredInRobots], array_slice($outcome->sitemaps, 0, 6));
    }

    /** Why nothing could be audited, in a code that says whether the website or the crawler's access was the cause. */
    private function failureCodeFor(CrawlOutcome $outcome): string
    {
        if ($outcome->failureCode !== null && $outcome->failureCode !== 'nothing_fetched') {
            return $outcome->failureCode;
        }

        $connection = $outcome->connection;

        return match ($connection?->state) {
            ConnectionResult::HTTP_ERROR => 'http_error',
            ConnectionResult::CRAWLER_BLOCKED => 'crawler_blocked',
            ConnectionResult::ROBOTS_BLOCKED => 'blocked_by_robots',
            ConnectionResult::TLS_ERROR => 'tls_failed',
            ConnectionResult::NETWORK_ERROR => (string) ($connection->reason ?? 'unreachable'),
            ConnectionResult::REDIRECTS_ELSEWHERE => 'redirects_elsewhere',
            default => 'nothing_audited',
        };
    }

    /**
     * A tracked improvement is VERIFIED fixed only by evidence: the latest complete crawl no longer contains the
     * rule. It is re-opened if the rule comes back. Partial crawls do not verify anything (a page may simply not
     * have been re-checked).
     */
    private function verifyPlanItems(Business $business, ExternalSiteCrawl $crawl, ?ExternalAuditResult $result): void
    {
        if ($crawl->status !== ExternalSiteCrawl::COMPLETED || $result === null) {
            return;
        }

        $present = [];

        foreach ($result->findings as $finding) {
            $present[$finding->ruleKey] = true;
        }

        foreach (ExternalSitePlanItem::query()->where('business_id', $business->id)->get() as $item) {
            $stillThere = isset($present[$item->rule_key]);

            if (! $stillThere && $item->resolved_in_crawl_id === null) {
                $item->forceFill(['resolved_in_crawl_id' => $crawl->id])->save();
            } elseif ($stillThere && $item->resolved_in_crawl_id !== null) {
                $item->forceFill(['resolved_in_crawl_id' => null])->save();
            }
        }
    }

    private function fail(ExternalSiteCrawl $crawl, string $code): ExternalSiteCrawl
    {
        ExternalSiteCrawl::query()->whereKey($crawl->id)->whereIn('status', [ExternalSiteCrawl::QUEUED, ExternalSiteCrawl::RUNNING])->update([
            'status' => ExternalSiteCrawl::FAILED,
            'stage' => null,
            'failure_code' => mb_substr($code, 0, 48),
            'active_lock' => null,
            'finished_at' => now(),
        ]);

        return $crawl->refresh();
    }

    /**
     * Keeps the full detail (pages, links, findings) of the latest N crawls per Business. Older crawls lose the bulky
     * detail but KEEP their summary row, so the audit history is never silently erased.
     */
    private function prune(int $businessId): void
    {
        $doomed = ExternalSiteCrawl::query()
            ->where('business_id', $businessId)
            ->orderByDesc('id')
            ->skip($this->config->retainedCrawls())
            ->take(200)
            ->whereNull('details_pruned_at')
            ->pluck('id')
            ->all();

        foreach (array_chunk($doomed, 20) as $ids) {
            DB::table('external_site_pages')->whereIn('crawl_id', $ids)->delete();
            DB::table('external_site_findings')->whereIn('crawl_id', $ids)->delete();
            ExternalSiteCrawl::query()->whereIn('id', $ids)->update(['details_pruned_at' => now()]);
        }
    }
}
