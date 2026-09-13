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
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        $fake->queueSearchResult(new \App\Library\Messaging\DTO\AvailableNumberCandidate('+14155550200', \App\Enums\Messaging\PhoneNumberType::Local, 'candidate-ref-order-1'));

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        // PR #295 Correction Round 1, item 2 — the order is placed with
        // the opaque candidate_token this platform's own search response
        // just issued, never with raw, independently-editable fields.
        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), [
            'number_type' => 'local',
        ])->assertOk();
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => $candidateToken,
        ])->assertSessionHas('status', 'success');

        $this->assertDatabaseHas('business_messaging_numbers', ['phone_number' => '+14155550200']);

        $response = $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]));
        $response->assertOk();
        $response->assertSee('+14155550200');
        $response->assertSee('Business details');
    }

    // -----------------------------------------------------------------
    // PR #295 Correction Round 1, item 2 — server-side candidate
    // anti-tamper.
    // -----------------------------------------------------------------

    public function test_a_tampered_candidate_token_is_refused_and_orders_nothing(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        $fake->queueSearchResult(new \App\Library\Messaging\DTO\AvailableNumberCandidate('+14155550210', \App\Enums\Messaging\PhoneNumberType::Local, 'candidate-ref-real'));

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer);

        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), [
            'number_type' => 'local',
        ])->assertOk();
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        // A tampered/forged token (garbage ciphertext) must never decrypt
        // to a usable candidate.
        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), [
            'candidate_token' => $candidateToken . 'tampered',
        ])->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_numbers', 0);
        $this->assertSame([], $fake->provisionedOrders, 'A tampered token must never reach the provider.');
    }

    public function test_a_candidate_token_issued_for_a_different_business_is_refused(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        $fake->queueSearchResult(new \App\Library\Messaging\DTO\AvailableNumberCandidate('+14155550211', \App\Enums\Messaging\PhoneNumberType::Local, 'candidate-ref-real-2'));

        [$customerA, $businessA, $workspaceA] = $this->tenant(WorkspacePlanTier::Growth, 'Business A', 'Workspace A');
        [, $businessB, $workspaceB] = $this->tenant(WorkspacePlanTier::Growth, 'Business B', 'Workspace B');
        $this->authenticateAs($customerA);

        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspaceA->uid, $businessA->uid]), [
            'number_type' => 'local',
        ])->assertOk();
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        // Business A's own token must never order a number for Business B,
        // even though A's customer cannot even reach B's tenancy normally.
        $token = \App\Library\Messaging\CandidateToken::decode($candidateToken, $businessA);
        $tokenForB = \App\Library\Messaging\CandidateToken::encode($businessB, $token);

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspaceA->uid, $businessA->uid]), [
            'candidate_token' => $tokenForB,
        ])->assertSessionHas('status', 'error');

        $this->assertDatabaseCount('business_messaging_numbers', 0);
    }

    private function extractCandidateToken(string $html): string
    {
        preg_match('/name="candidate_token" value="([^"]+)"/', $html, $matches);

        return $matches[1] ?? '';
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
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);

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

    // -----------------------------------------------------------------
    // PR #295 Correction Round 1, item 1 — authorization: mutating,
    // cost-incurring actions require buy_numbers / manage_advanced_provider,
    // never the read-only view_numbers alone.
    // -----------------------------------------------------------------

    public function test_a_view_numbers_only_user_can_view_the_page_but_not_search_or_order_a_number(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer, ['view_numbers']);

        // This application's AuthorizationException handling (app/Exceptions/Handler.php)
        // renders every unhandled permission denial as 401, not 403/404 —
        // matching how the rest of this app's permission-denial tests
        // assert it.
        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk();
        $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'local'])
            ->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), ['candidate_token' => 'irrelevant'])
            ->assertStatus(401);

        $this->assertDatabaseCount('business_messaging_numbers', 0);
    }

    public function test_a_buy_numbers_user_can_order_a_number(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        $fake->queueSearchResult(new AvailableNumberCandidate('+14155550220', \App\Enums\Messaging\PhoneNumberType::Local, 'candidate-ref-buy-numbers'));

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->authenticateAs($customer, ['view_numbers', 'buy_numbers']);

        $searchResponse = $this->post(route('customer.workspaces.businesses.text-messaging.number.search', [$workspace->uid, $business->uid]), ['number_type' => 'local'])->assertOk();
        $candidateToken = $this->extractCandidateToken($searchResponse->getContent());

        $this->post(route('customer.workspaces.businesses.text-messaging.number.order', [$workspace->uid, $business->uid]), ['candidate_token' => $candidateToken])
            ->assertSessionHas('status', 'success');

        $this->assertDatabaseHas('business_messaging_numbers', ['phone_number' => '+14155550220']);
    }

    public function test_a_view_numbers_only_user_can_view_registration_but_not_update_or_submit_it(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550310');
        $this->authenticateAs($customer, ['view_numbers']);

        $this->get(route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]))->assertOk();
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertStatus(401);
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertStatus(401);

        $this->assertDatabaseMissing('business_messaging_registrations', ['business_id' => $business->id]);
    }

    public function test_a_manage_advanced_provider_user_can_update_and_submit_registration(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550311');
        $this->authenticateAs($customer, ['view_numbers', 'manage_advanced_provider']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload())
            ->assertSessionHas('status', 'success');
        $this->post(route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]))
            ->assertSessionHas('status', 'success');

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->status);
    }

    // -----------------------------------------------------------------
    // PR #295 Correction Round 1, item 8 — an Approved registration is
    // immutable through the normal customer edit route, proven via a
    // direct POST rather than only through hidden UI.
    // -----------------------------------------------------------------

    public function test_a_direct_post_cannot_edit_an_already_approved_registration(): void
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth);
        $this->attachNumber($this->attachIdentity($business), '+14155550410');
        $approved = BusinessMessagingRegistration::create(array_merge($this->registrationPayload(), [
            'business_id' => $business->id,
            'number_type' => 'local',
            'status' => 'approved',
            'approved_at' => now(),
        ]));
        $this->authenticateAs($customer, ['view_numbers', 'manage_advanced_provider']);

        $this->post(route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]), $this->registrationPayload([
            'legal_business_name' => 'A Different Name Entirely LLC',
        ]))->assertSessionHas('status', 'error');

        $this->assertSame('Harbor Lane Studios LLC', $approved->fresh()->legal_business_name);
    }
}
