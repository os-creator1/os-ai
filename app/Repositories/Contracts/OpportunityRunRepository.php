<?php

namespace App\Repositories\Contracts;

use App\Enums\Opportunity\OpportunityWorkerKey;
use App\Models\OpportunityRun;

interface OpportunityRunRepository extends BaseRepository
{
    public function findForUpdate(int $id): ?OpportunityRun;

    public function findRunningForUpdate(int $businessId, OpportunityWorkerKey $workerKey): ?OpportunityRun;

    public function create(array $attributes): OpportunityRun;

    public function update(OpportunityRun $run, array $attributes): OpportunityRun;
}
