<?php

namespace Tests\Feature\Messaging;

use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingRegistrationService;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\Exceptions\MessagingRegistrationAlreadySubmittedException;
use App\Library\Messaging\Exceptions\MessagingRegistrationNotReadyException;
use App\Library\Messaging\Exceptions\MessagingRegistrationProviderException;
use App\Library\Messaging\Exceptions\MessagingRegistrationRecoveryRequiredException;
use App\Library\Messaging\FakeProvisioningAdapter;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Models\BusinessMessagingRegistration;
use App\Models\Currency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Toll-free verification is $0 through Telnyx: it needs no billing meter,
 * never touches the wallet, is still submitted to the carrier, and can
 * never be submitted (or charged for anything) twice. Local 10DLC billing
 * stays separate.
 */
class TollFreeVerificationFlowTest extends TestCase
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
        ]);
    }

    private function details(array $overrides = []): array
    {
        return array_merge([
            'number_type' => 'toll_free', 'legal_business_name' => 'Harbor Lane Studios LLC', 'entity_type' => 'ein', 'ein' => '12-3456789',
            'address_line_1' => '1 Harbor Lane', 'city' => 'Portland', 'region' => 'OR', 'postal_code' => '97201', 'country_code' => 'US',
            'website_url' => 'https://harborlane.example', 'contact_email' => 'owner@harborlane.example', 'contact_phone' => '+15035550100',
            'use_case' => 'customer_care', 'campaign_description' => 'Harbor Lane sends appointment reminders.',
            'opt_in_method' => 'Customers tick an optional SMS box on our booking form.',
            'sample_message_1' => 'Harbor Lane: your appointment is confirmed. Reply STOP to opt out.',
            'sample_message_2' => 'Harbor Lane: reminder, your appointment is tomorrow. Reply STOP to opt out.',
            'privacy_policy_url' => 'https://harborlane.example/privacy', 'terms_url' => 'https://harborlane.example/terms', 'support_contact' => 'help@fixture.example', 'support_contact_confirmed' => '1', 'support_contact_confirmed_for' => 'help@fixture.example', 'support_contact_confirmed_at' => '2026-01-01 00:00:00',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000', 'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
        ], $overrides);
    }

    private function service(): BusinessMessagingRegistrationService
    {
        return app(BusinessMessagingRegistrationService::class);
    }

    /** @return array{0: \App\Models\Business, 1: BusinessMessagingRegistration} */
    private function tollFreeBusiness(string $registrationType = 'toll_free'): array
    {
        [$business, , $number] = $this->managedBusiness('+18885550142');
        $number->update(['number_type' => 'toll_free']);

        return [$business, $this->service()->captureDetails($business, $this->details(['number_type' => $registrationType]))];
    }

    private function tables(): array
    {
        return [
            'meters' => DB::table('usage_meters')->count(),
            'wallets' => DB::table('business_usage_wallets')->count(),
            'reservations' => DB::table('business_usage_reservations')->count(),
            'ledger' => DB::table('business_usage_ledger_entries')->count(),
        ];
    }

    public function test_toll_free_verification_is_submitted_with_no_meter_no_wallet_and_no_charge(): void
    {
        [$business, $registration] = $this->tollFreeBusiness();
        $before = $this->tables();
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_1']])]);

        $submitted = $this->service()->submit($registration);

        Http::assertSentCount(1);
        $this->assertSame(MessagingRegistrationStatus::Pending, $submitted->status);
        $this->assertSame('tfv_1', $submitted->provider_registration_id);
        $this->assertSame($before, $this->tables());
    }

    public function test_a_repeat_submit_never_sends_a_second_verification(): void
    {
        [, $registration] = $this->tollFreeBusiness();
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_2']])]);

        $this->service()->submit($registration);

        try {
            $this->service()->submit($registration);
            $this->fail('Expected refusal.');
        } catch (MessagingRegistrationAlreadySubmittedException) {
            // expected
        }

        Http::assertSentCount(1);
    }

    public function test_a_crash_after_provider_acceptance_reuses_the_saved_request_instead_of_resubmitting(): void
    {
        [, $registration] = $this->tollFreeBusiness();
        // State left by a crash after Telnyx accepted the request and it was
        // saved, but before the Pending status write.
        $registration->update(['provider_registration_id' => 'tfv_crash']);
        Http::fake();

        $submitted = $this->service()->submit($registration);

        Http::assertNothingSent();
        $this->assertSame('tfv_crash', $submitted->provider_registration_id);
        $this->assertSame(MessagingRegistrationStatus::Pending, $submitted->status);
    }

    public function test_an_unconfirmed_outcome_blocks_blind_resubmission_with_a_recorded_incident(): void
    {
        [$business, $registration] = $this->tollFreeBusiness();
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response([], 503)]);

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->service()->submit($registration);
                $this->fail('Expected recovery-required.');
            } catch (MessagingRegistrationRecoveryRequiredException) {
                // expected
            }
        }

        Http::assertSentCount(1);
        $this->assertSame(1, DB::table('business_messaging_provisioning_incidents')
            ->where('business_id', $business->id)->where('stage', TelnyxProvisioningAdapter::INCIDENT_TOLLFREE_UNKNOWN)->whereNull('resolved_at')->count());
    }

    public function test_a_local_registration_saved_before_a_toll_free_number_was_bought_is_submitted_as_toll_free_with_data_preserved(): void
    {
        $fake = new FakeProvisioningAdapter();
        $this->app->instance(MessagingProvisioningAdapter::class, $fake);
        [$business, $registration] = $this->tollFreeBusiness('local');
        $registration->update(['provider_brand_id' => 'brand_from_local_attempt']);

        $submitted = $this->service()->submit($registration);

        $this->assertCount(1, $fake->submittedRegistrations);
        $this->assertSame(PhoneNumberType::TollFree, $fake->submittedRegistrations[0]->numberType);
        $this->assertSame('+18885550142', $fake->submittedRegistrations[0]->phoneNumber);
        $this->assertNull($fake->submittedRegistrations[0]->existingBrandId);
        $this->assertSame('Harbor Lane Studios LLC', $submitted->legal_business_name);
        $this->assertSame('12-3456789', $submitted->ein);
        // The earlier local attempt's brand reference is kept, never deleted.
        $this->assertSame('brand_from_local_attempt', $submitted->provider_brand_id);
        $this->assertSame(PhoneNumberType::TollFree, $submitted->number_type);
    }

    public function test_switching_the_number_type_keeps_captured_data_and_every_provider_reference(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->details(['number_type' => 'local']));
        $registration->update(['provider_brand_id' => 'brand_partial']);

        $switched = $this->service()->captureDetails($business, ['number_type' => 'toll_free']);

        $this->assertSame(PhoneNumberType::TollFree, $switched->number_type);
        $this->assertSame('brand_partial', $switched->provider_brand_id);
        $this->assertSame('Harbor Lane Studios LLC', $switched->legal_business_name);
        $this->assertSame('customer_care', $switched->use_case);
    }

    public function test_local_billing_stays_isolated_a_local_submit_without_a_meter_never_succeeds_for_free(): void
    {
        $business = $this->makeBusiness();
        $registration = $this->service()->captureDetails($business, $this->details(['number_type' => 'local']));
        Http::fake();

        $this->expectException(\App\Library\Messaging\Exceptions\MessagingFundingUnavailableException::class);

        try {
            $this->service()->submit($registration);
        } finally {
            Http::assertNothingSent();
        }
    }

    private function incidents(): int
    {
        return DB::table('business_messaging_provisioning_incidents')->count();
    }

    public function test_a_definitive_4xx_rejection_is_a_provider_rejection_logged_with_codes_and_field_names_only(): void
    {
        [$business, $registration] = $this->tollFreeBusiness();
        $before = $this->tables();
        Log::spy();
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['errors' => [[
            'code' => '10015', 'title' => 'Invalid',
            'detail' => 'businessName owner@harborlane.example 12-3456789 is not acceptable',
            'source' => ['pointer' => '/businessName'],
        ]]], 422)]);

        try {
            $this->service()->submit($registration);
            $this->fail('Expected a provider rejection.');
        } catch (MessagingRegistrationProviderException $e) {
            $this->assertSame(MessagingRegistrationProviderException::STEP_TOLL_FREE, $e->step);
        }

        Http::assertSentCount(1);
        $this->assertSame(0, $this->incidents());
        $this->assertNull($registration->fresh()->provider_registration_id);
        $this->assertNotSame(MessagingRegistrationStatus::Pending, $registration->fresh()->status);
        $this->assertSame($before, $this->tables());

        Log::shouldHaveReceived('warning')->withArgs(function (string $message, array $context = []) use ($business): bool {
            if ($message !== 'Telnyx toll-free verification submission rejected.') {
                return false;
            }

            $encoded = json_encode($context);

            return $context['business_id'] === (int) $business->id
                && $context['stage'] === 'toll_free_verification'
                && $context['http_status'] === 422
                && $context['provider_error_codes'] === ['10015']
                && $context['rejected_fields'] === ['/businessName']
                && ! str_contains($encoded, '12-3456789')
                && ! str_contains($encoded, 'owner@harborlane')
                && ! str_contains($encoded, 'fixture_api_key');
        })->once();
    }

    public function test_a_credentials_refusal_is_an_internal_error_not_a_carrier_rejection(): void
    {
        [, $registration] = $this->tollFreeBusiness();
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['errors' => [['code' => '10009']]], 401)]);

        try {
            $this->service()->submit($registration);
            $this->fail('Expected an internal refusal.');
        } catch (\RuntimeException $e) {
            $this->assertSame(\RuntimeException::class, $e::class);
        }

        $this->assertSame(0, $this->incidents());
    }

    public function test_a_local_preflight_failure_is_internal_sends_nothing_and_logs_field_names_only(): void
    {
        [, $registration] = $this->tollFreeBusiness();
        $registration->update(['opt_in_image_url' => null]);
        Log::spy();
        Http::fake();

        try {
            $this->service()->submit($registration->fresh());
            $this->fail('Expected a local preflight failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame(\RuntimeException::class, $e::class);
        }

        Http::assertNothingSent();
        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c = []) => $m === 'Toll-free verification blocked by local preflight.'
            && $c['missing_or_invalid'] === ['opt_in_image_url'])->once();
        $this->assertSame(0, $this->incidents());
    }

    public function test_over_length_free_text_is_refused_locally_before_anything_is_sent(): void
    {
        [, $registration] = $this->tollFreeBusiness();
        $registration->update(['campaign_description' => str_repeat('a', 501)]);
        Http::fake();

        $this->expectException(MessagingRegistrationNotReadyException::class);

        try {
            $this->service()->submit($registration->fresh());
        } finally {
            Http::assertNothingSent();
            $this->assertSame(0, $this->incidents());
        }
    }

    public function test_the_request_matches_telnyxs_published_toll_free_schema(): void
    {
        [, $registration] = $this->tollFreeBusiness();
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_schema']])]);

        $this->service()->submit($registration);

        Http::assertSent(function ($request): bool {
            $body = $request->data();
            $known = ['businessName', 'corporateWebsite', 'businessAddr1', 'businessAddr2', 'businessCity', 'businessState', 'businessZip',
                'businessContactFirstName', 'businessContactLastName', 'businessContactEmail', 'businessContactPhone', 'messageVolume',
                'phoneNumbers', 'useCase', 'useCaseSummary', 'productionMessageContent', 'optInWorkflow', 'optInWorkflowImageURLs',
                'additionalInformation', 'businessRegistrationNumber', 'businessRegistrationType', 'businessRegistrationCountry',
                'entityType', 'privacyPolicyURL', 'termsAndConditionURL', 'optInKeywords'];
            $required = ['businessName', 'corporateWebsite', 'businessAddr1', 'businessCity', 'businessState', 'businessZip',
                'businessContactFirstName', 'businessContactLastName', 'businessContactEmail', 'businessContactPhone', 'messageVolume',
                'phoneNumbers', 'useCase', 'useCaseSummary', 'productionMessageContent', 'optInWorkflow', 'optInWorkflowImageURLs', 'additionalInformation'];

            return array_diff(array_keys($body), $known) === []
                && array_diff($required, array_keys($body)) === []
                && $body['useCase'] === 'Conversational / Alerts'
                && $body['messageVolume'] === '1,000'
                && $body['businessState'] === 'Oregon'
                && $body['entityType'] === 'PRIVATE_PROFIT'
                && mb_strlen($body['useCaseSummary']) <= 500 && mb_strlen($body['optInWorkflow']) <= 500
                && mb_strlen($body['additionalInformation']) <= 500 && mb_strlen($body['productionMessageContent']) <= 1000
                && isset($body['phoneNumbers'][0]['phoneNumber']) && isset($body['optInWorkflowImageURLs'][0]['url']);
        });
    }

    public function test_a_request_timeout_is_an_uncertain_outcome_that_blocks_resubmission(): void
    {
        [, $registration] = $this->tollFreeBusiness();
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response([], 408)]);

        for ($i = 0; $i < 2; $i++) {
            try {
                $this->service()->submit($registration);
                $this->fail('Expected recovery-required.');
            } catch (MessagingRegistrationRecoveryRequiredException) {
                // expected
            }
        }

        Http::assertSentCount(1);
        $this->assertSame(1, $this->incidents());
    }
}
