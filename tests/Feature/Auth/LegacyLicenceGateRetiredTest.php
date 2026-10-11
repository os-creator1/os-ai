<?php

namespace Tests\Feature\Auth;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\Feature\PlatformOwner\Concerns\PlatformOwnerFixtures;
use Tests\TestCase;

/**
 * The inherited Ultimate SMS purchase-code gate ("ValidProduct", packages/kashem/licenseChecker) was retired by the
 * owner's decision (docs/product/LICENSING-LEGACY-GATE.md). These tests pin that it is gone AND that access control
 * did not loosen with it: guests, customers, tenants and the Platform Owner area behave exactly as before.
 */
class LegacyLicenceGateRetiredTest extends TestCase
{
    use PlatformOwnerFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootPlatformOwnerFixtures();
        // A fresh install has no licence rows at all.
        AppConfig::whereIn('setting', ['license', 'license_type', 'valid_domain'])->delete();
    }

    public function test_a_platform_owner_reaches_the_dashboard_with_no_licence_rows(): void
    {
        $this->actingAsPlatformOwner();

        $this->get(route('admin.home'))->assertOk();
    }

    public function test_a_customer_reaches_their_business_with_no_licence_rows_but_never_the_admin_area(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->get(route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid]))->assertOk();
        $this->get(route('user.home'))->assertOk();
        $this->assertNotSame(200, $this->get(route('admin.home'))->getStatusCode());
    }

    public function test_a_customer_cannot_open_another_tenants_business(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        [, $otherBusiness, $otherWorkspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->assertContains(
            $this->get(route('customer.workspaces.businesses.analytics.overview', [$otherWorkspace->uid, $otherBusiness->uid]))->getStatusCode(),
            [403, 404]
        );
    }

    public function test_guests_are_denied_the_admin_and_customer_areas(): void
    {
        $this->assertNotSame(200, $this->get(route('admin.home'))->getStatusCode());
        $this->assertNotSame(200, $this->get(route('user.home'))->getStatusCode());
    }

    public function test_the_activation_screen_and_its_routes_no_longer_exist(): void
    {
        $this->assertFalse(Route::has('verify.license'));
        $this->get('/verify-purchase-code')->assertNotFound();
        $this->post('/verify-purchase-code', ['purchase_code' => 'x', 'domain' => 'http://localhost'])->assertNotFound();
    }

    public function test_the_login_page_loads_without_a_licence_and_never_calls_the_vendor(): void
    {
        Http::fake();

        $this->get(route('login'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_signed_in_users_are_not_redirected_by_the_guest_middleware_to_an_activation_screen(): void
    {
        $this->actingAsPlatformOwner();

        $response = $this->get(route('login'));

        $this->assertStringNotContainsString('verify-purchase-code', (string) $response->headers->get('Location'));
    }

    public function test_authenticated_platform_owner_cannot_switch_accounts_by_posting_login_credentials(): void
    {
        $owner = $this->actingAsPlatformOwner();
        $other = User::create([
            'first_name' => 'Other',
            'last_name' => 'Administrator',
            'email' => 'other-admin-' . uniqid('', true) . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('AnotherSecurePassword123!'),
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        config()->set('no-captcha.login', false);

        $this->post(route('login'), [
            'email' => $other->email,
            'password' => 'AnotherSecurePassword123!',
        ])->assertRedirect(\App\Helpers\Helper::home_route());

        $this->assertSame($owner->id, \Illuminate\Support\Facades\Auth::id());
    }

    public function test_authenticated_customer_cannot_switch_accounts_by_posting_login_credentials(): void
    {
        [$customer] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $other = User::create([
            'first_name' => 'Other',
            'last_name' => 'Customer',
            'email' => 'other-customer-' . uniqid('', true) . '@example.test',
            'password' => \Illuminate\Support\Facades\Hash::make('AnotherSecurePassword123!'),
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);

        config()->set('no-captcha.login', false);

        $this->post(route('login'), [
            'email' => $other->email,
            'password' => 'AnotherSecurePassword123!',
        ])->assertRedirect(route('user.home'));

        $this->assertSame($customer->id, \Illuminate\Support\Facades\Auth::id());
    }

    public function test_the_runtime_source_has_no_vendor_licence_callback_or_gate_alias(): void
    {
        $hits = [];
        foreach ([app_path(), base_path('routes'), config_path(), resource_path('views')] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (! in_array($file->getExtension(), ['php'], true)) {
                    continue;
                }
                $contents = (string) file_get_contents($file->getPathname());
                foreach (['verify.license', 'verify-purchase-code', "'ValidProduct'", 'RedirectIfNotValid', 'licenseChecker', 'ultimatesms.codeglen.com/verify'] as $needle) {
                    if (str_contains($contents, $needle)) {
                        $hits[] = $file->getPathname() . ' => ' . $needle;
                    }
                }
            }
        }

        // The vendor's demo SMS-sending-server presets are a separate, documented item, not a licence callback.
        $this->assertSame([], $hits);
        $this->assertDirectoryDoesNotExist(base_path('packages/kashem'));
    }

    public function test_no_seeder_creates_an_account_with_a_literal_password_or_a_vendor_address(): void
    {
        $hits = [];
        foreach (glob(database_path('seeders/*.php')) as $path) {
            $contents = (string) file_get_contents($path);
            if (preg_match("/(Hash::make|bcrypt)\\(\\s*['\"]/", $contents) || str_contains($contents, '@codeglen.com')) {
                $hits[] = basename($path);
            }
        }

        $this->assertSame([], $hits);
        foreach (['SupportAdministratorsSeeder', 'SupportTicketSeeder', 'uSupportPluginSeeder'] as $gone) {
            $this->assertFileDoesNotExist(database_path("seeders/{$gone}.php"));
        }
    }

    public function test_seeding_the_role_and_config_seeders_creates_no_user(): void
    {
        $before = User::count();

        $this->artisan('db:seed', ['--class' => 'UserSeeder'])->assertSuccessful();

        $this->assertSame($before, User::count());
    }

    public function test_the_default_settings_carry_no_licence_rows_and_no_vendor_mailbox(): void
    {
        $defaults = (new AppConfig())->defaultSettings();
        $keys = array_column($defaults, 'setting');
        foreach (['license', 'license_type', 'valid_domain'] as $legacy) {
            $this->assertNotContains($legacy, $keys);
        }
        $this->assertStringNotContainsString('akasham67', json_encode($defaults));
    }
}
