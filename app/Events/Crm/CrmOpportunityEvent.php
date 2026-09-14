<?php

namespace App\Events\Crm;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The canonical CRM opportunity facts later automations listen to.
 *
 * DELIBERATELY NOT App\Events\Opportunity\*. Those belong to the AI COO /
 * Business Advisor recommendation engine. These are sales-deal facts, and their
 * canonical names say so: `opportunity_created`, `opportunity_stage_changed`,
 * `opportunity_won`, `opportunity_lost` (the NAME constant on each subclass).
 *
 * THE SAME SHAPE AUTOMATIONS V2 ALREADY CONSUMES (compare
 * App\Events\Conversation\InboundMessageReceived):
 *
 *   AFTER COMMIT. A deal created or moved inside a transaction that rolls back
 *   produces no event, so nothing downstream can act on a change that never
 *   happened.
 *
 *   BUSINESS-SCOPED, EXPLICITLY. `businessId` is the deal's own Business, taken
 *   from the row, never inferred. The contact is the deal's own contact (and
 *   null if it has since been deleted: such an event enrolls nobody).
 *
 *   IDS ONLY. A listener re-reads the deal, so a stage renamed or a deal moved
 *   again between the change and a queued job is seen as it is when the work
 *   runs. The stage semantic keys are carried as values because they are the
 *   stable facts a trigger filter would match on ("entered new_inquiry").
 *
 *   A DETERMINISTIC OCCURRENCE KEY, built server-side from the persisted row
 *   that recorded the change (`crm_opportunity_history.id`), so a replayed event
 *   composes the same key and V2's EnrollmentService refuses the duplicate.
 *
 * Automations V2 consumes them without this domain knowing: WorkflowTriggerType
 * declares one trigger per NAME, and EnrollFromCrmOpportunityEvent hands each
 * event to CrmOpportunityTriggerSource, which calls EnrollmentService — exactly
 * how V2-F wired `message_received`. Nothing here calls Automations.
 */
abstract class CrmOpportunityEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public const NAME = '';

    public function __construct(
        public readonly int $businessId,
        public readonly int $opportunityId,
        public readonly ?int $contactId,
        public readonly int $pipelineId,
        public readonly int $stageId,
        public readonly ?string $stageSemanticKey,
        public readonly int $historyId,
        public readonly ?int $actorUserId,
    ) {
    }

    /** The canonical event name, e.g. `opportunity_won`. */
    public function name(): string
    {
        return static::NAME;
    }

    /**
     * `crm_opportunity_history:{id}` — one real-world change, one key, however
     * many times the event is replayed.
     */
    public function occurrenceKey(): string
    {
        return 'crm_opportunity_history:' . $this->historyId;
    }
}
