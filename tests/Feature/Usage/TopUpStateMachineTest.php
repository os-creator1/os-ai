<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\FundingAttemptState;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\UsageBillingCheckoutManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessFundingAttemptRepository;
use App\Repositories\Contracts\BusinessFundingAttemptTransitionRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §11/§25 item 80 — every top-up state transition,
 * append-only transition-row creation, idempotent repeat-commit no-op.
 */
class TopUpStateMachineTest extends TestCase
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
     * @return array{0: \App\Models\Customer, 1: Business}
     */
    private function businessWithAttachedInstrument(): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $setupIntent = $instrumentManager->createSetupIntent($business, $customer->user_id);
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $workspace->id);
        $this->gateway->registerPaymentMethod(new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            $providerCustomer->provider_customer_id,
            'card', 'visa', '4242', 12, 2030,
        ));
        $instrumentManager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        return [$customer, $business];
    }

    public function test_successful_top_up_transitions_created_to_provider_pending_to_succeeded(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertSame(FundingAttemptState::Succeeded, $result->state);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame(FundingAttemptState::Succeeded, $attempt->state);

        $transitions = app(BusinessFundingAttemptTransitionRepository::class)->query()
            ->where('funding_attempt_id', $result->fundingAttemptId)->orderBy('id')->pluck('to_state')
            ->map(fn ($s) => $s->value)->all();
        $this->assertSame(['created', 'provider_pending', 'succeeded'], $transitions);
    }

    public function test_declined_card_transitions_to_failed_with_a_reason(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();
        $this->gateway->paymentIntentOutcomes = ['*' => 'declined'];

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertSame(FundingAttemptState::Failed, $result->state);
        $this->assertSame('card_declined', $result->denialReason);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame(FundingAttemptState::Failed, $attempt->state);
        $this->assertNotNull($attempt->failure_reason);

        $transitions = app(BusinessFundingAttemptTransitionRepository::class)->query()
            ->where('funding_attempt_id', $result->fundingAttemptId)->orderBy('id')->pluck('to_state')
            ->map(fn ($s) => $s->value)->all();
        $this->assertSame(['created', 'failed'], $transitions);
    }

    public function test_requires_action_leaves_the_attempt_pending_authentication(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();
        $this->gateway->paymentIntentOutcomes = ['*' => 'requires_action'];

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertSame(FundingAttemptState::RequiresAction, $result->state);

        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);
        $this->assertSame(FundingAttemptState::RequiresAction, $attempt->state);
    }

    public function test_no_provider_customer_denies_the_attempt(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertSame(FundingAttemptState::Failed, $result->state);
        $this->assertSame('no_provider_customer', $result->denialReason);
        $this->assertSame(0, $result->fundingAttemptId);
    }

    public function test_no_payment_instrument_denies_the_attempt_without_creating_a_provider_call(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        // A resolved provider customer with no attached instrument.
        app(PaymentInstrumentManager::class)->resolveProviderCustomer($business, $customer->user_id);

        $result = app(UsageBillingCheckoutManager::class)->initiateTopUp($business, $customer->user_id, 5_000_000);

        $this->assertSame(FundingAttemptState::Failed, $result->state);
        $this->assertSame('no_payment_instrument', $result->denialReason);
        $this->assertSame(0, $result->fundingAttemptId);
    }

    public function test_repeat_commit_on_an_already_succeeded_attempt_is_idempotent(): void
    {
        [$customer, $business] = $this->businessWithAttachedInstrument();
        $checkoutManager = app(UsageBillingCheckoutManager::class);

        $result = $checkoutManager->initiateTopUp($business, $customer->user_id, 5_000_000);
        $attempt = app(BusinessFundingAttemptRepository::class)->findById($result->fundingAttemptId);

        // confirmAttemptFromReturn() on an already-succeeded attempt must
        // not credit the wallet a second time.
        $second = $checkoutManager->confirmAttemptFromReturn($attempt);

        $this->assertSame(FundingAttemptState::Succeeded, $second->state);

        $wallet = app(\App\Repositories\Contracts\BusinessUsageWalletRepository::class)->findByBusinessId((int) $business->id);
        $this->assertSame('5000000', (string) $wallet->available_balance_micro);
    }
}
