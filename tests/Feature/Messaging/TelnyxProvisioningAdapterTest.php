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
    }

    private function submission(Business $business, PhoneNumberType $numberType, ?string $phoneNumber = null): MessagingRegistrationSubmission
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
            phoneNumber: $phoneNumber,
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

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::TollFree, '+18005550100'));

        $this->assertNull($result->providerBrandId);
        $this->assertNull($result->providerCampaignId);
        $this->assertSame('tfv_fixture_1', $result->providerRegistrationId);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messaging_tollfree/verification/requests')
            && ! str_contains($request->url(), 'verification_requests'));
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
            'api.telnyx.com/v2/10dlc/campaign/campaign_fixture_2' => Http::response(['data' => ['campaignStatus' => 'ACTIVE']]),
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

    public function test_assign_messaging_profile_to_campaign_hits_the_confirmed_endpoint_and_reports_requested(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile' => Http::response(['data' => ['taskId' => 'task_fixture_1']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->assignMessagingProfileToCampaign('mp_fixture_1', 'campaign_fixture_1');

        $this->assertSame(CampaignAssignmentOutcome::Requested, $result->outcome);
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
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile' => Http::response(['data' => []]),
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
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers' => Http::response(['records' => [
                ['taskId' => 'task_fixture_4', 'phoneNumber' => '+14155550100', 'status' => 'completed'],
            ]]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_4', '+14155550100');

        $this->assertSame(CampaignAssignmentOutcome::Confirmed, $result->outcome);
        Http::assertSent(fn ($request) => $request->method() === 'GET'
            && str_contains($request->url(), '/10dlc/phoneNumberAssignmentByProfile/task_fixture_4/phoneNumbers'));
    }

    public function test_check_campaign_assignment_status_reports_failed_on_a_failed_record(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers' => Http::response(['records' => [
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
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers' => Http::response(['records' => [
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
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers' => Http::response(['records' => [
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
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers' => Http::response(['errors' => [['title' => 'Internal Server Error']]], 500),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_8', '+14155550104');

        $this->assertSame(CampaignAssignmentOutcome::Requested, $result->outcome, 'A poll failure is retried later, never guessed as a terminal outcome.');
    }

    public function test_check_campaign_assignment_status_stays_requested_on_a_transport_exception(): void
    {
        $this->enableProvisioning();
        Http::fake([
            'api.telnyx.com/v2/10dlc/phoneNumberAssignmentByProfile/*/phoneNumbers' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Simulated network failure.'),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->checkCampaignAssignmentStatus('task_fixture_9', '+14155550105');

        $this->assertSame(CampaignAssignmentOutcome::Requested, $result->outcome);
    }
}
