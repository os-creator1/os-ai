<?php

namespace App\Library\Payments;

use App\Enums\AgencyBilling\AgencyStripeConnectionStatus;
use App\Enums\Documents\StripeConnectionStatus;
use App\Exceptions\Payments\StripeAccountClaimedException;
use App\Models\AgencyStripeConnection;
use App\Models\BusinessStripeConnection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single, database-enforced answer to "who currently owns this Stripe
 * account?" across BOTH payment lanes (Business lane B and Agency lane C).
 *
 * `stripe_account_claims.stripe_account_id` is UNIQUE, so two connections —
 * two Businesses, or a Business and an Agency — can never both hold an account:
 * the second INSERT blocks on the first's uncommitted row and then fails. A
 * claim is written in the SAME transaction as the connection row it protects
 * and deleted in the same transaction that makes that row terminal, so a claim
 * can only outlive a connection through a crash — and claim() evicts such a
 * stale claim after proving its backing row is no longer live.
 *
 * REVOCATION LOCK. An OAuth grant may only be revoked at Stripe while no one
 * else can claim the account. A connection being disconnected revokes while it
 * still holds its own claim; a grant that was received but rejected (and so
 * never had a claim) takes a short-lived `revoke` claim for the duration of
 * the provider call. Either way a competing connection is refused for that
 * window instead of having its fresh access revoked from under it.
 *
 * No provider call is made here, and callers must not call the provider inside
 * the transaction that claims or releases.
 */
final class StripeAccountClaims
{
    public const LANE_BUSINESS = 'business';

    public const LANE_AGENCY = 'agency';

    private const LANE_REVOKE = 'revoke';

    /** A revoke lock this old is a crashed process, not a live provider call. */
    private const REVOKE_LOCK_SECONDS = 120;

    /**
     * Claim an account for a connection. Call inside the transaction that
     * creates the connection row.
     *
     * @throws StripeAccountClaimedException
     */
    public function claim(string $stripeAccountId, string $lane, int $connectionId): void
    {
        $this->insertClaim($stripeAccountId, $lane, $connectionId, null);
    }

    /**
     * Release a claim in the transaction that makes the connection terminal.
     * Matches lane AND connection id, so it can never release a successor's.
     */
    public function release(string $stripeAccountId, string $lane, int $connectionId): void
    {
        DB::table('stripe_account_claims')
            ->where('stripe_account_id', $stripeAccountId)
            ->where('lane', $lane)
            ->where('connection_id', $connectionId)
            ->delete();
    }

    /**
     * Take the short-lived lock that makes revoking a never-claimed grant safe.
     * False means someone owns or is revoking the account: do not revoke.
     */
    public function acquireRevocationLock(string $stripeAccountId): bool
    {
        try {
            $this->insertClaim($stripeAccountId, self::LANE_REVOKE, null, now()->addSeconds(self::REVOKE_LOCK_SECONDS));

            return true;
        } catch (StripeAccountClaimedException) {
            return false;
        }
    }

    public function releaseRevocationLock(string $stripeAccountId): void
    {
        DB::table('stripe_account_claims')
            ->where('stripe_account_id', $stripeAccountId)
            ->where('lane', self::LANE_REVOKE)
            ->delete();
    }

    /**
     * @throws StripeAccountClaimedException
     */
    private function insertClaim(string $stripeAccountId, string $lane, ?int $connectionId, ?\DateTimeInterface $expiresAt): void
    {
        $row = [
            'stripe_account_id' => $stripeAccountId,
            'lane' => $lane,
            'connection_id' => $connectionId,
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                DB::table('stripe_account_claims')->insert($row);

                return;
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }

                if (! $this->evictIfStale($stripeAccountId)) {
                    throw StripeAccountClaimedException::make();
                }
            }
        }

        throw StripeAccountClaimedException::make();
    }

    /** Deletes the existing claim only if its backing connection is provably not live. */
    private function evictIfStale(string $stripeAccountId): bool
    {
        $claim = DB::table('stripe_account_claims')->where('stripe_account_id', $stripeAccountId)->lockForUpdate()->first();

        if ($claim === null) {
            return true; // released between our insert and this read
        }

        if ($this->isLive($claim)) {
            return false;
        }

        DB::table('stripe_account_claims')->where('id', $claim->id)->delete();

        return true;
    }

    /**
     * Liveness is read with LOCKING (current) reads on purpose: under InnoDB's
     * REPEATABLE READ a plain SELECT would use this transaction's snapshot, which
     * can predate the winner's commit — the winner's connection would look absent,
     * its claim would be judged stale and evicted, and both lanes would win.
     */
    private function isLive(object $claim): bool
    {
        if ($claim->lane === self::LANE_REVOKE || $claim->connection_id === null) {
            return $claim->expires_at === null || now()->lt($claim->expires_at);
        }

        if ($claim->lane === self::LANE_BUSINESS) {
            return BusinessStripeConnection::query()
                ->whereKey($claim->connection_id)
                ->where('stripe_account_id', $claim->stripe_account_id)
                ->whereIn('status', [
                    StripeConnectionStatus::Pending->value,
                    StripeConnectionStatus::Onboarding->value,
                    StripeConnectionStatus::Active->value,
                    StripeConnectionStatus::Restricted->value,
                ])->lockForUpdate()->exists();
        }

        return AgencyStripeConnection::query()
            ->whereKey($claim->connection_id)
            ->where('stripe_account_id', $claim->stripe_account_id)
            ->where('status', '!=', AgencyStripeConnectionStatus::Disconnected->value)
            ->lockForUpdate()
            ->exists();
    }
}
