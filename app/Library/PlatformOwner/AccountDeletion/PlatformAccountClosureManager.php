<?php

namespace App\Library\PlatformOwner\AccountDeletion;

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
 *   1. block_access      the account can no longer sign in or call the API;
 *                        sessions and tokens are revoked.
 *   2. cancel_billing    every subscription that could still charge is
 *                        cancelled THROUGH ITS PROVIDER GATEWAY (the provider
 *                        is read first, so an outcome that was uncertain is
 *                        converged, not repeated). A local subscription row is
 *                        never simply deleted or relabelled.
 *   3. halt_activity     Workspaces and Businesses stop being usable; outreach,
 *                        campaigns, schedules, automations and websites stop;
 *                        provider connections are disconnected; Agency
 *                        relationships end. Nothing owned by anyone else moves.
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
    /** A run that has held the claim longer than this is presumed dead. */
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
            // Already final, or another run holds the claim: report the current row.
            return $closure->refresh();
        }

        $closure = $claimed;

        try {
            $this->run($closure);
        } catch (Throwable $e) {
            report($e);
            $this->record($closure, 'run', 'failed', 'The run stopped on an unexpected error; it will be retried.');
            $closure->forceFill(['last_error' => 'Unexpected error: ' . class_basename($e)])->save();
        } finally {
            $closure->refresh()->forceFill(['claimed_at' => null])->save();
        }

        return $closure->refresh();
    }

    // -- the lifecycle ----------------------------------------------------------

    private function run(PlatformAccountClosure $closure): void
    {
        // Re-check authority and identity immediately before acting, every run.
        $this->authority->assertAdministrator((int) $closure->requested_by_user_id);

        $user = User::query()->where('uid', $closure->user_uid)->first();

        if ($user === null) {
            // The row is gone but the closure never recorded it (a crash after the
            // purge commit): the purge transaction also marks the closure Deleted,
            // so this is only reachable if the user vanished some other way.
            $closure->forceFill(['state' => AccountClosureState::Deleted, 'outcome' => 'deleted', 'completed_at' => now(), 'pending_items' => [], 'last_error' => null])->save();

            return;
        }

        if ($user->is_admin || (int) $user->id === (int) $closure->requested_by_user_id) {
            throw new AccountClosureRefused('Administrator or acting accounts can never be closed.');
        }

        $pending = [];

        $this->blockAccess($closure, $user);
        $pending = array_merge($pending, $this->cancelBilling($closure, $user));
        $pending = array_merge($pending, $this->haltActivity($closure, $user));

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

        $claimed = DB::transaction(function () use ($uid) {
            $locked = PlatformAccountClosure::query()->where('uid', $uid)->lockForUpdate()->first();

            if ($locked === null || $locked->state->isFinal()) {
                return null;
            }

            if ($locked->claimed_at !== null && $locked->claimed_at->gt(now()->subSeconds(self::CLAIM_SECONDS))) {
                return null;
            }

            $locked->forceFill(['claimed_at' => now(), 'last_attempt_at' => now(), 'attempts' => $locked->attempts + 1])->save();

            return $locked;
        });

        return $claimed;
    }

    // -- step 1 -----------------------------------------------------------------

    private function blockAccess(PlatformAccountClosure $closure, User $user): void
    {
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

        // Their seats in other people's Workspaces. The Workspaces are untouched.
        DB::table('workspace_memberships')
            ->where('user_id', $user->id)
            ->whereNotIn('workspace_id', fn ($q) => $q->select('id')->from('workspaces')->where('owner_user_id', $user->id))
            ->update(['is_active' => false, 'updated_at' => now()]);

        $this->record($closure, 'block_access', 'done', 'Sign-in, sessions, tokens and seats in other Workspaces revoked.');
    }

    // -- step 2 -----------------------------------------------------------------

    /**
     * @return array<int, string> outstanding items
     */
    private function cancelBilling(PlatformAccountClosure $closure, User $user): array
    {
        $pending = [];
        $workspaceIds = DB::table('workspaces')->where('owner_user_id', $user->id)->pluck('id')->all();

        if ($workspaceIds === []) {
            $this->record($closure, 'cancel_billing', 'done', 'No Workspaces, so no subscriptions.');

            return [];
        }

        foreach (PlatformSubscription::query()->whereIn('workspace_id', $workspaceIds)->get() as $sub) {
            if (! $this->assessor->platformSubscriptionIsLive($sub)) {
                continue;
            }

            try {
                $this->cancelPlatformSubscription($closure, $sub);
            } catch (PlatformBillingException) {
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

            try {
                $this->cancelAgencySubscription($closure, $agency, $sub);
            } catch (AgencyBillingException) {
                $pending[] = 'Agency client subscription ' . $sub->uid . ' could not be cancelled with the payment provider yet; it will be retried. It may still be charging.';
            }
        }

        foreach (AdditionalBusinessSlotAgreement::query()->whereIn('workspace_id', $workspaceIds)->get() as $agreement) {
            if (! $this->assessor->slotAgreementIsCharging($agreement)) {
                continue;
            }

            try {
                app(UsageBillingCheckoutManager::class)->requestSlotAgreementCancellation($agreement, (int) $closure->requested_by_user_id, 'Account closure: ' . $closure->reason);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'Slot agreement #' . $agreement->id . ' could not be set to stop renewing yet; it will be retried.';
            }
        }

        DB::table('business_usage_wallets')
            ->whereIn('business_id', fn ($q) => $q->select('id')->from('businesses')->whereIn('workspace_id', $workspaceIds))
            ->where('auto_recharge_enabled', true)
            ->update(['auto_recharge_enabled' => false, 'updated_at' => now()]);

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

        try {
            app(PlatformSubscriptionFinalizer::class)->apply($sub, $snapshot);
        } catch (Throwable $e) {
            // The provider has ended it; mirror that fact locally even if the
            // entitlement writers refuse (e.g. the Workspace is already retired).
            report($e);
            PlatformSubscription::query()->whereKey($sub->id)->update([
                'status' => $snapshot->status->value,
                'canceled_at' => $snapshot->canceledAt ?? now(),
                'ended_at' => $snapshot->endedAt ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function cancelAgencySubscription(PlatformAccountClosure $closure, AgencyStripeGateway $gateway, AgencyClientSubscription $sub): void
    {
        $account = (string) $sub->connected_account_id;
        $id = (string) $sub->provider_subscription_id;

        $snapshot = $gateway->retrieveSubscription($account, $id);

        if ($snapshot->status !== \App\Enums\AgencyBilling\AgencyClientSubscriptionStatus::Canceled && $snapshot->status !== \App\Enums\AgencyBilling\AgencyClientSubscriptionStatus::IncompleteExpired) {
            $snapshot = $gateway->cancelSubscriptionNow($account, $id, 'account-closure:' . $closure->uid . ':agency:' . $sub->uid);
        }

        if ($snapshot->status !== \App\Enums\AgencyBilling\AgencyClientSubscriptionStatus::Canceled && $snapshot->status !== \App\Enums\AgencyBilling\AgencyClientSubscriptionStatus::IncompleteExpired) {
            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        }

        try {
            app(AgencyClientSubscriptionFinalizer::class)->apply($sub, $snapshot);
        } catch (Throwable $e) {
            report($e);
            AgencyClientSubscription::query()->whereKey($sub->id)->update([
                'status' => $snapshot->status->value,
                'canceled_at' => $snapshot->canceledAt ?? now(),
                'ended_at' => $snapshot->endedAt ?? now(),
                'updated_at' => now(),
            ]);
        }
    }

    // -- step 3 -----------------------------------------------------------------

    /**
     * @return array<int, string> outstanding items
     */
    private function haltActivity(PlatformAccountClosure $closure, User $user): array
    {
        $pending = [];
        $actorId = (int) $closure->requested_by_user_id;
        $workspaceIds = DB::table('workspaces')->where('owner_user_id', $user->id)->pluck('id')->all();
        $businessIds = $workspaceIds === [] ? [] : DB::table('businesses')->whereIn('workspace_id', $workspaceIds)->pluck('id')->all();

        // Per-user legacy work.
        DB::table('campaigns')->where('user_id', $user->id)->whereIn('status', PlatformAccountAssessor::RUNNING_CAMPAIGN_STATUSES)
            ->update(['status' => 'cancelled', 'updated_at' => now()]);
        DB::table('automations')->where('user_id', $user->id)->where('status', 'active')
            ->update(['status' => 'inactive', 'updated_at' => now()]);
        DB::table('schedule_messages')->where('user_id', $user->id)->where('schedule_on', '>=', now()->subMinutes(3))->delete();

        if ($workspaceIds !== []) {
            // Stop access first, then work, so nothing is admitted in between.
            DB::table('workspace_memberships')->whereIn('workspace_id', $workspaceIds)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('workspaces')->whereIn('id', $workspaceIds)->update(['is_active' => false, 'updated_at' => now()]);
            DB::table('businesses')->whereIn('workspace_id', $workspaceIds)->where('status', '!=', 'inactive')->update(['status' => 'inactive', 'updated_at' => now()]);

            foreach ($workspaceIds as $workspaceId) {
                DB::table('workspace_usage_controls')->updateOrInsert(
                    ['workspace_id' => $workspaceId],
                    ['paid_activity_paused_at' => now(), 'paid_activity_paused_by_user_id' => $actorId, 'updated_at' => now(), 'created_at' => now()],
                );
            }

            DB::table('agency_prospect_campaigns')->whereIn('workspace_id', $workspaceIds)->where('status', 'active')->update(['status' => 'paused', 'updated_at' => now()]);
            DB::table('agency_prospects')->whereIn('workspace_id', $workspaceIds)->where('status', 'active')->update(['status' => 'stopped', 'updated_at' => now()]);

            $this->terminateRelationships($workspaceIds, $actorId, $closure);
        }

        if ($businessIds !== []) {
            DB::table('automation_workflows')->whereIn('business_id', $businessIds)->where('status', 'published')->update(['status' => 'paused', 'updated_at' => now()]);
            DB::table('websites')->whereIn('business_id', $businessIds)->where('status', 'published')->update(['status' => 'archived', 'updated_at' => now()]);
            DB::table('form_deployments')->whereIn('form_id', fn ($q) => $q->select('id')->from('forms')->whereIn('business_id', $businessIds))->update(['is_enabled' => false, 'updated_at' => now()]);

            $pending = array_merge($pending, $this->disconnectProviders($businessIds, $actorId));
        }

        foreach (ExternalCalendarConnection::query()->where('user_id', $user->id)->whereIn('state', ['pending', 'active'])->get() as $connection) {
            try {
                app(ExternalCalendarConnectionManager::class)->disconnect($connection, $actorId);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'A calendar connection could not be disconnected yet; it will be retried.';
            }
        }

        $this->record($closure, 'halt_activity', $pending === [] ? 'done' : 'pending', $pending === [] ? 'Workspaces, Businesses, outreach, campaigns, schedules, automations, websites and provider connections stopped.' : count($pending) . ' item(s) outstanding.');

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
    private function disconnectProviders(array $businessIds, int $actorId): array
    {
        $pending = [];

        foreach (BusinessGoogleConnection::query()->whereIn('business_id', $businessIds)->whereIn('state', ['pending', 'active'])->get() as $connection) {
            try {
                app(GoogleBusinessProfileConnectionManager::class)->disconnect($connection, $actorId);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'A Google connection could not be disconnected yet; it will be retried.';
            }
        }

        foreach (BusinessMetaConnection::query()->whereIn('business_id', $businessIds)->whereIn('state', ['pending', 'active'])->get() as $connection) {
            try {
                app(MetaAdsConnectionManager::class)->disconnect($connection, $actorId);
            } catch (Throwable $e) {
                report($e);
                $pending[] = 'A Meta connection could not be disconnected yet; it will be retried.';
            }
        }

        foreach (BusinessEmailAccount::query()->whereIn('business_id', $businessIds)->whereIn('state', ['pending', 'active'])->get() as $account) {
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
        $assessment = $this->assessor->assess($user);

        if ($assessment['connected_services']['carrier_numbers'] > 0) {
            $pending[] = $assessment['connected_services']['carrier_numbers'] . ' messaging number(s) are still held at the carrier. Release them through the Messaging Numbers lifecycle, then retry.';
        }

        if ($assessment['billing_commitments']['charging'] > 0 && $pending === []) {
            // Defence in depth: the assessor still sees something able to charge.
            $pending[] = 'A subscription or agreement is still able to charge; it will be retried.';
        }

        if ($pending !== []) {
            $closure->forceFill([
                'pending_items' => array_values(array_unique($pending)),
                'last_error' => implode(' ', array_values(array_unique($pending))),
            ])->save();

            $this->record($closure, 'finalize', 'pending', 'Waiting on provider or accounting prerequisites.');
            $this->audit->record((int) $closure->requested_by_user_id, 'user.closure_pending', 'user', $user->uid, 'Closure of ' . $user->email . ' is pending: ' . count($pending) . ' item(s) outstanding');

            return;
        }

        if ($assessment['verdict'] === PlatformAccountAssessor::VERDICT_DELETE) {
            $this->erase($closure, $user);

            return;
        }

        $closure->forceFill([
            'state' => AccountClosureState::Closed,
            'outcome' => 'closed_retained',
            'pending_items' => [],
            'last_error' => null,
            'completed_at' => now(),
        ])->save();

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

                $closure->forceFill([
                    'state' => AccountClosureState::Deleted,
                    'outcome' => 'deleted',
                    'user_id' => null,
                    'pending_items' => [],
                    'last_error' => null,
                    'completed_at' => now(),
                    'preflight' => null,
                ])->save();
            }, 3);
        } catch (AccountClosureRefused $e) {
            // Became ineligible between the check and the lock: close instead.
            $closure->forceFill(['state' => AccountClosureState::Closed, 'outcome' => 'closed_retained', 'pending_items' => [], 'last_error' => null, 'completed_at' => now()])->save();
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
        $closure->forceFill(['steps' => $steps])->save();
    }
}
