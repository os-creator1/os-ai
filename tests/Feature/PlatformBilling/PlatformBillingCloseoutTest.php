<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Library\PlatformBilling\PlatformSubscriptionManager;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\PlatformSubscription;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 — the two Lane-A closeout blockers.
 *
 * Both are recovery flows that dead-ended: a locked customer who could see the
 * button that would have saved them but could not press it, and a returning
 * customer whose new Stripe trial the product refused to acknowledge.
 */
class PlatformBillingCloseoutTest extends TestCase
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

    private function decisionFor($workspace)
    {
        return app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh());
    }

    private function tierOf($workspace): ?WorkspacePlanTier
    {
        return app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier;
    }

    /**
     * The REAL route into Locked: the provider reports a failed renewal, the
     * canonical 3-day Grace window opens, nobody pays, and the scheduled sweep
     * locks the account. Never a hand-written `locked_at`.
     */
    private function lockThroughGrace(array $fixture): void
    {
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);
        $this->deliver('invoice.payment_failed', $fixture);

        $decision = $this->decisionFor($fixture['workspace']);
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state,
            'Grace keeps full access — that is the point of it.');
        $this->assertTrue($decision->isInGracePeriod());

        $this->travelTo(now()->addDays(EntitlementManager::GRACE_PERIOD_DAYS + 1));
        $this->artisan('workspaces:advance-account-lifecycle')->assertExitCode(0);

        $this->assertTrue($this->decisionFor($fixture['workspace'])->isLocked(),
            'Grace elapsed without payment, so the account is locked.');
        $this->assertNotNull(WorkspacePlanAssignment::query()->sole()->locked_at);
    }

    // =====================================================================
    // 1 — A LOCKED ACCOUNT MUST BE ABLE TO FIX ITS PAYMENT METHOD
    //
    // The locked screen's own copy promises that completing payment restores
    // access, and sends the customer to Plan & subscription to do it. The one
    // action there that can complete a payment was gated, so the promise was
    // unkeepable: click it, bounce straight back to the locked screen.
    // =====================================================================

    public function test_a_locked_owner_can_still_reach_the_payment_method_recovery(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->lockThroughGrace($fixture);
        $this->authenticateAs($fixture['customer']);

        $uid = $fixture['workspace']->uid;

        // The locked screen still points at Plan & subscription…
        $this->get(route('customer.account-locked.show'))->assertOk();
        $this->get(route('customer.workspaces.plan.show', [$uid]))->assertOk();

        // …and the action it offers there now actually runs.
        $response = $this->get(route('customer.workspaces.plan.payment-method', [$uid]));

        $response->assertRedirect();
        $this->assertStringContainsString(
            PlatformSubscriptionManager::PORTAL_FLOW_PAYMENT_METHOD,
            (string) $response->headers->get('Location'),
            'It opens Stripe\'s hosted portal on the payment-method update flow.',
        );

        $portal = $this->stripe->callsOf('createBillingPortalSession');
        $this->assertCount(1, $portal);
        $this->assertSame('payment_method_update', $portal[0]['args']['flow']);
        $this->assertSame(
            (string) PlatformSubscription::query()->sole()->provider_customer_id,
            $portal[0]['args']['customer'],
            'For this customer, resolved from the durable row rather than the request.',
        );
    }

    public function test_a_locked_workspaces_active_admin_may_also_fix_the_payment_method(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->lockThroughGrace($fixture);

        $admin = $this->createCustomer();
        $this->createMembership($fixture['workspace'], $admin->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($admin);

        $this->get(route('customer.workspaces.plan.payment-method', [$fixture['workspace']->uid]))
            ->assertRedirect();

        $this->assertCount(1, $this->stripe->callsOf('createBillingPortalSession'),
            'The established financial-authority rule is owner OR active Admin with account-frame authority.');
    }

    public function test_a_locked_workspaces_staff_still_cannot_reach_the_payment_method(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->lockThroughGrace($fixture);

        $staff = $this->createCustomer();
        $this->createMembership($fixture['workspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($staff);

        $this->get(route('customer.workspaces.plan.payment-method', [$fixture['workspace']->uid]))
            ->assertNotFound();

        $this->assertSame([], $this->stripe->callsOf('createBillingPortalSession'),
            'Allowlisting the route grants no authority; the controller still decides.');
    }

    public function test_a_stranger_still_cannot_reach_a_locked_workspaces_payment_method(): void
    {
        $victim = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->lockThroughGrace($victim);

        $stranger = $this->subscribedWorkspace(WorkspacePlanTier::Core);
        $this->authenticateAs($stranger['customer']);

        $this->get(route('customer.workspaces.plan.payment-method', [$victim['workspace']->uid]))
            ->assertNotFound();

        $this->assertSame([], $this->stripe->callsOf('createBillingPortalSession'));
    }

    public function test_the_allowlist_is_one_narrow_recovery_exception_not_a_prefix(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->lockThroughGrace($fixture);
        $this->authenticateAs($fixture['customer']);

        $uid = $fixture['workspace']->uid;
        $locked = route('customer.account-locked.show');

        // Ordinary operational surfaces of the SAME locked Workspace, reached
        // by the same owner, are still gated.
        $this->get(route('customer.workspaces.team.show', [$uid]))->assertRedirect($locked);
        $this->get(route('customer.workspaces.settings.show', [$uid]))->assertRedirect($locked);
        $this->get(route('customer.workspaces.show', [$uid]))->assertRedirect($locked);

        // And so are the billing MUTATIONS, which are not recovery.
        $this->post(route('customer.workspaces.plan.change', [$uid]), ['tier' => 'core', 'confirm' => '1'])
            ->assertRedirect($locked);
        $this->post(route('customer.workspaces.plan.cancel', [$uid]), ['confirm' => '1'])
            ->assertRedirect($locked);
        $this->post(route('customer.workspaces.plan.resume', [$uid]))->assertRedirect($locked);

        $this->assertSame([], $this->stripe->callsOf('changeSubscriptionPrice'));
        $this->assertSame([], $this->stripe->callsOf('setCancelAtPeriodEnd'));
    }

    // =====================================================================
    // 2 — A PROVIDER-CONFIRMED TRIAL RE-SUBSCRIBE MUST RESTORE ACCESS
    //
    // The customer cancelled, was locked, came back on a plan with a trial,
    // and Stripe confirmed `trialing`. The tier converged and the lock did
    // not: a valid Stripe trial, and a product that said Locked.
    // =====================================================================

    /** Ends a subscription for real — provider truth first, then our finalizer. */
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

    /**
     * @return array{fixture: array<string, mixed>, session: string, catalog: WorkspacePlanCatalog}
     */
    private function endedThenResubscribing(int $trialDays = 14, WorkspacePlanTier $target = WorkspacePlanTier::Agency): array
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);

        $this->assertTrue($this->decisionFor($fixture['workspace'])->isLocked());

        $catalog = $this->sellableTier($target, trialDays: $trialDays, price: '299.00');

        $session = $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $catalog, 'owner@example.test',
            'https://app.test/back', 'https://app.test/plan',
        );

        return ['fixture' => $fixture, 'session' => $session->sessionId, 'catalog' => $catalog];
    }

    private function deliverFor(string $providerSubscriptionId, string $eventId)
    {
        [$body, $headers] = $this->webhookBody(
            'customer.subscription.updated',
            $providerSubscriptionId,
            (string) PlatformSubscription::query()->sole()->provider_customer_id,
            $eventId,
            null,
            (string) PlatformSubscription::query()->sole()->uid,
        );

        return $this->postPlatformWebhook($body, $headers);
    }

    public function test_the_webhook_alone_restores_access_for_a_trialing_resubscribe(): void
    {
        $context = $this->endedThenResubscribing();
        $fixture = $context['fixture'];

        $before = [
            Workspace::query()->count(),
            Business::query()->count(),
            BusinessLocation::query()->count(),
            PlatformSubscription::query()->count(),
        ];

        // The customer pays on Stripe's page and closes the tab. Nothing local
        // has run; the webhook is the only thing that arrives.
        $newSubscriptionId = $this->stripe->completeCheckout($context['session']);
        $this->assertSame(
            PlatformSubscriptionStatus::Trialing,
            $this->stripe->subscriptionSnapshot($newSubscriptionId)->status,
            'The target plan carries a trial, so the provider confirms `trialing`.',
        );

        $this->deliverFor($newSubscriptionId, 'evt_trial_resub')->assertOk();

        // 6 — the same account, reused.
        $this->assertSame($before, [
            Workspace::query()->count(),
            Business::query()->count(),
            BusinessLocation::query()->count(),
            PlatformSubscription::query()->count(),
        ]);

        // 7 — the canonical tier is the tier actually purchased.
        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']));

        // 8 — the assignment carries the PROVIDER-CONFIRMED trial, and nothing
        //     that contradicts it.
        $subscription = PlatformSubscription::query()->sole();
        $assignment = WorkspacePlanAssignment::query()->sole();

        $this->assertNull($assignment->locked_at, 'The stale lock from the previous life is gone.');
        $this->assertNull($assignment->grace_started_at);
        $this->assertNotNull($assignment->trial_ends_at);
        $this->assertSame(
            $subscription->trial_ends_at->getTimestamp(),
            $assignment->trial_ends_at->getTimestamp(),
            'The canonical trial end is the one the provider confirmed.',
        );

        // 9 — and the gate that actually governs the account agrees.
        $decision = $this->decisionFor($fixture['workspace']);
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state);
        $this->assertSame('plan_trial', $decision->reason);
        $this->assertTrue($decision->isInTrial());
    }

    public function test_replaying_the_trialing_resubscribe_moves_nothing(): void
    {
        $context = $this->endedThenResubscribing();
        $fixture = $context['fixture'];

        $newSubscriptionId = $this->stripe->completeCheckout($context['session']);
        $this->deliverFor($newSubscriptionId, 'evt_trial_a')->assertOk();

        $trialEnd = WorkspacePlanAssignment::query()->sole()->trial_ends_at->getTimestamp();
        $restored = DB::table('workspace_entitlement_transitions')
            ->where('transition_type', 'access_restored')->count();

        $this->deliverFor($newSubscriptionId, 'evt_trial_a')->assertOk();
        $this->deliverFor($newSubscriptionId, 'evt_trial_b')->assertOk();
        $this->manager()->applyProviderSubscription(
            PlatformSubscription::query()->sole(), $newSubscriptionId, 'evt_trial_c', now(),
        );

        $assignment = WorkspacePlanAssignment::query()->sole();

        $this->assertSame($trialEnd, $assignment->trial_ends_at->getTimestamp(),
            'Replay never moves a trial end forward.');
        $this->assertSame($restored, DB::table('workspace_entitlement_transitions')
            ->where('transition_type', 'access_restored')->count(),
            'And writes no second lifecycle transition.');
        $this->assertSame(1, WorkspacePlanAssignment::query()->count());
        $this->assertNull($assignment->locked_at);
    }

    public function test_the_browser_return_and_the_webhook_converge_identically(): void
    {
        $context = $this->endedThenResubscribing();
        $fixture = $context['fixture'];

        $newSubscriptionId = $this->stripe->completeCheckout($context['session']);

        // Both arrive, in the worst order, twice each.
        $this->manager()->confirmCheckoutSession($context['session']);
        $this->deliverFor($newSubscriptionId, 'evt_race_a')->assertOk();
        $this->manager()->confirmCheckoutSession($context['session']);
        $this->deliverFor($newSubscriptionId, 'evt_race_b')->assertOk();

        $assignment = WorkspacePlanAssignment::query()->sole();
        $subscription = PlatformSubscription::query()->sole();

        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']));
        $this->assertNull($assignment->locked_at);
        $this->assertSame(
            $subscription->trial_ends_at->getTimestamp(),
            $assignment->trial_ends_at->getTimestamp(),
        );
        $this->assertSame(1, DB::table('workspace_entitlement_transitions')
            ->where('transition_type', 'access_restored')->count(),
            'One restoration, however many times the two routes arrive.');
        $this->assertNull($subscription->pending_operation_uid);
        $this->assertTrue($this->decisionFor($fixture['workspace'])->isInTrial());
    }

    public function test_trialing_without_a_provider_trial_end_does_not_unlock_the_account(): void
    {
        $context = $this->endedThenResubscribing();
        $fixture = $context['fixture'];

        $newSubscriptionId = $this->stripe->completeCheckout($context['session']);

        // The provider says `trialing` but supplies nothing we could call a
        // trial. Unlocking on the strength of a status alone would be exactly
        // the fabricated paid state §7 forbids.
        $this->stripe->subscriptions[$newSubscriptionId]['trial_end'] = null;

        $this->deliverFor($newSubscriptionId, 'evt_trial_missing')->assertOk();

        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertNotNull($assignment->locked_at, 'The lock stays until there is something real to replace it.');
        $this->assertNull($assignment->trial_ends_at);
        $this->assertTrue($this->decisionFor($fixture['workspace'])->isLocked());

        // Provider truth that IS complete converges it afterwards.
        $this->stripe->subscriptions[$newSubscriptionId]['trial_end'] = now()->addDays(14)->startOfSecond();
        $this->deliverFor($newSubscriptionId, 'evt_trial_complete')->assertOk();

        $this->assertNull(WorkspacePlanAssignment::query()->sole()->locked_at);
        $this->assertTrue($this->decisionFor($fixture['workspace'])->isInTrial());
    }

    public function test_a_trial_end_already_in_the_past_does_not_unlock_the_account(): void
    {
        $context = $this->endedThenResubscribing();
        $fixture = $context['fixture'];

        $newSubscriptionId = $this->stripe->completeCheckout($context['session']);
        $this->stripe->subscriptions[$newSubscriptionId]['trial_end'] = now()->subDay()->startOfSecond();

        $this->deliverFor($newSubscriptionId, 'evt_trial_past')->assertOk();

        $this->assertNotNull(WorkspacePlanAssignment::query()->sole()->locked_at,
            'An ended trial is not a trial, and unlocking on one only invites the sweep to re-lock.');
        $this->assertTrue($this->decisionFor($fixture['workspace'])->isLocked());
    }

    public function test_an_ordinary_active_resubscribe_still_behaves_exactly_as_before(): void
    {
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth);
        $this->endSubscription($fixture);

        $agency = $this->sellableTier(WorkspacePlanTier::Agency, price: '299.00');
        $session = $this->manager()->startResubscribeCheckout(
            $fixture['workspace'], $agency, 'owner@example.test',
            'https://app.test/back', 'https://app.test/plan',
        );

        $newSubscriptionId = $this->stripe->completeCheckout($session->sessionId);
        $this->assertSame(
            PlatformSubscriptionStatus::Active,
            $this->stripe->subscriptionSnapshot($newSubscriptionId)->status,
        );

        $this->manager()->confirmCheckoutSession($session->sessionId);

        $assignment = WorkspacePlanAssignment::query()->sole();
        $this->assertSame(WorkspacePlanTier::Agency, $this->tierOf($fixture['workspace']));
        $this->assertNull($assignment->locked_at);
        $this->assertNull($assignment->trial_ends_at, 'A paid re-subscribe grants no trial.');
        $this->assertSame(CustomerAccountAccessState::Usable, $this->decisionFor($fixture['workspace'])->state);
        $this->assertFalse($this->decisionFor($fixture['workspace'])->isInTrial());
    }

    public function test_an_event_from_the_retired_subscription_cannot_touch_the_new_trial(): void
    {
        $context = $this->endedThenResubscribing();
        $fixture = $context['fixture'];
        $oldSubscriptionId = $fixture['provider_subscription_id'];

        $newSubscriptionId = $this->stripe->completeCheckout($context['session']);
        $this->deliverFor($newSubscriptionId, 'evt_new_trial')->assertOk();

        $trialEnd = WorkspacePlanAssignment::query()->sole()->trial_ends_at->getTimestamp();

        // The previous life's subscription is still canceled at the provider,
        // and Stripe keeps talking about it.
        $this->deliverFor($oldSubscriptionId, 'evt_old_life')->assertOk();

        $assignment = WorkspacePlanAssignment::query()->sole();
        $subscription = PlatformSubscription::query()->sole();

        $this->assertNull($assignment->locked_at, 'A retired subscription cannot lock the trial that replaced it.');
        $this->assertSame($trialEnd, $assignment->trial_ends_at->getTimestamp());
        $this->assertSame($newSubscriptionId, (string) $subscription->provider_subscription_id);
        $this->assertSame(PlatformSubscriptionStatus::Trialing, $subscription->status);
        $this->assertTrue($this->decisionFor($fixture['workspace'])->isInTrial());
    }

    public function test_a_first_signup_trial_is_unchanged_by_the_new_lifecycle_arm(): void
    {
        // The case that previously wrote nothing here, and still must not need
        // to: the assignment is created afterwards, from the same
        // provider-confirmed trial end.
        $fixture = $this->subscribedWorkspace(WorkspacePlanTier::Growth, trialDays: 11);

        $subscription = $fixture['subscription'];
        $assignment = WorkspacePlanAssignment::query()->sole();

        $this->assertSame($subscription->trial_ends_at->getTimestamp(), $assignment->trial_ends_at->getTimestamp());
        $this->assertNull($assignment->locked_at);
        $this->assertSame(0, DB::table('workspace_entitlement_transitions')
            ->where('transition_type', 'access_restored')->count(),
            'A brand-new trial restores nothing, because nothing was taken away.');

        // A later trialing event for the same running trial is a pure no-op.
        $this->deliver('customer.subscription.updated', $fixture)->assertOk();

        $this->assertSame($assignment->trial_ends_at->getTimestamp(),
            WorkspacePlanAssignment::query()->sole()->trial_ends_at->getTimestamp());
        $this->assertSame(0, DB::table('workspace_entitlement_transitions')
            ->where('transition_type', 'access_restored')->count());
    }
}
