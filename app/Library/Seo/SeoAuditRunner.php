<?php

namespace App\Library\Seo;

use App\Enums\Seo\SeoAuditRunStatus;
use App\Enums\Seo\SeoAuditSeverity;
use App\Enums\Website\WebsiteStatus;
use App\Models\SeoAuditFinding;
use App\Models\SeoAuditRun;
use App\Models\Website;
use App\Models\WebsiteRevision;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Contract 18 §8.7, Sub-slice G — persists ONE audit of ONE immutable
 * published revision, then prunes history.
 *
 * THE ONLY SEO CLASS IN THIS SUB-SLICE THAT WRITES, and it writes exactly two
 * tables: `seo_audit_runs` and `seo_audit_findings`. It has no path to
 * `websites`, `website_pages`, `website_revisions` or `website_assets`
 * (§12.1) — it only READS a revision through SeoPublishedContentReader — and
 * no path to Business, Location or Google data. SeoAuditBoundaryTest pins
 * that table-scoped rule, the same way SeoCitationsBoundaryTest pins
 * SeoCitationManager's.
 *
 * It also makes no decisions: every rule lives in the pure SeoAuditEvaluator
 * and every severity in SeoAuditRuleRegistry. This class chooses nothing a
 * customer will read.
 *
 * IDEMPOTENT PER REVISION, IN THE DATABASE (§8.7). Identity is
 * `(website_revision_id, rule_set_version)` and it is a UNIQUE key, so a
 * duplicate WebsitePublished delivery, a re-queued job, a concurrent worker
 * and a manual re-run of the same revision all converge on the SAME canonical
 * run. The pre-check below is an optimisation; the unique key is the
 * guarantee, and the QueryException branch is what makes the concurrent case
 * safe rather than merely unlikely.
 *
 * NO URL IS EVER FETCHED and no AI is ever called — there is no HTTP client,
 * no crawler and no provider here, by construction.
 */
final class SeoAuditRunner
{
    public function __construct(
        private readonly SeoPublishedContentReader $reader,
        private readonly SeoAuditEvaluator $evaluator,
        private readonly SeoConfig $config,
    ) {
    }

    /**
     * Audit the named revision of the named Website.
     *
     * Returns the canonical run for that revision — newly written, or the one
     * that already existed. Returns null only when the revision is not this
     * Website's, which is the cross-tenant case and must produce nothing.
     */
    public function runForRevision(int $businessId, int $websiteId, int $revisionId): ?SeoAuditRun
    {
        $existing = $this->existingRun($revisionId);

        if ($existing !== null) {
            return $existing;
        }

        $content = $this->reader->forRevision($websiteId, $revisionId);

        if ($content === null) {
            // Distinguish "not this Website's revision" (write nothing) from
            // "the revision exists but its snapshot is unusable" (an honest
            // failed run). The extra read happens only on this rare branch.
            $revisionExists = WebsiteRevision::query()
                ->where('website_id', $websiteId)
                ->where('id', $revisionId)
                ->exists();

            if (! $revisionExists) {
                return null;
            }

            return $this->persist($businessId, $websiteId, $revisionId, SeoAuditRunStatus::Failed, 0, []);
        }

        return $this->persist(
            $businessId,
            $websiteId,
            $revisionId,
            SeoAuditRunStatus::Completed,
            $content->pageCount(),
            $this->evaluator->evaluate($content),
        );
    }

    /**
     * The Business's CURRENTLY published Website revision, resolved through
     * the Business so a caller never passes ids it guessed — this is what a
     * manual re-run audits.
     *
     * Returns null when there is nothing published to audit. Read-only.
     *
     * @return array{website_id: int, revision_id: int}|null
     */
    public function publishedTargetFor(int $businessId): ?array
    {
        $website = Website::query()
            ->where('business_id', $businessId)
            ->first(['id', 'status', 'published_revision_id']);

        if ($website === null
            || $website->status !== WebsiteStatus::Published
            || $website->published_revision_id === null) {
            return null;
        }

        return [
            'website_id' => (int) $website->id,
            'revision_id' => (int) $website->published_revision_id,
        ];
    }

    /**
     * @param  array<int, SeoAuditFindingDraft>  $drafts
     */
    private function persist(
        int $businessId,
        int $websiteId,
        int $revisionId,
        SeoAuditRunStatus $status,
        int $pageCount,
        array $drafts,
    ): SeoAuditRun {
        $counts = [
            SeoAuditSeverity::Critical->value => 0,
            SeoAuditSeverity::Warning->value => 0,
            SeoAuditSeverity::Info->value => 0,
        ];

        foreach ($drafts as $draft) {
            $counts[$draft->severity()->value]++;
        }

        try {
            $run = DB::transaction(function () use ($businessId, $websiteId, $revisionId, $status, $pageCount, $counts, $drafts): SeoAuditRun {
                $run = SeoAuditRun::query()->create([
                    'business_id' => $businessId,
                    'website_id' => $websiteId,
                    'website_revision_id' => $revisionId,
                    'rule_set_version' => SeoAuditRuleRegistry::VERSION,
                    'status' => $status->value,
                    'page_count' => $pageCount,
                    'critical_count' => $counts[SeoAuditSeverity::Critical->value],
                    'warning_count' => $counts[SeoAuditSeverity::Warning->value],
                    'info_count' => $counts[SeoAuditSeverity::Info->value],
                ]);

                if ($drafts !== []) {
                    // ONE insert for every finding: §11.4 requires the query
                    // count to be independent of how many findings there are.
                    $now = now();

                    SeoAuditFinding::query()->insert(array_map(
                        static fn (SeoAuditFindingDraft $d): array => [
                            'seo_audit_run_id' => $run->id,
                            'page_uid' => $d->pageUid,
                            'rule_key' => $d->ruleKey,
                            'severity' => $d->severity()->value,
                            'facts' => json_encode($d->facts),
                            'created_at' => $now,
                        ],
                        $drafts,
                    ));
                }

                return $run;
            });
        } catch (QueryException $e) {
            // A concurrent worker won the unique key. Its run is the
            // canonical one; ours never existed.
            $concurrent = $this->existingRun($revisionId);

            if ($concurrent !== null) {
                return $concurrent;
            }

            throw $e;
        }

        $this->prune($websiteId);

        return $run;
    }

    private function existingRun(int $revisionId): ?SeoAuditRun
    {
        return SeoAuditRun::query()
            ->where('website_revision_id', $revisionId)
            ->where('rule_set_version', SeoAuditRuleRegistry::VERSION)
            ->first();
    }

    /**
     * §8.7 retention — keep only the latest N runs per Website.
     *
     * Ordered by id DESC, so the run just written is always inside the kept
     * window and can never prune itself. Bounded by construction: it deletes
     * only ids it has already selected, and findings follow by
     * `cascadeOnDelete`. It touches NO Website table — pruning SEO history
     * must never reach a revision (§12.1).
     */
    private function prune(int $websiteId): void
    {
        $keep = $this->config->auditRunsRetained();

        $doomed = SeoAuditRun::query()
            ->where('website_id', $websiteId)
            ->orderByDesc('id')
            ->skip($keep)
            ->take(100)
            ->pluck('id')
            ->all();

        if ($doomed === []) {
            return;
        }

        SeoAuditRun::query()->whereIn('id', $doomed)->delete();
    }
}
