<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Money\StripeMinorUnits;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 — the four defects whole-branch review found.
 *
 * Each block reproduces a state a real customer could reach, and asserts the
 * property that was untrue before the correction. None of them asserts a
 * shape for its own sake.
 */
class PlatformBillingWholeBranchTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
    }

    private function manager(): PlatformSubscriptionManager
    {
        return app(PlatformSubscriptionManager::class);
    }

    private function tierOf($workspace): ?WorkspacePlanTier
    {
        return app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier;
    }

    /**
     * Re-prices a tier ONTO A NEW IMMUTABLE PROVIDER PRICE, which is what
     * really happens: a Stripe Price's amount cannot be edited, so a reprice
     * always means a different Price id.
     */
    private function repriceOntoNewProviderPrice(WorkspacePlanTier $tier, string $price, string $newPriceId): WorkspacePlanCatalog
    {
        $catalog = WorkspacePlanCatalog::query()->where('tier', $tier->value)->firstOrFail();

        $catalog->forceFill(['price' => $price, 'provider_price_id' => $newPriceId])->save();

        $this->stripe->definePrice($newPriceId, [
            'currency' => 'USD',
            'unit_amount' => StripeMinorUnits::toMinor($price, 'USD'),
            'interval' => 'month',
            'interval_count' => 1,
            'livemode' => false,
        ]);

        return $catalog->refresh();
    }

    /** Ends a subscription for real: provider truth first, then our finalizer. */
    private function endSubscription(array $fixture): void
    {
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::Canceled);

        $this->manager()->applyProviderSubscription(
            $fixture['subscription'],
            $fixture['provider_subscription_id'],
            'evt_ended_' . Str::random(6),
            now(),
        );
    }

    // =====================================================================
    // 1 — CHECKOUT ATTEMPT REPLACEMENT MUST BE CONCURRENCY SAFE
    //
    // The invariant is a commercial one, not a tidiness one: AT MOST ONE
    // PAYABLE CHECKOUT SESSION EXISTS PER WORKSPACE. Two payable sessions
    // mean two subscriptions and two charges for one account.
    //
    // Every interleaving below is forced through the fake gateway's seams, at
    // exactly the instant a real request would be suspended on the network.
    // =====================================================================

    public function test_two_simultaneous_same_tier_checkouts_converge_on_one_payable_session(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $fixture = $this->unassignedWorkspace();

        // The SECOND request arrives while the first is waiting on Stripe.
        $this->stripe->interleaveOnce('createSubscriptionCheckout', function (): void {
            $this->manager()->startCheckout(
                Workspace::query()->sole(), WorkspacePlanCatalog::query()->where('tier', 'growth')->sole(),
                'owner@example.test', 'https://app.test/done', 'https://app.test/cancel',
            );
        });

        $result = $this->manager()->startCheckout(
            $fixture['workspace'], $catalog, 'owner@example.test', 'https://app.test/done', 'https://app.test/cancel',
        );

        $this->assertCount(1, $this->stripe->payableSessionIds(),
            'Two simultaneous retries of the SAME tier are one attempt, so they are one session.');

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame($result->sessionId, (string) $subscription->provider_checkout_session_id);
        $this->assertSame([$result->sessionId], $this->stripe->payableSessionIds());
    }

    public function test_two_simultaneous_different_tier_replacements_cannot_leave_two_payable_sessions(): void
    {
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '49.00');
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '99.00');
        $agency = $this->sellableTier(WorkspacePlanTier::Agency, price: '199.00');
        $fixture = $this->unassignedWorkspace();

        $first = $this->manager()->startCheckout(
            $fixture['workspace'], $core, 'owner@example.test', 'https://app.test/done', 'https://app.test/cancel',
        );

        // The customer picks Growth, and — while that request is inspecting the
        // old session at the provider — a second tab picks Agency. This is the
        // exact race the old code lost: both inspect the same open session,
        // both decide to replace it, and both mint.
        $this->stripe->interleaveOnce('retrieveCheckoutSession', function (): void {
            $this->manager()->startCheckout(
                Workspace::query()->sole(), WorkspacePlanCatalog::query()->where('tier', 'agency')->sole(),
                'owner@example.test', 'https://app.test/done', 'https://app.test/cancel',
            );
        });

        $winner = $this->manager()->startCheckout(
            $fixture['workspace'], $growth, 'owner@example.test', 'https://app.test/done', 'https://app.test/cancel',
        );

        $payable = $this->stripe->payableSessionIds();

        $this->assertCount(1, $payable, 'Two different-tier replacements may never leave two payable sessions.');
        $this->assertNotContains($first->sessionId, $payable, 'The abandoned Core session is retired.');

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame($payable[0], (string) $subscription->provider_checkout_session_id,
            'The database points at the ONE session that can still be paid.');
        $this->assertSame($winner->sessionId, $payable[0]);
        $this->assertSame((string) $growth->provider_price_id, (string) $subscription->checkout_attempt_price_id,
            'And at the attempt that actually survived.');
    }

    public function test_a_request_superseded_after_provider_creation_expires_its_stale_new_session(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $fixture = $this->unassignedWorkspace();

        // THE HARD CASE. The session is already created at the provider when
        // we discover somebody else replaced our attempt. Nothing local can
        // undo a payable session; only the provider can, so we must ask it to.
        $this->stripe->interleaveOnce('createSubscriptionCheckout', function (): void {
            DB::table('platform_subscriptions')->update([
                'checkout_attempt_uid' => (string) Str::uuid(),
                'checkout_attempt_generation' => DB::raw('checkout_attempt_generation + 1'),
            ]);
        });

        $result = $this->manager()->startCheckout(
            $fixture['workspace'], $catalog, 'owner@example.test', 'https://app.test/done', 'https://app.test/cancel',
        );

        $orphan = $this->stripe->callsOf('createSubscriptionCheckout')[0];
        $expired = array_column(array_column($this->stripe->callsOf('expireCheckoutSession'), 'args'), 'session');

        $this->assertNotSame($orphan['args']['idempotency_key'], (string) PlatformSubscription::query()->sole()->checkoutAttemptKey(),
            'The superseded attempt is not the one the row now holds.');
        $this->assertCount(1, $this->stripe->payableSessionIds(),
            'The session created for the superseded attempt must not stay payable.');
        $this->assertSame([$result->sessionId], $this->stripe->payableSessionIds());
        $this->assertNotEmpty($expired, 'The orphaned session is retired at the provider, not merely forgotten.');
        $this->assertNotContains($result->sessionId, $expired);

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame($result->sessionId, (string) $subscription->provider_checkout_session_id);
    }

    public function test_a_permanently_contended_checkout_gives_up_instead_of_spinning(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $fixture = $this->unassignedWorkspace();

        // Somebody supersedes this request on EVERY round. Bounded retry means
        // it refuses; unbounded recursion would never return.
        $this->stripe->hooks['createSubscriptionCheckout'] = function (): void {
            DB::table('platform_subscriptions')->update([
                'checkout_attempt_uid' => (string) Str::uuid(),
                'checkout_attempt_generation' => DB::raw('checkout_attempt_generation + 1'),
            ]);
        };

        try {
            $this->manager()->startCheckout(
                $fixture['workspace'], $catalog, 'owner@example.test', 'https://app.test/done', 'https://app.test/cancel',
            );
            $this->fail('A permanently contended checkout must refuse rather than loop forever.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::CHECKOUT_CONTENDED, $e->reason);
        }

        $this->assertSame([], $this->stripe->payableSessionIds(),
            'Every session it created on the way is retired, so giving up leaves nothing payable.');
        $this->assertLessThanOrEqual(3, count($this->stripe->callsOf('createSubscriptionCheckout')),
            'Bounded, not merely finite.');
    }

    public function test_sequential_checkout_behaviour_is_unchanged(): void
    {
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '49.00');
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '99.00');
        $fixture = $this->unassignedWorkspace();

        $first = $this->manager()->startCheckout(
            $fixture['workspace'], $core, 'o@example.test', 'https://app.test/done', 'https://app.test/cancel',
        );
        $retry = $this->manager()->startCheckout(
            $fixture['workspace'], $core, 'o@example.test', 'https://app.test/done', 'https://app.test/cancel',
        );

        $this->assertSame($first->sessionId, $retry->sessionId, 'A same-tier retry is still one session.');

        $switched = $this->manager()->startCheckout(
            $fixture['workspace'], $growth, 'o@example.test', 'https://app.test/done', 'https://app.test/cancel',
        );

        $this->assertNotSame($first->sessionId, $switched->sessionId);
        $this->assertSame([$switched->sessionId], $this->stripe->payableSessionIds(),
            'A tier switch still retires the old session before minting the new attempt.');
    }

    // =====================================================================
    // 2 — IMMEDIATE UPGRADE NEEDS DURABLE PROVIDER↔LOCAL RECONCILIATION
    //
    // THE INVARIANT: provider Price changed to the target
    //   → eventually the local commercial snapshot AND the canonical V1
    //     entitlement converge to that same target, exactly once.
    //
    // Its contrapositive matters just as much: a provider Price that did NOT
    // change never widens the entitlement.
    // =====================================================================

    /** Applies the Price at the provider and then loses our side of the call. */
    private function providerSucceedsThenLocalFails(\Throwable $failure): void
    {
        $this->stripe->interleaveOnce('changeSubscriptionPrice', function (array $args, $gateway) use ($failure): void {
            $gateway->subscriptions[$args['subscription']]['price'] = $args['price'];

            throw $failure;
        });
    }

    public function test_a_durable_operation_is_recorded_before_stripe_is_touched(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        // Observed from INSIDE the provider call: the operation must already
        // be durable at the moment Stripe is asked to do anything.
        $this->stripe->interleaveOnce('changeSubscriptionPrice', function () use ($growth): void {
            $row = PlatformSubscription::query()->sole();

            $this->assertNotNull($row->pending_operation_uid);
            $this->assertSame(PlatformSubscriptionManager::PENDING_UPGRADE, (string) $row->pending_kind);
            $this->assertSame((int) $growth->id, (int) $row->pending_plan_catalog_id);
            $this->assertSame((string) $growth->provider_price_id, (string) $row->pending_price_id);
            $this->assertSame('199.00', (string) $row->pending_price_snapshot);
        });

        $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);

        $this->assertNull(PlatformSubscription::query()->sole()->pending_operation_uid,
            'And it is cleared once provider truth has proved it.');
    }

    public function test_a_lost_upgrade_response_leaves_the_entitlement_alone_and_is_repaired_by_the_webhook(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        $this->providerSucceedsThenLocalFails(
            PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED)
        );

        try {
            $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);
            $this->fail('The lost response must surface, not be swallowed.');
        } catch (PlatformBillingException) {
            // Expected: Stripe changed the Price; our HTTP call never returned.
        }

        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['workspace']),
            'Nothing local ran, so the entitlement has not moved.');
        $this->assertNotNull(PlatformSubscription::query()->sole()->pending_operation_uid,
            'But the intent survives, which is the whole point of writing it first.');

        $this->deliver('customer.subscription.updated', $fixture);

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']),
            'The webhook finishes the SAME operation: the customer receives what they are being charged for.');
        $this->assertSame((int) $growth->id, (int) $subscription->workspace_plan_catalog_id);
        $this->assertSame('199.00', (string) $subscription->price_snapshot);
        $this->assertNull($subscription->pending_operation_uid);
    }

    public function test_a_database_failure_after_the_provider_upgrade_is_repaired_by_the_webhook(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        // A database failure rather than a provider one: recovery must not
        // depend on WHICH exception aborted the local side.
        $this->providerSucceedsThenLocalFails(new RuntimeException('SQLSTATE[40001]: Serialization failure'));

        try {
            $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);
            $this->fail('The local failure must surface.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['workspace']));

        $this->deliver('customer.subscription.updated', $fixture);

        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']));
        $this->assertNull(PlatformSubscription::query()->sole()->pending_operation_uid);
    }

    public function test_an_entitlement_failure_after_the_provider_upgrade_is_repaired_by_the_webhook(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        // The provider call succeeds and the commercial snapshot IS written;
        // the entitlement phase is what fails. That is a genuinely PARTIAL
        // convergence — a different broken state from "nothing local ran" —
        // and the operation must still finish it later.
        $failing = new class(new \App\Models\WorkspacePlanAssignment()) extends \App\Repositories\Eloquent\EloquentWorkspacePlanAssignmentRepository
        {
            public bool $armed = true;

            public function findByWorkspaceId(int $workspaceId): ?\App\Models\WorkspacePlanAssignment
            {
                if ($this->armed) {
                    $this->armed = false;

                    throw new RuntimeException('entitlement lookup failed');
                }

                return parent::findByWorkspaceId($workspaceId);
            }
        };
        $this->app->instance(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class, $failing);

        try {
            $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);
            $this->fail('The entitlement failure must surface.');
        } catch (RuntimeException) {
            // Expected.
        }

        $this->app->forgetInstance(\App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class);
        $this->app->bind(
            \App\Repositories\Contracts\WorkspacePlanAssignmentRepository::class,
            \App\Repositories\Eloquent\EloquentWorkspacePlanAssignmentRepository::class,
        );

        $this->assertSame((int) $growth->id, (int) PlatformSubscription::query()->sole()->workspace_plan_catalog_id,
            'The commercial record followed provider truth…');
        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['workspace']),
            '…but the entitlement did not move, because its write never happened.');
        $this->assertNotNull(PlatformSubscription::query()->sole()->pending_operation_uid,
            'So the operation is not finished, and must not be cleared.');

        $this->deliver('customer.subscription.updated', $fixture);

        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']));
        $this->assertNull(PlatformSubscription::query()->sole()->pending_operation_uid);
    }

    public function test_a_provider_price_that_did_not_change_never_widens_the_entitlement(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        // The provider is asked, and does NOT move to the target Price — a
        // refused change, a schedule that deferred it, a stale read. Our side
        // then fails too, so nothing local ran either.
        $this->stripe->interleaveOnce('changeSubscriptionPrice', function (array $args, $gateway): void {
            $gateway->subscriptions[$args['subscription']]['price'] = 'price_fake_core';

            throw PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED);
        });

        try {
            $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);
        } catch (PlatformBillingException) {
            // Expected.
        }

        // Even a webhook cannot widen it, because provider truth still says Core.
        $this->deliver('customer.subscription.updated', $fixture);

        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['workspace']),
            'Entitlement follows the money, never our own intent.');
        $this->assertSame((int) $fixture['subscription']->workspace_plan_catalog_id,
            (int) PlatformSubscription::query()->sole()->workspace_plan_catalog_id);
        $this->assertNotNull(PlatformSubscription::query()->sole()->pending_operation_uid,
            'The operation stays open, waiting for truth that may never come.');
    }

    public function test_a_duplicate_upgrade_webhook_converges_exactly_once(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);

        $before = DB::table('workspace_entitlement_transitions')->where('transition_type', 'plan_changed')->count();

        $this->deliver('customer.subscription.updated', $fixture, ['event_id' => 'evt_dupe']);
        $this->deliver('customer.subscription.updated', $fixture, ['event_id' => 'evt_dupe']);

        $this->assertSame($before, DB::table('workspace_entitlement_transitions')->where('transition_type', 'plan_changed')->count(),
            'A finished operation is finished: repeating its event changes nothing.');
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']));
        $this->assertNull(PlatformSubscription::query()->sole()->pending_operation_uid);
    }

    public function test_an_out_of_order_webhook_cannot_undo_a_finished_upgrade(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');

        $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);

        $this->deliver('customer.subscription.updated', $fixture, [
            'event_id' => 'evt_late', 'created' => now()->subDay()->getTimestamp(),
        ]);

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']));
        $this->assertSame((int) $growth->id, (int) $subscription->workspace_plan_catalog_id);
        $this->assertNull($subscription->pending_operation_uid);
    }

    public function test_the_plan_change_key_belongs_to_the_operation_not_to_the_target_catalog(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');
        $core = WorkspacePlanCatalog::query()->where('tier', 'core')->firstOrFail();

        $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);
        $firstKey = $this->stripe->callsOf('changeSubscriptionPrice')[0]['args']['idempotency_key'];

        // Back down to Core, then up to a REPRICED Growth on a NEW Stripe
        // Price. Keying on the catalog id would send this second upgrade with
        // the first one's key and different parameters, which Stripe rejects.
        $this->manager()->requestPlanChange($fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id);
        $this->travelTo(now()->addMonths(2));
        $this->manager()->applyDuePendingPlanChanges(10);

        $repriced = $this->repriceOntoNewProviderPrice(WorkspacePlanTier::Growth, '249.00', 'price_fake_growth_v2');
        $this->manager()->requestPlanChange($fixture['workspace'], $repriced, (int) $fixture['workspace']->owner_user_id);

        $keys = array_column(array_column($this->stripe->callsOf('changeSubscriptionPrice'), 'args'), 'idempotency_key');

        $this->assertSame(count($keys), count(array_unique($keys)),
            'Every distinct plan-change operation carries its own key.');
        $this->assertNotSame($firstKey, end($keys));
        $this->assertStringContainsString(':change:', (string) $firstKey);
        $this->assertStringNotContainsString(':upgrade:' . $growth->id, (string) $firstKey);
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']));
        $this->assertSame('249.00', (string) PlatformSubscription::query()->sole()->price_snapshot);
    }

    // =====================================================================
    // 2b — A SCHEDULED DOWNGRADE IS BOUND TO THE TERMS AGREED AT REQUEST TIME
    // =====================================================================

    public function test_a_scheduled_downgrade_uses_the_terms_agreed_when_it_was_requested(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');

        $this->manager()->requestPlanChange($fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id);

        $scheduled = PlatformSubscription::query()->sole();
        $this->assertSame('97.00', (string) $scheduled->pending_price_snapshot,
            'The terms are captured at REQUEST time, not read again at the boundary.');
        $this->assertSame('price_fake_core', (string) $scheduled->pending_price_id);

        // Weeks pass, and the Platform Owner reprices Core onto a new Stripe
        // Price. This customer agreed to 97.00 and must still get 97.00.
        $this->repriceOntoNewProviderPrice(WorkspacePlanTier::Core, '147.00', 'price_fake_core_v2');

        $this->travelTo(now()->addMonths(2));
        $this->assertSame(1, $this->manager()->applyDuePendingPlanChanges(10));

        $call = $this->stripe->callsOf('changeSubscriptionPrice')[0]['args'];
        $this->assertSame('price_fake_core', $call['price'],
            'The provider is asked for the Price the customer agreed to.');
        $this->assertFalse($call['prorate']);

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame('97.00', (string) $subscription->price_snapshot,
            'A customer who scheduled 97.00 Core is never moved onto a repriced 147.00 Core.');
        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['workspace']));
        $this->assertNull($subscription->pending_operation_uid);
    }

    public function test_a_downgrade_whose_local_side_failed_converges_on_the_next_sweep(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');

        $this->manager()->requestPlanChange($fixture['workspace'], $core, (int) $fixture['workspace']->owner_user_id);
        $this->travelTo(now()->addMonths(2));

        // Sweep 1: the provider applies the Price, our side then dies. The
        // sweep logs and moves on rather than aborting the batch.
        $this->providerSucceedsThenLocalFails(new RuntimeException('SQLSTATE[40001]: Serialization failure'));
        $this->assertSame(0, $this->manager()->applyDuePendingPlanChanges(10));
        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']));

        // Sweep 2: the same operation, the same key, and this time it lands.
        $this->assertSame(1, $this->manager()->applyDuePendingPlanChanges(10));
        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['workspace']));
        $this->assertNull(PlatformSubscription::query()->sole()->pending_operation_uid);
    }

    public function test_an_in_flight_upgrade_is_not_silently_replaced_by_a_different_target(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $growth = $this->sellableTier(WorkspacePlanTier::Growth, price: '199.00');
        $agency = $this->sellableTier(WorkspacePlanTier::Agency, price: '299.00');

        $this->providerSucceedsThenLocalFails(
            PlatformBillingException::because(PlatformBillingException::PROVIDER_FAILED)
        );

        try {
            $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);
        } catch (PlatformBillingException) {
            // Expected.
        }

        try {
            $this->manager()->requestPlanChange($fixture['workspace'], $agency, (int) $fixture['workspace']->owner_user_id);
            $this->fail('An operation that may already have reached the provider must not be overwritten.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::CHANGE_IN_PROGRESS, $e->reason);
        }

        // Retrying the SAME target is a retry, and reuses the same operation.
        $before = (string) PlatformSubscription::query()->sole()->pending_operation_uid;
        $this->manager()->requestPlanChange($fixture['workspace'], $growth, (int) $fixture['workspace']->owner_user_id);

        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']));
        $this->assertStringContainsString($before, (string) $this->stripe->callsOf('changeSubscriptionPrice')[1]['args']['idempotency_key'],
            'A retry re-drives the ORIGINAL operation key, which is what idempotency is for.');
    }

    // =====================================================================
    // 3 — A FULLY CANCELED CUSTOMER NEEDS A REAL WAY BACK
    // =====================================================================

    public function test_a_canceled_customer_is_offered_a_restart_rather_than_broken_plan_controls(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);
        $this->authenticateAs($fixture['customer']);

        $this->get(route('customer.workspaces.plan.show', [$fixture['workspace']->uid]))
            ->assertOk()
            ->assertSee('Ended')
            ->assertSee('data-role="subscription-resubscribe"', false)
            ->assertDontSee('data-role="subscription-change-plan"', false)
            ->assertDontSee('data-role="subscription-cancel"', false);
    }

    public function test_a_same_tier_resubscribe_restores_access_on_the_same_account(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $oldProviderSubscriptionId = $fixture['provider_subscription_id'];
        $this->endSubscription($fixture);

        $this->assertTrue($this->isLockedOut($fixture['workspace']), 'A canceled subscription locks the account.');

        $growth = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $session = $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $growth, 'owner@example.test', 'https://app.test/back', 'https://app.test/plan',
        );

        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']),
            'Nothing is granted before the provider confirms.');

        $newProviderSubscriptionId = $this->stripe->completeCheckout($session->sessionId);
        $this->manager()->confirmCheckoutSession($session->sessionId);

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame($newProviderSubscriptionId, (string) $subscription->provider_subscription_id);
        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->status);
        $this->assertFalse($this->isLockedOut($fixture['workspace']),
            'A confirmed new subscription restores lifecycle access.');
        $this->assertContains($oldProviderSubscriptionId, $subscription->retiredProviderSubscriptionIds(),
            'The old provider subscription is kept for audit, not erased.');
    }

    public function test_a_different_tier_resubscribe_moves_the_canonical_plan(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);

        $agency = $this->sellableTier(WorkspacePlanTier::Agency, price: '299.00');
        $session = $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $agency, 'owner@example.test', 'https://app.test/back', 'https://app.test/plan',
        );

        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['workspace']),
            'Still the old tier: nothing has been paid for yet.');

        $this->stripe->completeCheckout($session->sessionId);
        $this->manager()->confirmCheckoutSession($session->sessionId);

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']),
            'The canonical plan moves to the tier actually purchased.');
        $this->assertSame((int) $agency->id, (int) $subscription->workspace_plan_catalog_id);
        $this->assertSame('299.00', (string) $subscription->price_snapshot);
        $this->assertNull($subscription->pending_operation_uid);
        $this->assertFalse($this->isLockedOut($fixture['workspace']));
    }

    public function test_resubscribing_provisions_no_second_workspace_business_or_location(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);

        $before = [
            Workspace::query()->count(),
            Business::query()->count(),
            BusinessLocation::query()->count(),
            PlatformSubscription::query()->count(),
        ];

        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '49.00');
        $session = $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $core, 'owner@example.test', 'https://app.test/back', 'https://app.test/plan',
        );
        $this->stripe->completeCheckout($session->sessionId);
        $this->manager()->confirmCheckoutSession($session->sessionId);

        $this->assertSame($before, [
            Workspace::query()->count(),
            Business::query()->count(),
            BusinessLocation::query()->count(),
            PlatformSubscription::query()->count(),
        ], 'The customer comes back to the account they already had.');
        $this->assertSame(1, Workspace::query()->count());
    }

    public function test_an_event_from_the_old_provider_subscription_cannot_mutate_the_new_one(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $oldProviderSubscriptionId = $fixture['provider_subscription_id'];
        $this->endSubscription($fixture);

        $growth = WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail();
        $session = $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $growth, 'owner@example.test', 'https://app.test/back', 'https://app.test/plan',
        );
        $newProviderSubscriptionId = $this->stripe->completeCheckout($session->sessionId);
        $this->manager()->confirmCheckoutSession($session->sessionId);

        $this->assertFalse($this->isLockedOut($fixture['workspace']));

        // A late `customer.subscription.deleted` for the PREVIOUS life. It
        // still resolves to this row — the customer id and our own operation
        // metadata both belong to it — so only the retired list can stop it.
        [$body, $headers] = $this->webhookBody(
            'customer.subscription.deleted',
            $oldProviderSubscriptionId,
            (string) PlatformSubscription::query()->sole()->provider_customer_id,
            'evt_old_life',
            null,
            (string) PlatformSubscription::query()->sole()->uid,
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame($newProviderSubscriptionId, (string) $subscription->provider_subscription_id,
            'The new relationship is untouched.');
        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->status);
        $this->assertFalse($this->isLockedOut($fixture['workspace']),
            'A dead subscription may never lock the account that replaced it.');
    }

    public function test_the_webhook_alone_finishes_a_resubscribe_when_the_browser_never_returns(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);

        $agency = $this->sellableTier(WorkspacePlanTier::Agency, price: '299.00');
        $session = $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $agency, 'owner@example.test', 'https://app.test/back', 'https://app.test/plan',
        );

        // The customer pays and closes the tab. Nothing local has run.
        $newProviderSubscriptionId = $this->stripe->completeCheckout($session->sessionId);

        [$body, $headers] = $this->webhookBody(
            'customer.subscription.updated', $newProviderSubscriptionId, null, 'evt_resub',
            null, (string) PlatformSubscription::query()->sole()->uid,
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']));
        $this->assertFalse($this->isLockedOut($fixture['workspace']));
        $this->assertNull(PlatformSubscription::query()->sole()->pending_operation_uid);
    }

    public function test_repeating_the_resubscribe_return_and_webhook_is_idempotent(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);

        $agency = $this->sellableTier(WorkspacePlanTier::Agency, price: '299.00');
        $session = $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $agency, 'owner@example.test', 'https://app.test/back', 'https://app.test/plan',
        );
        $newProviderSubscriptionId = $this->stripe->completeCheckout($session->sessionId);

        $this->manager()->confirmCheckoutSession($session->sessionId);
        $this->manager()->confirmCheckoutSession($session->sessionId);

        [$body, $headers] = $this->webhookBody(
            'customer.subscription.updated', $newProviderSubscriptionId, null, 'evt_resub_a',
            null, (string) PlatformSubscription::query()->sole()->uid,
        );
        $this->postPlatformWebhook($body, $headers)->assertOk();
        $this->postPlatformWebhook($body, $headers)->assertOk();

        $this->assertSame(1, PlatformSubscription::query()->count());
        $this->assertSame(1, DB::table('workspace_plan_assignments')->count());
        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']));
        $this->assertFalse($this->isLockedOut($fixture['workspace']));
    }

    public function test_the_resubscribe_route_opens_checkout_and_the_return_finishes_it(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);
        $this->sellableTier(WorkspacePlanTier::Agency, price: '299.00');
        $this->authenticateAs($fixture['customer']);

        $uid = $fixture['workspace']->uid;

        $this->post(route('customer.workspaces.plan.resubscribe', [$uid]), ['tier' => 'agency', 'confirm' => '1'])
            ->assertRedirect();

        $session = (string) PlatformSubscription::query()->sole()->provider_checkout_session_id;
        $this->assertNotSame('', $session);
        $this->stripe->completeCheckout($session);

        $this->get(route('customer.workspaces.plan.resubscribe-return', [$uid]))
            ->assertRedirect(route('customer.workspaces.plan.show', [$uid]));

        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']));
        $this->assertFalse($this->isLockedOut($fixture['workspace']));

        $this->get(route('customer.workspaces.plan.show', [$uid]))
            ->assertOk()
            ->assertSee('Active')
            ->assertDontSee('data-role="subscription-resubscribe"', false);
    }

    public function test_staff_cannot_restart_somebody_elses_subscription(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);

        $staff = $this->createCustomer();
        $this->createMembership($fixture['workspace'], $staff->user, [
            'role' => \App\Enums\Workspace\WorkspaceMembershipRole::Staff,
            'business_access_scope' => \App\Enums\Workspace\WorkspaceBusinessAccessScope::All,
            'location_access_scope' => \App\Enums\Workspace\LocationAccessScope::All,
        ]);
        $this->authenticateAs($staff);

        $uid = $fixture['workspace']->uid;
        $this->post(route('customer.workspaces.plan.resubscribe', [$uid]), ['tier' => 'growth', 'confirm' => '1'])
            ->assertNotFound();
        $this->get(route('customer.workspaces.plan.resubscribe-return', [$uid]))->assertNotFound();

        $this->assertSame([], $this->stripe->payableSessionIds(),
            'Nothing was opened on an account this actor may not spend money on.');
    }

    public function test_a_live_subscription_cannot_use_the_resubscribe_path(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $agency = $this->sellableTier(WorkspacePlanTier::Agency, price: '299.00');

        $this->expectException(PlatformBillingException::class);

        $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $agency, 'owner@example.test', 'https://app.test/back', 'https://app.test/plan',
        );
    }

    /**
     * The CANONICAL access answer — the resolver every gate in the product
     * already consults, never a re-derivation from provider status.
     */
    private function isLockedOut($workspace): bool
    {
        return app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->isLocked();
    }

    // =====================================================================
    // 4 — ISK AND UGX CANNOT BE CHARGED IN FRACTIONS
    //
    // Stripe: represent them as two-decimal values "where the decimal amount
    // is always 00", and "you can't charge fractions of" either. So the factor
    // is 100 and a non-zero fraction is not a price that exists.
    // =====================================================================

    public static function wholeUnitAmounts(): array
    {
        return [
            'ISK whole' => ['297', 'ISK', 29700],
            'ISK explicit zeros' => ['297.00', 'ISK', 29700],
            'ISK single zero' => ['297.0', 'ISK', 29700],
            'UGX whole' => ['297', 'UGX', 29700],
            'UGX explicit zeros' => ['297.00', 'UGX', 29700],
            'UGX single zero' => ['297.0', 'UGX', 29700],
            'ISK one cent' => ['297.01', 'ISK', null],
            'ISK half' => ['297.50', 'ISK', null],
            'ISK half short' => ['297.5', 'ISK', null],
            'UGX one cent' => ['297.01', 'UGX', null],
            'UGX half' => ['297.50', 'UGX', null],
            'UGX half short' => ['297.5', 'UGX', null],
            // HUF and TWD are zero-decimal for PAYOUTS only; fractions of them
            // are perfectly chargeable and must stay so.
            'HUF half' => ['297.50', 'HUF', 29750],
            'TWD half' => ['297.50', 'TWD', 29750],
            'HUF whole' => ['297.00', 'HUF', 29700],
            // A genuinely zero-decimal currency is unaffected by this rule.
            'JPY whole' => ['297', 'JPY', 297],
            'JPY fraction' => ['297.50', 'JPY', null],
        ];
    }

    #[DataProvider('wholeUnitAmounts')]
    public function test_isk_and_ugx_refuse_fractional_amounts(string $amount, string $currency, ?int $expected): void
    {
        $this->assertSame($expected, StripeMinorUnits::toMinor($amount, $currency));
    }

    public function test_isk_and_ugx_are_whole_unit_currencies_that_still_carry_two_decimals(): void
    {
        foreach (['ISK', 'UGX', 'isk', ' ugx '] as $code) {
            $this->assertTrue(StripeMinorUnits::isWholeUnitsOnly($code));
            $this->assertSame(100, StripeMinorUnits::factor($code),
                'Two decimals at the wire is what Stripe asks for; only the fraction is forbidden.');
        }

        foreach (['USD', 'HUF', 'TWD', 'JPY'] as $code) {
            $this->assertFalse(StripeMinorUnits::isWholeUnitsOnly($code));
        }
    }

    public function test_a_fractional_isk_price_cannot_pass_the_owner_parity_check(): void
    {
        // A price the provider can never charge must not be accepted as one
        // that matches — that is the §11 failure this rule prevents.
        $this->assertNull(StripeMinorUnits::toMinor('297.50', 'ISK'));
        $this->assertSame(29700, StripeMinorUnits::toMinor('297.00', 'ISK'));
    }
}
