<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
}
