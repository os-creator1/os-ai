<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Catalog\CatalogItemType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\AuthorizesCatalogRequests;
use App\Library\Catalog\CatalogItemManager;
use App\Library\Catalog\CatalogMoney;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Models\CatalogItem;
use App\Models\Currency;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // The "Add to catalog" dialog is rendered by the list page itself.
            'types' => CatalogItemType::cases(),
            'defaultCurrency' => $business->currency_code,
            'currencies' => Currency::query()->where('status', true)->orderBy('name')->get(['code', 'name', 'format']),
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
            'currencies' => Currency::query()->where('status', true)->orderBy('name')->get(['code', 'name']),
        ]);
    }

    /**
     * The same action serves the full-page form (redirects, the non-JavaScript
     * fallback) and the catalog's "Add to catalog" dialog, which asks for JSON
     * and gets a 201 with where to reload, or a 422 with field errors worded
     * exactly as the manager or the request validator worded them.
     */
    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse|JsonResponse
    {
        [, $business] = $this->catalogScope($workspaceUid, $businessUid);

        try {
            $data = $this->validated($request);
        } catch (ValidationException $exception) {
            if (! $request->expectsJson()) {
                throw $exception;
            }

            // The application's global JSON renderer reduces a validation
            // failure to one message; the dialog needs the field it belongs to.
            return response()->json([
                'message' => collect($exception->errors())->flatten()->first(),
                'errors' => $exception->errors(),
            ], 422);
        }

        try {
            $item = $this->items->create($business, $this->attributes($data), (int) Auth::id());
        } catch (CatalogRuleException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => ['catalog' => [$exception->getMessage()]],
                ], 422);
            }

            return $this->refused($exception);
        }

        $added = '"' . $item->name . '" added to your catalog.';

        if ($request->expectsJson()) {
            // Survives the reload the dialog performs, so the list shows the
            // new item together with the confirmation.
            $request->session()->flash('flash_success', $added);

            return response()->json([
                'message' => $added,
                'uid' => $item->uid,
                'redirect' => route('customer.workspaces.businesses.catalog.index', [$workspaceUid, $businessUid]),
            ], 201);
        }

        return redirect()
            ->route('customer.workspaces.businesses.catalog.edit', [$workspaceUid, $businessUid, $item->uid])
            ->with('flash_success', $added);
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
            'currencies' => Currency::query()->where('status', true)->orderBy('name')->get(['code', 'name']),
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
        $data = $request->validate([
            'type' => ['required', 'string', Rule::in(array_column(CatalogItemType::cases(), 'value'))],
            'name' => ['required', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:20000'],
            'price' => ['nullable', 'string', 'max:40'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            // Only the "Add to catalog" dialog sends this: which of its two
            // price modes the person chose. Absent for the classic forms.
            'pricing' => ['nullable', 'string', Rule::in(['fixed', 'quote'])],
        ]);

        // "Fixed price" with no figure typed must never quietly become a
        // quote-only item: that would save something the person did not ask
        // for. (The manager cannot see this — by then the mode is gone.)
        if (($data['pricing'] ?? null) === 'fixed' && trim((string) ($data['price'] ?? '')) === '') {
            throw ValidationException::withMessages(['price' => 'Enter a price, or choose Quote only.']);
        }

        return $data;
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
        // "Quote only" is no price at all — whatever else was posted.
        if (($data['pricing'] ?? null) === 'quote') {
            $data['price'] = null;
            $data['currency_code'] = null;
        }

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
