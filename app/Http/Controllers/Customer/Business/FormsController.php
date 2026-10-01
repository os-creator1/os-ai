<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Forms\FormDeploymentSource;
use App\Enums\Forms\FormFieldType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\AuthorizesFormsRequests;
use App\Library\Forms\Exceptions\FormRuleException;
use App\Library\Forms\FormDefinitionNormalizer;
use App\Library\Forms\FormManager;
use App\Library\Forms\FormSubmissionReader;
use App\Models\CrmPipeline;
use App\Models\Form;
use App\Models\FormDeployment;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Forms V1 — the standalone customer Forms area: list, create, edit, activate /
 * switch off, and offer a form at a Location.
 *
 * A THIN HTTP BOUNDARY. Every action runs the Forms gate chain
 * (AuthorizesFormsRequests: tenancy -> `forms` capability -> `forms`
 * entitlement) BEFORE it validates or reads anything, then hands straight to
 * `FormManager`, the only writer. No form rule lives here: field bounds, the
 * phone-field requirement for Opportunities, versioning and the lifecycle are
 * the manager's, and a refusal (`FormRuleException`) is shown as it was worded.
 *
 * Definition management follows Business tenancy + capability (a definition is
 * Business-wide). Offering a form at a Location additionally runs
 * LocationAccessGuard, so a Location-limited member can only deploy to — and only
 * ever sees — the Locations they may reach.
 */
class FormsController extends Controller
{
    use AuthorizesFormsRequests;

    public function __construct(
        private readonly FormManager $forms,
        private readonly FormSubmissionReader $reader,
    ) {
    }

    public function index(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->formsScope($workspaceUid, $businessUid);

        return view('customer.business.forms.index', [
            'workspace' => $workspace,
            'business' => $business,
            'forms' => Form::query()
                ->where('business_id', $business->id)
                ->orderByDesc('id')
                ->limit(200)
                ->get(),
        ]);
    }

