<?php

namespace App\Library\Crm;

use App\Enums\Crm\CrmContactStatus;
use App\Enums\Crm\CrmContactStatusSource;
use App\Enums\Crm\CrmOpportunityHistoryEvent;
use App\Enums\Crm\CrmOpportunityStatus;
use App\Enums\Crm\CrmStageSemanticKey;
use App\Events\Crm\CrmOpportunityCreated;
use App\Events\Crm\CrmOpportunityLost;
use App\Events\Crm\CrmOpportunityStageChanged;
use App\Events\Crm\CrmOpportunityWon;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Models\Business;
use App\Models\Contacts;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityHistory;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * The one writer of CRM opportunities.
 *
 * Every change runs in a transaction that locks the deal, writes the deal, writes
 * its history row, and raises its canonical event — and because the events are
 * ShouldDispatchAfterCommit, a change that rolls back leaves no history and
 * raises nothing. Keeping all three in one place is what keeps them in step.
 *
 * TENANCY IS RE-CHECKED HERE, not assumed. Controllers only ever look records up
 * inside the resolved Business, but a pipeline, stage or contact of another
 * Business reaching this class is refused rather than attached.
 *
 * A CONTACT IS NOT AUTOMATICALLY A LEAD: nothing here reacts to a contact being
 * created. A deal exists because create() was called for it.
 */
class CrmOpportunityService
{
    public const TITLE_MAX = 150;

    public function create(
        Business $business,
        CrmPipeline $pipeline,
        Contacts $contact,
        string $title,
        ?int $valueMinor = null,
        ?CrmPipelineStage $stage = null,
        ?int $actorUserId = null,
        string $source = CrmOpportunity::SOURCE_MANUAL,
    ): CrmOpportunity {
        $title = $this->title($title);

        if ((int) $pipeline->business_id !== (int) $business->id || (int) $contact->business_id !== (int) $business->id) {
            throw new CrmRuleException('That pipeline or contact is not part of this Business.');
        }

        if ($pipeline->archived_at !== null) {
            throw new CrmRuleException('That pipeline is archived.');
        }

        $stage ??= $this->startingStage($pipeline);
        $this->assertUsableStage($pipeline, $stage);

        if ($valueMinor !== null && $valueMinor < 0) {
            throw new CrmRuleException('The value cannot be negative.');
        }

        return DB::transaction(function () use ($business, $pipeline, $contact, $title, $valueMinor, $stage, $actorUserId, $source): CrmOpportunity {
            $now = now();

            $opportunity = CrmOpportunity::create([
                'business_id' => $business->id,
                'location_id' => CrmOpportunity::singleActiveLocationIdFor($business->id),
                'pipeline_id' => $pipeline->id,
                'stage_id' => $stage->id,
                'contact_id' => $contact->id,
                'title' => $title,
                'value_minor' => $valueMinor,
                'currency_code' => $business->currency_code,
                'status' => CrmOpportunityStatus::Open,
                'contact_status' => CrmContactStatus::NoContact,
                'source' => $source,
                'stage_entered_at' => $now,
                'created_by_user_id' => $actorUserId,
            ]);

            $history = $this->record($opportunity, CrmOpportunityHistoryEvent::Created, $actorUserId, to: $stage);

            CrmOpportunityCreated::dispatch(...$this->eventArguments($opportunity, $stage, $history, $actorUserId));

            return $opportunity;
        });
    }

