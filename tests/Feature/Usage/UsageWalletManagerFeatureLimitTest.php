<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\FeatureLimitExceedsPlatformSafetyLimitException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.B — settable ahead of metering activation;
 * bounded above by any configured platform safety limit; null clears.
 */
class UsageWalletManagerFeatureLimitTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_set_and_clear_feature_limit(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $actorId = (int) $business->workspace->owner_user_id;

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '2000000', $actorId, 'Set.');
        $this->assertDatabaseHas('business_feature_usage_limits', ['business_id' => $business->id, 'feature_key' => 'crm', 'monthly_limit_micro' => 2_000_000]);

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', null, $actorId, 'Clear.');
        $this->assertDatabaseMissing('business_feature_usage_limits', ['business_id' => $business->id, 'feature_key' => 'crm']);
    }

    public function test_settable_ahead_of_metering_activation(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $actorId = (int) $business->workspace->owner_user_id;

        $this->assertFalse(\App\Models\PlatformFeatureUsageClassification::query()->where('feature_key', 'crm')->value('is_metered'));

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '2000000', $actorId, 'Pre-configured.');

        $this->assertDatabaseHas('business_feature_usage_limits', ['business_id' => $business->id, 'feature_key' => 'crm']);
    }

    public function test_a_limit_above_the_configured_safety_ceiling_is_rejected(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $actorId = (int) $business->workspace->owner_user_id;
        $admin = \App\Models\User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(UsageWalletManager::class)->setSafetyLimit('crm', '1000000', (int) $admin->id, 'Ceiling.');

        $this->expectException(FeatureLimitExceedsPlatformSafetyLimitException::class);
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '2000000', $actorId, 'Too high.');
    }

    public function test_a_limit_at_or_below_the_configured_safety_ceiling_is_accepted(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $actorId = (int) $business->workspace->owner_user_id;
        $admin = \App\Models\User::create([
            'first_name' => 'Admin', 'last_name' => 'User', 'email' => 'admin' . uniqid() . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        app(UsageWalletManager::class)->setSafetyLimit('crm', '2000000', (int) $admin->id, 'Ceiling.');
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '2000000', $actorId, 'At ceiling.');

        $this->assertDatabaseHas('business_feature_usage_limits', ['business_id' => $business->id, 'feature_key' => 'crm', 'monthly_limit_micro' => 2_000_000]);
    }
}
