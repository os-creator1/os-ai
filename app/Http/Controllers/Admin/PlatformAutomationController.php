<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PlatformAutomation\PlatformAutomationStatus;
use App\Enums\PlatformAutomation\PlatformRunState;
use App\Enums\PlatformAutomation\PlatformStepState;
use App\Library\PlatformAutomation\PlatformAutomationCatalog;
use App\Library\PlatformAutomation\PlatformAutomationManager;
use App\Library\PlatformAutomation\PlatformRunExecutor;
use App\Models\PlatformAutomation;
use App\Models\PlatformAutomationRun;
use App\Models\PlatformAutomationStep;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Platform Owner UI for Platform Automations: Automations / Recipes / Runs / Failures.
 * Thin: every write goes through PlatformAutomationManager or PlatformRunExecutor.
 * The route group's EnsureUserIsAdministrator is the boundary; each action re-checks
 * the Platform Owner marker so a mis-wired route can never open it to staff.
 */
class PlatformAutomationController extends AdminBaseController
{
    private const TABS = ['automations', 'recipes', 'runs', 'failures'];

    public function __construct(
        private readonly PlatformAutomationManager $automations,
        private readonly PlatformRunExecutor $runs,
    ) {
    }

    public function list(Request $request): View
    {
        $this->owner();
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'automations';

        $runQuery = PlatformAutomationRun::query()->with('automation:id,uid,name')->orderByDesc('id');

        if ($tab === 'failures') {
            $runQuery->whereIn('state', [PlatformRunState::Failed->value, PlatformRunState::AwaitingApproval->value]);
        } elseif ($request->filled('state') && PlatformRunState::tryFrom((string) $request->query('state'))) {
            $runQuery->where('state', (string) $request->query('state'));
        }

        return view('admin.platform-automations.index', [
            'tab' => $tab,
            'automations' => PlatformAutomation::query()
                ->where('status', '!=', PlatformAutomationStatus::Archived->value)
                ->withCount('runs')->orderByDesc('id')->get(),
            'recipes' => PlatformAutomationCatalog::recipes(),
            'triggers' => PlatformAutomationCatalog::triggers(),
            'runs' => in_array($tab, ['runs', 'failures'], true) ? $runQuery->paginate(25)->withQueryString() : null,
            'counts' => [
                'failed' => PlatformAutomationRun::query()->where('state', PlatformRunState::Failed->value)->count(),
                'approval' => PlatformAutomationRun::query()->where('state', PlatformRunState::AwaitingApproval->value)->count(),
            ],
            'breadcrumbs' => $this->crumbs(),
        ]);
    }

    public function create(): View
    {
        $this->owner();

        return $this->form(new PlatformAutomation(['definition' => ['params' => [], 'conditions' => [], 'steps' => []]]));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->owner();
        $automation = $this->automations->create($this->input($request), (int) Auth::id());

        return redirect()->route('admin.platform-automations.edit', $automation)->with(['status' => 'success', 'message' => 'Automation saved as a draft. Enable it when you are ready.']);
    }

    public function edit(PlatformAutomation $automation): View
    {
        $this->owner();

        return $this->form($automation);
    }

    public function update(Request $request, PlatformAutomation $automation): RedirectResponse
    {
        $this->owner();
        $this->automations->update($automation, $this->input($request), (int) Auth::id());

        return redirect()->route('admin.platform-automations.edit', $automation)->with(['status' => 'success', 'message' => 'Automation saved.']);
    }

    public function enable(PlatformAutomation $automation): RedirectResponse
    {
        $this->owner();
        $this->automations->enable($automation, (int) Auth::id());

        return back()->with(['status' => 'success', 'message' => 'Automation enabled.']);
    }

    public function disable(PlatformAutomation $automation): RedirectResponse
    {
        $this->owner();
        $this->automations->disable($automation, (int) Auth::id());

        return back()->with(['status' => 'success', 'message' => 'Automation disabled. Runs already started will finish.']);
    }

