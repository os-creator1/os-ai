<?php

namespace App\Library\MetaAds\Sync;

use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Jobs\MetaAds\SyncMetaAdsAccount;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsSyncRun;
use Illuminate\Support\Facades\DB;

/**
 * The one place a Meta sync is QUEUED (sweep, manual refresh, connect all come
 * through here). It creates the `queued` meta_ads_sync_runs row and dispatches
 * the job, so "is a sync already waiting?" is answerable from the database
 * and a second request cannot enqueue a duplicate.
 *
 * Deduplication is race-free: the account row is locked for the check and the
 * insert, so two simultaneous callers serialise and the loser sees the
 * winner's queued run. The dispatch itself happens after the transaction.
 * No provider call is made here.
 */
final class MetaAdsSyncDispatcher
{
    public function __construct(private readonly MetaAdsSyncGuard $guard)
    {
    }

    /**
     * @return MetaAdsSyncRun|null the queued run, or null when a sync is already live for the account
     */
    public function queue(
        MetaAdsAccount $account,
        MetaAdsSyncTrigger $trigger,
        ?int $actorUserId = null,
        int $delaySeconds = 0,
    ): ?MetaAdsSyncRun {
        $run = DB::transaction(function () use ($account, $trigger): ?MetaAdsSyncRun {
            DB::table('meta_ads_accounts')->where('id', $account->id)->lockForUpdate()->first();

            $this->retireLostQueuedRuns($account);

            return $this->guard->hasLiveWork($account) ? null : $this->createQueuedRun($account, $trigger);
        });

        if ($run === null) {
            return null;
        }

        $job = new SyncMetaAdsAccount((int) $account->id, $trigger, $actorUserId, (int) $run->id);

        if ($delaySeconds > 0) {
            $job->delay(now()->addSeconds($delaySeconds));
        }

        dispatch($job);

        return $run;
    }

    public function createQueuedRun(MetaAdsAccount $account, MetaAdsSyncTrigger $trigger): MetaAdsSyncRun
    {
        return MetaAdsSyncRun::create([
            'business_id' => $account->business_id,
            'meta_ads_account_id' => $account->id,
            'state' => MetaAdsSyncRunState::Queued,
            'trigger' => $trigger,
        ]);
    }

    /** A queued run whose job never ran must not block the account forever. */
    private function retireLostQueuedRuns(MetaAdsAccount $account): void
    {
        DB::table('meta_ads_sync_runs')
            ->where('meta_ads_account_id', $account->id)
            ->where('state', MetaAdsSyncRunState::Queued->value)
            ->where('created_at', '<', now()->subMinutes(MetaAdsSyncGuard::QUEUED_RUN_WINDOW_MINUTES))
            ->update([
                'state' => MetaAdsSyncRunState::Skipped->value,
                'failure_code' => MetaAdsSyncFailureCode::EXPIRED,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
