<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoIndexabilityState;
use App\Enums\Website\WebsiteStatus;
use App\Models\Business;
use App\Models\SeoAuditFinding;
use App\Models\SeoAuditRun;
use App\Models\Website;

/**
 * Contract 18 §8.7, Sub-slice G — the read model behind the Website SEO audit
 * screen.
 *
 * READ-ONLY, and scoped by Business. Every row it returns is reached THROUGH
 * the Business (§10.1): the Website is selected by `business_id`, runs by that
 * Website's id, and findings by that run's id — so a guessed foreign Website,
 * revision, run or finding id resolves to nothing rather than to another
 * tenant's data.
 *
 * CONSTANT QUERY COST (§11.4). Five reads, whatever the number of pages,
 * findings or historical runs: the Website, the retained run window, the
 * latest run's findings, and the audited revision's snapshot (one read inside
 * SeoPublishedContentReader, which is itself two at most). Nothing here loops
 * a query, so an N+1 cannot appear as the site or its problems grow.
 *
 * It never fetches a URL and never calls a provider or AI — it reads rows the
 * audit already wrote plus the immutable snapshot those rows describe.
 */
final class SeoAuditPageReader
{
    public function __construct(private readonly SeoPublishedContentReader $content)
    {
    }

    public function read(Business $business): SeoAuditPage
    {
        $website = Website::query()
            ->where('business_id', $business->id)
            ->first(['id', 'status', 'published_revision_id']);

        $indexability = ($website === null
            || $website->status !== WebsiteStatus::Published
            || $website->published_revision_id === null)
            // §5.2.4 / §8.7 — a platform property, never a finding.
            ? SeoIndexabilityState::NoPublishedWebsite
            : SeoIndexabilityState::PlatformPathNotIndexable;

        if ($website === null) {
            return new SeoAuditPage($indexability, null, [], []);
        }

        // Scoped by BOTH the Business and its Website. The Website was
        // already selected by `business_id`, so the second predicate is
        // redundant on well-formed data — which is exactly why it is here: a
        // run row whose `business_id` disagrees with the Website it claims is
        // corrupt or cross-tenant, and must not render. Authorization is the
        // conjunction, never the row's own stored ids.
        /** @var array<int, SeoAuditRun> $history */
        $history = SeoAuditRun::query()
            ->where('website_id', $website->id)
            ->where('business_id', $business->id)
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->all();

        $latest = $history[0] ?? null;

        if ($latest === null) {
            return new SeoAuditPage($indexability, null, [], []);
        }

        return new SeoAuditPage($indexability, $latest, $this->findings($latest, (int) $website->id), $history);
    }

    /**
     * @return array<int, SeoAuditFindingView>
     */
    private function findings(SeoAuditRun $run, int $websiteId): array
    {
        $rows = SeoAuditFinding::query()
            ->where('seo_audit_run_id', $run->id)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Page NAMES come from the revision the run actually audited, not from
        // whatever is published now: a finding must be labelled with the page
        // as it was when the problem was found.
        $names = $this->pageNames($websiteId, (int) $run->website_revision_id);

        $views = [];

        foreach ($rows as $row) {
            $ruleKey = (string) $row->rule_key;

            if (! SeoAuditRuleRegistry::has($ruleKey)) {
                // A row written by a rule set this code no longer knows.
                // Skipped rather than rendered with invented wording.
                continue;
            }

            $pageUid = $row->page_uid === null ? null : (string) $row->page_uid;

            $views[] = new SeoAuditFindingView(
                ruleKey: $ruleKey,
                severity: SeoAuditRuleRegistry::severityFor($ruleKey),
                title: SeoAuditRuleRegistry::titleFor($ruleKey),
                description: SeoAuditRuleRegistry::describe($ruleKey, is_array($row->facts) ? $row->facts : []),
                pageUid: $pageUid,
                pageName: $pageUid === null ? null : ($names[$pageUid] ?? null),
            );
        }

        return $views;
    }

    /**
     * uid => page name, from the audited snapshot. One read, no loop.
     *
     * @return array<string, string>
     */
    private function pageNames(int $websiteId, int $revisionId): array
    {
        $content = $this->content->forRevision($websiteId, $revisionId);

        if ($content === null) {
            return [];
        }

        $names = [];

        foreach ($content->pages as $page) {
            $uid = trim($page->uid);

            if ($uid !== '') {
                $names[$uid] = $page->title;
            }
        }

        return $names;
    }
}
