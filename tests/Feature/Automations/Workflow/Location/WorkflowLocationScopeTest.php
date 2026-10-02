<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowLocationScope;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Business\BusinessLocationLifecycleState;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowLocationAdmission;
use App\Library\Automation\Workflow\WorkflowCompiler;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflowVersion;
use App\Models\Business;
use App\Models\BusinessLocation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Location run-scope foundation — the lane's own contract, §5/§6/§10/§13:
 * schema/versioning, the central admission authority, and immutability of
 * the pinned run Location.
 */
class WorkflowLocationScopeTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;

    /** @return array{0: Business, 1: BusinessLocation, 2: BusinessLocation} */
    private function twoLocations(): array
    {
        [, $business] = $this->entitledTenant();
        $locationA = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $locationB = $this->businessLocation($business);

        return [$business, $locationA, $locationB];
    }

    private function publishWithScope(Business $business, string $scope, array $locationIds): AutomationWorkflowVersion
    {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Scoped', WorkflowTriggerType::ContactCreated);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactCreated);
        $definition['location_scope'] = $scope;
        $definition['location_ids'] = $locationIds;
        $definition['root']['next'] = [$this->endStep()];

        $drafts->autosave($draft, $definition, $draft->definition_revision);

        return app(WorkflowPublisher::class)->publish($workflow->fresh());
    }

    // =================================================================
    // Schema / versioning (§5)
    // =================================================================

    public function test_a_new_draft_defaults_to_all_locations(): void
    {
        [, $business] = $this->entitledTenant();
        $definition = app(WorkflowDraftService::class)->starterDefinition(WorkflowTriggerType::ContactCreated);

        $this->assertSame('all', $definition['location_scope']);
        $this->assertSame([], $definition['location_ids']);
    }

    public function test_a_document_missing_the_scope_key_entirely_loads_and_validates_as_all(): void
    {
        [, $business] = $this->entitledTenant();
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Legacy shape', WorkflowTriggerType::ContactCreated);
        $draft = $workflow->draftVersion();

        // A document exactly as it existed before this lane — no
        // location_scope, no location_ids key at all.
        $definition = [
            'schema_version' => 1,
            'root' => [
                'key' => 'r', 'type' => 'trigger',
                'config' => ['trigger_type' => 'contact_created', 'enrollment_policy' => 'once_ever', 'enrollment_policy_source' => 'default', 'failure_policy' => 'halt'],
                'next' => [$this->endStep()],
            ],
        ];
        $drafts->autosave($draft, $definition, $draft->definition_revision);

        $errors = app(WorkflowCompiler::class)->validate($draft->fresh());
        $this->assertSame([], $errors, 'A pre-existing document with no location key must load and validate without error.');

        $version = app(WorkflowPublisher::class)->publish($workflow->fresh());
        $this->assertSame(WorkflowLocationScope::All, $version->location_scope);
    }

    public function test_published_version_pins_the_chosen_scope(): void
    {
        [$business, $locationA] = $this->twoLocations();
        $version = $this->publishWithScope($business, 'one', [$locationA->id]);

        $this->assertSame(WorkflowLocationScope::One, $version->location_scope);
    }

    public function test_selected_version_persists_only_the_selected_same_business_locations(): void
    {
        [$business, $locationA, $locationB] = $this->twoLocations();
        $version = $this->publishWithScope($business, 'selected', [$locationA->id, $locationB->id]);

        $persisted = $version->locations()->pluck('business_locations.id')->sort()->values()->all();
        $this->assertSame([$locationA->id, $locationB->id], $persisted);
    }

    public function test_all_scope_persists_no_location_rows(): void
    {
        [$business] = $this->twoLocations();
        $version = $this->publishWithScope($business, 'all', []);

        $this->assertSame(0, $version->locations()->count(), 'All scope must never require a row per Location.');
    }

    public function test_one_requires_exactly_one_location(): void
    {
        [$business, $locationA, $locationB] = $this->twoLocations();

        $this->expectException(ValidationException::class);
        $this->publishWithScope($business, 'one', [$locationA->id, $locationB->id]);
    }

    public function test_selected_requires_at_least_one_location(): void
    {
        [$business] = $this->twoLocations();

        $this->expectException(ValidationException::class);
        $this->publishWithScope($business, 'selected', []);
    }

    public function test_a_foreign_business_location_id_is_refused_at_publish(): void
    {
        [$business] = $this->twoLocations();
        [, $otherBusiness] = $this->entitledTenant();
        $foreignLocation = BusinessLocation::query()->where('business_id', $otherBusiness->id)->firstOrFail();

        $this->expectException(ValidationException::class);
        $this->publishWithScope($business, 'one', [$foreignLocation->id]);
    }

    public function test_an_archived_location_is_refused_at_publish(): void
    {
        [$business, $locationA] = $this->twoLocations();
        $locationA->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value])->save();

        $this->expectException(ValidationException::class);
        $this->publishWithScope($business, 'one', [$locationA->id]);
    }

    public function test_published_version_location_scope_is_never_mutated_in_place(): void
    {
        [$business, $locationA] = $this->twoLocations();
        $version = $this->publishWithScope($business, 'one', [$locationA->id]);
        $versionLocationsBefore = $version->locations()->pluck('business_locations.id')->all();

        // Republish to a new scope entirely.
        $drafts = app(WorkflowDraftService::class);
        $workflow = $version->workflow;
        $draft = $drafts->ensureDraft($workflow->fresh());
        $definition = $draft->definition;
        $definition['location_scope'] = 'all';
        $definition['location_ids'] = [];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        $version = $version->fresh();
        $this->assertSame(WorkflowLocationScope::One, $version->location_scope, 'The OLD version row must keep its own scope forever.');
        $this->assertSame($versionLocationsBefore, $version->locations()->pluck('business_locations.id')->all());
    }

    public function test_republish_changes_scope_only_for_new_enrollments(): void
    {
        [$business, $locationA, $locationB] = $this->twoLocations();
        $versionOne = $this->publishWithScope($business, 'one', [$locationA->id]);
        $workflow = $versionOne->workflow;

        $contactA = $this->contactFor($business);
        $contactA->forceFill(['location_id' => $locationA->id])->save();
        $enrollmentOne = app(EnrollmentService::class)->enroll($workflow->fresh(), $contactA, $locationA->id, (string) $contactA->id);
        $this->assertNotNull($enrollmentOne, 'Location A is admitted under the first version.');

        // Republish to Location B only.
        $drafts = app(WorkflowDraftService::class);
        $draft = $drafts->ensureDraft($workflow->fresh());
        $definition = $draft->definition;
        $definition['location_scope'] = 'one';
        $definition['location_ids'] = [$locationB->id];
        $drafts->autosave($draft, $definition, $draft->definition_revision);
        app(WorkflowPublisher::class)->publish($workflow->fresh());

        $contactB = $this->contactFor($business);
        $contactB->forceFill(['location_id' => $locationB->id])->save();
        $enrollmentTwo = app(EnrollmentService::class)->enroll($workflow->fresh(), $contactB, $locationB->id, (string) $contactB->id);
        $this->assertNotNull($enrollmentTwo, 'Location B is admitted under the new, republished version.');

        $this->assertSame((int) $locationA->id, (int) $enrollmentOne->fresh()->business_location_id, 'The old enrollment keeps its original Location, untouched by the republish.');
    }

    // =================================================================
    // Central admission authority (§10)
    // =================================================================

    public function test_the_admission_table(): void
    {
        [$business, $locationA, $locationB] = $this->twoLocations();
        [, $otherBusiness] = $this->entitledTenant();
        $foreignLocation = BusinessLocation::query()->where('business_id', $otherBusiness->id)->firstOrFail();

        $admission = app(WorkflowLocationAdmission::class);

        $all = $this->publishWithScope($business, 'all', []);
        $this->assertTrue($admission->admits($all, $locationA->fresh()));
        $this->assertFalse($admission->admits($all, null), 'All + null must refuse.');
        $this->assertFalse($admission->admits($all, $foreignLocation), 'All + foreign Business must refuse.');

        $locationA->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value])->save();
        $this->assertFalse($admission->admits($all, $locationA->fresh()), 'All + archived must refuse a NEW enrollment.');
        $locationA->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Active->value])->save();

        $selected = $this->publishWithScope($business, 'selected', [$locationA->id]);
        $this->assertTrue($admission->admits($selected, $locationA->fresh()));
        $this->assertFalse($admission->admits($selected, $locationB->fresh()), 'Selected + unselected Location must refuse.');

        $one = $this->publishWithScope($business, 'one', [$locationB->id]);
        $this->assertTrue($admission->admits($one, $locationB->fresh()));
        $this->assertFalse($admission->admits($one, $locationA->fresh()), 'One + any other Location must refuse.');
    }

    // =================================================================
    // Enrollment authority (§8/§10)
    // =================================================================

    public function test_every_new_enrollment_has_a_business_location_id(): void
    {
        // A single-Location Business, deliberately — Contacts::
        // singleActiveLocationIdFor() (which contactFor() relies on) only
        // resolves when exactly one active Location exists; two-Location
        // tenants are for the tests that need to tell them apart.
        [, $business] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $contact->location_id, (string) $contact->id);

        $this->assertNotNull($enrollment);
        $this->assertNotNull($enrollment->business_location_id);
        $this->assertSame((int) $contact->location_id, (int) $enrollment->business_location_id);
    }

    public function test_a_null_location_id_never_enrolls(): void
    {
        [$business] = $this->twoLocations();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        $this->assertNull(app(EnrollmentService::class)->enroll($workflow, $contact, null, (string) $contact->id));
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_a_foreign_business_location_never_enrolls(): void
    {
        [$business] = $this->twoLocations();
        [, $otherBusiness] = $this->entitledTenant();
        $foreignLocation = BusinessLocation::query()->where('business_id', $otherBusiness->id)->firstOrFail();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        $this->assertNull(app(EnrollmentService::class)->enroll($workflow, $contact, $foreignLocation->id, (string) $contact->id));
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_an_archived_location_never_enrolls(): void
    {
        [$business, $locationA] = $this->twoLocations();
        $locationA->forceFill(['lifecycle_state' => BusinessLocationLifecycleState::Archived->value])->save();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);

        $this->assertNull(app(EnrollmentService::class)->enroll($workflow, $contact, $locationA->id, (string) $contact->id));
    }

    // =================================================================
    // Immutability (§13)
    // =================================================================

    public function test_a_contact_transfer_after_enrollment_does_not_move_the_pinned_location(): void
    {
        [$business, $locationA, $locationB] = $this->twoLocations();
        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $locationA->id])->save();

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $locationA->id, (string) $contact->id);
        $this->assertNotNull($enrollment);

        // The Contact moves to Location B after the run started.
        $contact->forceFill(['location_id' => $locationB->id])->save();

        $this->assertSame((int) $locationA->id, (int) $enrollment->fresh()->business_location_id, 'A Contact transfer must never move an already-pinned run.');
    }

    public function test_wait_and_wake_retain_the_pinned_location(): void
    {
        [$business, $locationA] = $this->twoLocations();
        [$workflow] = $this->publishWorkflow($business, [
            ['key' => 'w', 'type' => 'wait', 'config' => ['mode' => 'duration', 'amount' => 1, 'unit' => 'days']],
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $locationA->id])->save();

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $locationA->id, (string) $contact->id);
        app(\App\Library\Automation\Workflow\Runtime\WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertTrue($enrollment->fresh()->isWaiting(), 'The journey must be parked at the wait node for this proof to mean anything.');

        AutomationEnrollment::query()->whereKey($enrollment->id)->update(['resume_at' => now()->subMinute()]);

        app(\App\Library\Automation\Workflow\Runtime\WorkflowWakeService::class)->wakeDue();
        app(\App\Library\Automation\Workflow\Runtime\WorkflowAdvancer::class)->advance($enrollment->fresh());

        $this->assertTrue($enrollment->fresh()->isTerminal(), 'The journey must actually have run to completion for this proof to mean anything.');
        $this->assertSame((int) $locationA->id, (int) $enrollment->fresh()->business_location_id, 'Wait/wake must never touch the pinned Location.');
    }

    public function test_recovery_retains_the_pinned_location(): void
    {
        [$business, $locationA] = $this->twoLocations();
        [$workflow] = $this->publishWorkflow($business, [$this->recordedStep('a'), $this->endStep()]);
        $executor = $this->recordingExecutor();
        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $locationA->id])->save();

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $locationA->id, (string) $contact->id);

        // A "started" step run at the cursor with no terminal status — the
        // interrupted-step shape §7.4 recovers, as if a worker died between
        // the claim and recording the result.
        \Illuminate\Support\Facades\DB::table('automation_step_runs')->insert([
            'uid' => (string) \Illuminate\Support\Str::uuid(),
            'business_id' => $enrollment->business_id,
            'enrollment_id' => $enrollment->id,
            'node_id' => $enrollment->current_node_id,
            'node_type' => 'send_sms',
            'status' => 'started',
            'started_at' => now()->subMinutes(30),
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
        AutomationEnrollment::query()->whereKey($enrollment->id)->update(['last_advanced_at' => now()->subMinutes(30)]);

        app(\App\Library\Automation\Workflow\Runtime\WorkflowRecoveryService::class)->recoverStalled();

        $this->assertSame((int) $locationA->id, (int) $enrollment->fresh()->business_location_id, 'Recovery must never touch the pinned Location.');
    }
}
