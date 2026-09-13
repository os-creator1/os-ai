<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Exceptions\Usage\NoActiveRateForFeatureException;
use App\Exceptions\Usage\UsageMeterBusinessScopeMismatchException;
use App\Exceptions\Usage\UsageMeterCurrencyMismatchException;
use App\Exceptions\Usage\UsageMeterNotMeteredException;
use App\Exceptions\Usage\UsageMeterRateIntegrityException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\DTO\ProvisionedNumberResult;
use App\Library\Messaging\DTO\RegistrationStatusQuery;
use App\Library\Messaging\DTO\RegistrationSubmissionResult;
use App\Library\Messaging\Exceptions\MessagingFundingUnavailableException;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Usage\UsageWalletManager;
use App\Models\Business;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Text messaging setup/number/compliance hub — the production-shaped
 * Telnyx provisioning adapter, developed and exercised exclusively
 * against Http::fake(); no live Telnyx account, number, profile, brand or
 * campaign is ever created by this class or by any test of it.
 *
 * PR #295 Correction Round 1 (item 10) — endpoint verification status,
 * checked against Telnyx's current documented API surface via web search
 * (developers.telnyx.com is not reachable from this environment's egress
 * proxy, so page titles/snippets are the authoritative source used, not a
 * live account):
 *
 *   - GET  /available_phone_numbers                  CONFIRMED path (also already
 *                                                      matched TelnyxMessagingAdapter's
 *                                                      own established conventions).
 *   - POST /messaging_profiles                        CONFIRMED path.
 *   - POST /number_orders                              CONFIRMED path.
 *   - POST /10dlc/brand                                CONFIRMED path (10DLC endpoints
 *   - POST /10dlc/campaign                             live under the /10dlc/ prefix,
 *   - GET  /10dlc/campaign/{campaignId}                which the original Implementation
 *                                                      Round 1 code omitted — corrected
 *                                                      here). Response field
 *                                                      `campaignStatus` confirmed by
 *                                                      Telnyx's own "Get My Campaign"
 *                                                      API page; exact non-ACTIVE status
 *                                                      vocabulary beyond ACTIVE/FAILED is
 *                                                      NOT independently confirmed field-
 *                                                      by-field — mapped conservatively
 *                                                      (unrecognised values stay Pending,
 *                                                      never guessed Approved).
 *   - POST /messaging_tollfree/verification/requests   CONFIRMED path (the original
 *   - GET  /messaging_tollfree/verification/requests/{id}
 *                                                      Implementation Round 1 guess —
 *                                                      /messaging_tollfree_verification_requests
 *                                                      — was wrong and is corrected here).
 *                                                      Response field `verificationStatus`
 *                                                      confirmed, with documented values
 *                                                      "Waiting for Telnyx" / "Waiting For
 *                                                      Customer" / "Verified"; the exact
 *                                                      rejection-state string was not
 *                                                      independently confirmed, so a
 *                                                      conservative substring match
 *                                                      ("reject", "declin", "fail") is used
 *                                                      and everything else stays Pending —
 *                                                      never guessed Approved.
 *
 * None of this can run with a real credential today regardless: every
 * write path here also requires config('messaging.managed_messaging_provisioning_enabled')
 * (default false, item 5) AND a successful wallet funding reservation
 * (item 4) AND config('messaging.managed_messaging_enabled') AND a real API
 * key — so an unconfirmed field mapping above cannot cause a live paid
 * mistake without a deliberate, multi-gate production rollout decision
 * this correction round does not make.
 *
 * Same fail-closed activation as TelnyxMessagingAdapter (§4.4): the
 * platform kill switch, the provisioning-specific gate, and the API key
 * must all be present before any request can be built, checked once in
 * the constructor.
 */
class TelnyxProvisioningAdapter implements MessagingProvisioningAdapter
{
    private const API_BASE = 'https://api.telnyx.com/v2';

    private const TIMEOUT_SECONDS = 20;

    public const FEATURE_NUMBER_PURCHASE = 'messaging_number_purchase';

    public const FEATURE_TEN_DLC_REGISTRATION = 'messaging_10dlc_registration';

    public const FEATURE_TOLL_FREE_VERIFICATION = 'messaging_tollfree_verification';

    private readonly string $apiKey;

