<?php

namespace Tests\Feature\Messaging;

use App\Enums\Entitlement\PlatformFeature;
use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
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
            'api.telnyx.com/v2/10dlc/campaign' => Http::response(['data' => ['campaignId' => 'campaign_fixture_1']]),
        ]);

        $result = app(TelnyxProvisioningAdapter::class)->submitRegistration($this->submission($business, PhoneNumberType::Local));

        $this->assertSame('brand_fixture_1', $result->providerBrandId);
        $this->assertSame('campaign_fixture_1', $result->providerCampaignId);
        $this->assertNull($result->providerRegistrationId);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/10dlc/brand'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/10dlc/campaign'));
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
}
