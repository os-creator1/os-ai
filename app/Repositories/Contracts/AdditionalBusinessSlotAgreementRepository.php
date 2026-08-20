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
     * M4 contract §22 — ReconcileSlotAgreementAllocation's own stuck-
     * allocation query: agreements left in allocation_pending past the
     * given bounded threshold.
     *
     * @return Collection<int, AdditionalBusinessSlotAgreement>
     */
    public function findStuckInAllocationPending(int $thresholdMinutes): Collection;

    public function create(array $attributes): AdditionalBusinessSlotAgreement;

    public function update(AdditionalBusinessSlotAgreement $agreement, array $attributes): AdditionalBusinessSlotAgreement;
}
