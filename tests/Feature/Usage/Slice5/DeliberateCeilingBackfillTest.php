<?php

namespace Tests\Feature\Usage\Slice5;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Usage\PayerType;
use App\Library\Usage\UsageWalletManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Usage\Slice5\Concerns\Slice5Fixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 5 — Correction Round 1 §15: the additive
 * migration 2026_09_11_120004 adds the refusal-alert marker column and,
 * idempotently, switches OFF every enabled automatic top-up whose monthly
 * ceiling is missing, zero, below its preset or above the approved $500
 * maximum — preserving every balance, ledger row, payer assignment, stored
 * value and consent record, and never fabricating consent or re-enabling.
 */
class DeliberateCeilingBackfillTest extends TestCase
{
    use RefreshDatabase;
    use Slice5Fixtures;

    private function migration(): object
    {
        return require base_path('database/migrations/2026_09_11_120004_require_deliberate_ceiling_for_enabled_auto_recharge_on_business_usage_wallets.php');
    }

    public function test_the_backfill_disables_only_non_compliant_enabled_rows_and_preserves_everything_else(): void
    {
        [$owner, , $workspace] = $this->tenantWithWallet(WorkspacePlanTier::Agency, 'Agency House', 'Northwind Agency');
        $this->assertTrue(Schema::hasColumn('business_usage_wallets', 'auto_recharge_refusal_notified_at'));

        $rows = [];
        foreach ([
            'compliant' => ['auto_recharge_enabled' => true, 'monthly_recharge_cap_micro' => 100_000_000],
            'missing_ceiling' => ['auto_recharge_enabled' => true, 'monthly_recharge_cap_micro' => null],
            'above_maximum' => ['auto_recharge_enabled' => true, 'monthly_recharge_cap_micro' => 600_000_000],
            'below_preset' => ['auto_recharge_enabled' => true, 'monthly_recharge_cap_micro' => 4_990_000],
            'zero_ceiling' => ['auto_recharge_enabled' => true, 'monthly_recharge_cap_micro' => 0],
            'already_off' => ['auto_recharge_enabled' => false, 'monthly_recharge_cap_micro' => null],
        ] as $label => $columns) {
            [, $business] = $this->clientBusiness($workspace, 'Client ' . $label);
            $this->setPayer($business, $label === 'below_preset' ? PayerType::Business : PayerType::Workspace);
            DB::table('business_usage_wallets')->where('business_id', $business->id)->update(array_merge([
                'auto_recharge_threshold_micro' => 2_000_000,
                'auto_recharge_amount_micro' => 5_000_000,
                'auto_recharge_consented_at' => '2026-09-01 10:00:00',
                'auto_recharge_consented_by_user_id' => $owner->user_id,
                'available_balance_micro' => 7_000_000,
                'refundable_paid_available_micro' => 7_000_000,
                'recharged_this_period_micro' => 5_000_000,
            ], $columns));
            DB::table('business_usage_ledger_entries')->insert([
                'business_id' => $business->id, 'wallet_id' => $this->walletRow($business)->id, 'entry_type' => 'paid_top_up',
                'available_delta_micro' => 7_000_000, 'reserved_delta_micro' => 0, 'debt_delta_micro' => 0, 'refundable_paid_delta_micro' => 7_000_000,
                'currency_id' => $this->usd(), 'correlation_key' => 'backfill-fixture-' . $business->id, 'created_at' => now(),
            ]);
            $rows[$label] = $business;
        }

        $walletsBefore = collect($rows)->map(fn ($b) => (array) $this->walletRow($b))->all();
        $ledgerBefore = DB::table('business_usage_ledger_entries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $payersBefore = DB::table('business_payer_assignments')->orderBy('business_id')->get()->map(fn ($r) => (array) $r)->all();

        $this->assertSame(['disabled' => 4], $this->migration()->apply());

        foreach ($rows as $label => $business) {
            $after = (array) $this->walletRow($business);
            $expectedEnabled = $label === 'compliant' ? 1 : 0;
            $this->assertSame($expectedEnabled, (int) $after['auto_recharge_enabled'], "{$label}: enabled flag");

            // Every other column — balances, stored values, consent record — is byte-for-byte preserved.
            $before = $walletsBefore[$label];
            unset($before['auto_recharge_enabled'], $before['updated_at'], $after['auto_recharge_enabled'], $after['updated_at']);
            $this->assertSame($before, $after, "{$label}: nothing but the flag changes");
            $this->assertSame('7000000', (string) $after['available_balance_micro']);
            $this->assertSame('2026-09-01 10:00:00', (string) $after['auto_recharge_consented_at'], 'Consent is neither fabricated nor erased.');
        }

        $this->assertSame($ledgerBefore, DB::table('business_usage_ledger_entries')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all(), 'Ledger history is untouched.');
        $this->assertSame($payersBefore, DB::table('business_payer_assignments')->orderBy('business_id')->get()->map(fn ($r) => (array) $r)->all(), 'Payer assignments are untouched.');
        $this->assertSame(0, DB::table('business_funding_attempts')->count());

        // Idempotent: a second application changes nothing.
        $this->assertSame(['disabled' => 0], $this->migration()->apply());
        $this->assertSame(1, (int) $this->walletRow($rows['compliant'])->auto_recharge_enabled);

        // From here on the manager keeps the invariant: the switched-off row re-enables only with a compliant ceiling.
        $this->fakeProvider();
        $this->attachFakeCard($rows['missing_ceiling'], (int) $owner->user_id);
        try {
            app(UsageWalletManager::class)->configureAutoRecharge($rows['missing_ceiling'], true, '2000000', '5000000', null, (int) $owner->user_id);
            $this->fail('Re-enabling without a ceiling must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertSame('monthly_cap_required', $e->getMessage());
        }
        app(UsageWalletManager::class)->configureAutoRecharge($rows['missing_ceiling'], true, '2000000', '5000000', '100000000', (int) $owner->user_id);
        $this->assertSame(1, (int) $this->walletRow($rows['missing_ceiling'])->auto_recharge_enabled);
    }
}
