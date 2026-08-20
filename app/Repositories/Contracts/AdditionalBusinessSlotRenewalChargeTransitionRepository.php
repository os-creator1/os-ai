<?php

namespace App\Repositories\Contracts;

use App\Models\AdditionalBusinessSlotRenewalChargeTransition;
use Illuminate\Support\Collection;

/**
 * Append-only (M4 contract §20) — deliberately no update() method.
 * countFailedForCharge() is the sole durable source the attempt-ordinal
 * algorithm (M4 contract §23) reads: current ordinal = 1 + this count.
 */
interface AdditionalBusinessSlotRenewalChargeTransitionRepository extends BaseRepository
{
    public function create(array $attributes): AdditionalBusinessSlotRenewalChargeTransition;

    /**
     * @return Collection<int, AdditionalBusinessSlotRenewalChargeTransition>
     */
    public function forRenewalCharge(int $renewalChargeId): Collection;

    /**
     * M4 contract §23 — the exact attempt-ordinal source of truth: count
     * of already-recorded to_state = 'failed' rows for this exact charge.
     */
    public function countFailedForCharge(int $renewalChargeId): int;
}
