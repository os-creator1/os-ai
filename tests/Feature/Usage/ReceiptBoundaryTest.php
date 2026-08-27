<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\ReceiptEvidenceUnavailableException;
use App\Jobs\Usage\SendReceiptNotification;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\CheckoutSessionResult;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\BusinessFundingAttempt;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\Usage\ReceiptAvailableNotification;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementRepository;
use App\Repositories\Contracts\BusinessBillingReceiptRepository;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use App\Repositories\Contracts\WorkspacePlanCatalogRepository;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Receipt Boundary Correction Contract §10 — proofs that do not naturally
 * fit any existing test file's own scope: schema, migration DDL,
 * slot-agreement non-eligibility, ensureFundingReceipt()'s own provider-
 * facing behavior, recoverability, notification preference/recipient
 * resolution, and the "no legacy invoices / no local document / Fake-only"
 * boundary assertions.
 */
class ReceiptBoundaryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private FakePaymentProviderGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $this->gateway = new FakePaymentProviderGateway();
        app()->instance(PaymentProviderGateway::class, $this->gateway);
    }

    /**
     * @return array{0: \App\Models\Business, 1: BusinessFundingAttempt, 2: int}
     */
    private function businessWithSuccessfulTopUp(?string $receiptUrl = 'https://fake.stripe.test/receipts/ch_fake_receipt_boundary', ?string $receiptChargeId = 'ch_fake_receipt_boundary'): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $result = $checkoutManager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $paymentMethodId = 'pm_fake_receipt_boundary';
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $attempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $checkoutManager->expectedMinorUnitsFor($attempt),
            $checkoutManager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_receipt_boundary',
            $paymentMethodId,
            $receiptUrl,
            $receiptChargeId,
        ));

        if ($receiptUrl === null) {
            try {
                $checkoutManager->confirmAttemptFromReturn($attempt);
            } catch (ReceiptEvidenceUnavailableException) {
                // Expected when evidence is deliberately absent.
            }
        } else {
            $checkoutManager->confirmAttemptFromReturn($attempt);
        }

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $ledgerEntry = DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->first();

        return [$business, $attempt, (int) $ledgerEntry->id];
    }

    public function test_business_billing_receipts_schema_matches_the_rfc_exactly(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('business_billing_receipts'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('business_billing_receipts', [
            'id', 'business_id', 'ledger_entry_id', 'provider_receipt_url', 'provider_reference', 'created_at',
        ]));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('business_billing_receipts', 'updated_at'));

        $foreignKeys = DB::select(
            'SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE '.
            'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            ['business_billing_receipts'],
        );
        $referencedTables = collect($foreignKeys)->pluck('REFERENCED_TABLE_NAME')->all();
        $this->assertContains('businesses', $referencedTables);
        $this->assertContains('business_usage_ledger_entries', $referencedTables);
    }

    public function test_receipt_migration_declares_no_extra_unique_or_convenience_index(): void
    {
        $source = file_get_contents(database_path('migrations/2026_08_27_120001_create_business_billing_receipts_table.php'));

        $this->assertStringNotContainsString('->unique(', $source);
        $this->assertStringNotContainsString('->index(', $source);
        $this->assertStringContainsString("->foreign('business_id')", $source);
        $this->assertStringContainsString("->foreign('ledger_entry_id')", $source);
    }

    public function test_slot_agreement_flows_never_create_a_business_billing_receipt(): void
    {
        $owner = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Owner', 'email' => 'owner'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid().'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $currencyId = Currency::query()->first()->id;
        $catalog = app(WorkspacePlanCatalogRepository::class)->findByTier(WorkspacePlanTier::Core);
        app(EntitlementManager::class)->updateCatalogPricing($catalog, '20.00', $currencyId, '0.5000', $admin->id, 'Fixture pricing.');
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', false, 0);

        $manager = app(UsageBillingCheckoutManager::class);
        $quote = $manager->quoteAdditionalSlotAgreement($workspace, 2, $owner->id);
        $agreement = app(AdditionalBusinessSlotAgreementRepository::class)->findById($quote->agreementId);
        $manager->initiateSlotAgreementCheckout($agreement, $owner->id);

        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult('pm_fake_slot_receipt', $providerCustomer->provider_customer_id, 'card', 'visa', '4242', 12, 2030));
        $this->gateway->checkoutSessionOutcomes[$agreement->local_idempotency_key] = ['providerPaymentMethodId' => 'pm_fake_slot_receipt'];

        $manager->confirmSlotAgreementFromReturn($agreement);

        $this->assertSame(0, DB::table('business_billing_receipts')->count());
    }

    public function test_ensure_funding_receipt_resolves_evidence_for_a_checkout_backed_attempt(): void
    {
        [$business, $attempt, $ledgerEntryId] = $this->businessWithSuccessfulTopUp();

        $receipt = DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntryId)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('ch_fake_receipt_boundary', $receipt->provider_reference);
        $this->assertSame('https://fake.stripe.test/receipts/ch_fake_receipt_boundary', $receipt->provider_receipt_url);
    }

    public function test_ensure_funding_receipt_resolves_evidence_for_a_payment_intent_backed_attempt(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $customer->user_id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        $result = app(UsageBillingCheckoutManager::class)->initiateAutoRecharge($business, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $ledgerEntry = DB::table('business_usage_ledger_entries')->where('funding_attempt_id', $attempt->id)->first();
        $receipt = DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntry->id)->first();

        $this->assertNotNull($receipt);
        $this->assertStringStartsWith('ch_fake_', $receipt->provider_reference);
    }

    /**
     * Renamed this round (§H) — the existing-receipt path never returns
     * null; no null return is asserted anywhere in this test.
     */
    public function test_ensure_funding_receipt_returns_the_existing_receipt_without_a_provider_call_or_write(): void
    {
        [$business, $attempt, $ledgerEntryId] = $this->businessWithSuccessfulTopUp();

        $existingCount = DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntryId)->count();
        $this->assertSame(1, $existingCount);

        $this->gateway->retrievePaymentIntentCalls = [];
        $this->gateway->retrieveCheckoutSessionCalls = [];

        $receipt = app(UsageBillingCheckoutManager::class)->ensureFundingReceipt($attempt, $ledgerEntryId);

        $this->assertNotNull($receipt);
        $existing = app(BusinessBillingReceiptRepository::class)->findByLedgerEntryId($ledgerEntryId);
        $this->assertSame($existing->id, $receipt->id);
        $this->assertSame(1, DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntryId)->count());
        $this->assertEmpty($this->gateway->retrievePaymentIntentCalls);
        $this->assertEmpty($this->gateway->retrieveCheckoutSessionCalls);
    }

    /**
     * Receipt Boundary Correction Contract §7/§L — the exact recovery
     * proof: a prior evidence failure never blocks a later manual
     * re-dispatch, once evidence becomes available, from persisting the
     * receipt.
     */
    public function test_a_manually_redispatched_send_receipt_notification_persists_the_receipt_after_a_prior_evidence_failure(): void
    {
        [$business, $attempt, $ledgerEntryId] = $this->businessWithSuccessfulTopUp(receiptUrl: null, receiptChargeId: null);

        $this->assertSame(0, DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntryId)->count());

        // Evidence becomes available: re-register the same Session id with
        // real receipt fields.
        $this->gateway->registerCheckoutSessionResult(new CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            app(UsageBillingCheckoutManager::class)->expectedMinorUnitsFor($attempt),
            app(UsageBillingCheckoutManager::class)->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_receipt_boundary',
            'pm_fake_receipt_boundary',
            'https://fake.stripe.test/receipts/ch_fake_recovered',
            'ch_fake_recovered',
        ));

        app()->call([new SendReceiptNotification((int) $attempt->id, $ledgerEntryId), 'handle']);

        $receipt = DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntryId)->first();
        $this->assertNotNull($receipt);
        $this->assertSame('ch_fake_recovered', $receipt->provider_reference);
    }

    public function test_send_receipt_notification_is_an_after_commit_queue_job(): void
    {
        $this->assertTrue(is_subclass_of(SendReceiptNotification::class, ShouldQueueAfterCommit::class));
        $this->assertTrue(is_subclass_of(SendReceiptNotification::class, \App\Jobs\Base::class));

        $job = new SendReceiptNotification(1, 1);
        $this->assertSame(1, $job->tries);
        $this->assertSame(1, $job->maxExceptions);
    }

    public function test_notification_opt_out_still_persists_the_receipt_but_sends_no_mail(): void
    {
        Notification::fake();

        [$business, $attempt, $ledgerEntryId] = $this->businessWithSuccessfulTopUp();

        app(BillingProfileManager::class)->updateBillingContact(
            $business, null, 'Jane Doe', 'jane@example.test', false, (int) $business->customer_id,
        );

        app()->call([new SendReceiptNotification((int) $attempt->id, $ledgerEntryId), 'handle']);

        $this->assertSame(1, DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntryId)->count());
        Notification::assertNothingSent();
    }

    public function test_missing_billing_contact_still_persists_the_receipt_but_sends_no_mail(): void
    {
        Notification::fake();

        [$business, $attempt, $ledgerEntryId] = $this->businessWithSuccessfulTopUp();

        $this->assertSame(1, DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntryId)->count());
        Notification::assertNothingSent();
    }

    public function test_independent_billing_contact_receives_the_receipt_at_contact_email(): void
    {
        Notification::fake();

        [$business, $attempt, $ledgerEntryId] = $this->businessWithSuccessfulTopUp();

        $expectedEmail = 'jane'.uniqid().'@example.test';
        app(BillingProfileManager::class)->updateBillingContact(
            $business, null, 'Jane Doe', $expectedEmail, true, (int) $business->customer_id,
        );

        app()->call([new SendReceiptNotification((int) $attempt->id, $ledgerEntryId), 'handle']);

        Notification::assertSentOnDemand(
            ReceiptAvailableNotification::class,
            function (ReceiptAvailableNotification $notification, array $channels, object $notifiable) use ($expectedEmail) {
                return $notifiable->routes['mail'] === $expectedEmail;
            },
        );
    }

    public function test_user_backed_billing_contact_receives_the_receipt_at_the_linked_user_email(): void
    {
        Notification::fake();

        [$business, $attempt, $ledgerEntryId] = $this->businessWithSuccessfulTopUp();

        $contactUser = User::create([
            'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane'.uniqid().'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
        app(BillingProfileManager::class)->updateBillingContact(
            $business, (int) $contactUser->id, null, null, true, (int) $business->customer_id,
        );

        app()->call([new SendReceiptNotification((int) $attempt->id, $ledgerEntryId), 'handle']);

        Notification::assertSentOnDemand(
            ReceiptAvailableNotification::class,
            function (ReceiptAvailableNotification $notification, array $channels, object $notifiable) use ($contactUser) {
                return $notifiable->routes['mail'] === $contactUser->email;
            },
        );
    }

    public function test_no_code_path_in_this_correction_references_the_legacy_invoices_table(): void
    {
        $files = [
            app_path('Library/Usage/UsageWalletManager.php'),
            app_path('Library/Usage/UsageBillingCheckoutManager.php'),
            app_path('Jobs/Usage/SendReceiptNotification.php'),
            app_path('Notifications/Usage/ReceiptAvailableNotification.php'),
            app_path('Models/BusinessBillingReceipt.php'),
            app_path('Repositories/Eloquent/EloquentBusinessBillingReceiptRepository.php'),
        ];

        foreach ($files as $file) {
            $this->assertStringNotContainsString("'invoices'", file_get_contents($file));
            $this->assertStringNotContainsString('->invoices()', file_get_contents($file));
        }
    }

    public function test_provider_receipt_url_is_always_the_verbatim_stripe_value_never_locally_constructed(): void
    {
        [$business, $attempt, $ledgerEntryId] = $this->businessWithSuccessfulTopUp(
            receiptUrl: 'https://checkout.stripe.com/pay/cs_test_receipt/receipt',
            receiptChargeId: 'ch_verbatim_test',
        );

        $receipt = DB::table('business_billing_receipts')->where('ledger_entry_id', $ledgerEntryId)->first();
        $this->assertSame('https://checkout.stripe.com/pay/cs_test_receipt/receipt', $receipt->provider_receipt_url);
    }

    public function test_receipt_boundary_tests_bind_only_the_fake_gateway_never_the_real_stripe_gateway(): void
    {
        $this->assertInstanceOf(FakePaymentProviderGateway::class, app(PaymentProviderGateway::class));
    }
}