    public function __construct(private readonly UsageWalletManager $wallet)
    {
        if (! config('messaging.managed_messaging_enabled')) {
            throw MessagingProviderNotConfiguredException::disabled();
        }

        // PR #295 Correction Round 1, item 5 — a separate, default-OFF gate
        // for live number purchase/registration specifically; normal
        // managed SMS sending (TelnyxMessagingAdapter) does not check this.
        if (! config('messaging.managed_messaging_provisioning_enabled')) {
            throw MessagingProviderNotConfiguredException::missingKey('messaging.managed_messaging_provisioning_enabled');
        }

        $apiKey = (string) (config('services.telnyx.api_key') ?? '');

        if ($apiKey === '') {
            throw MessagingProviderNotConfiguredException::missingKey('services.telnyx.api_key');
        }

        $this->apiKey = $apiKey;
    }

    public function searchNumbers(NumberSearchCriteria $criteria): array
    {
        $filter = [
            'filter[country_code]' => $criteria->countryCode,
            'filter[features][]' => 'sms',
            'filter[limit]' => 1,
        ];

        if ($criteria->numberType === PhoneNumberType::TollFree) {
            $filter['filter[number_type]'] = 'toll_free';
        } elseif ($criteria->areaCode !== null && $criteria->areaCode !== '') {
            $filter['filter[national_destination_code]'] = $criteria->areaCode;
        }

        $response = $this->client()->get(self::API_BASE . '/available_phone_numbers', $filter);

        if (! $response->successful()) {
            return [];
        }

        $candidates = [];

        foreach ((array) $response->json('data', []) as $row) {
            if (! is_array($row) || ! isset($row['phone_number'])) {
                continue;
            }

            $candidates[] = new AvailableNumberCandidate(
                phoneNumber: (string) $row['phone_number'],
                numberType: $criteria->numberType,
                providerCandidateReference: (string) $row['phone_number'],
            );
        }

        return $candidates;
    }

    /**
     * PR #295 Correction Round 1, item 4 — the funding reservation happens
     * BEFORE either HTTP call, so an unfunded Business never reaches
     * Telnyx at all. Item 3 — the caller (BusinessMessagingProvisioningService)
     * has already durably reserved the local identity slot before this
     * method is ever invoked; this method's own job is only to make the
     * provider commitment itself funded and reconciled.
     */
    public function provisionNumber(Business $business, AvailableNumberCandidate $candidate): ProvisionedNumberResult
    {
        $reservationId = $this->reserveFunding($business, self::FEATURE_NUMBER_PURCHASE);

        try {
            $profileResponse = $this->client()->post(self::API_BASE . '/messaging_profiles', [
                'name' => sprintf('Business #%d', $business->id),
            ]);

            if (! $profileResponse->successful()) {
                throw new \RuntimeException('Telnyx rejected the Messaging Profile request.');
            }

            $messagingProfileId = (string) $profileResponse->json('data.id');

            $orderResponse = $this->client()->post(self::API_BASE . '/number_orders', [
                'phone_numbers' => [['phone_number' => $candidate->providerCandidateReference]],
                'messaging_profile_id' => $messagingProfileId,
            ]);

            if (! $orderResponse->successful()) {
                // The Messaging Profile above WAS genuinely created on
                // Telnyx before this second call failed — that resource
                // must not become invisible just because the local flow
                // stops here.
                app(ProvisioningIncidentRecorder::class)->record(
                    business: $business,
                    stage: 'messaging_profile_created_but_number_order_failed',
                    messagingProfileId: $messagingProfileId,
                    providerPhoneNumberId: null,
                    phoneNumber: $candidate->phoneNumber,
                    numberType: $candidate->numberType->value,
                    errorMessage: (string) $orderResponse->status(),
                );

                throw new \RuntimeException('Telnyx rejected the number order request.');
            }

            $providerPhoneNumberId = (string) ($orderResponse->json('data.phone_numbers.0.id') ?? $orderResponse->json('data.id'));
        } catch (\Throwable $e) {
            $this->wallet->release($reservationId);

            throw $e;
        }

        $this->wallet->commit($reservationId);

        return new ProvisionedNumberResult(
            messagingProfileId: $messagingProfileId,
            providerPhoneNumberId: $providerPhoneNumberId,
            phoneNumber: $candidate->phoneNumber,
        );
    }

