<?php

namespace App\Library\PlatformOwner\AccountDeletion;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\PlatformOwner\AccountClosureState;
use App\Enums\Workspace\AgencyClientRelationshipStatus;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\AgencyBilling\AgencyClientSubscriptionFinalizer;
use App\Library\AgencyBilling\AgencyStripeGateway;
use App\Library\BusinessEmail\BusinessEmailAccountManager;
use App\Library\Calendar\ExternalCalendar\ExternalCalendarConnectionManager;
use App\Library\GoogleBusinessProfile\GoogleBusinessProfileConnectionManager;
use App\Library\MetaAds\MetaAdsConnectionManager;
use App\Library\PlatformBilling\PlatformStripeGateway;
use App\Library\PlatformBilling\PlatformSubscriptionFinalizer;
use App\Library\PlatformOwner\PlatformAdminAuditLog;
use App\Library\PlatformOwner\PlatformOwnerAuthority;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Models\AdditionalBusinessSlotAgreement;
use App\Models\AgencyClientSubscription;
use App\Models\AgencyClientWorkspaceRelationship;
use App\Models\BusinessEmailAccount;
use App\Models\BusinessGoogleConnection;
use App\Models\BusinessMetaConnection;
use App\Models\ExternalCalendarConnection;
use App\Models\PlatformAccountClosure;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Repositories\Contracts\AgencyClientWorkspaceRelationshipRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * THE one authority for deleting a customer account. Nothing else — no
 * controller, no legacy CustomerController — may remove a customer's user,
 * Workspaces or Businesses.
 *
 * WHY A LIFECYCLE AND NOT A TRANSACTION. Cancelling a Stripe subscription and
 * deleting local rows cannot be one atomic operation: the provider call can
 * succeed while the local write fails, or fail after Stripe acted. So the
 * operation is a durable, resumable state machine
 * (platform_account_closures), and every step is idempotent:
 *
 *   1. lock_down         ONE local transaction, BEFORE any provider call: the
 *                        account can no longer sign in; sessions and tokens are
 *                        revoked; every member's access to the owned Workspaces
 *                        is removed; Workspaces and Businesses go inactive;
 *                        paid activity is paused and auto-recharge disabled;
 *                        outreach, campaigns, schedules, automations, websites
 *                        and forms stop; Agency relationships end. Nothing
 *                        owned by anyone else moves. Whatever happens at the
 *                        provider afterwards, nobody can run billable work.
 *   2. cancel_billing    every subscription that could still charge is
 *                        cancelled THROUGH ITS PROVIDER GATEWAY (the provider
 *                        is read first, so an outcome that was uncertain is
 *                        converged, not repeated), and the confirmed result is
 *                        recorded ONLY through the authoritative finalizer. If
 *                        the finalizer fails the item stays pending and is
 *                        reconciled on retry; local status is never written
 *                        directly and a row is never deleted or relabelled.
 *   3. disconnect        provider connections (Google, Meta, mailbox, calendar).
 *   4. finalize          only when no provider or accounting prerequisite is
 *                        outstanding: either erase the account-owned rows
 *                        (no protected history) -> DELETED, or keep them
 *                        locked -> CLOSED. Otherwise the closure stays CLOSING
 *                        with a visible, actionable list of what is pending.
 *
 * "Complete" is only ever reported when the final state really is final.
 * A failed provider cancellation leaves CLOSING, never CLOSED/DELETED.
 */
class PlatformAccountClosureManager
{
    /**
     * The lease. A run renews it (heartbeat) before every external call and
     * every item, and every guarded write renews it too, so only a worker stuck
     * inside ONE operation for longer than this can lose it — and a worker that
     * has lost it is stopped at its next heartbeat (AccountClosureClaimLost) and
     * can write nothing further. Every provider call is bounded far below this
     * by the HTTP client timeouts.
     */
    private const CLAIM_SECONDS = 300;

    public function __construct(
        private readonly PlatformOwnerAuthority $authority,
        private readonly PlatformAdminAuditLog $audit,
        private readonly PlatformAccountAssessor $assessor,
        private readonly AccountRecordGraph $graph,
    ) {
    }

