<?php

namespace App\Jobs\Seo;

use App\Library\Seo\SeoConfig;
use App\Models\SeoRankObservation;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Daily retention: delete observations older than the retention window (default
 * 13 months, lower-only). Bounded batches. Only observations are pruned — the
 * provider-cost ledger is the platform's spend history and is kept.
 */
class PruneSeoRankObservations implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function handle(SeoConfig $config): void
    {
        $cutoff = CarbonImmutable::now('UTC')->subMonthsNoOverflow($config->rankRetentionMonths());

        do {
            $deleted = SeoRankObservation::query()
                ->where('checked_at', '<', $cutoff)
                ->limit(1000)
                ->delete();
        } while ($deleted > 0);
    }
}
