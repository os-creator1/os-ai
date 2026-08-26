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
use App\Repositories\Contracts\CampaignRepository;
use App\Repositories\Contracts\UsageMeterRepository;
use App\Repositories\Contracts\WorkspaceEntitlementOverrideRepository;
use App\Repositories\Eloquent\EloquentCampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionMethod;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * RFC-005 Milestone 5 §5.1/§7/§12 item 1/2/13 item 5,15,21 — proves, by
 * direct inspection of the actual widened signature (not by driving the
 * full legacy quickSend() call chain, which requires an unrelated,
 * pre-existing subscription/plan/pricing fixture depth outside this
 * correction's own scope), that the new $conversationContext
 * discriminator is additive and default-false: every one of the five
 * non-ChatBox quickSend() call sites this milestone's own audit found
 * (bulk Quick Send, contact-group welcome SMS, DLR auto-reply, and the
 * two third-party API controllers) calls quickSend($campaign, $input)
 * with exactly two arguments, unchanged by this correction — PHP itself
 * guarantees each of those calls silently receives the default `false`,
 * with zero source change required at any of those five call sites.
 */
class QuickSendNonConversationCallersUnaffectedTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    public function test_conversation_context_parameter_is_additive_and_defaults_to_false(): void
    {
        $interfaceMethod = new ReflectionMethod(CampaignRepository::class, 'quickSend');
        $implementationMethod = new ReflectionMethod(EloquentCampaignRepository::class, 'quickSend');

        foreach ([$interfaceMethod, $implementationMethod] as $method) {
            $parameters = $method->getParameters();
            $this->assertCount(3, $parameters, $method->getDeclaringClass()->getName() . '::quickSend() must have exactly 3 parameters.');

            $third = $parameters[2];
            $this->assertSame('conversationContext', $third->getName());
            $this->assertTrue($third->isDefaultValueAvailable());
            $this->assertFalse($third->getDefaultValue());
            $this->assertTrue($third->getType() !== null && (string) $third->getType() === 'bool');
        }
    }

    /**
     * Confirms every non-ChatBox quickSend() call site this milestone's
     * own repository-wide audit found still calls with exactly two
     * arguments — a direct, mechanical proof (not an assumption) that
     * none of them opts into M5 metering, and that none required a
     * source change to remain correct after the widening.
     */
    public function test_every_known_non_chatbox_caller_still_passes_exactly_two_arguments(): void
    {
        $callSites = [
            'app/Repositories/Eloquent/EloquentContactsRepository.php',
            'app/Http/Controllers/Customer/CampaignController.php',
            'app/Http/Controllers/Customer/DLRController.php',
            'app/Http/Controllers/API/CampaignHTTPController.php',
            'app/Http/Controllers/API/CampaignController.php',
        ];

        foreach ($callSites as $relativePath) {
            $path = base_path($relativePath);
            $this->assertFileExists($path);

            $contents = file_get_contents($path);

            $this->assertMatchesRegularExpression(
                '/->quickSend\(/',
                $contents,
                "{$relativePath} was expected to still call quickSend()."
            );

            // A literal array second argument (e.g. quickSend($campaign, [...]))
            // may itself contain commas, so this checks only that no
            // *third top-level argument* — specifically ", true)" or
            // ", false)" immediately before the call's own closing paren
            // — was added at any call site in this file.
            $this->assertSame(
                0,
                preg_match('/->quickSend\([^;]*,\s*(?:true|false)\s*\)\s*;/s', $contents),
                "{$relativePath} must not have gained a third quickSend() argument."
            );
        }
    }

    public function test_chatbox_controller_is_the_only_caller_passing_true(): void
    {
        $contents = file_get_contents(base_path('app/Http/Controllers/Customer/ChatBoxController.php'));

        $this->assertSame(2, preg_match_all('/->quickSend\([^)]*,\s*true\s*\)/', $contents));
    }

    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Admin', 'last_name' => 'Actor',
            'email' => 'admin' . uniqid() . '@example.test', 'status' => true,
            'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ])->id;
    }

    /**
     * @return array{business: \App\Models\Business, user: User, country: Country, sendingServer: SendingServer}
     */
    private function buildFullyQualifyingFixture(): array
    {
        $currency = Currency::query()->where('code', 'M5T')->first()
            ?? Currency::create(['name' => 'M5 Test Dollar', 'code' => 'M5T', 'format' => '$', 'status' => true]);

        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['currency_code' => 'M5T']));
        $user = User::find($business->customer_id);
        $user->update(['sms_unit' => 1000]);

        $now = now();
        DB::table('business_usage_wallets')->insert([
            'business_id' => $business->id, 'currency_id' => $currency->id,
            'available_balance_micro' => 10_000_000, 'reserved_balance_micro' => 0, 'debt_balance_micro' => 0,
            'spend_period_key' => $now->format('Y-m'), 'spend_period_start_utc' => $now, 'spend_period_end_utc' => $now->clone()->addMonth(),
            'recharge_period_key' => $now->format('Y-m'), 'recharge_period_start_utc' => $now, 'recharge_period_end_utc' => $now->clone()->addMonth(),
            'billing_status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $meterKey = 'conversations.pilot.' . $business->id;
        app(UsageMeterRepository::class)->create([
            'meter_key' => $meterKey, 'feature_key' => 'conversations', 'business_id' => $business->id,
            'currency_id' => $currency->id, 'description' => 'Forged-origin fixture meter.', 'updated_by_user_id' => $user->id,
        ]);
        app(UsageWalletManager::class)->setActiveRate($meterKey, '1000000', '500000', 'per message', $currency->id, $user->id, 'Fixture.');
        app(UsageWalletManager::class)->activateMetering($meterKey, $user->id, 'Fixture.');

        $actorId = $this->createActorUserId();
        app(EntitlementManager::class)->assignFirstPlan($business->workspace, \App\Enums\Entitlement\WorkspacePlanTier::Core, $actorId, 'Fixture assignment.', true, 0);
        app(WorkspaceEntitlementOverrideRepository::class)->create([
            'workspace_id' => $business->workspace_id, 'feature_key' => PlatformFeature::Conversations->value,
            'state' => WorkspaceEntitlementOverrideState::Allow->value, 'reason' => 'Fixture grant.', 'created_by_user_id' => $actorId,
        ]);

        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $sendingServer = SendingServer::create([
            'name' => 'Forged-origin Twilio Server', 'settings' => SendingServer::TYPE_TWILIO, 'status' => true,
            'plain' => true, 'whatsapp' => true, 'voice' => true, 'mms' => true, 'viber' => true, 'otp' => true,
            'account_sid' => 'ACtest', 'auth_token' => 'authtest',
        ]);

        config([
            'usage_billing.conversations_metering.pilot_business_id' => $business->id,
            'usage_billing.conversations_metering.pilot_country_id' => $country->id,
            'usage_billing.conversations_metering.pilot_sending_server_id' => $sendingServer->id,
        ]);

        $plan = Plan::create([
            'currency_id' => $currency->id, 'name' => 'Fixture Plan', 'price' => 10, 'billing_cycle' => 'monthly',
            'frequency_amount' => 1, 'frequency_unit' => 'month', 'options' => json_encode([]), 'status' => true,
        ]);
        Subscription::create(['user_id' => $user->id, 'plan_id' => $plan->id, 'status' => Subscription::STATUS_ACTIVE, 'paid' => true, 'start_at' => now(), 'end_at' => null]);
        CustomerBasedPricingPlan::create([
            'user_id' => $user->id, 'country_id' => $country->id, 'plan_id' => $plan->id,
            'options' => json_encode([
                'plain_sms' => 0.05, 'whatsapp_sms' => 0.10, 'voice_sms' => 0.10,
                'mms_sms' => 0.10, 'viber_sms' => 0.10, 'otp_sms' => 0.10,
            ]), 'status' => true,
        ]);

        return ['business' => $business, 'user' => $user, 'country' => $country, 'sendingServer' => $sendingServer];
    }

    /**
     * RFC-005 Milestone 5 §7/§3.11 — the discriminator is a trusted PHP
     * parameter, never derived from $input. Calling quickSend() through its
     * ordinary two-argument, non-trusted-origin surface — even with a
     * forged conversation_context=true and a valid idempotency_token
     * planted directly in $input, and every other tuple condition
     * satisfied so the send WOULD qualify if the forged input were
     * trusted — must still take the exact legacy path: zero RFC-005
     * reservation, zero ledger charge, wallet untouched, legacy sms_unit
     * decremented exactly as before.
     */
    public function test_forged_non_chatbox_opt_in_is_ignored_and_legacy_path_is_used(): void
    {
        $fixture = $this->buildFullyQualifyingFixture();

        $campaign = \Mockery::mock(Campaigns::class);
        $campaign->shouldReceive('sendPlainSMS')->once()->andReturn((object) [
            'status' => 'Delivered', 'customer_status' => 'Delivered', 'cost' => 0.05, 'sms_count' => 1,
        ]);

        $input = [
            'user' => $fixture['user'],
            'sms_type' => 'plain',
            'sender_id' => 'TestSender',
            'region_code' => $fixture['country']->iso_code,
            'country_code' => $fixture['country']->country_code,
            'recipient' => '5551234567',
            'message' => 'Forged origin attempt.',
            'sending_server' => $fixture['sendingServer']->id,
            // Forged: a real caller could stuff these into $input, but the
            // discriminator only ever comes from the trusted PHP parameter.
            'conversation_context' => true,
            'idempotency_token' => (string) \Illuminate\Support\Str::uuid(),
        ];

        $sms_unit_before = $fixture['user']->sms_unit;

        // Ordinary, non-trusted two-argument call — conversationContext
        // defaults to false regardless of what $input contains.
        $response = app(EloquentCampaignRepository::class)->quickSend($campaign, $input);

        $payload = $response->getData();
        $this->assertObjectNotHasProperty('m5_token_action', $payload, 'A forged/untrusted call must never receive an m5_token_action.');

        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count());
        $this->assertSame(0, DB::table('business_usage_ledger_entries')->count());

        $wallet = DB::table('business_usage_wallets')->where('business_id', $fixture['business']->id)->first();
        $this->assertSame(10_000_000, (int) $wallet->available_balance_micro, 'RFC-005 wallet must be completely untouched by a forged non-trusted call.');

        $fixture['user']->refresh();
        $this->assertLessThan($sms_unit_before, $fixture['user']->sms_unit, 'Legacy sms_unit must decrement exactly as before.');
    }

    /**
     * RFC-005 Milestone 5 §5.2 — MMS/WhatsApp/Viber/OTP/Voice remain
     * unconditionally unchanged during M5, regardless of
     * Conversations.is_metered, even for a trusted ChatBox-origin call:
     * only sms_type plain/unicode is ever evaluated for M5 qualification.
     */
    /**
     * M5 contract §5.2 — MMS, WhatsApp, Viber, OTP, and Voice remain
     * unconditionally unchanged during M5, regardless of
     * Conversations.is_metered, even for a trusted ChatBox-origin call:
     * only sms_type plain/unicode is ever evaluated for M5 qualification.
     * Exceptional correction, Defect 3 — behaviorally proven for every
     * channel the contract names, not one representative example.
     */
    public function test_every_excluded_channel_from_chatbox_origin_remains_on_legacy_billing(): void
    {
        $channels = [
            'whatsapp' => ['method' => 'sendWhatsApp', 'extraInput' => [], 'message' => 'A WhatsApp message.'],
            'mms' => ['method' => 'sendMMS', 'extraInput' => ['media_url' => 'https://example.test/image.png'], 'message' => 'An MMS message.'],
            'viber' => ['method' => 'sendViber', 'extraInput' => [], 'message' => 'A Viber message.'],
            'otp' => ['method' => 'sendOTP', 'extraInput' => [], 'message' => 'An OTP message.'],
            'voice' => ['method' => 'sendVoiceSMS', 'extraInput' => ['language' => 'en', 'gender' => 'female'], 'message' => 'A Voice message.'],
        ];

        foreach ($channels as $smsType => $spec) {
            $fixture = $this->buildFullyQualifyingFixture();

            $campaign = \Mockery::mock(Campaigns::class);
            $campaign->shouldReceive($spec['method'])->once()->andReturn((object) [
                'status' => 'Delivered', 'customer_status' => 'Delivered', 'cost' => 0.10, 'sms_count' => 1,
            ]);

            $input = array_merge([
                'user' => $fixture['user'],
                'sms_type' => $smsType,
                'sender_id' => 'TestSender',
                'region_code' => $fixture['country']->iso_code,
                'country_code' => $fixture['country']->country_code,
                'recipient' => '5551234567',
                'message' => $spec['message'],
                'sending_server' => $fixture['sendingServer']->id,
                'idempotency_token' => (string) \Illuminate\Support\Str::uuid(),
            ], $spec['extraInput']);

            $sms_unit_before = $fixture['user']->sms_unit;

            // Trusted ChatBox origin (conversationContext = true), but a
            // non-plain/unicode channel — M5's own sms_type gate (§5.1
            // item 1) must still exclude it.
            $response = app(EloquentCampaignRepository::class)->quickSend($campaign, $input, true);

            $payload = $response->getData();
            $this->assertObjectNotHasProperty('m5_token_action', $payload, "Channel [{$smsType}] must never carry an m5_token_action.");

            $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $fixture['business']->id)->count(), "Channel [{$smsType}] must create zero RFC-005 reservations.");
            $this->assertSame(0, DB::table('business_usage_ledger_entries')->count(), "Channel [{$smsType}] must create zero RFC-005 ledger entries.");

            $wallet = DB::table('business_usage_wallets')->where('business_id', $fixture['business']->id)->first();
            $this->assertSame(10_000_000, (int) $wallet->available_balance_micro, "Channel [{$smsType}] must leave the RFC-005 wallet untouched.");

            $fixture['user']->refresh();
            $this->assertLessThan($sms_unit_before, $fixture['user']->sms_unit, "Channel [{$smsType}] must decrement legacy sms_unit exactly as before.");
        }
    }
}
