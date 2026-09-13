<?php

namespace Tests\Feature\Business;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Owner product decision — Settings -> Text messaging is the entire
 * messaging setup/health hub. This proves the three-state contract end to
 * end through the real controller/routes: STATE 1 (no number) -> STATE 2
 * (registration required) -> STATE 3 (ready), plus the preview/inert
 * requirement ("with no live Telnyx credentials, preview must render a
 * truthful inert state; no live purchases") and Business isolation.
 *
 * CreatesMessagingFixtures' attachIdentity()/attachNumber() write the
 * managed rows directly — appropriate here, since this file is proving
 * what the PAGE shows for a given state, not how a number gets acquired
 * (BusinessMessagingProvisioningServiceTest owns that).
 */
class TextMessagingSetupStateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    // -----------------------------------------------------------------
    // STATE 1 — no number.
    // -----------------------------------------------------------------

    public function test_a_business_with_no_number_sees_the_get_a_number_state(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Get a phone number');
        $response->assertDontSee('Telnyx', false);
        $response->assertDontSee('Twilio', false);
        $response->assertDontSee('10DLC', false);
    }

    public function test_without_configured_credentials_state_one_renders_a_truthful_inert_preview(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Preview', false);
        $response->assertSee('data-role="preview-notice"', false);
    }

    public function test_searching_without_configured_credentials_never_returns_a_fake_number(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $response = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), [
            'number_type' => 'local',
        ]);

        $response->assertOk();
        $response->assertSee('No number found');
        $this->assertDatabaseMissing('business_messaging_numbers', ['business_messaging_identity_id' => null]);
        $this->assertDatabaseCount('business_messaging_numbers', 0);
    }

    public function test_ordering_a_number_without_configured_credentials_never_purchases_anything(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'phone_number' => '+14155550199',
            'provider_candidate_reference' => 'should-never-be-ordered',
            'number_type' => 'local',
        ])->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_numbers', 0);
        $this->assertDatabaseCount('business_messaging_identities', 0);
    }

    public function test_ordering_a_real_number_with_credentials_configured_advances_to_registration_required(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'phone_number' => '+14155550200',
            'provider_candidate_reference' => 'candidate-ref-order-1',
            'number_type' => 'local',
        ])->assertSessionHas('status', 'success');

        $this->assertDatabaseHas('business_messaging_numbers', ['phone_number' => '+14155550200']);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $response->assertOk();
        $response->assertSee('+14155550200');
        $response->assertSee('Business details');
    }

    public function test_a_business_that_already_has_a_number_cannot_reach_the_search_or_order_actions(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550201');
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'local'])
            ->assertNotFound();
        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'phone_number' => '+14155550202', 'provider_candidate_reference' => 'x', 'number_type' => 'local',
        ])->assertNotFound();
    }

    // -----------------------------------------------------------------
    // STATE 2 — registration required.
    // -----------------------------------------------------------------

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

    public function test_a_business_with_a_number_but_no_registration_sees_the_registration_state(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550300');
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('+14155550300');
        $response->assertSee('Not started');
        $response->assertSee('Business details');
        $response->assertDontSee('10DLC', false);
        $response->assertSee('Messaging registration');
    }

    public function test_saving_business_details_persists_them_and_stays_in_the_registration_state(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550301');
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertSessionHas('status', 'success');

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame('Harbor Lane Studios LLC', $registration->legal_business_name);
        $this->assertSame('local', $registration->number_type->value);
    }

    public function test_submitting_registration_without_details_is_rejected(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550302');
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'error');

        $this->assertDatabaseMissing('business_messaging_registrations', ['business_id' => $business->id]);
    }

    public function test_submitting_registration_with_credentials_configured_moves_to_pending(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550303');
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->status);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $response->assertSee('Pending review');
    }

    public function test_submitting_registration_without_configured_credentials_never_fakes_approval(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550304');
        $this->authenticateAs($customer);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload());
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'error');

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->status);
        $this->assertNull($registration->provider_brand_id);
    }

    public function test_a_rejected_registration_shows_the_rejection_reason_and_a_next_action(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550305');
        BusinessMessagingRegistration::create(array_merge($this->registrationPayload(), [
            'business_id' => $business->id,
            'number_type' => 'local',
            'status' => 'rejected',
            'rejection_reason' => 'The website did not match the business name.',
        ]));
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Needs attention');
        $response->assertSee('The website did not match the business name.');
        $response->assertSee('Business details');
    }

    // -----------------------------------------------------------------
    // STATE 3 — ready.
    // -----------------------------------------------------------------

    public function test_an_active_number_with_an_approved_registration_is_ready(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+14155550400');
        BusinessMessagingRegistration::create(array_merge($this->registrationPayload(), [
            'business_id' => $business->id,
            'number_type' => 'local',
            'status' => 'approved',
            'approved_at' => now(),
        ]));
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Ready');
        $response->assertSee('+14155550400');
        $response->assertSeeInOrder(['Text messages', 'Available']);
        $response->assertSeeInOrder(['Picture messages', 'Available']);
        $response->assertSee('Delivery & usage');
    }

    public function test_an_active_number_with_pending_registration_is_not_yet_ready(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+14155550401');
        BusinessMessagingRegistration::create(array_merge($this->registrationPayload(), [
            'business_id' => $business->id,
            'number_type' => 'local',
            'status' => 'pending',
        ]));
        $this->authenticateAs($customer);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));

        $response->assertOk();
        $response->assertSee('Pending review');
        $response->assertSee('+14155550401');
        $response->assertDontSee('data-role="preview-notice"', false);
    }

    // -----------------------------------------------------------------
    // Business isolation.
    // -----------------------------------------------------------------

    public function test_business_a_cannot_view_or_act_on_business_bs_text_messaging_setup(): void
    {
        [$customerA, , $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $this->authenticateAs($customerA);

        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspaceA->uid, $businessB->uid]))
            ->assertNotFound();
        $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceB->uid, $businessB->uid]), ['number_type' => 'local'])
            ->assertNotFound();
    }
}
