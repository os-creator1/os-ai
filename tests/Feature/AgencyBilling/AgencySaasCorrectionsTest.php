<?php

namespace Tests\Feature\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\AgencyBilling\AgencyStripeConnectionStatus;
use App\Enums\AgencyBilling\AgencySubscriptionEventState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencyClientSubscriptionEvent;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * Lane C — the two pre-PR corrections.
 *
 * Both are the same shape of defect: something that was true at the moment it
 * was written stopped being true one state change later, and the customer paid
 * for it.
 */
class AgencySaasCorrectionsTest extends TestCase
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

    private function decisionFor($workspace)
    {
        return app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh());
    }

    private function tierOf($workspace): ?WorkspacePlanTier
    {
        return app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier;
    }

    /** @return array<string, mixed> */
    private function ready(string $agencyName = 'Northwind Agency', string $clientName = 'Harbor Lane'): array
    {
        $fixture = $this->agencyWithClient($agencyName, $clientName);
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        return $fixture;
    }

    /**
     * The REAL route into Locked for an agency-billed client: their renewal
     * fails at the AGENCY, the canonical 3-day grace window opens, nobody pays,
     * and the scheduled sweep locks them. Never a hand-written `locked_at`.
     */
    private function lockThroughGrace(array $fixture, array $enrolled): void
    {
        $this->agencyStripe->setSubscriptionStatus(
            $enrolled['provider_subscription_id'],
            AgencyClientSubscriptionStatus::PastDue,
        );
        $this->deliverAgencyEvent('invoice.payment_failed', $enrolled, ['event_id' => 'evt_fail'])->assertOk();

        $decision = $this->decisionFor($fixture['clientWorkspace']);
        $this->assertTrue($decision->isInGracePeriod(), 'Grace keeps full access — that is the point of it.');

        $this->travelTo(now()->addDays(EntitlementManager::GRACE_PERIOD_DAYS + 1));
        $this->artisan('workspaces:advance-account-lifecycle')->assertExitCode(0);

        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isLocked());
        $this->assertNotNull(WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->sole()->locked_at);
    }

    // =====================================================================
    // 1 — A LOCKED AGENCY-BILLED CLIENT MUST BE ABLE TO PAY THEIR WAY OUT
    //
    // The money that ends their lock happens to be owed to their agency
    // rather than to us. That changes who is paid, and nothing at all about
    // the customer's right to reach the one action that ends the lock.
    // =====================================================================

    public function test_a_locked_client_owner_reaches_the_agency_billing_portal(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);
        $this->lockThroughGrace($fixture, $enrolled);

        $this->authenticateAs($fixture['clientOwner']);
        $uid = $fixture['clientWorkspace']->uid;

        // The locked screen is where they land, and the billing page is
        // reachable from it.
        $this->get(route('customer.account-locked.show'))->assertOk();
        $this->get(route('customer.workspaces.agency-plan.show', [$uid]))
            ->assertOk()
            ->assertSee('Northwind Agency');

        $response = $this->get(route('customer.workspaces.agency-plan.payment-method', [$uid]));
        $response->assertRedirect();

        $portal = $this->agencyStripe->callsOf('createBillingPortalSession');
        $this->assertCount(1, $portal);
        $this->assertSame(
            (string) $enrolled['subscription']->refresh()->connected_account_id,
            $portal[0]['args']['account'],
            'The portal is the AGENCY\'s, because the agency is who they owe.',
        );
        $this->assertSame('payment_method_update', $portal[0]['args']['flow']);
    }

    public function test_a_locked_clients_active_admin_may_also_recover(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);
        $this->lockThroughGrace($fixture, $enrolled);

        $admin = $this->createCustomer();
        $this->createMembership($fixture['clientWorkspace'], $admin->user, [
            'role' => WorkspaceMembershipRole::Admin,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($admin);

        $this->get(route('customer.workspaces.agency-plan.payment-method', [$fixture['clientWorkspace']->uid]))
            ->assertRedirect();

        $this->assertCount(1, $this->agencyStripe->callsOf('createBillingPortalSession'),
            'The established financial-authority rule is unchanged by the lock.');
    }

    public function test_a_locked_clients_staff_and_a_stranger_still_cannot_open_the_portal(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);
        $this->lockThroughGrace($fixture, $enrolled);

        $uid = $fixture['clientWorkspace']->uid;

        $staff = $this->createCustomer();
        $this->createMembership($fixture['clientWorkspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($staff);
        $this->get(route('customer.workspaces.agency-plan.payment-method', [$uid]))->assertNotFound();

        $stranger = $this->createCustomer();
        $this->authenticateAs($stranger);
        $this->get(route('customer.workspaces.agency-plan.payment-method', [$uid]))->assertNotFound();

        // And the AGENCY, which manages this client in every other respect.
        $this->authenticateAs($fixture['agencyOwner']);
        $this->get(route('customer.workspaces.agency-plan.payment-method', [$uid]))->assertNotFound();

        $this->assertSame([], $this->agencyStripe->callsOf('createBillingPortalSession'),
            'Allowlisting a route grants no authority; the controller and domain still decide.');
    }

    public function test_a_fully_canceled_client_reaches_resubscribe_and_the_checkout_return(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        // It ends for real, which locks them.
        $this->agencyStripe->setSubscriptionStatus(
            $enrolled['provider_subscription_id'], AgencyClientSubscriptionStatus::Canceled,
        );
        $this->deliverAgencyEvent('customer.subscription.deleted', $enrolled, ['event_id' => 'evt_ended'])->assertOk();
        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isLocked());

        $this->authenticateAs($fixture['clientOwner']);
        $uid = $fixture['clientWorkspace']->uid;

        $this->get(route('customer.workspaces.agency-plan.show', [$uid]))
            ->assertOk()
            ->assertSee('Ended')
            ->assertSee('data-role="agency-plan-resubscribe"', false);

        $this->post(route('customer.workspaces.agency-plan.resubscribe', [$uid]), [
            'plan_uid' => $plan->uid, 'confirm' => '1',
        ])->assertRedirect();

        $subscription = AgencyClientSubscription::query()->sole();
        $this->agencyStripe->completeCheckout((string) $subscription->provider_checkout_session_id);

        // THE RETURN ARRIVES WHILE THE ACCOUNT IS STILL LOCKED — confirmation
        // has not converged yet, so a gate that bounced it would strand a
        // customer who has just paid.
        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isLocked());

        $this->get(route('customer.workspaces.agency-plan.return', [$uid]))
            ->assertRedirect(route('customer.workspaces.agency-plan.show', [$uid]));

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked(),
            'Provider confirmation restores the CLIENT\'s own lifecycle, and only theirs.');
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['clientWorkspace']));
    }

    public function test_a_locked_client_can_pay_a_fresh_offer_through_checkout(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        // Ended and locked…
        $this->agencyStripe->setSubscriptionStatus(
            $enrolled['provider_subscription_id'], AgencyClientSubscriptionStatus::Canceled,
        );
        $this->deliverAgencyEvent('customer.subscription.deleted', $enrolled, ['event_id' => 'evt_ended'])->assertOk();
        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isLocked());

        // …and the agency offers them a FRESH plan, which puts the row back to
        // `offered`. `.resubscribe` refuses a non-terminal row, so `.checkout`
        // is this customer's only way out — which is exactly why it is
        // allowlisted.
        $cheaper = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id,
            WorkspacePlanTier::Core, '149.00', null, 'Starter');
        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace'], $fixture['clientWorkspace'], $cheaper,
        );

        $this->authenticateAs($fixture['clientOwner']);
        $uid = $fixture['clientWorkspace']->uid;

        $this->post(route('customer.workspaces.agency-plan.checkout', [$uid]), ['confirm' => '1'])
            ->assertRedirect();

        $subscription = AgencyClientSubscription::query()->sole();
        $this->agencyStripe->completeCheckout((string) $subscription->provider_checkout_session_id);
        $this->get(route('customer.workspaces.agency-plan.return', [$uid]))->assertRedirect();

        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked());
        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['clientWorkspace']));
    }

    public function test_the_allowlist_is_recovery_only_and_unlocks_nothing_operational(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);
        $this->lockThroughGrace($fixture, $enrolled);

        $this->authenticateAs($fixture['clientOwner']);
        $uid = $fixture['clientWorkspace']->uid;
        $locked = route('customer.account-locked.show');

        // Ordinary operational surfaces of the same locked Workspace.
        $this->get(route('customer.workspaces.team.show', [$uid]))->assertRedirect($locked);
        $this->get(route('customer.workspaces.settings.show', [$uid]))->assertRedirect($locked);
        $this->get(route('customer.workspaces.show', [$uid]))->assertRedirect($locked);

        // And the ordinary billing MUTATIONS, which are not recovery.
        $this->post(route('customer.workspaces.agency-plan.change', [$uid]), [
            'plan_uid' => $plan->uid, 'confirm' => '1',
        ])->assertRedirect($locked);
        $this->post(route('customer.workspaces.agency-plan.cancel', [$uid]), ['confirm' => '1'])
            ->assertRedirect($locked);
        $this->post(route('customer.workspaces.agency-plan.resume', [$uid]))->assertRedirect($locked);

        $this->assertSame([], $this->agencyStripe->callsOf('changeSubscriptionPrice'));
        $this->assertSame([], $this->agencyStripe->callsOf('setCancelAtPeriodEnd'));
    }

    public function test_an_agencys_own_lapse_is_never_shown_as_the_clients_payment_failure(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        $clientAssignment = WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->sole();

        // The AGENCY's own account lapses. The client is paid up.
        app(EntitlementManager::class)->lockForNonPayment($fixture['agencyWorkspace'], null, 'Agency lapsed.');

        // Paying again cannot resolve somebody else's locked account.
        // Delivered before signing in, because Stripe posts unauthenticated.
        $this->deliverAgencyEvent('invoice.paid', $enrolled, ['event_id' => 'evt_client_paid'])->assertOk();

        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isLocked(),
            'A client cannot buy their way out of their agency\'s own delinquency.');
        $this->assertSame('agency_locked', $this->decisionFor($fixture['clientWorkspace'])->reason);
        $this->assertNull($clientAssignment->refresh()->locked_at,
            'The client\'s OWN lifecycle state is untouched by their agency\'s lapse.');

        // And the page says so honestly, rather than blaming their card.
        $this->authenticateAs($fixture['clientOwner']);
        $uid = $fixture['clientWorkspace']->uid;

        $this->get(route('customer.workspaces.agency-plan.show', [$uid]))
            ->assertOk()
            ->assertSee('Unavailable')
            ->assertSee('status of the agency account')
            ->assertDontSee('could not take your latest payment')
            ->assertDontSee('data-role="agency-plan-billing-problem"', false);

        // The agency fixes its own account, and the client resumes on their own
        // state alone.
        app(EntitlementManager::class)->recoverAccess($fixture['agencyWorkspace'], null, 'Agency paid.');
        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked());
    }

    // =====================================================================
    // 2 — A DISCONNECTED CONNECTION STILL OWNS THE SUBSCRIPTIONS IT CREATED
    //
    // `disconnect()` deliberately does not cancel an agency's existing client
    // billing relationships. Those keep renewing, failing and cancelling at
    // the provider, and every one of those events names the account that has
    // since been disconnected.
    // =====================================================================

    public function test_a_disconnected_connection_still_resolves_its_own_events(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        $accountId = (string) $enrolled['subscription']->refresh()->connected_account_id;

        // The agency disconnects. Its existing client subscriptions are
        // deliberately left alone at the provider.
        $this->connections()->disconnect((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
        $this->assertSame(AgencyStripeConnectionStatus::Disconnected,
            $this->connections()->history($fixture['agencyWorkspace'])->first()->status);
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $enrolled['subscription']->refresh()->status,
            'And the client\'s subscription is not cancelled on the agency\'s behalf.');

        // A renewal failure now arrives for that still-live subscription.
        $this->agencyStripe->setSubscriptionStatus(
            $enrolled['provider_subscription_id'], AgencyClientSubscriptionStatus::PastDue,
        );

        [$body, $headers] = $this->agencyWebhookBody(
            'invoice.payment_failed', $accountId, $enrolled['provider_subscription_id'],
            (string) $enrolled['subscription']->refresh()->provider_customer_id,
            'evt_after_disconnect', null, (string) $enrolled['subscription']->uid,
        );
        $this->postAgencyWebhook($body, $headers)->assertOk();

        $event = AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_after_disconnect')->sole();
        $this->assertSame(AgencySubscriptionEventState::Processed, $event->state,
            'A historical connection still owns its own events.');

        $this->assertSame(AgencyClientSubscriptionStatus::PastDue, $enrolled['subscription']->refresh()->status);
        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isInGracePeriod(),
            'So the client\'s independent lifecycle keeps updating rather than silently stopping.');
    }

    public function test_a_disconnected_connection_can_still_never_sell_anything(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->connections()->disconnect((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']));

        // No new offer…
        try {
            $this->subscriptions()->offer(
                (int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace'],
                $fixture['clientWorkspace'], $plan,
            );
            $this->fail('A disconnected account must never sell anything again.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::NO_CONNECTION, $e->reason);
        }

        // …and no new price binding or publication.
        try {
            $this->plans()->bindProviderPrice((int) $fixture['agencyOwner']->user_id, $plan->refresh(), null);
            $this->fail('Binding a price needs a chargeable account.');
        } catch (AgencyBillingException $e) {
            $this->assertSame(AgencyBillingException::NO_CONNECTION, $e->reason);
        }

        $this->assertSame(0, AgencyClientSubscription::query()->count());
    }

    public function test_the_historical_lookup_does_not_reconnect_or_re_enable_anything(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);
        $accountId = (string) $enrolled['subscription']->refresh()->connected_account_id;

        $this->connections()->disconnect((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);
        $this->deliverAgencyEvent('invoice.paid', $enrolled, ['event_id' => 'evt_still_paying'])->assertOk();

        $connection = $this->connections()->history($fixture['agencyWorkspace'])->first();

        $this->assertSame(AgencyStripeConnectionStatus::Disconnected, $connection->status,
            'Resolving ownership answers a question; it does not change an answer.');
        $this->assertFalse((bool) $connection->charges_enabled);
        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    public function test_another_agencys_account_is_still_refused_after_a_disconnect(): void
    {
        $first = $this->ready('First Agency', 'First Client');
        $second = $this->ready('Second Agency', 'Second Client');

        $plan = $this->publishedPlan($first['agencyWorkspace'], (int) $first['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($first, $plan);

        $this->connections()->disconnect((int) $first['agencyOwner']->user_id, $first['agencyWorkspace']);

        $otherAccount = (string) $this->connections()
            ->liveConnection($second['agencyWorkspace'])->stripe_account_id;

        [$body, $headers] = $this->agencyWebhookBody(
            'customer.subscription.deleted', $otherAccount, $enrolled['provider_subscription_id'],
            (string) $enrolled['subscription']->refresh()->provider_customer_id,
            'evt_cross', null, (string) $enrolled['subscription']->uid,
        );
        $this->postAgencyWebhook($body, $headers)->assertOk();

        $this->assertSame(AgencySubscriptionEventState::Failed,
            AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_cross')->sole()->state);
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $enrolled['subscription']->refresh()->status,
            'Widening ownership resolution must not widen who may speak for whom.');
    }

    public function test_an_account_no_agency_ever_connected_is_still_refused(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        $this->connections()->disconnect((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        [$body, $headers] = $this->agencyWebhookBody(
            'customer.subscription.deleted', 'acct_neverSeenBefore',
            $enrolled['provider_subscription_id'], null, 'evt_unknown',
        );
        $this->postAgencyWebhook($body, $headers)->assertOk();

        $event = AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_unknown')->sole();
        $this->assertSame(AgencySubscriptionEventState::Failed, $event->state);
        $this->assertSame('unknown_connected_account', $event->last_error);
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $enrolled['subscription']->refresh()->status);
    }

    public function test_a_retired_subscription_still_cannot_mutate_its_replacement_after_a_disconnect(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);
        $oldProviderSubscriptionId = $enrolled['provider_subscription_id'];

        // It ends, the client comes back…
        $this->agencyStripe->setSubscriptionStatus($oldProviderSubscriptionId, AgencyClientSubscriptionStatus::Canceled);
        $this->deliverAgencyEvent('customer.subscription.deleted', $enrolled, ['event_id' => 'evt_ended'])->assertOk();

        $session = $this->subscriptions()->startResubscribeCheckout(
            (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'], $plan,
            'client@example.test', 'https://app.test/return', 'https://app.test/cancel',
        );
        $this->agencyStripe->completeCheckout($session->sessionId);
        $this->subscriptions()->confirmCheckoutSession($enrolled['subscription']->refresh(), $session->sessionId);

        $subscription = $enrolled['subscription']->refresh();
        $accountId = (string) $subscription->connected_account_id;

        // …and only then does the agency disconnect.
        $this->connections()->disconnect((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        // A late event for the PREVIOUS life, resolving through the historical
        // connection, must still be refused by the retired list.
        [$body, $headers] = $this->agencyWebhookBody(
            'customer.subscription.deleted', $accountId, $oldProviderSubscriptionId,
            (string) $subscription->provider_customer_id, 'evt_old_life', null, (string) $subscription->uid,
        );
        $this->postAgencyWebhook($body, $headers)->assertOk();

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked(),
            'A dead subscription may never lock the one that replaced it, disconnect or no disconnect.');
    }

    public function test_revoked_provider_access_fails_closed_and_fabricates_nothing(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);
        $accountId = (string) $enrolled['subscription']->refresh()->connected_account_id;

        $this->connections()->disconnect((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        // The agency also revoked our access at Stripe, so provider truth can
        // no longer be retrieved for that account.
        unset($this->agencyStripe->subscriptions[$enrolled['provider_subscription_id']]['account']);

        [$body, $headers] = $this->agencyWebhookBody(
            'customer.subscription.deleted', $accountId, $enrolled['provider_subscription_id'],
            (string) $enrolled['subscription']->provider_customer_id, 'evt_revoked', null,
            (string) $enrolled['subscription']->uid,
        );

        // The job rethrows so its bounded retry runs and Stripe re-delivers —
        // correct for a transient outage, and harmless for a permanent one.
        // Under the test queue driver that propagates to the HTTP response, so
        // what matters here is the DURABLE outcome, asserted below.
        try {
            $this->postAgencyWebhook($body, $headers);
        } catch (\Throwable) {
            // Expected: provider truth could not be retrieved.
        }

        $event = AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_revoked')->sole();

        $this->assertSame(AgencySubscriptionEventState::Failed, $event->state,
            'Visibly failed, so an operator can see it and re-drive it later.');
        $this->assertSame(AgencyBillingException::PROVIDER_FAILED, $event->last_error,
            'A safe reason code, never provider text and never an exception class name.');

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $enrolled['subscription']->refresh()->status,
            'And NOTHING was taken from the unverified payload — no cancellation, no renewal, no state at all.');
        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked());
    }
}
