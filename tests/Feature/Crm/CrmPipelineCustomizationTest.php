<?php

namespace Tests\Feature\Crm;

use App\Events\Crm\CrmOpportunityStageChanged;
use App\Library\Crm\CrmBoard;
use App\Library\Crm\CrmBoardFilters;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Models\CrmOpportunityHistory;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * A Business customising its own pipeline after it was copied from the template.
 * The one fixed point: New inquiry can be renamed, but stays first and is never
 * archived.
 */
class CrmPipelineCustomizationTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    public function test_add_rename_and_the_semantic_key_survives_a_rename(): void
    {
        [, $business] = $this->crmTenant();
        $service = app(CrmPipelineService::class);
        $pipeline = $this->standardPipeline($business);

        $added = $service->addStage($pipeline, '  Site visit booked ');
        $service->renameStage($this->stageKeyed($pipeline, 'new_inquiry'), 'Fresh leads');

        $this->assertSame(['Fresh leads', 'Qualified', 'Proposal sent', 'Negotiating', 'Site visit booked'], $this->order($pipeline));
        $this->assertNull($added->semantic_key);
        $this->assertSame($business->id, $added->business_id);

        // Still where new deals start, whatever it is called now.
        $start = app(CrmOpportunityService::class)->startingStage($pipeline);
        $this->assertSame(['new_inquiry', 'Fresh leads'], [$start->semantic_key, $start->name]);
        $this->assertSame('new_inquiry', $this->deal($business, $pipeline)->stage->semantic_key);

        $this->assertRefusedWithoutChange(fn () => $service->renameStage($added, '   '), $pipeline);
    }

    public function test_reorder_keeps_new_inquiry_first_and_must_list_every_active_stage(): void
    {
        [, $business] = $this->crmTenant();
        $service = app(CrmPipelineService::class);
        $pipeline = $this->standardPipeline($business);
        $uids = fn (string ...$keys) => array_map(fn ($key) => $this->stageKeyed($pipeline, $key)->uid, $keys);

        $service->reorderStages($pipeline, $uids('new_inquiry', 'proposal_sent', 'qualified', 'negotiating'));
        $this->assertSame(['New inquiry', 'Proposal sent', 'Qualified', 'Negotiating'], $this->order($pipeline));

        $this->assertRefusedWithoutChange(fn () => $service->reorderStages($pipeline, $uids('qualified', 'new_inquiry', 'proposal_sent', 'negotiating')), $pipeline);
        $this->assertRefusedWithoutChange(fn () => $service->reorderStages($pipeline, $uids('new_inquiry', 'qualified')), $pipeline);

        $service->moveStage($this->stageKeyed($pipeline, 'negotiating'), -1);
        $this->assertSame(['New inquiry', 'Proposal sent', 'Negotiating', 'Qualified'], $this->order($pipeline));

        $this->assertRefusedWithoutChange(fn () => $service->moveStage($this->stageKeyed($pipeline, 'proposal_sent'), -1), $pipeline);
    }

    public function test_new_inquiry_can_never_be_archived(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);

        $this->assertRefusedWithoutChange(fn () => app(CrmPipelineService::class)->archiveStage($this->stageKeyed($pipeline, 'new_inquiry'), $this->stageKeyed($pipeline, 'qualified')), $pipeline);
        $this->assertNull($this->stageKeyed($pipeline, 'new_inquiry')->archived_at);
    }

    public function test_archiving_a_stage_with_open_deals_requires_a_destination_and_moves_them_there(): void
    {
        [, $business] = $this->crmTenant();
        $service = app(CrmPipelineService::class);
        $deals = app(CrmOpportunityService::class);
        $pipeline = $this->standardPipeline($business);
        $qualified = $this->stageKeyed($pipeline, 'qualified');
        $proposal = $this->stageKeyed($pipeline, 'proposal_sent');

        $open = [$this->deal($business, $pipeline, title: 'Open A', stage: $qualified), $this->deal($business, $pipeline, title: 'Open B', stage: $qualified)];
        $closed = $this->deal($business, $pipeline, title: 'Won here', stage: $qualified);
        $deals->markWon($closed);

        // No destination: refused, nothing moved or archived.
        $this->assertRefusedWithoutChange(fn () => $service->archiveStage($qualified), $pipeline);
        $this->assertNull($qualified->fresh()->archived_at);

        // A stage of another pipeline is not a destination.
        $other = app(CrmPipelineService::class)->createPipeline($business, 'Weddings');
        $this->assertRefusedWithoutChange(fn () => $service->archiveStage($qualified, $this->stageKeyed($other, 'qualified')), $pipeline);

        Event::fake([CrmOpportunityStageChanged::class]);

        $this->assertSame(2, $service->archiveStage($qualified, $proposal));

        $this->assertNotNull($qualified->fresh()->archived_at);
        foreach ($open as $deal) {
            $this->assertSame($proposal->id, $deal->fresh()->stage_id);
            $this->assertSame(1, CrmOpportunityHistory::query()->where('opportunity_id', $deal->id)->where('event', 'stage_changed')->count());
        }
        Event::assertDispatchedTimes(CrmOpportunityStageChanged::class, 2);

        // The won deal keeps the stage it closed in.
        $this->assertSame($qualified->id, $closed->fresh()->stage_id);

        // The archived stage is gone from the board's columns and from new deals.
        $board = app(CrmBoard::class)->board($business, $pipeline, new CrmBoardFilters());
        $this->assertSame(['New inquiry', 'Proposal sent', 'Negotiating'], array_column($board['columns'], 'name'));
        $this->assertRefusedWithoutChange(fn () => app(CrmOpportunityService::class)->create($business, $pipeline, $this->crmContact($business), 'Into archive', null, $qualified->fresh()), $pipeline);

        // Closed deals in archived stages still surface when asked for.
        $wonBoard = app(CrmBoard::class)->board($business, $pipeline, new CrmBoardFilters(status: 'won'));
        $archived = collect($wonBoard['columns'])->firstWhere('key', CrmBoard::ARCHIVED_COLUMN);
        $this->assertSame(['Won here'], array_column($archived['cards'], 'title'));

        // Reopening it lands at the start of the pipeline, never in a hidden column.
        $deals->reopen($closed->fresh());
        $this->assertSame('new_inquiry', $closed->fresh()->stage->semantic_key);
        Event::assertDispatchedTimes(CrmOpportunityStageChanged::class, 3);
    }

    public function test_an_empty_stage_archives_without_a_destination(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);

        $this->assertSame(0, app(CrmPipelineService::class)->archiveStage($this->stageKeyed($pipeline, 'negotiating')));
        $this->assertSame(['New inquiry', 'Qualified', 'Proposal sent'], $this->order($pipeline));
    }

    /** @return list<string> active stage names in order */
    private function order(CrmPipeline $pipeline): array
    {
        return CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->whereNull('archived_at')->orderBy('position')->orderBy('id')->pluck('name')->all();
    }

    private function assertRefusedWithoutChange(callable $change, CrmPipeline $pipeline): void
    {
        $before = CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('id')->get(['name', 'position', 'archived_at'])->toArray();

        try {
            $change();
            $this->fail('The change should have been refused.');
        } catch (CrmRuleException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame($before, CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('id')->get(['name', 'position', 'archived_at'])->toArray());
    }
}
