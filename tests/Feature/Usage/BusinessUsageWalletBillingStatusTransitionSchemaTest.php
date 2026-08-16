<?php

namespace Tests\Feature\Usage;

use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §11.4 — exact schema shape for
 * business_usage_wallet_billing_status_transitions: append-only,
 * composite-protected on (wallet_id, business_id), mirroring M1's own
 * tenancy-ID integrity pattern.
 */
class BusinessUsageWalletBillingStatusTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function createWallet(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;

        $now = now();
        $walletId = DB::table('business_usage_wallets')->insertGetId([
            'business_id' => $business->id,
            'currency_id' => $currencyId,
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

        return [$walletId, $business->id];
    }

    public function test_wallet_business_composite_fk_rejects_a_mismatched_pair(): void
    {
        [$walletId, $businessId] = $this->createWallet();
        $customer2 = $this->createCustomer();
        $unrelatedBusiness = $this->createBusinessWithWorkspace($customer2, $this->businessAttributes());

        $this->expectException(QueryException::class);

        DB::table('business_usage_wallet_billing_status_transitions')->insert([
            'wallet_id' => $walletId,
            'business_id' => $unrelatedBusiness->id,
            'from_status' => 'active',
            'to_status' => 'suspended',
            'source' => 'admin_action',
            'actor_user_id' => 1,
            'reason' => 'Test.',
            'created_at' => now(),
        ]);
    }

    public function test_actor_user_id_is_nullable_for_dispute_webhook_source(): void
    {
        [$walletId, $businessId] = $this->createWallet();

        $id = DB::table('business_usage_wallet_billing_status_transitions')->insertGetId([
            'wallet_id' => $walletId,
            'business_id' => $businessId,
            'from_status' => 'active',
            'to_status' => 'suspended',
            'source' => 'dispute_webhook',
            'actor_user_id' => null,
            'reason' => 'Chargeback.',
            'created_at' => now(),
        ]);

        $row = DB::table('business_usage_wallet_billing_status_transitions')->find($id);
        $this->assertNull($row->actor_user_id);
    }

    public function test_table_is_empty_at_m2_launch(): void
    {
        $this->createWallet();
        $this->assertSame(0, DB::table('business_usage_wallet_billing_status_transitions')->count());
    }
}
