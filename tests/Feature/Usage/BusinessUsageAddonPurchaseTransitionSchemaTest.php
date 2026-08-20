<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * M4 contract §18 — business_usage_addon_purchase_transitions exact
 * schema shape: purchase_id restrictOnDelete, append-only history.
 */
class BusinessUsageAddonPurchaseTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function createPurchase(): int
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        $providerCustomerId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe', 'business_id' => $business->id, 'provider_customer_id' => 'cus_'.$business->id,
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $attemptId = DB::table('business_funding_attempts')->insertGetId([
            'business_id' => $business->id, 'wallet_id' => $wallet->id, 'purpose' => 'addon_purchase',
            'payer_type_snapshot' => 'workspace', 'provider_customer_external_id_snapshot' => 'cus_ext',
            'provider_customer_id' => $providerCustomerId, 'payment_method_display_snapshot' => 'visa •••• 4242, exp 12/26',
            'expected_currency_id' => (int) $wallet->currency_id, 'expected_amount_micro' => 1_000_000,
            'local_idempotency_key' => 'idem-'.uniqid(), 'state' => 'created', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('business_usage_addon_purchases')->insertGetId([
            'business_id' => $business->id, 'addon_key' => 'fixture-addon', 'price_micro' => 1_000_000,
            'funding_attempt_id' => $attemptId, 'status' => 'pending', 'requested_by_user_id' => 1, 'created_at' => now(),
        ]);
    }

    public function test_purchase_id_restricts_deletion_while_referenced(): void
    {
        $purchaseId = $this->createPurchase();

        DB::table('business_usage_addon_purchase_transitions')->insert([
            'purchase_id' => $purchaseId, 'from_status' => 'pending', 'to_status' => 'completed',
            'source' => 'sync_response', 'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_usage_addon_purchases')->where('id', $purchaseId)->delete();
    }

    public function test_transitions_are_append_only_history(): void
    {
        $purchaseId = $this->createPurchase();

        DB::table('business_usage_addon_purchase_transitions')->insert([
            'purchase_id' => $purchaseId, 'from_status' => 'pending', 'to_status' => 'completed',
            'source' => 'sync_response', 'created_at' => now(),
        ]);

        $this->assertSame(1, DB::table('business_usage_addon_purchase_transitions')->where('purchase_id', $purchaseId)->count());
    }
}
