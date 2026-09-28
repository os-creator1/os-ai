<?php

namespace Tests\Feature\NicheBlueprint;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Enums\NicheBlueprint\BlueprintComponentInstallationState;
use App\Exceptions\NicheBlueprint\InvalidComponentDescriptorException;
use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Library\NicheBlueprint\NicheBlueprintInstaller;
use App\Models\CrmPipeline;
use App\Models\NicheBlueprintVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
use Tests\TestCase;

/**
 * Implementation Contract 20 §12.D — end-to-end proof of the first real
 * Blueprint component adapter, through the real publisher, the real
 * installer and the real `CrmPipelineComponentAdapter` (no test-only
 * adapter substituted).
 *
 * Sub-slice C's own suites already prove the installer's general engine
 * (entitlement filtering, idempotent re-run, partial failure, concurrency,
 * upgrade/downgrade inertness — §13) against test-only adapters; that
 * coverage is adapter-agnostic and is not repeated here. This file proves
 * only what is specific to shipping a REAL adapter: it publishes cleanly,
 * a malformed descriptor is refused at publish, and installing produces a
 * genuine Business-owned CRM pipeline with a correct provenance record.
 */
class CrmPipelineBlueprintPublishAndInstallTest extends TestCase
{
    use CreatesBlueprintInstallationFixtures;
    use RefreshDatabase;

    /** Contract 20 §5.5's exact component payload. */
    private function pipelinePayload(): array
    {
        return [
            'template_key' => 'photo_booth',
            'template_version' => 1,
            'pipeline_key' => 'sales',
            'name' => 'Sales pipeline',
            'stages' => [
                ['name' => 'New Lead', 'semantic_key' => 'new_inquiry'],
                ['name' => 'Auto Follow-Up', 'semantic_key' => 'auto_follow_up'],
                ['name' => 'In Contact', 'semantic_key' => 'in_contact'],
                ['name' => 'Proposal Sent', 'semantic_key' => 'proposal_sent'],
                ['name' => 'Invoice Sent', 'semantic_key' => 'invoice_sent'],
                ['name' => 'Questionnaire Sent', 'semantic_key' => 'questionnaire_sent'],
                ['name' => 'Questionnaire Submitted', 'semantic_key' => 'questionnaire_submitted'],
                ['name' => 'Done', 'semantic_key' => 'done'],
            ],
        ];
    }

    private function installer(): NicheBlueprintInstaller
    {
        return app(NicheBlueprintInstaller::class);
    }

    // ------------------------------------------------------------ publish

    public function test_the_photo_booth_component_publishes_cleanly(): void
    {
        $blueprint = $this->publishBlueprint([
            ['key' => 'photo_booth_default_pipeline', 'type' => CrmPipelineComponentAdapter::TYPE, 'feature' => self::FEATURE_INSTALLS_EVERYWHERE, 'payload' => $this->pipelinePayload()],
        ]);

        $version = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->first();

        $this->assertNotNull($version);
        $this->assertSame('published', $version->state->value);
        $this->assertCount(1, $version->components);
        $this->assertSame('crm_pipeline', $version->components->first()->component_type);
    }

    public function test_a_malformed_pipeline_payload_fails_publication_cleanly(): void
    {
        $publisher = $this->blueprintPublisher();
        $adminId = $this->platformAdminId();

        $blueprint = $publisher->createBlueprint($adminId, 'photo_booth', 'Photo Booth', null, self::FIXTURE_INDUSTRY);
        $version = $publisher->createDraftVersion($adminId, $blueprint);

        $badPayload = $this->pipelinePayload();
        $badPayload['stages'][0]['semantic_key'] = 'not_new_inquiry';

        $publisher->addDraftComponent(
            $adminId,
            $version,
            'photo_booth_default_pipeline',
            CrmPipelineComponentAdapter::TYPE,
            self::FEATURE_INSTALLS_EVERYWHERE,
            $badPayload,
        );

        $this->expectException(InvalidComponentDescriptorException::class);

        try {
            $publisher->publishVersion($adminId, $version);
        } finally {
            // §6.2's gate refuses BEFORE any write: the draft is untouched.
            $this->assertSame('draft', $version->fresh()->state->value);
        }
    }

    // ------------------------------------------------------------- install

    public function test_end_to_end_a_crm_entitled_business_receives_the_photo_booth_pipeline_on_install(): void
    {
        $blueprint = $this->publishBlueprint([
            ['key' => 'photo_booth_default_pipeline', 'type' => CrmPipelineComponentAdapter::TYPE, 'feature' => self::FEATURE_INSTALLS_EVERYWHERE, 'payload' => $this->pipelinePayload()],
        ]);

        // tenant() creates a Business and assigns its first plan, which —
        // through the real §9.1 BusinessCreated / WorkspacePlanAssigned
        // triggers, run synchronously under the `sync` queue driver — has
        // already installed the Blueprint by the time this call returns. No
        // explicit installer call is needed here; this is the genuine
        // end-to-end path, not a direct unit call into the engine.
        [, $business] = $this->tenant(WorkspacePlanTier::Core);

        $record = $this->installationRecords($business)->get('photo_booth_default_pipeline');

        $this->assertNotNull($record);
        $this->assertSame(BlueprintComponentInstallationState::Installed, $record->state);
        $this->assertSame('crm_pipeline', $record->installed_record_type);
        $this->assertSame((int) $blueprint->id, (int) $record->blueprint_id);

        $pipeline = CrmPipeline::query()->find($record->installed_record_id);

        $this->assertNotNull($pipeline, 'The installation record must point at a real CrmPipeline row.');
        $this->assertSame((int) $business->id, (int) $pipeline->business_id);
        $this->assertSame('photo_booth', $pipeline->template_key);
        $this->assertSame(1, $pipeline->template_version);
        $this->assertSame('sales', $pipeline->template_pipeline_key);
        $this->assertSame(
            ['new_inquiry', 'auto_follow_up', 'in_contact', 'proposal_sent', 'invoice_sent', 'questionnaire_sent', 'questionnaire_submitted', 'done'],
            $pipeline->stages()->orderBy('position')->pluck('semantic_key')->all()
        );
    }

    public function test_a_repeated_installer_run_does_not_duplicate_the_pipeline(): void
    {
        $this->publishBlueprint([
            ['key' => 'photo_booth_default_pipeline', 'type' => CrmPipelineComponentAdapter::TYPE, 'feature' => self::FEATURE_INSTALLS_EVERYWHERE, 'payload' => $this->pipelinePayload()],
        ]);

        // Already installed once, automatically, by tenant()'s own plan
        // assignment (§9.1) — the baseline this test's explicit re-runs must
        // not disturb.
        [, $business] = $this->tenant(WorkspacePlanTier::Growth);

        $firstRecordId = $this->installationRecords($business)->get('photo_booth_default_pipeline')->installed_record_id;
        $this->assertNotNull($firstRecordId);

        foreach ([1, 2] as $attempt) {
            $result = $this->installer()->installForBusiness($business);

            $this->assertSame(0, $result->installed, "Attempt {$attempt} must not install again.");
            $this->assertSame(1, $result->alreadyDecided, "Attempt {$attempt} must find the component already decided.");
        }

        $finalRecordId = $this->installationRecords($business)->get('photo_booth_default_pipeline')->installed_record_id;

        $this->assertSame($firstRecordId, $finalRecordId);
        $this->assertSame(1, CrmPipeline::query()->where('business_id', $business->id)->count());
    }
}
