<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\PaymentMethodResult;
use App\Library\Usage\SetupIntentResult;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessPaymentInstrumentRepository;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §10/§25 item 79 — attach validation (provider
 * customer match), detach never a hard delete, one-default-per-provider-
 * customer locking.
 */
class PaymentInstrumentAttachDetachTest extends TestCase
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

    private function setUpBusinessWithPayer(): array
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        return [$customer, $business];
    }

    /**
     * The fake gateway's own default retrievePaymentMethod() fallback is
     * intentionally customer-agnostic (owned by "cus_fake_unknown") — a
     * genuine attach flow always registers the real provider customer id
     * first, exactly as a real Stripe SetupIntent's confirmed PaymentMethod
     * would already carry the customer it was actually attached to.
     */
    private function registerMatchingPaymentMethod(Business $business, SetupIntentResult $setupIntent, ?PaymentMethodResult $overrides = null): void
    {
        $providerCustomer = app(PaymentProviderCustomerRepository::class)->findActiveByWorkspaceId((int) $business->workspace_id)
            ?? app(PaymentProviderCustomerRepository::class)->findActiveByBusinessId((int) $business->id);

        $providerPaymentMethodId = 'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_'));

        $this->gateway->registerPaymentMethod($overrides ?? new PaymentMethodResult(
            $providerPaymentMethodId,
            $providerCustomer->provider_customer_id,
            'card',
            'visa',
            '4242',
            12,
            2030,
        ));
    }

    public function test_confirm_setup_intent_attaches_the_instrument_and_marks_it_default(): void
    {
        [$customer, $business] = $this->setUpBusinessWithPayer();
        $manager = app(PaymentInstrumentManager::class);

        $setupIntent = $manager->createSetupIntent($business, $customer->user_id);
        $this->registerMatchingPaymentMethod($business, $setupIntent);
        $instrument = $manager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        $this->assertNotNull($instrument);
        $this->assertTrue((bool) $instrument->is_default);
        $this->assertSame('active', $instrument->status->value);
    }

    public function test_repeat_confirmation_of_the_same_setup_intent_is_a_no_op(): void
    {
        [$customer, $business] = $this->setUpBusinessWithPayer();
        $manager = app(PaymentInstrumentManager::class);

        $setupIntent = $manager->createSetupIntent($business, $customer->user_id);
        $this->registerMatchingPaymentMethod($business, $setupIntent);
        $first = $manager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);
        $second = $manager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, app(BusinessPaymentInstrumentRepository::class)->activeForProviderCustomer((int) $first->provider_customer_id)->count());
    }

    public function test_attachment_rejects_a_payment_method_belonging_to_a_different_provider_customer(): void
    {
        [$customer, $business] = $this->setUpBusinessWithPayer();
        $manager = app(PaymentInstrumentManager::class);

        $setupIntent = $manager->createSetupIntent($business, $customer->user_id);

        // Deliberately mismatched provider customer id.
        $this->registerMatchingPaymentMethod($business, $setupIntent, new PaymentMethodResult(
            'pm_fake_'.substr($setupIntent->providerSetupIntentId, strlen('seti_fake_')),
            'cus_unrelated_customer',
            'card',
            'visa',
            '4242',
            12,
            2030,
        ));

        $instrument = $manager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);

        $this->assertNull($instrument);
    }

    public function test_detach_never_hard_deletes_the_row(): void
    {
        [$customer, $business] = $this->setUpBusinessWithPayer();
        $manager = app(PaymentInstrumentManager::class);

        $setupIntent = $manager->createSetupIntent($business, $customer->user_id);
        $this->registerMatchingPaymentMethod($business, $setupIntent);
        $instrument = $manager->confirmSetupIntentAndAttach($business, $customer->user_id, $setupIntent->providerSetupIntentId);
        $this->assertNotNull($instrument);

        $manager->detachInstrument($business, $customer->user_id, $instrument);

        $fresh = app(BusinessPaymentInstrumentRepository::class)->findById((int) $instrument->id);
        $this->assertNotNull($fresh);
        $this->assertSame('detached', $fresh->status->value);
        $this->assertNotNull($fresh->detached_at);
    }

    public function test_only_one_default_instrument_per_provider_customer(): void
    {
        [$customer, $business] = $this->setUpBusinessWithPayer();
        $manager = app(PaymentInstrumentManager::class);

        $firstSetup = $manager->createSetupIntent($business, $customer->user_id);
        $this->registerMatchingPaymentMethod($business, $firstSetup);
        $first = $manager->confirmSetupIntentAndAttach($business, $customer->user_id, $firstSetup->providerSetupIntentId);
        $this->assertNotNull($first);

        $secondSetup = $manager->createSetupIntent($business, $customer->user_id);
        $this->registerMatchingPaymentMethod($business, $secondSetup, new PaymentMethodResult(
            'pm_fake_'.substr($secondSetup->providerSetupIntentId, strlen('seti_fake_')),
            $first->providerCustomer->provider_customer_id,
            'card', 'mastercard', '4444', 6, 2028,
        ));
        $second = $manager->confirmSetupIntentAndAttach($business, $customer->user_id, $secondSetup->providerSetupIntentId);
        $this->assertNotNull($second);

        $manager->setDefaultInstrument($business, $customer->user_id, $second);

        $repository = app(BusinessPaymentInstrumentRepository::class);
        $freshFirst = $repository->findById((int) $first->id);
        $freshSecond = $repository->findById((int) $second->id);

        $this->assertFalse((bool) $freshFirst->is_default);
        $this->assertTrue((bool) $freshSecond->is_default);
    }
}
