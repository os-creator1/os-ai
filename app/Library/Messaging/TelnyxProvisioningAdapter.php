<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\CarrierReleaseOutcome;
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
use App\Library\Messaging\DTO\CarrierReleaseResult;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\NumberReleaseQuery;
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
 *   - POST /10dlc/campaignBuilder                      live under the /10dlc/ prefix).
 *   - GET  /10dlc/campaign/{campaignId}                Phone Numbers + A2P lane
 *                                                      correction — campaign CREATION
 *                                                      is a distinct resource,
 *                                                      `campaignBuilder`, from campaign
 *                                                      RETRIEVAL/management, `campaign/
 *                                                      {campaignId}`; the original
 *                                                      Implementation Round 1 code
 *                                                      posted creation to the retrieval
 *                                                      path, which is not how Telnyx's
 *                                                      API is shaped. Verified directly
 *                                                      against developers.telnyx.com's
 *                                                      "Campaign Builder" and "Get My
 *                                                      Campaign" API reference pages.
 *                                                      Response field `campaignStatus`
 *                                                      confirmed by Telnyx's own "Get
 *                                                      My Campaign" API page; exact
 *                                                      non-ACTIVE status vocabulary
 *                                                      beyond ACTIVE/FAILED is NOT
 *                                                      independently confirmed field-
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
 *   - GET    /phone_numbers/{id}                       CONFIRMED path (developers.telnyx.com/
 *   - DELETE /phone_numbers/{id}                       api-reference/phone-number-configurations/
 *                                                      retrieve-a-phone-number and
 *                                                      .../api/numbers/delete-phone-number
 *                                                      — Phone Numbers + A2P lane
 *                                                      carrier-release boundary).
 *
 *                                                      Review correction — a bare 2xx or a
 *                                                      bare 404 on the DELETE call is NOT
 *                                                      by itself proof of anything: a 2xx
 *                                                      could echo the wrong resource (a
 *                                                      stale/corrupted provider reference
 *                                                      pointing at someone else's number),
 *                                                      and a 404 could mean the reference
 *                                                      was always wrong, not that this
 *                                                      platform's own prior attempt
 *                                                      actually succeeded. releaseNumber()
 *                                                      therefore verifies twice before
 *                                                      ever reporting Confirmed:
 *
 *                                                      1. GET the resource by
 *                                                         providerPhoneNumberId first.
 *                                                         Its own documented `phone_number`
 *                                                         field must match
 *                                                         $query->phoneNumber exactly — a
 *                                                         mismatch means the stored
 *                                                         reference does not identify the
 *                                                         number this platform believes it
 *                                                         is releasing, and this method
 *                                                         refuses to proceed to DELETE at
 *                                                         all (NotConfirmed). A 404 here is
 *                                                         likewise NotConfirmed, never
 *                                                         guessed as "already deleted" —
 *                                                         there is no identity to verify
 *                                                         against. If the lookup's own
 *                                                         documented `status` field already
 *                                                         reads "deleted" AND the identity
 *                                                         matches, that alone is Confirmed
 *                                                         (the safe way to recognize a
 *                                                         retry after an earlier attempt's
 *                                                         own response was lost to this
 *                                                         platform's network/timeout —
 *                                                         verified via retrieval, never
 *                                                         guessed from a delete 404).
 *                                                      2. Only once identity is verified
 *                                                         and the number is not already
 *                                                         confirmed deleted does this method
 *                                                         call DELETE. A 404 here is ALWAYS
 *                                                         NotConfirmed — per this
 *                                                         correction, never treated as
 *                                                         confirmed on its own. A 2xx
 *                                                         response is Confirmed only once
 *                                                         its own `id`/`phone_number` still
 *                                                         match AND its `status` field
 *                                                         reads "deleted"; any 2xx response
 *                                                         that cannot be verified that way
 *                                                         is ambiguous and NotConfirmed —
 *                                                         never guessed as a success.
 *
 *                                                      Every other response, or a
 *                                                      transport-level exception/timeout at
 *                                                      either step, is likewise
 *                                                      NotConfirmed — mirroring this
 *                                                      class's own "never guess Approved"
 *                                                      discipline for registration status.
 *                                                      Telnyx's own support documentation
 *                                                      additionally states a deleted number
 *                                                      then sits through a hold/ageing
 *                                                      period before anyone else can buy
 *                                                      it — that is the carrier's own
 *                                                      internal process and has no bearing
 *                                                      on this platform's own "no longer
 *                                                      ours" determination.
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

    /**
     * Phone Numbers + A2P lane — see this class's own docblock for the
     * full endpoint/verification rationale. Deliberately does NOT go
     * through reserveFunding(): releasing a number is not a purchase, and
     * this contract has no "release fee" concept — the wallet is never
     * touched here. Never mutates any local record; NumberLifecycleManager::
     * confirmCarrierRelease() is the only writer of the resulting local
     * state, under its own row lock.
     *
     * Two verified steps, never a single trusting call: a lookup that must
     * identity-match before anything is deleted, then a delete whose own
     * response must confirm both identity and deleted state before this
     * method ever reports Confirmed. A 404 at either step is NotConfirmed
     * — it is never treated as proof that a prior release already
     * succeeded.
     */
    public function releaseNumber(NumberReleaseQuery $query): CarrierReleaseResult
    {
        $lookup = $this->lookUpNumber($query);

        if ($lookup instanceof CarrierReleaseResult) {
            // A definitive outcome already — either a verified prior
            // deletion (Confirmed) or a lookup failure/mismatch
            // (NotConfirmed). Either way, DELETE is never called.
            return $lookup;
        }

        // $lookup is the resource's own current status string here — the
        // identity already matched, and it does not yet read "deleted".
        try {
            $response = $this->client()->delete(self::API_BASE . '/phone_numbers/' . $query->providerPhoneNumberId);
        } catch (\Throwable) {
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'delete_transport_error');
        }

        if ($response->status() === 404) {
            // Never proof of a prior successful release on its own — the
            // reference could simply be wrong. NumberLifecycleManager
            // records this as a visible, retryable failure rather than
            // ever guessing the number is gone.
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'delete_not_found');
        }

        if (! $response->successful()) {
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'delete_http_' . $response->status());
        }

        $responseId = $response->json('data.id');
        $responsePhoneNumber = $response->json('data.phone_number');
        $responseStatus = strtolower((string) $response->json('data.status', ''));

        $identityConfirmed = ($responseId === null || (string) $responseId === $query->providerPhoneNumberId)
            && $responsePhoneNumber === $query->phoneNumber;

        if (! $identityConfirmed || $responseStatus !== 'deleted') {
            // A 2xx response that does not itself confirm WHICH resource
            // was deleted, or does not confirm it was actually deleted, is
            // ambiguous — never guessed as a success.
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'delete_response_ambiguous');
        }

        return new CarrierReleaseResult(CarrierReleaseOutcome::Confirmed, 'delete_confirmed');
    }

    /**
     * Retrieves the resource and verifies its identity before any delete
     * is ever attempted. Returns a definitive CarrierReleaseResult when
     * there is nothing left for releaseNumber() to do (a lookup failure,
     * an identity mismatch, or a lookup that already shows the number
     * deleted); otherwise returns the resource's own current status string
     * so releaseNumber() knows it is safe to proceed to DELETE.
     */
    private function lookUpNumber(NumberReleaseQuery $query): CarrierReleaseResult|string
    {
        try {
            $response = $this->client()->get(self::API_BASE . '/phone_numbers/' . $query->providerPhoneNumberId);
        } catch (\Throwable) {
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'lookup_transport_error');
        }

        if ($response->status() === 404) {
            // Not, by itself, evidence of anything — never guessed as
            // "already deleted" without a matching identity to verify.
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'lookup_not_found');
        }

        if (! $response->successful()) {
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'lookup_http_' . $response->status());
        }

        $phoneNumber = $response->json('data.phone_number');

        if ($phoneNumber !== $query->phoneNumber) {
            // The stored provider reference does not identify the number
            // this platform believes it is releasing — refuses to
            // proceed to DELETE at all.
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'lookup_phone_number_mismatch');
        }

        $status = strtolower((string) $response->json('data.status', ''));

        if ($status === 'deleted') {
            // Verified via retrieval, never guessed from a delete 404 —
            // the safe way to recognize a retry after an earlier attempt's
            // own response was lost to this platform's own network/timeout.
            return new CarrierReleaseResult(CarrierReleaseOutcome::Confirmed, 'lookup_already_deleted');
        }

        return $status;
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

            $campaignResponse = $this->client()->post(self::API_BASE . '/10dlc/campaignBuilder', [
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
