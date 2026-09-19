<?php

namespace App\Http\Controllers\Customer\Business\Concerns;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Workspace;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 16 §6 — the Packages & Products authorization chain,
 * in the ONE place that enforces its order, shared by both catalog controllers
 * so the order cannot drift between them.
 *
 *   1. TENANCY      — the canonical Workspace/Business chain
 *                     (`resolveBusinessTenancy`). A foreign or unknown
 *                     Business is refused HERE, before capability or
 *                     entitlement is even asked, so holding the capability
 *                     tells an actor nothing about a Business they cannot
 *                     reach. -> 404
 *   2. CAPABILITY   — `packages_products` (config/customer-permissions.php).
 *                     Reaching the Business without it is refused regardless
 *                     of tenancy. -> 401 (see below)
 *   3. ENTITLEMENT  — `EntitlementManager` for
 *                     `PlatformFeature::PackagesProducts`, through the
 *                     existing `resolveEntitledBusinessTenancy` seam. While
 *                     the registry entry is `Planned` this ALWAYS refuses,
 *                     which is the whole mechanism that keeps the catalog
 *                     non-customer-executable until the final flip. -> 404
 *   4. LOCATION ACL — Location-scoped actions only: the Location must belong
 *                     to THIS Business (the FK-domain-integrity check, ahead of
 *                     any ACL concern), then `LocationAccessGuard` decides,
 *                     fresh on every request. -> 404
 *
 * NONE SUBSTITUTES FOR ANOTHER. Each is checked explicitly and independently;
 * a request that satisfies three still fails on the fourth.
 *
 * ONE RESPONSE FOR "NOT YOURS" AND "NOT THERE". A foreign Business, a Location
 * of another Business, a Location the actor's grants do not cover, and a
 * catalog item of another Business all answer the same 404 a nonexistent id
 * would. The domain managers deliberately leave existence-disclosure policy to
 * this HTTP layer (`CatalogItemManager::lockItemForBusiness()` docblock), and
 * this is that policy: an actor must never learn that a record they cannot
 * reach exists.
 *
 * WHAT THIS DOES NOT DO. It authorizes; it never applies a catalog rule. Every
 * validation, price rule and lifecycle change stays in the domain managers.
 */
trait AuthorizesCatalogRequests
{
    use ResolvesBusinessTenancy;

    /** The single capability key (§6). One key, not a CRUD matrix. */
    protected const CATALOG_CAPABILITY = 'packages_products';

    /**
     * Gates 1-3, in the contracted order.
     *
     * @return array{0: Workspace, 1: Business}
     */
    protected function catalogScope(string $workspaceUid, string $businessUid): array
    {
        // Gate 1 — tenancy. Aborts 404 before anything below is consulted.
        $this->resolveBusinessTenancy($workspaceUid, $businessUid);

        // Gate 2 — the customer capability. `authorize()` throws
        // AuthorizationException, which this application renders as 401
        // (errors.401) — the same refusal every other capability-gated customer
        // controller (CRM, Google Business Profile) already gives.
        $this->authorize(self::CATALOG_CAPABILITY);

        // Gate 3 — platform entitlement. This re-runs the (request-memoized,
        // idempotent) tenancy chain as part of the existing seam; that is
        // defence in depth, not a substitute for gate 1 above, which has
        // already refused anyone without tenancy.
        return $this->resolveEntitledBusinessTenancy(
            $workspaceUid,
            $businessUid,
            PlatformFeature::PackagesProducts->value
        );
    }

    /**
     * Gates 1-4, for a Location-scoped action or read.
     *
     * @return array{0: Workspace, 1: Business, 2: BusinessLocation}
     */
    protected function catalogLocationScope(string $workspaceUid, string $businessUid, string $locationUid): array
    {
        [$workspace, $business] = $this->catalogScope($workspaceUid, $businessUid);

        // Gate 4a — the FK-domain-integrity check. Scoped to THIS Business, so
        // a Location of another Business is simply not found, exactly as a
        // nonexistent uid is.
        $location = app(BusinessLocationRepository::class)->findForBusinessByUid($business, $locationUid);

        if ($location === null) {
            abort(404);
        }

        // Gate 4b — LocationAccessGuard, asked fresh every time (Addendum §4:
        // "knowing or binding a record ID must never bypass Location
        // authorization"). A boolean plus abort(404) rather than
        // assertUserCanAccessLocation(): the latter throws
        // LocationAccessDeniedException, which would be a distinguishable
        // answer for a Location the actor cannot reach.
        if (! app(LocationAccessGuard::class)->userCanAccessLocation((int) Auth::id(), $location)) {
            abort(404);
        }

        return [$workspace, $business, $location];
    }

    /**
     * A catalog item of THIS Business by its uid, or 404. A foreign Business's
     * item is not found by a Business-scoped lookup, so it is indistinguishable
     * from a nonexistent one. Read only — every write goes through
     * CatalogItemManager, which re-verifies ownership itself.
     */
    protected function catalogItemOrAbort(Business $business, string $catalogItemUid): CatalogItem
    {
        return CatalogItem::query()
            ->where('business_id', $business->id)
            ->where('uid', $catalogItemUid)
            ->first() ?? abort(404);
    }
}
