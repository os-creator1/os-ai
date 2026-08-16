<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.A/§6.D — nullable by default; no invented
 * default; prospective only; a value below already-committed spend is
 * explicitly allowed.
 */
class UsageWalletManagerSpendCapTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_set_and_clear_spend_cap(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $actorId = (int) $business->workspace->owner_user_id;

        app(UsageWalletManager::class)->setSpendCap($business, '5000000', $actorId, 'Set cap.');
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id, 'monthly_spend_cap_micro' => 5_000_000]);

        app(UsageWalletManager::class)->setSpendCap($business, null, $actorId, 'Clear cap.');
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id, 'monthly_spend_cap_micro' => null]);
    }

    public function test_setting_the_cap_records_a_transition(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $actorId = (int) $business->workspace->owner_user_id;

        app(UsageWalletManager::class)->setSpendCap($business, '5000000', $actorId, 'Set cap.');

        $this->assertDatabaseHas('business_usage_limit_transitions', [
            'business_id' => $business->id,
            'limit_type' => 'business_spend_cap',
            'from_value_micro' => null,
            'to_value_micro' => 5_000_000,
            'actor_user_id' => $actorId,
        ]);
    }

    public function test_a_cap_below_already_committed_spend_is_allowed_and_does_not_touch_history(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $actorId = (int) $business->workspace->owner_user_id;

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['committed_spend_this_period_micro' => 10_000_000]);

        app(UsageWalletManager::class)->setSpendCap($business, '1000000', $actorId, 'Tighten below committed.');

        $this->assertDatabaseHas('business_usage_wallets', [
            'business_id' => $business->id,
            'monthly_spend_cap_micro' => 1_000_000,
            'committed_spend_this_period_micro' => 10_000_000,
        ]);
    }

    public function test_staff_may_not_change_the_spend_cap(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $staffCustomer = $this->createCustomer();
        \App\Models\WorkspaceMembership::create([
            'workspace_id' => $business->workspace->id, 'user_id' => (int) $staffCustomer->user_id,
            'role' => \App\Enums\Workspace\WorkspaceMembershipRole::Staff,
            'business_access_scope' => \App\Enums\Workspace\WorkspaceBusinessAccessScope::All,
            'is_active' => true,
        ]);

        $this->expectException(UnauthorizedUsageBillingManagementException::class);
        app(UsageWalletManager::class)->setSpendCap($business, '1000000', (int) $staffCustomer->user_id, 'Denied.');
    }
}
