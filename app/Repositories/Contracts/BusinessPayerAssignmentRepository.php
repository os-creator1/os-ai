<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessPayerAssignment;

interface BusinessPayerAssignmentRepository extends BaseRepository
{
    public function findByBusinessId(int $businessId): ?BusinessPayerAssignment;

    public function findForUpdateByBusinessId(int $businessId): ?BusinessPayerAssignment;

    public function create(array $attributes): BusinessPayerAssignment;

    public function update(BusinessPayerAssignment $assignment, array $attributes): BusinessPayerAssignment;
}
