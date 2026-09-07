<?php

namespace Tests\Feature\Analytics;

use App\Models\Campaigns;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\PlansCoverageCountries;
use App\Models\Senderid;
use App\Models\Subscription;
use App\Notifications\SendCampaignCopy;
use App\Repositories\Contracts\CampaignRepository;
use App\Repositories\Eloquent\EloquentCampaignRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\Feature\Analytics\Concerns\CreatesAnalyticsFixtures;
use Tests\TestCase;

/**
 * B5 dangling-route correction. B5 deleted the customer Reports product and
 * its `customer.reports.*` route names, but three stop-listed producers kept
 * calling them: the thirteen success redirects of the legacy (non
 * Business-scoped) CampaignController actions, the campaign-copy e-mail link
 * built in EloquentCampaignRepository, and an unreachable duplicate guard in
 * Admin\ReportsController. Every reachable call now targets a route that
 * exists, and the e-mail link is derived from the campaign's own persisted
 * Business and Workspace only.
 */
class AnalyticsLegacyRouteConsumerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAnalyticsFixtures;

    private const ALL_LEGACY_PERMISSIONS = [
        'sms_quick_send', 'voice_quick_send', 'mms_quick_send', 'whatsapp_quick_send', 'viber_quick_send', 'otp_quick_send',
        'sms_campaign_builder', 'voice_campaign_builder', 'mms_campaign_builder', 'whatsapp_campaign_builder', 'viber_campaign_builder', 'otp_campaign_builder',
        'view_reports',
    ];

    protected function tearDown(): void
    {
        // Tool::uploadImage() writes MMS media directly under public_path('mms')
        // (pre-existing behaviour, unrelated to B5) — leave no artifact behind.
        if (File::isDirectory(public_path('mms'))) {
            File::deleteDirectory(public_path('mms'));
        }

        parent::tearDown();
    }

    // ---------------------------------------------------------------
    // The thirteen corrected CampaignController redirects
    // ---------------------------------------------------------------

    /**
     * Six legacy quick-send actions: their success redirect used to target
     * customer.reports.all. None of these routes carries a Workspace or a
     * Business parameter, so the only safe destination is the bare
     * Analytics chooser, which resolves the Business itself.
     */
    public function test_the_six_legacy_quick_send_success_redirects_target_the_analytics_entry(): void
    {
        [$customer, , , $fixture] = $this->sendableTenant();
        $this->authenticateAsCustomer($customer, self::ALL_LEGACY_PERMISSIONS);

        $base = ['recipients' => '4155552671', 'delimiter' => ',', 'country_code' => $fixture['country']->id];
        $cases = [
            'sms' => ['message' => 'Hello there'],
            'voice' => ['message' => 'Hello there', 'language' => 'en-US', 'gender' => 'female'],
            'mms' => ['message' => 'Picture attached', 'mms_file' => UploadedFile::fake()->image('pic.jpg')],
            'whatsapp' => ['message' => 'Hello there'],
            'viber' => ['message' => 'Hello there'],
            'otp' => ['message' => 'Your code is 1234'],
        ];

        foreach ($cases as $channel => $input) {
            $this->bindRepositoryMock([
                'checkQuickSendValidation' => response()->json([
                    'status' => 'success', 'sender_id' => 'TestSender', 'sms_type' => $channel === 'sms' ? 'plain' : $channel, 'user_id' => $customer->user_id,
                ]),
                'quickSend' => response()->json(['status' => 'success', 'message' => 'sent']),
            ]);

            $response = $this->post(route('customer.' . $channel . '.quick_send'), $base + $input);

            $response->assertRedirect(route('customer.analytics.entry'));
            // The SMS action flashes 'success'; the other five channels flash 'info' (pre-existing).
            $response->assertSessionHas('status', $channel === 'sms' ? 'success' : 'info');
            $this->assertTrue(Route::has('customer.analytics.entry'), $channel . ': the redirect target must be a registered route.');
        }
    }

    /**
     * Six legacy campaign-builder actions: their success redirect used to
     * target customer.reports.campaigns.
     */
    public function test_the_six_legacy_campaign_builder_success_redirects_target_the_analytics_entry(): void
    {
        [$customer, $business] = $this->sendableTenant();
        $this->authenticateAsCustomer($customer, self::ALL_LEGACY_PERMISSIONS);
        $group = $this->group($business);

        $base = ['name' => 'Legacy builder campaign', 'contact_groups' => [$group->id], 'message' => 'Hello there'];
        $cases = [
            'sms' => [],
            'voice' => ['language' => 'en-US', 'gender' => 'female'],
            'mms' => ['mms_file' => UploadedFile::fake()->image('pic.jpg')],
            'whatsapp' => [],
            'viber' => [],
            'otp' => [],
        ];

        foreach ($cases as $channel => $input) {
            $this->bindRepositoryMock([
                'campaignBuilder' => response()->json(['status' => 'success', 'message' => 'Campaign created']),
            ]);

            $response = $this->post(route('customer.' . $channel . '.campaign_builder'), $base + $input);

            $response->assertRedirect(route('customer.analytics.entry'));
            $response->assertSessionHas('status', 'success');
            $response->assertSessionHas('message', 'Campaign created');
        }
    }

    /**
     * The file-import processing action: its success redirect used to
     * target customer.reports.campaigns.
     */
    public function test_the_legacy_import_process_success_redirect_targets_the_analytics_entry(): void
    {
        [$customer] = $this->sendableTenant();
        $this->authenticateAsCustomer($customer, self::ALL_LEGACY_PERMISSIONS);
        $this->bindRepositoryMock([
            'sendUsingFile' => response()->json(['status' => 'success', 'message' => 'Import queued']),
        ]);

        $response = $this->post(route('customer.sms.import_process'), ['form_data' => json_encode(['sms_type' => 'plain'])]);

        $response->assertRedirect(route('customer.analytics.entry'));
        $response->assertSessionHas('status', 'success');
    }

    /**
     * The bare entry already proves 0 / 1 / many accessible Businesses in
     * AnalyticsSecurityTest. What the correction adds is that the flash left
     * by a legacy action survives the single-Business hop and is rendered on
     * the overview, so the sender still sees the outcome.
     */
    public function test_the_legacy_flash_survives_the_single_business_entry_redirect_and_is_rendered(): void
    {
        [$customer, $business, $workspace, $fixture] = $this->sendableTenant();
        $this->authenticateAsCustomer($customer, self::ALL_LEGACY_PERMISSIONS);
        $this->bindRepositoryMock([
            'checkQuickSendValidation' => response()->json(['status' => 'success', 'sender_id' => 'TestSender', 'sms_type' => 'plain', 'user_id' => $customer->user_id]),
            'quickSend' => response()->json(['status' => 'success', 'message' => 'sent']),
        ]);

        $this->post(route('customer.sms.quick_send'), ['recipients' => '4155552671', 'delimiter' => ',', 'message' => 'Hi', 'country_code' => $fixture['country']->id])
            ->assertRedirect(route('customer.analytics.entry'));

        $this->get(route('customer.analytics.entry'))
            ->assertRedirect(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $this->overview($workspace, $business)
            ->assertOk()
            ->assertSee('data-role="flash-message"', false)
            ->assertSee(__('locale.campaigns.message_successfully_delivered'));
    }

    public function test_the_entry_renders_a_flash_for_a_customer_without_an_accessible_business(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $this->authenticateAsCustomer($customer, ['view_reports']);

        $this->withSession(['status' => 'success', 'message' => 'Quick send finished'])
            ->get(route('customer.analytics.entry'))
            ->assertOk()
            ->assertSee('data-role="flash-message"', false)
            ->assertSee('Quick send finished')
            ->assertSee('No Business available yet');

        // The five non-SMS quick-send actions flash 'info'; the alert component
        // has no such variant, so it must render as the accent alert, not danger.
        $this->withSession(['status' => 'info', 'message' => 'Voice call queued'])
            ->get(route('customer.analytics.entry'))
            ->assertOk()
            ->assertSee('Voice call queued')
            ->assertSee('alert-primary', false)
            ->assertDontSee('alert-danger', false);
    }

    // ---------------------------------------------------------------
    // Campaign-copy notification link
    // ---------------------------------------------------------------

    public function test_the_campaign_copy_link_targets_the_campaigns_own_business_and_workspace(): void
    {
        [, $businessA, $workspaceA] = $this->tenant('America/New_York', 'Tenant A');
        [, $businessB, $workspaceB] = $this->tenant('America/New_York', 'Tenant B');
        $campaignA = $this->campaign($businessA, ['campaign_name' => 'A copy']);
        $campaignB = $this->campaign($businessB, ['campaign_name' => 'B copy']);
        $repository = app(EloquentCampaignRepository::class);

        $linkA = $repository->campaignCopyLink($campaignA);
        $linkB = $repository->campaignCopyLink($campaignB);

        $this->assertSame(route('customer.workspaces.businesses.outreach.campaigns.show', [$workspaceA->uid, $businessA->uid, $campaignA->uid]), $linkA);
        $this->assertSame(route('customer.workspaces.businesses.outreach.campaigns.show', [$workspaceB->uid, $businessB->uid, $campaignB->uid]), $linkB);
        $this->assertStringNotContainsString($businessB->uid, $linkA, 'A campaign can never link into another Business.');
        $this->assertStringNotContainsString($workspaceB->uid, $linkA);
        $this->assertStringNotContainsString($businessA->uid, $linkB);
        $this->assertTrue(Route::has('customer.workspaces.businesses.outreach.campaigns.show'));
    }

    /**
     * Rule 6: the identifiers come from the persisted Business relationship,
     * not from the campaign's user_id. A campaign whose user_id points at a
     * different customer still links into the Business it is persisted
     * under, and a campaign without any persisted Business gets the bare
     * Outreach chooser, which carries no tenant identifier at all.
     */
    public function test_the_campaign_copy_link_never_produces_another_businesss_url(): void
    {
        [$customerA, $businessA, $workspaceA] = $this->tenant('America/New_York', 'Tenant A');
        [, $businessB, $workspaceB] = $this->tenant('America/New_York', 'Tenant B');
        $repository = app(EloquentCampaignRepository::class);

        $mismatched = $this->campaign($businessB, ['user_id' => $customerA->user_id, 'campaign_name' => 'Mismatched']);
        $orphan = Campaigns::create([
            'user_id' => $customerA->user_id,
            'business_id' => null,
            'campaign_name' => 'Orphan',
            'message' => 'Hello',
            'sms_type' => 'plain',
            'status' => Campaigns::STATUS_DONE,
        ])->fresh();

        $this->assertSame(
            route('customer.workspaces.businesses.outreach.campaigns.show', [$workspaceB->uid, $businessB->uid, $mismatched->uid]),
            $repository->campaignCopyLink($mismatched)
        );
        $this->assertStringNotContainsString($businessA->uid, $repository->campaignCopyLink($mismatched));

        $orphanLink = $repository->campaignCopyLink($orphan);
        $this->assertSame(route('customer.outreach.index'), $orphanLink);
        foreach ([$businessA->uid, $businessB->uid, $workspaceA->uid, $workspaceB->uid, $orphan->uid] as $identifier) {
            $this->assertStringNotContainsString($identifier, $orphanLink);
        }
    }

    /**
     * End to end through the real campaignBuilder(): the "send me a copy"
     * e-mail carries the canonical Business-scoped URL of the campaign that
     * was actually created.
     */
    public function test_the_campaign_copy_notification_carries_the_canonical_business_scoped_url(): void
    {
        Notification::fake();
        [$customer, $business, $workspace, $fixture] = $this->sendableTenant();
        $this->actingAs($customer->user);
        $group = $this->group($business);
        Contacts::create(['customer_id' => $customer->user_id, 'business_id' => $business->id, 'group_id' => $group->id, 'phone' => '14155552671', 'status' => 'subscribe']);
        Senderid::create(['user_id' => $customer->user_id, 'business_id' => $business->id, 'sender_id' => 'TESTSENDER', 'status' => 'active']);

        $result = app(CampaignRepository::class)->campaignBuilder(new Campaigns(), [
            'name' => 'Copy Me Campaign',
            'message' => 'Hello copy',
            'sms_type' => 'plain',
            'contact_groups' => [$group->id],
            'originator' => 'sender_id',
            'sender_id' => ['TESTSENDER'],
            'plan_id' => $fixture['plan']->id,
            'business_id' => $business->id,
            'advanced' => 'true',
            'send_copy' => 'true',
        ]);

        $this->assertSame('success', $result->getData()->status ?? null, 'campaignBuilder() must complete: ' . json_encode($result->getData()));
        $campaign = Campaigns::where('campaign_name', 'Copy Me Campaign')->first();
        $this->assertNotNull($campaign);
        $this->assertSame($business->id, $campaign->business_id);
        $expected = route('customer.workspaces.businesses.outreach.campaigns.show', [$workspace->uid, $business->uid, $campaign->uid]);

        Notification::assertSentTo($customer->user, SendCampaignCopy::class, function (SendCampaignCopy $notification) use ($customer, $expected): bool {
            return $notification->toMail($customer->user)->actionUrl === $expected;
        });
    }

    // ---------------------------------------------------------------
    // No stale route name survives in reachable PHP; the dead retained views
    // are proven unreachable; the admin duplicate block is gone.
    // ---------------------------------------------------------------

    private const DEAD_RETAINED_VIEWS = [
        'resources/views/customer/Campaigns/overview.blade.php',
        'resources/views/customer/Campaigns/_contacts.blade.php',
        'resources/views/customer/Campaigns/updateCampaignBuilder.blade.php',
    ];

    public function test_no_deleted_customer_reports_route_name_remains_in_reachable_php(): void
    {
        $hits = [];
        foreach ([app_path(), base_path('routes'), base_path('config')] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if ($file->getExtension() === 'php' && str_contains(php_strip_whitespace($file->getPathname()), 'customer.reports.')) { // comments stripped: only executable code counts
                    $hits[] = str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname());
                }
            }
        }

        $this->assertSame([], $hits, 'customer.reports.* is still referenced by reachable PHP.');

        // The only remaining textual hits live in views nothing renders.
        $viewHits = [];
        foreach (File::allFiles(resource_path('views')) as $file) {
            if (str_contains(file_get_contents($file->getPathname()), 'customer.reports.')) {
                $viewHits[] = str_replace('\\', '/', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname()));
            }
        }
        sort($viewHits);
        $expected = self::DEAD_RETAINED_VIEWS;
        sort($expected);
        $this->assertSame($expected, $viewHits, 'A view outside the known dead set references a deleted report route.');
    }

    public function test_the_dead_retained_campaign_views_have_no_caller(): void
    {
        $names = ['customer.Campaigns.overview', 'customer.Campaigns._contacts', 'customer.Campaigns._overview', 'customer.Campaigns.updateCampaignBuilder'];
        $callers = [];
        $files = array_merge(
            File::allFiles(app_path()),
            File::allFiles(base_path('routes')),
            File::allFiles(resource_path('views'))
        );

        foreach ($files as $file) {
            $relative = str_replace('\\', '/', str_replace(base_path() . DIRECTORY_SEPARATOR, '', $file->getPathname()));
            if (in_array($relative, self::DEAD_RETAINED_VIEWS, true) || $relative === 'resources/views/customer/Campaigns/_overview.blade.php') {
                continue; // the dead views may reference each other
            }
            $source = file_get_contents($file->getPathname());
            foreach ($names as $name) {
                if (str_contains($source, $name)) {
                    $callers[] = $relative . ' -> ' . $name;
                }
            }
        }

        $this->assertSame([], $callers, 'A dead retained Campaign view is still rendered from somewhere.');
    }

    public function test_the_admin_campaign_overview_keeps_only_its_admin_guard(): void
    {
        $source = file_get_contents(app_path('Http/Controllers/Admin/ReportsController.php'));

        $this->assertStringNotContainsString("route('customer.", $source, 'Admin flows must never redirect into customer routes.');
        $this->assertSame(1, substr_count($source, "return redirect()->route('admin.reports.campaigns')->with(["), 'Exactly one campaignOverview guard remains.');
        $this->assertTrue(Route::has('admin.reports.campaigns'));
    }

    // ---------------------------------------------------------------
    // Neighbours merged from main keep working
    // ---------------------------------------------------------------

    public function test_b1_canonical_campaign_show_route_still_renders_for_the_owner(): void
    {
        [$customer, $business, $workspace] = $this->tenant();
        $campaign = $this->campaign($business, ['status' => Campaigns::STATUS_PAUSED]);
        $this->authenticateAsCustomer($customer, ['sms_campaign_builder']);

        $this->get(route('customer.workspaces.businesses.outreach.campaigns.show', [$workspace->uid, $business->uid, $campaign->uid]))
            ->assertOk()
            ->assertSee($campaign->campaign_name);
    }

    public function test_website_routes_and_the_gbp_contract_survive_the_merge(): void
    {
        foreach (['customer.website.index', 'customer.workspaces.businesses.website.show', 'customer.workspaces.businesses.website.pages.index', 'public.website.home', 'public.website.page'] as $name) {
            $this->assertTrue(Route::has($name), $name . ' must remain registered.');
        }
        foreach (['customer.analytics.entry', 'customer.workspaces.businesses.analytics.overview', 'customer.workspaces.businesses.analytics.campaigns', 'customer.workspaces.businesses.analytics.series'] as $name) {
            $this->assertTrue(Route::has($name), $name . ' must remain registered.');
        }

        $this->assertFileExists(base_path('docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md'));
        $this->assertFileExists(base_path('docs/automation/B5-BUSINESS-ANALYTICS-CONTRACT.md'));
    }

    // ---------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: \App\Models\Business, 2: \App\Models\Workspace, 3: array{country: Country, plan: Plan}}
     */
    private function sendableTenant(): array
    {
        [$customer, $business, $workspace] = $this->tenant();

        $country = Country::firstOrCreate(['country_code' => '1', 'iso_code' => 'US'], ['name' => 'United States', 'status' => 1]);
        $currency = Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'currency_id' => $currency->id,
            'name' => 'Route Correction Plan ' . uniqid(),
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
            'options' => json_encode([
                'plain' => true, 'voice' => true, 'mms' => true, 'whatsapp' => true, 'viber' => true, 'otp' => true,
                'plain_sms' => 0.05, 'voice_sms' => 0.05, 'mms_sms' => 0.10, 'whatsapp_sms' => 0.05, 'viber_sms' => 0.05, 'otp_sms' => 0.05,
            ]),
        ]);

        Subscription::create([
            'user_id' => $customer->user_id,
            'plan_id' => $plan->id,
            'status' => Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);

        $customer->user->sms_unit = 1000;
        $customer->user->save();

        return [$customer, $business, $workspace, ['country' => $country, 'plan' => $plan]];
    }

    /**
     * Binds a CampaignRepository double returning the given JSON responses,
     * so the legacy controller actions run their own precondition chain and
     * reach their success redirect without engaging the sending core.
     */
    private function bindRepositoryMock(array $responses): void
    {
        $mock = Mockery::mock(CampaignRepository::class);
        foreach ($responses as $method => $response) {
            $mock->shouldReceive($method)->once()->andReturn($response);
        }
        $this->app->instance(CampaignRepository::class, $mock);
    }
}
