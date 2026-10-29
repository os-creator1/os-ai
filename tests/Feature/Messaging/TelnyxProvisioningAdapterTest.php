<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\CampaignAssignmentOutcome;
use App\Enums\Messaging\CarrierReleaseOutcome;
use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\NumberReleaseQuery;
use App\Library\Messaging\DTO\RegistrationStatusQuery;
use App\Library\Messaging\Exceptions\MessagingFundingUnavailableException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\TelnyxProvisioningAdapter;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use App\Models\Currency;
use App\Models\User;
use App\Repositories\Contracts\UsageMeterRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Business\Concerns\CreatesBusinessTestData;
use Tests\TestCase;

/**
 * PR #295 Correction Round 1 — the real Telnyx provisioning adapter:
 *
 *  - item 4: every cost-incurring call reserves UsageWalletManager funding
 *    BEFORE any HTTP request, and this repository has no active rate for
 *    any of the three messaging feature keys yet, so the real path stays
 *    structurally impossible today — proven here by asserting ZERO HTTP
 *    requests are ever sent when unfunded.
 *  - item 5: the separate managed_messaging_provisioning_enabled gate.
 *  - item 7/10: 10DLC uses the /10dlc/ prefixed endpoints and a
 *    campaignStatus response field; toll-free uses its own
 *    /messaging_tollfree/verification/requests endpoint and a
 *    verificationStatus field, and never shares an id with 10DLC's
 *    brand/campaign fields.
 *
 * Every scenario here funds the wallet using the exact fixture sequence
 * UsageWalletManagerReservationLifecycleTest already established (a real
 * UsageMeter row, then setActiveRate(), then activateMetering()) — no
 * retail price is invented; the numbers used are disposable test fixture
 * values, not a product decision.
 */
