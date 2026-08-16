<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Usage\UsageWalletManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.C — platform-administrator-only. Ships fully
 * functional and directly testable, with zero calling production code
 * path at M2 (mirrors M1's own business_usage_rates precedent).
 */
class UsageWalletManagerSafetyLimitTest extends TestCase
{
    use RefreshDatabase;

    private function createAdmin(): User
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
    }

    private function createNonAdmin(): User
    {
        return User::create([
            'first_name' => 'Regular', 'last_name' => 'User', 'email' => 'user' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
    }

    public function test_platform_administrator_may_set_the_safety_limit(): void
    {
        $admin = $this->createAdmin();

        app(UsageWalletManager::class)->setSafetyLimit('crm', '5000000', (int) $admin->id, 'Ceiling.');

        $this->assertDatabaseHas('platform_feature_usage_safety_limits', ['feature_key' => 'crm', 'max_monthly_limit_micro' => 5_000_000]);
        $this->assertDatabaseHas('business_usage_limit_transitions', [
            'limit_type' => 'platform_safety_limit',
            'feature_key' => 'crm',
            'business_id' => null,
            'actor_user_id' => (int) $admin->id,
        ]);
    }

    public function test_non_administrator_may_not_set_the_safety_limit(): void
    {
        $user = $this->createNonAdmin();

        $this->expectException(UnauthorizedUsageBillingManagementException::class);
        app(UsageWalletManager::class)->setSafetyLimit('crm', '5000000', (int) $user->id, 'Denied.');
    }

    public function test_updating_an_existing_safety_limit_records_the_from_value(): void
    {
        $admin = $this->createAdmin();

        app(UsageWalletManager::class)->setSafetyLimit('crm', '5000000', (int) $admin->id, 'Initial.');
        app(UsageWalletManager::class)->setSafetyLimit('crm', '3000000', (int) $admin->id, 'Lowered.');

        $this->assertDatabaseHas('platform_feature_usage_safety_limits', ['feature_key' => 'crm', 'max_monthly_limit_micro' => 3_000_000]);
        $this->assertDatabaseHas('business_usage_limit_transitions', [
            'limit_type' => 'platform_safety_limit',
            'feature_key' => 'crm',
            'from_value_micro' => 5_000_000,
            'to_value_micro' => 3_000_000,
        ]);
    }
}
