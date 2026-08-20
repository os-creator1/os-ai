<?php

namespace App\Repositories\Eloquent;

use App\Models\AdditionalBusinessSlotAgreementTransition;
use App\Repositories\Contracts\AdditionalBusinessSlotAgreementTransitionRepository;
use Illuminate\Support\Collection;

class EloquentAdditionalBusinessSlotAgreementTransitionRepository extends EloquentBaseRepository implements AdditionalBusinessSlotAgreementTransitionRepository
{
    public function __construct(AdditionalBusinessSlotAgreementTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): AdditionalBusinessSlotAgreementTransition
    {
        /** @var AdditionalBusinessSlotAgreementTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }

    public function forAgreement(int $agreementId): Collection
    {
        return $this->query()
            ->where('agreement_id', $agreementId)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }
}
