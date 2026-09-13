<?php

namespace App\Library\Crm;

use App\Enums\Crm\CrmOpportunityStatus;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Library\Crm\Templates\BusinessTemplateApplier;
use App\Library\Crm\Templates\BusinessTemplateRegistry;
use App\Models\Business;
use App\Models\CrmOpportunity;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Support\Facades\DB;

/**
 * A Business customising its own pipelines — after, and independently of, the
 * template they were copied from.
 *
 * THE ONE FIXED POINT. A pipeline's `new_inquiry` stage may be renamed, but it
 * always stays first and can never be archived: it is where every opportunity
 * starts, and what later automations and forms address. Everything else — adding,
 * renaming, reordering and archiving stages — is the Business's to decide.
 *
 * ARCHIVING IS THE SAFE REMOVAL. A stage is never hard-deleted. Its open deals
 * must be moved to another active stage of the same pipeline first (each move is
 * a normal, recorded, announced stage change); its won and lost deals stay where
 * they closed, so their history and reports stay true.
 *
 * Every stage change locks the pipeline row, so two customers reordering or
 * archiving the same pipeline at once are applied one after the other.
 */
class CrmPipelineService
{
    public const NAME_MAX = 100;

    public function __construct(
        private readonly BusinessTemplateRegistry $templates,
        private readonly BusinessTemplateApplier $applier,
        private readonly CrmOpportunityService $opportunities,
    ) {
    }

    /**
     * The Business's first pipeline, copied from the standard template. Safe to
     * call twice: the template is applied once.
     */
    public function setUpStandardPipeline(Business $business, ?int $actorUserId = null): CrmPipeline
    {
        $this->applier->applyPipelines($business, $this->templates->generic(), $actorUserId);

        return CrmPipeline::query()->forBusiness($business)->active()->orderBy('position')->orderBy('id')->firstOrFail();
    }

    /** "+ New pipeline": another copy of the standard stages, under its own name. */
    public function createPipeline(Business $business, string $name, ?int $actorUserId = null): CrmPipeline
    {
        $template = $this->templates->generic();

        return $this->applier->copyPipeline($business, $template, $template->pipelines[0], $this->name($name), $actorUserId);
    }

    public function renamePipeline(CrmPipeline $pipeline, string $name): void
    {
        $pipeline->forceFill(['name' => $this->name($name)])->save();
    }

    public function addStage(CrmPipeline $pipeline, string $name): CrmPipelineStage
    {
        $name = $this->name($name);

        return DB::transaction(function () use ($pipeline, $name): CrmPipelineStage {
            $this->lockPipeline($pipeline);

            $last = CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->max('position');

            return CrmPipelineStage::create([
                'business_id' => $pipeline->business_id,
                'pipeline_id' => $pipeline->id,
                'name' => $name,
                'semantic_key' => null,
                'position' => $last === null ? 0 : (int) $last + 1,
            ]);
        });
    }

    public function renameStage(CrmPipelineStage $stage, string $name): void
    {
        $stage->forceFill(['name' => $this->name($name)])->save();
    }

    /** One step earlier (-1) or later (+1) among the pipeline's active stages. */
    public function moveStage(CrmPipelineStage $stage, int $offset): void
    {
        DB::transaction(function () use ($stage, $offset): void {
            $pipeline = $this->lockPipeline(CrmPipeline::query()->findOrFail($stage->pipeline_id));
            $order = $this->activeStages($pipeline)->pluck('uid')->all();
            $from = array_search($stage->uid, $order, true);

            if ($from === false) {
                throw new CrmRuleException('That stage is archived.');
            }

            $to = max(0, min(count($order) - 1, $from + $offset));

            if ($to === $from) {
                return;
            }

            [$order[$from], $order[$to]] = [$order[$to], $order[$from]];

            $this->applyOrder($pipeline, $order);
        });
    }

