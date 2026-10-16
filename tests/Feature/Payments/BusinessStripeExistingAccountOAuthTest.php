<?php

namespace Tests\Feature\Payments;

use App\Enums\Business\BusinessStatus;
use App\Enums\Documents\StripeConnectionStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Payments\StripeConnectException;
use App\Http\Controllers\Customer\Business\BusinessPaymentsController;
use App\Jobs\BusinessPayments\ProcessBusinessPaymentEvent;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Payments\StripeConnectGateway;
use App\Library\Payments\StripeConnectManager;
use App\Models\Business;
use App\Models\BusinessPaymentEvent;
use App\Models\BusinessStripeConnection;
use App\Models\Customer;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Support\Payments\FakeStripeConnectGateway;
use Tests\TestCase;

/**
 * Business Stripe Connect — "Connect existing Stripe account" (Standard OAuth)
 * for money lane B. No network: the fake gateway stands in for Stripe.
 */
class BusinessStripeExistingAccountOAuthTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private const SESSION = 'business_stripe_oauth_pending.';

    private FakeStripeConnectGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeStripeConnectGateway();
        $this->gateway->baselineTransactionLevel = DB::transactionLevel();
        $this->gateway->detailsSubmitted = true;
        $this->gateway->chargesEnabled = true;
        $this->gateway->payoutsEnabled = true;
        $this->app->instance(StripeConnectGateway::class, $this->gateway);
        $this->allowEntitlement();
    }

    /** @return array{0: Customer, 1: Business, 2: Workspace} */
    private function tenantBusiness(string $name = 'Harbor Lane Studios'): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, $name, $name . ' WS');
        $business->status = BusinessStatus::Active;
        $business->country_code = 'US';
        $business->save();

        return [$customer, $business->fresh(), $workspace->fresh()];
    }

    private function manager(): StripeConnectManager
    {
        return app(StripeConnectManager::class);
    }

    private function url(string $action, Workspace $workspace, Business $business): string
    {
        return route('customer.workspaces.businesses.payments.connect.' . $action, [$workspace->uid, $business->uid]);
    }

    private function cbUrl(array $query): string
    {
        return route('customer.business.stripe.connect-existing.callback', $query);
    }

    private function allowEntitlement(): void
    {
        $this->app->bind(BusinessPaymentsController::class, fn ($app) => new class(
            $app->make(StripeConnectManager::class),
            $app->make(EntitlementManager::class),
        ) extends BusinessPaymentsController {
            protected function entitlementAllows(Workspace $workspace, Business $business): bool
            {
                return true;
            }
        });
    }

    private function actAsOwner(Business $business): Customer
    {
        $owner = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $this->authenticateAs($owner);

        return $owner;
    }

    private function addWorkspaceAdmin(Workspace $workspace, Customer $member): void
    {
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $member->user_id,
            'role' => 'admin',
            'business_access_scope' => WorkspaceBusinessAccessScope::All->value,
            'location_access_scope' => LocationAccessScope::All->value,
            'is_active' => true,
        ]);
    }

    /** Runs the start action and returns the issued `state`. */
    private function startOverHttp(Workspace $workspace, Business $business): string
    {
        $response = $this->post($this->url('existing', $workspace, $business));
        $response->assertRedirect();
        parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);

        return (string) $query['state'];
    }

    private function seedPending(string $state, Workspace $workspace, Business $business, int $userId, array $override = []): void
    {
        session()->put(self::SESSION . $state, $override + [
            'user_id' => $userId,
            'workspace_uid' => $workspace->uid,
            'business_uid' => $business->uid,
            'business_id' => (int) $business->id,
            'live' => false,
            'expires_at' => now()->addMinutes(5)->timestamp,
        ]);
    }

    // =================================================================
    // New vs existing
    // =================================================================

    public function test_existing_account_connects_with_stripe_truth_and_never_creates_an_account(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->gateway->requirementsDue = ['external_account'];

        $connection = $this->manager()->connectExisting($customer->user_id, $business, 'ac_code1', false);

        $this->assertSame('acct_existing000001', $connection->stripe_account_id);
        $this->assertSame('oauth', $connection->connection_method);
        $this->assertSame((int) $customer->user_id, (int) $connection->connected_by_user_id);
        $this->assertSame(StripeConnectionStatus::Active, $connection->status);
        $this->assertTrue((bool) $connection->charges_enabled);
        $this->assertTrue((bool) $connection->payouts_enabled);
        $this->assertSame(['external_account'], json_decode((string) $connection->getRawOriginal('requirements_due'), true));
        $this->assertSame(['exchangeOAuthCode', 'retrieveAccount'], $this->gateway->callNames());
        $this->assertTrue($this->manager()->isChargeReady($business->fresh()));
    }

    public function test_new_account_flow_is_unchanged_and_marked_created(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        $connection = BusinessStripeConnection::query()->sole();
        $this->assertSame('created', $connection->connection_method);
        $this->assertSame(['createAccount', 'createOnboardingLink'], $this->gateway->callNames());
    }

    public function test_existing_account_with_restricted_capabilities_is_not_charge_ready(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->gateway->chargesEnabled = false;
        $this->gateway->disabledReason = 'requirements.past_due';

        $connection = $this->manager()->connectExisting($customer->user_id, $business, 'ac_code1', false);

        $this->assertSame(StripeConnectionStatus::Restricted, $connection->status);
        $this->assertFalse($this->manager()->isChargeReady($business->fresh()));
    }

    // =================================================================
    // HTTP flow, fixed callback, state validation
    // =================================================================

    public function test_start_then_callback_connects_over_http_with_a_fixed_tenant_free_callback(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);

        $state = $this->startOverHttp($workspace, $business);
        $call = $this->gateway->callsOf('oauthAuthorizeUrl')[0]['args'];
        $this->assertSame(route('customer.business.stripe.connect-existing.callback'), $call['redirect_uri']);
        $this->assertStringNotContainsString($workspace->uid, $call['redirect_uri']);
        $this->assertStringNotContainsString($business->uid, $call['redirect_uri']);
        $this->assertGreaterThanOrEqual(40, strlen($state));

        $this->get($this->cbUrl(['state' => $state, 'code' => 'ac_ok', 'scope' => 'read_write']))
            ->assertRedirect($this->url('show', $workspace, $business))
            ->assertSessionHas('status', 'success');

        $this->assertSame('acct_existing000001', BusinessStripeConnection::query()->sole()->stripe_account_id);
    }

    public function test_replayed_callback_is_rejected_and_creates_nothing_more(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);
        $state = $this->startOverHttp($workspace, $business);

        $this->get($this->cbUrl(['state' => $state, 'code' => 'ac_ok']))->assertSessionHas('status', 'success');
        $this->get($this->cbUrl(['state' => $state, 'code' => 'ac_ok']))
            ->assertRedirect(route('user.home'))
            ->assertSessionHas('status', 'error');

        $this->assertSame(1, BusinessStripeConnection::query()->count());
        $this->assertCount(1, $this->gateway->callsOf('exchangeOAuthCode'));
    }

    public function test_missing_unknown_tampered_and_expired_state_never_reach_stripe(): void
    {
        [$customer, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);
        $state = $this->startOverHttp($workspace, $business);

        foreach ([
            ['code' => 'ac_x'],
            ['state' => 'forged', 'code' => 'ac_x'],
            ['state' => $state . 'x', 'code' => 'ac_x'],
        ] as $query) {
            $this->get($this->cbUrl($query))->assertRedirect(route('user.home'))->assertSessionHas('status', 'error');
        }

        // Expired: a valid record whose time has passed.
        $this->seedPending('expiredstate', $workspace, $business, $customer->user_id, ['expires_at' => now()->subMinute()->timestamp]);
        $this->get($this->cbUrl(['state' => 'expiredstate', 'code' => 'ac_x']))->assertRedirect(route('user.home'));
        $this->assertNull(session(self::SESSION . 'expiredstate'));

        $this->assertSame([], $this->gateway->callsOf('exchangeOAuthCode'));
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_a_state_started_by_another_user_cannot_be_completed(): void
    {
        [$owner, $business, $workspace] = $this->tenantBusiness();
        $other = $this->createCustomer();
        $this->addWorkspaceAdmin($workspace, $other);
        $this->authenticateAs($other);
        $this->seedPending('crossuser', $workspace, $business, $owner->user_id);

        $this->get($this->cbUrl(['state' => 'crossuser', 'code' => 'ac_x']))->assertRedirect(route('user.home'));

        $this->assertSame([], $this->gateway->callsOf('exchangeOAuthCode'));
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_a_pending_state_cannot_be_redirected_to_another_business(): void
    {
        [$owner, $business, $workspace] = $this->tenantBusiness();
        [, $foreign, $foreignWs] = $this->tenantBusiness('Other Studio');
        $this->actAsOwner($business);
        // Mismatched ids: the record names one Business by uid and another by id.
        $this->seedPending('swap', $workspace, $business, $owner->user_id, ['business_id' => (int) $foreign->id]);

        $this->get($this->cbUrl(['state' => 'swap', 'code' => 'ac_x']))->assertNotFound();

        $this->assertSame([], $this->gateway->callsOf('exchangeOAuthCode'));
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_user_denial_on_the_stripe_page_is_calm_and_makes_no_call(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);
        $state = $this->startOverHttp($workspace, $business);

        $this->get($this->cbUrl(['state' => $state, 'error' => 'access_denied']))
            ->assertRedirect($this->url('show', $workspace, $business))
            ->assertSessionHas('status', 'error');

        $this->assertSame([], $this->gateway->callsOf('exchangeOAuthCode'));
    }

    // =================================================================
    // Authorization
    // =================================================================

    public function test_staff_cannot_start_or_complete_existing_account_connection(): void
    {
        [$owner, $business, $workspace] = $this->tenantBusiness();
        $staff = $this->createCustomer();
        $this->addWorkspaceAdmin($workspace, $staff);
        $this->authenticateAs($staff);

        $this->post($this->url('existing', $workspace, $business))->assertNotFound();
        $this->assertSame([], $this->gateway->callsOf('oauthAuthorizeUrl'));

        // Even holding a state of their own, a non-owner is refused before Stripe.
        $this->seedPending('staffstate', $workspace, $business, $staff->user_id);
        $this->get($this->cbUrl(['state' => 'staffstate', 'code' => 'ac_x']))->assertNotFound();
        $this->assertSame([], $this->gateway->callsOf('exchangeOAuthCode'));
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_a_foreign_owner_cannot_start_for_this_business(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        [, $foreignBusiness] = $this->tenantBusiness('Other Studio');
        $this->actAsOwner($foreignBusiness);

        $this->post($this->url('existing', $workspace, $business))->assertNotFound();
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_view_as_agency_actor_is_refused(): void
    {
        $pair = $this->createAgencyManagedClient(clientBusinessName: 'Harbor Lane Studios', agencyBusinessName: 'Primary', agencyWorkspaceName: 'Northwind Agency');
        $this->assignTier($pair['clientWorkspace'], WorkspacePlanTier::Growth);
        $business = $pair['clientBusiness'];
        $business->status = BusinessStatus::Active;
        $business->save();

        $this->authenticateAs($pair['agencyOwner']);
        $this->post(route('customer.workspaces.clients.view-as', [$pair['agencyWorkspace']->uid, $pair['clientWorkspace']->uid]))
            ->assertRedirect(route('user.home'));

        $this->post(route('customer.workspaces.businesses.payments.connect.existing', [$pair['clientWorkspace']->uid, $business->uid]))
            ->assertNotFound();
        $this->assertSame([], $this->gateway->callsOf('oauthAuthorizeUrl'));
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    // =================================================================
    // Stripe-side validation
    // =================================================================

    public function test_scope_mode_and_identity_are_validated_and_unused_grants_revoked(): void
    {
        [$customer, $business] = $this->tenantBusiness();

        $this->gateway->oauthScope = 'read_only';
        $this->expectReason(StripeConnectException::ACCOUNT_NOT_ELIGIBLE, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false));
        $this->assertCount(1, $this->gateway->callsOf('deauthorizeAccount'));

        $this->gateway->oauthScope = 'read_write';
        $this->gateway->oauthLivemode = true; // live account on a test platform
        $this->expectReason(StripeConnectException::MODE_MISMATCH, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_2', false));

        $this->gateway->oauthLivemode = false;
        $this->gateway->platformLive = true; // test account, live platform and test attempt
        $this->expectReason(StripeConnectException::MODE_MISMATCH, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_3', false));

        $this->gateway->platformLive = false;
        $this->gateway->accountType = 'express'; // not a Standard account
        $this->expectReason(StripeConnectException::ACCOUNT_NOT_ELIGIBLE, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_4', false));

        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_attempt_started_in_one_mode_cannot_complete_in_another(): void
    {
        [$customer, $business] = $this->tenantBusiness();

        // The attempt recorded live=true, but the platform and account are test.
        $this->expectReason(StripeConnectException::MODE_MISMATCH, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', true));
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_provider_rejection_shows_the_eligibility_message_and_creates_nothing(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);
        $state = $this->startOverHttp($workspace, $business);
        $this->gateway->failOAuthWith = StripeConnectException::providerFailed();

        $response = $this->get($this->cbUrl(['state' => $state, 'code' => 'ac_bad']));
        $response->assertRedirect($this->url('show', $workspace, $business))->assertSessionHas('status', 'error');
        $this->assertStringContainsString('Not every existing Stripe account is eligible', (string) session('message'));
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_unconfigured_client_id_refuses_to_start(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);
        $this->gateway->failAuthorizeWith = StripeConnectException::notConfigured();

        $this->post($this->url('existing', $workspace, $business))
            ->assertRedirect($this->url('show', $workspace, $business))
            ->assertSessionHas('status', 'error');
    }

    // =================================================================
    // One live connection, conflicts, races
    // =================================================================

    public function test_a_second_live_connection_for_the_business_is_refused(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);

        $this->expectReason(StripeConnectException::ALREADY_CONNECTED, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_2', false));
        $this->assertSame(1, BusinessStripeConnection::query()->count());
    }

    public function test_an_account_live_on_another_business_is_refused_without_revoking_its_grant(): void
    {
        [$customerA, $a] = $this->tenantBusiness('Studio A');
        [$customerB, $b] = $this->tenantBusiness('Studio B');
        $this->manager()->connectExisting($customerA->user_id, $a, 'ac_1', false);

        $this->expectReason(StripeConnectException::ACCOUNT_IN_USE, fn () => $this->manager()->connectExisting($customerB->user_id, $b, 'ac_2', false));

        $this->assertSame(1, BusinessStripeConnection::query()->count());
        $this->assertSame([], $this->gateway->callsOf('deauthorizeAccount'), "A's working grant must not be revoked.");
    }

    public function test_an_account_live_on_an_agency_lane_is_refused(): void
    {
        [$customer, $business, $workspace] = $this->tenantBusiness();
        DB::table('agency_stripe_connections')->insert([
            'uid' => (string) Str::uuid(), 'agency_workspace_id' => $workspace->id,
            'stripe_account_id' => 'acct_existing000001', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('stripe_account_claims')->insert([
            'stripe_account_id' => 'acct_existing000001', 'lane' => 'agency',
            'connection_id' => DB::table('agency_stripe_connections')->value('id'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectReason(StripeConnectException::ACCOUNT_IN_USE, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false));
        $this->assertSame(0, BusinessStripeConnection::query()->count());
        $this->assertSame([], $this->gateway->callsOf('deauthorizeAccount'));
    }

    public function test_the_database_refuses_two_live_rows_for_one_account_even_if_the_app_check_is_bypassed(): void
    {
        [, $a] = $this->tenantBusiness('Studio A');
        [, $b] = $this->tenantBusiness('Studio B');
        $row = fn (Business $x, string $status) => [
            'uid' => (string) Str::uuid(), 'business_id' => $x->id, 'stripe_account_id' => 'acct_dup',
            'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ];
        DB::table('business_stripe_connections')->insert($row($a, 'active'));

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('business_stripe_connections')->insert($row($b, 'onboarding'));
    }

    public function test_the_same_account_can_be_relinked_after_disconnect(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);
        $this->manager()->disconnect($customer->user_id, $business);
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_2', false);

        $this->assertSame(2, BusinessStripeConnection::query()->count());
        $this->assertSame(1, BusinessStripeConnection::query()->where('status', '!=', 'disconnected')->count());
    }

    // =================================================================
    // Disconnect / revocation
    // =================================================================

    public function test_disconnecting_an_oauth_account_revokes_our_grant_only(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);

        $result = $this->manager()->disconnect($customer->user_id, $business);

        $this->assertSame(StripeConnectionStatus::Disconnected, $result->status);
        $this->assertFalse((bool) $result->charges_enabled);
        $this->assertSame(['acct_existing000001'], array_column(array_column($this->gateway->callsOf('deauthorizeAccount'), 'args'), 'account'));
        $this->assertSame('revoked', $result->getAttribute('oauth_revocation'));
    }

    public function test_revocation_failure_keeps_the_local_disconnect_and_tells_the_owner(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        $owner = $this->actAsOwner($business);
        $this->manager()->connectExisting($owner->user_id, $business, 'ac_1', false);
        $this->gateway->failDeauthorizeWith = StripeConnectException::providerFailed();

        $this->post($this->url('disconnect', $workspace, $business))->assertSessionHas('status', 'success');

        $this->assertStringContainsString('Connected platforms', (string) session('message'));
        $this->assertSame(StripeConnectionStatus::Disconnected, BusinessStripeConnection::query()->sole()->status);
    }

    public function test_disconnecting_a_platform_created_account_makes_no_revocation_call(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $this->manager()->disconnect($customer->user_id, $business);

        $this->assertSame([], $this->gateway->callsOf('deauthorizeAccount'));
    }

    public function test_provider_revocation_event_disconnects_only_that_business_and_is_idempotent(): void
    {
        [$ownerA, $a] = $this->tenantBusiness('Studio A');
        [$ownerB, $b] = $this->tenantBusiness('Studio B');
        $this->manager()->connectExisting($ownerA->user_id, $a, 'ac_1', false);
        $this->gateway->oauthAccountId = 'acct_existing000002';
        $this->manager()->connectExisting($ownerB->user_id, $b, 'ac_2', false);

        $this->deliver('evt_deauth_1', 'acct_existing000001', 'account.application.deauthorized');
        $this->deliver('evt_deauth_1', 'acct_existing000001', 'account.application.deauthorized'); // duplicate delivery
        $this->deliver('evt_deauth_2', 'acct_existing000001', 'account.application.deauthorized'); // already terminal

        $rowA = BusinessStripeConnection::query()->where('business_id', $a->id)->sole();
        $this->assertSame(StripeConnectionStatus::Disconnected, $rowA->status);
        $this->assertSame('provider_revoked_access', $rowA->requirements_disabled_reason);
        $this->assertFalse($this->manager()->isChargeReady($a->fresh()));
        $this->assertSame(StripeConnectionStatus::Active, BusinessStripeConnection::query()->where('business_id', $b->id)->sole()->status);
        $this->assertSame([], $this->gateway->callsOf('deauthorizeAccount'), 'Nothing to call: Stripe already revoked us.');
    }

    public function test_revocation_event_leaves_an_agency_connection_for_the_same_id_alone(): void
    {
        [, , $workspace] = $this->tenantBusiness();
        DB::table('agency_stripe_connections')->insert([
            'uid' => (string) Str::uuid(), 'agency_workspace_id' => $workspace->id,
            'stripe_account_id' => 'acct_agencyonly', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->deliver('evt_x', 'acct_agencyonly', 'account.application.deauthorized');

        $this->assertSame('active', DB::table('agency_stripe_connections')->value('status'));
    }

    public function test_forged_revocation_event_is_refused_before_anything_is_stored(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);

        $body = json_encode(['id' => 'evt_forged', 'type' => 'account.application.deauthorized', 'account' => 'acct_existing000001']);
        $this->call('POST', '/stripe/webhook/business-payments', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => 'v1=forged', 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(400);

        $this->assertSame(0, BusinessPaymentEvent::query()->count());
        $this->assertSame(StripeConnectionStatus::Active, BusinessStripeConnection::query()->sole()->status);
    }

    public function test_a_missing_or_unrecognised_account_type_fails_closed(): void
    {
        [$customer, $business] = $this->tenantBusiness();

        foreach ([null, 'express', 'custom', ''] as $i => $type) {
            $this->gateway->accountType = $type;
            $this->expectReason(StripeConnectException::ACCOUNT_NOT_ELIGIBLE, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_t' . $i, false));
        }

        $this->assertSame(0, BusinessStripeConnection::query()->count());
        $this->assertSame(0, DB::table('stripe_account_claims')->count());
    }

    public function test_a_delayed_revocation_event_from_an_older_connection_does_not_disconnect_the_new_one(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);
        $oldEventTime = now()->subMinutes(10)->timestamp;
        $this->manager()->disconnect($customer->user_id, $business);
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_2', false); // same account, new authorisation

        $this->deliver('evt_old_1', 'acct_existing000001', 'account.application.deauthorized', $oldEventTime);
        $this->deliver('evt_old_2', 'acct_existing000001', 'account.application.deauthorized', null, omitCreated: true);

        $live = BusinessStripeConnection::query()->where('status', '!=', 'disconnected')->sole();
        $this->assertSame(StripeConnectionStatus::Active, $live->status);
        $this->assertTrue($this->manager()->isChargeReady($business->fresh()));
        $this->assertSame(1, DB::table('stripe_account_claims')->count());

        // A genuine, later revocation still works.
        $this->deliver('evt_new', 'acct_existing000001', 'account.application.deauthorized', now()->addMinute()->timestamp);
        $this->assertSame(0, BusinessStripeConnection::query()->where('status', '!=', 'disconnected')->count());
        $this->assertSame(0, DB::table('stripe_account_claims')->count());
    }

    public function test_the_claim_is_held_across_revocation_and_released_only_when_the_connection_is_terminal(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        [$otherOwner, $other] = $this->tenantBusiness('Other Studio');
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);

        $seen = [];
        $this->gateway->duringDeauthorize = function () use (&$seen, $otherOwner, $other) {
            $seen['claim_held'] = DB::table('stripe_account_claims')->where('stripe_account_id', 'acct_existing000001')->exists();
            $seen['still_live'] = BusinessStripeConnection::query()->where('status', 'active')->exists();
            try {
                $this->manager()->connectExisting($otherOwner->user_id, $other, 'ac_race', false);
                $seen['competitor'] = 'won';
            } catch (StripeConnectException $e) {
                $seen['competitor'] = $e->reason;
            }
        };

        $this->manager()->disconnect($customer->user_id, $business);

        $this->assertTrue($seen['claim_held'], 'The claim must still be held while the grant is being revoked.');
        $this->assertTrue($seen['still_live']);
        $this->assertSame(StripeConnectException::ACCOUNT_IN_USE, $seen['competitor'], 'A competitor cannot claim mid-revocation.');
        $this->assertSame(0, DB::table('stripe_account_claims')->count(), 'Released once terminal.');
    }

    public function test_an_unused_rejected_grant_is_revoked_under_the_revocation_lock_and_skipped_when_claimed(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        [$otherOwner, $other] = $this->tenantBusiness('Other Studio');

        // Unclaimed + rejected -> revoked, lock released afterwards.
        $this->gateway->oauthScope = 'read_only';
        $this->expectReason(StripeConnectException::ACCOUNT_NOT_ELIGIBLE, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false));
        $this->assertCount(1, $this->gateway->callsOf('deauthorizeAccount'));
        $this->assertSame(0, DB::table('stripe_account_claims')->count());

        // Claimed by a winner -> a loser's rejected grant must NOT revoke it.
        $this->gateway->oauthScope = 'read_write';
        $this->manager()->connectExisting($otherOwner->user_id, $other, 'ac_2', false);
        $this->gateway->oauthScope = 'read_only';
        $this->expectReason(StripeConnectException::ACCOUNT_NOT_ELIGIBLE, fn () => $this->manager()->connectExisting($customer->user_id, $business, 'ac_3', false));
        $this->assertCount(1, $this->gateway->callsOf('deauthorizeAccount'), 'No second revocation against a claimed account.');
    }

    public function test_a_claim_left_by_a_crash_is_evicted_but_a_live_one_never_is(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        [$otherOwner, $other] = $this->tenantBusiness('Other Studio');

        // Stale: claim pointing at a connection that no longer exists.
        DB::table('stripe_account_claims')->insert([
            'stripe_account_id' => 'acct_existing000001', 'lane' => 'business', 'connection_id' => 999999,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);
        $this->assertSame(1, DB::table('stripe_account_claims')->count());

        // Live: refused.
        $this->expectReason(StripeConnectException::ACCOUNT_IN_USE, fn () => $this->manager()->connectExisting($otherOwner->user_id, $other, 'ac_2', false));
    }

    // =================================================================
    // Status page + payment integrity
    // =================================================================

    public function test_status_page_offers_both_choices_then_shows_unified_status(): void
    {
        [$customer, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);

        $html = $this->get($this->url('show', $workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Connect existing Stripe account', $html);
        $this->assertStringContainsString('Set up a new Stripe account', $html);

        $this->gateway->chargesEnabled = false;
        $this->gateway->payoutsEnabled = false;
        $this->gateway->requirementsDue = ['business_profile.url'];
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);

        $html = $this->get($this->url('show', $workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('Existing Stripe account', $html);
        $this->assertStringContainsString('data-capability="charges" data-enabled="0"', $html);
        $this->assertStringContainsString('data-capability="payouts" data-enabled="0"', $html);
        $this->assertStringContainsString('business profile.url', $html);
        $this->assertStringContainsString('data-role="refresh-form"', $html);
        $this->assertStringContainsString('data-role="disconnect-form"', $html);
        $this->assertStringNotContainsString('data-role="connect-existing-form"', $html);
    }

    public function test_payment_readiness_follows_stripe_not_the_oauth_redirect(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->gateway->chargesEnabled = false;
        $this->manager()->connectExisting($customer->user_id, $business, 'ac_1', false);
        $this->assertFalse($this->manager()->isChargeReady($business->fresh()));

        $this->gateway->chargesEnabled = true;
        $this->manager()->syncFromProvider($customer->user_id, $business);
        $this->assertTrue($this->manager()->isChargeReady($business->fresh()));
    }

    public function test_no_oauth_token_or_secret_is_ever_stored_and_no_fee_is_added(): void
    {
        $columns = DB::getSchemaBuilder()->getColumnListing('business_stripe_connections');
        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/token|secret|api_key/i', $column);
        }

        $grant = (new \ReflectionClass(\App\Library\Payments\OAuthGrant::class))->getProperties();
        $this->assertSame(['stripeAccountId', 'scope', 'livemode'], array_map(fn ($p) => $p->getName(), $grant));

        $gateway = file_get_contents(app_path('Library/Payments/StripeApiConnectGateway.php'));
        $this->assertStringNotContainsString('application_fee', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $gateway));
        $this->assertStringNotContainsString('access_token', preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $gateway));
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function expectReason(string $reason, callable $attempt): void
    {
        try {
            $attempt();
            $this->fail("Expected [{$reason}].");
        } catch (StripeConnectException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /** Delivers a signed event through the real intake + job. */
    private function deliver(string $eventId, string $account, string $type, ?int $created = null, bool $omitCreated = false): void
    {
        $payload = ['id' => $eventId, 'type' => $type, 'account' => $account, 'data' => ['object' => []]];
        if (! $omitCreated) {
            $payload['created'] = $created ?? now()->addSecond()->timestamp;
        }
        $body = json_encode($payload);
        $this->call('POST', '/stripe/webhook/business-payments', [], [], [], [
            'HTTP_STRIPE_SIGNATURE' => $this->gateway->validSignature, 'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();

        foreach (BusinessPaymentEvent::query()->where('provider_event_id', $eventId)->get() as $event) {
            app()->call([new ProcessBusinessPaymentEvent((int) $event->id), 'handle']);
        }
    }
}
