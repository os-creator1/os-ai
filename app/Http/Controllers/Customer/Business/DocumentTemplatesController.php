<?php

namespace App\Http\Controllers\Customer\Business;

use App\Exceptions\Documents\DocumentTemplateConflictException;
use App\Exceptions\Documents\DocumentTemplateRefusedException;
use App\Exceptions\Documents\InvalidDocumentBlocksException;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessDocuments;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Documents\Blocks\DocumentBlockRenderer;
use App\Library\Documents\Blocks\DocumentMergeFields;
use App\Library\Documents\Editor\DocumentEditorToolbox;
use App\Library\Documents\Templates\DocumentTemplateEditorState;
use App\Library\Documents\Templates\DocumentTemplateService;
use App\Library\Entitlement\EntitlementManager;
use App\Library\Workspace\LocationAccessGuard;
use App\Models\DocumentTemplate;
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
 * Implementation Contract 17B §6 — the Business's template library and the
 * template editor's page + JSON endpoint. A thin adapter: the gate chain is the
 * documents one (ResolvesBusinessDocuments::business() — Workspace -> Business
 * -> `payments_contracts` capability -> the PaymentsContracts entitlement), the
 * authority over WHICH template is DocumentTemplateAccess (own templates only;
 * foreign / forged / unrecommended-platform uids are all the same 404), and
 * every write is a DocumentTemplateService call.
 *
 * Templates are Business-wide (they hold no Contact), so no Location check
 * applies here; the Location check runs when a template is USED on a document
 * (DocumentsController::store).
 *
 * `blocks` answers like the document editor's endpoints:
 *   200 {status:'ok', lock_version, ...}  409 {status:'conflict', lock_version}
 *   422 {status:'invalid', errors}        404 {status:'error'}
 */
class DocumentTemplatesController extends CustomerBaseController
{
    use ResolvesBusinessDocuments;

    public function __construct(
        private readonly DocumentTemplateService $templates,
        private readonly DocumentTemplateEditorState $state,
        private readonly EntitlementManager $entitlements,
        private readonly LocationAccessGuard $locations,
    ) {}

    public function library(Request $request, string $workspaceUid, string $businessUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $all = $this->templates->listFor($business, true);
        $active = $all->filter(fn (DocumentTemplate $t) => $t->status->value === 'active')->values();
        $archived = $all->filter(fn (DocumentTemplate $t) => $t->status->value === 'archived')->values();

        return view('customer.business.document-templates.index', [
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
            'templates' => $active,
            'archived' => $archived,
            'showArchived' => $request->boolean('archived'),
            'recommended' => $this->templates->recommendedFor($business),
            'snippet' => fn (DocumentTemplate $t) => $this->templates->snippet($t),
            'documentsUrl' => route('customer.workspaces.businesses.documents.index', [$workspaceUid, $businessUid]),
        ]);
    }

    /** Blank template -> straight into the template editor. */
    public function create(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $data = $request->validate([
            'name' => 'nullable|string|max:191',
            'template_type' => 'nullable|in:proposal,contract',
        ]);

        $template = $this->templates->createBlank(
            $business,
            trim((string) ($data['name'] ?? '')) !== '' ? (string) $data['name'] : 'Untitled template',
            $data['template_type'] ?? 'proposal',
            Auth::user(),
        );

        return redirect()->route('customer.workspaces.businesses.document-templates.edit', [$workspaceUid, $businessUid, $template->uid]);
    }

    public function edit(string $workspaceUid, string $businessUid, string $templateUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $template = $this->ownTemplate($business, $templateUid);
        $route = fn (string $name, array $extra = []) => route('customer.workspaces.businesses.document-templates.' . $name, [$workspaceUid, $businessUid, ...$extra]);

        return view('customer.business.documents.editor', [
            'document' => null,
            'toolbox' => DocumentEditorToolbox::categories(),
            'icons' => DocumentEditorToolbox::icons(),
            'bootstrap' => $this->state->bootstrap($template, $business, [
                'index' => $route('index'),
                'library' => $route('index'),
                'edit' => $route('edit', [$templateUid]),
                'blocks' => $route('blocks', [$templateUid]),
                'preview' => $route('preview', [$templateUid]),
            ]),
            'workspaceUid' => $workspaceUid,
            'businessUid' => $businessUid,
        ]);
    }

