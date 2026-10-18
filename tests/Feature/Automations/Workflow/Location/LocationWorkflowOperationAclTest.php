<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowStatus;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Jobs\Automation\Workflow\EnrollWorkflowContact;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Automations Location run-scope — the actor's Location ACL over EXISTING workflows.
 *
 * Every operation that resolves a workflow goes through one gate. A bound workflow
 * is operable only by an actor who reaches its Location; a Business-wide one only by
 * an actor who reaches every Location (the same reason only they may publish it); the
 * authority is the published version's scope, never inferred from a contact. An actor
 * outside it gets what an unknown uid gets — a 404 — before any contact is queried,
 * any job queued or any run row read.
 */
class LocationWorkflowOperationAclTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;
    use CreatesFormsFixtures;
    use BuildsFoundationWorkflows;
    use CallsWorkflowRoutes;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private Customer $staffDowntown;

    private Customer $staffAll;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $business, $this->workspace] = $this->formsTenant();
        $this->business = $this->activate($business);
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->staffDowntown = $this->staffGrantedOnly($this->workspace, $this->downtown);
        $this->staffAll = $this->staffWithFullReach($this->workspace);
    }

    private function url(string $name, AutomationWorkflow $workflow, ?AutomationEnrollment $enrollment = null): string
    {
        return $this->routeUrl($name, $this->workspace, $this->business, $workflow, $enrollment);
    }

    /** A LIVE workflow, authored and published by the owner. null scope = Business-wide. */
    private function live(?BusinessLocation $location, string $name = 'Flow'): AutomationWorkflow
    {
        $this->authenticateAs($this->owner);
        $created = $this->callJson('POST', $this->routeUrl('store', $this->workspace, $this->business), ['name' => $name . ' ' . uniqid(), 'trigger_type' => 'manual_enrollment'])->assertCreated();
        $workflow = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();

        $draft = $this->callJson('GET', $this->url('draft.show', $workflow))->assertOk()->json();
        $definition = $draft['definition'];
        $definition['root']['config']['business_location_id'] = $location?->id;
        $definition['root']['next'] = [$this->endStep()];
        $this->callJson('PUT', $this->url('draft.autosave', $workflow), ['definition' => $definition, 'definition_revision' => $draft['revision']])->assertOk();
        $this->callJson('POST', $this->url('publish', $workflow))->assertOk();

        return $workflow->fresh();
    }

    private function contactAt(?BusinessLocation $location, string $phone): Contacts
    {
        $contact = $this->crmContact($this->business, [], $phone);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);

        return $contact->fresh();
    }

    /** One finished journey, so history and logs have a row to protect. */
    private function journey(AutomationWorkflow $workflow, ?BusinessLocation $at): AutomationEnrollment
    {
        $contact = $this->contactAt($at, '1415555' . random_int(1000, 9999));
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'k-' . $contact->id, 0, $at?->id === null ? null : (int) $at->id);
        $this->assertNotNull($enrollment);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $enrollment->fresh();
    }

    /**
     * Every customer operation on one workflow: [label, method, url, body].
     *
     * @return list<array{0: string, 1: string, 2: string, 3: array<string, mixed>}>
     */
    private function operations(AutomationWorkflow $workflow, AutomationEnrollment $enrollment, Contacts $contact): array
    {
        $draft = $workflow->fresh()->draftVersion();

        return [
            ['open', 'GET', $this->url('show', $workflow), []],
            ['settings', 'GET', $this->url('settings', $workflow), []],
            ['draft show', 'GET', $this->url('draft.show', $workflow), []],
            ['draft autosave', 'PUT', $this->url('draft.autosave', $workflow), ['definition' => $draft?->definition ?? ['root' => []], 'definition_revision' => (int) ($draft?->definition_revision ?? 1)]],
            ['publish', 'POST', $this->url('publish', $workflow), []],
            ['discard draft', 'POST', $this->url('discard-draft', $workflow), []],
            ['pause', 'POST', $this->url('pause', $workflow), []],
            ['resume', 'POST', $this->url('resume', $workflow), []],
            ['archive', 'POST', $this->url('archive', $workflow), []],
            ['history', 'GET', $this->url('enrollments.index', $workflow), []],
            ['logs', 'GET', $this->url('enrollments.logs', $workflow, $enrollment), []],
            ['stop all', 'POST', $this->url('stop-all', $workflow), []],
            ['manual enrollment', 'POST', $this->url('enrollments.manual', $workflow), ['contact_uids' => [$contact->uid], 'confirmed' => true]],
            ['simulate', 'POST', $this->url('simulate', $workflow), ['contact_uid' => $contact->uid]],
            ['test contacts', 'GET', $this->url('test-contacts', $workflow), []],
        ];
    }

    private function refusedEverywhere(Customer $actor, AutomationWorkflow $workflow, AutomationEnrollment $enrollment, Contacts $contact): void
    {
        $before = [
            'status' => $workflow->fresh()->status,
            'published' => $workflow->fresh()->published_version_id,
            'enrollments' => AutomationEnrollment::query()->count(),
        ];

        $this->authenticateAs($actor);

        foreach ($this->operations($workflow, $enrollment, $contact) as [$label, $method, $url, $body]) {
            $response = $label === 'open' ? $this->get($url) : $this->callJson($method, $url, $body);

            $this->assertSame(404, $response->getStatusCode(), "{$label} must read as an unknown workflow.");
        }

        $fresh = $workflow->fresh();
        $this->assertSame($before['status'], $fresh->status, 'No state changed.');
        $this->assertSame($before['published'], $fresh->published_version_id);
        $this->assertSame($before['enrollments'], AutomationEnrollment::query()->count());
        Bus::assertNotDispatched(EnrollWorkflowContact::class);
    }

    // =================================================================
    // Refused
    // =================================================================

    public function test_selected_location_staff_cannot_reach_any_operation_of_a_workflow_bound_to_another_location(): void
    {
        $bound = $this->live($this->uptown);
        $enrollment = $this->journey($bound, $this->uptown);
        $contact = $this->contactAt($this->uptown, '14155554001');
        Bus::fake([EnrollWorkflowContact::class, AdvanceWorkflowEnrollment::class]);

        $this->refusedEverywhere($this->staffDowntown, $bound, $enrollment, $contact);
    }

    public function test_selected_location_staff_cannot_operate_a_business_wide_workflow(): void
    {
        $wide = $this->live(null);
        $enrollment = $this->journey($wide, $this->uptown);
        $contact = $this->contactAt($this->downtown, '14155554002');
        Bus::fake([EnrollWorkflowContact::class, AdvanceWorkflowEnrollment::class]);

        $this->refusedEverywhere($this->staffDowntown, $wide, $enrollment, $contact);
    }

    public function test_manual_enrollment_into_an_unreachable_workflow_queues_nothing_and_looks_up_no_contact(): void
    {
        $bound = $this->live($this->uptown);
        $theirs = $this->contactAt($this->uptown, '14155554003');
        Bus::fake([EnrollWorkflowContact::class, AdvanceWorkflowEnrollment::class]);

        $this->authenticateAs($this->staffDowntown);
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        $this->callJson('POST', $this->url('enrollments.manual', $bound), ['contact_uids' => [$theirs->uid], 'confirmed' => true])->assertStatus(404);

        Bus::assertNotDispatched(EnrollWorkflowContact::class);
        $this->assertSame([], array_filter($queries, fn (string $sql) => str_contains($sql, 'from `contacts`') && str_contains($sql, '`uid`')), 'The contact was never even looked up.');
        $this->assertSame(0, $this->enrollmentCount($bound));
    }

    public function test_forged_ids_fail_closed_across_workflows(): void
    {
        $mine = $this->live($this->downtown);
        $theirs = $this->live($this->uptown);
        $theirJourney = $this->journey($theirs, $this->uptown);
        $this->authenticateAs($this->staffDowntown);

        // Their run, addressed through MY workflow's path.
        $this->callJson('GET', $this->url('enrollments.logs', $mine, $theirJourney))->assertStatus(404);
        // Their workflow's own path.
        $this->callJson('GET', $this->url('enrollments.logs', $theirs, $theirJourney))->assertStatus(404);
        // A uid that does not exist reads identically.
        $this->callJson('GET', $this->routeUrl('settings', $this->workspace, $this->business, 'no-such-workflow'))->assertStatus(404);
    }

    public function test_the_workflow_list_shows_only_what_the_actor_may_operate(): void
    {
        $here = $this->live($this->downtown, 'Here');
        $there = $this->live($this->uptown, 'There');
        $wide = $this->live(null, 'Wide');
        $names = fn () => collect($this->callJson('GET', $this->routeUrl('index', $this->workspace, $this->business))->assertOk()->json('workflows'))->pluck('uid')->all();

        $this->authenticateAs($this->owner);
        $this->assertEqualsCanonicalizing([$here->uid, $there->uid, $wide->uid], $names());

        $this->authenticateAs($this->staffAll);
        $this->assertEqualsCanonicalizing([$here->uid, $there->uid, $wide->uid], $names());

        $this->authenticateAs($this->staffDowntown);
        $this->assertSame([$here->uid], $names(), 'Neither Uptown\'s nor the Business-wide workflow is even named.');
    }

    public function test_an_unpublished_draft_is_the_creators_or_bound_to_a_location_the_actor_reaches(): void
    {
        // The owner's unscoped, never-published draft: not the staff member's to open.
        $this->authenticateAs($this->owner);
        $created = $this->callJson('POST', $this->routeUrl('store', $this->workspace, $this->business), ['name' => 'Owner draft', 'trigger_type' => 'manual_enrollment'])->assertCreated();
        $ownersDraft = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();

        // The staff member's own unscoped draft: theirs, so they can open it and pick a Location.
        $this->authenticateAs($this->staffDowntown);
        $created = $this->callJson('POST', $this->routeUrl('store', $this->workspace, $this->business), ['name' => 'Staff draft', 'trigger_type' => 'manual_enrollment'])->assertCreated();
        $staffDraft = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();

        $this->callJson('GET', $this->url('draft.show', $ownersDraft))->assertStatus(404);
        $this->callJson('GET', $this->url('draft.show', $staffDraft))->assertOk();
        $this->get($this->url('show', $staffDraft))->assertOk();

        $listed = collect($this->callJson('GET', $this->routeUrl('index', $this->workspace, $this->business))->assertOk()->json('workflows'))->pluck('uid')->all();
        $this->assertSame([$staffDraft->uid], $listed);
    }

    // =================================================================
    // Allowed — unchanged
    // =================================================================

    public function test_selected_location_staff_operate_a_workflow_bound_to_their_own_location(): void
    {
        $bound = $this->live($this->downtown);
        $enrollment = $this->journey($bound, $this->downtown);
        $here = $this->contactAt($this->downtown, '14155554011');
        Bus::fake([EnrollWorkflowContact::class, AdvanceWorkflowEnrollment::class]);

        $this->authenticateAs($this->staffDowntown);

        $this->get($this->url('show', $bound))->assertOk();
        $this->callJson('GET', $this->url('enrollments.index', $bound))->assertOk();
        $this->callJson('GET', $this->url('enrollments.logs', $bound, $enrollment))->assertOk();
        $this->callJson('POST', $this->url('pause', $bound))->assertOk();
        $this->assertSame(WorkflowStatus::Paused, $bound->fresh()->status);
        $this->callJson('POST', $this->url('resume', $bound))->assertOk();
        $this->callJson('POST', $this->url('simulate', $bound), ['contact_uid' => $here->uid])->assertOk();
        $this->callJson('POST', $this->url('enrollments.manual', $bound), ['contact_uids' => [$here->uid], 'confirmed' => true])->assertStatus(202);
        Bus::assertDispatched(EnrollWorkflowContact::class, 1);
    }

    public function test_owner_and_all_locations_staff_operate_every_workflow(): void
    {
        $bound = $this->live($this->uptown);
        $wide = $this->live(null);
        $boundRun = $this->journey($bound, $this->uptown);

        foreach ([$this->owner, $this->staffAll] as $actor) {
            $this->authenticateAs($actor);

            foreach ([$bound, $wide] as $workflow) {
                $this->get($this->url('show', $workflow))->assertOk();
                $this->callJson('GET', $this->url('enrollments.index', $workflow))->assertOk();
                $this->callJson('POST', $this->url('pause', $workflow))->assertOk();
                $this->callJson('POST', $this->url('resume', $workflow))->assertOk();
            }

            $this->callJson('GET', $this->url('enrollments.logs', $bound, $boundRun))->assertOk();
        }
    }

    public function test_authority_follows_the_published_scope_not_where_the_contact_is_now(): void
    {
        $bound = $this->live($this->downtown);
        $enrollment = $this->journey($bound, $this->downtown);

        // The journey's contact has since moved to Uptown: the workflow's scope has not.
        DB::table('contacts')->where('id', $enrollment->contact_id)->update(['location_id' => $this->uptown->id]);

        $this->authenticateAs($this->staffDowntown);
        $this->callJson('GET', $this->url('enrollments.index', $bound))->assertOk();
        $this->callJson('GET', $this->url('enrollments.logs', $bound, $enrollment))->assertOk();
    }
}
