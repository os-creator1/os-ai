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
}
