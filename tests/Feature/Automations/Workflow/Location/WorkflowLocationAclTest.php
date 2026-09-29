<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\WorkflowDraftService;
use App\Library\Automation\Workflow\WorkflowPublisher;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Http\Support\CallsWorkflowRoutes;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Location run-scope foundation — lane contract §12: Location ACL for the
 * builder's scope selection, manual/test enrollment, and run history/logs.
 *
 * LocationAccessGuard (Contract 02/08B) is the one authority; nothing here
 * reimplements it — every test proves this lane's NEW consumers (workflow
 * scope publish, the manual-enrollment door, run history and logs) compose
 * correctly with that existing, unmodified guard.
 */
class WorkflowLocationAclTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use CallsWorkflowRoutes;

    /**
     * @return array{0: Business, 1: Workspace, 2: BusinessLocation, 3: BusinessLocation}
     */
    private function twoLocationTenant(): array
    {
        [, $business, $workspace] = $this->entitledTenant();

        // entitledTenant() already seeded ONE Location; find it, then add a
        // second — this lane's ACL tests need two distinct real Locations.
        $locationA = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $locationB = $this->businessLocation($business);

        return [$business, $workspace, $locationA, $locationB];
    }

    /** A Selected-scope staff member granted only $granted, active in $workspace. */
    private function selectedScopeStaff(Workspace $workspace, BusinessLocation $granted): User
    {
        $user = User::create([
            'first_name' => 'Selected',
            'last_name' => 'Staff',
            'email' => 'selected-staff-' . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => false,
            'active_portal' => 'customer',
        ]);

        $membership = $this->addMember($workspace, $user, WorkspaceMembershipRole::Staff);
        $membership->forceFill(['location_access_scope' => LocationAccessScope::Selected])->save();

        app(WorkspaceMembershipLocationRepository::class)->assign($membership, $granted);

        return $user;
    }

    private function publishedWorkflowDefinitionWithScope(Business $business, string $scope, array $locationIds): \App\Models\AutomationWorkflow
    {
        $drafts = app(WorkflowDraftService::class);
        $workflow = $drafts->createWorkflowWithDraft($business, 'Scoped workflow', WorkflowTriggerType::ContactCreated);
        $draft = $workflow->draftVersion();

        $definition = $drafts->starterDefinition(WorkflowTriggerType::ContactCreated);
        $definition['location_scope'] = $scope;
        $definition['location_ids'] = $locationIds;
        $definition['root']['next'] = [$this->endStep()];

        $drafts->autosave($draft, $definition, $draft->definition_revision);

        return $workflow->fresh();
    }

    // =================================================================
    // Builder publish — a Selected-scope staff member's own submission
    // =================================================================

    public function test_selected_scope_staff_cannot_publish_a_foreign_location_into_scope(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        $workflow = $this->publishedWorkflowDefinitionWithScope($business, 'selected', [$locationB->id]);

        $this->authenticateAsUser($staff);
        $result = $this->callJson('POST', $this->routeUrl('publish', $workspace, $business, $workflow));

        $result->assertStatus(422);
        $this->assertSame(
            null,
            $workflow->fresh()->published_version_id,
            'A staff member submitting a Location they cannot access must never publish.',
        );
    }

    public function test_selected_scope_staff_cannot_publish_all_locations_scope(): void
    {
        [$business, $workspace, $locationA] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        $workflow = $this->publishedWorkflowDefinitionWithScope($business, 'all', []);

        $this->authenticateAsUser($staff);
        $result = $this->callJson('POST', $this->routeUrl('publish', $workspace, $business, $workflow));

        $result->assertStatus(422);
        $this->assertNull(
            $workflow->fresh()->published_version_id,
            'A Selected-scope staff member must never widen a workflow to All Locations.',
        );
    }

    public function test_selected_scope_staff_can_publish_their_own_granted_location(): void
    {
        [$business, $workspace, $locationA] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        $workflow = $this->publishedWorkflowDefinitionWithScope($business, 'one', [$locationA->id]);

        $this->authenticateAsUser($staff);
        $this->callJson('POST', $this->routeUrl('publish', $workspace, $business, $workflow))
            ->assertSuccessful();

        $this->assertNotNull($workflow->fresh()->published_version_id, 'A staff member publishing their own granted Location must succeed.');
    }

    public function test_the_owner_may_publish_any_scope_regardless_of_staff_restrictions(): void
    {
        [$business, $workspace, , $locationB] = $this->twoLocationTenant();
        $workflow = $this->publishedWorkflowDefinitionWithScope($business, 'selected', [$locationB->id]);

        // The owner authenticates directly — no staff membership row at all.
        $this->authenticateAsCustomer($business->fresh()->customer);
        $this->callJson('POST', $this->routeUrl('publish', $workspace, $business, $workflow))
            ->assertSuccessful();
    }

    // =================================================================
    // Manual enrollment / test-contact picker (§12C)
    // =================================================================

    public function test_the_test_contact_picker_excludes_contacts_at_an_inaccessible_location(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        // Both Locations already exist by this point (twoLocationTenant()),
        // so Contacts::singleActiveLocationIdFor() is ambiguous (null) for
        // both — set each Contact's Location explicitly.
        $group = $this->contactGroup($business);
        $visible = $this->contact($business, $group, '12025559001');
        $visible->forceFill(['location_id' => $locationA->id])->save();
        $hidden = $this->contact($business, $group, '12025559002');
        $hidden->forceFill(['location_id' => $locationB->id])->save();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);

        // testContacts() itself gates on view_contact, on top of the
        // automations permission every other route here needs.
        $this->authenticateAsUser($staff, ['automations', 'view_contact']);
        $body = $this->callJson('GET', $this->routeUrl('test-contacts', $workspace, $business, $workflow))
            ->assertSuccessful()
            ->json();

        $uids = collect($body['contacts'])->pluck('uid')->all();
        $this->assertContains($visible->uid, $uids);
        $this->assertNotContains($hidden->uid, $uids, 'A Contact at a Location this staff member cannot access must never appear in the picker.');
    }

    public function test_a_forged_manual_enrollment_of_an_inaccessible_location_contact_fails_closed(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        $group = $this->contactGroup($business);
        $foreign = $this->contact($business, $group, '12025559003');
        $foreign->forceFill(['location_id' => $locationB->id])->save();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);

        $this->authenticateAsUser($staff);
        $this->callJson('POST', $this->routeUrl('enrollments.manual', $workspace, $business, $workflow), [
            'contact_uids' => [$foreign->uid],
            'confirmed' => true,
        ])->assertStatus(404);
    }

    public function test_a_staff_member_may_manually_enroll_a_contact_at_their_own_location(): void
    {
        [$business, $workspace, $locationA] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        $group = $this->contactGroup($business);
        $own = $this->contact($business, $group, '12025559004');
        $own->forceFill(['location_id' => $locationA->id])->save();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);

        $this->authenticateAsUser($staff);
        $this->callJson('POST', $this->routeUrl('enrollments.manual', $workspace, $business, $workflow), [
            'contact_uids' => [$own->uid],
            'confirmed' => true,
        ])->assertStatus(202);
    }

    // =================================================================
    // Run history and logs (§12D)
    // =================================================================

    public function test_enrollment_history_omits_runs_at_an_inaccessible_location(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        $group = $this->contactGroup($business);
        $ownContact = $this->contact($business, $group, '12025559005');
        $ownContact->forceFill(['location_id' => $locationA->id])->save();
        $foreignContact = $this->contact($business, $group, '12025559006');
        $foreignContact->forceFill(['location_id' => $locationB->id])->save();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $enrollments = app(EnrollmentService::class);
        $enrollments->enroll($workflow, $ownContact, $locationA->id, (string) $ownContact->id);
        $enrollments->enroll($workflow, $foreignContact, $locationB->id, (string) $foreignContact->id);

        $this->authenticateAsUser($staff);
        $body = $this->callJson('GET', $this->routeUrl('enrollments.index', $workspace, $business, $workflow))
            ->assertSuccessful()
            ->json();

        $uids = collect($body['enrollments'])->pluck('contact_uid')->all();
        $this->assertContains($ownContact->uid, $uids);
        $this->assertNotContains($foreignContact->uid, $uids, 'A run at a Location this staff member cannot access must never appear in history.');
    }

    public function test_the_owner_sees_every_locations_run_history(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();

        $group = $this->contactGroup($business);
        $contactA = $this->contact($business, $group, '12025559007');
        $contactA->forceFill(['location_id' => $locationA->id])->save();
        $contactB = $this->contact($business, $group, '12025559008');
        $contactB->forceFill(['location_id' => $locationB->id])->save();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $enrollments = app(EnrollmentService::class);
        $enrollments->enroll($workflow, $contactA, $locationA->id, (string) $contactA->id);
        $enrollments->enroll($workflow, $contactB, $locationB->id, (string) $contactB->id);

        $this->authenticateAsCustomer($business->fresh()->customer);
        $body = $this->callJson('GET', $this->routeUrl('enrollments.index', $workspace, $business, $workflow))
            ->assertSuccessful()
            ->json();

        $this->assertCount(2, $body['enrollments'], 'The owner must see every Location\'s runs.');
    }

    public function test_direct_logs_url_for_an_inaccessible_enrollment_fails_closed(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        $group = $this->contactGroup($business);
        $foreignContact = $this->contact($business, $group, '12025559009');
        $foreignContact->forceFill(['location_id' => $locationB->id])->save();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $foreignContact, $locationB->id, (string) $foreignContact->id);

        $this->authenticateAsUser($staff);
        $this->callJson('GET', $this->routeUrl('enrollments.logs', $workspace, $business, $workflow, $enrollment))
            ->assertStatus(404);
    }

    public function test_logs_for_an_accessible_enrollment_succeed(): void
    {
        [$business, $workspace, $locationA] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        $group = $this->contactGroup($business);
        $ownContact = $this->contact($business, $group, '12025559010');
        $ownContact->forceFill(['location_id' => $locationA->id])->save();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()]);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $ownContact, $locationA->id, (string) $ownContact->id);

        $this->authenticateAsUser($staff);
        $this->callJson('GET', $this->routeUrl('enrollments.logs', $workspace, $business, $workflow, $enrollment))
            ->assertSuccessful();
    }
}
