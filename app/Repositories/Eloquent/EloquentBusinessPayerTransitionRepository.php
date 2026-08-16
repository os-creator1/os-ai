<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessPayerTransition;
use App\Repositories\Contracts\BusinessPayerTransitionRepository;
use Illuminate\Support\Collection;

class EloquentBusinessPayerTransitionRepository extends EloquentBaseRepository implements BusinessPayerTransitionRepository
{
    public function __construct(BusinessPayerTransition $transition)
    {
        parent::__construct($transition);
    }

    public function create(array $attributes): BusinessPayerTransition
    {
        /** @var BusinessPayerTransition $transition */
        $transition = $this->make($attributes);
        $transition->save();

        return $transition;
    }

    public function forBusiness(int $businessId): Collection
    {
        return $this->query()->where('business_id', $businessId)->orderBy('id')->get();
    }
}
