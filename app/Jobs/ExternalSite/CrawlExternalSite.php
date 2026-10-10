<?php

namespace App\Jobs\ExternalSite;

use App\Library\ExternalSite\ExternalSiteAuditRunner;
use App\Models\ExternalSiteCrawl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * External Website Audit Mode V1 — runs one queued crawl off the request.
 *
 * IDS ONLY. One try: the runner claims the crawl row atomically (queued ->
 * running), so a redelivery does nothing, and a failed crawl records a reason
 * code and is simply requested again by the owner or the scheduler.
 */
class CrawlExternalSite implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public readonly int $crawlId)
    {
    }

    public function handle(ExternalSiteAuditRunner $runner): void
    {
        $crawl = ExternalSiteCrawl::query()->find($this->crawlId);

        if ($crawl !== null) {
            $runner->run($crawl);
        }
    }
}
