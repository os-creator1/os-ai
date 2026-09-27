<?php

namespace App\Repositories\Contracts;

use App\Enums\GoogleBusinessProfile\GoogleConnectionProduct;
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
 * SEO Contract 18 §7.3 — every finder also takes a GoogleConnectionProduct,
 * REQUIRED rather than defaulted. A Business can now hold up to one
 * connection row per product (§7.2), so "the connection for this Business"
 * is no longer a well-formed question on its own; a defaulted parameter
 * would still let a forgetful call site silently fall back to whichever
 * product happened to be the default, exactly the unscoped-lookup shape
 * this contract closes. Every existing GBP call site passes
 * GoogleConnectionProduct::BusinessProfile explicitly.
 *
 * Writes stay with GoogleBusinessProfileConnectionManager, which owns the
 * §10.1 state machine and is the only writer of refresh_token_encrypted.
 */
interface BusinessGoogleConnectionRepository
{
    public function findForBusiness(Business $business, GoogleConnectionProduct $product): ?BusinessGoogleConnection;

    public function findForBusinessId(int $businessId, GoogleConnectionProduct $product): ?BusinessGoogleConnection;
}
