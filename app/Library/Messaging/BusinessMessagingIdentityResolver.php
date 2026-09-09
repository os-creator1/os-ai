<?php

namespace App\Library\Messaging;

use App\Enums\Messaging\BusinessMessagingIdentityStatus;
use App\Enums\Messaging\BusinessMessagingNumberStatus;
use App\Enums\Messaging\MessagingProvider;
use App\Library\Messaging\Exceptions\MessagingIdentityConflictException;
use App\Models\Business;
use App\Models\BusinessMessagingIdentity;
use App\Models\BusinessMessagingNumber;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Slice 3 §4.3/§4.5/§4.9 — resolution of managed messaging identities and
 * numbers.
 *
 * Every resolution method returns null, never a guess, on anything short of
 * exactly one unambiguous, active match. There is deliberately no
 * "first active" fallback anywhere in this class: ambiguity fails closed.
 *
 * The database is the mechanism for every uniqueness invariant, not a
 * backstop behind an application check — MySQL's UNIQUE indexes on the
 * STORED generated guard columns (§4.2) decide races regardless of timing.
 * The lockForUpdate() pre-checks below exist only to turn what would
 * otherwise be a raw QueryException into a clean, catchable
 * MessagingIdentityConflictException for a well-behaved caller; removing or
 * racing past them does not weaken the invariant.
 */
