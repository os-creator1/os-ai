<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessBillingReceipt;

interface BusinessBillingReceiptRepository extends BaseRepository
{
    public function findById(int $id): ?BusinessBillingReceipt;

    public function findByLedgerEntryId(int $ledgerEntryId): ?BusinessBillingReceipt;

    public function create(array $attributes): BusinessBillingReceipt;
}
