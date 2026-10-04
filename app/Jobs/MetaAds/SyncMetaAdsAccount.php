<?php

namespace App\Jobs\MetaAds;

use App\Enums\MetaAds\MetaAdsSyncRunState;
use App\Enums\MetaAds\MetaAdsSyncTrigger;
use App\Exceptions\MetaAds\MetaProviderException;
use App\Jobs\Base;
use App\Library\MetaAds\Sync\MetaAdsSyncCoordinator;
use App\Library\MetaAds\Sync\MetaAdsSyncDispatcher;
use App\Library\MetaAds\Sync\MetaAdsSyncEligibility;
use App\Library\MetaAds\Sync\MetaAdsSyncFailureCode;
use App\Library\MetaAds\Sync\MetaAdsSyncGuard;
use App\Models\MetaAdsAccount;
use App\Models\MetaAdsSyncRun;

/**
 * Meta Ads Module V1 contract 24 §6 — syncs ONE Meta ad account.
 *
 * App\Jobs\Base sets tries = 1, so there is no automatic provider retry: the
 * next sweep (or a throttled manual refresh) is the retry.
 *
 * Before ANY provider call the job RE-FETCHES the account and re-checks
 * Business / workspace / entitlement / connection / token / Meta identity
 * (MetaAdsSyncEligibility), then takes the account claim so two jobs never run
 * for one account. The claim is released in `finally` whatever happens, but
 * only while it is still this job's (a claim taken over after going stale is
 * never released here).
 *
 * Provider failures are already classified, ledgered and stored on the run by
 * the coordinator, so they end the job quietly (re-throwing would only add a
 * failed_jobs row; tries = 1 means it could not retry anyway). Anything else
 * is a programming error: the coordinator finalises the run and the exception
 * propagates to the queue.
 */
class SyncMetaAdsAccount extends Base
{
    public function __construct(
        public readonly int $accountId,
        public readonly MetaAdsSyncTrigger $trigger = MetaAdsSyncTrigger::Scheduled,
        public readonly ?int $actorUserId = null,
        public readonly ?int $syncRunId = null,
    ) {
    }

    public function handle(
        MetaAdsSyncEligibility $eligibility,
        MetaAdsSyncGuard $guard,
        MetaAdsSyncCoordinator $coordinator,
        MetaAdsSyncDispatcher $dispatcher,
    ): void {
        $run = $this->queuedRun($dispatcher);

        if ($run === null) {
            return;
        }

        $check = $eligibility->evaluate($this->accountId);

        if (! $check->isAllowed()) {
            // An active connection whose token ran out is moved to `expired` here (no provider call).
            $eligibility->retireExpiredToken($check);
            $coordinator->skip($run, MetaAdsSyncFailureCode::NOT_SYNCABLE);

            return;
        }

        $claim = $guard->acquire($this->accountId);

        if ($claim === null) {
            $coordinator->skip($run, MetaAdsSyncFailureCode::ALREADY_RUNNING);

            return;
        }

        try {
            $coordinator->execute($check->account, $check->connection, $run, $this->actorUserId, $claim);
        } catch (MetaProviderException) {
            // Already classified, ledgered and stored on the run.
        } finally {
            $guard->release($claim);
        }
    }

    /**
     * The run this job executes: the one it was queued with (which must still
     * be `queued`), or a fresh one when dispatched without a run row.
     */
    private function queuedRun(MetaAdsSyncDispatcher $dispatcher): ?MetaAdsSyncRun
    {
        if ($this->syncRunId === null) {
            $account = MetaAdsAccount::query()->find($this->accountId);

            return $account === null ? null : $dispatcher->createQueuedRun($account, $this->trigger);
        }

        $run = MetaAdsSyncRun::query()
            ->where('id', $this->syncRunId)
            ->where('meta_ads_account_id', $this->accountId)
            ->first();

        return $run !== null && $run->state === MetaAdsSyncRunState::Queued ? $run : null;
    }
}
