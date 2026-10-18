<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\AuthorizesFormsRequests;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Library\Forms\Exceptions\FormStaleVersionException;
use App\Library\Forms\FormAnalyticsReader;
use App\Library\Forms\FormDefinitionNormalizer;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormSubmissionReader;
use App\Models\FormVersion;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Forms visual builder — the editor's JSON endpoints (autosave, preview) and the
 * two read-only tabs that have no editing of their own (Notifications, Analytics).
 *
 * THIN, LIKE THE REST OF FORMS. Every action runs the same gate chain as
 * FormsController (AuthorizesFormsRequests: tenancy -> `forms` capability ->
 * `forms` entitlement) BEFORE it reads anything, re-loads the form through the
 * Business it was addressed by (a form uid of another Business is a 404), and
 * hands to the domain: FormManager is still the ONLY writer and
 * FormDefinitionNormalizer still decides what is valid. Nothing here writes a
 * version directly, so the immutable-version contract is untouched.
 *
 * AUTOSAVE. The browser names the version it was editing (`base_version`).
 * FormManager refuses, under its row lock, a save on top of any other version —
 * answered 409 "stale tab" so a second tab or a teammate can never silently
 * overwrite a newer edit. A save that changes nothing writes no version.
 */
class FormBuilderController extends Controller
{
    use AuthorizesFormsRequests;

    public function __construct(
        private readonly FormManager $forms,
        private readonly FormDefinitionNormalizer $definitions,
        private readonly FormSubmissionReader $reader,
        private readonly FormAnalyticsReader $analytics,
    ) {
    }

    public function save(Request $request, string $workspaceUid, string $businessUid, string $formUid): JsonResponse
    {
        [, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        $data = $this->validatedDocument($request) + $request->validate([
            'base_version' => ['required', 'integer', 'min:1'],
            'base_hash' => ['nullable', 'string', 'size:64'],
        ]);

        try {
            $updated = $this->forms->update($business, $form, $data, (int) Auth::id(), (int) $data['base_version'], $data['base_hash'] ?? null, true);
        } catch (FormStaleVersionException $exception) {
            return response()->json(['status' => 'conflict', 'message' => $exception->getMessage(), 'current_version' => $exception->currentVersion], 409);
        } catch (FormRuleException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'status' => 'saved',
            'version' => (int) $updated->current_version,
            'hash' => $updated->currentVersion()->content_hash,
            'saved_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Renders the UNSAVED document through the very partials the public form uses
     * (public.forms._style / _card / _fields). Nothing is written and no token or
     * deployment exists; the answer is a self-contained HTML document for an iframe.
     */
    public function preview(Request $request, string $workspaceUid, string $businessUid, string $formUid)
    {
        [, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        $input = $this->validatedDocument($request);

        try {
            $name = $this->definitions->name($input['name'] ?? null);
            $content = $this->definitions->content($business, $input, $form->currentVersion()?->fields ?? []);
        } catch (FormRuleException $exception) {
            return response()->json(['status' => 'error', 'message' => $exception->getMessage()], 422);
        }

        $version = new FormVersion($content);

        return response()->view('public.forms.preview', [
            'name' => $name,
            'version' => $version,
            'pages' => $version->pages(),
        ])->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function notifications(string $workspaceUid, string $businessUid, string $formUid): View
    {
        [$workspace, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        return view('customer.business.forms.notifications', [
            'workspace' => $workspace,
            'business' => $business,
            'form' => $form,
            'tab' => 'notifications',
        ]);
    }

    public function analytics(string $workspaceUid, string $businessUid, string $formUid): View
    {
        [$workspace, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        $visible = $this->reader->visibleLocations($business, (int) Auth::id());

        return view('customer.business.forms.analytics', [
            'workspace' => $workspace,
            'business' => $business,
            'form' => $form,
            'tab' => 'analytics',
            'stats' => $this->analytics->summary($business, $form, $visible, (bool) $form->currentVersion()?->isMultiPage()),
        ]);
    }

    /**
     * Shape validation only — the RULES are FormDefinitionNormalizer's. Mirrors
     * FormsController::validated() but takes an options ARRAY, which is what the
     * visual editor owns (the classic post sends a newline-separated string).
     *
     * @return array<string, mixed>
     */
    private function validatedDocument(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:1000'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'submit_label' => ['nullable', 'string', 'max:1000'],
            'success_message' => ['nullable', 'string', 'max:5000'],
            'design' => ['nullable', 'array'],
            'design.*' => ['nullable', 'string', 'max:64'],
            'pages' => ['nullable', 'array', 'max:'.(FormDefinitionNormalizer::MAX_PAGES * 2)],
            'pages.*' => ['array'],
            'pages.*.key' => ['nullable', 'string', 'max:64'],
            'pages.*.title' => ['nullable', 'string', 'max:1000'],
            'pages.*.position' => ['nullable', 'integer'],
            'fields' => ['required', 'array', 'max:'.(FormDefinitionNormalizer::MAX_FIELDS + FormDefinitionNormalizer::MAX_BLOCKS + 25)],
            'fields.*' => ['array'],
            'fields.*.key' => ['nullable', 'string', 'max:64'],
            'fields.*.label' => ['nullable', 'string', 'max:5000'],
            'fields.*.page' => ['nullable', 'string', 'max:64'],
            'fields.*.type' => ['nullable', 'string', 'max:32'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.options' => ['nullable', 'array', 'max:'.(FormDefinitionNormalizer::MAX_OPTIONS + 25)],
            'fields.*.options.*' => ['nullable', 'string', 'max:1000'],
            'fields.*.contact_name' => ['nullable', 'boolean'],
            'fields.*.contact_part' => ['nullable', 'string', 'max:32'],
            'fields.*.custom_field_uid' => ['nullable', 'string', 'max:64'],
            'fields.*.placeholder' => ['nullable', 'string', 'max:1000'],
            'fields.*.help' => ['nullable', 'string', 'max:2000'],
            'fields.*.width' => ['nullable', 'string', 'max:16'],
            'fields.*.default' => ['nullable', 'string', 'max:1000'],
            'create_opportunity' => ['nullable', 'boolean'],
            'opportunity_pipeline_id' => ['nullable', 'integer'],
        ]);
    }
}
