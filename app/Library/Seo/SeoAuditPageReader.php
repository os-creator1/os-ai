<?php

namespace App\Library\Seo;

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
 * THE RUN SHOWN IS THE ONE FOR THE PUBLISHED REVISION. Not "the newest row":
 * after a rollback the newest run can describe a revision that is no longer
 * live, and presenting its findings as the site's current state would be
 * wrong. No run for the published revision (never audited, pruned, or a newer
 * rule set not run yet) is reported as "not checked yet for this version".
 *
 * CONSTANT QUERY COST (§11.4). Six reads, whatever the number of pages,
 * findings or historical runs: the Website, the published revision's snapshot
 * (also the source of page names), the Website's domain check (two), the
 * retained run window, and the shown run's findings. Nothing here loops a
 * query, so an N+1 cannot appear as the site or its problems grow.
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

        if ($website === null) {
            return new SeoAuditPage(SeoIndexability::resolve(null, false), null, [], []);
        }

        $published = $website->status === WebsiteStatus::Published && $website->published_revision_id !== null;

        $publishedContent = $published
            ? $this->content->forRevision((int) $website->id, (int) $website->published_revision_id)
            : null;

        // Whether search engines can find the site: a status about how it is
        // set up today (§5.2.4), never a finding.
        $indexability = SeoIndexability::resolve(
            $publishedContent,
            $publishedContent !== null && $this->content->hasActivePrimaryDomain((int) $website->id),
        );

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

        $current = $published ? $this->runForPublishedRevision($history, (int) $website->published_revision_id) : null;

        if ($current === null) {
            return new SeoAuditPage($indexability, null, [], $history, $published);
        }

        return new SeoAuditPage($indexability, $current, $this->findings($current, $publishedContent), $history, $published);
    }

    /**
     * The newest run of the CURRENT rule set that audited the published
     * revision, from the already-loaded window — no query.
     *
     * @param  array<int, SeoAuditRun>  $history  newest first
     */
    private function runForPublishedRevision(array $history, int $publishedRevisionId): ?SeoAuditRun
    {
        foreach ($history as $run) {
            if ((int) $run->website_revision_id === $publishedRevisionId
                && (int) $run->rule_set_version === SeoAuditRuleRegistry::VERSION) {
                return $run;
            }
        }

        return null;
    }

    /**
     * @return array<int, SeoAuditFindingView>
     */
    private function findings(SeoAuditRun $run, ?SeoPublishedContent $audited): array
    {
        $rows = SeoAuditFinding::query()
            ->where('seo_audit_run_id', $run->id)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        // Page NAMES come from the revision the run audited — which is the
        // published revision, already read above, so no second read.
        $names = $this->pageNames($audited);

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
     * uid => page name, from the audited snapshot. No query, no loop.
     *
     * @return array<string, string>
     */
    private function pageNames(?SeoPublishedContent $content): array
    {
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
