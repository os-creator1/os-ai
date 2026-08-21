<?php

namespace App\Repositories\Contracts;

use App\Models\AdditionalBusinessSlotAgreement;
use Illuminate\Support\Collection;

interface AdditionalBusinessSlotAgreementRepository extends BaseRepository
{
    public function findById(int $id): ?AdditionalBusinessSlotAgreement;

    public function findForUpdateById(int $id): ?AdditionalBusinessSlotAgreement;

    public function findByLocalIdempotencyKey(string $key): ?AdditionalBusinessSlotAgreement;

    public function findByProviderReference(string $reference): ?AdditionalBusinessSlotAgreement;

    /**
     * M4 contract §11 — InitiateSlotAgreementRenewal's own due-agreement
     * query: next_renewal_at <= now(), not pending cancellation, not
     * lapsed, in the completed state.
     *
     * @return Collection<int, AdditionalBusinessSlotAgreement>
     */
    public function findDueForRenewal(): Collection;

    /**
     * M4 contract §12 — FinalizeSlotAgreementCancellation's own due-
     * agreement query: pending cancellation whose effective instant has
     * passed, not already canceled.
     *
     * @return Collection<int, AdditionalBusinessSlotAgreement>
     */
    public function findDueForCancellationFinalization(): Collection;

    /**
     * M4 contract §22 (Correction Round 2 §A) —
     * ReconcileSlotAgreementAllocation's own recovery-discovery query:
     * every agreement whose downstream allocation is provider-verified but
     * incomplete — payment_succeeded/allocation_pending past the given
     * bounded threshold (never racing an in-flight synchronous return/
     * webhook confirmation), plus allocation_failed unconditionally (only
     * ever reached after performVerifiedAllocation()'s own exception phase
     * has already committed and returned control, so no synchronous call
     * is ever still in flight for that state).
     *
     * @return Collection<int, AdditionalBusinessSlotAgreement>
     */
    public function findRequiringAllocationRecovery(int $thresholdMinutes): Collection;

    public function create(array $attributes): AdditionalBusinessSlotAgreement;

    public function update(AdditionalBusinessSlotAgreement $agreement, array $attributes): AdditionalBusinessSlotAgreement;
}
