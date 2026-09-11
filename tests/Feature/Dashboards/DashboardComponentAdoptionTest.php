<?php

namespace Tests\Feature\Dashboards;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * Design System M2 Slice 3 contract §8 item 3 — mechanically proves the
 * exact locked component adoptions (§5 item 2) are real, using each
 * component's own stable, distinguishing markup marker read directly from
 * its current source (resources/views/components/*.blade.php): card ->
 * `ds-card`; table -> `ds-table`; empty-state -> `ds-empty-state`; input
 * -> `ds-field` + `form-control` wrapper combined with the component's
 * own auto-generated `id`; button -> the component's own
 * `btn-primary`/`transition-fast` classes.
 *
 * B5 Business Analytics (2026-09, contract §14, §18.7) deleted the ghost
 * Hot Leads and AI Analytics surfaces (they ran on untracked chat_boxes
 * columns and an ai_box_campaign_map table with no migration), so their
 * card/table/select/badge/alert adoption cases and the ephemeral
 * `security_test_ddl` schema fixture that fabricated that schema are gone
 * from this file. The surviving cases are the canonical admin settings AI
 * section, the customer dashboard, and the admin dashboard.
 */
class DashboardComponentAdoptionTest extends TestCase
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

    /**
     * B3 Simplified Platform Settings (2026-09) retargeted this from the
     * orphan `/admin/ai-brain` surface (deleted outright) to the one
     * canonical platform AI provider configuration surface, admin/
     * settings' own AI section. Assertions unchanged in substance: the
     * AI section's model field is a real x-input (ds-field wrapper,
     * id="model") and its save action is a real x-button (btn-primary).
     */
    public function test_ai_settings_input_and_button_adoption_are_real(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend', 'general settings', 'manage ai_settings']);

        $response = $this->get('/admin/settings');

        $response->assertOk();
        $response->assertSee('ds-field', false);
        $response->assertSee('id="model"', false);
        $response->assertSee('btn-primary', false);
    }

    /**
     * Customer Experience Slice 4 §12 — the dashboard's empty state is the
     * richer layouts.partials.empty-state (the `ds-empty-state` marker),
     * rendered for the zero-Business account. The former Opportunity panel,
     * whose own empty card this test once pinned, is gone: an Advisor band
     * with nothing to recommend is now absent, not empty (§6).
     */
    public function test_customer_dashboard_empty_state_adoption_is_real(): void
    {
        config(['opportunity.enabled' => true]);

        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('ds-empty-state', false);
        $response->assertSee('data-role="empty-state" data-state="empty"', false);
    }

    /**
     * Several Businesses and none chosen: the Account-frame chooser, never a
     * guessed Business's recommendations (the sole-Business panel and its
     * primary-Business guess were deleted by Slice 4 §3).
     */
    public function test_customer_dashboard_shows_no_recommendations_with_several_accessible_businesses_and_none_chosen(): void
    {
        config(['opportunity.enabled' => true]);

        $this->ensureRequiredAppConfigRowsExist();
        $customer = $this->createCustomer();
        foreach ([$this->businessAttributes(), $this->businessAttributes(['name' => 'Second Venue'])] as $attributes) {
            $business = $this->createBusinessWithWorkspace($customer, $attributes);
            \Illuminate\Support\Facades\DB::table('businesses')->where('id', $business->id)->update(['status' => \App\Enums\Business\BusinessStatus::Active->value]);
        }
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(['access_backend'])]);
        $this->actingAs($customer->user);

        $response = $this->get(route('user.home'));

        $response->assertOk();
        $response->assertSee('data-kind="chooser"', false);
        $response->assertDontSee('data-band="recommendations"', false);
        $response->assertDontSee('View all opportunities');
    }

    public function test_admin_dashboard_table_adoption_is_real(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $this->actingAsAdmin(['access backend']);

        $response = $this->get(route('admin.home'));

        $response->assertOk();
        $response->assertSee('ds-table', false);
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
