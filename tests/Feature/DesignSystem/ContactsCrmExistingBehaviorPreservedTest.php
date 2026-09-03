<?php

namespace Tests\Feature\DesignSystem;

use App\Models\AppConfig;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Design System M2 Slice 5 (Contacts & CRM) — behavior-preservation matrix
 * (§8/§13 of the contract). Proves the restyle left every DOM/JS/plugin
 * contract, route, request-parameter name, and id untouched, and that the
 * merged CRM security remediation's escaping/encoding behavior survives
 * the presentation pass unchanged. This file makes zero assertion that
 * would require an app/database/routes change to satisfy.
 */
class ContactsCrmExistingBehaviorPreservedTest extends TestCase
{
    use RefreshDatabase;

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

    // -----------------------------------------------------------------
    // Form actions, methods, CSRF (source-level)
    // -----------------------------------------------------------------

    public function test_contact_create_form_keeps_its_route_method_and_csrf_token(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/Contacts/create.blade.php'));

        $this->assertStringContainsString("route('customer.contact.store', \$contact->uid)", $contents);
        $this->assertStringContainsString('method="post"', $contents);
        $this->assertStringContainsString('@csrf', $contents);
    }

    public function test_public_subscribe_form_keeps_its_route_method_and_csrf_token(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/Contacts/subscribe_form.blade.php'));

        $this->assertStringContainsString("route('contacts.subscribe_url', \$contact->uid)", $contents);
        $this->assertStringContainsString('method="POST"', $contents);
        $this->assertStringContainsString('@csrf', $contents);
    }

    public function test_public_unsubscribe_form_keeps_its_route_method_and_csrf_token(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/Contacts/unsubscribe_form.blade.php'));

        $this->assertStringContainsString("route('contacts.unsubscribe_url', \$contact->uid)", $contents);
        $this->assertStringContainsString('method="POST"', $contents);
        $this->assertStringContainsString('@csrf', $contents);
    }

    public function test_export_modal_form_keeps_its_route_method_and_csrf_token(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_contacts.blade.php'));

        $this->assertStringContainsString("route('customer.contact.export', \$contact->uid)", $contents);
        $this->assertStringContainsString('method="post"', $contents);
        $this->assertStringContainsString('@csrf', $contents);
    }

    // -----------------------------------------------------------------
    // Request parameter names survive component adoption
    // -----------------------------------------------------------------

    public function test_adopted_inputs_and_selects_keep_their_original_request_parameter_names(): void
    {
        $expected = [
            'resources/views/customer/Blacklists/create.blade.php' => ['name="reason"'],
            'resources/views/admin/Blacklists/create.blade.php' => ['name="reason"'],
            'resources/views/customer/opportunities/show.blade.php' => ['name="value"', 'name="duration"'],
            'resources/views/admin/opportunities/index.blade.php' => ['name="status"', 'name="business_id"', 'name="worker_key"'],
            'resources/views/admin/opportunities/show.blade.php' => ['name="duration"'],
        ];

        foreach ($expected as $view => $names) {
            $contents = file_get_contents(base_path($view));
            foreach ($names as $name) {
                $this->assertStringContainsString($name, $contents, "{$view} must still carry {$name}.");
            }
        }
    }

    public function test_export_modal_keeps_its_original_field_names(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_contacts.blade.php'));

        $this->assertStringContainsString('name="contact_fields[]"', $contents);
        $this->assertStringContainsString('name="include_phone"', $contents);
    }

    // -----------------------------------------------------------------
    // IDs and data-* hooks survive
    // -----------------------------------------------------------------

    public function test_export_modal_keeps_its_original_ids(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_contacts.blade.php'));

        foreach (['id="exportContactModal"', 'id="select-all-btn"', 'id="closeExportContact"', 'id="finalExportContact"', 'data-tippy-id="customer.contacts.show.force_export_phone_number"'] as $hook) {
            $this->assertStringContainsString($hook, $contents, "Expected {$hook} to survive.");
        }
    }

    public function test_fields_partial_keeps_its_dynamic_data_hooks(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_fields.blade.php'));

        $this->assertStringContainsString('data-remove-id="{{ $item->uid }}"', $contents);
        $this->assertStringContainsString("data-field-id='{{ \$item->uid }}'", $contents);
        $this->assertStringContainsString('sample-url="', $contents);
    }

    public function test_export_modal_still_opens_via_its_original_javascript_call(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/show.blade.php'));

        $this->assertStringContainsString('$(\'#exportContactModal\').modal("show");', $contents);
    }

    // -----------------------------------------------------------------
    // Plugin wiring (DataTables / Select2 / Flatpickr / SweetAlert2)
    // -----------------------------------------------------------------

    public function test_datatables_ajax_endpoints_are_unchanged_across_the_three_datatable_shells(): void
    {
        $expected = [
            'resources/views/customer/contactGroups/index.blade.php' => 'customer.contacts.search',
            'resources/views/customer/Blacklists/index.blade.php' => 'customer.blacklists.search',
            'resources/views/admin/Blacklists/index.blade.php' => 'admin.blacklists.search',
        ];

        foreach ($expected as $view => $routeName) {
            $contents = file_get_contents(base_path($view));
            $this->assertStringContainsString("route('{$routeName}')", $contents, "{$view} must keep its DataTables ajax.url route.");
            $this->assertStringContainsString('"ajax"', $contents);
        }
    }

