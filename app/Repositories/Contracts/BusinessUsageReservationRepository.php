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

    public function create(array $attributes): BusinessUsageReservation;

    public function update(BusinessUsageReservation $reservation, array $attributes): BusinessUsageReservation;
}
