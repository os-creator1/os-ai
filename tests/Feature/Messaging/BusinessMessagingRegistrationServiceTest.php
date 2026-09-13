<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Text messaging setup/number/compliance hub — STATE 2's capture/submit/
 * refresh lifecycle. Capturing details never contacts the provider;
 * submitting never re-asks for data the customer already gave; refreshing
 * only ever moves the status to what the provider actually reports.
 */
class BusinessMessagingRegistrationServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    private function bindFakeProvisioningAdapter(): FakeProvisioningAdapter
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);

        return $fake;
    }

    private function service(): BusinessMessagingRegistrationService
    {
        return new BusinessMessagingRegistrationService();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'number_type' => 'local',
            'legal_business_name' => 'Harbor Lane Studios LLC',
            'entity_type' => 'ein',
            'ein' => '12-3456789',
            'address_line_1' => '1 Harbor Lane',
            'city' => 'Portland',
            'region' => 'OR',
            'postal_code' => '97201',
            'country_code' => 'US',
            'website_url' => 'https://harborlane.example',
            'contact_email' => 'owner@harborlane.example',
            'contact_phone' => '+15035550100',
            'use_case' => 'appointment_reminders',
            'opt_in_method' => 'Customers check a box on our booking form.',
            'sample_message_1' => 'Harbor Lane: your appointment is confirmed for Tuesday. Reply STOP to unsubscribe.',
            'sample_message_2' => 'Harbor Lane: reminder, your appointment is tomorrow at 2pm.',
            'privacy_policy_url' => 'https://harborlane.example/privacy',
            'terms_url' => 'https://harborlane.example/terms',
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // Capture — idempotent per Business, no provider call.
    // -----------------------------------------------------------------

    public function test_capturing_details_creates_one_row_per_business(): void
    {
        $business = $this->makeBusiness();

        $registration = $this->service()->captureDetails($business, $this->payload());

        $this->assertSame($business->id, $registration->business_id);
        $this->assertSame('Harbor Lane Studios LLC', $registration->legal_business_name);
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->status);
        $this->assertSame(1, BusinessMessagingRegistration::where('business_id', $business->id)->count());
    }

    public function test_capturing_details_again_updates_the_same_row_never_creates_a_second(): void
    {
        $business = $this->makeBusiness();
        $this->service()->captureDetails($business, $this->payload());

        $this->service()->captureDetails($business, $this->payload(['legal_business_name' => 'Harbor Lane Studios, Corrected LLC']));

        $this->assertSame(1, BusinessMessagingRegistration::where('business_id', $business->id)->count());
        $this->assertSame('Harbor Lane Studios, Corrected LLC', BusinessMessagingRegistration::where('business_id', $business->id)->first()->legal_business_name);
    }

    public function test_editing_a_rejected_registration_clears_the_rejection(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());
        $registration->update(['status' => MessagingRegistrationStatus::Rejected->value, 'rejection_reason' => 'Missing a valid website.']);

        $updated = $this->service()->captureDetails($business, $this->payload(['website_url' => 'https://harborlane.example/home']));

        $this->assertSame(MessagingRegistrationStatus::NotStarted, $updated->status);
        $this->assertNull($updated->rejection_reason);
    }

    public function test_capturing_details_never_contacts_the_provider(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();

        $this->service()->captureDetails($business, $this->payload());

        $this->assertSame([], $fake->submittedRegistrations);
    }

    // -----------------------------------------------------------------
    // Submit — sends exactly the captured data, realistically Pending.
    // -----------------------------------------------------------------

    public function test_submitting_sends_the_captured_fields_and_records_the_providers_pending_answer(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());

        $submitted = $this->service()->submit($registration);

        $this->assertCount(1, $fake->submittedRegistrations);
        $this->assertSame('Harbor Lane Studios LLC', $fake->submittedRegistrations[0]->legalBusinessName);
        $this->assertSame(MessagingRegistrationStatus::Pending, $submitted->status);
        $this->assertNotNull($submitted->provider_brand_id);
        $this->assertNotNull($submitted->provider_campaign_id);
        $this->assertNotNull($submitted->submitted_at);
    }

    public function test_submitting_a_toll_free_registration_still_produces_both_opaque_references(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload(['number_type' => 'toll_free']));

        $submitted = $this->service()->submit($registration);

        $this->assertNotNull($submitted->provider_brand_id);
        $this->assertNotNull($submitted->provider_campaign_id);
    }

    public function test_submitting_when_not_configured_throws_and_does_not_change_status(): void
    {
        config(['messaging.managed_messaging_enabled' => false]);
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());

        try {
            $this->service()->submit($registration);
            $this->fail('Expected MessagingProviderNotConfiguredException.');
        } catch (MessagingProviderNotConfiguredException) {
            // expected
        }

        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Refresh — only ever moves to what the provider actually reports.
    // -----------------------------------------------------------------

    public function test_refresh_applies_an_approved_answer_and_stamps_approved_at(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->service()->submit($this->service()->captureDetails($business, $this->payload()));
        $fake->scriptRegistrationStatus($registration->provider_brand_id, $registration->provider_campaign_id, MessagingRegistrationStatus::Approved);

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::Approved, $refreshed->status);
        $this->assertNotNull($refreshed->approved_at);
        $this->assertTrue($refreshed->isApproved());
    }

    public function test_refresh_applies_a_rejected_answer_and_stamps_rejected_at(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->service()->submit($this->service()->captureDetails($business, $this->payload()));
        $fake->scriptRegistrationStatus($registration->provider_brand_id, $registration->provider_campaign_id, MessagingRegistrationStatus::Rejected);

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::Rejected, $refreshed->status);
        $this->assertNotNull($refreshed->rejected_at);
    }

    public function test_refresh_without_a_prior_submission_is_a_no_op(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->payload());

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::NotStarted, $refreshed->status);
    }

    public function test_refresh_when_not_configured_never_throws_and_leaves_status_unchanged(): void
    {
        $fake = $this->bindFakeProvisioningAdapter();
        $business = $this->makeBusiness();
        $registration = $this->service()->submit($this->service()->captureDetails($business, $this->payload()));
        config(['messaging.managed_messaging_enabled' => false]);

        $refreshed = $this->service()->refreshStatus($registration);

        $this->assertSame(MessagingRegistrationStatus::Pending, $refreshed->status);
    }
}
