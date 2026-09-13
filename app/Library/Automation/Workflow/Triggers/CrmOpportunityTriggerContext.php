<?php

namespace App\Library\Automation\Workflow\Triggers;

use App\Enums\Automation\Workflow\WorkflowTriggerType;

/**
 * The facts one CRM sales-opportunity trigger hands a workflow run — identifiers
 * only, all belonging to one Business.
 *
 * Built from the persisted change itself: the `crm_opportunity_history` row the
 * CRM wrote in the same transaction as the change, joined to its deal. That row
 * is immutable, so the facts read the same when a queued job runs late, when an
 * event is replayed, and when a journey asks again weeks later from its
 * enrollment's occurrence key (`crm_opportunity_history:{id}`). No stage or
 * pipeline NAME is carried — names are mutable labels; ids are the identity.
 *
 *   created        stageId          the stage the deal was created in
 *   stage changed  fromStageId,     where it left and where it arrived (a reopen
 *                  toStageId        that lands the deal in another stage is one)
 *   won / lost     outcome          `won` or `lost`
 *
 * This is the CRM Opportunities domain (crm_*). The AI COO / Advisor
 * recommendation `opportunities` never produce one of these.
 */
final readonly class CrmOpportunityTriggerContext
{
    /** Matches App\Events\Crm\CrmOpportunityEvent::occurrenceKey(). */
    public const OCCURRENCE_PREFIX = 'crm_opportunity_history:';

    public function __construct(
        public WorkflowTriggerType $triggerType,
        public int $businessId,
        public int $historyId,
        public int $opportunityId,
        public ?int $contactId,
        public int $pipelineId,
        public ?int $stageId,
        public ?int $fromStageId,
        public ?int $toStageId,
        public ?string $outcome,
    ) {
    }

    /** The CRM's own occurrence key for this change — one change, one key. */
    public function occurrenceKey(): string
    {
        return self::OCCURRENCE_PREFIX . $this->historyId;
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'trigger_type' => $this->triggerType->value,
            'business_id' => $this->businessId,
            'history_id' => $this->historyId,
            'opportunity_id' => $this->opportunityId,
            'contact_id' => $this->contactId,
            'pipeline_id' => $this->pipelineId,
            'stage_id' => $this->stageId,
            'from_stage_id' => $this->fromStageId,
            'to_stage_id' => $this->toStageId,
            'outcome' => $this->outcome,
        ];
    }
}
