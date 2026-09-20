<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Catalog\CatalogItemType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\AuthorizesCatalogRequests;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogMoney;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\CatalogItem;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Implementation Contract 16 §6, §12.E — the Business-wide Packages & Products
 * catalog: list, create, edit, archive/reactivate and reorder.
 *
 * A THIN HTTP BOUNDARY. Every action runs the §6 gate chain
 * (AuthorizesCatalogRequests: tenancy -> capability -> entitlement) BEFORE it
 * validates or reads anything, and then hands straight to
 * `CatalogItemManager`. This class applies NO catalog rule of its own: name
 * length, the price/currency co-nullability, the archive/reactivate lifecycle
 * and the reorder completeness check all live in the manager, and a refusal
 * (`CatalogRuleException`) is shown to the person as the manager worded it.
 * It never writes `catalog_items` itself — `CatalogItemManager` is the only
 * writer — and a source-boundary test holds it to that.
 *
 * The only translation done here is presentational: the price a person types
 * ("49.99") becomes the whole minor-unit integer the manager requires, via
 * `CatalogMoney`, which validates nothing the manager owns.
 *
 * WHILE `PlatformFeature::PackagesProducts` IS `Planned` EVERY ACTION HERE
 * FAILS AT THE ENTITLEMENT GATE (404) — including for an owner — which is the
 * intended pre-flip state; the flip is what makes this surface reachable.
 */
class CatalogItemsController extends Controller
{
    use AuthorizesCatalogRequests;

    public function __construct(private readonly CatalogItemManager $items)
    {
    }

    public function index(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->catalogScope($workspaceUid, $businessUid);

        $all = CatalogItem::query()
            ->where('business_id', $business->id)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        return view('customer.business.catalog.index', [
            'workspace' => $workspace,
            'business' => $business,
            'activeItems' => $all->filter(fn (CatalogItem $item) => $item->isActive())->values(),
            'archivedItems' => $all->filter(fn (CatalogItem $item) => $item->isArchived())->values(),
        ]);
    }

    public function create(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->catalogScope($workspaceUid, $businessUid);

        return view('customer.business.catalog.create', [
            'workspace' => $workspace,
            'business' => $business,
            'types' => CatalogItemType::cases(),
            'defaultCurrency' => $business->currency_code,
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->catalogScope($workspaceUid, $businessUid);

        $data = $this->validated($request);

        try {
            $item = $this->items->create($business, $this->attributes($data), (int) Auth::id());
        } catch (CatalogRuleException $exception) {
            return $this->refused($exception);
        }

        return redirect()
            ->route('customer.workspaces.businesses.catalog.edit', [$workspaceUid, $businessUid, $item->uid])
            ->with('flash_success', '"' . $item->name . '" added to your catalog.');
    }

    public function edit(string $workspaceUid, string $businessUid, string $catalogItemUid): View
    {
        [$workspace, $business] = $this->catalogScope($workspaceUid, $businessUid);
        $item = $this->catalogItemOrAbort($business, $catalogItemUid);

        return view('customer.business.catalog.edit', [
            'workspace' => $workspace,
            'business' => $business,
            'item' => $item,
            'types' => CatalogItemType::cases(),
            'priceInput' => CatalogMoney::toInput($item->price_minor, $item->currency_code),
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $catalogItemUid): RedirectResponse
    {
        [, $business] = $this->catalogScope($workspaceUid, $businessUid);
        $item = $this->catalogItemOrAbort($business, $catalogItemUid);

        $data = $this->validated($request);

        try {
            $updated = $this->items->update($business, $item, $this->attributes($data));
        } catch (CatalogRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToEdit($workspaceUid, $businessUid, $updated->uid)
            ->with('flash_success', 'Saved.');
    }

    public function archive(string $workspaceUid, string $businessUid, string $catalogItemUid): RedirectResponse
    {
        [, $business] = $this->catalogScope($workspaceUid, $businessUid);
        $item = $this->catalogItemOrAbort($business, $catalogItemUid);

        try {
            $this->items->archive($business, $item);
        } catch (CatalogRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToIndex($workspaceUid, $businessUid)
            ->with('flash_success', '"' . $item->name . '" archived.');
    }

    public function reactivate(string $workspaceUid, string $businessUid, string $catalogItemUid): RedirectResponse
    {
        [, $business] = $this->catalogScope($workspaceUid, $businessUid);
        $item = $this->catalogItemOrAbort($business, $catalogItemUid);

        try {
            $this->items->reactivate($business, $item);
        } catch (CatalogRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToIndex($workspaceUid, $businessUid)
            ->with('flash_success', '"' . $item->name . '" is active again.');
    }

    /**
     * The complete ordered list of the Business's ACTIVE item uids, exactly as
     * the person wants it. Completeness, duplicates and foreign ids are ALL the
     * manager's to refuse (`CatalogItemManager::reorder()`); this passes the
     * list straight through and adds no ordering logic of its own — which also
     * means a stale order (an item added or archived in another tab) is
     * refused rather than half-applied.
     */
    public function reorder(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->catalogScope($workspaceUid, $businessUid);

        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['required', 'string'],
        ]);

        try {
            $this->items->reorder($business, array_values($data['order']));
        } catch (CatalogRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToIndex($workspaceUid, $businessUid)->with('flash_success', 'Order saved.');
    }

    /**
     * Shape validation only — that the form sent strings of a sane size. The
     * RULES (name 1-160, description 5000, the price/currency pair) are the
     * manager's, so the limits here are deliberately loose enough never to
     * pre-empt or contradict them.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'string', Rule::in(array_column(CatalogItemType::cases(), 'value'))],
            'name' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:20000'],
            'price' => ['nullable', 'string', 'max:40'],
            'currency_code' => ['nullable', 'string', 'max:8'],
        ]);
    }

    /**
     * The manager's own attribute names. Note there is no `lifecycle_state`,
     * `archived_at`, `position` or `business_id` here: none of them can be
     * supplied by a form, and the manager would discard them anyway.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws CatalogRuleException when the typed price cannot be converted
     */
    private function attributes(array $data): array
    {
        return [
            'type' => $data['type'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'price_minor' => CatalogMoney::toMinor($data['price'] ?? null, $data['currency_code'] ?? null),
            'currency_code' => $data['currency_code'] ?? null,
        ];
    }

    private function refused(CatalogRuleException $exception): RedirectResponse
    {
        return back()->withInput()->withErrors(['catalog' => $exception->getMessage()]);
    }

    private function backToEdit(string $workspaceUid, string $businessUid, string $catalogItemUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.catalog.edit', [$workspaceUid, $businessUid, $catalogItemUid]);
    }

    private function backToIndex(string $workspaceUid, string $businessUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.catalog.index', [$workspaceUid, $businessUid]);
    }
}
