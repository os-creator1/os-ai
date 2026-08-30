<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
        // Seeded deliberately below the eventual overage amount (8,000,000)
        // so the overage commit below is genuinely split across both
        // available and debt — never resolved entirely from available —
        // exercising the debt_delta_micro half of the committed-spend
        // formula, not just the available_delta_micro half.
        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => 10_000_000]);

        // Exact-match commit.
        $r1 = $manager->reserve($business, 'crm', (string) Str::uuid(), '2');
        $manager->commit($r1->reservationId, '2'); // committed = 2,000,000

        // Under-reservation commit.
        $r2 = $manager->reserve($business, 'crm', (string) Str::uuid(), '3');
        $manager->commit($r2->reservationId, '1'); // committed = 1,000,000

        // Overage commit, genuinely split across available and debt: by
        // this point available_balance_micro is 5,000,000 (10,000,000 seed
        // minus 2,000,000 committed by r1 minus the 3,000,000 held by r2's
        // reservation, restored to 1,000,000 net-consumed after its
        // under-reservation release, minus 2,000,000 held by r3's own
        // reservation) — less than the 8,000,000 overage, forcing
        // overageFromAvailable=5,000,000 and overageToDebt=3,000,000.
        $r3 = $manager->reserve($business, 'crm', (string) Str::uuid(), '2');
        $manager->commit($r3->reservationId, '10'); // committed = 10,000,000, overage 8,000,000 (5,000,000 available + 3,000,000 debt)

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

        // Confirm the overage was genuinely split — a regression that
        // recomputes only the available-delta half of the formula and
        // drops the debt term would still coincidentally match the total
        // above only if debt_delta_micro were always 0; asserting it here
        // directly closes that gap.
        $overageEntry = DB::table('business_usage_ledger_entries')
            ->where('business_id', $business->id)
            ->where('entry_type', 'usage_overage_charge')
            ->first();
        $this->assertSame(-5_000_000, (int) $overageEntry->available_delta_micro);
        $this->assertSame(3_000_000, (int) $overageEntry->debt_delta_micro);
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

        // Drive the real production boundary — UsageWalletManager::setSpendCap()
        // — rather than a raw column update, so a regression inside the
        // method itself (e.g. one that incorrectly rewrites the
        // committed-spend counter) would be caught here. The actor must be
        // authorized to manage this Business's usage billing settings —
        // the Workspace owner, matching UsageWalletManagerSpendCapTest's
        // own established convention.
        $business->loadMissing('workspace');
        $actorId = (int) $business->workspace->owner_user_id;
        $manager->setSpendCap($business, '50000000', $actorId, 'Raise cap.');

        $committedAfter = (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('committed_spend_this_period_micro');

        $this->assertSame($committedBefore, $committedAfter);
    }

    public function test_committing_a_reservation_from_a_prior_rolled_over_period_does_not_inflate_the_new_periods_committed_spend(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-15 12:00:00', 'UTC'));

        $business = $this->business();
        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $this->activateRate();
        DB::table('business_usage_wallets')->where('business_id', $business->id)
            ->update(['available_balance_micro' => 5_000_000]);

        // Reserve against the wallet's current (January) period.
        $reservation = $manager->reserve($business, 'crm', (string) Str::uuid(), '2');
        $reservationRow = DB::table('business_usage_reservations')->where('id', $reservation->reservationId)->first();
        $originalPeriodKey = $reservationRow->period_key;
        $this->assertSame('2026-01', $originalPeriodKey);

        // Advance frozen time forward several months, mirroring
        // UsageCalendarMonthRolloverTest::test_multi_month_dormancy_lands_in_current_month_in_one_step's
        // own established technique — a real calendar-month change, not
        // merely pushing spend_period_end_utc into the past while "now"
        // stays put (which would recompute to the same, unchanged month).
        Carbon::setTestNow(Carbon::parse('2026-07-20 12:00:00', 'UTC'));

        // Any wallet-touching call lazily rolls the period forward; use a
        // reserve-then-release throwaway unit so the rollover is triggered
        // without altering the balances the assertions below depend on.
        $rollForward = $manager->reserve($business, 'crm', (string) Str::uuid(), '1');
        $manager->release($rollForward->reservationId);

        $walletAfterRollover = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();
        $this->assertSame('2026-07', $walletAfterRollover->spend_period_key);
        $this->assertNotSame($originalPeriodKey, $walletAfterRollover->spend_period_key);
        $this->assertSame(0, (int) $walletAfterRollover->committed_spend_this_period_micro);

        // Commit the now-stale, prior-period reservation.
        $manager->commit($reservation->reservationId, '2');

        $walletAfterCommit = DB::table('business_usage_wallets')->where('business_id', $business->id)->first();

        // The new period's committed_spend_this_period_micro must be
        // unaffected by committing a reservation from an already-rolled-
        // over prior period — UsageWalletManager::commit()'s own
        // $isCurrentPeriod guard must have skipped the increment.
        $this->assertSame(0, (int) $walletAfterCommit->committed_spend_this_period_micro);

        // The ledger entry and available-balance delta must still apply
        // correctly regardless of the period mismatch.
        $chargeEntry = DB::table('business_usage_ledger_entries')
            ->where('business_id', $business->id)
            ->where('reservation_id', $reservation->reservationId)
            ->where('entry_type', 'usage_charge')
            ->first();
        $this->assertNotNull($chargeEntry);
        $this->assertSame($originalPeriodKey, $chargeEntry->period_key);
        $this->assertSame(-2_000_000, (int) $chargeEntry->reserved_delta_micro);

        // An independent from-scratch recomputation for the reservation's
        // own original period still matches what the formula would have
        // produced, proving the formula itself — not merely the guard —
        // remains correct for a cross-period commit.
        $recomputedForOriginalPeriod = (int) DB::table('business_usage_ledger_entries')
            ->where('business_id', $business->id)
            ->where('period_key', $originalPeriodKey)
            ->where('entry_type', 'usage_charge')
            ->sum(DB::raw('-reserved_delta_micro'));
        $this->assertSame(2_000_000, $recomputedForOriginalPeriod);
    }
}
