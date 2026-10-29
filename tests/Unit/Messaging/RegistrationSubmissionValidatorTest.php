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

    public function test_sample_message_1_must_carry_the_customer_facing_brand_not_the_first_words_of_the_legal_name(): void
    {
        // The stated DBA brands sample 1 even though it shares nothing with the legal entity.
        $this->assertSame([], $this->issues($this->registration()));

        // An unrelated sender name matches no DBA, Business name or legal name.
        $unrelated = $this->issues($this->registration([
            'sample_message_1' => 'Acme Party Rentals: your booking is confirmed. Reply STOP to opt out.',
        ]));
        $this->assertCount(1, $unrelated);
        $this->assertStringContainsString('Sample message 1 should name your business', $unrelated[0]);

        // Branding only sample 2 is not enough: sample 1 is what the carrier receives.
        $this->assertCount(1, $this->issues($this->registration([
            'sample_message_1' => 'Your booking is confirmed. Reply STOP to opt out.',
        ])));

        // A partial legal-name prefix is no longer accepted by itself ("Spartan Security").
        $this->assertCount(1, $this->issues($this->registration([
            'sample_message_1' => 'Spartan Security: your booking is confirmed. Reply STOP to opt out.',
        ])));

        // The full legal name (entity suffix ignored) and the Business name on file still work.
        foreach (['Spartan Security Force', 'Kaleb Hill Photography'] as $brand) {
            $this->assertSame([], $this->issues($this->registration([
                'doing_business_as' => null,
                'sample_message_1' => $brand . ': your booking is confirmed. Reply STOP to opt out.',
            ])), $brand);
        }
    }

    public function test_toll_free_text_fields_are_limited_to_500_characters_with_exact_messages(): void
    {
        $long = str_repeat('a', 501);
        $issues = $this->issues($this->registration([
            'campaign_description' => 'Kaleb Hill Photo Booth sends booking confirmations. ' . $long,
            'opt_in_method' => 'Customers tick an optional SMS box on https://example-photo.test/contact. ' . $long,
        ]));
        $this->assertCount(2, $issues);
        $this->assertStringContainsString('Shorten your campaign description to 500 characters or fewer', $issues[0]);
        $this->assertStringContainsString('Shorten your message-flow (how customers opt in) description to 500 characters or fewer', $issues[1]);

        // Exactly 500 is allowed.
        $this->assertSame([], $this->issues($this->registration([
            'campaign_description' => substr(str_repeat('Kaleb Hill Photo Booth sends booking confirmations and reminders. ', 12), 0, 500),
        ])));
    }

    public function test_special_occasions_and_save_the_date_are_customer_care_not_marketing_but_real_offers_are(): void
    {
        $this->assertSame([], $this->issues($this->registration([
            'campaign_description' => 'Kaleb Hill Photo Booth sends booking confirmations for weddings and other special occasions, and save the date reminders.',
            'sample_message_2' => 'Kaleb Hill Photo Booth: save the date for your special occasion on Saturday. Reply STOP to opt out.',
        ])));

        foreach (['Enjoy special offers this month', 'Weekend specials on every package', 'Save 20 on all packages', 'Big sale today', '20% off'] as $promo) {
            $this->assertNotEmpty($this->issues($this->registration([
                'campaign_description' => 'Kaleb Hill Photo Booth sends booking confirmations. ' . $promo . '.',
            ])), $promo);
        }
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
