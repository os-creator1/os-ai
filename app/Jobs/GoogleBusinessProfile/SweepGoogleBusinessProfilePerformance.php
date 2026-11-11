<?php

namespace App\Jobs\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Jobs\Base;
use App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceLoader;
use App\Models\BusinessGoogleLocation;
use Carbon\CarbonImmutable;

/**
 * The daily, STAGGERED refresh of every active linked location's Google performance, so the page opens with current numbers.
 *
 * It only dispatches one RefreshGoogleBusinessProfilePerformance per location with a deterministic delay across the hour (Google
 * denies quota increases to a "highly spiky request pattern"), and honours the same project-level circuit breaker as the mirror
 * sweep. A location is skipped only if its held result will still be available at its next scheduled read; concurrent reads of one
 * connection are prevented by the connection claim in each job.
 */
class SweepGoogleBusinessProfilePerformance extends Base
{
    private const CHUNK = 100;

    private const STAGGER_WINDOW = 3600;

    /** Allowance, in seconds, for a worker that starts a scheduled job late. */
    public const LATE_WORKER_MARGIN = 900;

    public function handle(GoogleBusinessProfilePerformanceLoader $performance): void
    {
        if ((new SweepGoogleBusinessProfileRefreshes())->breakerTripped()) {
            return;
        }

        BusinessGoogleLocation::query()
            ->whereHas('connection', fn ($query) => $query->where('state', GoogleConnectionState::Active->value))
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($bindings) use ($performance) {
                foreach ($bindings as $binding) {
                    $delay = ((int) $binding->id * 13) % self::STAGGER_WINDOW;

                    // This location's NEXT scheduled read is one day from now plus the same stagger (and a margin for a late
                    // worker). A held result is left alone only if it will still be there then; the cache never lives past 24
                    // hours, so in practice every location is refreshed each day and none loses its numbers in between.
                    if ($performance->remainsAvailableThrough($binding, CarbonImmutable::now('UTC')->addDay()->addSeconds($delay + self::LATE_WORKER_MARGIN))) {
                        continue;
                    }

                    RefreshGoogleBusinessProfilePerformance::dispatch((int) $binding->id)->delay(now()->addSeconds($delay));
                }
            });
    }
}
