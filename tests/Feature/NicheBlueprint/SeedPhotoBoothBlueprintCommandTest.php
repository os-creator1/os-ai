<?php

namespace Tests\Feature\NicheBlueprint;

use App\Library\NicheBlueprint\Adapters\CrmPipelineComponentAdapter;
use App\Models\NicheBlueprint;
use App\Models\NicheBlueprintComponent;
use App\Models\NicheBlueprintVersion;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\NicheBlueprint\Support\CreatesBlueprintInstallationFixtures;
use Tests\Feature\NicheBlueprint\Support\TestInstallingComponentAdapter;
use Tests\TestCase;

/**
 * Contract 20 §5.5/§12.D — `blueprint:seed-photo-booth`, including its
 * interrupted-seed recovery.
 *
 * The command's own claim is "idempotent, safe to run repeatedly —
 * including after an interrupted run". A prior run may have created the
 * `photo_booth` Blueprint and its v1 draft and then been interrupted before
 * publishing (a crash, a killed process). A naive re-run would call
 * `createDraftVersion()` again and hit `DraftVersionAlreadyExistsException`,
 * stranding the seed permanently. This suite proves the actual recovery
 * behaviour: an empty or exactly-matching draft is safely resumed and
 * published; anything else — content that can only be explained by real
 * operator authoring, not this seed's own partial run — is left completely
 * untouched and the command fails closed.
 */
class SeedPhotoBoothBlueprintCommandTest extends TestCase
{
    use CreatesBlueprintInstallationFixtures;
    use RefreshDatabase;

    private const COMMAND = 'blueprint:seed-photo-booth';

    private const BLUEPRINT_KEY = 'photo_booth';

    private const COMPONENT_KEY = 'photo_booth_default_pipeline';

    /** The exact Contract 20 §5.5 descriptor the command itself publishes. */
    private function expectedPayload(): array
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

    private function bootstrapAdmin(): int
    {
        $this->ensureRequiredAppConfigRowsExist();

        return $this->platformAdminId();
    }

    /** A Blueprint row with no versions at all, as createBlueprint() alone leaves it. */
    private function createBareBlueprint(int $adminId): NicheBlueprint
    {
        return $this->blueprintPublisher()->createBlueprint(
            $adminId,
            self::BLUEPRINT_KEY,
            'Photo Booth',
            null,
            self::FIXTURE_INDUSTRY,
        );
    }

    private function createDraft(int $adminId, NicheBlueprint $blueprint): NicheBlueprintVersion
    {
        return $this->blueprintPublisher()->createDraftVersion($adminId, $blueprint);
    }

    // --------------------------------------------------------- ordinary path

    public function test_a_fresh_run_creates_and_publishes_v1_and_a_second_run_is_a_no_op(): void
    {
        $this->bootstrapAdmin();

        $this->assertSame(Command::SUCCESS, Artisan::call(self::COMMAND));

        $blueprint = NicheBlueprint::query()->where('key', self::BLUEPRINT_KEY)->first();
        $this->assertNotNull($blueprint);

        $version = NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->first();
        $this->assertNotNull($version);
        $this->assertSame(1, $version->version_number);
        $this->assertCount(1, $version->components);
        $this->assertSame('crm_pipeline', $version->components->first()->component_type);

        // Case 5 — ordinary second run after a successful publish: no-op.
        $this->assertSame(Command::SUCCESS, Artisan::call(self::COMMAND));

        $this->assertSame(
            1,
            NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->count(),
            'Re-running the seed command after a successful publish must not create a second version.'
        );
    }

    // ------------------------------------------------- interrupted-seed recovery

    /** Case 1 — existing Blueprint, empty draft: resume, add the component, publish. */
    public function test_it_resumes_an_existing_empty_draft(): void
    {
        $adminId = $this->bootstrapAdmin();
        $blueprint = $this->createBareBlueprint($adminId);
        $draft = $this->createDraft($adminId, $blueprint);

        $this->assertSame(0, NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->count());

        $this->assertSame(Command::SUCCESS, Artisan::call(self::COMMAND));

        $published = NicheBlueprintVersion::query()->whereKey($draft->id)->first();
        $this->assertSame('published', $published->state->value);
        $this->assertSame(1, $published->version_number);

        $components = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->get();
        $this->assertCount(1, $components);
        $this->assertSame(self::COMPONENT_KEY, $components->first()->component_key);
        $this->assertSame(CrmPipelineComponentAdapter::TYPE, $components->first()->component_type);
        $this->assertSame('crm', $components->first()->required_feature_key);
        $this->assertEquals($this->expectedPayload(), $components->first()->payload);

        $this->assertSame(1, NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->count());
    }

    /** Case 2 — existing Blueprint, draft already holds exactly the expected component: publish as-is. */
    public function test_it_resumes_a_draft_that_already_has_exactly_the_expected_component(): void
    {
        $adminId = $this->bootstrapAdmin();
        $blueprint = $this->createBareBlueprint($adminId);
        $draft = $this->createDraft($adminId, $blueprint);

        $existing = $this->blueprintPublisher()->addDraftComponent(
            $adminId,
            $draft,
            self::COMPONENT_KEY,
            CrmPipelineComponentAdapter::TYPE,
            'crm',
            $this->expectedPayload(),
        );

        $this->assertSame(Command::SUCCESS, Artisan::call(self::COMMAND));

        $published = NicheBlueprintVersion::query()->whereKey($draft->id)->first();
        $this->assertSame('published', $published->state->value);
        $this->assertSame(1, $published->version_number);

        // No duplicate: still the SAME component row, not a second one.
        $components = NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->get();
        $this->assertCount(1, $components);
        $this->assertSame((int) $existing->id, (int) $components->first()->id);
    }

