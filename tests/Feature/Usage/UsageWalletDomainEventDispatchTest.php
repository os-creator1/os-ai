<?php

namespace Tests\Feature\Usage;

use App\Enums\Usage\UsageLedgerEntryType;
use App\Events\Usage\BusinessUsageCommitted;
use App\Events\Usage\BusinessUsageReservationReleased;
use App\Events\Usage\BusinessUsageReserved;
use App\Events\Usage\BusinessWalletCredited;
use App\Events\Usage\BusinessWalletDebited;
use App\Events\Usage\BusinessWalletDebtCleared;
use App\Events\Usage\BusinessWalletDebtIncurred;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Job/Event Dispatch Completion Correction Contract §6 — exact
 * emission/non-emission proofs for the seven missing wallet/reservation
 * events. Fixture pattern mirrors UsageWalletManagerReservationLifecycleTest
 * exactly (the same repository this contract's own audit read from).
 */
class UsageWalletDomainEventDispatchTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private const FAKED_EVENTS = [
        BusinessWalletCredited::class,
        BusinessWalletDebited::class,
        BusinessWalletDebtIncurred::class,
        BusinessWalletDebtCleared::class,
        BusinessUsageReserved::class,
        BusinessUsageCommitted::class,
        BusinessUsageReservationReleased::class,
    ];

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

    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    private function activateRate(string $featureKey = 'crm', string $retailRateMicro = '1000000'): void
    {
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => $featureKey,
            'feature_key' => $featureKey,
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Event-dispatch fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate(
            $featureKey,
            $retailRateMicro,
            '500000',
            'per message',
            $currencyId,
            $actorId,
            'Test rate activation.',
        );

        app(UsageWalletManager::class)->activateMetering($featureKey, $actorId, 'Test metering activation.');
    }

    private function seedBalance(int $businessId, int $availableMicro): void
    {
        DB::table('business_usage_wallets')->where('business_id', $businessId)->update(['available_balance_micro' => $availableMicro]);
    }

    // --- BusinessUsageReserved ---

    public function test_reserve_dispatches_business_usage_reserved(): void
    {
        Event::fake(self::FAKED_EVENTS);

        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);

        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');

        Event::assertDispatchedTimes(BusinessUsageReserved::class, 1);
        Event::assertDispatched(BusinessUsageReserved::class, function (BusinessUsageReserved $event) use ($business, $reservation) {
            return $event->businessId === $business->id
                && $event->reservationId === $reservation->reservationId
                && $event->featureKey === 'crm'
                && $event->reservedAmountMicro === 2_000_000;
        });
    }

    public function test_repeat_reserve_with_same_idempotency_key_does_not_redispatch(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);
        $idempotencyKey = (string) Str::uuid();

        app(UsageWalletManager::class)->reserve($business, 'crm', $idempotencyKey, '2');

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->reserve($business, 'crm', $idempotencyKey, '2');

        Event::assertNotDispatched(BusinessUsageReserved::class);
    }

    // --- BusinessUsageCommitted ---

    public function test_commit_dispatches_business_usage_committed(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->commit($reservation->reservationId, '2');

        Event::assertDispatchedTimes(BusinessUsageCommitted::class, 1);
        Event::assertDispatched(BusinessUsageCommitted::class, function (BusinessUsageCommitted $event) use ($business, $reservation) {
            return $event->businessId === $business->id
                && $event->reservationId === $reservation->reservationId
                && $event->featureKey === 'crm'
                && $event->finalAmountMicro === 2_000_000
                && $event->reservedAmountMicro === 2_000_000;
        });
    }

    public function test_repeat_commit_on_already_committed_reservation_does_not_redispatch(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        app(UsageWalletManager::class)->commit($reservation->reservationId, '2');

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->commit($reservation->reservationId, '2');

        Event::assertNotDispatched(BusinessUsageCommitted::class);
    }

    /**
     * Reuses UsageWalletManagerReservationLifecycleTest's own already-
     * verified overage scenario (2,500,000 available, reserve 2 => final
     * quantity 3 => 1,000,000 overage split 500,000 available / 500,000
     * debt) to prove BusinessWalletDebited and BusinessWalletDebtIncurred
     * are NOT mutually exclusive — a single commit() call dispatches both.
     */
    public function test_commit_overage_dispatches_both_debited_and_debt_incurred_from_the_same_call(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 2_500_000);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->commit($reservation->reservationId, '3');

        Event::assertDispatchedTimes(BusinessWalletDebited::class, 1);
        Event::assertDispatchedTimes(BusinessWalletDebtIncurred::class, 1);
        Event::assertDispatched(BusinessWalletDebited::class, fn (BusinessWalletDebited $event) => $event->businessId === $business->id && $event->amountMicro === 500_000);
        Event::assertDispatched(BusinessWalletDebtIncurred::class, fn (BusinessWalletDebtIncurred $event) => $event->businessId === $business->id && $event->amountMicro === 500_000);
    }

    public function test_commit_overage_fully_absorbed_by_debt_dispatches_only_debt_incurred(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 0);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '0');

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->commit($reservation->reservationId, '2');

        Event::assertNotDispatched(BusinessWalletDebited::class);
        Event::assertDispatchedTimes(BusinessWalletDebtIncurred::class, 1);
        Event::assertDispatched(BusinessWalletDebtIncurred::class, fn (BusinessWalletDebtIncurred $event) => $event->amountMicro === 2_000_000);
    }

    public function test_unused_release_commit_does_not_dispatch_wallet_credited_or_debited(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->commit($reservation->reservationId, '1');

        Event::assertNotDispatched(BusinessWalletCredited::class);
        Event::assertNotDispatched(BusinessWalletDebited::class);
        Event::assertNotDispatched(BusinessWalletDebtIncurred::class);
        Event::assertNotDispatched(BusinessWalletDebtCleared::class);
        Event::assertDispatchedTimes(BusinessUsageCommitted::class, 1);
    }

    // --- BusinessUsageReservationReleased ---

    public function test_release_dispatches_business_usage_reservation_released_with_released_status(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->release($reservation->reservationId);

        Event::assertDispatchedTimes(BusinessUsageReservationReleased::class, 1);
        Event::assertDispatched(BusinessUsageReservationReleased::class, function (BusinessUsageReservationReleased $event) use ($business, $reservation) {
            return $event->businessId === $business->id
                && $event->reservationId === $reservation->reservationId
                && $event->releasedAmountMicro === 2_000_000
                && $event->resultingStatus === 'released';
        });
    }

    public function test_expired_release_dispatches_business_usage_reservation_released_with_expired_status(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        DB::table('business_usage_reservations')->where('id', $reservation->reservationId)
            ->update(['expires_at' => now()->subMinute()]);

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->release($reservation->reservationId);

        Event::assertDispatched(BusinessUsageReservationReleased::class, fn (BusinessUsageReservationReleased $event) => $event->resultingStatus === 'expired');
    }

    public function test_repeat_release_on_already_released_reservation_does_not_redispatch(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        $this->seedBalance($business->id, 5_000_000);
        $reservation = app(UsageWalletManager::class)->reserve($business, 'crm', (string) Str::uuid(), '2');
        app(UsageWalletManager::class)->release($reservation->reservationId);

        Event::fake(self::FAKED_EVENTS);

        app(UsageWalletManager::class)->release($reservation->reservationId);

        Event::assertNotDispatched(BusinessUsageReservationReleased::class);
    }

    // --- BusinessWalletCredited / BusinessWalletDebtCleared ---

    public function test_credit_from_funding_dispatches_business_wallet_credited_when_remainder_positive(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        Event::fake(self::FAKED_EVENTS);
        // Queue::fake() prevents creditFromFunding()'s own
        // SendReceiptNotification::dispatch()->afterCommit() from
        // actually running — that job needs a real funding-attempt row
        // and a bound payment gateway, neither of which is this test's
        // concern (it is exercising the new wallet events only).
        Queue::fake();

        app(UsageWalletManager::class)->creditFromFunding(
            $business->id,
            UsageLedgerEntryType::PaidTopUp,
            5_000_000,
            1,
            (string) Str::uuid(),
        );

        Event::assertDispatchedTimes(BusinessWalletCredited::class, 1);
        Event::assertNotDispatched(BusinessWalletDebtCleared::class);
        Event::assertDispatched(BusinessWalletCredited::class, fn (BusinessWalletCredited $event) => $event->businessId === $business->id && $event->amountMicro === 5_000_000);
    }

    /**
     * Proves BusinessWalletCredited and BusinessWalletDebtCleared are not
     * mutually exclusive — a single creditFromFunding() call whose amount
     * exceeds outstanding debt clears the debt AND credits the remainder.
     */
    public function test_credit_from_funding_dispatches_both_credited_and_debt_cleared_from_the_same_call(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['debt_balance_micro' => 1_000_000]);

        Event::fake(self::FAKED_EVENTS);
        // Queue::fake() prevents creditFromFunding()'s own
        // SendReceiptNotification::dispatch()->afterCommit() from
        // actually running — that job needs a real funding-attempt row
        // and a bound payment gateway, neither of which is this test's
        // concern (it is exercising the new wallet events only).
        Queue::fake();

        app(UsageWalletManager::class)->creditFromFunding(
            $business->id,
            UsageLedgerEntryType::PaidTopUp,
            3_000_000,
            1,
            (string) Str::uuid(),
        );

        Event::assertDispatchedTimes(BusinessWalletCredited::class, 1);
        Event::assertDispatchedTimes(BusinessWalletDebtCleared::class, 1);
        Event::assertDispatched(BusinessWalletCredited::class, fn (BusinessWalletCredited $event) => $event->amountMicro === 2_000_000);
        Event::assertDispatched(BusinessWalletDebtCleared::class, fn (BusinessWalletDebtCleared $event) => $event->amountMicro === 1_000_000);
    }

    public function test_credit_from_funding_fully_clearing_debt_with_no_remainder_dispatches_only_debt_cleared(): void
    {
        $business = $this->business();
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['debt_balance_micro' => 3_000_000]);

        Event::fake(self::FAKED_EVENTS);
        // Queue::fake() prevents creditFromFunding()'s own
        // SendReceiptNotification::dispatch()->afterCommit() from
        // actually running — that job needs a real funding-attempt row
        // and a bound payment gateway, neither of which is this test's
        // concern (it is exercising the new wallet events only).
        Queue::fake();

        app(UsageWalletManager::class)->creditFromFunding(
            $business->id,
            UsageLedgerEntryType::PaidTopUp,
            3_000_000,
            1,
            (string) Str::uuid(),
        );

        Event::assertNotDispatched(BusinessWalletCredited::class);
        Event::assertDispatchedTimes(BusinessWalletDebtCleared::class, 1);
    }
}