    /**
     * Initiate (or resume, or re-confirm as already done) the deletion of one
     * customer account.
     *
     * @throws AccountClosureRefused  nothing was changed
     */
    public function request(int $actorId, string $targetUid, string $typedEmail, string $reason, string $fingerprint): PlatformAccountClosure
    {
        $this->authority->assertAdministrator($actorId);

        $reason = trim($reason);

        if (mb_strlen($reason) < 3) {
            throw new AccountClosureRefused(__('Say why (at least 3 characters).'));
        }

        $user = User::query()->where('uid', $targetUid)->first();

        if ($user === null) {
            $tombstone = PlatformAccountClosure::query()->where('user_uid', $targetUid)->first();

            if ($tombstone !== null) {
                // A repeated request for an account that is already gone.
                return $tombstone;
            }

            throw new AccountClosureRefused(__('That account no longer exists.'));
        }

        if ($user->is_admin || (int) $user->id === $actorId) {
            throw new AccountClosureRefused(__('Administrator accounts, and your own account, can never be deleted here.'));
        }

        if (! hash_equals(mb_strtolower(trim($user->email)), mb_strtolower(trim($typedEmail)))) {
            throw new AccountClosureRefused(__('The typed email does not match this account.'));
        }

        $closure = PlatformAccountClosure::query()->where('user_uid', $user->uid)->first();

        if ($closure === null) {
            $assessment = $this->assessor->assess($user);

            if ($assessment['verdict'] === PlatformAccountAssessor::VERDICT_BLOCKED) {
                throw new AccountClosureRefused(__('Deletion is blocked for this account: :r', ['r' => implode(' ', $assessment['reasons'])]));
            }

            if (! hash_equals($assessment['fingerprint'], $fingerprint)) {
                throw new AccountClosureRefused(__('This account changed after you reviewed it. Review the updated impact and confirm again.'));
            }

            $closure = $this->open($actorId, $user, $reason, $assessment);
        }

        return $this->advance($closure);
    }

    /**
     * Resume an unfinished closure (the Retry button and the scheduled sweep).
     * $actorId null is the scheduled sweep, which acts under the authority of
     * whoever requested the closure, re-checked below.
     */
    public function resume(string $closureUid, ?int $actorId = null): PlatformAccountClosure
    {
        $closure = PlatformAccountClosure::query()->where('uid', $closureUid)->firstOrFail();

        if ($actorId !== null) {
            $this->authority->assertAdministrator($actorId);
        }

        return $this->advance($closure);
    }

    /**
     * Run every outstanding step once. Safe to call repeatedly and
     * concurrently: only one run holds the claim.
     */
    public function advance(PlatformAccountClosure $closure): PlatformAccountClosure
    {
        $claimed = $this->claim($closure);

        if ($claimed === null) {
            // Already final, or another run holds the lease: report the current row.
            return $closure->refresh();
        }

        $closure = $claimed;

        try {
            $this->run($closure);
        } catch (AccountClosureClaimLost) {
            // Another worker took over after this one stalled. Write nothing.
            Log::warning('account-closure.claim-lost', ['closure' => $closure->uid]);
        } catch (Throwable $e) {
            report($e);

            try {
                $this->record($closure, 'run', 'failed', 'The run stopped on an unexpected error; it will be retried.');
                $this->write($closure, ['last_error' => 'Unexpected error: ' . class_basename($e)]);
            } catch (AccountClosureClaimLost) {
            }
        } finally {
            // Release only OUR lease; a successor's is left alone.
            DB::table('platform_account_closures')
                ->where('id', $closure->id)->where('claim_token', $closure->claim_token)
                ->update(['claimed_at' => null, 'claim_token' => null]);
        }

        return $closure->refresh();
    }

    // -- the lifecycle ----------------------------------------------------------

    private function run(PlatformAccountClosure $closure): void
    {
        $this->heartbeat($closure);

        // Re-check authority and identity immediately before acting, every run.
        $this->authority->assertAdministrator((int) $closure->requested_by_user_id);

        $user = User::query()->where('uid', $closure->user_uid)->first();

        if ($user === null) {
            // The row is gone but the closure never recorded it (a crash after the
            // purge commit): the purge transaction also marks the closure Deleted,
            // so this is only reachable if the user vanished some other way.
            $this->write($closure, ['state' => AccountClosureState::Deleted, 'outcome' => 'deleted', 'completed_at' => now(), 'pending_items' => [], 'last_error' => null]);

            return;
        }

        if ($user->is_admin || (int) $user->id === (int) $closure->requested_by_user_id) {
            throw new AccountClosureRefused('Administrator or acting accounts can never be closed.');
        }

        $this->lockDown($closure, $user);

        $pending = [];
        $pending = array_merge($pending, $this->cancelBilling($closure, $user));
        $pending = array_merge($pending, $this->disconnectExternal($closure, $user));

        $this->finalize($closure, $user, $pending);
    }

