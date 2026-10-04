<?php

namespace App\Library\GoogleAds\Sync;

use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Jobs\GoogleAds\SyncGoogleAdsAccount;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSyncRun;
use Illuminate\Support\Facades\DB;

/**
 * The one place a sync is QUEUED (sweep, manual refresh, connect, after-select
 * all come through here). It creates the `queued` google_ads_sync_runs row
 * and dispatches the job, so "is a sync already waiting?" is answerable from
 * the database and a second request cannot enqueue a duplicate.
 *
 * Deduplication is race-free: the account row is locked for the check and the
 * insert, so two simultaneous callers serialise and the loser sees the
 * winner's queued run. The dispatch itself happens after the transaction.
 * No provider call is made here.
 */
final class GoogleAdsSyncDispatcher
{
    public function __construct(private readonly GoogleAdsSyncGuard $guard)
    {
    }

    /**
     * @return GoogleAdsSyncRun|null the queued run, or null when a sync is already live for the account
     */
    public function queue(
        GoogleAdsAccount $account,
        GoogleAdsSyncTrigger $trigger,
        ?int $actorUserId = null,
        int $delaySeconds = 0,
    ): ?GoogleAdsSyncRun {
        $run = DB::transaction(function () use ($account, $trigger): ?GoogleAdsSyncRun {
            DB::table('google_ads_accounts')->where('id', $account->id)->lockForUpdate()->first();

            $this->retireLostQueuedRuns($account);

            return $this->guard->hasLiveWork($account) ? null : $this->createQueuedRun($account, $trigger);
        });

        if ($run === null) {
            return null;
        }

        $job = new SyncGoogleAdsAccount((int) $account->id, $trigger, $actorUserId, (int) $run->id);

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }

        dispatch($job);

        return $run;
    }

    public function createQueuedRun(GoogleAdsAccount $account, GoogleAdsSyncTrigger $trigger): GoogleAdsSyncRun
    {
        return GoogleAdsSyncRun::create([
            'business_id' => $account->business_id,
            'google_ads_account_id' => $account->id,
            'state' => GoogleAdsSyncRunState::Queued,
            'trigger' => $trigger,
        ]);
    }

    /** A queued run whose job never ran must not block the account forever. */
    private function retireLostQueuedRuns(GoogleAdsAccount $account): void
    {
        DB::table('google_ads_sync_runs')
            ->where('google_ads_account_id', $account->id)
            ->where('state', GoogleAdsSyncRunState::Queued->value)
            ->where('created_at', '<', now()->subMinutes(GoogleAdsSyncGuard::QUEUED_RUN_WINDOW_MINUTES))
            ->update([
                'state' => GoogleAdsSyncRunState::Skipped->value,
                'failure_code' => GoogleAdsSyncFailureCode::EXPIRED,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
