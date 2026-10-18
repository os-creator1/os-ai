<?php

namespace App\Console\Commands;

use App\Library\Seo\Rank\Provider\SeoRankProviderException;
use App\Library\Seo\Rank\SeoRankLocationCatalog;
use Illuminate\Console\Command;

/**
 * Refresh the local cache of the provider's FREE location catalogue
 * (seo_rank_locations). Free endpoint, no spend. Needs provider credentials but
 * not the paid-tracking master switch, so locations can be loaded before rank
 * tracking is turned on.
 */
class SyncSeoRankLocations extends Command
{
    protected $signature = 'seo:rank-sync-locations';

    protected $description = 'Cache the rank-tracking provider search locations (free provider endpoint)';

    public function handle(SeoRankLocationCatalog $catalog): int
    {
        try {
            $count = $catalog->sync();
        } catch (SeoRankProviderException $e) {
            $this->error('Location sync failed (' . $e->errorCode . ').');

            return self::FAILURE;
        }

        $this->info("Cached {$count} locations.");

        return self::SUCCESS;
    }
}
