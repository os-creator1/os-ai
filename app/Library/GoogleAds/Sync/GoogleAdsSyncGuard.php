<?php

namespace App\Library\GoogleAds\Sync;

use App\Enums\GoogleAds\GoogleAdsSyncRunState;
use App\Models\GoogleAdsAccount;
use App\Models\GoogleAdsSyncRun;
use Illuminate\Support\Facades\DB;

/**
 * Contract §5 / §14 — "one sync per account at a time".
 *
 * The claim is `google_ads_accounts.sync_claimed_at`, taken with ONE atomic
 * conditional UPDATE (so two workers can never both win) and considered
 * abandoned after CLAIM_STALE_MINUTES (a dead worker can never wedge an
 * account). It mirrors the GBP connection refresh claim, on the Ads account.
 *
 * hasLiveWork() is the wider "is anything already in flight or about to be"
 * question the sweep, the requester and the freshness reader all ask: a live
 * claim, a run that is `running`, or a `queued` run whose job is plausibly
 * still waiting in the queue.
 */
final class GoogleAdsSyncGuard
{
    public const CLAIM_STALE_MINUTES = 15;

    /** A queued run older than this is treated as lost (its job never ran). */
    public const QUEUED_RUN_WINDOW_MINUTES = 180;

    /** True only for the single caller that wins the account. */
    public function acquire(int $accountId): bool
    {
        $affected = DB::table('google_ads_accounts')
            ->where('id', $accountId)
            ->where(static function ($query): void {
                $query->whereNull('sync_claimed_at')
                    ->orWhere('sync_claimed_at', '<', now()->subMinutes(self::CLAIM_STALE_MINUTES));
            })
            ->update(['sync_claimed_at' => now()]);

        if ($affected !== 1) {
            return false;
        }

        // We hold the claim, so any `running` row older than the stale window
        // belongs to a dead worker. Retire it so it cannot read as live forever.
        DB::table('google_ads_sync_runs')
            ->where('google_ads_account_id', $accountId)
            ->where('state', GoogleAdsSyncRunState::Running->value)
            ->where('started_at', '<', now()->subMinutes(self::CLAIM_STALE_MINUTES))
            ->update([
                'state' => GoogleAdsSyncRunState::Failed->value,
                'failure_code' => GoogleAdsSyncFailureCode::ABANDONED,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        return true;
    }

    public function release(int $accountId): void
    {
        DB::table('google_ads_accounts')->where('id', $accountId)->update(['sync_claimed_at' => null]);
    }

    public function isClaimLive(GoogleAdsAccount $account): bool
    {
        $claimedAt = DB::table('google_ads_accounts')->where('id', $account->id)->value('sync_claimed_at');

        return $claimedAt !== null
            && $claimedAt >= now()->subMinutes(self::CLAIM_STALE_MINUTES)->format('Y-m-d H:i:s');
    }

    public function hasLiveWork(GoogleAdsAccount $account): bool
    {
        if ($this->isClaimLive($account)) {
            return true;
        }

        return GoogleAdsSyncRun::query()
            ->where('google_ads_account_id', $account->id)
            ->where(function ($query): void {
                $query->where(function ($queued): void {
                    $queued->where('state', GoogleAdsSyncRunState::Queued->value)
                        ->where('created_at', '>=', now()->subMinutes(self::QUEUED_RUN_WINDOW_MINUTES));
                })->orWhere(function ($running): void {
                    $running->where('state', GoogleAdsSyncRunState::Running->value)
                        ->where('started_at', '>=', now()->subMinutes(self::CLAIM_STALE_MINUTES));
                });
            })
            ->exists();
    }
}
