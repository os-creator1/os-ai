<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\AppConfig;
use App\Models\Campaigns;
use App\Models\Contacts;
use App\Models\ContactGroups;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\CustomerBasedSendingServer;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Reports;
use App\Models\Senderid;
use App\Models\SendingServer;
use App\Models\Subscription;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Customer Experience Slice 3 §4.5 — T-MSG-9, 10, 65, 66.
 *
 * The delegation seam, exercised at the two production convergence points
 * the contract names and then through the real campaign-builder async
 * chain, which is where the prior contract revision's untested claim went
 * wrong: knowing `Campaigns::sendSMS()` is a convergence point is not the
 * same as proving a real `POST` actually reaches it.
 *
 * `Http::fake()` is active for every test here, so a send that escaped the
 * managed path into a legacy provider `case` block would be caught twice
 * over: by `Http::assertNothingSent()` and by the absence of an operation
 * row.
 */
class ManagedCampaignDelegationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    /** The plan the campaign-builder payload declares coverage against. */
    private ?int $planId = null;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->bindFakeAdapter();
        $this->ensureRequiredAppConfigRowsExist();

        User::create([
            'first_name' => 'Placeholder',
            'last_name' => 'SuperAdmin',
            'email' => 'placeholder-superadmin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);
    }

    // ---------------------------------------------------------------
    // T-MSG-9 — quickSend() delegates
    // ---------------------------------------------------------------

    public function test_quick_send_for_a_managed_business_never_reaches_the_legacy_provider_switch(): void
    {
        [$tenant, $business, $identity, $number] = $this->managedSendableTenant();

        $response = $this->quickSend($tenant, $business, '14155552671', 'Hello from quickSend');

        $this->assertSame('success', $response->getData()->status, (string) ($response->getData()->message ?? ''));

        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The managed adapter must have handled the send.');
        Http::assertNothingSent();

        $this->assertSame(1, $this->operationCount(), 'Exactly one operation row per managed send.');
        $this->assertOperationBelongsTo($identity, $number);
    }

    public function test_quick_send_for_a_business_without_a_managed_identity_is_untouched(): void
    {
        [$tenant, $business] = $this->sendableTenant();

        // No identity at all: the delegate returns null and the legacy path
        // runs exactly as before. It fails here for its own ordinary
        // reasons (no configured provider in this sandbox) — what matters
        // is that Slice 3 did not intercept it.
        $this->quickSend($tenant, $business, '14155552672', 'Hello legacy');

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        $this->assertSame(0, $this->operationCount(), 'A non-managed Business must write no operation row.');
    }

    // ---------------------------------------------------------------
    // T-MSG-10 — Campaigns' own dispatch switch delegates
    // ---------------------------------------------------------------

    public function test_the_campaigns_dispatch_switch_delegates_for_a_managed_business(): void
    {
        [, $business, $identity, $number] = $this->managedSendableTenant();

        $campaign = new Campaigns();
        $campaign->business_id = $business->id;
        // The Reports row the delegation returns is attributed to the
        // campaign's own owner, exactly as the legacy path attributes it.
        $campaign->user_id = $business->customer->user_id;
        $campaign->sms_type = 'plain';

        $result = $campaign->sendSMS([
            'user_id' => $business->customer_id,
            'phone' => '14155552673',
            'sender_id' => 'TESTSENDER',
            'message' => 'Hello from the bulk path',
            'sms_type' => 'plain',
            'cost' => 0,
            'sms_count' => 1,
        ]);

        $this->assertSame('Delivered', $result->status);
        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        Http::assertNothingSent();

        $this->assertSame(1, $this->operationCount());
        $this->assertOperationBelongsTo($identity, $number);
    }

    // ---------------------------------------------------------------
    // T-MSG-65 / T-MSG-66 — the real async chain, both entry routes
    // ---------------------------------------------------------------

    public function test_the_outreach_campaign_route_reaches_the_delegated_switch_through_the_real_job_chain(): void
    {
        [$tenant, $business, $identity, $number] = $this->managedSendableTenant();
        $group = $this->groupWithOneContact($tenant, $business, '14155552674');

        // The queue runs synchronously in this suite, so this really does
        // execute RunCampaign -> LoadCampaign -> SendMessage -> send() ->
        // sendSMS(), not a mocked stand-in.
        $this->authenticateAsCustomer($tenant, ['sms_campaign_builder']);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.campaign', [
            $business->workspace->uid,
            $business->uid,
        ]), $this->campaignPayload($group));

        $campaign = Campaigns::query()->where('campaign_name', 'Managed Blast')->first();
        $this->assertNotNull($campaign, 'The campaign must have been created and dispatched.');

        // (a) the managed adapter handled it, and no legacy provider was
        //     contacted over HTTP
        $this->assertCount(1, $this->fakeAdapter->sentRequests, 'The managed adapter must have handled the send.');
        Http::assertNothingSent();

        // (b) the identity/number used are this Business's own
        $this->assertOperationBelongsTo($identity, $number);

        // (c) exactly one measurement row, written through recordMeasurement()
        $this->assertSame(1, DB::table('business_usage_measurements')->count());

        // (e) exactly one operation row
        $this->assertSame(1, $this->operationCount());
    }

    public function test_the_legacy_campaign_builder_route_reaches_the_same_delegated_switch(): void
    {
        [$tenant, $business, $identity, $number] = $this->managedSendableTenant();
        $group = $this->groupWithOneContact($tenant, $business, '14155552675');

        $this->authenticateAsCustomer($tenant, ['sms_campaign_builder']);

        // CampaignController@storeCampaign forwards the request body
        // verbatim to campaignBuilder(). `business_id` is supplied here for
        // one mechanical reason, stated plainly rather than hidden: without
        // it, campaignBuilder() takes its legacy AI-prospecting branch,
        // which inserts `chat_boxes.ai_stage` and into `ai_box_campaign_map`
        // — neither of which any migration in this repository defines. That
        // branch therefore throws on any migration-built database, on this
        // branch and on pristine `origin/main` alike (the same pre-existing
        // gap OutreachCorrection1Test already documents). It is unrelated
        // to Slice 3 and outside its allowlist. Supplying the key skips
        // that branch and lets the assertion below be about the delegation
        // seam, which is what this test is for.
        $response = $this->post('/sms/campaign-builder', array_merge($this->campaignPayload($group), [
            'business_id' => $business->id,
            'user_id' => $tenant->user_id,
        ]));

        $this->assertNotNull(
            Campaigns::query()->where('campaign_name', 'Managed Blast')->first(),
            'The campaign must have been created and dispatched.',
        );

        $this->assertCount(1, $this->fakeAdapter->sentRequests);
        Http::assertNothingSent();
        $this->assertOperationBelongsTo($identity, $number);
        $this->assertSame(1, DB::table('business_usage_measurements')->count());
        $this->assertSame(1, $this->operationCount());
    }

    // ---------------------------------------------------------------
    // T-MSG-66 (d) — the two refusals, with zero provider calls
    // ---------------------------------------------------------------

    public function test_the_kill_switch_refuses_the_send_with_zero_provider_calls(): void
    {
        [$tenant, $business] = $this->managedSendableTenant();
        $group = $this->groupWithOneContact($tenant, $business, '14155552676');

        config(['messaging.managed_messaging_enabled' => false]);

        $this->authenticateAsCustomer($tenant, ['sms_campaign_builder']);
        $this->post(route('customer.workspaces.businesses.outreach.sms.campaign', [
            $business->workspace->uid,
            $business->uid,
        ]), $this->campaignPayload($group));

        $this->assertCount(0, $this->fakeAdapter->sentRequests, 'The kill switch must stop the send before the provider.');
        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_usage_measurements')->count());
    }

    public function test_a_managed_business_never_falls_back_to_a_legacy_provider_when_its_identity_is_unusable(): void
    {
        [$tenant, $business, $identity, $number] = $this->managedSendableTenant();

        // The identity stays active, but its only active primary number is
        // released — an unusable managed configuration. §4.5 requires this
        // to fail closed, NOT to quietly fall through to the legacy
        // provider, which would defeat the isolation this slice exists for.
        $number->status = \App\Enums\Messaging\BusinessMessagingNumberStatus::Released->value;
        $number->is_primary = false;
        $number->save();

        // §4.5 step 3's mechanism is the exception itself: the send stops
        // here rather than continuing into the legacy provider switch below
        // the delegation point.
        try {
            $this->quickSend($tenant, $business, '14155552677', 'Should not send');
            $this->fail('An unusable managed identity must not fall through to the legacy path.');
        } catch (MessagingIdentityConflictException $e) {
            $this->assertStringContainsString('active primary number', $e->getMessage());
        }

        $this->assertCount(0, $this->fakeAdapter->sentRequests);
        Http::assertNothingSent();
        $this->assertSame(
            0,
            Reports::query()->where('business_id', $business->id)->count(),
            'A managed Business with an unusable identity must not produce a legacy send.',
        );
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function operationCount(): int
    {
        return DB::table(ManagedMessageDispatcher::TABLE)->count();
    }

    private function assertOperationBelongsTo(
        \App\Models\BusinessMessagingIdentity $identity,
        \App\Models\BusinessMessagingNumber $number,
    ): void {
        $rows = DB::table(ManagedMessageDispatcher::TABLE)->get();

        $this->assertNotEmpty($rows, 'No operation row was written at all.');

        foreach ($rows as $row) {
            $this->assertSame((int) $identity->business_id, (int) $row->business_id);
            $this->assertSame((int) $identity->id, (int) $row->business_messaging_identity_id);
        }

        // The operations table records the identity, not the number (§4.2),
        // so the number is proven where it is actually used: on the request
        // the adapter received.
        foreach ($this->fakeAdapter->sentRequests as $request) {
            $this->assertSame((int) $number->id, $request->businessMessagingNumberId);
            $this->assertSame($number->phone_number, $request->fromNumber);
            $this->assertSame($identity->messaging_profile_id, $request->messagingProfileId);
        }
    }

    private function quickSend(Customer $tenant, \App\Models\Business $business, string $recipient, string $message): \Illuminate\Http\JsonResponse
    {
        $campaign = new Campaigns();
        $campaign->business_id = $business->id;

        return app(CampaignRepository::class)->quickSend($campaign, [
            'user' => $tenant->user,
            'user_id' => $tenant->user_id,
            'business_id' => $business->id,
            'sms_type' => 'plain',
            'sender_id' => 'TESTSENDER',
            'originator' => 'sender_id',
            'recipient' => $recipient,
            'phone' => $recipient,
            'country_code' => '1',
            'region_code' => 'US',
            'message' => $message,
        ]);
    }

    private function campaignPayload(ContactGroups $group): array
    {
        return [
            'name' => 'Managed Blast',
            'message' => 'Hello from the campaign builder',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['TESTSENDER'],
            // campaignBuilder() reads the coverage for `plan_id` straight
            // out of the request body, exactly as the real campaign-builder
            // form submits it.
            'plan_id' => $this->planId,
        ];
    }

    private function groupWithOneContact(Customer $tenant, \App\Models\Business $business, string $phone): ContactGroups
    {
        $group = ContactGroups::create([
            'customer_id' => $tenant->user_id,
            'business_id' => $business->id,
            'name' => 'Managed Group ' . uniqid(),
            'status' => true,
        ]);

        Contacts::create([
            'customer_id' => $tenant->user_id,
            'business_id' => $business->id,
            'group_id' => $group->id,
            'phone' => $phone,
            'status' => 'subscribe',
        ]);

        return $group;
    }

    /**
     * A Business that can legitimately send, WITH a managed identity.
     *
     * @return array{0: Customer, 1: \App\Models\Business, 2: \App\Models\BusinessMessagingIdentity, 3: \App\Models\BusinessMessagingNumber}
     */
    private function managedSendableTenant(): array
    {
        [$tenant, $business] = $this->sendableTenant();

        $identity = $this->attachIdentity($business);
        $number = $this->attachNumber($identity, $this->uniqueNumber(), true);

        config(['messaging.managed_messaging_enabled' => true]);

        return [$tenant, $business, $identity, $number];
    }

    /** @return array{0: Customer, 1: \App\Models\Business} */
    private function sendableTenant(): array
    {
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        $country = Country::firstOrCreate(
            ['country_code' => '1', 'iso_code' => 'US'],
            ['name' => 'United States', 'status' => 1],
        );
        $currency = Currency::firstOrCreate(
            ['code' => 'USD'],
            ['name' => 'US Dollar', 'format' => '$', 'status' => true],
        );

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Slice 3 Delegation Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        $this->planId = (int) $plan->id;

        // A legacy sending server, and the plan coverage pointing at it.
        //
        // Slice 3's delegation seam sits at quickSend()'s contracted
        // PRE-DISPATCH point (§4.11), which is only reached AFTER the legacy
        // coverage and sending-server resolution above it succeeds. So even
        // a fully managed Business still needs this legacy configuration to
        // reach the managed path at all. That is a real contradiction with
        // §4.7's "managed messaging is the normal experience", and it is
        // reported rather than papered over by silently moving the
        // insertion earlier — moving it would also bypass RFC-005's
        // Conversations reservation seam, which this slice is not
        // authorized to redesign. The fixture supplies the prerequisite so
        // that what this file asserts is the delegation seam itself.
        $sendingServer = SendingServer::create([
            'name' => 'Legacy Fixture Server',
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'plain' => true,
            'user_id' => $tenant->user_id,
            'account_sid' => 'AC_FIXTURE',
            'auth_token' => 'fixture_token',
            // sending_servers.quota_value defaults to 0, which the campaign
            // rate limiter reads as "zero sends allowed" and refuses the
            // very first job. RateLimit::UNLIMITED is what a real server
            // without a configured quota carries.
            'quota_value' => \App\Library\RateLimit::UNLIMITED,
        ]);

        CustomerBasedSendingServer::create([
            'user_id' => $tenant->user_id,
            'business_id' => $business->id,
            'sending_server' => $sendingServer->id,
            'status' => true,
        ]);

        PlansCoverageCountries::create([
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'status' => true,
            'sending_server' => $sendingServer->id,
            'options' => json_encode(['plain' => true, 'plain_sms' => 0.05]),
        ]);

        Subscription::create([
            'user_id' => $tenant->user_id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        Senderid::create([
            'user_id' => $tenant->user_id,
            'business_id' => $business->id,
            'sender_id' => 'TESTSENDER',
            'status' => 'active',
        ]);

        $tenant->user->sms_unit = 1000;
        $tenant->user->save();

        return [$tenant, $business->fresh()];
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])
            ->pluck('setting')
            ->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }
}
