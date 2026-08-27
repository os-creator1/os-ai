<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageLedgerEntry;

/**
 * Append-only — no update()/delete() method exists on this contract.
 */
interface BusinessUsageLedgerEntryRepository extends BaseRepository
{
    public function create(array $attributes): BusinessUsageLedgerEntry;

    /**
     * Receipt Boundary Correction Contract §9 — a plain, unlocked read,
     * used by SendReceiptNotification's own pre-check.
     */
    public function findById(int $id): ?BusinessUsageLedgerEntry;

    /**
     * Receipt Boundary Correction Contract §5/§9 — locks the row via
     * SELECT ... FOR UPDATE, mirroring
     * BusinessUsageWalletRepository::findForUpdateByBusinessId()'s own
     * convention. The sole idempotency mechanism for
     * UsageWalletManager::attachFundingReceipt() — no new UNIQUE
     * constraint is introduced.
     */
    public function findForUpdateById(int $id): ?BusinessUsageLedgerEntry;

    /**
     * RFC-005 Reservation Admission Correction Contract §4.B/§9 — the
     * current-period committed amount for one Business+feature_key,
     * reusing RFC-005 §13's own committed-amount formula: a UsageCharge
     * entry's committed amount is -reserved_delta_micro; a
     * UsageOverageCharge entry's committed amount is
     * (-available_delta_micro) + debt_delta_micro. Scoped by feature_key,
     * never meter_key, so committed usage aggregates across every
     * meter_key sharing that feature_key (Amendment 1). No other entry
     * type contributes, matching §13 exactly.
     */
    public function sumCommittedAmountForFeature(int $businessId, string $featureKey, string $periodKey): int;
}
