<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\UsageReservationStatus;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Milestone 5 §6.2 — the manual-resolution command
 * (usage:resolve-reservation), all six locked safety checks, in order:
 * (1) reservation exists, (2) status exactly Pending, (3) feature_key
 * exactly conversations, (4) actor is a platform administrator, (5)
 * reason is non-empty, (6) reservation's business_id equals the
 * currently-configured pilot_business_id. --outcome is restricted to
 * exactly 'sent' (commit) or 'not-sent' (release).
 */
class ResolveAmbiguousUsageReservationCommandTest extends TestCase
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

    /**
     * @return array{business: \App\Models\Business, reservation_id: int}
     */
    private function createPendingReservation(int $currencyId, int $actorId, bool $setPilotConfig = true): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'M5T']));

        $now = now();
        DB::table('business_usage_wallets')->insert([
            'business_id' => $business->id, 'currency_id' => $currencyId,
            'available_balance_micro' => 5_000_000, 'reserved_balance_micro' => 0, 'debt_balance_micro' => 0,
            'spend_period_key' => $now->format('Y-m'), 'spend_period_start_utc' => $now, 'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => $now->format('Y-m'), 'recharge_period_start_utc' => $now, 'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $meterKey = 'conversations.pilot.' . $business->id;
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey, 'feature_key' => 'conversations', 'business_id' => $business->id,
            'currency_id' => $currencyId, 'description' => 'Test pilot meter.', 'updated_by_user_id' => $actorId,
        ]);

        $manager = app(UsageWalletManager::class);
        $manager->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($meterKey, $actorId, 'Fixture.');

        if ($setPilotConfig) {
            config(['usage_billing.conversations_metering.pilot_business_id' => $business->id]);
        }

        $result = $manager->reserve($business, $meterKey, 'idem-' . uniqid(), '1');

        return ['business' => $business, 'reservation_id' => $result->reservationId];
    }

    public function test_check_1_nonexistent_reservation_is_rejected(): void
    {
        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => 999999,
            '--outcome' => 'sent', '--actor-user-id' => $this->createAdminUserId(), '--reason' => 'Test.',
        ])->assertFailed();
    }

    public function test_check_2_non_pending_reservation_is_rejected(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId);

        app(UsageWalletManager::class)->commit($fixture['reservation_id']);

        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'not-sent', '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->assertFailed();

        $this->assertSame(
            UsageReservationStatus::Committed->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_check_3_non_conversations_reservation_is_rejected(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId);

        DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->update(['feature_key' => 'crm']);

        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'sent', '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->assertFailed();

        $this->assertSame(
            UsageReservationStatus::Pending->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_check_4_non_admin_actor_is_rejected(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId);
        $nonAdminId = $this->createNonAdminUserId();

        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'sent', '--actor-user-id' => $nonAdminId, '--reason' => 'Test.',
        ])->assertFailed();

        $this->assertSame(
            UsageReservationStatus::Pending->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_check_5_empty_reason_is_rejected(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId);

        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'sent', '--actor-user-id' => $actorId,
        ])->assertFailed();

        $this->assertSame(
            UsageReservationStatus::Pending->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_check_6_different_business_than_currently_configured_pilot_is_rejected(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId);

        // Re-point the pilot config at a different Business after the
        // reservation was created — the reservation's own persisted
        // business_id no longer matches the currently-configured pilot.
        config(['usage_billing.conversations_metering.pilot_business_id' => 999999]);

        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'sent', '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->assertFailed();

        $this->assertSame(
            UsageReservationStatus::Pending->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_invalid_outcome_value_is_rejected(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId);

        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'maybe', '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->assertFailed();

        $this->assertSame(
            UsageReservationStatus::Pending->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_sent_outcome_commits_the_reservation(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId);

        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'sent', '--actor-user-id' => $actorId, '--reason' => 'Confirmed delivered upstream.',
        ])->expectsConfirmation('Apply this resolution?', 'yes')->assertSuccessful();

        $this->assertSame(
            UsageReservationStatus::Committed->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_not_sent_outcome_releases_the_reservation(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId);

        $this->artisan('usage:resolve-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'not-sent', '--actor-user-id' => $actorId, '--reason' => 'Confirmed never sent.',
        ])->expectsConfirmation('Apply this resolution?', 'yes')->assertSuccessful();

        $this->assertSame(
            UsageReservationStatus::Released->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }
}
