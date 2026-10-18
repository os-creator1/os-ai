<?php

namespace Tests\Feature\Demo;

use App\Enums\Automation\Workflow\ContactCreationSource;
use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Triggers\ContactCreatedTriggerSource;
use App\Library\Automation\Workflow\Triggers\ListeningWorkflows;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Library\Demo\BrightFrame\BrightFrameData;
use App\Library\Demo\BrightFrame\DemoProvisioningGuard;
use App\Models\AppConfig;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Support\TestDatabaseSafety;
use Tests\TestCase;

/**
 * The BrightFrame sales-demo provisioner, run end to end against the disposable
 * test database. Proves the guard fails closed, a full run populates every
 * module, a second run changes nothing, nothing outside the demo tenant is
 * touched, and the safety audit finds no way to message, charge or call out.
 */
class BrightFrameProvisionerTest extends TestCase
{
    use CreatesBusinessTestData;
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery-staple-91';

    protected function setUp(): void
    {
        parent::setUp();

        TestDatabaseSafety::activeTestDatabase();

        User::create([
            'first_name' => 'Platform', 'last_name' => 'Owner', 'email' => 'platform-owner@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        foreach (['license' => 'x', 'custom_script' => ''] as $setting => $value) {
            AppConfig::firstOrCreate(['setting' => $setting], ['value' => $value]);
        }
        $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
        AppConfig::firstOrCreate(['setting' => 'customer_permissions'], ['value' => $default['value']]);

        config(['app.url' => 'https://staging.getmotiongrove.com']);
        $this->optIn(true);
    }

    protected function tearDown(): void
    {
        $this->optIn(false);
        putenv('BF_TEST_PASSWORD');
        unset($_SERVER['BF_TEST_PASSWORD'], $_ENV['BF_TEST_PASSWORD']);

        parent::tearDown();
    }

    private function optIn(bool $on): void
    {
        $value = $on ? 'true' : 'false';
        putenv(DemoProvisioningGuard::OPT_IN_ENV . '=' . $value);
        $_SERVER[DemoProvisioningGuard::OPT_IN_ENV] = $value;
        $_ENV[DemoProvisioningGuard::OPT_IN_ENV] = $value;
    }

    private function provision(array $extra = []): int
    {
        putenv('BF_TEST_PASSWORD=' . self::PASSWORD);
        $_SERVER['BF_TEST_PASSWORD'] = self::PASSWORD;
        $_ENV['BF_TEST_PASSWORD'] = self::PASSWORD;

        return Artisan::call('demo:provision-brightframe', array_merge([
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--password-env' => 'BF_TEST_PASSWORD',
        ], $extra));
    }

    private function demoBusiness(): Business
    {
        return Business::query()->where('name', BrightFrameData::BUSINESS_NAME)->firstOrFail();
    }

    /** @return array<string, int> */
    private function footprint(Business $business): array
    {
        $id = $business->id;

        return [
            'contacts' => DB::table('contacts')->where('business_id', $id)->count(),
            'opportunities' => DB::table('crm_opportunities')->where('business_id', $id)->count(),
            'conversations' => DB::table('chat_boxes')->where('business_id', $id)->count(),
            'messages' => DB::table('chat_box_messages')->join('chat_boxes', 'chat_boxes.id', '=', 'chat_box_messages.box_id')->where('chat_boxes.business_id', $id)->count(),
            'appointments' => DB::table('appointments')->join('business_locations', 'business_locations.id', '=', 'appointments.business_location_id')->where('business_locations.business_id', $id)->count(),
            'services' => DB::table('business_services')->where('business_id', $id)->count(),
            'packages' => DB::table('catalog_items')->where('business_id', $id)->count(),
            'forms' => DB::table('forms')->where('business_id', $id)->count(),
            'submissions' => DB::table('form_submissions')->where('business_id', $id)->count(),
            'workflows' => DB::table('automation_workflows')->where('business_id', $id)->count(),
            'documents' => DB::table('business_documents')->where('business_id', $id)->count(),
            'keywords' => DB::table('seo_keywords')->where('business_id', $id)->count(),
            'rank_observations' => DB::table('seo_rank_observations')->where('business_id', $id)->count(),
            'google_ads_metrics' => DB::table('google_ads_daily_metrics')->where('business_id', $id)->count(),
            'meta_ads_insights' => DB::table('meta_ads_daily_insights')->where('business_id', $id)->count(),
            'website_pages' => DB::table('website_pages')->join('websites', 'websites.id', '=', 'website_pages.website_id')->where('websites.business_id', $id)->count(),
            'website_assets' => DB::table('website_assets')->join('websites', 'websites.id', '=', 'website_assets.website_id')->where('websites.business_id', $id)->count(),
            'revisions' => DB::table('website_revisions')->join('websites', 'websites.id', '=', 'website_revisions.website_id')->where('websites.business_id', $id)->count(),
            'articles' => DB::table('website_articles')->where('business_id', $id)->count(),
        ];
    }

    public function test_it_refuses_without_the_explicit_opt_in(): void
    {
        $this->optIn(false);

        $this->assertSame(1, $this->provision());
        $this->assertSame(0, Business::query()->where('name', BrightFrameData::BUSINESS_NAME)->count());
    }

    public function test_it_refuses_a_host_that_is_not_the_staging_host_whatever_app_env_says(): void
    {
        config(['app.url' => 'https://app.getmotiongrove.com', 'app.env' => 'staging']);

        $this->assertSame(1, $this->provision());
        $this->assertSame(0, User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->count());
    }

    public function test_it_accepts_the_staging_host_even_when_app_env_is_production(): void
    {
        config(['app.env' => 'production']);

        $state = app(DemoProvisioningGuard::class)->assertMayProvision(DB::connection()->getDatabaseName());

        $this->assertSame('staging.getmotiongrove.com', $state['app_host']);
    }

    public function test_it_refuses_when_the_confirmed_database_is_not_the_connected_one(): void
    {
        $this->assertSame(1, $this->provision(['--confirm-database' => 'some_other_database']));
        $this->assertSame(0, User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->count());
    }

    public function test_inspect_changes_nothing(): void
    {
        $this->assertSame(0, Artisan::call('demo:provision-brightframe', ['--inspect' => true]));
        $this->assertStringContainsString('staging.getmotiongrove.com', Artisan::output());
        $this->assertSame(0, User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->count());
    }

    public function test_it_rejects_a_short_password(): void
    {
        $_SERVER['BF_TEST_PASSWORD'] = 'short';
        putenv('BF_TEST_PASSWORD=short');

        $this->expectException(\RuntimeException::class);
        Artisan::call('demo:provision-brightframe', [
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--password-env' => 'BF_TEST_PASSWORD',
        ]);
    }

    public function test_a_full_run_populates_every_module_with_a_working_login(): void
    {
        $this->assertSame(0, $this->provision(), Artisan::output());

        $business = $this->demoBusiness();
        $footprint = $this->footprint($business);

        $this->assertSame(24, $footprint['contacts']);
        $this->assertSame(18, $footprint['opportunities']);
        $this->assertSame(10, $footprint['conversations']);
        $this->assertSame(16, $footprint['appointments']);
        $this->assertSame(5, $footprint['services']);
        $this->assertSame(3, $footprint['packages']);
        $this->assertSame(3, $footprint['forms']);
        $this->assertGreaterThan(0, $footprint['submissions']);
        $this->assertSame(6, $footprint['workflows']);
        $this->assertSame(7, $footprint['documents']);
        $this->assertSame(6, $footprint['keywords']);
        $this->assertGreaterThan(0, $footprint['rank_observations']);
        $this->assertGreaterThan(0, $footprint['google_ads_metrics']);
        $this->assertGreaterThan(0, $footprint['meta_ads_insights']);
        $this->assertSame(1, $footprint['revisions']);
        $this->assertSame(3, $footprint['articles']);
        $this->assertGreaterThanOrEqual(10, $footprint['website_pages']);

        $user = User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('complimentary', $this->planKind($business));
    }

    private function planKind(Business $business): string
    {
        $assignment = DB::table('workspace_plan_assignments')->where('workspace_id', $business->workspace_id)->first();

        return $assignment->is_complimentary ? 'complimentary' : 'paid';
    }

    public function test_a_second_run_changes_nothing_and_keeps_the_password(): void
    {
        $this->provision();
        $first = $this->footprint($this->demoBusiness());
        $passwordHash = User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->value('password');

        $this->assertSame(0, $this->provision(), Artisan::output());

        $this->assertSame($first, $this->footprint($this->demoBusiness()));
        $this->assertSame($passwordHash, User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->value('password'));
        $this->assertSame(1, Business::query()->where('name', BrightFrameData::BUSINESS_NAME)->count());
    }

    public function test_rotate_password_replaces_only_the_password(): void
    {
        $this->provision();
        $before = $this->footprint($this->demoBusiness());

        $_SERVER['BF_TEST_PASSWORD'] = 'a-different-secret-password-77';
        putenv('BF_TEST_PASSWORD=a-different-secret-password-77');
        $this->assertSame(0, Artisan::call('demo:provision-brightframe', [
            '--confirm-database' => DB::connection()->getDatabaseName(),
            '--password-env' => 'BF_TEST_PASSWORD',
            '--rotate-password' => true,
        ]));

        $user = User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->firstOrFail();
        $this->assertTrue(Hash::check('a-different-secret-password-77', $user->password));
        $this->assertSame($before, $this->footprint($this->demoBusiness()));
    }

    public function test_it_never_touches_another_business_and_will_not_adopt_a_foreign_user(): void
    {
        $this->ensureCustomerOwnedBusiness($other = $this->createCustomer());
        $otherBusiness = Business::query()->where('customer_id', $other->user_id)->firstOrFail();
        $otherBefore = $this->footprint($otherBusiness);

        $this->provision();

        $this->assertSame($otherBefore, $this->footprint($otherBusiness->fresh()));
        $this->assertSame(0, Contacts::query()->where('business_id', $otherBusiness->id)->count());

        // A pre-existing real user with the login email must be refused, never taken over.
        User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->update(['email' => 'renamed@example.test']);
        $stranger = User::create([
            'first_name' => 'Real', 'last_name' => 'Customer', 'email' => BrightFrameData::DEFAULT_LOGIN_EMAIL,
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);

        try {
            $this->provision(['--rotate-password' => true]);
            $this->fail('A foreign user must not be adopted.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not the BrightFrame demo owner', $e->getMessage());
        }

        $this->assertNull($stranger->fresh()->password);
    }

    private function ensureCustomerOwnedBusiness($customer): void
    {
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Someone Elses Real Business']));
    }

    public function test_the_demo_tenant_has_no_way_to_message_charge_or_call_out(): void
    {
        $this->provision();
        $business = $this->demoBusiness();

        foreach (['business_messaging_identities', 'business_email_accounts', 'sending_servers', 'phone_numbers', 'business_funding_attempts'] as $table) {
            $column = $table === 'sending_servers' ? 'user_id' : 'business_id';
            $value = $table === 'sending_servers' ? $business->customer_id : $business->id;
            $this->assertSame(0, DB::table($table)->where($column, $value)->count(), $table);
        }

        $this->assertSame(0, DB::table('business_google_connections')->where('business_id', $business->id)->whereNotNull('refresh_token_encrypted')->count());
        $this->assertSame(0, DB::table('business_meta_connections')->where('business_id', $business->id)->whereNotNull('access_token_encrypted')->count());
        $this->assertSame(0, DB::table('business_stripe_connections')->where('business_id', $business->id)->where('charges_enabled', true)->count());
        $this->assertSame(0, DB::table('website_domains')->count());
        $this->assertSame(0, DB::table('booking_types')->where('notify_email', true)->orWhere('notify_sms', true)->count());
        $this->assertSame(0, DB::table('automation_enrollments')->count(), 'provisioning must not enrol anyone in a live workflow');
        $this->assertSame(0, DB::table('seo_rank_targets')->where('next_check_at', '<=', now()->addYear())->count());
    }

    public function test_demo_workflows_cannot_cause_outbound_actions_after_provisioning_has_finished(): void
    {
        $this->provision();
        $business = $this->demoBusiness();

        // Draft workflows keep their full configuration for the builder UI...
        $workflows = AutomationWorkflow::query()->where('business_id', $business->id)->get();
        $this->assertCount(6, $workflows);
        foreach ($workflows as $workflow) {
            $this->assertSame(WorkflowStatus::Draft, $workflow->status, $workflow->name);
            $this->assertNull($workflow->published_version_id, $workflow->name);
            $this->assertNotEmpty($workflow->draftVersion()->definition['root']['next'] ?? [], $workflow->name);
        }

        // ...and the command is over. Fakes are installed NOW, so they prove what normal requests, jobs and workers can do.
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Http::fake();

        foreach (WorkflowTriggerType::cases() as $type) {
            $this->assertSame([], app(ListeningWorkflows::class)->for($business->id, $type), $type->value . ' must have no listening workflow');
        }

        $contact = Contacts::query()->where('business_id', $business->id)->firstOrFail();
        $this->assertSame(0, app(ContactCreatedTriggerSource::class)->handleContactCreated($contact, ContactCreationSource::Manual));

        $this->assertSame(0, DB::table('automation_enrollments')->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    public function test_a_workflow_left_published_by_an_earlier_run_is_paused_by_the_next_run(): void
    {
        $this->provision();
        $business = $this->demoBusiness();
        $workflow = AutomationWorkflow::query()->where('business_id', $business->id)->where('name', 'Alert Team to New Text Messages')->firstOrFail();
        app(WorkflowPublisher::class)->publish($workflow, null);
        $this->assertSame(WorkflowStatus::Published, $workflow->fresh()->status);

        $this->assertSame(0, $this->provision());

        $this->assertSame(WorkflowStatus::Paused, $workflow->fresh()->status);
        $this->assertSame([], app(ListeningWorkflows::class)->for($business->id, WorkflowTriggerType::MessageReceived));
        $this->assertSame(0, DB::table('automation_workflows')->where('business_id', $business->id)->where('status', 'published')->count());
    }

    public function test_the_demo_user_can_log_in_and_the_website_is_public(): void
    {
        $this->provision();
        $user = User::query()->where('email', BrightFrameData::DEFAULT_LOGIN_EMAIL)->firstOrFail();
        $business = $this->demoBusiness();
        $workspace = $business->workspace;
        $website = Website::query()->where('business_id', $business->id)->firstOrFail();

        $this->get('/sites/' . $website->public_id)->assertOk()->assertSee('BrightFrame Photo Booths');

        $user->forceFill(['email_verified_at' => now()])->save();
        $this->withSession(['permissions' => collect(array_merge(['access_backend'], array_keys(config('customer-permissions'))))]);
        $this->actingAs($user);

        $base = '/workspaces/' . $workspace->uid . '/businesses/' . $business->uid;
        foreach (['/people', '/opportunities', '/conversations', '/forms', '/catalog', '/documents', '/seo/keywords', '/seo/citations', '/gbp', '/ads', '/ads/meta', '/website', '/automations/workflows'] as $path) {
            $this->get($base . $path)->assertOk();
        }
        $this->get('/dashboard')->assertOk();
    }
}
