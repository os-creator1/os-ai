<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\FundingAttemptPurpose;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\TransitionSource;
use App\Exceptions\Usage\UnauthorizedSlotAgreementActionException;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessFundingAttemptTransitionRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Admin Usage Billing Surface Contract §5.4 — manager-layer
 * tests for the corrected retryFundingAttemptAsAdministrator() (§2.1.2),
 * mirroring SlotAgreementAdminAuthorityTest's own established manager-
 * layer pattern for the sibling M4 admin methods on the same class.
 */
class FundingAttemptRetryAsAdministratorAuthorityTest extends TestCase
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

    private function adminUser(): User
    {
        return User::create([
            'first_name' => 'Fixture', 'last_name' => 'Admin', 'email' => 'admin'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    private function nonAdminUser(): User
    {
        return User::create([
            'first_name' => 'Fixture', 'last_name' => 'NonAdmin', 'email' => 'nonadmin'.uniqid('', true).'@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
    }

    private function resumableAttempt(): \App\Models\BusinessFundingAttempt
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        return app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
    }

    public function test_a_non_admin_actor_directly_invoking_retry_as_administrator_is_denied_before_any_gateway_call(): void
    {
        $attempt = $this->resumableAttempt();
        $nonAdmin = $this->nonAdminUser();

        $gatewayMock = Mockery::mock(PaymentProviderGateway::class);
        $gatewayMock->shouldNotReceive('retrieveCheckoutSession');
        $gatewayMock->shouldNotReceive('retrievePaymentIntent');
        $this->app->instance(PaymentProviderGateway::class, $gatewayMock);

        $this->expectException(UnauthorizedSlotAgreementActionException::class);
        app(UsageBillingCheckoutManager::class)->retryFundingAttemptAsAdministrator($attempt, $nonAdmin->id, 'Attempted.');
    }

    public function test_a_blank_reason_retry_is_denied_before_any_gateway_call(): void
    {
        $attempt = $this->resumableAttempt();
        $admin = $this->adminUser();

        $gatewayMock = Mockery::mock(PaymentProviderGateway::class);
        $gatewayMock->shouldNotReceive('retrieveCheckoutSession');
        $gatewayMock->shouldNotReceive('retrievePaymentIntent');
        $this->app->instance(PaymentProviderGateway::class, $gatewayMock);

        $this->expectException(UnauthorizedSlotAgreementActionException::class);
        app(UsageBillingCheckoutManager::class)->retryFundingAttemptAsAdministrator($attempt, $admin->id, '   ');
    }

    public function test_a_successful_admin_retry_records_the_actor_and_the_normalized_reason_on_the_transition(): void
    {
        $attempt = $this->resumableAttempt();
        $admin = $this->adminUser();

        $manager = app(UsageBillingCheckoutManager::class);
        $paymentMethodId = 'pm_fake_verified_'.uniqid();

        $this->gateway->registerPaymentMethod(new \App\Library\Usage\PaymentMethodResult(
            $paymentMethodId,
            $attempt->provider_customer_external_id_snapshot,
            'card', 'visa', '4242', 12, 2030,
        ));

        $this->gateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $manager->expectedMinorUnitsFor($attempt),
            $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_verified_'.uniqid(),
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_retry_authority.',
            'ch_fake_retry_authority',
        ));

        $result = $manager->retryFundingAttemptAsAdministrator($attempt, $admin->id, '  Support requested.  ');

        $this->assertSame(FundingAttemptState::Succeeded, $result->state);

        $transition = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $attempt->id)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->first();

        $this->assertSame($admin->id, $transition->actor_user_id);
        $this->assertSame('Support requested.', $transition->reason);
    }

    public function test_existing_non_admin_transition_sources_still_persist_a_null_reason(): void
    {
        $attempt = $this->resumableAttempt();

        $manager = app(UsageBillingCheckoutManager::class);
        $paymentMethodId = 'pm_fake_verified_'.uniqid();

        $this->gateway->registerPaymentMethod(new \App\Library\Usage\PaymentMethodResult(
            $paymentMethodId,
            $attempt->provider_customer_external_id_snapshot,
            'card', 'visa', '4242', 12, 2030,
        ));

        $this->gateway->registerCheckoutSessionResult(new \App\Library\Usage\CheckoutSessionResult(
            (string) $attempt->provider_session_or_intent_reference,
            'complete',
            'paid',
            null,
            $manager->expectedMinorUnitsFor($attempt),
            $manager->expectedCurrencyCodeFor($attempt),
            $attempt->provider_customer_external_id_snapshot,
            'pi_fake_verified_'.uniqid(),
            $paymentMethodId,
            'https://fake.stripe.test/receipts/ch_fake_sync_response.',
            'ch_fake_sync_response',
        ));

        $manager->confirmAttemptFromReturn($attempt);

        $transition = DB::table('business_funding_attempt_transitions')
            ->where('funding_attempt_id', $attempt->id)
            ->where('to_state', FundingAttemptState::Succeeded->value)
            ->first();

        $this->assertSame(TransitionSource::SyncResponse->value, $transition->source);
        $this->assertNull($transition->reason);
    }
}
