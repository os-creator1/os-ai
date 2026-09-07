<?php

namespace App\Repositories\Contracts;

use App\Models\Business;
use App\Models\BusinessGoogleConnection;

/**
 * GBP Slice A contract §19.3 — the READ seam for Google connections.
 *
 * Every finder takes a Business. There is deliberately NO
 * findByUid($uid) WITHOUT a Business anywhere in this interface: an
 * unscoped lookup is exactly the shape a cross-tenant IDOR needs, and the
 * contract forbids it (§15.3).
 *
 * Writes stay with GoogleBusinessProfileConnectionManager, which owns the
 * §10.1 state machine and is the only writer of refresh_token_encrypted.
 */
interface BusinessGoogleConnectionRepository
{
    public function findForBusiness(Business $business): ?BusinessGoogleConnection;

    public function findForBusinessId(int $businessId): ?BusinessGoogleConnection;
}
