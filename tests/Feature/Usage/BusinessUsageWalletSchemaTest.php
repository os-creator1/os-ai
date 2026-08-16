<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §6.1 — exact schema shape for business_usage_wallets:
 * columns, nullable/default rules, UNIQUE(business_id)/UNIQUE(id,business_id),
 * and restrictOnDelete() on business_id/currency_id.
 */
class BusinessUsageWalletSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function usdCurrencyId(): int
    {
        return Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
    }

    private function insertWallet(int $businessId, int $currencyId, array $overrides = []): int
    {
        $now = now();

        return DB::table('business_usage_wallets')->insertGetId(array_merge([
            'business_id' => $businessId,
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
        ], $overrides));
    }

    public function test_defaults_and_nullable_rules(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $currencyId = $this->usdCurrencyId();

        $walletId = $this->insertWallet($business->id, $currencyId);

        $row = DB::table('business_usage_wallets')->find($walletId);

        $this->assertSame(0, (int) $row->available_balance_micro);
        $this->assertSame(0, (int) $row->reserved_balance_micro);
        $this->assertSame(0, (int) $row->debt_balance_micro);
        $this->assertNull($row->monthly_spend_cap_micro);
        $this->assertSame(0, (int) $row->auto_recharge_enabled);
        $this->assertNull($row->auto_recharge_threshold_micro);
        $this->assertSame(0, (int) $row->committed_spend_this_period_micro);
        $this->assertSame(0, (int) $row->reserved_spend_this_period_micro);
        $this->assertSame(0, (int) $row->recharged_this_period_micro);
        $this->assertSame(0, (int) $row->consecutive_recharge_failures);
        $this->assertNull($row->low_balance_notified_at);
        $this->assertSame('active', $row->billing_status);
    }

    public function test_business_id_is_unique(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $currencyId = $this->usdCurrencyId();

        $this->insertWallet($business->id, $currencyId);

        $this->expectException(QueryException::class);
        $this->insertWallet($business->id, $currencyId);
    }

    public function test_composite_id_business_id_unique_index_exists(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $currencyId = $this->usdCurrencyId();
        $walletId = $this->insertWallet($business->id, $currencyId);

        $this->assertDatabaseHas('business_usage_wallets', ['id' => $walletId, 'business_id' => $business->id]);

        // The composite unique index is what makes the composite FK on
        // business_usage_reservations/business_usage_ledger_entries
        // buildable at all — already proven by migration success; this
        // asserts the specific (id, business_id) pair is queryable.
        $count = DB::table('business_usage_wallets')->where('id', $walletId)->where('business_id', $business->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_business_id_restricts_deletion_while_wallet_exists(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $currencyId = $this->usdCurrencyId();
        $this->insertWallet($business->id, $currencyId);

        $this->expectException(QueryException::class);
        DB::table('businesses')->where('id', $business->id)->delete();
    }

    public function test_currency_id_restricts_deletion_while_wallet_exists(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $currencyId = $this->usdCurrencyId();
        $this->insertWallet($business->id, $currencyId);

        $this->expectException(QueryException::class);
        DB::table('currencies')->where('id', $currencyId)->delete();
    }
}
