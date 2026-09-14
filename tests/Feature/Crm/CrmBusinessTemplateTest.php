<?php

namespace Tests\Feature\Crm;

use App\Enums\Crm\CrmStageSemanticKey;
use App\Library\Crm\CrmPipelineService;
use App\Library\Crm\Templates\BusinessTemplate;
use App\Library\Crm\Templates\BusinessTemplateApplier;
use App\Library\Crm\Templates\BusinessTemplateRegistry;
use App\Library\Crm\Templates\PipelineBlueprint;
use App\Library\Crm\Templates\StageBlueprint;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\TestCase;

/**
 * The Business Template seam: a template is COPIED into a Business, once, with
 * its provenance recorded — and a later change to the template never reaches a
 * Business that already received it.
 */
class CrmBusinessTemplateTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;

    public function test_v1_ships_one_generic_template_with_one_pipeline_starting_at_new_inquiry(): void
    {
        $template = app(BusinessTemplateRegistry::class)->generic();

        $this->assertSame('generic', $template->key);
        $this->assertCount(1, $template->pipelines);
        $this->assertSame(CrmStageSemanticKey::NewInquiry->value, $template->pipelines[0]->stages[0]->semanticKey);
        $this->assertSame('New inquiry', $template->pipelines[0]->stages[0]->name);
    }

    public function test_applying_copies_the_pipeline_and_stages_into_rows_the_business_owns_with_provenance(): void
    {
        [, $business] = $this->crmTenant();

        $created = app(BusinessTemplateApplier::class)->applyPipelines($business, app(BusinessTemplateRegistry::class)->generic(), null);

        $this->assertCount(1, $created);
        $pipeline = $created[0]->fresh();
        $this->assertSame($business->id, $pipeline->business_id);
        $this->assertSame('Sales pipeline', $pipeline->name);
        $this->assertSame(['generic', 1, 'sales'], [$pipeline->template_key, $pipeline->template_version, $pipeline->template_pipeline_key]);

        $stages = CrmPipelineStage::query()->where('pipeline_id', $pipeline->id)->orderBy('position')->get();
        $this->assertSame(['New inquiry', 'Qualified', 'Proposal sent', 'Negotiating'], $stages->pluck('name')->all());
        $this->assertSame(['new_inquiry', 'qualified', 'proposal_sent', 'negotiating'], $stages->pluck('semantic_key')->all());
        $this->assertSame([0, 1, 2, 3], $stages->pluck('position')->all());
        $this->assertSame([$business->id], $stages->pluck('business_id')->unique()->values()->all());
    }

    public function test_applying_the_same_template_twice_copies_it_once(): void
    {
        [, $business] = $this->crmTenant();
        $service = app(CrmPipelineService::class);

        $first = $service->setUpStandardPipeline($business);
        $second = $service->setUpStandardPipeline($business);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, CrmPipeline::query()->forBusiness($business)->count());
        $this->assertSame(4, CrmPipelineStage::query()->where('business_id', $business->id)->count());
    }

    public function test_changing_the_template_later_never_mutates_a_business_that_already_received_it(): void
    {
        [, $first] = $this->crmTenant('First Studio', 'First');
        [, $second] = $this->crmTenant('Second Studio', 'Second');
        $registry = app(BusinessTemplateRegistry::class);

        $received = $this->standardPipeline($first);
        $before = CrmPipelineStage::query()->where('pipeline_id', $received->id)->orderBy('position')->get(['name', 'semantic_key'])->toArray();

        // The master template is revised: renamed stages, one removed, one added.
        $registry->register(new BusinessTemplate('generic', 2, 'Standard', [
            new PipelineBlueprint('sales', 'Sales', [
                new StageBlueprint('Fresh lead', 'new_inquiry'),
                new StageBlueprint('Consultation booked', 'consultation_booked'),
            ]),
        ]));

        $this->assertSame($before, CrmPipelineStage::query()->where('pipeline_id', $received->id)->orderBy('position')->get(['name', 'semantic_key'])->toArray());
        $this->assertSame(1, $received->fresh()->template_version);

        // Re-applying to the Business that has it copies nothing new.
        $this->assertSame([], app(BusinessTemplateApplier::class)->applyPipelines($first, $registry->generic()));

        // A Business set up afterwards receives the revised snapshot.
        $later = $this->standardPipeline($second);
        $this->assertSame(2, $later->template_version);
        $this->assertSame(['Fresh lead', 'Consultation booked'], CrmPipelineStage::query()->where('pipeline_id', $later->id)->orderBy('position')->pluck('name')->all());

        // And customising a copy never touches the template.
        app(CrmPipelineService::class)->renameStage($this->stageKeyed($received, 'new_inquiry'), 'Enquiries');
        $this->assertSame('Fresh lead', $registry->generic()->pipelines[0]->stages[0]->name);
    }

    public function test_a_new_pipeline_is_a_separate_named_copy_of_the_standard_stages(): void
    {
        [, $business] = $this->crmTenant();
        $service = app(CrmPipelineService::class);
        $service->setUpStandardPipeline($business);

        $weddings = $service->createPipeline($business, 'Weddings');

        $this->assertSame(2, CrmPipeline::query()->forBusiness($business)->count());
        $this->assertSame(1, $weddings->position);
        $this->assertSame('new_inquiry', $this->stageNamed($weddings, 'New inquiry')->semantic_key);
    }

    public function test_a_template_pipeline_must_start_with_new_inquiry_and_keep_semantic_keys_unique(): void
    {
        try {
            new PipelineBlueprint('sales', 'Sales', [new StageBlueprint('Qualified', 'qualified'), new StageBlueprint('New inquiry', 'new_inquiry')]);
            $this->fail('A pipeline not starting with new_inquiry must be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        new PipelineBlueprint('sales', 'Sales', [new StageBlueprint('New inquiry', 'new_inquiry'), new StageBlueprint('Again', 'new_inquiry')]);
    }

    public function test_nothing_is_created_for_a_business_that_never_sets_up_a_pipeline(): void
    {
        [$customer, $business, $workspace] = $this->crmTenant();
        $this->authenticateAs($customer);

        $this->get($this->crmRoute('board', $workspace, $business))->assertOk()->assertSee('data-role="crm-setup"', false);

        $this->assertSame(0, CrmPipeline::query()->count());
    }
}
