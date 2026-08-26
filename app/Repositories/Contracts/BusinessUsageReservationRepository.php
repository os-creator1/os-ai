<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageReservation;
use Illuminate\Support\Collection;

interface BusinessUsageReservationRepository extends BaseRepository
{
    public function findById(int $id): ?BusinessUsageReservation;

    /**
     * Row-locking variant of findById(), for commit()/release() (RFC-005
     * §13).
     */
    public function findForUpdateById(int $id): ?BusinessUsageReservation;

    public function findByIdempotencyKey(string $idempotencyKey): ?BusinessUsageReservation;

    /**
     * Bounded page of pending reservations past their own expires_at, for
     * ExpireStaleUsageReservations.
     */
    public function findExpiredPending(int $limit): Collection;

    /**
     * RFC-005 Reservation Admission Correction Contract §4.B/§9 — the sum
     * of reserved_amount_micro across every still-pending reservation for
     * one Business+feature_key+period_key. Scoped by feature_key, never
     * meter_key, so consumption aggregates across every meter_key sharing
     * that feature_key (Amendment 1).
     */
    public function sumPendingReservedAmountForFeature(int $businessId, string $featureKey, string $periodKey): int;

    public function create(array $attributes): BusinessUsageReservation;

    public function update(BusinessUsageReservation $reservation, array $attributes): BusinessUsageReservation;
}
