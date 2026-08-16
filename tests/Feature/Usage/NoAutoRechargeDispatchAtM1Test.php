<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 M1 contract §5.7/§14 (renamed and redefined, Correction Round 1) —
 * a successful reserve() (whose Reservation entry has a negative
 * available_delta_micro) dispatches no job of any kind related to
 * auto-recharge; EvaluateBusinessAutoRecharge is asserted to not exist as
 * a referenced/bound class anywhere in M1's own code. The direct
 * regression test for §5.7's corrected conclusion that M3, not M1, owns
 * this trigger.
 */
class NoAutoRechargeDispatchAtM1Test extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_evaluate_business_auto_recharge_class_does_not_exist(): void
    {
        $this->assertFalse(
            class_exists('App\\Jobs\\Usage\\EvaluateBusinessAutoRecharge'),
            'EvaluateBusinessAutoRecharge must not exist at M1 (RFC-005 §36 item 3 assigns it to M3).',
        );
    }

    public function test_successful_reserve_dispatches_no_job_or_queued_work_of_any_kind(): void
    {
        Bus::fake();
        Queue::fake();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $manager->setActiveRate('crm', '1000000', '500000', 'per message', Currency::query()->first()->id, 1, 'Fixture.');
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 5_000_000]);

        $result = $manager->reserve($business, 'crm', (string) Str::uuid(), '1');

        $this->assertTrue($result->granted);

        // The Reservation entry's own available_delta_micro is negative
        // (RFC-005 §13) — the exact condition that would trigger
        // EvaluateBusinessAutoRecharge in the RFC's final-state design —
        // yet no job of any kind is ever dispatched at M1.
        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $result->reservationId,
            'entry_type' => 'reservation',
        ]);
        $entry = DB::table('business_usage_ledger_entries')->where('reservation_id', $result->reservationId)->first();
        $this->assertLessThan(0, (int) $entry->available_delta_micro);

        Bus::assertNothingDispatched();
        Queue::assertNothingPushed();
    }
}
