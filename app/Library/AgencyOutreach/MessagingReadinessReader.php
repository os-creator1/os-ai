<?php

namespace App\Library\AgencyOutreach;

use App\Enums\Messaging\PhoneNumberType;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\PortOutRequestManager;
use App\Models\Business;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberPortOutRequest;
use App\Models\BusinessMessagingRegistration;
use Illuminate\Database\Eloquent\Collection;

/**
 * The ONE read of "what is this Business's text-messaging situation?":
 * no number | number_required | registration_required | ready.
 *
 * Extracted verbatim from TextMessagingController::situation() so Settings ->
 * Text messaging and Outreach readiness can never disagree about whether a
 * Business can send. Read-only: no writes, no provider call.
 */
class MessagingReadinessReader
{
    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly PortOutRequestManager $portOutRequests,
    ) {
    }

    /**
     * @return array{state: string, phoneNumber: ?string, textingAvailable: bool, mediaAvailable: bool, registration: ?BusinessMessagingRegistration, campaignAssignmentStatus?: ?string, campaignAssignmentFailureReason?: ?string, retainedNumbers: Collection<int, BusinessMessagingNumber>, portOutRequestsByNumberId: array<int, ?BusinessMessagingNumberPortOutRequest>}
     */
    public function situation(Business $business): array
    {
        $identity = $this->identities->resolveForBusiness($business);
        $registration = $this->registrationFor($business);

        // Computed once, independent of the active-identity/active-number
        // branches below, through the ownership-safe lookup: the port-out exit
        // path must stay reachable for every retained number whichever branch
        // is taken.
        $portOutContext = $this->portOutContextFor($business);

        if ($identity === null) {
            // Telnyx supports (and this platform requires) completing 10DLC
            // business verification BEFORE any local number is purchased;
            // toll-free can never reach this branch un-Approved, since its own
            // carrier verification needs an already-owned number.
            if ($registration !== null && $registration->number_type === PhoneNumberType::Local) {
                $state = $registration->isApproved() ? 'number_required' : 'registration_required';

                return ['state' => $state, 'phoneNumber' => null, 'textingAvailable' => false, 'mediaAvailable' => false, 'registration' => $registration, ...$portOutContext];
            }

            return ['state' => 'no_number', 'phoneNumber' => null, 'textingAvailable' => false, 'mediaAvailable' => false, 'registration' => $registration, ...$portOutContext];
        }

        try {
            $number = $this->identities->resolvePrimaryNumber($identity);
        } catch (MessagingIdentityConflictException) {
            return ['state' => 'no_number', 'phoneNumber' => null, 'textingAvailable' => false, 'mediaAvailable' => false, 'registration' => $registration, ...$portOutContext];
        }

        // A local number's own carrier-side campaign assignment must be
        // genuinely Confirmed, never merely Requested, before the customer is
        // told they are Ready. See
        // BusinessMessagingNumber::isCampaignAssignmentConfirmedOrNotRequired().
        $ready = $number->isActive()
            && $registration !== null
            && $registration->isApproved()
            && $number->isCampaignAssignmentConfirmedOrNotRequired();

        return [
            'state' => $ready ? 'ready' : 'registration_required',
            'phoneNumber' => $number->phone_number,
            'textingAvailable' => $ready,
            'mediaAvailable' => $ready,
            'registration' => $registration,
            'campaignAssignmentStatus' => $number->campaign_assignment_status,
            'campaignAssignmentFailureReason' => $number->campaign_assignment_failure_reason,
            ...$portOutContext,
        ];
    }

    /**
     * @return array{retainedNumbers: Collection<int, BusinessMessagingNumber>, portOutRequestsByNumberId: array<int, ?BusinessMessagingNumberPortOutRequest>}
     */
    public function portOutContextFor(Business $business): array
    {
        $retainedNumbers = $this->portOutRequests->retainedNumbersFor($business);

        return [
            'retainedNumbers' => $retainedNumbers,
            'portOutRequestsByNumberId' => $retainedNumbers
                ->mapWithKeys(fn (BusinessMessagingNumber $number) => [
                    (int) $number->id => $this->portOutRequests->activeRequestFor($number),
                ])
                ->all(),
        ];
    }

    public function registrationFor(Business $business): ?BusinessMessagingRegistration
    {
        return BusinessMessagingRegistration::query()->where('business_id', $business->id)->first();
    }
}
