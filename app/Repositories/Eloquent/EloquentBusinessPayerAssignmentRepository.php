<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessPayerAssignment;
use App\Repositories\Contracts\BusinessPayerAssignmentRepository;

class EloquentBusinessPayerAssignmentRepository extends EloquentBaseRepository implements BusinessPayerAssignmentRepository
{
    public function __construct(BusinessPayerAssignment $assignment)
    {
        parent::__construct($assignment);
    }

    public function findByBusinessId(int $businessId): ?BusinessPayerAssignment
    {
        return $this->query()->where('business_id', $businessId)->first();
    }

    public function findForUpdateByBusinessId(int $businessId): ?BusinessPayerAssignment
    {
        return $this->query()->where('business_id', $businessId)->lockForUpdate()->first();
    }

    public function create(array $attributes): BusinessPayerAssignment
    {
        /** @var BusinessPayerAssignment $assignment */
        $assignment = $this->make($attributes);
        $assignment->save();

        return $assignment;
    }

    public function update(BusinessPayerAssignment $assignment, array $attributes): BusinessPayerAssignment
    {
        $assignment->fill($attributes);
        $assignment->save();

        return $assignment;
    }
}
