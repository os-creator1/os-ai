<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\BillingStatusTransitionSource;
use App\Enums\Usage\WalletBillingStatus;
use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.G — platform-administrator-only for
 * admin_action; ships fully functional and directly testable, with zero
 * calling production code path at M2 (no admin HTTP route, no webhook
 * processing yet).
 */
class UsageWalletBillingStatusTransitionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_platform_administrator_may_set_billing_status(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $admin = User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(UsageWalletManager::class)->setBillingStatus($business, WalletBillingStatus::Suspended, BillingStatusTransitionSource::AdminAction, (int) $admin->id, 'Fraud review.');

        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id, 'billing_status' => 'suspended']);
        $this->assertDatabaseHas('business_usage_wallet_billing_status_transitions', [
            'business_id' => $business->id, 'from_status' => 'active', 'to_status' => 'suspended',
            'source' => 'admin_action', 'actor_user_id' => (int) $admin->id, 'reason' => 'Fraud review.',
        ]);
    }

    public function test_non_administrator_may_not_set_billing_status_via_admin_action(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $this->expectException(UnauthorizedUsageBillingManagementException::class);
        app(UsageWalletManager::class)->setBillingStatus($business, WalletBillingStatus::Suspended, BillingStatusTransitionSource::AdminAction, (int) $business->customer_id, 'Denied.');
    }

    public function test_dispute_webhook_source_accepts_a_null_actor(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        app(UsageWalletManager::class)->setBillingStatus($business, WalletBillingStatus::Suspended, BillingStatusTransitionSource::DisputeWebhook, null, 'Chargeback.');

        $this->assertDatabaseHas('business_usage_wallet_billing_status_transitions', ['business_id' => $business->id, 'source' => 'dispute_webhook', 'actor_user_id' => null]);
    }

    public function test_no_m2_production_code_path_calls_set_billing_status(): void
    {
        $controllerSource = file_get_contents(app_path('Http/Controllers/Customer/Business/UsageBillingController.php'));
        $listenerSource = file_get_contents(app_path('Listeners/Usage/InitializeBusinessUsageProfile.php'));

        $this->assertStringNotContainsString('setBillingStatus', $controllerSource);
        $this->assertStringNotContainsString('setBillingStatus', $listenerSource);
    }
}
