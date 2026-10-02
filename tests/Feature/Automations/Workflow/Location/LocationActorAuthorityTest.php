<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Workspace\LocationAccessScope;
use App\Library\Automation\Workflow\WorkflowLocationAuthority;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Workspace;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Automations Location run-scope — WHO may scope a workflow to what.
 *
 * The platform Location ACL (LocationAccessGuard) decides, on the server, at save
 * and at publish: an owner or an all-Locations member may bind to any Location and
 * may publish Business-wide; a member with selected Locations may bind only to
 * their own and may NOT publish a Business-wide workflow (authority over every
 * Location). The same ACL narrows the Test-workflow contact picker and simulate.
 */
class LocationActorAuthorityTest extends TestCase
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

    protected function setUp(): void
    {
        parent::setUp();

        [$this->owner, $business, $this->workspace] = $this->formsTenant();
        $this->business = $this->activate($business);
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->staffDowntown = $this->staffGrantedOnly($this->workspace, $this->downtown);
    }

    private function as(Customer $customer): void
    {
        $this->authenticateAs($customer);
    }

    /** A draft authored by the owner on the manual trigger, scoped as given (null = Business-wide). */
    private function ownersDraft(?int $locationId): AutomationWorkflow
    {
        $this->as($this->owner);
        $created = $this->callJson('POST', $this->routeUrl('store', $this->workspace, $this->business), ['name' => 'Flow ' . uniqid(), 'trigger_type' => 'manual_enrollment'])->assertCreated();
        $workflow = AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();
        $this->saveScope($workflow, $locationId)->assertOk();

        return $workflow;
    }

    /** An unscoped, never-published draft created by `$creator` — theirs to open and scope. */
    private function draftBy(Customer $creator): AutomationWorkflow
    {
        $this->as($creator);
        $created = $this->callJson('POST', $this->routeUrl('store', $this->workspace, $this->business), ['name' => 'Flow ' . uniqid(), 'trigger_type' => 'manual_enrollment'])->assertCreated();

        return AutomationWorkflow::query()->where('uid', $created->json('workflow.uid'))->firstOrFail();
    }

    private function saveScope(AutomationWorkflow $workflow, ?int $locationId): \Illuminate\Testing\TestResponse
    {
        $draft = $this->callJson('GET', $this->routeUrl('draft.show', $this->workspace, $this->business, $workflow))->assertOk()->json();
        $definition = $draft['definition'];
        $definition['root']['config']['business_location_id'] = $locationId;
        $definition['root']['next'] = [$this->endStep()];

        return $this->callJson('PUT', $this->routeUrl('draft.autosave', $this->workspace, $this->business, $workflow), [
            'definition' => $definition,
            'definition_revision' => $draft['revision'],
        ]);
    }

    private function publish(AutomationWorkflow $workflow): \Illuminate\Testing\TestResponse
    {
        return $this->callJson('POST', $this->routeUrl('publish', $this->workspace, $this->business, $workflow));
    }

    private function storedScope(AutomationWorkflow $workflow): mixed
    {
        return $workflow->fresh()->draftVersion()?->definition['root']['config']['business_location_id'] ?? null;
    }

    /** @return array<string, mixed> */
    private function builderData(AutomationWorkflow $workflow): array
    {
        $html = $this->get($this->routeUrl('show', $this->workspace, $this->business, $workflow))->assertOk()->getContent();
        preg_match('#<script type="application/json" id="wf-builder-data">(.*?)</script>#s', $html, $match);

        return json_decode(html_entity_decode($match[1]), true);
    }

    // =================================================================
    // The picker
    // =================================================================

    public function test_an_owner_is_offered_every_location_and_whole_business(): void
    {
        $workflow = $this->ownersDraft(null);

        $data = $this->builderData($workflow);

        $this->assertEqualsCanonicalizing([(int) $this->downtown->id, (int) $this->uptown->id], array_column($data['catalogs']['locations'], 'id'));
        $this->assertTrue($data['locationScope']['businessWide']);
    }

    public function test_selected_location_staff_are_offered_only_their_locations_and_no_whole_business(): void
    {
        $workflow = $this->draftBy($this->staffDowntown);

        $data = $this->builderData($workflow);

        $this->assertSame([(int) $this->downtown->id], array_column($data['catalogs']['locations'], 'id'), 'Uptown is not theirs, so it is not even named.');
        $this->assertFalse($data['locationScope']['businessWide']);
    }

    // =================================================================
    // Save and publish, on the server
    // =================================================================

    public function test_a_forged_location_id_is_never_saved_for_staff_who_cannot_reach_it(): void
    {
        $workflow = $this->draftBy($this->staffDowntown);

        $this->saveScope($workflow, (int) $this->uptown->id)->assertStatus(422);
        $this->assertNull($this->storedScope($workflow), 'The forged Location did not reach the draft.');

        $this->saveScope($workflow, (int) $this->downtown->id)->assertOk();
        $this->assertSame((int) $this->downtown->id, (int) $this->storedScope($workflow));

        // A draft with no scope yet is allowed: nothing is live until it publishes.
        $this->saveScope($workflow, null)->assertOk();
    }

    public function test_selected_location_staff_cannot_publish_a_workflow_bound_to_a_location_they_cannot_reach(): void
    {
        $workflow = $this->ownersDraft((int) $this->uptown->id);
        $this->as($this->staffDowntown);

        // The operation gate refuses the whole workflow first (404, as for an unknown uid)...
        $this->publish($workflow)->assertStatus(404);
        $this->assertNull($workflow->fresh()->published_version_id);

        // ...and the publisher itself still refuses it, as defence in depth.
        try {
            app(\App\Library\Automation\Workflow\WorkflowPublisher::class)->publish($workflow->fresh(), (int) $this->staffDowntown->user_id);
            $this->fail('The publisher must refuse an unreachable Location.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('You do not have access to that location.', json_encode($exception->errors()));
        }

        $this->assertNull($workflow->fresh()->published_version_id);
    }

    public function test_selected_location_staff_can_publish_for_their_own_location(): void
    {
        $workflow = $this->ownersDraft((int) $this->downtown->id);
        $this->as($this->staffDowntown);

        $this->publish($workflow)->assertOk()->assertJsonPath('status', 'published');
    }

    public function test_selected_location_staff_cannot_publish_a_business_wide_workflow(): void
    {
        // Their own unscoped draft: they may open it, but may not publish it Business-wide.
        $workflow = $this->draftBy($this->staffDowntown);

        $this->publish($workflow)->assertStatus(422)->assertJsonFragment([
            'Choose one of your locations. A whole-business workflow runs for every location, so it needs access to all of them.',
        ]);

        $this->assertNull($workflow->fresh()->published_version_id, 'The generic automations capability does not buy authority over every Location.');
    }

    public function test_owner_and_all_locations_staff_can_publish_business_wide(): void
    {
        $this->as($this->owner);
        $this->publish($this->ownersDraft(null))->assertOk();

        $allStaff = $this->staffWithFullReach($this->workspace);
        $workflow = $this->ownersDraft(null);
        $this->as($allStaff);
        $this->publish($workflow)->assertOk();
    }

    public function test_full_reach_is_computed_against_the_locations_that_exist_now(): void
    {
        // Selected Locations that happen to cover every Location: full reach.
        $both = $this->staffGrantedOnly($this->workspace, $this->downtown);
        app(WorkspaceMembershipLocationRepository::class)->assign(
            \App\Models\WorkspaceMembership::query()->where('user_id', $both->user_id)->firstOrFail(),
            $this->uptown,
        );
        $authority = app(WorkflowLocationAuthority::class);

        $this->assertTrue($authority->hasFullReach((int) $both->user_id, $this->business));
        $this->assertFalse($authority->hasFullReach((int) $this->staffDowntown->user_id, $this->business));

        // A Location added later withdraws it.
        $this->formsLocation($this->business, 'Midtown');
        $this->assertFalse($authority->hasFullReach((int) $both->user_id, $this->business));
        $this->assertTrue($authority->hasFullReach((int) $this->owner->user_id, $this->business));
    }

    public function test_an_actor_with_no_location_reach_can_bind_nothing(): void
    {
        $authority = app(WorkflowLocationAuthority::class);
        $stranger = $this->outsider();

        $this->assertSame([], $authority->reachableIds((int) $stranger->user_id, $this->business));
        $this->assertSame(LocationAccessScope::Selected, \App\Models\WorkspaceMembership::query()->where('user_id', $this->staffDowntown->user_id)->first()->location_access_scope);
    }

    // =================================================================
    // Test workflow
    // =================================================================

    private function contactAt(?BusinessLocation $location, string $phone): Contacts
    {
        $contact = $this->crmContact($this->business, [], $phone);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location?->id]);

        return $contact->fresh();
    }

    public function test_the_test_contact_picker_and_simulate_honour_the_actors_location_reach(): void
    {
        $workflow = $this->draftBy($this->staffDowntown);
        $here = $this->contactAt($this->downtown, '14155553001');
        $there = $this->contactAt($this->uptown, '14155553002');
        $nowhere = $this->contactAt(null, '14155553003');

        $pickerUids = fn () => collect($this->callJson('GET', $this->routeUrl('test-contacts', $this->workspace, $this->business, $workflow))->assertOk()->json('contacts'))->pluck('uid')->all();

        $this->as($this->owner);
        $this->assertEqualsCanonicalizing([$here->uid, $there->uid, $nowhere->uid], $pickerUids(), 'An all-Locations actor sees every contact.');
        $this->callJson('POST', $this->routeUrl('simulate', $this->workspace, $this->business, $workflow), ['contact_uid' => $there->uid])->assertOk();

        $this->as($this->staffDowntown);
        $this->assertSame([$here->uid], $pickerUids(), 'Selected-Location staff see only contacts in their Location.');
        $this->callJson('POST', $this->routeUrl('simulate', $this->workspace, $this->business, $workflow), ['contact_uid' => $here->uid])->assertOk();

        foreach ([$there, $nowhere] as $unreachable) {
            $this->callJson('POST', $this->routeUrl('simulate', $this->workspace, $this->business, $workflow), ['contact_uid' => $unreachable->uid])
                ->assertStatus(404);
        }
    }

    public function test_a_bound_workflows_picker_offers_only_that_locations_contacts(): void
    {
        $workflow = $this->ownersDraft((int) $this->downtown->id);
        $here = $this->contactAt($this->downtown, '14155553011');
        $this->contactAt($this->uptown, '14155553012');

        $uids = collect($this->callJson('GET', $this->routeUrl('test-contacts', $this->workspace, $this->business, $workflow))->assertOk()->json('contacts'))->pluck('uid')->all();

        $this->assertSame([$here->uid], $uids, 'Even the owner is offered only contacts the bound workflow could ever take.');
    }
}
