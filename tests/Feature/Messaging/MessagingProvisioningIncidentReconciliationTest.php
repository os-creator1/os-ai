<?php

namespace Tests\Feature\Messaging;

use App\Helpers\Helper;
use App\Library\Messaging\ProvisioningIncidentRecorder;
use App\Models\AppConfig;
use App\Models\Business;
use App\Models\BusinessMessagingProvisioningIncident;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Phone Numbers + A2P lane — the support/ops reconciliation path for
 * business_messaging_provisioning_incidents. Visibility and resolution are
 * both platform-administrator-only (mirroring PaymentProviderEventController
 * and AiUsageAdminSummaryTest's own authorization shape); resolution is an
 * explicit, auditable, idempotent action, never inferred or automatic.
 */
class MessagingProvisioningIncidentReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ensureRequiredAppConfigRowsExist();

        // AiUsageAdminSummaryTest's own established pattern (via
        // CreatesCustomerContextFixtures::platformAdminId()): user id 1
        // short-circuits EloquentAccountRepository::hasPermission() as an
        // unconditional super-admin, regardless of is_admin. Burning it here
        // on an unrelated throwaway user keeps every fixture user created
        // below out of that bypass, so the authorization tests below prove
        // what they claim to prove.
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

    private function recorder(): ProvisioningIncidentRecorder
    {
        return app(ProvisioningIncidentRecorder::class);
    }

    private function recordIncident(Business $business, array $overrides = []): BusinessMessagingProvisioningIncident
    {
        return $this->recorder()->record(
            $business,
            $overrides['stage'] ?? 'number_attach_failed_after_provider_success',
            $overrides['messaging_profile_id'] ?? 'mp_fixture_1',
            $overrides['provider_phone_number_id'] ?? 'pn_fixture_1',
            $overrides['phone_number'] ?? '+14155550199',
            $overrides['number_type'] ?? 'local',
            $overrides['error_message'] ?? 'Simulated reconciliation-test failure.',
        );
    }

    private function actingAsAdmin(): User
    {
        $admin = User::create([
            'first_name' => 'Ops', 'last_name' => 'Admin',
            'email' => 'provisioning-ops-admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);

        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($admin);

        return $admin;
    }

    // =================================================================
    // Visibility — authorized platform operators only.
    // =================================================================

    public function test_a_guest_cannot_list_incidents(): void
    {
        $this->get(route('admin.messaging-provisioning-incidents.index'))->assertUnauthorized();
    }

    public function test_a_customer_cannot_list_incidents_even_with_backend_permissions_in_session(): void
    {
        $customer = $this->createCustomer();

        $this->withSession(['permissions' => collect(['access backend', 'access_backend'])]);
        $this->actingAs($customer->user);

        $this->get(route('admin.messaging-provisioning-incidents.index'))->assertUnauthorized();
    }

    public function test_an_administrator_sees_unresolved_incidents(): void
    {
        $business = $this->makeBusiness();
        $this->recordIncident($business, ['phone_number' => '+14155550201', 'error_message' => 'Number order failed after profile creation.']);

        $this->actingAsAdmin();
        $html = (string) $this->get(route('admin.messaging-provisioning-incidents.index'))->assertOk()->getContent();

        $this->assertStringContainsString($business->name, $html);
        $this->assertStringContainsString('+14155550201', $html);
        $this->assertStringContainsString('number_attach_failed_after_provider_success', $html);
        $this->assertStringContainsString('Number order failed after profile creation.', $html);
    }

    public function test_a_resolved_incident_no_longer_appears_in_the_unresolved_list(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business, ['phone_number' => '+14155550202']);

        $admin = $this->actingAsAdmin();
        $this->recorder()->resolve((int) $incident->id, (int) $admin->id, 'Verified via Telnyx portal; number correctly attached.');

        $html = (string) $this->get(route('admin.messaging-provisioning-incidents.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('+14155550202', $html);
    }

    // =================================================================
    // Resolution — explicit, auditable, platform-administrator only.
    // =================================================================

    public function test_an_administrator_can_resolve_an_incident_with_an_auditable_note(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);
        $admin = $this->actingAsAdmin();

        $this->post(route('admin.messaging-provisioning-incidents.resolve', $incident->id), [
            'reconciliation_confirmed' => '1',
            'resolution_note' => 'Confirmed in Telnyx dashboard: number pn_fixture_1 is attached to mp_fixture_1. Re-ran attachNumber() manually.',
        ])
            ->assertRedirect(route('admin.messaging-provisioning-incidents.index'))
            ->assertSessionHas('flash_success');

        $incident->refresh();
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame((int) $admin->id, $incident->resolved_by_user_id);
        $this->assertSame(
            'Confirmed in Telnyx dashboard: number pn_fixture_1 is attached to mp_fixture_1. Re-ran attachNumber() manually.',
            $incident->resolution_note,
        );
    }

    public function test_resolving_an_incident_requires_a_resolution_note(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);
        $this->actingAsAdmin();

        $this->post(route('admin.messaging-provisioning-incidents.resolve', $incident->id), [
            'reconciliation_confirmed' => '1',
        ])
            ->assertSessionHasErrors('resolution_note');

        $this->assertNull($incident->fresh()->resolved_at);
    }

    /**
     * Item 2 of the review — 'reconciliation_confirmed' is a human
     * attestation that the operator personally checked the Telnyx resource
     * and this platform's own records, distinct from and required alongside
     * the free-text note describing what was checked.
     */
    public function test_resolving_an_incident_requires_the_reconciliation_confirmation_when_omitted(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);
        $this->actingAsAdmin();

        $this->post(route('admin.messaging-provisioning-incidents.resolve', $incident->id), [
            'resolution_note' => 'Checked Telnyx dashboard but forgot to tick the box.',
        ])
            ->assertSessionHasErrors('reconciliation_confirmed');

        $this->assertNull($incident->fresh()->resolved_at, 'A note alone, without the confirmation, must never resolve an incident.');
    }

    public function test_resolving_an_incident_requires_the_reconciliation_confirmation_to_be_truthy_not_merely_present(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);
        $this->actingAsAdmin();

        $this->post(route('admin.messaging-provisioning-incidents.resolve', $incident->id), [
            'reconciliation_confirmed' => '0',
            'resolution_note' => 'The field is present but unchecked.',
        ])
            ->assertSessionHasErrors('reconciliation_confirmed');

        $this->assertNull($incident->fresh()->resolved_at);
    }

    public function test_a_guest_cannot_resolve_an_incident(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);

        $this->post(route('admin.messaging-provisioning-incidents.resolve', $incident->id), [
            'resolution_note' => 'Attempted by an unauthenticated request.',
        ])->assertUnauthorized();

        $this->assertNull($incident->fresh()->resolved_at, 'An unauthenticated request must never resolve an incident.');
    }

    public function test_a_customer_cannot_resolve_an_incident_even_with_backend_permissions_in_session(): void
    {
        $customer = $this->createCustomer();
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);

        $this->withSession(['permissions' => collect(['access backend', 'access_backend'])]);
        $this->actingAs($customer->user);

        $this->post(route('admin.messaging-provisioning-incidents.resolve', $incident->id), [
            'resolution_note' => 'Attempted by a customer account.',
        ])->assertUnauthorized();

        $this->assertNull($incident->fresh()->resolved_at, 'A customer account must never resolve an incident, regardless of session permissions.');
    }

    // =================================================================
    // Idempotency — a repeat resolution attempt is a safe no-op.
    // =================================================================

    public function test_a_repeat_resolution_attempt_is_a_no_op_and_never_overwrites_the_original_resolution(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);
        $admin = $this->actingAsAdmin();

        $this->post(route('admin.messaging-provisioning-incidents.resolve', $incident->id), [
            'reconciliation_confirmed' => '1',
            'resolution_note' => 'First reconciliation: verified with Telnyx support.',
        ])->assertSessionHas('flash_success');

        $incident->refresh();
        $firstResolvedAt = $incident->resolved_at;
        $firstResolvedBy = $incident->resolved_by_user_id;
        $firstNote = $incident->resolution_note;

        $secondAdmin = User::create([
            'first_name' => 'Second', 'last_name' => 'Admin',
            'email' => 'second-admin' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => true, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($secondAdmin);

        $this->post(route('admin.messaging-provisioning-incidents.resolve', $incident->id), [
            'reconciliation_confirmed' => '1',
            'resolution_note' => 'Second attempt — should never land.',
        ])
            ->assertRedirect(route('admin.messaging-provisioning-incidents.index'))
            ->assertSessionHas('flash_error');

        $incident->refresh();
        $this->assertEquals($firstResolvedAt, $incident->resolved_at, 'resolved_at must never be re-timestamped by a repeat attempt.');
        $this->assertSame($firstResolvedBy, $incident->resolved_by_user_id, 'The original resolver must never be overwritten.');
        $this->assertSame($firstNote, $incident->resolution_note, 'The original resolution note must never be overwritten.');
        $this->assertNotSame($admin->id, $secondAdmin->id);
    }

    public function test_the_recorder_resolve_call_itself_is_idempotent_at_the_database_layer(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);

        $first = $this->recorder()->resolve((int) $incident->id, 1, 'First direct resolve() call.');
        $second = $this->recorder()->resolve((int) $incident->id, 2, 'Second direct resolve() call.');

        $this->assertSame(1, $first, 'The first resolve() call updates exactly one row.');
        $this->assertSame(0, $second, 'A second resolve() call against an already-resolved row updates zero rows.');

        $incident->refresh();
        $this->assertSame(1, $incident->resolved_by_user_id);
        $this->assertSame('First direct resolve() call.', $incident->resolution_note);
    }

    public function test_resolving_a_nonexistent_incident_updates_nothing(): void
    {
        $this->assertSame(0, $this->recorder()->resolve(999999, 1, 'No such incident.'));
    }

    // =================================================================
    // Item 2 of the review — the UI is explicit that this is a human
    // attestation, never an automated Telnyx check.
    // =================================================================

    public function test_the_index_page_presents_resolution_as_a_human_attestation_not_an_automated_check(): void
    {
        $business = $this->makeBusiness();
        $incident = $this->recordIncident($business);
        $this->actingAsAdmin();

        $html = (string) $this->get(route('admin.messaging-provisioning-incidents.index'))->assertOk()->getContent();

        $this->assertStringContainsString('name="reconciliation_confirmed"', $html);
        $this->assertStringContainsString('checked this number/profile in Telnyx', $html);
        $this->assertStringContainsString('the app has not verified Telnyx on your behalf', $html);
        $this->assertMatchesRegularExpression(
            '/name="reconciliation_confirmed"[^>]*required/',
            $html,
            'The confirmation checkbox must be required client-side, not only server-side.',
        );
    }

    // =================================================================
    // Item 1 of the review — a discoverable admin-menu link, bounded by the
    // same authorization the route itself enforces.
    // =================================================================

    /**
     * @return array<int, object>
     */
    private function usageBillingSubmenu(): array
    {
        $submenu = collect(Helper::menuData()['admin'])->firstWhere('name', 'Usage Billing')['submenu'] ?? [];

        return json_decode(json_encode($submenu));
    }

    public function test_an_administrator_can_find_the_incident_list_link_in_the_admin_menu(): void
    {
        $this->actingAsAdmin();

        $html = view('panels.submenu', ['menu' => $this->usageBillingSubmenu()])->render();

        $this->assertStringContainsString(url(config('app.admin_path') . '/messaging-provisioning-incidents'), $html);
        $this->assertStringContainsString('Messaging Provisioning Incidents', $html);
    }

    public function test_a_customer_cannot_find_the_incident_list_link_in_the_admin_menu_even_with_backend_permissions(): void
    {
        $customer = $this->createCustomer();
        $this->withSession(['permissions' => collect(['access backend', 'access_backend'])]);
        $this->actingAs($customer->user);

        $html = view('panels.submenu', ['menu' => $this->usageBillingSubmenu()])->render();

        $this->assertStringNotContainsString(url(config('app.admin_path') . '/messaging-provisioning-incidents'), $html);
    }

    /**
     * The realistic gap the review flagged: a non-admin backend account
     * (is_admin false, is_customer false — e.g. limited support staff) can
     * legitimately hold the 'access backend' permission and reach some
     * admin pages, but must never see a link to a page
     * EnsureUserIsAdministrator will refuse it.
     */
    public function test_a_non_admin_backend_account_cannot_find_the_incident_list_link_even_holding_the_access_backend_permission(): void
    {
        $staff = User::create([
            'first_name' => 'Limited', 'last_name' => 'Staff',
            'email' => 'limited-staff-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => false, 'active_portal' => 'admin',
        ]);
        $this->withSession(['permissions' => collect(['access backend'])]);
        $this->actingAs($staff);

        $html = view('panels.submenu', ['menu' => $this->usageBillingSubmenu()])->render();

        $this->assertStringNotContainsString(url(config('app.admin_path') . '/messaging-provisioning-incidents'), $html);
        // And the sibling items untouched by this fix keep their prior,
        // unrelated behaviour — this correction changes nothing for them.
        $this->assertStringContainsString(url(config('app.admin_path') . '/provider-events'), $html);
    }

    // =================================================================
    // Item 3 of the review — the recorder is the only resolution writer.
    // =================================================================

    public function test_the_resolution_columns_are_not_mass_assignable(): void
    {
        $model = new BusinessMessagingProvisioningIncident();

        $this->assertFalse($model->isFillable('resolved_at'));
        $this->assertFalse($model->isFillable('resolved_by_user_id'));
        $this->assertFalse($model->isFillable('resolution_note'));
    }

    public function test_mass_assignment_cannot_resolve_an_incident_bypassing_the_recorder(): void
    {
        $business = $this->makeBusiness();

        $incident = BusinessMessagingProvisioningIncident::create([
            'business_id' => (int) $business->id,
            'stage' => 'number_attach_failed_after_provider_success',
            'phone_number' => '+14155550998',
            // An attacker- or bug-shaped attempt to smuggle a resolution
            // through mass assignment at creation time.
            'resolved_at' => now(),
            'resolved_by_user_id' => 999,
            'resolution_note' => 'Smuggled through mass assignment.',
        ]);

        $incident->refresh();
        $this->assertNull($incident->resolved_at);
        $this->assertNull($incident->resolved_by_user_id);
        $this->assertNull($incident->resolution_note);
    }
}
