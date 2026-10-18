<?php

namespace App\Library\GoogleAds\Sync;

use App\Library\GoogleAds\GoogleAdsConfig;
use App\Models\GoogleAdsAccount;
use Carbon\CarbonInterface;

/**
 * Google Ads Module V1 contract §5 / §16 — the pure READ answer to "how fresh
 * is what this page is showing?". No provider call, no write; every Ads page
 * uses it for "Updated {relative}", "Data through {date}" and the warning.
 *
 * Precedence: running > never_synced > failed_with_data > stale > fresh.
 * `isStale` is true when a successful sync exists and is older than twice
 * sync.min_interval_hours (independent of a later failure); it is false when
 * nothing ever synced (there is no data to be stale). `lastFailureCode` is
 * the stored safe code only, and `lastFailureLabel` its owner-facing text.
 */
final class GoogleAdsFreshness
{
    public function __construct(
        private readonly GoogleAdsConfig $config,
        private readonly GoogleAdsSyncGuard $guard,
    ) {
    }

    public function for(GoogleAdsAccount $account): GoogleAdsFreshnessSnapshot
    {
        $last = $account->last_successful_sync_at;
        $through = $account->data_through_date;
        $code = $account->last_sync_failure_code;
        $code = GoogleAdsSyncFailureCode::isKnown($code) ? $code : ($code === null ? null : 'unknown');

        $isStale = $last instanceof CarbonInterface
            && $last->lt(now()->subHours(2 * $this->config->syncMinIntervalHours()));

        $state = match (true) {
            $this->guard->hasLiveWork($account) => GoogleAdsFreshnessState::Running,
            $last === null => GoogleAdsFreshnessState::NeverSynced,
            $code !== null => GoogleAdsFreshnessState::FailedWithData,
            $isStale => GoogleAdsFreshnessState::Stale,
            default => GoogleAdsFreshnessState::Fresh,
        };

        return new GoogleAdsFreshnessSnapshot(
            state: $state,
            lastSuccessfulSyncAt: $last,
            dataThroughDate: $through,
            lastFailureCode: $code,
            lastFailureLabel: GoogleAdsSyncFailureCode::label($code),
            isStale: $isStale,
        );
    }
}
