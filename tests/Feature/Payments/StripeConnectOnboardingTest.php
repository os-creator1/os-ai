<?php

namespace Tests\Feature\Payments;

use App\Enums\Business\BusinessStatus;
use App\Enums\Documents\StripeConnectionStatus;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Exceptions\Payments\StripeConnectException;
use App\Http\Controllers\Customer\Business\BusinessPaymentsController;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Payments\StripeConnectGateway;
use App\Library\Payments\StripeConnectManager;
use App\Models\Business;
use App\Models\BusinessStripeConnection;
use App\Models\Customer;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\Support\Payments\FakeStripeConnectGateway;
use Tests\TestCase;

/**
 * Implementation Contract 17 Sub-slice D (§5.7, §6.2, §11.2, §12.D) — Stripe
 * Connect onboarding for money lane B.
 */
class StripeConnectOnboardingTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;

    private FakeStripeConnectGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = new FakeStripeConnectGateway();
        // RefreshDatabase wraps each test in its own transaction; the fake
        // policing §7 must measure depth ABOVE that, not against zero.
        $this->gateway->baselineTransactionLevel = DB::transactionLevel();
        $this->app->instance(StripeConnectGateway::class, $this->gateway);
    }

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace}
     */
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

    /** Mirrors DocumentsControllerTest: replace ONLY the entitlement step. */
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

    private function actAsOwner(Business $business, ?array $permissions = null): Customer
    {
        $owner = Customer::query()->where('user_id', $business->customer_id)->firstOrFail();
        $this->authenticateAs($owner, $permissions);

        return $owner;
    }

    // =================================================================
    // Onboarding state transitions (§12.D)
    // =================================================================

    public function test_connect_creates_one_onboarding_connection_and_returns_a_stripe_hosted_url(): void
    {
        [$customer, $business] = $this->tenantBusiness();

        $url = $this->manager()->connect($customer->user_id, $business, 'https://app.test/refresh', 'https://app.test/return');

        $connection = BusinessStripeConnection::query()->sole();
        $this->assertSame((int) $business->id, (int) $connection->business_id);
        $this->assertStringStartsWith('acct_fake', $connection->stripe_account_id);
        $this->assertSame(StripeConnectionStatus::Onboarding, $connection->status);
        $this->assertNotNull($connection->connected_at);
        $this->assertNull($connection->disconnected_at);
        $this->assertSame(0, (int) $connection->lock_version);
        $this->assertStringContainsString($connection->stripe_account_id, $url);
        $this->assertSame(['createAccount', 'createOnboardingLink'], $this->gateway->callNames());
    }

    public function test_sync_moves_onboarding_to_active_then_restricted_as_provider_truth_changes(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        // Details submitted and charges live -> active.
        $this->gateway->detailsSubmitted = true;
        $this->gateway->chargesEnabled = true;
        $this->gateway->payoutsEnabled = true;
        $connection = $this->manager()->syncFromProvider($customer->user_id, $business);

        $this->assertSame(StripeConnectionStatus::Active, $connection->status);
        $this->assertTrue((bool) $connection->charges_enabled);
        $this->assertSame(1, (int) $connection->lock_version);
        $this->assertNotNull($connection->last_synced_at);
        $this->assertTrue($this->manager()->isChargeReady($business->fresh()));

        // Stripe later disables charges -> restricted, and not chargeable.
        $this->gateway->chargesEnabled = false;
        $this->gateway->disabledReason = 'requirements.past_due';
        $connection = $this->manager()->syncFromProvider($customer->user_id, $business);

        $this->assertSame(StripeConnectionStatus::Restricted, $connection->status);
        $this->assertSame('requirements.past_due', $connection->requirements_disabled_reason);
        $this->assertSame(2, (int) $connection->lock_version);
        $this->assertFalse($this->manager()->isChargeReady($business->fresh()));
    }

    public function test_an_account_that_has_not_submitted_details_stays_onboarding(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        $this->gateway->chargesEnabled = true; // but details not submitted
        $connection = $this->manager()->syncFromProvider($customer->user_id, $business);

        $this->assertSame(StripeConnectionStatus::Onboarding, $connection->status);
        $this->assertFalse($this->manager()->isChargeReady($business->fresh()));
    }

    public function test_resume_mints_a_fresh_single_use_onboarding_link_for_the_same_account(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $accountId = BusinessStripeConnection::query()->sole()->stripe_account_id;

        $url = $this->manager()->resumeOnboarding($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        $this->assertStringContainsString($accountId, $url);
        $this->assertSame(1, BusinessStripeConnection::query()->count(), 'Resuming must not create a second connection.');
    }

    public function test_a_concurrent_writer_during_the_provider_call_wins_the_optimistic_race(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $connection = BusinessStripeConnection::query()->sole();

        // Another process advances the row WHILE our provider call is in
        // flight — the only window in which our observed lock_version can go
        // stale, because the manager re-reads the row on entry.
        $this->gateway->duringRetrieve = function () use ($connection) {
            DB::table('business_stripe_connections')->where('id', $connection->id)->update([
                'lock_version' => 5,
                'status' => StripeConnectionStatus::Active->value,
                'charges_enabled' => true,
                'details_submitted' => true,
            ]);
        };
        $this->gateway->chargesEnabled = false;
        $this->gateway->detailsSubmitted = false;

        $this->manager()->syncFromProvider($customer->user_id, $business);

        $fresh = BusinessStripeConnection::query()->sole();
        $this->assertSame(5, (int) $fresh->lock_version, 'A losing CAS must not bump the version.');
        $this->assertSame(StripeConnectionStatus::Active, $fresh->status,
            'The concurrent winner stands; a stale observation must not overwrite it.');
        $this->assertTrue((bool) $fresh->charges_enabled);
    }

    public function test_an_uncontended_sync_applies_and_advances_the_version(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        $this->gateway->detailsSubmitted = true;
        $this->gateway->chargesEnabled = true;
        $this->manager()->syncFromProvider($customer->user_id, $business);

        $fresh = BusinessStripeConnection::query()->sole();
        $this->assertSame(1, (int) $fresh->lock_version);
        $this->assertSame(StripeConnectionStatus::Active, $fresh->status);
    }

    // =================================================================
    // §5.7 — one live connection, history preserved
    // =================================================================

    public function test_a_second_connect_while_one_is_live_is_refused(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        try {
            $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
            $this->fail('A Business may hold only one live connection.');
        } catch (StripeConnectException $e) {
            $this->assertSame(StripeConnectException::ALREADY_CONNECTED, $e->reason);
        }

        $this->assertSame(1, BusinessStripeConnection::query()->count());
        $this->assertSame(['createAccount', 'createOnboardingLink'], $this->gateway->callNames(),
            'The refusal must happen before any second provider account is created.');
    }

    public function test_the_database_itself_refuses_a_second_live_connection(): void
    {
        [, $business] = $this->tenantBusiness();

        DB::table('business_stripe_connections')->insert($this->rawConnection($business, 'acct_one', 'active'));

        $this->expectException(QueryException::class);

        try {
            DB::table('business_stripe_connections')->insert($this->rawConnection($business, 'acct_two', 'onboarding'));
        } catch (QueryException $e) {
            $this->assertStringContainsString('bsc_active_business_unique', $e->getMessage());

            throw $e;
        }
    }

    public function test_disconnect_frees_the_slot_and_reconnecting_creates_a_new_row(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $first = BusinessStripeConnection::query()->sole();
        $firstAccountId = $first->stripe_account_id;

        $this->manager()->disconnect($customer->user_id, $business);

        $first->refresh();
        $this->assertSame(StripeConnectionStatus::Disconnected, $first->status);
        $this->assertNotNull($first->disconnected_at);
        $this->assertNull(DB::table('business_stripe_connections')->where('id', $first->id)->value('active_business_id'),
            'A terminal row must release the generated live slot.');

        $this->manager()->connect($customer->user_id, $business->fresh(), 'https://app.test/r', 'https://app.test/t');

        $this->assertSame(2, BusinessStripeConnection::query()->count());
        $second = BusinessStripeConnection::query()->orderByDesc('id')->first();
        $this->assertNotSame($firstAccountId, $second->stripe_account_id);

        // The historical row is untouched: same id, same account, still terminal.
        $this->assertSame($firstAccountId, $first->refresh()->stripe_account_id,
            'stripe_account_id is never rewritten on an existing row.');
        $this->assertSame(StripeConnectionStatus::Disconnected, $first->status);
    }

    public function test_disconnect_makes_no_provider_call_at_all(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $before = $this->gateway->callNames();

        $this->manager()->disconnect($customer->user_id, $business);

        $this->assertSame($before, $this->gateway->callNames(),
            'The connected account belongs to the Business; disconnecting is a local act.');
    }

    public function test_a_sync_can_never_revive_a_disconnected_connection(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $this->manager()->disconnect($customer->user_id, $business);

        try {
            $this->manager()->syncFromProvider($customer->user_id, $business);
            $this->fail('There is no live connection to sync.');
        } catch (StripeConnectException $e) {
            $this->assertSame(StripeConnectException::NOT_CONNECTED, $e->reason);
        }

        $this->assertSame(StripeConnectionStatus::Disconnected, BusinessStripeConnection::query()->sole()->status);
    }

    // =================================================================
    // §6.2 — owner-only, server-derived
    // =================================================================

    public function test_a_non_owner_member_cannot_connect_sync_or_disconnect(): void
    {
        [$customer, $business, $workspace] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        $staff = $this->createCustomer();
        $this->addWorkspaceAdmin($workspace, $staff);
        $before = $this->dbSnapshot();

        foreach ([
            fn () => $this->manager()->connect($staff->user_id, $business, 'https://app.test/r', 'https://app.test/t'),
            fn () => $this->manager()->resumeOnboarding($staff->user_id, $business, 'https://app.test/r', 'https://app.test/t'),
            fn () => $this->manager()->syncFromProvider($staff->user_id, $business),
            fn () => $this->manager()->disconnect($staff->user_id, $business),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Even a Workspace Admin is not an owner for financial consent.');
            } catch (StripeConnectException $e) {
                $this->assertSame(StripeConnectException::NOT_OWNER, $e->reason);
            }
        }

        $this->assertSame($before, $this->dbSnapshot());
    }

    public function test_the_workspace_owner_counts_as_an_owner(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        $ownerUserId = (int) $workspace->owner_user_id;

        // Point the Business at a DIFFERENT real customer, so the only route
        // to ownership left is the Workspace-owner branch.
        $someoneElse = $this->createCustomer();
        DB::table('businesses')->where('id', $business->id)->update(['customer_id' => $someoneElse->user_id]);
        $this->assertNotSame($ownerUserId, (int) $someoneElse->user_id);

        $this->manager()->connect($ownerUserId, $business->fresh(), 'https://app.test/r', 'https://app.test/t');

        $this->assertSame(1, BusinessStripeConnection::query()->count());
    }

    public function test_a_foreign_businesss_owner_cannot_touch_this_businesss_connection(): void
    {
        [, $mine] = $this->tenantBusiness('Mine');
        [$otherCustomer, $other] = $this->tenantBusiness('Other');
        $this->manager()->connect($otherCustomer->user_id, $other, 'https://app.test/r', 'https://app.test/t');
        $before = $this->dbSnapshot();

        try {
            $this->manager()->connect($otherCustomer->user_id, $mine, 'https://app.test/r', 'https://app.test/t');
            $this->fail('Cross-Business access must fail closed.');
        } catch (StripeConnectException $e) {
            $this->assertSame(StripeConnectException::NOT_OWNER, $e->reason);
        }

        $this->assertSame($before, $this->dbSnapshot());
    }

    public function test_a_tampered_business_model_cannot_borrow_another_businesss_identity(): void
    {
        [$customer, $mine] = $this->tenantBusiness('Mine');
        [, $foreign] = $this->tenantBusiness('Foreign');

        // A detached model claiming the foreign Business's id, with my own
        // owner fields forged onto it. Ownership is re-derived from
        // persistence, so the forgery is ignored.
        $lying = new Business();
        $lying->forceFill(['id' => $foreign->id, 'customer_id' => $customer->user_id, 'workspace_id' => $mine->workspace_id]);

        try {
            $this->manager()->connect($customer->user_id, $lying, 'https://app.test/r', 'https://app.test/t');
            $this->fail('Ownership must be re-derived, never trusted from the passed model.');
        } catch (StripeConnectException $e) {
            $this->assertSame(StripeConnectException::NOT_OWNER, $e->reason);
        }

        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    // =================================================================
    // Server-derived account identity
    // =================================================================

    public function test_the_stripe_account_is_always_read_from_our_own_row(): void
    {
        [$customer, $mine] = $this->tenantBusiness('Mine');
        [$otherCustomer, $other] = $this->tenantBusiness('Other');
        $this->manager()->connect($otherCustomer->user_id, $other, 'https://app.test/r', 'https://app.test/t');
        $foreignAccount = BusinessStripeConnection::query()->where('business_id', $other->id)->sole()->stripe_account_id;

        $this->manager()->connect($customer->user_id, $mine, 'https://app.test/r', 'https://app.test/t');
        $myAccount = BusinessStripeConnection::query()->where('business_id', $mine->id)->sole()->stripe_account_id;
        $this->gateway->calls = [];

        $this->manager()->syncFromProvider($customer->user_id, $mine);
        $this->manager()->resumeOnboarding($customer->user_id, $mine, 'https://app.test/r', 'https://app.test/t');

        $this->assertSame([$myAccount, $myAccount], $this->gateway->accountsTouched());
        $this->assertNotContains($foreignAccount, $this->gateway->accountsTouched());
    }

    public function test_no_route_or_controller_action_accepts_a_connected_account_id(): void
    {
        $routes = collect(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains((string) $route->getActionName(), BusinessPaymentsController::class));

        $this->assertCount(5, $routes);

        foreach ($routes as $route) {
            $this->assertSame(['workspaceUid', 'businessUid'], $route->parameterNames(),
                "[{$route->getName()}] must take no provider identifier.");
        }

        $source = file_get_contents(dirname(__DIR__, 3) . '/app/Http/Controllers/Customer/Business/BusinessPaymentsController.php');
        foreach (['stripe_account', 'account_id', 'acct_', 'connected_account'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source,
                "The controller must never read [{$forbidden}] from a request.");
        }
    }

    // =================================================================
    // §7 — no provider call under a transaction or lock
    // =================================================================

    public function test_no_gateway_call_happens_inside_a_transaction(): void
    {
        // The fake throws if DB::transactionLevel() rises above the baseline,
        // so simply exercising every path proves it.
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $this->manager()->syncFromProvider($customer->user_id, $business);
        $this->manager()->resumeOnboarding($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $this->manager()->disconnect($customer->user_id, $business);

        $this->assertNotEmpty($this->gateway->calls);

        foreach ($this->gateway->calls as $call) {
            $this->assertSame($this->gateway->baselineTransactionLevel, $call['transaction_level'],
                "[{$call['method']}] ran inside a transaction.");
        }
    }

    public function test_a_provider_failure_leaves_no_half_built_connection(): void
    {
        [$customer, $business] = $this->tenantBusiness();
        $this->gateway->failWith = StripeConnectException::providerFailed();

        try {
            $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
            $this->fail('Expected the provider failure to surface.');
        } catch (StripeConnectException $e) {
            $this->assertSame(StripeConnectException::PROVIDER_FAILED, $e->reason);
        }

        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    // =================================================================
    // HTTP surface: the gate chain
    // =================================================================

    public function test_every_route_is_404_while_payments_contracts_is_planned(): void
    {
        [, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);

        $this->get($this->url('show', $workspace, $business))->assertNotFound();
        $this->post($this->url('start', $workspace, $business))->assertNotFound();
        $this->get($this->url('resume', $workspace, $business))->assertNotFound();
        $this->post($this->url('refresh', $workspace, $business))->assertNotFound();
        $this->post($this->url('disconnect', $workspace, $business))->assertNotFound();

        $this->assertSame(0, BusinessStripeConnection::query()->count());
        $this->assertSame([], $this->gateway->calls);
    }

    public function test_without_the_payments_contracts_capability_the_surface_is_refused(): void
    {
        $this->allowEntitlement();
        [, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business, ['view_contact']);

        $this->get($this->url('show', $workspace, $business))->assertUnauthorized();
    }

    public function test_a_stranger_gets_a_404_for_every_route(): void
    {
        $this->allowEntitlement();
        [, $business, $workspace] = $this->tenantBusiness();
        $this->authenticateAs($this->createCustomer());

        $this->get($this->url('show', $workspace, $business))->assertNotFound();
        $this->post($this->url('start', $workspace, $business))->assertNotFound();
        $this->assertSame(0, BusinessStripeConnection::query()->count());
    }

    public function test_the_owner_can_connect_and_disconnect_over_http(): void
    {
        $this->allowEntitlement();
        [, $business, $workspace] = $this->tenantBusiness();
        $this->actAsOwner($business);

        $this->post($this->url('start', $workspace, $business))
            ->assertRedirect('https://connect.stripe.test/setup/acct_fake000001');

        $this->assertSame(StripeConnectionStatus::Onboarding, BusinessStripeConnection::query()->sole()->status);

        $this->post($this->url('disconnect', $workspace, $business))
            ->assertRedirect($this->url('show', $workspace, $business))
            ->assertSessionHas('status', 'success');

        $this->assertSame(StripeConnectionStatus::Disconnected, BusinessStripeConnection::query()->sole()->status);
    }

    public function test_a_non_owner_who_passes_the_chain_can_read_but_never_mutate(): void
    {
        $this->allowEntitlement();
        [$customer, $business, $workspace] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        $staff = $this->createCustomer();
        $this->addWorkspaceAdmin($workspace, $staff);
        $this->authenticateAs($staff);
        $before = $this->dbSnapshot();

        $html = $this->get($this->url('show', $workspace, $business))->assertOk()->getContent();
        $this->assertStringContainsString('data-role="owner-only-note"', $html);
        $this->assertStringNotContainsString('data-role="disconnect-form"', $html);

        $this->post($this->url('start', $workspace, $business))->assertNotFound();
        $this->post($this->url('disconnect', $workspace, $business))->assertNotFound();
        $this->post($this->url('refresh', $workspace, $business))->assertNotFound();

        $this->assertSame($before, $this->dbSnapshot());
    }

    public function test_the_status_page_shows_the_locked_commercial_posture_and_readiness(): void
    {
        $this->allowEntitlement();
        [$customer, $business, $workspace] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');
        $this->actAsOwner($business);

        $html = $this->get($this->url('show', $workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('merchant of', $html);
        $this->assertStringContainsString('data-ready="0"', $html);
        $this->assertStringContainsString('data-status="onboarding"', $html);
    }

    // =================================================================
    // Lane and scope boundaries
    // =================================================================

    public function test_lane_b_never_reaches_the_platform_or_usage_stripe_stack(): void
    {
        // §4.2 — lane B owns a SECOND gateway precisely so lane-B charges do
        // not run through the platform's own Stripe account. The full §11.1
        // artifact list is enforced for these same files by
        // DocumentsSchemaTest; this pins the lane-D gateway specifically.
        foreach ($this->laneBSources() as $path => $code) {
            foreach ([
                'App\\Library\\Usage', 'StripePaymentProviderGateway', 'PaymentProviderGateway',
                'PayerType', 'EffectivePayer', 'UsageWallet', 'PaymentController',
                'business_payment_instruments', 'business_usage_',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code, basename($path) . " must not reference [{$forbidden}].");
            }

            // The lane-A/D TABLE `payment_methods` is forbidden; Stripe's own
            // `automatic_payment_methods` request parameter (Sub-slice E) is
            // not the same string and must not be caught by it.
            $this->assertSame(0, preg_match('/(?<!automatic_)\bpayment_methods\b/', $code),
                basename($path) . ' must not reference the lane-A/D payment_methods table.');
        }
    }

    public function test_only_the_gateway_implementation_touches_the_stripe_sdk(): void
    {
        foreach ($this->laneBSources() as $path => $code) {
            if (basename($path) === 'StripeApiConnectGateway.php') {
                $this->assertStringContainsString('Stripe\\StripeClient', $code);

                continue;
            }

            $this->assertStringNotContainsString('Stripe\\', $code,
                basename($path) . ' must reach Stripe only through the gateway.');
        }
    }

    public function test_lane_b_contains_no_dispute_or_platform_fee_machinery(): void
    {
        // Sub-slice E legitimately added PaymentIntents, the Payment Element
        // and the verified webhook, and Sub-slice F legitimately added
        // refunds, so none of those are forbidden here any more. What remains
        // out of scope in V1 is the DISPUTE surface and, permanently, any
        // platform intermediation of the Business's revenue (§11.2).
        foreach ($this->laneBSources() as $path => $code) {
            foreach ([
                'application_fee', 'on_behalf_of', 'transfer_data',
                'dispute', 'Dispute',
                'reminder', 'Reminder',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $code,
                    basename($path) . " must not contain [{$forbidden}].");
            }
        }

        // D's own onboarding path still creates no payment row: that only
        // happens through E's PaymentManager, which this test never calls.
        [$customer, $business] = $this->tenantBusiness();
        $this->manager()->connect($customer->user_id, $business, 'https://app.test/r', 'https://app.test/t');

        $this->assertSame(0, DB::table('business_document_payments')->count());
    }

    public function test_no_application_fee_or_platform_intermediation_is_configured(): void
    {
        $gateway = (string) file_get_contents(app_path('Library/Payments/StripeApiConnectGateway.php'));

        // §11.2's locked posture, asserted as the exact documented values.
        $this->assertStringContainsString("'fees' => ['payer' => 'account']", $gateway);
        $this->assertStringContainsString("'losses' => ['payments' => 'stripe']", $gateway);
        $this->assertStringContainsString("'requirement_collection' => 'stripe'", $gateway);
        $this->assertStringContainsString("'stripe_dashboard' => ['type' => 'full']", $gateway);

        // The deprecated legacy account architecture is never selected.
        $this->assertStringNotContainsString("'type' => 'standard'", $gateway);
        $this->assertStringNotContainsString("'type' => 'express'", $gateway);
        $this->assertStringNotContainsString("'type' => 'custom'", $gateway);
    }

    public function test_no_secret_is_ever_returned_logged_or_thrown(): void
    {
        foreach ($this->laneBSources() as $path => $code) {
            // The GATEWAY — the one file that holds secrets and raw provider
            // objects — still may not log at all.
            if (basename($path) === 'StripeApiConnectGateway.php') {
                $this->assertStringNotContainsString('Log::', $code, 'The gateway must never log.');
            }

            // Everywhere else in the lane, Sub-slice F's bounded sweeps do
            // need to record that one row failed — otherwise a failure is
            // silent. What a log line may never carry is the material this
            // boundary exists to contain: a secret, a provider payload or
            // account, or a raw exception (whose message is provider text).
            foreach (["/Log::[^;]*\\\$secret/", "/Log::[^;]*\\\$snapshot/", "/Log::[^;]*stripe_account/",
                "/Log::[^;]*getMessage/", "/Log::[^;]*=>\s*\\\$e\b/", "/Log::[^;]*\\\$connection/",
            ] as $pattern) {
                $this->assertSame(0, preg_match($pattern, $code),
                    basename($path) . ' must not log provider material.');
            }

            // The API key is read in ONE place and never leaves it.
            if (basename($path) !== 'StripeApiConnectGateway.php') {
                $this->assertStringNotContainsString('services.stripe.secret', $code,
                    basename($path) . ' must not read the platform API key.');
                $this->assertStringNotContainsString('StripeClient', $code);
            }
        }

        $gateway = (string) file_get_contents(app_path('Library/Payments/StripeApiConnectGateway.php'));

        // Exactly two secrets are read, both here and nowhere else: the
        // platform API key (constructor) and the Connect webhook signing
        // secret (§5.8). Neither is returned, logged or thrown.
        $this->assertSame(1, substr_count($gateway, 'services.stripe.secret'));
        $this->assertSame(1, substr_count($gateway, 'services.stripe.connect_webhook.secret'));
        // The secret is CONSUMED (handed to the SDK client) but never handed
        // back: returning `new StripeClient($secret)` is fine, returning
        // `$secret` is not.
        $this->assertSame(0, preg_match('/return\s+\$secret\b/', $gateway), 'A secret must never be returned.');
        $this->assertSame(0, preg_match('/(Exception|throw)[^;]*\$secret/', $gateway), 'A secret must never reach an exception.');
        $this->assertSame(0, preg_match('/Log::[^;]*\$secret/', $gateway), 'A secret must never be logged.');

        // Provider error text is swallowed, never propagated: it can carry
        // account identifiers and request detail.
        $exception = (string) file_get_contents(app_path('Exceptions/Payments/StripeConnectException.php'));
        $this->assertStringNotContainsString('getMessage()', $exception);
        $this->assertStringNotContainsString('$e->', $gateway, 'The provider exception is caught without being read.');
    }

    /** @return array<string, string> path => source */
    private function laneBSources(): array
    {
        $files = array_merge(
            glob(app_path('Library/Payments/*.php')) ?: [],
            glob(app_path('Exceptions/Payments/*.php')) ?: [],
            [app_path('Http/Controllers/Customer/Business/BusinessPaymentsController.php')],
        );

        $sources = [];

        foreach ($files as $file) {
            // Comments stripped: a docblock naming a forbidden lane must not
            // itself trip the scan.
            $code = '';

            foreach (token_get_all((string) file_get_contents($file)) as $token) {
                if (is_array($token)) {
                    if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            $sources[$file] = $code;
        }

        $this->assertNotEmpty($sources);

        return $sources;
    }

    // =================================================================
    // Helpers
    // =================================================================

    /** @return array<string, mixed> */
    private function rawConnection(Business $business, string $accountId, string $status): array
    {
        return [
            'uid' => (string) Str::uuid(),
            'business_id' => $business->id,
            'stripe_account_id' => $accountId,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /** An ACTIVE Workspace Admin: full operational reach, and still not an owner. */
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

    private function dbSnapshot(): string
    {
        return md5(DB::table('business_stripe_connections')->orderBy('id')->get()->toJson());
    }
}
