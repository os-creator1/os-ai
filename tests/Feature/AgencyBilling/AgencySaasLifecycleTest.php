<?php

namespace Tests\Feature\AgencyBilling;

use App\Enums\AgencyBilling\AgencyClientSubscriptionStatus;
use App\Enums\AgencyBilling\AgencySubscriptionEventState;
use App\Enums\Entitlement\CustomerAccountAccessState;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Exceptions\AgencyBilling\AgencyBillingException;
use App\Library\AgencyBilling\AgencyClientSubscriptionManager;
use App\Library\Entitlement\CustomerAccountAccessResolver;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyClientSubscription;
use App\Models\AgencyClientSubscriptionEvent;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use App\Models\WorkspacePlanAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * Lane C §C4.1/§C7 — the subscription's whole life, and the isolation rules
 * that make it safe to run many agencies on one platform.
 */
class AgencySaasLifecycleTest extends TestCase
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

    private function tierOf($workspace): ?WorkspacePlanTier
    {
        return app(EntitlementManager::class)->getWorkspaceEntitlementSummary($workspace->fresh())->tier;
    }

    private function decisionFor($workspace)
    {
        return app(CustomerAccountAccessResolver::class)->resolve($workspace->fresh());
    }

    /** @return array<string, mixed> */
    private function ready(string $agencyName = 'Northwind Agency', string $clientName = 'Harbor Lane'): array
    {
        $fixture = $this->agencyWithClient($agencyName, $clientName);
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        return $fixture;
    }

    // =====================================================================
    // §C7 — activation without the browser
    // =====================================================================

    public function test_the_webhook_alone_finishes_enrollment_when_the_browser_never_returns(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace'], $fixture['clientWorkspace'], $plan,
        );
        $session = $this->subscriptions()->startCheckout(
            (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'],
            'client@example.test', 'https://app.test/return', 'https://app.test/cancel',
        );

        // The client pays and closes the tab. Nothing local has run.
        $providerSubscriptionId = $this->agencyStripe->completeCheckout($session->sessionId);
        $subscription = AgencyClientSubscription::query()->sole();

        [$body, $headers] = $this->agencyWebhookBody(
            'customer.subscription.updated',
            (string) $subscription->connected_account_id,
            $providerSubscriptionId,
            null,
            'evt_webhook_only',
            null,
            (string) $subscription->uid,
        );
        $this->postAgencyWebhook($body, $headers)->assertOk();

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['clientWorkspace']),
            'A client who pays and closes the tab still gets what they paid for.');
        $this->assertSame(CustomerAccountAccessState::Usable, $this->decisionFor($fixture['clientWorkspace'])->state);
    }

    public function test_the_browser_return_and_the_webhook_race_converges_once(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        $before = DB::table('workspace_entitlement_transitions')->count();

        $this->deliverAgencyEvent('customer.subscription.updated', $enrolled, ['event_id' => 'evt_race_a'])->assertOk();
        $this->subscriptions()->confirmCheckoutSession($enrolled['subscription']->refresh(), $enrolled['session_id']);
        $this->deliverAgencyEvent('customer.subscription.updated', $enrolled, ['event_id' => 'evt_race_b'])->assertOk();

        $this->assertSame(1, WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->count());
        $this->assertSame($before, DB::table('workspace_entitlement_transitions')->count(),
            'Converging again writes no further canonical transitions.');
    }

    public function test_a_duplicate_event_is_a_no_op_at_the_database(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        $this->deliverAgencyEvent('customer.subscription.updated', $enrolled, ['event_id' => 'evt_dupe'])->assertOk();
        $this->deliverAgencyEvent('customer.subscription.updated', $enrolled, ['event_id' => 'evt_dupe'])->assertOk();

        $this->assertSame(1, AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_dupe')->count(),
            'unique(provider_event_id) makes duplicate delivery structural, not discretionary.');
    }

    public function test_an_out_of_order_event_never_moves_state_backwards(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        // A newer event lands first.
        $this->deliverAgencyEvent('customer.subscription.updated', $enrolled, ['event_id' => 'evt_new'])->assertOk();

        // Then a much older one arrives, after the provider moved to past_due.
        $this->agencyStripe->setSubscriptionStatus($enrolled['provider_subscription_id'], AgencyClientSubscriptionStatus::PastDue);
        $this->deliverAgencyEvent('customer.subscription.updated', $enrolled, [
            'event_id' => 'evt_old', 'created' => now()->subDay()->getTimestamp(),
        ])->assertOk();

        $this->assertNotSame(CustomerAccountAccessState::Locked, $this->decisionFor($fixture['clientWorkspace'])->state,
            'A late event may not lock a client who is fine.');
        $this->assertSame(1, WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->count());
    }

    // =====================================================================
    // §C4.1 — cross-agency and cross-account rejection
    // =====================================================================

    public function test_an_event_from_another_agencys_account_cannot_move_this_clients_subscription(): void
    {
        $first = $this->ready('First Agency', 'First Client');
        $second = $this->ready('Second Agency', 'Second Client');

        $firstPlan = $this->publishedPlan($first['agencyWorkspace'], (int) $first['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($first, $firstPlan);

        $otherAccount = (string) $this->connections()->liveConnection($second['agencyWorkspace'])->stripe_account_id;

        // The SECOND agency's account claims the FIRST agency's subscription.
        [$body, $headers] = $this->agencyWebhookBody(
            'customer.subscription.deleted',
            $otherAccount,
            $enrolled['provider_subscription_id'],
            (string) $enrolled['subscription']->refresh()->provider_customer_id,
            'evt_cross_agency',
            null,
            (string) $enrolled['subscription']->uid,
        );
        $this->postAgencyWebhook($body, $headers)->assertOk();

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $enrolled['subscription']->refresh()->status,
            'One agency\'s account can never speak for another agency\'s client.');
        $this->assertSame(CustomerAccountAccessState::Usable, $this->decisionFor($first['clientWorkspace'])->state);

        $event = AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_cross_agency')->sole();
        $this->assertSame(AgencySubscriptionEventState::Failed, $event->state);
    }

    public function test_an_event_naming_an_unknown_account_is_failed_closed(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        [$body, $headers] = $this->agencyWebhookBody(
            'customer.subscription.deleted',
            'acct_neverSeenBefore',
            $enrolled['provider_subscription_id'],
            null,
            'evt_unknown_account',
        );
        $this->postAgencyWebhook($body, $headers)->assertOk();

        $event = AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_unknown_account')->sole();
        $this->assertSame(AgencySubscriptionEventState::Failed, $event->state);
        $this->assertSame('unknown_connected_account', $event->last_error);
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $enrolled['subscription']->refresh()->status);
    }

    public function test_an_unsigned_webhook_is_refused_before_anything_is_stored(): void
    {
        $this->postAgencyWebhook('{"id":"evt_forged","type":"customer.subscription.updated"}', [
            'Stripe-Signature' => 'v1=not-the-real-signature',
        ])->assertStatus(400);

        $this->assertSame(0, AgencyClientSubscriptionEvent::query()->count(),
            'A body we cannot verify never reaches the database.');
    }

    // =====================================================================
    // §C7 — failure, grace, locked, recovery
    // =====================================================================

    public function test_a_failed_payment_opens_one_grace_window_then_locks_then_recovers(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        // 1. The client's card fails at the AGENCY.
        $this->agencyStripe->setSubscriptionStatus($enrolled['provider_subscription_id'], AgencyClientSubscriptionStatus::PastDue);
        $this->deliverAgencyEvent('invoice.payment_failed', $enrolled, ['event_id' => 'evt_fail_1'])->assertOk();

        $decision = $this->decisionFor($fixture['clientWorkspace']);
        $this->assertSame(CustomerAccountAccessState::Usable, $decision->state, 'Grace keeps full access.');
        $this->assertTrue($decision->isInGracePeriod());

        $graceStartedAt = WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->sole()->grace_started_at;

        // 2. A second failure must not slide the window.
        $this->deliverAgencyEvent('invoice.payment_failed', $enrolled, ['event_id' => 'evt_fail_2'])->assertOk();

        $this->assertEquals($graceStartedAt, WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->sole()->grace_started_at,
            'One window, however many failures arrive.');

        // 3. Grace elapses and the scheduled sweep locks the account.
        $this->travelTo(now()->addDays(EntitlementManager::GRACE_PERIOD_DAYS + 1));
        $this->artisan('workspaces:advance-account-lifecycle')->assertExitCode(0);

        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isLocked());

        // 4. The client pays their agency, and access returns immediately.
        $this->agencyStripe->setSubscriptionStatus($enrolled['provider_subscription_id'], AgencyClientSubscriptionStatus::Active);
        $this->deliverAgencyEvent('invoice.paid', $enrolled, ['event_id' => 'evt_paid'])->assertOk();

        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked());
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['clientWorkspace']),
            'And the data and tier were never destroyed.');
    }

    public function test_one_clients_nonpayment_never_touches_another_client(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $firstEnrolled = $this->enrolledClient($fixture, $plan);

        // A SECOND client of the SAME agency.
        $secondClient = $this->createIndependentWorkspaceBusiness(businessName: 'Second Client', workspaceName: 'Second Client WS');
        app(\App\Library\Workspace\AgencyClientRelationshipManager::class)->create(
            (int) $fixture['agencyOwner']->user_id,
            $fixture['agencyWorkspace']->fresh(),
            $secondClient['workspace'],
        );

        $secondFixture = array_merge($fixture, [
            'clientWorkspace' => $secondClient['workspace'],
            'clientOwner' => $secondClient['customer'],
        ]);
        $secondEnrolled = $this->enrolledClient($secondFixture, $plan);

        // The FIRST client stops paying, all the way to Locked.
        $this->agencyStripe->setSubscriptionStatus($firstEnrolled['provider_subscription_id'], AgencyClientSubscriptionStatus::Canceled);
        $this->deliverAgencyEvent('customer.subscription.deleted', $firstEnrolled, ['event_id' => 'evt_first_gone'])->assertOk();

        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isLocked());

        // The second client is entirely unaffected.
        $this->assertFalse($this->decisionFor($secondClient['workspace'])->isLocked(),
            'One client\'s non-payment is one client\'s problem.');
        $this->assertSame(AgencyClientSubscriptionStatus::Active, $secondEnrolled['subscription']->refresh()->status);

        // And so is the AGENCY's own account.
        $this->assertFalse($this->decisionFor($fixture['agencyWorkspace'])->isLocked(),
            'A client\'s failure never reaches the agency\'s own lifecycle.');
    }

    public function test_agency_delinquency_composes_upstream_without_overwriting_a_clients_own_state(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $this->enrolledClient($fixture, $plan);

        $clientAssignment = WorkspacePlanAssignment::query()
            ->where('workspace_id', $fixture['clientWorkspace']->id)->sole();
        $this->assertNull($clientAssignment->locked_at);

        // The AGENCY's own account is locked (its lane-A subscription lapsed).
        app(EntitlementManager::class)->lockForNonPayment($fixture['agencyWorkspace'], null, 'Agency lapsed.');

        $decision = $this->decisionFor($fixture['clientWorkspace']);
        $this->assertTrue($decision->isLocked(), 'The client loses effective access…');
        $this->assertSame('agency_locked', $decision->reason);

        $this->assertNull($clientAssignment->refresh()->locked_at,
            '…but not one byte of their OWN lifecycle state was overwritten.');

        // The agency recovers, and the client resumes on their own state alone.
        app(EntitlementManager::class)->recoverAccess($fixture['agencyWorkspace'], null, 'Agency paid.');

        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked());
    }

    // =====================================================================
    // §C7 — plan changes, cancellation and coming back
    // =====================================================================

    public function test_an_upgrade_is_immediate_and_a_downgrade_waits_for_the_boundary(): void
    {
        $fixture = $this->ready();
        $core = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id,
            WorkspacePlanTier::Core, '149.00', null, 'Starter');
        $growth = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id,
            WorkspacePlanTier::Growth, '349.00', null, 'Growth Partner');

        $enrolled = $this->enrolledClient($fixture, $core);
        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['clientWorkspace']));

        // Upgrade — the client asks, because the client pays.
        $direction = $this->subscriptions()->requestPlanChange(
            (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'], $growth,
        );

        $this->assertSame(AgencyClientSubscriptionManager::CHANGE_UPGRADED, $direction);
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['clientWorkspace']));
        $this->assertSame('349.00', (string) $enrolled['subscription']->refresh()->price_snapshot);
        $this->assertTrue($this->agencyStripe->callsOf('changeSubscriptionPrice')[0]['args']['prorate']);

        // Downgrade — nothing changes today.
        $direction = $this->subscriptions()->requestPlanChange(
            (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'], $core,
        );

        $this->assertSame(AgencyClientSubscriptionManager::CHANGE_DOWNGRADE_SCHEDULED, $direction);
        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['clientWorkspace']),
            'The client keeps the tier they have already paid for.');
        $this->assertSame('149.00', (string) $enrolled['subscription']->refresh()->pending_price_snapshot,
            'And the downgrade is bound to the terms agreed at REQUEST time.');
    }

    public function test_a_scheduled_downgrade_uses_the_terms_agreed_when_it_was_requested(): void
    {
        $fixture = $this->ready();
        $core = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id,
            WorkspacePlanTier::Core, '149.00', null, 'Starter');
        $growth = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id,
            WorkspacePlanTier::Growth, '349.00', null, 'Growth Partner');

        $enrolled = $this->enrolledClient($fixture, $growth);

        $this->subscriptions()->requestPlanChange(
            (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'], $core,
        );

        $agreedPriceId = (string) $enrolled['subscription']->refresh()->pending_price_id;

        // Weeks pass and the AGENCY raises the price of that very plan.
        $this->plans()->update((int) $fixture['agencyOwner']->user_id, $core, ['price' => '199.00'], 'Increase.');
        $this->plans()->bindProviderPrice((int) $fixture['agencyOwner']->user_id, $core->refresh(), null);
        $this->plans()->publish((int) $fixture['agencyOwner']->user_id, $core->refresh());

        $this->travelTo(now()->addMonths(2));
        $this->artisan('agency-subscriptions:apply-due-plan-changes')->assertExitCode(0);

        $subscription = $enrolled['subscription']->refresh();
        $this->assertSame('149.00', (string) $subscription->price_snapshot,
            'A client who agreed to 149.00 is not moved onto a repriced 199.00.');
        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['clientWorkspace']));

        $call = $this->agencyStripe->callsOf('changeSubscriptionPrice');
        $this->assertSame($agreedPriceId, end($call)['args']['price']);
        $this->assertFalse(end($call)['args']['prorate']);
    }

    public function test_a_lost_upgrade_response_is_repaired_by_the_webhook(): void
    {
        $fixture = $this->ready();
        $core = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id,
            WorkspacePlanTier::Core, '149.00', null, 'Starter');
        $growth = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id,
            WorkspacePlanTier::Growth, '349.00', null, 'Growth Partner');

        $enrolled = $this->enrolledClient($fixture, $core);

        // Stripe applies the change; our side never hears back.
        $this->agencyStripe->interleaveOnce('changeSubscriptionPrice', function (array $args, $gateway): void {
            $gateway->subscriptions[$args['subscription']]['price'] = $args['price'];

            throw AgencyBillingException::because(AgencyBillingException::PROVIDER_FAILED);
        });

        try {
            $this->subscriptions()->requestPlanChange(
                (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'], $growth,
            );
            $this->fail('The lost response must surface.');
        } catch (AgencyBillingException) {
            // Expected.
        }

        $this->assertSame(WorkspacePlanTier::Core, $this->tierOf($fixture['clientWorkspace']),
            'Nothing local ran, so the entitlement has not widened.');
        $this->assertNotNull($enrolled['subscription']->refresh()->pending_operation_uid,
            'But the intent survives, which is why it is written first.');

        $this->deliverAgencyEvent('customer.subscription.updated', $enrolled, ['event_id' => 'evt_repair'])->assertOk();

        $this->assertSame(WorkspacePlanTier::Growth, $this->tierOf($fixture['clientWorkspace']),
            'The webhook finishes the SAME operation.');
        $this->assertSame('349.00', (string) $enrolled['subscription']->refresh()->price_snapshot);
        $this->assertNull($enrolled['subscription']->refresh()->pending_operation_uid);
    }

    public function test_a_canceled_client_can_start_again_and_the_old_subscription_cannot_interfere(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);
        $oldProviderSubscriptionId = $enrolled['provider_subscription_id'];

        $before = [
            Workspace::query()->count(),
            Business::query()->count(),
            BusinessLocation::query()->count(),
            AgencyClientSubscription::query()->count(),
        ];

        // It ends for real.
        $this->agencyStripe->setSubscriptionStatus($oldProviderSubscriptionId, AgencyClientSubscriptionStatus::Canceled);
        $this->deliverAgencyEvent('customer.subscription.deleted', $enrolled, ['event_id' => 'evt_ended'])->assertOk();

        $this->assertTrue($enrolled['subscription']->refresh()->status->isTerminal());
        $this->assertTrue($this->decisionFor($fixture['clientWorkspace'])->isLocked());

        // The client comes back, on their own authority.
        $session = $this->subscriptions()->startResubscribeCheckout(
            (int) $fixture['clientOwner']->user_id,
            $fixture['clientWorkspace'],
            $plan,
            'client@example.test',
            'https://app.test/return',
            'https://app.test/cancel',
        );
        $newProviderSubscriptionId = $this->agencyStripe->completeCheckout($session->sessionId);
        $this->subscriptions()->confirmCheckoutSession($enrolled['subscription']->refresh(), $session->sessionId);

        $subscription = $enrolled['subscription']->refresh();

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->status);
        $this->assertSame($newProviderSubscriptionId, (string) $subscription->provider_subscription_id);
        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked());
        $this->assertContains($oldProviderSubscriptionId, $subscription->retiredProviderSubscriptionIds(),
            'The old provider subscription is kept for audit.');

        $this->assertSame($before, [
            Workspace::query()->count(),
            Business::query()->count(),
            BusinessLocation::query()->count(),
            AgencyClientSubscription::query()->count(),
        ], 'The client comes back to the account they already had.');

        // A late event for the PREVIOUS life must not lock the new one.
        [$body, $headers] = $this->agencyWebhookBody(
            'customer.subscription.deleted',
            (string) $subscription->connected_account_id,
            $oldProviderSubscriptionId,
            (string) $subscription->provider_customer_id,
            'evt_old_life',
            null,
            (string) $subscription->uid,
        );
        $this->postAgencyWebhook($body, $headers)->assertOk();

        $this->assertSame(AgencyClientSubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertFalse($this->decisionFor($fixture['clientWorkspace'])->isLocked(),
            'A dead subscription may never lock the one that replaced it.');
    }

    // =====================================================================
    // §C7 — one payable session, under concurrency
    // =====================================================================

    public function test_two_simultaneous_checkouts_leave_exactly_one_payable_session(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace'], $fixture['clientWorkspace'], $plan,
        );

        // A second request arrives while the first is waiting on Stripe.
        $this->agencyStripe->interleaveOnce('createSubscriptionCheckout', function () use ($fixture): void {
            $this->subscriptions()->startCheckout(
                (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'],
                'client@example.test', 'https://app.test/return', 'https://app.test/cancel',
            );
        });

        $result = $this->subscriptions()->startCheckout(
            (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'],
            'client@example.test', 'https://app.test/return', 'https://app.test/cancel',
        );

        $this->assertSame([$result->sessionId], $this->agencyStripe->payableSessionIds(),
            'Two payable sessions would mean two subscriptions for one client.');
        $this->assertSame($result->sessionId,
            (string) AgencyClientSubscription::query()->sole()->provider_checkout_session_id);
    }

    public function test_a_superseded_request_expires_the_session_it_created(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        $this->subscriptions()->offer(
            (int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace'], $fixture['clientWorkspace'], $plan,
        );

        // The session is already created at the provider when we discover
        // somebody replaced our attempt. Only the provider can un-pay it.
        $this->agencyStripe->interleaveOnce('createSubscriptionCheckout', function (): void {
            DB::table('agency_client_subscriptions')->update([
                'checkout_attempt_uid' => (string) \Illuminate\Support\Str::uuid(),
                'checkout_attempt_generation' => DB::raw('checkout_attempt_generation + 1'),
            ]);
        });

        $result = $this->subscriptions()->startCheckout(
            (int) $fixture['clientOwner']->user_id, $fixture['clientWorkspace'],
            'client@example.test', 'https://app.test/return', 'https://app.test/cancel',
        );

        $this->assertSame([$result->sessionId], $this->agencyStripe->payableSessionIds());
        $this->assertNotEmpty($this->agencyStripe->callsOf('expireCheckoutSession'),
            'The orphan is retired at the provider, not merely forgotten.');
    }

    // =====================================================================
    // §C1 — lane isolation
    // =====================================================================

    public function test_lane_c_creates_and_mutates_nothing_belonging_to_another_lane(): void
    {
        $fixture = $this->ready();
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);
        $enrolled = $this->enrolledClient($fixture, $plan);

        $this->deliverAgencyEvent('invoice.paid', $enrolled, ['event_id' => 'evt_paid'])->assertOk();

        foreach ([
            'platform_subscriptions',
            'platform_subscription_events',
            'business_stripe_connections',
            'business_document_payments',
            'business_payment_events',
            'business_usage_wallets',
            'payment_provider_events',
        ] as $table) {
            $this->assertSame(0, DB::table($table)->count(),
                "Lane C must never write {$table}.");
        }

        // And the platform's own catalog is untouched by an agency's pricing.
        $this->assertSame(0, DB::table('workspace_plan_catalog_pricing_changes')->count());
    }
}
