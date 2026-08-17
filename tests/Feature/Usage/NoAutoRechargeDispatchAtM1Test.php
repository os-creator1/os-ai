<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §5.7/§14 (renamed and redefined, Correction Round 1)
 * — originally the direct regression test for "M3, not M1, owns this
 * trigger." Corrected here, directly during the M3 implementation session
 * resumed under RFC-005-M3-CONTRACT.md — not a separate contract
 * correction round, since this file was never on that contract's own
 * 113-item allowlist and was discovered only by the M3 implementation's
 * own full regression run, exactly mirroring the NoStripeOrProviderCodeAtM2Test
 * discovery that triggered Correction Round 2. M3 (RFC-005 §36 item 3) is
 * now the milestone that owns EvaluateBusinessAutoRecharge, and
 * UsageWalletManager::reserve()/commit() now legitimately dispatch it (M3
 * contract §15, item 100) at every negative-available_delta_micro write
 * site. The invariant this file still proves: with auto_recharge_enabled
 * left at its M1 default (false), that dispatch is a safe,
 * provider-call-free no-op — never a funding attempt, never a Stripe call
 * — exactly as if the M3 mechanism did not exist for a Business that
 * never configured it. Full behavioral coverage of the dispatch itself is
 * AutoRechargeThresholdAndCapTest.php and AutoRechargeLoopPreventionTest.php
 * (M3 contract §25 items 94-95).
 */
class NoAutoRechargeDispatchAtM1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_evaluate_business_auto_recharge_class_exists_and_is_owned_by_m3(): void
    {
        $this->assertTrue(
            class_exists('App\\Jobs\\Usage\\EvaluateBusinessAutoRecharge'),
            'EvaluateBusinessAutoRecharge is owned by M3 (RFC-005 §36 item 3) and now exists.',
        );
    }

    public function test_successful_reserve_with_auto_recharge_disabled_creates_no_funding_attempt_or_provider_call(): void
    {
        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $manager->setActiveRate('crm', '1000000', '500000', 'per message', Currency::query()->first()->id, 1, 'Fixture.');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        // auto_recharge_enabled is left at its M1 default (false) — never
        // configured by this test.
        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertFalse((bool) $wallet->auto_recharge_enabled);

        $result = $manager->reserve($business, 'crm', (string) Str::uuid(), '1');

        $this->assertTrue($result->granted);

        // The Reservation entry's own available_delta_micro is negative
        // (RFC-005 §13) — the exact condition that dispatches
        // EvaluateBusinessAutoRecharge (M3 contract §15) — and the job
        // runs synchronously inline (QUEUE_CONNECTION=sync). Its own
        // auto_recharge_enabled guard must still result in zero
        // provider-facing effect for a Business that never configured it.
        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $result->reservationId,
            'entry_type' => 'reservation',
        ]);
        $entry = DB::table('business_usage_ledger_entries')->where('reservation_id', $result->reservationId)->first();
        $this->assertLessThan(0, (int) $entry->available_delta_micro);

        $this->assertSame(0, DB::table('business_funding_attempts')->where('business_id', $business->id)->count());
    }
}
