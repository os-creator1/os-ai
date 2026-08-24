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
 * RFC-005 Milestone 5 §6.2/§13 item 29 — the manual ambiguous-reservation
 * resolution command: all six safety checks (admin-only, reservation
 * exists, currently Pending, minimum age, explicit commit/release
 * outcome, explicit reason).
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
    private function createPendingReservation(int $currencyId, int $actorId, ?\Illuminate\Support\Carbon $reservedAt = null): array
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

        $result = $manager->reserve($business, $meterKey, 'idem-' . uniqid(), '1');

        if ($reservedAt !== null) {
            DB::table('business_usage_reservations')->where('id', $result->reservationId)->update(['reserved_at' => $reservedAt]);
        }

        return ['business' => $business, 'reservation_id' => $result->reservationId];
    }

    public function test_admin_only_resolution_rejects_non_admin_actor(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId, now()->subMinutes(20));
        $nonAdminId = $this->createNonAdminUserId();

        $this->artisan('usage:resolve-ambiguous-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'commit', '--actor-user-id' => $nonAdminId, '--reason' => 'Test.',
        ])->assertFailed();

        $this->assertSame(
            UsageReservationStatus::Pending->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_resolution_requires_explicit_commit_or_release_outcome(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId, now()->subMinutes(20));

        $this->artisan('usage:resolve-ambiguous-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'maybe', '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->assertFailed();
    }

    public function test_resolution_requires_a_non_empty_reason(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId, now()->subMinutes(20));

        $this->artisan('usage:resolve-ambiguous-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'commit', '--actor-user-id' => $actorId,
        ])->assertFailed();
    }

    public function test_resolution_refuses_a_reservation_younger_than_the_minimum_age(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId, now());

        $this->artisan('usage:resolve-ambiguous-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'commit', '--actor-user-id' => $actorId, '--reason' => 'Too soon.',
        ])->assertFailed();

        $this->assertSame(
            UsageReservationStatus::Pending->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_resolution_refuses_an_already_terminal_reservation(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId, now()->subMinutes(20));

        app(UsageWalletManager::class)->commit($fixture['reservation_id']);

        $this->artisan('usage:resolve-ambiguous-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'release', '--actor-user-id' => $actorId, '--reason' => 'Test.',
        ])->assertFailed();
    }

    public function test_commit_outcome_commits_the_reservation(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId, now()->subMinutes(20));

        $this->artisan('usage:resolve-ambiguous-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'commit', '--actor-user-id' => $actorId, '--reason' => 'Confirmed delivered upstream.',
        ])->expectsConfirmation('Apply this resolution?', 'yes')->assertSuccessful();

        $this->assertSame(
            UsageReservationStatus::Committed->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }

    public function test_release_outcome_releases_the_reservation(): void
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createAdminUserId();
        $fixture = $this->createPendingReservation($currencyId, $actorId, now()->subMinutes(20));

        $this->artisan('usage:resolve-ambiguous-reservation', [
            'reservation-id' => $fixture['reservation_id'],
            '--outcome' => 'release', '--actor-user-id' => $actorId, '--reason' => 'Confirmed never sent.',
        ])->expectsConfirmation('Apply this resolution?', 'yes')->assertSuccessful();

        $this->assertSame(
            UsageReservationStatus::Released->value,
            DB::table('business_usage_reservations')->where('id', $fixture['reservation_id'])->value('status'),
        );
    }
}
