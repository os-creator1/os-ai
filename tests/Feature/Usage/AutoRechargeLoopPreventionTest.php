<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Enums\Usage\UsageLedgerEntryType;
use App\Jobs\Usage\EvaluateBusinessAutoRecharge;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\BillingProfileManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M3 contract §15/§25 item 95 — a successful AutoRecharge ledger
 * entry (positive available_delta_micro) never re-triggers
 * EvaluateBusinessAutoRecharge. The dispatch condition in
 * UsageWalletManager is strictly "< 0" (reserve()'s reservation insert,
 * commit()'s overage-charge insert) — creditFromFunding() itself never
 * dispatches, so the job cannot recursively re-invoke itself from its own
 * successful outcome.
 */
class AutoRechargeLoopPreventionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
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

    public function test_crediting_from_a_confirmed_auto_recharge_does_not_dispatch_another_evaluation(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        app(UsageWalletManager::class)->creditFromFunding(
            (int) $business->id,
            UsageLedgerEntryType::AutoRecharge,
            5_000_000,
            0,
            'loop-prevention-'.uniqid(),
        );

        Queue::assertNotPushed(EvaluateBusinessAutoRecharge::class);
    }

    public function test_crediting_from_a_confirmed_manual_top_up_does_not_dispatch_an_evaluation_either(): void
    {
        Queue::fake();

        $customer = $this->createCustomer();
        $workspace = $this->entitledWorkspace($customer->user);
        $business = app(BusinessRepository::class)->createForCustomerInWorkspace($customer, $workspace, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(BillingProfileManager::class)->changePayer($business, PayerType::Workspace, $customer->user_id, 'Test.');

        app(UsageWalletManager::class)->creditFromFunding(
            (int) $business->id,
            UsageLedgerEntryType::PaidTopUp,
            5_000_000,
            0,
            'loop-prevention-'.uniqid(),
        );

        Queue::assertNotPushed(EvaluateBusinessAutoRecharge::class);
    }
}
