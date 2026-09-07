<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use Illuminate\Support\Collection;

/**
 * GBP Slice A contract §19.3 / §27 — the READ seam for the audit ledger.
 *
 * Writes stay with GoogleBusinessProfileOperationLedger, which is the ONE
 * writer of business_google_operations.
 */
interface BusinessGoogleOperationRepository
{
    /**
     * The most recent operations for a Business, newest first — the
     * §25.8 customer-visible change log.
     *
     * @return Collection<int, \App\Models\BusinessGoogleOperation>
     */
    public function recentForBusiness(Business $business, int $limit = 20): Collection;
}
