<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\AuthorizesCatalogRequests;
use App\Library\Catalog\CatalogItemLocationOverrideManager;
use App\Library\Catalog\CatalogLocationOfferReader;
use App\Library\Catalog\CatalogMoney;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Library\Workspace\LocationAccessGuard;
use App\Repositories\Contracts\BusinessLocationRepository;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 16 §5.2, §6, §12.E — per-Location control of the
 * Business-wide catalog: whether an item is offered at a Location, an optional
 * Location price override, and the effective price a customer would see there.
 *
 * A THIN HTTP BOUNDARY over `CatalogItemLocationOverrideManager` (every write)
 * and `CatalogItemPricingResolver` (every displayed price, reached through
 * `CatalogLocationOfferReader`). No override rule, sparse-default rule or
 * price rule is reproduced here, and this class never writes
 * `catalog_item_location_overrides` itself.
 *
 * ALL FOUR GATES on every Location-scoped action, in order and independently
 * (AuthorizesCatalogRequests): tenancy, the `packages_products` capability, the
 * entitlement decision, then the Location — first that it belongs to THIS
 * Business, then `LocationAccessGuard`, asked fresh on every request.
 *
 * LOCATIONS THE ACTOR CANNOT REACH DO NOT EXIST HERE. The Location list is
 * built from `LocationAccessGuard::accessibleLocationIdsForBusiness()` — the
 * same decision the per-Location check runs — so an unreachable Location is
 * not listed, is not counted, and answers 404 exactly like a nonexistent uid
 * when guessed. No second Location authority is invented.
 */
class CatalogLocationOffersController extends Controller
{
    use AuthorizesCatalogRequests;

    public function __construct(
        private readonly CatalogItemLocationOverrideManager $overrides,
        private readonly CatalogLocationOfferReader $offers,
        private readonly LocationAccessGuard $guard,
        private readonly BusinessLocationRepository $locations,
    ) {
    }

    public function index(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->catalogScope($workspaceUid, $businessUid);

        $accessible = array_flip($this->guard->accessibleLocationIdsForBusiness((int) Auth::id(), $business));

        // Only what the actor can reach. Nothing below ever sees, counts or
        // names a Location outside this filtered set.
        $locations = $this->locations->forBusiness($business)
            ->filter(fn ($location) => isset($accessible[(int) $location->id]))
            ->values();

        return view('customer.business.catalog.locations', [
            'workspace' => $workspace,
            'business' => $business,
            'locations' => $locations,
        ]);
    }

    public function show(string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->catalogLocationScope($workspaceUid, $businessUid, $locationUid);

        return view('customer.business.catalog.location', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'rows' => $this->offers->rowsFor($business, $location),
        ]);
    }

    public function setEnabled(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $catalogItemUid): RedirectResponse
    {
        [, $business, $location] = $this->catalogLocationScope($workspaceUid, $businessUid, $locationUid);
        $item = $this->catalogItemOrAbort($business, $catalogItemUid);

        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);

        try {
            $this->overrides->setEnabled($business, $item, $location, (bool) $data['is_enabled']);
        } catch (CatalogRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToLocation($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', $data['is_enabled'] ? '"' . $item->name . '" is offered here.' : '"' . $item->name . '" is no longer offered here.');
    }

    /**
     * A blank price CLEARS the override, so the item falls back to its
     * Business-wide price. Whether an override is permitted at all (the item
     * must have a Business-wide price and currency) is the manager's rule.
     */
    public function setPrice(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $catalogItemUid): RedirectResponse
    {
        [, $business, $location] = $this->catalogLocationScope($workspaceUid, $businessUid, $locationUid);
        $item = $this->catalogItemOrAbort($business, $catalogItemUid);

        $data = $request->validate(['price' => ['nullable', 'string', 'max:40']]);

        try {
            // There is no currency override (§5.2): the override amount is
            // always in the item's own currency, so that is what the typed
            // figure is scaled by.
            $priceMinor = CatalogMoney::toMinor($data['price'] ?? null, $item->currency_code);

            $this->overrides->setPriceOverride($business, $item, $location, $priceMinor);
        } catch (CatalogRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToLocation($workspaceUid, $businessUid, $locationUid)
            ->with('flash_success', $priceMinor === null ? 'Price override removed.' : 'Price saved for this location.');
    }

    private function refused(CatalogRuleException $exception): RedirectResponse
    {
        return back()->withInput()->withErrors(['catalog' => $exception->getMessage()]);
    }

    private function backToLocation(string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.catalog.locations.show', [$workspaceUid, $businessUid, $locationUid]);
    }
}
