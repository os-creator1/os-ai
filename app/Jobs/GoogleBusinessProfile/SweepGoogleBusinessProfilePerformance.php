<?php

namespace App\Jobs\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Jobs\Base;
use App\Library\GoogleBusinessProfilePerformance\GoogleBusinessProfilePerformanceLoader;
use App\Models\BusinessGoogleLocation;

/**
 * The daily, STAGGERED refresh of every active linked location's Google performance, so the page opens with current numbers.
 *
 * It only dispatches one RefreshGoogleBusinessProfilePerformance per location with a deterministic delay across the hour (Google
 * denies quota increases to a "highly spiky request pattern"), and honours the same project-level circuit breaker as the mirror
 * sweep. A location that already holds a result read within the last 20 hours is skipped, so a visit and the sweep never duplicate
 * a read; each job re-checks that and the connection claim itself.
 */
class SweepGoogleBusinessProfilePerformance extends Base
{
    private const CHUNK = 100;

    private const STAGGER_WINDOW = 3600;

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
                    if ($performance->isFresh($binding)) {
                        continue;
                    }

                    RefreshGoogleBusinessProfilePerformance::dispatch((int) $binding->id)
                        ->delay(now()->addSeconds(((int) $binding->id * 13) % self::STAGGER_WINDOW));
                }
            });
    }
}
