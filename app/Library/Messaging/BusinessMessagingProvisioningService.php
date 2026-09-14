<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Library\Messaging\Contracts\MessagingProvisioningAdapter;
use App\Library\Messaging\DTO\AvailableNumberCandidate;
use App\Library\Messaging\DTO\NumberSearchCriteria;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\MessagingProviderNotConfiguredException;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
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
}
