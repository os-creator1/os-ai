<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Exceptions\Usage\UnauthorizedPayerAssignmentException;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §16/§25 item 97 — an in-flight attempt's frozen
 * payer_type_snapshot is unaffected by a concurrent payer change; a new
 * attempt under the old payer's authority is blocked once the change
 * commits.
 */
class PayerChangeDuringPendingAttemptTest extends TestCase
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

    private function entitledWorkspace(User $owner): Workspace
    {
        $workspace = Workspace::create(['name' => 'Test Workspace', 'owner_user_id' => $owner->id, 'is_active' => true]);
        $admin = User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'fixture' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        app(EntitlementManager::class)->assignFirstPlan($workspace, WorkspacePlanTier::Core, $admin->id, 'Fixture.', true, 2);

        return $workspace->fresh();
    }

    /**
     * RFC-005 Funding Provider-Flow Correction Contract §17 — corrected:
     * a ManualTopUp attempt is Checkout-Session-backed (provider_pending,
     * never requires_action via paymentIntentOutcomes, which no longer
     * applies), and requires no pre-saved instrument; only a resolved
     * provider customer (§9's Option 1, unchanged). The actual invariant
     * under test — the frozen payer_type_snapshot is unaffected by a
     * later payer change, and the in-flight attempt remains independently
     * confirmable — is fully preserved.
     */
    public function test_an_in_flight_attempts_payer_snapshot_is_unaffected_by_a_later_payer_change(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $directOwner = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerCustomer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $ownerCustomer->user_id);

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $result = $checkoutManager->initiateTopUp($business, $ownerCustomer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame(PayerType::Workspace, $attempt->payer_type_snapshot);
        $this->assertSame(FundingAttemptState::ProviderPending, $attempt->state);

        // The payer change happens while the attempt is still in flight.
        app(BillingProfileManager::class)->changePayer($business, PayerType::Business, $directOwner->user_id, 'Mid-flight payer change.');

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(PayerType::Workspace, $freshAttempt->payer_type_snapshot, 'The already-created attempt\'s own frozen snapshot must not retroactively change.');

        // The already-in-flight attempt is still independently confirmable.
        $paymentMethodId = 'pm_fake_payer_change';
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            $paymentMethodId, $freshAttempt->provider_customer_external_id_snapshot, 'card', 'visa', '4242', 12, 2030,
        ));
        $this->gateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $freshAttempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $checkoutManager->expectedMinorUnitsFor($freshAttempt),
            $checkoutManager->expectedCurrencyCodeFor($freshAttempt),
            $freshAttempt->provider_customer_external_id_snapshot,
            'pi_fake_payer_change',
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_payer_change',
            'ch_fake_payer_change',
        ));

        $confirmed = $checkoutManager->confirmAttemptFromReturn($freshAttempt);
        $this->assertSame(FundingAttemptState::Succeeded, $confirmed->state);
    }

    public function test_a_new_attempt_under_the_old_payers_authority_is_blocked_once_the_change_commits(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $directOwner = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerCustomer->user_id, 'Test.');

        // Payer changes from workspace to business.
        app(BillingProfileManager::class)->changePayer($business, PayerType::Business, $directOwner->user_id, 'Test.');

        $this->expectException(UnauthorizedPayerAssignmentException::class);
        app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $ownerCustomer->user_id, 1_000_000);
    }

    public function test_updating_the_billing_contact_never_rewrites_a_funding_attempts_frozen_snapshot(): void
    {
        $ownerCustomer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($ownerCustomer->user);
        $directOwner = $this->createCustomer();
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($directOwner, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $ownerCustomer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $ownerCustomer->user_id);

        app(BillingProfileManager::class)->updateBillingContact(
            $business, null, 'Original Contact', 'original@example.test', true, $ownerCustomer->user_id,
        );

        $checkoutManager = app(UsageBillingCheckoutManager::class);
        $result = $checkoutManager->initiateTopUp($business, $ownerCustomer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        $this->assertSame('Original Contact', $attempt->billing_contact_name_snapshot);
        $this->assertSame('original@example.test', $attempt->billing_contact_email_snapshot);

        // The billing contact changes after the funding attempt was
        // created — its own frozen snapshot columns must never be
        // re-derived from the current contact, on read or on a later
        // write.
        app(BillingProfileManager::class)->updateBillingContact(
            $business, null, 'Changed Contact', 'changed@example.test', true, $ownerCustomer->user_id,
        );

        $freshAttempt = app(BusinessFundingAttemptRepository::class)->findById($attempt->id);
        $this->assertSame(
            'Original Contact',
            $freshAttempt->billing_contact_name_snapshot,
            'The funding attempt\'s own frozen snapshot must not retroactively change.',
        );
        $this->assertSame(
            'original@example.test',
            $freshAttempt->billing_contact_email_snapshot,
            'The funding attempt\'s own frozen snapshot must not retroactively change.',
        );
    }
}
