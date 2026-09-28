<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
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
    }

    private function submission(Business $business, PhoneNumberType $numberType): MessagingRegistrationSubmission
    {
        return new MessagingRegistrationSubmission(
            businessId: $business->id,
            numberType: $numberType,
            legalBusinessName: 'Harbor Lane Studios LLC',
            entityType: MessagingEntityType::Ein,
            ein: '12-3456789',
            addressLine1: '1 Harbor Lane',
            addressLine2: null,
            city: 'Portland',
            region: 'OR',
            postalCode: '97201',
            countryCode: 'US',
            websiteUrl: 'https://harborlane.example',
            contactEmail: 'owner@harborlane.example',
            contactPhone: '+15035550100',
            useCase: 'appointment_reminders',
            optInMethod: 'Customers check a box on our booking form.',
            sampleMessage1: 'Harbor Lane: your appointment is confirmed. Reply STOP to unsubscribe.',
            sampleMessage2: 'Harbor Lane: reminder, your appointment is tomorrow.',
            privacyPolicyUrl: 'https://harborlane.example/privacy',
            termsUrl: 'https://harborlane.example/terms',
        );
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
            app(TelnyxProvisioningAdapter::class)->provisionNumber($business, new AvailableNumberCandidate('+14155550500', PhoneNumberType::Local, 'candidate-ref-real-1'));
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
            'api.telnyx.com/v2/messaging_profiles' => Http::response(['data' => ['id' => 'mp_fixture_1']]),
            'api.telnyx.com/v2/number_orders' => Http::response(['data' => ['phone_numbers' => [['id' => 'pn_fixture_1']]]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->provisionNumber($business, new AvailableNumberCandidate('+14155550501', PhoneNumberType::Local, 'candidate-ref-real-2'));

        $this->assertSame('mp_fixture_1', $result->messagingProfileId);
        $this->assertSame('pn_fixture_1', $result->providerPhoneNumberId);
        Http::assertSentCount(2);
    }

    public function test_10dlc_submission_hits_the_10dlc_prefixed_endpoints_once_funded(): void
    {
        $this->enableProvisioning();
        $business = $this->business();
        $this->fundWallet($business, TelnyxProvisioningAdapter::FEATURE_TEN_DLC_REGISTRATION);

        Http::fake([
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

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree));

        $this->assertNull($result->providerBrandId);
        $this->assertNull($result->providerCampaignId);
        $this->assertSame('tfv_fixture_1', $result->providerRegistrationId);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messaging_tollfree/verification/requests')
            && ! str_contains($request->url(), 'verification_requests'));
    }

    public function test_refresh_status_routes_10dlc_to_the_10dlc_campaign_endpoint(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/campaign/campaign_fixture_2' => Http::response(['data' => ['campaignStatus' => 'ACTIVE']]),
        ]);

        $status = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::Local,
            providerBrandId: 'brand_fixture_2',
            providerCampaignId: 'campaign_fixture_2',
            providerRegistrationId: null,
        ));

        $this->assertSame(MessagingRegistrationStatus::Approved, $status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/10dlc/campaign/campaign_fixture_2'));
    }

    public function test_refresh_status_routes_toll_free_to_its_own_verification_endpoint(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/messaging_tollfree/verification/requests/tfv_fixture_2' => Http::response(['data' => ['verificationStatus' => 'Verified']]),
        ]);

        $status = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::TollFree,
            providerBrandId: null,
            providerCampaignId: null,
            providerRegistrationId: 'tfv_fixture_2',
        ));

        $this->assertSame(MessagingRegistrationStatus::Approved, $status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messaging_tollfree/verification/requests/tfv_fixture_2'));
    }

    public function test_refresh_status_never_guesses_approved_for_an_unrecognised_toll_free_value(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/messaging_tollfree/verification/requests/tfv_fixture_3' => Http::response(['data' => ['verificationStatus' => 'Waiting for Telnyx']]),
        ]);

        $status = app(TelnyxProvisioningAdapter::class)->refreshRegistrationStatus(new RegistrationStatusQuery(
            numberType: PhoneNumberType::TollFree,
            providerBrandId: null,
            providerCampaignId: null,
            providerRegistrationId: 'tfv_fixture_3',
        ));

        $this->assertSame(MessagingRegistrationStatus::Pending, $status);
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
}
