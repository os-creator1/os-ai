<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\PortOutRequestStatus;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\PortOutRequestAlreadyActiveException;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberPortOutRequest;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Phone Numbers + A2P lane — messaging contract §13.4: "A customer may
 * port a number out. The platform must not obstruct it. Porting out is a
 * supported, documented request path, not a support escalation."
 *
 * This class records and tracks the request only. It never releases,
 * replaces, transfers or purchases a number, never touches
 * BusinessMessagingNumber.status, and never calls Telnyx. The actual port
 * happens outside this platform, through the customer's winning carrier
 * and Telnyx's own porting process; a later, separately authorized release
 * slice owns whatever happens to this platform's own number/identity
 * records once that real-world port completes.
 */
class PortOutRequestManager
{
    /**
     * Review correction — the ownership-safe lookup for §13.4's exit path.
     * Deliberately NOT BusinessMessagingIdentityResolver::resolveForBusiness()
     * / resolvePrimaryNumber(): those are Slice 3's outbound-send/inbound-
     * attribution contract, which is correctly restricted to an Active
     * identity and an Active primary number (T-MSG-3) — exactly the wrong
     * restriction here, since a customer with a Suspended number (§13.3:
     * "stops new paid outbound while retaining the number") or a Pending
     * identity still retains that number and must still be able to request
     * to leave with it. The only status this method excludes is Released —
     * a released number is no longer retained, so there is nothing left to
     * port. Ownership is always resolved through the identity's own
     * business_id (BusinessMessagingNumber carries no business_id column of
     * its own), never trusted from a caller-supplied pairing, and a
     * business with more than one retained primary number candidate is
     * refused rather than guessed, mirroring resolvePrimaryNumber()'s own
     * fail-closed discipline.
     */
    public function retainedNumberFor(Business $business): ?BusinessMessagingNumber
    {
        $identityIds = BusinessMessagingIdentity::query()
            ->where('business_id', (int) $business->id)
            ->pluck('id');

        if ($identityIds->isEmpty()) {
            return null;
        }

        $candidates = BusinessMessagingNumber::query()
            ->whereIn('business_messaging_identity_id', $identityIds)
            ->where('is_primary', true)
            ->where('status', '!=', BusinessMessagingNumberStatus::Released->value)
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @throws MessagingIdentityConflictException      when $number does not
     *                                                  actually belong to
     *                                                  $business — the
     *                                                  manager never trusts
     *                                                  a caller-supplied
     *                                                  pairing, even though
     *                                                  every current caller
     *                                                  resolves $number from
     *                                                  $business itself via
     *                                                  retainedNumberFor()
     * @throws PortOutRequestAlreadyActiveException when this number already
     *                                               has an unresolved
     *                                               request, whether found
     *                                               by the pre-check or by
     *                                               MySQL's own UNIQUE index
     *                                               losing a concurrent race
     */
    public function request(Business $business, BusinessMessagingNumber $number, int $requestedByUserId): BusinessMessagingNumberPortOutRequest
    {
        $this->assertNumberBelongsToBusiness($business, $number);

        try {
            return DB::transaction(function () use ($business, $number, $requestedByUserId): BusinessMessagingNumberPortOutRequest {
                $existing = BusinessMessagingNumberPortOutRequest::query()
                    ->where('business_messaging_number_id', (int) $number->id)
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof BusinessMessagingNumberPortOutRequest) {
                    throw new PortOutRequestAlreadyActiveException(sprintf(
                        'Number [%d] already has an active port-out request.',
                        (int) $number->id,
                    ));
                }

                $request = new BusinessMessagingNumberPortOutRequest([
                    'business_id' => (int) $business->id,
                    'business_messaging_number_id' => (int) $number->id,
                    'phone_number' => $number->phone_number,
                    'status' => PortOutRequestStatus::Requested->value,
                    'requested_by_user_id' => $requestedByUserId,
                ]);
                $request->save();

                return $request;
            });
        } catch (UniqueConstraintViolationException $e) {
            // NARROWED, deliberately — see
            // BusinessMessagingIdentityResolver::create()'s own identical
            // reasoning. Only a genuine unique-constraint violation is a
            // conflict; every other query failure propagates unchanged.
            throw new PortOutRequestAlreadyActiveException(
                'This number already has an active port-out request.',
                0,
                $e,
            );
        }
    }

    /**
     * Idempotent, auditable cancellation — an atomic conditional UPDATE
     * mirroring ProvisioningIncidentRecorder::resolve()'s exact shape. A
     * repeat or concurrent cancellation attempt on an already-cancelled (or
     * nonexistent) request updates zero rows rather than re-timestamping it
     * or overwriting who cancelled it.
     *
     * @return int the number of rows updated — 1 on a genuine cancellation,
     *             0 when the request was already cancelled or does not exist
     */
    public function cancel(int $requestId, int $cancelledByUserId): int
    {
        return DB::table('business_messaging_number_port_out_requests')
            ->where('id', $requestId)
            ->where('status', PortOutRequestStatus::Requested->value)
            ->update([
                'status' => PortOutRequestStatus::Cancelled->value,
                'cancelled_at' => now(),
                'cancelled_by_user_id' => $cancelledByUserId,
                'updated_at' => now(),
            ]);
    }

    public function activeRequestFor(BusinessMessagingNumber $number): ?BusinessMessagingNumberPortOutRequest
    {
        return BusinessMessagingNumberPortOutRequest::query()
            ->where('business_messaging_number_id', (int) $number->id)
            ->active()
            ->first();
    }

    /**
     * @throws MessagingIdentityConflictException
     */
    private function assertNumberBelongsToBusiness(Business $business, BusinessMessagingNumber $number): void
    {
        $identity = $number->identity;

        if (! $identity instanceof BusinessMessagingIdentity || (int) $identity->business_id !== (int) $business->id) {
            throw new MessagingIdentityConflictException(sprintf(
                'Number [%d] does not belong to Business [%d].',
                (int) $number->id,
                (int) $business->id,
            ));
        }
    }
}