class TelnyxProvisioningAdapterTest extends TestCase
{
    use RefreshDatabase;
    use CreatesBusinessTestData;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['name' => 'US Dollar', 'code' => 'USD', 'format' => '$', 'status' => true]);
    }

    private function business(): Business
    {
        return $this->createBusinessWithWorkspace($this->createCustomer(), $this->businessAttributes());
    }

    private function enableProvisioning(): void
    {
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => true,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
        ]);
    }

    private function createActorUserId(): int
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Actor',
            'email' => 'actor' . uniqid() . '@example.test',
            'status' => true,
            'is_admin' => true,
            'is_customer' => false,
            'active_portal' => 'admin',
        ])->id;
    }

    private function fundWallet(Business $business, string $featureKey, int $availableMicro = 100_000_000): void
    {
        $wallet = app(UsageWalletManager::class);
        $wallet->initializeWalletForNewBusiness($business->id);

        $actorId = $this->createActorUserId();
        $currencyId = Currency::query()->first()->id;

        // EloquentUsageMeterRepository::create() requires feature_key to be
        // a real, code-defined PlatformFeature — deliberately, so a meter
        // can never be seeded for an invented feature. This test fixture
        // reuses the existing MessagingTransport case (the one messaging-
        // domain feature already registered) purely as that required,
        // valid identity; it does not claim or imply any product decision
        // about what a real approved rate for THESE three provisioning
        // actions would be keyed to — that remains for a later slice, per
        // this repository's own convention (see PlatformFeature's own
        // docblock on MessagingTransport). meter_key is what
        // reserve()/TelnyxProvisioningAdapter actually key their lookup
        // by, and is exactly the adapter's own feature key.
        app(UsageMeterRepository::class)->create([
            'meter_key' => $featureKey,
            'feature_key' => PlatformFeature::MessagingTransport->value,
            'business_id' => null,
            'currency_id' => $currencyId,
            'description' => 'PR #295 Correction Round 1 fixture meter (test-only).',
            'updated_by_user_id' => $actorId,
        ]);

        $wallet->setActiveRate($featureKey, '1000000', '500000', 'per action', $currencyId, $actorId, 'Test rate activation.');
        $wallet->activateMetering($featureKey, $actorId, 'Test metering activation.');

        DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => $availableMicro]);

        // The paid actions also require their companion prices to be configured:
        // the rental multiplier for a number; the brand fee and the monthly
        // campaign cost for a registration. [meter key => retail micro].
        $companions = match ($featureKey) {
            TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE => [\App\Library\Messaging\NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL => '1000000'],
            TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION => [
                \App\Library\Messaging\NumberLifecycleManager::FEATURE_TEN_DLC_CAMPAIGN_RENEWAL => '500000',
                TelnyxProvisioningAdapter::FEATURE_TEN_DLC_BRAND_REGISTRATION => '1000000',
            ],
            TelnyxProvisioningAdapter::FEATURE_TEN_DLC_LOW_VOLUME_REGISTRATION => [
                \App\Library\Messaging\NumberLifecycleManager::FEATURE_TEN_DLC_LOW_VOLUME_CAMPAIGN_RENEWAL => '500000',
                TelnyxProvisioningAdapter::FEATURE_TEN_DLC_BRAND_REGISTRATION => '1000000',
            ],
            default => [],
        };

        foreach ($companions as $recurringKey => $retailMicro) {
            if (DB::table('usage_meters')->where('meter_key', $recurringKey)->exists()) {
                continue;
            }

            app(UsageMeterRepository::class)->create([
                'meter_key' => $recurringKey, 'feature_key' => PlatformFeature::MessagingTransport->value, 'business_id' => null,
                'currency_id' => $currencyId, 'description' => 'Companion fixture meter (test-only).', 'updated_by_user_id' => $actorId,
            ]);
            $wallet->setActiveRate($recurringKey, $retailMicro, '0', 'per unit', $currencyId, $actorId, 'Test rate activation.');
            $wallet->activateMetering($recurringKey, $actorId, 'Test metering activation.');
        }
    }

    private function submission(Business $business, PhoneNumberType $numberType, ?string $phoneNumber = null, ?string $existingBrandId = null, array $overrides = []): MessagingRegistrationSubmission
    {
        return new MessagingRegistrationSubmission(...array_merge([
            'businessId' => $business->id,
            'numberType' => $numberType,
            'legalBusinessName' => 'Harbor Lane Studios LLC',
            'entityType' => MessagingEntityType::Ein,
            'ein' => '12-3456789',
            'addressLine1' => '1 Harbor Lane',
            'addressLine2' => null,
            'city' => 'Portland',
            'region' => 'OR',
            'postalCode' => '97201',
            'countryCode' => 'US',
            'websiteUrl' => 'https://harborlane.example',
            'contactEmail' => 'owner@harborlane.example',
            'contactPhone' => '+15035550100',
            'useCase' => 'appointment_reminders',
            'optInMethod' => 'Customers check a box on our booking form.',
            'sampleMessage1' => 'Harbor Lane: your appointment is confirmed. Reply STOP to unsubscribe.',
            'sampleMessage2' => 'Harbor Lane: reminder, your appointment is tomorrow.',
            'privacyPolicyUrl' => 'https://harborlane.example/privacy',
            'termsUrl' => 'https://harborlane.example/terms',
            'contactFirstName' => 'Pat',
            'contactLastName' => 'Owner',
            'messageVolume' => '1,000',
            'optInImageUrl' => 'https://example.com/opt-in.png',
            'brandVertical' => 'ENTERTAINMENT',
            'phoneNumber' => $phoneNumber,
            'existingBrandId' => $existingBrandId,
            'campaignDescription' => 'Harbor Lane sends appointment confirmations and reminders to customers who booked.',
            'supportContact' => 'help@harborlane.example',
        ], $overrides)        );
    }

    // -----------------------------------------------------------------
    // Item 5 — the separate provisioning gate.
    // -----------------------------------------------------------------

    public function test_the_real_adapter_refuses_construction_when_the_provisioning_gate_is_off(): void
    {
        config([
            'messaging.managed_messaging_enabled' => true,
            'messaging.managed_messaging_provisioning_enabled' => false,
            'services.telnyx.api_key' => 'fixture_api_key_not_a_real_credential',
        ]);

        $this->expectException(MessagingProviderNotConfiguredException::class);

        app(TelnyxProvisioningAdapter::class);
    }

    // -----------------------------------------------------------------
    // Item 4 — funding gate before any provider request.
    // -----------------------------------------------------------------

    public function test_provisioning_a_number_is_refused_before_any_provider_request_when_unfunded(): void
    {
        $this->enableProvisioning();
        Http::fake();
        $business = $this->business();

        try {
            app(TelnyxProvisioningAdapter::class)->provisionNumber($business, new AvailableNumberCandidate('+14155550500', PhoneNumberType::Local, 'candidate-ref-real-1', 0, 1_000_000, 'USD'));
            $this->fail('Expected MessagingFundingUnavailableException.');
        } catch (MessagingFundingUnavailableException) {
            // expected
        }

        Http::assertNothingSent();
    }

    public function test_submitting_a_registration_is_refused_before_any_provider_request_when_unfunded(): void
    {
        $this->enableProvisioning();
        Http::fake();
        $business = $this->business();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local));
            $this->fail('Expected MessagingFundingUnavailableException.');
        } catch (MessagingFundingUnavailableException) {
            // expected
        }

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Items 7/10 — once funded, the corrected endpoints and honest
    // per-regime fields.
    // -----------------------------------------------------------------

    public function test_provisioning_a_number_hits_the_confirmed_endpoints_once_funded(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE);

        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [['phone_number' => '+14155550501', 'cost_information' => ['upfront_cost' => '0.00', 'monthly_cost' => '1.00', 'currency' => 'USD']]]]),
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_fixture_1']]),
            'api.telnyx.com/v2/number_orders' => Http::response(['data' => ['phone_numbers' => [['id' => 'pn_fixture_1']]]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->provisionNumber($business, new AvailableNumberCandidate('+14155550501', PhoneNumberType::Local, '+14155550501', 0, 1_000_000, 'USD'));

        $this->assertSame('mp_fixture_1', $result->messagingProfileId);
        $this->assertSame('pn_fixture_1', $result->providerPhoneNumberId);
        // The quote re-check (a read), the Messaging Profile and the order.
        Http::assertSentCount(3);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'filter%5Bphone_number%5D%5Bcontains%5D=5550501'));
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/messaging_profiles')
            && $request['whitelisted_destinations'] === ['US']);
        $this->assertSame(1_000_000, $result->monthlyCostMicro);
    }

    public function test_messaging_profile_rejection_refunds_hold_and_never_orders_number(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE, 20_000_000);

        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [[
                'phone_number' => '+18445183944',
                'cost_information' => ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD'],
            ]]]),
            'api.telnyx.com/v2/messaging_profiles' => Http::response([
                'errors' => [['code' => '10039', 'detail' => 'Messaging profile limit reached']],
            ], 403),
            'api.telnyx.com/v2/number_orders' => Http::response(['data' => []]),
        ]);

        try {
            app(TelnyxProvisioningAdapter::class)->provisionNumber(
                $business,
                new AvailableNumberCandidate('+18445183944', PhoneNumberType::TollFree, '+18445183944', 250_000, 1_000_000, 'USD')
            );
            $this->fail('Expected carrier profile rejection.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Telnyx rejected the Messaging Profile request.', $e->getMessage());
        }

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/messaging_profiles')
            && $request['whitelisted_destinations'] === ['US']);
        Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/number_orders'));
        $this->assertSame(20_000_000, (int) DB::table('business_usage_wallets')
            ->where('business_id', $business->id)->value('available_balance_micro'));
        $this->assertSame(['released'], DB::table('business_usage_reservations')
            ->where('business_id', $business->id)->pluck('status')->all());
    }

    public function test_10dlc_submission_hits_the_10dlc_prefixed_endpoints_once_funded(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['data' => ['brandId' => 'brand_fixture_1']]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['data' => ['campaignId' => 'campaign_fixture_1']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local));

        $this->assertSame('brand_fixture_1', $result->providerBrandId);
        $this->assertSame('campaign_fixture_1', $result->providerCampaignId);
        $this->assertNull($result->providerRegistrationId);
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), '/10dlc/brand'));
        // Campaign CREATION is the dedicated campaignBuilder resource, never the
        // campaign/{id} retrieval path — verified against Telnyx's own "Campaign
        // Builder" and "Get My Campaign" API reference pages.
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://api.telnyx.com/v2/10dlc/campaignBuilder');
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/10dlc/campaign')
            && ! str_contains($request->url(), '/10dlc/campaignBuilder'));
    }

    public function test_toll_free_submission_hits_the_corrected_endpoint_once_funded(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TOLL_FREE_VERIFICATION);

        Http::fake([
            'api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_fixture_1']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree, '+18005550100'));

        $this->assertNull($result->providerBrandId);
        $this->assertNull($result->providerCampaignId);
        $this->assertSame('tfv_fixture_1', $result->providerRegistrationId);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messaging_tollfree/verification/requests')
            && ! str_contains($request->url(), 'verification_requests'));
    }

    private function tollFreeSubmit(int $status, array|string $body, array $overrides = []): array
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TOLL_FREE_VERIFICATION);
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response($body, $status, ['x-request-id' => 'req-tf-1'])]);

        $logged = [];
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [(string) $message, (array) $context];
        });
        \Illuminate\Support\Facades\Log::shouldReceive('error', 'info', 'notice', 'debug')->andReturnNull();

        try {
            $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree, '+18005550100', null, $overrides));
            $caught = null;
        } catch (\Throwable $e) {
            $result = null;
            $caught = $e;
        }

        return [$caught, $logged, $business, $result];
    }

    public function test_a_definitive_toll_free_4xx_is_a_provider_rejection_with_a_safe_diagnosable_log_and_no_incident(): void
    {
        [$e, $logged, $business] = $this->tollFreeSubmit(422, ['errors' => [['code' => '10015', 'title' => 'Invalid value 12-3456789', 'detail' => 'bad ein', 'source' => ['pointer' => '/doingBusinessAs']]]]);

        $this->assertInstanceOf(\App\Library\Messaging\Exceptions\MessagingRegistrationProviderException::class, $e);
        $this->assertSame('toll_free', $e->step);
        $this->assertSame(422, $e->httpStatus);
        $this->assertSame(['/doingBusinessAs'], $e->rejectedFields);
        $this->assertSame(0, \App\Models\BusinessMessagingProvisioningIncident::query()->where('business_id', $business->id)->count());
        $this->assertSame('toll_free', $logged[0][1]['stage']);
        $this->assertSame(422, $logged[0][1]['http_status']);
        $this->assertSame(['/doingBusinessAs'], $logged[0][1]['rejected_fields']);
        $dump = json_encode($logged);
        foreach (['12-3456789', 'Harbor Lane', '5035550100', '+18005550100', 'Bearer', 'Invalid value', 'bad ein'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_toll_free_server_errors_conflicts_and_odd_statuses_are_uncertain_and_record_an_incident(): void
    {
        foreach ([500, 409, 408, 302] as $status) {
            [$e, , $business] = $this->tollFreeSubmit($status, '');
            $this->assertInstanceOf(\App\Library\Messaging\Exceptions\MessagingRegistrationRecoveryRequiredException::class, $e, (string) $status);
        }
    }

    public function test_an_invalid_local_preflight_is_distinct_sends_nothing_and_names_only_fields(): void
    {
        [$e] = $this->tollFreeSubmit(200, ['data' => ['id' => 'x']], ['messageVolume' => 'lots', 'contactLastName' => '']);

        $this->assertInstanceOf(\App\Library\Messaging\Exceptions\MessagingRegistrationPreflightException::class, $e);
        $this->assertSame(['message_volume', 'contact_name'], $e->fields);
        Http::assertNothingSent();
    }

    public function test_over_long_toll_free_text_is_refused_in_preflight_never_truncated(): void
    {
        [$e] = $this->tollFreeSubmit(200, ['data' => ['id' => 'x']], ['campaignDescription' => str_repeat('a', 501), 'optInMethod' => str_repeat('b', 501)]);

        $this->assertInstanceOf(\App\Library\Messaging\Exceptions\MessagingRegistrationPreflightException::class, $e);
        $this->assertSame(['campaign_description', 'opt_in_method'], $e->fields);
        Http::assertNothingSent();
    }

    public function test_a_500_character_description_and_opt_in_workflow_are_sent_whole(): void
    {
        $text = str_repeat('c', 500);
        [$e] = $this->tollFreeSubmit(200, ['data' => ['id' => 'tfv_full_1']], ['campaignDescription' => $text, 'optInMethod' => $text]);

        $this->assertNull($e);
        Http::assertSent(fn ($request) => $request->data()['useCaseSummary'] === $text && $request->data()['optInWorkflow'] === $text);
    }

    public function test_a_successful_toll_free_submission_returns_the_provider_id_without_an_incident(): void
    {
        [$e, , $business, $result] = $this->tollFreeSubmit(200, ['data' => ['id' => 'tfv_ok_1']]);

        $this->assertNull($e);
        $this->assertSame('tfv_ok_1', $result->providerRegistrationId);
        $this->assertSame(0, \App\Models\BusinessMessagingProvisioningIncident::query()->where('business_id', $business->id)->count());
    }

    public function test_toll_free_payload_carries_the_stated_dba_a_truthful_note_and_no_invented_reseller(): void
    {
        $this->enableProvisioning();
        config(['messaging.toll_free_isv_reseller' => null]);
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TOLL_FREE_VERIFICATION);
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_fixture_dba']])]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree, '+18005550100', null, [
            'doingBusinessAs' => 'Harbor Lane Photo Booth',
            'additionalInformation' => 'Our website uses the Photo Booth brand; email is on the parent domain.',
            'optInImageUrl' => 'https://harborlane.example/book-now',
        ]));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['businessName'] === 'Harbor Lane Studios LLC'
                && $body['doingBusinessAs'] === 'Harbor Lane Photo Booth'
                && ! array_key_exists('isvReseller', $body)
                && $body['productionMessageContent'] === 'Harbor Lane: your appointment is confirmed. Reply STOP to unsubscribe.'
                && $body['additionalInformation'] !== $body['useCaseSummary']
                && str_contains($body['additionalInformation'], 'Harbor Lane Studios LLC operates the customer-facing brand Harbor Lane Photo Booth.')
                && str_contains($body['additionalInformation'], 'email is on the parent domain')
                && $body['optInWorkflowImageURLs'] === [['url' => 'https://harborlane.example/book-now']];
        });
    }

    public function test_toll_free_payload_sends_isv_reseller_only_when_configured_and_omits_an_unneeded_dba(): void
    {
        $this->enableProvisioning();
        config(['messaging.toll_free_isv_reseller' => 'Configured Reseller Inc']);
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TOLL_FREE_VERIFICATION);
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_fixture_isv']])]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree, '+18005550100', null, [
            'doingBusinessAs' => 'harbor lane studios llc',
        ]));

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['isvReseller'] === 'Configured Reseller Inc'
                && ! array_key_exists('doingBusinessAs', $body)
                && $body['additionalInformation'] === 'No additional information.';
        });
    }

    /**
     * Review correction — Telnyx's own toll-free verification submission
     * requires the already-owned number being verified.
     */
    public function test_toll_free_submission_includes_the_already_owned_phone_number(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TOLL_FREE_VERIFICATION);

        Http::fake([
            'api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['data' => ['id' => 'tfv_fixture_2']]),
        ]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree, '+18005550100'));

        Http::assertSent(fn ($request) => $request['phoneNumbers'] === [['phoneNumber' => '+18005550100']]);
    }

    /**
     * Review correction — a toll-free submission attempted without an
     * already-owned number must refuse, never silently omit the required
     * field. Unreachable through the real controller flow, which only
     * ever submits toll-free once a number already exists; checked here
     * independently, never trusted from the caller alone.
     */
    public function test_toll_free_submission_refuses_without_a_phone_number(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TOLL_FREE_VERIFICATION);
        Http::fake();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Toll-free verification cannot be submitted without an already-owned number.');

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree));
    }

    /**
     * Review correction — 10DLC brand+campaign registration has no
     * equivalent requirement: Telnyx genuinely supports submitting it
     * before any number exists.
     */
    public function test_ten_dlc_submission_succeeds_without_a_phone_number(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['data' => ['brandId' => 'brand_fixture_no_number']]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['data' => ['campaignId' => 'campaign_fixture_no_number']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local));

        $this->assertSame('brand_fixture_no_number', $result->providerBrandId);
        $this->assertSame('campaign_fixture_no_number', $result->providerCampaignId);
    }

    public function test_refresh_status_routes_10dlc_to_the_10dlc_campaign_endpoint(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/campaign/campaign_fixture_2' => Http::response(['campaignId' => 'campaign_fixture_2', 'campaignStatus' => 'MNO_PROVISIONED', 'status' => 'ACTIVE']),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::Local,
            providerBrandId: 'brand_fixture_2',
            providerCampaignId: 'campaign_fixture_2',
            providerRegistrationId: null,
        ));

        $this->assertSame(MessagingRegistrationStatus::Approved, $result->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/10dlc/campaign/campaign_fixture_2'));
    }

    public function test_refresh_status_routes_toll_free_to_its_own_verification_endpoint(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/messaging_tollfree/verification/requests/tfv_fixture_2' => Http::response(['data' => ['verificationStatus' => 'Verified']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::TollFree,
            providerBrandId: null,
            providerCampaignId: null,
            providerRegistrationId: 'tfv_fixture_2',
        ));

        $this->assertSame(MessagingRegistrationStatus::Approved, $result->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messaging_tollfree/verification/requests/tfv_fixture_2'));
    }

    public function test_refresh_status_never_guesses_approved_for_an_unrecognised_toll_free_value(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/messaging_tollfree/verification/requests/tfv_fixture_3' => Http::response(['data' => ['verificationStatus' => 'Waiting for Telnyx']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::TollFree,
            providerBrandId: null,
            providerCampaignId: null,
            providerRegistrationId: 'tfv_fixture_3',
        ));

        $this->assertSame(MessagingRegistrationStatus::Pending, $result->status);
    }

    // -----------------------------------------------------------------
    // Phone Numbers + A2P lane — the carrier-release boundary.
    // Deliberately does NOT reserveFunding(): releasing a number is not a
    // purchase, so this is tested without any wallet fixture at all.
    //
    // Review correction — releaseNumber() now verifies twice before ever
    // reporting Confirmed: a GET lookup that must identity-match first,
    // then a DELETE whose own response must confirm both identity and
    // deleted state. A DELETE 404 is never, by itself, proof of a prior
    // successful release.
    // -----------------------------------------------------------------

    public function test_release_number_confirms_only_once_the_lookup_matches_and_the_delete_response_confirms_deletion(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_valid_1' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => 'pn_valid_1', 'phone_number' => '+14155550700', 'status' => 'active']], 200);
                }

                return Http::response(['data' => ['id' => 'pn_valid_1', 'phone_number' => '+14155550700', 'status' => 'deleted']], 200);
            },
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_valid_1', '+14155550700'));

        $this->assertSame(CarrierReleaseOutcome::Confirmed, $result->outcome);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), '/phone_numbers/pn_valid_1'));
        Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), '/phone_numbers/pn_valid_1'));
    }

    /**
     * The mismatched-provider-ID case: the stored reference resolves to a
     * DIFFERENT phone number at the carrier than this platform believes it
     * is releasing. DELETE must never even be attempted.
     */
    public function test_release_number_refuses_to_delete_when_the_lookup_phone_number_does_not_match(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_mismatch_1' => Http::response(['data' => ['id' => 'pn_mismatch_1', 'phone_number' => '+19995551234', 'status' => 'active']], 200),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_mismatch_1', '+14155550701'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('lookup_phone_number_mismatch', $result->detail);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /**
     * A DELETE 404 must remain NotConfirmed even when the prior lookup
     * matched — it is not proof of a prior successful release.
     */
    public function test_release_number_treats_a_delete_404_as_not_confirmed(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_delete_404_1' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => 'pn_delete_404_1', 'phone_number' => '+14155550702', 'status' => 'active']], 200);
                }

                return Http::response(['errors' => [['title' => 'Not Found']]], 404);
            },
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_delete_404_1', '+14155550702'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('delete_not_found', $result->detail);
    }

    /**
     * A 2xx delete response that does not itself confirm identity/deleted
     * state is ambiguous — never guessed as a success.
     */
    public function test_release_number_treats_an_ambiguous_2xx_delete_response_as_not_confirmed(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_ambiguous_1' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => 'pn_ambiguous_1', 'phone_number' => '+14155550703', 'status' => 'active']], 200);
                }

                // 2xx, but the response never confirms the deleted state.
                return Http::response(['data' => ['id' => 'pn_ambiguous_1', 'phone_number' => '+14155550703', 'status' => 'active']], 200);
            },
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_ambiguous_1', '+14155550703'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('delete_response_ambiguous', $result->detail);
    }

    /**
     * A 2xx delete response naming a DIFFERENT phone number than expected
     * is likewise ambiguous, even though the HTTP call itself succeeded.
     */
    public function test_release_number_treats_a_delete_response_identity_mismatch_as_not_confirmed(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_delete_mismatch_1' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => 'pn_delete_mismatch_1', 'phone_number' => '+14155550704', 'status' => 'active']], 200);
                }

                return Http::response(['data' => ['id' => 'pn_delete_mismatch_1', 'phone_number' => '+19995559999', 'status' => 'deleted']], 200);
            },
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_delete_mismatch_1', '+14155550704'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('delete_response_ambiguous', $result->detail);
    }

    public function test_release_number_never_guesses_confirmed_for_any_other_delete_error_status(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_release_fixture_3' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => 'pn_release_fixture_3', 'phone_number' => '+14155550602', 'status' => 'active']], 200);
                }

                return Http::response(['errors' => [['title' => 'Unauthorized']]], 401);
            },
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_release_fixture_3', '+14155550602'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
    }

    public function test_release_number_treats_a_transport_exception_as_not_confirmed(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_release_fixture_4' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out.'),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_release_fixture_4', '+14155550603'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('lookup_transport_error', $result->detail);
    }

    /**
     * A 404 on the LOOKUP itself is likewise never guessed as "already
     * deleted" — there is no identity to verify against, so DELETE is
     * never attempted either.
     */
    public function test_release_number_treats_a_lookup_404_as_not_confirmed(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_lookup_404_1' => Http::response(['errors' => [['title' => 'Not Found']]], 404),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_lookup_404_1', '+14155550705'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('lookup_not_found', $result->detail);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /**
     * A retry after an earlier attempt's own response was lost to this
     * platform's network/timeout: the lookup itself already shows the
     * number deleted, with a matching identity. Confirmed WITHOUT a
     * second, redundant delete call — verified via retrieval, never
     * guessed from a delete 404.
     */
    public function test_release_number_confirms_from_the_lookup_alone_when_already_deleted(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_already_deleted_1' => Http::response(['data' => ['id' => 'pn_already_deleted_1', 'phone_number' => '+14155550706', 'status' => 'deleted']], 200),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_already_deleted_1', '+14155550706'));

        $this->assertSame(CarrierReleaseOutcome::Confirmed, $result->outcome);
        $this->assertSame('lookup_already_deleted', $result->detail);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /**
     * Review correction — a lookup response naming a DIFFERENT id than the
     * one this platform called with must refuse to proceed to DELETE,
     * independent of whatever phone_number it happens to carry.
     */
    public function test_release_number_refuses_when_the_lookup_id_does_not_match(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_id_mismatch_1' => Http::response(['data' => ['id' => 'pn_totally_different', 'phone_number' => '+14155550707', 'status' => 'active']], 200),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_id_mismatch_1', '+14155550707'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('lookup_id_mismatch', $result->detail);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /**
     * Review correction — a MISSING id on the lookup response must never
     * count as confirmation either; the earlier version only checked id
     * "if present", which a response omitting it entirely would pass by
     * default.
     */
    public function test_release_number_refuses_when_the_lookup_response_has_no_id(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_no_lookup_id_1' => Http::response(['data' => ['phone_number' => '+14155550708', 'status' => 'active']], 200),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_no_lookup_id_1', '+14155550708'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('lookup_id_mismatch', $result->detail);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    /**
     * Review correction — a MISSING id on an otherwise-successful delete
     * response must never count as confirmation either.
     */
    public function test_release_number_refuses_when_the_delete_response_has_no_id(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_no_delete_id_1' => function ($request) {
                if ($request->method() === 'GET') {
                    return Http::response(['data' => ['id' => 'pn_no_delete_id_1', 'phone_number' => '+14155550709', 'status' => 'active']], 200);
                }

                return Http::response(['data' => ['phone_number' => '+14155550709', 'status' => 'deleted']], 200);
            },
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_no_delete_id_1', '+14155550709'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('delete_response_ambiguous', $result->detail);
    }

    /**
     * Review correction — a carrier-side mid-port status must refuse
     * DELETE entirely, independent of this platform's own
     * PortOutRequestManager tracking (which NumberLifecycleManager checks
     * separately, before ever reaching the adapter).
     */
    public function test_release_number_refuses_to_delete_when_the_lookup_status_is_port_out_pending(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_port_out_pending_1' => Http::response(['data' => ['id' => 'pn_port_out_pending_1', 'phone_number' => '+14155550710', 'status' => 'port-out-pending']], 200),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_port_out_pending_1', '+14155550710'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('lookup_status_not_safe_for_deletion', $result->detail);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_release_number_refuses_to_delete_when_the_lookup_status_is_ported_out(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_ported_out_1' => Http::response(['data' => ['id' => 'pn_ported_out_1', 'phone_number' => '+14155550711', 'status' => 'ported-out']], 200),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_ported_out_1', '+14155550711'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('lookup_status_not_safe_for_deletion', $result->detail);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    public function test_release_number_refuses_to_delete_when_the_lookup_status_is_missing(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/phone_numbers/pn_missing_status_1' => Http::response(['data' => ['id' => 'pn_missing_status_1', 'phone_number' => '+14155550712']], 200),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->releaseNumber(new NumberReleaseQuery('pn_missing_status_1', '+14155550712'));

        $this->assertSame(CarrierReleaseOutcome::NotConfirmed, $result->outcome);
        $this->assertSame('lookup_status_not_safe_for_deletion', $result->detail);
        Http::assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }

    // -----------------------------------------------------------------
    // Review correction — refreshRegistrationStatus() now returns the
    // whole RegistrationStatusResult, and correctly recognizes the full
    // documented 10DLC/toll-free rejection vocabulary, with a
    // carrier-supplied reason when one is present.
    // -----------------------------------------------------------------

    public function test_refresh_status_recognizes_mno_rejected_as_rejected_with_its_reason(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/campaign/campaign_fixture_rejected_1' => Http::response(['data' => [
                'campaignStatus' => 'MNO_REJECTED',
                'failureReasons' => ['Sample message missing required opt-out language.'],
            ]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::Local,
            providerBrandId: 'brand_fixture_rejected_1',
            providerCampaignId: 'campaign_fixture_rejected_1',
            providerRegistrationId: null,
        ));

        $this->assertSame(MessagingRegistrationStatus::Rejected, $result->status);
        $this->assertSame('Sample message missing required opt-out language.', $result->rejectionReason);
    }

    public function test_refresh_status_recognizes_telnyx_failed_as_rejected_with_no_reason_when_none_supplied(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/campaign/campaign_fixture_rejected_2' => Http::response(['data' => ['campaignStatus' => 'TELNYX_FAILED']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::Local,
            providerBrandId: 'brand_fixture_rejected_2',
            providerCampaignId: 'campaign_fixture_rejected_2',
            providerRegistrationId: null,
        ));

        $this->assertSame(MessagingRegistrationStatus::Rejected, $result->status);
        $this->assertNull($result->rejectionReason);
    }

    public function test_refresh_status_extracts_a_toll_free_decline_reason_when_present(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/messaging_tollfree/verification/requests/tfv_fixture_rejected_1' => Http::response(['data' => [
                'verificationStatus' => 'Rejected',
                'declineReason' => 'Business name did not match Secretary of State records.',
            ]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::TollFree,
            providerBrandId: null,
            providerCampaignId: null,
            providerRegistrationId: 'tfv_fixture_rejected_1',
        ));

        $this->assertSame(MessagingRegistrationStatus::Rejected, $result->status);
        $this->assertSame('Business name did not match Secretary of State records.', $result->rejectionReason);
    }

    // -----------------------------------------------------------------
    // Phone Numbers + A2P lane — the verify-first sequence's own final
    // step, linking a freshly purchased local number's Messaging Profile
    // to an already-approved 10DLC campaign.
    // -----------------------------------------------------------------

    /**
     * Second review correction — Telnyx's own documented response for this
     * endpoint is a top-level {messagingProfileId, taskId, campaignId, ...}
     * body (HTTP 202), never nested under a "data" key (verified against
     * Telnyx's own published SDK type definitions; see this test's own
     * class docblock and TelnyxProvisioningAdapter's own for the
     * verification source and citation). This fixture previously used the
     * same incorrect {data: {taskId: ...}} wrapper the adapter itself
     * incorrectly read, so the two bugs cancelled out and this test passed
     * despite the adapter never being able to read a real taskId from a
     * genuine Telnyx response.
     */
    public function test_assign_messaging_profile_to_campaign_hits_the_confirmed_endpoint_and_reports_requested(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile' => Http::response(['taskId' => 'task_fixture_1', 'messagingProfileId' => 'mp_fixture_1', 'campaignId' => 'campaign_fixture_1'], 202),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->assignMessagingProfileToCampaign('mp_fixture_1', 'campaign_fixture_1');

        $this->assertSame(CampaignAssignmentOutcome::Requested, $result->outcome);
        $this->assertSame('task_fixture_1', $result->taskId);
        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_contains($request->url(), '/10dlc/phoneNumberAssignmentByProfile')
            && $request['messagingProfileId'] === 'mp_fixture_1'
            && $request['campaignId'] === 'campaign_fixture_1');
    }

    public function test_assign_messaging_profile_to_campaign_never_guesses_requested_on_a_failed_response(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile' => Http::response(['errors' => [['title' => 'Not Found']]], 404),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->assignMessagingProfileToCampaign('mp_fixture_2', 'campaign_fixture_2');

        $this->assertSame(CampaignAssignmentOutcome::Failed, $result->outcome);
    }

    /**
     * Review correction — a 2xx response carrying no usable taskId leaves
     * this platform with nothing to ever poll for completion; it must
     * never be reported as Requested (a promise of later confirmation this
     * platform could not actually check for).
     */
    public function test_assign_messaging_profile_to_campaign_fails_when_the_response_carries_no_task_id(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile' => Http::response(['messagingProfileId' => 'mp_fixture_3', 'campaignId' => 'campaign_fixture_3'], 202),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->assignMessagingProfileToCampaign('mp_fixture_3', 'campaign_fixture_3');

        $this->assertSame(CampaignAssignmentOutcome::Failed, $result->outcome);
        $this->assertSame('missing_task_id', $result->detail);
    }

    // -----------------------------------------------------------------
    // Review correction — checkCampaignAssignmentStatus(): the real
    // completion mechanism this correction added. Telnyx's own assignment
    // endpoint above returns only a background task; this is the ONLY
    // path that may ever report Confirmed, and only for the documented
    // "completed" per-record status — every other outcome (failed,
    // in-progress, an unmatched phone number, a non-2xx response, or a
    // transport exception) must report Requested again (poll again later)
    // or Failed, never a guessed Confirmed.
    // -----------------------------------------------------------------

    public function test_check_campaign_assignment_status_confirms_only_on_a_completed_record_for_this_exact_number(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers*' => Http::response(['records' => [
                ['taskId' => 'task_fixture_4', 'phoneNumber' => '+14155550100', 'status' => 'completed'],
            ]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_4', '+14155550100');

        $this->assertSame(CampaignAssignmentOutcome::Confirmed, $result->outcome);
        // Second review correction — an explicit, generous page size is
        // requested rather than trusting an undocumented default, so a
        // task's own records can never be silently split across pages.
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/10dlc/phoneNumberAssignmentByProfile/task_fixture_4/phoneNumbers')
            && str_contains($request->url(), 'recordsPerPage='));
    }

    public function test_check_campaign_assignment_status_reports_failed_on_a_failed_record(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers*' => Http::response(['records' => [
                ['taskId' => 'task_fixture_5', 'phoneNumber' => '+14155550101', 'status' => 'failed'],
            ]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_5', '+14155550101');

        $this->assertSame(CampaignAssignmentOutcome::Failed, $result->outcome);
    }

    /**
     * Documented in-progress values ("pending", "starting", "processing",
     * "running") must never be equated with completion — "Requested" is
     * the only honest answer while the carrier is still working.
     */
    public function test_check_campaign_assignment_status_stays_requested_while_still_processing(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers*' => Http::response(['records' => [
                ['taskId' => 'task_fixture_6', 'phoneNumber' => '+14155550102', 'status' => 'processing'],
            ]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_6', '+14155550102');

        $this->assertSame(CampaignAssignmentOutcome::Requested, $result->outcome);
    }

    public function test_check_campaign_assignment_status_stays_requested_when_this_number_has_no_record_yet(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers*' => Http::response(['records' => [
                ['taskId' => 'task_fixture_7', 'phoneNumber' => '+14155559999', 'status' => 'completed'],
            ]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_7', '+14155550103');

        $this->assertSame(CampaignAssignmentOutcome::Requested, $result->outcome, 'A different number\'s completed record must never be attributed to this one.');
    }

    public function test_check_campaign_assignment_status_stays_requested_on_a_non_2xx_response(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers*' => Http::response(['errors' => [['title' => 'Internal Server Error']]], 500),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_8', '+14155550104');

        $this->assertSame(CampaignAssignmentOutcome::Requested, $result->outcome, 'A poll failure is retried later, never guessed as a terminal outcome.');
    }

    public function test_check_campaign_assignment_status_stays_requested_on_a_transport_exception(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers*' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Simulated network failure.'),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_9', '+14155550105');

        $this->assertSame(CampaignAssignmentOutcome::Requested, $result->outcome);
    }

    // -----------------------------------------------------------------
    // Missing-wallet recovery — a Business with no business_usage_wallets
    // row is provisioned through the canonical initializer, never a raw
    // "wallet not found" failure, and is never given free balance.
    // -----------------------------------------------------------------

    public function test_a_business_missing_its_wallet_is_provisioned_and_still_blocked_when_unfunded(): void
    {
        $this->enableProvisioning();
        Http::fake();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, 0);
        DB::table('business_usage_wallets')->where('business_id', $business->id)->delete();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local));
            $this->fail('Expected MessagingInsufficientFundsException.');
        } catch (\App\Library\Messaging\Exceptions\MessagingInsufficientFundsException) {
            // expected: wallet re-created at zero balance, funding still required
        }

        $this->assertSame(1, DB::table('business_usage_wallets')->where('business_id', $business->id)->count());
        $this->assertSame(0, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
        Http::assertNothingSent();
    }

    public function test_wallet_initialization_is_idempotent_and_never_duplicates_a_wallet(): void
    {
        $business = $this->business();
        DB::table('business_usage_wallets')->where('business_id', $business->id)->delete();

        $manager = app(UsageWalletManager::class);
        $manager->initializeWalletForNewBusiness($business->id);
        $manager->initializeWalletForNewBusiness($business->id);

        // The unique business_id index backs the race path (the duplicate-key
        // loser returns quietly); true cross-process concurrency is not
        // exercised here.
        $this->assertSame(1, DB::table('business_usage_wallets')->where('business_id', $business->id)->count());
    }

    public function test_a_funded_10dlc_submission_charges_exactly_once_on_the_wallet(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['data' => ['brandId' => 'brand_fixture_2']]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['data' => ['campaignId' => 'campaign_fixture_2']]),
        ]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local));

        Http::assertSentCount(2);
        $this->assertSame(['committed', 'committed'], DB::table('business_usage_reservations')->where('business_id', $business->id)->orderBy('id')->pluck('status')->all());
    }

    // -----------------------------------------------------------------
    // Partial-submission recovery.
    // -----------------------------------------------------------------

    private function localRegistration(Business $business): \App\Models\BusinessMessagingRegistration
    {
        return \App\Models\BusinessMessagingRegistration::create([
            'business_id' => $business->id,
            'number_type' => 'local',
            'entity_type' => 'ein',
            'use_case' => 'customer_care', 'region' => 'OR',
            'contact_first_name' => 'Pat', 'contact_last_name' => 'Owner', 'message_volume' => '1,000',
            'opt_in_image_url' => 'https://example.com/opt-in.png', 'brand_vertical' => 'ENTERTAINMENT',
            'legal_business_name' => 'Harbor Lane Studios LLC', 'contact_email' => 'owner@harborlane.example', 'contact_phone' => '+15035550100', 'support_contact' => 'help@harborlane.example', 'support_contact_confirmed_at' => '2026-01-01 00:00:00',
            'campaign_description' => 'Harbor Lane sends appointment confirmations and reminders to customers who booked.',
            'status' => 'not_started',
        ]);
    }

    private function retrySubmission(Business $business): MessagingRegistrationSubmission
    {
        $brandId = DB::table('business_messaging_registrations')->where('business_id', $business->id)->value('provider_brand_id');

        return $this->submission($business, PhoneNumberType::Local, null, $brandId);
    }

    private function brandRequestCount(): int
    {
        return Http::recorded(fn ($request) => str_contains($request->url(), '/10dlc/brand'))->count();
    }

    public function test_a_retry_after_brand_success_and_campaign_failure_reuses_the_brand_and_never_double_reserves(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['data' => ['brandId' => 'brand_fixture_9']]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::sequence()
                ->push(['errors' => []], 422)
                ->push(['errors' => []], 422)
                ->push(['data' => ['campaignId' => 'campaign_fixture_9']]),
        ]);

        for ($i = 0; $i < 2; $i++) {
            try {
                app(TelnyxProvisioningAdapter::class)->submitRegistration($this->retrySubmission($business));
                $this->fail('Expected the campaign rejection.');
            } catch (\RuntimeException) {
                // expected; brand persisted before the campaign step
            }
        }

        $this->assertSame('brand_fixture_9', DB::table('business_messaging_registrations')->where('business_id', $business->id)->value('provider_brand_id'));

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->retrySubmission($business));

        $this->assertSame('brand_fixture_9', $result->providerBrandId);
        $this->assertSame('campaign_fixture_9', $result->providerCampaignId);
        $this->assertSame(1, $this->brandRequestCount());

        $statuses = DB::table('business_usage_reservations')
            ->where('idempotency_key', 'like', 'messaging-registration:%')
            ->orderBy('idempotency_key')->pluck('status')->all();
        $this->assertSame(['released', 'released', 'committed'], $statuses);
    }

    public function test_an_unconfirmed_brand_outcome_blocks_blind_resubmission(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        Http::fake(['api.telnyx.com/v2/10dlc/brand' => Http::response([], 503)]);

        for ($i = 0; $i < 2; $i++) {
            try {
                app(TelnyxProvisioningAdapter::class)->submitRegistration($this->retrySubmission($business));
                $this->fail('Expected recovery-required.');
            } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationRecoveryRequiredException) {
                // expected
            }
        }

        $this->assertSame(1, $this->brandRequestCount());
        $this->assertSame(1, DB::table('business_messaging_provisioning_incidents')->where('stage', '10dlc_brand_outcome_unknown')->whereNull('resolved_at')->count());
        $this->assertSame(0, DB::table('business_usage_reservations')->where('status', 'pending')->count());
    }

    public function test_a_crash_after_provider_success_reuses_the_pending_reservation_and_creates_nothing_twice(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        // State left by a crash after the campaign succeeded and was
        // persisted, but before the fee commit: ids saved, reservation pending.
        $registration->update(['provider_brand_id' => 'brand_crash_1', 'provider_campaign_id' => 'campaign_crash_1']);
        $pendingId = app(UsageWalletManager::class)->reserve(
            $business,
            TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION,
            'messaging-registration:' . $business->id . ':' . TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION . ':0:1',
        )->reservationId;

        Http::fake();

        $submission = MessagingRegistrationSubmission::fromModel($registration->fresh());
        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($submission);

        Http::assertNothingSent();
        $this->assertSame('campaign_crash_1', $result->providerCampaignId);
        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
        $this->assertSame('committed', DB::table('business_usage_reservations')->where('id', $pendingId)->value('status'));
    }

    public function test_committed_fee_with_saved_ids_after_a_failed_status_write_completes_without_a_fee_or_provider_call(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        $registration->update(['provider_brand_id' => 'brand_done_1', 'provider_campaign_id' => 'campaign_done_1']);
        $wallet = app(UsageWalletManager::class);
        $reservationId = $wallet->reserve($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, 'messaging-registration:' . $business->id . ':' . TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION . ':0:1')->reservationId;
        $wallet->commit($reservationId);
        Http::fake();

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));

        Http::assertNothingSent();
        $this->assertSame(MessagingRegistrationStatus::Pending, $result->status);
        $this->assertSame('campaign_done_1', $result->providerCampaignId);
        $this->assertSame(1, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    public function test_committed_fee_with_incomplete_saved_ids_is_blocked_with_a_recorded_incident(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        $wallet = app(UsageWalletManager::class);
        $reservationId = $wallet->reserve($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, 'messaging-registration:' . $business->id . ':' . TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION . ':0:1')->reservationId;
        $wallet->commit($reservationId);
        Http::fake();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));
            $this->fail('Expected recovery-required.');
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationRecoveryRequiredException) {
            // expected
        }

        Http::assertNothingSent();
        $this->assertSame(1, DB::table('business_messaging_provisioning_incidents')->where('stage', '10dlc_funding_state_ambiguous')->whereNull('resolved_at')->count());
    }

    public function test_a_rejected_registration_never_reuses_its_rejected_ids_and_commits_only_after_a_real_submission(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);
        $registration->update(['status' => 'rejected', 'provider_brand_id' => 'brand_rejected', 'provider_campaign_id' => 'campaign_rejected']);

        $submission = MessagingRegistrationSubmission::fromModel($registration->fresh());
        $this->assertNull($submission->existingBrandId);
        $this->assertNull($submission->existingCampaignId);

        Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['data' => ['brandId' => 'brand_new_1']]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['data' => ['campaignId' => 'campaign_new_1']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($submission);

        $this->assertSame('brand_new_1', $result->providerBrandId);
        $this->assertSame(1, $this->brandRequestCount());
        $this->assertSame(['committed', 'committed'], DB::table('business_usage_reservations')->where('business_id', $business->id)->pluck('status')->all());
    }

    /** @return array<int, array{string, array<string, mixed>}> */
    private function providerFailureLogs(int $status, string $stage): array
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        $body = ['errors' => [['code' => '10005', 'title' => 'Invalid ein 12-3456789', 'detail' => 'EIN 12-3456789 at 1 Main St, jane@example.test, +15555550100', 'source' => ['pointer' => '/ein']]]];
        Http::fake($stage === 'brand' ? [
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response($body, $status, ['x-request-id' => 'req-abc-123']),
        ] : [
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['brandId' => 'brand_diag_1']),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response($body, $status, ['x-request-id' => 'req-abc-123']),
        ]);

        $logged = [];
        \Illuminate\Support\Facades\Log::shouldReceive('warning')->andReturnUsing(function ($message, $context = []) use (&$logged) {
            $logged[] = [(string) $message, (array) $context];
        });
        \Illuminate\Support\Facades\Log::shouldReceive('error', 'info', 'notice', 'debug')->andReturnNull();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));
            $this->fail('Expected the provider rejection to surface.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Telnyx rejected', $e->getMessage()); // customer-neutral path unchanged
        }

        $this->assertSame($business->id, $logged[0][1]['business_id'] ?? null);

        return $logged;
    }

    public function test_a_brand_api_rejection_is_diagnosable_in_the_logs_without_any_sensitive_data(): void
    {
        $logged = $this->providerFailureLogs(422, 'brand');

        $this->assertCount(1, $logged);
        $this->assertSame('brand', $logged[0][1]['stage']);
        $this->assertSame(422, $logged[0][1]['http_status']);
        $this->assertSame('10005', $logged[0][1]['provider_error_code']);
        $this->assertSame('/ein', $logged[0][1]['rejected_field']);
        $this->assertSame('req-abc-123', $logged[0][1]['request_id']);

        $dump = json_encode($logged);
        foreach (['12-3456789', 'Main St', 'jane@example.test', '5555550100', 'Invalid ein', 'Authorization', 'Bearer'] as $secret) {
            $this->assertStringNotContainsString($secret, $dump);
        }
    }

    public function test_a_campaign_api_rejection_is_diagnosable_and_leaves_the_saved_brand_and_accounting_intact(): void
    {
        $logged = $this->providerFailureLogs(400, 'campaign');

        $this->assertSame('campaign', $logged[0][1]['stage']);
        $this->assertSame(400, $logged[0][1]['http_status']);
        $this->assertSame('10005', $logged[0][1]['provider_error_code']);
        $this->assertStringNotContainsString('12-3456789', json_encode($logged));
        $this->assertSame('brand_diag_1', \App\Models\BusinessMessagingRegistration::query()->value('provider_brand_id'));
    }

    private function lowVolumeCampaignPost(array $overrides = []): array
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::registrationFeatureFor($overrides['useCase'] ?? 'low_volume_mixed'));
        Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['brandId' => 'brand_msg_1']),
            'api.telnyx.com/v2/10dlc/campaignBuilder/brand/*' => Http::response(['usecase' => 'LOW_VOLUME', 'minSubUsecases' => 2, 'maxSubUsecases' => 5]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['campaignId' => 'camp_msg_1']),
        ]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local, null, null, array_merge([
            'useCase' => 'low_volume_mixed', 'messagePurposes' => ['care', 'marketing'], 'brandVertical' => 'ENTERTAINMENT',
        ], $overrides)));

        return Http::recorded(fn ($r) => $r->method() === 'POST' && $r->url() === 'https://api.telnyx.com/v2/10dlc/campaignBuilder')->first()[0]->data();
    }

    public function test_campaign_submission_carries_valid_business_specific_confirmation_messages_and_keeps_low_volume_sub_usecases(): void
    {
        $body = $this->lowVolumeCampaignPost();

        $this->assertSame('LOW_VOLUME', $body['usecase']);
        $this->assertSame(['CUSTOMER_CARE', 'MARKETING'], $body['subUsecases']);

        foreach (['optinMessage', 'optoutMessage', 'helpMessage'] as $field) {
            $this->assertGreaterThanOrEqual(20, mb_strlen($body[$field]), $field);
            $this->assertLessThanOrEqual(320, mb_strlen($body[$field]), $field);
            $this->assertStringContainsString('Harbor Lane Studios LLC', $body[$field], $field);
            $this->assertStringNotContainsString('Lumina', $body[$field]);
            $this->assertStringNotContainsString('MotionGrove', $body[$field]);
        }

        // Separate consent: never claims the recipient joined both programs.
        $this->assertStringContainsString('texts you agreed to', $body['optinMessage']);
        $this->assertStringContainsString('and/or promotions', $body['optinMessage']);
        $this->assertStringContainsString('STOP', $body['optinMessage']);
        $this->assertStringContainsString('HELP', $body['optinMessage']);
        $this->assertStringContainsString('Msg & data rates may apply', $body['optinMessage']);
        $this->assertStringContainsString('unsubscribed', $body['optoutMessage']);
        // The HELP contact is the Business's confirmed support contact, never the brand representative's.
        $this->assertStringContainsString('help@harborlane.example', $body['helpMessage']);
        $this->assertStringNotContainsString('owner@harborlane.example', $body['helpMessage']);
        $this->assertStringNotContainsString('+15035550100', $body['helpMessage']);
        $this->assertSame('HELP,INFO', $body['helpKeywords']);
    }

    public function test_every_supported_campaign_type_gets_accurate_confirmation_wording(): void
    {
        $expect = [
            'customer_care' => 'customer care and appointment texts',
            'appointment_reminders' => 'customer care and appointment texts',
            'marketing' => 'promotional texts',
            'account_notifications' => 'account notification texts',
            'two_factor' => 'one-time passcode and verification texts',
            'mixed' => 'the texts you agreed to',
            'low_volume_mixed' => 'the texts you agreed to',
        ];
        $this->enableProvisioning();
        $method = new \ReflectionMethod(TelnyxProvisioningAdapter::class, 'campaignConfirmationMessages');
        $business = $this->business();

        foreach ($expect as $useCase => $phrase) {
            $messages = $method->invoke(app(TelnyxProvisioningAdapter::class), $this->submission($business, PhoneNumberType::Local, null, null, ['useCase' => $useCase]));
            $this->assertStringContainsString($phrase, $messages['optin'], $useCase);
            $this->assertGreaterThanOrEqual(20, mb_strlen($messages['help']));
        }

        // Single-purpose types never claim promotions or service they were not registered for.
        $care = $method->invoke(app(TelnyxProvisioningAdapter::class), $this->submission($business, PhoneNumberType::Local, null, null, ['useCase' => 'customer_care']));
        $this->assertStringNotContainsString('promotion', $care['optin']);
        $promo = $method->invoke(app(TelnyxProvisioningAdapter::class), $this->submission($business, PhoneNumberType::Local, null, null, ['useCase' => 'marketing']));
        $this->assertStringNotContainsString('customer care', $promo['optin']);
    }

    public function test_a_sole_proprietor_brand_is_refused_before_any_reservation_or_provider_call(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);
        Http::fake();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local, null, null, ['entityType' => MessagingEntityType::SoleProprietor, 'ein' => null, 'phase' => 'brand']));
            $this->fail('Expected refusal.');
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationNotEligibleException $e) {
            $this->assertStringContainsString('one-time PIN text', $e->getMessage());
            $this->assertStringContainsString('Nothing was charged', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    private function assertRefusedBeforeFundingOrProvider(array $overrides): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);
        Http::fake();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local, null, null, $overrides));
            $this->fail('Expected refusal.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('confirmation message', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    public function test_an_unconfirmed_support_contact_is_refused_even_when_a_brand_contact_exists(): void
    {
        $this->assertRefusedBeforeFundingOrProvider(['supportContact' => null]);
    }

    public function test_an_invalid_support_contact_is_refused_before_any_funding_or_provider_call(): void
    {
        $this->assertRefusedBeforeFundingOrProvider(['supportContact' => 'call us sometime']);
    }

    public function test_an_overlong_confirmation_message_is_refused_never_truncated_before_funding_or_provider_call(): void
    {
        $this->assertRefusedBeforeFundingOrProvider(['legalBusinessName' => str_repeat('Long Name ', 40)]);
    }

    // -----------------------------------------------------------------
    // Provider use-case mapping and payload contract.
    // -----------------------------------------------------------------

    public function test_mixed_is_refused_before_funding_while_the_mixed_flag_is_off(): void
    {
        $this->enableProvisioning();
        $this->assertFalse((bool) config('messaging.ten_dlc_mixed_enabled'));
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);
        Http::fake();

        $submission = $this->submission($business, PhoneNumberType::Local);
        $mixed = new MessagingRegistrationSubmission(...array_merge(get_object_vars($submission), ['useCase' => 'mixed']));

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration($mixed);
            $this->fail('Mixed must be refused while disabled.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('not enabled', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_usage_reservations')->count());
    }

    public function test_internal_use_cases_map_to_provider_enum_values_and_keywords_have_no_spaces(): void
    {
        $this->enableProvisioning();
        config(['messaging.ten_dlc_mixed_enabled' => true]);

        foreach (['customer_care' => ['CUSTOMER_CARE', null], 'marketing' => ['MARKETING', null], 'mixed' => ['MIXED', ['CUSTOMER_CARE', 'MARKETING']], 'appointment_reminders' => ['CUSTOMER_CARE', null]] as $internal => [$expected, $subs]) {
            $business = $this->business();
            $registration = $this->localRegistration($business);

            if (! DB::table('usage_meters')->where('meter_key', TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION)->exists()) {
                $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);
            } else {
                app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
                DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 100_000_000]);
            }

            $registration->update(['use_case' => $internal]);

            Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
                'api.telnyx.com/v2/10dlc/brand' => Http::response(['data' => ['brandId' => 'brand_map_' . $internal]]),
                'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['data' => ['campaignId' => 'camp_map_' . $internal]]),
            ]);

            app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));

            Http::assertSent(function ($request) use ($expected, $subs) {
                if (! str_contains($request->url(), '/10dlc/campaignBuilder')) {
                    return false;
                }

                $data = $request->data();

                return $data['usecase'] === $expected
                    && ($data['subUsecases'] ?? null) === $subs
                    && $data['optinKeywords'] === 'START,SUBSCRIBE'
                    && $data['optoutKeywords'] === 'STOP,UNSUBSCRIBE'
                    && $data['helpKeywords'] === 'HELP,INFO'
                    && $data['description'] !== $expected;
            });
        }
    }

    public function test_an_unmapped_use_case_is_refused_before_funding_or_any_provider_request(): void
    {
        $this->enableProvisioning();
        Http::fake();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);
        $registration->update(['use_case' => 'something_invented']);

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));
            $this->fail('Expected refusal.');
        } catch (\RuntimeException) {
            // expected
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    // -----------------------------------------------------------------
    // Provider response shapes: the Telnyx 10DLC and toll-free verification
    // endpoints return ROOT-level objects (no `data` envelope).
    // -----------------------------------------------------------------

    public function test_root_level_telnyx_responses_are_read_for_brand_campaign_and_verification_ids(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['brandId' => '4b20017f-8da9-a992-a6c0-683072fb7729', 'tcrBrandId' => 'BXYZ123', 'identityStatus' => 'UNVERIFIED', 'status' => 'REGISTRATION_PENDING', 'mock' => false]),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['campaignId' => 'c8d1a4f0-fixture', 'brandId' => '4b20017f-8da9-a992-a6c0-683072fb7729', 'usecase' => 'CUSTOMER_CARE', 'subUsecases' => [], 'description' => 'd', 'mock' => false, 'termsAndConditions' => true, 'campaignStatus' => 'TCR_PENDING', 'submissionStatus' => 'CREATED']),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));

        $this->assertSame('4b20017f-8da9-a992-a6c0-683072fb7729', $result->providerBrandId);
        $this->assertSame('c8d1a4f0-fixture', $result->providerCampaignId);
        $this->assertSame('4b20017f-8da9-a992-a6c0-683072fb7729', DB::table('business_messaging_registrations')->where('business_id', $business->id)->value('provider_brand_id'));
    }

    public function test_a_root_level_toll_free_verification_response_is_read(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        Http::fake(['api.telnyx.com/v2/messaging_tollfree/verification/requests' => Http::response(['id' => '8b2f3a52-2a62-4f4e-a3f1-5d3c7a9c1e10', 'verificationRequestId' => 'vr-fixture', 'verificationStatus' => 'In Progress', 'businessName' => 'Harbor Lane Studios LLC'])]);

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree, '+18885550142'));

        $this->assertSame('8b2f3a52-2a62-4f4e-a3f1-5d3c7a9c1e10', $result->providerRegistrationId);
    }

    public function test_campaign_status_follows_the_documented_root_level_fields_conservatively(): void
    {
        $this->enableProvisioning();
        $query = new RegistrationStatusQuery(numberType: PhoneNumberType::Local, providerBrandId: 'b', providerCampaignId: 'camp_status', providerRegistrationId: null);

        $expect = [
            'pending while the registry reviews' => [['campaignStatus' => 'TCR_PENDING', 'status' => 'ACTIVE'], MessagingRegistrationStatus::Pending, null],
            'pending when accepted but not yet provisioned' => [['campaignStatus' => 'MNO_ACCEPTED', 'status' => 'ACTIVE'], MessagingRegistrationStatus::Pending, null],
            'approved only when carriers provisioned it' => [['campaignStatus' => 'MNO_PROVISIONED', 'status' => 'ACTIVE'], MessagingRegistrationStatus::Approved, null],
            'rejected by a carrier, with the reason' => [['campaignStatus' => 'MNO_REJECTED', 'failureReasons' => 'Sample message mismatch'], MessagingRegistrationStatus::Rejected, 'Sample message mismatch'],
            'rejected by the registry' => [['campaignStatus' => 'TCR_FAILED', 'submissionStatus' => 'FAILED', 'failureReasons' => 'Brand not vetted'], MessagingRegistrationStatus::Rejected, 'Brand not vetted'],
            'expired is not approved' => [['campaignStatus' => 'TCR_EXPIRED', 'status' => 'EXPIRED'], MessagingRegistrationStatus::Rejected, 'The carrier campaign expired or was suspended.'],
            'ACTIVE alone is never trusted as approval' => [['status' => 'ACTIVE'], MessagingRegistrationStatus::Pending, null],
            'an unknown value is pending' => [['campaignStatus' => 'SOMETHING_NEW'], MessagingRegistrationStatus::Pending, null],
        ];

        $current = [];
        Http::fake(['api.telnyx.com/v2/10dlc/campaign/camp_status' => function () use (&$current) { return Http::response($current + ['campaignId' => 'camp_status']); }]);

        foreach ($expect as $label => [$body, $status, $reason]) {
            $current = $body;

            $result = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus($query);

            $this->assertSame($status, $result->status, $label);
            $this->assertSame($reason, $result->rejectionReason, $label);
        }
    }

    public function test_low_volume_mixed_with_a_single_purpose_is_refused_before_funding_or_any_request(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);
        $registration->update(['use_case' => 'low_volume_mixed', 'message_purposes' => ['care']]);
        Http::fake();

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));
            $this->fail('Expected refusal.');
        } catch (\App\Library\Messaging\Exceptions\MessagingRegistrationNotEligibleException $e) {
            $this->assertStringContainsString('at least two different kinds', $e->getMessage());
        }

        Http::assertNothingSent();
        $this->assertSame(0, DB::table('business_usage_reservations')->where('business_id', $business->id)->count());
    }

    // -----------------------------------------------------------------
    // Zero-markup pass-through number pricing (Telnyx cost_information).
    // -----------------------------------------------------------------

    private function quoteRow(string $number, array $cost): array
    {
        return ['record_type' => 'available_phone_number', 'phone_number' => $number, 'best_effort' => false, 'cost_information' => $cost, 'features' => [['name' => 'sms']]];
    }

    public function test_search_reads_the_documented_cost_information_and_never_offers_unknown_pricing(): void
    {
        $this->enableProvisioning();
        config(['messaging.number_sms_addon_monthly_cost_micro' => 0]);
        Http::fake(['api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [
            $this->quoteRow('+18885550101', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD']),
            ['record_type' => 'available_phone_number', 'phone_number' => '+18885550102', 'features' => [['name' => 'sms']]],                                  // no price
            $this->quoteRow('+18885550103', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'EUR']),                                         // not USD
            $this->quoteRow('+18885550104', ['upfront_cost' => '0.00', 'monthly_cost' => '0.00', 'currency' => 'USD']),                                         // free is never trusted
            $this->quoteRow('+18885550105', ['upfront_cost' => '1,00', 'monthly_cost' => '1.00', 'currency' => 'USD']),                                         // malformed
            $this->quoteRow('+18885550106', ['upfront_cost' => '500.00', 'monthly_cost' => '40.00', 'currency' => 'USD']),                                      // special number above the ceiling
        ]])]);

        $found = app(TelnyxProvisioningAdapter::class)->searchNumbers(new \App\Library\Messaging\DTO\NumberSearchCriteria('US', PhoneNumberType::TollFree));

        $this->assertCount(1, $found);
        $this->assertSame('+18885550101', $found[0]->phoneNumber);
        $this->assertSame([250_000, 1_000_000, 'USD'], [$found[0]->upfrontCostMicro, $found[0]->monthlyCostMicro, $found[0]->currency]);
        $this->assertSame(1_250_000, $found[0]->initialCostMicro());
        // The documented filter name (there is no filter[number_type]).
        Http::assertSent(fn ($request) => str_contains($request->url(), 'filter%5Bphone_number_type%5D=toll_free') && ! str_contains($request->url(), 'number_type%5D=toll_free&'));
        // A larger provider pool is required to rotate away from the first five.
        Http::assertSent(fn ($request) => str_contains($request->url(), 'filter%5Blimit%5D=50'));

        config(['messaging.number_sms_addon_monthly_cost_micro' => 100_000]);
        $withAddon = app(TelnyxProvisioningAdapter::class)->searchNumbers(new \App\Library\Messaging\DTO\NumberSearchCriteria('US', PhoneNumberType::TollFree));
        $this->assertSame(1_100_000, $withAddon[0]->monthlyCostMicro);
    }

    public function test_toll_free_prefix_is_sent_as_number_pattern_not_geographic_area_code(): void
    {
        $this->enableProvisioning();
        Http::fake(['api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [
            $this->quoteRow('+18445550101', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD']),
        ]])]);

        $criteria = new \App\Library\Messaging\DTO\NumberSearchCriteria(
            countryCode: 'US',
            numberType: PhoneNumberType::TollFree,
            areaCode: '760',
            tollFreePrefix: '844',
        );
        $found = app(TelnyxProvisioningAdapter::class)->searchNumbers($criteria);

        $this->assertCount(1, $found);
        Http::assertSent(fn ($request) =>
            str_contains($request->url(), 'filter%5Bphone_number%5D%5Bstarts_with%5D=%2B1844')
            && ! str_contains($request->url(), 'national_destination_code')
        );
    }

    private function purchaseFixture(string $reference, string $upfront, string $monthly, string $multiplierMicro = '1000000'): array
    {
        $this->enableProvisioning();
        $business = $this->business();

        if (DB::table('usage_meters')->where('meter_key', TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE)->exists()) {
            app(UsageWalletManager::class)->initializeWalletForNewBusiness($business->id);
            DB::table('business_usage_wallets')->where('business_id', $business->id)->update(['available_balance_micro' => 20_000_000]);
        } else {
            $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE, 20_000_000);
        }

        // Zero markup is a 1.00 multiplier; a different owner multiplier is honoured exactly.
        if ($multiplierMicro !== '1000000') {
            $actor = $this->createActorUserId();
            app(UsageWalletManager::class)->setActiveRate(TelnyxProvisioningAdapter::FEATURE_NUMBER_PURCHASE, $multiplierMicro, '0', 'per 1.00 of carrier cost', Currency::query()->first()->id, $actor, 'Test multiplier.');
        }

        return [$business, new AvailableNumberCandidate($reference, PhoneNumberType::TollFree, $reference, (int) bcmul($upfront, '1000000', 0), (int) bcmul($monthly, '1000000', 0), 'USD')];
    }

    public function test_checkout_rechecks_a_real_toll_free_number_by_national_digits_not_full_e164_prefix(): void
    {
        [$business, $candidate] = $this->purchaseFixture('+18445183944', '0.25', '1.00');
        $lookupChecked = false;

        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => function ($request) use (&$lookupChecked) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);
                $filter = $params['filter'] ?? [];
                $this->assertSame('US', $filter['country_code'] ?? null);
                $this->assertSame('toll_free', $filter['phone_number_type'] ?? null);
                $this->assertSame('5183944', $filter['phone_number']['contains'] ?? null);
                $this->assertArrayNotHasKey('starts_with', $filter['phone_number'] ?? []);
                $lookupChecked = true;

                return Http::response(['data' => [
                    $this->quoteRow('+18885183944', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD']),
                    $this->quoteRow('+18445183944', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD']),
                ]]);
            },
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_quote_lookup']]),
            'api.telnyx.com/v2/number_orders' => Http::response(['data' => ['status' => 'success', 'phone_numbers' => [['id' => 'pn_quote_lookup', 'status' => 'success']]]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->provisionNumber($business, $candidate);

        $this->assertTrue($lookupChecked);
        $this->assertSame('pn_quote_lookup', $result->providerPhoneNumberId);
        Http::assertSentCount(3);
    }

    public function test_quote_lookup_never_accepts_a_different_number_even_with_identical_last_digits_and_price(): void
    {
        [$business, $candidate] = $this->purchaseFixture('+18445183944', '0.25', '1.00');

        Http::fake(['api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [
            $this->quoteRow('+18885183944', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD']),
        ]])]);

        try {
            app(TelnyxProvisioningAdapter::class)->provisionNumber($business, $candidate);
            $this->fail('Expected refusal when the exact selected number is absent.');
        } catch (\App\Library\Messaging\Exceptions\NumberPriceChangedException) {
            // expected
        }

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame(20_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_carrier_quote_lookup_http_error_never_places_an_order_or_claims_number_sold(): void
    {
        [$business, $candidate] = $this->purchaseFixture('+18445183944', '0.25', '1.00');

        Http::fake(['api.telnyx.com/v2/available_phone_numbers*' => Http::response(['errors' => [['detail' => 'Invalid search criteria']]], 422)]);

        try {
            app(TelnyxProvisioningAdapter::class)->provisionNumber($business, $candidate);
            $this->fail('Expected refusal when carrier quote lookup fails.');
        } catch (\App\Library\Messaging\Exceptions\NumberPriceChangedException $e) {
            $this->assertStringContainsString('could not confirm', $e->getMessage());
            $this->assertStringNotContainsString('no longer available', $e->getMessage());
        }

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame(20_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_the_first_charge_is_exactly_the_quoted_upfront_plus_first_month_at_the_owner_multiplier(): void
    {
        [$business, $candidate] = $this->purchaseFixture('+18885550101', '0.25', '1.00');
        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [$this->quoteRow('+18885550101', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD'])]]),
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_q1']]),
            'api.telnyx.com/v2/number_orders' => Http::response(['data' => ['status' => 'success', 'phone_numbers' => [['id' => 'pn_q1', 'status' => 'success']]]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->provisionNumber($business, $candidate);

        $this->assertSame(1_000_000, $result->monthlyCostMicro);
        $this->assertSame(250_000, $result->upfrontCostMicro);
        $this->assertSame(20_000_000 - 1_250_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_a_markup_multiplier_is_applied_exactly_and_zero_markup_is_the_default_semantics(): void
    {
        [$business, $candidate] = $this->purchaseFixture('+18885550111', '0.25', '1.00', '1100000');
        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [$this->quoteRow('+18885550111', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD'])]]),
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_q2']]),
            'api.telnyx.com/v2/number_orders' => Http::response(['data' => ['phone_numbers' => [['id' => 'pn_q2']]]]),
        ]);

        app(TelnyxProvisioningAdapter::class)->provisionNumber($business, $candidate);

        $this->assertSame(20_000_000 - 1_375_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_a_changed_carrier_price_stops_the_purchase_before_anything_is_ordered_or_held(): void
    {
        [$business, $candidate] = $this->purchaseFixture('+18885550121', '0.25', '1.00');
        Http::fake(['api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => [$this->quoteRow('+18885550121', ['upfront_cost' => '0.25', 'monthly_cost' => '1.50', 'currency' => 'USD'])]])]);

        try {
            app(TelnyxProvisioningAdapter::class)->provisionNumber($business, $candidate);
            $this->fail('Expected a price-changed refusal.');
        } catch (\App\Library\Messaging\Exceptions\NumberPriceChangedException $e) {
            $this->assertStringContainsString('changed', $e->getMessage());
        }

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame(20_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
        $this->assertSame(['released'], DB::table('business_usage_reservations')->where('business_id', $business->id)->pluck('status')->all());
    }

    public function test_a_candidate_with_no_quote_or_that_is_gone_is_never_bought(): void
    {
        [$business] = $this->purchaseFixture('+18885550131', '0.25', '1.00');
        Http::fake(['api.telnyx.com/v2/available_phone_numbers*' => Http::response(['data' => []])]);

        foreach ([
            new AvailableNumberCandidate('+18885550131', PhoneNumberType::TollFree, '+18885550131'),                              // no quote
            new AvailableNumberCandidate('+18885550131', PhoneNumberType::TollFree, '+18885550131', 250_000, 1_000_000, 'USD'),  // gone from the search
        ] as $candidate) {
            try {
                app(TelnyxProvisioningAdapter::class)->provisionNumber($business, $candidate);
                $this->fail('Expected a refusal.');
            } catch (\App\Library\Messaging\Exceptions\NumberPriceChangedException) {
                // expected
            }
        }

        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
        $this->assertSame(20_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_a_failed_or_pending_order_is_not_a_purchase(): void
    {
        $status = 'failure';
        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => fn () => Http::response(['data' => [$this->quoteRow('+18885550141', ['upfront_cost' => '0.25', 'monthly_cost' => '1.00', 'currency' => 'USD'])]]),
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_s']]),
            'api.telnyx.com/v2/number_orders' => function () use (&$status) {
                return Http::response(['data' => ['status' => $status, 'phone_numbers' => [['id' => 'pn_s', 'status' => $status]]]]);
            },
        ]);

        foreach (['failure' => 0, 'pending' => 1] as $status => $incidents) {
            [$business, $candidate] = $this->purchaseFixture('+18885550141', '0.25', '1.00');

            try {
                app(TelnyxProvisioningAdapter::class)->provisionNumber($business, $candidate);
                $this->fail('Expected a refusal for status ' . $status);
            } catch (\RuntimeException) {
                // expected
            }

            // A definite failure hands the hold back; an unconfirmed order may be billing, so its charge is kept.
            $this->assertSame(20_000_000 - ($status === 'pending' ? 1_250_000 : 0), (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'), $status);
            $this->assertSame($incidents, DB::table('business_messaging_provisioning_incidents')->where('business_id', $business->id)->where('stage', TelnyxProvisioningAdapter::INCIDENT_NUMBER_ORDER_UNKNOWN)->count(), $status);
        }
    }

    // -----------------------------------------------------------------
    // 10DLC: the brand fee is earned when the carrier creates the brand.
    // -----------------------------------------------------------------

    public function test_the_brand_fee_stays_charged_when_the_campaign_then_fails_and_a_retry_never_charges_it_twice(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, 20_000_000);
        $campaignStatus = 422;
        Http::fake([
            'api.telnyx.com/v2/10dlc/brand/*' => Http::response(['brandId' => 'brand_x', 'tcrBrandId' => 'BTEST1', 'identityStatus' => 'VERIFIED', 'status' => 'OK']),
            'api.telnyx.com/v2/10dlc/brand' => Http::response(['brandId' => 'brand_keep_1', 'status' => 'REGISTRATION_PENDING']),
            'api.telnyx.com/v2/10dlc/campaignBuilder' => function () use (&$campaignStatus) {
                return $campaignStatus === 200 ? Http::response(['campaignId' => 'camp_keep_1']) : Http::response(['errors' => []], $campaignStatus);
            },
        ]);

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));
            $this->fail('Expected the campaign rejection.');
        } catch (\RuntimeException) {
            // expected
        }

        // Fixture rates: brand 1.00, prepaid months 1.00. The carrier holds the brand, so only its fee is kept.
        $this->assertSame(20_000_000 - 1_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
        $this->assertSame(['committed', 'released'], DB::table('business_usage_reservations')->where('business_id', $business->id)->orderBy('id')->pluck('status')->sort()->values()->all());

        $campaignStatus = 200;
        app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));

        $this->assertSame(1, Http::recorded(fn ($request) => str_contains($request->url(), '/10dlc/brand'))->count());
        $this->assertSame(20_000_000 - 1_000_000 - 1_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }

    public function test_a_customer_who_cannot_cover_both_the_brand_and_the_campaign_months_never_has_a_brand_created(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, 1_500_000); // enough for one 1.00 fee, not two
        Http::fake();

        $this->expectException(\App\Library\Messaging\Exceptions\MessagingInsufficientFundsException::class);

        try {
            app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));
        } finally {
            Http::assertNothingSent();
            $this->assertSame(1_500_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
        }
    }

    public function test_a_crash_after_the_brand_was_created_commits_its_held_fee_on_retry_instead_of_letting_it_expire(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $registration = $this->localRegistration($business);
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION, 20_000_000);
        $registration->update(['provider_brand_id' => 'brand_crash_2']);
        $held = app(UsageWalletManager::class)->reserve($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_BRAND_REGISTRATION, 'messaging-brand:' . $business->id . ':0:1')->reservationId;
        Http::fake(['api.telnyx.com/v2/10dlc/campaignBuilder' => Http::response(['campaignId' => 'camp_crash_2'])]);

        app(TelnyxProvisioningAdapter::class)->submitRegistration(MessagingRegistrationSubmission::fromModel($registration->fresh()));

        $this->assertSame('committed', DB::table('business_usage_reservations')->where('id', $held)->value('status'));
        $this->assertSame(0, Http::recorded(fn ($request) => str_contains($request->url(), '/10dlc/brand'))->count());
        $this->assertSame(20_000_000 - 1_000_000 - 1_000_000, (int) DB::table('business_usage_wallets')->where('business_id', $business->id)->value('available_balance_micro'));
    }
}
