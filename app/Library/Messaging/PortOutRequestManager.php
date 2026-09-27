<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\PortOutRequestStatus;
use App\Library\Messaging\Exceptions\PortOutRequestAlreadyActiveException;
use App\Models\Business;
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
     * @throws PortOutRequestAlreadyActiveException when this number already
     *                                               has an unresolved
     *                                               request, whether found
     *                                               by the pre-check or by
     *                                               MySQL's own UNIQUE index
     *                                               losing a concurrent race
     */
    public function request(Business $business, BusinessMessagingNumber $number, int $requestedByUserId): BusinessMessagingNumberPortOutRequest
    {
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
}
