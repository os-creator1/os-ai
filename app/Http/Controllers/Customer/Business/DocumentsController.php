<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Documents\DocumentManager;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Payments\PaymentManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Exceptions\Payments\RefundException;
use App\Exceptions\Payments\StripeConnectException;
use App\Exceptions\Workspace\LocationAccessDeniedException;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\BusinessDocumentLineItem;
use App\Models\BusinessDocumentPayment;
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

    public function __construct(private readonly DocumentManager $manager, private readonly EntitlementManager $entitlements, private readonly LocationAccessGuard $locations, private readonly PaymentManager $payments) {}

    public function listing(string $workspaceUid, string $businessUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $ids = $this->locations->accessibleLocationIdsForBusiness((int) Auth::id(), $business);
        return view('customer.business.documents.index', [
            'documents' => BusinessDocument::where('business_id', $business->id)->whereIn('business_location_id', $ids)->latest()->paginate(25),
            'workspaceUid' => $workspaceUid, 'businessUid' => $businessUid,
            'locations' => BusinessLocation::where('business_id', $business->id)->whereIn('id', $ids)->where('lifecycle_state', 'active')->get(),
            'contacts' => Contacts::where('business_id', $business->id)->whereIn('location_id', $ids)->get(),
            'opportunities' => CrmOpportunity::where('business_id', $business->id)->whereIn('location_id', $ids)->get(),
        ]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $data = $request->validate(['location_uid' => 'required|string|max:64', 'contact_uid' => 'required|string|max:64', 'opportunity_uid' => 'nullable|string|max:64', 'kind' => 'required|in:proposal,invoice', 'title' => 'required|string|max:200']);
        $location = BusinessLocation::where('business_id', $business->id)->where('uid', $data['location_uid'])->first() ?? abort(404);
        $this->location($location);
        $contact = Contacts::where('uid', $data['contact_uid'])->first() ?? abort(404);
        $opportunity = isset($data['opportunity_uid']) ? (CrmOpportunity::where('uid', $data['opportunity_uid'])->first() ?? abort(404)) : null;
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
        $data = $request->validate([
            'title' => 'sometimes|required|string|max:200',
            'content' => 'sometimes|array',
            // §5.2 — the delivery identity. Prefillable while the document has
            // never been sent, frozen by the manager from the first send on.
            'recipient_name_snapshot' => 'sometimes|nullable|string|max:191',
            'recipient_email_snapshot' => 'sometimes|nullable|email|max:255',
            'recipient_phone_snapshot' => 'sometimes|nullable|string|max:32',
        ]);
        $this->manager->edit($document, $data);
        return back();
    }

    /**
     * §7.1 SEND — freezes the draft version, mints the secure link and, only
     * after commit, emails it to the frozen recipient snapshot.
     */
    public function send(string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $this->manager->send($this->document($workspaceUid, $businessUid, $documentUid));
        return back();
    }

    /**
     * §7.1 REVISE — opens version N+1 on an already-sent document, copying
     * the issued version's lines and schedule commercial terms. The existing
     * link keeps working until the new version is sent.
     */
    public function revise(string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $this->manager->revise($this->document($workspaceUid, $businessUid, $documentUid), Auth::user());
        return back();
    }

    public function catalogLine(Request $request, string $workspaceUid, string $businessUid, string $documentUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $data = $request->validate(['catalog_item_uid' => 'required|string|max:64', 'quantity' => 'required|integer|min:1', 'explicit_price_minor' => 'nullable|integer|min:0']);
        $item = CatalogItem::where('business_id', $document->business_id)->where('uid', $data['catalog_item_uid'])->first() ?? abort(404);
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
        $line = BusinessDocumentLineItem::where('uid', $lineUid)->first() ?? abort(404);
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

    /**
     * Sub-slice F §7.4/§6.1 — issue a refund against one captured payment.
     *
     * THE SAME GATE CHAIN AS EVERY OTHER ACTION HERE, and deliberately no
     * more: Workspace -> Business -> access -> Active -> `payments_contracts`
     * -> the PaymentsContracts entitlement -> LocationAccessGuard. §6.1 gives
     * refunds the SAME single capability as the rest of the document surface
     * plus an explicit confirmation — so there is no new permission key, no
     * new matrix entry, and no owner-only rule (that is §6.2's, and it belongs
     * to connecting and disconnecting Stripe, not to refunding).
     *
     * THE CONFIRMATION IS THE EXTRA STEP. `confirm` must be explicitly
     * accepted, so a refund can never be the result of a stray POST or a
     * re-submitted form.
     *
     * THE BROWSER NEVER NAMES A PROVIDER OBJECT. The payment is addressed by
     * OUR uid and re-scoped to this document, and the connected account is
     * read from the payment's own historical connection inside the manager
     * (§5.7). No Stripe identifier is accepted in any form.
     */
    public function refund(Request $request, string $workspaceUid, string $businessUid, string $documentUid, string $paymentUid): RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $data = $request->validate(['confirm' => 'required|accepted', 'amount_minor' => 'required|integer|min:1', 'reason' => 'nullable|string|max:255']);
        $payment = BusinessDocumentPayment::where('business_document_id', $document->id)->where('uid', $paymentUid)->first() ?? abort(404);
        try {
            $this->payments->requestRefund($payment, (int) $data['amount_minor'], $data['reason'] ?? null, (int) Auth::id());
        } catch (RefundException $e) {
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        } catch (StripeConnectException $e) {
            // The provider's own text never reaches the page (§11.8).
            return back()->with(['status' => 'error', 'message' => $e->customerMessage()]);
        }
        return back()->with(['status' => 'success', 'message' => 'Refund requested.']);
    }

    private function document(string $workspaceUid, string $businessUid, string $documentUid): BusinessDocument
    {
        $business = $this->business($workspaceUid, $businessUid);
        $document = BusinessDocument::where('business_id', $business->id)->where('uid', $documentUid)->first() ?? abort(404);
        $location = BusinessLocation::where('business_id', $business->id)->find($document->business_location_id) ?? abort(404);
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
