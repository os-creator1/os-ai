<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §6.6/§6.7 — business_usage_reservations/
 * business_usage_ledger_entries full column set, the composite FK
 * (wallet_id, business_id) on both tables, UNIQUE(idempotency_key)/
 * UNIQUE(correlation_key), and the documented deferred-FK note for
 * business_usage_ledger_entries.funding_attempt_id (no FK at M1).
 */
class BusinessUsageReservationLedgerSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function walletFixture(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;

        $now = now();
        $walletId = DB::table('business_usage_wallets')->insertGetId([
            'business_id' => $business->id,
            'currency_id' => $currencyId,
            'available_balance_micro' => 0,
            'reserved_balance_micro' => 0,
            'debt_balance_micro' => 0,
            'spend_period_key' => '2026-08',
            'spend_period_start_utc' => $now,
            'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => '2026-08',
            'recharge_period_start_utc' => $now,
            'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rateId = DB::table('business_usage_rates')->insertGetId([
            'feature_key' => 'crm',
            'version' => 1,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up',
            'currency_id' => $currencyId,
            'created_by_user_id' => 1,
            'created_at' => $now,
        ]);

        return ['business_id' => $business->id, 'wallet_id' => $walletId, 'currency_id' => $currencyId, 'rate_id' => $rateId];
    }

    /**
     * RFC-005 Amendment 1 §B/§D, Slice 1 EXPAND — a disposable UsageMeter
     * plus a business_usage_rates row carrying a matching meter_key, for
     * exercising the new composite meter/rate FKs. Never a real/seeded
     * meter — created and torn down inside this test's own transaction.
     *
     * Uses version 2, not 1: walletFixture() (called first by every test
     * that also calls this fixture) already creates a 'crm'/version 1
     * rate, and business_usage_rates_feature_key_version_unique — Slice
     * 1's intentionally retained legacy constraint (RFC-005 Amendment 1
     * §D) — is feature-wide, not meter-local, so a second 'crm' rate must
     * use a distinct version regardless of which meter it belongs to.
     */
    private function meterFixture(int $currencyId): array
    {
        $meterKey = 'crm.meter.' . uniqid();
        $version = 2;
        $now = now();

        DB::table('usage_meters')->insert([
            'meter_key' => $meterKey,
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'is_metered' => false,
            'active_rate_id' => null,
            'description' => 'Slice 1 schema test fixture meter.',
            'updated_by_user_id' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $rateId = DB::table('business_usage_rates')->insertGetId([
            'feature_key' => 'crm',
            'meter_key' => $meterKey,
            'version' => $version,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'unit_label' => 'per message',
            'rounding_rule' => 'round_half_up',
            'currency_id' => $currencyId,
            'created_by_user_id' => 1,
            'created_at' => $now,
        ]);

        return ['meter_key' => $meterKey, 'rate_id' => $rateId, 'rate_version' => $version];
    }

    private function insertReservation(array $fixture, array $overrides = []): int
    {
        $now = now();

        return DB::table('business_usage_reservations')->insertGetId(array_merge([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'feature_key' => 'crm',
            'period_key' => '2026-08',
            'status' => 'pending',
            'reserved_amount_micro' => 1000,
            'rate_id' => $fixture['rate_id'],
            'rate_version' => 1,
            'retail_rate_micro' => 1000,
            'provider_cost_micro' => 500,
            'rounding_rule' => 'round_half_up',
            'idempotency_key' => 'idem-' . uniqid(),
            'correlation_key' => 'corr-' . uniqid(),
            'reserved_at' => $now,
            'expires_at' => $now->clone()->addMinutes(30),
        ], $overrides));
    }

    public function test_reservation_idempotency_key_is_unique(): void
    {
        $fixture = $this->walletFixture();
        $key = 'idem-' . uniqid();
        $this->insertReservation($fixture, ['idempotency_key' => $key, 'correlation_key' => 'corr-a']);

        $this->expectException(QueryException::class);
        $this->insertReservation($fixture, ['idempotency_key' => $key, 'correlation_key' => 'corr-b']);
    }

    public function test_reservation_composite_fk_rejects_mismatched_wallet_business_pair(): void
    {
        $fixture = $this->walletFixture();
        $otherCustomer = $this->createCustomer();
        $otherBusiness = $this->createBusinessWithWorkspace($otherCustomer, $this->businessAttributes(['name' => 'Other Co']));

        $this->expectException(QueryException::class);
        $this->insertReservation($fixture, [
            'business_id' => $otherBusiness->id,
            'idempotency_key' => 'idem-mismatch-' . uniqid(),
        ]);
    }

    public function test_ledger_entry_correlation_key_is_unique(): void
    {
        $fixture = $this->walletFixture();
        $key = 'corr-' . uniqid();

        DB::table('business_usage_ledger_entries')->insert([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'reservation',
            'available_delta_micro' => -1000,
            'reserved_delta_micro' => 1000,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'correlation_key' => $key,
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_usage_ledger_entries')->insert([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'reservation',
            'available_delta_micro' => -500,
            'reserved_delta_micro' => 500,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'correlation_key' => $key,
            'created_at' => now(),
        ]);
    }

    public function test_ledger_entry_composite_fk_rejects_mismatched_wallet_business_pair(): void
    {
        $fixture = $this->walletFixture();
        $otherCustomer = $this->createCustomer();
        $otherBusiness = $this->createBusinessWithWorkspace($otherCustomer, $this->businessAttributes(['name' => 'Other Co 2']));

        $this->expectException(QueryException::class);
        DB::table('business_usage_ledger_entries')->insert([
            'business_id' => $otherBusiness->id,
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'reservation',
            'available_delta_micro' => -1000,
            'reserved_delta_micro' => 1000,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'correlation_key' => 'corr-mismatch-' . uniqid(),
            'created_at' => now(),
        ]);
    }

    public function test_funding_attempt_id_has_no_fk_and_accepts_null(): void
    {
        $fixture = $this->walletFixture();

        $id = DB::table('business_usage_ledger_entries')->insertGetId([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'reservation',
            'available_delta_micro' => -1000,
            'reserved_delta_micro' => 1000,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'funding_attempt_id' => null,
            'correlation_key' => 'corr-null-fa-' . uniqid(),
            'created_at' => now(),
        ]);
        $this->assertDatabaseHas('business_usage_ledger_entries', ['id' => $id, 'funding_attempt_id' => null]);

        // Deferred-FK note (M1 contract §6.7): funding_attempt_id has no
        // FK constraint at M1, so an arbitrary integer with no
        // corresponding row anywhere is accepted without error.
        $idWithArbitraryValue = DB::table('business_usage_ledger_entries')->insertGetId([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'reservation',
            'available_delta_micro' => -1000,
            'reserved_delta_micro' => 1000,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'funding_attempt_id' => 999999,
            'correlation_key' => 'corr-arbitrary-fa-' . uniqid(),
            'created_at' => now(),
        ]);
        $this->assertDatabaseHas('business_usage_ledger_entries', ['id' => $idWithArbitraryValue, 'funding_attempt_id' => 999999]);
    }

    /**
     * RFC-005 Amendment 1 §F, Slice 1 EXPAND — the current, unmodified
     * reserve() never populates meter_key; the new column must accept
     * that transitional NULL without error.
     */
    public function test_reservation_meter_key_null_is_accepted(): void
    {
        $fixture = $this->walletFixture();

        $id = $this->insertReservation($fixture, [
            'idempotency_key' => 'idem-null-meter-' . uniqid(),
            'correlation_key' => 'corr-null-meter-' . uniqid(),
        ]);

        $this->assertDatabaseHas('business_usage_reservations', ['id' => $id, 'meter_key' => null]);
    }

    public function test_reservation_meter_rate_composite_fk_rejects_mismatched_pair(): void
    {
        $fixture = $this->walletFixture();
        $meter = $this->meterFixture($fixture['currency_id']);

        $this->expectException(QueryException::class);
        $this->insertReservation($fixture, [
            'meter_key' => $meter['meter_key'],
            'rate_id' => $fixture['rate_id'],
            'idempotency_key' => 'idem-mismatch-meter-' . uniqid(),
            'correlation_key' => 'corr-mismatch-meter-' . uniqid(),
        ]);
    }

    public function test_reservation_meter_rate_composite_fk_accepts_matching_pair(): void
    {
        $fixture = $this->walletFixture();
        $meter = $this->meterFixture($fixture['currency_id']);

        $id = $this->insertReservation($fixture, [
            'meter_key' => $meter['meter_key'],
            'rate_id' => $meter['rate_id'],
            'rate_version' => $meter['rate_version'],
            'idempotency_key' => 'idem-match-meter-' . uniqid(),
            'correlation_key' => 'corr-match-meter-' . uniqid(),
        ]);

        $this->assertDatabaseHas('business_usage_reservations', [
            'id' => $id,
            'meter_key' => $meter['meter_key'],
            'rate_id' => $meter['rate_id'],
            'rate_version' => $meter['rate_version'],
        ]);
    }

    /**
     * RFC-005 Amendment 1 §G, Slice 1 EXPAND — the current, unmodified
     * commit()/release() never populate meter_key; the new column must
     * accept that transitional NULL without error.
     */
    public function test_ledger_meter_key_null_is_accepted(): void
    {
        $fixture = $this->walletFixture();

        $id = DB::table('business_usage_ledger_entries')->insertGetId([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'reservation',
            'available_delta_micro' => -1000,
            'reserved_delta_micro' => 1000,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'correlation_key' => 'corr-null-ledger-meter-' . uniqid(),
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('business_usage_ledger_entries', ['id' => $id, 'meter_key' => null]);
    }

    public function test_ledger_meter_rate_composite_fk_rejects_mismatched_pair(): void
    {
        $fixture = $this->walletFixture();
        $meter = $this->meterFixture($fixture['currency_id']);

        $this->expectException(QueryException::class);
        DB::table('business_usage_ledger_entries')->insert([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'usage_charge',
            'available_delta_micro' => 0,
            'reserved_delta_micro' => -1000,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'meter_key' => $meter['meter_key'],
            'rate_id' => $fixture['rate_id'],
            'correlation_key' => 'corr-mismatch-ledger-meter-' . uniqid(),
            'created_at' => now(),
        ]);
    }

    /**
     * RFC-005 Amendment 1 §G — the legitimate ReservationRelease shape:
     * meter_key populated, rate_id NULL. MySQL/InnoDB's MATCH SIMPLE
     * semantics exempt this row from the composite FK (any-NULL exempts),
     * so it must be accepted, not rejected.
     */
    public function test_ledger_reservation_release_shape_with_meter_key_and_null_rate_id_is_accepted(): void
    {
        $fixture = $this->walletFixture();
        $meter = $this->meterFixture($fixture['currency_id']);

        $id = DB::table('business_usage_ledger_entries')->insertGetId([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'reservation_release',
            'available_delta_micro' => 1000,
            'reserved_delta_micro' => -1000,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'meter_key' => $meter['meter_key'],
            'rate_id' => null,
            'correlation_key' => 'corr-release-shape-' . uniqid(),
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'id' => $id,
            'meter_key' => $meter['meter_key'],
            'rate_id' => null,
        ]);
    }

    /**
     * RFC-005 Amendment 1 Slice 2 CUTOVER §5.4 — after cutover, a genuine
     * reserve() call (through the real repositories, not a raw insert)
     * dual-writes both feature_key and meter_key on the reservation and
     * its own reservation-type ledger entry, resolved from the UsageMeter
     * — never the raw featureKey parameter re-derived independently.
     */
    public function test_reserve_dual_writes_feature_key_and_meter_key_from_the_resolved_meter(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $actorId = User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
        $meterKey = 'crm.meter.' . uniqid();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey,
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Dual-write fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);
        $manager->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($meterKey, $actorId, 'Fixture.');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        $result = $manager->reserve($business, $meterKey, (string) Str::uuid(), '1');

        $this->assertTrue($result->granted);
        $this->assertDatabaseHas('business_usage_reservations', [
            'id' => $result->reservationId,
            'feature_key' => 'crm',
            'meter_key' => $meterKey,
        ]);
        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $result->reservationId,
            'entry_type' => 'reservation',
            'feature_key' => 'crm',
            'meter_key' => $meterKey,
        ]);
    }

    /**
     * RFC-005 Amendment 1 §N — a historical, real M3/M4-shaped
     * non-metered entry (feature_key/rate_id both NULL already) remains
     * valid and untouched once meter_key is added.
     */
    public function test_historical_non_metered_ledger_row_remains_valid_with_null_meter_key(): void
    {
        $fixture = $this->walletFixture();

        $id = DB::table('business_usage_ledger_entries')->insertGetId([
            'business_id' => $fixture['business_id'],
            'wallet_id' => $fixture['wallet_id'],
            'entry_type' => 'paid_top_up',
            'available_delta_micro' => 5000,
            'reserved_delta_micro' => 0,
            'debt_delta_micro' => 0,
            'currency_id' => $fixture['currency_id'],
            'correlation_key' => 'corr-historical-' . uniqid(),
            'created_at' => now(),
        ]);

        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'id' => $id,
            'feature_key' => null,
            'meter_key' => null,
            'rate_id' => null,
        ]);
    }
}
