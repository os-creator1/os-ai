<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\BusinessCurrencyUnresolvableException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §5.5/§9 (Correction Round 1) — a wallet's currency
 * always comes from that specific Business's own valid, active currency;
 * missing/unknown/inactive/ambiguous all fail closed with no partial
 * state and no fallback; retry after correction succeeds exactly once;
 * one Business's currency failure never contaminates another's; a later
 * currency_code change never rewrites an existing wallet's currency.
 */
class BusinessCurrencyResolutionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_valid_currency_resolves_to_the_correct_currency_id(): void
    {
        $usd = Currency::create(['name' => 'US Dollar', 'code' => 'usd', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'USD']));

        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        $this->assertDatabaseHas('business_usage_wallets', [
            'business_id' => $business->id,
            'currency_id' => $usd->id,
        ]);
    }

    public function test_missing_currency_fails_closed_with_no_partial_state(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'USD']));
        // No currencies row seeded at all.

        try {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
            $this->fail('Expected BusinessCurrencyUnresolvableException.');
        } catch (BusinessCurrencyUnresolvableException $e) {
            $this->assertSame($business->id, $e->businessId);
            $this->assertSame(BusinessCurrencyUnresolvableException::CLASSIFICATION_NOT_FOUND, $e->classification);
        }

        $this->assertDatabaseCount('business_usage_wallets', 0);
        $this->assertDatabaseCount('business_usage_ledger_entries', 0);
    }

    public function test_unknown_currency_code_fails_closed(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'ZZZ']));

        $this->expectException(BusinessCurrencyUnresolvableException::class);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
    }

    public function test_inactive_only_currency_fails_closed(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => false]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'USD']));

        try {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
            $this->fail('Expected BusinessCurrencyUnresolvableException.');
        } catch (BusinessCurrencyUnresolvableException $e) {
            $this->assertSame(BusinessCurrencyUnresolvableException::CLASSIFICATION_NOT_FOUND, $e->classification);
        }

        $this->assertDatabaseCount('business_usage_wallets', 0);
    }

    public function test_ambiguous_multiple_active_matches_fails_closed(): void
    {
        Currency::create(['name' => 'US Dollar (platform)', 'code' => 'USD', 'format' => '$', 'status' => true]);
        Currency::create(['name' => 'US Dollar (custom)', 'code' => 'usd', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'USD']));

        try {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
            $this->fail('Expected BusinessCurrencyUnresolvableException.');
        } catch (BusinessCurrencyUnresolvableException $e) {
            $this->assertSame(BusinessCurrencyUnresolvableException::CLASSIFICATION_AMBIGUOUS, $e->classification);
        }

        $this->assertDatabaseCount('business_usage_wallets', 0);
    }

    public function test_no_fallback_currency_is_ever_used(): void
    {
        // Only a non-USD currency exists; a Business recorded as USD must
        // never silently receive it.
        $eur = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'USD']));

        $this->expectException(BusinessCurrencyUnresolvableException::class);

        try {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        } finally {
            $this->assertDatabaseMissing('business_usage_wallets', ['business_id' => $business->id, 'currency_id' => $eur->id]);
        }
    }

    public function test_retry_after_correcting_business_currency_succeeds_exactly_once(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'ZZZ']));

        try {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
            $this->fail('Expected BusinessCurrencyUnresolvableException.');
        } catch (BusinessCurrencyUnresolvableException) {
            // expected
        }

        $usd = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        DB::table('businesses')->where('id', $business->id)->update(['currency_code' => 'USD']);

        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id); // repeat call, still exactly one

        $this->assertDatabaseCount('business_usage_wallets', 1);
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $business->id, 'currency_id' => $usd->id]);
    }

    public function test_one_invalid_business_does_not_cross_contaminate_another(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customerA = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customerA, $this->businessAttributes(['currency_code' => 'ZZZ']));

        $customerB = $this->createCustomer();
        $businessB = $this->createBusinessWithWorkspace($customerB, $this->businessAttributes(['currency_code' => 'USD']));

        try {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        } catch (BusinessCurrencyUnresolvableException) {
            // expected — Business A's own failure
        }

        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);

        $this->assertDatabaseMissing('business_usage_wallets', ['business_id' => $businessA->id]);
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $businessB->id]);
    }

    public function test_later_currency_code_change_does_not_rewrite_existing_wallet_currency(): void
    {
        $usd = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'USD']));
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);

        DB::table('businesses')->where('id', $business->id)->update(['currency_code' => 'EUR']);

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame($usd->id, $wallet->currency_id);
    }
}
