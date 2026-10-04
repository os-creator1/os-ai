<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Documents\DocumentDraftConflictException;
use App\Exceptions\Documents\DocumentTemplateRefusedException;
use App\Exceptions\Documents\InvalidDocumentBlocksException;
use App\Library\Documents\Templates\DocumentTemplateService;
use App\Exceptions\Documents\InvalidDocumentPaymentPlanException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessDocuments;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Catalog\Exceptions\CatalogRuleException;
use App\Library\Contacts\ContactDirectory;
use App\Library\Documents\Blocks\BlockSchema;
use App\Library\Documents\Blocks\DocumentBlockRenderer;
use App\Library\Documents\Blocks\DocumentMergeFields;
use App\Enums\Documents\DocumentKind;
use App\Enums\Documents\DocumentVersionState;
use App\Library\Documents\Editor\DocumentEditorToolbox;
use App\Library\Documents\Editor\DocumentCatalogPicker;
use App\Library\Documents\Editor\DocumentContactDates;
use App\Library\Documents\Delivery\DocumentLinkSmsSender;
use App\Library\Documents\Editor\DocumentEditorService;
use App\Library\Documents\Editor\DocumentEditorState;
use App\Library\Documents\Editor\DocumentLegacyUpgrader;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\Business;
use App\Models\BusinessDocument;
use App\Models\CatalogItem;
use App\Models\Contacts;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Implementation Contract 17B — the visual editor's page and JSON API. A thin
 * adapter: every authorization decision is DocumentsController's own chain
 * (ResolvesBusinessDocuments), every write is a DocumentManager draft mutation
 * reached through DocumentEditorService, and every response is built here
 * explicitly so the real status code reaches the browser:
 *
 *   200 {status: 'ok', lock_version, ...}
 *   409 {status: 'conflict', lock_version}                stale expected_lock_version
 *   422 {status: 'invalid', errors: {field: [message]}}   validation / refused state
 *   404 {status: 'error'}                                  foreign or unknown uid
 *
 * Every mutating document endpoint requires `expected_lock_version`.
 */
class DocumentEditorController extends CustomerBaseController
{
    use ResolvesBusinessDocuments {
        document as private resolveBusinessDocument;
    }

    /**
     * Contract 17B §1 — only a proposal (a "contract" is a proposal too) is edited in
     * this builder. An invoice resolves like any foreign / unknown uid: 404.
     */
    private function document(string $workspaceUid, string $businessUid, string $documentUid): BusinessDocument
    {
        $document = $this->resolveBusinessDocument($workspaceUid, $businessUid, $documentUid);
        abort_unless($document->kind === DocumentKind::Proposal, 404);

        return $document;
    }

    public function __construct(
        private readonly DocumentEditorService $editor,
        private readonly DocumentEditorState $state,
        private readonly DocumentCatalogPicker $catalog,
        private readonly DocumentContactDates $dates,
        private readonly DocumentLegacyUpgrader $upgrader,
        private readonly DocumentTemplateService $templates,
        private readonly ContactDirectory $contacts,
        private readonly EntitlementManager $entitlements,
        private readonly LocationAccessGuard $locations,
    ) {}

    public function edit(string $workspaceUid, string $businessUid, string $documentUid): View
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $base = [$workspaceUid, $businessUid];
        $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.documents.' . $name, [...$base, ...$extra]);

        $business = Business::findOrFail($document->business_id);
        $contact = $document->contact_id === null ? null : Contacts::query()->whereKey($document->contact_id)->where('business_id', $business->id)->first();
        $contactName = $contact === null ? '' : trim((string) $contact->getFullName(''));

