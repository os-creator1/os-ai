<?php

namespace App\Http\Controllers\Customer\Business;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\AuthorizesFormsRequests;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Library\Forms\Exceptions\FormStaleVersionException;
use App\Library\Forms\FormAnalyticsReader;
use App\Library\Forms\FormDefinitionNormalizer;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormNotificationWorkflows;
use App\Library\Forms\FormSubmissionReader;
use App\Models\FormVersion;
use Illuminate\Contracts\View\View;
use App\Enums\Entitlement\PlatformFeature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        private readonly FormNotificationWorkflows $notifier,
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

        // Notifications ARE Automations. The cards only become buttons for someone who
        // may manage automations on a Business entitled to them; otherwise the tab
        // says so instead of offering a door that would answer 401/404.
        $canAutomate = $this->canManageAutomations($workspaceUid, $businessUid);

        return view('customer.business.forms.notifications', [
            'workspace' => $workspace,
            'business' => $business,
            'form' => $form,
            'tab' => 'notifications',
            'canAutomate' => $canAutomate,
            'eligibility' => $canAutomate ? $this->notifier->eligibility($business) : [],
            'linked' => $canAutomate ? $this->notifier->linked($business, $form) : [],
        ]);
    }

    /**
     * "Set up" on a notification card: a NEW DRAFT automation pointed at this form
     * with one starter step, opened in the existing workflow editor. Never published
     * here — nothing is sent until the person reviews and publishes it there.
     */
    public function startNotification(string $workspaceUid, string $businessUid, string $formUid, string $action): RedirectResponse
    {
        [, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        abort_unless(in_array($action, FormNotificationWorkflows::ACTIONS, true), 404);

        $this->authorize('automations');
        $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Automations->value);

        $eligibility = $this->notifier->eligibility($business)[$action];

        if (! $eligibility['available']) {
            return redirect()
                ->route('customer.workspaces.businesses.forms.notifications', [$workspaceUid, $businessUid, $form->uid])
                ->withErrors(['forms' => $eligibility['reason'] ?: 'That channel is not set up for this business yet.']);
        }

        $workflow = $this->notifier->start($business, $form, $action, (int) Auth::id());

        return redirect()->route('customer.workspaces.businesses.automations.workflows.show', [$workspaceUid, $businessUid, $workflow->uid]);
    }

    private function canManageAutomations(string $workspaceUid, string $businessUid): bool
    {
        if (! Gate::allows('automations')) {
            return false;
        }

        try {
            $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Automations->value);
        } catch (HttpExceptionInterface) {
            return false;
        }

        return true;
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
            'shareUrl' => $this->formShareUrl($form, $visible),
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
