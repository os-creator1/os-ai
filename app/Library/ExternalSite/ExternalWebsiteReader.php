<?php

namespace App\Library\ExternalSite;

use App\Library\ExternalSite\Audit\ExternalAuditCatalog;
use App\Library\ExternalSite\Audit\ExternalPageImportance;
use App\Models\Business;
use App\Models\ExternalSiteCrawl;
use App\Models\ExternalSiteFinding;
use App\Models\ExternalSiteLink;
use App\Models\ExternalSitePage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * External Website Audit — the read model behind Website > Overview / Audit / Plan /
 * Pages / Settings for an existing-website Business. Read-only, Business-scoped, a
 * constant number of queries whatever the page or finding counts. It reads stored
 * rows only: it never fetches a URL.
 *
 * SITE SCOPING. Every "current results" read is restricted to crawls of the
 * Business's CURRENT website host (the address saved now, www and non-www alike). A
 * crawl of an earlier address is history: it stays in the history list, labelled
 * with its own address, and can never appear as the current site's results. That is
 * what stops a changed website address from showing the previous site's findings.
 *
 * "CURRENT" MEANS A FINISHED REPORT. The latest report is the newest crawl that
 * finished with results (completed or partial) in the current format. A queued or
 * running crawl never replaces it: the screens keep showing the last report, labelled
 * with its time, while the new check runs.
 */
final class ExternalWebsiteReader
{
    // ---------------------------------------------------------------- crawls

    public function bareHost(?string $url): ?string
    {
        $url = trim((string) $url);

        if ($url === '') {
            return null;
        }

        $host = strtolower((string) (parse_url(preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url) === 1 ? $url : 'https://'.$url, PHP_URL_HOST) ?: ''));

        if ($host === '') {
            return null;
        }

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    /** @return Builder<ExternalSiteCrawl> the Business's crawls of its CURRENT website address only */
    public function siteQuery(Business $business): Builder
    {
        $bare = $this->bareHost($business->website_url);

        $query = ExternalSiteCrawl::query()->where('business_id', $business->id);

        return $bare === null ? $query->whereRaw('1 = 0') : $query->whereIn('host', [$bare, 'www.'.$bare]);
    }

    /** The newest crawl of the current address, whatever its status. */
    public function latest(Business $business): ?ExternalSiteCrawl
    {
        return $this->siteQuery($business)->latest('id')->first();
    }

    /** The newest crawl of the current address that is queued or running. */
    public function active(Business $business): ?ExternalSiteCrawl
    {
        return $this->siteQuery($business)->whereIn('status', [ExternalSiteCrawl::QUEUED, ExternalSiteCrawl::RUNNING])->latest('id')->first();
    }

    /** The newest finished report (completed or partial) in the current format: what Overview, Audit, Plan and Pages describe. */
    public function latestReport(Business $business): ?ExternalSiteCrawl
    {
        return $this->siteQuery($business)
            ->whereIn('status', [ExternalSiteCrawl::COMPLETED, ExternalSiteCrawl::PARTIAL])
            ->where('rule_set_version', '>=', ExternalAuditCatalog::VERSION)
            ->whereNull('details_pruned_at')
            ->latest('id')
            ->first();
    }

    /** A finished crawl from the first version of the audit (counts only), shown as an earlier-format check. */
    public function latestEarlierFormat(Business $business): ?ExternalSiteCrawl
    {
        return $this->siteQuery($business)
            ->whereIn('status', [ExternalSiteCrawl::COMPLETED, ExternalSiteCrawl::PARTIAL])
            ->where(fn ($q) => $q->whereNull('rule_set_version')->orWhere('rule_set_version', '<', ExternalAuditCatalog::VERSION))
            ->latest('id')
            ->first();
    }

    /** Kept for callers of the first version. */
    public function latestCompleted(Business $business): ?ExternalSiteCrawl
    {
        return $this->latestReport($business);
    }

    public function previousReport(Business $business, ExternalSiteCrawl $current): ?ExternalSiteCrawl
    {
        return $this->siteQuery($business)
            ->whereIn('status', [ExternalSiteCrawl::COMPLETED, ExternalSiteCrawl::PARTIAL])
            ->where('rule_set_version', '>=', ExternalAuditCatalog::VERSION)
            ->whereNull('details_pruned_at')
            ->where('id', '<', $current->id)
            ->latest('id')
            ->first();
    }

