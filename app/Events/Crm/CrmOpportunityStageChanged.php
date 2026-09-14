<?php

namespace App\Events\Crm;

/**
 * `opportunity_stage_changed` — a deal moved from one stage to another of the
 * same pipeline, by hand or because its stage was archived with the deal moved
 * elsewhere. `stageId`/`stageSemanticKey` are where it arrived.
 */
class CrmOpportunityStageChanged extends CrmOpportunityEvent
{
    public const NAME = 'opportunity_stage_changed';

    public function __construct(
        int $businessId,
        int $opportunityId,
        ?int $contactId,
        int $pipelineId,
        int $stageId,
        ?string $stageSemanticKey,
        int $historyId,
        ?int $actorUserId,
        public readonly int $fromStageId,
        public readonly ?string $fromStageSemanticKey,
    ) {
        parent::__construct($businessId, $opportunityId, $contactId, $pipelineId, $stageId, $stageSemanticKey, $historyId, $actorUserId);
    }
}