class BusinessMessagingIdentityResolver
{
    /**
     * The outbound path's only entry point: always re-resolved from the
     * tenancy-verified Business, never from caller-supplied input.
     */
    public function resolveForBusiness(Business $business): ?BusinessMessagingIdentity
    {
        $candidates = BusinessMessagingIdentity::query()
            ->where('business_id', (int) $business->id)
            ->where('status', BusinessMessagingIdentityStatus::Active->value)
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * One of §4.6's two independent inbound attribution signals. Never
     * sufficient on its own.
     */
    public function resolveByMessagingProfileId(?string $messagingProfileId): ?BusinessMessagingIdentity
    {
        if ($messagingProfileId === null || trim($messagingProfileId) === '') {
            return null;
        }

        $candidates = BusinessMessagingIdentity::query()
            ->where('messaging_profile_id', trim($messagingProfileId))
            ->where('status', BusinessMessagingIdentityStatus::Active->value)
            ->orderBy('id')
            ->limit(2)
            ->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * The other independent inbound signal — resolved by joining through
     * business_messaging_numbers, so a number's owning Business has exactly
     * one source of truth.
     */
    public function resolveByPhoneNumber(?string $phoneNumber): ?BusinessMessagingIdentity
    {
        $normalized = E164Normalizer::normalize($phoneNumber);

        if ($normalized === null) {
            return null;
        }

        $identityIds = BusinessMessagingNumber::query()
            ->where('phone_number', $normalized)
            ->where('status', BusinessMessagingNumberStatus::Active->value)
            ->orderBy('id')
            ->limit(2)
            ->pluck('business_messaging_identity_id')
            ->unique()
            ->all();

        if (count($identityIds) !== 1) {
            return null;
        }

        $identity = BusinessMessagingIdentity::query()
            ->whereKey($identityIds[0])
            ->where('status', BusinessMessagingIdentityStatus::Active->value)
            ->first();

        return $identity instanceof BusinessMessagingIdentity ? $identity : null;
    }

    /**
     * Outbound number selection, §4.5 step 3: the identity's single active
     * primary number.
     *
     * Zero or more than one fails closed with a conflict exception and zero
     * provider calls — never "pick whichever row sorts first."
     */
    public function resolvePrimaryNumber(BusinessMessagingIdentity $identity): BusinessMessagingNumber
    {
        $candidates = BusinessMessagingNumber::query()
            ->where('business_messaging_identity_id', (int) $identity->id)
            ->where('status', BusinessMessagingNumberStatus::Active->value)
            ->where('is_primary', true)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($candidates->count() !== 1) {
            throw new MessagingIdentityConflictException(sprintf(
                'Expected exactly one active primary number for messaging identity [%d]; found %d.',
                (int) $identity->id,
                $candidates->count(),
            ));
        }

        return $candidates->first();
    }

    /**
     * Create the one active-or-pending identity for a Business.
     *
     * @throws MessagingIdentityConflictException when one already exists, or
     *                                            when MySQL's own unique
     *                                            index rejects the insert
     */
    public function create(
        Business $business,
        string $messagingProfileId,
        ?string $messagingConnectionId = null,
        MessagingProvider $provider = MessagingProvider::Telnyx,
        BusinessMessagingIdentityStatus $status = BusinessMessagingIdentityStatus::Pending,
    ): BusinessMessagingIdentity {
        try {
            return DB::transaction(function () use ($business, $messagingProfileId, $messagingConnectionId, $provider, $status): BusinessMessagingIdentity {
                $existing = BusinessMessagingIdentity::query()
                    ->where('business_id', (int) $business->id)
                    ->whereIn('status', [
                        BusinessMessagingIdentityStatus::Pending->value,
                        BusinessMessagingIdentityStatus::Active->value,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($existing instanceof BusinessMessagingIdentity) {
                    throw new MessagingIdentityConflictException(sprintf(
                        'Business [%d] already has an active-or-pending messaging identity.',
                        (int) $business->id,
                    ));
                }

                $identity = new BusinessMessagingIdentity([
                    'uid' => (string) Str::uuid(),
                    'business_id' => (int) $business->id,
                    'provider' => $provider->value,
                    'status' => $status->value,
                    'messaging_profile_id' => $messagingProfileId,
                    'messaging_connection_id' => $messagingConnectionId,
                    'activated_at' => $status === BusinessMessagingIdentityStatus::Active ? Carbon::now() : null,
                ]);
                $identity->save();

                return $identity;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            throw new MessagingIdentityConflictException(
                'A conflicting messaging identity already exists for this Business or Messaging Profile.',
                0,
                $e,
            );
        }
    }

    /**
     * Attach one number to an identity, normalizing to canonical E.164
     * before any uniqueness check runs.
     *
     * @throws MessagingIdentityConflictException on an unusable number, or
     *                                            when MySQL rejects the row
     */
    public function attachNumber(
        BusinessMessagingIdentity $identity,
        string $phoneNumber,
        bool $isPrimary = false,
        BusinessMessagingNumberStatus $status = BusinessMessagingNumberStatus::Pending,
        ?string $providerNumberReference = null,
    ): BusinessMessagingNumber {
        $normalized = E164Normalizer::normalize($phoneNumber);

        if ($normalized === null) {
            throw new MessagingIdentityConflictException(
                'A managed messaging number must be an explicit, valid international number.',
            );
        }

        try {
            return DB::transaction(function () use ($identity, $normalized, $isPrimary, $status, $providerNumberReference): BusinessMessagingNumber {
                $claimed = BusinessMessagingNumber::query()
                    ->where('phone_number', $normalized)
                    ->whereIn('status', [
                        BusinessMessagingNumberStatus::Pending->value,
                        BusinessMessagingNumberStatus::Active->value,
                    ])
                    ->lockForUpdate()
                    ->first();

                if ($claimed instanceof BusinessMessagingNumber) {
                    throw new MessagingIdentityConflictException(sprintf(
                        'Number [%s] is already claimed by an active-or-pending mapping.',
                        $normalized,
                    ));
                }

                $number = new BusinessMessagingNumber([
                    'business_messaging_identity_id' => (int) $identity->id,
                    'phone_number' => $normalized,
                    'provider_number_reference' => $providerNumberReference,
                    'status' => $status->value,
                    'is_primary' => $isPrimary,
                    'activated_at' => $status === BusinessMessagingNumberStatus::Active ? Carbon::now() : null,
                ]);
                $number->save();

                return $number;
            });
        } catch (UniqueConstraintViolationException|QueryException $e) {
            throw new MessagingIdentityConflictException(
                'A conflicting messaging number mapping already exists.',
                0,
                $e,
            );
        }
    }
}
