<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Crm\CrmOpportunityHistoryEvent;
use App\Events\Crm\CrmOpportunityEvent;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Contracts\TriggerSource;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * Automations V2 — CRM sales opportunity triggers: "Opportunity created",
 * "Opportunity moves stage", "Opportunity marked won", "Opportunity marked lost".
 *
 * THE CRM DOES NOT CALL AUTOMATIONS. CrmOpportunityService emits its own
 * after-commit domain events (App\Events\Crm\*) and knows nothing about
 * workflows; a queued listener hands each event here. One class serves the four
 * trigger types — it is registered once per type, so the trigger-source
 * registry and the trigger enum still agree one-to-one.
 *
 * WHAT IS TRUSTED. The event is ids only, and a listener may run late or twice,
 * so nothing in it is taken on faith: the change is re-read from the CRM's own
 * `crm_opportunity_history` row, joined to its deal, filtered on the event's
 * Business, and must be the kind of change the event names. The contact is the
 * deal's own, and must still be a contact of that Business. A deal with no
 * contact enrolls nobody — a journey is always a contact's.
 *
 * THE OCCURRENCE KEY is the history row (`crm_opportunity_history:{id}`), the
 * CRM's own deterministic key, so a replayed event or a retried job composes the
 * same key and EnrollmentService refuses the duplicate. Paused, archived and
 * unpublished workflows are never listening, and EnrollmentService re-checks
 * that at the door.
 *
 * AI COO / Advisor recommendations (App\Models\Opportunity, `opportunities`)
 * are a different domain and never reach this class.
 */
