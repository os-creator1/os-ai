<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\Contracts\PaymentProviderGateway;
use App\Library\Usage\FakePaymentProviderGateway;
use App\Library\Usage\PaymentInstrumentManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use App\Repositories\Contracts\PaymentProviderCustomerRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §9/§25 item 78 — Business-owned vs Workspace-owned
 * provider-customer isolation, no cross-tenant reuse, and the
 * detach-then-recreate case.
 */
class ProviderCustomerOwnershipTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        app()->instance(PaymentProviderGateway::class, new FakePaymentProviderGateway());
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

    public function test_workspace_paying_businesses_share_one_workspace_scoped_provider_customer(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $businessA = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'A']));
        $businessB = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'B']));
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);
        app(BillingProfileManager::class)->changePayer($businessA, PayerType::Workspace, $customer->user_id, 'Test.');
        app(BillingProfileManager::class)->changePayer($businessB, PayerType::Workspace, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $customerA = $instrumentManager->resolveProviderCustomer($businessA, $customer->user_id);
        $customerB = $instrumentManager->resolveProviderCustomer($businessB, $customer->user_id);

        $this->assertSame($customerA->id, $customerB->id);
    }

    public function test_business_paying_businesses_never_share_a_provider_customer(): void
    {
        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $businessA = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'A']));
        $businessB = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes(['name' => 'B']));
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);
        app(BillingProfileManager::class)->changePayer($businessA, PayerType::Business, $customer->user_id, 'Test.');
        app(BillingProfileManager::class)->changePayer($businessB, PayerType::Business, $customer->user_id, 'Test.');

        $instrumentManager = app(PaymentInstrumentManager::class);
        $customerA = $instrumentManager->resolveProviderCustomer($businessA, $customer->user_id);
        $customerB = $instrumentManager->resolveProviderCustomer($businessB, $customer->user_id);

        $this->assertNotSame($customerA->id, $customerB->id);
    }

    public function test_unique_provider_and_active_business_id_rejects_a_second_active_row(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        DB::table('payment_provider_customers')->insert([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_first',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('payment_provider_customers')->insert([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_second',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_detach_then_recreate_allows_a_new_active_row(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $firstId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_first',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_provider_customers')->where('id', $firstId)->update(['status' => 'detached']);

        $repository = app(PaymentProviderCustomerRepository::class);
        $this->assertNull($repository->findActiveByBusinessId((int) $business->id));

        DB::table('payment_provider_customers')->insert([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_second',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $active = $repository->findActiveByBusinessId((int) $business->id);
        $this->assertSame('cus_second', $active->provider_customer_id);
    }
}
