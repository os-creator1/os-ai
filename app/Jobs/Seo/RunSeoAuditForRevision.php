<?php

namespace App\Jobs\Seo;

use App\Enums\Business\BusinessStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Workspace\BusinessWorkspaceMismatchException;
use App\Exceptions\Workspace\WorkspaceBusinessNotFoundException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Seo\SeoAuditRunner;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Contract 18 §8.7, Sub-slice G — audit ONE immutable published revision, off
 * the request.
 *
 * IDS ONLY, never a hydrated model or a snapshot payload: the job is queued
 * from a listener on an event that itself carries only ids, and the revision
 * it names is immutable, so re-reading it later is guaranteed to give the same
 * document the publish produced.
 *
 * SAFE TO RETRY AND SAFE TO DUPLICATE. Identity is
 * `(website_revision_id, rule_set_version)` with a UNIQUE key behind it, so a
 * redelivered event, a re-queued job and a manual re-run all converge on one
 * canonical run (§8.7). That is why `$tries` above 1 is harmless here: an
 * attempt that already succeeded simply finds the existing run and returns it.
 *
 * READS ONE REVISION, WRITES ONLY SEO TABLES. No URL is fetched, no crawler
 * runs, no AI or provider is called, and nothing under `websites`,
 * `website_pages`, `website_revisions` or `website_assets` is written (§12.1).
 */
class RunSeoAuditForRevision implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $businessId,
        public readonly int $websiteId,
        public readonly int $websiteRevisionId,
    ) {
    }

    /**
     * §8.7 — one revision-scoped audit, but only if the Business may still
     * have one. A revision that is not this Website's produces nothing at
     * all, which is the cross-tenant case the runner enforces.
     */
    public function handle(SeoAuditRunner $runner, EntitlementManager $entitlements): void
    {
        if (! $this->stillEntitled($entitlements)) {
            return;
        }

        $runner->runForRevision($this->businessId, $this->websiteId, $this->websiteRevisionId);
    }

    /**
     * Contract 18 §10.3 — "Jobs re-check entitlement and Location access at
     * execution time (GBP §24.7)." Mirrors
     * RefreshGoogleBusinessProfileMirror::stillEligible() narrowly, with
     * PlatformFeature::SeoModule in place of the GBP module.
     *
     * WHY IT MATTERS HERE. The publish that queued this job is authoritative
     * and independent of SEO, so the job can be delivered long after the
     * event — and, while SeoModule is Planned, for a Business that may never
     * see the audit at all. Re-checking at execution is what stops an
     * automatic WebsitePublished delivery from quietly accumulating audit
     * data for an unentitled Business.
     *
     * No LocationAccessGuard: the Website audit is explicitly Business-wide
     * and G-1 defers page<->Location attribution, so there is no Location to
     * check and inventing one would be a fabricated authorization claim.
     *
     * Failure is SILENT by design — return quietly, create no audit run,
     * mutate nothing. An unentitled Business is not an error to retry.
     */
    private function stillEntitled(EntitlementManager $entitlements): bool
    {
        $business = Business::query()->find($this->businessId);

        if ($business === null || $business->status !== BusinessStatus::Active) {
            return false;
        }

        if ($business->workspace_id === null) {
            return false;
        }

        $workspace = Workspace::query()->find($business->workspace_id);

        if ($workspace === null || ! $workspace->is_active) {
            return false;
        }

        return $this->featureIsAllowed($entitlements, $workspace, $business);
    }

    /**
     * The entitlement decision alone, as its own overridable step.
     *
     * EntitlementManager is `final`, so a test cannot replace the decision by
     * mocking it. This seam exists so a test-only subclass can say "assume
     * the feature is allowed" WITHOUT also disabling the Business-active and
     * Workspace-active checks above — those stay real code in every test.
     * It mirrors the repository's existing "replace exactly and only this
     * step" idiom (the EntitlementBypass* controllers).
     */
    protected function featureIsAllowed(EntitlementManager $entitlements, Workspace $workspace, Business $business): bool
    {
        try {
            // A background run has no human actor; the entitlement
            // signature's actor argument is audit-only and is never a
            // tenancy decision (the GBP scheduled-job convention).
            $decision = $entitlements->decide(
                $workspace,
                $business,
                PlatformFeature::SeoModule->value,
                0,
            );
        } catch (WorkspaceBusinessNotFoundException|BusinessWorkspaceMismatchException) {
            return false;
        }

        return $decision->allowed;
    }

    /**
     * A permanently failing audit is logged and dropped. It must never become
     * a customer-visible problem: the publish it followed already succeeded,
     * and the next publish (or a manual re-run) produces a fresh attempt.
     */
    public function failed(Throwable $e): void
    {
        Log::warning('RunSeoAuditForRevision failed', [
            'business_id' => $this->businessId,
            'website_id' => $this->websiteId,
            'website_revision_id' => $this->websiteRevisionId,
            'exception' => $e->getMessage(),
        ]);
    }
}
