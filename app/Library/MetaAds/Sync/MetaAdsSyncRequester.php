<?php

namespace App\Library\MetaAds\Sync;

use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Library\MetaAds\MetaAdsConfig;
use App\Models\MetaAdsAccount;
use Illuminate\Support\Facades\DB;

/**
 * Meta Ads Module V1 contract 24 §6 — the entry point controllers use to ask
 * for a sync. It NEVER calls Meta: it validates, throttles, records the queued
 * run and dispatches the job (MetaAdsSyncDispatcher).
 *
 * requestManual():  owner pressed Refresh. Per-Business throttle
 *   (`manual_refresh_requested_at`, config manual_refresh_min_minutes), and a
 *   last successful sync younger than that window is reused (FreshEnough) so
 *   mashing the button costs zero provider calls.
 * requestInitial(): right after connect / account selection. Not throttled,
 *   still deduplicated (trigger `connect`).
 *
 * The caller has already authorised the actor (`manage_meta_ads`).
 */
final class MetaAdsSyncRequester
{
    public function __construct(
        private readonly MetaAdsConfig $config,
        private readonly MetaAdsSyncEligibility $eligibility,
        private readonly MetaAdsSyncGuard $guard,
        private readonly MetaAdsSyncDispatcher $dispatcher,
    ) {
    }

    public function requestManual(MetaAdsAccount $account, int $actorUserId): MetaAdsSyncRequestResult
    {
        $check = $this->eligibility->evaluate((int) $account->id);

        if (! $check->isAllowed()) {
            return MetaAdsSyncRequestResult::notSyncable();
        }

        $account = $check->account;
        $window = $this->config->manualRefreshMinMinutes();

        if ($account->last_successful_sync_at !== null && $account->last_successful_sync_at->gt(now()->subMinutes($window))) {
            return MetaAdsSyncRequestResult::freshEnough($account->last_successful_sync_at->copy()->addMinutes($window));
        }

        if ($this->guard->hasLiveWork($account)) {
            return MetaAdsSyncRequestResult::alreadyRunning();
        }

        $previous = $account->manual_refresh_requested_at;

        // One atomic UPDATE takes the throttle slot, so two simultaneous
        // clicks cannot both be accepted.
        $took = DB::table('meta_ads_accounts')
            ->where('id', $account->id)
            ->where(static function ($query) use ($window): void {
                $query->whereNull('manual_refresh_requested_at')
                    ->orWhere('manual_refresh_requested_at', '<=', now()->subMinutes($window));
            })
            ->update(['manual_refresh_requested_at' => now()]);

        if ($took !== 1) {
            $requestedAt = DB::table('meta_ads_accounts')->where('id', $account->id)->value('manual_refresh_requested_at');

            return MetaAdsSyncRequestResult::throttled(
                now()->parse((string) $requestedAt)->addMinutes($window),
            );
        }

        $run = $this->dispatcher->queue($account, MetaAdsSyncTrigger::Manual, $actorUserId);

        if ($run === null) {
            // Lost a race with another queued sync: give the slot back.
            DB::table('meta_ads_accounts')->where('id', $account->id)->update(['manual_refresh_requested_at' => $previous]);

            return MetaAdsSyncRequestResult::alreadyRunning();
        }

        return MetaAdsSyncRequestResult::queued($run);
    }

    public function requestInitial(MetaAdsAccount $account, ?int $actorUserId = null): MetaAdsSyncRequestResult
    {
        $check = $this->eligibility->evaluate((int) $account->id);

        if (! $check->isAllowed()) {
            return MetaAdsSyncRequestResult::notSyncable();
        }

        $run = $this->dispatcher->queue($check->account, MetaAdsSyncTrigger::Connect, $actorUserId);

        return $run === null ? MetaAdsSyncRequestResult::alreadyRunning() : MetaAdsSyncRequestResult::queued($run);
    }
}
