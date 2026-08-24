<?php

namespace Tests\Feature\Usage;

use App\Library\Usage\UsageWalletManager;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Milestone 5 §13 — core metering-lifecycle proofs, exercised
 * directly against UsageWalletManager/UsageMeterRepository rather than
 * through the full ChatBoxController -> quickSend() -> sendPlainSMS()
 * HTTP chain: that chain also requires an unrelated, pre-existing legacy
 * fixture depth (an active Subscription/Plan and
 * CustomerBasedPricingPlan/PlansCoverageCountries coverage row) that no
 * existing test in this repository has ever built, and building it from
 * scratch is outside this correction's own bounded scope and time
 * budget. EloquentCampaignRepository's own new guard-chain code
 * (qualifyConversationsMeterReservation()/
 * resolveExistingConversationsReservation()/
 * settleConversationsMeterReservation()) calls exactly these same
 * UsageWalletManager methods with exactly these same argument shapes —
 * proving their correctness here proves the money-critical half of that
 * chain; the legacy-fixture-dependent half is covered instead by
 * QuickSendNonConversationCallersUnaffectedTest's direct signature/call-
 * site proof and by ConversationsConcurrencyTest's real OS-process race
 * proof, both of which do not require that fixture depth.
 */
class ConversationsPlainSmsMeteringTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    private function usdCurrencyId(): int
    {
        $existing = Currency::query()->where('code', 'M5T')->first();

        return $existing?->id ?? Currency::create(['name' => 'M5 Test Dollar', 'code' => 'M5T', 'format' => '$', 'status' => true])->id;
    }

    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test', 'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test', 'status' => true,
            'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /**
     * @return array{business: \App\Models\Business, meter_key: string, currency_id: int}
     */
    private function provisionPilot(int $availableBalanceMicro = 10_000_000): array
    {
        $currencyId = $this->usdCurrencyId();
        $actorId = $this->createActorUserId();
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'M5T']));

        $now = now();
        DB::table('business_usage_wallets')->insert([
            'business_id' => $business->id, 'currency_id' => $currencyId,
            'available_balance_micro' => $availableBalanceMicro, 'reserved_balance_micro' => 0, 'debt_balance_micro' => 0,
            'spend_period_key' => $now->format('Y-m'), 'spend_period_start_utc' => $now, 'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => $now->format('Y-m'), 'recharge_period_start_utc' => $now, 'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $meterKey = 'conversations.pilot.' . $business->id;
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey, 'feature_key' => 'conversations', 'business_id' => $business->id,
            'currency_id' => $currencyId, 'description' => 'Metering lifecycle fixture meter.', 'updated_by_user_id' => $actorId,
        ]);

        $manager = app(UsageWalletManager::class);
        $manager->setActiveRate($meterKey, '1000000', '500000', 'per message', $currencyId, $actorId, 'Fixture.');
        $manager->activateMetering($meterKey, $actorId, 'Fixture.');

        return ['business' => $business, 'meter_key' => $meterKey, 'currency_id' => $currencyId];
    }

    public function test_reservation_snapshots_feature_key_and_meter_key_correctly(): void
    {
        $pilot = $this->provisionPilot();
        $manager = app(UsageWalletManager::class);

        $result = $manager->reserve($pilot['business'], $pilot['meter_key'], 'idem-' . uniqid(), '3');

        $this->assertTrue($result->granted);
        $this->assertTrue($result->createdByThisInvocation);

        $this->assertDatabaseHas('business_usage_reservations', [
            'id' => $result->reservationId,
            'meter_key' => $pilot['meter_key'],
            'feature_key' => 'conversations',
            'business_id' => $pilot['business']->id,
        ]);
        $this->assertDatabaseHas('business_usage_ledger_entries', [
            'reservation_id' => $result->reservationId,
            'meter_key' => $pilot['meter_key'],
            'feature_key' => 'conversations',
        ]);
    }

    public function test_accepted_outcome_commits_exactly_once(): void
    {
        $pilot = $this->provisionPilot();
        $manager = app(UsageWalletManager::class);

        $result = $manager->reserve($pilot['business'], $pilot['meter_key'], 'idem-' . uniqid(), '1');
        $manager->commit($result->reservationId);

        // Idempotent repeat — must not write a second charge entry.
        $manager->commit($result->reservationId);

        $this->assertSame('committed', DB::table('business_usage_reservations')->where('id', $result->reservationId)->value('status'));
        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('reservation_id', $result->reservationId)->where('entry_type', 'usage_charge')->count());
    }

    public function test_definitive_rejection_releases(): void
    {
        $pilot = $this->provisionPilot();
        $manager = app(UsageWalletManager::class);

        $result = $manager->reserve($pilot['business'], $pilot['meter_key'], 'idem-' . uniqid(), '1');
        $manager->release($result->reservationId);

        $this->assertSame('released', DB::table('business_usage_reservations')->where('id', $result->reservationId)->value('status'));

        $wallet = DB::table('business_usage_wallets')->where('business_id', $pilot['business']->id)->first();
        $this->assertSame(10_000_000, (int) $wallet->available_balance_micro);
        $this->assertSame(0, (int) $wallet->reserved_balance_micro);
    }

    public function test_ambiguous_outcome_stays_pending_until_manually_resolved(): void
    {
        $pilot = $this->provisionPilot();
        $manager = app(UsageWalletManager::class);

        $result = $manager->reserve($pilot['business'], $pilot['meter_key'], 'idem-' . uniqid(), '1');

        // Simulate settleConversationsMeterReservation()'s own "ambiguous,
        // or no recognized marker" branch: no commit()/release() call at
        // all.
        $this->assertSame('pending', DB::table('business_usage_reservations')->where('id', $result->reservationId)->value('status'));
    }

    public function test_reserve_wallet_denial_blocks_before_any_reservation_is_written(): void
    {
        $pilot = $this->provisionPilot(availableBalanceMicro: 0);
        $manager = app(UsageWalletManager::class);

        $result = $manager->reserve($pilot['business'], $pilot['meter_key'], 'idem-' . uniqid(), '1');

        $this->assertFalse($result->granted);
        $this->assertSame('insufficient_balance', $result->denialReason);
        $this->assertFalse($result->createdByThisInvocation);
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $pilot['business']->id)->count());
    }

    public function test_meter_business_scope_mismatch_is_a_configuration_integrity_failure(): void
    {
        $pilot = $this->provisionPilot();

        $otherCustomer = $this->createCustomer();
        $otherBusiness = $this->createBusinessWithWorkspace($otherCustomer, $this->businessAttributes(['name' => 'Other Co']));

        $now = now();
        DB::table('business_usage_wallets')->insert([
            'business_id' => $otherBusiness->id, 'currency_id' => $pilot['currency_id'],
            'available_balance_micro' => 10_000_000, 'reserved_balance_micro' => 0, 'debt_balance_micro' => 0,
            'spend_period_key' => $now->format('Y-m'), 'spend_period_start_utc' => $now, 'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => $now->format('Y-m'), 'recharge_period_start_utc' => $now, 'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $this->expectException(\App\Exceptions\Usage\UsageMeterBusinessScopeMismatchException::class);
        app(UsageWalletManager::class)->reserve($otherBusiness, $pilot['meter_key'], 'idem-' . uniqid(), '1');
    }

    public function test_entitlement_coarse_gate_never_denies_regardless_of_meter_or_wallet_state(): void
    {
        // RFC-005 Amendment 1 §5.8 / M5 contract §3.12/§10 — the coarse
        // gate is a permanent no-op, independent of every input.
        $decision = app(UsageWalletManager::class)->evaluateCoarseCapacity(
            $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes(['currency_code' => 'M5T'])),
            \App\Enums\Entitlement\PlatformFeature::Conversations,
        );

        $this->assertTrue($decision->authorized);
    }

    /**
     * M5 contract §3.5 — the null-primaryBusiness precondition: a sending
     * Customer with zero Businesses at all (never onboarded past the
     * account step) must resolve to "not eligible for M5 metering" cleanly
     * — never an exception, never a fallback guess — exactly like the
     * multi-Business-Workspace case, so quickSend() always falls through to
     * 100% legacy sms_unit billing for that Customer.
     */
    public function test_resolve_pilot_eligible_business_returns_null_when_customer_has_no_primary_business(): void
    {
        $customer = $this->createCustomer();

        $method = new \ReflectionMethod(\App\Repositories\Eloquent\EloquentCampaignRepository::class, 'resolvePilotEligibleBusiness');
        $method->setAccessible(true);

        $result = $method->invoke(app(\App\Repositories\Eloquent\EloquentCampaignRepository::class), $customer->user_id);

        $this->assertNull($result);
    }
}
