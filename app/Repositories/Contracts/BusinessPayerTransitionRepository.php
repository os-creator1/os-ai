<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessPayerTransition;
use Illuminate\Support\Collection;

interface BusinessPayerTransitionRepository extends BaseRepository
{
    public function create(array $attributes): BusinessPayerTransition;

    /**
     * @return Collection<int, BusinessPayerTransition>
     */
    public function forBusiness(int $businessId): Collection;
}
