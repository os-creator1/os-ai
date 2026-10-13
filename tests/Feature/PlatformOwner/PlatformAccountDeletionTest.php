<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\PlatformOwner\AccountClosureState;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Automation\AutomationEligibility;
use App\Library\PlatformOwner\AccountDeletion\AccountClosureRefused;
use App\Library\PlatformOwner\AccountDeletion\AccountRecordGraph;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountAssessor;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountClosureManager;
use App\Library\PlatformOwner\AccountDeletion\ProtectedAccountRecords;
use App\Models\Business;
use App\Models\PlatformAccountClosure;
use App\Models\PlatformAdminAction;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Platform Owner — customer account deletion / closure lifecycle
 * (PlatformAccountClosureManager). Runs on disposable MySQL with a FAKE
 * payment provider: no Stripe, no network.
 */
class PlatformAccountDeletionTest extends TestCase
{
    use RefreshDatabase;
    use PlatformOwnerFixtures;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        $this->admin = $this->actingAsPlatformOwner(array_merge($this->ownerPermissions, ['view customer', 'delete customer']));
    }

    private function manager(): PlatformAccountClosureManager
    {
        return app(PlatformAccountClosureManager::class);
    }

    private function assess(User $user): array
    {
        return app(PlatformAccountAssessor::class)->assess($user);
    }

    private function deleteAccount(User $user, string $reason = 'Customer asked to leave.', ?User $actor = null): PlatformAccountClosure
    {
        return $this->manager()->request(($actor ?? $this->admin)->id, $user->uid, $user->email, $reason, $this->assess($user)['fingerprint']);
    }

    private function exists(string $table, int $id): bool
    {
        return DB::table($table)->where('id', $id)->exists();
    }

    // -- complete deletion ----------------------------------------------------

    public function test_a_customer_with_one_workspace_and_one_business_is_completely_deleted(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        [, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Core, 'Other Studio', 'Other');
        $user = $customer->user;

        $assessment = $this->assess($user);
        $this->assertSame(PlatformAccountAssessor::VERDICT_DELETE, $assessment['verdict'], implode(' | ', $assessment['reasons']));

        $closure = $this->deleteAccount($user);

        $this->assertSame(AccountClosureState::Deleted, $closure->state);
        $this->assertSame('deleted', $closure->outcome);
        $this->assertNull($closure->user_id);
        $this->assertFalse($this->exists('users', $user->id));
        $this->assertFalse($this->exists('workspaces', $workspace->id));
        $this->assertFalse($this->exists('businesses', $business->id));
        $this->assertSame(0, DB::table('business_locations')->where('business_id', $business->id)->count());
        $this->assertSame(0, DB::table('workspace_plan_assignments')->where('workspace_id', $workspace->id)->count());

        // The unrelated tenant is untouched.
        $this->assertTrue($this->exists('workspaces', $otherWorkspace->id));
        $this->assertTrue($this->exists('businesses', $otherBusiness->id));
        $this->assertTrue(Workspace::find($otherWorkspace->id)->is_active);

        // The deletion is audited without the user row.
        $this->assertTrue(PlatformAdminAction::query()->where('action', 'user.deleted')->where('subject_ref', $user->uid)->exists());
    }

    public function test_a_customer_with_several_owned_workspaces_loses_all_of_them_and_nothing_else(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'First Studio', 'First');
        $second = $this->createIndependentWorkspaceBusiness($customer, 'Second Studio', 'Second');
        [, , $stranger] = $this->tenant(WorkspacePlanTier::Core, 'Stranger Studio', 'Stranger');

        $assessment = $this->assess($customer->user);
        $this->assertCount(2, $assessment['owned_workspaces']);
        $this->assertSame(PlatformAccountAssessor::VERDICT_DELETE, $assessment['verdict'], implode(' | ', $assessment['reasons']));

        $this->assertSame(AccountClosureState::Deleted, $this->deleteAccount($customer->user)->state);

        $this->assertFalse($this->exists('workspaces', $workspace->id));
        $this->assertFalse($this->exists('workspaces', $second['workspace']->id));
        $this->assertFalse($this->exists('businesses', $second['business']->id));
        $this->assertTrue($this->exists('workspaces', $stranger->id));
    }

    public function test_a_member_of_someone_elses_workspace_loses_only_their_seat(): void
    {
        [$owner, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $staffCustomer = $this->createCustomer();
        $membership = $this->member($workspace, $staffCustomer->user, WorkspaceMembershipRole::Staff);

        $assessment = $this->assess($staffCustomer->user);
        $this->assertSame([], $assessment['owned_workspaces']);
        $this->assertCount(1, $assessment['memberships_elsewhere']);

        $closure = $this->deleteAccount($staffCustomer->user);

        $this->assertSame(AccountClosureState::Deleted, $closure->state);
        $this->assertFalse($this->exists('users', $staffCustomer->user->id));
        $this->assertFalse($this->exists('workspace_memberships', $membership->id));
        // Somebody else's Workspace, Business and owner are intact and active.
        $this->assertTrue(Workspace::find($workspace->id)->is_active);
        $this->assertTrue($this->exists('businesses', $business->id));
        $this->assertTrue((bool) User::find($owner->user_id)->status);
    }

    public function test_an_agency_owner_is_deleted_without_deleting_independently_owned_clients(): void
    {
        $fixture = $this->createAgencyManagedClient();
        $agencyUser = $fixture['agencyOwner']->user;

        $assessment = $this->assess($agencyUser);
        $this->assertCount(1, $assessment['agency']['clients']);
        $this->assertSame($fixture['clientOwner']->user->email, $assessment['agency']['clients'][0]['owner_email']);

        $closure = $this->deleteAccount($agencyUser);

        $this->assertSame(AccountClosureState::Deleted, $closure->state, json_encode($closure->pending_items));
        $this->assertFalse($this->exists('workspaces', $fixture['agencyWorkspace']->id));
        $this->assertSame(0, DB::table('agency_client_workspace_relationships')->where('agency_workspace_id', $fixture['agencyWorkspace']->id)->count());

        // The client keeps its account, Workspace and Business.
        $this->assertTrue($this->exists('users', $fixture['clientOwner']->user->id));
        $this->assertTrue($this->exists('workspaces', $fixture['clientWorkspace']->id));
        $this->assertTrue($this->exists('businesses', $fixture['clientBusiness']->id));
        $this->assertTrue(Workspace::find($fixture['clientWorkspace']->id)->is_active);
    }

    // -- subscriptions: cancellation, failure, retry ----------------------------

    public function test_an_active_subscription_is_cancelled_at_the_provider_and_the_account_is_closed_not_deleted(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $user = $fixture['customer']->user;
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');

        $assessment = $this->assess($user);
        $this->assertSame(PlatformAccountAssessor::VERDICT_CLOSE, $assessment['verdict']);
        $this->assertSame(1, $assessment['billing_commitments']['charging']);

        $closure = $this->deleteAccount($user);

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertSame('closed_retained', $closure->outcome);

        // Cancelled through the provider boundary, with a closure-scoped idempotency key.
        $calls = $this->stripe->callsOf('cancelSubscriptionNow');
        $this->assertCount(1, $calls);
        $this->assertSame($fixture['provider_subscription_id'], $calls[0]['args']['subscription']);
        $this->assertStringStartsWith('account-closure:' . $closure->uid, $calls[0]['args']['idempotency_key']);
        $this->assertSame(PlatformSubscriptionStatus::Canceled, $this->stripe->subscriptionSnapshot($fixture['provider_subscription_id'])->status);

        // The local subscription row is kept (history), and says canceled.
        $sub = PlatformSubscription::query()->where('workspace_id', $fixture['workspace']->id)->firstOrFail();
        $this->assertSame(PlatformSubscriptionStatus::Canceled, $sub->status);

        // The account is retired but its protected records and the user row remain.
        $this->assertFalse((bool) User::find($user->id)->status);
        $this->assertFalse((bool) Workspace::find($fixture['workspace']->id)->is_active);
        $this->assertSame('inactive', Business::find($business->id)->status->value);
        $this->assertTrue($this->exists('platform_subscriptions', $sub->id));
        $this->assertTrue(PlatformAdminAction::query()->where('action', 'user.closed')->where('subject_ref', $user->uid)->exists());
        $this->assertFalse(PlatformAdminAction::query()->where('action', 'user.deleted')->exists());
    }

    public function test_a_failed_cancellation_is_never_reported_as_complete_and_retries_converge(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $user = $fixture['customer']->user;
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');

        $this->stripe->failWith = PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);

        $closure = $this->deleteAccount($user);

        // Access is blocked immediately, but the lifecycle is explicitly unfinished.
        $this->assertSame(AccountClosureState::Closing, $closure->state);
        $this->assertNull($closure->completed_at);
        $this->assertNotEmpty($closure->pending_items);
        $this->assertStringContainsString('may still be charging', implode(' ', $closure->pending_items));
        $this->assertFalse((bool) User::find($user->id)->status);
        $this->assertFalse((bool) Workspace::find($fixture['workspace']->id)->is_active);
        $this->assertNotSame(PlatformSubscriptionStatus::Canceled, PlatformSubscription::query()->where('workspace_id', $fixture['workspace']->id)->first()->status);
        $this->assertFalse(PlatformAdminAction::query()->whereIn('action', ['user.closed', 'user.deleted'])->exists());

        // The provider recovers; a retry completes it.
        $this->stripe->failWith = null;
        $closure = $this->manager()->resume($closure->uid, $this->admin->id);

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertSame([], $closure->pending_items);
        $this->assertSame(PlatformSubscriptionStatus::Canceled, PlatformSubscription::query()->where('workspace_id', $fixture['workspace']->id)->first()->status);

        // Every attempt used the SAME idempotency key.
        $keys = array_unique(array_map(fn ($c) => $c['args']['idempotency_key'], $this->stripe->callsOf('cancelSubscriptionNow')));
        $this->assertCount(1, $keys);
    }

    public function test_an_uncertain_provider_outcome_is_converged_without_cancelling_twice(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $user = $fixture['customer']->user;
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');

        // Stripe cancelled it, but our earlier attempt never saw the answer.
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::Canceled);

        $closure = $this->deleteAccount($user);

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertCount(0, $this->stripe->callsOf('cancelSubscriptionNow'));
        $this->assertSame(PlatformSubscriptionStatus::Canceled, PlatformSubscription::query()->where('workspace_id', $fixture['workspace']->id)->first()->status);
    }

    // -- protected financial records -------------------------------------------

    public function test_a_wallet_balance_and_ledger_are_retained_and_force_closure_not_deletion(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $currencyId = $this->fixtureCurrencyId();

        $walletId = DB::table('business_usage_wallets')->where('business_id', $business->id)->value('id')
            ?? app(\App\Library\Usage\UsageWalletManager::class)->initializeWalletForNewBusiness($business->id)?->id
            ?? DB::table('business_usage_wallets')->where('business_id', $business->id)->value('id');
        $this->assertNotNull($walletId);

        DB::table('business_usage_wallets')->where('id', $walletId)->update(['available_balance_micro' => 5_000_000]);
        $entryId = DB::table('business_usage_ledger_entries')->insertGetId([
            'business_id' => $business->id,
            'wallet_id' => $walletId,
            'entry_type' => 'usage_charge_reversal',
            'available_delta_micro' => 5_000_000,
            'reserved_delta_micro' => 0,
            'debt_delta_micro' => 0,
            'currency_id' => $currencyId,
            'correlation_key' => 'closure-test-' . uniqid(),
            'created_at' => now(),
        ]);

        $assessment = $this->assess($customer->user);
        $this->assertSame(PlatformAccountAssessor::VERDICT_CLOSE, $assessment['verdict']);
        $labels = array_column($assessment['protected_records'], 'label');
        $this->assertContains('Usage ledger entries', $labels);
        $this->assertContains('Usage wallet holding a balance or debt', $labels);

        $closure = $this->deleteAccount($customer->user);

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        // Nothing financial was erased, and no constraint was loosened to allow it.
        $this->assertTrue($this->exists('business_usage_ledger_entries', $entryId));
        $this->assertSame(5_000_000, (int) DB::table('business_usage_wallets')->where('id', $walletId)->value('available_balance_micro'));
        $this->assertTrue($this->exists('businesses', $business->id));
        $this->assertTrue($this->exists('users', $customer->user->id));
        $this->assertFalse((bool) DB::table('business_usage_wallets')->where('id', $walletId)->value('auto_recharge_enabled'));
    }

    public function test_the_account_graph_never_selects_another_owners_rows(): void
    {
        // An appointment assigned to this user in somebody else's Business is not
        // theirs to destroy: it blocks hard deletion (and closes instead).
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        [, $otherBusiness] = $this->tenant(WorkspacePlanTier::Core, 'Other Studio', 'Other');

        $graph = app(AccountRecordGraph::class);
        $selection = $graph->discover($customer->user->id, Workspace::where('owner_user_id', $customer->user->id)->pluck('id')->all());
        $this->assertSame([], $selection->blockers);
        $this->assertSame([], $selection->unsupported);
        $this->assertTrue($selection->count('users') === 1);
        $this->assertNotContains($otherBusiness->id, $selection->idsOf('businesses'));
    }

    // -- running work and access ------------------------------------------------

    public function test_running_work_is_stopped_and_access_is_revoked(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $user = $customer->user;
        $currencyId = $this->fixtureCurrencyId();

        // Retain-forcing record, so the rows we assert on survive.
        $walletId = app(\App\Library\Usage\UsageWalletManager::class)->initializeWalletForNewBusiness($business->id)?->id
            ?? DB::table('business_usage_wallets')->where('business_id', $business->id)->value('id');
        DB::table('business_usage_wallets')->where('id', $walletId)->update(['available_balance_micro' => 1_000_000, 'auto_recharge_enabled' => true]);

        $user->forceFill(['api_token' => 'tok_' . uniqid(), 'remember_token' => 'remember'])->save();
        DB::table('campaigns')->insert(['uid' => uniqid(), 'user_id' => $user->id, 'campaign_name' => 'Blast', 'message' => 'hi', 'sms_type' => 'plain', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('schedule_messages')->insert(['uid' => uniqid(), 'user_id' => $user->id, 'from' => 'X', 'to' => '+15550001', 'message' => 'later', 'sms_type' => 'plain', 'status' => 'scheduled', 'schedule_on' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $automationId = DB::table('automations')->insertGetId(['uid' => uniqid(), 'name' => 'Charge trigger', 'user_id' => $user->id, 'business_id' => $business->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $closure = $this->deleteAccount($user);

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $fresh = User::find($user->id);
        $this->assertFalse((bool) $fresh->status);
        $this->assertNull($fresh->api_token);
        $this->assertNull($fresh->remember_token);
        $this->assertSame('cancelled', DB::table('campaigns')->where('user_id', $user->id)->value('status'));
        $this->assertSame(0, DB::table('schedule_messages')->where('user_id', $user->id)->count());
        $this->assertSame('inactive', DB::table('automations')->where('id', $automationId)->value('status'));
        $this->assertFalse((bool) DB::table('business_usage_wallets')->where('id', $walletId)->value('auto_recharge_enabled'));
        $this->assertNotNull(DB::table('workspace_usage_controls')->where('workspace_id', $workspace->id)->value('paid_activity_paused_at'));

        // A job already queued for that automation is refused at its checkpoint.
        $reason = null;
        $this->assertNull(app(AutomationEligibility::class)->resolve($automationId, $reason));
        $this->assertContains($reason, ['automation_disabled', 'business_inactive', 'workspace_inactive']);
    }

    // -- idempotency, retries, forged and unauthorized requests -----------------

    public function test_repeating_a_request_for_a_deleted_or_closed_account_is_a_safe_no_op(): void
    {
        [$gone] = $this->tenant(WorkspacePlanTier::Core, 'Gone Studio', 'Gone');
        $first = $this->deleteAccount($gone->user);
        $this->assertSame(AccountClosureState::Deleted, $first->state);

        $second = $this->manager()->request($this->admin->id, $gone->user->uid, $gone->user->email, 'Again', str_repeat('a', 64));
        $this->assertSame($first->id, $second->id);
        $this->assertSame(AccountClosureState::Deleted, $second->state);
        $this->assertSame(1, PlatformAdminAction::query()->where('action', 'user.deleted')->count());

        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');
        $closed = $this->deleteAccount($fixture['customer']->user);
        $this->assertSame(AccountClosureState::Closed, $closed->state);
        $calls = count($this->stripe->calls);

        $again = $this->manager()->request($this->admin->id, $fixture['customer']->user->uid, $fixture['customer']->user->email, 'Again', str_repeat('b', 64));
        $this->assertSame($closed->id, $again->id);
        $this->assertSame(AccountClosureState::Closed, $again->state);
        $this->assertCount($calls, $this->stripe->calls, 'a repeat must not touch the provider');
        $this->assertSame(1, PlatformAccountClosure::query()->where('user_uid', $fixture['customer']->user->uid)->count());
    }

    public function test_a_non_administrator_cannot_delete_an_account(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core);
        [$other] = $this->tenant(WorkspacePlanTier::Core, 'Other Studio', 'Other');

        $this->expectException(AuthorizationException::class);

        try {
            $this->manager()->request($other->user->id, $customer->user->uid, $customer->user->email, 'Because', str_repeat('c', 64));
        } finally {
            $this->assertTrue($this->exists('users', $customer->user->id));
            $this->assertSame(0, PlatformAccountClosure::count());
        }
    }

    public function test_the_platform_owner_administrators_and_the_acting_user_are_protected(): void
    {
        $this->expectRefusal(fn () => $this->manager()->request($this->admin->id, $this->admin->uid, $this->admin->email, 'self', str_repeat('d', 64)));

        $otherAdmin = User::create([
            'first_name' => 'Second', 'last_name' => 'Admin', 'email' => 'second-admin@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->expectRefusal(fn () => $this->manager()->request($this->admin->id, $otherAdmin->uid, $otherAdmin->email, 'admin', str_repeat('d', 64)));

        $this->assertTrue($this->exists('users', $otherAdmin->id));
        $this->assertTrue((bool) $otherAdmin->fresh()->status);
        $this->assertSame(0, PlatformAccountClosure::count());
    }

    public function test_a_wrong_typed_email_a_missing_reason_and_a_stale_review_are_refused_before_anything_changes(): void
    {
        [$customer, , $workspace] = $this->tenant(WorkspacePlanTier::Core);
        [$other] = $this->tenant(WorkspacePlanTier::Core, 'Other Studio', 'Other');
        $user = $customer->user;
        $fingerprint = $this->assess($user)['fingerprint'];

        // Another account's email (cross-account / forged).
        $this->expectRefusal(fn () => $this->manager()->request($this->admin->id, $user->uid, $other->user->email, 'reason', $fingerprint));
        $this->expectRefusal(fn () => $this->manager()->request($this->admin->id, $user->uid, $user->email, 'no', $fingerprint));
        $this->expectRefusal(fn () => $this->manager()->request($this->admin->id, $user->uid, $user->email, 'reason', str_repeat('0', 64)));

        // The account changes after it was reviewed.
        $this->createIndependentWorkspaceBusiness($customer, 'New Studio', 'New');
        $this->expectRefusal(fn () => $this->manager()->request($this->admin->id, $user->uid, $user->email, 'reason', $fingerprint));

        $this->assertTrue((bool) User::find($user->id)->status);
        $this->assertTrue(Workspace::find($workspace->id)->is_active);
        $this->assertSame(0, PlatformAccountClosure::count());
    }

    public function test_a_closure_in_progress_cannot_be_reopened_by_reactivation(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');
        $this->stripe->failWith = PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        $closure = $this->deleteAccount($fixture['customer']->user);
        $this->assertSame(AccountClosureState::Closing, $closure->state);

        $this->expectException(\RuntimeException::class);
        app(\App\Library\PlatformOwner\PlatformUserActions::class)->reactivate($this->admin->id, $fixture['customer']->user->fresh(), 'oops');
    }

    public function test_the_scheduled_sweep_finishes_unfinished_closures(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');
        $this->stripe->failWith = PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        $closure = $this->deleteAccount($fixture['customer']->user);
        $this->assertSame(AccountClosureState::Closing, $closure->state);

        $this->stripe->failWith = null;
        $this->artisan('platform:advance-account-closures')->assertExitCode(0);

        $this->assertSame(AccountClosureState::Closed, $closure->fresh()->state);
    }

    // -- schema drift guards ------------------------------------------------------

    public function test_every_foreign_key_into_users_is_consciously_classified(): void
    {
        $classified = array_merge(AccountRecordGraph::USER_OWNED, AccountRecordGraph::USER_REFERENCES_THAT_BLOCK);
        $unclassified = [];

        foreach (app(AccountRecordGraph::class)->foreignKeys() as $fk) {
            if ($fk['parent'] !== 'users' || $fk['rule'] === 'SET NULL') {
                continue;
            }

            foreach ($fk['columns'] as $column) {
                if (! in_array($fk['child'] . '.' . $column, $classified, true)) {
                    $unclassified[] = $fk['child'] . '.' . $column;
                }
            }
        }

        $this->assertSame([], $unclassified, 'A new foreign key into users must be added to AccountRecordGraph::USER_OWNED or USER_REFERENCES_THAT_BLOCK.');
    }

    public function test_every_table_owned_by_a_workspace_or_business_is_inside_the_deletion_graph(): void
    {
        $reachable = app(AccountRecordGraph::class)->reachableTables();
        $outside = [];

        foreach (['workspace_id', 'business_id'] as $column) {
            foreach (DB::select('select table_name as t from information_schema.columns where table_schema = ? and column_name = ?', [DB::connection()->getDatabaseName(), $column]) as $row) {
                if (! in_array($row->t, $reachable, true)) {
                    $outside[$row->t] = true;
                }
            }
        }

        // Plain-column references (no FK) must be explicitly retained or explicitly owned.
        $declared = array_merge(array_keys(ProtectedAccountRecords::retainedByReference()), array_keys(ProtectedAccountRecords::ownedByReference()));
        $linked = array_diff(array_keys($outside), $declared);

        $this->assertSame([], array_values($linked), 'A table with a workspace_id/business_id column is not covered by the account deletion graph.');
    }

    public function test_the_whole_reachable_schema_has_a_valid_deletion_order(): void
    {
        $order = app(AccountRecordGraph::class)->orderForAllReachableTables();

        $this->assertContains('users', $order);
        $this->assertLessThan(array_search('users', $order, true), array_search('customers', $order, true));
        $this->assertLessThan(array_search('businesses', $order, true), array_search('business_locations', $order, true));
        $this->assertLessThan(array_search('workspaces', $order, true), array_search('businesses', $order, true));
        $this->assertLessThan(array_search('business_usage_wallets', $order, true), array_search('business_usage_ledger_entries', $order, true));
    }

    public function test_every_protected_and_classified_table_exists(): void
    {
        foreach (array_keys(ProtectedAccountRecords::definitions()) as $table) {
            $this->assertTrue(Schema::hasTable($table), "Protected table [{$table}] no longer exists.");
        }

        foreach (array_merge(AccountRecordGraph::USER_OWNED, AccountRecordGraph::USER_REFERENCES_THAT_BLOCK) as $key) {
            [$table, $column] = explode('.', $key);
            $this->assertTrue(Schema::hasColumn($table, $column), "[{$key}] no longer exists.");
        }
    }

    private function expectRefusal(callable $do): void
    {
        try {
            $do();
        } catch (AccountClosureRefused) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the request to be refused.');
    }
}