class CrmOpportunityTriggerSource implements TriggerSource
{
    public const SKIPPED_NO_CHANGE = 'no_matching_change';
    public const SKIPPED_NO_CONTACT = 'no_contact';
    public const SKIPPED_NOT_ENROLLED = 'not_enrolled';

    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly WorkflowTriggerType $triggerType,
    ) {
        if (! $triggerType->isCrmOpportunity()) {
            throw new \InvalidArgumentException('A CRM opportunity trigger source serves only CRM opportunity triggers.');
        }
    }

    public function triggerType(): WorkflowTriggerType
    {
        return $this->triggerType;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Handle one CRM opportunity event.
     *
     * @return array{enrolled: int, skipped: array<string, int>}
     */
    public function handle(CrmOpportunityEvent $event): array
    {
        $result = ['enrolled' => 0, 'skipped' => []];

        $context = $this->contextFor($event->businessId, $event->historyId);

        // The event must describe the change its history row recorded: the same
        // deal, and the kind of change this source's trigger listens for.
        if ($context === null
            || $context->triggerType !== $this->triggerType
            || WorkflowTriggerType::tryFrom($event->name()) !== $this->triggerType
            || $context->opportunityId !== $event->opportunityId) {
            return $this->skip($result, self::SKIPPED_NO_CHANGE);
        }

        $contact = $context->contactId === null
            ? null
            : Contacts::query()->where('business_id', $context->businessId)->whereKey($context->contactId)->first();

        if ($contact === null) {
            return $this->skip($result, self::SKIPPED_NO_CONTACT);
        }

        foreach ($this->listeningWorkflows($context) as $workflow) {
            $enrollment = $this->enrollments->enroll($workflow, $contact, $context->occurrenceKey());

            // Null is EnrollmentService's own refusal: the same change replayed,
            // the contact still part-way through, the workflow paused since.
            if ($enrollment === null) {
                $result = $this->skip($result, self::SKIPPED_NOT_ENROLLED);

                continue;
            }

            $result['enrolled']++;
            AdvanceWorkflowEnrollment::dispatch((int) $enrollment->getKey());
        }

        return $result;
    }

    /**
     * The trigger facts a journey was started with, read back from its
     * enrollment — the same history row, so the same answer as when it fired.
     * Null for an enrollment another trigger started, or one whose change no
     * longer resolves inside its Business.
     */
    public function contextForEnrollment(AutomationEnrollment $enrollment): ?CrmOpportunityTriggerContext
    {
        $key = (string) $enrollment->trigger_occurrence_key;

        if ($enrollment->trigger_type !== $this->triggerType || ! str_starts_with($key, CrmOpportunityTriggerContext::OCCURRENCE_PREFIX)) {
            return null;
        }

        $historyId = substr($key, strlen(CrmOpportunityTriggerContext::OCCURRENCE_PREFIX));

        if (! ctype_digit($historyId)) {
            return null;
        }

        $context = $this->contextFor((int) $enrollment->business_id, (int) $historyId);

        return $context !== null && $context->triggerType === $this->triggerType ? $context : null;
    }

    /**
     * One CRM change, as its history row recorded it, inside one Business.
     *
     * One statement: the history row joined to its deal, both filtered on the
     * Business. A row of another Business, or a history id that names nothing,
     * reads as no change at all.
     */
    private function contextFor(int $businessId, int $historyId): ?CrmOpportunityTriggerContext
    {
        $row = DB::table('crm_opportunity_history as h')
            ->join('crm_opportunities as o', function ($join): void {
                $join->on('o.id', '=', 'h.opportunity_id')->on('o.business_id', '=', 'h.business_id');
            })
            ->where('h.id', $historyId)
            ->where('h.business_id', $businessId)
            ->first([
                'h.id', 'h.business_id', 'h.opportunity_id', 'h.event', 'h.from_stage_id', 'h.to_stage_id',
                'h.to_value', 'o.pipeline_id', 'o.contact_id',
            ]);

        if ($row === null) {
            return null;
        }

        $event = CrmOpportunityHistoryEvent::tryFrom((string) $row->event);
        $from = $row->from_stage_id === null ? null : (int) $row->from_stage_id;
        $to = $row->to_stage_id === null ? null : (int) $row->to_stage_id;

        $triggerType = match (true) {
            $event === CrmOpportunityHistoryEvent::Created => WorkflowTriggerType::OpportunityCreated,
            $event === CrmOpportunityHistoryEvent::StageChanged => WorkflowTriggerType::OpportunityStageChanged,
            // Reopening a deal whose stage was archived lands it in another stage,
            // and the CRM announces that as a stage change.
            $event === CrmOpportunityHistoryEvent::Reopened && $from !== null && $to !== null => WorkflowTriggerType::OpportunityStageChanged,
            $event === CrmOpportunityHistoryEvent::Won => WorkflowTriggerType::OpportunityWon,
            $event === CrmOpportunityHistoryEvent::Lost => WorkflowTriggerType::OpportunityLost,
            default => null,
        };

        if ($triggerType === null) {
            return null;
        }

        $isMove = $triggerType === WorkflowTriggerType::OpportunityStageChanged;
        $isOutcome = in_array($triggerType, [WorkflowTriggerType::OpportunityWon, WorkflowTriggerType::OpportunityLost], true);

        return new CrmOpportunityTriggerContext(
            triggerType: $triggerType,
            businessId: (int) $row->business_id,
            historyId: (int) $row->id,
            opportunityId: (int) $row->opportunity_id,
            contactId: $row->contact_id === null ? null : (int) $row->contact_id,
            pipelineId: (int) $row->pipeline_id,
            // Created records the stage it arrived in as `to`; a move arrives there too.
            stageId: $isOutcome ? null : $to,
            fromStageId: $isMove ? $from : null,
            toStageId: $isMove ? $to : null,
            outcome: $isOutcome ? (string) $row->to_value : null,
        );
    }

    /**
     * The published workflows of this Business whose pinned trigger is this
     * source's, and whose optional filters admit this change.
     *
     * One query for the candidates (workflow + its compiled trigger node's
     * config), one to load the matching models — bounded by the Business's own
     * published-workflow limit, exactly as the contact-created source does.
     *
     * @return iterable<AutomationWorkflow>
     */
    private function listeningWorkflows(CrmOpportunityTriggerContext $context): iterable
    {
        $candidates = DB::table('automation_workflows as w')
            ->join('automation_workflow_versions as v', 'v.id', '=', 'w.published_version_id')
            ->join('automation_workflow_nodes as n', function ($join): void {
                $join->on('n.version_id', '=', 'v.id')->where('n.node_type', '=', 'trigger');
            })
            ->where('w.business_id', $context->businessId)
            ->where('w.status', WorkflowStatus::Published->value)
            ->where('v.trigger_type', $this->triggerType->value)
            ->orderBy('w.id')
            ->limit(WorkflowLimits::MAX_PUBLISHED_WORKFLOWS_PER_BUSINESS)
            ->get(['w.id as workflow_id', 'n.config as trigger_config']);

        $matching = [];

        foreach ($candidates as $candidate) {
            if ($this->triggerAdmits($candidate->trigger_config, $context)) {
                $matching[] = (int) $candidate->workflow_id;
            }
        }

        if ($matching === []) {
            return [];
        }

        return AutomationWorkflow::query()
            ->whereIn('id', $matching)
            ->where('business_id', $context->businessId)
            ->orderBy('id')
            ->get();
    }

    /**
     * "Opportunity moves stage" may narrow to a pipeline, a stage left and a
     * stage entered, each optional. The other three triggers have no filter.
     *
     * @param string|array<string, mixed>|null $rawConfig the compiled trigger node's config
     */
    private function triggerAdmits(string|array|null $rawConfig, CrmOpportunityTriggerContext $context): bool
    {
        $config = is_string($rawConfig) ? json_decode($rawConfig, true) : $rawConfig;

        if (! is_array($config)) {
            // A trigger we cannot read is a workflow that does not fire.
            return false;
        }

        if ($this->triggerType !== WorkflowTriggerType::OpportunityStageChanged) {
            return true;
        }

        foreach ([
            'pipeline_id' => $context->pipelineId,
            'from_stage_id' => $context->fromStageId,
            'to_stage_id' => $context->toStageId,
        ] as $key => $actual) {
            $wanted = $config[$key] ?? null;

            if ($wanted !== null && $wanted !== '' && (int) $wanted !== $actual) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{enrolled: int, skipped: array<string, int>} $result
     * @return array{enrolled: int, skipped: array<string, int>}
     */
    private function skip(array $result, string $reason): array
    {
        $result['skipped'][$reason] = ($result['skipped'][$reason] ?? 0) + 1;

        return $result;
    }
}
