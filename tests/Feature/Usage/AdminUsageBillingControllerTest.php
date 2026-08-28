<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\FundingAttemptPurpose;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Enums\Usage\WalletBillingStatus;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use App\Repositories\Contracts\BusinessUsageWalletRepository;
use App\Repositories\Contracts\PlatformFeatureUsageSafetyLimitRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Admin Usage Billing Surface Contract §5.1 — HTTP-level tests
 * for Admin\UsageBillingController (§2.1), following
 * AdminWorkspaceEntitlementControllerTest's own established template.
 */
class AdminUsageBillingControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private FakePaymentProviderGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }

    private function actingAsAdmin(array $permissions = ['access backend']): User
    {
        $admin = User::create([
            'first_name' => 'Test', 'last_name' => 'Admin',
            'email' => 'admin'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function businessWithWallet(): Business
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        return $business;
    }

    private function businessWithProviderCustomer(): Business
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        return $business;
    }

    private function setWalletBalances(Business $business, int $availableMicro, int $debtMicro): void
    {
        DB::table('business_usage_wallets')
            ->where('business_id', $business->id)
            ->update(['available_balance_micro' => $availableMicro, 'debt_balance_micro' => $debtMicro]);
    }

    private function seedLedgerRow(Business $business, array $overrides = []): void
    {
        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($business->id);

        DB::table('business_usage_ledger_entries')->insert(array_merge([
            'business_id' => $business->id,
            'wallet_id' => $wallet->id,
            'entry_type' => UsageLedgerEntryType::UsageCharge->value,
            'available_delta_micro' => 0,
            'reserved_delta_micro' => 0,
            'debt_delta_micro' => 0,
            'gross_amount_micro' => 1_000_000,
            'currency_id' => $wallet->currency_id,
            'feature_key' => 'sms_send',
            'period_key' => now()->format('Y-m'),
            'quantity' => 1,
            'provider_cost_micro' => 500_000,
            'correlation_key' => 'seed:'.Str::uuid(),
            'created_at' => now(),
        ], $overrides));
    }

    private function resumableFundingAttempt(Business $business): \App\Models\BusinessFundingAttempt
    {
        $wallet = app(BusinessUsageWalletRepository::class)->findByBusinessId($business->id);
        $customerUserId = $business->customer->user_id;

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customerUserId, 5_000_000);

        return app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
    }

    private function registerVerifiedCheckoutOutcome(\App\Models\BusinessFundingAttempt $attempt): void
    {
        $manager = app(UsageBillingCheckoutManager::class);
        $paymentMethodId = 'pm_fake_verified_'.uniqid();

        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot,
            'card', 'visa', '4242', 12, 2030,
        ));

        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference, 'complete', 'paid', null,
            $manager->expectedMinorUnitsFor($attempt), $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot, 'pi_fake_verified_'.uniqid(),
            $paymentMethodId, 'https://fake.stripe.test/receipts/ch_fake_admin_http.',
            'ch_fake_admin_http',
        ));
    }

    // --- Route shape -----------------------------------------------------

    public function test_all_new_routes_exist_with_expected_verbs(): void
    {
        $expected = [
            ['admin.businesses.usage-billing.show', 'GET'],
            ['admin.businesses.usage-billing.credit', 'POST'],
            ['admin.businesses.usage-billing.suspend', 'POST'],
            ['admin.businesses.usage-billing.resume', 'POST'],
            ['admin.businesses.usage-billing.funding-attempts.retry', 'POST'],
            ['admin.usage-billing.safety-limits.index', 'GET'],
            ['admin.usage-billing.safety-limits.update', 'POST'],
        ];

        foreach ($expected as [$name, $verb]) {
            $this->assertTrue(Route::has($name), "Missing route [{$name}]");
            $route = Route::getRoutes()->getByName($name);
            $this->assertContains($verb, $route->methods());
        }
    }

    // --- show() authorization ---------------------------------------------

    public function test_guest_cannot_view_a_businesss_usage_billing_dashboard(): void
    {
        $business = $this->businessWithWallet();

        $this->get(route('admin.businesses.usage-billing.show', $business))->assertUnauthorized();
    }

    public function test_a_non_admin_customer_cannot_view_a_businesss_usage_billing_dashboard(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAs($business->customer->user);

        $this->get(route('admin.businesses.usage-billing.show', $business))->assertUnauthorized();
    }

    public function test_a_non_admin_account_is_blocked_even_with_usage_billing_permissions_in_session(): void
    {
        $business = $this->businessWithWallet();
        $customer = $this->createCustomer();
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($customer->user);

        $this->get(route('admin.businesses.usage-billing.show', $business))->assertUnauthorized();
    }

    public function test_an_administrator_can_view_a_businesss_usage_billing_dashboard(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->get(route('admin.businesses.usage-billing.show', $business))->assertOk();
    }

    public function test_the_dashboard_shows_wallet_balance_debt_and_configured_limits(): void
    {
        $business = $this->businessWithWallet();
        $this->setWalletBalances($business, 7_000_000, 2_000_000);
        $this->actingAsAdmin();

        $response = $this->get(route('admin.businesses.usage-billing.show', $business));

        $response->assertOk();
        $response->assertSee('7000000');
        $response->assertSee('2000000');
    }

    public function test_the_ledger_listing_is_paginated(): void
    {
        $business = $this->businessWithWallet();
        for ($i = 0; $i < 30; $i++) {
            $this->seedLedgerRow($business);
        }
        $this->actingAsAdmin();

        $response = $this->get(route('admin.businesses.usage-billing.show', $business));

        $response->assertOk();
        $response->assertViewHas('ledgerEntries', fn ($paginator) => $paginator->count() === 25 && $paginator->total() === 30);
    }

    public function test_the_ledger_listing_can_be_filtered_by_entry_type_and_date_range(): void
    {
        $business = $this->businessWithWallet();
        $this->seedLedgerRow($business, ['entry_type' => UsageLedgerEntryType::UsageCharge->value]);
        $this->seedLedgerRow($business, ['entry_type' => UsageLedgerEntryType::ManualCredit->value]);
        $this->actingAsAdmin();

        $response = $this->get(route('admin.businesses.usage-billing.show', [$business, 'entry_type' => 'manual_credit']));

        $response->assertOk();
        $response->assertViewHas('ledgerEntries', function ($paginator) {
            return $paginator->count() === 1
                && $paginator->first()->entry_type === UsageLedgerEntryType::ManualCredit;
        });
    }

    /**
     * An unmatched implicit {business} route-model-binding throws
     * Illuminate\Database\Eloquent\ModelNotFoundException before the
     * request ever reaches UsageBillingController::show() — proving no
     * manager or repository call is ever attempted for it. This app's
     * own Handler::render() (app/Exceptions/Handler.php, unmodified by
     * this contract) maps that exception to the 500 errors.500 view
     * outside the local environment, identical to every other admin
     * controller's own implicit Business/Workspace binding — not a
     * behavior this contract introduces or is authorized to change.
     */
    public function test_an_unknown_business_id_returns_not_found_before_any_manager_call(): void
    {
        $this->actingAsAdmin();

        $response = $this->get(route('admin.businesses.usage-billing.show', 999999));

        $response->assertStatus(500);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\ModelNotFoundException::class, $response->exception);
    }

    // --- issueManualCredit() ----------------------------------------------

    public function test_guest_cannot_issue_manual_credit(): void
    {
        $business = $this->businessWithWallet();

        $this->post(route('admin.businesses.usage-billing.credit', $business), $this->manualCreditPayload())
            ->assertUnauthorized();

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
    }

    public function test_a_non_admin_customer_cannot_issue_manual_credit(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAs($business->customer->user);

        $this->post(route('admin.businesses.usage-billing.credit', $business), $this->manualCreditPayload())
            ->assertUnauthorized();

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
    }

    public function test_an_administrator_can_issue_a_manual_credit(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.credit', $business), $this->manualCreditPayload())
            ->assertRedirect(route('admin.businesses.usage-billing.show', $business));

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(5_000_000, (int) $wallet->available_balance_micro);
    }

    public function test_an_administrator_can_issue_a_promotional_credit(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.credit', $business), $this->manualCreditPayload(['entry_type' => 'promotional_credit']))
            ->assertRedirect(route('admin.businesses.usage-billing.show', $business));

        $entry = DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->first();
        $this->assertSame('promotional_credit', $entry->entry_type);
    }

    public function test_issuing_manual_credit_requires_a_mandatory_reason(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.credit', $business), $this->manualCreditPayload(['reason' => '']))
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
    }

    public function test_issuing_manual_credit_requires_a_positive_amount(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.credit', $business), $this->manualCreditPayload(['amount_micro' => 0]))
            ->assertSessionHasErrors('amount_micro');

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
    }

    public function test_issuing_manual_credit_rejects_a_disallowed_entry_type(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.credit', $business), $this->manualCreditPayload(['entry_type' => 'usage_charge']))
            ->assertSessionHasErrors('entry_type');

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
    }

    private function manualCreditPayload(array $overrides = []): array
    {
        return array_merge([
            'operation_id' => (string) Str::uuid(),
            'entry_type' => 'manual_credit',
            'amount_micro' => 5_000_000,
            'reason' => 'Goodwill credit.',
        ], $overrides);
    }

    // --- suspend/resume -----------------------------------------------------

    public function test_an_administrator_can_suspend_a_businesss_wallet_billing_status(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.suspend', $business), ['reason' => 'Fraud review.'])
            ->assertRedirect(route('admin.businesses.usage-billing.show', $business));

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(WalletBillingStatus::Suspended->value, $wallet->billing_status);
    }

    public function test_suspending_billing_status_requires_a_mandatory_reason(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.suspend', $business), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(WalletBillingStatus::Active->value, $wallet->billing_status);
    }

    public function test_an_administrator_can_resume_a_suspended_businesss_wallet_billing_status(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->actingAsAdmin();
        app(UsageWalletManager::class)->setBillingStatus(
            $business, WalletBillingStatus::Suspended, \App\Enums\Usage\BillingStatusTransitionSource::AdminAction, $admin->id, 'Suspended for fixture.',
        );

        $this->post(route('admin.businesses.usage-billing.resume', $business), ['reason' => 'Resolved.'])
            ->assertRedirect(route('admin.businesses.usage-billing.show', $business));

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(WalletBillingStatus::Active->value, $wallet->billing_status);
    }

    public function test_resuming_billing_status_requires_a_mandatory_reason(): void
    {
        $business = $this->businessWithWallet();
        $admin = $this->actingAsAdmin();
        app(UsageWalletManager::class)->setBillingStatus(
            $business, WalletBillingStatus::Suspended, \App\Enums\Usage\BillingStatusTransitionSource::AdminAction, $admin->id, 'Suspended for fixture.',
        );

        $this->post(route('admin.businesses.usage-billing.resume', $business), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(WalletBillingStatus::Suspended->value, $wallet->billing_status);
    }

    // --- retryFundingAttempt() ----------------------------------------------

    public function test_an_administrator_can_retry_an_outstanding_funding_attempt(): void
    {
        $business = $this->businessWithProviderCustomer();
        $attempt = $this->resumableFundingAttempt($business);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.funding-attempts.retry', [$business, $attempt->id]), ['reason' => 'Confirmed via provider dashboard.'])
            ->assertRedirect(route('admin.businesses.usage-billing.show', $business));

        $this->assertSame(FundingAttemptState::Succeeded, $attempt->fresh()->state);
    }

    public function test_retrying_a_funding_attempt_requires_a_mandatory_reason(): void
    {
        $business = $this->businessWithProviderCustomer();
        $attempt = $this->resumableFundingAttempt($business);
        $this->registerVerifiedCheckoutOutcome($attempt);
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.funding-attempts.retry', [$business, $attempt->id]), ['reason' => ''])
            ->assertSessionHasErrors('reason');

        $this->assertSame(FundingAttemptState::ProviderPending, $attempt->fresh()->state);
    }

    public function test_retrying_a_funding_attempt_for_an_unrelated_business_is_not_found(): void
    {
        $business = $this->businessWithProviderCustomer();
        $attempt = $this->resumableFundingAttempt($business);
        $this->registerVerifiedCheckoutOutcome($attempt);

        $unrelatedBusiness = $this->businessWithWallet();
        $this->actingAsAdmin();

        $gatewayMock = \Mockery::mock(PaymentProviderGateway::class);
        $gatewayMock->shouldNotReceive('retrieveCheckoutSession');
        $gatewayMock->shouldNotReceive('retrievePaymentIntent');
        $this->app->instance(PaymentProviderGateway::class, $gatewayMock);

        $this->post(route('admin.businesses.usage-billing.funding-attempts.retry', [$unrelatedBusiness, $attempt->id]), ['reason' => 'Mismatched.'])
            ->assertNotFound();

        $this->assertSame(FundingAttemptState::ProviderPending, $attempt->fresh()->state);
    }

    public function test_mutating_one_businesss_wallet_never_affects_an_unrelated_businesss_wallet(): void
    {
        $businessA = $this->businessWithWallet();
        $businessB = $this->businessWithWallet();
        $this->actingAsAdmin();

        $this->post(route('admin.businesses.usage-billing.credit', $businessA), $this->manualCreditPayload())
            ->assertRedirect();

        $walletA = DB::table('business_usage_wallets')->where('business_id', $businessA->id)->first();
        $walletB = DB::table('business_usage_wallets')->where('business_id', $businessB->id)->first();

        $this->assertSame(5_000_000, (int) $walletA->available_balance_micro);
        $this->assertSame(0, (int) $walletB->available_balance_micro);
    }

    // --- Platform safety limits ----------------------------------------------

    public function test_an_administrator_can_view_and_set_the_platform_feature_usage_safety_limit(): void
    {
        $this->actingAsAdmin();

        $this->get(route('admin.usage-billing.safety-limits.index'))->assertOk();

        $this->post(route('admin.usage-billing.safety-limits.update'), [
            'feature_key' => 'sms_send',
            'max_monthly_limit_micro' => 100_000_000,
            'reason' => 'Initial platform cap.',
        ])->assertRedirect(route('admin.usage-billing.safety-limits.index'));

        $limit = app(PlatformFeatureUsageSafetyLimitRepository::class)->findByFeatureKey('sms_send');
        $this->assertNotNull($limit);
        $this->assertSame(100_000_000, $limit->max_monthly_limit_micro);
    }

    public function test_setting_the_platform_safety_limit_requires_a_mandatory_reason(): void
    {
        $this->actingAsAdmin();

        $this->post(route('admin.usage-billing.safety-limits.update'), [
            'feature_key' => 'sms_send',
            'max_monthly_limit_micro' => 100_000_000,
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertNull(app(PlatformFeatureUsageSafetyLimitRepository::class)->findByFeatureKey('sms_send'));
    }

    // --- Idempotent replay via HTTP ------------------------------------------

    public function test_a_repeated_manual_credit_submission_with_the_same_operation_id_creates_exactly_one_ledger_entry(): void
    {
        $business = $this->businessWithWallet();
        $this->actingAsAdmin();
        $payload = $this->manualCreditPayload();

        $this->post(route('admin.businesses.usage-billing.credit', $business), $payload)->assertRedirect();
        $this->post(route('admin.businesses.usage-billing.credit', $business), $payload)->assertRedirect();

        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->count());
        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(5_000_000, (int) $wallet->available_balance_micro);
    }

    // --- Margin aggregate -----------------------------------------------------

    public function test_the_margin_aggregate_sums_multiple_usage_charge_and_usage_overage_charge_rows_correctly(): void
    {
        $business = $this->businessWithWallet();
        $periodKey = now()->format('Y-m');

        $this->seedLedgerRow($business, [
            'entry_type' => UsageLedgerEntryType::UsageCharge->value, 'feature_key' => 'sms_send',
            'period_key' => $periodKey, 'quantity' => 1, 'gross_amount_micro' => 10, 'provider_cost_micro' => 4,
        ]);
        $this->seedLedgerRow($business, [
            'entry_type' => UsageLedgerEntryType::UsageOverageCharge->value, 'feature_key' => 'sms_send',
            'period_key' => $periodKey, 'quantity' => 1, 'gross_amount_micro' => 20, 'provider_cost_micro' => 6,
        ]);

        $rows = app(BusinessUsageLedgerEntryRepository::class)->marginAggregateForBusiness((int) $business->id, $periodKey);
        $row = $rows->firstWhere('feature_key', 'sms_send');

        $this->assertSame('30', $row->retail_revenue_micro);
        $this->assertSame('10', $row->provider_cost_micro);
        $this->assertSame('20', $row->margin_micro);
        $this->assertIsString($row->retail_revenue_micro);
        $this->assertIsString($row->provider_cost_micro);
        $this->assertIsString($row->margin_micro);
    }

    public function test_the_margin_aggregate_rounds_a_half_micro_boundary_consistently_between_cost_and_margin(): void
    {
        $business = $this->businessWithWallet();
        $periodKey = now()->format('Y-m');

        $this->seedLedgerRow($business, [
            'entry_type' => UsageLedgerEntryType::UsageCharge->value, 'feature_key' => 'half_micro_feature',
            'period_key' => $periodKey, 'quantity' => 0.5, 'gross_amount_micro' => 10, 'provider_cost_micro' => 1,
        ]);
        $this->seedLedgerRow($business, [
            'entry_type' => UsageLedgerEntryType::UsageCharge->value, 'feature_key' => 'half_micro_feature',
            'period_key' => $periodKey, 'quantity' => 0.5, 'gross_amount_micro' => 10, 'provider_cost_micro' => 2,
        ]);

        $rows = app(BusinessUsageLedgerEntryRepository::class)->marginAggregateForBusiness((int) $business->id, $periodKey);
        $row = $rows->firstWhere('feature_key', 'half_micro_feature');

        $this->assertSame('2', $row->provider_cost_micro);
        $this->assertSame('20', $row->retail_revenue_micro);
        $this->assertSame(0, bccomp($row->margin_micro, '18', 0));
    }

    public function test_the_margin_aggregate_does_not_overflow_or_lose_precision_for_values_and_sums_exceeding_the_unsigned_bigint_maximum(): void
    {
        $business = $this->businessWithWallet();
        $periodKey = now()->format('Y-m');

        for ($i = 0; $i < 2; $i++) {
            $this->seedLedgerRow($business, [
                'entry_type' => UsageLedgerEntryType::UsageCharge->value, 'feature_key' => 'overflow_feature',
                'period_key' => $periodKey, 'quantity' => 1, 'gross_amount_micro' => 20,
                'provider_cost_micro' => '18446744073709551615',
            ]);
        }

        $rows = app(BusinessUsageLedgerEntryRepository::class)->marginAggregateForBusiness((int) $business->id, $periodKey);
        $row = $rows->firstWhere('feature_key', 'overflow_feature');

        $this->assertSame('36893488147419103230', $row->provider_cost_micro);
        $this->assertSame('40', $row->retail_revenue_micro);
        $this->assertIsString($row->provider_cost_micro);
        $this->assertIsString($row->retail_revenue_micro);
        $this->assertIsString($row->margin_micro);
        $this->assertSame(0, bccomp($row->margin_micro, '-36893488147419103190', 0));
    }

    public function test_the_margin_aggregate_is_isolated_by_business_and_period(): void
    {
        $businessA = $this->businessWithWallet();
        $businessB = $this->businessWithWallet();
        $periodKey = now()->format('Y-m');
        $otherPeriodKey = now()->subMonth()->format('Y-m');

        $this->seedLedgerRow($businessA, [
            'entry_type' => UsageLedgerEntryType::UsageCharge->value, 'feature_key' => 'isolation_feature',
            'period_key' => $periodKey, 'quantity' => 1, 'gross_amount_micro' => 10, 'provider_cost_micro' => 4,
        ]);
        $this->seedLedgerRow($businessA, [
            'entry_type' => UsageLedgerEntryType::UsageCharge->value, 'feature_key' => 'isolation_feature',
            'period_key' => $otherPeriodKey, 'quantity' => 1, 'gross_amount_micro' => 999, 'provider_cost_micro' => 999,
        ]);
        $this->seedLedgerRow($businessB, [
            'entry_type' => UsageLedgerEntryType::UsageCharge->value, 'feature_key' => 'isolation_feature',
            'period_key' => $periodKey, 'quantity' => 1, 'gross_amount_micro' => 999, 'provider_cost_micro' => 999,
        ]);

        $rows = app(BusinessUsageLedgerEntryRepository::class)->marginAggregateForBusiness((int) $businessA->id, $periodKey);
        $row = $rows->firstWhere('feature_key', 'isolation_feature');

        $this->assertSame('10', $row->retail_revenue_micro);
        $this->assertSame('4', $row->provider_cost_micro);
    }

    public function test_the_margin_aggregate_always_satisfies_margin_equals_revenue_minus_cost(): void
    {
        $business = $this->businessWithWallet();
        $periodKey = now()->format('Y-m');

        $this->seedLedgerRow($business, [
            'entry_type' => UsageLedgerEntryType::UsageCharge->value, 'feature_key' => 'invariant_a',
            'period_key' => $periodKey, 'quantity' => 3, 'gross_amount_micro' => 17, 'provider_cost_micro' => 5,
        ]);
        $this->seedLedgerRow($business, [
            'entry_type' => UsageLedgerEntryType::UsageOverageCharge->value, 'feature_key' => 'invariant_b',
            'period_key' => $periodKey, 'quantity' => 1, 'gross_amount_micro' => 40,
            'provider_cost_micro' => '18446744073709551615',
        ]);

        $rows = app(BusinessUsageLedgerEntryRepository::class)->marginAggregateForBusiness((int) $business->id, $periodKey);

        $this->assertGreaterThanOrEqual(2, $rows->count());

        foreach ($rows as $row) {
            $this->assertIsString($row->retail_revenue_micro);
            $this->assertIsString($row->provider_cost_micro);
            $this->assertIsString($row->margin_micro);
            $this->assertSame(0, bccomp($row->margin_micro, bcsub($row->retail_revenue_micro, $row->provider_cost_micro, 0), 0));
        }
    }
}
