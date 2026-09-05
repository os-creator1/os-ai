<?php

namespace Tests\Feature\Outreach;

use App\Helpers\Helper;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Subscription;
use App\Models\Templates;
use App\Models\User;
use App\Repositories\Contracts\CampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * B1 Pass 2 — Outreach / Compose, Business-addressable: reuse of the
 * existing Campaign send core with an explicit selected Business, campaign-
 * builder persistence, template/AI reuse, subscription/coverage protection
 * resolved from the Business owner, RFC-005 metering non-engagement, and
 * navigation consolidation.
 *
 * The send/campaign-builder tests bind a Mockery double for
 * CampaignRepository into the container rather than rebuilding the full
 * legacy SendingServer/coverage-relation fixture depth — that depth belongs
 * to EloquentCampaignRepository's own already-covered internals. What these
 * tests prove instead is the OutreachController wiring itself: the exact
 * sanitized payload shape (now including the explicit business_id/user_id
 * keys) reaching checkQuickSendValidation()/quickSend()/campaignBuilder(),
 * and that quickSend() is always invoked with exactly its 2 default-shaped
 * arguments (never a 3rd `true` conversationContext).
 */
class OutreachComposeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

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

    protected function tearDown(): void
    {
        // Tool::uploadImage() writes directly to public_path('mms/') rather
        // than through the Storage facade (pre-existing, unrelated to B1) —
        // clean up whatever the MMS send test caused it to write.
        if (File::isDirectory(public_path('mms'))) {
            File::deleteDirectory(public_path('mms'));
        }

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Manual SMS send reaches the existing send core with the explicit
    // selected Business threaded through as sendData['business_id'].
    // -----------------------------------------------------------------

    public function test_manual_sms_send_reaches_existing_quicksend_core_with_explicit_business(): void
    {
        [$tenant, $business, $fixture] = $this->sendableTenant();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $mockRepo = \Mockery::mock(CampaignRepository::class);
        $mockRepo->shouldReceive('checkQuickSendValidation')
            ->once()
            ->withArgs(function (array $input) use ($business) {
                return $input['message'] === 'Hello there'
                    && $input['business_id'] === $business->id
                    && $input['user_id'] === $business->customer_id
                    && ! array_key_exists('recipients', $input)
                    && ! array_key_exists('delimiter', $input)
                    && ! array_key_exists('_token', $input);
            })
            ->andReturn(response()->json([
                'status' => 'success', 'sender_id' => 'TestSender', 'sms_type' => 'plain', 'user_id' => $business->customer_id,
            ]));
        $mockRepo->shouldReceive('quickSend')
            ->once()
            ->withArgs(function ($campaign, array $sendData) use ($business) {
                return $campaign instanceof Campaigns && $campaign->business_id === $business->id;
            })
            ->andReturn(response()->json(['status' => 'success', 'message' => 'sent']));
        $this->app->instance(CampaignRepository::class, $mockRepo);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.send', [$business->workspace->uid, $business->uid]), [
            'recipients' => '4155552671',
            'delimiter' => ',',
            'message' => 'Hello there',
            'country_code' => $fixture['country']->id,
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.outreach.campaigns', [$business->workspace->uid, $business->uid]));
        $response->assertSessionHas('status', 'success');
        $this->assertSame(0, \DB::table('business_usage_reservations')->count(), 'Default Outreach quick-send must never engage RFC-005 M5 metering.');
    }

    public function test_manual_mms_send_reaches_existing_quicksend_core_with_explicit_business(): void
    {
        [$tenant, $business, $fixture] = $this->sendableTenant();
        $this->authenticateAsCustomer($tenant, ['mms_quick_send']);

        $mockRepo = \Mockery::mock(CampaignRepository::class);
        $mockRepo->shouldReceive('checkQuickSendValidation')
            ->once()
            ->withArgs(fn (array $input) => $input['business_id'] === $business->id)
            ->andReturn(response()->json([
                'status' => 'success', 'sender_id' => 'TestSender', 'sms_type' => 'mms', 'user_id' => $business->customer_id,
            ]));
        $mockRepo->shouldReceive('quickSend')
            ->once()
            ->with(\Mockery::type(Campaigns::class), \Mockery::type('array'))
            ->andReturn(response()->json(['status' => 'success', 'message' => 'sent']));
        $this->app->instance(CampaignRepository::class, $mockRepo);

        $response = $this->post(route('customer.workspaces.businesses.outreach.mms.send', [$business->workspace->uid, $business->uid]), [
            'recipients' => '4155552671',
            'delimiter' => ',',
            'message' => 'Picture attached',
            'country_code' => $fixture['country']->id,
            'mms_file' => UploadedFile::fake()->image('pic.jpg'),
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.outreach.campaigns', [$business->workspace->uid, $business->uid]));
        $this->assertSame(0, \DB::table('business_usage_reservations')->count());
    }

    // -----------------------------------------------------------------
    // Campaign-builder persistence receives the explicit Business, and
    // Business-scoped contact groups are reused unchanged.
    // -----------------------------------------------------------------

    public function test_sms_campaign_builder_reuses_existing_campaignbuilder_persistence_with_explicit_business(): void
    {
        [$tenant, $business] = $this->sendableTenant();
        $this->authenticateAsCustomer($tenant, ['sms_campaign_builder']);
        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $business->id, 'name' => 'VIPs', 'status' => true]);

        $mockRepo = \Mockery::mock(CampaignRepository::class);
        $mockRepo->shouldReceive('campaignBuilder')
            ->once()
            ->withArgs(function ($campaign, array $input) use ($group, $business) {
                return $campaign instanceof Campaigns
                    && $input['name'] === 'VIP Blast'
                    && $input['business_id'] === $business->id
                    && $input['user_id'] === $business->customer_id
                    && array_map('strval', $input['contact_groups']) === [(string) $group->id];
            })
            ->andReturn(response()->json(['status' => 'success', 'message' => 'queued']));
        $this->app->instance(CampaignRepository::class, $mockRepo);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.campaign', [$business->workspace->uid, $business->uid]), [
            'name' => 'VIP Blast',
            'contact_groups' => [$group->id],
            'message' => 'Hello VIPs',
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.outreach.campaigns', [$business->workspace->uid, $business->uid]));
        $response->assertSessionHas('status', 'success');
    }

    public function test_mms_campaign_builder_reuses_existing_campaignbuilder_persistence_with_explicit_business(): void
    {
        [$tenant, $business] = $this->sendableTenant();
        $this->authenticateAsCustomer($tenant, ['mms_campaign_builder']);
        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $business->id, 'name' => 'VIPs', 'status' => true]);

        $mockRepo = \Mockery::mock(CampaignRepository::class);
        $mockRepo->shouldReceive('campaignBuilder')
            ->once()
            ->withArgs(fn ($campaign, array $input) => $input['sms_type'] === 'mms' && $input['business_id'] === $business->id)
            ->andReturn(response()->json(['status' => 'success', 'message' => 'queued']));
        $this->app->instance(CampaignRepository::class, $mockRepo);

        $response = $this->post(route('customer.workspaces.businesses.outreach.mms.campaign', [$business->workspace->uid, $business->uid]), [
            'name' => 'VIP MMS Blast',
            'contact_groups' => [$group->id],
            'message' => 'Hello VIPs',
            'mms_file' => UploadedFile::fake()->image('pic.jpg'),
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.outreach.campaigns', [$business->workspace->uid, $business->uid]));
    }

    // -----------------------------------------------------------------
    // Legacy CSV import routes remain reachable (unaffected by B1 Pass 2)
    // -----------------------------------------------------------------

    public function test_legacy_sms_and_mms_import_routes_remain_reachable(): void
    {
        [$tenant] = $this->sendableTenant();
        $this->authenticateAsCustomer($tenant, ['sms_bulk_messages', 'mms_bulk_messages']);

        $this->get(route('customer.sms.import'))->assertOk();
        $this->get(route('customer.mms.import'))->assertOk();
    }

    // -----------------------------------------------------------------
    // Template application now reuses the new, Business-scoped
    // templates.show_data endpoint — never the legacy user-scoped one.
    // -----------------------------------------------------------------

    public function test_template_application_reuses_the_new_business_scoped_show_data_endpoint(): void
    {
        [$tenant, $business] = $this->sendableTenant();
        $template = Templates::create([
            'user_id' => $tenant->user_id,
            'business_id' => $business->id,
            'name' => 'Welcome',
            'message' => 'Welcome aboard!',
            'dlt_template_id' => 'DLT123',
            'status' => true,
        ]);
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->postJson(route('customer.workspaces.businesses.outreach.templates.show_data', [$business->workspace->uid, $business->uid, $template->id]));

        $response->assertOk();
        $response->assertJson(['status' => 'success', 'message' => 'Welcome aboard!', 'dlt_template_id' => 'DLT123']);
    }

    // -----------------------------------------------------------------
    // AI generation surfaced via the existing openai.generate route, no
    // duplicated backend, reachable from the Business-scoped page.
    // -----------------------------------------------------------------

    public function test_composer_wires_ai_generate_to_existing_openai_route_when_active(): void
    {
        config(['services.openai.active' => true]);
        [$tenant, $business] = $this->sendableTenant();
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee(route('customer.openai.generate'), false);
        $response->assertSee('data-role="ai-generate-trigger"', false);
    }

    // -----------------------------------------------------------------
    // Legacy subscription/coverage protection preserved, now resolved
    // from the selected Business's owning customer.
    // -----------------------------------------------------------------

    public function test_outreach_index_redirects_to_subscriptions_when_business_owner_has_no_active_subscription(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->get(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]));

        $response->assertRedirect(route('customer.subscriptions.index'));
        $response->assertSessionHas('status', 'error');
    }

    public function test_sms_send_redirects_when_business_owner_has_no_active_subscription(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());
        $this->authenticateAsCustomer($tenant, ['sms_quick_send']);

        $response = $this->post(route('customer.workspaces.businesses.outreach.sms.send', [$business->workspace->uid, $business->uid]), [
            'recipients' => '4155552671',
            'delimiter' => ',',
            'message' => 'Hello',
        ]);

        $response->assertRedirect(route('customer.workspaces.businesses.outreach.index', [$business->workspace->uid, $business->uid]));
        $response->assertSessionHas('status', 'error');
    }

    // -----------------------------------------------------------------
    // The raw QuickSend debug logging is still gone from the reused send
    // path (unaffected by B1 Pass 2).
    // -----------------------------------------------------------------

    public function test_raw_quicksend_debug_logging_has_been_removed_from_the_reused_send_path(): void
    {
        $controllerSource = file_get_contents(base_path('app/Http/Controllers/Customer/CampaignController.php'));
        $repositorySource = file_get_contents(base_path('app/Repositories/Eloquent/EloquentCampaignRepository.php'));

        $this->assertStringNotContainsString('QUICKSEND SENDDATA', $controllerSource);
        $this->assertStringNotContainsString('QUICKSEND COUNTRY LOOKUP', $repositorySource);
        $this->assertStringNotContainsString('QUICKSEND SERVER', $repositorySource);
        $this->assertStringNotContainsString('QUICKSEND PROVIDER RESPONSE', $repositorySource);
    }

    // -----------------------------------------------------------------
    // Navigation still points to one Outreach entry, not six legacy
    // channel silos (unaffected by B1 Pass 2).
    // -----------------------------------------------------------------

    public function test_customer_navigation_exposes_one_outreach_entry_not_six_channel_silos(): void
    {
        $menu = Helper::menuData();
        $customerMenu = collect($menu['customer']);
        $topLevelNames = $customerMenu->pluck('name')->all();

        $this->assertContains('Outreach', $topLevelNames);
        $this->assertNotContains('SMS', $topLevelNames);
        $this->assertNotContains('MMS', $topLevelNames);
        $this->assertNotContains('Voice', $topLevelNames);
        $this->assertNotContains('WhatsApp', $topLevelNames);
        $this->assertNotContains('Viber', $topLevelNames);
        $this->assertNotContains('OTP', $topLevelNames);

        $outreachEntry = $customerMenu->firstWhere('name', 'Outreach');
        $this->assertSame('sms_quick_send|sms_campaign_builder|mms_quick_send|mms_campaign_builder', $outreachEntry['access']);

        $sendingEntry = $customerMenu->firstWhere('name', 'Sending');
        $templateSubmenuNames = collect($sendingEntry['submenu'])->pluck('name')->all();
        $this->assertNotContains('SMS Template', $templateSubmenuNames, 'Standalone Templates must no longer be a first-class nav destination.');
    }

    // -----------------------------------------------------------------
    // Voice/WhatsApp/Viber/OTP backend confirmation — routes/controller
    // methods must remain reachable even though navigation no longer
    // links to them (unaffected by B1 Pass 2).
    // -----------------------------------------------------------------

    public function test_deferred_channel_backend_routes_remain_reachable(): void
    {
        [$tenant] = $this->sendableTenant();
        $this->authenticateAsCustomer($tenant, ['voice_quick_send', 'whatsapp_quick_send', 'viber_quick_send', 'otp_quick_send']);

        $this->get(route('customer.voice.quick_send'))->assertOk();
        $this->get(route('customer.whatsapp.quick_send'))->assertOk();
        $this->get(route('customer.viber.quick_send'))->assertOk();
        $this->get(route('customer.otp.quick_send'))->assertOk();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business, 2: array{country: Country, plan: Plan}}
     */
    private function sendableTenant(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Outreach Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        PlansCoverageCountries::create([
            'plan_id' => $plan->id,
            'country_id' => $country->id,
            'status' => true,
            'options' => json_encode(['plain' => true, 'mms' => true, 'plain_sms' => 0.05, 'mms_sms' => 0.10]),
        ]);

        Subscription::create([
            'user_id' => $tenant->user_id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        return [$tenant, $business, ['country' => $country, 'plan' => $plan]];
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
            $default = collect((new AppConfig())->defaultSettings())
                ->firstWhere('setting', 'customer_permissions');

            AppConfig::create($default);
        }
    }
}
