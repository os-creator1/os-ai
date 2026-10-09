<?php

namespace App\Console\Commands;

use App\Library\ExternalSite\ExternalSiteConfig;
use App\Library\ExternalSite\ExternalSiteCrawlManager;
use App\Library\ExternalSite\ExternalSiteException;
use App\Models\Business;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * External Website Audit Mode V1 — the scheduled re-check of every external
 * website, about once a week.
 *
 * Bounded by construction: a hard --limit per invocation, a Business is only
 * considered when its last crawl is older than the cadence (or it has none), and
 * ExternalSiteCrawlManager still enforces one active crawl per Business. It only
 * queues jobs; the crawl itself runs on the queue with its own limits. It never
 * takes a URL from anywhere but the Business's stored address.
 */
class RecrawlExternalWebsites extends Command
{
    protected $signature = 'website:recrawl-external {--limit=100 : Maximum number of Businesses to queue in this run}';

    protected $description = 'Queue the weekly re-check of every Business that uses an existing (external) website';

    public function handle(ExternalSiteCrawlManager $crawls, ExternalSiteConfig $config): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cutoff = now()->subDays($config->recrawlIntervalDays());
        $queued = 0;

        $ids = DB::table('businesses')
            ->where('website_mode', 'external')
            ->whereNotNull('website_url')
            ->where('website_url', '<>', '')
            ->whereNotExists(function ($q) use ($cutoff): void {
                $q->select(DB::raw(1))->from('external_site_crawls as c')
                    ->whereColumn('c.business_id', 'businesses.id')
                    ->where('c.created_at', '>', $cutoff);
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach (Business::query()->whereIn('id', $ids)->get() as $business) {
            try {
                if ($crawls->request($business, 'scheduled')['state'] === ExternalSiteCrawlManager::STATE_QUEUED) {
                    $queued++;
                }
            } catch (ExternalSiteException) {
                // An address that cannot be crawled is skipped; the Website screens tell the owner.
            }
        }

        $this->info("Queued {$queued} external website check(s).");

        return self::SUCCESS;
    }
}