    public function submitRegistration(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        if ($submission->numberType === PhoneNumberType::TollFree) {
            return $this->submitTollFreeVerification($submission);
        }

        return $this->submitTenDlcRegistration($submission);
    }

    /**
     * PR #295 Correction Round 1, item 7 — routes to the correct real
     * status endpoint per regime instead of one shared, and for toll-free
     * fictitious, "/campaign/{id}" call.
     */
    public function refreshRegistrationStatus(RegistrationStatusQuery $query): MessagingRegistrationStatus
    {
        if ($query->numberType === PhoneNumberType::TollFree) {
            return $this->refreshTollFreeVerificationStatus($query);
        }

        return $this->refreshTenDlcCampaignStatus($query);
    }

    private function submitTenDlcRegistration(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        $business = Business::query()->findOrFail($submission->businessId);
        $reservationId = $this->reserveFunding($business, self::FEATURE_TEN_DLC_REGISTRATION);

        try {
            $brandResponse = $this->client()->post(self::API_BASE . '/10dlc/brand', [
                'entityType' => $submission->entityType === MessagingEntityType::Ein ? 'PRIVATE_PROFIT' : 'SOLE_PROPRIETOR',
                'displayName' => $submission->legalBusinessName,
                'companyName' => $submission->legalBusinessName,
                'ein' => $submission->ein,
                'email' => $submission->contactEmail,
                'phone' => $submission->contactPhone,
                'street' => $submission->addressLine1,
                'city' => $submission->city,
                'state' => $submission->region,
                'postalCode' => $submission->postalCode,
                'country' => $submission->countryCode,
                'website' => $submission->websiteUrl,
            ]);

            if (! $brandResponse->successful()) {
                throw new \RuntimeException('Telnyx rejected the 10DLC brand submission.');
            }

            $brandId = (string) $brandResponse->json('data.brandId', $brandResponse->json('data.id'));

            $campaignResponse = $this->client()->post(self::API_BASE . '/10dlc/campaign', [
                'brandId' => $brandId,
                'usecase' => $submission->useCase,
                'description' => $submission->useCase,
                'sample1' => $submission->sampleMessage1,
                'sample2' => $submission->sampleMessage2,
                'messageFlow' => $submission->optInMethod,
                'privacyPolicyLink' => $submission->privacyPolicyUrl,
                'termsAndConditionsLink' => $submission->termsUrl,
                'optinKeywords' => 'START, SUBSCRIBE',
                'optoutKeywords' => 'STOP, UNSUBSCRIBE',
                'helpKeywords' => 'HELP, INFO',
            ]);

            if (! $campaignResponse->successful()) {
                app(ProvisioningIncidentRecorder::class)->record(
                    business: $business,
                    stage: '10dlc_brand_created_but_campaign_failed',
                    messagingProfileId: null,
                    providerPhoneNumberId: null,
                    phoneNumber: null,
                    numberType: PhoneNumberType::Local->value,
                    errorMessage: 'brand_id=' . $brandId . ' status=' . $campaignResponse->status(),
                );

                throw new \RuntimeException('Telnyx rejected the 10DLC campaign submission.');
            }

            $campaignId = (string) $campaignResponse->json('data.campaignId', $campaignResponse->json('data.id'));
        } catch (\Throwable $e) {
            $this->wallet->release($reservationId);

            throw $e;
        }

        $this->wallet->commit($reservationId);

        return new RegistrationSubmissionResult($brandId, $campaignId, null, MessagingRegistrationStatus::Pending);
    }

