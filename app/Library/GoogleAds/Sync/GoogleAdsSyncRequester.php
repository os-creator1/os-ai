<?php

namespace App\Library\GoogleAds\Sync;

use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Library\GoogleAds\GoogleAdsConfig;
use App\Models\GoogleAdsAccount;
use Illuminate\Support\Facades\DB;

/**
 * Google Ads Module V1 contract §5 / §14 — the entry point controllers use to
 * ask for a sync. It NEVER calls Google: it validates, throttles, records the
 * queued run and dispatches the job (GoogleAdsSyncDispatcher).
 *
 * requestManual():  owner pressed Refresh. Per-Business throttle
 *   (`manual_refresh_requested_at`, config manual_refresh_min_minutes), and a
 *   last successful sync younger than that window is reused (FreshEnough) so
 *   mashing the button costs zero provider calls.
 * requestInitial(): right after connect / account selection. Not throttled,
 *   still deduplicated.
 *
 * The caller has already authorised the actor (`manage_google_ads`).
 */
final class GoogleAdsSyncRequester
{
    public function __construct(
        private readonly GoogleAdsConfig $config,
        private readonly GoogleAdsSyncEligibility $eligibility,
        private readonly GoogleAdsSyncGuard $guard,
        private readonly GoogleAdsSyncDispatcher $dispatcher,
    ) {
    }

    public function requestManual(GoogleAdsAccount $account, int $actorUserId): GoogleAdsSyncRequestResult
    {
        $check = $this->eligibility->evaluate((int) $account->id);

        if (! $check->isAllowed()) {
            return GoogleAdsSyncRequestResult::notSyncable();
        }

        $account = $check->account;
        $window = $this->config->manualRefreshMinMinutes();

        if ($account->last_successful_sync_at !== null && $account->last_successful_sync_at->gt(now()->subMinutes($window))) {
            return GoogleAdsSyncRequestResult::freshEnough($account->last_successful_sync_at->copy()->addMinutes($window));
        }

        if ($this->guard->hasLiveWork($account)) {
            return GoogleAdsSyncRequestResult::alreadyRunning();
        }

        $previous = $account->manual_refresh_requested_at;

        // One atomic UPDATE takes the throttle slot, so two simultaneous
        // clicks cannot both be accepted.
        $took = DB::table('google_ads_accounts')
            ->where('id', $account->id)
            ->where(static function ($query) use ($window): void {
                $query->whereNull('manual_refresh_requested_at')
                    ->orWhere('manual_refresh_requested_at', '<=', now()->subMinutes($window));
            })
            ->update(['manual_refresh_requested_at' => now()]);

        if ($took !== 1) {
            $requestedAt = DB::table('google_ads_accounts')->where('id', $account->id)->value('manual_refresh_requested_at');

            return GoogleAdsSyncRequestResult::throttled(
                now()->parse((string) $requestedAt)->addMinutes($window),
            );
        }

        $run = $this->dispatcher->queue($account, GoogleAdsSyncTrigger::Manual, $actorUserId);

        if ($run === null) {
            // Lost a race with another queued sync: give the slot back.
            DB::table('google_ads_accounts')->where('id', $account->id)->update(['manual_refresh_requested_at' => $previous]);

            return GoogleAdsSyncRequestResult::alreadyRunning();
        }

        return GoogleAdsSyncRequestResult::queued($run);
    }

    public function requestInitial(GoogleAdsAccount $account, ?int $actorUserId = null): GoogleAdsSyncRequestResult
    {
        $check = $this->eligibility->evaluate((int) $account->id);

        if (! $check->isAllowed()) {
            return GoogleAdsSyncRequestResult::notSyncable();
        }

        $run = $this->dispatcher->queue($check->account, GoogleAdsSyncTrigger::Connect, $actorUserId);

        return $run === null ? GoogleAdsSyncRequestResult::alreadyRunning() : GoogleAdsSyncRequestResult::queued($run);
    }
}
