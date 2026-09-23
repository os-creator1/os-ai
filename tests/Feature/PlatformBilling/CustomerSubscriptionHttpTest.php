<?php

namespace Tests\Feature\PlatformBilling;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\PlatformBilling\PlatformSubscriptionStatus;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Models\Customer;
use App\Models\PlatformSubscription;
use App\Models\WorkspacePlanCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PlatformBilling\Concerns\CreatesPlatformSubscriptions;
use Tests\TestCase;

/**
 * Implementation Contract 21 §10/§13/§4 — the customer's Plan & subscription
 * page and its real actions, through HTTP.
 */
class CustomerSubscriptionHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesPlatformSubscriptions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindFakeStripe();
    }

    /**
     * A subscribed Workspace whose OWNER is signed in.
     *
     * @return array{customer: Customer, workspace: \App\Models\Workspace, subscription: PlatformSubscription, provider_subscription_id: string}
     */
    private function signedInSubscriber(WorkspacePlanTier $tier = WorkspacePlanTier::Growth, ?int $trialDays = null): array
    {
        $fixture = $this->subscribedWorkspace($tier, $trialDays);
        $this->authenticateAs($fixture['customer']);

        return $fixture;
    }

    private function planUrl($workspace): string
    {
        return route('customer.workspaces.plan.show', [$workspace->uid]);
    }

    // =================================================================
    // §13 — the page reflects the canonical V1 subscription
    // =================================================================

    public function test_the_plan_page_shows_the_canonical_v1_subscription(): void
    {
        $fixture = $this->signedInSubscriber(WorkspacePlanTier::Growth, trialDays: 14);

        $response = $this->get($this->planUrl($fixture['workspace']));

        $response->assertOk()
            ->assertSee('Subscription')
            ->assertSee('Trial')
            // The SNAPSHOT price — what this customer actually pays.
            ->assertSee((string) $fixture['subscription']->price_snapshot)
            ->assertSee('monthly');
    }

    public function test_the_page_shows_the_snapshot_price_not_the_current_catalog_price(): void
    {
        $fixture = $this->signedInSubscriber(WorkspacePlanTier::Growth);
        $this->assertSame('99.00', (string) $fixture['subscription']->price_snapshot);

        app(EntitlementManager::class)->updateCatalogPricing(
            WorkspacePlanCatalog::query()->where('tier', 'growth')->firstOrFail(),
            '499.00', $this->fixtureCurrencyId(), null, $this->platformAdminId(), 'Repricing.',
        );

        $this->get($this->planUrl($fixture['workspace']))
            ->assertOk()
            ->assertSee('99.00')
            ->assertDontSee('499.00 USD / monthly');
    }

    public function test_the_page_never_surfaces_legacy_ultimate_sms_plan_semantics(): void
    {
        $fixture = $this->signedInSubscriber();

        $html = $this->get($this->planUrl($fixture['workspace']))->assertOk()->getContent();

        foreach (['customer.subscriptions', 'sending credit', 'Renew subscription', 'braintree'] as $legacy) {
            $this->assertStringNotContainsString($legacy, $html);
        }
    }

    public function test_a_complimentary_workspace_is_never_shown_as_paid(): void
    {
        $fixture = $this->signedInSubscriber();
        DB::table('workspace_plan_assignments')->where('workspace_id', $fixture['workspace']->id)
            ->update(['is_complimentary' => true]);

        $this->get($this->planUrl($fixture['workspace']))
            ->assertOk()
            ->assertSee('Complimentary account')
            ->assertDontSee('Cancel subscription');
    }

    // =================================================================
    // §10.2 — change plan
    // =================================================================

    public function test_the_page_states_what_each_plan_change_will_do(): void
    {
        $fixture = $this->signedInSubscriber(WorkspacePlanTier::Growth);
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');
        $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');

        $response = $this->get($this->planUrl($fixture['workspace']))->assertOk();

        $response->assertSee('Upgrade — takes effect immediately')
            ->assertSee('Downgrade — takes effect at the end of your current billing period');
    }

    public function test_an_upgrade_takes_effect_immediately(): void
    {
        $fixture = $this->signedInSubscriber(WorkspacePlanTier::Core);
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');

        $this->post(route('customer.workspaces.plan.change', [$fixture['workspace']->uid]), [
            'tier' => 'agency', 'confirm' => '1',
        ])->assertRedirect();

        $this->assertSame(WorkspacePlanTier::Agency, app(EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($fixture['workspace']->fresh())->tier);
        $this->assertSame('497.00', (string) $fixture['subscription']->refresh()->price_snapshot);
    }

    public function test_a_downgrade_is_scheduled_for_the_period_end(): void
    {
        $fixture = $this->signedInSubscriber(WorkspacePlanTier::Agency);
        $core = $this->sellableTier(WorkspacePlanTier::Core, price: '97.00');

        $this->post(route('customer.workspaces.plan.change', [$fixture['workspace']->uid]), [
            'tier' => 'core', 'confirm' => '1',
        ])->assertRedirect();

        $subscription = $fixture['subscription']->refresh();
        $this->assertSame((int) $core->id, (int) $subscription->pending_plan_catalog_id);
        $this->assertSame(WorkspacePlanTier::Agency, app(EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($fixture['workspace']->fresh())->tier,
            'The customer keeps what they paid for until the period ends.');
    }

    public function test_a_plan_change_without_confirmation_is_refused(): void
    {
        $fixture = $this->signedInSubscriber(WorkspacePlanTier::Core);
        $this->sellableTier(WorkspacePlanTier::Agency, price: '497.00');

        $this->post(route('customer.workspaces.plan.change', [$fixture['workspace']->uid]), ['tier' => 'agency'])
            ->assertSessionHasErrors('confirm');

        $this->assertSame(WorkspacePlanTier::Core, app(EntitlementManager::class)
            ->getWorkspaceEntitlementSummary($fixture['workspace']->fresh())->tier);
    }

    // =================================================================
    // §10.3 — cancel and resume
    // =================================================================

    public function test_cancelling_preserves_access_and_shows_the_end_date(): void
    {
        $fixture = $this->signedInSubscriber();

        $this->post(route('customer.workspaces.plan.cancel', [$fixture['workspace']->uid]), ['confirm' => '1'])
            ->assertRedirect();

        $this->assertTrue((bool) $fixture['subscription']->refresh()->cancel_at_period_end);

        $this->get($this->planUrl($fixture['workspace']))
            ->assertOk()
            ->assertSee('Ending at period end')
            ->assertSee('Access ends');
    }

    public function test_cancelling_twice_is_harmless(): void
    {
        $fixture = $this->signedInSubscriber();

        $this->post(route('customer.workspaces.plan.cancel', [$fixture['workspace']->uid]), ['confirm' => '1']);
        $this->post(route('customer.workspaces.plan.cancel', [$fixture['workspace']->uid]), ['confirm' => '1'])
            ->assertRedirect();

        $this->assertTrue((bool) $fixture['subscription']->refresh()->cancel_at_period_end);
    }

    public function test_a_cancellation_can_be_resumed(): void
    {
        $fixture = $this->signedInSubscriber();
        $this->post(route('customer.workspaces.plan.cancel', [$fixture['workspace']->uid]), ['confirm' => '1']);

        $this->get($this->planUrl($fixture['workspace']))->assertOk()->assertSee('Keep my subscription');

        $this->post(route('customer.workspaces.plan.resume', [$fixture['workspace']->uid]))->assertRedirect();

        $this->assertFalse((bool) $fixture['subscription']->refresh()->cancel_at_period_end);
    }

    // =================================================================
    // §4/§9 — payment-method recovery
    // =================================================================

    public function test_the_payment_method_route_sends_the_customer_to_stripes_hosted_portal(): void
    {
        $fixture = $this->signedInSubscriber();

        $response = $this->get(route('customer.workspaces.plan.payment-method', [$fixture['workspace']->uid]));

        $response->assertRedirectContains('billing.stripe.test');
        $call = $this->stripe->callsOf('createBillingPortalSession')[0];
        $this->assertSame('payment_method_update', $call['args']['flow'],
            'The documented deep link for replacing the default payment method.');
        $this->assertSame((string) $fixture['subscription']->provider_customer_id, $call['args']['customer']);
    }

    public function test_the_grace_warning_offers_the_real_recovery_action(): void
    {
        $fixture = $this->signedInSubscriber();
        $this->stripe->setSubscriptionStatus($fixture['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);
        $this->deliver('invoice.payment_failed', $fixture);

        $response = $this->get($this->planUrl($fixture['workspace']))->assertOk();

        $response->assertSee('We could not take your latest payment.')
            ->assertSee('Update your payment method')
            // The Grace deadline, from the canonical lifecycle.
            ->assertSee('After that it locks until payment succeeds.');

        // ...and it does NOT send them to legacy Ultimate SMS billing.
        $this->assertStringNotContainsString('customer.subscriptions', $response->getContent());
    }

    public function test_no_card_field_is_ever_rendered_by_this_application(): void
    {
        $fixture = $this->signedInSubscriber();

        $html = $this->get($this->planUrl($fixture['workspace']))->assertOk()->getContent();

        foreach (['name="card_number"', 'name="cvc"', 'name="exp_month"', 'autocomplete="cc-number"'] as $card) {
            $this->assertStringNotContainsString($card, $html,
                'Card details are collected by Stripe, never by this application.');
        }
    }

    // =================================================================
    // Security
    // =================================================================

    public function test_staff_cannot_perform_financial_subscription_actions(): void
    {
        $fixture = $this->subscribedWorkspace();
        $staffUser = $this->createCustomer();
        $this->createMembership($fixture['workspace'], $staffUser->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($staffUser);

        $workspaceUid = $fixture['workspace']->uid;
        $this->post(route('customer.workspaces.plan.change', [$workspaceUid]), ['tier' => 'core', 'confirm' => '1'])->assertNotFound();
        $this->post(route('customer.workspaces.plan.cancel', [$workspaceUid]), ['confirm' => '1'])->assertNotFound();
        $this->post(route('customer.workspaces.plan.resume', [$workspaceUid]))->assertNotFound();
        $this->get(route('customer.workspaces.plan.payment-method', [$workspaceUid]))->assertNotFound();

        $this->assertFalse((bool) $fixture['subscription']->refresh()->cancel_at_period_end);
    }

    public function test_a_stranger_cannot_touch_another_workspaces_subscription(): void
    {
        $victim = $this->subscribedWorkspace();
        $stranger = $this->subscribedWorkspace();
        $this->authenticateAs($stranger['customer']);

        $this->post(route('customer.workspaces.plan.cancel', [$victim['workspace']->uid]), ['confirm' => '1'])
            ->assertNotFound();

        $this->assertFalse((bool) $victim['subscription']->refresh()->cancel_at_period_end);
    }

    public function test_a_provider_event_can_only_move_its_own_workspace(): void
    {
        $first = $this->subscribedWorkspace();
        $second = $this->subscribedWorkspace();

        // An event for the SECOND Workspace's subscription, with the FIRST
        // Workspace's customer id in the payload. The payload's customer field
        // is never trusted: resolution is by provider subscription reference,
        // and the outcome is re-read from the provider.
        $this->stripe->setSubscriptionStatus($second['provider_subscription_id'], PlatformSubscriptionStatus::PastDue);
        $this->deliver('customer.subscription.updated', $second, [
            'customer' => (string) $first['subscription']->provider_customer_id,
        ])->assertOk();

        $this->assertSame(PlatformSubscriptionStatus::PastDue, $second['subscription']->refresh()->status);
        $this->assertSame(PlatformSubscriptionStatus::Active, $first['subscription']->refresh()->status,
            'A forged customer id cannot reach across to another Workspace.');
        $this->assertNull(DB::table('workspace_plan_assignments')
            ->where('workspace_id', $first['workspace']->id)->value('grace_started_at'));
    }

    public function test_a_mismatched_subscription_identity_fails_closed(): void
    {
        $fixture = $this->subscribedWorkspace();

        // The local row already knows its provider subscription; an event
        // claiming a different one for it must be refused, never applied.
        $fixture['subscription']->forceFill(['provider_subscription_id' => 'sub_something_else'])->save();

        $this->deliver('customer.subscription.updated', $fixture)->assertOk();

        $event = \App\Models\PlatformSubscriptionEvent::query()->orderByDesc('id')->firstOrFail();
        $this->assertContains($event->last_error, ['subscription_mismatch', 'no_matching_local_record'],
            'Either the resolver or the finalizer cross-check refuses it; both are fail-closed.');
        $this->assertSame(\App\Enums\PlatformBilling\PlatformSubscriptionEventState::Failed, $event->state);
    }

    public function test_an_anonymous_visitor_cannot_reach_the_subscription_actions(): void
    {
        $fixture = $this->subscribedWorkspace();

        // The customer route group answers 401 rather than redirecting.
        $this->post(route('customer.workspaces.plan.cancel', [$fixture['workspace']->uid]), ['confirm' => '1'])
            ->assertUnauthorized();

        $this->assertFalse((bool) $fixture['subscription']->refresh()->cancel_at_period_end);
    }
}
