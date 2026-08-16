<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageBillingPresenter;
use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M2 contract §9/§10 — ledger pagination correctness; cross-
 * Business ledger entries never leak onto another Business's page.
 */
class UsageBillingLedgerPaginationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function insertLedgerEntries(int $businessId, int $walletId, int $currencyId, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('business_usage_ledger_entries')->insert([
                'business_id' => $businessId,
                'wallet_id' => $walletId,
                'entry_type' => 'manual_credit',
                'available_delta_micro' => 1000,
                'reserved_delta_micro' => 0,
                'debt_delta_micro' => 0,
                'currency_id' => $currencyId,
                'correlation_key' => 'test-' . $businessId . '-' . $i . '-' . uniqid(),
                'created_at' => now()->subMinutes($i),
            ]);
        }
    }

    public function test_ledger_is_paginated_at_twenty_five_per_page(): void
    {
        $currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $walletId = DB::table('business_usage_wallets')->where('business_id', $business->id)->value('id');

        $this->insertLedgerEntries($business->id, $walletId, $currencyId, 30);

        $viewModel = app(UsageBillingPresenter::class)->buildDashboardViewModel($business);

        $this->assertSame(25, $viewModel->ledger->count());
        $this->assertSame(30, $viewModel->ledger->total());
        $this->assertSame(2, $viewModel->ledger->lastPage());
    }

    public function test_ledger_page_never_includes_another_businesss_entries(): void
    {
        $currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $customer = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $businessB = $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessA->id);
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($businessB->id);
        $walletIdA = DB::table('business_usage_wallets')->where('business_id', $businessA->id)->value('id');
        $walletIdB = DB::table('business_usage_wallets')->where('business_id', $businessB->id)->value('id');

        $this->insertLedgerEntries($businessA->id, $walletIdA, $currencyId, 3);
        $this->insertLedgerEntries($businessB->id, $walletIdB, $currencyId, 5);

        $viewModelA = app(UsageBillingPresenter::class)->buildDashboardViewModel($businessA);

        $this->assertSame(3, $viewModelA->ledger->total());
    }

    public function test_ledger_rows_are_reverse_chronological(): void
    {
        $currencyId = Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true])->id;
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
        $walletId = DB::table('business_usage_wallets')->where('business_id', $business->id)->value('id');

        $this->insertLedgerEntries($business->id, $walletId, $currencyId, 5);

        $viewModel = app(UsageBillingPresenter::class)->buildDashboardViewModel($business);
        $timestamps = $viewModel->ledger->getCollection()->map(fn ($row) => $row->createdAt->timestamp)->all();

        $sorted = $timestamps;
        rsort($sorted);
        $this->assertSame($sorted, $timestamps);
    }
}
