<?php

namespace Tests\Feature\Business;

use App\Models\AppConfig;
use App\Models\Business;
use App\Models\ContactGroups;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * B1 Pass 2 — Business-addressable customer CRM (Contacts / Contact
 * Groups). Covers the "minimum coherent customer cutover": Business
 * isolation (a Business cannot see or mutate another Business's groups,
 * even when both share the same underlying customer), create/import-style
 * writes landing in the selected Business, cross-Business record ids
 * failing closed exactly like a nonexistent id, and that the legacy
 * customer-scoped Contacts routes keep working unchanged for callers that
 * never resolve a Business (proven separately by ContactsSecurityTest and
 * ContactGroupsSecurityTest, both still green, unmodified).
 */
class BusinessScopedCrmTest extends TestCase
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

    // -----------------------------------------------------------------
    // Isolation — a Business cannot see another Business's contact
    // groups, even when the SAME customer owns both Businesses.
    // -----------------------------------------------------------------

    public function test_business_scoped_show_404s_for_a_group_belonging_to_a_different_business(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        $foreignGroup = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $businessB->id, 'name' => 'B Group', 'status' => true]);

        $this->authenticateAsCustomer($tenant, ['view_contact_group']);

        $this->get(route('customer.workspaces.businesses.contacts.show', [$businessA->workspace->uid, $businessA->uid, $foreignGroup->uid]))
            ->assertStatus(404);
    }

    public function test_business_scoped_show_404s_for_a_nonexistent_group_identically(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_contact_group']);

        $this->get(route('customer.workspaces.businesses.contacts.show', [$business->workspace->uid, $business->uid, 'no-such-uid']))
            ->assertStatus(404);
    }

    public function test_business_a_cannot_move_or_copy_contacts_into_business_bs_group(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        $groupA = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $businessA->id, 'name' => 'A Group', 'status' => true]);
        $groupB = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $businessB->id, 'name' => 'B Group', 'status' => true]);
        $contact = Contacts::create(['customer_id' => $tenant->user_id, 'business_id' => $businessA->id, 'group_id' => $groupA->id, 'phone' => '14155550001', 'status' => 'subscribe']);

        $this->authenticateAsCustomer($tenant, ['update_contact']);

        $response = $this->postJson(route('customer.workspaces.businesses.contact.batch_action', [$businessA->workspace->uid, $businessA->uid, $groupA->uid]), [
            'action' => 'move',
            'ids' => [$contact->uid],
            'target_group' => $groupB->uid,
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'error']);
        $this->assertDatabaseHas('contacts', ['id' => $contact->id, 'group_id' => $groupA->id]);
    }

    // -----------------------------------------------------------------
    // Writes land in the explicit selected Business, never guessed via
    // LegacyBusinessResolver.
    // -----------------------------------------------------------------

    public function test_business_scoped_store_creates_the_group_under_the_selected_business(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['create_contact_group']);
        $this->giveActiveSubscription($tenant);

        $response = $this->post(route('customer.workspaces.businesses.contacts.store', [$business->workspace->uid, $business->uid]), [
            'name' => 'New Business Group',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('contact_groups', [
            'name' => 'New Business Group',
            'business_id' => $business->id,
            'customer_id' => $business->customer_id,
        ]);
    }

    public function test_business_scoped_copy_keeps_the_copied_group_in_the_same_business(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $group = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $business->id, 'name' => 'Original', 'status' => true]);
        $this->giveActiveSubscription($tenant);
        $this->authenticateAsCustomer($tenant, ['create_contact_group']);

        $response = $this->postJson(route('customer.workspaces.businesses.contacts.copy', [$business->workspace->uid, $business->uid, $group->uid]), [
            'group_name' => 'Copy of Original',
        ]);

        $response->assertOk();
        $response->assertJson(['status' => 'success']);
        $this->assertDatabaseHas('contact_groups', ['name' => 'Copy of Original', 'business_id' => $business->id]);
    }

    // -----------------------------------------------------------------
    // Batch destroy/enable/disable stay confined to the selected
    // Business's own groups.
    // -----------------------------------------------------------------

    public function test_business_scoped_batch_destroy_cannot_touch_a_different_businesss_group(): void
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $businessA = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business A']));
        $businessB = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes(['name' => 'Business B']));

        $foreignGroup = ContactGroups::create(['customer_id' => $tenant->user_id, 'business_id' => $businessB->id, 'name' => 'B Group', 'status' => true]);

        $this->authenticateAsCustomer($tenant, ['delete_contact_group']);

        $this->postJson(route('customer.workspaces.businesses.contacts.batch_action', [$businessA->workspace->uid, $businessA->uid]), [
            'action' => 'destroy',
            'ids' => [$foreignGroup->uid],
        ]);

        $this->assertDatabaseHas('contact_groups', ['id' => $foreignGroup->id]);
    }

    // -----------------------------------------------------------------
    // Access boundary — same fail-closed pattern as Outreach/Usage &
    // Billing: unknown Workspace/Business or an inaccessible Business
    // 404s before any query runs.
    // -----------------------------------------------------------------

    public function test_business_scoped_contacts_index_404s_for_actor_with_no_access_to_the_business(): void
    {
        [, $businessA] = $this->tenantWithBusiness();
        [$tenantB] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenantB, ['view_contact_group']);

        $this->get(route('customer.workspaces.businesses.contacts.index', [$businessA->workspace->uid, $businessA->uid]))
            ->assertStatus(404);
    }

    public function test_business_scoped_contacts_index_404s_for_unknown_business(): void
    {
        [$tenant, $business] = $this->tenantWithBusiness();
        $this->authenticateAsCustomer($tenant, ['view_contact_group']);

        $this->get(route('customer.workspaces.businesses.contacts.index', [$business->workspace->uid, 'no-such-business']))
            ->assertStatus(404);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business}
     */
    private function tenantWithBusiness(): array
    {
        $this->ensureRequiredAppConfigRowsExist();
        $tenant = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($tenant, $this->businessAttributes());

        return [$tenant, $business];
    }

    private function authenticateAsCustomer(Customer $customer, array $permissions): void
    {
        $customer->user->email_verified_at = now();
        $customer->user->save();

        $this->withSession(['permissions' => collect(array_merge(['access_backend'], $permissions))]);
        $this->actingAs($customer->user);
    }

    private function giveActiveSubscription(Customer $customer): void
    {
        $currency = \App\Models\Currency::firstOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'format' => '$', 'status' => true]);

        $plan = \App\Models\Plan::create([
            'currency_id' => $currency->id,
            'name' => 'CRM Business Test Plan ' . uniqid(),
            'price' => 10,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'options' => json_encode([]),
            'status' => true,
        ]);

        \App\Models\Subscription::create([
            'user_id' => $customer->user_id,
            'plan_id' => $plan->id,
            'status' => \App\Models\Subscription::STATUS_ACTIVE,
            'paid' => true,
            'start_at' => now(),
            'end_at' => null,
        ]);
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
