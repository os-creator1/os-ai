<?php

namespace Tests\Feature\DesignSystem;

use App\Helpers\Helper;
use App\Models\AppConfig;
use App\Models\ContactGroups;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * Design System M2 Slice 5 (Contacts & CRM) — mechanical content checks
 * across the exact 29-view allowlist (§9 of the contract). Proves the icon
 * migration (79 static data-feather occurrences, 30 distinct names,
 * migrated to <x-ds-icon>), the untouched runtime feather.icons[...] calls
 * (11, across the 4 named files), the absence of hardcoded color/font
 * literals, and that native-retained surfaces (DataTables shells, the
 * export modal, the _import_history status badge, the three
 * table-primary-headed history tables) remain structurally present. This
 * file makes zero assertion requiring any ContactsController change.
 */
class ContactsCrmDesignSystemContentTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

    /**
     * @var string[]
     */
    private const SLICE5_VIEWS = [
        'resources/views/customer/Contacts/create.blade.php',
        'resources/views/customer/Contacts/import.blade.php',
        'resources/views/customer/Contacts/import/mapping.blade.php',
        'resources/views/customer/Contacts/import_file.blade.php',
        'resources/views/customer/Contacts/paste_text.blade.php',
        'resources/views/customer/Contacts/show.blade.php',
        'resources/views/customer/Contacts/subscribe_form.blade.php',
        'resources/views/customer/Contacts/unsubscribe_form.blade.php',
        'resources/views/customer/contactGroups/_contacts.blade.php',
        'resources/views/customer/contactGroups/_fields.blade.php',
        'resources/views/customer/contactGroups/_form_fields.blade.php',
        'resources/views/customer/contactGroups/_import_history.blade.php',
        'resources/views/customer/contactGroups/_message.blade.php',
        'resources/views/customer/contactGroups/_opt_in_keywords.blade.php',
        'resources/views/customer/contactGroups/_opt_out_keywords.blade.php',
        'resources/views/customer/contactGroups/_settings.blade.php',
        'resources/views/customer/contactGroups/create.blade.php',
        'resources/views/customer/contactGroups/index.blade.php',
        'resources/views/customer/contactGroups/show.blade.php',
        'resources/views/customer/Blacklists/create.blade.php',
        'resources/views/customer/Blacklists/index.blade.php',
        'resources/views/admin/Blacklists/create.blade.php',
        'resources/views/admin/Blacklists/index.blade.php',
        'resources/views/customer/opportunities/index.blade.php',
        'resources/views/customer/opportunities/show.blade.php',
        'resources/views/admin/opportunities/index.blade.php',
        'resources/views/admin/opportunities/show.blade.php',
        'resources/views/admin/opportunities/runs/index.blade.php',
        'resources/views/admin/opportunities/runs/show.blade.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->setOpportunityEngineEnabled(true);

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

    // -----------------------------------------------------------------
    // Source-level mechanical checks — contract §14
    // -----------------------------------------------------------------

    public function test_zero_static_data_feather_remains_across_all_29_views(): void
    {
        foreach (self::SLICE5_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(
                0,
                substr_count($contents, 'data-feather'),
                "Expected zero static data-feather occurrences in {$view}."
            );
        }
    }

    public function test_runtime_feather_icons_calls_remain_exactly_11_across_the_4_named_files(): void
    {
        $expected = [
            'resources/views/customer/contactGroups/index.blade.php' => 5,
            'resources/views/customer/contactGroups/show.blade.php' => 4,
            'resources/views/customer/Blacklists/index.blade.php' => 1,
            'resources/views/admin/Blacklists/index.blade.php' => 1,
        ];

        $total = 0;
        foreach ($expected as $view => $count) {
            $contents = file_get_contents(base_path($view));
            $actual = substr_count($contents, "feather.icons[");
            $this->assertSame($count, $actual, "Expected {$count} feather.icons[...] calls in {$view}, found {$actual}.");
            $total += $actual;
        }

        $this->assertSame(11, $total);
    }

    public function test_no_hardcoded_color_or_font_family_literals_introduced(): void
    {
        foreach (self::SLICE5_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));

            $this->assertSame(
                0,
                preg_match('/#[0-9A-Fa-f]{3,8}\b/', $contents),
                "Expected zero hex color literals in {$view}."
            );
            $this->assertSame(
                0,
                preg_match('/font-family\s*:/', $contents),
                "Expected zero font-family declarations in {$view}."
            );
        }
    }

    public function test_contact_group_show_inline_script_was_not_extracted(): void
    {
        $this->assertFileDoesNotExist(base_path('resources/js/scripts/pages/contact-group-show.js'));

        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/show.blade.php'));
        $this->assertStringContainsString('@section(\'page-script\')', $contents);
    }

    public function test_contact_group_show_has_exactly_8_live_partial_includes_and_segments_remains_commented(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/show.blade.php'));

        $this->assertSame(8, substr_count($contents, '@include('));

        foreach (['_contacts', '_settings', '_message', '_segments', '_fields', '_opt_in_keywords', '_opt_out_keywords', '_import_history'] as $partial) {
            $this->assertStringContainsString("@include('customer.contactGroups.{$partial}')", $contents);
        }

        // The nav-tab link to the segments pane stays commented out.
        $this->assertStringContainsString('{{--                        <li class="nav-item">--}}', $contents);
    }

    public function test_all_three_native_paginator_links_calls_remain(): void
    {
        foreach ([
            'resources/views/customer/opportunities/index.blade.php',
            'resources/views/admin/opportunities/index.blade.php',
            'resources/views/admin/opportunities/runs/index.blade.php',
        ] as $view) {
            $contents = file_get_contents(base_path($view));
            $this->assertStringContainsString('->links()', $contents);
        }
    }

    public function test_import_history_status_badge_expression_remains_native_with_bg_info(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_import_history.blade.php'));

        $this->assertStringContainsString('bg-info', $contents);
        $this->assertStringNotContainsString('<x-badge', $contents);
    }

    public function test_export_modal_remains_native_bootstrap_markup(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_contacts.blade.php'));

        $this->assertStringContainsString('id="exportContactModal"', $contents);
        $this->assertStringContainsString('modal-dialog modal-lg', $contents);
        $this->assertStringNotContainsString('<x-dialog', $contents);
    }

    public function test_the_two_datatables_shell_and_table_primary_tables_stay_native(): void
    {
        $nativeTableViews = [
            'resources/views/customer/contactGroups/index.blade.php',
            'resources/views/customer/contactGroups/show.blade.php',
            'resources/views/customer/Blacklists/index.blade.php',
            'resources/views/admin/Blacklists/index.blade.php',
            'resources/views/admin/opportunities/index.blade.php',
            'resources/views/admin/opportunities/show.blade.php',
            'resources/views/admin/opportunities/runs/index.blade.php',
        ];

        foreach ($nativeTableViews as $view) {
            $contents = file_get_contents(base_path($view));
            $this->assertStringNotContainsString('<x-table', $contents, "Expected {$view} to keep its table native.");
        }
    }

    public function test_the_15_label_based_radio_controls_are_retained_across_the_3_named_files(): void
    {
        $expected = [
            'resources/views/customer/Contacts/paste_text.blade.php' => 5,
            'resources/views/customer/Blacklists/create.blade.php' => 5,
            'resources/views/admin/Blacklists/create.blade.php' => 5,
        ];

        foreach ($expected as $view => $count) {
            $contents = file_get_contents(base_path($view));
            $this->assertSame($count, substr_count($contents, '<label class="btn btn-outline-primary"'), $view);
        }
    }

    // -----------------------------------------------------------------
    // Live render smoke checks — icons actually resolve at runtime
    // -----------------------------------------------------------------

    public function test_contact_group_show_renders_migrated_icons_and_preserves_feather_runtime(): void
    {
        [$customer, $group] = $this->authenticatedCustomerWithGroup();

        $response = $this->get(route('customer.contacts.show', $group->uid));

        $response->assertOk();
        $response->assertSee('ds-icon', false);
        // Feather runtime script + the untouched runtime toSvg() calls survive.
        $response->assertSee('feather.icons[', false);
        $response->assertDontSee('data-feather=', false);
    }

    public function test_customer_blacklists_index_renders_without_data_feather_and_keeps_datatables_shell(): void
    {
        [$customer] = $this->authenticatedCustomerWithGroup();

        $response = $this->get(route('customer.blacklists.index'));

        $response->assertOk();
        $response->assertSee('datatables-basic', false);
        $response->assertDontSee('data-feather=', false);
    }

    public function test_admin_blacklists_index_renders_without_data_feather(): void
    {
        $this->actingAsAdmin(['access backend', 'view blacklist']);

        $response = $this->get(route('admin.blacklists.index'));

        $response->assertOk();
        $response->assertDontSee('data-feather=', false);
    }

    public function test_customer_opportunities_index_renders_the_adopted_table_and_empty_state(): void
    {
        $business = $this->actingAsCustomerWithBusiness();
        $this->createOpportunity($business, ['title' => 'Design System Content Check']);

        $response = $this->get(route('customer.opportunities.index'));

        $response->assertOk();
        $response->assertSee('ds-table', false);
    }

    public function test_customer_opportunities_index_renders_the_adopted_empty_state_when_no_rows_exist(): void
    {
        $this->actingAsCustomerWithBusiness();

        $response = $this->get(route('customer.opportunities.index'));

        $response->assertOk();
        $response->assertSee('ds-empty-state', false);
    }

    public function test_admin_opportunities_index_keeps_its_table_primary_thead_native(): void
    {
        $this->actingAsAdmin(['access backend', 'view opportunities']);

        $response = $this->get(route('admin.opportunities.index'));

        $response->assertOk();
        $response->assertSee('table-primary', false);
        $response->assertDontSee('ds-table', false);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: ContactGroups}
     */
    private function authenticatedCustomerWithGroup(): array
    {
        $this->ensureRequiredAppConfigRowsExist();

        $user = User::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'customer' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
            'email_verified_at' => now(),
        ]);

        $customer = Customer::create(['user_id' => $user->id]);
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $group = ContactGroups::create([
            'customer_id' => $user->id,
            'name' => 'Design System Group',
            'status' => true,
        ]);

        $this->actingAs($user);

        return [$customer, $group];
    }

    /**
     * MenuServiceProvider::boot() builds and View::share()s 'menuData' once,
     * at application boot — before this test ever changes
     * config('opportunity.enabled'). Changing the config alone leaves the
     * already-shared menu stale, causing an unrelated null-property error
     * in Helper::menuData() consumers. Re-running the exact same source
     * (Helper::menuData()) and re-sharing it, mirroring
     * OpportunityQueueHttpTest.php's own identical helper, keeps every
     * opportunity view rendering correctly under this flag.
     */
    private function setOpportunityEngineEnabled(bool $enabled): void
    {
        config()->set('opportunity.enabled', $enabled);

        $menuData = json_decode(json_encode(Helper::menuData()));

        View::share('menuData', [$menuData, $menuData]);
    }

    private function actingAsAdmin(array $permissions): User
    {
        $this->ensureRequiredAppConfigRowsExist();

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

    private function actingAsCustomerWithBusiness(): \App\Models\Business
    {
        $this->ensureRequiredAppConfigRowsExist();

        $business = $this->createBusinessForOpportunities();
        $customer = $business->customer;
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->actingAs($customer->user);

        return $business;
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
