<?php

namespace App\Repositories\Contracts;

use App\Models\AdditionalBusinessSlotRenewalCharge;

interface AdditionalBusinessSlotRenewalChargeRepository extends BaseRepository
{
    public function findById(int $id): ?AdditionalBusinessSlotRenewalCharge;

    public function findForUpdateById(int $id): ?AdditionalBusinessSlotRenewalCharge;

    public function findByLocalIdempotencyKey(string $key): ?AdditionalBusinessSlotRenewalCharge;

    public function findByProviderReference(string $reference): ?AdditionalBusinessSlotRenewalCharge;

    /**
     * M4 contract §11 — the repeated-mid-period-increase idempotency
     * lookup: a genuine retry of the same increase carries the identical
     * change_operation_id.
     */
    public function findByChangeOperationId(string $changeOperationId): ?AdditionalBusinessSlotRenewalCharge;

    /**
     * M4 contract §22 (Correction Round 2 §E.4) — the actual previous
     * scheduled_renewal charge for this agreement, excluding the charge
     * just created, ordered most-recent-first. Used to compare a newly
     * created scheduled renewal's amount against the real prior period,
     * never the agreement's own frozen checkout amount (which only applies
     * to the genuinely first-ever scheduled renewal).
     */
    public function findPreviousScheduledRenewalCharge(int $agreementId, int $excludingChargeId): ?AdditionalBusinessSlotRenewalCharge;

    public function create(array $attributes): AdditionalBusinessSlotRenewalCharge;

    public function update(AdditionalBusinessSlotRenewalCharge $charge, array $attributes): AdditionalBusinessSlotRenewalCharge;
}
