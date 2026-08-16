<?php

namespace App\Repositories\Contracts;

use App\Models\BusinessUsageLedgerEntry;

/**
 * Append-only — no update()/delete() method exists on this contract.
 */
interface BusinessUsageLedgerEntryRepository extends BaseRepository
{
    public function create(array $attributes): BusinessUsageLedgerEntry;
}
