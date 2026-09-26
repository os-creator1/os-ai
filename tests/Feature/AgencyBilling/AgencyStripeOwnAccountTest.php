<?php

namespace Tests\Feature\AgencyBilling;

use App\Enums\AgencyBilling\AgencyStripeConnectionStatus;
use App\Enums\AgencyBilling\AgencySubscriptionEventState;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Jobs\AgencyBilling\ProcessAgencyClientSubscriptionEvent;
use App\Models\AgencyClientSubscriptionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\AgencyBilling\Concerns\CreatesAgencySaasFixtures;
use Tests\TestCase;

/**
 * "Jazmin Media operates the software platform, not the merchant." Every
 * Agency must receive its own client payments directly, pay its own Stripe
 * fees, and bear its own payment-loss liability — never the platform. This
 * suite proves the two ways an Agency gets a connected account both enforce
 * that fail-closed, that a fixed, workspace-agnostic OAuth callback resolves
 * the Agency only from verified server-side state (never a request
 * parameter), that a provider-side revocation and a stale local status both
 * fail closed at the actual financial boundary, and that the previously-
 * working connect/onboard/sync/subscribe flow
 * (StripeApiAgencyGateway::accountCreateParams(), unchanged) still reaches
 * Active exactly as before.
 */
class AgencyStripeOwnAccountTest extends TestCase
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

    private function startUrl(string $agencyUid): string
    {
        return route('customer.workspaces.agency.saas.stripe.connect-existing', [$agencyUid]);
    }

    /** Task 1 — ONE fixed, workspace-agnostic callback URL, shared by every Agency. */
    private function callbackUrl(array $query = []): string
    {
        return route('customer.agency.stripe.connect-existing.callback')
            . (empty($query) ? '' : ('?' . http_build_query($query)));
    }

    /** Starts the flow and returns the `state` Stripe's own authorize URL was given. */
    private function startAndCaptureState(string $agencyUid): string
    {
        $response = $this->get($this->startUrl($agencyUid));
        $response->assertRedirect();

        $location = (string) $response->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $this->assertArrayHasKey('state', $query, 'oauthAuthorizeUrl() must carry a state parameter.');

        return (string) $query['state'];
    }

    /** @return array{agencyWorkspace: \App\Models\Workspace, agencyOwner: \App\Models\Customer} */
    private function readyAgencyForRegression(): array
    {
        $fixture = $this->agencyWithClient();
        $this->connectAgencyStripe($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id);

        return $fixture;
    }

    // ------------------------------------------------------------------
    // Task 1 — fixed callback, server-side state, connecting a compatible
    // existing account
    // ------------------------------------------------------------------

    public function test_the_owner_can_connect_a_compatible_existing_account_through_the_fixed_callback(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $state = $this->startAndCaptureState($uid);
        $this->agencyStripe->registerExistingAccount('ac_test_code_1', 'acct_existing_compatible');

        $this->get($this->callbackUrl(['state' => $state, 'code' => 'ac_test_code_1']))
            ->assertRedirect(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertSessionHas('status', 'success');

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->assertNotNull($connection);
        $this->assertSame('acct_existing_compatible', (string) $connection->stripe_account_id);
        $this->assertSame(AgencyStripeConnectionStatus::Active, $connection->status);
        $this->assertTrue($this->connections()->isChargeReady($fixture['agencyWorkspace']));

        $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertOk()
            ->assertSee('Ready to take payments');
    }

    public function test_the_same_state_cannot_be_replayed(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $state = $this->startAndCaptureState($uid);
        $this->agencyStripe->registerExistingAccount('ac_test_code_replay', 'acct_replay');

        $this->get($this->callbackUrl(['state' => $state, 'code' => 'ac_test_code_replay']))
            ->assertSessionHas('status', 'success');

        // A second delivery of the identical callback URL — the state was
        // already consumed and forgotten, so this must find nothing, never
        // attempt a second exchange or a second connection.
        $this->agencyStripe->calls = [];
        $this->get($this->callbackUrl(['state' => $state, 'code' => 'ac_test_code_replay']))
            ->assertRedirect(route('user.home'))
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $this->agencyStripe->callsOf('exchangeOAuthCode'));
        $this->assertCount(1, $this->connections()->history($fixture['agencyWorkspace']));
    }

    public function test_an_expired_state_is_refused(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $state = $this->startAndCaptureState($uid);
        session()->put('agency_stripe_oauth_pending.' . $state, [
            'agency_workspace_id' => (int) $fixture['agencyWorkspace']->id,
            'user_id' => (int) $fixture['agencyOwner']->user_id,
            'expires_at' => now()->subMinute()->timestamp,
        ]);
        $this->agencyStripe->registerExistingAccount('ac_test_code_expired', 'acct_expired');

        $this->get($this->callbackUrl(['state' => $state, 'code' => 'ac_test_code_expired']))
            ->assertRedirect(route('user.home'))
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $this->agencyStripe->callsOf('exchangeOAuthCode'));
        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    public function test_a_mismatched_state_is_refused_and_creates_no_connection(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $this->startAndCaptureState($uid);
        $this->agencyStripe->registerExistingAccount('ac_test_code_3', 'acct_forged');

        $this->get($this->callbackUrl(['state' => 'not-the-real-state', 'code' => 'ac_test_code_3']))
            ->assertRedirect(route('user.home'))
            ->assertSessionHas('status', 'error');

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
        $this->assertSame([], $this->agencyStripe->callsOf('exchangeOAuthCode'), 'A code must never be exchanged against an unverified state.');
    }

    public function test_a_state_issued_to_a_different_authenticated_user_is_refused(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $state = $this->startAndCaptureState($uid);
        $this->agencyStripe->registerExistingAccount('ac_test_code_cross_user', 'acct_cross_user');

        // A different authenticated user than the one who started the
        // attempt somehow submits the same state (a stolen/leaked redirect,
        // or session confusion) — refused even though the state itself is
        // otherwise genuine and unexpired.
        $imposter = $this->createCustomer();
        $this->authenticateAs($imposter);

        $this->get($this->callbackUrl(['state' => $state, 'code' => 'ac_test_code_cross_user']))
            ->assertRedirect(route('user.home'))
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $this->agencyStripe->callsOf('exchangeOAuthCode'));
        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    public function test_stripe_reporting_the_owner_declined_is_handled_without_a_connection(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $state = $this->startAndCaptureState($uid);

        $this->get($this->callbackUrl(['state' => $state, 'error' => 'access_denied', 'error_description' => 'The user denied your request']))
            ->assertRedirect(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertSessionHas('status', 'error');

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    // ------------------------------------------------------------------
    // Fail-closed on an incompatible existing account
    // ------------------------------------------------------------------

    public static function incompatibleControllerShapes(): array
    {
        return [
            'platform would pay Stripe fees' => [['fees_payer' => 'application']],
            'platform would bear payment losses' => [['losses_payer' => 'application']],
            'platform would collect requirements' => [['requirement_collection' => 'application']],
            'agency has no full Dashboard' => [['dashboard_type' => 'express']],
            'no controller reported at all (legacy/unverifiable)' => [[
                'fees_payer' => null, 'losses_payer' => null,
                'requirement_collection' => null, 'dashboard_type' => null,
            ]],
        ];
    }

    /** @dataProvider incompatibleControllerShapes */
    public function test_an_incompatible_existing_account_never_becomes_chargeable(array $facts): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $state = $this->startAndCaptureState($uid);
        // charges_enabled true on purpose: even an account Stripe itself
        // would let charge must be refused here on its commercial shape
        // alone (objective #4/#5's own fail-closed requirement).
        $this->agencyStripe->registerExistingAccount('ac_test_code_2', 'acct_existing_incompatible', $facts);

        $this->get($this->callbackUrl(['state' => $state, 'code' => 'ac_test_code_2']))
            ->assertRedirect(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertSessionHas('status', 'error');

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->assertNotNull($connection, 'Still recorded — an accurate, disconnectable history, never a silent no-op.');
        $this->assertSame(AgencyStripeConnectionStatus::Incompatible, $connection->status);
        $this->assertFalse($connection->canCharge());
        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']));
        $this->assertFalse((bool) $connection->charges_enabled, 'charges_enabled is forced false locally regardless of what the provider reported.');

        $page = $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]));
        $page->assertOk();
        $page->assertSee('Not compatible with this platform');
        // Never offered "Finish setup" — the dashboard type cannot change.
        $page->assertDontSee('Finish Stripe setup');
    }

    public function test_syncing_an_account_whose_controller_shape_changed_also_fails_closed(): void
    {
        // Preserves the existing, working create->onboard->sync flow
        // (accountCreateParams() is untouched), then proves the SAME
        // statusFor() gate a resync goes through would also catch a
        // provider-reported shape it does not recognise, not only the
        // OAuth path.
        $fixture = $this->readyAgencyForRegression();

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->assertSame(AgencyStripeConnectionStatus::Active, $connection->status);

        $this->agencyStripe->accounts[(string) $connection->stripe_account_id]['dashboard_type'] = 'express';

        $this->connections()->syncFromProvider((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        $resynced = $connection->fresh();
        $this->assertSame(AgencyStripeConnectionStatus::Incompatible, $resynced->status);
        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']));
    }

    // ------------------------------------------------------------------
    // Task 3 — fail-closed freshness at the actual financial boundary
    // ------------------------------------------------------------------

    public function test_a_stale_connection_is_reverified_before_a_new_financial_operation_and_refused_if_now_bad(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $agencyWorkspace = $fixture['agencyWorkspace'];
        $connection = $this->connections()->liveConnection($agencyWorkspace);

        // The provider's own state changed AFTER our last sync — Stripe
        // restricted the account — but nothing has locally re-synced yet.
        $this->agencyStripe->restrictAccount((string) $connection->stripe_account_id);
        \Illuminate\Support\Facades\DB::table('agency_stripe_connections')
            ->where('id', $connection->id)
            ->update(['last_synced_at' => now()->subMinutes(20)]);

        $this->expectException(\App\Exceptions\AgencyBilling\AgencyBillingException::class);

        try {
            $this->connections()->chargeableConnection($agencyWorkspace);
        } finally {
            $this->assertNotEmpty($this->agencyStripe->callsOf('retrieveAccount'), 'A stale connection must be re-verified against the provider before a new financial operation.');
        }
    }

    public function test_a_fresh_connection_is_not_reverified_on_every_call(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $agencyWorkspace = $fixture['agencyWorkspace'];

        $this->agencyStripe->calls = [];
        $this->connections()->chargeableConnection($agencyWorkspace);

        $this->assertSame([], $this->agencyStripe->callsOf('retrieveAccount'), 'A recently-synced connection must not trigger a redundant provider call.');
    }

    public function test_a_compatible_account_still_reaches_checkout_after_the_freshness_reverification(): void
    {
        // Preserves the working subscription flow end to end, through the
        // Task 3 freshness path specifically (last_synced_at forced stale).
        $fixture = $this->readyAgencyForRegression();
        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        \Illuminate\Support\Facades\DB::table('agency_stripe_connections')
            ->where('id', $connection->id)
            ->update(['last_synced_at' => now()->subMinutes(20)]);

        $this->ensureCanonicalTierPriced(\App\Enums\Entitlement\WorkspacePlanTier::Growth);
        $plan = $this->publishedPlan($fixture['agencyWorkspace'], (int) $fixture['agencyOwner']->user_id, price: '349.00');

        $this->authenticateAs($fixture['agencyOwner']);
        $this->post(route('customer.workspaces.agency.saas.clients.offer', [$fixture['agencyWorkspace']->uid, $fixture['clientWorkspace']->uid]), [
            'plan_uid' => $plan->uid, 'confirm' => '1',
        ])->assertRedirect();

        $this->assertNotEmpty($this->agencyStripe->callsOf('retrieveAccount'));
        $this->assertSame(\App\Enums\AgencyBilling\AgencyClientSubscriptionStatus::Offered, $this->statusOf($fixture['clientWorkspace']));
    }

    // ------------------------------------------------------------------
    // Task 2 — account.application.deauthorized
    // ------------------------------------------------------------------

    public function test_a_provider_side_revocation_disconnects_the_connection_and_refuses_new_charges(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $accountId = (string) $connection->stripe_account_id;

        $this->deliverAndProcessWebhookEvent('account.application.deauthorized', $accountId, [
            'id' => $accountId,
            'object' => 'application',
        ]);

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
        $this->assertFalse($this->connections()->isChargeReady($fixture['agencyWorkspace']));

        $refreshed = $connection->fresh();
        $this->assertSame(AgencyStripeConnectionStatus::Disconnected, $refreshed->status);
        $this->assertFalse((bool) $refreshed->charges_enabled);

        // Never interferes with the provider's own subscription lifecycle
        // or deletes history.
        $this->assertCount(1, $this->connections()->history($fixture['agencyWorkspace']));

        // The UI accurately shows "not connected" — the same honest state a
        // never-connected Agency sees.
        $this->authenticateAs($fixture['agencyOwner']);
        $this->get(route('customer.workspaces.agency.saas.stripe', [$fixture['agencyWorkspace']->uid]))
            ->assertOk()
            ->assertSee('Not connected');
    }

    public function test_revocation_for_an_unknown_account_is_a_safe_no_op(): void
    {
        $fixture = $this->readyAgencyForRegression();

        $this->deliverAndProcessWebhookEvent('account.application.deauthorized', 'acct_never_connected_here', [
            'id' => 'acct_never_connected_here',
            'object' => 'application',
        ]);

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->assertNotNull($connection, 'An event naming a different, unrelated account must not touch this Agency\'s own connection.');
        $this->assertSame(AgencyStripeConnectionStatus::Active, $connection->status);
    }

    /** Intake -> job, exactly as production delivery works, using the real signature-verified fake gateway. */
    private function deliverAndProcessWebhookEvent(string $eventType, string $connectedAccountId, array $object): void
    {
        $payload = json_encode([
            'id' => 'evt_' . uniqid(),
            'type' => $eventType,
            'account' => $connectedAccountId,
            'data' => ['object' => $object],
        ]);

        $this->call('POST', route('public.agency-subscriptions.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_Stripe-Signature' => $this->agencyStripe->validSignature,
        ], $payload)->assertStatus(200);

        $event = AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'like', 'evt_%')->latest('id')->first();
        $this->assertNotNull($event);

        ProcessAgencyClientSubscriptionEvent::dispatchSync((int) $event->id);

        $this->assertSame(AgencySubscriptionEventState::Processed, $event->fresh()->state);
    }

    // ------------------------------------------------------------------
    // P1-A — Stripe Connect account.updated, no manual refresh required
    // ------------------------------------------------------------------

    public function test_account_updated_webhook_moves_a_restricted_account_to_active(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $accountId = (string) $connection->stripe_account_id;

        // The exact real acceptance scenario: OAuth-connected, initially
        // restricted pending Stripe's own verification.
        $this->agencyStripe->restrictAccount($accountId, 'requirements.pending_verification');
        \Illuminate\Support\Facades\DB::table('agency_stripe_connections')->where('id', $connection->id)->update([
            'status' => AgencyStripeConnectionStatus::Restricted->value,
            'charges_enabled' => false,
            'requirements_disabled_reason' => 'requirements.pending_verification',
        ]);

        // The Agency finishes verification; Stripe now genuinely reports it ready.
        $this->agencyStripe->completeOnboarding($accountId);

        $this->deliverAndProcessWebhookEvent('account.updated', $accountId, [
            'id' => $accountId,
            'object' => 'account',
            // Deliberately WRONG: the event's own embedded snapshot must
            // never be trusted — only a real retrieveAccount() call may
            // decide this.
            'charges_enabled' => false,
        ]);

        $refreshed = $connection->fresh();
        $this->assertSame(AgencyStripeConnectionStatus::Active, $refreshed->status);
        $this->assertTrue($this->connections()->isChargeReady($fixture['agencyWorkspace']));
    }

    public function test_account_updated_for_a_different_account_does_not_affect_this_agency(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);

        // Fixture setup already performed its own real sync; only calls
        // made AFTER this point are relevant to the assertion below.
        $this->agencyStripe->calls = [];

        $this->deliverAndProcessWebhookEvent('account.updated', 'acct_unrelated_elsewhere', [
            'id' => 'acct_unrelated_elsewhere', 'object' => 'account',
        ]);

        $this->assertSame($connection->lock_version, $connection->fresh()->lock_version, 'An event for a different account must never write to this connection.');
        $this->assertSame([], $this->agencyStripe->callsOf('retrieveAccount'), 'An unknown account id must never trigger a provider call at all.');
    }

    public function test_account_updated_for_a_historical_disconnected_connection_does_not_resurrect_it(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $oldConnection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $oldAccountId = (string) $oldConnection->stripe_account_id;

        $this->connections()->disconnect((int) $fixture['agencyOwner']->user_id, $fixture['agencyWorkspace']);

        // A late account.updated for the NOW-DISCONNECTED account arrives.
        $this->deliverAndProcessWebhookEvent('account.updated', $oldAccountId, ['id' => $oldAccountId, 'object' => 'account']);

        $this->assertSame(AgencyStripeConnectionStatus::Disconnected, $oldConnection->fresh()->status);
        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    public function test_an_account_updated_event_arriving_after_deauthorization_cannot_undo_it(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $accountId = (string) $connection->stripe_account_id;

        $this->deliverAndProcessWebhookEvent('account.application.deauthorized', $accountId, ['id' => $accountId, 'object' => 'application']);
        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));

        // Out-of-order delivery: a stale account.updated for the same
        // account arrives only AFTER the deauthorization already committed.
        $this->deliverAndProcessWebhookEvent('account.updated', $accountId, ['id' => $accountId, 'object' => 'account']);

        $this->assertSame(AgencyStripeConnectionStatus::Disconnected, $connection->fresh()->status);
        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    public function test_provider_retrieval_failure_during_account_updated_never_marks_the_account_ready(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $accountId = (string) $connection->stripe_account_id;
        $before = $connection->status;

        // A ONE-TIME hook on retrieveAccount specifically (never
        // verifyWebhookPayload, which intake must still pass) — the queue
        // connection under test runs the job SYNCHRONOUSLY as part of
        // handling the POST below (mirrors
        // AgencySaasCorrectionsTest::test_revoked_provider_access_fails_closed_and_fabricates_nothing()'s
        // own established pattern for exactly this scenario), so the
        // exception surfaces through the HTTP call itself.
        $this->agencyStripe->interleaveOnce('retrieveAccount', function (): void {
            throw \App\Exceptions\AgencyBilling\AgencyBillingException::because(\App\Exceptions\AgencyBilling\AgencyBillingException::PROVIDER_FAILED);
        });

        $payload = json_encode(['id' => 'evt_provider_fail', 'type' => 'account.updated', 'account' => $accountId, 'data' => ['object' => ['id' => $accountId]]]);

        try {
            $this->call('POST', route('public.agency-subscriptions.webhook'), [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => $this->agencyStripe->validSignature,
            ], $payload);
        } catch (\Throwable) {
            // Expected: provider truth could not be retrieved. The job's
            // own bounded retry policy owns what happens next; what
            // matters here is the durable outcome, asserted below.
        }

        $event = AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_provider_fail')->first();
        $this->assertNotNull($event);
        $this->assertSame(AgencySubscriptionEventState::Failed, $event->state);
        $this->assertSame(\App\Exceptions\AgencyBilling\AgencyBillingException::PROVIDER_FAILED, $event->last_error);
        $this->assertSame($before, $connection->fresh()->status, 'A failed provider read must never change local status.');
    }

    public function test_an_incorrectly_signed_account_updated_event_is_rejected(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $accountId = (string) $this->connections()->liveConnection($fixture['agencyWorkspace'])->stripe_account_id;

        $payload = json_encode(['id' => 'evt_bad_sig', 'type' => 'account.updated', 'account' => $accountId, 'data' => ['object' => ['id' => $accountId]]]);

        $this->call('POST', route('public.agency-subscriptions.webhook'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => 'not-the-real-signature',
        ], $payload)->assertStatus(400);

        $this->assertSame(0, AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_bad_sig')->count());
    }

    public function test_a_duplicate_account_updated_delivery_is_idempotent(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $accountId = (string) $this->connections()->liveConnection($fixture['agencyWorkspace'])->stripe_account_id;

        $payload = json_encode(['id' => 'evt_dup_1', 'type' => 'account.updated', 'account' => $accountId, 'data' => ['object' => ['id' => $accountId]]]);
        $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_Stripe-Signature' => $this->agencyStripe->validSignature];

        $this->call('POST', route('public.agency-subscriptions.webhook'), [], [], [], $headers, $payload)->assertStatus(200);
        $this->call('POST', route('public.agency-subscriptions.webhook'), [], [], [], $headers, $payload)->assertStatus(200);

        $this->assertSame(1, AgencyClientSubscriptionEvent::query()->where('provider_event_id', 'evt_dup_1')->count());
    }

    // ------------------------------------------------------------------
    // P1-B/D — automatic refresh on return from onboarding, no manual button
    // ------------------------------------------------------------------

    public function test_returning_from_stripe_onboarding_automatically_syncs_status(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $this->post(route('customer.workspaces.agency.saas.stripe.connect', [$uid]), [
            'country' => 'US', 'email' => 'agency@example.test',
        ])->assertRedirect();

        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);
        $this->agencyStripe->completeOnboarding((string) $connection->stripe_account_id);

        // The browser lands back on the plain page WITH the return marker
        // Stripe's own redirect carries — never a button click.
        $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]) . '?stripe_return=1')
            ->assertRedirect(route('customer.workspaces.agency.saas.stripe', [$uid]));

        $this->assertSame(AgencyStripeConnectionStatus::Active, $connection->fresh()->status);

        $this->get(route('customer.workspaces.agency.saas.stripe', [$uid]))
            ->assertOk()
            ->assertSee('Ready to take payments');
    }

    public function test_an_ordinary_page_view_without_the_return_marker_makes_no_provider_call(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $this->authenticateAs($fixture['agencyOwner']);

        $this->agencyStripe->calls = [];
        $this->get(route('customer.workspaces.agency.saas.stripe', [$fixture['agencyWorkspace']->uid]))->assertOk();

        $this->assertSame([], $this->agencyStripe->callsOf('retrieveAccount'));
    }

    public function test_the_manual_refresh_button_no_longer_appears_on_the_page(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $this->authenticateAs($fixture['agencyOwner']);

        $this->get(route('customer.workspaces.agency.saas.stripe', [$fixture['agencyWorkspace']->uid]))
            ->assertOk()
            ->assertDontSee('Refresh status from Stripe')
            ->assertDontSee('data-role="agency-stripe-sync"', false);
    }

    // ------------------------------------------------------------------
    // P1-C — browser status polling, server-side throttled
    // ------------------------------------------------------------------

    public function test_status_poll_reflects_a_change_without_a_manual_refresh_and_is_server_side_throttled(): void
    {
        $fixture = $this->agencyWithClient();
        $this->authenticateAs($fixture['agencyOwner']);
        $uid = $fixture['agencyWorkspace']->uid;

        $this->post(route('customer.workspaces.agency.saas.stripe.connect', [$uid]), [
            'country' => 'US', 'email' => 'agency@example.test',
        ])->assertRedirect();
        $connection = $this->connections()->liveConnection($fixture['agencyWorkspace']);

        $first = $this->postJson(route('customer.workspaces.agency.saas.stripe.status-poll', [$uid]));
        $first->assertOk();
        $this->assertSame('onboarding', $first->json('status'));

        // Finishes at Stripe; the local row does not know yet.
        $this->agencyStripe->completeOnboarding((string) $connection->stripe_account_id);

        // Immediately polling again is throttled server-side: still stale.
        $this->agencyStripe->calls = [];
        $throttled = $this->postJson(route('customer.workspaces.agency.saas.stripe.status-poll', [$uid]));
        $this->assertSame('onboarding', $throttled->json('status'));
        $this->assertSame([], $this->agencyStripe->callsOf('retrieveAccount'), 'A poll inside the throttle window must never reach the provider.');

        // Past the throttle window, the very next poll performs a real
        // check and reflects the change automatically.
        \Illuminate\Support\Facades\DB::table('agency_stripe_connections')->where('id', $connection->id)->update(['last_synced_at' => now()->subMinute()]);
        $fresh = $this->postJson(route('customer.workspaces.agency.saas.stripe.status-poll', [$uid]));
        $this->assertSame('active', $fresh->json('status'));
        $this->assertNotEmpty($this->agencyStripe->callsOf('retrieveAccount'));
    }

    public function test_status_poll_is_owner_only_and_makes_no_provider_call_for_staff(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $staff = $this->createCustomer();
        $this->createMembership($fixture['agencyWorkspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($staff);

        \Illuminate\Support\Facades\DB::table('agency_stripe_connections')
            ->where('agency_workspace_id', $fixture['agencyWorkspace']->id)
            ->update(['last_synced_at' => now()->subMinute()]);
        $this->agencyStripe->calls = [];

        $response = $this->postJson(route('customer.workspaces.agency.saas.stripe.status-poll', [$fixture['agencyWorkspace']->uid]));
        $response->assertOk();
        $this->assertNull($response->json('status'));
        $this->assertSame([], $this->agencyStripe->callsOf('retrieveAccount'));
    }

    // ------------------------------------------------------------------
    // Authorization — owner only, same rule as every other lane-C write
    // ------------------------------------------------------------------

    public function test_agency_staff_cannot_start_connecting_an_existing_account(): void
    {
        $fixture = $this->agencyWithClient();
        $staff = $this->createCustomer();
        $this->createMembership($fixture['agencyWorkspace'], $staff->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
        ]);
        $this->authenticateAs($staff);

        $this->get($this->startUrl($fixture['agencyWorkspace']->uid))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertNull($this->connections()->liveConnection($fixture['agencyWorkspace']));
    }

    public function test_an_unrelated_actor_cannot_start_connecting_an_existing_account(): void
    {
        $fixture = $this->agencyWithClient();
        $stranger = $this->createCustomer();
        $this->authenticateAs($stranger);

        $this->get($this->startUrl($fixture['agencyWorkspace']->uid))->assertNotFound();
    }

    public function test_an_agency_that_already_has_a_connection_cannot_start_connecting_a_second_one(): void
    {
        $fixture = $this->readyAgencyForRegression();
        $this->authenticateAs($fixture['agencyOwner']);

        $this->get($this->startUrl($fixture['agencyWorkspace']->uid))
            ->assertRedirect()
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $this->agencyStripe->callsOf('oauthAuthorizeUrl'));
    }
}
