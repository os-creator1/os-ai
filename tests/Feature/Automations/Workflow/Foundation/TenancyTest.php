<?php

namespace Tests\Feature\Automations\Workflow\Foundation;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationWorkflow;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\TestCase;

/**
 * Automations V2 V2-0 — T-WF-4 and the Business-tenancy rules (§14.2).
 *
 * A workflow belongs to exactly one Business, and everything it references must
 * belong to that same Business. The checks live in two places on purpose: the
 * compiler refuses a cross-Business reference at publish, so it can never become
 * live at all, and the database refuses a row that names a Business that does not
 * exist. Later slices re-verify at execution (§7.3), which is defence in depth
 * rather than a substitute.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;

    private function drafts(): WorkflowDraftService
    {
        return app(WorkflowDraftService::class);
    }

    /** A trigger node that watches one specific group, plus an action. */
    private function definitionWatching(int $groupId, ?int $dateFieldId = null, ?int $updateFieldId = null): array
    {
        $triggerConfig = $dateFieldId === null
            ? [
                'trigger_type' => 'contact_created',
                'contact_group_id' => $groupId,
                'enrollment_policy' => 'once_ever',
                'enrollment_policy_source' => 'default',
                'failure_policy' => 'halt',
            ]
            : [
                'trigger_type' => 'contact_date_reached',
                'contact_group_id' => $groupId,
                'date_field_id' => $dateFieldId,
                'offset' => '0 day',
                'send_at' => '09:00',
                'enrollment_policy' => 'once_per_occurrence',
                'enrollment_policy_source' => 'default',
                'failure_policy' => 'halt',
            ];

        $action = $updateFieldId === null
            ? ['key' => (string) Str::uuid(), 'type' => 'send_sms', 'config' => ['body' => 'Hello']]
            : ['key' => (string) Str::uuid(), 'type' => 'update_contact_field',
               'config' => ['field_id' => $updateFieldId, 'value' => 'touched']];

        return [
            'schema_version' => 1,
            'root' => [
                'key' => (string) Str::uuid(),
                'type' => 'trigger',
                'config' => $triggerConfig,
                'next' => [$action],
            ],
        ];
    }

    private function publishAttempt(AutomationWorkflow $workflow, array $definition): array
    {
        $draft = $workflow->draftVersion();
        $this->drafts()->autosave($draft, $definition, $draft->definition_revision);

        try {
            app(WorkflowPublisher::class)->publish($workflow->fresh());

            return [];
        } catch (ValidationException $e) {
            return $e->errors();
        }
    }

    /** T-WF-4 — another Business's contact group cannot be referenced. */
    public function test_a_contact_group_from_another_business_is_refused(): void
    {
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();

        $foreignGroup = $this->contactGroup($businessB, 'Theirs');

        $workflow = $this->drafts()->createWorkflowWithDraft(
            $businessA,
            'Cross-business watcher',
            WorkflowTriggerType::ContactCreated,
        );

        $errors = $this->publishAttempt($workflow, $this->definitionWatching((int) $foreignGroup->id));

        $this->assertNotEmpty($errors, "Another Business's contact group must not be publishable.");
        $this->assertStringContainsString(
            'does not belong to this business',
            collect($errors)->flatten()->implode(' '),
        );

        // And nothing was compiled.
        $this->assertSame(0, DB::table('automation_workflow_nodes')->count());
        $this->assertNull($workflow->fresh()->published_version_id);
    }

    /** T-WF-4 — a date field from another Business's group is refused. */
    public function test_a_date_field_from_another_business_is_refused(): void
    {
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();

        $ownGroup = $this->contactGroup($businessA, 'Ours');
        $foreignGroup = $this->contactGroup($businessB, 'Theirs');
        $foreignField = $this->dateField($foreignGroup, 'THEIR_DATE');

        $workflow = $this->drafts()->createWorkflowWithDraft(
            $businessA,
            'Cross-business date',
            WorkflowTriggerType::ContactDateReached,
        );

        $errors = $this->publishAttempt(
            $workflow,
            $this->definitionWatching((int) $ownGroup->id, (int) $foreignField->id),
        );

        $this->assertNotEmpty($errors, "Another Business's date field must not be publishable.");
        $this->assertStringContainsString(
            'does not belong to the contact group',
            collect($errors)->flatten()->implode(' '),
        );
    }

    /** A correct, same-Business reference publishes cleanly. */
    public function test_a_same_business_reference_publishes(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Ours');
        $field = $this->dateField($group, 'BIRTH_DATE');

        $workflow = $this->drafts()->createWorkflowWithDraft(
            $business,
            'Birthday wishes',
            WorkflowTriggerType::ContactDateReached,
        );

        $errors = $this->publishAttempt(
            $workflow,
            $this->definitionWatching((int) $group->id, (int) $field->id),
        );

        $this->assertSame([], $errors, 'A workflow referencing only its own Business must publish.');

        $published = $workflow->fresh()->publishedVersion;
        $this->assertNotNull($published);

        // Every compiled row carries the workflow's Business, so tenancy is
        // checkable on any row without a join.
        $businessIds = DB::table('automation_workflow_nodes')->where('version_id', $published->id)
            ->pluck('business_id')->unique()->all();
        $this->assertSame([(int) $business->id], array_map('intval', $businessIds));

        $edgeBusinessIds = DB::table('automation_workflow_edges')->where('version_id', $published->id)
            ->pluck('business_id')->unique()->all();
        $this->assertSame([(int) $business->id], array_map('intval', $edgeBusinessIds));
    }

    /**
     * B4 §7.B carried forward: a custom field belongs to exactly one group, so
     * updating a field requires the trigger to watch that same group.
     */
    public function test_updating_a_field_requires_the_trigger_to_watch_its_group(): void
    {
        [, $business] = $this->entitledTenant();
        $watched = $this->contactGroup($business, 'Watched');
        $other = $this->contactGroup($business, 'Other');
        $fieldInOther = $this->textField($other, 'OTHER_NOTE');

        $workflow = $this->drafts()->createWorkflowWithDraft(
            $business,
            'Field updater',
            WorkflowTriggerType::ContactCreated,
        );

        // Same Business, but a field from a group the trigger does not watch.
        $errors = $this->publishAttempt(
            $workflow,
            $this->definitionWatching((int) $watched->id, null, (int) $fieldInOther->id),
        );

        $this->assertNotEmpty($errors, 'A field outside the watched group must not be publishable.');
        $this->assertStringContainsString(
            'does not belong to the contact group this workflow watches',
            collect($errors)->flatten()->implode(' '),
        );
    }

    /** The same action with no watched group at all is refused too. */
    public function test_updating_a_field_requires_an_explicit_trigger_group(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Any');
        $field = $this->textField($group, 'NOTE');

        $workflow = $this->drafts()->createWorkflowWithDraft(
            $business,
            'Groupless updater',
            WorkflowTriggerType::ContactCreated,
        );

        $definition = $this->definitionWatching((int) $group->id, null, (int) $field->id);
        unset($definition['root']['config']['contact_group_id']);

        $errors = $this->publishAttempt($workflow, $definition);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString(
            'must watch one specific contact group',
            collect($errors)->flatten()->implode(' '),
        );
    }

    /** A workflow cannot exist without a Business, nor name one that does not exist. */
    public function test_a_workflow_must_name_a_real_business(): void
    {
        try {
            DB::table('automation_workflows')->insert([
                'uid' => (string) Str::uuid(),
                'name' => 'Orphan',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A workflow with no Business must be refused.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        try {
            DB::table('automation_workflows')->insert([
                'uid' => (string) Str::uuid(),
                'business_id' => 9999999,
                'name' => 'Ghost business',
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('A workflow naming a nonexistent Business must be refused.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    /** The compiler reports problems keyed by the step they belong to. */
    public function test_validation_errors_are_keyed_by_the_step_they_belong_to(): void
    {
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();
        $foreignGroup = $this->contactGroup($businessB, 'Theirs');

        $workflow = $this->drafts()->createWorkflowWithDraft(
            $businessA,
            'Keyed errors',
            WorkflowTriggerType::ContactCreated,
        );

        $definition = $this->definitionWatching((int) $foreignGroup->id);
        $triggerKey = $definition['root']['key'];

        $draft = $workflow->draftVersion();
        $this->drafts()->autosave($draft, $definition, $draft->definition_revision);

        $errors = app(WorkflowCompiler::class)->validate($draft->fresh());

        $this->assertArrayHasKey(
            $triggerKey,
            $errors,
            'The canvas needs each problem attached to the step that owns it.',
        );
    }
}
