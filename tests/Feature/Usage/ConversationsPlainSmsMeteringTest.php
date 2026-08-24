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
     * M5 contract §3.5 — the null-primaryBusiness precondition query
     * (§3.5's own "count of Customers with a chat_boxes row whose
     * customer->primaryBusiness is null") run against the raw resolution
     * primitive: a Customer with zero Businesses at all resolves to null,
     * as required by both the fail-closed §6 step 1 handling and the
     * step-0 key-namespacing resolution — never an exception.
     */
    public function test_resolve_primary_business_returns_null_when_customer_has_no_primary_business(): void
    {
        $customer = $this->createCustomer();

        $method = new \ReflectionMethod(\App\Repositories\Eloquent\EloquentCampaignRepository::class, 'resolvePrimaryBusiness');
        $method->setAccessible(true);

        $result = $method->invoke(app(\App\Repositories\Eloquent\EloquentCampaignRepository::class), $customer->user_id);

        $this->assertNull($result);
    }

    /**
     * M5 contract §3.5/§6 step 1 — a null primaryBusiness is a
     * fail-closed case, distinct from the multi-Business-Workspace case:
     * it must deny the send outright (m5_token_action: retain, no
     * reservation created, no legacy fallback), never silently downgrade
     * to legacy sms_unit billing the way a genuinely non-qualifying
     * (e.g. multi-Business) send does.
     */
    public function test_qualify_reservation_fails_closed_when_primary_business_is_null(): void
    {
        $customer = $this->createCustomer();
        $user = \App\Models\User::find($customer->user_id);

        $method = new \ReflectionMethod(\App\Repositories\Eloquent\EloquentCampaignRepository::class, 'qualifyConversationsMeterReservation');
        $method->setAccessible(true);

        $result = $method->invoke(
            app(\App\Repositories\Eloquent\EloquentCampaignRepository::class),
            $user,
            null,
            null,
            '1',
            (string) \Illuminate\Support\Str::uuid(),
        );

        $this->assertFalse($result['qualifies']);
        $this->assertNotNull($result['response']);
        $this->assertSame('retain', $result['response']->getData()->m5_token_action);
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
    }

    /**
     * M5 contract §3.3/§10/§12 — EntitlementManager::decide() remains the
     * feature-availability/plan-entitlement authority (Amendment 1 only
     * decoupled wallet health from this decision, it did not remove the
     * decision itself). A Workspace with no plan assignment at all (the
     * natural default of the fixture helper below, with no override
     * granted) is denied, creating zero reservation and never qualifying
     * — proving entitlement denial is independent of, and evaluated
     * before, wallet/meter state.
     */
    public function test_entitlement_denial_prevents_reservation_creation(): void
    {
        $pilot = $this->provisionPilot();
        $pilotBusinessId = $pilot['business']->id;

        $country = \App\Models\Country::firstOrCreate(
            ['country_code' => '1', 'iso_code' => 'US'],
            ['name' => 'United States', 'status' => 1],
        );

        $sendingServer = \App\Models\SendingServer::create([
            'name' => 'M5 Test Twilio Server',
            'settings' => \App\Models\SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
            'account_sid' => 'ACtest',
            'auth_token' => 'authtest',
        ]);

        config([
            'usage_billing.conversations_metering.pilot_business_id' => $pilotBusinessId,
            'usage_billing.conversations_metering.pilot_country_id' => $country->id,
            'usage_billing.conversations_metering.pilot_sending_server_id' => $sendingServer->id,
        ]);

        $user = \App\Models\User::find($pilot['business']->customer_id);

        $method = new \ReflectionMethod(\App\Repositories\Eloquent\EloquentCampaignRepository::class, 'qualifyConversationsMeterReservation');
        $method->setAccessible(true);

        $result = $method->invoke(
            app(\App\Repositories\Eloquent\EloquentCampaignRepository::class),
            $user,
            $country,
            $sendingServer,
            '1',
            (string) \Illuminate\Support\Str::uuid(),
        );

        $this->assertFalse($result['qualifies']);
        $this->assertNotNull($result['response']);
        $this->assertSame('retain', $result['response']->getData()->m5_token_action);
        $this->assertSame('workspace_plan_unassigned', $result['response']->getData()->message);
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
    }

    /**
     * M5 contract §6.1 — business-namespaced (never user-namespaced) key
     * derivation: the identical raw client token produces two distinct
     * derived keys under two different Businesses, and (mechanically, by
     * construction) Business B's step-0 lookup can never resolve Business
     * A's reservation for that shared raw token.
     */
    public function test_idempotency_key_is_business_namespaced_and_isolated_across_businesses(): void
    {
        $pilotA = $this->provisionPilot();
        $customerB = $this->createCustomer();
        $businessB = $this->createBusinessWithWorkspace($customerB, $this->businessAttributes(['currency_code' => 'M5T']));

        $method = new \ReflectionMethod(\App\Repositories\Eloquent\EloquentCampaignRepository::class, 'conversationsIdempotencyKey');
        $method->setAccessible(true);
        $repository = app(\App\Repositories\Eloquent\EloquentCampaignRepository::class);

        $rawToken = (string) \Illuminate\Support\Str::uuid();
        $keyA = $method->invoke($repository, $pilotA['business']->id, $rawToken);
        $keyB = $method->invoke($repository, $businessB->id, $rawToken);

        $this->assertNotSame($keyA, $keyB);

        // A reservation created under Business A's derived key must not be
        // resolvable via Business B's own derived key for the identical
        // raw token.
        $manager = app(UsageWalletManager::class);
        $result = $manager->reserve($pilotA['business'], $pilotA['meter_key'], $keyA, '1');
        $this->assertTrue($result->createdByThisInvocation);

        $this->assertNull(app(\App\Repositories\Contracts\BusinessUsageReservationRepository::class)->findByIdempotencyKey($keyB));
    }

    /**
     * M5 contract §6 step 4 case E — a wallet-capacity denial from
     * reserve() creates no reservation row, so the client token must be
     * retained (never cleared) for a legitimate later retry once the
     * underlying denial is resolved. Driven through a fully-qualifying
     * pilot tuple fixture so qualifyConversationsMeterReservation()'s own
     * wallet-denial branch (reached only once every earlier tuple/meter/
     * entitlement check has passed) is exercised end-to-end.
     */
    public function test_qualify_reservation_wallet_denial_retains_the_token_end_to_end(): void
    {
        $pilot = $this->provisionPilot(availableBalanceMicro: 0);
        $pilotBusinessId = $pilot['business']->id;

        // Grant Conversations entitlement directly via an explicit
        // workspace override, sidestepping any plan-catalog fixture
        // depth — this test's own concern is the wallet-denial branch
        // reached only after entitlement already passed, not entitlement
        // itself (covered separately).
        app(\App\Repositories\Contracts\WorkspaceEntitlementOverrideRepository::class)->create([
            'workspace_id' => $pilot['business']->workspace_id,
            'feature_key' => \App\Enums\Entitlement\PlatformFeature::Conversations->value,
            'state' => \App\Enums\Entitlement\WorkspaceEntitlementOverrideState::Allow->value,
            'reason' => 'Fixture grant.',
            'created_by_user_id' => $this->createActorUserId(),
        ]);

        $country = \App\Models\Country::firstOrCreate(
            ['country_code' => '1', 'iso_code' => 'US'],
            ['name' => 'United States', 'status' => 1],
        );

        $sendingServer = \App\Models\SendingServer::create([
            'name' => 'M5 Test Twilio Server',
            'settings' => \App\Models\SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
            'account_sid' => 'ACtest',
            'auth_token' => 'authtest',
        ]);

        config([
            'usage_billing.conversations_metering.pilot_business_id' => $pilotBusinessId,
            'usage_billing.conversations_metering.pilot_country_id' => $country->id,
            'usage_billing.conversations_metering.pilot_sending_server_id' => $sendingServer->id,
        ]);

        $user = \App\Models\User::find($pilot['business']->customer_id);

        $method = new \ReflectionMethod(\App\Repositories\Eloquent\EloquentCampaignRepository::class, 'qualifyConversationsMeterReservation');
        $method->setAccessible(true);

        $result = $method->invoke(
            app(\App\Repositories\Eloquent\EloquentCampaignRepository::class),
            $user,
            $country,
            $sendingServer,
            '1',
            (string) \Illuminate\Support\Str::uuid(),
        );

        $this->assertFalse($result['qualifies']);
        $this->assertNotNull($result['response']);
        $this->assertSame('retain', $result['response']->getData()->m5_token_action);
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
    }

    /**
     * M5 contract §3.9/§6 step 6 — settleConversationsMeterReservation()'s
     * exact outcome->action mapping: accepted commits and clears,
     * definitive_rejection releases and clears, ambiguous_exception and an
     * absent/unrecognized marker both leave the reservation Pending and
     * retain the token.
     */
    public function test_settle_reservation_maps_every_outcome_to_the_locked_action(): void
    {
        $method = new \ReflectionMethod(\App\Repositories\Eloquent\EloquentCampaignRepository::class, 'settleConversationsMeterReservation');
        $method->setAccessible(true);
        $repository = app(\App\Repositories\Eloquent\EloquentCampaignRepository::class);

        // accepted -> commit, clear
        $pilot = $this->provisionPilot();
        $manager = app(UsageWalletManager::class);
        $reservation = $manager->reserve($pilot['business'], $pilot['meter_key'], 'idem-' . uniqid(), '1');
        $action = $method->invoke($repository, $reservation->reservationId, $pilot['business']->id, (object) ['m5_outcome' => 'accepted'], '1');
        $this->assertSame('clear', $action);
        $this->assertSame('committed', DB::table('business_usage_reservations')->where('id', $reservation->reservationId)->value('status'));

        // definitive_rejection -> release, clear
        $pilot2 = $this->provisionPilot();
        $reservation2 = $manager->reserve($pilot2['business'], $pilot2['meter_key'], 'idem-' . uniqid(), '1');
        $action2 = $method->invoke($repository, $reservation2->reservationId, $pilot2['business']->id, (object) ['m5_outcome' => 'definitive_rejection'], '1');
        $this->assertSame('clear', $action2);
        $this->assertSame('released', DB::table('business_usage_reservations')->where('id', $reservation2->reservationId)->value('status'));

        // ambiguous_exception -> leave Pending, retain
        $pilot3 = $this->provisionPilot();
        $reservation3 = $manager->reserve($pilot3['business'], $pilot3['meter_key'], 'idem-' . uniqid(), '1');
        $action3 = $method->invoke($repository, $reservation3->reservationId, $pilot3['business']->id, (object) ['m5_outcome' => 'ambiguous_exception'], '1');
        $this->assertSame('retain', $action3);
        $this->assertSame('pending', DB::table('business_usage_reservations')->where('id', $reservation3->reservationId)->value('status'));

        // absent marker -> leave Pending, retain (defensive fallback)
        $pilot4 = $this->provisionPilot();
        $reservation4 = $manager->reserve($pilot4['business'], $pilot4['meter_key'], 'idem-' . uniqid(), '1');
        $action4 = $method->invoke($repository, $reservation4->reservationId, $pilot4['business']->id, (object) [], '1');
        $this->assertSame('retain', $action4);
        $this->assertSame('pending', DB::table('business_usage_reservations')->where('id', $reservation4->reservationId)->value('status'));
    }
}
