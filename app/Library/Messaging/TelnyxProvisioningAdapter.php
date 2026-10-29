<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\CampaignAssignmentOutcome;
use App\Enums\Messaging\CarrierReleaseOutcome;
use App\Enums\Messaging\MessagingEntityType;
use App\Enums\Messaging\MessagingRegistrationStatus;
use App\Enums\Messaging\PhoneNumberType;
use App\Exceptions\Usage\NoActiveRateForFeatureException;
use App\Exceptions\Usage\UsageMeterBusinessScopeMismatchException;
use App\Exceptions\Usage\UsageMeterCurrencyMismatchException;
use App\Exceptions\Usage\UsageMeterNotMeteredException;
use App\Exceptions\Usage\UsageMeterRateIntegrityException;
use App\Exceptions\Usage\BusinessCurrencyUnresolvableException;
use App\Exceptions\Usage\UsageWalletNotFoundException;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\CampaignAssignmentResult;
use App\Library\Messaging\DTO\CarrierReleaseResult;
use App\Library\Messaging\DTO\MessagingRegistrationSubmission;
use App\Library\Messaging\DTO\NumberReleaseQuery;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\DTO\ProvisionedNumberResult;
use App\Library\Messaging\DTO\RegistrationStatusQuery;
use App\Library\Messaging\DTO\RegistrationStatusResult;
use App\Library\Messaging\DTO\BrandStatusResult;
use App\Library\Messaging\DTO\RegistrationSubmissionResult;
use App\Library\Messaging\Exceptions\MessagingFundingUnavailableException;
use App\Library\Messaging\Registration\CarrierRegistrationOptions;
use App\Library\Messaging\Exceptions\MessagingInsufficientFundsException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Library\Messaging\Exceptions\NumberPriceChangedException;
use App\Library\Messaging\Exceptions\MessagingRegistrationNotEligibleException;
use App\Library\Messaging\Exceptions\MessagingRegistrationRecoveryRequiredException;
use App\Library\Usage\UsageWalletManager;
use App\Enums\Usage\UsageReservationStatus;
use App\Models\Business;
use App\Models\BusinessMessagingProvisioningIncident;
use App\Models\BusinessMessagingRegistration;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use Illuminate\Http\Client\ConnectionException;
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
 *                                                      My Campaign" API page; the ACTIVE
 *                                                      success value and the REJECTED/
 *                                                      FAILED/DECLINED rejection family
 *                                                      (matched by substring — covers the
 *                                                      documented MNO_REJECTED,
 *                                                      TCR_FAILED, TELNYX_FAILED and
 *                                                      MNO_PROVISIONING_FAILED values
 *                                                      none of which equalled this
 *                                                      class's own original bare-string
 *                                                      match) are the only ones this
 *                                                      class treats as terminal;
 *                                                      everything else stays Pending —
 *                                                      never guessed Approved. A rejected
 *                                                      campaign's reason is read from
 *                                                      `failureReasons`/`reasons` when
 *                                                      present (see extractReason()'s own
 *                                                      docblock for the confirmation
 *                                                      caveat); null otherwise.
 *   - POST /messaging_tollfree/verification/requests   CONFIRMED path (the original
 *   - GET  /messaging_tollfree/verification/requests/{id}
 *                                                      Implementation Round 1 guess —
 *                                                      /messaging_tollfree_verification_requests
 *                                                      — was wrong and is corrected here).
 *                                                      Review correction — the submission
 *                                                      body is documented to require a
 *                                                      `phoneNumbers` array naming the
 *                                                      already-owned number being
 *                                                      verified; the original
 *                                                      implementation omitted it entirely.
 *                                                      Response field `verificationStatus`
 *                                                      confirmed, with documented values
 *                                                      "Waiting for Telnyx" / "Waiting For
 *                                                      Customer" / "Verified"; the exact
 *                                                      rejection-state string was not
 *                                                      independently confirmed, so a
 *                                                      conservative substring match
 *                                                      ("reject", "declin", "fail") is used
 *                                                      and everything else stays Pending —
 *                                                      never guessed Approved. A rejected
 *                                                      verification's reason is read from
 *                                                      `declineReason`/`rejectionReason`/
 *                                                      `reason` when present (support
 *                                                      material repeatedly calls this a
 *                                                      "decline reason"; the exact field
 *                                                      name was not independently
 *                                                      confirmable); null otherwise.
 *   - POST /10dlc/phoneNumberAssignmentByProfile        CONFIRMED path (developers.telnyx.com/
 *                                                      api/messaging/10dlc/post-assign-
 *                                                      messaging-profile-to-campaign) — Phone
 *                                                      Numbers + A2P lane, the step that
 *                                                      links a freshly purchased local
 *                                                      number's own Messaging Profile to an
 *                                                      ALREADY-approved 10DLC campaign, for
 *                                                      the sequence this platform now
 *                                                      supports: business verification
 *                                                      completed before any number is
 *                                                      purchased. A successful call returns
 *                                                      HTTP 202 with `taskId` (plus
 *                                                      `messagingProfileId` and
 *                                                      `campaignId`/`tcrCampaignId`) as
 *                                                      TOP-LEVEL response fields — NOT nested
 *                                                      under a `data` key. Second review
 *                                                      correction — the original
 *                                                      implementation read `data.taskId`,
 *                                                      which Telnyx's own response never
 *                                                      populates, so a real call would always
 *                                                      have reported Failed('missing_task_id')
 *                                                      regardless of the genuine outcome;
 *                                                      verified against Telnyx's own published
 *                                                      SDK type definitions
 *                                                      (github.com/team-telnyx/telnyx-node,
 *                                                      PhoneNumberAssignmentByProfileAssignResponse)
 *                                                      since developers.telnyx.com is not
 *                                                      reachable from this environment's
 *                                                      egress proxy. `taskId` is a background
 *                                                      task, never an immediate confirmation —
 *                                                      see CampaignAssignmentOutcome's own
 *                                                      docblock. A 2xx response with no usable
 *                                                      top-level `taskId` is Failed, never
 *                                                      Requested — this platform could never
 *                                                      poll for completion without one.
 *   - GET    /10dlc/phoneNumberAssignmentByProfile/     CONFIRMED path (developers.telnyx.com/
 *            {taskId}/phoneNumbers                       api-reference/bulk-phone-number-
 *                                                      campaigns/get-phone-number-status) —
 *                                                      review correction: this platform now
 *                                                      actually polls this task to
 *                                                      completion (checkCampaignAssignmentStatus()),
 *                                                      matched by this exact phone number
 *                                                      within the response's own top-level
 *                                                      `records` array (confirmed unwrapped,
 *                                                      via the same SDK type definitions cited
 *                                                      above — PhoneNumberAssignmentByProfile
 *                                                      RetrievePhoneNumberStatusResponse) rather
 *                                                      than trusting the overall-task-level
 *                                                      status endpoint (GET .../{taskId}, also
 *                                                      documented, but a coarser signal this
 *                                                      platform's own one-number-per-task usage
 *                                                      does not need). Documented per-record
 *                                                      `status` values include "completed" and
 *                                                      "failed" — only these two ever leave
 *                                                      Requested; every other value (the
 *                                                      documented in-progress states, an
 *                                                      unrecognized one, a missing record, a
 *                                                      non-2xx response, or a transport
 *                                                      exception) reports Requested again —
 *                                                      never guessed as terminal.
 *
 *                                                      Second review correction — this
 *                                                      endpoint accepts `page`/`recordsPerPage`
 *                                                      query parameters and neither the
 *                                                      published SDK types nor Telnyx's own
 *                                                      support documentation state a default
 *                                                      page size, so checkCampaignAssignmentStatus()
 *                                                      now passes `recordsPerPage` explicitly
 *                                                      (comfortably above this platform's own
 *                                                      real usage) rather than trusting an
 *                                                      unstated default to include every
 *                                                      record. This platform's own structural
 *                                                      invariant makes a single generous page
 *                                                      sufficient regardless: provisionNumber()
 *                                                      below always creates a brand-new
 *                                                      Messaging Profile per number order (POST
 *                                                      /messaging_profiles, one call per
 *                                                      order), and BusinessMessagingIdentityResolver::
 *                                                      attachNumber() — the only production
 *                                                      call site that ever attaches a number to
 *                                                      an identity/profile — is reachable only
 *                                                      once per identity (guardNoExistingNumber()
 *                                                      in TextMessagingController refuses a
 *                                                      second order once an identity already
 *                                                      exists, and provisionNumber() itself
 *                                                      always reserves a FRESH identity before
 *                                                      ever calling the adapter). A Messaging
 *                                                      Profile this platform creates therefore
 *                                                      never holds more than the one phone
 *                                                      number checkCampaignAssignmentStatus()
 *                                                      is looking for, so its task's own
 *                                                      `records` array never has more than one
 *                                                      entry to page through in the first
 *                                                      place — the explicit page size is
 *                                                      defense in depth against that invariant
 *                                                      ever quietly changing, not a fix for an
 *                                                      observed pagination gap.
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
 *                                                         providerPhoneNumberId first. Its
 *                                                         own documented `id` field must be
 *                                                         PRESENT and equal
 *                                                         providerPhoneNumberId, AND its
 *                                                         `phone_number` field must match
 *                                                         $query->phoneNumber exactly — a
 *                                                         missing or mismatched id, or a
 *                                                         mismatched phone_number, means the
 *                                                         stored reference does not
 *                                                         identify the number this platform
 *                                                         believes it is releasing, and this
 *                                                         method refuses to proceed to
 *                                                         DELETE at all (NotConfirmed). A 404
 *                                                         here is likewise NotConfirmed,
 *                                                         never guessed as "already deleted"
 *                                                         — there is no identity to verify
 *                                                         against. If identity is confirmed
 *                                                         and the lookup's own documented
 *                                                         `status` field already reads
 *                                                         "deleted", that alone is Confirmed
 *                                                         (the safe way to recognize a retry
 *                                                         after an earlier attempt's own
 *                                                         response was lost to this
 *                                                         platform's network/timeout —
 *                                                         verified via retrieval, never
 *                                                         guessed from a delete 404).
 *                                                         Otherwise, `status` must be one of
 *                                                         SAFE_STATUSES_FOR_DELETION (today,
 *                                                         only "active") before this method
 *                                                         will ever proceed to DELETE — a
 *                                                         mid-port state ("port-out-pending",
 *                                                         "ported-out"), an unrecognized
 *                                                         value, or a missing one all refuse
 *                                                         (NotConfirmed) rather than guess
 *                                                         it is safe to delete from.
 *                                                      2. Only once every check above holds
 *                                                         does this method call DELETE. A
 *                                                         404 here is ALWAYS NotConfirmed —
 *                                                         per this correction, never treated
 *                                                         as confirmed on its own. A 2xx
 *                                                         response is Confirmed only once
 *                                                         its own `id` is PRESENT and equal
 *                                                         providerPhoneNumberId, its
 *                                                         `phone_number` still matches, AND
 *                                                         its `status` field reads "deleted";
 *                                                         a missing id never counts as
 *                                                         confirmation, and any 2xx response
 *                                                         that cannot be verified that way is
 *                                                         ambiguous and NotConfirmed — never
 *                                                         guessed as a success.
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

    /** One-time carrier brand registration fee — charged when the brand is actually created, never refunded after. */
    public const FEATURE_TEN_DLC_BRAND_REGISTRATION = 'messaging_10dlc_brand_registration';

    public const FEATURE_TEN_DLC_LOW_VOLUME_REGISTRATION = 'messaging_10dlc_low_volume_registration';

    public static function registrationFeatureFor(?string $useCase): string
    {
        return $useCase === 'low_volume_mixed' ? self::FEATURE_TEN_DLC_LOW_VOLUME_REGISTRATION : self::FEATURE_TEN_DLC_REGISTRATION;
    }

    public const FEATURE_TOLL_FREE_VERIFICATION = 'messaging_tollfree_verification';

    /**
     * Phone Numbers + A2P lane — the only carrier-side status
     * releaseNumber() recognizes as safe to delete from. Telnyx's own
     * documented status vocabulary includes mid-port states
     * ("port-out-pending", "ported-out") this platform must never delete
     * out from under — deleting a number while the carrier itself shows
     * it mid-port would be nonsensical and dangerous, independent of this
     * platform's own PortOutRequestManager tracking. Every other value —
     * a recognized-but-unsafe status, an unrecognized one, or a missing
     * one — refuses to proceed to DELETE at all, never guessed as safe.
     *
     * @var list<string>
     */
    private const SAFE_STATUSES_FOR_DELETION = ['active'];

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
            // Bring back enough inventory for multiple fresh five-number batches.
            // Telnyx may otherwise return the same first five on every search.
            'filter[limit]' => 50,
        ];

        // Telnyx documents `filter[phone_number_type]` (local | toll_free ...);
        // there is no `filter[number_type]`.
        $filter['filter[phone_number_type]'] = $criteria->numberType === PhoneNumberType::TollFree ? 'toll_free' : 'local';

        if ($criteria->numberType !== PhoneNumberType::TollFree && $criteria->areaCode !== null && $criteria->areaCode !== '') {
            $filter['filter[national_destination_code]'] = $criteria->areaCode;
        }

        if ($criteria->numberType === PhoneNumberType::TollFree && $criteria->tollFreePrefix !== null && $criteria->tollFreePrefix !== '') {
            $filter['filter[phone_number][starts_with]'] = '+1' . $criteria->tollFreePrefix;
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

            // Unknown or unusable pricing is never offered for purchase.
            $quote = $this->quoteFrom($row);

            if ($quote === null) {
                continue;
            }

            $candidates[] = new AvailableNumberCandidate(
                phoneNumber: (string) $row['phone_number'],
                numberType: $criteria->numberType,
                providerCandidateReference: (string) $row['phone_number'],
                upfrontCostMicro: $quote['upfront'],
                monthlyCostMicro: $quote['monthly'],
                currency: $quote['currency'],
            );
        }

        return $candidates;
    }

    /**
     * Telnyx's `cost_information` (strings: upfront_cost, monthly_cost,
     * currency) as exact micro-units, plus the owner-configured at-cost SMS
     * capability add-on when set. Null for anything incomplete, non-USD,
     * non-positive, malformed or above the safety ceiling — which also
     * keeps unusually priced special numbers from being sold at a wrong
     * price.
     *
     * @param  array<string, mixed>  $row
     * @return array{upfront: int, monthly: int, currency: string}|null
     */
    private function quoteFrom(array $row): ?array
    {
        $cost = $row['cost_information'] ?? null;

        if (! is_array($cost) || ($cost['currency'] ?? null) !== 'USD') {
            return null;
        }

        $upfront = self::decimalToMicro($cost['upfront_cost'] ?? null);
        $monthly = self::decimalToMicro($cost['monthly_cost'] ?? null);

        if ($upfront === null || $monthly === null || $monthly < 1) {
            return null;
        }

        $monthly += max(0, (int) config('messaging.number_sms_addon_monthly_cost_micro', 0));
        $ceiling = (int) config('messaging.number_price_ceiling_micro', 25_000_000);

        if ($upfront > $ceiling || $monthly > $ceiling) {
            return null;
        }

        return ['upfront' => $upfront, 'monthly' => $monthly, 'currency' => 'USD'];
    }

    private static function decimalToMicro(mixed $value): ?int
    {
        if (! is_string($value) || preg_match('/^\d{1,9}(\.\d{1,6})?$/', trim($value)) !== 1) {
            return null;
        }

        return (int) bcmul(trim($value), '1000000', 0);
    }

    /**
     * Confirm the exact selected number and its price against fresh Telnyx
     * inventory before placing an order. Using the full E.164 value in
     * a starts_with pattern can yield no results even when the ordinary
     * inventory search offered the number. Search for the last seven
     * national digits instead, then ONLY accept an
     * exact E.164 match with the same valid quote. Never trust a near match.
     */
    private function assertNumberQuoteStillCurrent(AvailableNumberCandidate $candidate): void
    {
        if (! $candidate->hasQuote()) {
            throw new NumberPriceChangedException('We couldn\'t confirm the price of this number. Nothing was charged. Please search again.');
        }

        $digits = preg_replace('/\\D/', '', $candidate->phoneNumber);
        if (! is_string($digits) || strlen($digits) < 10) {
            throw new NumberPriceChangedException('We couldn\'t confirm this phone number. Nothing was charged. Please search again.');
        }

        try {
            $response = $this->client()->get(self::API_BASE . '/available_phone_numbers', [
                'filter[country_code]' => 'US',
                'filter[phone_number_type]' => $candidate->numberType === PhoneNumberType::TollFree ? 'toll_free' : 'local',
                'filter[features][]' => 'sms',
                'filter[phone_number][contains]' => substr($digits, -7),
                'filter[limit]' => 100,
            ]);
        } catch (ConnectionException) {
            throw new NumberPriceChangedException('We couldn\'t reach the carrier to confirm this number\'s price. Nothing was charged. Please try again.');
        }

        if (! $response->successful()) {
            // An API/auth/filter failure is not evidence the number was sold.
            // Never place an order without a successful, exact quote response.
            \Illuminate\Support\Facades\Log::warning('Telnyx number quote confirmation lookup failed', [
                'status' => $response->status(),
                'number_type' => $candidate->numberType->value,
            ]);
            throw new NumberPriceChangedException('The carrier could not confirm this number right now. Nothing was charged. Please try again.');
        }

        $current = null;

        foreach ((array) $response->json('data', []) as $row) {
            if (is_array($row) && ($row['phone_number'] ?? null) === $candidate->phoneNumber
                && $candidate->providerCandidateReference === $candidate->phoneNumber) {
                $current = $this->quoteFrom($row);
                break;
            }
        }

        if ($current === null) {
            \Illuminate\Support\Facades\Log::warning('Telnyx quote recheck returned no exact priced number', [
                'number_type' => $candidate->numberType->value,
                'returned_count' => count((array) $response->json('data', [])),
            ]);
            throw new NumberPriceChangedException('This number is no longer available at a confirmed price. Nothing was charged. Please search again.');
        }

        if ($current['upfront'] !== $candidate->upfrontCostMicro || $current['monthly'] !== $candidate->monthlyCostMicro) {
            throw new NumberPriceChangedException(sprintf(
                'The price of this number changed to %s today and %s per month. Nothing was charged — please search again and confirm the new price.',
                \App\Library\Dashboard\DashboardMoney::format((string) ($current['upfront'] + $current['monthly']), 'USD'),
                \App\Library\Dashboard\DashboardMoney::format((string) $current['monthly'], 'USD'),
            ));
        }
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
        ProvisioningPilot::assertOpen($business);

        // A number order whose outcome was never confirmed may have bought
        // (and be billing) a number at the carrier: no second purchase
        // until an operator reconciles it.
        if (BusinessMessagingProvisioningIncident::query()
            ->where('business_id', $business->id)
            ->where('stage', self::INCIDENT_NUMBER_ORDER_UNKNOWN)
            ->whereNull('resolved_at')
            ->exists()) {
            throw new MessagingRegistrationRecoveryRequiredException('An earlier number order has an unconfirmed carrier outcome.');
        }

        $this->assertRecurringPriceConfigured(NumberLifecycleManager::FEATURE_NUMBER_RENTAL_RENEWAL);

        // Zero-markup pass-through: the meter's rate is the owner's multiplier
        // (1.00 = no markup) per 1.00 of carrier cost, and the quantity is the
        // exact carrier cost charged at order — the upfront cost plus the
        // first month's rental. Rental renewals start next month, so the
        // first month is never charged twice.
        $reservationId = $this->reserveFunding(
            $business,
            self::FEATURE_NUMBER_PURCHASE,
            null,
            bcdiv((string) $candidate->initialCostMicro(), '1000000', 6),
        );

        try {
            // The price the customer confirmed must still be the carrier's
            // price. Checked after funds are held (an unfunded Business never
            // reaches Telnyx at all) and before anything is ordered; a mismatch
            // releases the hold and asks the customer to confirm again.
            $this->assertNumberQuoteStillCurrent($candidate);

            $profileResponse = $this->client()->post(self::API_BASE . '/messaging_profiles', [
                'name' => sprintf('Business #%d', $business->id),
                // Required by Telnyx on every new Messaging Profile since 2024.
                // This is a US-number product; do not whitelist every country.
                'whitelisted_destinations' => ['US'],
            ]);

            if (! $profileResponse->successful()) {
                // Retain only diagnostic codes/status, never secrets or the
                // provider's raw error body, which might contain PII.
                \Illuminate\Support\Facades\Log::warning('Telnyx Messaging Profile creation rejected', [
                    'business_id' => $business->id,
                    'http_status' => $profileResponse->status(),
                    'provider_code' => $profileResponse->json('errors.0.code'),
                    'provider_parameter' => $profileResponse->json('errors.0.source.parameter')
                        ?? $profileResponse->json('errors.0.source.pointer'),
                ]);

                throw new \RuntimeException('Telnyx rejected the Messaging Profile request.');
            }

            $messagingProfileId = (string) $profileResponse->json('data.id');
            if ($messagingProfileId === '') {
                \Illuminate\Support\Facades\Log::warning('Telnyx Messaging Profile response omitted an id', [
                    'business_id' => $business->id,
                    'http_status' => $profileResponse->status(),
                ]);
                throw new \RuntimeException('Telnyx returned no Messaging Profile identifier.');
            }

            try {
                $orderResponse = $this->client()->post(self::API_BASE . '/number_orders', [
                    'phone_numbers' => [['phone_number' => $candidate->providerCandidateReference]],
                    'messaging_profile_id' => $messagingProfileId,
                ]);
            } catch (ConnectionException) {
                throw $this->unconfirmedNumberOrder($business, $candidate, $messagingProfileId, 'order request did not complete');
            }

            if ($orderResponse->serverError() || $orderResponse->status() === 408) {
                throw $this->unconfirmedNumberOrder($business, $candidate, $messagingProfileId, 'order status=' . $orderResponse->status());
            }

            if (! $orderResponse->successful()) {
                // Expose carrier diagnostics in server logs, not to the
                // customer, and never include an API key or request body.
                \Illuminate\Support\Facades\Log::warning('Telnyx number order rejected', [
                    'business_id' => $business->id,
                    'http_status' => $orderResponse->status(),
                    'provider_code' => $orderResponse->json('errors.0.code'),
                    'provider_parameter' => $orderResponse->json('errors.0.source.parameter')
                        ?? $orderResponse->json('errors.0.source.pointer'),
                ]);
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

            // HTTP 200 is not a completed order: each number carries its own
            // status (pending | success | failure). A failure is definitive;
            // a still-pending order may yet complete, so it is an unconfirmed
            // outcome (incident + block), not a quiet success or a refund.
            $orderedStatus = strtolower((string) ($orderResponse->json('data.phone_numbers.0.status') ?? $orderResponse->json('data.status') ?? 'success'));

            if ($orderedStatus === 'failure') {
                app(ProvisioningIncidentRecorder::class)->record(
                    business: $business,
                    stage: 'messaging_profile_created_but_number_order_failed',
                    messagingProfileId: $messagingProfileId,
                    providerPhoneNumberId: null,
                    phoneNumber: $candidate->phoneNumber,
                    numberType: $candidate->numberType->value,
                    errorMessage: 'order status=failure',
                );

                throw new \RuntimeException('Telnyx could not complete the number order.');
            }

            if ($orderedStatus === 'pending') {
                throw $this->unconfirmedNumberOrder($business, $candidate, $messagingProfileId, 'order accepted but still pending');
            }

            $providerPhoneNumberId = (string) ($orderResponse->json('data.phone_numbers.0.id') ?? $orderResponse->json('data.id'));
        } catch (\Throwable $e) {
            $this->settleAfterFailure($reservationId, $e);

            throw $e;
        }

        $this->wallet->commit($reservationId);

        return new ProvisionedNumberResult(
            messagingProfileId: $messagingProfileId,
            providerPhoneNumberId: $providerPhoneNumberId,
            phoneNumber: $candidate->phoneNumber,
            upfrontCostMicro: $candidate->upfrontCostMicro,
            monthlyCostMicro: $candidate->monthlyCostMicro,
            costCurrency: $candidate->currency,
        );
    }

    public function submitRegistration(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        ProvisioningPilot::assertOpen($submission->businessId);

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
    public function refreshRegistrationStatus(RegistrationStatusQuery $query): RegistrationStatusResult
    {
        if ($query->numberType === PhoneNumberType::TollFree) {
            return $this->refreshTollFreeVerificationStatus($query);
        }

        return $this->refreshTenDlcCampaignStatus($query);
    }

    /**
     * Phone Numbers + A2P lane — see this class's own docblock and the
     * interface's own for the endpoint/async-task rationale. Confirmed
     * path: POST /10dlc/phoneNumberAssignmentByProfile, body
     * {messagingProfileId, campaignId} (developers.telnyx.com/api/
     * messaging/10dlc/post-assign-messaging-profile-to-campaign). Never
     * goes through reserveFunding(): this links an already-purchased
     * number's already-created Messaging Profile to an already-approved
     * campaign — no new resource is purchased here.
     *
     * Second review correction — `taskId` is a TOP-LEVEL response field,
     * never nested under `data`; see this class's own docblock for the
     * verification source. Reading `data.taskId` (the original
     * implementation) would silently read null on every real call and
     * report every genuine success as Failed('missing_task_id').
     */
    public function assignMessagingProfileToCampaign(string $messagingProfileId, string $providerCampaignId): CampaignAssignmentResult
    {
        try {
            $response = $this->client()->post(self::API_BASE . '/10dlc/phoneNumberAssignmentByProfile', [
                'messagingProfileId' => $messagingProfileId,
                'campaignId' => $providerCampaignId,
            ]);
        } catch (\Throwable) {
            return new CampaignAssignmentResult(CampaignAssignmentOutcome::Failed, 'transport_error');
        }

        if (! $response->successful()) {
            return new CampaignAssignmentResult(CampaignAssignmentOutcome::Failed, 'http_' . $response->status());
        }

        $taskId = $response->json('taskId');

        if (! is_string($taskId) || $taskId === '') {
            // A 2xx response with no usable task id leaves this platform
            // unable to ever poll for completion — never guessed as
            // Requested (which promises a later confirmation this
            // platform could not actually check for).
            return new CampaignAssignmentResult(CampaignAssignmentOutcome::Failed, 'missing_task_id');
        }

        return new CampaignAssignmentResult(CampaignAssignmentOutcome::Requested, 'task_requested', $taskId);
    }

    /**
     * Review correction — see this class's own docblock and the
     * interface's own for the confirmed completion mechanism: GET
     * /10dlc/phoneNumberAssignmentByProfile/{taskId}/phoneNumbers, whose
     * documented response shape is a top-level {records: [{taskId,
     * phoneNumber, status}, ...]} — one record per number in the task.
     * This platform's own provisionNumber()/attachNumber() invariant (see
     * this class's own docblock) means a task this platform created never
     * actually holds more than one number, but this still matches by
     * $phoneNumber explicitly rather than trusting record order or count,
     * and still requests a generous explicit `recordsPerPage` rather than
     * relying on an undocumented default — belt and braces, not a fix for
     * an observed gap.
     */
    public function checkCampaignAssignmentStatus(string $taskId, string $phoneNumber): CampaignAssignmentResult
    {
        try {
            $response = $this->client()->get(self::API_BASE . '/10dlc/phoneNumberAssignmentByProfile/' . $taskId . '/phoneNumbers', [
                'recordsPerPage' => 50,
            ]);
        } catch (\Throwable) {
            return new CampaignAssignmentResult(CampaignAssignmentOutcome::Requested, 'poll_transport_error');
        }

        if (! $response->successful()) {
            return new CampaignAssignmentResult(CampaignAssignmentOutcome::Requested, 'poll_http_' . $response->status());
        }

        $record = null;

        foreach ((array) $response->json('records', []) as $row) {
            if (is_array($row) && ($row['phoneNumber'] ?? null) === $phoneNumber) {
                $record = $row;
                break;
            }
        }

        if ($record === null) {
            // The task exists but says nothing about THIS number yet —
            // never guessed either way.
            return new CampaignAssignmentResult(CampaignAssignmentOutcome::Requested, 'poll_record_not_found');
        }

        $status = strtolower((string) ($record['status'] ?? ''));

        if ($status === 'completed') {
            return new CampaignAssignmentResult(CampaignAssignmentOutcome::Confirmed, 'poll_completed');
        }

        if ($status === 'failed') {
            return new CampaignAssignmentResult(CampaignAssignmentOutcome::Failed, 'poll_failed');
        }

        // Covers the documented in-progress values ("pending", "starting",
        // "processing", "running") and anything this platform has not
        // seen before — never guessed as terminal either way.
        return new CampaignAssignmentResult(CampaignAssignmentOutcome::Requested, 'poll_status_' . ($status !== '' ? $status : 'unknown'));
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

        // Review correction — a MISSING id must never count as
        // confirmation. The earlier version treated an absent id as "no
        // opinion, check phone_number instead", which let a delete
        // response with no id at all pass identity verification on
        // phone_number alone. The id is now required to be PRESENT and
        // matching, exactly like the lookup step already requires.
        $identityConfirmed = $responseId !== null
            && (string) $responseId === $query->providerPhoneNumberId
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
     * an identity mismatch, a lookup that already shows the number
     * deleted, or a status not recognized as safe to delete from);
     * otherwise returns the resource's own current (already verified
     * safe) status string so releaseNumber() knows it is safe to proceed
     * to DELETE.
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

        $id = $response->json('data.id');

        // Review correction — id must be PRESENT and match, not merely
        // "if present, must match". A response with no id at all proves
        // nothing about which resource was actually retrieved.
        if ($id === null || (string) $id !== $query->providerPhoneNumberId) {
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'lookup_id_mismatch');
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

        if (! in_array($status, self::SAFE_STATUSES_FOR_DELETION, true)) {
            // Covers a recognized-but-unsafe status (e.g. port-out-pending,
            // ported-out), an unrecognized one, and a missing one alike —
            // never guessed as safe to delete from.
            return new CarrierReleaseResult(CarrierReleaseOutcome::NotConfirmed, 'lookup_status_not_safe_for_deletion');
        }

        return $status;
    }

    public const INCIDENT_BRAND_UNKNOWN = '10dlc_brand_outcome_unknown';
    public const INCIDENT_CAMPAIGN_UNKNOWN = '10dlc_campaign_outcome_unknown';
    public const INCIDENT_FUNDING_AMBIGUOUS = '10dlc_funding_state_ambiguous';
    public const INCIDENT_NUMBER_ORDER_UNKNOWN = 'number_order_outcome_unknown';
    public const INCIDENT_TOLLFREE_UNKNOWN = 'tollfree_verification_outcome_unknown';

    /**
     * Internal use-case key => Telnyx/TCR campaign `usecase` enum value.
     * Telnyx documents the field only as "must be of defined valid types"
     * (see GET /10dlc/enum/usecase); the TCR values it passes through are
     * upper-case, so the internal lower-case keys are never sent as-is.
     * Appointment reminders have no TCR use case of their own and are
     * customer care. MIXED additionally carries its two sub-use-cases.
     * The exact acceptance of each value for a given brand is the
     * carrier's decision and was not verifiable against a live account.
     *
     * @var array<string, array{usecase: string, sub?: list<string>}>
     */
    private const TEN_DLC_USE_CASES = [
        'customer_care' => ['usecase' => 'CUSTOMER_CARE'],
        'marketing' => ['usecase' => 'MARKETING'],
        'mixed' => ['usecase' => 'MIXED', 'sub' => ['CUSTOMER_CARE', 'MARKETING']],
        // Telnyx/TCR "Low Volume Mixed" is its own use case (LOW_VOLUME), NOT
        // MIXED. Its sub-use-cases are the customer's real purposes, and the
        // brand's eligibility is checked against Telnyx before it is created.
        'low_volume_mixed' => ['usecase' => 'LOW_VOLUME', 'purposes' => true],
        'account_notifications' => ['usecase' => 'ACCOUNT_NOTIFICATION'],
        'appointment_reminders' => ['usecase' => 'CUSTOMER_CARE'],
        'two_factor' => ['usecase' => '2FA'],
    ];

    private function submitTenDlcRegistration(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        // Checked before any wallet reservation: an unmapped use case must
        // never reach the provider as a guessed value.
        $useCase = self::TEN_DLC_USE_CASES[$submission->useCase] ?? null;

        if ($useCase === null) {
            throw new \RuntimeException('This messaging type cannot be submitted as a 10DLC campaign.');
        }

        // Low Volume Mixed needs at least one real, valid purpose. Nothing is
        // added on the customer's behalf to reach a cheaper campaign type.
        $subUsecases = [];

        if (($useCase['purposes'] ?? false) === true) {
            foreach ($submission->messagePurposes as $purpose) {
                if (isset(CarrierRegistrationOptions::LOW_VOLUME_PURPOSES[$purpose])) {
                    $subUsecases[] = CarrierRegistrationOptions::LOW_VOLUME_PURPOSES[$purpose]['sub_usecase'];
                }
            }

            $subUsecases = array_values(array_unique($subUsecases));

            if (count($subUsecases) < 2) {
                throw new MessagingRegistrationNotEligibleException('Low Volume Mixed is for a business that genuinely sends at least two different kinds of text (service and promotional). Choose the standard campaign type that matches what you send.');
            }
        }

        // Also before any wallet reservation: Mixed stays off until carrier
        // acceptance has been verified.
        if ($useCase['usecase'] === 'MIXED' && ! config('messaging.ten_dlc_mixed_enabled')) {
            throw new \RuntimeException('Mixed 10DLC campaigns are not enabled yet.');
        }

        // Checked before any wallet reservation: values Telnyx requires and
        // validates must be present and provider-valid.
        if ($submission->existingBrandId === null
            && (! isset(CarrierRegistrationOptions::BRAND_VERTICALS[(string) $submission->brandVertical])
                || CarrierRegistrationOptions::stateCode($submission->region) === null)) {
            throw new \RuntimeException('A 10DLC brand needs an industry category and a valid US state.');
        }

        // Checked before any wallet reservation: a campaign description is
        // required, and the technical use-case key is never a substitute.
        if ($submission->campaignDescription === null || trim($submission->campaignDescription) === '') {
            throw new \RuntimeException('A 10DLC campaign description is required before submission.');
        }

        // Telnyx requires the three confirmation messages (20-320 characters
        // each). Built from this Business's own registration and validated
        // before any wallet reservation or provider call.
        $confirmationMessages = $this->campaignConfirmationMessages($submission);

        $business = Business::query()->findOrFail($submission->businessId);

        // An earlier attempt whose provider outcome was never confirmed
        // blocks every automatic retry until an operator resolves the
        // incident — a brand/campaign may already exist at Telnyx.
        if (BusinessMessagingProvisioningIncident::query()
            ->where('business_id', $business->id)
            ->whereIn('stage', [self::INCIDENT_BRAND_UNKNOWN, self::INCIDENT_CAMPAIGN_UNKNOWN, self::INCIDENT_FUNDING_AMBIGUOUS, self::INCIDENT_TOLLFREE_UNKNOWN])
            ->whereNull('resolved_at')
            ->exists()) {
            throw new MessagingRegistrationRecoveryRequiredException('An earlier 10DLC submission has an unconfirmed provider outcome.');
        }

        if ($submission->phase === 'brand') {
            return $this->submitBrandStage($business, $submission);
        }

        if ($submission->phase === 'campaign' && $submission->existingBrandId === null) {
            throw new \RuntimeException('A campaign can only be submitted after the business brand exists.');
        }

        $registrationFeature = self::registrationFeatureFor($submission->useCase);
        $this->assertRecurringPriceConfigured(NumberLifecycleManager::campaignRenewalFeatureFor($submission->useCase));

        // The carrier brand fee is its own priced charge (when a brand is
        // created), so it must be configured before anything is reserved.
        $needsBrand = $submission->existingBrandId === null;

        if ($needsBrand) {
            $this->assertRecurringPriceConfigured(self::FEATURE_TEN_DLC_BRAND_REGISTRATION);
        }

        $operationKey = $this->registrationOperationKey($business, $registrationFeature, $submission);

        if ($operationKey === null) {
            // This cycle's fee is already committed and the saved brand and
            // campaign are the ones it paid for: only the local status
            // transition is missing. No fee, no provider call.
            return new RegistrationSubmissionResult($submission->existingBrandId, $submission->existingCampaignId, null, MessagingRegistrationStatus::Pending);
        }

        // 1) The prepaid campaign months (the carrier bills three upfront) —
        //    held first, so a customer who cannot cover BOTH never has a
        //    brand created for them.
        $reservationId = $this->reserveFunding($business, $registrationFeature, $operationKey);

        // 2) The brand fee, held now and committed the moment the carrier
        //    actually creates the brand: a real carrier commitment is never
        //    refunded because a later step failed.
        $brandReservationId = null;

        if ($needsBrand) {
            try {
                $brandReservationId = $this->reserveFunding($business, self::FEATURE_TEN_DLC_BRAND_REGISTRATION, $this->brandOperationKey($business, $submission));
            } catch (\Throwable $e) {
                $this->wallet->release($reservationId);

                throw $e;
            }
        } else {
            // A brand saved by an earlier attempt whose fee was held but
            // not yet committed (a crash right after the carrier created it):
            // commit it now rather than letting the hold expire unbilled.
            $this->commitPendingBrandFee($business, $submission);
        }

        $brandId = $submission->existingBrandId;

        try {
            if ($brandId === null) {
                [$brandId, $tcrBrandId] = $this->createBrandAtProvider($business, $submission);

                // The carrier now holds this brand: its fee is earned and is
                // committed here, so no later failure can hand it back.
                if ($brandReservationId !== null) {
                    $this->wallet->commit($brandReservationId);
                    $brandReservationId = null;
                }
            }

            $campaignId = $submission->existingCampaignId;

            // Low Volume Mixed: ask Telnyx whether THIS brand qualifies for
            // the use case before creating (and paying for) a campaign. A
            // read-only call: any failure is a definitive refusal, never an
            // unknown create outcome. The brand stays saved for a retry.
            if ($campaignId === null && ($useCase['purposes'] ?? false) === true) {
                try {
                    $qualification = $this->client()->get(self::API_BASE . '/10dlc/campaignBuilder/brand/' . $brandId . '/usecase/' . $useCase['usecase']);
                } catch (ConnectionException) {
                    throw MessagingRegistrationNotEligibleException::temporary('We couldn\'t reach the carrier to check your eligibility for Low Volume Mixed. ' . $this->qualificationFeeNote($needsBrand) . ' Please try again later.');
                }

                if (! $qualification->successful()) {
                    $http = $qualification->status();

                    // Only an explicit refusal is ineligibility. A rate limit, timeout or
                    // server error says nothing about the business, and a 400 is only
                    // trusted once the carrier confirms the brand is verified (an
                    // unverified brand is refused with 400 until verification finishes).
                    if ($http === 429 || $http === 408 || $http >= 500
                        || ($http === 400 && $this->refreshBrandStatus($brandId)->state !== BrandStatusResult::VERIFIED)) {
                        if ($http === 400) {
                            BusinessMessagingRegistration::query()->where('business_id', $business->id)->update(['brand_status' => 'processing']);
                        }

                        throw MessagingRegistrationNotEligibleException::temporary('We couldn\'t confirm your eligibility for Low Volume Mixed yet; the carrier has not finished verifying your business or did not answer. ' . $this->qualificationFeeNote($needsBrand) . ' We will keep checking; you can try again shortly.');
                    }

                    $this->logProviderFailure($business, 'qualification', $qualification);

                    // Only a structured provider error code explicitly configured
                    // as "not eligible" is a verdict. Any other refusal (400, 403,
                    // 422...) does not say WHY, so it is an uncertain, temporary
                    // submission issue: the campaign stays unsubmitted, the brand
                    // stays saved, nothing is charged, and no other campaign type
                    // is ever substituted on the customer's behalf.
                    $code = $this->providerValue($qualification, ['errors.0.code']);

                    if ($code !== '' && in_array($code, array_map('strval', (array) config('messaging.qualification_ineligible_codes', [])), true)) {
                        throw new MessagingRegistrationNotEligibleException('Telnyx says this business is not eligible for a Low Volume Mixed campaign. ' . $this->qualificationFeeNote($needsBrand) . ' Choose a standard service or promotional campaign instead.');
                    }

                    throw MessagingRegistrationNotEligibleException::temporary('The carrier did not accept the Low Volume Mixed eligibility check and did not say why, so your campaign has not been submitted. Your verified business is saved, ' . lcfirst($this->qualificationFeeNote($needsBrand)) . ' You can try again shortly; if it keeps happening, contact support with the time of your attempt. Nothing about your campaign type was changed.');
                }

                // UsecaseMetadata is a root-level object. Unknown or malformed
                // limits are never guessed at: refuse rather than send a
                // campaign the registry may reject (or that drops a purpose).
                $minSub = $qualification->json('minSubUsecases');
                $maxSub = $qualification->json('maxSubUsecases');

                if (! is_int($minSub) || ! is_int($maxSub) || $minSub < 0 || $maxSub < 0
                    || $this->providerValue($qualification, ['usecase']) !== $useCase['usecase']) {
                    throw MessagingRegistrationNotEligibleException::temporary('We couldn\'t confirm the carrier\'s rules for Low Volume Mixed right now. ' . $this->qualificationFeeNote($needsBrand) . ' Please try again later.');
                }

                if (count($subUsecases) < $minSub) {
                    throw new MessagingRegistrationNotEligibleException('The carrier needs at least ' . $minSub . ' distinct message purposes for Low Volume Mixed, and you declared ' . count($subUsecases) . '. ' . $this->qualificationFeeNote($needsBrand) . ' Choose the standard campaign type that matches what you send.');
                }

                if (count($subUsecases) > $maxSub) {
                    throw new MessagingRegistrationNotEligibleException('The carrier allows at most ' . $maxSub . ' message purposes for Low Volume Mixed, and you declared ' . count($subUsecases) . '. ' . $this->qualificationFeeNote($needsBrand) . ' None of your purposes was dropped. Choose a campaign type that fits what you send.');
                }
            }

            // A previous attempt that already created and persisted the
            // campaign but never reached the fee commit only finishes the
            // funding step here — never a second campaign.
            try {
                $campaignResponse = $campaignId !== null ? null : $this->client()->post(self::API_BASE . '/10dlc/campaignBuilder', [
                    'brandId' => $brandId,
                    'usecase' => $useCase['usecase'],
                    ...(isset($useCase['sub']) ? ['subUsecases' => $useCase['sub']] : []),
                    ...(($useCase['purposes'] ?? false) === true && $subUsecases !== [] ? ['subUsecases' => $subUsecases] : []),
                    'description' => $submission->campaignDescription,
                    'sample1' => $submission->sampleMessage1,
                    'sample2' => $submission->sampleMessage2,
                    'messageFlow' => $submission->optInMethod,
                    'privacyPolicyLink' => $submission->privacyPolicyUrl,
                    'termsAndConditionsLink' => $submission->termsUrl,
                    'optinKeywords' => 'START,SUBSCRIBE',
                    'optoutKeywords' => 'STOP,UNSUBSCRIBE',
                    'helpKeywords' => 'HELP,INFO',
                    'optinMessage' => $confirmationMessages['optin'],
                    'optoutMessage' => $confirmationMessages['optout'],
                    'helpMessage' => $confirmationMessages['help'],
                    // The registration's consent checklist is validated
                    // (and customer-attested) before this point.
                    'subscriberOptin' => true,
                    'subscriberOptout' => true,
                    'subscriberHelp' => true,
                    'termsAndConditions' => true,
                    'embeddedLink' => false,
                    'embeddedPhone' => false,
                    'numberPool' => false,
                    'ageGated' => false,
                    'directLending' => false,
                    // Keeps the campaign alive across billing cycles while
                    // the customer is billed its monthly campaign cost.
                    'autoRenewal' => true,
                ]);
            } catch (ConnectionException $e) {
                throw $this->unconfirmedOutcome($business, self::INCIDENT_CAMPAIGN_UNKNOWN, $brandId, 'campaign request did not complete');
            }

            if ($campaignResponse !== null && ! $campaignResponse->successful()) {
                $this->logProviderFailure($business, 'campaign', $campaignResponse);

                if ($campaignResponse->serverError() || $campaignResponse->status() === 408) {
                    throw $this->unconfirmedOutcome($business, self::INCIDENT_CAMPAIGN_UNKNOWN, $brandId, 'campaign status=' . $campaignResponse->status());
                }

                // Definitive rejection: the brand stays persisted on the
                // registration and the next attempt reuses it.
                app(ProvisioningIncidentRecorder::class)->record(
                    business: $business,
                    stage: '10dlc_brand_created_but_campaign_failed',
                    messagingProfileId: null,
                    providerPhoneNumberId: null,
                    phoneNumber: null,
                    numberType: PhoneNumberType::Local->value,
                    errorMessage: 'brand_id=' . $brandId . ' status=' . $campaignResponse->status(),
                );

                throw new \App\Library\Messaging\Exceptions\MessagingRegistrationProviderException('Telnyx rejected the 10DLC campaign submission.', 'campaign', true, $needsBrand);
            }

            if ($campaignResponse !== null) {
                $campaignId = $this->providerValue($campaignResponse, ['campaignId', 'data.campaignId', 'data.id']);

                if ($campaignId === '') {
                    throw $this->unconfirmedOutcome($business, self::INCIDENT_CAMPAIGN_UNKNOWN, $brandId, 'campaign accepted without an id');
                }

                // Durable before the fee commit: a crash between provider
                // success and commit must not lead to a second campaign.
                BusinessMessagingRegistration::query()
                    ->where('business_id', $business->id)
                    ->update(['provider_campaign_id' => $campaignId]);
            }
        } catch (\Throwable $e) {
            $this->settleAfterFailure($reservationId, $e);

            if ($brandReservationId !== null) {
                $this->settleAfterFailure($brandReservationId, $e);
            }

            throw $e;
        }

        $this->wallet->commit($reservationId);

        return new RegistrationSubmissionResult($brandId, $campaignId, null, MessagingRegistrationStatus::Pending);
    }

    /**
     * Stable per-cycle identity for a brand fee. Each brand the carrier
     * actually creates is a new fee (including a replacement brand after the
     * customer changes brand-defining details), so a committed, released or
     * expired attempt moves on to the next suffix; a still-pending one is
     * reused rather than duplicated.
     */
    private function brandOperationKey(Business $business, MessagingRegistrationSubmission $submission): string
    {
        $repository = app(BusinessUsageReservationRepository::class);

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $key = sprintf('messaging-brand:%d:%s:%d', $business->id, $submission->fundingCycle, $attempt);
            $existing = $repository->findByIdempotencyKey($key);

            if ($existing === null || $existing->status === UsageReservationStatus::Pending) {
                return $key;
            }
        }

        throw new MessagingRegistrationRecoveryRequiredException('Too many brand attempts in one funding cycle.');
    }

    private function commitPendingBrandFee(Business $business, MessagingRegistrationSubmission $submission): void
    {
        $repository = app(BusinessUsageReservationRepository::class);

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $existing = $repository->findByIdempotencyKey(sprintf('messaging-brand:%d:%s:%d', $business->id, $submission->fundingCycle, $attempt));

            if ($existing === null) {
                return;
            }

            if ($existing->status === UsageReservationStatus::Pending) {
                $this->wallet->commit((int) $existing->id);

                return;
            }
        }
    }

    /**
     * A paid action with an ongoing carrier cost is never started unless
     * that ongoing cost is priced too — otherwise the platform would absorb
     * it. Refused before any funds are reserved or provider call is made.
     */
    private function assertRecurringPriceConfigured(string $recurringFeature): void
    {
        if (app(MessagingChargePreview::class)->fee($recurringFeature) === null) {
            throw new MessagingFundingUnavailableException(
                sprintf('The recurring price [%s] is not configured.', $recurringFeature),
                0,
                null,
                MessagingFundingUnavailableException::REASON_PRICING_NOT_CONFIGURED,
            );
        }
    }

    /**
     * Telnyx's 10DLC and toll-free verification endpoints return a ROOT-level
     * object (no `data` envelope — confirmed in the API reference: brandId,
     * campaignId, id, campaignStatus, verificationStatus). A legacy `data.`
     * wrapper is still read as a fallback; a blank value is treated as absent.
     *
     * @param  list<string>  $paths  tried in order
     */
    /**
     * Customer wording for a Low Volume Mixed refusal that happens AFTER the
     * brand step: a brand the carrier created in this attempt has already
     * cost its fee (committed, never refunded), while the campaign fee was
     * never charged (its hold is released). A brand saved by an earlier
     * attempt was paid then, so nothing new is charged now.
     */
    private function qualificationFeeNote(bool $brandCreatedNow): string
    {
        return $brandCreatedNow
            ? 'The carrier brand fee was already charged because the carrier created your brand, and it is not refunded; your brand is saved so a retry will not pay it again. The campaign fee was not charged.'
            : 'Your brand was registered earlier, so no new brand fee was charged, and the campaign fee was not charged.';
    }

    /**
     * Stage 1: create the carrier brand once and charge its fee once. A brand
     * already saved is reused as-is (no request, no charge). The campaign is
     * never created here, and nothing about the brand is assumed verified.
     */
    /**
     * Telnyx verifies a sole-proprietor brand with an SMS one-time PIN sent to
     * the contact phone, which the carrier expects to be entered back. This
     * platform has no PIN entry step, so such a brand could be paid for and then
     * never complete. It is refused before any reservation or provider call.
     */
    private function assertSoleProprietorSupported(MessagingRegistrationSubmission $submission): void
    {
        if ($submission->entityType === MessagingEntityType::SoleProprietor) {
            throw new MessagingRegistrationNotEligibleException(self::SOLE_PROPRIETOR_GUIDANCE . ' Nothing was charged.');
        }
    }

    public const SOLE_PROPRIETOR_GUIDANCE = 'Registering as an individual / sole proprietor requires a one-time PIN text from the carrier that can\'t be completed in MotionGrove yet. Register with your business\'s EIN instead, or use a toll-free number, which needs no EIN or PIN.';

    private function submitBrandStage(Business $business, MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        if ($submission->existingBrandId !== null) {
            return new RegistrationSubmissionResult($submission->existingBrandId, null, null, MessagingRegistrationStatus::NotStarted);
        }

        // The whole lifecycle must be priced before the first charge: a
        // customer is never charged the brand fee for a campaign type whose
        // own prices are not configured (nothing is silently omitted later).
        $this->assertSoleProprietorSupported($submission);
        $this->assertRecurringPriceConfigured(self::FEATURE_TEN_DLC_BRAND_REGISTRATION);
        $this->assertRecurringPriceConfigured(self::registrationFeatureFor($submission->useCase));
        $this->assertRecurringPriceConfigured(NumberLifecycleManager::campaignRenewalFeatureFor($submission->useCase));
        $reservationId = $this->reserveFunding($business, self::FEATURE_TEN_DLC_BRAND_REGISTRATION, $this->brandOperationKey($business, $submission));

        try {
            [$brandId, $tcrBrandId] = $this->createBrandAtProvider($business, $submission);
        } catch (\Throwable $e) {
            $this->settleAfterFailure($reservationId, $e);

            throw $e;
        }

        // The carrier holds this brand now: its fee is earned and never handed back.
        $this->wallet->commit($reservationId);

        return new RegistrationSubmissionResult($brandId, null, null, MessagingRegistrationStatus::NotStarted, $tcrBrandId);
    }

    /**
     * GET /10dlc/brand/{id}. An HTTP 200 / `status: OK` only proves the lookup
     * worked; verification is the brand's `identityStatus`. Rate limits,
     * timeouts, server errors and unreadable bodies are UNCHANGED, never final.
     */
    public function refreshBrandStatus(string $providerBrandId): BrandStatusResult
    {
        try {
            $response = $this->client()->get(self::API_BASE . '/10dlc/brand/' . rawurlencode($providerBrandId));
        } catch (\Throwable) {
            return new BrandStatusResult(BrandStatusResult::UNCHANGED);
        }

        if (! $response->successful()) {
            return new BrandStatusResult(BrandStatusResult::UNCHANGED);
        }

        $identity = strtoupper($this->providerValue($response, ['identityStatus', 'data.identityStatus']));
        $status = strtoupper($this->providerValue($response, ['status', 'data.status']));
        $tcr = $this->providerValue($response, ['tcrBrandId', 'data.tcrBrandId']);
        $tcr = $tcr !== '' ? $tcr : null;

        if ($identity === '' && $status === '') {
            return new BrandStatusResult(BrandStatusResult::UNCHANGED);
        }

        if (in_array($status, ['REGISTRATION_FAILED', 'FAILED', 'REJECTED', 'DELETED'], true)) {
            return new BrandStatusResult(BrandStatusResult::NEEDS_ATTENTION, $tcr, 'The carrier rejected this business\'s verification. Contact support to correct and resubmit it.');
        }

        if (in_array($identity, ['VERIFIED', 'VETTED_VERIFIED'], true) && ! in_array($status, ['REGISTRATION_PENDING', 'PENDING'], true)) {
            return new BrandStatusResult(BrandStatusResult::VERIFIED, $tcr);
        }

        // UNVERIFIED, SELF_DECLARED, a pending registration or any value not
        // seen before: the carrier is still working. Never guessed verified.
        return new BrandStatusResult(BrandStatusResult::PROCESSING, $tcr);
    }

    /**
     * Creates the carrier brand and saves its id durably. Throws exactly what
     * the one-shot path always did (unconfirmed outcome, provider rejection).
     *
     * @return array{0: string, 1: ?string} [brand id, TCR brand id when supplied]
     */
    private function createBrandAtProvider(Business $business, MessagingRegistrationSubmission $submission): array
    {
        try {
            $brandResponse = $this->client()->post(self::API_BASE . '/10dlc/brand', array_filter([
                'entityType' => $submission->entityType === MessagingEntityType::Ein ? 'PRIVATE_PROFIT' : 'SOLE_PROPRIETOR',
                'displayName' => $submission->legalBusinessName,
                'companyName' => $submission->legalBusinessName,
                'firstName' => $submission->contactFirstName,
                'lastName' => $submission->contactLastName,
                'ein' => $submission->ein,
                'email' => $submission->contactEmail,
                'phone' => $submission->contactPhone,
                'street' => $submission->addressLine1,
                'city' => $submission->city,
                // Telnyx: "Must be 2 letters code for United States."
                'state' => CarrierRegistrationOptions::stateCode($submission->region),
                'postalCode' => $submission->postalCode,
                'country' => $submission->countryCode,
                'website' => $submission->websiteUrl,
                // Required by Telnyx; the customer's own choice, never inferred here.
                'vertical' => $submission->brandVertical,
            ], fn ($value) => $value !== null && $value !== ''));
        } catch (ConnectionException $e) {
            throw $this->unconfirmedOutcome($business, self::INCIDENT_BRAND_UNKNOWN, null, 'brand request did not complete');
        }

        if (! $brandResponse->successful()) {
            $this->logProviderFailure($business, 'brand', $brandResponse);

            if ($brandResponse->serverError() || $brandResponse->status() === 408) {
                throw $this->unconfirmedOutcome($business, self::INCIDENT_BRAND_UNKNOWN, null, 'brand status=' . $brandResponse->status());
            }

            throw new \App\Library\Messaging\Exceptions\MessagingRegistrationProviderException('Telnyx rejected the 10DLC brand submission.', 'brand');
        }

        $brandId = $this->providerValue($brandResponse, ['brandId', 'data.brandId', 'data.id']);

        if ($brandId === '') {
            throw $this->unconfirmedOutcome($business, self::INCIDENT_BRAND_UNKNOWN, null, 'brand accepted without an id');
        }

        // Durable before the next external step: a later campaign
        // failure must never lose the brand this call just created.
        $tcrBrandId = $this->providerValue($brandResponse, ['tcrBrandId', 'data.tcrBrandId']);

        BusinessMessagingRegistration::query()
            ->where('business_id', $business->id)
            ->update(array_filter([
                'provider_brand_id' => $brandId,
                'provider_brand_tcr_id' => $tcrBrandId !== '' ? $tcrBrandId : null,
                // A new brand is never trusted as verified: the carrier checks it.
                'brand_status' => 'processing',
                'brand_created_at' => now(),
            ], fn ($v) => $v !== null));

        return [$brandId, $tcrBrandId !== '' ? $tcrBrandId : null];
    }

    /**
     * The campaign's opt-in, opt-out and help confirmation messages, built
     * only from this registration's own brand name, declared purposes and
     * support contact. Nothing about consent is invented: the opt-in text
     * only names the programs the customer registered. Refuses (before any
     * funding) when a message would fall outside Telnyx's 20-320 characters
     * or the support contact is missing.
     *
     * @return array{optin: string, optout: string, help: string}
     */
    private function campaignConfirmationMessages(MessagingRegistrationSubmission $submission): array
    {
        $refuse = fn (string $why) => new MessagingRegistrationNotEligibleException($why . ' Nothing was charged.');
        $brand = trim((string) preg_replace('/\s+/u', ' ', $submission->legalBusinessName));
        $contact = trim((string) $submission->supportContact);

        if ($brand === '') {
            throw $refuse('Add your business name to build the text-message confirmation messages.');
        }

        if (! \App\Library\Messaging\Registration\RegistrationSubmissionValidator::isUsableSupportContact($contact)) {
            throw $refuse('Add and confirm your Business\'s own customer-facing support email or phone; it is used in the HELP confirmation message.');
        }

        // What a recipient is told they joined. The text only names the
        // campaign type the Business registered; for the mixed types it never
        // claims a recipient joined both programs, because each recipient may
        // have agreed to service texts, promotions, or both, separately.
        $program = match ($submission->useCase) {
            'customer_care', 'appointment_reminders' => 'customer care and appointment texts',
            'marketing' => 'promotional texts',
            'account_notifications' => 'account notification texts',
            'two_factor' => 'one-time passcode and verification texts',
            'mixed', 'low_volume_mixed' => 'the texts you agreed to (customer care and/or promotions, as you chose when you signed up)',
            default => null,
        };

        if ($program === null) {
            throw $refuse('This messaging type has no confirmation message wording.');
        }

        $messages = [
            'optin' => "{$brand}: You are now subscribed to {$program}. Message frequency varies. Msg & data rates may apply. Reply HELP for help or STOP to opt out.",
            'optout' => "{$brand}: You have been unsubscribed and will receive no more text messages from us. Reply START to subscribe again.",
            'help' => "{$brand}: For help, contact {$contact}. Message frequency varies. Msg & data rates may apply. Reply STOP to opt out.",
        ];

        foreach ($messages as $message) {
            $length = mb_strlen($message);

            if ($length < 20 || $length > 320) {
                throw $refuse('A text-message confirmation message would fall outside the carrier\'s 20-320 character limit; shorten your business name.');
            }
        }

        return $messages;
    }

    /**
     * Operator diagnostics for a failed brand/campaign call. Deliberately
     * allow-listed: only the stage, HTTP status, the provider's short error
     * code, the name of the rejected field (JSON pointer, never its value)
     * and the request id, each checked against a strict pattern. Never the
     * request payload, response body, title/detail text or any credential.
     */
    private function logProviderFailure(Business $business, string $stage, \Illuminate\Http\Client\Response $response): void
    {
        $safe = static function (mixed $value, int $max = 64): ?string {
            $value = is_scalar($value) ? (string) $value : '';

            return $value !== '' && strlen($value) <= $max && preg_match('/^[A-Za-z0-9_.\/\-]+$/', $value) === 1 ? $value : null;
        };

        $errors = $response->json('errors');
        $errors = is_array($errors) ? array_slice($errors, 0, 10) : [];
        $codes = array_values(array_unique(array_filter(array_map(fn ($e) => is_array($e) ? $safe($e['code'] ?? null) : null, $errors))));
        $fields = array_values(array_unique(array_filter(array_map(fn ($e) => is_array($e) ? $safe($e['source']['pointer'] ?? null) : null, $errors))));

        \Illuminate\Support\Facades\Log::warning($stage === 'toll_free' ? 'Telnyx toll-free verification provider call failed.' : 'Telnyx 10DLC provider call failed.', [
            'stage' => $stage,
            'business_id' => (int) $business->id,
            'http_status' => $response->status(),
            'provider_error_code' => $safe($response->json('errors.0.code')),
            'rejected_field' => $safe($response->json('errors.0.source.pointer')),
            'provider_error_codes' => $codes,
            'rejected_fields' => $fields,
            'request_id' => $safe($response->header('x-request-id')) ?? $safe($response->header('x-telnyx-request-id')),
        ]);
    }

    private function providerValue(\Illuminate\Http\Client\Response $response, array $paths): string
    {
        foreach ($paths as $path) {
            $value = $response->json($path);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    /**
     * A definite failure hands the held funds back. An UNCONFIRMED provider
     * outcome (the order or campaign may exist and be billing at the
     * carrier) keeps the charge: a real carrier cost is never absorbed by
     * releasing the hold, and the operator who resolves the incident
     * credits it back only if the carrier confirms nothing was created.
     */
    private function settleAfterFailure(int $reservationId, \Throwable $e): void
    {
        if ($e instanceof MessagingRegistrationRecoveryRequiredException) {
            $this->wallet->commit($reservationId);

            return;
        }

        $this->wallet->release($reservationId);
    }

    private function unconfirmedNumberOrder(Business $business, AvailableNumberCandidate $candidate, string $messagingProfileId, string $detail): MessagingRegistrationRecoveryRequiredException
    {
        app(ProvisioningIncidentRecorder::class)->record(
            business: $business,
            stage: self::INCIDENT_NUMBER_ORDER_UNKNOWN,
            messagingProfileId: $messagingProfileId,
            providerPhoneNumberId: null,
            phoneNumber: $candidate->phoneNumber,
            numberType: $candidate->numberType->value,
            errorMessage: $detail,
        );

        return new MessagingRegistrationRecoveryRequiredException('The number order outcome could not be confirmed.');
    }

    private function unconfirmedOutcome(Business $business, string $stage, ?string $brandId, string $detail): MessagingRegistrationRecoveryRequiredException
    {
        app(ProvisioningIncidentRecorder::class)->record(
            business: $business,
            stage: $stage,
            messagingProfileId: null,
            providerPhoneNumberId: null,
            phoneNumber: null,
            numberType: PhoneNumberType::Local->value,
            errorMessage: trim(($brandId !== null ? 'brand_id=' . $brandId . ' ' : '') . $detail),
        );

        return new MessagingRegistrationRecoveryRequiredException('The provider outcome could not be confirmed.');
    }

    /**
     * Stable, registration-level funding identity, scoped to the funding
     * cycle (one per carrier rejection). Each failed attempt releases its
     * reservation (terminal), so the next attempt moves to the next suffix;
     * a still-pending one (a crash before release) is reused rather than
     * duplicated.
     *
     * A committed reservation in this cycle means the fee was already
     * charged. When the saved brand and campaign are present it is
     * unambiguously the same successful submission and null is returned
     * (nothing to reserve, nothing to send). Anything else is ambiguous:
     * an incident is recorded and automatic retry stays blocked.
     */
    private function registrationOperationKey(Business $business, string $featureKey, MessagingRegistrationSubmission $submission): ?string
    {
        $repository = app(BusinessUsageReservationRepository::class);

        for ($attempt = 1; $attempt <= 50; $attempt++) {
            $key = sprintf('messaging-registration:%d:%s:%s:%d', $business->id, $featureKey, $submission->fundingCycle, $attempt);
            $existing = $repository->findByIdempotencyKey($key);

            if ($existing === null || $existing->status === UsageReservationStatus::Pending) {
                return $key;
            }

            if ($existing->status === UsageReservationStatus::Committed) {
                if ($submission->existingBrandId !== null && $submission->existingCampaignId !== null) {
                    return null;
                }

                throw $this->unconfirmedOutcome($business, self::INCIDENT_FUNDING_AMBIGUOUS, $submission->existingBrandId, 'fee committed but saved provider ids are incomplete');
            }
        }

        throw $this->unconfirmedOutcome($business, self::INCIDENT_FUNDING_AMBIGUOUS, $submission->existingBrandId, 'too many failed attempts in one funding cycle');
    }

    private function submitTollFreeVerification(MessagingRegistrationSubmission $submission): RegistrationSubmissionResult
    {
        // Review correction — Telnyx's toll-free verification genuinely
        // cannot be submitted without the number already being verified
        // (confirmed via current Telnyx documentation: the request body
        // carries a `phoneNumbers` array, and Telnyx's own guidance warns
        // the number must already be assigned to a messaging profile
        // before submitting, or the submission must be started over). This
        // platform's own controller never reaches this method without an
        // already-owned number for a toll-free registration — this is a
        // second, independent check at the adapter boundary, never trusted
        // from the caller alone.
        if ($submission->phoneNumber === null) {
            throw new \RuntimeException('Toll-free verification cannot be submitted without an already-owned number.');
        }

        // Provider-required values, checked before any request: Telnyx
        // validates these and a guessed value would only be rejected.
        $tollFreeUseCase = CarrierRegistrationOptions::TOLL_FREE_USE_CASES[$submission->useCase] ?? null;

        if ($submission->existingVerificationId === null) {
            $failed = array_keys(array_filter([
                'use_case' => $tollFreeUseCase === null,
                'message_volume' => ! isset(CarrierRegistrationOptions::MESSAGE_VOLUMES[(string) $submission->messageVolume]),
                'state' => CarrierRegistrationOptions::stateName($submission->region) === null,
                'contact_name' => trim((string) $submission->contactFirstName) === '' || trim((string) $submission->contactLastName) === '',
                'opt_in_url' => \App\Library\Messaging\Registration\RegistrationSubmissionValidator::optInEvidenceUrlIssue((string) $submission->optInImageUrl) !== null,
            ]));

            if ($failed !== []) {
                // Our own check, before any provider request: names only, never values.
                throw new \App\Library\Messaging\Exceptions\MessagingRegistrationPreflightException($failed);
            }
        }

        $business = Business::query()->findOrFail($submission->businessId);

        // Toll-free verification is $0 through Telnyx: there is genuinely
        // no fee to reserve, so this path never touches the usage wallet
        // (and so needs no billing meter, and can never charge). The
        // carrier verification itself is still submitted — Pending until
        // Telnyx approves it. Number rental and SMS usage are billed
        // through their own paths, unchanged.

        // A request the provider already accepted on an earlier attempt
        // (saved before the status write) is never submitted twice.
        if ($submission->existingVerificationId !== null) {
            return new RegistrationSubmissionResult(null, null, $submission->existingVerificationId, MessagingRegistrationStatus::Pending);
        }

        // An earlier attempt whose provider outcome was never confirmed
        // blocks automatic retry until an operator resolves the incident.
        if (BusinessMessagingProvisioningIncident::query()
            ->where('business_id', $business->id)
            ->where('stage', self::INCIDENT_TOLLFREE_UNKNOWN)
            ->whereNull('resolved_at')
            ->exists()) {
            throw new MessagingRegistrationRecoveryRequiredException('An earlier toll-free verification submission has an unconfirmed provider outcome.');
        }

        try {
            $response = $this->client()->post(self::API_BASE . '/messaging_tollfree/verification/requests', array_filter([
                'businessName' => $submission->legalBusinessName,
                'corporateWebsite' => $submission->websiteUrl,
                'businessAddr1' => $submission->addressLine1,
                'businessAddr2' => $submission->addressLine2,
                'businessCity' => $submission->city,
                // Telnyx: "The full name of the state (not the 2 letter code)".
                'businessState' => CarrierRegistrationOptions::stateName($submission->region),
                'businessZip' => $submission->postalCode,
                'businessContactFirstName' => $submission->contactFirstName,
                'businessContactLastName' => $submission->contactLastName,
                'businessContactEmail' => $submission->contactEmail,
                'businessContactPhone' => $submission->contactPhone,
                'messageVolume' => $submission->messageVolume,
                'useCase' => $tollFreeUseCase,
                'useCaseSummary' => mb_substr((string) $submission->campaignDescription, 0, 500),
                'productionMessageContent' => $submission->sampleMessage1,
                'optInWorkflow' => $submission->optInMethod,
                'optInWorkflowImageURLs' => [['url' => $submission->optInImageUrl]],
                'doingBusinessAs' => $this->tollFreeDoingBusinessAs($submission),
                // Optional ISV name: only when the platform is configured as a reseller account.
                'isvReseller' => $this->tollFreeIsvReseller(),
                'additionalInformation' => $this->tollFreeAdditionalInformation($submission),
                'businessRegistrationNumber' => $submission->entityType === MessagingEntityType::Ein ? $submission->ein : null,
                'businessRegistrationType' => $submission->entityType === MessagingEntityType::Ein ? 'EIN' : null,
                'businessRegistrationCountry' => $submission->entityType === MessagingEntityType::Ein ? 'US' : null,
                'entityType' => $submission->entityType === MessagingEntityType::Ein ? 'PRIVATE_PROFIT' : 'SOLE_PROPRIETOR',
                'privacyPolicyURL' => $submission->privacyPolicyUrl,
                'termsAndConditionURL' => $submission->termsUrl,
                'optInKeywords' => 'START,SUBSCRIBE',
                'phoneNumbers' => [['phoneNumber' => $submission->phoneNumber]],
            ], fn ($value) => $value !== null && $value !== ''));
        } catch (ConnectionException) {
            throw $this->unconfirmedOutcome($business, self::INCIDENT_TOLLFREE_UNKNOWN, null, 'toll-free request did not complete');
        }

        if (! $response->successful()) {
            $status = $response->status();
            $this->logProviderFailure($business, 'toll_free', $response);

            // Only a definitive client-error refusal means no request was created. A 409
            // (a request may already exist), a timeout, a server error and any other
            // non-2xx (redirect, odd code) are uncertain, so they go to the incident path.
            $definitive = $status >= 400 && $status < 500 && ! in_array($status, [408, 409], true);

            if (! $definitive) {
                throw $this->unconfirmedOutcome($business, self::INCIDENT_TOLLFREE_UNKNOWN, null, 'toll-free status=' . $status);
            }

            $pointers = $response->json('errors.*.source.pointer');
            $pointers = is_array($pointers) ? array_slice(array_values(array_filter($pointers, fn ($p) => is_string($p) && strlen($p) <= 64 && preg_match('/^[A-Za-z0-9_.\/\-]+$/', $p) === 1)), 0, 10) : [];

            throw new \App\Library\Messaging\Exceptions\MessagingRegistrationProviderException('Telnyx rejected the toll-free verification submission.', \App\Library\Messaging\Exceptions\MessagingRegistrationProviderException::STEP_TOLL_FREE, false, false, $status, $pointers);
        }

        $requestId = $this->providerValue($response, ['id', 'data.id', 'data.verificationId']);

        if ($requestId === '') {
            throw $this->unconfirmedOutcome($business, self::INCIDENT_TOLLFREE_UNKNOWN, null, 'toll-free accepted without an id');
        }

        // Durable before the local status write: a crash after this point
        // must never lead to a second verification request.
        BusinessMessagingRegistration::query()
            ->where('business_id', $business->id)
            ->update(['provider_registration_id' => $requestId]);

        // PR #295 Correction Round 1, item 7 — toll-free has no
        // brand/campaign concept; the single opaque id goes into its own
        // honest field, never duplicated into provider_brand_id/
        // provider_campaign_id.
        return new RegistrationSubmissionResult(null, null, $requestId, MessagingRegistrationStatus::Pending);
    }

    /** Owner-stated DBA only, and only when it really differs from the legal name. */
    private function tollFreeDoingBusinessAs(MessagingRegistrationSubmission $submission): ?string
    {
        $dba = trim((string) $submission->doingBusinessAs);

        return $dba !== '' && strcasecmp($dba, trim((string) $submission->legalBusinessName)) !== 0 ? mb_substr($dba, 0, 500) : null;
    }

    private function tollFreeIsvReseller(): ?string
    {
        $name = config('messaging.toll_free_isv_reseller');

        return is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 500) : null;
    }

    /**
     * Telnyx requires this text. It carries only what the owner stated: the DBA relationship
     * (when one was given) and their own note about legitimate name/domain differences — never
     * a copy of the campaign description and never an inferred explanation.
     */
    private function tollFreeAdditionalInformation(MessagingRegistrationSubmission $submission): string
    {
        $parts = [];
        $dba = $this->tollFreeDoingBusinessAs($submission);

        if ($dba !== null) {
            $parts[] = 'Legal entity ' . trim((string) $submission->legalBusinessName) . ' operates the customer-facing brand ' . $dba . '.';
        }

        if (trim((string) $submission->additionalInformation) !== '') {
            $parts[] = trim((string) $submission->additionalInformation);
        }

        return mb_substr($parts === [] ? 'No additional information.' : implode(' ', $parts), 0, 500);
    }

    /**
     * Review correction — the exact-equality REJECTED/FAILED/DECLINED
     * match missed every real 10DLC failure status this additional
     * research confirmed (team-telnyx's own public knowledge-base and
     * Telnyx's release notes): MNO_REJECTED, TCR_FAILED, TELNYX_FAILED and
     * MNO_PROVISIONING_FAILED are all genuine rejection states that never
     * equalled the old bare strings, so a truly rejected campaign stayed
     * Pending forever under the previous mapping. Matched conservatively
     * by substring now (never the reverse: nothing that merely CONTAINS
     * "ACTIVE" is treated as approved), and the "never guess Approved"
     * discipline is unchanged — ACTIVE remains the one confirmed success
     * value (confirmed independently via team-telnyx/knowledge-base); the
     * originally-guessed APPROVED/VERIFIED aliases are kept only because
     * removing an already-shipped, harmless allowance is not this
     * correction's job.
     *
     * $reason is read from the first of several plausible field names
     * this environment's egress limits prevented independently confirming
     * field-by-field: `failureReasons` (an array, joined) and `reasons`
     * (an array, joined — the name team-telnyx's own webhook-payload
     * documentation uses for registration failures). Null when none of
     * them are present — the caller then shows an explicit "no detailed
     * reason was supplied" message, never a guessed one.
     */
    private function refreshTenDlcCampaignStatus(RegistrationStatusQuery $query): RegistrationStatusResult
    {
        if ($query->providerCampaignId === null) {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Pending);
        }

        try {
            $response = $this->client()->get(self::API_BASE . '/10dlc/campaign/' . $query->providerCampaignId);
        } catch (ConnectionException) {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Pending, null, false);
        }

        if (! $response->successful()) {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Pending, null, false);
        }

        // GET /10dlc/campaign/{id} returns a root-level campaign object:
        // `campaignStatus` (TCR_/TELNYX_/MNO_ stages), `status` (ACTIVE or
        // EXPIRED) and `submissionStatus`. Only a campaign the carriers have
        // actually provisioned (MNO_PROVISIONED) is Approved; an absent or
        // unknown value is Pending, never guessed Approved.
        $campaignStatus = strtoupper($this->providerValue($response, ['campaignStatus', 'data.campaignStatus']));
        $status = strtoupper($this->providerValue($response, ['status', 'data.status']));
        $submission = strtoupper($this->providerValue($response, ['submissionStatus', 'data.submissionStatus']));

        // A body carrying no status field at all is malformed, not a verdict.
        if ($campaignStatus === '' && $status === '' && $submission === '') {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Pending, null, false);
        }

        if ($campaignStatus === 'MNO_PROVISIONED') {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Approved);
        }

        $failed = in_array($campaignStatus, ['TCR_FAILED', 'TELNYX_FAILED', 'MNO_REJECTED', 'MNO_PROVISIONING_FAILED'], true)
            || $submission === 'FAILED';

        if ($failed) {
            return new RegistrationStatusResult(
                MessagingRegistrationStatus::Rejected,
                $this->extractReason($response, ['failureReasons', 'data.failureReasons', 'data.reasons']),
            );
        }

        if (in_array($campaignStatus, ['TCR_EXPIRED', 'TCR_SUSPENDED'], true) || $status === 'EXPIRED') {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Rejected, 'The carrier campaign expired or was suspended.');
        }

        return new RegistrationStatusResult(MessagingRegistrationStatus::Pending);
    }

    private function refreshTollFreeVerificationStatus(RegistrationStatusQuery $query): RegistrationStatusResult
    {
        if ($query->providerRegistrationId === null) {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Pending);
        }

        try {
            $response = $this->client()->get(self::API_BASE . '/messaging_tollfree/verification/requests/' . $query->providerRegistrationId);
        } catch (ConnectionException) {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Pending, null, false);
        }

        if (! $response->successful()) {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Pending, null, false);
        }

        $status = strtolower($this->providerValue($response, ['verificationStatus', 'data.verificationStatus', 'data.status']));

        // A body with no status at all is a malformed answer, not a verdict.
        if ($status === '') {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Pending, null, false);
        }

        if ($status === 'verified') {
            return new RegistrationStatusResult(MessagingRegistrationStatus::Approved);
        }

        if (str_contains($status, 'reject') || str_contains($status, 'declin') || str_contains($status, 'fail')) {
            // Field name not independently confirmable in this environment
            // (egress-limited, same constraint this class's own docblock
            // already documents elsewhere) — support materials repeatedly
            // call this a "decline reason", so `declineReason` is tried
            // first, then the more generic names every other regime here
            // already tries.
            return new RegistrationStatusResult(
                MessagingRegistrationStatus::Rejected,
                $this->extractReason($response, ['reason', 'declineReason', 'data.declineReason', 'data.rejectionReason', 'data.reason']),
            );
        }

        // Covers the confirmed documented pending states ("Waiting for
        // Telnyx", "Waiting For Customer") and any other value this
        // platform has not seen before — never guessed Approved.
        return new RegistrationStatusResult(MessagingRegistrationStatus::Pending);
    }

    /**
     * Tries each field, in order, and returns the first non-blank one it
     * finds — a string as-is, or an array of strings joined with "; ".
     * Null when nothing usable is present, so the caller shows an
     * explicit "no detailed reason was supplied" message rather than a
     * guessed one.
     *
     * @param  list<string>  $fieldPaths
     */
    private function extractReason(\Illuminate\Http\Client\Response $response, array $fieldPaths): ?string
    {
        foreach ($fieldPaths as $path) {
            $value = $response->json($path);

            if (is_array($value)) {
                $value = implode('; ', array_filter(array_map(
                    fn ($item) => is_scalar($item) ? (string) $item : (is_array($item) ? ($item['reason'] ?? $item['message'] ?? null) : null),
                    $value,
                )));
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
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
    private function reserveFunding(Business $business, string $featureKey, ?string $idempotencyKey = null, ?string $quantity = null): int
    {
        try {
            // A Business whose creation-time listener failed (or that
            // predates the wallet backfill) has no wallet row. Provision it
            // through the canonical idempotent, race-safe initializer — the
            // wallet starts at a zero balance, so the funding check below
            // still refuses an unfunded Business exactly as before.
            $this->wallet->initializeWalletForNewBusiness($business->id);

            $result = $this->wallet->reserve($business, $featureKey, $idempotencyKey ?? (string) Str::uuid(), $quantity);
        } catch (BusinessCurrencyUnresolvableException|UsageWalletNotFoundException|NoActiveRateForFeatureException|UsageMeterNotMeteredException|UsageMeterBusinessScopeMismatchException|UsageMeterCurrencyMismatchException|UsageMeterRateIntegrityException $e) {
            $pricingMissing = $e instanceof NoActiveRateForFeatureException || $e instanceof UsageMeterNotMeteredException;

            throw new MessagingFundingUnavailableException(sprintf(
                'Funding is not yet configured for [%s]: %s',
                $featureKey,
                $e->getMessage(),
            ), 0, $e, $pricingMissing
                ? MessagingFundingUnavailableException::REASON_PRICING_NOT_CONFIGURED
                : MessagingFundingUnavailableException::REASON_WALLET_CONFIGURATION);
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
