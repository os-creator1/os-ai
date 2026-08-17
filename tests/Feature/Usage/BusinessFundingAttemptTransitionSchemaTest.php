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
 * RFC-005 M3 contract §21.4 — business_funding_attempt_transitions exact
 * schema shape: funding_attempt_id restrictOnDelete, provider_event_id a
 * plain nullable FK (no restrictOnDelete stated by the RFC).
 */
class BusinessFundingAttemptTransitionSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function createAttempt(): int
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        $providerCustomerId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_'.$business->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('business_funding_attempts')->insertGetId([
            'business_id' => $business->id,
            'wallet_id' => $wallet->id,
            'purpose' => 'manual_top_up',
            'payer_type_snapshot' => 'workspace',
            'provider_customer_external_id_snapshot' => 'cus_ext',
            'provider_customer_id' => $providerCustomerId,
            'payment_method_display_snapshot' => 'visa •••• 4242, exp 12/26',
            'expected_currency_id' => (int) $wallet->currency_id,
            'expected_amount_micro' => 1000000,
            'local_idempotency_key' => 'idem-'.uniqid(),
            'state' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_funding_attempt_id_restricts_deletion_while_referenced(): void
    {
        $attemptId = $this->createAttempt();

        DB::table('business_funding_attempt_transitions')->insert([
            'funding_attempt_id' => $attemptId,
            'from_state' => 'created',
            'to_state' => 'created',
            'source' => 'sync_response',
            'created_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('business_funding_attempts')->where('id', $attemptId)->delete();
    }

    public function test_provider_event_id_is_nullable(): void
    {
        $attemptId = $this->createAttempt();

        $id = DB::table('business_funding_attempt_transitions')->insertGetId([
            'funding_attempt_id' => $attemptId,
            'from_state' => 'created',
            'to_state' => 'provider_pending',
            'source' => 'sync_response',
            'provider_event_id' => null,
            'created_at' => now(),
        ]);

        $row = DB::table('business_funding_attempt_transitions')->find($id);
        $this->assertNull($row->provider_event_id);
    }

    public function test_transitions_are_append_only_history(): void
    {
        $attemptId = $this->createAttempt();

        DB::table('business_funding_attempt_transitions')->insert([
            'funding_attempt_id' => $attemptId,
            'from_state' => 'created',
            'to_state' => 'provider_pending',
            'source' => 'sync_response',
            'created_at' => now(),
        ]);

        DB::table('business_funding_attempt_transitions')->insert([
            'funding_attempt_id' => $attemptId,
            'from_state' => 'provider_pending',
            'to_state' => 'succeeded',
            'source' => 'webhook_event',
            'created_at' => now(),
        ]);

        $this->assertSame(2, DB::table('business_funding_attempt_transitions')->where('funding_attempt_id', $attemptId)->count());
    }
}
