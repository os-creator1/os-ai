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
            'doing_business_as' => 'Kaleb Hill Photo Booth',
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
            'Add a public link to your opt-in page or a screenshot of your opt-in form (the carrier requires opt-in evidence, and it must start with https://).',
            $this->issues($this->registration(['opt_in_image_url' => null])),
        );
        $this->assertContains(
            'Add a public link to your opt-in page or a screenshot of your opt-in form (the carrier requires opt-in evidence, and it must start with https://).',
            $this->issues($this->registration(['opt_in_image_url' => 'not a url'])),
        );
        $this->assertNotEmpty($this->issues($this->registration(['consent_confirmations' => []])));
    }

    public function test_toll_free_sample_one_must_carry_the_stated_dba_which_may_differ_from_the_legal_name(): void
    {
        // Legal name and DBA differ; the DBA-branded samples pass (no first-two-words legal-name rule).
        $this->assertSame([], $this->issues($this->registration()));

        // An unrelated sender name is refused for sample 1 and flagged on sample 2.
        $issues = $this->issues($this->registration([
            'sample_message_1' => 'Acme Party Rentals: your booking is confirmed. Reply STOP to opt out.',
            'sample_message_2' => 'Acme Party Rentals: reminder, your event is this weekend. Reply STOP to opt out.',
        ]));
        $this->assertStringContainsString('Sample message 1 must include "Kaleb Hill Photo Booth"', implode(' ', $issues));
        $this->assertStringContainsString('Sample message 2 should also include "Kaleb Hill Photo Booth"', implode(' ', $issues));

        // With no DBA stated, the registered legal name is the brand (entity suffix and punctuation ignored).
        $this->assertSame([], $this->issues($this->registration([
            'doing_business_as' => null,
            'sample_message_1' => 'Spartan Security Force: your booking is confirmed. Reply STOP to opt out.',
            'sample_message_2' => 'Spartan Security Force, LLC: reminder, your event is this weekend. Reply STOP to opt out.',
        ])));
    }

    public function test_a_branded_sample_two_can_never_satisfy_the_transmitted_sample_one(): void
    {
        $issues = $this->issues($this->registration([
            'sample_message_1' => 'Your booking is confirmed for Saturday. Reply STOP to opt out.',
            'sample_message_2' => 'Kaleb Hill Photo Booth: reminder, your event is this weekend. Reply STOP to opt out.',
        ]));

        $this->assertCount(1, $issues);
        $this->assertStringContainsString('Sample message 1 must include "Kaleb Hill Photo Booth"', $issues[0]);
    }

    public function test_toll_free_sample_one_needs_opt_out_wording(): void
    {
        $issues = $this->issues($this->registration([
            'sample_message_1' => 'Kaleb Hill Photo Booth: your booking is confirmed for Saturday.',
        ]));

        $this->assertSame(['Sample message 1 needs opt-out wording such as "Reply STOP to opt out".'], $issues);
    }

    public function test_customer_care_wording_with_special_occasions_or_save_the_date_is_not_marketing(): void
    {
        $this->assertSame([], $this->issues($this->registration([
            'campaign_description' => 'Kaleb Hill Photo Booth texts booking confirmations and reminders for weddings and other special occasions. Save the date notes are sent to existing clients only.',
            'sample_message_2' => 'Kaleb Hill Photo Booth: save the date, your special occasion is this weekend. Reply STOP to opt out.',
        ])));
    }

    public function test_toll_free_text_fields_are_validated_against_telnyxs_published_maximums_without_trimming(): void
    {
        $brand = 'Kaleb Hill Photo Booth sends booking confirmations. ';
        $atLimit = $brand . str_repeat('a', 500 - mb_strlen($brand));
        $this->assertSame(500, mb_strlen($atLimit));
        $this->assertSame([], $this->issues($this->registration(['campaign_description' => $atLimit, 'opt_in_method' => str_repeat('b', 500)])));

        $issues = $this->issues($this->registration(['campaign_description' => $atLimit . 'a', 'opt_in_method' => str_repeat('b', 501)]));
        $this->assertCount(2, $issues);
        $this->assertStringContainsString('Your campaign description is 501 characters; toll-free verification accepts at most 500', $issues[0]);
        $this->assertStringContainsString('message-flow (how customers opt in) description is 501 characters', $issues[1]);

        // Whitespace is part of the outgoing value: 500 characters plus a trailing space is 501.
        $padded = $this->issues($this->registration(['campaign_description' => $atLimit . ' ']));
        $this->assertStringContainsString('Your campaign description is 501 characters', implode(' ', $padded));

        // The same text is fine for a non-toll-free (10DLC) registration: the limit is Telnyx's toll-free schema's.
        $this->assertStringNotContainsString('accepts at most 500', implode(' ', $this->issues($this->registration([
            'number_type' => 'local', 'campaign_description' => $atLimit . 'a',
        ]))));
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

    public function test_opt_in_evidence_accepts_public_pages_and_images_over_http_but_rejects_malformed_links(): void
    {
        foreach (['https://i.imgur.com/AbC123.png', 'https://imgur.com/gallery/verify-Pq0u7sX#GDFfzGQ', 'https://kalebhillphotobooth.com/checkavailability', 'http://example-photo.test/optin'] as $ok) {
            $this->assertSame([], $this->issues($this->registration(['opt_in_image_url' => $ok])), $ok);
        }

        foreach (['ftp://example-photo.test/optin.png', 'javascript://example.com/%0Aalert(1)', 'not a url', ''] as $bad) {
            $issues = $this->issues($this->registration(['opt_in_image_url' => $bad]));
            $this->assertCount(1, $issues, $bad);
            $this->assertStringContainsString('opt-in', $issues[0], $bad);
        }
    }

    public function test_a_stated_dba_can_brand_the_samples_but_an_unrelated_name_cannot(): void
    {
        $dbaSamples = [
            'sample_message_1' => 'Moonlight Booths: your booking is confirmed. Reply STOP to opt out.',
            'sample_message_2' => 'Moonlight Booths: reminder, your event is this weekend. Reply STOP to opt out.',
        ];

        $this->assertNotEmpty($this->issues($this->registration($dbaSamples)));
        $this->assertSame([], $this->issues($this->registration($dbaSamples + ['doing_business_as' => 'Moonlight Booths'])));
    }

    public function test_toll_free_customer_care_does_not_need_local_10dlc_fields(): void
    {
        // No brand vertical, support contact or brand id is required for toll-free.
        $this->assertSame([], $this->issues($this->registration(['brand_vertical' => null, 'support_contact' => null, 'provider_brand_id' => null])));
    }

    public function test_each_missing_consent_confirmation_is_named_exactly(): void
    {
        $issues = $this->issues($this->registration(['consent_confirmations' => ['public_opt_in_url']]));
        $this->assertCount(count(RegistrationTemplateCatalog::CONSENT_ITEMS) - 1, $issues);
        $this->assertContains('Confirm on your website, then check: ' . RegistrationTemplateCatalog::CONSENT_ITEMS['stop_help'] . '.', $issues);
    }
}
