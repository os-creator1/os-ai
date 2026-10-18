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
 * OWNERSHIP. A claim older than CLAIM_STALE_MINUTES can be taken over, so the
 * claimant holds a GoogleAdsSyncClaim (the stored stamp). It heartbeats at the
 * start of every stage (a live run never goes stale) and releases ONLY while
 * the stored stamp still equals its own, so a job whose claim was taken over
 * can neither extend nor release the new owner's claim.
 *
 * hasLiveWork() is the wider "is anything already in flight or about to be"
 * question the sweep, the requester and the freshness reader all ask: a live
 * claim, a run that is `running`, or a `queued` run whose job is plausibly
 * still waiting in the queue.
 */
final class GoogleAdsSyncGuard
{
    public const CLAIM_STALE_MINUTES = 15;

    private const STAMP_FORMAT = 'Y-m-d H:i:s';

    /** A queued run older than this is treated as lost (its job never ran). */
    public const QUEUED_RUN_WINDOW_MINUTES = 180;

    /** The claim for the single caller that wins the account, or null. */
    public function acquire(int $accountId): ?GoogleAdsSyncClaim
    {
        $stamp = now()->format(self::STAMP_FORMAT);

        $affected = DB::table('google_ads_accounts')
            ->where('id', $accountId)
            ->where(static function ($query): void {
                $query->whereNull('sync_claimed_at')
                    ->orWhere('sync_claimed_at', '<', now()->subMinutes(self::CLAIM_STALE_MINUTES));
            })
            ->update(['sync_claimed_at' => $stamp]);

        if ($affected !== 1) {
            return null;
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

        return new GoogleAdsSyncClaim($accountId, $stamp);
    }

    /**
     * Extends our claim. False when it was taken over (the caller must stop).
     * A same-second heartbeat changes nothing, so ownership is then read back.
     */
    public function heartbeat(GoogleAdsSyncClaim $claim): bool
    {
        $next = now()->format(self::STAMP_FORMAT);

        if ($next === $claim->stamp) {
            return DB::table('google_ads_accounts')->where('id', $claim->accountId)->value('sync_claimed_at') === $claim->stamp;
        }

        $affected = DB::table('google_ads_accounts')
            ->where('id', $claim->accountId)
            ->where('sync_claimed_at', $claim->stamp)
            ->update(['sync_claimed_at' => $next]);

        if ($affected !== 1) {
            return false;
        }

        $claim->stamp = $next;

        return true;
    }

    /** Releases the claim only while it is still ours. */
    public function release(GoogleAdsSyncClaim $claim): void
    {
        DB::table('google_ads_accounts')
            ->where('id', $claim->accountId)
            ->where('sync_claimed_at', $claim->stamp)
            ->update(['sync_claimed_at' => null]);
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
