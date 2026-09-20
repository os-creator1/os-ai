<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Exceptions\Workspace\LocationAccessDeniedException;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLineItem;
use App\Models\BusinessLocation;
use App\Models\CatalogItem;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class DocumentsController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(private readonly DocumentManager $manager, private readonly EntitlementManager $entitlements, private readonly LocationAccessGuard $locations) {}

    public function listing(string $workspaceUid, string $businessUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $ids = $this->locations->accessibleLocationIdsForBusiness((int) Auth::id(), $business);
        return view('customer.business.documents.index', [
            'documents' => BusinessDocument::where('business_id', $business->id)->whereIn('business_location_id', $ids)->latest()->paginate(25),
            'workspaceUid' => $workspaceUid, 'businessUid' => $businessUid,
            'locations' => BusinessLocation::where('business_id', $business->id)->whereIn('id', $ids)->where('lifecycle_state', 'active')->get(),
            'contacts' => Contacts::where('business_id', $business->id)->whereIn('location_id', $ids)->get(),
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $data = $request->validate(['location_uid' => 'required|uuid', 'contact_uid' => 'required|uuid', 'opportunity_uid' => 'nullable|uuid', 'kind' => 'required|in:proposal,invoice', 'title' => 'required|string|max:200']);
        $location = BusinessLocation::where('business_id', $business->id)->where('uid', $data['location_uid'])->firstOrFail();
        $this->location($location);
        $contact = Contacts::where('uid', $data['contact_uid'])->firstOrFail();
        $opportunity = isset($data['opportunity_uid']) ? CrmOpportunity::where('uid', $data['opportunity_uid'])->firstOrFail() : null;
        $document = $this->manager->create($business, $location, $contact, $opportunity, $data['kind'], $data['title'], Auth::user());
        return redirect()->route('customer.workspaces.businesses.documents.show', [$workspaceUid, $businessUid, $document->uid]);
    }

    public function show(string $workspaceUid, string $businessUid, string $documentUid): View
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $version = $document->versions()->where('state', 'draft')->first();
        return view('customer.business.documents.show', [
            'document' => $document, 'version' => $version?->load(['lineItems', 'paymentScheduleItems']),
            'catalogItems' => CatalogItem::where('business_id', $document->business_id)->where('lifecycle_state', 'active')->orderBy('position')->get(),
            'workspaceUid' => $workspaceUid, 'businessUid' => $businessUid,
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $data = $request->validate(['title' => 'sometimes|required|string|max:200', 'content' => 'sometimes|array']);
        $this->manager->edit($document, $data);
        return back();
    }

    public function catalogLine(Request $request, string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $data = $request->validate(['catalog_item_uid' => 'required|uuid', 'quantity' => 'required|integer|min:1', 'explicit_price_minor' => 'nullable|integer|min:0']);
        $item = CatalogItem::where('business_id', $document->business_id)->where('uid', $data['catalog_item_uid'])->firstOrFail();
        $this->manager->addCatalogLine($document, $item, (int) $data['quantity'], Auth::user(), isset($data['explicit_price_minor']) ? (int) $data['explicit_price_minor'] : null);
        return back();
    }

    public function customLine(Request $request, string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $data = $request->validate(['name' => 'required|string|max:200', 'description' => 'nullable|string', 'quantity' => 'required|integer|min:1', 'unit_price_minor' => 'required|integer|min:0']);
        $this->manager->addCustomLine($document, $data['name'], $data['description'] ?? null, (int) $data['quantity'], (int) $data['unit_price_minor']);
        return back();
    }

    public function removeLine(string $workspaceUid, string $businessUid, string $documentUid, string $lineUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $line = BusinessDocumentLineItem::where('uid', $lineUid)->firstOrFail();
        $this->manager->removeLine($document, $line);
        return back();
    }

    public function reorder(Request $request, string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $data = $request->validate(['line_uids' => 'required|array', 'line_uids.*' => 'required|uuid']);
        $ids = BusinessDocumentLineItem::whereIn('uid', $data['line_uids'])->pluck('id', 'uid');
        abort_unless($ids->count() === count($data['line_uids']), 404);
        $this->manager->reorderLines($document, array_map(fn ($uid) => $ids[$uid], $data['line_uids']));
        return back();
    }

    public function schedule(Request $request, string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $data = $request->validate(['terms' => 'required|array|min:1|max:2', 'terms.*.kind' => 'required|in:full,deposit,balance', 'terms.*.amount_minor' => 'required|integer|min:0', 'terms.*.currency_code' => 'required|string|size:3', 'terms.*.due_at' => 'nullable|date']);
        $this->manager->setSchedule($document, array_map(fn ($term) => [...$term, 'amount_minor' => (int) $term['amount_minor']], $data['terms']));
        return back();
    }

    public function void(Request $request, string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $data = $request->validate(['reason' => 'required|string|max:255']);
        $this->manager->void($document, $data['reason']);
        return back();
    }

    private function document(string $workspaceUid, string $businessUid, string $documentUid): BusinessDocument
    {
        $business = $this->business($workspaceUid, $businessUid);
        $document = BusinessDocument::where('business_id', $business->id)->where('uid', $documentUid)->firstOrFail();
        $location = BusinessLocation::where('business_id', $business->id)->findOrFail($document->business_location_id);
        $this->location($location);
        return $document;
    }

    private function business(string $workspaceUid, string $businessUid): Business
    {
        [$workspace, $business] = $this->resolveBusinessTenancy($workspaceUid, $businessUid);
        $this->authorize('payments_contracts');
        abort_unless($this->entitlementAllows($workspace, $business), 404);
        return $business;
    }

    protected function entitlementAllows(\App\Models\Workspace $workspace, Business $business): bool
    {
        return $this->entitlements->decide($workspace, $business, PlatformFeature::PaymentsContracts->value, (int) Auth::id())->allowed;
    }

    private function location(BusinessLocation $location): void
    {
        try {
            $this->locations->assertUserCanAccessLocation((int) Auth::id(), $location);
        } catch (LocationAccessDeniedException) {
            abort(404);
        }
    }
}
