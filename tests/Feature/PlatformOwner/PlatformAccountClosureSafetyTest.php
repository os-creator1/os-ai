<?php

namespace Tests\Feature\PlatformOwner;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\PlatformOwner\AccountClosureState;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\PlatformBilling\PlatformSubscriptionFinalizer;
use App\Library\PlatformOwner\AccountDeletion\AccountClosureClaimLost;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountAssessor;
use App\Library\PlatformOwner\AccountDeletion\PlatformAccountClosureManager;
use App\Library\Workspace\WorkspaceManager;
use App\Models\PlatformAccountClosure;
use App\Models\PlatformAdminAction;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Eloquent\EloquentCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * Review corrections for account deletion:
 *  1. the legacy customer hard delete is retired for EVERY customer;
 *  2. billable activity is locked down BEFORE any provider call, so a provider
 *     failure can never leave members able to keep working;
 *  3. a provider-confirmed cancellation is recorded ONLY through the
 *     authoritative finalizer, and stays pending until that succeeds;
 *  4. the run's lease cannot expire under an active run, and a run that loses
 *     it can write or erase nothing.
 */
class PlatformAccountClosureSafetyTest extends TestCase
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

    private function request(User $user): PlatformAccountClosure
    {
        $fingerprint = app(PlatformAccountAssessor::class)->assess($user)['fingerprint'];

        return $this->manager()->request($this->admin->id, $user->uid, $user->email, 'Customer request', $fingerprint);
    }

    /** A subscribed Workspace + Business + one staff member. */
    private function billedTenantWithStaff(): array
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $business = $this->addBusiness($fixture['customer'], $fixture['workspace'], 'Billed Studio');
        $staff = $this->createCustomer();
        $this->member($fixture['workspace'], $staff->user, WorkspaceMembershipRole::Admin);

        return [$fixture, $business, $staff->user];
    }

    // -- 1. the legacy hard delete is retired for everyone -----------------------

    public function test_legacy_customer_delete_is_retired_even_for_a_customer_without_workspaces_and_keeps_invoices(): void
    {
        $customer = $this->createCustomer();
        $user = $customer->user;
        $this->assertSame(0, Workspace::where('owner_user_id', $user->id)->count());

        $currencyId = $this->fixtureCurrencyId();
        $methodId = DB::table('payment_methods')->insertGetId(['uid' => (string) Str::uuid(), 'name' => 'Card', 'type' => 'stripe', 'status' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $invoiceId = DB::table('invoices')->insertGetId([
            'uid' => (string) Str::uuid(), 'user_id' => $user->id, 'currency_id' => $currencyId, 'payment_method' => $methodId,
            'amount' => '49.00', 'type' => 'subscribe', 'status' => 'paid', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deleteJson(route('admin.customers.destroy', $user->uid))
            ->assertOk()->assertJsonPath('status', 'error')
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Platform Owner > Users'));

        $this->postJson(route('admin.customers.batch_action'), ['action' => 'delete', 'ids' => [$user->uid]])
            ->assertOk()->assertJsonPath('status', 'error');

        $this->assertTrue(User::whereKey($user->id)->exists());
        $this->assertTrue(DB::table('customers')->where('user_id', $user->id)->exists());
        $this->assertTrue(DB::table('invoices')->where('id', $invoiceId)->exists(), 'historical invoices must survive');

        // And the repository itself no longer hard-deletes.
        $this->expectException(\App\Exceptions\GeneralException::class);
        app(EloquentCustomerRepository::class)->destroy($user);
    }

    public function test_legacy_single_toggle_cannot_reactivate_a_closed_account(): void
    {
        [$fixture] = $this->billedTenantWithStaff();
        $owner = $fixture['customer']->user;
        $closure = $this->request($owner);
        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertFalse((bool) $owner->fresh()->status);

        $this->postJson(route('admin.customers.active', $owner->uid))
            ->assertOk()->assertJsonPath('status', 'error');
        $this->assertFalse((bool) $owner->fresh()->status);
    }

    public function test_legacy_bulk_enable_rejects_mixed_batch_atomically(): void
    {
        [$fixture] = $this->billedTenantWithStaff();
        $closed = $fixture['customer']->user;
        $this->assertSame(AccountClosureState::Closed, $this->request($closed)->state);
        $ordinary = $this->createCustomer()->user;
        $ordinary->forceFill(['status' => false])->save();

        \Illuminate\Support\Facades\Event::fake([\App\Events\Account\UserAccountStatusChanged::class]);
        $this->postJson(route('admin.customers.batch_action'), [
            'action' => 'enable', 'ids' => [$closed->uid, $ordinary->uid],
        ])->assertOk()->assertJsonPath('status', 'error');
        $this->assertFalse((bool) $closed->fresh()->status);
        $this->assertFalse((bool) $ordinary->fresh()->status);
        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\Account\UserAccountStatusChanged::class);

        $this->postJson(route('admin.customers.batch_action'), [
            'action' => 'enable', 'ids' => [$ordinary->uid],
        ])->assertOk()->assertJsonPath('status', 'success');
        $this->assertTrue((bool) $ordinary->fresh()->status);
    }

    // -- 2. lock down BEFORE the provider call ------------------------------------

    public function test_billable_activity_is_already_locked_down_when_the_provider_is_called(): void
    {
        [$fixture, $business, $staff] = $this->billedTenantWithStaff();
        $owner = $fixture['customer']->user;
        DB::table('campaigns')->insert(['uid' => uniqid(), 'user_id' => $owner->id, 'campaign_name' => 'Blast', 'message' => 'hi', 'sms_type' => 'plain', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now()]);
        $automationId = DB::table('automations')->insertGetId(['uid' => uniqid(), 'name' => 'Charge', 'user_id' => $owner->id, 'business_id' => $business->id, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue((bool) DB::table('workspace_memberships')->where('user_id', $staff->id)->value('is_active'));

        $seen = null;
        $this->stripe->hooks['cancelSubscriptionNow'] = function () use ($fixture, $business, $staff, $owner, $automationId, &$seen) {
            $seen = [
                'workspace_active' => (bool) Workspace::find($fixture['workspace']->id)->is_active,
                'business' => DB::table('businesses')->where('id', $business->id)->value('status'),
                'staff_access' => app(WorkspaceManager::class)->userCanAccessBusiness($staff->id, $business->fresh()),
                'staff_membership_active' => (bool) DB::table('workspace_memberships')->where('user_id', $staff->id)->value('is_active'),
                'owner_enabled' => (bool) User::find($owner->id)->status,
                'campaign' => DB::table('campaigns')->where('user_id', $owner->id)->value('status'),
                'automation' => DB::table('automations')->where('id', $automationId)->value('status'),
                'paid_paused' => DB::table('workspace_usage_controls')->where('workspace_id', $fixture['workspace']->id)->value('paid_activity_paused_at') !== null,
            ];
        };

        $this->request($owner);

        $this->assertSame([
            'workspace_active' => false, 'business' => 'inactive', 'staff_access' => false, 'staff_membership_active' => false,
            'owner_enabled' => false, 'campaign' => 'cancelled', 'automation' => 'inactive', 'paid_paused' => true,
        ], $seen);
    }

    public function test_an_unexpected_provider_exception_still_leaves_everything_locked_and_retries_safely(): void
    {
        [$fixture, $business, $staff] = $this->billedTenantWithStaff();
        $owner = $fixture['customer']->user;

        // Not a billing exception: anything at all going wrong at the provider.
        $this->stripe->hooks['cancelSubscriptionNow'] = fn () => throw new \RuntimeException('network exploded');

        $closure = $this->request($owner);

        $this->assertSame(AccountClosureState::Closing, $closure->state);
        $this->assertStringContainsString('may still be charging', implode(' ', $closure->pending_items));
        $this->assertFalse((bool) Workspace::find($fixture['workspace']->id)->is_active);
        $this->assertFalse(app(WorkspaceManager::class)->userCanAccessBusiness($staff->id, $business->fresh()));
        $this->assertFalse((bool) User::find($owner->id)->status);

        unset($this->stripe->hooks['cancelSubscriptionNow']);
        $closure = $this->manager()->resume($closure->uid, $this->admin->id);

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertSame(PlatformSubscriptionStatus::Canceled, PlatformSubscription::where('workspace_id', $fixture['workspace']->id)->first()->status);
        $this->assertCount(1, array_unique(array_map(fn ($c) => $c['args']['idempotency_key'], $this->stripe->callsOf('cancelSubscriptionNow'))));
    }

    // -- 3. reconciliation only through the authoritative finalizer ----------------

    public function test_provider_success_followed_by_finalizer_failure_stays_pending_and_never_writes_status_directly(): void
    {
        [$fixture] = $this->billedTenantWithStaff();
        $owner = $fixture['customer']->user;
        $this->app->bind(PlatformSubscriptionFinalizer::class, fn () => new class {
            public function apply(...$args): string
            {
                throw new \RuntimeException('finalizer unavailable');
            }
        });

        $closure = $this->request($owner);

        // Stripe really cancelled it...
        $this->assertCount(1, $this->stripe->callsOf('cancelSubscriptionNow'));
        $this->assertSame(PlatformSubscriptionStatus::Canceled, $this->stripe->subscriptionSnapshot($fixture['provider_subscription_id'])->status);
        // ...but the closure is NOT complete, and local state was not forged.
        $this->assertSame(AccountClosureState::Closing, $closure->state);
        $this->assertNull($closure->completed_at);
        $this->assertStringContainsString('recording that locally failed', implode(' ', $closure->pending_items));
        $local = PlatformSubscription::where('workspace_id', $fixture['workspace']->id)->first();
        $this->assertNotSame(PlatformSubscriptionStatus::Canceled, $local->status);
        $this->assertNull($local->canceled_at);
        $this->assertFalse(PlatformAdminAction::whereIn('action', ['user.closed', 'user.deleted'])->exists());

        // A finalizer that quietly ignores the snapshot is not reconciliation either.
        $this->app->bind(PlatformSubscriptionFinalizer::class, fn () => new class {
            public function apply(...$args): string
            {
                return 'ignored';
            }
        });
        $closure = $this->manager()->resume($closure->uid, $this->admin->id);
        $this->assertSame(AccountClosureState::Closing, $closure->state);

        // The finalizer recovers: the retry reads the provider (already ended),
        // reconciles through the real finalizer and does NOT cancel again.
        $this->app->bind(PlatformSubscriptionFinalizer::class, PlatformSubscriptionFinalizer::class);
        $closure = $this->manager()->resume($closure->uid, $this->admin->id);

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertSame(PlatformSubscriptionStatus::Canceled, PlatformSubscription::where('workspace_id', $fixture['workspace']->id)->first()->status);
        $this->assertCount(1, $this->stripe->callsOf('cancelSubscriptionNow'));
    }

    // -- 4. the lease -------------------------------------------------------------

    public function test_a_second_worker_cannot_run_while_the_first_is_inside_a_long_operation(): void
    {
        [$fixture] = $this->billedTenantWithStaff();
        $owner = $fixture['customer']->user;
        $secondWorkerSawClaim = null;

        $this->stripe->interleaveOnce('cancelSubscriptionNow', function () use ($owner, &$secondWorkerSawClaim) {
            // 200s into the provider call — inside the 300s lease because the run
            // renewed it immediately before calling out.
            $this->travel(200)->seconds();
            $closure = PlatformAccountClosure::where('user_uid', $owner->uid)->first();
            $secondWorkerSawClaim = $this->manager()->resume($closure->uid, $this->admin->id);
        });

        $closure = $this->request($owner);

        $this->assertSame(AccountClosureState::Closing, $secondWorkerSawClaim->state, 'the second worker must not run');
        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertCount(1, $this->stripe->callsOf('cancelSubscriptionNow'), 'only one worker performed the provider operation');
        $this->assertSame(1, $closure->attempts);
        $this->assertNull($closure->fresh()->claim_token, 'the lease is released at the end');
    }

    public function test_a_worker_that_lost_the_lease_stops_and_cannot_write_or_erase(): void
    {
        [$fixture] = $this->billedTenantWithStaff();
        $owner = $fixture['customer']->user;

        // Worker A stalls inside the provider call for longer than the lease; worker B takes over and finishes.
        $this->stripe->interleaveOnce('cancelSubscriptionNow', function () use ($owner) {
            $this->travel(400)->seconds();
            $closure = PlatformAccountClosure::where('user_uid', $owner->uid)->first();
            $this->manager()->resume($closure->uid, $this->admin->id);
        });

        $closure = $this->request($owner);

        $this->assertSame(AccountClosureState::Closed, $closure->state);
        $this->assertSame(2, $closure->attempts, 'A, then the successor B');
        $this->assertSame(1, PlatformAdminAction::where('action', 'user.closed')->where('subject_ref', $owner->uid)->count(), 'finished once, by B only');
        $this->assertNull($closure->claim_token);
        $this->assertSame(PlatformSubscriptionStatus::Canceled, PlatformSubscription::where('workspace_id', $fixture['workspace']->id)->first()->status);
    }

    public function test_a_stale_worker_cannot_erase_an_account(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Core, 'Clean Studio', 'Clean');
        $user = $customer->user;
        $fingerprint = app(PlatformAccountAssessor::class)->assess($user)['fingerprint'];

        // Open the closure and hand its lease to a "current" worker; the caller holds a stale token.
        $open = new ReflectionMethod($this->manager(), 'open');
        $open->setAccessible(true);
        $closure = $open->invoke($this->manager(), $this->admin->id, $user, 'Customer request', app(PlatformAccountAssessor::class)->assess($user));
        DB::table('platform_account_closures')->where('id', $closure->id)->update(['claim_token' => (string) Str::uuid(), 'claimed_at' => now()]);
        $stale = PlatformAccountClosure::find($closure->id);
        $stale->claim_token = (string) Str::uuid();

        $erase = new ReflectionMethod($this->manager(), 'erase');
        $erase->setAccessible(true);

        try {
            $erase->invoke($this->manager(), $stale, $user);
            $this->fail('A stale worker must not be able to erase.');
        } catch (AccountClosureClaimLost) {
            $this->addToAssertionCount(1);
        }

        $this->assertTrue(User::whereKey($user->id)->exists());
        $this->assertSame(AccountClosureState::Closing, PlatformAccountClosure::find($closure->id)->state);
        $this->assertNotEmpty($fingerprint);
    }
}