    public function create(string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->formsScope($workspaceUid, $businessUid);

        return view('customer.business.forms.create', $this->editorData($workspace, $business, null));
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        [, $business] = $this->formsScope($workspaceUid, $businessUid);

        try {
            $form = $this->forms->create($business, $this->validated($request), (int) Auth::id());
        } catch (FormRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToEdit($workspaceUid, $businessUid, $form)
            ->with('flash_success', '"'.$form->name.'" created as a draft. Choose where to offer it, then activate it.');
    }

    public function edit(string $workspaceUid, string $businessUid, string $formUid): View
    {
        [$workspace, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        return view('customer.business.forms.edit', $this->editorData($workspace, $business, $form));
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $formUid): RedirectResponse
    {
        [, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        try {
            $updated = $this->forms->update($business, $form, $this->validated($request), (int) Auth::id());
        } catch (FormRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToEdit($workspaceUid, $businessUid, $updated)->with('flash_success', 'Saved.');
    }

    public function activate(string $workspaceUid, string $businessUid, string $formUid): RedirectResponse
    {
        [, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        try {
            $this->forms->activate($business, $form);
        } catch (FormRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToEdit($workspaceUid, $businessUid, $form)->with('flash_success', 'This form is now active.');
    }

    public function deactivate(string $workspaceUid, string $businessUid, string $formUid): RedirectResponse
    {
        [, $business] = $this->formsScope($workspaceUid, $businessUid);
        $form = $this->formOrAbort($business, $formUid);

        try {
            $this->forms->deactivate($business, $form);
        } catch (FormRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToEdit($workspaceUid, $businessUid, $form)->with('flash_success', 'This form is switched off. Its link no longer accepts responses.');
    }

    public function setLocation(Request $request, string $workspaceUid, string $businessUid, string $formUid, string $locationUid): RedirectResponse
    {
        [, $business, $location] = $this->formsLocationScope($workspaceUid, $businessUid, $locationUid);
        $form = $this->formOrAbort($business, $formUid);

        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        try {
            $this->forms->setDeployment($business, $form, $location, (bool) $data['enabled']);
        } catch (FormRuleException $exception) {
            return $this->refused($exception);
        }

        return $this->backToEdit($workspaceUid, $businessUid, $form)->with(
            'flash_success',
            $data['enabled'] ? 'Now offered at '.($location->name ?: 'that location').'.' : 'No longer offered at '.($location->name ?: 'that location').'.'
        );
    }

    /**
     * Shape validation only — the RULES (field bounds, types, the Opportunity
     * requirement) are FormManager's, so the limits here are deliberately loose
     * enough never to pre-empt or contradict them.
     *
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:1000'],
            'intro' => ['nullable', 'string', 'max:20000'],
            'submit_label' => ['nullable', 'string', 'max:1000'],
            'success_message' => ['nullable', 'string', 'max:5000'],
            'pages' => ['nullable', 'array', 'max:'.(FormDefinitionNormalizer::MAX_PAGES * 2)],
            'pages.*' => ['array'],
            'pages.*.key' => ['nullable', 'string', 'max:64'],
            'pages.*.title' => ['nullable', 'string', 'max:1000'],
            'pages.*.position' => ['nullable', 'integer'],
            'fields' => ['required', 'array', 'max:'.(FormDefinitionNormalizer::MAX_FIELDS + 25)],
            'fields.*' => ['array'],
            'fields.*.key' => ['nullable', 'string', 'max:64'],
            'fields.*.label' => ['nullable', 'string', 'max:1000'],
            'fields.*.page' => ['nullable', 'string', 'max:64'],
            'fields.*.type' => ['nullable', 'string', 'max:32'],
            'fields.*.required' => ['nullable', 'boolean'],
            'fields.*.options' => ['nullable', 'string', 'max:5000'],
            'fields.*.contact_name' => ['nullable', 'boolean'],
            'create_opportunity' => ['nullable', 'boolean'],
            'opportunity_pipeline_id' => ['nullable', 'integer'],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function editorData($workspace, $business, ?Form $form): array
    {
        $version = $form?->currentVersion();

        $rows = old('fields');
        if (! is_array($rows)) {
            $rows = collect($version?->fields ?? [])
                ->map(fn (array $field) => $field + ['options' => ''])
                ->map(fn (array $field) => array_merge($field, ['options' => implode("\n", $field['options'] ?? [])]))
                ->all();
        }
        // Spare, unused rows: a blank label is ignored on save.
        $rows = array_pad(array_values($rows), min(count($rows) + 3, FormDefinitionNormalizer::MAX_FIELDS + 3), []);

        // Page SLOTS: the version's pages (in their order), then unused spares up
        // to the bound. A slot keeps its key wherever it is moved; reordering is
        // an edit of its position, and a slot nobody uses is dropped on save.
        $pageRows = old('pages');
        if (! is_array($pageRows)) {
            $pageRows = [];
            foreach ($version?->pages() ?? [['key' => 'page_1', 'title' => null]] as $i => $page) {
                $pageRows[] = ['key' => $page['key'], 'title' => $page['title'] ?? '', 'position' => $i + 1];
            }
            $taken = array_column($pageRows, 'key');
            for ($n = 1; count($pageRows) < FormDefinitionNormalizer::MAX_PAGES; $n++) {
                if (! in_array('page_'.$n, $taken, true)) {
                    $pageRows[] = ['key' => 'page_'.$n, 'title' => '', 'position' => count($pageRows) + 1];
                }
            }
        }

        $visible = $form === null ? [] : $this->reader->visibleLocations($business, (int) Auth::id());

        return [
            'workspace' => $workspace,
            'business' => $business,
            'form' => $form,
            'version' => $version,
            'rows' => $rows,
            'pageRows' => $pageRows,
            'types' => FormFieldType::cases(),
            'pipelines' => CrmPipeline::query()->forBusiness($business)->active()->orderBy('position')->orderBy('id')->get(['id', 'name']),
            'locations' => $visible,
            'deployments' => $form === null
                ? collect()
                : FormDeployment::query()
                    ->where('form_id', $form->id)
                    ->where('source', FormDeploymentSource::DirectLink->value)
                    ->whereIn('business_location_id', array_keys($visible))
                    ->get()
                    ->keyBy('business_location_id'),
            'limits' => [
                'fields' => FormDefinitionNormalizer::MAX_FIELDS,
                'pages' => FormDefinitionNormalizer::MAX_PAGES,
            ],
        ];
    }

    private function refused(FormRuleException $exception): RedirectResponse
    {
        return back()->withInput()->withErrors(['forms' => $exception->getMessage()]);
    }

    private function backToEdit(string $workspaceUid, string $businessUid, Form $form): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.forms.edit', [$workspaceUid, $businessUid, $form->uid]);
    }
}
