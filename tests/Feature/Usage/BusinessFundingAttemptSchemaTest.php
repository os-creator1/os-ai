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
 * RFC-005 M3 contract §21.3 — business_funding_attempts exact schema
 * shape: composite (wallet_id, business_id) -> (id, business_id) FK,
 * UNIQUE(local_idempotency_key), UNIQUE(provider_session_or_intent_reference).
 */
class BusinessFundingAttemptSchemaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function fixture(): array
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $currencyId = (int) $wallet->currency_id;

        $providerCustomerId = DB::table('payment_provider_customers')->insertGetId([
            'provider' => 'stripe',
            'business_id' => $business->id,
            'provider_customer_id' => 'cus_'.$business->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$business, $wallet, $currencyId, $providerCustomerId];
    }

    private function baseAttributes(int $businessId, int $walletId, int $currencyId, int $providerCustomerId, array $overrides = []): array
    {
        return array_merge([
            'business_id' => $businessId,
            'wallet_id' => $walletId,
            'purpose' => 'manual_top_up',
            'payer_type_snapshot' => 'workspace',
            'provider_customer_external_id_snapshot' => 'cus_ext',
            'provider_customer_id' => $providerCustomerId,
            'payment_method_display_snapshot' => 'visa •••• 4242, exp 12/26',
            'expected_currency_id' => $currencyId,
            'expected_amount_micro' => 1000000,
            'local_idempotency_key' => 'idem-'.uniqid(),
            'state' => 'created',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    public function test_local_idempotency_key_is_unique(): void
    {
        [$business, $wallet, $currencyId, $providerCustomerId] = $this->fixture();

        DB::table('business_funding_attempts')->insert(
            $this->baseAttributes($business->id, $wallet->id, $currencyId, $providerCustomerId, ['local_idempotency_key' => 'dup-key'])
        );

        $this->expectException(QueryException::class);
        DB::table('business_funding_attempts')->insert(
            $this->baseAttributes($business->id, $wallet->id, $currencyId, $providerCustomerId, ['local_idempotency_key' => 'dup-key'])
        );
    }

    public function test_provider_session_or_intent_reference_is_unique_when_populated(): void
    {
        [$business, $wallet, $currencyId, $providerCustomerId] = $this->fixture();

        DB::table('business_funding_attempts')->insert(
            $this->baseAttributes($business->id, $wallet->id, $currencyId, $providerCustomerId, [
                'provider_session_or_intent_reference' => 'pi_dup',
            ])
        );

        $this->expectException(QueryException::class);
        DB::table('business_funding_attempts')->insert(
            $this->baseAttributes($business->id, $wallet->id, $currencyId, $providerCustomerId, [
                'local_idempotency_key' => 'idem-'.uniqid(),
                'provider_session_or_intent_reference' => 'pi_dup',
            ])
        );
    }

    public function test_composite_fk_rejects_wallet_business_mismatch(): void
    {
        [$business, $wallet, $currencyId, $providerCustomerId] = $this->fixture();

        $otherCustomer = $this->createCustomer();
        $otherBusiness = $this->createBusinessWithWorkspace($otherCustomer, $this->businessAttributes());

        $this->expectException(QueryException::class);
        DB::table('business_funding_attempts')->insert(
            $this->baseAttributes($otherBusiness->id, $wallet->id, $currencyId, $providerCustomerId)
        );
    }

    public function test_multiple_null_provider_references_are_allowed(): void
    {
        [$business, $wallet, $currencyId, $providerCustomerId] = $this->fixture();

        DB::table('business_funding_attempts')->insert(
            $this->baseAttributes($business->id, $wallet->id, $currencyId, $providerCustomerId, ['local_idempotency_key' => 'idem-a'])
        );

        // MySQL treats multiple NULLs in a unique index as distinct — a
        // second attempt with no provider reference yet must not collide.
        DB::table('business_funding_attempts')->insert(
            $this->baseAttributes($business->id, $wallet->id, $currencyId, $providerCustomerId, ['local_idempotency_key' => 'idem-b'])
        );

        $this->assertSame(2, DB::table('business_funding_attempts')->where('business_id', $business->id)->count());
    }
}