    private function submitTollFreeVerification(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        $business = Business::query()->findOrFail($submission->businessId);
        $reservationId = $this->reserveFunding($business, self::FEATURE_TOLL_FREE_VERIFICATION);

        try {
            $response = $this->client()->post(self::API_BASE . '/messaging_tollfree/verification/requests', [
                'businessName' => $submission->legalBusinessName,
                'businessAddr1' => $submission->addressLine1,
                'businessCity' => $submission->city,
                'businessState' => $submission->region,
                'businessZip' => $submission->postalCode,
                'businessContactEmail' => $submission->contactEmail,
                'businessContactPhone' => $submission->contactPhone,
                'websiteUrl' => $submission->websiteUrl,
                'useCaseCategories' => [$submission->useCase],
                'useCaseSummary' => $submission->useCase,
                'productionMessageContent' => $submission->sampleMessage1,
                'optInWorkflow' => $submission->optInMethod,
                'privacyPolicyUrl' => $submission->privacyPolicyUrl,
                'termsAndConditionsUrl' => $submission->termsUrl,
            ]);

            if (! $response->successful()) {
                throw new \RuntimeException('Telnyx rejected the toll-free verification submission.');
            }

            $requestId = (string) $response->json('data.id', $response->json('data.verificationId'));
        } catch (\Throwable $e) {
            $this->wallet->release($reservationId);

            throw $e;
        }

        $this->wallet->commit($reservationId);

        // PR #295 Correction Round 1, item 7 — toll-free has no
        // brand/campaign concept; the single opaque id goes into its own
        // honest field, never duplicated into provider_brand_id/
        // provider_campaign_id.
        return new RegistrationSubmissionResult(null, null, $requestId, MessagingRegistrationStatus::Pending);
    }

    private function refreshTenDlcCampaignStatus(RegistrationStatusQuery $query): MessagingRegistrationStatus
    {
        if ($query->providerCampaignId === null) {
            return MessagingRegistrationStatus::Pending;
        }

        $response = $this->client()->get(self::API_BASE . '/10dlc/campaign/' . $query->providerCampaignId);

        if (! $response->successful()) {
            return MessagingRegistrationStatus::Pending;
        }

        $status = strtoupper((string) $response->json('data.campaignStatus', $response->json('data.status', '')));

        return match (true) {
            $status === 'ACTIVE' || $status === 'APPROVED' || $status === 'VERIFIED' => MessagingRegistrationStatus::Approved,
            $status === 'FAILED' || $status === 'REJECTED' || $status === 'DECLINED' => MessagingRegistrationStatus::Rejected,
            default => MessagingRegistrationStatus::Pending,
        };
    }

    private function refreshTollFreeVerificationStatus(RegistrationStatusQuery $query): MessagingRegistrationStatus
    {
        if ($query->providerRegistrationId === null) {
            return MessagingRegistrationStatus::Pending;
        }

        $response = $this->client()->get(self::API_BASE . '/messaging_tollfree/verification/requests/' . $query->providerRegistrationId);

        if (! $response->successful()) {
            return MessagingRegistrationStatus::Pending;
        }

        $status = strtolower((string) $response->json('data.verificationStatus', $response->json('data.status', '')));

        if ($status === 'verified') {
            return MessagingRegistrationStatus::Approved;
        }

        if (str_contains($status, 'reject') || str_contains($status, 'declin') || str_contains($status, 'fail')) {
            return MessagingRegistrationStatus::Rejected;
        }

        // Covers the confirmed documented pending states ("Waiting for
        // Telnyx", "Waiting For Customer") and any other value this
        // platform has not seen before — never guessed Approved.
        return MessagingRegistrationStatus::Pending;
    }

    /**
     * PR #295 Correction Round 1, item 4 — reuses UsageWalletManager
     * exclusively (no second balance system, no invented retail price).
     * Neither a UsageMeter row nor an approved rate exists yet for any of
     * these three feature keys in this repository, so reserve() throws one
     * of the "not configured" exceptions below for every real call today —
     * which is exactly the required behaviour: the real cost-incurring
     * path stays impossible until an owner-approved rate activates it. A
     * later slice that adds the rate needs no code change here.
     */
    private function reserveFunding(Business $business, string $featureKey): int
    {
        try {
            $result = $this->wallet->reserve($business, $featureKey, (string) Str::uuid());
        } catch (UsageWalletNotFoundException|NoActiveRateForFeatureException|UsageMeterNotMeteredException|UsageMeterBusinessScopeMismatchException|UsageMeterCurrencyMismatchException|UsageMeterRateIntegrityException $e) {
            throw new MessagingFundingUnavailableException(sprintf(
                'Funding is not yet configured for [%s]: %s',
                $featureKey,
                $e->getMessage(),
            ), 0, $e);
        }

        if (! $result->granted || $result->reservationId === null) {
            throw new MessagingInsufficientFundsException($result->denialReason);
        }

        return $result->reservationId;
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->asJson();
    }
}