    /**
     * Every crawl of this Business, newest first, INCLUDING those of earlier addresses (history is never hidden).
     *
     * @return Collection<int, ExternalSiteCrawl>
     */
    public function history(Business $business, int $limit = 10): Collection
    {
        return ExternalSiteCrawl::query()->where('business_id', $business->id)->latest('id')->limit($limit)->get();
    }

    /** True when the crawl belongs to the Business's current website address. */
    public function isCurrentSite(Business $business, ExternalSiteCrawl $crawl): bool
    {
        $bare = $this->bareHost($business->website_url);

        return $bare !== null && $this->bareHost((string) $crawl->host) === $bare;
    }

    /**
     * The live state the Overview polls. Small and cheap: one row.
     *
     * @return array<string, mixed>
     */
    public function status(Business $business): array
    {
        $crawl = $this->latest($business);

        if ($crawl === null) {
            return ['has_crawl' => false, 'active' => false, 'status' => 'none', 'token' => 'none'];
        }

        return [
            'has_crawl' => true,
            'active' => $crawl->isActive(),
            'status' => $crawl->status,
            'status_label' => ExternalAuditPresenter::statusLabel($crawl),
            'stage' => $crawl->stage,
            'stage_label' => ExternalAuditPresenter::stageLabel($crawl->stage),
            'discovered' => (int) $crawl->progress_discovered,
            'checked' => (int) $crawl->progress_checked,
            'budget' => (int) ($crawl->page_budget ?? 0),
            'started_at' => $crawl->started_at?->toIso8601String(),
            'finished_at' => $crawl->finished_at?->toIso8601String(),
            'token' => $crawl->id.':'.$crawl->status,
        ];
    }

    // ---------------------------------------------------------------- findings

