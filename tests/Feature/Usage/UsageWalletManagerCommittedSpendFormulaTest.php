<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 §13's corrected committed-amount formula, M1 contract §14 —
 * verified exactly against a mixed sequence of exact-match,
 * under-reservation, and overage-with-debt commits, independently
 * cross-checked against a from-scratch ledger recomputation:
 * UsageCharge committed = -reserved_delta_micro;
 * UsageOverageCharge committed = (-available_delta_micro) + debt_delta_micro.
 */
class UsageWalletManagerCommittedSpendFormulaTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function business(): Business
    {
        $customer = $this->createCustomer();

        return $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
    }

    /**
     * A genuine, disposable actor User — usage_meters.updated_by_user_id
     * has no database FK, so UsageMeterRepository::create() enforces
     * actor existence at the application layer instead (RFC-005 Amendment
     * 1 §B).
     */
    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    /**
     * RFC-005 Amendment 1 Slice 2 CUTOVER §2's locked fixture sequence: a
     * genuine, disposable UsageMeter must exist before setActiveRate()
     * creates/activates a rate for it, and activateMetering() must flip
     * is_metered before reserve() will accept it.
     */
    private function activateRate(string $retailRateMicro = '1000000'): void
    {
        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;

        app(UsageMeterRepository::class)->create([
            'meter_key' => 'crm',
            'feature_key' => 'crm',
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'Slice 2 cutover fixture meter.',
            'updated_by_user_id' => $actorId,
        ]);

        app(UsageWalletManager::class)->setActiveRate(
            'crm', $retailRateMicro, '500000', 'per message',
            $currencyId, $actorId, 'Fixture.',
        );

        app(UsageWalletManager::class)->activateMetering('crm', $actorId, 'Fixture.');
    }

    public function test_committed_spend_matches_from_scratch_ledger_recomputation(): void
    {
        $business = $this->business();
        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => 20_000_000]);

        // Exact-match commit.
        $r1 = $manager->reserve($business, 'crm', (string) Str::uuid(), '2');
        $manager->commit($r1->reservationId, '2'); // committed = 2,000,000

        // Under-reservation commit.
        $r2 = $manager->reserve($business, 'crm', (string) Str::uuid(), '3');
        $manager->commit($r2->reservationId, '1'); // committed = 1,000,000

        // Overage commit, split across available and debt.
        $r3 = $manager->reserve($business, 'crm', (string) Str::uuid(), '2');
        $manager->commit($r3->reservationId, '10'); // committed = 10,000,000, overage 8,000,000

        $wallet = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        // Independent from-scratch recomputation via the corrected formula.
        $entries = DB::table('business_usage_ledger_entries')->where('business_id', $business->id)->get();
        $recomputed = 0;
        foreach ($entries as $entry) {
            if ($entry->entry_type === 'usage_charge') {
                $recomputed += -$entry->reserved_delta_micro;
            } elseif ($entry->entry_type === 'usage_overage_charge') {
                $recomputed += (-$entry->available_delta_micro) + $entry->debt_delta_micro;
            }
        }

        $this->assertSame(13_000_000, $recomputed); // 2,000,000 + 1,000,000 + 10,000,000
        $this->assertSame($recomputed, (int) $wallet->committed_spend_this_period_micro);
    }

    public function test_reversal_entry_types_never_decrement_committed_spend(): void
    {
        $business = $this->business();
        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => 5_000_000]);

        $r1 = $manager->reserve($business, 'crm', (string) Str::uuid(), '2');
        $manager->commit($r1->reservationId, '2');

        $before = (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('committed_spend_this_period_micro');

        // Simulate a UsageChargeReversal ledger entry directly (M1 never
        // writes this entry type itself, but the invariant under test is
        // that no code path — including a direct ledger insert — is ever
        // consulted by the wallet's own cached-counter update logic; only
        // commit() ever touches committed_spend_this_period_micro).
        DB::table('business_usage_ledger_entries')->insert([
            'business_id' => $business->id,
            'wallet_id' => DB::table('business_usage_wallets')->where('business_id', $business->id)->value('id'),
            'entry_type' => 'usage_charge_reversal',
            'available_delta_micro' => 2_000_000,
            'reserved_delta_micro' => 0,
            'debt_delta_micro' => 0,
            'currency_id' => Currency::query()->first()->id,
            'correlation_key' => 'reversal-' . uniqid(),
            'created_at' => now(),
        ]);

        $after = (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('committed_spend_this_period_micro');

        $this->assertSame($before, $after);
    }

    public function test_cap_configuration_change_is_prospective_only(): void
    {
        $business = $this->business();
        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => 5_000_000]);

        $r1 = $manager->reserve($business, 'crm', (string) Str::uuid(), '2');
        $manager->commit($r1->reservationId, '2');

        $committedBefore = (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('committed_spend_this_period_micro');

        // The only real M1-era lever: changing the configured cap value
        // itself (monthly_spend_cap_micro), never the derived counter.
        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['monthly_spend_cap_micro' => 50_000_000]);

        $committedAfter = (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('committed_spend_this_period_micro');

        $this->assertSame($committedBefore, $committedAfter);
    }
}
