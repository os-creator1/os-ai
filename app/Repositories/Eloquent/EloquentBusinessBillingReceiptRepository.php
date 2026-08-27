<?php

namespace App\Repositories\Eloquent;

use App\Models\BusinessBillingReceipt;
use App\Repositories\Contracts\BusinessBillingReceiptRepository;

class EloquentBusinessBillingReceiptRepository extends EloquentBaseRepository implements BusinessBillingReceiptRepository
{
    public function __construct(BusinessBillingReceipt $receipt)
    {
        parent::__construct($receipt);
    }

    public function findById(int $id): ?BusinessBillingReceipt
    {
        return $this->query()->find($id);
    }

    public function findByLedgerEntryId(int $ledgerEntryId): ?BusinessBillingReceipt
    {
        return $this->query()->where('ledger_entry_id', $ledgerEntryId)->first();
    }

    public function create(array $attributes): BusinessBillingReceipt
    {
        /** @var BusinessBillingReceipt $receipt */
        $receipt = $this->make($attributes);
        $receipt->save();

        return $receipt;
    }
}
