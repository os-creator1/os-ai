<?php

namespace Tests\Unit\Messaging;

use App\Library\Messaging\Registration\RegistrationSubmissionValidator;
use App\Library\Messaging\Registration\RegistrationTemplateCatalog;
use App\Models\Business;
use App\Models\BusinessMessagingRegistration;
use Tests\TestCase;

/**
 * Pure validator rules — no database. A toll-free customer-care registration
 * is the baseline; each test changes one thing and asserts the exact message.
 */
class RegistrationSubmissionValidatorTest extends TestCase
{
    private function registration(array $overrides = [], string $businessName = 'Kaleb Hill Photography'): BusinessMessagingRegistration
    {
        $registration = (new BusinessMessagingRegistration())->forceFill(array_merge([
            'number_type' => 'toll_free',
            'status' => 'not_started',
            'entity_type' => 'ein',
            'ein' => '12-3456789',
            'legal_business_name' => 'Spartan Security Force, LLC',
            'address_line_1' => '1 Main St', 'city' => 'Austin', 'region' => 'TX', 'postal_code' => '78701',
            'website_url' => 'https://example-photo.test',
            'contact_email' => 'owner@example-photo.test', 'contact_phone' => '+15125550100',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner',
            'use_case' => 'customer_care',
            'message_volume' => '1,000',
            'opt_in_image_url' => 'https://example-photo.test/opt-in.png',
            'campaign_description' => 'Kaleb Hill Photo Booth sends booking confirmations and event reminders. We do not send promotional messages.',
            'opt_in_method' => 'Customers tick an optional SMS box on https://example-photo.test/contact when booking.',
            'sample_message_1' => 'Kaleb Hill Photo Booth: your booking is confirmed for Sat 5pm. Reply STOP to opt out.',
            'sample_message_2' => 'Kaleb Hill Photo Booth: reminder, your event is this weekend. Reply STOP to opt out.',
            'privacy_policy_url' => 'https://example-photo.test/privacy',
            'terms_url' => 'https://example-photo.test/sms-terms',
            'consent_confirmations' => array_keys(RegistrationTemplateCatalog::CONSENT_ITEMS),
        ], $overrides));
        $registration->setRelation('business', (new Business())->forceFill(['name' => $businessName]));

        return $registration;
    }

    private function issues(BusinessMessagingRegistration $registration): array
    {
        return (new RegistrationSubmissionValidator())->issues($registration);
    }

    public function test_a_complete_customer_care_registration_with_a_dba_and_negated_promo_wording_passes(): void
    {
        $this->assertSame([], $this->issues($this->registration()));
    }

    public function test_missing_or_invalid_opt_in_evidence_is_still_refused(): void
    {
        $this->assertContains(
            'Add a link to a screenshot of your opt-in form (the carrier requires an image).',
            $this->issues($this->registration(['opt_in_image_url' => null])),
        );
        $this->assertContains(
            'Add a link to a screenshot of your opt-in form (the carrier requires an image).',
            $this->issues($this->registration(['opt_in_image_url' => 'not a url'])),
        );
        $this->assertNotEmpty($this->issues($this->registration(['consent_confirmations' => []])));
    }

    public function test_samples_must_name_the_legal_entity_or_the_business_name_on_file_and_nothing_else(): void
    {
        // Legal name "Spartan Security…" is absent, but the Business's own name on file ("Kaleb Hill…") is used.
        $this->assertSame([], $this->issues($this->registration()));

        // An unrelated sender name matches neither the legal name nor the Business name.
        $unrelated = $this->issues($this->registration([
            'sample_message_1' => 'Acme Party Rentals: your booking is confirmed. Reply STOP to opt out.',
            'sample_message_2' => 'Acme Party Rentals: reminder, your event is this weekend. Reply STOP to opt out.',
        ]));
        $this->assertCount(1, $unrelated);
        $this->assertStringContainsString('Include your business name in at least one sample message', $unrelated[0]);

        // The legal name itself still works.
        $this->assertSame([], $this->issues($this->registration([
            'sample_message_1' => 'Spartan Security Force: your booking is confirmed. Reply STOP to opt out.',
            'sample_message_2' => 'Spartan Security Force: reminder, your event is this weekend. Reply STOP to opt out.',
        ])));
    }

    public function test_negated_promotional_wording_is_not_classified_as_marketing(): void
    {
        foreach ([
            'We do not send promotional messages.',
            'No promotional or marketing messages are sent.',
            'This number never sends discounts, sales or deals.',
            "We don\u{2019}t send promotions.",
        ] as $sentence) {
            $this->assertSame([], $this->issues($this->registration([
                'campaign_description' => 'Kaleb Hill Photo Booth sends booking confirmations and event reminders. ' . $sentence,
            ])), $sentence);
        }
    }

    public function test_genuine_promotional_wording_in_a_care_only_campaign_is_still_refused(): void
    {
        $issues = $this->issues($this->registration([
            'campaign_description' => 'Kaleb Hill Photo Booth sends booking confirmations and monthly promotions.',
            'sample_message_1' => 'Kaleb Hill Photo Booth: 20% off all packages this month! Reply STOP to opt out.',
        ]));

        $this->assertStringContainsString('mentions promotions', implode(' ', $issues));
        $this->assertStringContainsString('reads as a promotion', implode(' ', $issues));
    }
}