    /**
     * Put the pipeline's active stages in exactly this order.
     *
     * @param  list<string>  $stageUids every active stage uid, once
     */
    public function reorderStages(CrmPipeline $pipeline, array $stageUids): void
    {
        DB::transaction(function () use ($pipeline, $stageUids): void {
            $this->applyOrder($this->lockPipeline($pipeline), array_values($stageUids));
        });
    }

    /**
     * Archive a stage, first moving its open deals to `$destination`.
     *
     * @return int how many open deals were moved
     */
    public function archiveStage(CrmPipelineStage $stage, ?CrmPipelineStage $destination = null, ?int $actorUserId = null): int
    {
        return DB::transaction(function () use ($stage, $destination, $actorUserId): int {
            $pipeline = $this->lockPipeline(CrmPipeline::query()->findOrFail($stage->pipeline_id));
            $stage = CrmPipelineStage::query()->whereKey($stage->id)->firstOrFail();

            if ($stage->isNewInquiry()) {
                throw new CrmRuleException('"' . $stage->name . '" is where every opportunity starts, so it can’t be archived. You can rename it.');
            }

            if ($stage->isArchived()) {
                throw new CrmRuleException('That stage is already archived.');
            }

            if ($this->activeStages($pipeline)->count() <= 1) {
                throw new CrmRuleException('A pipeline needs at least one stage.');
            }

            $openDeals = CrmOpportunity::query()
                ->where('stage_id', $stage->id)
                ->where('status', CrmOpportunityStatus::Open->value);

            $count = (clone $openDeals)->count();

            if ($count > 0) {
                if ($destination === null) {
                    throw new CrmRuleException('Choose where the ' . $count . ' open ' . ($count === 1 ? 'opportunity' : 'opportunities') . ' in "' . $stage->name . '" should go.');
                }

                if ((int) $destination->pipeline_id !== (int) $pipeline->id || $destination->is($stage) || $destination->isArchived()) {
                    throw new CrmRuleException('Choose another active stage of this pipeline.');
                }

                // By id, so deals leaving the stage as they move never shift the next chunk.
                $openDeals->chunkById(200, function ($deals) use ($destination, $actorUserId): void {
                    foreach ($deals as $deal) {
                        $this->opportunities->moveToStage($deal, $destination, $actorUserId);
                    }
                });
            }

            $stage->forceFill(['archived_at' => now()])->save();

            return $count;
        });
    }

    /**
     * @param  list<string>  $order
     */
    private function applyOrder(CrmPipeline $pipeline, array $order): void
    {
        $stages = $this->activeStages($pipeline)->keyBy('uid');

        if (count($order) !== $stages->count() || count(array_unique($order)) !== count($order) || array_diff($order, $stages->keys()->all()) !== []) {
            throw new CrmRuleException('The stage order must list every active stage of this pipeline once.');
        }

        $newInquiry = $stages->first(fn (CrmPipelineStage $stage) => $stage->isNewInquiry());

        if ($newInquiry !== null && $order[0] !== $newInquiry->uid) {
            throw new CrmRuleException('"' . $newInquiry->name . '" always comes first.');
        }

        foreach ($order as $position => $uid) {
            $stage = $stages[$uid];

            if ($stage->position !== $position) {
                $stage->forceFill(['position' => $position])->save();
            }
        }
    }

    private function activeStages(CrmPipeline $pipeline)
    {
        return CrmPipelineStage::query()
            ->where('pipeline_id', $pipeline->id)
            ->whereNull('archived_at')
            ->orderBy('position')
            ->orderBy('id')
            ->get();
    }

    private function lockPipeline(CrmPipeline $pipeline): CrmPipeline
    {
        return CrmPipeline::query()->whereKey($pipeline->id)->lockForUpdate()->firstOrFail();
    }

    private function name(string $name): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX) {
            throw new CrmRuleException('Use a name of 1 to ' . self::NAME_MAX . ' characters.');
        }

        return $name;
    }
}