    /** Case 3 — existing draft, conflicting component content: fail closed, nothing touched. */
    public function test_it_fails_closed_on_a_draft_with_a_conflicting_component_payload(): void
    {
        $adminId = $this->bootstrapAdmin();
        $blueprint = $this->createBareBlueprint($adminId);
        $draft = $this->createDraft($adminId, $blueprint);

        $conflictingPayload = $this->expectedPayload();
        $conflictingPayload['pipeline_key'] = 'not_sales';

        $conflicting = $this->blueprintPublisher()->addDraftComponent(
            $adminId,
            $draft,
            self::COMPONENT_KEY,
            CrmPipelineComponentAdapter::TYPE,
            'crm',
            $conflictingPayload,
        );

        $this->assertSame(Command::FAILURE, Artisan::call(self::COMMAND));

        // The draft remains a draft — never published.
        $this->assertSame('draft', $draft->fresh()->state->value);
        $this->assertSame(
            0,
            NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->count()
        );

        // The conflicting component is completely unchanged — no field rewritten.
        $untouched = NicheBlueprintComponent::query()->whereKey($conflicting->id)->first();
        $this->assertSame($conflicting->component_key, $untouched->component_key);
        $this->assertSame($conflicting->component_type, $untouched->component_type);
        $this->assertSame($conflicting->required_feature_key, $untouched->required_feature_key);
        $this->assertEquals($conflictingPayload, $untouched->payload);
        $this->assertSame(1, NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->count());
    }

    /** Case 4 — existing draft, an extra component alongside the expected one: fail closed. */
    public function test_it_fails_closed_on_a_draft_with_an_extra_component(): void
    {
        $adminId = $this->bootstrapAdmin();
        $blueprint = $this->createBareBlueprint($adminId);
        $draft = $this->createDraft($adminId, $blueprint);

        $this->blueprintPublisher()->addDraftComponent(
            $adminId,
            $draft,
            self::COMPONENT_KEY,
            CrmPipelineComponentAdapter::TYPE,
            'crm',
            $this->expectedPayload(),
        );

        // An extra, unrelated component an operator might have started authoring.
        $this->blueprintPublisher()->addDraftComponent(
            $adminId,
            $draft,
            'an_operator_authored_extra_component',
            CrmPipelineComponentAdapter::TYPE,
            'crm',
            $this->expectedPayload(),
        );

        $this->assertSame(Command::FAILURE, Artisan::call(self::COMMAND));

        $this->assertSame('draft', $draft->fresh()->state->value);
        $this->assertSame(2, NicheBlueprintComponent::query()->where('blueprint_version_id', $draft->id)->count());
        $this->assertSame(
            0,
            NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->where('state', 'published')->count()
        );
    }

    /** A mismatched component_type under the expected key is a conflict too, not merely a payload diff. */
    public function test_it_fails_closed_when_the_existing_component_has_a_different_type(): void
    {
        $adminId = $this->bootstrapAdmin();
        $blueprint = $this->createBareBlueprint($adminId);
        $draft = $this->createDraft($adminId, $blueprint);

        $this->registerBlueprintTestAdapters();

        $this->blueprintPublisher()->addDraftComponent(
            $adminId,
            $draft,
            self::COMPONENT_KEY,
            TestInstallingComponentAdapter::TYPE,
            'crm',
            ['name' => self::COMPONENT_KEY],
        );

        $this->assertSame(Command::FAILURE, Artisan::call(self::COMMAND));

        $this->assertSame('draft', $draft->fresh()->state->value);
    }

    /** Version discipline: a draft that is not v1 (e.g. a superseded v1 already exists) is never published as the seed. */
    public function test_it_fails_closed_when_the_existing_draft_is_not_version_1(): void
    {
        $adminId = $this->bootstrapAdmin();
        $blueprint = $this->createBareBlueprint($adminId);

        // Publish a real v1, then supersede it WITHOUT a replacement (§12.B
        // supersede()) — leaving a superseded v1 and no published version at
        // all, so hasPublishedVersion() is false and this command's own
        // recovery logic is reached. The next draft created after that is
        // v2, never v1.
        $publisher = $this->blueprintPublisher();
        $v1 = $this->createDraft($adminId, $blueprint);
        $publisher->addDraftComponent($adminId, $v1, self::COMPONENT_KEY, CrmPipelineComponentAdapter::TYPE, 'crm', $this->expectedPayload());
        $publisher->publishVersion($adminId, $v1);
        $publisher->supersede($adminId, $v1);

        $v2 = $publisher->createDraftVersion($adminId, $blueprint);

        $this->assertSame(2, $v2->version_number);

        $this->assertSame(Command::FAILURE, Artisan::call(self::COMMAND));

        // Untouched: v2 stays a draft, v1 stays superseded, no v3 appears.
        $this->assertSame('draft', $v2->fresh()->state->value);
        $this->assertSame('superseded', $v1->fresh()->state->value);
        $this->assertSame(2, NicheBlueprintVersion::query()->where('blueprint_id', $blueprint->id)->count());
    }
}
