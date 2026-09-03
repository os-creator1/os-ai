<?php

namespace Tests\Feature\DesignSystem;

use App\Models\AppConfig;
use App\Models\ContactGroups;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Opportunity\Concerns\CreatesOpportunityTestData;
use Tests\TestCase;

/**
 * Design System M2 Slice 5 (Contacts & CRM) — component-adoption assertions.
 * Asserts ONLY the exact contracted adoption set (§5 of the contract):
 * 24 <x-card>, 38 <x-button>, 11 <x-input>/<x-select> (7 select + 4 input),
 * exactly 1 <x-table>, 4 <x-alert>, 1 <x-empty-state>, 1 <x-tooltip>, and
 * zero <x-dialog>/<x-pagination>/<x-badge> for the running-state pill —
 * never a generic "every card/button adopts" assumption.
 */
class ContactsCrmComponentAdoptionTest extends TestCase
{
    use RefreshDatabase;
    use CreatesOpportunityTestData;

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

    public function test_exactly_24_card_markers_are_present(): void
    {
        $this->assertMarkerTotal('<x-card', 24);
    }

    public function test_exactly_38_button_markers_are_present(): void
    {
        $this->assertMarkerTotal('<x-button', 38);
    }

    public function test_exactly_7_select_and_4_input_markers_are_present(): void
    {
        $this->assertMarkerTotal('<x-select', 7);
        $this->assertMarkerTotal('<x-input', 4);
    }

    public function test_exactly_1_table_marker_is_present_and_only_on_customer_opportunities_index(): void
    {
        $this->assertMarkerTotal('<x-table', 1);

        $contents = file_get_contents(base_path('resources/views/customer/opportunities/index.blade.php'));
        $this->assertStringContainsString('<x-table', $contents);
    }

    public function test_exactly_4_alert_markers_are_present_across_the_two_opportunity_show_pages(): void
    {
        $this->assertMarkerTotal('<x-alert', 4);

        foreach ([
            'resources/views/customer/opportunities/show.blade.php',
            'resources/views/admin/opportunities/show.blade.php',
        ] as $view) {
            $contents = file_get_contents(base_path($view));
            $this->assertSame(2, substr_count($contents, '<x-alert'), $view);
        }
    }

    public function test_exactly_1_empty_state_marker_is_present_on_customer_opportunities_index_only(): void
    {
        $this->assertMarkerTotal('<x-empty-state', 1);

        $contents = file_get_contents(base_path('resources/views/customer/opportunities/index.blade.php'));
        $this->assertStringContainsString('<x-empty-state', $contents);

        // Never both x-alert and x-empty-state on the same empty-result node.
        $this->assertStringNotContainsString('<x-alert', $contents);
    }

    public function test_exactly_1_tooltip_marker_is_present_on_contacts_partial(): void
    {
        $this->assertMarkerTotal('<x-tooltip', 1);

        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_contacts.blade.php'));
        $this->assertStringContainsString('<x-tooltip', $contents);
    }

    // -----------------------------------------------------------------
    // Explicit non-adoption assertions — never demand what the contract
    // locks as native.
    // -----------------------------------------------------------------

    public function test_dialog_is_never_adopted_for_the_export_modal(): void
    {
        $this->assertMarkerTotal('<x-dialog', 0);
    }

    public function test_pagination_component_is_never_adopted(): void
    {
        $this->assertMarkerTotal('<x-pagination', 0);
    }

    public function test_badge_is_never_adopted_for_the_import_history_running_state(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_import_history.blade.php'));
        $this->assertStringNotContainsString('<x-badge', $contents);
    }

    public function test_button_is_never_adopted_for_the_15_label_based_radio_controls(): void
    {
        foreach ([
            'resources/views/customer/Contacts/paste_text.blade.php',
            'resources/views/customer/Blacklists/create.blade.php',
            'resources/views/admin/Blacklists/create.blade.php',
        ] as $view) {
            $contents = file_get_contents(base_path($view));
            $this->assertSame(5, substr_count($contents, '<label class="btn btn-outline-primary"'), $view);
        }
    }

    public function test_table_is_never_adopted_for_any_datatables_shell_or_table_primary_table(): void
    {
        foreach ([
            'resources/views/customer/contactGroups/index.blade.php',
            'resources/views/customer/contactGroups/show.blade.php',
            'resources/views/customer/Blacklists/index.blade.php',
            'resources/views/admin/Blacklists/index.blade.php',
            'resources/views/admin/opportunities/index.blade.php',
            'resources/views/admin/opportunities/show.blade.php',
            'resources/views/admin/opportunities/runs/index.blade.php',
        ] as $view) {
            $contents = file_get_contents(base_path($view));
            $this->assertStringNotContainsString('<x-table', $contents, $view);
        }
    }

    public function test_form_fields_partial_has_no_card_to_adopt(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_form_fields.blade.php'));
        $this->assertStringNotContainsString('<x-card', $contents);
    }

    public function test_contacts_partial_adopts_exactly_one_card_for_its_table_only(): void
    {
        $contents = file_get_contents(base_path('resources/views/customer/contactGroups/_contacts.blade.php'));
        $this->assertSame(1, substr_count($contents, '<x-card'));

        // The 3 KPI stat cards remain native.
        $this->assertSame(3, substr_count($contents, 'class="card"'));
    }

    public function test_business_id_is_adopted_through_x_input_not_a_bare_native_input(): void
    {
        $contents = file_get_contents(base_path('resources/views/admin/opportunities/index.blade.php'));

        $this->assertMatchesRegularExpression('/<x-input[^>]*name="business_id"/s', $contents);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]*name="business_id"/', $contents);
    }

    // -----------------------------------------------------------------
    // Live render proof — adopted markers actually resolve to the
    // component's own real, documented output classes at runtime.
    // -----------------------------------------------------------------

    public function test_contact_group_create_renders_the_adopted_card_and_button_output_classes(): void
    {
        [, $group] = $this->authenticatedCustomerWithGroup();

        $response = $this->get(route('customer.contact.create', $group->uid));

        $response->assertOk();
        $response->assertSee('ds-card', false);
        $response->assertSee('transition-fast', false);
    }

    public function test_customer_blacklists_create_renders_the_adopted_input_output_class(): void
    {
        [$customer] = $this->authenticatedCustomerWithPermissions(['view_blacklist', 'create_blacklist']);

        $response = $this->get(route('customer.blacklists.create'));

        $response->assertOk();
        $response->assertSee('ds-field', false);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function assertMarkerTotal(string $marker, int $expected): void
    {
        $total = 0;
        foreach (self::SLICE5_VIEWS as $view) {
            $contents = file_get_contents(base_path($view));
            $total += substr_count($contents, $marker);
        }

        $this->assertSame($expected, $total, "Expected exactly {$expected} occurrences of {$marker} across the 29-view allowlist.");
    }

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
     * @return array{0: Customer}
     */
    private function authenticatedCustomerWithPermissions(array $permissions): array
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
        $customer->permissions = array_values(array_unique(array_merge(
            ['access_backend'],
            $permissions
        )));
        $customer->save();

        $this->actingAs($user);

        return [$customer];
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
