<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\PortOutRequestStatus;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Library\Messaging\Exceptions\PortOutRequestAlreadyActiveException;
use App\Library\Messaging\Exceptions\PortOutRequestNumberNotPortableException;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use App\Models\BusinessMessagingNumberPortOutRequest;
use Illuminate\Database\Eloquent\Collection;
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
     * Review correction — a Pending number is not yet an actually acquired
     * one. The schema's own default is 'pending' (business_messaging_numbers'
     * creating migration), attachNumber()'s own default status, and the
     * *reservation* it shares with Active under the active_or_pending_phone_number
     * guard column is a claim on the E.164 number against a duplicate
     * mapping — never a confirmed carrier acquisition. The real,
     * synchronous provisioning path (BusinessMessagingProvisioningService)
     * writes Active directly; it never leaves a genuinely purchased number
     * sitting at Pending. Only Active and Suspended represent a number the
     * Business actually holds today — Suspended still retains it (§13.3:
     * suspension "stops new paid outbound while retaining the number").
     * Pending and Released are both excluded, for opposite reasons: Pending
     * because there may be nothing real to port yet, Released because there
     * is nothing left to port at all.
     *
     * @var list<string>
     */
    private const PORTABLE_STATUSES = [
        BusinessMessagingNumberStatus::Active->value,
        BusinessMessagingNumberStatus::Suspended->value,
    ];

    /**
     * Review correction — every actually acquired, non-released number
     * belonging to this Business, not only its primary number. The
     * one-to-many schema (§4.2) and the contract both allow more than one
     * retained number; the earlier single-number, primary-only lookup lost
     * the port-out path for every number once a Business held two.
     *
     * Deliberately NOT BusinessMessagingIdentityResolver::resolveForBusiness()
     * / resolvePrimaryNumber(): those are Slice 3's outbound-send/inbound-
     * attribution contract, restricted to one Active identity and one
     * Active primary number (T-MSG-3) — the wrong restriction here.
     * Ownership is always resolved through each identity's own business_id
     * (BusinessMessagingNumber carries no business_id column of its own).
     *
     * @return Collection<int, BusinessMessagingNumber>
     */
    public function retainedNumbersFor(Business $business): Collection
    {
        $identityIds = BusinessMessagingIdentity::query()
            ->where('business_id', (int) $business->id)
            ->pluck('id');

        if ($identityIds->isEmpty()) {
            return new Collection();
        }

        return BusinessMessagingNumber::query()
            ->whereIn('business_messaging_identity_id', $identityIds)
            ->whereIn('status', self::PORTABLE_STATUSES)
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get();
    }

    /**
     * The mutation-boundary lookup for a client-posted number id: never
     * trusted alone. Returns the number only when it both belongs to this
     * Business (through its identity's business_id) AND is currently in a
     * portable status — the same two checks request() below re-verifies
     * independently before writing anything, so a caller that skips this
     * lookup (or is wrong about it) still cannot make a bad write.
     */
    public function retainedNumberFor(Business $business, int $numberId): ?BusinessMessagingNumber
    {
        $identityIds = BusinessMessagingIdentity::query()
            ->where('business_id', (int) $business->id)
            ->pluck('id');

        if ($identityIds->isEmpty()) {
            return null;
        }

        return BusinessMessagingNumber::query()
            ->where('id', $numberId)
            ->whereIn('business_messaging_identity_id', $identityIds)
            ->whereIn('status', self::PORTABLE_STATUSES)
            ->first();
    }

    /**
     * @throws MessagingIdentityConflictException       when $number does not
     *                                                   actually belong to
     *                                                   $business — the
     *                                                   manager never trusts
     *                                                   a caller-supplied
     *                                                   pairing, even though
     *                                                   every current caller
     *                                                   resolves $number
     *                                                   from $business
     *                                                   itself first
     * @throws PortOutRequestNumberNotPortableException when $number is not
     *                                                   currently in a
     *                                                   portable status
     *                                                   (Pending or
     *                                                   Released) — checked
     *                                                   here too, never
     *                                                   trusted from the
     *                                                   caller's own filter
     * @throws PortOutRequestAlreadyActiveException when this number already
     *                                               has an unresolved
     *                                               request, whether found
     *                                               by the pre-check or by
     *                                               MySQL's own UNIQUE index
     *                                               losing a concurrent race
     *
     * Review correction — locks the exact same business_messaging_numbers
     * row that NumberLifecycleManager::recordReleaseDecision() locks,
     * before ever checking or writing anything, so a port-out request and
     * a release decision always serialize through one another instead of
     * racing: whichever transaction acquires this row first runs fully to
     * completion before the other proceeds. That means a release decision
     * can never be recorded while a request() call for the same number is
     * still in flight (it will see this request once request() commits),
     * and conversely this method always evaluates portability against
     * whatever the most recently committed state actually is — never a
     * stale $number the caller loaded earlier.
     */
    public function request(Business $business, BusinessMessagingNumber $number, int $requestedByUserId): BusinessMessagingNumberPortOutRequest
    {
        $this->assertNumberBelongsToBusiness($business, $number);

        try {
            return DB::transaction(function () use ($business, $number, $requestedByUserId): BusinessMessagingNumberPortOutRequest {
                $lockedNumber = BusinessMessagingNumber::query()->whereKey($number->id)->lockForUpdate()->first();

                if ($lockedNumber === null) {
                    throw new PortOutRequestNumberNotPortableException(sprintf(
                        'Number [%d] no longer exists.',
                        (int) $number->id,
                    ));
                }

                $this->assertNumberIsPortable($lockedNumber);

                $existing = BusinessMessagingNumberPortOutRequest::query()
                    ->where('business_messaging_number_id', (int) $lockedNumber->id)
                    ->active()
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof BusinessMessagingNumberPortOutRequest) {
                    throw new PortOutRequestAlreadyActiveException(sprintf(
                        'Number [%d] already has an active port-out request.',
                        (int) $lockedNumber->id,
                    ));
                }

                $request = new BusinessMessagingNumberPortOutRequest([
                    'business_id' => (int) $business->id,
                    'business_messaging_number_id' => (int) $lockedNumber->id,
                    'phone_number' => $lockedNumber->phone_number,
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

    /**
     * @throws PortOutRequestNumberNotPortableException
     */
    private function assertNumberIsPortable(BusinessMessagingNumber $number): void
    {
        if (! in_array($number->status->value, self::PORTABLE_STATUSES, true)) {
            throw new PortOutRequestNumberNotPortableException(sprintf(
                'Number [%d] is not currently portable (status: %s).',
                (int) $number->id,
                $number->status->value,
            ));
        }
    }
}
