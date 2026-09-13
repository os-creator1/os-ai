<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\DTO\ProvisionedNumberResult;
use App\Library\Messaging\DTO\RegistrationSubmissionResult;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Models\Business;
use Illuminate\Support\Facades\Http;

/**
 * Text messaging setup/number/compliance hub — the production-shaped
 * Telnyx provisioning adapter, developed and exercised exclusively
 * against Http::fake(); no live Telnyx account, number, profile, brand or
 * campaign is ever created by this class or by any test of it.
 *
 * VERIFY BEFORE ENABLING WITH A REAL KEY. Endpoint paths and payload
 * shapes below are constructed from Telnyx's documented v2 REST
 * conventions (the same ones TelnyxMessagingAdapter's own /v2/messages
 * call already uses successfully) — number search/order, Messaging
 * Profile creation, and 10DLC/toll-free registration are NOT exercised
 * anywhere else in this codebase yet, so unlike /v2/messages this class's
 * exact request/response shape has not been confirmed against a live
 * Telnyx account. Confirm against Telnyx's current API reference (or a
 * recorded real response) before config('messaging.managed_messaging_enabled')
 * is ever turned on in an environment holding a real API key.
 *
 * Same fail-closed activation as TelnyxMessagingAdapter (§4.4): both the
 * platform kill switch and the API key must be present before any request
 * can be built, checked once in the constructor.
 */
class TelnyxProvisioningAdapter implements MessagingProvisioningAdapter
{
    private const API_BASE = 'https://api.telnyx.com/v2';

    private const TIMEOUT_SECONDS = 20;

    private readonly string $apiKey;

    public function __construct()
    {
        if (! config('messaging.managed_messaging_enabled')) {
            throw MessagingProviderNotConfiguredException::disabled();
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

    public function provisionNumber(Business $business, AvailableNumberCandidate $candidate): ProvisionedNumberResult
    {
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
            throw new \RuntimeException('Telnyx rejected the number order request.');
        }

        $providerPhoneNumberId = (string) ($orderResponse->json('data.phone_numbers.0.id') ?? $orderResponse->json('data.id'));

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

    public function refreshRegistrationStatus(string $providerBrandId, string $providerCampaignId): MessagingRegistrationStatus
    {
        $response = $this->client()->get(self::API_BASE . '/campaign/' . $providerCampaignId);

        if (! $response->successful()) {
            return MessagingRegistrationStatus::Pending;
        }

        return match ((string) $response->json('data.campaignStatus', $response->json('data.status', ''))) {
            'APPROVED', 'VERIFIED' => MessagingRegistrationStatus::Approved,
            'REJECTED', 'FAILED' => MessagingRegistrationStatus::Rejected,
            default => MessagingRegistrationStatus::Pending,
        };
    }

    private function submitTenDlcRegistration(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        $brandResponse = $this->client()->post(self::API_BASE . '/brand', [
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

        $campaignResponse = $this->client()->post(self::API_BASE . '/campaign', [
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
            throw new \RuntimeException('Telnyx rejected the 10DLC campaign submission.');
        }

        $campaignId = (string) $campaignResponse->json('data.campaignId', $campaignResponse->json('data.id'));

        return new RegistrationSubmissionResult($brandId, $campaignId, MessagingRegistrationStatus::Pending);
    }

    private function submitTollFreeVerification(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        $response = $this->client()->post(self::API_BASE . '/messaging_tollfree_verification_requests', [
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

        // Toll-free verification has no separate "brand" concept; the
        // verification request id fills both opaque reference fields so
        // refreshRegistrationStatus() has one id to poll either way.
        $requestId = (string) $response->json('data.verificationId', $response->json('data.id'));

        return new RegistrationSubmissionResult($requestId, $requestId, MessagingRegistrationStatus::Pending);
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withToken($this->apiKey)
            ->timeout(self::TIMEOUT_SECONDS)
            ->acceptJson()
            ->asJson();
    }
}
