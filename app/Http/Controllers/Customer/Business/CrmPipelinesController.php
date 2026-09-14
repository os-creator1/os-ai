<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Crm\CrmOpportunityStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Http\Controllers\Customer\CustomerBaseController;
use App\Library\Crm\CrmPipelineService;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Models\Business;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * CRM Opportunities — setting a Business's pipelines up and customising them:
 * the first pipeline from the standard template, further pipelines, and adding,
 * renaming, reordering and archiving stages.
 *
 * The same tenancy chain and permissions as CrmOpportunitiesController; the
 * rules themselves (New inquiry stays first and can't be archived, open deals
 * must go somewhere before their stage is archived) live in CrmPipelineService.
 */
class CrmPipelinesController extends CustomerBaseController
{
    use ResolvesBusinessTenancy;

    public function __construct(private readonly CrmPipelineService $pipelines)
    {
    }

    /** "Set up your pipeline": copy the standard template. Safe to submit twice. */
    public function setup(string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);

        $pipeline = $this->pipelines->setUpStandardPipeline($business, (int) Auth::id());

        return redirect()->route('customer.workspaces.businesses.crm.board', [$workspaceUid, $businessUid, 'pipeline' => $pipeline->uid]);
    }

    public function store(Request $request, string $workspaceUid, string $businessUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);

        $data = $request->validate(['name' => ['required', 'string', 'max:' . CrmPipelineService::NAME_MAX]]);

        try {
            $pipeline = $this->pipelines->createPipeline($business, $data['name'], (int) Auth::id());
        } catch (CrmRuleException $exception) {
            return back()->withInput()->withErrors(['name' => $exception->getMessage()]);
        }

        return redirect()
            ->route('customer.workspaces.businesses.crm.pipelines.settings', [$workspaceUid, $businessUid, $pipeline->uid])
            ->with(['status' => 'success', 'message' => '"' . $pipeline->name . '" created. Adjust its stages below.']);
    }

    public function settings(string $workspaceUid, string $businessUid, string $pipelineUid): View
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);

        $pipeline = $this->pipeline($business, $pipelineUid);
        $stages = $pipeline->stages()->get();

        $openCounts = CrmOpportunity::query()
            ->where('business_id', $business->id)
            ->where('pipeline_id', $pipeline->id)
            ->where('status', CrmOpportunityStatus::Open->value)
            ->groupBy('stage_id')
            ->selectRaw('stage_id, count(*) as deals')
            ->pluck('deals', 'stage_id');

        return view('customer.crm.pipeline-settings', [
            'pipeline' => $pipeline,
            'activeStages' => $stages->whereNull('archived_at')->values(),
            'archivedStages' => $stages->whereNotNull('archived_at')->values(),
            'openCounts' => $openCounts,
        ]);
    }

    public function update(Request $request, string $workspaceUid, string $businessUid, string $pipelineUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);
        $pipeline = $this->pipeline($business, $pipelineUid);

        $data = $request->validate(['name' => ['required', 'string', 'max:' . CrmPipelineService::NAME_MAX]]);

        return $this->attempt(fn () => $this->pipelines->renamePipeline($pipeline, $data['name']), 'Pipeline renamed.');
    }

    public function storeStage(Request $request, string $workspaceUid, string $businessUid, string $pipelineUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);
        $pipeline = $this->pipeline($business, $pipelineUid);

        $data = $request->validate(['name' => ['required', 'string', 'max:' . CrmPipelineService::NAME_MAX]]);

        return $this->attempt(fn () => $this->pipelines->addStage($pipeline, $data['name']), 'Stage added.');
    }

    public function updateStage(Request $request, string $workspaceUid, string $businessUid, string $pipelineUid, string $stageUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);
        $stage = $this->stage($this->pipeline($business, $pipelineUid), $stageUid);

        $data = $request->validate(['name' => ['required', 'string', 'max:' . CrmPipelineService::NAME_MAX]]);

        return $this->attempt(fn () => $this->pipelines->renameStage($stage, $data['name']), 'Stage renamed.');
    }

    public function moveStage(Request $request, string $workspaceUid, string $businessUid, string $pipelineUid, string $stageUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);
        $stage = $this->stage($this->pipeline($business, $pipelineUid), $stageUid);

        $data = $request->validate(['direction' => ['required', Rule::in(['up', 'down'])]]);

        return $this->attempt(fn () => $this->pipelines->moveStage($stage, $data['direction'] === 'up' ? -1 : 1), 'Stage order saved.');
    }

    public function archiveStage(Request $request, string $workspaceUid, string $businessUid, string $pipelineUid, string $stageUid): RedirectResponse
    {
        $business = $this->business($workspaceUid, $businessUid);
        $this->authorize(CrmOpportunitiesController::MANAGE_PERMISSION);
        $pipeline = $this->pipeline($business, $pipelineUid);
        $stage = $this->stage($pipeline, $stageUid);

        $data = $request->validate(['destination' => ['nullable', 'string', 'max:64']]);
        $destination = empty($data['destination']) ? null : $this->stage($pipeline, $data['destination']);

        return $this->attempt(function () use ($stage, $destination) {
            $moved = $this->pipelines->archiveStage($stage, $destination, (int) Auth::id());

            return '"' . $stage->name . '" archived' . ($moved > 0 ? ' and ' . $moved . ' open ' . ($moved === 1 ? 'opportunity' : 'opportunities') . ' moved to "' . $destination->name . '".' : '.');
        });
    }

    private function business(string $workspaceUid, string $businessUid): Business
    {
        [, $business] = $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Crm->value);

        return $business;
    }

    private function pipeline(Business $business, string $uid): CrmPipeline
    {
        return CrmPipeline::query()->forBusiness($business)->active()->where('uid', $uid)->first() ?? abort(404);
    }

    private function stage(CrmPipeline $pipeline, string $uid): CrmPipelineStage
    {
        return CrmPipelineStage::query()
            ->where('business_id', $pipeline->business_id)
            ->where('pipeline_id', $pipeline->id)
            ->where('uid', $uid)
            ->first() ?? abort(404);
    }

    private function attempt(callable $change, ?string $success = null): RedirectResponse
    {
        try {
            $result = $change();
        } catch (CrmRuleException $exception) {
            return back()->with(['status' => 'error', 'message' => $exception->getMessage()]);
        }

        return back()->with(['status' => 'success', 'message' => $success ?? (string) $result]);
    }
}