    /**
     * @return bool false when the deal was already in that stage (nothing changed)
     */
    public function moveToStage(CrmOpportunity $opportunity, CrmPipelineStage $to, ?int $actorUserId = null): bool
    {
        return DB::transaction(function () use ($opportunity, $to, $actorUserId): bool {
            $locked = $this->lock($opportunity);

            if ((int) $to->business_id !== (int) $locked->business_id || (int) $to->pipeline_id !== (int) $locked->pipeline_id) {
                throw new CrmRuleException('That stage belongs to a different pipeline.');
            }

            if ($to->archived_at !== null) {
                throw new CrmRuleException('That stage is archived.');
            }

            if (! $locked->isOpen()) {
                throw new CrmRuleException('Reopen this opportunity before moving it.');
            }

            if ((int) $locked->stage_id === (int) $to->id) {
                return false;
            }

            $from = CrmPipelineStage::query()->findOrFail($locked->stage_id);

            $locked->forceFill(['stage_id' => $to->id, 'stage_entered_at' => now()])->save();

            $history = $this->record($locked, CrmOpportunityHistoryEvent::StageChanged, $actorUserId, from: $from, to: $to);

            CrmOpportunityStageChanged::dispatch(
                ...$this->eventArguments($locked, $to, $history, $actorUserId),
                fromStageId: $from->id,
                fromStageSemanticKey: $from->semantic_key,
            );

            $opportunity->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    public function markWon(CrmOpportunity $opportunity, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($opportunity, $actorUserId): void {
            $locked = $this->lockOpen($opportunity);

            $locked->forceFill(['status' => CrmOpportunityStatus::Won, 'won_at' => now(), 'lost_at' => null, 'lost_reason' => null])->save();

            $stage = CrmPipelineStage::query()->findOrFail($locked->stage_id);
            $history = $this->record($locked, CrmOpportunityHistoryEvent::Won, $actorUserId, fromValue: CrmOpportunityStatus::Open->value, toValue: CrmOpportunityStatus::Won->value);

            CrmOpportunityWon::dispatch(...$this->eventArguments($locked, $stage, $history, $actorUserId));

            $opportunity->setRawAttributes($locked->getAttributes(), true);
        });
    }

    public function markLost(CrmOpportunity $opportunity, ?string $reason = null, ?int $actorUserId = null): void
    {
        $reason = $reason === null ? null : mb_substr(trim($reason), 0, 255);

        DB::transaction(function () use ($opportunity, $reason, $actorUserId): void {
            $locked = $this->lockOpen($opportunity);

            $locked->forceFill(['status' => CrmOpportunityStatus::Lost, 'lost_at' => now(), 'won_at' => null, 'lost_reason' => $reason === '' ? null : $reason])->save();

            $stage = CrmPipelineStage::query()->findOrFail($locked->stage_id);
            $history = $this->record($locked, CrmOpportunityHistoryEvent::Lost, $actorUserId, fromValue: CrmOpportunityStatus::Open->value, toValue: CrmOpportunityStatus::Lost->value);

            CrmOpportunityLost::dispatch(...$this->eventArguments($locked, $stage, $history, $actorUserId));

            $opportunity->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * Back to open, in the stage it closed in — or, when that stage has since
     * been archived, at the start of its pipeline, so it is never reopened into
     * a column nobody can see. That arrival is a stage change, and is announced
     * as one.
     */
    public function reopen(CrmOpportunity $opportunity, ?int $actorUserId = null): void
    {
        DB::transaction(function () use ($opportunity, $actorUserId): void {
            $locked = $this->lock($opportunity);

            if ($locked->isOpen()) {
                throw new CrmRuleException('This opportunity is already open.');
            }

            $from = CrmPipelineStage::query()->findOrFail($locked->stage_id);
            $to = $from->archived_at === null ? $from : $this->startingStage(CrmPipeline::query()->findOrFail($locked->pipeline_id));
            $previous = $locked->status->value;

            $locked->forceFill([
                'status' => CrmOpportunityStatus::Open,
                'won_at' => null,
                'lost_at' => null,
                'lost_reason' => null,
                'stage_id' => $to->id,
                'stage_entered_at' => $to->is($from) ? $locked->stage_entered_at : now(),
            ])->save();

            $history = $this->record(
                $locked,
                CrmOpportunityHistoryEvent::Reopened,
                $actorUserId,
                from: $to->is($from) ? null : $from,
                to: $to->is($from) ? null : $to,
                fromValue: $previous,
                toValue: CrmOpportunityStatus::Open->value,
            );

            if (! $to->is($from)) {
                CrmOpportunityStageChanged::dispatch(
                    ...$this->eventArguments($locked, $to, $history, $actorUserId),
                    fromStageId: $from->id,
                    fromStageSemanticKey: $from->semantic_key,
                );
            }

            $opportunity->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * No contact / In contact. `$source` records who set it, so the later
     * automatic update from inbound conversations can respect a customer's own
     * correction.
     *
     * @return bool false when it already had that status
     */
    public function setContactStatus(CrmOpportunity $opportunity, CrmContactStatus $status, CrmContactStatusSource $source = CrmContactStatusSource::Manual, ?int $actorUserId = null): bool
    {
        return DB::transaction(function () use ($opportunity, $status, $source, $actorUserId): bool {
            $locked = $this->lock($opportunity);

            if ($locked->contact_status === $status) {
                return false;
            }

            $previous = $locked->contact_status->value;

            $locked->forceFill(['contact_status' => $status, 'contact_status_source' => $source, 'contact_status_changed_at' => now()])->save();

            $this->record($locked, CrmOpportunityHistoryEvent::ContactStatusChanged, $actorUserId, fromValue: $previous, toValue: $status->value);

            $opportunity->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    public function updateDetails(CrmOpportunity $opportunity, string $title, ?int $valueMinor): void
    {
        if ($valueMinor !== null && $valueMinor < 0) {
            throw new CrmRuleException('The value cannot be negative.');
        }

        $opportunity->forceFill(['title' => $this->title($title), 'value_minor' => $valueMinor])->save();
    }

    /** Where a new deal starts: the pipeline's New inquiry, else its first active stage. */
    public function startingStage(CrmPipeline $pipeline): CrmPipelineStage
    {
        $stages = CrmPipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        $stage = $stages->firstWhere('semantic_key', CrmStageSemanticKey::NewInquiry->value) ?? $stages->first();

        if ($stage === null) {
            throw new CrmRuleException('This pipeline has no active stages.');
        }

        return $stage;
    }

    private function assertUsableStage(CrmPipeline $pipeline, CrmPipelineStage $stage): void
    {
        if ((int) $stage->pipeline_id !== (int) $pipeline->id || (int) $stage->business_id !== (int) $pipeline->business_id) {
            throw new CrmRuleException('That stage belongs to a different pipeline.');
        }

        if ($stage->archived_at !== null) {
            throw new CrmRuleException('That stage is archived.');
        }
    }

    private function title(string $title): string
    {
        $title = trim($title);

        if ($title === '' || mb_strlen($title) > self::TITLE_MAX) {
            throw new CrmRuleException('Give the opportunity a name of up to ' . self::TITLE_MAX . ' characters.');
        }

        return $title;
    }

    private function lock(CrmOpportunity $opportunity): CrmOpportunity
    {
        return CrmOpportunity::query()->whereKey($opportunity->id)->lockForUpdate()->firstOrFail();
    }

    private function lockOpen(CrmOpportunity $opportunity): CrmOpportunity
    {
        $locked = $this->lock($opportunity);

        if (! $locked->isOpen()) {
            throw new CrmRuleException('This opportunity is already ' . strtolower($locked->status->label()) . '.');
        }

        return $locked;
    }

    private function record(
        CrmOpportunity $opportunity,
        CrmOpportunityHistoryEvent $event,
        ?int $actorUserId,
        ?CrmPipelineStage $from = null,
        ?CrmPipelineStage $to = null,
        ?string $fromValue = null,
        ?string $toValue = null,
    ): CrmOpportunityHistory {
        return CrmOpportunityHistory::create([
            'business_id' => $opportunity->business_id,
            'opportunity_id' => $opportunity->id,
            'event' => $event,
            'from_stage_id' => $from?->id,
            'to_stage_id' => $to?->id,
            'from_stage_name' => $from?->name,
            'to_stage_name' => $to?->name,
            'from_value' => $fromValue,
            'to_value' => $toValue,
            'actor_user_id' => $actorUserId,
        ]);
    }

    /**
     * @return array{businessId: int, opportunityId: int, contactId: ?int, pipelineId: int, stageId: int, stageSemanticKey: ?string, historyId: int, actorUserId: ?int}
     */
    private function eventArguments(CrmOpportunity $opportunity, CrmPipelineStage $stage, CrmOpportunityHistory $history, ?int $actorUserId): array
    {
        return [
            'businessId' => (int) $opportunity->business_id,
            'opportunityId' => (int) $opportunity->id,
            'contactId' => $opportunity->contact_id === null ? null : (int) $opportunity->contact_id,
            'pipelineId' => (int) $opportunity->pipeline_id,
            'stageId' => (int) $stage->id,
            'stageSemanticKey' => $stage->semantic_key,
            'historyId' => (int) $history->id,
            'actorUserId' => $actorUserId,
        ];
    }
}
