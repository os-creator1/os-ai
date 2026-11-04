<?php

namespace Tests\Feature\Messaging;

use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\ProvisioningPilot;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/** Real provisioning is closed by default and opens for explicitly listed Businesses only. */
class ProvisioningPilotTest extends TestCase
{
    use RefreshDatabase;
    use CreatesMessagingFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
            'messaging.provisioning_open_to_all' => false,
            'messaging.provisioning_pilot_business_ids' => '',
        ]);
    }

    private function registrationFor(): array
    {
        [$business, , $number] = $this->managedBusiness('+18885550142');
        $number->update(['number_type' => 'toll_free']);
        $registration = app(BusinessMessagingRegistrationService::class)->captureDetails($business, [
            'number_type' => 'toll_free', 'legal_business_name' => 'Harbor Lane Studios LLC', 'entity_type' => 'ein', 'ein' => '12-3456789',
            'address_line_1' => '1 Harbor Lane', 'city' => 'Portland', 'region' => 'OR', 'postal_code' => '97201', 'country_code' => 'US',
            'website_url' => 'https://harborlane.example', 'contact_email' => 'owner@harborlane.example', 'contact_phone' => '+15035550100',
            'use_case' => 'customer_care', 'campaign_description' => 'Harbor Lane sends appointment reminders.',
            'opt_in_method' => 'Customers tick an optional SMS box on our booking form.',
            'sample_message_1' => 'Harbor Lane: your appointment is confirmed. Reply STOP to opt out.',
            'sample_message_2' => 'Harbor Lane: reminder, your appointment is tomorrow. Reply STOP to opt out.',
            'privacy_policy_url' => 'https://harborlane.example/privacy', 'terms_url' => 'https://harborlane.example/terms', 'support_contact' => 'help@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'help@fixture.example', 'support_contact_confirmed_at' => '2026-01-01 00:00:00',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000', 'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
        ]);

        return [$business, $registration];
    }

    public function test_provisioning_is_closed_by_default(): void
    {
        $this->assertFalse(config('messaging.provisioning_open_to_all'));
        $this->assertFalse(ProvisioningPilot::allows(1));
    }

    public function test_only_listed_business_ids_are_allowed(): void
    {
        config(['messaging.provisioning_pilot_business_ids' => ' 7, 12 ,x,']);

        $this->assertTrue(ProvisioningPilot::allows(7));
        $this->assertTrue(ProvisioningPilot::allows(12));
        $this->assertFalse(ProvisioningPilot::allows(1));
        $this->assertFalse(ProvisioningPilot::allows(70));
    }

    public function test_a_non_pilot_business_cannot_submit_and_no_carrier_call_is_made(): void
    {
        [, $registration] = $this->registrationFor();
        Http::fake();

        $this->expectException(MessagingProviderNotConfiguredException::class);

        try {
            app(BusinessMessagingRegistrationService::class)->submit($registration);
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_listed_pilot_business_submits_one_toll_free_verification(): void
    {
        [$business, $registration] = $this->registrationFor();
        config(['messaging.provisioning_pilot_business_ids' => (string) $business->id]);
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['id' => 'tfv_pilot', 'verificationStatus' => 'In Progress'])]);

        $submitted = app(BusinessMessagingRegistrationService::class)->submit($registration);

        Http::assertSentCount(1);
        $this->assertSame('tfv_pilot', $submitted->provider_registration_id);
    }
}
