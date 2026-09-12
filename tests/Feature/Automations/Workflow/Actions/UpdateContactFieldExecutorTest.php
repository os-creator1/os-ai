<?php

namespace Tests\Feature\Automations\Workflow\Actions;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\StepRunStatus;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Executors\UpdateContactFieldNodeExecutor;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\ContactGroupFields;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-B — the Update contact field action.
 *
 * B4's semantics, carried forward rather than reinvented: one allowlisted
 * {field_id, value} pair, written to the enrolled contact only, through the
 * ordinary custom-field table. The tests that matter are the ones that prove a
 * field id cannot be used to reach outside the contact's own group or Business —
 * including after the definition has been tampered with, because a pinned
 * version outlives the checks that were run when it was published.
 */
class UpdateContactFieldExecutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsActionWorkflows;

    /** 11. The canonical field and value are written. */
    public function test_it_writes_the_configured_value_to_the_configured_field(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Members');
        $field = $this->textField($group, 'STATUS_NOTE');
        $contact = $this->contact($business, $group, '12025557001');

        [$workflow] = $this->publishGroupScopedWorkflow($business, $group, [
            $this->updateFieldStep((int) $field->id, 'vip'),
            $this->endStep(),
        ]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
        $this->assertDatabaseHas('contacts_custom_field', [
            'contact_id' => $contact->id,
            'field_id' => $field->id,
            'value' => 'vip',
        ]);
        $this->assertSame(
            1,
            DB::table('contacts_custom_field')->where('contact_id', $contact->id)->where('field_id', $field->id)->count(),
            'The canonical upsert must not leave a duplicate row.',
        );
    }

    /** The write is idempotent, which is what its side-effect class promises. */
    public function test_re_running_the_step_re_applies_the_same_value(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Members');
        $field = $this->textField($group, 'STATUS_NOTE');
        $contact = $this->contact($business, $group, '12025557002');

        // A pre-existing value, so this is an update rather than an insert.
        DB::table('contacts_custom_field')->insert([
            'contact_id' => $contact->id, 'field_id' => $field->id, 'value' => 'old',
        ]);

        [$workflow] = $this->publishGroupScopedWorkflow($business, $group, [
            $this->updateFieldStep((int) $field->id, 'fresh'),
            $this->endStep(),
        ]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        $advancer = app(WorkflowAdvancer::class);

        for ($i = 0; $i < 3; $i++) {
            $advancer->advance($enrollment->fresh() ?? $enrollment);
        }

        $this->assertSame(
            'fresh',
            DB::table('contacts_custom_field')->where('contact_id', $contact->id)
                ->where('field_id', $field->id)->value('value'),
        );
        $this->assertSame(1, DB::table('contacts_custom_field')
            ->where('contact_id', $contact->id)->where('field_id', $field->id)->count());
    }

    /**
     * 12a. The compiler refuses to publish a workflow pointed at another
     * Business's field, so this never reaches the runtime in the first place.
     */
    public function test_a_foreign_field_cannot_even_be_published(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Ours');

        [, $otherBusiness] = $this->entitledTenant();
        $foreignGroup = $this->contactGroup($otherBusiness, 'Theirs');
        $foreignField = $this->textField($foreignGroup, 'THEIR_NOTE');

        $drafts = app(WorkflowDraftService::class);
        $trigger = \App\Enums\Automation\Workflow\WorkflowTriggerType::ContactCreated;
        $workflow = $drafts->createWorkflowWithDraft($business, 'Cross tenant', $trigger);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition($trigger);
        $definition['root']['config'] += ['contact_group_id' => $group->id];
        $definition['root']['next'] = [
            $this->updateFieldStep((int) $foreignField->id, 'tampered'),
            $this->endStep(),
        ];
        $drafts->autosave($draft, $definition, $draft->definition_revision);

        $this->expectException(\Throwable::class);

        app(WorkflowPublisher::class)->publish($workflow->fresh());
    }

    /**
     * 12b. And if a foreign field id reaches the compiled graph anyway — a
     * tampered row, a group moved between Businesses after publishing — the
     * executor still writes nothing. Publish-time validation is not the last
     * line of defence.
     */
    public function test_a_tampered_foreign_field_writes_nothing(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Ours');
        $field = $this->textField($group, 'OUR_NOTE');
        $contact = $this->contact($business, $group, '12025557003');

        [, $otherBusiness] = $this->entitledTenant();
        $foreignGroup = $this->contactGroup($otherBusiness, 'Theirs');
        $foreignField = $this->textField($foreignGroup, 'THEIR_NOTE');
        $foreignContact = $this->contact($otherBusiness, $foreignGroup, '12025557004');

        [$workflow, $version] = $this->publishGroupScopedWorkflow($business, $group, [
            $this->updateFieldStep((int) $field->id, 'ok'),
            $this->endStep(),
        ]);

        // Repoint the compiled node at the other Business's field.
        DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)->where('node_type', 'update_contact_field')
            ->update(['config' => json_encode(['field_id' => (int) $foreignField->id, 'value' => 'tampered'])]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertDatabaseMissing('contacts_custom_field', ['field_id' => $foreignField->id]);
        $this->assertSame(
            0,
            DB::table('contacts_custom_field')->where('contact_id', $foreignContact->id)->count(),
            'No row belonging to the other Business may be touched.',
        );

        $stepRun = DB::table('automation_step_runs')->where('node_type', 'update_contact_field')->first();
        $this->assertSame(StepRunStatus::Skipped->value, $stepRun->status);
        $this->assertSame('field_not_in_contact_business', $stepRun->safe_error_summary);
    }

    /** A field from another group of the SAME Business is refused too. */
    public function test_a_field_from_another_group_is_refused(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Watched');
        $field = $this->textField($group, 'WATCHED_NOTE');
        $contact = $this->contact($business, $group, '12025557005');

        $otherGroup = $this->contactGroup($business, 'Other');
        $otherField = $this->textField($otherGroup, 'OTHER_NOTE');

        [$workflow, $version] = $this->publishGroupScopedWorkflow($business, $group, [
            $this->updateFieldStep((int) $field->id, 'ok'),
            $this->endStep(),
        ]);

        DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)->where('node_type', 'update_contact_field')
            ->update(['config' => json_encode(['field_id' => (int) $otherField->id, 'value' => 'wrong group'])]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertDatabaseMissing('contacts_custom_field', ['field_id' => $otherField->id]);
    }

    /** The phone field is identity, not a custom attribute, and is never written. */
    public function test_the_phone_field_is_never_writable(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Members');
        $field = $this->textField($group, 'STATUS_NOTE');
        $contact = $this->contact($business, $group, '12025557006');

        [$workflow, $version] = $this->publishGroupScopedWorkflow($business, $group, [
            $this->updateFieldStep((int) $field->id, 'ok'),
            $this->endStep(),
        ]);

        // Turn the very field the workflow writes into the phone field.
        ContactGroupFields::query()->whereKey($field->id)->update(['is_phone' => true]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertDatabaseMissing('contacts_custom_field', ['field_id' => $field->id]);

        $stepRun = DB::table('automation_step_runs')->where('node_type', 'update_contact_field')->first();
        $this->assertSame('phone_field_not_writable', $stepRun->safe_error_summary);
    }

    /** A skipped field write does not end the journey. */
    public function test_a_skipped_write_lets_the_journey_continue(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Members');
        $field = $this->textField($group, 'STATUS_NOTE');
        $contact = $this->contact($business, $group, '12025557007');

        [$workflow, $version] = $this->publishGroupScopedWorkflow($business, $group, [
            $this->updateFieldStep((int) $field->id, 'ok'),
            $this->endStep(),
        ]);

        DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)->where('node_type', 'update_contact_field')
            ->update(['config' => json_encode(['field_id' => 99999999, 'value' => 'nowhere'])]);

        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->fresh()->status);
    }

    public function test_the_registry_resolves_this_executor(): void
    {
        $this->assertInstanceOf(
            UpdateContactFieldNodeExecutor::class,
            app(NodeExecutorRegistry::class)
                ->for(\App\Enums\Automation\Workflow\WorkflowNodeType::UpdateContactField),
        );
    }
}
