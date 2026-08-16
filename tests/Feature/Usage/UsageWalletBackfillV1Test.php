<?php

namespace Tests\Feature\Usage;

use App\Exceptions\Usage\BusinessCurrencyUnresolvableException;
use App\Exceptions\Usage\UsageWalletBackfillIncompleteException;
use App\Library\Usage\Migration\UsageWalletBackfillV1;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §9.2/§14 — a wallet is created for every existing
 * Business, each resolved from that specific Business's own currency_code;
 * a Business with an unresolvable currency is skipped with no partial
 * state, its failure recorded, and processing continues; the final
 * assertion fails with the exact remaining count and failure list; no
 * legacy payment/billing table is touched.
 */
class UsageWalletBackfillV1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_backfill_creates_a_wallet_for_every_business_using_its_own_currency(): void
    {
        $usd = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        $eur = Currency::create(['name' => 'Euro', 'code' => 'EUR', 'format' => '€', 'status' => true]);

        $customerA = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customerA, $this->businessAttributes(['currency_code' => 'USD']));

        $customerB = $this->createCustomer();
        $businessB = $this->createBusinessWithWorkspace($customerB, $this->businessAttributes(['currency_code' => 'EUR']));

        $result = (new UsageWalletBackfillV1())->run();

        $this->assertSame(2, $result->businessesProcessed);
        $this->assertSame(2, $result->walletsCreated);
        $this->assertSame(0, $result->remainingUnwalletedCount);
        $this->assertSame([], $result->currencyResolutionFailures);

        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $businessA->id, 'currency_id' => $usd->id]);
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $businessB->id, 'currency_id' => $eur->id]);
    }

    public function test_business_with_unresolvable_currency_is_skipped_and_recorded_while_processing_continues(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customerA = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customerA, $this->businessAttributes(['currency_code' => 'ZZZ']));

        $customerB = $this->createCustomer();
        $businessB = $this->createBusinessWithWorkspace($customerB, $this->businessAttributes(['currency_code' => 'USD']));

        try {
            (new UsageWalletBackfillV1())->run();
            $this->fail('Expected UsageWalletBackfillIncompleteException.');
        } catch (UsageWalletBackfillIncompleteException $e) {
            $this->assertSame(1, $e->remainingUnwalletedCount);
            $this->assertCount(1, $e->currencyResolutionFailures);
            $this->assertSame($businessA->id, $e->currencyResolutionFailures[0]['business_id']);
            $this->assertSame(BusinessCurrencyUnresolvableException::CLASSIFICATION_NOT_FOUND, $e->currencyResolutionFailures[0]['classification']);
        }

        // Business B, unaffected by Business A's failure, still got its wallet.
        $this->assertDatabaseHas('business_usage_wallets', ['business_id' => $businessB->id]);
        $this->assertDatabaseMissing('business_usage_wallets', ['business_id' => $businessA->id]);
    }

    public function test_rerun_is_idempotent_and_makes_zero_writes_when_fully_walleted(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        (new UsageWalletBackfillV1())->run();
        $countAfterFirstRun = DB::table('business_usage_wallets')->count();

        $secondResult = (new UsageWalletBackfillV1())->run();

        $this->assertSame($countAfterFirstRun, DB::table('business_usage_wallets')->count());
        $this->assertSame(0, $secondResult->walletsCreated);
        $this->assertSame(0, $secondResult->remainingUnwalletedCount);
    }

    public function test_no_legacy_payment_table_is_touched(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        (new UsageWalletBackfillV1())->run();

        $legacyTables = ['payment_methods', 'invoices', 'subscription_transactions', 'subscriptions'];
        foreach ($queries as $sql) {
            foreach ($legacyTables as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }
        }
    }
}
