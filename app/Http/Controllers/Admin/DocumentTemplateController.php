<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Documents\DocumentTemplateStatus;
use App\Exceptions\Documents\DocumentTemplateConflictException;
use App\Exceptions\Documents\DocumentTemplateRefusedException;
use App\Exceptions\Documents\InvalidDocumentBlocksException;
use App\Exceptions\NicheBlueprint\BlueprintAuthoringException;
use App\Exceptions\NicheBlueprint\UnknownBlueprintComponentTypeException;
use App\Http\Requests\Admin\DocumentTemplate\AssignNichesRequest;
use App\Http\Requests\Admin\DocumentTemplate\PublishBlueprintRequest;
use App\Http\Requests\Admin\DocumentTemplate\StoreDocumentTemplateRequest;
use App\Http\Requests\Admin\DocumentTemplate\UpdateDocumentTemplateBlocksRequest;
use App\Library\Documents\Blocks\DocumentBlockRenderer;
use App\Library\Documents\Blocks\DocumentMergeFields;
use App\Library\Documents\Editor\DocumentEditorToolbox;
use App\Library\Documents\Templates\DocumentTemplateEditorState;
use App\Library\Documents\Templates\DocumentTemplateService;
use App\Library\Documents\Templates\PlatformTemplateNicheAssignments;
use App\Models\DocumentTemplate;
use App\Models\NicheBlueprint;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Implementation Contract 17B §6b - the Platform Owner's proposal / contract
 * template surface (`/admin/document-templates`).
 *
 * A thin adapter. Authority is layered exactly like every other Platform Owner
 * surface: the route group's `can:access backend` + EnsureUserIsAdministrator
 * (View As and non-admin accounts refused), `->missing()` through
 * PlatformOwnerAuthority::refuseMissingTarget (no existence oracle for a
 * non-owner), the FormRequests re-ask PlatformOwnerAuthority, and the service
 * re-derives `users.is_admin` on every platform write.
 *
 * ONLY PLATFORM TEMPLATES ARE REACHABLE HERE: every action resolves its template
 * through platform() which 404s a Business-owned row, and the service `update()`
 * is called with a NULL scope (platform-owned only). No Business route can reach
 * this controller, and no Business scope can reach a platform template.
 *
 * Writes: template content/status -> DocumentTemplateService; niche assignment
 * -> PlatformTemplateNicheAssignments -> NicheBlueprintPublisher (the sole
 * blueprint write seam). Nothing is copied into a Business anywhere.
 */
class DocumentTemplateController extends AdminBaseController
{
    public function __construct(
        private readonly DocumentTemplateService $templates,
        private readonly DocumentTemplateEditorState $state,
        private readonly PlatformTemplateNicheAssignments $assignments,
    ) {
    }

    public function index(): View
    {
        $templates = DocumentTemplate::query()
            ->whereNull('business_id')
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate(25);

        return view('admin.document-templates.index', [
            'templates' => $templates,
            'niches' => $this->assignments->liveAssignments(),
            'breadcrumbs' => $this->breadcrumbs(),
        ]);
    }

    public function create(): View
    {
        return view('admin.document-templates.create', [
            'breadcrumbs' => $this->breadcrumbs([['name' => 'New template']]),
        ]);
    }

    /** Blank DRAFT -> straight into the editor. */
    public function store(StoreDocumentTemplateRequest $request): RedirectResponse
    {
        $template = $this->templates->createPlatform(
            (string) $request->validated('name'),
            (string) $request->validated('template_type'),
            $request->validated('description'),
            Auth::user(),
        );

        return redirect()->route('admin.document-templates.edit', $template);
    }

    public function edit(DocumentTemplate $template): View
    {
        $template = $this->platform($template);
        $route = fn (string $name) => route('admin.document-templates.'.$name, $template);

        return view('customer.business.documents.editor', [
            'document' => null,
            'toolbox' => DocumentEditorToolbox::platformCategories(),
            'icons' => DocumentEditorToolbox::icons(),
            'bootstrap' => $this->state->bootstrap($template, null, [
                'index' => route('admin.document-templates.index'),
                'library' => route('admin.document-templates.index'),
                'edit' => $route('edit'),
                'blocks' => $route('blocks'),
                'preview' => $route('preview'),
                'niches' => $route('niches'),
                'publish' => $route('publish'),
                'disable' => $route('disable'),
            ], 'platform_template', DocumentEditorToolbox::platformCategories()),
        ]);
    }

    /** Autosave target. 200 / 409 / 422 / 404, exactly like the Business template endpoint. */
    public function blocks(UpdateDocumentTemplateBlocksRequest $request, DocumentTemplate $template): JsonResponse
    {
        $template = $this->platform($template);
        $data = $request->validated();

        $changes = array_intersect_key($data, array_flip(['blocks', 'name', 'description', 'template_type']));
        if (isset($data['type']) && ! isset($changes['template_type'])) {
            $changes['template_type'] = $data['type'];
        }

        try {
            $saved = $this->templates->update($template, $changes, (int) $data['expected_lock_version'], null);
        } catch (DocumentTemplateConflictException $e) {
            return response()->json(['status' => 'conflict', 'message' => $e->getMessage(), 'lock_version' => $e->currentLockVersion], 409);
        } catch (InvalidDocumentBlocksException $e) {
            return response()->json([
                'status' => 'invalid',
                'message' => $e->getMessage(),
                'errors' => array_map(fn ($message) => [$message], $e->errors()),
            ], 422);
        } catch (DocumentTemplateRefusedException $e) {
            return response()->json(['status' => 'invalid', 'message' => $e->getMessage(), 'errors' => ['template' => [$e->getMessage()]]], 422);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json(['status' => 'invalid', 'message' => $e->validator->errors()->first(), 'errors' => $e->errors()], 422);
        }

        return response()->json([
            'status' => 'ok',
            'lock_version' => (int) $saved->lock_version,
            'name' => (string) $saved->name,
            'template_type' => $saved->template_type->value,
            'description' => $saved->description,
            'blocks' => is_array($saved->blocks) ? $saved->blocks : [],
        ]);
    }