    public function duplicate(PlatformAutomation $automation): RedirectResponse
    {
        $this->owner();
        $copy = $this->automations->duplicate($automation, (int) Auth::id());

        return redirect()->route('admin.platform-automations.edit', $copy)->with(['status' => 'success', 'message' => 'Duplicated as a draft.']);
    }

    public function archive(PlatformAutomation $automation): RedirectResponse
    {
        $this->owner();
        $this->automations->archive($automation, (int) Auth::id());

        return redirect()->route('admin.platform-automations.index')->with(['status' => 'success', 'message' => 'Automation archived.']);
    }

    public function useRecipe(string $recipe): RedirectResponse
    {
        $this->owner();
        $automation = $this->automations->createFromRecipe($recipe, (int) Auth::id());

        return redirect()->route('admin.platform-automations.edit', $automation)->with(['status' => 'success', 'message' => 'Recipe copied. Review it, then enable it.']);
    }

    public function showRun(PlatformAutomationRun $run): View
    {
        $this->owner();

        return view('admin.platform-automations.run', [
            'run' => $run->load('automation', 'version', 'steps'),
            'actions' => PlatformAutomationCatalog::actions(),
            'breadcrumbs' => $this->crumbs('Run'),
        ]);
    }

    public function retryRun(PlatformAutomationRun $run): RedirectResponse
    {
        $this->owner();

        return back()->with($this->runs->retry($run)
            ? ['status' => 'success', 'message' => 'Run re-queued from the failed step.']
            : ['status' => 'error', 'message' => 'Only a failed run can be retried.']);
    }

    public function approveStep(PlatformAutomationRun $run, int $index): RedirectResponse
    {
        $this->owner();
        $this->runs->approve($this->awaitingStep($run, $index), (int) Auth::id());

        return back()->with(['status' => 'success', 'message' => 'Approved. The step runs now, as you.']);
    }

    public function rejectStep(PlatformAutomationRun $run, int $index): RedirectResponse
    {
        $this->owner();
        $this->runs->reject($this->awaitingStep($run, $index), (int) Auth::id());

        return back()->with(['status' => 'success', 'message' => 'Rejected. The run was cancelled.']);
    }

    private function awaitingStep(PlatformAutomationRun $run, int $index): PlatformAutomationStep
    {
        return $run->steps()->where('step_index', $index)->where('state', PlatformStepState::AwaitingApproval->value)->firstOrFail();
    }

    private function form(PlatformAutomation $automation): View
    {
        return view('admin.platform-automations.edit', [
            'automation' => $automation,
            'triggers' => PlatformAutomationCatalog::triggers(),
            'unavailableTriggers' => PlatformAutomationCatalog::unavailableTriggers(),
            'actions' => PlatformAutomationCatalog::actions(),
            'unavailableActions' => PlatformAutomationCatalog::unavailableActions(),
            'facts' => PlatformAutomationCatalog::FACTS,
            'operators' => PlatformAutomationCatalog::OPERATORS,
            'mergeTokens' => PlatformAutomationCatalog::MERGE_TOKENS,
            'breadcrumbs' => $this->crumbs($automation->exists ? 'Edit' : 'New'),
        ]);
    }

    /** @return array<string, mixed> */
    private function input(Request $request): array
    {
        return [
            'name' => $request->input('name'),
            'description' => $request->input('description'),
            'trigger_type' => $request->input('trigger_type'),
            'definition' => [
                'params' => (array) $request->input('params', []),
                'conditions' => array_values((array) $request->input('conditions', [])),
                'steps' => array_values((array) $request->input('steps', [])),
            ],
        ];
    }

    private function owner(): void
    {
        abort_unless(Auth::user()?->is_admin, 404);
    }

    /** @return array<int, array<string, string>> */
    private function crumbs(?string $trailing = null): array
    {
        $crumbs = [
            ['link' => route('admin.platform-owner.overview'), 'name' => 'Platform Owner'],
            ['link' => $trailing === null ? null : route('admin.platform-automations.index'), 'name' => 'Platform Automations'],
        ];

        if ($trailing !== null) {
            $crumbs[] = ['name' => $trailing];
        }

        return array_map(fn ($c) => array_filter($c, fn ($v) => $v !== null), $crumbs);
    }
}
