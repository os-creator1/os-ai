<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessUsageLedgerEntry;
use App\Repositories\Contracts\BusinessUsageLedgerEntryRepository;

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
}
