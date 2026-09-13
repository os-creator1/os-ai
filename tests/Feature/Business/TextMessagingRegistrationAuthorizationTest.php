<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * PR #295 Correction Round 2, item 1 — LOCKED authorization model for
 * legal/compliance messaging registration:
 *
 *   READ (show / delivery-usage)          -> view_numbers
 *   NUMBER ACQUISITION (search / order)   -> buy_numbers
 *   LEGAL/COMPLIANCE REGISTRATION         -> buy_numbers AND the canonical
 *                                            Workspace/Account management
 *                                            authority (owner-or-active-
 *                                            admin — CustomerContext::
 *                                            canManageWorkspace(), the
 *                                            exact rule already gating
 *                                            Settings -> Team/Billing/Plan)
 *
 * manage_advanced_provider (Agency/BYO provider configuration, default
 * false) is never checked here — that would lock ordinary Core/Growth
 * customers out of finishing their own managed setup, and is exactly the
 * bug this round fixes. The Advanced/BYO surface itself
 * (MessagingChannelsController) is untouched; RelocatedAdvancedProviderAuthorizationTest
 * and MessagingProviderAuthorizationTest continue to own its own matrix.
 */
class TextMessagingRegistrationAuthorizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function bindFakeProvisioningAdapter(): FakeProvisioningAdapter
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_key',
        ]);

        return $fake;
    }

    private function registrationPayload(array $overrides = []): array
    {
        return array_merge([
            'legal_business_name' => 'Harbor Lane Studios LLC',
            'entity_type' => 'ein',
            'ein' => '12-3456789',
            'address_line_1' => '1 Harbor Lane',
            'city' => 'Portland',
            'region' => 'OR',
            'postal_code' => '97201',
            'website_url' => 'https://harborlane.example',
            'contact_email' => 'owner@harborlane.example',
            'contact_phone' => '+15035550100',
            'use_case' => 'appointment_reminders',
            'opt_in_method' => 'Customers check a box on our booking form.',
            'sample_message_1' => 'Harbor Lane: your appointment is confirmed. Reply STOP to unsubscribe.',
            'sample_message_2' => 'Harbor Lane: reminder, your appointment is tomorrow.',
            'privacy_policy_url' => 'https://harborlane.example/privacy',
            'terms_url' => 'https://harborlane.example/terms',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // A / B — Core and Growth owners can complete registration with no
    // Agency/BYO permission whatsoever.
    // -----------------------------------------------------------------

    public function test_a_core_owner_can_save_and_submit_registration(): void
    {
        $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Core);
        $this->attachNumber($this->attachIdentity($business), '+14155550500');
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $this->assertSame(
            MessagingRegistrationStatus::Pending,
            BusinessMessagingRegistration::where('business_id', $business->id)->first()->status,
        );
    }

    public function test_a_growth_owner_can_save_and_submit_registration(): void
    {
        $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550501');
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $this->assertSame(
            MessagingRegistrationStatus::Pending,
            BusinessMessagingRegistration::where('business_id', $business->id)->first()->status,
        );
    }

    // -----------------------------------------------------------------
    // C — view_numbers alone (no buy_numbers) can never mutate.
    // -----------------------------------------------------------------

    public function test_view_numbers_only_can_never_mutate_registration(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550502');
        $this->authenticateAs($customer, ['view_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertStatus(401);

        $this->assertDatabaseMissing('business_messaging_registrations', ['business_id' => $business->id]);
    }

    // -----------------------------------------------------------------
    // D — a genuine Workspace member, holding full buy_numbers access to
    // this exact Business, is still refused unless they are the owner or
    // an active Admin. An active Admin (an "authorized account manager"
    // who is NOT the owner) succeeds, exactly like Settings -> Team.
    // -----------------------------------------------------------------

    public function test_a_plain_workspace_member_with_buy_numbers_cannot_mutate_registration(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550503');

        $staffCustomer = $this->createCustomer();
        $this->member($workspace, $staffCustomer->user, WorkspaceMembershipRole::Staff, WorkspaceBusinessAccessScope::All, true);
        $this->authenticateAs($staffCustomer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertStatus(401);

        $this->assertDatabaseMissing('business_messaging_registrations', ['business_id' => $business->id]);
    }

    public function test_an_active_admin_who_is_not_the_owner_can_mutate_registration(): void
    {
        $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550504');

        $adminCustomer = $this->createCustomer();
        $this->member($workspace, $adminCustomer->user, WorkspaceMembershipRole::Admin, WorkspaceBusinessAccessScope::All, true);
        $this->authenticateAs($adminCustomer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $this->assertSame(
            MessagingRegistrationStatus::Pending,
            BusinessMessagingRegistration::where('business_id', $business->id)->first()->status,
        );
    }

    public function test_an_unrelated_businesss_member_cannot_mutate_a_foreign_business_registration(): void
    {
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $this->attachNumber($this->attachIdentity($businessB), '+14155550505');
        $this->authenticateAs($customerA, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspaceB->uid, $businessB->uid]), $this->registrationPayload())
            ->assertNotFound();
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspaceB->uid, $businessB->uid]))
            ->assertNotFound();

        $this->assertDatabaseMissing('business_messaging_registrations', ['business_id' => $businessB->id]);
    }

    // -----------------------------------------------------------------
    // E — an Agency-tier owner completes MANAGED registration with no
    // manage_advanced_provider grant at all.
    // -----------------------------------------------------------------

    public function test_an_agency_owner_can_complete_managed_registration_without_byo_provider_access(): void
    {
        $this->bindFakeProvisioningAdapter();
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Agency);
        $this->attachNumber($this->attachIdentity($business), '+14155550506');
        // Deliberately NOT granted: manage_advanced_provider.
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $this->assertSame(
            MessagingRegistrationStatus::Pending,
            BusinessMessagingRegistration::where('business_id', $business->id)->first()->status,
        );
    }

    // -----------------------------------------------------------------
    // F (independence) — manage_advanced_provider, granted ALONE without
    // buy_numbers, is not a substitute and never suffices here. This is
    // the other half of the bug this round fixes: the permission this
    // controller now checks (buy_numbers) is completely independent of
    // the Agency/BYO permission, in both directions.
    // -----------------------------------------------------------------

    public function test_manage_advanced_provider_alone_never_suffices_for_registration(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550507');
        $this->authenticateAs($customer, ['view_numbers', 'manage_advanced_provider']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertStatus(401);

        $this->assertDatabaseMissing('business_messaging_registrations', ['business_id' => $business->id]);
    }
}
