<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionEventState;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Exceptions\PlatformBilling\PlatformBillingException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\PlatformSubscription;
use App\Models\PlatformSubscriptionEvent;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §7/§9/§12/§15 — the lane-A spine:
 * signup -> provider subscription/trial -> canonical plan assignment ->
 * entitlement/access -> webhook renewal and failure -> Grace/Locked/recovery
 * -> cancellation.
 */
class PlatformSubscriptionLifecycleTest extends TestCase
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

    private function accessState($workspace): CustomerAccountAccessState
    {
        return app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh())->state;
    }

    // =================================================================
    // §7 — checkout, and nothing paid until the provider says so
    // =================================================================

    public function test_starting_checkout_writes_one_durable_row_before_the_provider_is_called(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $fixture = $this->unassignedWorkspace();

        $session = $this->manager()->startCheckout(
            $fixture['workspace'], $catalog, 'owner@example.test',
            'https://app.test/done', 'https://app.test/cancel',
        );

        $subscription = PlatformSubscription::query()->sole();
        $this->assertSame(PlatformSubscriptionStatus::Pending, $subscription->status);
        $this->assertSame('platform-subscription:' . $subscription->uid, (string) $subscription->local_idempotency_key);
        $this->assertSame($session->sessionId, (string) $subscription->provider_checkout_session_id);

        $call = $this->stripe->callsOf('createSubscriptionCheckout')[0];
        $this->assertSame((string) $subscription->uid, $call['args']['client_reference_id'],
            'Our own durable identity is what the provider echoes back.');
        $this->assertSame($this->stripe->baselineTransactionLevel, $call['transaction_level'],
            'The provider call happens after commit, outside every lock.');
    }

    public function test_an_unconfirmed_checkout_never_produces_a_paid_active_state(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $fixture = $this->unassignedWorkspace();

        $this->manager()->startCheckout($fixture['workspace'], $catalog, 'owner@example.test', 'https://a', 'https://b');

        $this->assertSame(PlatformSubscriptionStatus::Pending, PlatformSubscription::query()->sole()->status);
        $this->assertSame(0, WorkspacePlanAssignment::query()->count(),
            '§7 — no provider result, no plan assignment.');
    }

    public function test_repeating_an_uncertain_checkout_reuses_one_session_and_one_row(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $fixture = $this->unassignedWorkspace();

        $first = $this->manager()->startCheckout($fixture['workspace'], $catalog, 'owner@example.test', 'https://a', 'https://b');
        $second = $this->manager()->startCheckout($fixture['workspace']->fresh(), $catalog, 'owner@example.test', 'https://a', 'https://b');

        $this->assertSame($first->sessionId, $second->sessionId, 'One idempotency key, one provider session.');
        $this->assertSame(1, PlatformSubscription::query()->count());
    }

    public function test_a_tier_that_is_not_available_for_signup_is_refused(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $catalog->forceFill(['available_for_signup' => false])->save();
        $fixture = $this->unassignedWorkspace();

        try {
            $this->manager()->startCheckout($fixture['workspace'], $catalog->refresh(), 'o@example.test', 'https://a', 'https://b');
            $this->fail('An unavailable tier must be refused.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::TIER_NOT_AVAILABLE, $e->reason);
        }

        $this->assertSame(0, PlatformSubscription::query()->count());
        $this->assertSame([], $this->stripe->callsOf('createSubscriptionCheckout'));
    }

    public function test_a_tier_with_no_provider_price_is_refused(): void
    {
        $catalog = $this->sellableTier(WorkspacePlanTier::Growth);
        $catalog->forceFill(['provider_price_id' => null])->save();
        $fixture = $this->unassignedWorkspace();

        try {
            $this->manager()->startCheckout($fixture['workspace'], $catalog->refresh(), 'o@example.test', 'https://a', 'https://b');
            $this->fail('An unpriced tier must be refused.');
        } catch (PlatformBillingException $e) {
            $this->assertSame(PlatformBillingException::TIER_NOT_PRICED, $e->reason);
        }
    }

    // =================================================================
    // §8 — trial, snapshotted
    // =================================================================

    public function test_a_configured_trial_produces_trial_state_with_a_snapshotted_end(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth, trialDays: 11);
        $subscription = $fixture['subscription'];

        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->status);
        $this->assertSame(11, (int) $subscription->trial_days_snapshot);
        $this->assertNotNull($subscription->trial_ends_at);
        $this->assertNotNull($subscription->provider_subscription_id);
        $this->assertNotNull($subscription->provider_customer_id);

        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertFalse((bool) $assignment->is_complimentary, '§3.4 — a paying customer is never complimentary.');
        $this->assertNotNull($assignment->trial_ends_at);
        $this->assertEqualsWithDelta(
            $subscription->trial_ends_at->getTimestamp(),
            $assignment->trial_ends_at->getTimestamp(),
            2,
            'The assignment carries the SAME snapshotted trial end.',
        );

        // Blueprint §27 — a trial is Usable with a hint, never a lock.
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessState($fixture['workspace']));
    }

    public function test_changing_the_catalog_trial_later_does_not_rewrite_an_existing_trial(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth, trialDays: 11);
        $before = $fixture['subscription']->trial_ends_at;

        $this->sellableTier(WorkspacePlanTier::Growth, trialDays: 30);

        $this->assertEquals($before, $fixture['subscription']->refresh()->trial_ends_at);
        $this->assertSame(11, (int) $fixture['subscription']->refresh()->trial_days_snapshot);
    }

    public function test_a_no_trial_plan_becomes_active_from_provider_truth(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Core, trialDays: null);

        $this->assertSame(PlatformSubscriptionStatus::Active, $fixture['subscription']->status);
        $this->assertNull($fixture['subscription']->trial_ends_at);
        $this->assertNull(WorkspacePlanAssignment::query()->sole()->trial_ends_at);
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessState($fixture['workspace']));
    }

    public function test_the_assignment_tier_matches_the_tier_the_customer_selected(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Agency);

        $summary = app(EntitlementManager::class)->getWorkspaceEntitlementSummary($fixture['workspace']->fresh());
        $this->assertSame(WorkspacePlanTier::Agency, $summary->tier);
    }

    // =================================================================
    // §12 — webhooks
    // =================================================================

    public function test_an_invalid_signature_returns_400_and_writes_nothing(): void
    {
        $fixture = $this->subscribedWorkspace();
        [$body] = $this->webhookBody('customer.subscription.updated', $fixture['provider_subscription_id']);

        $this->postPlatformWebhook($body, ['Stripe-Signature' => 'v1=forged'])->assertStatus(400);

        $this->assertSame(0, PlatformSubscriptionEvent::query()->count(),
            'Verification happens BEFORE any row is inserted.');
    }

    public function test_a_duplicate_delivery_returns_200_and_reprocesses_nothing(): void
    {
        $fixture = $this->subscribedWorkspace();

        $this->deliver('customer.subscription.updated', $fixture, ['event_id' => 'evt_dup'])->assertOk();
        $this->deliver('customer.subscription.updated', $fixture, ['event_id' => 'evt_dup'])->assertOk();
        $this->deliver('customer.subscription.updated', $fixture, ['event_id' => 'evt_dup'])->assertOk();

        $this->assertSame(1, PlatformSubscriptionEvent::query()->count());
        $this->assertSame(1, (int) PlatformSubscriptionEvent::query()->sole()->attempts);
    }

    public function test_an_unknown_event_type_is_acknowledged_and_ignored(): void
    {
        $fixture = $this->subscribedWorkspace();
        [$body, $headers] = $this->webhookBody('customer.discount.created', $fixture['provider_subscription_id']);

        $this->postPlatformWebhook($body, $headers)->assertOk();

        $event = PlatformSubscriptionEvent::query()->sole();
        $this->assertSame(PlatformSubscriptionEventState::Ignored, $event->state);
        $this->assertSame('unhandled_event_type', $event->last_error);
    }

    public function test_an_event_for_a_foreign_subscription_fails_closed(): void
    {
        $fixture = $this->subscribedWorkspace();

        $this->deliver('customer.subscription.updated', $fixture, [
            'subscription' => 'sub_someone_else',
            'customer' => 'cus_someone_else',
        ])->assertOk();

        $event = PlatformSubscriptionEvent::query()->sole();
        $this->assertSame(PlatformSubscriptionEventState::Failed, $event->state);
        $this->assertSame('no_matching_local_record', $event->last_error);
        $this->assertSame(PlatformSubscriptionStatus::Active, $fixture['subscription']->refresh()->status);
    }

    public function test_a_foreign_checkout_session_is_never_adopted(): void
    {
        $this->subscribedWorkspace();
        $body = json_encode([
            'id' => 'evt_foreign',
            'type' => 'checkout.session.completed',
            'created' => now()->getTimestamp(),
            'data' => ['object' => ['id' => 'cs_other_product', 'object' => 'checkout.session']],
        ]);

        $this->postPlatformWebhook($body, ['Stripe-Signature' => $this->stripe->validSignature])->assertOk();

        $this->assertSame('foreign_checkout_session', PlatformSubscriptionEvent::query()->sole()->last_error);
    }

    public function test_an_out_of_order_event_never_moves_state_backwards(): void
    {
        $fixture = $this->subscribedWorkspace();
        $subscriptionId = $fixture['provider_subscription_id'];

        // A newer "active" arrives first.
        $this->stripe->setSubscriptionStatus($subscriptionId, PlatformSubscriptionStatus::Active);
        $this->deliver('invoice.paid', $fixture, ['event_id' => 'evt_new', 'created' => now()->getTimestamp()])->assertOk();
        $this->assertSame(PlatformSubscriptionStatus::Active, $fixture['subscription']->refresh()->status);

        // Then a STALE past_due, emitted earlier, is delivered late.
        $this->stripe->setSubscriptionStatus($subscriptionId, PlatformSubscriptionStatus::PastDue);
        $this->deliver('invoice.payment_failed', $fixture, [
            'event_id' => 'evt_old',
            'created' => now()->subHour()->getTimestamp(),
        ])->assertOk();

        $this->assertSame(PlatformSubscriptionStatus::Active, $fixture['subscription']->refresh()->status,
            'A late older event must not re-lock a customer who has already paid.');
        $this->assertSame('ignored_out_of_order',
            PlatformSubscriptionEvent::query()->where('provider_event_id', 'evt_old')->sole()->last_error);
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessState($fixture['workspace']));
    }

    // =================================================================
    // §9 — failure, Grace, Locked, recovery
    // =================================================================

    public function test_one_failed_renewal_starts_one_three_day_grace_window(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);

        $this->deliver('invoice.payment_failed', $fixture)->assertOk();

        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertNotNull($assignment->grace_started_at);
        $this->assertNull($assignment->locked_at);
        // Blueprint §27 — Grace keeps full access, with a billing prompt.
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessState($fixture['workspace']));
    }

    public function test_repeated_failures_do_not_extend_the_grace_window(): void
    {
        $fixture = $this->subscribedWorkspace();
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);

        $this->deliver('invoice.payment_failed', $fixture, ['event_id' => 'evt_f1'])->assertOk();
        $opened = WorkspacePlanAssignment::query()->sole()->grace_started_at;

        $this->travel(2)->days();
        $this->deliver('invoice.payment_failed', $fixture, ['event_id' => 'evt_f2'])->assertOk();
        $this->deliver('invoice.payment_failed', $fixture, ['event_id' => 'evt_f3'])->assertOk();

        $this->assertEquals($opened, WorkspacePlanAssignment::query()->sole()->grace_started_at,
            'The window opens once; repeated failure never slides it forward.');
        $this->travelBack();
    }

    public function test_grace_expiry_locks_and_a_late_payment_unlocks(): void
    {
        $fixture = $this->subscribedWorkspace();
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);
        $this->deliver('invoice.payment_failed', $fixture)->assertOk();

        // The existing canonical sweep is what locks — lane A adds no second
        // grace mechanism.
        $this->travel(EntitlementManager::GRACE_PERIOD_DAYS + 1)->days();
        $this->artisan('workspaces:advance-account-lifecycle')->assertExitCode(0);

        $this->assertNotNull(WorkspacePlanAssignment::query()->sole()->locked_at);
        $this->assertSame(CustomerAccountAccessState::Locked, $this->accessState($fixture['workspace']));

        // Blueprint §27 — a confirmed payment restores access immediately.
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::Active);
        $this->deliver('invoice.paid', $fixture, ['event_id' => 'evt_recovered'])->assertOk();

        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertNull($assignment->locked_at);
        $this->assertNull($assignment->grace_started_at);
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessState($fixture['workspace']));
        $this->travelBack();
    }

    public function test_a_successful_renewal_keeps_the_account_active(): void
    {
        $fixture = $this->subscribedWorkspace();
        $this->stripe->advancePeriod($fixture['provider_subscription_id']);

        $this->deliver('invoice.paid', $fixture)->assertOk();

        $subscription = $fixture['subscription']->refresh();
        $this->assertSame(PlatformSubscriptionStatus::Active, $subscription->status);
        $this->assertNotNull($subscription->current_period_end);
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessState($fixture['workspace']));
    }

    // =================================================================
    // §10.3 — cancellation
    // =================================================================

    public function test_cancellation_preserves_access_through_the_paid_period(): void
    {
        $fixture = $this->subscribedWorkspace();

        $this->manager()->requestCancellation($fixture['workspace']);

        $subscription = $fixture['subscription']->refresh();
        $this->assertTrue((bool) $subscription->cancel_at_period_end);
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessState($fixture['workspace']),
            'Already-paid time is never taken away.');
    }

    public function test_end_of_service_locks_and_replay_does_not_corrupt_it(): void
    {
        $fixture = $this->subscribedWorkspace();
        $this->manager()->requestCancellation($fixture['workspace']);

        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::Canceled);
        $this->deliver('customer.subscription.deleted', $fixture, ['event_id' => 'evt_end'])->assertOk();

        $lockedAt = WorkspacePlanAssignment::query()->sole()->locked_at;
        $this->assertNotNull($lockedAt);
        $this->assertSame(CustomerAccountAccessState::Locked, $this->accessState($fixture['workspace']));

        // A replayed end-of-service event must not double-transition.
        $this->deliver('customer.subscription.deleted', $fixture, ['event_id' => 'evt_end_2'])->assertOk();
        $this->assertEquals($lockedAt, WorkspacePlanAssignment::query()->sole()->locked_at);
    }

    public function test_a_scheduled_cancellation_can_be_resumed(): void
    {
        $fixture = $this->subscribedWorkspace();
        $this->manager()->requestCancellation($fixture['workspace']);

        $this->manager()->resumeSubscription($fixture['workspace']);

        $this->assertFalse((bool) $fixture['subscription']->refresh()->cancel_at_period_end);
    }

    // =================================================================
    // §11.1 — complimentary Workspaces are never touched by lane A
    // =================================================================

    public function test_a_complimentary_workspace_is_never_locked_by_a_provider_event(): void
    {
        $fixture = $this->subscribedWorkspace();
        DB::table('workspace_plan_assignments')
            ->where('workspace_id', $fixture['workspace']->id)
            ->update(['is_complimentary' => true]);

        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::Canceled);
        $this->deliver('customer.subscription.deleted', $fixture)->assertOk();

        $this->assertNull(WorkspacePlanAssignment::query()->sole()->locked_at);
        $this->assertSame('ignored_complimentary', PlatformSubscriptionEvent::query()->sole()->last_error);
        $this->assertSame(CustomerAccountAccessState::Usable, $this->accessState($fixture['workspace']));
    }
}