    private function open(int $actorId, User $user, string $reason, array $assessment): PlatformAccountClosure
    {
        try {
            $closure = PlatformAccountClosure::create([
                'user_id' => $user->id,
                'user_uid' => $user->uid,
                'user_email' => $user->email,
                'user_name' => trim($user->first_name . ' ' . $user->last_name) ?: null,
                'requested_by_user_id' => $actorId,
                'reason' => $reason,
                'state' => AccountClosureState::Closing,
                'preflight' => $assessment,
                'steps' => [],
                'pending_items' => [],
            ]);
        } catch (QueryException $e) {
            // Two requests raced; user_uid is unique, so they converge on one row.
            $existing = PlatformAccountClosure::query()->where('user_uid', $user->uid)->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }

        $this->audit->record($actorId, 'user.deletion_requested', 'user', $user->uid, 'Requested deletion of ' . $user->email . ' (' . $assessment['verdict'] . ')', $reason, ['verdict' => $assessment['verdict']]);

        return $closure;
    }

    private function claim(PlatformAccountClosure $closure): ?PlatformAccountClosure
    {
        $uid = $closure->uid;

        return DB::transaction(function () use ($uid) {
            $locked = PlatformAccountClosure::query()->where('uid', $uid)->lockForUpdate()->first();

            if ($locked === null || $locked->state->isFinal()) {
                return null;
            }

            if ($locked->claim_token !== null && $locked->claimed_at !== null && $locked->claimed_at->gt(now()->subSeconds(self::CLAIM_SECONDS))) {
                return null;
            }

            $token = (string) Str::uuid();

            DB::table('platform_account_closures')->where('id', $locked->id)->update([
                'claim_token' => $token,
                'claimed_at' => now(),
                'last_attempt_at' => now(),
                'attempts' => $locked->attempts + 1,
            ]);

            return $locked->refresh();
        });
    }

    /**
     * Renew the lease, or stop. Called before every external call and item.
     *
     * @throws AccountClosureClaimLost
     */
    private function heartbeat(PlatformAccountClosure $closure): void
    {
        $this->guarded($closure, fn ($query) => $query->update(['claimed_at' => now()]));
    }

    /**
     * Every write the lease-holder makes goes through here: it is applied only
     * while this run still holds the lease, and it renews the lease.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws AccountClosureClaimLost
     */
    private function write(PlatformAccountClosure $closure, array $attributes): void
    {
        $row = $attributes;

        foreach (['preflight', 'steps', 'pending_items'] as $json) {
            if (array_key_exists($json, $row) && $row[$json] !== null) {
                $row[$json] = json_encode($row[$json]);
            }
        }

        if (($row['state'] ?? null) instanceof AccountClosureState) {
            $row['state'] = $row['state']->value;
        }

        $row['claimed_at'] = now();
        $row['updated_at'] = now();

        $this->guarded($closure, fn ($query) => $query->update($row));

        $closure->forceFill($attributes)->syncOriginal();
    }

    /**
     * @throws AccountClosureClaimLost
     */
    private function guarded(PlatformAccountClosure $closure, callable $do): void
    {
        $query = fn () => DB::table('platform_account_closures')->where('id', $closure->id)->where('claim_token', $closure->claim_token);

        // MySQL reports 0 changed rows for an identical update, so a zero is only
        // "lost" if the lease row itself is gone.
        if ($do($query()) === 0 && ! $query()->exists()) {
            throw new AccountClosureClaimLost();
        }
    }

    // -- step 1 -----------------------------------------------------------------

