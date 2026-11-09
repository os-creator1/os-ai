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
    protected $signature = 'seo:rank-sync-locations {--if-missing : Only call the provider when a supported country has no cached cities}';

    protected $description = 'Cache the rank-tracking provider search locations (free provider endpoint)';

    public function handle(SeoRankLocationCatalog $catalog): int
    {
        $countries = SeoRankLocationCatalog::supportedCountries();

        if ($this->option('if-missing')) {
            $countries = $catalog->missingCountries();

            if ($countries === []) {
                $this->info('Locations already cached; nothing to do.');

                return self::SUCCESS;
            }
        }

        $failed = false;

        foreach ($countries as $country) {
            try {
                $this->info("{$country}: cached " . $catalog->syncCountry($country) . ' locations.');
            } catch (SeoRankProviderException $e) {
                $failed = true;
                $this->error("{$country}: location sync failed ({$e->errorCode}).");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