    public function test_select2_and_flatpickr_plugin_hooks_remain_wired(): void
    {
        $select2 = file_get_contents(base_path('resources/views/customer/contactGroups/_message.blade.php'));
        $this->assertStringContainsString('select2', $select2);

        $flatpickr = file_get_contents(base_path('resources/views/customer/Contacts/create.blade.php'));
        $this->assertStringContainsString('flatpickr', $flatpickr);

        $subscribeFlatpickr = file_get_contents(base_path('resources/views/customer/Contacts/subscribe_form.blade.php'));
        $this->assertStringContainsString('.flatpickr(', $subscribeFlatpickr);
        $this->assertStringContainsString('.select2({', $subscribeFlatpickr);
    }

    public function test_sweetalert2_calls_remain_wired_to_their_original_routes(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/show.blade.php'));

        $this->assertGreaterThanOrEqual(10, substr_count($contents, 'Swal.fire('));
        $this->assertStringContainsString('customClass', $contents);
    }

    // -----------------------------------------------------------------
    // Permission gating directives remain present
    // -----------------------------------------------------------------

    public function test_permission_directives_remain_present_across_the_gated_views(): void
    {
        foreach ([
            'resources/views/customer/contactGroups/index.blade.php',
            'resources/views/customer/contactGroups/_contacts.blade.php',
            'resources/views/customer/Blacklists/index.blade.php',
            'resources/views/admin/Blacklists/index.blade.php',
        ] as $view) {
            $contents = file_get_contents(base_path($view));
            $hasCan = str_contains($contents, '@can(') || str_contains($contents, '@canany(');
            $this->assertTrue($hasCan, "Expected {$view} to still contain a @can/@canany permission gate.");
        }
    }

    // -----------------------------------------------------------------
    // Public subscribe/unsubscribe behavior (live render)
    // -----------------------------------------------------------------

    public function test_public_subscribe_form_renders_for_a_real_contact_group_with_its_form_intact(): void
    {
        $group = $this->createContactWithGroup();

        $response = $this->get(route('contacts.subscribe_url', $group->uid));

        $response->assertOk();
        $response->assertSee('action="' . route('contacts.subscribe_url', $group->uid) . '"', false);
        $response->assertSee('name="_token"', false);
    }

    public function test_public_unsubscribe_form_renders_for_a_real_contact_group_with_its_form_intact(): void
    {
        $group = $this->createContactWithGroup();

        $response = $this->get(route('contacts.unsubscribe_url', $group->uid));

        $response->assertOk();
        $response->assertSee('action="' . route('contacts.unsubscribe_url', $group->uid) . '"', false);
    }

    // -----------------------------------------------------------------
    // Admin Blacklists global visibility wiring (view layer)
    // -----------------------------------------------------------------

    public function test_admin_blacklists_index_still_targets_the_unscoped_global_search_endpoint(): void
    {
        $admin = $this->actingAsAdmin(['access backend', 'view blacklist']);

        $response = $this->get(route('admin.blacklists.index'));

        $response->assertOk();
        $response->assertSee(route('admin.blacklists.search'), false);
    }

    // -----------------------------------------------------------------
    // Merged security escaping/encoding behavior survives the restyle
    // -----------------------------------------------------------------

    public function test_contact_group_show_still_json_encodes_group_names_via_js_from_and_never_leaks_raw_markup(): void
    {
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
        $this->ensureRequiredAppConfigRowsExist();

        $customer = Customer::create(['user_id' => $user->id]);
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        $group = ContactGroups::create([
            'customer_id' => $user->id,
            'name' => 'Design System Group',
            'status' => true,
        ]);

        ContactGroups::create([
            'customer_id' => $user->id,
            'name' => '<script>xssMarker123</script>"\'',
            'status' => true,
        ]);

        $this->actingAs($user);

        $response = $this->get(route('customer.contacts.show', $group->uid));

        $response->assertOk();
        $response->assertDontSee('<script>xssMarker123</script>', false);
    }

    public function test_admin_blacklists_search_still_html_entity_encodes_display_names_in_its_controller_source(): void
    {
        $contents = file_get_contents(base_path('app/Http/Controllers/Admin/BlacklistsController.php'));

        $this->assertStringContainsString('e($blacklist->user->displayName())', $contents);
        $this->assertStringContainsString("e(\$customer_profile)", $contents);
        $this->assertStringContainsString("e(\$customer_name)", $contents);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function createContactWithGroup(): ContactGroups
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
        ]);

        Customer::create(['user_id' => $user->id]);
        $this->giveUnlimitedActiveSubscription($user);

        $group = ContactGroups::create([
            'customer_id' => $user->id,
            'name' => 'Design System Group',
            'status' => true,
        ]);

        Contacts::create([
            'customer_id' => $user->id,
            'group_id' => $group->id,
            'phone' => '12025550199',
            'status' => 'subscribe',
        ]);

        return $group;
    }

    /**
     * subscribeURL()'s own pre-existing (unrelated to this Design System
     * pass) code reads $user->customer->activeSubscription()->plan_id,
     * which requires an active Plan/Subscription to avoid a null-property
     * read. Mirrors ContactGroupsSecurityTest::giveUnlimitedActiveSubscription().
     */
    private function giveUnlimitedActiveSubscription(User $user): void
    {
        $currency = Currency::query()->where('code', 'CGT')->first()
            ?? Currency::create(['name' => 'CRM Test Dollar', 'code' => 'CGT', 'format' => '$', 'status' => true]);

        $plan = Plan::create([
            'user_id' => $user->id,
            'name' => 'CRM Test Plan',
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'currency_id' => $currency->id,
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