    /**
     * Everything that must be true before the first provider call, applied in
     * ONE transaction so it is all-or-nothing and idempotent.
     */
    private function lockDown(PlatformAccountClosure $closure, User $user): void
    {
        $actorId = (int) $closure->requested_by_user_id;

        DB::transaction(function () use ($user, $actorId, $closure) {
            $user->forceFill([
                'status' => false,
                'password_changed_at' => now(),
                'remember_token' => null,
                'api_token' => null,
            ])->save();

            if (Schema::hasTable('sessions')) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }

            if (Schema::hasTable('personal_access_tokens')) {
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
            }

            DB::table('view_as_sessions')->where('actor_user_id', $user->id)->delete();

            $owned = fn ($q) => $q->select('id')->from('workspaces')->where('owner_user_id', $user->id);

            // Their seats in other people's Workspaces. Those Workspaces are untouched.
            DB::table('workspace_memberships')->where('user_id', $user->id)->whereNotIn('workspace_id', $owned)
                ->update(['is_active' => false, 'updated_at' => now()]);

            // Per-user legacy work.
            DB::table('campaigns')->where('user_id', $user->id)->whereIn('status', PlatformAccountAssessor::RUNNING_CAMPAIGN_STATUSES)
                ->update(['status' => 'cancelled', 'updated_at' => now()]);
            DB::table('automations')->where('user_id', $user->id)->where('status', 'active')
                ->update(['status' => 'inactive', 'updated_at' => now()]);
            DB::table('schedule_messages')->where('user_id', $user->id)->where('schedule_on', '>=', now()->subMinutes(3))->delete();

            $workspaceIds = DB::table('workspaces')->where('owner_user_id', $user->id)->pluck('id')->all();

            if ($workspaceIds === []) {
                return;
            }

            $businessIds = DB::table('businesses')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();

            // Every member of every owned Workspace, then the Workspace and its Businesses.
            DB::table('workspace_memberships')->whereIn('workspace_id', $workspaceIds)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('workspaces')->whereIn('id', $workspaceIds)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('businesses')->whereIn('workspace_id', $workspaceIds)->where('status', '!=', 'inactive')->update(['status' => 'inactive', 'updated_at' => now()]);

            foreach ($workspaceIds as $workspaceId) {
                DB::table('workspace_usage_controls')->updateOrInsert(
                    ['workspace_id' => $workspaceId],
                    ['paid_activity_paused_at' => now(), 'paid_activity_paused_by_user_id' => $actorId, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            if ($businessIds !== []) {
                DB::table('business_usage_wallets')->whereIn('business_id', $businessIds)->where('auto_recharge_enabled', true)
                    ->update(['auto_recharge_enabled' => false, 'updated_at' => now()]);
                DB::table('automation_workflows')->whereIn('business_id', $businessIds)->where('status', 'published')->update(['status' => 'paused', 'updated_at' => now()]);
                DB::table('websites')->whereIn('business_id', $businessIds)->where('status', 'published')->update(['status' => 'archived', 'updated_at' => now()]);
                DB::table('form_deployments')->whereIn('form_id', fn ($q) => $q->select('id')->from('forms')->whereIn('business_id', $businessIds))->update(['is_enabled' => false, 'updated_at' => now()]);
            }

            DB::table('agency_prospect_campaigns')->whereIn('workspace_id', $workspaceIds)->where('status', 'active')->update(['status' => 'paused', 'updated_at' => now()]);
            DB::table('agency_prospects')->whereIn('workspace_id', $workspaceIds)->where('status', 'active')->update(['status' => 'stopped', 'updated_at' => now()]);

            $this->terminateRelationships($workspaceIds, $actorId, $closure);
        });

        $this->record($closure, 'lock_down', 'done', 'Sign-in, sessions, tokens, member access, Workspaces, Businesses, paid activity, campaigns, schedules, automations, outreach, websites and Agency relationships stopped.');
    }

    // -- step 2 -----------------------------------------------------------------

    /**
     * @return array<int, string> outstanding items
     */
    private function cancelBilling(PlatformAccountClosure $closure, User $user): array
    {
        $pending = [];
        $this->heartbeat($closure);
        $workspaceIds = DB::table('workspaces')->where('owner_user_id', $user->id)->pluck('id')->all();

        if ($workspaceIds === []) {
            $this->record($closure, 'cancel_billing', 'done', 'No Workspaces, so no subscriptions.');

            return [];
        }

        foreach (PlatformSubscription::query()->whereIn('workspace_id', $workspaceIds)->get() as $sub) {
            if (! $this->assessor->platformSubscriptionIsLive($sub)) {
                continue;
            }

            $this->heartbeat($closure);

            try {
                $this->cancelPlatformSubscription($closure, $sub);
            } catch (AccountClosureClaimLost $e) {
                throw $e;
            } catch (AccountClosureReconciliationPending) {
                $pending[] = 'Platform subscription ' . $sub->uid . ' is cancelled at the payment provider, but recording that locally failed; it will be reconciled on retry.';
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'Platform subscription ' . $sub->uid . ' could not be cancelled with the payment provider yet; it will be retried. It may still be charging.';
            }
        }

        $agency = app(AgencyStripeGateway::class);

        foreach (AgencyClientSubscription::query()
            ->where(fn ($q) => $q->whereIn('client_workspace_id', $workspaceIds)->orWhereIn('agency_workspace_id', $workspaceIds))
            ->get() as $sub) {
            if (! $this->assessor->agencySubscriptionIsLive($sub)) {
                continue;
            }

            if ((string) $sub->connected_account_id === '') {
                $pending[] = 'Agency client subscription ' . $sub->uid . ' has no connected account recorded; it cannot be cancelled automatically and may still be charging.';

                continue;
            }

            $this->heartbeat($closure);

            try {
                $this->cancelAgencySubscription($closure, $agency, $sub);
            } catch (AccountClosureClaimLost $e) {
                throw $e;
            } catch (AccountClosureReconciliationPending) {
                $pending[] = 'Agency client subscription ' . $sub->uid . ' is cancelled at the payment provider, but recording that locally failed; it will be reconciled on retry.';
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'Agency client subscription ' . $sub->uid . ' could not be cancelled with the payment provider yet; it will be retried. It may still be charging.';
            }
        }

        foreach (AdditionalBusinessSlotAgreement::query()->whereIn('workspace_id', $workspaceIds)->get() as $agreement) {
            if (! $this->assessor->slotAgreementIsCharging($agreement)) {
                continue;
            }

            $this->heartbeat($closure);

            try {
                app(UsageBillingCheckoutManager::class)->requestSlotAgreementCancellation($agreement, (int) $closure->requested_by_user_id, 'Account closure: ' . $closure->reason);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'Slot agreement #' . $agreement->id . ' could not be set to stop renewing yet; it will be retried.';
            }
        }

        $this->record($closure, 'cancel_billing', $pending === [] ? 'done' : 'pending', $pending === [] ? 'No subscription can still charge.' : count($pending) . ' billing item(s) outstanding.');

        return $pending;
    }

    private function cancelPlatformSubscription(PlatformAccountClosure $closure, PlatformSubscription $sub): void
    {
        $gateway = app(PlatformStripeGateway::class);
        $id = (string) $sub->provider_subscription_id;

        // Read the authority first: if an earlier attempt's outcome was uncertain
        // the provider may already have cancelled it.
        $snapshot = $gateway->retrieveSubscription($id);

        if (! $snapshot->status->isTerminal()) {
            $snapshot = $gateway->cancelSubscriptionNow($id, 'account-closure:' . $closure->uid . ':platform:' . $sub->uid);
        }

        if (! $snapshot->status->isTerminal()) {
            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        }

        // The provider has ended it. Record that ONLY through the authoritative
        // finalizer; if it cannot, stay pending (the retry re-reads the provider,
        // sees it ended, and reconciles without cancelling again).
        try {
            app(PlatformSubscriptionFinalizer::class)->apply($sub, $snapshot);
            $sub->refresh();
        } catch (Throwable $e) {
            report($e);

            throw new AccountClosureReconciliationPending('platform', 0, $e);
        }

        if ($this->assessor->platformSubscriptionIsLive($sub)) {
            throw new AccountClosureReconciliationPending('platform');
        }
    }

    private function cancelAgencySubscription(PlatformAccountClosure $closure, AgencyStripeGateway $gateway, AgencyClientSubscription $sub): void
    {
        $account = (string) $sub->connected_account_id;
        $id = (string) $sub->provider_subscription_id;
        $ended = fn ($snapshot) => in_array($snapshot->status, [AgencyClientSubscriptionStatus::Canceled, AgencyClientSubscriptionStatus::IncompleteExpired], true);

        $snapshot = $gateway->retrieveSubscription($account, $id);

        if (! $ended($snapshot)) {
            $snapshot = $gateway->cancelSubscriptionNow($account, $id, 'account-closure:' . $closure->uid . ':agency:' . $sub->uid);
        }

        if (! $ended($snapshot)) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        try {
            app(AgencyClientSubscriptionFinalizer::class)->apply($sub, $snapshot);
            $sub->refresh();
        } catch (Throwable $e) {
            report($e);

            throw new AccountClosureReconciliationPending('agency', 0, $e);
        }

        if ($this->assessor->agencySubscriptionIsLive($sub)) {
            throw new AccountClosureReconciliationPending('agency');
        }
    }

    // -- step 3 -----------------------------------------------------------------

    /**
     * Provider connections. Local lockdown already happened; these are the
     * external revocations, each retryable on its own.
     *
     * @return array<int, string> outstanding items
     */
    private function disconnectExternal(PlatformAccountClosure $closure, User $user): array
    {
        $pending = [];
        $actorId = (int) $closure->requested_by_user_id;
        $workspaceIds = DB::table('workspaces')->where('owner_user_id', $user->id)->pluck('id')->all();
        $businessIds = $workspaceIds === [] ? [] : DB::table('businesses')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();

        if ($businessIds !== []) {
            $pending = array_merge($pending, $this->disconnectProviders($closure, $businessIds, $actorId));
        }

        foreach (ExternalCalendarConnection::query()->where('user_id', $user->id)->whereIn('state', ['pending', 'active'])->get() as $connection) {
            $this->heartbeat($closure);

            try {
                app(ExternalCalendarConnectionManager::class)->disconnect($connection, $actorId);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'A calendar connection could not be disconnected yet; it will be retried.';
            }
        }

        $this->record($closure, 'disconnect', $pending === [] ? 'done' : 'pending', $pending === [] ? 'Provider connections disconnected.' : count($pending) . ' item(s) outstanding.');

        return $pending;
    }

    /**
     * @param  array<int, int>  $workspaceIds
     */
    private function terminateRelationships(array $workspaceIds, int $actorId, PlatformAccountClosure $closure): void
    {
        $repository = app(AgencyClientWorkspaceRelationshipRepository::class);

        $relationships = AgencyClientWorkspaceRelationship::query()
            ->where('status', AgencyClientRelationshipStatus::Active->value)
            ->where(fn ($q) => $q->whereIn('agency_workspace_id', $workspaceIds)->orWhereIn('client_workspace_id', $workspaceIds))
            ->get();

        foreach ($relationships as $relationship) {
            $repository->markTerminated($relationship, $actorId, 'Account closure: ' . $closure->user_email);
        }
    }

    /**
     * @param  array<int, int>  $businessIds
     * @return array<int, string>
     */
    private function disconnectProviders(PlatformAccountClosure $closure, array $businessIds, int $actorId): array
    {
        $pending = [];

        foreach (BusinessGoogleConnection::query()->whereIn('business_id', $businessIds)->whereIn('state', ['pending', 'active'])->get() as $connection) {
            $this->heartbeat($closure);

            try {
                app(GoogleBusinessProfileConnectionManager::class)->disconnect($connection, $actorId);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'A Google connection could not be disconnected yet; it will be retried.';
            }
        }

        foreach (BusinessMetaConnection::query()->whereIn('business_id', $businessIds)->whereIn('state', ['pending', 'active'])->get() as $connection) {
            $this->heartbeat($closure);

            try {
                app(MetaAdsConnectionManager::class)->disconnect($connection, $actorId);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'A Meta connection could not be disconnected yet; it will be retried.';
            }
        }

        foreach (BusinessEmailAccount::query()->whereIn('business_id', $businessIds)->whereIn('state', ['pending', 'active'])->get() as $account) {
            $this->heartbeat($closure);

            try {
                app(BusinessEmailAccountManager::class)->disconnect($account);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'A connected mailbox could not be disconnected yet; it will be retried.';
            }
        }

        return $pending;
    }

    // -- step 4 -----------------------------------------------------------------

    /**
     * @param  array<int, string>  $pending
     */
    private function finalize(PlatformAccountClosure $closure, User $user, array $pending): void
    {
        $this->heartbeat($closure);
        $assessment = $this->assessor->assess($user);

        if ($assessment['connected_services']['carrier_numbers'] > 0) {
            $pending[] = $assessment['connected_services']['carrier_numbers'] . ' messaging number(s) are still held at the carrier. Release them through the Messaging Numbers lifecycle, then retry.';
        }

        if ($assessment['billing_commitments']['charging'] > 0 && $pending === []) {
            // Defence in depth: the assessor still sees something able to charge.
            $pending[] = 'A subscription or agreement is still able to charge; it will be retried.';
        }

        if ($pending !== []) {
            $this->write($closure, [
                'pending_items' => array_values(array_unique($pending)),
                'last_error' => implode(' ', array_values(array_unique($pending))),
            ]);

            $this->record($closure, 'finalize', 'pending', 'Waiting on provider or accounting prerequisites.');
            $this->audit->record((int) $closure->requested_by_user_id, 'user.closure_pending', 'user', $user->uid, 'Closure of ' . $user->email . ' is pending: ' . count($pending) . ' item(s) outstanding');

            return;
        }

        if ($assessment['verdict'] === PlatformAccountAssessor::VERDICT_DELETE) {
            $this->erase($closure, $user);

            return;
        }

        $this->write($closure, [
            'state' => AccountClosureState::Closed,
            'outcome' => 'closed_retained',
            'pending_items' => [],
            'last_error' => null,
            'completed_at' => now(),
        ]);

        $this->record($closure, 'finalize', 'done', 'Account closed. Protected records are retained, locked.');
        $this->audit->record((int) $closure->requested_by_user_id, 'user.closed', 'user', $user->uid, 'Closed ' . $user->email . '; protected records retained', $closure->reason, ['retained' => array_column($assessment['protected_records'], 'label')]);
    }

    private function erase(PlatformAccountClosure $closure, User $user): void
    {
        $userId = (int) $user->id;
        $uid = (string) $user->uid;
        $email = (string) $user->email;
        $files = [];

        try {
            DB::transaction(function () use ($closure, $userId, $email, &$files) {
                // The destructive step re-proves, under a row lock, that this run
                // STILL holds the lease: a worker that stalled and was replaced can
                // never erase.
                $held = PlatformAccountClosure::query()->whereKey($closure->id)->where('claim_token', $closure->claim_token)->lockForUpdate()->exists();

                if (! $held) {
                    throw new AccountClosureClaimLost();
                }

                // Re-derive inside the transaction: ownership is checked against
                // the database at the instant of deletion, not the earlier read.
                $workspaceIds = DB::table('workspaces')->where('owner_user_id', $userId)->pluck('id')->map(fn ($id) => (int) $id)->all();
                $selection = $this->graph->discover($userId, $workspaceIds);

                if ($selection->blockers !== [] || $selection->unsupported !== [] || $this->graph->protectedRecords($selection) !== []) {
                    throw new AccountClosureRefused('Protected or external records appeared; the account can no longer be erased.');
                }

                $files = $this->graph->files($selection);
                $this->graph->purge($selection);

                foreach (['password_resets', 'password_reset_tokens'] as $table) {
                    if (Schema::hasTable($table)) {
                        DB::table($table)->where('email', $email)->delete();
                    }
                }

                $this->write($closure, [
                    'state' => AccountClosureState::Deleted,
                    'outcome' => 'deleted',
                    'user_id' => null,
                    'pending_items' => [],
                    'last_error' => null,
                    'completed_at' => now(),
                    'preflight' => null,
                ]);
            }, 3);
        } catch (AccountClosureRefused $e) {
            // Became ineligible between the check and the lock: close instead.
            $this->write($closure, ['state' => AccountClosureState::Closed, 'outcome' => 'closed_retained', 'pending_items' => [], 'last_error' => null, 'completed_at' => now()]);
            $this->record($closure, 'finalize', 'done', $e->getMessage());

            return;
        }

        foreach ($files as $file) {
            try {
                Storage::disk($file['disk'])->delete($file['path']);
            } catch (Throwable $e) {
                Log::warning('account-closure.file-cleanup-failed', ['closure' => $closure->uid, 'disk' => $file['disk']]);
            }
        }

        $this->record($closure, 'finalize', 'done', 'Account and all account-owned data erased.');
        $this->audit->record((int) $closure->requested_by_user_id, 'user.deleted', 'user', $uid, 'Deleted ' . $email . ' and all account-owned data', $closure->reason);
    }

    // -- bookkeeping ------------------------------------------------------------

    private function record(PlatformAccountClosure $closure, string $step, string $status, string $detail): void
    {
        $steps = $closure->steps ?? [];
        $steps[$step] = ['status' => $status, 'detail' => $detail, 'at' => now()->toIso8601String()];
        $this->write($closure, ['steps' => $steps]);
    }
}