        return view('customer.business.documents.editor', [
            'document' => $document,
            'toolbox' => DocumentEditorToolbox::categories(),
            'icons' => DocumentEditorToolbox::icons(),
            'bootstrap' => $this->state->bootstrap($document) + [
                'mode' => 'document',
                'contact' => ['name' => $contactName !== '' ? $contactName : (string) $document->recipient_name_snapshot, 'email' => (string) $document->recipient_email_snapshot],
                'business' => ['name' => (string) $business->name],
                'images' => DocumentEditorToolbox::images($business),
                'toolbox' => [
                    'categories' => DocumentEditorToolbox::categories(),
                    'block_types' => BlockSchema::TYPES,
                    'merge_fields' => DocumentMergeFields::catalogFor($business),
                    'limits' => ['max_blocks' => BlockSchema::MAX_BLOCKS, 'max_bytes' => BlockSchema::MAX_BYTES, 'max_run_text' => BlockSchema::MAX_RUN_TEXT],
                    'plan' => ['structures' => ['full', 'deposit'], 'full_due' => ['on_signing', 'date'], 'balance_due' => ['after_deposit', 'date']],
                ],
                'urls' => [
                    'show' => $route('show', [$documentUid]),
                    'index' => $route('index'),
                    'edit' => $route('editor.edit', [$documentUid]),
                    'preview' => $route('editor.preview', [$documentUid]),
                    'blocks' => $route('editor.blocks', [$documentUid]),
                    'plan' => $route('editor.plan', [$documentUid]),
                    'lines_catalog' => $route('editor.lines.catalog', [$documentUid]),
                    'lines_custom' => $route('editor.lines.custom', [$documentUid]),
                    'lines_order' => $route('editor.lines.order', [$documentUid]),
                    'line_template' => $route('editor.lines.quantity', [$documentUid, '__LINE__']),
                    'catalog_search' => $route('editor.catalog.search', [$documentUid]),
                    'catalog_store' => $route('editor.catalog.store', [$documentUid]),
                    'contact_dates' => $route('editor.contact.dates', [$documentUid]),
                    'upgrade' => $route('editor.upgrade', [$documentUid]),
                    'send' => $route('editor.send', [$documentUid]),
                    'contacts_search' => $route('editor.contacts.search'),
                    'save_template' => $route('editor.save-template', [$documentUid]),
                    'template_library' => route('customer.workspaces.businesses.document-templates.index', $base),
                ],
            ],
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
        ]);
    }

    /**
     * The CURRENT saved draft rendered by the one renderer (mode preview) in a
     * standalone print-width page: merge fields resolve live from the document's
     * Contact and Business, lines and schedule are the canonical rows. An issued
     * version renders from its frozen parties instead, exactly as the recipient
     * saw it. A legacy / empty (non-block) document has nothing to preview here,
     * so it goes to the classic page.
     */
    public function preview(string $workspaceUid, string $businessUid, string $documentUid): View|RedirectResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $version = $this->state->version($document);

        if ($version === null || ! BlockSchema::hasBlocks($version->content)) {
            return redirect()->route('customer.workspaces.businesses.documents.show', [$workspaceUid, $businessUid, $documentUid]);
        }

        $business = Business::findOrFail($document->business_id);
        $isDraft = $version->state === DocumentVersionState::Draft;
        $merge = $isDraft
            ? DocumentMergeFields::forDocument($document)
            : DocumentMergeFields::fromFrozenParties(is_array($version->content['parties'] ?? null) ? $version->content['parties'] : []);

        return view('customer.business.documents.editor-preview', [
            'document' => $document,
            'business' => $business,
            'isDraft' => $isDraft,
            'blocksHtml' => app(DocumentBlockRenderer::class)->renderVersion($version, 'preview', $merge, [
                'business_id' => (int) $document->business_id,
                'timezone' => (string) ($business->timezone ?: config('app.timezone', 'UTC')),
            ]),
        ]);
    }

    /** Autosave target: blocks (+ title). */
    public function blocks(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document) {
            $data = $this->validated($request, [
                'blocks' => 'present|array',
                'title' => 'sometimes|required|string|max:200',
                'expected_lock_version' => 'required|integer|min:1',
            ]);

            return $this->editor->saveBlocks($document, $data['blocks'], $data['title'] ?? null, (int) $data['expected_lock_version']);
        });
    }

    public function plan(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document) {
            $data = $this->validated($request, [
                'structure' => 'required|string|max:16',
                'deposit' => 'nullable|string|max:40',
                'full_due' => 'nullable|string|max:16',
                'full_due_date' => 'nullable|string|max:10',
                'balance_due' => 'nullable|string|max:16',
                'balance_due_date' => 'nullable|string|max:10',
                'expected_lock_version' => 'required|integer|min:1',
            ]);

            return $this->editor->applyPlan($document, $data, (int) $data['expected_lock_version']);
        });
    }

    public function catalogLine(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document) {
            $data = $this->validated($request, [
                'catalog_item_uid' => 'required|string|max:64',
                'quantity' => 'nullable|integer|min:1|max:4294967295',
                // Only for a quote-only item, whose price the document has to state.
                'price' => 'nullable|string|max:40',
                'expected_lock_version' => 'required|integer|min:1',
            ]);
            // Looked up INSIDE this document's Business: a foreign item is a 404.
            $item = CatalogItem::where('business_id', $document->business_id)->where('uid', $data['catalog_item_uid'])->first() ?? abort(404);

            return $this->editor->addCatalogLine($document, $item, (int) ($data['quantity'] ?? 1), $data['price'] ?? null, Auth::user(), (int) $data['expected_lock_version']);
        });
    }

    public function customLine(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document) {
            $data = $this->validated($request, [
                'name' => 'required|string|max:200',
                'description' => 'nullable|string|max:2000',
                'quantity' => 'nullable|integer|min:1|max:4294967295',
                'price' => 'required|string|max:40',
                'expected_lock_version' => 'required|integer|min:1',
            ]);

            return $this->editor->addCustomLine($document, $data['name'], $data['description'] ?? null, (int) ($data['quantity'] ?? 1), $data['price'], (int) $data['expected_lock_version']);
        });
    }

    public function quantity(Request $request, string $workspaceUid, string $businessUid, string $documentUid, string $lineUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document, $lineUid) {
            $data = $this->validated($request, [
                'quantity' => 'required|integer|min:1|max:4294967295',
                'expected_lock_version' => 'required|integer|min:1',
            ]);

            return $this->editor->updateQuantity($document, $lineUid, (int) $data['quantity'], (int) $data['expected_lock_version']);
        });
    }

    public function destroyLine(Request $request, string $workspaceUid, string $businessUid, string $documentUid, string $lineUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document, $lineUid) {
            $data = $this->validated($request, ['expected_lock_version' => 'required|integer|min:1']);

            return $this->editor->removeLine($document, $lineUid, (int) $data['expected_lock_version']);
        });
    }

    public function order(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document) {
            $data = $this->validated($request, [
                'line_uids' => 'required|array',
                'line_uids.*' => 'required|uuid',
                'expected_lock_version' => 'required|integer|min:1',
            ]);

            return $this->editor->reorderLines($document, array_values($data['line_uids']), (int) $data['expected_lock_version']);
        });
    }

    public function catalogSearch(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $business = Business::findOrFail($document->business_id);

        return $this->respond(fn () => [
            'items' => $this->catalog->search($business, $document, mb_substr(trim((string) $request->query('q', '')), 0, 100)),
        ]);
    }

    /**
     * Create a product / package from inside the editor so it can be selected
     * straight away. It is a catalog write, so it also needs the catalog's own
     * `packages_products` capability and entitlement — the documents capability
     * alone never creates catalog rows.
     */
    public function catalogStore(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);
        $business = Business::findOrFail($document->business_id);
        $this->authorize('packages_products');
        abort_unless($this->entitlements->decide($business->workspace, $business, PlatformFeature::PackagesProducts->value, (int) Auth::id())->allowed, 404);

        return $this->respond(function () use ($request, $document, $business) {
            $data = $this->validated($request, [
                'type' => 'required|string|in:product,package',
                'name' => 'required|string|max:1000',
                'description' => 'nullable|string|max:20000',
                'price' => 'nullable|string|max:40',
            ]);

            return ['item' => $this->catalog->create($business, $document, $data, (int) Auth::id())];
        });
    }

    public function contactDates(string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(fn () => ['dates' => $this->dates->candidates($document)]);
    }

    public function upgrade(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document) {
            $data = $this->validated($request, ['expected_lock_version' => 'required|integer|min:1']);

            return $this->upgrader->upgrade($document, (int) $data['expected_lock_version']);
        });
    }

    /**
     * Contract 17B §7 — the send dialog. ONE Draft->Sent transition delivered over
     * the chosen channels (see DocumentEditorService::send). Per-channel results
     * come back in `delivery`; a channel that could not be attempted never blocks
     * the send or the other channel.
     *
     *   channels[]               required, >= 1 of email|sms
     *   message                  optional text-message wording (the link is always appended)
     *   recipient_email/_phone   only used while the document's snapshot is still empty
     *   expected_lock_version    required
     */
    public function send(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document) {
            $data = $this->validated($request, [
                'channels' => 'required|array|min:1',
                'channels.*' => 'required|string|in:email,sms',
                'message' => 'nullable|string|max:' . DocumentLinkSmsSender::MAX_CUSTOM_MESSAGE,
                'recipient_email' => 'nullable|email|max:255',
                'recipient_phone' => 'nullable|string|max:32',
                'expected_lock_version' => 'required|integer|min:1',
            ]);

            return $this->editor->send($document, array_values($data['channels']), $data['message'] ?? null, $data['recipient_email'] ?? null, $data['recipient_phone'] ?? null, (int) $data['expected_lock_version']);
        });
    }


    /**
     * Contract 17B §6 — "Save as template": the layout of this document (its open
     * draft blocks, or the issued version's for a sent / signed one) becomes an
     * ACTIVE Business template. The document is never written and no Contact,
     * product, price or payment plan is copied (DocumentTemplateService).
     */
    public function saveTemplate(Request $request, string $workspaceUid, string $businessUid, string $documentUid): JsonResponse
    {
        $document = $this->document($workspaceUid, $businessUid, $documentUid);

        return $this->respond(function () use ($request, $document, $workspaceUid, $businessUid) {
            $data = $this->validated($request, [
                'name' => 'required|string|max:' . DocumentTemplateService::NAME_MAX,
                'template_type' => 'required|string|in:proposal,contract',
                'description' => 'nullable|string|max:' . DocumentTemplateService::DESCRIPTION_MAX,
            ]);

            $template = $this->templates->saveFromDocument($document, $data['name'], $data['template_type'], $data['description'] ?? null, Auth::user());

            return [
                'template' => ['uid' => (string) $template->uid, 'name' => (string) $template->name, 'type' => $template->template_type->value],
                'dropped_images' => $this->templates->lastDroppedImages(),
                'library_url' => route('customer.workspaces.businesses.document-templates.index', [$workspaceUid, $businessUid]),
                'edit_url' => route('customer.workspaces.businesses.document-templates.edit', [$workspaceUid, $businessUid, $template->uid]),
            ];
        });
    }
    /**
     * The new-document flow's contact picker: this Business's contacts in the
     * Locations the actor can reach, behind the documents capability rather than
     * the CRM's.
     */
    public function contactSearch(Request $request, string $workspaceUid, string $businessUid): JsonResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $search = mb_substr(trim((string) $request->query('q', '')), 0, 100);
        $reach = $this->locations->accessibleLocationIdsForBusiness((int) Auth::id(), $business);

        $found = $this->contacts->search($business, $search, 20);
        $allowed = Contacts::query()
            ->where('business_id', $business->id)
            ->whereIn('uid', array_column($found, 'uid'))
            ->whereIn('location_id', $reach)
            ->pluck('uid')->all();

        return response()->json([
            'status' => 'ok',
            'results' => array_values(array_map(fn (array $contact) => [
                'uid' => $contact['uid'],
                'text' => $contact['name'] !== null ? $contact['name'] . ' · ' . $contact['phone'] : $contact['phone'],
            ], array_filter($found, fn (array $contact) => in_array($contact['uid'], $allowed, true)))),
        ]);
    }

    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        return Validator::make($request->all(), $rules)->validate();
    }

    /**
     * Run one endpoint body and turn every expected failure into the explicit
     * JSON response for its real status.
     */
    private function respond(Closure $action): JsonResponse
    {
        try {
            return response()->json(['status' => 'ok'] + $action());
        } catch (DocumentDraftConflictException $e) {
            return response()->json(['status' => 'conflict', 'message' => $e->getMessage(), 'lock_version' => $e->currentLockVersion], 409);
        } catch (InvalidDocumentBlocksException $e) {
            return $this->invalid($e->getMessage(), array_map(fn ($message) => [$message], $e->errors()));
        } catch (InvalidDocumentPaymentPlanException $e) {
            return $this->invalid($e->getMessage(), array_map(fn ($message) => [$message], $e->errors()));
        } catch (DocumentTemplateRefusedException $e) {
            return $this->invalid($e->getMessage(), ['template' => [$e->getMessage()]]);
        } catch (CatalogRuleException $e) {
            return $this->invalid($e->getMessage(), ['catalog' => [$e->getMessage()]]);
        } catch (ValidationException $e) {
            return $this->invalid($e->validator->errors()->first() ?: $e->getMessage(), $e->errors());
        } catch (ModelNotFoundException) {
            return response()->json(['status' => 'error', 'message' => 'Not found.'], 404);
        } catch (HttpExceptionInterface $e) {
            return response()->json(['status' => 'error', 'message' => $e->getStatusCode() === 404 ? 'Not found.' : 'Request refused.'], $e->getStatusCode());
        }
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function invalid(string $message, array $errors): JsonResponse
    {
        return response()->json(['status' => 'invalid', 'message' => $message, 'errors' => $errors], 422);
    }
}
