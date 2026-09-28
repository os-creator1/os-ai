<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\CampaignAssignmentOutcome;
use App\Enums\Messaging\MessagingProvider;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\CampaignAssignmentResult;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Text messaging setup/number/compliance hub — STATE 1's "Get a phone
 * number" orchestration: a provider-neutral search, then a real order
 * that only writes BusinessMessagingIdentity/BusinessMessagingNumber
 * after a genuine (or explicitly-faked-in-a-test) provider round trip.
 *
 * Mirrors ManagedMessageDispatcher::dispatch()'s own discipline: the
 * adapter is resolved lazily, inside each method, never injected through
 * this service's own constructor — injecting it would make merely
 * constructing this service (e.g. via the container resolving a
 * controller's dependencies) throw MessagingProviderNotConfiguredException
 * whenever managed messaging is off, which is wrong for a service whose
 * whole job includes rendering a truthful INERT state rather than
 * exploding.
 *
 * PR #295 Correction Round 1, item 3 — provisionNumber() now reserves the
 * Business's one-and-only identity slot BEFORE any provider call, not
 * after. A concurrent second request (double-click, stale reload, a
 * second tab) fails on that reservation immediately, via the exact same
 * lockForUpdate()+UNIQUE-index mechanism BusinessMessagingIdentityResolver
 * already uses for every other identity write — it can never reach the
 * adapter, so it can never cause a second paid provider commitment. If the
 * provider call then succeeds but this platform's own local finalization
 * (attaching the number) fails, the already-real provider identifiers are
 * never dropped: they are written either onto the reserved identity row
 * itself (which is durably persisted before the number-attach step even
 * runs) or into a ProvisioningIncidentRecorder row for manual
 * reconciliation — never silently lost.
 */
class BusinessMessagingProvisioningService
{
    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly ProvisioningIncidentRecorder $incidents,
    ) {
    }

    public function isAvailable(): bool
    {
        return ProvisioningAvailability::isConfigured();
    }

    /**
     * @return list<AvailableNumberCandidate>
     */
    public function searchNumbers(NumberSearchCriteria $criteria): array
    {
        try {
            $adapter = app(MessagingProvisioningAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            // Preview/inert state — never a live search, never a guessed
            // result list.
            return [];
        }

        return $adapter->searchNumbers($criteria);
    }

    /**
     * Places the real order and persists the resulting identity/number
     * together. Only ever called with a candidate the caller obtained from
     * searchNumbers() in this same request — there is no path that reaches
     * a persisted BusinessMessagingNumber without a genuine adapter round
     * trip producing a ProvisionedNumberResult first (see
     * MessagingProvisioningAdapter's own docblock).
     *
     * @throws MessagingProviderNotConfiguredException when not configured — the
     *                                                  caller must have already
     *                                                  checked isAvailable()
     *                                                  before offering this
     *                                                  action at all
     * @throws MessagingIdentityConflictException       when the Business already
     *                                                  has an active-or-pending
     *                                                  identity or number, or
     *                                                  when a concurrent request
     *                                                  won the reservation first
     */
    public function provisionNumber(Business $business, AvailableNumberCandidate $candidate): BusinessMessagingNumber
    {
        // Deliberately NOT caught here — provisionNumber() must never be
        // reachable at all unless the caller already confirmed
        // isAvailable(); a thrown exception at this point is a caller bug,
        // not a state this method silently absorbs into an inert render,
        // and it must happen before any reservation is written.
        $adapter = app(MessagingProvisioningAdapter::class);

        // Reserve this Business's one-and-only identity slot BEFORE any
        // provider call. create() itself performs the lockForUpdate()
        // pre-check plus the real MySQL UNIQUE index
        // (bmi_provider_active_or_pending_business_unique) — a concurrent
        // second caller fails HERE, never reaching $adapter below, and
        // therefore never causing a second paid commitment. The real
        // messaging_profile_id is not known yet, so a unique per-attempt
        // placeholder holds the (also-UNIQUE) column until the provider
        // call finishes.
        $reservationToken = (string) Str::uuid();
        $identity = $this->identities->create(
            $business,
            'reserved:' . $reservationToken,
            null,
            MessagingProvider::Telnyx,
            BusinessMessagingIdentityStatus::Pending,
        );

        try {
            $result = $adapter->provisionNumber($business, $candidate);
        } catch (\Throwable $e) {
            // The reservation never reached the provider, or the provider
            // refused it outright — nothing real exists to reconcile, so
            // the placeholder is freed for another attempt rather than
            // left stuck "pending" forever.
            $identity->delete();

            throw $e;
        }

        // The provider call succeeded: persist its real identifiers onto
        // the ALREADY-DURABLE reservation row immediately, before
        // attempting the number attach below. If the attach step now
        // fails for any reason, this identity row still carries the real
        // messaging_profile_id — it is not lost — and the number side is
        // captured in the incident record.
        $identity->forceFill([
            'messaging_profile_id' => $result->messagingProfileId,
            'status' => BusinessMessagingIdentityStatus::Active->value,
            'activated_at' => now(),
        ])->save();

        try {
            return $this->identities->attachNumber(
                $identity,
                $result->phoneNumber,
                true,
                BusinessMessagingNumberStatus::Active,
                $result->providerPhoneNumberId,
                $candidate->numberType,
            );
        } catch (\Throwable $e) {
            $this->incidents->record(
                business: $business,
                stage: 'number_attach_failed_after_provider_success',
                messagingProfileId: $result->messagingProfileId,
                providerPhoneNumberId: $result->providerPhoneNumberId,
                phoneNumber: $result->phoneNumber,
                numberType: $candidate->numberType->value,
                errorMessage: $e->getMessage(),
            );

            throw $e;
        }
    }

    /**
     * Phone Numbers + A2P lane — the verify-first sequence's own final
     * step: link a freshly purchased local number's own, just-created
     * Messaging Profile to a 10DLC campaign this Business's business
     * verification already had Approved BEFORE this number ever existed.
     * Never a purchase, never funded — a pure linking call.
     *
     * Review correction — the result is now DURABLY persisted onto the
     * number row (campaign_assignment_status and friends), never held only
     * in this call's own return value: Telnyx's own assignment endpoint
     * returns a background task id, not an immediate confirmation, so
     * "Requested" must be recorded and later resolved by
     * refreshPendingCampaignAssignment(), never left as an in-memory fact
     * this call's caller could lose the moment the response is handled. A
     * failed (or unconfirmable — missing profile/campaign id) result is
     * ALSO recorded as a provisioning incident, exactly like
     * number_attach_failed_after_provider_success above: the number
     * itself is already genuinely purchased and charged by the time this
     * runs, so that success must never be silently lost or retroactively
     * undone just because this last linking step could not be confirmed.
     */
    public function assignToApprovedCampaign(Business $business, BusinessMessagingNumber $number, ?string $providerCampaignId): CampaignAssignmentResult
    {
        $messagingProfileId = $number->identity?->messaging_profile_id;

        if ($messagingProfileId === null) {
            return $this->finalizeAssignmentAttempt($business, $number, null, new CampaignAssignmentResult(CampaignAssignmentOutcome::Failed, 'missing_messaging_profile_id'));
        }

        if ($providerCampaignId === null || $providerCampaignId === '') {
            return $this->finalizeAssignmentAttempt($business, $number, $messagingProfileId, new CampaignAssignmentResult(CampaignAssignmentOutcome::Failed, 'missing_campaign_id'));
        }

        try {
            $adapter = app(MessagingProvisioningAdapter::class);
            $result = $adapter->assignMessagingProfileToCampaign($messagingProfileId, $providerCampaignId);
        } catch (MessagingProviderNotConfiguredException) {
            // Never reachable through the real controller flow (it only
            // calls this once provisionNumber() has already succeeded in
            // the very same request, so isAvailable() was already true) —
            // caught anyway, since this method's own contract is "never
            // block the number purchase's already-genuine success."
            $result = new CampaignAssignmentResult(CampaignAssignmentOutcome::Failed, 'not_configured');
        }

        return $this->finalizeAssignmentAttempt($business, $number, $messagingProfileId, $result);
    }

    /**
     * Review correction — the SAME scheduled-sweep pattern
     * BusinessMessagingRegistrationService::refreshAllPending() already
     * established: a Requested assignment is polled via the provider's
     * own confirmed completion mechanism until it resolves. Still
     * processing is a silent no-op (poll again next sweep); Confirmed or
     * Failed is persisted, and Failed is ALSO recorded as a provisioning
     * incident so a stuck number is visible to operators rather than
     * silently retried forever with no trace.
     */
    public function refreshPendingCampaignAssignment(BusinessMessagingNumber $number): void
    {
        if ($number->campaign_assignment_status !== CampaignAssignmentOutcome::Requested->value || $number->campaign_assignment_task_id === null) {
            return;
        }

        try {
            $adapter = app(MessagingProvisioningAdapter::class);
        } catch (MessagingProviderNotConfiguredException) {
            return;
        }

        $result = $adapter->checkCampaignAssignmentStatus($number->campaign_assignment_task_id, $number->phone_number);

        if ($result->outcome === CampaignAssignmentOutcome::Requested) {
            // Still processing — nothing new to persist; polled again on
            // the next sweep.
            return;
        }

        $this->finalizeAssignmentAttempt($number->identity->business, $number, $number->identity?->messaging_profile_id, $result);
    }

    /**
     * PR #295 Correction Round 1, item 6's own established pattern
     * (RefreshPendingMessagingRegistrations) — one provider-configuration
     * or per-number failure never aborts the rest of the sweep.
     */
    public function refreshAllPendingCampaignAssignments(): void
    {
        BusinessMessagingNumber::query()
            ->where('campaign_assignment_status', CampaignAssignmentOutcome::Requested->value)
            ->orderBy('id')
            ->chunkById(50, function ($numbers): void {
                foreach ($numbers as $number) {
                    try {
                        $this->refreshPendingCampaignAssignment($number);
                    } catch (\Throwable) {
                        continue;
                    }
                }
            });
    }

    /**
     * The single writer of business_messaging_numbers' five
     * campaign_assignment_* columns — never through the model's own mass
     * assignment (deliberately absent from BusinessMessagingNumber's own
     * $fillable). Persists the outcome and, on Failed, also records the
     * provisioning incident — the one place both of those durable writes
     * happen together, so neither call site above can accidentally do one
     * without the other.
     */
    private function finalizeAssignmentAttempt(Business $business, BusinessMessagingNumber $number, ?string $messagingProfileId, CampaignAssignmentResult $result): CampaignAssignmentResult
    {
        $attributes = [
            'campaign_assignment_status' => $result->outcome->value,
            'updated_at' => now(),
        ];

        if ($result->outcome === CampaignAssignmentOutcome::Requested) {
            $attributes['campaign_assignment_task_id'] = $result->taskId;
        } elseif ($result->outcome === CampaignAssignmentOutcome::Confirmed) {
            $attributes['campaign_assignment_confirmed_at'] = now();
            $attributes['campaign_assignment_failed_at'] = null;
            $attributes['campaign_assignment_failure_reason'] = null;
        } else {
            $attributes['campaign_assignment_failed_at'] = now();
            $attributes['campaign_assignment_failure_reason'] = $result->detail !== null ? Str::limit($result->detail, 490, '') : null;
        }

        DB::table('business_messaging_numbers')->where('id', $number->id)->update($attributes);

        if ($result->outcome === CampaignAssignmentOutcome::Failed) {
            $this->incidents->record(
                business: $business,
                stage: 'campaign_assignment_request_failed',
                messagingProfileId: $messagingProfileId,
                providerPhoneNumberId: $number->provider_number_reference,
                phoneNumber: $number->phone_number,
                numberType: $number->number_type->value,
                errorMessage: $result->detail,
            );
        }

        return $result;
    }
}
