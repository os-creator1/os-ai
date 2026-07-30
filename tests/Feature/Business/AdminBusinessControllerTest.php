<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessStatus;
use App\Events\Business\BusinessUpdated;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

class AdminBusinessControllerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    /**
     * Seeded once per test, before any actor logs in: RedirectIfNotValid
     * (ValidProduct) runs ahead of the 'can:access backend' gate for this
     * middleware group and crashes on a missing 'license' row before
     * authorization is ever evaluated — this affects every authenticated
     * actor in this file (admin or ordinary customer alike), not just the
     * admin ones, so it belongs in setUp() rather than a per-actor helper.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();
    }

    public function test_guest_cannot_access_admin_business_index(): void
    {
        $this->get(route('admin.businesses.index'))->assertUnauthorized();
    }

    public function test_ordinary_customer_cannot_access_admin_business_routes(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAs($customer->user);

        $this->get(route('admin.businesses.index'))->assertUnauthorized();
        $this->get(route('admin.businesses.show', $business))->assertUnauthorized();
        $this->get(route('admin.businesses.edit', $business))->assertUnauthorized();
        $this->put(route('admin.businesses.update', $business), $this->adminIdentityPayload())
            ->assertUnauthorized();
        $this->patch(route('admin.businesses.status.update', $business), ['status' => BusinessStatus::Active->value])
            ->assertUnauthorized();

        $fresh = $business->fresh();
        $this->assertSame($business->name, $fresh->name);
        $this->assertSame(BusinessStatus::Draft, $fresh->status);
    }

    /**
     * Direct regression test for the reported defect: EnsureUserIsAdministrator
     * must block a non-admin account (users.is_admin = false) even when its
     * session somehow carries the exact permission strings the feature-level
     * checks look for — proving the account-type boundary is independent of,
     * and does not rely on, permission-string content.
     */
    public function test_a_non_admin_account_is_blocked_even_with_business_permissions_in_session(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->withSession(['permissions' => collect(['access backend', 'view business', 'edit business'])]);
        $this->actingAs($customer->user);

        $this->get(route('admin.businesses.index'))->assertUnauthorized();
        $this->get(route('admin.businesses.show', $business))->assertUnauthorized();
        $this->put(route('admin.businesses.update', $business), $this->adminIdentityPayload())
            ->assertUnauthorized();

        $this->assertSame($business->name, $business->fresh()->name);
    }

    public function test_admin_without_access_backend_permission_is_blocked_at_the_route_gate(): void
    {
        // No permissions at all — not even generic backend access.
        $this->actingAsAdmin([]);

        $this->get(route('admin.businesses.index'))->assertUnauthorized();
    }

    public function test_admin_with_backend_access_but_no_business_permission_cannot_view_index(): void
    {
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.businesses.index'))->assertUnauthorized();
    }

    public function test_admin_with_backend_access_but_no_business_permission_cannot_view_show(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend']);

        $this->get(route('admin.businesses.show', $business))->assertUnauthorized();
    }

    public function test_view_only_admin_can_view_index_and_show(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business']);

        $this->get(route('admin.businesses.index'))->assertOk()->assertSee($business->name);
        $this->get(route('admin.businesses.show', $business))->assertOk()->assertSee($business->name);
    }

    public function test_view_only_admin_cannot_open_edit_form(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business']);

        $this->get(route('admin.businesses.edit', $business))->assertUnauthorized();
    }

    public function test_view_only_admin_cannot_submit_an_update(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business']);

        $this->put(route('admin.businesses.update', $business), $this->adminIdentityPayload())
            ->assertUnauthorized();

        $this->assertSame($business->name, $business->fresh()->name);
    }

    public function test_view_only_admin_cannot_change_status(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business']);

        $this->patch(route('admin.businesses.status.update', $business), ['status' => BusinessStatus::Active->value])
            ->assertUnauthorized();

        $this->assertSame(BusinessStatus::Draft, $business->fresh()->status);
    }

    /**
     * Proves admin access is intentionally cross-tenant: the acting admin
     * has no ownership relationship to the customer whose business is
     * edited, yet the update succeeds once properly authorized.
     */
    public function test_edit_authorized_admin_can_update_any_business_across_tenants(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business', 'edit business']);

        $this->put(route('admin.businesses.update', $business), $this->adminIdentityPayload(['name' => 'Renamed By Admin']))
            ->assertRedirect(route('admin.businesses.show', $business));

        $this->assertSame('Renamed By Admin', $business->fresh()->name);
    }

    public function test_admin_update_cannot_reassign_customer_id(): void
    {
        $owner = $this->createCustomer();
        $stranger = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($owner, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business', 'edit business']);

        $this->put(route('admin.businesses.update', $business), $this->adminIdentityPayload([
            'customer_id' => $stranger->user_id,
        ]));

        $this->assertSame($owner->user_id, $business->fresh()->customer_id);
    }

    public function test_admin_update_cannot_set_other_protected_fields(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business', 'edit business']);

        $this->put(route('admin.businesses.update', $business), $this->adminIdentityPayload([
            'is_primary' => false,
            'status' => BusinessStatus::Active->value,
            'canonical_domain' => 'attacker.test',
            'activated_at' => now()->toDateTimeString(),
            'created_at' => now()->subYear()->toDateTimeString(),
            'updated_at' => now()->subYear()->toDateTimeString(),
        ]));

        $fresh = $business->fresh();
        $this->assertTrue($fresh->is_primary);
        $this->assertSame(BusinessStatus::Draft, $fresh->status);
        $this->assertNull($fresh->canonical_domain);
        $this->assertNull($fresh->activated_at);
        $this->assertNotEquals(now()->subYear()->toDateString(), $fresh->created_at->toDateString());
    }

    public function test_admin_update_does_not_persist_partial_changes_on_validation_failure(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business', 'edit business']);

        $payload = $this->adminIdentityPayload([
            'name' => '', // required — fails validation
            'email' => 'new-email@example.test',
        ]);

        $this->put(route('admin.businesses.update', $business), $payload)
            ->assertSessionHasErrors('name');

        $fresh = $business->fresh();
        $this->assertSame($business->name, $fresh->name);
        $this->assertNull($fresh->email);
    }

    /**
     * BusinessUpdated is only ever dispatched by BusinessManager::updateBusiness()
     * — never by the raw repository update() path — so this is direct proof the
     * admin controller reuses the accepted orchestration layer rather than
     * duplicating persistence logic.
     */
    public function test_admin_update_uses_business_manager_and_dispatches_business_updated(): void
    {
        Event::fake([BusinessUpdated::class]);
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business', 'edit business']);

        $this->put(route('admin.businesses.update', $business), $this->adminIdentityPayload(['name' => 'Orchestrated Name']));

        Event::assertDispatched(BusinessUpdated::class, fn ($event) => $event->businessId === $business->id
            && in_array('name', $event->changedFields, true));
    }

    public function test_admin_can_update_status_via_the_dedicated_route(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business', 'edit business']);

        $this->patch(route('admin.businesses.status.update', $business), ['status' => BusinessStatus::Active->value])
            ->assertRedirect(route('admin.businesses.show', $business));

        $fresh = $business->fresh();
        $this->assertSame(BusinessStatus::Active, $fresh->status);
        $this->assertNotNull($fresh->activated_at);
    }

    public function test_status_update_does_not_accept_activated_at_from_the_request(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business', 'edit business']);

        $forgedTimestamp = now()->subYears(5)->toDateTimeString();

        $this->patch(route('admin.businesses.status.update', $business), [
            'status' => BusinessStatus::Active->value,
            'activated_at' => $forgedTimestamp,
        ]);

        $fresh = $business->fresh();
        $this->assertNotEquals($forgedTimestamp, $fresh->activated_at?->toDateTimeString());
    }

    /**
     * A status-only PATCH must never touch identity fields, proving it goes
     * through BusinessRepository::updateStatus() and not the generic
     * update()/BusinessManager identity path.
     */
    public function test_status_update_does_not_change_identity_fields(): void
    {
        $customer = $this->createCustomer();
        $business = $this->createBusinessWithWorkspace($customer, $this->businessAttributes());
        $this->actingAsAdmin(['access backend', 'view business', 'edit business']);

        $this->patch(route('admin.businesses.status.update', $business), [
            'status' => BusinessStatus::Active->value,
            'name' => 'Should Not Apply',
        ]);

        $this->assertSame($business->name, $business->fresh()->name);
    }

    public function test_index_filter_query_params_are_wired_through_to_the_repository(): void
    {
        $customer = $this->createCustomer();
        $matching = $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Snap Booth Co']));
        $this->createBusinessWithWorkspace($customer, $this->businessAttributes(['name' => 'Unrelated Co']));
        $this->actingAsAdmin(['access backend', 'view business']);

        $this->get(route('admin.businesses.index', ['search' => 'Snap Booth']))
            ->assertOk()
            ->assertSee('Snap Booth Co')
            ->assertDontSee('Unrelated Co');

        $this->assertSame('Snap Booth Co', $matching->name);
    }

    public function test_customer_business_edit_route_remains_tenant_scoped(): void
    {
        $customerA = $this->createCustomer();
        $customerB = $this->createCustomer();
        $this->createBusinessWithWorkspace($customerA, $this->businessAttributes(['name' => 'Customer A Co']));
        $businessB = $this->createBusinessWithWorkspace($customerB, $this->businessAttributes(['name' => 'Customer B Co']));

        $customerA->permissions = \App\Models\Customer::customerPermissions();
        $customerA->save();
        $customerA->user->email_verified_at = now();
        $customerA->user->save();
        $this->actingAs($customerA->user);

        $response = $this->get(route('customer.business.edit'));

        $response->assertOk();
        $response->assertSee('Customer A Co');
        $response->assertDontSee('Customer B Co');
        $this->assertNotSame($businessB->name, 'Customer A Co');
    }

    /**
     * @return array<string, mixed>
     */
    private function adminIdentityPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Snap Booth Co',
            'industry' => 'photo_booth_service',
            'country_code' => 'US',
            'timezone' => 'America/New_York',
            'currency_code' => 'USD',
        ], $overrides);
    }

    private function actingAsAdmin(array $permissions): User
    {
        $admin = User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect($permissions)]);
        $this->actingAs($admin);

        return $admin;
    }

    /**
     * Both the admin route group (ValidProduct) and the customer route
     * group render the shared layout, which unconditionally reads the
     * 'license' and 'custom_script' app_config rows (see
     * BusinessOnboardingHttpTest for the fuller rationale).
     */
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
