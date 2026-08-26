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

    /**
     * RFC-005 Reservation Admission Correction Contract §4.A/§9 — a
     * genuine, disposable UsageMeter/active rate must exist before
     * reserve() will accept the feature key (mirrors
     * UsageWalletManagerReservationLifecycleTest's own established
     * fixture sequence).
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
            'currency_id' => $currencyId, 'description' => 'Spend-cap fixture meter.', 'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate($featureKey, $retailRateMicro, '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        app(UsageWalletManager::class)->activateMetering($featureKey, $actorId, 'Fixture.');
    }

    public function test_business_spend_cap_denies_reserve_when_headroom_exhausted(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 10_000_000]);
        $actorId = (int) $business->workspace->owner_user_id;

        app(UsageWalletManager::class)->setSpendCap($business, '1000000', $actorId, 'Exactly one reservation.');

        $first = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($first->granted);

        $second = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertFalse($second->granted);
        $this->assertSame('business_spend_cap', $second->denialReason);
        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    public function test_business_spend_cap_allows_candidate_exactly_equal_to_headroom(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 10_000_000]);
        $actorId = (int) $business->workspace->owner_user_id;

        app(UsageWalletManager::class)->setSpendCap($business, '1000000', $actorId, 'Exactly one reservation.');

        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        $this->assertTrue($result->granted, 'A candidate exactly equal to headroom must be allowed, never denied.');
    }

    /**
     * RFC-005 Reservation Admission Correction Contract §11 — a candidate
     * exactly one micro-unit above headroom must be denied (the exact
     * boundary complement of the equality-accepted proof above).
     */
    public function test_business_spend_cap_denies_candidate_one_micro_above_headroom(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate(retailRateMicro: '1000001');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 10_000_000]);
        $actorId = (int) $business->workspace->owner_user_id;

        app(UsageWalletManager::class)->setSpendCap($business, '1000000', $actorId, 'Headroom one micro below the candidate cost.');

        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        $this->assertFalse($result->granted, 'A candidate exactly one micro-unit above headroom must be denied.');
        $this->assertSame('business_spend_cap', $result->denialReason);
    }

    /**
     * RFC-005 Reservation Admission Correction Contract §5 — the exact
     * contradiction the merged contract itself was corrected to resolve:
     * a cap tightened below already-consumed spend clamps headroom to
     * zero (never negative), so a positive-amount candidate is denied
     * while a zero-amount candidate remains allowed, and the historical
     * committed_spend_this_period_micro counter is never touched.
     */
    public function test_business_spend_cap_tightened_below_already_committed_spend_clamps_headroom_to_zero(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $business->loadMissing('workspace');
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update([
            'available_balance_micro' => 10_000_000,
            'committed_spend_this_period_micro' => 10_000_000,
        ]);
        $actorId = (int) $business->workspace->owner_user_id;

        app(UsageWalletManager::class)->setSpendCap($business, '1000000', $actorId, 'Tighten below committed.');

        $positiveCandidate = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertFalse($positiveCandidate->granted);
        $this->assertSame('business_spend_cap', $positiveCandidate->denialReason);

        $zeroCandidate = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '0');
        $this->assertTrue($zeroCandidate->granted, 'A zero-amount candidate must remain allowed even when headroom has clamped to zero.');

        $this->assertDatabaseHas('business_usage_wallets', [
            'business_id' => $business->id,
            'committed_spend_this_period_micro' => 10_000_000,
        ]);
    }
}