    /** The template through the ONE renderer, with sample merge data and generic product / payment placeholders. */
    public function preview(DocumentTemplate $template): View
    {
        $template = $this->platform($template);

        return view('customer.business.documents.editor-preview', [
            'previewTitle' => (string) $template->name,
            'previewNote' => 'Platform template preview. Names are sample data; the Business chooses the product and payment terms when it uses the template.',
            'blocksHtml' => app(DocumentBlockRenderer::class)->render(is_array($template->blocks) ? $template->blocks : [], 'template_preview', [
                'merge' => DocumentMergeFields::sample(),
                'business_id' => null,
            ]),
        ]);
    }

    /** Draft / disabled -> published. Recommended only once it is also assigned to a PUBLISHED niche blueprint version. */
    public function publish(DocumentTemplate $template): RedirectResponse
    {
        $template = $this->platform($template);

        try {
            $this->templates->activatePlatform($template, Auth::user());
        } catch (DocumentTemplateRefusedException $e) {
            return $this->backToEditor($template)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.document-templates.index')
            ->with('flash_success', '"'.$template->name.'" is published. Assign it to a niche (and publish that blueprint version) to recommend it to businesses.');
    }

    /** Published -> disabled: gone from every Business's recommendations immediately; documents already created keep their own copy. */
    public function disable(DocumentTemplate $template): RedirectResponse
    {
        $template = $this->platform($template);

        try {
            $this->templates->disablePlatform($template, Auth::user());
        } catch (DocumentTemplateRefusedException $e) {
            return $this->backToEditor($template)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.document-templates.index')
            ->with('flash_success', '"'.$template->name.'" is disabled and no longer recommended to any business.');
    }

    /** The assignment screen: every niche blueprint with a checkbox, plus pending (unpublished) changes. */
    public function niches(DocumentTemplate $template): View
    {
        $template = $this->platform($template);

        return view('admin.document-templates.niches', [
            'template' => $template,
            'rows' => $this->assignments->overview($template),
            'breadcrumbs' => $this->breadcrumbs([
                ['link' => route('admin.document-templates.edit', $template), 'name' => $template->name],
                ['name' => 'Assign to niches'],
            ]),
        ]);
    }

    /** Step 1: record the choice on each blueprint's DRAFT version. Nothing is live until step 2. */
    public function assignNiches(AssignNichesRequest $request, DocumentTemplate $template): RedirectResponse
    {
        $template = $this->platform($template);

        try {
            $result = $this->assignments->apply(Auth::user(), $template, $request->blueprintUids());
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (BlueprintAuthoringException|UnknownBlueprintComponentTypeException|\InvalidArgumentException $e) {
            return redirect()->route('admin.document-templates.niches', $template)->with('flash_error', $e->getMessage());
        }

        $message = $result['assigned'] + $result['unassigned'] === 0
            ? 'No changes.'
            : 'Saved to blueprint draft versions ('.$result['assigned'].' added, '.$result['unassigned'].' removed). Publish the blueprint version below to make it live.';

        return redirect()->route('admin.document-templates.niches', $template)->with('flash_success', $message);
    }

    /** Step 2: the explicit "Publish blueprint version" action, one niche at a time. */
    public function publishBlueprint(PublishBlueprintRequest $request, DocumentTemplate $template): RedirectResponse
    {
        $template = $this->platform($template);
        $blueprint = NicheBlueprint::query()->where('uid', (string) $request->validated('blueprint'))->first() ?? abort(404);

        try {
            $version = $this->assignments->publishDraft(Auth::user(), $blueprint);
        } catch (ModelNotFoundException) {
            return redirect()->route('admin.document-templates.niches', $template)->with('flash_error', 'That blueprint has no draft version to publish.');
        } catch (BlueprintAuthoringException|UnknownBlueprintComponentTypeException $e) {
            return redirect()->route('admin.document-templates.niches', $template)->with('flash_error', $e->getMessage());
        }

        return redirect()->route('admin.document-templates.niches', $template)
            ->with('flash_success', $blueprint->display_name.' blueprint version '.$version->version_number.' published.');
    }

    /**
     * A PLATFORM template or a plain 404: a Business-owned uid is indistinguishable from an unknown one. (A non-owner
     * never gets this far - the route group refuses them first, and an unresolved uid goes through refuseMissingTarget.)
     */
    private function platform(DocumentTemplate $template): DocumentTemplate
    {
        abort_unless($template->isPlatformOwned(), 404);

        return $template;
    }

    private function backToEditor(DocumentTemplate $template): RedirectResponse
    {
        return redirect()->route('admin.document-templates.edit', $template);
    }

    /**
     * @param  array<int, array<string, mixed>>  $trailing
     * @return array<int, array<string, mixed>>
     */
    private function breadcrumbs(array $trailing = []): array
    {
        return [
            ['link' => url(config('app.admin_path').'/dashboard'), 'name' => __('locale.menu.Dashboard')],
            ['link' => route('admin.document-templates.index'), 'name' => 'Proposal templates'],
            ...$trailing,
        ];
    }
}
