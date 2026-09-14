<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\CrmContactStatus;
use App\Enums\Crm\CrmContactStatusSource;
use App\Enums\Crm\CrmOpportunityHistoryEvent;
use App\Enums\Crm\CrmOpportunityStatus;
use App\Events\Crm\CrmOpportunityCreated;
use App\Events\Crm\CrmOpportunityEvent;
use App\Events\Crm\CrmOpportunityLost;
use App\Events\Crm\CrmOpportunityStageChanged;
use App\Events\Crm\CrmOpportunityWon;
use App\Library\Crm\CrmOpportunityService;
use App\Library\Crm\CrmPipelineService;
use App\Library\Crm\Exceptions\CrmRuleException;
use App\Models\CrmOpportunity;
use App\Models\CrmOpportunityHistory;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * A CRM deal's life — create, move, win, lose, reopen, contact status — and the
 * canonical Business-scoped events later automations will listen to.
 */
class CrmOpportunityLifecycleTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    /** @var list<CrmOpportunityEvent> */
    private array $events = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([CrmOpportunityCreated::class, CrmOpportunityStageChanged::class, CrmOpportunityWon::class, CrmOpportunityLost::class] as $class) {
            Event::listen($class, function (CrmOpportunityEvent $event): void {
                $this->events[] = $event;
            });
        }
    }

    public function test_a_new_opportunity_starts_open_at_new_inquiry_with_no_contact(): void
    {
        [, $business] = $this->crmTenant();
        DB::table('businesses')->where('id', $business->id)->update(['currency_code' => 'USD']);
        $business->refresh();
        $pipeline = $this->standardPipeline($business);
        $contact = $this->crmContact($business);

        $deal = app(CrmOpportunityService::class)->create($business, $pipeline, $contact, '  Kitchen renovation  ', 125000, null, null);

        $deal->refresh();
        $this->assertSame('Kitchen renovation', $deal->title);
        $this->assertSame('new_inquiry', $deal->stage->semantic_key);
        $this->assertSame(CrmOpportunityStatus::Open, $deal->status);
        $this->assertSame(CrmContactStatus::NoContact, $deal->contact_status);
        $this->assertSame($contact->id, $deal->contact_id);
        $this->assertSame(['USD', 125000, 'manual'], [$deal->currency_code, $deal->value_minor, $deal->source]);
        $this->assertNotNull($deal->stage_entered_at);

        $history = CrmOpportunityHistory::query()->where('opportunity_id', $deal->id)->sole();
        $this->assertSame(CrmOpportunityHistoryEvent::Created, $history->event);
        $this->assertSame('New inquiry', $history->to_stage_name);

        $this->assertCount(1, $this->events);
        $event = $this->events[0];
        $this->assertInstanceOf(CrmOpportunityCreated::class, $event);
        $this->assertSame('opportunity_created', $event->name());
        $this->assertSame([$business->id, $deal->id, $contact->id, $pipeline->id, $deal->stage_id, 'new_inquiry'], [$event->businessId, $event->opportunityId, $event->contactId, $event->pipelineId, $event->stageId, $event->stageSemanticKey]);
        $this->assertSame('crm_opportunity_history:' . $history->id, $event->occurrenceKey());
    }

    public function test_the_canonical_event_names_and_after_commit_contract(): void
    {
        $this->assertSame('opportunity_created', CrmOpportunityCreated::NAME);
        $this->assertSame('opportunity_stage_changed', CrmOpportunityStageChanged::NAME);
        $this->assertSame('opportunity_won', CrmOpportunityWon::NAME);
        $this->assertSame('opportunity_lost', CrmOpportunityLost::NAME);

        foreach ([CrmOpportunityCreated::class, CrmOpportunityStageChanged::class, CrmOpportunityWon::class, CrmOpportunityLost::class] as $class) {
            $this->assertTrue(is_subclass_of($class, ShouldDispatchAfterCommit::class), $class . ' must dispatch after commit.');
            $this->assertStringStartsWith('App\\Events\\Crm\\', $class, 'Never the AI COO App\\Events\\Opportunity namespace.');
        }
    }

    public function test_a_change_that_rolls_back_leaves_no_row_no_history_and_raises_nothing(): void
    {
        [, $business] = $this->crmTenant();
        $pipeline = $this->standardPipeline($business);
        $contact = $this->crmContact($business);

        try {
            DB::transaction(function () use ($business, $pipeline, $contact): void {
                app(CrmOpportunityService::class)->create($business, $pipeline, $contact, 'Rolled back');
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
        }

        $this->assertSame(0, CrmOpportunity::query()->count());
        $this->assertSame(0, CrmOpportunityHistory::query()->count());
        $this->assertSame([], $this->events);
    }

    public function test_moving_records_history_with_stage_names_at_the_time_and_announces_the_change(): void
    {
        [, $business] = $this->crmTenant();
        $deal = $this->deal($business);
        $pipeline = $deal->pipeline;
        $proposal = $this->stageKeyed($pipeline, 'proposal_sent');
        $this->events = [];

        $this->assertTrue(app(CrmOpportunityService::class)->moveToStage($deal, $proposal, null));

        $deal->refresh();
        $this->assertSame($proposal->id, $deal->stage_id);

        $move = CrmOpportunityHistory::query()->where('opportunity_id', $deal->id)->where('event', 'stage_changed')->sole();
        $this->assertSame(['New inquiry', 'Proposal sent'], [$move->from_stage_name, $move->to_stage_name]);

        // Renaming afterwards does not rewrite what happened.
        app(CrmPipelineService::class)->renameStage($proposal, 'Quote sent');
        $this->assertSame('Proposal sent', $move->fresh()->to_stage_name);

        $this->assertCount(1, $this->events);
        $event = $this->events[0];
        $this->assertInstanceOf(CrmOpportunityStageChanged::class, $event);
        $this->assertSame(['new_inquiry', 'proposal_sent'], [$event->fromStageSemanticKey, $event->stageSemanticKey]);
        $this->assertSame('crm_opportunity_history:' . $move->id, $event->occurrenceKey());

        // Same stage again: nothing changes, nothing is announced.
        $this->assertFalse(app(CrmOpportunityService::class)->moveToStage($deal->fresh(), $proposal->fresh(), null));
        $this->assertCount(1, $this->events);
    }

    public function test_won_and_lost_close_the_deal_in_its_stage_and_announce_it_once(): void
    {
        [, $business] = $this->crmTenant();
        $service = app(CrmOpportunityService::class);
        $won = $this->deal($business, title: 'Won deal');
        $lost = $this->deal($business, $won->pipeline, title: 'Lost deal');
        $this->events = [];

        $service->markWon($won);
        $service->markLost($lost, ' Went with a competitor ');

        $this->assertSame(CrmOpportunityStatus::Won, $won->fresh()->status);
        $this->assertNotNull($won->fresh()->won_at);
        $this->assertSame(CrmOpportunityStatus::Lost, $lost->fresh()->status);
        $this->assertSame('Went with a competitor', $lost->fresh()->lost_reason);
        $this->assertSame($won->stage_id, $won->fresh()->stage_id);

        $this->assertSame([CrmOpportunityWon::class, CrmOpportunityLost::class], array_map(fn ($e) => $e::class, $this->events));
        $this->assertSame(['opportunity_won', 'opportunity_lost'], array_map(fn ($e) => $e->name(), $this->events));

        // Not twice, and a closed deal is not moved around.
        $this->assertRefused(fn () => $service->markWon($won->fresh()));
        $this->assertRefused(fn () => $service->moveToStage($won->fresh(), $this->stageKeyed($won->pipeline, 'qualified')));
        $this->assertCount(2, $this->events);

        $service->reopen($lost->fresh());
        $reopened = $lost->fresh();
        $this->assertSame(CrmOpportunityStatus::Open, $reopened->status);
        $this->assertNull($reopened->lost_reason);
        $this->assertNull($reopened->lost_at);
        $this->assertSame(CrmOpportunityHistoryEvent::Reopened, CrmOpportunityHistory::query()->where('opportunity_id', $lost->id)->orderByDesc('id')->first()->event);
    }

    public function test_contact_status_is_independent_of_the_stage_and_manually_correctable(): void
    {
        [, $business] = $this->crmTenant();
        $service = app(CrmOpportunityService::class);
        $quiet = $this->deal($business, title: 'Not yet reached');
        $reached = $this->deal($business, $quiet->pipeline, title: 'Already talking');
        $this->events = [];

        $this->assertTrue($service->setContactStatus($reached, CrmContactStatus::InContact));
        $this->assertFalse($service->setContactStatus($reached->fresh(), CrmContactStatus::InContact));

        // New inquiry + No contact and New inquiry + In contact, side by side.
        $this->assertSame([$quiet->fresh()->stage_id], [$reached->fresh()->stage_id]);
        $this->assertSame(CrmContactStatus::NoContact, $quiet->fresh()->contact_status);
        $this->assertSame(CrmContactStatus::InContact, $reached->fresh()->contact_status);
        $this->assertSame(CrmContactStatusSource::Manual, $reached->fresh()->contact_status_source);

        // Corrected back by hand.
        $service->setContactStatus($reached->fresh(), CrmContactStatus::NoContact);
        $this->assertSame(CrmContactStatus::NoContact, $reached->fresh()->contact_status);
        $this->assertSame(2, CrmOpportunityHistory::query()->where('opportunity_id', $reached->id)->where('event', 'contact_status_changed')->count());

        $this->assertSame([], $this->events, 'Contact status changes are not one of the four canonical events.');
    }

    public function test_pipeline_stage_and_contact_must_all_belong_to_the_business(): void
    {
        [, $business] = $this->crmTenant('Harbor Lane Studios', 'Harbor');
        [, $other] = $this->crmTenant('Other Studio', 'Other');
        $service = app(CrmOpportunityService::class);
        $pipeline = $this->standardPipeline($business);
        $otherPipeline = $this->standardPipeline($other);

        $this->assertRefused(fn () => $service->create($business, $pipeline, $this->crmContact($other), 'Foreign contact'));
        $this->assertRefused(fn () => $service->create($business, $otherPipeline, $this->crmContact($business), 'Foreign pipeline'));
        $this->assertRefused(fn () => $service->create($business, $pipeline, $this->crmContact($business), 'Foreign stage', null, $this->stageKeyed($otherPipeline, 'qualified')));

        $deal = $this->deal($business, $pipeline);
        $this->assertRefused(fn () => $service->moveToStage($deal, $this->stageKeyed($otherPipeline, 'qualified')));

        $this->assertSame(1, CrmOpportunity::query()->count());
    }

    public function test_a_contact_is_not_automatically_a_lead(): void
    {
        [, $business] = $this->crmTenant();
        $this->standardPipeline($business);

        $this->crmContact($business, ['FIRST_NAME' => 'Ana']);
        $this->crmContact($business);

        $this->assertSame(0, CrmOpportunity::query()->count());
        $this->assertSame([], $this->events);
    }

    public function test_deleting_the_contact_keeps_the_deal_and_its_history(): void
    {
        [, $business] = $this->crmTenant();
        $deal = $this->deal($business);

        DB::table('contacts')->where('id', $deal->contact_id)->delete();

        $this->assertNull($deal->fresh()->contact_id);
        $this->assertSame(1, CrmOpportunityHistory::query()->where('opportunity_id', $deal->id)->count());
    }

    private function assertRefused(callable $change): void
    {
        try {
            $change();
        } catch (CrmRuleException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('The change should have been refused.');
    }
}
