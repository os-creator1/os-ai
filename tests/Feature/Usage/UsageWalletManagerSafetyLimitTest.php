<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\UnauthorizedUsageBillingManagementException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §6.C — platform-administrator-only. Ships fully
 * functional and directly testable, with zero calling production code
 * path at M2 (mirrors M1's own business_usage_rates precedent).
 */
class UsageWalletManagerSafetyLimitTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

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

    /**
     * RFC-005 Reservation Admission Correction Contract §4.C/§9 — a
     * genuine, disposable UsageMeter/active rate must exist before
     * reserve() will accept it (mirrors UsageWalletManagerSpendCapTest's
     * own established fixture sequence).
     */
    private function activateRate(string $featureKey = 'crm', string $retailRateMicro = '1000000'): void
    {
        $actorId = User::create([
            'first_name' => 'Test', 'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test', 'status' => true,
            'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $featureKey, 'feature_key' => $featureKey, 'business_id' => null,
            'currency_id' => $currencyId, 'description' => 'Safety-limit fixture meter.', 'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate($featureKey, $retailRateMicro, '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        app(UsageWalletManager::class)->activateMetering($featureKey, $actorId, 'Fixture.');
    }

    /**
     * @return array{0: \App\Models\Business, 1: int}
     */
    private function businessWithWallet(int $availableBalanceMicro = 10_000_000): array
    {
        Currency::query()->where('code', 'USD')->exists() || Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => $availableBalanceMicro]);

        return [$business, (int) $business->workspace->owner_user_id];
    }

    public function test_safety_limit_denies_reserve_when_no_business_feature_limit_configured(): void
    {
        [$business, ] = $this->businessWithWallet();
        $this->activateRate('crm');
        $admin = $this->createAdmin();

        app(UsageWalletManager::class)->setSafetyLimit('crm', '1000000', (int) $admin->id, 'Platform-wide ceiling, no per-Business limit set.');

        $first = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($first->granted);

        $second = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertFalse($second->granted, 'The platform safety limit must be able to independently deny even with no business_feature_usage_limits row configured at all.');
        $this->assertSame('platform_safety_limit', $second->denialReason);
    }

    public function test_safety_limit_allows_candidate_exactly_equal_to_headroom(): void
    {
        [$business, ] = $this->businessWithWallet();
        $this->activateRate('crm');
        $admin = $this->createAdmin();

        app(UsageWalletManager::class)->setSafetyLimit('crm', '1000000', (int) $admin->id, 'Exactly one reservation.');

        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        $this->assertTrue($result->granted, 'A candidate exactly equal to the platform safety limit\'s own headroom must be allowed, never denied.');
    }

    /**
     * RFC-005 Reservation Admission Correction Contract §6 — the exact
     * denial precedence order: feature_limit before business_spend_cap
     * before platform_safety_limit before insufficient_balance. Each
     * stage configures every remaining control tight enough to also
     * deny, then loosens only the one that has already been proven to
     * fire, isolating the next control in the order. Because a denied
     * reserve() creates no reservation and consumes no headroom, the
     * same business/wallet/feature can be reused unmodified across every
     * stage.
     */
    public function test_denial_precedence_feature_limit_before_business_spend_cap_before_platform_safety_limit_before_insufficient_balance(): void
    {
        [$business, $actorId] = $this->businessWithWallet(availableBalanceMicro: 500_000);
        $this->activateRate('crm');
        $admin = $this->createAdmin();

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Stage 1: tight.');
        app(UsageWalletManager::class)->setSpendCap($business, '1000000', $actorId, 'Stage 1: tight.');
        app(UsageWalletManager::class)->setSafetyLimit('crm', '1000000', (int) $admin->id, 'Stage 1: tight.');

        // Candidate quantity 2 costs 2,000,000 — over every one of the
        // four controls (feature_limit, business_spend_cap,
        // platform_safety_limit, and the 500,000 available balance).
        $stage1 = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $this->assertFalse($stage1->granted);
        $this->assertSame('feature_limit', $stage1->denialReason);

        // setFeatureLimit() itself refuses to raise a Business's feature
        // limit above the platform safety ceiling, so the ceiling must be
        // raised first, then re-tightened afterward for the later stages.
        app(UsageWalletManager::class)->setSafetyLimit('crm', '10000000', (int) $admin->id, 'Stage 2: temporarily loosened to permit the feature-limit raise.');
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '10000000', $actorId, 'Stage 2: loosened, no longer decisive.');
        app(UsageWalletManager::class)->setSafetyLimit('crm', '1000000', (int) $admin->id, 'Stage 2: re-tightened for the remaining stages.');
        $stage2 = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $this->assertFalse($stage2->granted);
        $this->assertSame('business_spend_cap', $stage2->denialReason);

        app(UsageWalletManager::class)->setSpendCap($business, '10000000', $actorId, 'Stage 3: loosened, no longer decisive.');
        $stage3 = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $this->assertFalse($stage3->granted);
        $this->assertSame('platform_safety_limit', $stage3->denialReason);

        app(UsageWalletManager::class)->setSafetyLimit('crm', '10000000', (int) $admin->id, 'Stage 4: loosened, no longer decisive.');
        $stage4 = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $this->assertFalse($stage4->granted);
        $this->assertSame('insufficient_balance', $stage4->denialReason);

        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $business->id)->count(), 'None of the four denied attempts may have created any reservation row.');
    }
}
