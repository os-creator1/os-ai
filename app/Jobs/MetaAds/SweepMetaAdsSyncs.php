<?php

namespace App\Jobs\MetaAds;

use App\Enums\MetaAds\MetaConnectionState;
use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Enums\MetaAds\MetaOperationStatus;
use App\Enums\MetaAds\MetaOperationType;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Jobs\Base;
use App\Library\MetaAds\MetaAdsConfig;
use App\Library\MetaAds\Sync\MetaAdsSyncDispatcher;
use App\Library\MetaAds\Sync\MetaAdsSyncEligibility;
use App\Models\BusinessMetaOperation;
use App\Models\MetaAdsAccount;
use Illuminate\Support\Carbon;

/**
 * Meta Ads Module V1 contract 24 §6 — the AT MOST DAILY, STAGGERED sweep that
 * keeps a connected Meta account from silently rotting (mirror of
 * SweepGoogleAdsSyncs).
 *
 * It only QUEUES work. Per account it re-checks eligibility (a selected
 * account, an active connection, entitlement, ...), then asks the dispatcher,
 * which refuses when a claim or a queued/running run already exists, so
 * overlapping sweeps never double-queue. Each sync is delayed by a
 * deterministic per-account offset so a large tenant base spreads across the
 * hour instead of arriving as a burst against Meta's app-level quota.
 *
 * "New vs stale": an account is picked when it has never completed a sync or
 * its last COMPLETE sync is older than sync.min_interval_hours (floored at 20
 * h). A partial run does not advance that stamp, so it is retried by the next
 * sweep.
 *
 * The project-level circuit breaker is read from the Meta operation ledger:
 * when the most recent N `meta_ads_sync` operations inside the cool-down
 * window were ALL deferred or provider-unavailable, the whole sweep is skipped.
 */
class SweepMetaAdsSyncs extends Base
{
    private const CHUNK = 100;

    /** The stagger window, in seconds. */
    public const STAGGER_WINDOW = 3600;

    public function handle(MetaAdsConfig $config, MetaAdsSyncEligibility $eligibility, MetaAdsSyncDispatcher $dispatcher): void
    {
        if ($this->breakerTripped($config)) {
            return;
        }

        $cutoff = now()->subHours($config->syncMinIntervalHours());

        MetaAdsAccount::query()
            ->whereHas('connection', function ($query): void {
                $query->where('state', MetaConnectionState::Active->value);
            })
            ->whereNotNull('selected_at')
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('last_successful_sync_at')->orWhere('last_successful_sync_at', '<', $cutoff);
            })
            ->orderBy('id')
            ->chunkById(self::CHUNK, function ($accounts) use ($eligibility, $dispatcher): void {
                foreach ($accounts as $account) {
                    $check = $eligibility->evaluate((int) $account->id);

                    if (! $check->isAllowed()) {
                        $eligibility->retireExpiredToken($check);

                        continue;
                    }

                    $dispatcher->queue($account, MetaAdsSyncTrigger::Scheduled, null, self::staggerFor((int) $account->id));
                }
            });
    }

    public static function staggerFor(int $accountId): int
    {
        return ($accountId * 7) % self::STAGGER_WINDOW;
    }

    private function breakerTripped(MetaAdsConfig $config): bool
    {
        $threshold = $config->breakerThreshold();

        $recent = BusinessMetaOperation::query()
            ->where('operation_type', MetaOperationType::MetaAdsSync->value)
            ->where('created_at', '>=', Carbon::now()->subMinutes($config->breakerCooldownMinutes()))
            ->orderByDesc('id')
            ->limit($threshold)
            ->get(['status', 'failure_classification']);

        if ($recent->count() < $threshold) {
            return false;
        }

        foreach ($recent as $operation) {
            $backPressure = $operation->status === MetaOperationStatus::Deferred
                || $operation->failure_classification === MetaProviderException::PROVIDER_UNAVAILABLE;

            if (! $backPressure) {
                return false;
            }
        }

        return true;
    }
}
