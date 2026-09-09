<?php

namespace Tests\Feature\Dashboards;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 Slice 3 contract §8 item 1 — proves the actor genuinely
 * authorized on this Slice 3 implementation's own pinned post-remediation
 * baseline (dashboard-security remediation merge
 * 1059112d343f7cf3029e5d13ca8db065f98cdfd0) receives HTTP 200 from the
 * in-scope routes. user.home/admin.home are unchanged by the remediation
 * (§17 of that contract), reached by an ordinary customer/admin
 * respectively; the platform AI settings surface requires an
 * authenticated admin holding `manage ai_settings` (and `general
 * settings`, since B3 folded it into the one Settings page).
 *
 * B3 Simplified Platform Settings (2026-09) retargeted the AI settings
 * route from the orphan `/admin/ai-brain` surface (deleted outright) to
 * the canonical admin/settings AI section — see
 * test_ai_settings_returns_200_for_authorized_admin() below.
 *
 * B5 Business Analytics (2026-09, contract §14, §18.7) deleted the ghost
 * Hot Leads and AI Analytics surfaces — they ran on chat_boxes columns and
 * an ai_box_campaign_map table with no tracked migration — so their two
 * render cases and the ephemeral `security_test_ddl` schema fixture that
 * fabricated that schema are gone from this file. The customer dashboard
 * no longer renders the `#sms-reports` pie; it links to the Business-
 * scoped Analytics surface instead.
 *
 * Security Remediation Slice 0 §16.A.2 (D-19) extends, rather than
 * replaces, test_customer_home_returns_200_and_links_to_business_analytics()
 * below with one light assertion: the invoice figure UserController::index()
 * now pre-computes (`$unpaidAndPendingInvoiceCount` / `$totalInvoiceCount`)
 * still renders for an ordinary actor with zero invoices. The multi-tenant
 * cross-leak proof for this same fix lives in
 * tests/Feature/Security/DashboardInvoiceScopeTest.php, which this test does
 * not duplicate.
 */
class DashboardRenderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        // Consume users.id === 1 (repository-wide inherited super-admin Gate
        // bypass, EloquentAccountRepository::hasPermission()) so this file's
        // own actors are never accidentally granted every permission by
        // landing on the first auto-increment id.
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

    public function test_customer_home_returns_200_and_links_to_business_analytics(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend', 'view_reports'])]);
        $this->actingAs($customer->user);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee(route('customer.analytics.entry'), false);
        $response->assertDontSee('id="sms-reports"', false);
        $response->assertDontSee('apexcharts', false);

        // D-19: the pre-computed, actor-scoped invoice figures render fine
        // for a customer with no invoices at all.
        $response->assertSee('<sup>0</sup>', false);
        $response->assertSee('/ 0</h2>', false);
    }

    public function test_admin_home_returns_200(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $response = $this->get(route('admin.home'));

        $response->assertOk();
    }

    /**
     * B3 Simplified Platform Settings (2026-09) retargeted this from the
     * orphan `/admin/ai-brain` surface (deleted outright -- an
     * ai_settings table with no migration anywhere in this repository,
     * and a system_prompt field with zero production consumers) to the
     * one canonical platform AI provider configuration surface, admin/
     * settings' own AI section (config('services.openai.*')).
     */
    public function test_ai_settings_returns_200_for_authorized_admin(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'manage ai_settings']);

        $response = $this->get('/admin/settings');

        $response->assertOk();
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        // B3 Simplified Platform Settings: test_ai_settings_returns_200_
        // for_authorized_admin() now renders admin.settings.platform.
        // index, which additionally reads the company_address/
        // php_bin_path app_config rows -- seeded here the same way every
        // other settings-adjacent test in this repository seeds exactly
        // the rows its own render path touches.
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script', 'company_address', 'php_bin_path'])
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

        if (! in_array('company_address', $existing, true)) {
            AppConfig::create(['setting' => 'company_address', 'value' => 'Test Address']);
        }

        if (! in_array('php_bin_path', $existing, true)) {
            AppConfig::create(['setting' => 'php_bin_path', 'value' => '/usr/bin/php']);
        }
    }
}
