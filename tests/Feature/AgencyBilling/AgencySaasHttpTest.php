<?php

namespace Tests\Feature\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Entitlement\EntitlementManager;
use App\Library\ViewAs\ViewAsManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencySaasPlan;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * Lane C §C8 — the browser surfaces, through HTTP.
 *
 * The domain is proved elsewhere; these tests prove an agency and a client can
 * actually DO it: connect an account, publish a plan, offer it, consent to it,
 * and manage it afterwards — and that the people who must not be able to do
 * those things cannot.
 */
class AgencySaasHttpTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAgencySaasFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformAdminId();
        $this->ensureRequiredAppConfigRowsExist();
        $this->bindFakeAgencyStripe();
    }

    /** @return array<string, mixed> */
    private function readyAgency(): array
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        return $fixture;
    }

    // =====================================================================
    // Agency surfaces
    // =====================================================================

    public function test_an_agency_owner_can_walk_the_whole_saas_setup_in_the_browser(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        // 1. The connection page says, truthfully, that nothing is connected.
        $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertOk()
            ->assertSee('Not connected');

        // 2. Connecting sends them to Stripe's own hosted onboarding.
        $this->post(route('customer.workspaces.agency.saas.stripe.connect', [$uid]), [
            'country' => 'US', 'email' => 'agency@example.test',
        ])->assertRedirect();

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->agencyStripe->completeOnboarding((string) $connection->stripe_account_id);

        $this->post(route('customer.workspaces.agency.saas.stripe.sync', [$uid]))->assertRedirect();

        $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertOk()
            ->assertSee('Ready to take payments')
            ->assertDontSee((string) $connection->stripe_account_id);

        // 3. A plan, priced by the agency in the agency's own currency.
        $this->ensureCanonicalTierPriced(WorkspacePlanTier::Growth);

        $this->post(route('customer.workspaces.agency.saas.plans.store', [$uid]), [
            'name' => 'Growth Partner',
            'description' => 'Everything we run for you.',
            'tier' => 'growth',
            'price' => '349.00',
            'currency_id' => $this->fixtureCurrencyId(),
            'billing_cycle' => 'monthly',
        ])->assertRedirect();

        $plan = AgencySaasPlan::query()->sole();
        $this->assertFalse((bool) $plan->is_published, 'A new plan is not on sale until its price is verified.');

        // 4. Generate and verify the Stripe price on the agency's own account.
        $this->post(route('customer.workspaces.agency.saas.plans.price', [$uid, $plan->uid]), [])
            ->assertRedirect();
        $this->post(route('customer.workspaces.agency.saas.plans.publish', [$uid, $plan->uid]))
            ->assertRedirect();

        $this->assertTrue((bool) $plan->refresh()->is_published);

        $this->get(route('customer.workspaces.agency.saas.plans', [$uid]))
            ->assertOk()
            ->assertSee('Growth Partner')
            ->assertSee('Published');

        // 5. Offer it to the managed client. Nothing is charged.
        $this->post(route('customer.workspaces.agency.saas.clients.offer', [$uid, $fixture['clientWorkspace']->uid]), [
            'plan_uid' => $plan->uid, 'confirm' => '1',
        ])->assertRedirect();

        $this->assertSame(AgencyClientSubscriptionStatus::Offered, $this->statusOf($fixture['clientWorkspace']));
        $this->assertSame([], $this->agencyStripe->callsOf('createSubscriptionCheckout'));
    }

    public function test_the_revenue_page_labels_the_money_as_the_agencys_own(): void
    {
        $fixture = $this->readyAgency();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, price: '349.00');
        $this->enrolledClient($fixture, $plan);

        $this->authenticateAs($fixture['agencyOwner']);

        $this->get(route('customer.workspaces.agency.saas.revenue', [$fixture['agencyWorkspace']->uid]))
            ->assertOk()
            ->assertSee('349.00')
            ->assertSee('your own Stripe account')
            ->assertSee('not platform revenue');
    }

    public function test_agency_staff_may_look_but_never_configure_revenue(): void
    {
        $fixture = $this->readyAgency();
        $staff = $this->createCustomer();
        $this->createMembership($fixture['agencyWorkspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($staff);

        $uid = $fixture['agencyWorkspace']->uid;

        // Reading is ordinary agency-team work — and the owner-only controls
        // are simply not rendered for them.
        $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertOk()
            ->assertSee('Ready to take payments')
            ->assertDontSee('data-role="agency-stripe-disconnect"', false);

        $this->get(route('customer.workspaces.agency.saas.plans', [$uid]))
            ->assertOk()
            ->assertDontSee('data-role="agency-saas-create-plan"', false);

        // Writing is not. The manager refuses; the page reports it.
        $this->post(route('customer.workspaces.agency.saas.stripe.disconnect', [$uid]), ['confirm' => '1'])
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertNotNull($this->connections()->liveConnection($fixture['agencyWorkspace']),
            'Staff cannot disconnect the account that receives the agency\'s money.');
    }

    public function test_an_unrelated_actor_cannot_see_an_agencys_saas_surface(): void
    {
        $fixture = $this->readyAgency();
        $stranger = $this->createCustomer();
        $this->authenticateAs($stranger);

        $uid = $fixture['agencyWorkspace']->uid;

        $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]))->assertNotFound();
        $this->get(route('customer.workspaces.agency.saas.plans', [$uid]))->assertNotFound();
        $this->get(route('customer.workspaces.agency.saas.revenue', [$uid]))->assertNotFound();
    }

    // =====================================================================
    // Client surfaces — the consent boundary
    // =====================================================================

    public function test_a_client_reviews_the_real_terms_and_consents_for_themselves(): void
    {
        $fixture = $this->readyAgency();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, price: '349.00');

        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            $fixture['clientWorkspace'],
            $plan,
        );

        $this->authenticateAs($fixture['clientOwner']);
        $clientUid = $fixture['clientWorkspace']->uid;

        // The offer page names the agency, the price and the cycle.
        $this->get(route('customer.workspaces.agency-plan.show', [$clientUid]))
            ->assertOk()
            ->assertSee('Northwind Agency')
            ->assertSee('349.00')
            ->assertSee('Offered')
            ->assertSee('data-role="agency-plan-consent"', false);

        // Consent opens hosted Checkout on the agency's account.
        $this->post(route('customer.workspaces.agency-plan.checkout', [$clientUid]), ['confirm' => '1'])
            ->assertRedirect();

        $subscription = AgencyClientSubscription::query()->sole();
        $this->agencyStripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        $this->get(route('customer.workspaces.agency-plan.return', [$clientUid]))
            ->assertRedirect(route('customer.workspaces.agency-plan.show', [$clientUid]));

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(WorkspacePlanTier::Growth,
            app(EntitlementManager::class)->getWorkspaceEntitlementSummary($fixture['clientWorkspace']->fresh())->tier);

        $this->get(route('customer.workspaces.agency-plan.show', [$clientUid]))
            ->assertOk()
            ->assertSee('Active')
            ->assertSee('Manage payment method');
    }

    public function test_the_agency_cannot_reach_its_clients_consent_route(): void
    {
        $fixture = $this->readyAgency();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            $fixture['clientWorkspace'],
            $plan,
        );

        $this->authenticateAs($fixture['agencyOwner']);

        // 404, not 403: the agency does not even learn the surface exists for
        // a workspace it does not belong to.
        $this->post(route('customer.workspaces.agency-plan.checkout', [$fixture['clientWorkspace']->uid]), ['confirm' => '1'])
            ->assertNotFound();

        $this->assertSame([], $this->agencyStripe->callsOf('createSubscriptionCheckout'));
        $this->assertSame(AgencyClientSubscriptionStatus::Offered, $this->statusOf($fixture['clientWorkspace']));
    }

    public function test_client_staff_cannot_authorise_their_workspaces_payment(): void
    {
        $fixture = $this->readyAgency();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            $fixture['clientWorkspace'],
            $plan,
        );

        $staff = $this->createCustomer();
        $this->createMembership($fixture['clientWorkspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($staff);

        $this->post(route('customer.workspaces.agency-plan.checkout', [$fixture['clientWorkspace']->uid]), ['confirm' => '1'])
            ->assertNotFound();

        $this->assertSame([], $this->agencyStripe->callsOf('createSubscriptionCheckout'));
    }

    public function test_a_view_as_actor_cannot_fabricate_a_clients_financial_consent(): void
    {
        $fixture = $this->readyAgency();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace'],
            $fixture['clientWorkspace'],
            $plan,
        );

        // The agency owner starts a legitimate View As session on their client.
        $agencyOwner = $fixture['agencyOwner']->user->fresh();
        app(ViewAsManager::class)->startAgencyView(
            $agencyOwner,
            (string) $fixture['agencyWorkspace']->uid,
            (string) $fixture['clientWorkspace']->uid,
            'Support.',
        );

        // Even inside that session — with the viewed client's own tenancy —
        // the charge is refused. The DOMAIN refuses it, so it would hold even
        // if a route were ever forgotten in a middleware list.
        try {
            $this->subscriptions()->startCheckout(
                (int) $fixture['clientOwner']->user_id === (int) $agencyOwner->id
                    ? (int) $agencyOwner->id
                    : (int) $agencyOwner->id,
                $fixture['clientWorkspace'],
                'client@example.test',
                'https://app.test/return',
                'https://app.test/cancel',
            );
            $this->fail('A View As session must never be able to authorise a payment.');
        } catch (\App\Exceptions\AgencyBilling\AgencyBillingException $e) {
            $this->assertContains($e->reason, [
                \App\Exceptions\AgencyBilling\AgencyBillingException::CONSENT_NOT_AUTHORIZED,
                \App\Exceptions\AgencyBilling\AgencyBillingException::CONSENT_THROUGH_VIEW_AS,
            ], 'Refused either as the wrong actor or as a View As actor — both are fail-closed.');
        }

        $this->assertSame([], $this->agencyStripe->callsOf('createSubscriptionCheckout'));
        $this->assertSame(AgencyClientSubscriptionStatus::Offered, $this->statusOf($fixture['clientWorkspace']));
    }

    public function test_the_client_surface_never_contains_a_card_field(): void
    {
        $fixture = $this->readyAgency();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $this->enrolledClient($fixture, $plan);

        $this->authenticateAs($fixture['clientOwner']);

        $html = $this->get(route('customer.workspaces.agency-plan.show', [$fixture['clientWorkspace']->uid]))
            ->assertOk()->getContent();

        foreach (['name="card_number"', 'name="cvc"', 'name="exp_month"', 'autocomplete="cc-number"'] as $card) {
            $this->assertStringNotContainsString($card, $html,
                'Cards are typed on Stripe\'s own pages, never here.');
        }
    }

    public function test_a_client_can_cancel_and_resume_their_own_subscription(): void
    {
        $fixture = $this->readyAgency();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $this->enrolledClient($fixture, $plan);

        $this->authenticateAs($fixture['clientOwner']);
        $clientUid = $fixture['clientWorkspace']->uid;

        $this->post(route('customer.workspaces.agency-plan.cancel', [$clientUid]), ['confirm' => '1'])
            ->assertRedirect()->assertSessionHas('status', 'success');

        $subscription = AgencyClientSubscription::query()->sole();
        $this->assertTrue((bool) $subscription->refresh()->cancel_at_period_end);

        $this->get(route('customer.workspaces.agency-plan.show', [$clientUid]))
            ->assertOk()->assertSee('Ending at period end');

        $this->post(route('customer.workspaces.agency-plan.resume', [$clientUid]))
            ->assertRedirect()->assertSessionHas('status', 'success');

        $this->assertFalse((bool) $subscription->refresh()->cancel_at_period_end);
    }

    public function test_a_client_reaches_the_agency_hosted_billing_portal(): void
    {
        $fixture = $this->readyAgency();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        $this->authenticateAs($fixture['clientOwner']);

        $response = $this->get(route('customer.workspaces.agency-plan.payment-method', [$fixture['clientWorkspace']->uid]));

        $response->assertRedirect();

        $portal = $this->agencyStripe->callsOf('createBillingPortalSession');
        $this->assertCount(1, $portal);
        $this->assertSame(
            (string) $enrolled['subscription']->refresh()->connected_account_id,
            $portal[0]['args']['account'],
            'The portal is the AGENCY\'s, because the agency is who charges them.',
        );
        $this->assertSame('payment_method_update', $portal[0]['args']['flow']);
    }
}
