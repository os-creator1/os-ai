<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\PlatformFeature;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Milestone 5 §9.1/§3.14/§13 item 28 — the operator activation
 * command: meter provisioning (idempotent on exact match, hard-fail on
 * conflict), admin-only authority, rate activation, and the required
 * invariant that platform_feature_usage_classifications is never written.
 */
class ActivateConversationsUsageRateCommandTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function usdCurrencyId(): int
    {
        $existing = Currency::query()->where('code', 'M5T')->first();

        return $existing?->id ?? Currency::create(['name' => 'M5 Test Dollar', 'code' => 'M5T', 'format' => '$', 'status' => true])->id;
    }

    private function createAdminUserId(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'Actor',
            'email' => 'admin' . uniqid() . '@example.test', 'status' => true,
            'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function createNonAdminUserId(): int
    {
        return User::create([
            'first_name' => 'Regular', 'last_name' => 'Actor',
            'email' => 'regular' . uniqid() . '@example.test', 'status' => true,
            'is_admin' => false, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    private function setPilotBusinessConfig(int $businessId): void
    {
        config(['usage_billing.conversations_metering.pilot_business_id' => $businessId]);
    }

    private function createPilotBusinessWithWallet(int $currencyId): int
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'M5T']));

        $now = now();
        DB::table('business_usage_wallets')->insert([
            'business_id' => $business->id, 'currency_id' => $currencyId,
            'available_balance_micro' => 0, 'reserved_balance_micro' => 0, 'debt_balance_micro' => 0,
            'spend_period_key' => $now->format('Y-m'), 'spend_period_start_utc' => $now, 'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => $now->format('Y-m'), 'recharge_period_start_utc' => $now, 'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        return $business->id;
    }

    public function test_admin_only_activation_rejects_non_admin_actor(): void
    {
        $currencyId = $this->usdCurrencyId();
        $businessId = $this->createPilotBusinessWithWallet($currencyId);
        $this->setPilotBusinessConfig($businessId);
        $actorId = $this->createNonAdminUserId();

        $this->artisan('usage:activate-conversations-rate', [
            'retail-rate-micro' => '1000000', 'provider-cost-micro' => '500000',
            'unit-label' => 'per message', 'currency-code' => 'USD',
            '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->assertFailed();

        $this->assertSame(0, DB::table('usage_meters')->count());
    }

    public function test_activation_creates_pilot_meter_and_activates_rate(): void
    {
        $currencyId = $this->usdCurrencyId();
        $businessId = $this->createPilotBusinessWithWallet($currencyId);
        $this->setPilotBusinessConfig($businessId);
        $actorId = $this->createAdminUserId();

        $this->artisan('usage:activate-conversations-rate', [
            'retail-rate-micro' => '1000000', 'provider-cost-micro' => '500000',
            'unit-label' => 'per message', 'currency-code' => 'USD',
            '--actor-user-id' => $actorId, '--reason' => 'Pilot activation.',
        ])->expectsConfirmation('Activate this rate for the pilot meter?', 'yes')
          ->assertSuccessful();

        $meterKey = 'conversations.pilot.' . $businessId;
        $meter = app(UsageMeterRepository::class)->findByMeterKey($meterKey);

        $this->assertNotNull($meter);
        $this->assertSame(PlatformFeature::Conversations->value, $meter->feature_key);
        $this->assertSame($businessId, $meter->business_id);
        $this->assertSame($currencyId, $meter->currency_id);
        $this->assertTrue($meter->is_metered);
        $this->assertNotNull($meter->active_rate_id);

        $this->assertSame(1, DB::table('usage_meter_transitions')->where('meter_key', $meterKey)->count());
    }

    public function test_platform_feature_usage_classifications_is_never_written(): void
    {
        $currencyId = $this->usdCurrencyId();
        $businessId = $this->createPilotBusinessWithWallet($currencyId);
        $this->setPilotBusinessConfig($businessId);
        $actorId = $this->createAdminUserId();

        $before = DB::table('platform_feature_usage_classifications')
            ->where('feature_key', PlatformFeature::Conversations->value)
            ->first();

        $this->artisan('usage:activate-conversations-rate', [
            'retail-rate-micro' => '1000000', 'provider-cost-micro' => '500000',
            'unit-label' => 'per message', 'currency-code' => 'USD',
            '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->expectsConfirmation('Activate this rate for the pilot meter?', 'yes')
          ->assertSuccessful();

        $after = DB::table('platform_feature_usage_classifications')
            ->where('feature_key', PlatformFeature::Conversations->value)
            ->first();

        $this->assertFalse((bool) $after->is_metered);
        $this->assertNull($after->active_rate_id);
        $this->assertEquals($before, $after);
    }

    public function test_repeat_activation_against_matching_meter_is_idempotent(): void
    {
        $currencyId = $this->usdCurrencyId();
        $businessId = $this->createPilotBusinessWithWallet($currencyId);
        $this->setPilotBusinessConfig($businessId);
        $actorId = $this->createAdminUserId();

        $this->artisan('usage:activate-conversations-rate', [
            'retail-rate-micro' => '1000000', 'provider-cost-micro' => '500000',
            'unit-label' => 'per message', 'currency-code' => 'USD',
            '--actor-user-id' => $actorId, '--reason' => 'First.',
        ])->expectsConfirmation('Activate this rate for the pilot meter?', 'yes')->assertSuccessful();

        $this->assertSame(1, DB::table('usage_meters')->count());

        $this->artisan('usage:activate-conversations-rate', [
            'retail-rate-micro' => '2000000', 'provider-cost-micro' => '600000',
            'unit-label' => 'per message', 'currency-code' => 'USD',
            '--actor-user-id' => $actorId, '--reason' => 'Rotation.',
        ])->expectsConfirmation('Activate this rate for the pilot meter?', 'yes')->assertSuccessful();

        $this->assertSame(1, DB::table('usage_meters')->count());
        $this->assertSame(2, DB::table('business_usage_rates')->where('meter_key', 'conversations.pilot.' . $businessId)->count());
    }

    public function test_conflicting_existing_meter_identity_hard_fails(): void
    {
        $currencyId = $this->usdCurrencyId();
        $businessId = $this->createPilotBusinessWithWallet($currencyId);
        $this->setPilotBusinessConfig($businessId);
        $actorId = $this->createAdminUserId();

        $otherCurrencyId = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€', 'status' => true])->id;
        $meterKey = 'conversations.pilot.' . $businessId;
        $now = now();

        DB::table('usage_meters')->insert([
            'meter_key' => $meterKey, 'feature_key' => 'conversations', 'business_id' => $businessId,
            'currency_id' => $otherCurrencyId, 'is_metered' => false, 'active_rate_id' => null,
            'description' => 'Conflicting pre-existing meter.', 'updated_by_user_id' => $actorId,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->artisan('usage:activate-conversations-rate', [
            'retail-rate-micro' => '1000000', 'provider-cost-micro' => '500000',
            'unit-label' => 'per message', 'currency-code' => 'USD',
            '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->assertFailed();

        $this->assertSame(0, DB::table('business_usage_rates')->count());
    }
}
