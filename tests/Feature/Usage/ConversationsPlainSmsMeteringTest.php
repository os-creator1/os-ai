<?php

namespace Tests\Feature\Usage;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Entitlement\WorkspaceEntitlementOverrideState;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Usage\UsageWalletManager;
use App\Models\Campaigns;
use App\Models\Country;
use App\Models\Currency;
use App\Models\CustomerBasedPricingPlan;
use App\Models\Plan;
use App\Models\SendingServer;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use App\Repositories\Contracts\WorkspaceEntitlementOverrideRepository;
use App\Repositories\Eloquent\EloquentCampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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

        // Grant Conversations entitlement directly via a real plan
        // assignment plus an explicit workspace override — decide() reads
        // the assignment before it ever reaches the override (round-2
        // correction: an override alone, with no assignment row, still
        // denies with workspace_plan_unassigned, so this fixture was
        // silently proving the wrong branch until this fix). This test's
        // own concern is the wallet-denial branch reached only after
        // entitlement already passed, not entitlement itself (covered
        // separately).
        app(EntitlementManager::class)->assignFirstPlan(
            $pilot['business']->workspace,
            \App\Enums\Entitlement\WorkspacePlanTier::Core,
            $this->createActorUserId(),
            'Fixture assignment.',
            true,
            0,
        );

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

    // ========================================================================
    // Round 2 — real EloquentCampaignRepository::quickSend() integration
    // proofs. Builds the full legacy Subscription/Plan/CustomerBasedPricingPlan
    // fixture chain so the actual, unmodified production quickSend() method
    // runs end-to-end (country/coverage resolution, legacy sms_unit
    // pre-check, the M5 qualifying chain, response building) rather than
    // exercising its private helpers directly. Only the external provider
    // boundary (Campaigns::sendPlainSMS()) is stubbed via Mockery — quickSend()
    // itself, EntitlementManager::decide(), UsageWalletManager, and the
    // response/m5_token_action logic all run for real. Where the outcome-
    // classification seam inside SendCampaignSMS.php itself needs to be
    // proven (the ambiguous_exception case), a real (unmocked) Campaigns
    // instance is used and the real Twilio SDK is allowed to throw its own
    // real ConfigurationException synchronously (empty credentials) — no
    // network call is made or required for that path.
    // ========================================================================

    /**
     * @return array{business: \App\Models\Business, user: User, country: Country, sendingServer: SendingServer, meterKey: string}
     */
    private function buildQualifyingQuickSendFixture(
        int $availableBalanceMicro = 10_000_000,
        string $twilioAccountSid = 'ACtest',
        string $twilioAuthToken = 'authtest',
    ): array {
        $pilot = $this->provisionPilot($availableBalanceMicro);
        $business = $pilot['business'];
        $user = User::find($business->customer_id);

        // EntitlementManager::decide() requires a real WorkspacePlanAssignment
        // to exist before it ever reads a workspace override — assign one
        // first (mirroring EntitlementManagerDecisionTest's own established
        // fixture pattern), then grant Conversations explicitly via override
        // so this fixture never depends on which features a given plan tier
        // catalog happens to include.
        app(EntitlementManager::class)->assignFirstPlan(
            $business->workspace,
            \App\Enums\Entitlement\WorkspacePlanTier::Core,
            $this->createActorUserId(),
            'Fixture assignment.',
            true,
            0,
        );

        app(WorkspaceEntitlementOverrideRepository::class)->create([
            'workspace_id' => $business->workspace_id,
            'feature_key' => PlatformFeature::Conversations->value,
            'state' => WorkspaceEntitlementOverrideState::Allow->value,
            'reason' => 'Fixture grant.',
            'created_by_user_id' => $this->createActorUserId(),
        ]);

        $country = Country::firstOrCreate(
            ['country_code' => '1', 'iso_code' => 'US'],
            ['name' => 'United States', 'status' => 1],
        );

        $sendingServer = SendingServer::create([
            'name' => 'M5 Test Twilio Server ' . uniqid(),
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
            'account_sid' => $twilioAccountSid,
            'auth_token' => $twilioAuthToken,
        ]);

        config([
            'usage_billing.conversations_metering.pilot_business_id' => $business->id,
            'usage_billing.conversations_metering.pilot_country_id' => $country->id,
            'usage_billing.conversations_metering.pilot_sending_server_id' => $sendingServer->id,
        ]);

        $plan = Plan::create([
            'currency_id' => $pilot['currency_id'],
            'name' => 'M5 Test Plan',
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        Subscription::create([
            'user_id' => $user->id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        CustomerBasedPricingPlan::create([
            'user_id' => $user->id,
            'country_id' => $country->id,
            'plan_id' => $plan->id,
            'options' => json_encode([
                'plain_sms' => 0.05, 'voice_sms' => 0.10, 'mms_sms' => 0.10,
                'whatsapp_sms' => 0.10, 'viber_sms' => 0.10, 'otp_sms' => 0.10,
            ]),
            'status' => true,
        ]);

        return [
            'business' => $business,
            'user' => $user,
            'country' => $country,
            'sendingServer' => $sendingServer,
            'meterKey' => $pilot['meter_key'],
        ];
    }

    private function baseQuickSendInput(array $fixture, string $smsType = 'plain', ?string $idempotencyToken = null, string $message = 'Hello'): array
    {
        return [
            'user' => $fixture['user'],
            'sms_type' => $smsType,
            'sender_id' => 'TestSender',
            'region_code' => $fixture['country']->iso_code,
            'country_code' => $fixture['country']->country_code,
            'recipient' => '5551234567',
            'message' => $message,
            'sending_server' => $fixture['sendingServer']->id,
            'idempotency_token' => $idempotencyToken ?? (string) Str::uuid(),
        ];
    }

    private function mockCampaignReturning(string $m5Outcome, string $providerStatus): Campaigns
    {
        $campaign = \Mockery::mock(Campaigns::class);
        $campaign->shouldReceive('sendPlainSMS')->once()->andReturn((object) [
            'id' => 1, 'uid' => (string) Str::uuid(), 'to' => '14155552671', 'from' => 'TestSender',
            'message' => 'Hello', 'customer_status' => $providerStatus, 'status' => $providerStatus,
            'cost' => 0, 'sms_count' => 1, 'media_url' => null, 'm5_outcome' => $m5Outcome,
        ]);

        return $campaign;
    }

    public function test_real_quicksend_accepted_provider_outcome_commits_and_charges_wallet_only(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();
        $campaign = $this->mockCampaignReturning('accepted', 'Delivered');

        $sms_counter = new \App\Library\SMSCounter();
        $expectedSegments = $sms_counter->count('Hello')->messages;

        $legacySmsUnitBefore = $fixture['user']->sms_unit;

        $response = app(EloquentCampaignRepository::class)->quickSend(
            $campaign,
            $this->baseQuickSendInput($fixture),
            true,
        );

        $payload = $response->getData();
        $this->assertSame('success', $payload->status);
        $this->assertSame('clear', $payload->m5_token_action);

        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count());
        $reservation = DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->first();
        $this->assertSame('conversations', $reservation->feature_key);
        $this->assertSame($fixture['meterKey'], $reservation->meter_key);
        $this->assertEquals($expectedSegments, (int) $reservation->estimated_quantity);
        $this->assertSame('committed', $reservation->status);
        $this->assertEquals($expectedSegments, (int) $reservation->final_quantity);

        $this->assertSame(1, DB::table('business_usage_ledger_entries')->where('reservation_id', $reservation->id)->where('entry_type', 'usage_charge')->count());

        $wallet = DB::table('business_usage_wallets')->where('business_id', $fixture['business']->id)->first();
        $this->assertLessThan(10_000_000, (int) $wallet->available_balance_micro, 'Wallet must have been charged.');

        $fixture['user']->refresh();
        $this->assertSame($legacySmsUnitBefore, $fixture['user']->sms_unit, 'Legacy sms_unit must remain completely untouched for a qualifying M5 send.');
    }

    public function test_real_quicksend_definitive_rejection_releases_and_does_not_charge(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();
        $campaign = $this->mockCampaignReturning('definitive_rejection', 'Failed');

        $response = app(EloquentCampaignRepository::class)->quickSend(
            $campaign,
            $this->baseQuickSendInput($fixture),
            true,
        );

        $payload = $response->getData();
        $this->assertSame('error', $payload->status, 'A definitive_rejection must return status=error, not the legacy info string.');
        $this->assertSame('clear', $payload->m5_token_action);

        $reservation = DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->first();
        $this->assertSame('released', $reservation->status);

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('reservation_id', $reservation->id)->where('entry_type', 'usage_charge')->count());

        $wallet = DB::table('business_usage_wallets')->where('business_id', $fixture['business']->id)->first();
        $this->assertSame(10_000_000, (int) $wallet->available_balance_micro, 'A released reservation must fully restore the wallet.');
        $this->assertSame(0, (int) $wallet->reserved_balance_micro);
    }

    /**
     * RFC-005 Reservation Admission Correction Contract §11 — a denied
     * qualifying M5 Conversations send must never reach the provider and
     * must create no new reservation/ledger mutation, proven through the
     * REAL production guard chain (quickSend() ->
     * qualifyConversationsMeterReservation() -> UsageWalletManager::reserve()
     * -> early response), not merely through a direct reserve() call.
     * setFeatureLimit() (one of the three admission controls this
     * correction adds) is tightened to zero headroom on the fully
     * qualifying pilot Business, and the Campaigns mock asserts its
     * provider method is never invoked at all.
     */
    public function test_real_quicksend_feature_limit_denial_never_reaches_provider_or_mutates_state(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();

        app(UsageWalletManager::class)->setFeatureLimit(
            $fixture['business'],
            'conversations',
            '0',
            (int) $fixture['business']->workspace->owner_user_id,
            'No Conversations headroom at all.',
        );

        $campaign = \Mockery::mock(Campaigns::class);
        $campaign->shouldNotReceive('sendPlainSMS');

        $response = app(EloquentCampaignRepository::class)->quickSend(
            $campaign,
            $this->baseQuickSendInput($fixture),
            true,
        );

        $payload = $response->getData();
        $this->assertSame('error', $payload->status, 'A wallet-admission denial must return status=error, not reach the provider.');
        $this->assertSame('retain', $payload->m5_token_action);

        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count());
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('business_id', $fixture['business']->id)->count());
    }

    /**
     * Drives the REAL, unmocked SendCampaignSMS::sendPlainSMS() Twilio case
     * block: a Twilio SendingServer with empty credentials makes the real
     * twilio/sdk Client constructor throw a real, synchronous
     * ConfigurationException (no network call — Client::__construct()
     * throws before any HTTP request when username/password are both
     * empty) — proving the actual §3.9 classification seam sets
     * m5_outcome = 'ambiguous_exception' for a caught exception, not a
     * fabricated object.
     */
    public function test_real_quicksend_ambiguous_provider_exception_leaves_pending(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture(twilioAccountSid: '', twilioAuthToken: '');
        $campaign = new Campaigns();

        $response = app(EloquentCampaignRepository::class)->quickSend(
            $campaign,
            $this->baseQuickSendInput($fixture),
            true,
        );

        $payload = $response->getData();
        $this->assertSame('processing', $payload->status, 'An ambiguous_exception must return status=processing, not the legacy info string.');
        $this->assertSame('retain', $payload->m5_token_action);

        $reservation = DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->first();
        $this->assertNotNull($reservation);
        $this->assertSame('pending', $reservation->status);

        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('reservation_id', $reservation->id)->where('entry_type', 'usage_charge')->count());

        $wallet = DB::table('business_usage_wallets')->where('business_id', $fixture['business']->id)->first();
        $this->assertSame(10_000_000 - (int) $reservation->reserved_amount_micro, (int) $wallet->available_balance_micro);
        $this->assertSame((int) $reservation->reserved_amount_micro, (int) $wallet->reserved_balance_micro, 'Funds must remain reserved, not released, while ambiguous.');
    }

    /**
     * Exceptional correction, Defect 1 — a provider response with no
     * m5_outcome property at all (a defensive fallback that should be
     * unreachable by construction, since SendCampaignSMS.php always sets
     * the flag immediately before the provider call) must be treated
     * identically to ambiguous_exception: processing + retain + Pending.
     */
    public function test_real_quicksend_absent_m5_outcome_marker_leaves_pending_and_processing(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();

        $campaign = \Mockery::mock(Campaigns::class);
        $campaign->shouldReceive('sendPlainSMS')->once()->andReturn((object) [
            'id' => 1, 'uid' => (string) Str::uuid(), 'to' => '14155552671', 'from' => 'TestSender',
            'message' => 'Hello', 'customer_status' => 'Failed', 'status' => 'Failed',
            'cost' => 0, 'sms_count' => 1, 'media_url' => null,
            // Deliberately no 'm5_outcome' key at all.
        ]);

        $response = app(EloquentCampaignRepository::class)->quickSend(
            $campaign,
            $this->baseQuickSendInput($fixture),
            true,
        );

        $payload = $response->getData();
        $this->assertSame('processing', $payload->status, 'An absent/unrecognized M5 marker must return status=processing, not the legacy info string.');
        $this->assertSame('retain', $payload->m5_token_action);

        $reservation = DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->first();
        $this->assertSame('pending', $reservation->status);
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->where('reservation_id', $reservation->id)->where('entry_type', 'usage_charge')->count());
    }

    /**
     * Exceptional correction, Defect 1 — a non-M5-qualifying send (here,
     * a multi-Business Workspace, so $m5TokenAction stays null) must keep
     * its existing legacy response status exactly as before this
     * correction: 'success' for a Delivered provider result, and no
     * m5_token_action attached at all.
     */
    public function test_non_m5_provider_response_keeps_existing_legacy_status_unchanged(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();
        $fixture['user']->update(['sms_unit' => 1000]);

        app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace(
            \App\Models\Customer::where('user_id', $fixture['business']->customer_id)->first(),
            $fixture['business']->workspace,
            $this->businessAttributes(['name' => 'Second Business Legacy', 'currency_code' => 'M5T']),
        );

        $campaign = $this->mockCampaignReturning('accepted', 'Delivered');

        $response = app(EloquentCampaignRepository::class)->quickSend($campaign, $this->baseQuickSendInput($fixture), true);
        $payload = $response->getData();

        $this->assertSame('success', $payload->status, 'A non-qualifying send must keep the existing legacy success status.');
        $this->assertObjectNotHasProperty('m5_token_action', $payload);
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count());
    }

    /**
     * Same real, unmocked Twilio-exception code path as above, but with the
     * M5 tracking flag never set (conversationContext = false, the default
     * for every legacy caller) — proves the legacy exception-handling
     * behavior (Reports persistence, customer_status) is completely
     * unaffected, and that no m5_outcome is ever exposed on the resulting
     * Reports row for a non-M5 send.
     */
    public function test_real_quicksend_provider_exception_without_m5_flag_exposes_no_m5_outcome(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture(twilioAccountSid: '', twilioAuthToken: '');
        // conversationContext = false takes the legacy sms_unit path
        // (unaffected by M5): give the fixture user unlimited legacy
        // balance so the send actually reaches the provider call instead
        // of being denied by the pre-existing balance check.
        $fixture['user']->update(['sms_unit' => '-1']);
        $campaign = new Campaigns();

        $input = $this->baseQuickSendInput($fixture);
        unset($input['idempotency_token']);

        $response = app(EloquentCampaignRepository::class)->quickSend($campaign, $input, false);

        $payload = $response->getData();
        $this->assertObjectNotHasProperty('m5_token_action', $payload);

        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count());

        $report = DB::table('reports')->where('user_id', $fixture['user']->id)->latest('id')->first();
        $this->assertNotNull($report);
        // sendPlainSMS()'s own shared post-processing collapses any
        // non-Delivered status (including the Twilio catch block's own
        // in-memory 'Rejected') to 'Failed' before persisting — unchanged,
        // pre-existing legacy behavior this correction must not alter.
        $this->assertSame('Failed', $report->customer_status);
        $this->assertArrayNotHasKey('m5_outcome', (array) $report, 'm5_outcome is a non-persisted transient property and must never reach the reports table.');
    }

    /**
     * M5 contract §6 — same-token step-0, all four reachable reservation
     * states, each driven through two real quickSend() invocations sharing
     * the identical raw idempotency token. The retry's Campaign double
     * asserts zero invocations for every state — step 0 must intercept
     * before the provider is ever reached again.
     */
    public function test_step_0_intercepts_every_terminal_and_pending_state_for_the_same_token(): void
    {
        // Pending
        $fixture = $this->buildQualifyingQuickSendFixture();
        $token = (string) Str::uuid();
        $firstCampaign = $this->mockCampaignReturning('ambiguous_exception', 'Failed');
        app(EloquentCampaignRepository::class)->quickSend($firstCampaign, $this->baseQuickSendInput($fixture, idempotencyToken: $token), true);

        $retryCampaign = \Mockery::mock(Campaigns::class);
        $retryCampaign->shouldNotReceive('sendPlainSMS');
        $response = app(EloquentCampaignRepository::class)->quickSend($retryCampaign, $this->baseQuickSendInput($fixture, idempotencyToken: $token), true);
        $payload = $response->getData();
        $this->assertSame('processing', $payload->status);
        $this->assertSame('retain', $payload->m5_token_action);

        // Committed
        $fixture2 = $this->buildQualifyingQuickSendFixture();
        $token2 = (string) Str::uuid();
        $firstCampaign2 = $this->mockCampaignReturning('accepted', 'Delivered');
        app(EloquentCampaignRepository::class)->quickSend($firstCampaign2, $this->baseQuickSendInput($fixture2, idempotencyToken: $token2), true);

        $retryCampaign2 = \Mockery::mock(Campaigns::class);
        $retryCampaign2->shouldNotReceive('sendPlainSMS');
        $response2 = app(EloquentCampaignRepository::class)->quickSend($retryCampaign2, $this->baseQuickSendInput($fixture2, idempotencyToken: $token2), true);
        $payload2 = $response2->getData();
        $this->assertSame('success', $payload2->status);
        $this->assertSame('clear', $payload2->m5_token_action);

        // Released
        $fixture3 = $this->buildQualifyingQuickSendFixture();
        $token3 = (string) Str::uuid();
        $firstCampaign3 = $this->mockCampaignReturning('definitive_rejection', 'Failed');
        app(EloquentCampaignRepository::class)->quickSend($firstCampaign3, $this->baseQuickSendInput($fixture3, idempotencyToken: $token3), true);

        $retryCampaign3 = \Mockery::mock(Campaigns::class);
        $retryCampaign3->shouldNotReceive('sendPlainSMS');
        $response3 = app(EloquentCampaignRepository::class)->quickSend($retryCampaign3, $this->baseQuickSendInput($fixture3, idempotencyToken: $token3), true);
        $payload3 = $response3->getData();
        $this->assertSame('error', $payload3->status);
        $this->assertSame('clear', $payload3->m5_token_action);

        // Expired — simulated by directly aging an already-Pending
        // reservation's status/released_at, mirroring exactly what
        // ExpireStaleUsageReservations' own release() call would persist
        // after the real 30-minute TTL (§3.7, unmodified by this
        // correction) — driving the real job in a test is its own,
        // already-covered concern, not this same-token guard's.
        $fixture4 = $this->buildQualifyingQuickSendFixture();
        $token4 = (string) Str::uuid();
        $firstCampaign4 = $this->mockCampaignReturning('ambiguous_exception', 'Failed');
        app(EloquentCampaignRepository::class)->quickSend($firstCampaign4, $this->baseQuickSendInput($fixture4, idempotencyToken: $token4), true);
        DB::table('business_usage_reservations')->where('business_id', $fixture4['business']->id)->update([
            'status' => 'expired',
            'released_at' => now(),
        ]);

        $retryCampaign4 = \Mockery::mock(Campaigns::class);
        $retryCampaign4->shouldNotReceive('sendPlainSMS');
        $response4 = app(EloquentCampaignRepository::class)->quickSend($retryCampaign4, $this->baseQuickSendInput($fixture4, idempotencyToken: $token4), true);
        $payload4 = $response4->getData();
        $this->assertSame('error', $payload4->status);
        $this->assertSame('clear', $payload4->m5_token_action);
    }

    /**
     * M5 contract §3.13/§6 — a retry under the identical raw token, with
     * every other send-defining field independently changed (including
     * sms_type changed to a non-M5 channel), must still be intercepted by
     * step 0 before fresh §5.1 qualification or any legacy provider
     * dispatch — zero provider invocations for every changed-payload retry.
     */
    public function test_changed_payload_retry_cannot_escape_step_0(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();
        $token = (string) Str::uuid();
        $firstCampaign = $this->mockCampaignReturning('ambiguous_exception', 'Failed');
        app(EloquentCampaignRepository::class)->quickSend($firstCampaign, $this->baseQuickSendInput($fixture, idempotencyToken: $token), true);

        $otherCountry = Country::firstOrCreate(['country_code' => '44', 'iso_code' => 'GB'], ['name' => 'United Kingdom', 'status' => 1]);
        $otherServer = SendingServer::create([
            'name' => 'Other Server', 'settings' => SendingServer::TYPE_TWILIOCOPILOT, 'status' => true,
            'plain' => true, 'whatsapp' => true, 'account_sid' => 'ACother', 'auth_token' => 'authother',
        ]);

        $variants = [
            'changed sms_type (non-M5 channel)' => array_merge($this->baseQuickSendInput($fixture, smsType: 'whatsapp', idempotencyToken: $token), ['sending_server' => $otherServer->id]),
            'changed country' => array_merge($this->baseQuickSendInput($fixture, idempotencyToken: $token), ['country_code' => $otherCountry->country_code, 'region_code' => $otherCountry->iso_code]),
            'changed sending server' => array_merge($this->baseQuickSendInput($fixture, idempotencyToken: $token), ['sending_server' => $otherServer->id]),
            'changed sender' => array_merge($this->baseQuickSendInput($fixture, idempotencyToken: $token), ['sender_id' => 'DifferentSender']),
            'changed message' => array_merge($this->baseQuickSendInput($fixture, idempotencyToken: $token), ['message' => 'A completely different message body.']),
        ];

        foreach ($variants as $label => $input) {
            $retryCampaign = \Mockery::mock(Campaigns::class);
            $retryCampaign->shouldNotReceive('sendPlainSMS');

            $response = app(EloquentCampaignRepository::class)->quickSend($retryCampaign, $input, true);
            $payload = $response->getData();

            $this->assertSame('retain', $payload->m5_token_action, "Variant [{$label}] must still be governed by the existing Pending reservation.");
        }

        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count(), 'No changed-payload retry may create a second reservation.');
    }

    /**
     * M5 contract §3.5 — a Workspace that owns more than one Business
     * never engages M5 metering at all, even when one of its Businesses
     * exactly matches the pilot Business id: no guessed attribution, no
     * reservation, legacy behavior fully preserved.
     */
    public function test_multi_business_workspace_never_engages_m5_metering(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();
        $fixture['user']->update(['sms_unit' => 1000]);

        // A second Business in the identical Workspace makes cardinality 2.
        app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace(
            \App\Models\Customer::where('user_id', $fixture['business']->customer_id)->first(),
            $fixture['business']->workspace,
            $this->businessAttributes(['name' => 'Second Business', 'currency_code' => 'M5T']),
        );

        $campaign = $this->mockCampaignReturning('accepted', 'Delivered');

        $response = app(EloquentCampaignRepository::class)->quickSend($campaign, $this->baseQuickSendInput($fixture), true);
        $payload = $response->getData();

        $this->assertObjectNotHasProperty('m5_token_action', $payload, 'A non-qualifying multi-Business send must never carry an m5_token_action.');
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count());

        $wallet = DB::table('business_usage_wallets')->where('business_id', $fixture['business']->id)->first();
        $this->assertSame(10_000_000, (int) $wallet->available_balance_micro, 'Wallet must be completely untouched by a non-qualifying send.');

        $fixture['user']->refresh();
        $this->assertLessThan(1000, $fixture['user']->sms_unit, 'Legacy sms_unit must decrement exactly as before for a non-qualifying send.');
    }

    /**
     * M5 contract §5.1 items 4-6 — each independent pilot-tuple dimension
     * mismatch (Business, country, SendingServer id, gateway family) takes
     * the exact same legacy path unconditionally; only the complete exact
     * tuple engages M5 (already proven by the accepted-outcome test above).
     */
    public function test_each_pilot_tuple_dimension_independently_gates_m5_eligibility(): void
    {
        // Business mismatch: pilot_business_id repointed elsewhere.
        $fixture = $this->buildQualifyingQuickSendFixture();
        $fixture['user']->update(['sms_unit' => 1000]);
        config(['usage_billing.conversations_metering.pilot_business_id' => 999999]);
        $campaign = $this->mockCampaignReturning('accepted', 'Delivered');
        app(EloquentCampaignRepository::class)->quickSend($campaign, $this->baseQuickSendInput($fixture), true);
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count(), 'Business mismatch must not engage M5.');

        // Country mismatch: send to a country that isn't the pilot country.
        $fixture2 = $this->buildQualifyingQuickSendFixture();
        $fixture2['user']->update(['sms_unit' => 1000]);
        $otherCountry = Country::firstOrCreate(['country_code' => '44', 'iso_code' => 'GB'], ['name' => 'United Kingdom', 'status' => 1]);
        CustomerBasedPricingPlan::create([
            'user_id' => $fixture2['user']->id,
            'country_id' => $otherCountry->id,
            'plan_id' => DB::table('subscriptions')->where('user_id', $fixture2['user']->id)->value('plan_id'),
            'options' => json_encode(['plain_sms' => 0.05]),
            'status' => true,
        ]);
        $campaign2 = $this->mockCampaignReturning('accepted', 'Delivered');
        $input2 = $this->baseQuickSendInput($fixture2);
        $input2['country_code'] = $otherCountry->country_code;
        $input2['region_code'] = $otherCountry->iso_code;
        app(EloquentCampaignRepository::class)->quickSend($campaign2, $input2, true);
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture2['business']->id)->count(), 'Country mismatch must not engage M5.');

        // SendingServer id mismatch: a different Twilio server than the pilot's.
        $fixture3 = $this->buildQualifyingQuickSendFixture();
        $fixture3['user']->update(['sms_unit' => 1000]);
        $otherServer = SendingServer::create(['name' => 'Other Twilio', 'settings' => SendingServer::TYPE_TWILIO, 'status' => true, 'plain' => true, 'account_sid' => 'ACother2', 'auth_token' => 'authother2']);
        $campaign3 = $this->mockCampaignReturning('accepted', 'Delivered');
        $input3 = $this->baseQuickSendInput($fixture3);
        $input3['sending_server'] = $otherServer->id;
        app(EloquentCampaignRepository::class)->quickSend($campaign3, $input3, true);
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture3['business']->id)->count(), 'SendingServer id mismatch must not engage M5.');

        // Gateway family mismatch: the pilot server itself is not Twilio/TwilioCopilot.
        $fixture4 = $this->buildQualifyingQuickSendFixture();
        $fixture4['user']->update(['sms_unit' => 1000]);
        $nonTwilioServer = SendingServer::create(['name' => 'Non-Twilio', 'settings' => 'Nexmo', 'status' => true, 'plain' => true, 'account_sid' => 'x', 'auth_token' => 'y']);
        config(['usage_billing.conversations_metering.pilot_sending_server_id' => $nonTwilioServer->id]);
        $campaign4 = \Mockery::mock(Campaigns::class);
        $campaign4->shouldReceive('sendPlainSMS')->once()->andReturn((object) ['status' => 'Failed', 'customer_status' => 'Failed']);
        $input4 = $this->baseQuickSendInput($fixture4);
        $input4['sending_server'] = $nonTwilioServer->id;
        app(EloquentCampaignRepository::class)->quickSend($campaign4, $input4, true);
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture4['business']->id)->count(), 'Non-Twilio/TwilioCopilot gateway family must not engage M5.');
    }

    /**
     * M5 contract §8 — SMSCounter::count() is the sole quantity source,
     * identical for both reserve()'s estimatedQuantity and commit()'s
     * finalQuantity; no provider-reported segment reconciliation.
     */
    public function test_multi_segment_message_uses_the_identical_smscounter_quantity_throughout(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();
        $longMessage = str_repeat('This message is intentionally long enough to force multiple SMS segments. ', 5);

        $sms_counter = new \App\Library\SMSCounter();
        $expectedSegments = $sms_counter->count($longMessage)->messages;
        $this->assertGreaterThan(1, $expectedSegments, 'Fixture message must genuinely span multiple segments.');

        $campaign = \Mockery::mock(Campaigns::class);
        $campaign->shouldReceive('sendPlainSMS')->once()->andReturn((object) [
            'status' => 'Delivered', 'customer_status' => 'Delivered', 'sms_count' => $expectedSegments,
            'm5_outcome' => 'accepted',
        ]);

        app(EloquentCampaignRepository::class)->quickSend($campaign, $this->baseQuickSendInput($fixture, message: $longMessage), true);

        $reservation = DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->first();
        $this->assertEquals($expectedSegments, (int) $reservation->estimated_quantity);
        $this->assertEquals($expectedSegments, (int) $reservation->final_quantity);
    }

    /**
     * M5 contract §H — charging exclusivity, both directions. A: a
     * qualifying M5 send with insufficient legacy sms_unit but sufficient
     * wallet balance must still send successfully, charging only the
     * wallet. B: a non-qualifying legacy send charges only sms_unit,
     * leaving the RFC-005 wallet completely untouched.
     */
    public function test_no_double_charge_both_directions(): void
    {
        // A — qualifying M5 send, deliberately insufficient legacy sms_unit.
        $fixtureA = $this->buildQualifyingQuickSendFixture();
        $fixtureA['user']->update(['sms_unit' => 0]);
        $campaignA = $this->mockCampaignReturning('accepted', 'Delivered');

        $responseA = app(EloquentCampaignRepository::class)->quickSend($campaignA, $this->baseQuickSendInput($fixtureA), true);
        $this->assertSame('success', $responseA->getData()->status, 'Insufficient legacy balance must not block a qualifying M5 send.');

        $fixtureA['user']->refresh();
        $this->assertEquals(0, $fixtureA['user']->sms_unit, 'sms_unit must remain byte-for-byte unchanged for a qualifying M5 send.');

        $walletA = DB::table('business_usage_wallets')->where('business_id', $fixtureA['business']->id)->first();
        $this->assertLessThan(10_000_000, (int) $walletA->available_balance_micro, 'Wallet must have been charged.');

        // B — non-qualifying legacy send (multi-Business, so M5 never
        // engages), provider succeeds.
        $fixtureB = $this->buildQualifyingQuickSendFixture();
        $fixtureB['user']->update(['sms_unit' => 1000]);
        app(\App\Repositories\Contracts\BusinessRepository::class)->createForCustomerInWorkspace(
            \App\Models\Customer::where('user_id', $fixtureB['business']->customer_id)->first(),
            $fixtureB['business']->workspace,
            $this->businessAttributes(['name' => 'Second Business B', 'currency_code' => 'M5T']),
        );
        $campaignB = $this->mockCampaignReturning('accepted', 'Delivered');

        app(EloquentCampaignRepository::class)->quickSend($campaignB, $this->baseQuickSendInput($fixtureB), true);

        $fixtureB['user']->refresh();
        $this->assertLessThan(1000, $fixtureB['user']->sms_unit, 'Legacy sms_unit must decrement exactly as before for a non-qualifying send.');

        $walletB = DB::table('business_usage_wallets')->where('business_id', $fixtureB['business']->id)->first();
        $this->assertSame(10_000_000, (int) $walletB->available_balance_micro, 'RFC-005 wallet must remain completely untouched by a non-qualifying send.');
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixtureB['business']->id)->count());
    }

    /**
     * M5 contract §3.3/§10 — Amendment 1 decoupled wallet health from
     * EntitlementManager::decide()'s own decision; it did not remove the
     * decision itself. Calling the REAL decide() (not only
     * evaluateCoarseCapacity()) for Conversations must return an
     * identical allowed decision regardless of wallet billing_status,
     * debt, balance, or pilot meter is_metered/active_rate_id state,
     * given identical plan/override entitlement inputs.
     */
    public function test_entitlement_decision_is_identical_across_every_wallet_and_meter_state(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();
        $business = $fixture['business'];
        $meterKey = $fixture['meterKey'];
        $manager = app(EntitlementManager::class);

        $decideNow = fn () => $manager->decide($business->workspace, $business, PlatformFeature::Conversations->value, $fixture['user']->id);

        $baseline = $decideNow();
        $this->assertTrue($baseline->allowed);
        $this->assertNull($baseline->reason);

        // Test-alignment correction — decision->allowed and
        // decision->reason are independent properties on
        // EntitlementDecision; an allowed===true result is not
        // structurally guaranteed to carry the identical reason. Every
        // state below must assert both against the baseline, not
        // allowed alone.
        $assertSameDecision = function ($decision, string $label) use ($baseline): void {
            $this->assertSame($baseline->allowed, $decision->allowed, "{$label}: allowed must remain identical.");
            $this->assertSame($baseline->reason, $decision->reason, "{$label}: reason must remain identical.");
        };

        // Healthy wallet (baseline) already proven above. Now mutate wallet
        // health independently and re-decide each time.
        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 0]);
        $assertSameDecision($decideNow(), 'Insufficient balance');

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['billing_status' => 'suspended']);
        $assertSameDecision($decideNow(), 'Suspended wallet');

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['billing_status' => 'active', 'debt_balance_micro' => 5_000_000]);
        $assertSameDecision($decideNow(), 'Outstanding debt');

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['debt_balance_micro' => 0]);

        DB::table('usage_meters')->where('meter_key', $meterKey)->update(['is_metered' => false]);
        $assertSameDecision($decideNow(), 'Pilot meter inactive');

        $activeRateId = DB::table('usage_meters')->where('meter_key', $meterKey)->value('active_rate_id');
        DB::table('usage_meters')->where('meter_key', $meterKey)->update(['is_metered' => true, 'active_rate_id' => null]);
        $assertSameDecision($decideNow(), 'Pilot meter with no active rate');

        // Exceptional correction, Defect 2 — the pilot meter entirely
        // absent must actually be executed, not merely asserted safe from
        // source. Dependents are dropped in FK-safe order (transitions,
        // then activations, then rates, then the meter row itself); this
        // test uses RefreshDatabase, so no manual restoration is needed.
        DB::table('usage_meter_transitions')->where('meter_key', $meterKey)->delete();
        DB::table('business_usage_rate_activations')->where('meter_key', $meterKey)->delete();
        DB::table('business_usage_rates')->where('meter_key', $meterKey)->delete();
        DB::table('usage_meters')->where('meter_key', $meterKey)->delete();
        $this->assertSame(0, DB::table('usage_meters')->where('meter_key', $meterKey)->count());
        $assertSameDecision($decideNow(), 'Pilot meter entirely absent');

        // Exceptional correction, Defect 2 — the legacy
        // platform_feature_usage_classifications row for 'conversations'
        // is a pre-existing, globally-seeded row (not scoped to this
        // Business); decide() must remain identical regardless of its
        // is_metered value, since that legacy table is not the M5
        // activation authority.
        DB::table('platform_feature_usage_classifications')->where('feature_key', 'conversations')->update(['is_metered' => false]);
        $assertSameDecision($decideNow(), 'Legacy classification row is_metered=false');

        DB::table('platform_feature_usage_classifications')->where('feature_key', 'conversations')->update(['is_metered' => true]);
        $assertSameDecision($decideNow(), 'Legacy classification row is_metered=true');
    }

    // ========================================================================
    // Exceptional correction — §6.1 token-lifecycle verification, via real
    // HTTP requests against the actual, unmodified ChatBoxController routes
    // (never by touching ChatBoxController.php itself). Reuses this
    // repository's own established customer-route-authorization fixture
    // pattern (AppConfig seeding + Customer::customerPermissions()) from
    // UsageBillingDashboardAuthorizationTest.php.
    // ========================================================================

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = \App\Models\AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            \App\Models\AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            \App\Models\AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new \App\Models\AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            \App\Models\AppConfig::create($default);
        }
    }

    private function actingAsHttpCustomer(User $user): void
    {
        $this->ensureRequiredAppConfigRowsExist();

        $customer = \App\Models\Customer::where('user_id', $user->id)->first();
        $customer->permissions = \App\Models\Customer::customerPermissions();
        $customer->save();

        $user->email_verified_at = now();
        $user->save();

        $this->actingAs($user);
    }

    public function test_new_compose_mints_a_fresh_uuid_and_a_retry_reuses_the_supplied_one(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture();
        $this->actingAsHttpCustomer($fixture['user']);

        $firstResponse = $this->get(route('customer.chatbox.new'));
        $firstResponse->assertOk();
        $firstToken = $firstResponse->viewData('idempotencyToken');
        $this->assertIsString($firstToken);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($firstToken));

        $secondResponse = $this->get(route('customer.chatbox.new'));
        $secondResponse->assertOk();
        $secondToken = $secondResponse->viewData('idempotencyToken');

        $this->assertNotSame($firstToken, $secondToken, 'Two independent compose loads must mint two different tokens.');

        // A retry carrying ?m5_retry_token=<uuid> reuses that exact token
        // instead of minting a new one.
        $retryToken = (string) \Illuminate\Support\Str::uuid();
        $retryResponse = $this->get(route('customer.chatbox.new', ['m5_retry_token' => $retryToken]));
        $retryResponse->assertOk();
        $this->assertSame($retryToken, $retryResponse->viewData('idempotencyToken'), 'A valid retry token in the query string must be reused verbatim.');
    }

    public function test_reply_missing_token_returns_422_and_never_reaches_quicksend(): void
    {
        $customer = $this->createCustomer();
        $this->actingAsHttpCustomer(User::find($customer->user_id));

        $box = \App\Models\ChatBox::create([
            'user_id' => $customer->user_id, 'from' => 'TestSender', 'to' => '14155552671',
            'reply_by_customer' => true,
        ]);

        $response = $this->postJson(route('customer.chatbox.reply', $box->uid), [
            'message' => 'Hello, no token supplied.',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
        $this->assertSame(0, DB::table('reports')->count(), 'A 422 must never reach quickSend()/the provider at all.');
    }

    public function test_reply_invalid_token_returns_422_and_never_reaches_quicksend(): void
    {
        $customer = $this->createCustomer();
        $this->actingAsHttpCustomer(User::find($customer->user_id));

        $box = \App\Models\ChatBox::create([
            'user_id' => $customer->user_id, 'from' => 'TestSender', 'to' => '14155552671',
            'reply_by_customer' => true,
        ]);

        $response = $this->postJson(route('customer.chatbox.reply', $box->uid), [
            'message' => 'Hello, invalid token supplied.',
            'idempotency_token' => 'not-a-real-uuid',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
        $this->assertSame(0, DB::table('reports')->count(), 'A 422 must never reach quickSend()/the provider at all.');
    }

    public function test_reply_valid_token_passes_trusted_conversation_context_through_to_quicksend(): void
    {
        // Empty Twilio credentials (this repository's own established
        // no-network-call pattern) make the real, unmocked Twilio SDK
        // Client throw its own synchronous ConfigurationException — no
        // live network request is made or required. The point of this
        // test is only to prove conversationContext=true reached
        // quickSend()'s qualifying chain at all: a reservation is created
        // here, which never happens for conversationContext=false.
        $fixture = $this->buildQualifyingQuickSendFixture(twilioAccountSid: '', twilioAuthToken: '');
        $this->actingAsHttpCustomer($fixture['user']);

        \App\Models\PhoneNumbers::create([
            'user_id' => $fixture['user']->id, 'number' => 'TestSender', 'status' => 'assigned',
            'capabilities' => 'sms',
        ]);

        $box = \App\Models\ChatBox::create([
            'user_id' => $fixture['user']->id, 'from' => 'TestSender', 'to' => '14155552671',
            'sending_server_id' => $fixture['sendingServer']->id, 'reply_by_customer' => true,
        ]);

        $response = $this->postJson(route('customer.chatbox.reply', $box->uid), [
            'message' => 'A valid, real reply.',
            'idempotency_token' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $response->assertOk();

        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count(), 'A valid reply token must reach the M5 qualifying chain (conversationContext=true).');
    }

    /**
     * M5 contract §6.1 — a retain-outcome sent() redirect must carry the
     * identical idempotency token via ?m5_retry_token=, and must restore
     * the original send-defining form values via withInput() for the
     * next new() load's old() calls to read.
     */
    public function test_sent_retain_redirect_preserves_token_and_restores_form_input(): void
    {
        $fixture = $this->buildQualifyingQuickSendFixture(twilioAccountSid: '', twilioAuthToken: '');
        $this->actingAsHttpCustomer($fixture['user']);

        \App\Models\PhoneNumbers::create([
            'user_id' => $fixture['user']->id, 'number' => '14155552671', 'status' => 'assigned',
            'capabilities' => 'sms',
        ]);

        $token = (string) \Illuminate\Support\Str::uuid();

        $payload = [
            'sending_server' => $fixture['sendingServer']->id,
            'country_code' => $fixture['country']->id,
            'recipient' => '4155552671',
            'sender_id' => '14155552671',
            'message' => 'A real sent() retry fixture message.',
            'sms_type' => 'plain',
            'idempotency_token' => $token,
        ];

        $response = $this->post(route('customer.chatbox.sent'), $payload);

        $response->assertRedirect(route('customer.chatbox.new', ['m5_retry_token' => $token]));
        $response->assertSessionHasInput('sending_server', (string) $fixture['sendingServer']->id);
        $response->assertSessionHasInput('country_code', (string) $fixture['country']->id);
        $response->assertSessionHasInput('sender_id', '14155552671');
        $response->assertSessionHasInput('recipient', '4155552671');
        $response->assertSessionHasInput('message', 'A real sent() retry fixture message.');

        // Following the redirect, new()'s own m5_retry_token handling must
        // reuse the identical token verbatim.
        $followUp = $this->get(route('customer.chatbox.new', ['m5_retry_token' => $token]));
        $followUp->assertOk();
        $this->assertSame($token, $followUp->viewData('idempotencyToken'));
    }
}
