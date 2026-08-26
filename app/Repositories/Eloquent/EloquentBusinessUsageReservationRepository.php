<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageReservation;
use App\Repositories\Contracts\BusinessUsageReservationRepository;
use Illuminate\Support\Collection;

class EloquentBusinessUsageReservationRepository extends EloquentBaseRepository implements BusinessUsageReservationRepository
{
    public function __construct(BusinessUsageReservation $reservation)
    {
        parent::__construct($reservation);
    }

    public function findById(int $id): ?BusinessUsageReservation
    {
        return $this->query()->find($id);
    }

    public function findForUpdateById(int $id): ?BusinessUsageReservation
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    public function findByIdempotencyKey(string $idempotencyKey): ?BusinessUsageReservation
    {
        return $this->query()->where('idempotency_key', $idempotencyKey)->first();
    }

    public function findExpiredPending(int $limit): Collection
    {
        return $this->query()
            ->where('status', 'pending')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    public function sumPendingReservedAmountForFeature(int $businessId, string $featureKey, string $periodKey): int
    {
        return (int) $this->query()
            ->where('business_id', $businessId)
            ->where('feature_key', $featureKey)
            ->where('period_key', $periodKey)
            ->where('status', 'pending')
            ->sum('reserved_amount_micro');
    }

    public function create(array $attributes): BusinessUsageReservation
    {
        /** @var BusinessUsageReservation $reservation */
        $reservation = $this->make($attributes);
        $reservation->save();

        return $reservation;
    }

    public function update(BusinessUsageReservation $reservation, array $attributes): BusinessUsageReservation
    {
        $reservation->fill($attributes);
        $reservation->save();

        return $reservation;
    }
}
