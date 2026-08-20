<?php

namespace App\Repositories\Eloquent;

use App\Models\AdditionalBusinessSlotRenewalChargeTransition;
use App\Repositories\Contracts\AdditionalBusinessSlotRenewalChargeTransitionRepository;
use Illuminate\Support\Collection;

class EloquentAdditionalBusinessSlotRenewalChargeTransitionRepository extends EloquentBaseRepository implements AdditionalBusinessSlotRenewalChargeTransitionRepository
{
    public function __construct(AdditionalBusinessSlotRenewalChargeTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): AdditionalBusinessSlotRenewalChargeTransition
    {
        /** @var AdditionalBusinessSlotRenewalChargeTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }

    public function forRenewalCharge(int $renewalChargeId): Collection
    {
        return $this->query()
            ->where('renewal_charge_id', $renewalChargeId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function countFailedForCharge(int $renewalChargeId): int
    {
        return $this->query()
            ->where('renewal_charge_id', $renewalChargeId)
            ->where('to_state', 'failed')
            ->count();
    }
}
