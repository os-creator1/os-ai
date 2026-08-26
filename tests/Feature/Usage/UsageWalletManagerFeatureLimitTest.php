<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\FeatureLimitExceedsPlatformSafetyLimitException;
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

    /**
     * RFC-005 Reservation Admission Correction Contract §9 — a genuine,
     * disposable UsageMeter/active rate must exist before reserve() will
     * accept it (mirrors UsageWalletManagerReservationLifecycleTest's own
     * established fixture sequence). $meterKey defaults to $featureKey
     * but may differ, for the Amendment-1 multiple-meter-keys-share-one-
     * feature-key proof below.
     */
    private function activateRate(string $featureKey = 'crm', ?string $meterKey = null, string $retailRateMicro = '1000000'): void
    {
        $meterKey ??= $featureKey;
        $actorId = User::create([
            'first_name' => 'Test', 'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test', 'status' => true,
            'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey, 'feature_key' => $featureKey, 'business_id' => null,
            'currency_id' => $currencyId, 'description' => 'Feature-limit fixture meter.', 'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate($meterKey, $retailRateMicro, '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        app(UsageWalletManager::class)->activateMetering($meterKey, $actorId, 'Fixture.');
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

    public function test_feature_limit_denies_reserve_when_headroom_exhausted(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Exactly one reservation.');

        $first = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($first->granted);

        $second = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertFalse($second->granted);
        $this->assertSame('feature_limit', $second->denialReason);
        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    public function test_feature_limit_allows_candidate_exactly_equal_to_headroom(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Exactly one reservation.');

        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        $this->assertTrue($result->granted, 'A candidate exactly equal to headroom must be allowed, never denied.');
    }

    /**
     * RFC-005 Amendment 1 critical proof — business_feature_usage_limits
     * is keyed by business_id + feature_key, never meter_key. Two
     * distinct meter_keys sharing one feature_key must consume the SAME
     * shared feature limit.
     */
    public function test_feature_limit_aggregates_across_multiple_meter_keys_sharing_one_feature_key(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm', 'crm.meter_one');
        $this->activateRate('crm', 'crm.meter_two');

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Shared across meters.');

        $first = app(UsageWalletManager::class)->reserve($business, 'crm.meter_one', (string) Str::uuid(), '1');
        $this->assertTrue($first->granted);

        $second = app(UsageWalletManager::class)->reserve($business, 'crm.meter_two', (string) Str::uuid(), '1');
        $this->assertFalse($second->granted, 'crm.meter_two must be denied — it shares crm.meter_one\'s own feature_key limit, already fully consumed.');
        $this->assertSame('feature_limit', $second->denialReason);
    }

    public function test_a_different_feature_key_remains_independent(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');
        $this->activateRate('calendar');

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'CRM only.');

        $a = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($a->granted);

        // crm's own limit is now fully consumed, but calendar has no
        // limit configured at all — it must remain entirely unbounded by
        // crm's own consumption.
        $b = app(UsageWalletManager::class)->reserve($business, 'calendar', (string) Str::uuid(), '1');
        $this->assertTrue($b->granted, 'A different feature_key must never be affected by another feature_key\'s own limit/consumption.');
    }

    public function test_no_feature_limit_row_never_denies_that_control(): void
    {
        [$business, ] = $this->businessWithWallet();
        $this->activateRate('crm');

        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '5');

        $this->assertTrue($result->granted, 'With no business_feature_usage_limits row configured, that control must never deny.');
    }

    public function test_released_reservation_reopens_feature_headroom(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Exactly one reservation.');

        $first = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($first->granted);

        app(UsageWalletManager::class)->release($first->reservationId);

        $second = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($second->granted, 'A released reservation must reopen the feature headroom it previously consumed.');
    }

    public function test_expired_reservation_reopens_feature_headroom(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Exactly one reservation.');

        $first = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($first->granted);

        DB::table('business_usage_reservations')->where('id', $first->reservationId)->update(['expires_at' => now()->subMinute()]);
        app(UsageWalletManager::class)->expireStaleReservations();

        $second = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($second->granted, 'An expired-then-released reservation must reopen the feature headroom it previously consumed.');
    }

    public function test_committed_usage_consumes_future_feature_headroom(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Exactly one reservation.');

        $first = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($first->granted);
        app(UsageWalletManager::class)->commit($first->reservationId, '1');

        $second = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertFalse($second->granted, 'Committed usage must consume future headroom exactly as a still-pending reservation would.');
        $this->assertSame('feature_limit', $second->denialReason);
    }

    /**
     * RFC-005 §13's committed-amount formula, reused exactly by §4.B:
     * UsageOverageCharge's committed contribution is
     * (-available_delta_micro) + debt_delta_micro, which always equals
     * the real overage amount regardless of how it split between
     * available balance and debt.
     */
    public function test_overage_charge_consumes_feature_headroom_exactly_per_formula(): void
    {
        [$business, $actorId] = $this->businessWithWallet(availableBalanceMicro: 10_000_000);
        $this->activateRate('crm');
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '3000000', $actorId, 'Room for overage.');

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($reservation->granted);

        // Final quantity 2 against a reservation of 1 produces a real
        // 1,000,000-micro overage on top of the 1,000,000 already
        // reserved — total committed contribution must be 2,000,000.
        app(UsageWalletManager::class)->commit($reservation->reservationId, '2');

        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($result->granted, '2,000,000 committed + 1,000,000 candidate = 3,000,000, exactly at the limit — must be allowed.');

        $overLimit = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertFalse($overLimit->granted, 'One further reservation must now exceed the 3,000,000 limit and be denied.');
        $this->assertSame('feature_limit', $overLimit->denialReason);
    }

    public function test_stale_prior_period_reservation_does_not_pollute_new_period_feature_admission(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Exactly one reservation.');

        $first = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($first->granted);

        // Simulate the reservation having been made in an already-rolled-
        // over prior calendar-month period.
        DB::table('business_usage_reservations')->where('id', $first->reservationId)->update(['period_key' => '2020-01']);

        $second = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertTrue($second->granted, 'A reservation scoped to a prior period must never count toward the current period\'s feature headroom.');
    }

    public function test_idempotent_retry_does_not_consume_feature_headroom_twice(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');
        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Exactly one reservation.');

        $key = (string) Str::uuid();
        $first = app(UsageWalletManager::class)->reserve($business, 'crm', $key, '1');
        $this->assertTrue($first->granted);

        $retry = app(UsageWalletManager::class)->reserve($business, 'crm', $key, '1');
        $this->assertTrue($retry->granted, 'An idempotent retry of an already-successful reservation must return the existing reservation, never a fresh denial.');
        $this->assertSame($first->reservationId, $retry->reservationId);
        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    public function test_feature_limit_tightened_below_already_consumed_usage_clamps_headroom_to_zero(): void
    {
        [$business, $actorId] = $this->businessWithWallet();
        $this->activateRate('crm');

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '5000000', $actorId, 'Generous initially.');
        $consuming = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '5');
        $this->assertTrue($consuming->granted);

        app(UsageWalletManager::class)->setFeatureLimit($business, 'crm', '1000000', $actorId, 'Tightened below already-reserved usage.');

        $positiveCandidate = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');
        $this->assertFalse($positiveCandidate->granted);
        $this->assertSame('feature_limit', $positiveCandidate->denialReason);

        $zeroCandidate = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '0');
        $this->assertTrue($zeroCandidate->granted, 'A zero-amount candidate must remain allowed even when feature headroom has clamped to zero.');
    }
}
