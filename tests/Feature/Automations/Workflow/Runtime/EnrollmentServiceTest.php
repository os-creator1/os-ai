<?php

namespace Tests\Feature\Automations\Workflow\Runtime;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowLifecycleService;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Library\Automation\Workflow\WorkflowPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2 runtime — the canonical enrollment boundary (§7.5).
 *
 * Everything that can put a contact into a workflow comes through here, so these
 * are the rules that decide whether a journey exists at all: the version it is
 * pinned to, whether a duplicate is refused, and whether tenancy holds.
 */
class EnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;

    private function service(): EnrollmentService
    {
        return app(EnrollmentService::class);
    }

    /** The interface resolves to the runtime implementation, not something else. */
    public function test_the_contract_resolves_to_the_runtime_implementation(): void
    {
        $this->assertInstanceOf(
            \App\Library\Automation\Workflow\Runtime\WorkflowEnrollmentService::class,
            $this->service(),
        );
    }

    public function test_enrolling_pins_the_published_version_and_starts_at_the_root(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow, $version] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        $enrollment = $this->service()->enroll($workflow, $contact, (string) $contact->id);

        $this->assertNotNull($enrollment);
        $this->assertSame((int) $version->id, (int) $enrollment->version_id, 'The version must be pinned at enrollment.');
        $this->assertSame(EnrollmentStatus::Active, $enrollment->status);
        $this->assertSame(0, (int) $enrollment->step_count);

        $rootId = (int) DB::table('automation_workflow_nodes')
            ->where('version_id', $version->id)->where('node_type', 'trigger')->value('id');

        $this->assertSame($rootId, (int) $enrollment->current_node_id, 'A journey starts at the compiled root.');
        $this->assertSame((int) $business->id, (int) $enrollment->business_id);
    }

    /** The claim: a repeated trigger is a silent no-op, not an error or a double. */
    public function test_a_duplicate_enrollment_is_refused_silently(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        $first = $this->service()->enroll($workflow, $contact, (string) $contact->id);
        $second = $this->service()->enroll($workflow, $contact, (string) $contact->id);

        $this->assertNotNull($first);
        $this->assertNull($second, 'A repeated trigger must return null rather than enrolling twice.');
        $this->assertSame(1, DB::table('automation_enrollments')->count());
    }

    /**
     * The occurrence key is what lets a yearly workflow run every year — and the
     * active-contact guard is what stops two of them overlapping.
     */
    public function test_a_new_occurrence_may_enroll_only_after_the_previous_one_ends(): void
    {
        [, $business] = $this->entitledTenant();
        $group = $this->contactGroup($business, 'Yearly');
        $field = $this->dateField($group, 'BIRTH_DATE');
        $contact = $this->contact($business, $group, '12025557777');

        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Birthday', WorkflowTriggerType::ContactDateReached);
        $draft = $workflow->draftVersion();
        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactDateReached);
        $definition['root']['config'] += [
            'contact_group_id' => $group->id,
            'date_field_id' => $field->id,
            'offset' => '0 day',
            'send_at' => '09:00',
        ];
        $definition['root']['next'] = [$this->endStep()];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        $workflow = $workflow->fresh();

        $first = $this->service()->enroll($workflow, $contact, '2026');
        $this->assertNotNull($first);

        // Still in flight: the guard refuses a second concurrent journey even for
        // a different occurrence.
        $this->assertNull(
            $this->service()->enroll($workflow, $contact, '2027'),
            'A contact cannot be in one workflow twice at once.',
        );

        DB::table('automation_enrollments')->where('id', $first->id)
            ->update(['status' => EnrollmentStatus::Completed->value, 'current_node_id' => null]);

        $nextYear = $this->service()->enroll($workflow, $contact, '2027');

        $this->assertNotNull($nextYear, 'Next year must be allowed to enroll once the previous journey ended.');
        $this->assertNull(
            $this->service()->enroll($workflow, $contact, '2026'),
            'The occurrence that already ran must still be refused.',
        );
    }

    /** Tenancy is fail-closed at the boundary that creates the row. */
    public function test_a_contact_from_another_business_is_never_enrolled(): void
    {
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();

        [$workflow] = $this->publishWorkflow($businessA, [$this->endStep()]);
        $foreignContact = $this->contactFor($businessB, 'Theirs');

        $this->assertNull(
            $this->service()->enroll($workflow, $foreignContact, (string) $foreignContact->id),
            "Another Business's contact must never enter this workflow.",
        );
        $this->assertSame(0, DB::table('automation_enrollments')->count());
    }

    public function test_a_workflow_that_is_not_published_enrolls_nobody(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        app(WorkflowLifecycleService::class)->pause($workflow->fresh());

        $this->assertNull(
            $this->service()->enroll($workflow->fresh(), $contact, (string) $contact->id),
            'A paused workflow must not accept new enrollments.',
        );

        app(WorkflowLifecycleService::class)->archive($workflow->fresh());

        $this->assertNull($this->service()->enroll($workflow->fresh(), $contact, (string) $contact->id));
        $this->assertSame(0, DB::table('automation_enrollments')->count());
    }

    /** A draft-only workflow has nothing to pin, so it cannot enroll. */
    public function test_a_workflow_with_no_published_version_enrolls_nobody(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = app(WorkflowDraftService::class)
            ->createWorkflowWithDraft($business, 'Never published', WorkflowTriggerType::ContactCreated);
        $contact = $this->contactFor($business);

        $this->assertNull($this->service()->enroll($workflow, $contact, (string) $contact->id));
    }

    /** Lane F §6.1 — automations cascading into each other are bounded. */
    public function test_causation_depth_is_bounded(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        $this->assertNull(
            $this->service()->enroll(
                $workflow,
                $contact,
                (string) $contact->id,
                WorkflowLimits::MAX_CAUSATION_DEPTH + 1,
            ),
            'A cascade deeper than the cap must be refused at the door.',
        );

        $ok = $this->service()->enroll(
            $workflow,
            $contact,
            (string) $contact->id,
            WorkflowLimits::MAX_CAUSATION_DEPTH,
        );

        $this->assertNotNull($ok);
        $this->assertSame(WorkflowLimits::MAX_CAUSATION_DEPTH, (int) $ok->causation_depth);
    }

    /** A republish must not move an existing journey onto the new version. */
    public function test_an_enrollment_stays_pinned_after_a_republish(): void
    {
        [, $business] = $this->entitledTenant();
        [$workflow, $versionOne] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        $enrollment = $this->service()->enroll($workflow, $contact, (string) $contact->id);

        // Publish a second version.
        $drafts = app(WorkflowDraftService::class);
        $draft = $drafts->ensureDraft($workflow->fresh());
        $definition = $draft->definition;
        $definition['root']['next'] = [$this->recordedStep('added later'), $this->endStep()];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        $versionTwo = app(WorkflowPublisher::class)->publish($workflow->fresh());

        $this->assertNotSame((int) $versionOne->id, (int) $versionTwo->id);
        $this->assertSame(
            (int) $versionOne->id,
            (int) $enrollment->fresh()->version_id,
            'The pin must survive a republish.',
        );
        $this->assertSame(
            (int) $versionTwo->id,
            (int) $workflow->fresh()->published_version_id,
            'New enrollments must use the new version.',
        );

        // And a brand-new contact does start on version two.
        $newContact = $this->contactFor($business, 'Later');
        $later = $this->service()->enroll($workflow->fresh(), $newContact, (string) $newContact->id);

        $this->assertSame((int) $versionTwo->id, (int) $later->version_id);
    }
}
