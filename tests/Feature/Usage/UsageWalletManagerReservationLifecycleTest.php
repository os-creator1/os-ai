<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\InvalidReservationStateTransitionException;
use App\Exceptions\Usage\NoActiveRateForFeatureException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §14 — reserve()->commit() (exact-match,
 * under-reservation, overage) and reserve()->release(), each verified
 * against a directly-seeded wallet balance (M1 contract §5.7, since no M1
 * code path ever credits a wallet); expire() via a past-due reservation;
 * idempotency; invalid-transition guards; missing-rate guard.
 */
class UsageWalletManagerReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function business(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    private function activateRate(string $featureKey = 'crm', string $retailRateMicro = '1000000'): void
    {
        app(UsageWalletManager::class)->setActiveRate(
            $featureKey,
            $retailRateMicro,
            '500000',
            'per message',
            Currency::query()->first()->id,
            1,
            'Test rate activation.',
        );
    }

    private function seedBalance(int $businessId, int $availableMicro): void
    {
        DB::table('business_usage_wallets')->where('business_id', $businessId)
            ->update(['available_balance_micro' => $availableMicro]);
    }

    public function test_reserve_denies_when_no_active_rate(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $this->expectException(NoActiveRateForFeatureException::class);
        app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid());
    }

    public function test_reserve_denies_insufficient_balance(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();

        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        $this->assertFalse($result->granted);
        $this->assertSame('insufficient_balance', $result->denialReason);
        $this->assertDatabaseCount('business_usage_reservations', 0);
        $this->assertDatabaseCount('business_usage_ledger_entries', 0);
    }

    public function test_reserve_then_exact_match_commit(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $this->assertTrue($reservation->granted);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(3_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(2_000_000, (int) $wallet->reserved_balance_micro);

        $commit = app(UsageWalletManager::class)->commit($reservation->reservationId, '2');
        $this->assertFalse($commit->hadOverage);
        $this->assertFalse($commit->hadUnusedRelease);
        $this->assertSame('2000000', $commit->finalAmountMicro);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(3_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->reserved_balance_micro);
        $this->assertSame(0, (int) $wallet->debt_balance_micro);
        $this->assertSame(2_000_000, (int) $wallet->committed_spend_this_period_micro);
        $this->assertSame(0, (int) $wallet->reserved_spend_this_period_micro);

        $reservationRow = DB::table('business_usage_reservations')->find($reservation->reservationId);
        $this->assertSame('committed', $reservationRow->status);
        $this->assertNotNull($reservationRow->committed_at);
    }

    public function test_reserve_then_under_reservation_commit_releases_unused_portion(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $commit = app(UsageWalletManager::class)->commit($reservation->reservationId, '1');

        $this->assertFalse($commit->hadOverage);
        $this->assertTrue($commit->hadUnusedRelease);
        $this->assertSame('1000000', $commit->finalAmountMicro);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        // 5,000,000 - 2,000,000 (reserved) + 1,000,000 (unused release) = 4,000,000
        $this->assertSame(4_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->reserved_balance_micro);
        $this->assertSame(1_000_000, (int) $wallet->committed_spend_this_period_micro);

        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $reservation->reservationId,
            'entry_type' => 'reservation_release',
        ]);
    }

    public function test_reserve_then_overage_commit_draws_available_then_debt(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        // Only enough for the reservation itself plus a small overage buffer.
        $this->seedBalance($business->id, 2_500_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        // final quantity 3 => final amount 3,000,000, overage of 1,000,000
        // over the 2,000,000 reservation. Available after reserve is
        // 2,500,000 - 2,000,000 = 500,000 — covers only part of the overage.
        $commit = app(UsageWalletManager::class)->commit($reservation->reservationId, '3');

        $this->assertTrue($commit->hadOverage);
        $this->assertSame('3000000', $commit->finalAmountMicro);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(0, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->reserved_balance_micro);
        $this->assertSame(500_000, (int) $wallet->debt_balance_micro);
        // committed spend = final amount, regardless of the available/debt split
        $this->assertSame(3_000_000, (int) $wallet->committed_spend_this_period_micro);

        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $reservation->reservationId,
            'entry_type' => 'usage_overage_charge',
            'available_delta_micro' => -500_000,
            'debt_delta_micro' => 500_000,
        ]);
    }

    public function test_reserve_then_release(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        app(UsageWalletManager::class)->release($reservation->reservationId);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(5_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->reserved_balance_micro);

        $reservationRow = DB::table('business_usage_reservations')->find($reservation->reservationId);
        $this->assertSame('released', $reservationRow->status);
        $this->assertNotNull($reservationRow->released_at);
    }

    public function test_expire_stale_reservations_releases_without_committing(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        DB::table('business_usage_reservations')->where('id', $reservation->reservationId)
            ->update(['expires_at' => now()->subMinute()]);

        $count = app(UsageWalletManager::class)->expireStaleReservations();
        $this->assertSame(1, $count);

        $reservationRow = DB::table('business_usage_reservations')->find($reservation->reservationId);
        $this->assertSame('expired', $reservationRow->status);
        $this->assertNull($reservationRow->committed_at);
        $this->assertNotNull($reservationRow->released_at);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame(5_000_000, (int) $wallet->available_balance_micro);
    }

    public function test_repeat_reserve_with_same_idempotency_key_is_a_no_op(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);

        $key = (string) Str::uuid();
        $first = app(UsageWalletManager::class)->reserve($business, 'crm', $key, '2');
        $second = app(UsageWalletManager::class)->reserve($business, 'crm', $key, '2');

        $this->assertSame($first->reservationId, $second->reservationId);
        $this->assertDatabaseCount('business_usage_reservations', 1);
    }

    public function test_repeat_commit_on_already_committed_reservation_is_a_no_op(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        $first = app(UsageWalletManager::class)->commit($reservation->reservationId, '2');
        $second = app(UsageWalletManager::class)->commit($reservation->reservationId, '2');

        $this->assertSame($first->finalAmountMicro, $second->finalAmountMicro);
        $this->assertDatabaseCount('business_usage_ledger_entries', 2); // reservation + charge, never duplicated
    }

    public function test_commit_or_release_on_released_reservation_throws(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        app(UsageWalletManager::class)->release($reservation->reservationId);

        $this->expectException(InvalidReservationStateTransitionException::class);
        app(UsageWalletManager::class)->commit($reservation->reservationId, '2');
    }

    public function test_outstanding_debt_denies_a_new_reservation(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['debt_balance_micro' => 1]);

        $result = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '1');

        $this->assertFalse($result->granted);
        $this->assertSame('outstanding_debt', $result->denialReason);
    }
}
