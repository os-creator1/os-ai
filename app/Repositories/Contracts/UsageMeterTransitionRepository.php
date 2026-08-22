<?php

namespace App\Repositories\Contracts;

use App\Models\UsageMeterTransition;

/**
 * Append-only — no update()/delete() method exists on this contract.
 */
interface UsageMeterTransitionRepository extends BaseRepository
{
    public function create(array $attributes): UsageMeterTransition;
}
