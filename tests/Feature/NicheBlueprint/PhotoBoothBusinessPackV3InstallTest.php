<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Library\NicheBlueprint\Adapters\AutomationWorkflowComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\Adapters\CustomFieldComponentAdapter;
use App\Library\NicheBlueprint\Adapters\FormComponentAdapter;
use App\Library\NicheBlueprint\Adapters\TagSetComponentAdapter;
use App\Library\NicheBlueprint\PhotoBoothBusinessPackV3;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowVersion;
use App\Models\CrmPipeline;
use App\Models\CrmPipelineStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
use Tests\TestCase;

/** Real publisher + installer + adapters: the pack installs, as drafts, with correct stage triggers and guards. */
class PhotoBoothBusinessPackV3InstallTest extends TestCase
{
    use CreatesBlueprintInstallationFixtures;
    use RefreshDatabase;

    private function installPack(): \App\Models\Business
    {
        $components = array_map(
            fn (array $c) => ['key' => $c['key'], 'type' => $c['type'], 'feature' => self::FEATURE_INSTALLS_EVERYWHERE, 'payload' => $c['payload']],
            array_values(array_filter(
                PhotoBoothBusinessPackV3::components(),
                fn ($c) => in_array($c['type'], [TagSetComponentAdapter::TYPE, CustomFieldComponentAdapter::TYPE, FormComponentAdapter::TYPE, CrmPipelineComponentAdapter::TYPE, AutomationWorkflowComponentAdapter::TYPE], true),
            )),
        );

        $this->publishBlueprint($components);
        [, $business] = $this->tenant(WorkspacePlanTier::Core);

        return $business;
    }

    public function test_pack_installs_both_pipelines_and_every_workflow_as_a_draft(): void
    {
        $business = $this->installPack();

        $inquiries = CrmPipeline::query()->where('business_id', $business->id)->where('name', 'Inquiries')->firstOrFail();
        $bookings = CrmPipeline::query()->where('business_id', $business->id)->where('name', 'Bookings')->firstOrFail();
        $this->assertSame(array_column(PhotoBoothBusinessPackV3::INQUIRY_STAGES, 0), $inquiries->stages()->orderBy('position')->pluck('name')->all());
        $this->assertSame(array_column(PhotoBoothBusinessPackV3::BOOKING_STAGES, 0), $bookings->stages()->orderBy('position')->pluck('name')->all());

        $workflows = AutomationWorkflow::query()->where('business_id', $business->id)->get();
        $this->assertCount(count(PhotoBoothBusinessPackV3::workflows()), $workflows);
        foreach ($workflows as $workflow) {
            $this->assertNull($workflow->published_version_id, "{$workflow->name} must install as a draft, never active.");
        }
    }

    public function test_stage_triggers_point_at_this_business_stage_and_guards_compile_to_branches(): void
    {
        $business = $this->installPack();

        $workflow = AutomationWorkflow::query()->where('business_id', $business->id)->where('name', 'Proposal Follow-Up')->firstOrFail();
        $draft = AutomationWorkflowVersion::query()->where('workflow_id', $workflow->id)->firstOrFail();
        $stage = CrmPipelineStage::query()->where('business_id', $business->id)->where('name', 'Proposal Sent')->firstOrFail();

        $root = $draft->definition['root'];
        $this->assertSame((int) $stage->id, (int) $root['config']['to_stage_id']);
        $this->assertSame((int) $stage->pipeline_id, (int) $root['config']['pipeline_id']);

        // First step is the consent guard: If/Else whose YES lane ends and NO lane carries the rest.
        $guard = $root['next'][0];
        $this->assertSame('if_else', $guard['type']);
        $this->assertSame('contact.subscribed', $guard['config']['conditions'][0]['subject']);
        $this->assertSame('end', $guard['yes'][0]['type']);
        $this->assertNotEmpty($guard['no']);
        $this->assertSame([], $guard['next'] ?? [], 'Nothing continues after an If / Else except through its lanes.');
    }

    public function test_installing_the_pack_twice_does_not_duplicate_anything(): void
    {
        $business = $this->installPack();
        $before = AutomationWorkflow::query()->where('business_id', $business->id)->count();
        app(\App\Library\NicheBlueprint\NicheBlueprintInstaller::class)->installForBusiness($business);

        $this->assertSame($before, AutomationWorkflow::query()->where('business_id', $business->id)->count());
        $this->assertSame(2, CrmPipeline::query()->where('business_id', $business->id)->count());
    }
}
