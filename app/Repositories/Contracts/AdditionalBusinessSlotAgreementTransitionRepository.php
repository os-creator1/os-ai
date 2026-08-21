<?php

namespace App\Repositories\Contracts;

use App\Models\AdditionalBusinessSlotAgreementTransition;
use Illuminate\Support\Collection;

/**
 * Append-only (M4 contract §20) — deliberately no update() method,
 * mirroring RFC-004's own entitlement-transition-repository precedent.
 */
interface AdditionalBusinessSlotAgreementTransitionRepository extends BaseRepository
{
    public function create(array $attributes): AdditionalBusinessSlotAgreementTransition;

    /**
     * @return Collection<int, AdditionalBusinessSlotAgreementTransition>
     */
    public function forAgreement(int $agreementId): Collection;
}
