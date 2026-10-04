<?php

namespace App\Jobs\GoogleAds;

use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Enums\GoogleAds\GoogleAdsSyncTrigger;
use App\Exceptions\GoogleAds\GoogleAdsProviderException;
use App\Jobs\Base;
use App\Library\GoogleAds\Sync\GoogleAdsSyncCoordinator;
use App\Library\GoogleAds\Sync\GoogleAdsSyncDispatcher;
use App\Library\GoogleAds\Sync\GoogleAdsSyncEligibility;
use App\Library\GoogleAds\Sync\GoogleAdsSyncFailureCode;
use App\Library\GoogleAds\Sync\GoogleAdsSyncGuard;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSyncRun;

/**
 * Google Ads Module V1 contract §5 — syncs ONE Ads account.
 *
 * App\Jobs\Base sets tries = 1, so there is no automatic provider retry: the
 * next sweep (or a throttled manual refresh) is the retry.
 *
 * Before ANY provider call the job RE-FETCHES the account and re-checks
 * Business / workspace / entitlement / connection (GoogleAdsSyncEligibility),
 * then takes the account claim so two jobs never run for one account. The
 * claim is released in `finally` whatever happens, but only while it is still
 * this job's (a claim taken over after going stale is never released here).
 *
 * Provider failures are already classified, ledgered and stored on the run by
 * the coordinator, so they end the job quietly (re-throwing would only add a
 * failed_jobs row; tries = 1 means it could not retry anyway). Anything else
 * is a programming error: the coordinator finalises the run and the exception
 * propagates to the queue.
 */
class SyncGoogleAdsAccount extends Base
{
    public function __construct(
        public readonly int $accountId,
        public readonly GoogleAdsSyncTrigger $trigger = GoogleAdsSyncTrigger::Scheduled,
        public readonly ?int $actorUserId = null,
        public readonly ?int $syncRunId = null,
    ) {
    }

    public function handle(
        GoogleAdsSyncEligibility $eligibility,
        GoogleAdsSyncGuard $guard,
        GoogleAdsSyncCoordinator $coordinator,
        GoogleAdsSyncDispatcher $dispatcher,
    ): void {
        $run = $this->queuedRun($dispatcher);

        if ($run === null) {
            return;
        }

        $check = $eligibility->evaluate($this->accountId);

        if (! $check->isAllowed()) {
            $coordinator->skip($run, GoogleAdsSyncFailureCode::NOT_SYNCABLE);

            return;
        }

        $claim = $guard->acquire($this->accountId);

        if ($claim === null) {
            $coordinator->skip($run, GoogleAdsSyncFailureCode::ALREADY_RUNNING);

            return;
        }

        try {
            $coordinator->execute($check->account, $check->connection, $run, $this->actorUserId, $claim);
        } catch (GoogleAdsProviderException) {
            // Already classified, ledgered and stored on the run.
        } finally {
            $guard->release($claim);
        }
    }

    /**
     * The run this job executes: the one it was queued with (which must still
     * be `queued`), or a fresh one when dispatched without a run row.
     */
    private function queuedRun(GoogleAdsSyncDispatcher $dispatcher): ?GoogleAdsSyncRun
    {
        if ($this->syncRunId === null) {
            $account = GoogleAdsAccount::query()->find($this->accountId);

            return $account === null ? null : $dispatcher->createQueuedRun($account, $this->trigger);
        }

        $run = GoogleAdsSyncRun::query()
            ->where('id', $this->syncRunId)
            ->where('google_ads_account_id', $this->accountId)
            ->first();

        return $run !== null && $run->state === GoogleAdsSyncRunState::Queued ? $run : null;
    }
}
