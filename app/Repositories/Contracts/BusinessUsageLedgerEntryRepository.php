<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageLedgerEntry;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

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

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.4 — the admin
     * dashboard's own bounded, filterable, paginated ledger read.
     * $filters supports 'entry_type' (exact match) and 'from'/'to'
     * (inclusive created_at date-range bounds).
     */
    public function forBusinessPaginated(int $businessId, int $perPage, array $filters = []): LengthAwarePaginator;

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.3 — the manual-
     * credit idempotency lookup. A plain, unlocked read; the caller is
     * responsible for its own wallet-row lock.
     */
    public function findByCorrelationKey(string $correlationKey): ?BusinessUsageLedgerEntry;

    /**
     * RFC-005 Admin Usage Billing Surface Contract §2.5 — the provider-
     * cost/margin aggregate, one row per feature_key, computed entirely
     * in SQL using exact DECIMAL arithmetic. retail_revenue_micro and
     * provider_cost_micro are returned as exact integer-micro strings
     * (never PHP int/float); margin_micro is derived from them via
     * bcsub(), guaranteeing margin_micro === retail_revenue_micro -
     * provider_cost_micro for every row.
     */
    public function marginAggregateForBusiness(int $businessId, string $periodKey): Collection;

    /**
     * RFC-005 Remediation #6 §7/§13/§15 — the gross, provider-confirmed
     * refund progress for one funding attempt, computed entirely in SQL
     * as an exact decimal string (never PHP-collection-reduced). Sums
     * only Refund rows; unaffected by any dispute activity on the same
     * attempt.
     */
    public function sumRefundedMicroForFundingAttempt(int $fundingAttemptId): string;

    /**
     * RFC-005 Remediation #6 §8/§9/§15 — per-dispute bounding, independent
     * of the refund accumulator and of any other dispute on the same
     * attempt: sums gross_amount_micro for one funding attempt, one
     * specific dispute's own provider_reference, and one entry type
     * ('dispute_chargeback' for withdrawn, 'correction_reversal' for
     * reinstated).
     */
    public function sumDisputeMicroForFundingAttemptAndDispute(int $fundingAttemptId, string $providerDisputeId, string $entryType): string;

    /**
     * RFC-005 Remediation #6 §13/§20 — one bounded scalar query: true when
     * any dispute (grouped by its own provider_reference) has strictly
     * more withdrawn than reinstated for this funding attempt.
     */
    public function hasOutstandingDisputeExposureForFundingAttempt(int $fundingAttemptId): bool;

    /**
     * RFC-005 Remediation #6 §4/§15 — the original wallet-crediting entry
     * for a funding attempt, scoped exclusively to entry_type IN
     * ('paid_top_up', 'auto_recharge'). ManualCredit/PromotionalCredit are
     * never eligible — excluded by construction, never merely filtered
     * out after the fact.
     */
    public function findCreditEntryForFundingAttempt(int $fundingAttemptId): ?BusinessUsageLedgerEntry;
}
