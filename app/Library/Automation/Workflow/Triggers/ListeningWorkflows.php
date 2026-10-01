<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationWorkflow;
use Illuminate\Support\Facades\DB;

/**
 * Which published workflows of ONE Business are listening for one trigger type,
 * after their own trigger-node filters have admitted the fact.
 *
 * The same two-step read CrmOpportunityTriggerSource performs for its four
 * triggers — one query for the candidates (workflow plus its compiled trigger
 * node's config), one to load the matching models — shared here by the merged
 * foundation sources (tags, forms, appointments) so it exists once for them
 * rather than three more times.
 *
 * BUSINESS IS THE ONLY SCOPE. The candidate query filters on `w.business_id`, so
 * a fact from Business A can never see a workflow of Business B. A workflow that
 * is paused, archived or has no published version is never a candidate.
 * Bounded by the Business's own published-workflow limit.
 */
class ListeningWorkflows
{
    /**
     * @param \Closure(array<string, mixed>): bool|null $admits given the trigger
     *        node's decoded config; null admits every listening workflow
     *
     * @return list<AutomationWorkflow>
     */
    public function for(int $businessId, WorkflowTriggerType $type, ?\Closure $admits = null): array
    {
        $candidates = DB::table('automation_workflows as w')
            ->join('automation_workflow_versions as v', 'v.id', '=', 'w.published_version_id')
            ->join('automation_workflow_nodes as n', function ($join): void {
                $join->on('n.version_id', '=', 'v.id')->where('n.node_type', '=', 'trigger');
            })
            ->where('w.business_id', $businessId)
            ->where('w.status', WorkflowStatus::Published->value)
            ->where('v.trigger_type', $type->value)
            ->orderBy('w.id')
            ->limit(WorkflowLimits::MAX_PUBLISHED_WORKFLOWS_PER_BUSINESS)
            ->get(['w.id as workflow_id', 'n.config as trigger_config']);

        $matching = [];

        foreach ($candidates as $candidate) {
            $config = is_string($candidate->trigger_config)
                ? json_decode($candidate->trigger_config, true)
                : $candidate->trigger_config;

            // A trigger config we cannot read is a workflow that does not fire.
            if (! is_array($config)) {
                continue;
            }

            if ($admits === null || $admits($config)) {
                $matching[] = (int) $candidate->workflow_id;
            }
        }

        if ($matching === []) {
            return [];
        }

        return AutomationWorkflow::query()
            ->whereIn('id', $matching)
            ->where('business_id', $businessId)
            ->orderBy('id')
            ->get()
            ->all();
    }
}