    /** Autosave target: blocks (+ name / type / description), conditional on the template's lock_version. */
    public function blocks(Request $request, string $workspaceUid, string $businessUid, string $templateUid): JsonResponse
    {
        $business = $this->business($workspaceUid, $businessUid);

        return $this->respond(function () use ($request, $business, $templateUid) {
            $template = $this->ownTemplate($business, $templateUid);
            $data = Validator::make($request->all(), [
                'blocks' => 'sometimes|array',
                'name' => 'sometimes|required|string|max:' . DocumentTemplateService::NAME_MAX,
                'template_type' => 'sometimes|required|in:proposal,contract',
                'type' => 'sometimes|required|in:proposal,contract',
                'description' => 'sometimes|nullable|string|max:' . DocumentTemplateService::DESCRIPTION_MAX,
                'expected_lock_version' => 'required|integer|min:1',
            ])->validate();

            $changes = array_intersect_key($data, array_flip(['blocks', 'name', 'description', 'template_type']));
            if (isset($data['type']) && ! isset($changes['template_type'])) {
                $changes['template_type'] = $data['type'];
            }

            $saved = $this->templates->update($template, $changes, (int) $data['expected_lock_version'], $business);

            return [
                'lock_version' => (int) $saved->lock_version,
                'name' => (string) $saved->name,
                'template_type' => $saved->template_type->value,
                'description' => $saved->description,
                'blocks' => is_array($saved->blocks) ? $saved->blocks : [],
            ];
        });
    }

    public function duplicate(string $workspaceUid, string $businessUid, string $templateUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $template = $this->ownTemplate($business, $templateUid);
        $copy = $this->templates->duplicate($template, $business, Auth::user());

        return redirect()->route('customer.workspaces.businesses.document-templates.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => 'Created "' . $copy->name . '".']);
    }

    public function archive(string $workspaceUid, string $businessUid, string $templateUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $template = $this->templates->archive($this->ownTemplate($business, $templateUid), $business);

        return redirect()->route('customer.workspaces.businesses.document-templates.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => '"' . $template->name . '" was archived. Documents already created from it are not affected.']);
    }

    public function restore(string $workspaceUid, string $businessUid, string $templateUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $template = $this->templates->restore($this->ownTemplate($business, $templateUid), $business);

        return redirect()->route('customer.workspaces.businesses.document-templates.index', [$workspaceUid, $businessUid])
            ->with(['status' => 'success', 'message' => '"' . $template->name . '" was restored.']);
    }

    /** The template through the one renderer, with sample merge data and generic product / payment placeholders. */
    public function preview(string $workspaceUid, string $businessUid, string $templateUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $template = $this->readableTemplate($business, $templateUid);

        return view('customer.business.documents.editor-preview', [
            'previewTitle' => (string) $template->name,
            'previewNote' => 'Template preview. Names are sample data; you choose the product and payment terms when you use the template.',
            'blocksHtml' => app(DocumentBlockRenderer::class)->render(is_array($template->blocks) ? $template->blocks : [], 'template_preview', [
                'merge' => DocumentMergeFields::sample(),
                // Own template: its Business's catalog images render. A platform template has no images in V1.
                'business_id' => $template->isPlatformOwned() ? null : (int) $business->id,
            ]),
        ]);
    }

    /** A template of THIS Business, else a plain 404 (a foreign / forged / platform uid is indistinguishable from an unknown one). */
    private function ownTemplate(\App\Models\Business $business, string $uid): DocumentTemplate
    {
        try {
            return $this->templates->access()->owned($business, $uid);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function readableTemplate(\App\Models\Business $business, string $uid): DocumentTemplate
    {
        try {
            return $this->templates->access()->readable($business, $uid);
        } catch (ModelNotFoundException) {
            abort(404);
        }
    }

    private function respond(Closure $action): JsonResponse
    {
        try {
            return response()->json(['status' => 'ok'] + $action());
        } catch (DocumentTemplateConflictException $e) {
            return response()->json(['status' => 'conflict', 'message' => $e->getMessage(), 'lock_version' => $e->currentLockVersion], 409);
        } catch (InvalidDocumentBlocksException $e) {
            return $this->invalid($e->getMessage(), array_map(fn ($message) => [$message], $e->errors()));
        } catch (DocumentTemplateRefusedException $e) {
            return $this->invalid($e->getMessage(), ['template' => [$e->getMessage()]]);
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
