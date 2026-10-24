<?php

namespace Tests\Feature\Business;

use App\Enums\Business\BusinessIndustry;
use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Messaging\Registration\RegistrationTemplateCatalog;
use App\Models\BusinessLocation;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\Feature\Workspace\Concerns\CreatesCustomerContextFixtures;
use Tests\TestCase;

/**
 * Niche-aware registration defaults and pre-submission validation. Never
 * submits to a real provider: every submit runs against the fake adapter.
 */
class TextMessagingRegistrationDefaultsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCustomerContextFixtures;
    use CreatesMessagingFixtures;

    private function registrationState(): array
    {
        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Sunset Photo Booths');
        $this->attachNumber($this->attachIdentity($business), '+14155550400');
        $this->authenticateAs($customer);

        return [$business, $workspace];
    }

    private function showUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.text-messaging.show', [$workspace->uid, $business->uid]);
    }

    private function updateUrl($workspace, $business): string
    {
        return route('customer.workspaces.businesses.text-messaging.registration.update', [$workspace->uid, $business->uid]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'legal_business_name' => 'Sunset Photo Booths LLC',
            'entity_type' => 'ein',
            'ein' => '12-3456789',
            'address_line_1' => '9 Sunset Ave',
            'city' => 'Austin',
            'region' => 'TX',
            'postal_code' => '78701',
            'website_url' => 'https://sunsetbooths.example',
            'contact_email' => 'hi@sunsetbooths.example',
            'contact_phone' => '+15125550100',
            'use_case' => 'customer_care',
            'campaign_description' => 'Sunset Photo Booths sends quote follow-ups and event reminders to customers who inquired.',
            'opt_in_method' => 'Customers tick an optional SMS box on https://sunsetbooths.example/contact.',
            'sample_message_1' => 'Sunset Photo Booths: thanks for your inquiry, your quote is on its way. Reply STOP to opt out.',
            'sample_message_2' => 'Sunset Photo Booths: reminder that your event is this weekend. Reply STOP to opt out.',
            'privacy_policy_url' => 'https://sunsetbooths.example/privacy',
            'terms_url' => 'https://sunsetbooths.example/sms-terms',
            'consent_confirmations' => array_keys(RegistrationTemplateCatalog::CONSENT_ITEMS),
        ], $overrides);
    }

    public function test_photo_booth_niche_prefills_from_onboarding_without_inventing_identity(): void
    {
        [$business, $workspace] = $this->registrationState();
        $business->forceFill([
            'industry' => BusinessIndustry::PhotoBoothService->value,
            'website_url' => 'https://sunsetbooths.example',
            'email' => 'hi@sunsetbooths.example',
            'phone' => '+15125550100',
        ])->save();
        BusinessLocation::create([
            'business_id' => $business->id, 'name' => 'Primary Location', 'service_mode' => 'storefront',
            'address_line_1' => '9 Sunset Ave', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701',
            'country_code' => 'US', 'is_primary' => true,
        ]);
        BusinessMessagingRegistration::create([
            'business_id' => $business->id, 'number_type' => 'local', 'status' => 'not_started', 'use_case' => 'customer_care',
        ]);

        $html = $this->get($this->showUrl($workspace, $business))->assertOk()->getContent();

        $this->assertStringContainsString('value="Sunset Photo Booths"', $html);
        $this->assertStringContainsString('value="9 Sunset Ave"', $html);
        $this->assertStringContainsString('value="hi@sunsetbooths.example"', $html);
        $this->assertStringContainsString('Sunset Photo Booths sends customer-care texts to people who asked about photo booth rental', $html);
        $this->assertStringContainsString('Booking confirmations', $html);
        // Marketing copy and invented identity never appear for a care-only choice.
        $this->assertStringNotContainsString('photo booth package promotions', $html);
        $this->assertMatchesRegularExpression('/name="ein"[^>]*value=""/', $html);
        $this->assertMatchesRegularExpression('/name="privacy_policy_url"[^>]*value=""/', $html);
        $this->assertStringContainsString('needs your confirmation', $html);
    }

    public function test_three_plain_choices_map_to_supported_use_cases(): void
    {
        $this->assertSame('customer_care', RegistrationTemplateCatalog::useCaseForChoice('care'));
        $this->assertSame('marketing', RegistrationTemplateCatalog::useCaseForChoice('marketing'));
        $this->assertSame('mixed', RegistrationTemplateCatalog::useCaseForChoice('both'));
        $this->assertCount(3, RegistrationTemplateCatalog::CHOICES);

        $marketing = RegistrationTemplateCatalog::forUseCase('photo_booth_service', 'marketing', 'Sunset Photo Booths', null);
        $care = RegistrationTemplateCatalog::forUseCase('photo_booth_service', 'customer_care', 'Sunset Photo Booths', null);
        $this->assertContains('Seasonal specials', $marketing['topics']);
        $this->assertNotContains('Seasonal specials', $care['topics']);
        // A legacy use case outside the three choices gets no suggested copy.
        $this->assertNull(RegistrationTemplateCatalog::forUseCase('photo_booth_service', 'two_factor', 'X', null));
        $this->assertNull(RegistrationTemplateCatalog::forUseCase('photo_booth_service', null, 'X', null));

        [$business, $workspace] = $this->registrationState();
        $this->get($this->showUrl($workspace, $business))
            ->assertSee('Service and customer-care messages')
            ->assertSee('Promotional and marketing messages')
            ->assertDontSee('A mix of the above');
    }

    public function test_user_edits_survive_later_page_loads_and_use_case_changes(): void
    {
        [$business, $workspace] = $this->registrationState();
        $business->forceFill(['industry' => BusinessIndustry::PhotoBoothService->value])->save();

        $edited = 'Sunset Photo Booths: my own hand-edited sample. Reply STOP to opt out.';
        $this->post($this->updateUrl($workspace, $business), $this->payload(['sample_message_1' => $edited]))
            ->assertSessionHas('status', 'success');

        $this->get($this->showUrl($workspace, $business))->assertSee($edited, false);

        // Re-saving with a different choice keeps the customer's own copy.
        $this->post($this->updateUrl($workspace, $business), $this->payload(['use_case' => 'mixed', 'sample_message_1' => $edited]));
        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame($edited, $registration->sample_message_1);
        $this->assertSame('mixed', $registration->use_case);
        $this->get($this->showUrl($workspace, $business))->assertSee($edited, false);
    }

    public function test_missing_consent_or_invalid_data_blocks_submission_without_charging(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        [$business, $workspace] = $this->registrationState();
        $submit = route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]);

        $this->post($this->updateUrl($workspace, $business), $this->payload([
            'consent_confirmations' => ['stop_help'],
            'sample_message_1' => 'Sunset Photo Booths: 20% off all packages this month! Reply STOP to opt out.',
            'privacy_policy_url' => 'https://sunsetbooths.example/terms',
            'terms_url' => 'https://sunsetbooths.example/terms',
            'campaign_description' => 'Sunset Photo Booths sends [describe your messages] to customers.',
        ]));

        $this->post($submit)->assertSessionHas('status', 'error');

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::NotStarted, $registration->status);
        $this->assertNull($registration->provider_brand_id);

        $page = $this->get($this->showUrl($workspace, $business))->getContent();
        $this->assertStringContainsString('Before you submit', $page);
        $this->assertStringContainsString('Confirm on your website, then check', $page);
        $this->assertStringContainsString('reads as a promotion', $page);
        $this->assertStringContainsString('separate pages', $page);
        $this->assertStringContainsString('placeholder text', $page);
    }

    public function test_a_complete_registration_submits_once_and_a_repeat_submit_is_refused(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        config(['messaging.managed_messaging_enabled' => true, 'messaging.managed_messaging_provisioning_enabled' => true, 'services.telnyx.api_key' => 'fixture_key']);
        [$business, $workspace] = $this->registrationState();
        $submit = route('customer.workspaces.businesses.text-messaging.registration.submit', [$workspace->uid, $business->uid]);

        $this->post($this->updateUrl($workspace, $business), $this->payload());
        $this->post($submit)->assertSessionHas('status', 'success');

        $registration = BusinessMessagingRegistration::where('business_id', $business->id)->first();
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->status);
        $brandId = $registration->provider_brand_id;

        $this->post($submit)->assertSessionHas('status', 'error');
        $this->assertSame($brandId, $registration->fresh()->provider_brand_id);
        $this->assertSame(MessagingRegistrationStatus::Pending, $registration->fresh()->status);
    }
}
