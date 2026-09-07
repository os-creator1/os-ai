<?php

namespace App\Jobs\GoogleBusinessProfile;

use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Jobs\Base;
use App\Models\BusinessGoogleLocation;
use App\Models\BusinessGoogleOperation;
use Illuminate\Support\Carbon;

/**
 * GBP Slice A contract §24.2 / §24.3 — the AT MOST DAILY, STAGGERED
 * background refresh.
 *
 * Manual refresh is the primary mechanism; this exists so a connected
 * profile does not silently rot. There is NO higher-frequency polling of
 * any kind: Google explicitly denies quota increases to applications
 * showing "a highly spiky request pattern rather than a smooth
 * distribution", so staggering is a quota-preservation requirement, not a
 * nicety.
 *
 * Bindings are processed with chunkById so a large tenant never loads
 * every binding into memory, and each is dispatched as its own
 * RefreshGoogleBusinessProfileMirror with a deterministic per-binding
 * delay. That also survives the shared-hosting cron reality, which runs
 * `queue:work --stop-when-empty --max-time=180` every minute: a single
 * long "refresh everything" job would not, and is forbidden.
 */
class SweepGoogleBusinessProfileRefreshes extends Base
{
    private const CHUNK = 100;

    /** Contract §24.2 — the stagger window, in seconds. */
    private const STAGGER_WINDOW = 3600;

    public function handle(): void
    {
        if ($this->breakerTripped()) {
            // Contract §24.3 — the project-level circuit breaker. The
            // 300 QPM Google quota is per Cloud project and shared across
            // every customer, so a per-tenant limit cannot protect it.
            return;
        }

        $cutoff = now()->subHours($this->minIntervalHours());

        BusinessGoogleLocation::query()
            ->whereHas('connection', function ($query) {
                $query->where('state', GoogleConnectionState::Active->value);
            })
            ->where(function ($query) use ($cutoff) {
                $query->whereNull('last_synced_at')->orWhere('last_synced_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($bindings) {
                foreach ($bindings as $binding) {
                    RefreshGoogleBusinessProfileMirror::dispatch((int) $binding->id)
                        ->delay(now()->addSeconds($this->staggerFor((int) $binding->id)));
                }
            });
    }

    /**
     * Contract §24.2 — deterministic per-binding stagger, so a large
     * tenant's bindings spread across the hour rather than arriving as a
     * burst.
     */
    private function staggerFor(int $bindingId): int
    {
        return ($bindingId * 7) % self::STAGGER_WINDOW;
    }

    /**
     * Contract §24.2 — a configured value below 24 is REJECTED and 24 is
     * used. Validated with the house idiom, never trusted as a raw env
     * string.
     */
    private function minIntervalHours(): int
    {
        $configured = config('google_business_profile.sync.min_interval_hours');

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return 24;
        }

        $hours = (int) $configured;

        return $hours >= 24 ? $hours : 24;
    }

    /**
     * Contract §24.3 — trips after N consecutive deferred (429) or
     * provider-unavailable outcomes ACROSS ALL TENANTS within the cool-down
     * window, and suppresses background refreshes until the window passes.
     *
     * Read from the ledger rather than a cache: GBP introduces no
     * cross-request cache (contract §31, test T-CACHE-1), and the ledger
     * already records exactly these outcomes.
     */
    private function breakerTripped(): bool
    {
        $threshold = $this->positiveConfig('google_business_profile.sync.breaker_threshold', 20);
        $cooldown = $this->positiveConfig('google_business_profile.sync.breaker_cooldown_minutes', 30);

        $recent = BusinessGoogleOperation::query()
            ->where('created_at', '>=', Carbon::now()->subMinutes($cooldown))
            ->orderByDesc('id')
            ->limit($threshold)
            ->get(['status', 'failure_classification']);

        if ($recent->count() < $threshold) {
            return false;
        }

        foreach ($recent as $operation) {
            $isBackPressure = $operation->status === GoogleOperationStatus::Deferred
                || $operation->failure_classification === BusinessGoogleOperation::FAILURE_PROVIDER_UNAVAILABLE;

            if (! $isBackPressure) {
                return false;
            }
        }

        return true;
    }

    private function positiveConfig(string $key, int $default): int
    {
        $configured = config($key);

        if (! is_int($configured) && ! (is_string($configured) && ctype_digit($configured))) {
            return $default;
        }

        $value = (int) $configured;

        return $value > 0 ? $value : $default;
    }
}
