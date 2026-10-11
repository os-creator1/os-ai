<?php

namespace App\Library\PlatformBilling\Offers;

use App\Models\PlatformSubscription;
use App\Models\PlatformTrialClaim;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * THE rule that a free trial happens once.
 *
 * A trial is claimed against three identities at once — the Workspace, the
 * normalized mailbox (see EmailIdentity::key()), and the provider customer —
 * and each is a UNIQUE column, so "once" is enforced by the database rather
 * than by a check that two concurrent requests could both pass.
 *
 * Two phases:
 *
 *   reserve()  taken when a trial Checkout is opened. Expires, so an abandoned
 *              Checkout does not lock the customer out forever; while it is
 *              live it stops a second account on the same mailbox from opening
 *              its own trial in parallel.
 *   grant()    written when the PROVIDER confirms the trial has started. Never
 *              expires. This — not opening the link, not opening Checkout — is
 *              what "the trial has been used" means.
 *
 * Cancelling or abandoning a Checkout releases only a RESERVATION; a granted
 * claim survives cancellation, resubscription and account re-creation.
 */
final class TrialLedger
{
    public const RESERVATION_MINUTES = 120;

    /** Read-only: could this customer start a trial right now? */
    public function eligible(Workspace $workspace, string $email, ?string $providerCustomerId = null): bool
    {
        return ! $this->blockingClaims($workspace, EmailIdentity::key($email), $providerCustomerId)->isNotEmpty();
    }

    /**
     * Atomically reserve a trial for this Workspace, or report that it cannot
     * be had. True also when the SAME Workspace already holds the reservation.
     */
    public function reserve(Workspace $workspace, string $email, string $source, ?CarbonInterface $until = null, ?bool &$created = null): bool
    {
        $key = EmailIdentity::key($email);
        $until ??= now()->addMinutes(self::RESERVATION_MINUTES);
        // Set INSIDE the same locked transaction that inserts the claim, so a
        // caller can tell its own reservation from one it merely reused.
        $created = false;

        try {
            return DB::transaction(function () use ($workspace, $key, $source, $until, &$created): bool {
                // Lapsed reservations are not claims. Clear them first so a
                // stale one cannot block a legitimate customer.
                PlatformTrialClaim::query()
                    ->where('state', PlatformTrialClaim::STATE_RESERVED)
                    ->where('reserved_until', '<', now())
                    ->where(fn ($q) => $q->where('workspace_id', $workspace->id)->orWhere('email_key', $key))
                    ->delete();

                $existing = PlatformTrialClaim::query()
                    ->where(fn ($q) => $q->where('workspace_id', $workspace->id)->orWhere('email_key', $key))
                    ->lockForUpdate()
                    ->get();

                foreach ($existing as $claim) {
                    if ($claim->state === PlatformTrialClaim::STATE_GRANTED) {
                        return false;
                    }

                    if ((int) $claim->workspace_id !== (int) $workspace->id) {
                        // Someone else is mid-Checkout on this mailbox.
                        return false;
                    }
                }

                $own = $existing->first(fn (PlatformTrialClaim $c): bool => (int) $c->workspace_id === (int) $workspace->id);

                if ($own !== null) {
                    $own->forceFill(['email_key' => $key, 'reserved_until' => $until, 'source' => $source])->save();

                    return true;
                }

                (new PlatformTrialClaim())->forceFill([
                    'workspace_id' => $workspace->id,
                    'email_key' => $key,
                    'state' => PlatformTrialClaim::STATE_RESERVED,
                    'source' => $source,
                    'reserved_until' => $until,
                ])->save();

                $created = true;

                return true;
            });
        } catch (QueryException $e) {
            // A concurrent request took the same key between our read and our
            // insert; the unique index did its job. Not eligible.
            if ($this->isDuplicateKey($e)) {
                return false;
            }

            throw $e;
        }
    }

    /** Whether the Workspace already holds an open trial reservation (an earlier Checkout's). */
    public function hasReservation(Workspace $workspace): bool
    {
        return PlatformTrialClaim::query()
            ->where('workspace_id', $workspace->id)
            ->where('state', PlatformTrialClaim::STATE_RESERVED)
            ->exists();
    }

    /** Drops a Workspace's RESERVATION. A granted claim is never touched. */
    public function release(Workspace $workspace): void
    {
        PlatformTrialClaim::query()
            ->where('workspace_id', $workspace->id)
            ->where('state', PlatformTrialClaim::STATE_RESERVED)
            ->delete();
    }

    /**
     * Records that the provider confirmed a trial for this subscription.
     * Idempotent, and never throws on a conflict: by this point Stripe has
     * already started the trial, so the right response to a duplicate is to
     * keep the first claim and make the conflict visible, not to fail a webhook.
     */
    public function grant(PlatformSubscription $subscription, string $email, string $source): void
    {
        $key = EmailIdentity::key($email);
        $customerId = $subscription->provider_customer_id;

        try {
            DB::transaction(function () use ($subscription, $key, $customerId, $source): void {
                $claim = PlatformTrialClaim::query()
                    ->where('workspace_id', $subscription->workspace_id)
                    ->lockForUpdate()
                    ->first() ?? (new PlatformTrialClaim())->forceFill(['workspace_id' => $subscription->workspace_id]);

                $claim->forceFill([
                    'email_key' => $claim->email_key ?? $key,
                    'provider_customer_id' => $claim->provider_customer_id ?? $customerId,
                    'state' => PlatformTrialClaim::STATE_GRANTED,
                    'source' => $claim->source ?? $source,
                    'reserved_until' => null,
                    'granted_at' => $claim->granted_at ?? now(),
                ])->save();
            });
        } catch (QueryException $e) {
            if (! $this->isDuplicateKey($e)) {
                throw $e;
            }

            Log::warning('Platform trial claim conflicted on a unique identity; the first claim stands', [
                'workspace_id' => $subscription->workspace_id,
            ]);
        }
    }

    /** @return \Illuminate\Support\Collection<int, PlatformTrialClaim> */
    private function blockingClaims(Workspace $workspace, string $key, ?string $customerId)
    {
        return PlatformTrialClaim::query()
            ->where(function ($q) use ($workspace, $key, $customerId): void {
                $q->where('workspace_id', $workspace->id)->orWhere('email_key', $key);

                if ($customerId !== null && $customerId !== '') {
                    $q->orWhere('provider_customer_id', $customerId);
                }
            })
            ->get()
            ->filter(function (PlatformTrialClaim $claim) use ($workspace): bool {
                if ($claim->state === PlatformTrialClaim::STATE_GRANTED) {
                    return true;
                }

                // A reservation blocks only while live, and never its own holder.
                return (int) $claim->workspace_id !== (int) $workspace->id
                    && $claim->reserved_until !== null
                    && $claim->reserved_until->isFuture();
            })
            ->values();
    }

    private function isDuplicateKey(QueryException $e): bool
    {
        return (string) ($e->errorInfo[0] ?? '') === '23000'
            || str_contains(mb_strtolower($e->getMessage()), 'duplicate');
    }
}