    /**
     * Findings grouped by rule (one issue = one rule), most important first. Every row carries the exact page, what
     * was observed and its related URLs. Counts are computed from the rows, never copied from another group.
     *
     * @param  array{severity?: ?string, category?: ?string, kind?: ?string, q?: ?string, page?: ?int, status?: ?string}  $filters
     * @param  array{new: array<string, true>, unchanged: array<string, true>}|null  $diffKeys
     * @return list<array<string, mixed>>
     */
    public function issueGroups(ExternalSiteCrawl $crawl, array $filters = [], ?array $diffKeys = null): array
    {
        $findings = ExternalSiteFinding::query()->where('crawl_id', $crawl->id)->with('page:id,url,importance,title,kind')->orderBy('id')->get();
        $groups = [];

        foreach ($findings as $finding) {
            $rule = ExternalAuditCatalog::maybe((string) $finding->rule_key);

            if ($rule === null) {
                continue;
            }

            $key = (string) $finding->rule_key;
            $evidence = is_array($finding->evidence) ? $finding->evidence : [];
            $page = $finding->page;
            $status = $diffKeys === null ? null : (isset($diffKeys['new'][$finding->finding_key]) ? 'new' : (isset($diffKeys['unchanged'][$finding->finding_key]) ? 'unchanged' : null));

            $groups[$key] ??= ['rule_key' => $key, 'rows' => []];
            $groups[$key]['rows'][] = [
                'id' => (int) $finding->id,
                'page_id' => $page === null ? null : (int) $page->id,
                'page_url' => $page === null ? null : (string) $page->url,
                'path' => $page === null ? null : $page->displayPath(),
                'importance' => (string) ($evidence['importance'] ?? ($page->importance ?? 'standard')),
                'priority' => $finding->priority(),
                'confidence' => (string) ($finding->confidence ?? $rule['confidence']),
                'observed' => (string) ($evidence['observed'] ?? ''),
                'values' => (array) ($evidence['values'] ?? []),
                'related' => (array) ($evidence['related'] ?? []),
                'proposal' => $evidence['proposal'] ?? null,
                'group_key' => (string) ($finding->group_key ?? ''),
                'finding_key' => (string) $finding->finding_key,
                'status' => $status,
            ];
        }

        $out = [];

        foreach ($groups as $key => $group) {
            $built = $this->buildGroup($key, $group['rows']);

            if ($this->passes($built, $filters)) {
                $built = $this->applyRowFilters($built, $filters);
                $out[] = $built;
            }
        }

        usort($out, fn (array $a, array $b): int => [ExternalAuditCatalog::priorityWeight($b['priority']), $b['importance_weight'], $b['row_count'], $a['title']] <=> [ExternalAuditCatalog::priorityWeight($a['priority']), $a['importance_weight'], $a['row_count'], $b['title']]);

        return $out;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function buildGroup(string $key, array $rows): array
    {
        $rule = ExternalAuditCatalog::rule($key);
        $best = 'info';
        $weight = 0;
        $pages = [];

        foreach ($rows as $row) {
            if (ExternalAuditCatalog::priorityWeight($row['priority']) > ExternalAuditCatalog::priorityWeight($best)) {
                $best = $row['priority'];
            }

            $weight = max($weight, ExternalPageImportance::weight($row['importance']));

            if ($row['page_url'] !== null) {
                $pages[$row['page_url']] = true;
            }
        }

        $bookingCritical = false;

        foreach ($rows as $row) {
            $bookingCritical = $bookingCritical || $row['importance'] === ExternalPageImportance::KEY;
        }

        return [
            'rule_key' => $key,
            'title' => $rule['title'],
            'category' => $rule['category'],
            'category_label' => ExternalAuditCatalog::CATEGORIES[$rule['category']],
            'kind' => $rule['kind'],
            'kind_label' => ExternalAuditCatalog::KINDS[$rule['kind']],
            'priority' => $best,
            'priority_label' => ExternalAuditPresenter::priorityLabel($best),
            'confidence' => $rule['confidence'],
            'site_level' => (bool) $rule['site_level'],
            'what' => $rule['what'],
            'why' => $rule['why'],
            'proposal' => $rule['proposal'],
            'steps' => $rule['steps'],
            'verify' => $rule['verify'],
            'effort' => $rule['effort'],
            'rows' => $rows,
            'row_count' => count($rows),
            'page_count' => count($pages),
            'importance_weight' => $weight,
            'booking_critical' => $bookingCritical,
            'clusters' => $this->clusters($key, $rows),
        ];
    }

    /**
     * Rows that belong together (the pages sharing one title; the pages linking to one broken destination).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{label: string, rows: list<array<string, mixed>>}>
     */
    private function clusters(string $rule, array $rows): array
    {
        if (! in_array($rule, ['title_duplicate', 'description_duplicate', 'h1_duplicate', 'broken_internal_link', 'internal_link_to_redirect', 'link_to_canonicalized_page'], true)) {
            return [];
        }

        $clusters = [];

        foreach ($rows as $row) {
            $k = $row['group_key'] !== '' ? $row['group_key'] : 'x';
            $values = $row['values'];
            $label = (string) ($values['destination'] ?? $values['title'] ?? $values['description'] ?? $values['heading'] ?? $row['page_url'] ?? '');
            $clusters[$k] ??= ['label' => $label, 'rows' => []];
            $clusters[$k]['rows'][] = $row;
        }

        return array_values($clusters);
    }

    /**
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $filters
     */
    private function passes(array $group, array $filters): bool
    {
        if (($filters['severity'] ?? null) && $group['priority'] !== $filters['severity']) {
            return false;
        }

        if (($filters['category'] ?? null) && $group['category'] !== $filters['category']) {
            return false;
        }

        if (($filters['kind'] ?? null) && $group['kind'] !== $filters['kind']) {
            return false;
        }

        if (($filters['page'] ?? null)) {
            $has = false;

            foreach ($group['rows'] as $row) {
                $has = $has || $row['page_id'] === (int) $filters['page'];
            }

            if (! $has) {
                return false;
            }
        }

        if (($filters['status'] ?? null)) {
            $has = false;

            foreach ($group['rows'] as $row) {
                $has = $has || $row['status'] === $filters['status'];
            }

            if (! $has) {
                return false;
            }
        }

        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        if ($q !== '') {
            if (str_contains(mb_strtolower($group['title'].' '.$group['rule_key']), $q)) {
                return true;
            }

            foreach ($group['rows'] as $row) {
                if (str_contains(mb_strtolower((string) $row['page_url'].' '.$row['observed']), $q)) {
                    return true;
                }
            }

            return false;
        }

        return true;
    }

    /**
     * When the page / status / search filters are set, only the matching rows are shown inside the group.
     *
     * @param  array<string, mixed>  $group
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function applyRowFilters(array $group, array $filters): array
    {
        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));
        $titleHit = $q !== '' && str_contains(mb_strtolower($group['title'].' '.$group['rule_key']), $q);

        if (! ($filters['page'] ?? null) && ! ($filters['status'] ?? null) && ($q === '' || $titleHit)) {
            return $group;
        }

        $rows = array_values(array_filter($group['rows'], function (array $row) use ($filters, $q): bool {
            return (! ($filters['page'] ?? null) || $row['page_id'] === (int) $filters['page'])
                && (! ($filters['status'] ?? null) || $row['status'] === $filters['status'])
                && ($q === '' || str_contains(mb_strtolower((string) $row['page_url'].' '.$row['observed']), $q));
        }));

        $group['rows'] = $rows;
        $group['row_count'] = count($rows);
        $group['page_count'] = count(array_unique(array_filter(array_column($rows, 'page_url'))));
        $group['clusters'] = $this->clusters($group['rule_key'], $rows);

        return $group;
    }

    // ---------------------------------------------------------------- history / change tracking

    /**
     * What changed since the previous report: new, resolved and unchanged findings, compared ONLY over pages both checks
     * audited (a page that was not re-checked is "not re-checked", never "resolved"; a page that was not checked before is
     * "newly covered", never "new").
     *
     * @return array<string, mixed>|null null when there is no comparable previous report
     */
    public function diff(ExternalSiteCrawl $current, ?ExternalSiteCrawl $previous): ?array
    {
        if ($previous === null || ! $current->isReport() || ! $previous->isReport() || $this->bareHost((string) $current->host) !== $this->bareHost((string) $previous->host)) {
            return null;
        }

        $cur = $this->findingIndex($current);
        $prev = $this->findingIndex($previous);
        $curAudited = $this->auditedKeys($current);
        $prevAudited = $this->auditedKeys($previous);

        $new = [];
        $newlyCovered = [];
        $unchanged = [];
        $resolved = [];
        $notRechecked = [];

        foreach ($cur as $key => $row) {
            if (isset($prev[$key])) {
                $unchanged[$key] = $row;
            } elseif ($row['page_key'] === null || isset($prevAudited[$row['page_key']])) {
                $new[$key] = $row;
            } else {
                $newlyCovered[$key] = $row;
            }
        }

        foreach ($prev as $key => $row) {
            if (isset($cur[$key])) {
                continue;
            }

            if ($row['page_key'] === null || isset($curAudited[$row['page_key']])) {
                $resolved[$key] = $row;
            } else {
                $notRechecked[$key] = $row;
            }
        }

        $resolvedByRule = [];

        foreach ($resolved as $row) {
            $resolvedByRule[$row['rule_key']] = ($resolvedByRule[$row['rule_key']] ?? 0) + 1;
        }

        $shared = count(array_intersect_key($curAudited, $prevAudited));

        return [
            'previous_id' => $previous->id,
            'previous_finished_at' => $previous->finished_at,
            'new' => count($new),
            'new_keys' => array_fill_keys(array_keys($new), true),
            'newly_covered' => count($newlyCovered),
            'unchanged' => count($unchanged),
            'unchanged_keys' => array_fill_keys(array_keys($unchanged), true),
            'resolved' => count($resolved),
            'resolved_by_rule' => $resolvedByRule,
            'not_rechecked' => count($notRechecked),
            'pages_now' => count($curAudited),
            'pages_before' => count($prevAudited),
            'pages_in_both' => $shared,
            'same_coverage' => $current->status === ExternalSiteCrawl::COMPLETED && $previous->status === ExternalSiteCrawl::COMPLETED && count($curAudited) === count($prevAudited) && $shared === count($curAudited),
        ];
    }

    /** @return array<string, array{rule_key: string, page_key: ?string}> */
    private function findingIndex(ExternalSiteCrawl $crawl): array
    {
        $out = [];

        foreach (ExternalSiteFinding::query()->where('crawl_id', $crawl->id)->with('page:id,url')->get(['id', 'page_id', 'finding_key', 'rule_key']) as $f) {
            if ($f->finding_key !== null) {
                $out[(string) $f->finding_key] = ['rule_key' => (string) $f->rule_key, 'page_key' => $f->page === null ? null : UrlResolver::pageKey((string) $f->page->url)];
            }
        }

        return $out;
    }

    /** @return array<string, true> */
    private function auditedKeys(ExternalSiteCrawl $crawl): array
    {
        $out = [];

        foreach (ExternalSitePage::query()->where('crawl_id', $crawl->id)->where('kind', 'html')->pluck('url') as $url) {
            $key = UrlResolver::pageKey((string) $url);

            if ($key !== null) {
                $out[$key] = true;
            }
        }

        return $out;
    }

    // ---------------------------------------------------------------- pages

    /**
     * The audited HTML pages with their issue counts by priority, filtered and sorted.
     *
     * @param  array{q?: ?string, priority?: ?string, importance?: ?string, indexing?: ?string}  $filters
     * @return Collection<int, ExternalSitePage> each with `counts` (priority => n), `issue_total`, `worst`
     */
    public function pages(ExternalSiteCrawl $crawl, array $filters = [], string $sort = 'severity'): Collection
    {
        $pages = ExternalSitePage::query()->where('crawl_id', $crawl->id)->when($crawl->isReport(), fn ($q) => $q->where('kind', 'html'))->orderBy('id')->get();
        $counts = [];

        foreach (ExternalSiteFinding::query()->where('crawl_id', $crawl->id)->whereNotNull('page_id')->selectRaw('page_id, priority, severity, COUNT(*) as n')->groupBy('page_id', 'priority', 'severity')->get() as $row) {
            $priority = (string) ($row->priority ?: match ((string) $row->severity) {'critical' => 'critical', 'warning' => 'medium', default => 'low'});
            $counts[(int) $row->page_id][$priority] = ($counts[(int) $row->page_id][$priority] ?? 0) + (int) $row->n;
        }

        $pages->each(function (ExternalSitePage $page) use ($counts): void {
            $mine = $counts[(int) $page->id] ?? [];
            $worst = null;

            foreach (ExternalAuditCatalog::PRIORITIES as $p) {
                if (($mine[$p] ?? 0) > 0) {
                    $worst = $p;

                    break;
                }
            }

            $page->setAttribute('counts', $mine);
            $page->setAttribute('issue_total', array_sum($mine));
            $page->setAttribute('actionable_total', array_sum(array_diff_key($mine, ['info' => 1])));
            $page->setAttribute('worst', $worst);
        });

        $q = mb_strtolower(trim((string) ($filters['q'] ?? '')));

        $pages = $pages->filter(function (ExternalSitePage $p) use ($filters, $q): bool {
            if ($q !== '' && ! str_contains(mb_strtolower((string) $p->url.' '.(string) $p->title), $q)) {
                return false;
            }

            if (($filters['priority'] ?? null) && $p->worst !== $filters['priority']) {
                return false;
            }

            if (($filters['importance'] ?? null) && (string) $p->importance !== $filters['importance']) {
                return false;
            }

            if (($filters['indexing'] ?? null) === 'noindex' && ! $p->noindex) {
                return false;
            }

            if (($filters['indexing'] ?? null) === 'indexable' && $p->noindex) {
                return false;
            }

            return true;
        });

        $weight = fn (?string $p): int => $p === null ? 0 : ExternalAuditCatalog::priorityWeight($p);

        $sorted = match ($sort) {
            'issues' => $pages->sortByDesc(fn (ExternalSitePage $p) => [$p->actionable_total, $weight($p->worst)]),
            'title' => $pages->sortBy(fn (ExternalSitePage $p) => mb_strtolower((string) $p->title)),
            'url' => $pages->sortBy(fn (ExternalSitePage $p) => $p->displayPath()),
            'status' => $pages->sortBy(fn (ExternalSitePage $p) => [(int) $p->http_status, $p->displayPath()]),
            default => $pages->sortByDesc(fn (ExternalSitePage $p) => [$weight($p->worst), $p->actionable_total, -ExternalPageImportance::weight((string) $p->importance)]),
        };

        return $sorted->values();
    }

    /**
     * URLs that were found but NOT audited (redirects, errors, blocked, excluded, skipped ...), for the "not audited" list.
     *
     * @return Collection<int, ExternalSitePage>
     */
    public function otherUrls(ExternalSiteCrawl $crawl): Collection
    {
        return ExternalSitePage::query()->where('crawl_id', $crawl->id)->where('kind', '!=', 'html')->orderBy('kind')->orderBy('id')->limit(400)->get();
    }

    /**
     * Everything about ONE page: the stored observations, its findings, its links and the pages that link to it.
     *
     * @return array<string, mixed>|null
     */
    public function page(ExternalSiteCrawl $crawl, int $pageId): ?array
    {
        $page = ExternalSitePage::query()->where('crawl_id', $crawl->id)->whereKey($pageId)->first();

        if ($page === null) {
            return null;
        }

        $findings = ExternalSiteFinding::query()->where('crawl_id', $crawl->id)->where('page_id', $page->id)->orderBy('id')->get();
        $rows = [];

        foreach ($findings as $finding) {
            $rule = ExternalAuditCatalog::maybe((string) $finding->rule_key);

            if ($rule === null) {
                continue;
            }

            $evidence = is_array($finding->evidence) ? $finding->evidence : [];
            $rows[] = [
                'rule_key' => (string) $finding->rule_key,
                'title' => $rule['title'],
                'priority' => $finding->priority(),
                'kind' => $rule['kind'],
                'kind_label' => ExternalAuditCatalog::KINDS[$rule['kind']],
                'category_label' => ExternalAuditCatalog::CATEGORIES[$rule['category']],
                'observed' => (string) ($evidence['observed'] ?? ''),
                'values' => (array) ($evidence['values'] ?? []),
                'related' => (array) ($evidence['related'] ?? []),
                'proposal' => $evidence['proposal'] ?? null,
                'why' => $rule['why'],
                'steps' => $rule['steps'],
                'verify' => $rule['verify'],
                'confidence' => (string) ($finding->confidence ?? $rule['confidence']),
            ];
        }

        usort($rows, fn (array $a, array $b): int => ExternalAuditCatalog::priorityWeight($b['priority']) <=> ExternalAuditCatalog::priorityWeight($a['priority']));

        $targets = $this->targetIndex($crawl);
        $outgoing = [];

        foreach (ExternalSiteLink::query()->where('crawl_id', $crawl->id)->where('source_page_id', $page->id)->orderBy('id')->limit(150)->get() as $link) {
            $t = $targets[$link->target_hash] ?? null;
            $outgoing[] = ['url' => (string) $link->target_url, 'anchor' => (string) ($link->anchor ?? ''), 'nofollow' => (bool) $link->nofollow, 'kind' => $t['kind'] ?? 'unchecked', 'status' => $t['status'] ?? null, 'final_url' => $t['final_url'] ?? null];
        }

        $key = UrlResolver::pageKey((string) $page->url);
        $incoming = [];

        if ($key !== null) {
            foreach (ExternalSiteLink::query()->where('crawl_id', $crawl->id)->where('target_hash', sha1($key))->with('source:id,url,title')->limit(30)->get() as $link) {
                if ($link->source !== null) {
                    $incoming[] = ['page_id' => (int) $link->source->id, 'url' => (string) $link->source->url, 'path' => $link->source->displayPath(), 'anchor' => (string) ($link->anchor ?? '')];
                }
            }
        }

        return ['page' => $page, 'findings' => $rows, 'outgoing' => $outgoing, 'incoming' => $incoming, 'evidence' => (array) $page->evidence];
    }

    /** @return array<string, array{kind: string, status: ?int, final_url: ?string}> sha1(pageKey) => what the crawl found there */
    private function targetIndex(ExternalSiteCrawl $crawl): array
    {
        $out = [];

        foreach (ExternalSitePage::query()->where('crawl_id', $crawl->id)->get(['url', 'kind', 'http_status', 'final_url']) as $p) {
            $key = UrlResolver::pageKey((string) $p->url);

            if ($key !== null) {
                $out[sha1($key)] = ['kind' => (string) $p->kind, 'status' => $p->http_status === null ? null : (int) $p->http_status, 'final_url' => $p->final_url];
            }
        }

        return $out;
    }

    /**
     * How a page appears to search engines, in the owner's words (used by the earlier-format Pages table too).
     */
    public static function pageState(ExternalSitePage $page): string
    {
        return ExternalAuditPresenter::pageIndexability($page->indexable ?? ! $page->noindex, (string) (($page->evidence['canonical_status'] ?? null)), (string) ($page->kind ?: 'html'));
    }
}
