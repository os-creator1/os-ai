<?php

namespace App\Library\MetaAds\Sync;

use App\Library\MetaAds\MetaAdsConfig;
use App\Models\MetaAdsAccount;
use Carbon\CarbonInterface;

/**
 * Meta Ads Module V1 contract 24 §6 — the pure READ answer to "how fresh is
 * what this page is showing?". No provider call, no write; every Meta Ads page
 * uses it for "Updated {relative}", "Data through {date}" and the warning.
 *
 * Only a COMPLETE run advances last_successful_sync_at, so a partial run
 * (row_cap / usage_high) leaves the previous stamp in place and its code
 * shows as a warning.
 *
 * Precedence: running > never_synced > failed_with_data > stale > fresh.
 * `isStale` is true when a complete sync exists and is older than twice
 * sync.min_interval_hours (independent of a later failure); it is false when
 * nothing ever completed. `lastFailureCode` is the stored safe code only
 * (anything unrecognised is shown as `unknown`), `lastFailureLabel` its
 * owner-facing text.
 */
final class MetaAdsFreshness
{
    public function __construct(
        private readonly MetaAdsConfig $config,
        private readonly MetaAdsSyncGuard $guard,
    ) {
    }

    public function for(MetaAdsAccount $account): MetaAdsFreshnessSnapshot
    {
        $last = $account->last_successful_sync_at;
        $through = $account->data_through_date;
        $code = $account->last_sync_failure_code;
        $code = MetaAdsSyncFailureCode::isKnown($code) ? $code : ($code === null ? null : 'unknown');

        $isStale = $last instanceof CarbonInterface
            && $last->lt(now()->subHours(2 * $this->config->syncMinIntervalHours()));

        $state = match (true) {
            $this->guard->hasLiveWork($account) => MetaAdsFreshnessState::Running,
            $last === null => MetaAdsFreshnessState::NeverSynced,
            $code !== null => MetaAdsFreshnessState::FailedWithData,
            $isStale => MetaAdsFreshnessState::Stale,
            default => MetaAdsFreshnessState::Fresh,
        };

        return new MetaAdsFreshnessSnapshot(
            state: $state,
            lastSuccessfulSyncAt: $last,
            dataThroughDate: $through,
            lastFailureCode: $code,
            lastFailureLabel: MetaAdsSyncFailureCode::label($code),
            isStale: $isStale,
        );
    }
}
