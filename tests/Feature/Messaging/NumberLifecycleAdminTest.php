<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Helpers\Helper;
use App\Models\AppConfig;
use App\Models\BusinessMessagingNumber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Phone Numbers + A2P lane — the platform-ops admin surface for the
 * messaging contract §13.2/§13.3 number lifecycle: visibility into
 * suspended numbers, and the one genuinely human, irreversible action,
 * explicit audited release. Same admin-only authorization shape as
 * MessagingProvisioningIncidentReconciliationTest and PortOutRequestTest's
 * own admin surfaces.
 */
class NumberLifecycleAdminTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();

        // Same established pattern as MessagingProvisioningIncidentReconciliationTest
        // — burn user id 1's super-admin bypass on an unrelated user first.
        User::create([
            'first_name' => 'Unrelated', 'last_name' => 'FirstUser',
            'email' => 'burn-id-one-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => true, 'active_portal' => 'customer',
        ]);
    }

    private function ensureRequiredAppConfigRowsExist(): void
    {
        $existing = AppConfig::whereIn('setting', ['license', 'customer_permissions', 'custom_script'])->pluck('setting')->all();

        if (! in_array('license', $existing, true)) {
            AppConfig::create(['setting' => 'license', 'value' => 'test-license-key']);
        }

        if (! in_array('custom_script', $existing, true)) {
            AppConfig::create(['setting' => 'custom_script', 'value' => '']);
        }

        if (! in_array('customer_permissions', $existing, true)) {
            $default = collect((new AppConfig())->defaultSettings())->firstWhere('setting', 'customer_permissions');
            AppConfig::create($default);
        }
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'first_name' => 'Ops', 'last_name' => 'Admin',
            'email' => 'lifecycle-admin-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        return $admin;
    }

    private function releaseEligibleNumber(): BusinessMessagingNumber
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'grace_expires_at' => now()->subDay(),
            'release_notice_sent_at' => now(),
        ]);

        return $number->fresh();
    }

    // =================================================================
    // Visibility — authorized platform operators only.
    // =================================================================

    public function test_a_guest_cannot_list_suspended_numbers(): void
    {
        $this->get(route('admin.messaging-number-lifecycle.index'))->assertUnauthorized();
    }

    public function test_a_customer_cannot_list_suspended_numbers_even_with_backend_permissions_in_session(): void
    {
        $customer = $this->createCustomer();
        $this->withSession(['permissions' => collect(['access backend', 'access_backend'])]);
        $this->actingAs($customer->user);

        $this->get(route('admin.messaging-number-lifecycle.index'))->assertUnauthorized();
    }

    public function test_an_administrator_sees_suspended_numbers(): void
    {
        $number = $this->releaseEligibleNumber();
        $business = $number->identity->business;

        $this->actingAsAdmin();
        $html = (string) $this->get(route('admin.messaging-number-lifecycle.index'))->assertOk()->getContent();

        $this->assertStringContainsString($business->name, $html);
        $this->assertStringContainsString($number->phone_number, $html);
    }

    // =================================================================
    // Release — explicit, auditable, admin-only.
    // =================================================================

    public function test_a_guest_cannot_release_a_number(): void
    {
        $number = $this->releaseEligibleNumber();

        $this->post(route('admin.messaging-number-lifecycle.release', $number->id), [
            'release_confirmed' => '1',
            'note' => 'Attempted by an unauthenticated request.',
        ])->assertUnauthorized();

        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $number->fresh()->status);
    }

    public function test_a_customer_cannot_release_a_number_even_with_backend_permissions_in_session(): void
    {
        $number = $this->releaseEligibleNumber();
        $customer = $this->createCustomer();
        $this->withSession(['permissions' => collect(['access backend', 'access_backend'])]);
        $this->actingAs($customer->user);

        $this->post(route('admin.messaging-number-lifecycle.release', $number->id), [
            'release_confirmed' => '1',
            'note' => 'Attempted by a customer account.',
        ])->assertUnauthorized();

        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $number->fresh()->status);
    }

    public function test_an_administrator_can_release_an_eligible_number_with_note_and_confirmation(): void
    {
        $number = $this->releaseEligibleNumber();
        $admin = $this->actingAsAdmin();

        $this->post(route('admin.messaging-number-lifecycle.release', $number->id), [
            'release_confirmed' => '1',
            'note' => 'Confirmed with the customer; releasing now.',
        ])
            ->assertRedirect(route('admin.messaging-number-lifecycle.index'))
            ->assertSessionHas('flash_success');

        $number->refresh();
        $this->assertSame(BusinessMessagingNumberStatus::Released, $number->status);
        $this->assertNotNull($number->released_at);
    }

    public function test_release_requires_a_note(): void
    {
        $number = $this->releaseEligibleNumber();
        $this->actingAsAdmin();

        $this->post(route('admin.messaging-number-lifecycle.release', $number->id), [
            'release_confirmed' => '1',
        ])->assertSessionHasErrors('note');

        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $number->fresh()->status);
    }

    public function test_release_requires_the_confirmation_checkbox(): void
    {
        $number = $this->releaseEligibleNumber();
        $this->actingAsAdmin();

        $this->post(route('admin.messaging-number-lifecycle.release', $number->id), [
            'note' => 'Forgot to tick the box.',
        ])->assertSessionHasErrors('release_confirmed');

        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $number->fresh()->status);
    }

    public function test_release_is_refused_when_grace_has_not_expired(): void
    {
        $business = $this->makeBusiness();
        $number = $this->attachNumber($this->attachIdentity($business), $this->uniqueNumber(), true, BusinessMessagingNumberStatus::Suspended);
        DB::table('business_messaging_numbers')->where('id', $number->id)->update([
            'grace_expires_at' => now()->addDays(5),
            'release_notice_sent_at' => now(),
        ]);
        $this->actingAsAdmin();

        $this->post(route('admin.messaging-number-lifecycle.release', $number->id), [
            'release_confirmed' => '1',
            'note' => 'Attempted early release.',
        ])->assertSessionHas('flash_error');

        $this->assertSame(BusinessMessagingNumberStatus::Suspended, $number->fresh()->status);
    }

    // =================================================================
    // Discoverable admin menu link, same boundary as the route.
    // =================================================================

    /**
     * @return array<int, object>
     */
    private function usageBillingSubmenu(): array
    {
        $submenu = collect(Helper::menuData()['admin'])->firstWhere('name', 'Usage Billing')['submenu'] ?? [];

        return json_decode(json_encode($submenu));
    }

    public function test_an_administrator_can_find_the_number_lifecycle_link_in_the_admin_menu(): void
    {
        $this->actingAsAdmin();

        $html = view('panels.submenu', ['menu' => $this->usageBillingSubmenu()])->render();

        $this->assertStringContainsString(url(config('app.admin_path') . '/messaging-number-lifecycle'), $html);
        $this->assertStringContainsString('Messaging Number Lifecycle', $html);
    }

    public function test_a_non_admin_backend_account_cannot_find_the_number_lifecycle_link(): void
    {
        $staff = User::create([
            'first_name' => 'Limited', 'last_name' => 'Staff',
            'email' => 'limited-staff-lifecycle-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($staff);

        $html = view('panels.submenu', ['menu' => $this->usageBillingSubmenu()])->render();

        $this->assertStringNotContainsString(url(config('app.admin_path') . '/messaging-number-lifecycle'), $html);
    }
}
