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
 * M4 contract §10/§18/§21 — business_usage_addon_purchases exact schema
 * shape: funding_attempt_id unique (sole authoritative direction),
 * business_id/funding_attempt_id both restrictOnDelete.
 */
class BusinessUsageAddonPurchaseSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function createFundingAttempt(): array
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

        return [(int) $business->id, $attemptId];
    }

    private function baseAttributes(int $businessId, int $attemptId, array $overrides = []): array
    {
        return array_merge([
            'business_id' => $businessId,
            'addon_key' => 'fixture-addon',
            'price_micro' => 1_000_000,
            'funding_attempt_id' => $attemptId,
            'status' => 'pending',
            'requested_by_user_id' => 1,
            'created_at' => now(),
        ], $overrides);
    }

    public function test_funding_attempt_id_is_unique(): void
    {
        [$businessId, $attemptId] = $this->createFundingAttempt();

        DB::table('business_usage_addon_purchases')->insert($this->baseAttributes($businessId, $attemptId));

        [, $secondAttemptId] = $this->createFundingAttempt();
        unset($secondAttemptId);

        $this->expectException(QueryException::class);
        DB::table('business_usage_addon_purchases')->insert($this->baseAttributes($businessId, $attemptId));
    }

    public function test_funding_attempt_id_restricts_deletion_while_referenced(): void
    {
        [$businessId, $attemptId] = $this->createFundingAttempt();
        DB::table('business_usage_addon_purchases')->insert($this->baseAttributes($businessId, $attemptId));

        $this->expectException(QueryException::class);
        DB::table('business_funding_attempts')->where('id', $attemptId)->delete();
    }

    public function test_status_defaults_pending(): void
    {
        [$businessId, $attemptId] = $this->createFundingAttempt();
        $attrs = $this->baseAttributes($businessId, $attemptId);
        unset($attrs['status']);

        $id = DB::table('business_usage_addon_purchases')->insertGetId($attrs);

        $this->assertSame('pending', DB::table('business_usage_addon_purchases')->find($id)->status);
    }
}
