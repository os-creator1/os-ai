<?php

namespace App\Jobs\Seo;

use App\Library\Seo\SeoAuditRunner;
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
     * §8.7 — one revision-scoped audit. A revision that is not this Website's
     * produces nothing at all, which is the cross-tenant case.
     */
    public function handle(SeoAuditRunner $runner): void
    {
        $runner->runForRevision($this->businessId, $this->websiteId, $this->websiteRevisionId);
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
