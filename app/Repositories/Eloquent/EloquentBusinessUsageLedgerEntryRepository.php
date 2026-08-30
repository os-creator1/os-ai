<?php

namespace App\Repositories\Eloquent;

use App\Enums\Usage\UsageLedgerEntryType;
use App\Models\BusinessUsageLedgerEntry;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class EloquentBusinessUsageLedgerEntryRepository extends EloquentBaseRepository implements BusinessUsageLedgerEntryRepository
{
    public function __construct(BusinessUsageLedgerEntry $entry)
    {
        parent::__construct($entry);
    }

    public function create(array $attributes): BusinessUsageLedgerEntry
    {
        /** @var BusinessUsageLedgerEntry $entry */
        $entry = $this->make($attributes);
        $entry->save();

        return $entry;
    }

    public function findById(int $id): ?BusinessUsageLedgerEntry
    {
        return $this->query()->find($id);
    }

    public function findForUpdateById(int $id): ?BusinessUsageLedgerEntry
    {
        return $this->query()->where('id', $id)->lockForUpdate()->first();
    }

    public function sumCommittedAmountForFeature(int $businessId, string $featureKey, string $periodKey): int
    {
        $usageChargeReservedDelta = (int) $this->query()
            ->where('business_id', $businessId)
            ->where('feature_key', $featureKey)
            ->where('period_key', $periodKey)
            ->where('entry_type', 'usage_charge')
            ->sum('reserved_delta_micro');

        $overageQuery = fn () => $this->query()
            ->where('business_id', $businessId)
            ->where('feature_key', $featureKey)
            ->where('period_key', $periodKey)
            ->where('entry_type', 'usage_overage_charge');

        $overageAvailableDelta = (int) $overageQuery()->sum('available_delta_micro');
        $overageDebtDelta = (int) $overageQuery()->sum('debt_delta_micro');

        return (-$usageChargeReservedDelta) + ((-$overageAvailableDelta) + $overageDebtDelta);
    }

    /**
     * `$filters['from']`/`$filters['to']` are expected to already be
     * normalized, plain `created_at`-comparable timestamp strings (the
     * controller's own `normalizeDateBoundary()` produces these) — an
     * inclusive start-of-day `from` and an *exclusive* start-of-the-
     * next-day `to`, so the entire named final calendar day is covered
     * without a `whereDate()` wrapper defeating the `created_at` index.
     */
    public function forBusinessPaginated(int $businessId, int $perPage, array $filters = []): LengthAwarePaginator
    {
        $query = $this->query()
            ->where('business_id', $businessId)
            ->orderByDesc('id');

        if (! empty($filters['entry_type'])) {
            $query->where('entry_type', $filters['entry_type']);
        }

        if (! empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (! empty($filters['to'])) {
            $query->where('created_at', '<', $filters['to']);
        }

        return $query->paginate($perPage);
    }

    public function findByCorrelationKey(string $correlationKey): ?BusinessUsageLedgerEntry
    {
        return $this->query()->where('correlation_key', $correlationKey)->first();
    }

    /**
     * Deliberately DB::table(), never $this->query() (an Eloquent
     * Builder): BusinessUsageLedgerEntry::$casts casts provider_cost_micro
     * to PHP int, and Eloquent applies that cast on every attribute read
     * — hydrating this aggregate through the model would silently
     * re-truncate the exact string this method exists to preserve, the
     * identical failure mode this correction closes. A plain query
     * builder returns raw, uncast PDO string values for every DECIMAL
     * result column.
     */
    public function marginAggregateForBusiness(int $businessId, string $periodKey): Collection
    {
        return DB::table('business_usage_ledger_entries')
            ->selectRaw('feature_key')
            ->selectRaw('SUM(gross_amount_micro) AS retail_revenue_micro')
            ->selectRaw('ROUND(SUM(CAST(provider_cost_micro AS DECIMAL(20,0)) * quantity), 0) AS provider_cost_micro')
            ->where('business_id', $businessId)
            ->where('period_key', $periodKey)
            ->whereIn('entry_type', [UsageLedgerEntryType::UsageCharge->value, UsageLedgerEntryType::UsageOverageCharge->value])
            ->whereNotNull('provider_cost_micro')
            ->groupBy('feature_key')
            ->get()
            ->map(function ($row) {
                $row->retail_revenue_micro = (string) $row->retail_revenue_micro;
                $row->provider_cost_micro = (string) $row->provider_cost_micro;
                $row->margin_micro = bcsub($row->retail_revenue_micro, $row->provider_cost_micro, 0);

                return $row;
            });
    }

    public function sumRefundedMicroForFundingAttempt(int $fundingAttemptId): string
    {
        return (string) DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $fundingAttemptId)
            ->where('entry_type', UsageLedgerEntryType::Refund->value)
            ->selectRaw('COALESCE(SUM(gross_amount_micro), 0) AS total')
            ->value('total');
    }

    public function sumDisputeMicroForFundingAttemptAndDispute(int $fundingAttemptId, string $providerDisputeId, string $entryType): string
    {
        return (string) DB::table('business_usage_ledger_entries')
            ->where('funding_attempt_id', $fundingAttemptId)
            ->where('provider_reference', $providerDisputeId)
            ->where('entry_type', $entryType)
            ->selectRaw('COALESCE(SUM(gross_amount_micro), 0) AS total')
            ->value('total');
    }

    public function hasOutstandingDisputeExposureForFundingAttempt(int $fundingAttemptId): bool
    {
        return DB::table('business_usage_ledger_entries')
            ->select('provider_reference')
            ->where('funding_attempt_id', $fundingAttemptId)
            ->whereIn('entry_type', [UsageLedgerEntryType::DisputeChargeback->value, UsageLedgerEntryType::CorrectionReversal->value])
            ->whereNotNull('provider_reference')
            ->groupBy('provider_reference')
            ->havingRaw(
                "SUM(CASE WHEN entry_type = ? THEN gross_amount_micro ELSE 0 END) > ".
                "SUM(CASE WHEN entry_type = ? THEN gross_amount_micro ELSE 0 END)",
                [UsageLedgerEntryType::DisputeChargeback->value, UsageLedgerEntryType::CorrectionReversal->value],
            )
            ->limit(1)->get()->isNotEmpty();
    }

    public function findCreditEntryForFundingAttempt(int $fundingAttemptId): ?BusinessUsageLedgerEntry
    {
        return $this->query()
            ->where('funding_attempt_id', $fundingAttemptId)
            ->whereIn('entry_type', [UsageLedgerEntryType::PaidTopUp->value, UsageLedgerEntryType::AutoRecharge->value])
            ->first();
    }
}
