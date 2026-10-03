<?php

namespace App\Library\Automation\Workflow\Executors;

use App\Enums\Automation\Workflow\WorkflowNodeType;
use App\Enums\Crm\CrmOpportunityStatus;
use App\Library\Automation\Workflow\Contracts\NodeExecutionOutcome;
use App\Library\Automation\Workflow\Contracts\NodeExecutor;
use App\Library\Automation\Workflow\Runtime\ClaimedStepRun;
use App\Library\Automation\Workflow\Runtime\PinnedRunLocation;
use App\Library\Automation\Workflow\Runtime\TriggerFactResolver;
use App\Library\Automation\Workflow\Triggers\TriggerCause;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowNode;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\CrmPipelineStage;
use Throwable;

/**
 * Automations V2 — the Move opportunity action.
 *
 * THE CRM'S OWN TRANSITION. A stage change is CrmOpportunityService::moveToStage():
 * it locks the deal, refuses a foreign or archived stage and a closed deal, writes
 * the history row and emits the after-commit event — and reports a deal already in
 * the target stage as "nothing changed". This executor never writes a deal column
 * itself, so a replay of the step moves nothing and announces nothing twice.
 *
 * WHICH DEAL. The step is configured with a pipeline and a stage; the DEAL is the
 * journey's own, resolved safely at run time and never guessed:
 *
 *   1. the deal the triggering fact names (the deal that moved, that a form
 *      submission, document or appointment was linked to), when it is in the chosen
 *      pipeline and still open;
 *   2. otherwise the Contact's open deals in the chosen pipeline — exactly one, or
 *      the step stops with a bounded reason (`opportunity_not_found`,
 *      `opportunity_ambiguous`) instead of moving whichever came first.
 *
 * TENANCY AND LOCATION. Every read is scoped to the journey's Business, and the
 * stage must belong to that Business, to the chosen pipeline and be active — the
 * config is re-proved here because a pinned version outlives whatever was true at
 * publish. A deal that belongs to ANOTHER Location than the journey's pinned one is
 * never moved (`resource_outside_workflow_location`); a deal with no Location of its
 * own is Business-level and is not refused. The Contact must still be at a
 * Location-bound journey's Location.
 *
 * LOOP PREVENTION. The move tells the CRM it was made by this step
 * (`origin = automation_step_run:{id}`), so the stage-changed event it emits can
 * never re-trigger this workflow, and any other workflow it does trigger runs one
 * link deeper.
 *
 * Side-effect class IdempotentDatabase: re-running is safe.
 */
class MoveOpportunityNodeExecutor implements NodeExecutor
{
    public function __construct(
        private readonly CrmOpportunityService $opportunities,
        private readonly TriggerFactResolver $facts,
    ) {
    }

    public function handles(): WorkflowNodeType
    {
        return WorkflowNodeType::MoveOpportunity;
    }

    public function execute(
        AutomationWorkflowNode $node,
        AutomationEnrollment $enrollment,
        Business $business,
        Contacts $contact,
    ): NodeExecutionOutcome {
        $config = is_array($node->config) ? $node->config : [];
        $pipelineId = (int) ($config['pipeline_id'] ?? 0);
        $stageId = (int) ($config['stage_id'] ?? 0);

        if ($pipelineId <= 0 || $stageId <= 0) {
            return NodeExecutionOutcome::skipped('move_config_invalid');
        }

        $violation = PinnedRunLocation::violation($enrollment, $contact);

        if ($violation !== null) {
            return NodeExecutionOutcome::skipped($violation);
        }

        // The stage, proved against THIS Business and the chosen pipeline now.
        $stage = CrmPipelineStage::query()
            ->where('business_id', (int) $business->id)
            ->where('pipeline_id', $pipelineId)
            ->whereKey($stageId)
            ->first();

        if ($stage === null || $stage->archived_at !== null) {
            return NodeExecutionOutcome::failed('stage_unavailable');
        }

        $candidates = $this->candidates($enrollment, $business, $contact, $pipelineId);

        if ($candidates->isEmpty()) {
            return NodeExecutionOutcome::skipped('opportunity_not_found');
        }

        // A deal in another Location is never moved by a journey pinned elsewhere.
        $allowed = $candidates->filter(fn (CrmOpportunity $deal): bool => PinnedRunLocation::resourceViolation(
            $enrollment,
            $deal->location_id === null ? null : (int) $deal->location_id,
        ) === null);

        if ($allowed->isEmpty()) {
            return NodeExecutionOutcome::skipped(PinnedRunLocation::RESOURCE_OUTSIDE);
        }

        if ($allowed->count() > 1) {
            return NodeExecutionOutcome::skipped('opportunity_ambiguous');
        }

        /** @var CrmOpportunity $deal */
        $deal = $allowed->first();
        $stepRunId = ClaimedStepRun::idFor($node, $enrollment);

        try {
            $moved = $this->opportunities->moveToStage(
                $deal,
                $stage,
                null,
                $stepRunId === null ? null : TriggerCause::originFor($stepRunId),
            );
        } catch (CrmRuleException) {
            return NodeExecutionOutcome::failed('move_refused');
        } catch (Throwable $exception) {
            return NodeExecutionOutcome::failed('move_exception: ' . class_basename($exception));
        }

        return NodeExecutionOutcome::succeeded($moved ? 'Moved to ' . mb_substr((string) $stage->name, 0, 80) : 'Already in that stage');
    }

    /**
     * The open deals this step may consider, inside the Business and the chosen
     * pipeline: the fact's own deal when it qualifies, else the Contact's.
     *
     * @return \Illuminate\Support\Collection<int, CrmOpportunity>
     */
    private function candidates(AutomationEnrollment $enrollment, Business $business, Contacts $contact, int $pipelineId): \Illuminate\Support\Collection
    {
        $base = fn () => CrmOpportunity::query()
            ->where('business_id', (int) $business->id)
            ->where('pipeline_id', $pipelineId)
            ->where('status', CrmOpportunityStatus::Open->value);

        $factDealId = $this->facts->forEnrollment($enrollment)->opportunityId;

        if ($factDealId !== null) {
            $fact = $base()->whereKey($factDealId)->where('contact_id', (int) $contact->id)->get();

            if ($fact->isNotEmpty()) {
                return $fact;
            }
        }

        return $base()->where('contact_id', (int) $contact->id)->orderBy('id')->limit(5)->get();
    }
}
