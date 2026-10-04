<?php

namespace App\Jobs\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
use App\Enums\GoogleBusinessProfile\GoogleConnectionState;
use App\Enums\GoogleBusinessProfile\GoogleOperationStatus;
use App\Enums\GoogleBusinessProfile\GoogleOperationType;
use App\Jobs\Base;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Library\GoogleAds\Sync\GoogleAdsSyncDispatcher;
use App\Library\GoogleAds\Sync\GoogleAdsSyncEligibility;
use App\Models\BusinessGoogleOperation;
use App\Models\GoogleAdsAccount;
use Illuminate\Support\Carbon;

/**
 * Google Ads Module V1 contract §5 / §14 — the AT MOST DAILY, STAGGERED sweep
 * that keeps a connected account from silently rotting (modelled on
 * SweepGoogleBusinessProfileRefreshes).
 *
 * It only QUEUES work. Per account it re-checks eligibility, then asks the
 * dispatcher, which refuses when a claim or a queued/running run already
 * exists, so overlapping sweeps never double-queue. Each sync is delayed by a
 * deterministic per-account offset so a large tenant base spreads across the
 * hour instead of arriving as a burst against the shared Google quota.
 *
 * The project-level circuit breaker is read from the operation ledger: when
 * the most recent N `ads_sync` operations inside the cool-down window were
 * ALL deferred or provider-unavailable, the whole sweep is skipped.
 */
class SweepGoogleAdsSyncs extends Base
{
    private const CHUNK = 100;

    /** The stagger window, in seconds. */
    public const STAGGER_WINDOW = 3600;

    public function handle(GoogleAdsConfig $config, GoogleAdsSyncEligibility $eligibility, GoogleAdsSyncDispatcher $dispatcher): void
    {
        if ($this->breakerTripped($config)) {
            return;
        }

        $cutoff = now()->subHours($config->syncMinIntervalHours());

        GoogleAdsAccount::query()
            ->whereHas('connection', function ($query): void {
                $query->where('product', GoogleConnectionProduct::GoogleAds->value)
                    ->where('state', GoogleConnectionState::Active->value);
            })
            ->whereNotNull('selected_at')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_successful_sync_at')->orWhere('last_successful_sync_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($accounts) use ($eligibility, $dispatcher): void {
                foreach ($accounts as $account) {
                    if (! $eligibility->evaluate((int) $account->id)->isAllowed()) {
                        continue;
                    }

                    $dispatcher->queue($account, GoogleAdsSyncTrigger::Scheduled, null, self::staggerFor((int) $account->id));
                }
            });
    }

    public static function staggerFor(int $accountId): int
    {
        return ($accountId * 7) % self::STAGGER_WINDOW;
    }

    private function breakerTripped(GoogleAdsConfig $config): bool
    {
        $threshold = $config->breakerThreshold();

        $recent = BusinessGoogleOperation::query()
            ->where('operation_type', GoogleOperationType::AdsSync->value)
            ->where('created_at', '>=', Carbon::now()->subMinutes($config->breakerCooldownMinutes()))
            ->orderByDesc('id')
            ->limit($threshold)
            ->get(['status', 'failure_classification']);

        if ($recent->count() < $threshold) {
            return false;
        }

        foreach ($recent as $operation) {
            $backPressure = $operation->status === GoogleOperationStatus::Deferred
                || $operation->failure_classification === BusinessGoogleOperation::FAILURE_PROVIDER_UNAVAILABLE;

            if (! $backPressure) {
                return false;
            }
        }

        return true;
    }
}
