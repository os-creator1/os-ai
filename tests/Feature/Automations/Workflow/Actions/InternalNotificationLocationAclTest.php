<?php

namespace Tests\Feature\Automations\Workflow\Actions;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkflowInternalNotification;
use App\Repositories\Contracts\WorkspaceMembershipLocationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Location run-scope foundation — `InternalNotificationNodeExecutor` Location
 * ACL (independent review, pre-merge finding #3).
 *
 * `InternalNotificationExecutorTest` already proves the Business-level
 * recipient predicate (owner + Business-authorized members). Every fixture
 * there grants `location_access_scope = All`, so it cannot by itself prove
 * this lane's own narrowing. These tests exist to prove that narrowing:
 * a notification for a run pinned to Location A must never reach staff
 * authorized only for Location B, and must always reach the owner and any
 * All-scope or correctly-granted Selected-scope staff — using the
 * ENROLLMENT'S PINNED Location, never the Contact's current one.
 */
class InternalNotificationLocationAclTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsActionWorkflows;

    /** @return array{0: Business, 1: Workspace, 2: BusinessLocation, 3: BusinessLocation} */
    private function twoLocationTenant(): array
    {
        [, $business, $workspace] = $this->entitledTenant();
        $locationA = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        $locationB = $this->businessLocation($business);

        return [$business, $workspace, $locationA, $locationB];
    }

    /** @return array{0: AutomationWorkflow, 1: Contacts} */
    private function notifyingWorkflowAt(Business $business, BusinessLocation $location): array
    {
        [$workflow] = $this->publishWorkflow($business, [$this->notificationStep('A contact arrived.'), $this->endStep()]);
        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $location->id])->save();

        return [$workflow, $contact];
    }

    private function runAt(AutomationWorkflow $workflow, Contacts $contact, BusinessLocation $location): AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $location->id, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $enrollment->fresh();
    }

    private function selectedScopeStaff(Workspace $workspace, BusinessLocation ...$granted): User
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

        foreach ($granted as $location) {
            app(WorkspaceMembershipLocationRepository::class)->assign($membership, $location);
        }

        return $user;
    }

    /** The owner always receives, regardless of Location — the direct-owner authority is unconditional. */
    public function test_the_owner_is_notified_regardless_of_the_runs_pinned_location(): void
    {
        [$business, , $locationA] = $this->twoLocationTenant();
        [$workflow, $contact] = $this->notifyingWorkflowAt($business, $locationA);

        Notification::fake();

        $enrollment = $this->runAt($workflow, $contact, $locationA);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        Notification::assertSentTo(User::find((int) $business->customer_id), WorkflowInternalNotification::class);
    }

    /** All-scope staff receive a notification for a run at any of the Business's Locations. */
    public function test_all_scope_staff_are_notified_for_a_run_at_either_location(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();
        $staff = User::create([
            'first_name' => 'Wide', 'last_name' => 'Staff',
            'email' => 'wide-staff-' . uniqid('', true) . '@example.test',
            'status' => true, 'is_admin' => false, 'is_customer' => false, 'active_portal' => 'customer',
        ]);
        $membership = $this->addMember($workspace, $staff, WorkspaceMembershipRole::Staff);
        $membership->forceFill(['business_access_scope' => WorkspaceBusinessAccessScope::All->value])->save();

        [$workflowA, $contactA] = $this->notifyingWorkflowAt($business, $locationA);
        Notification::fake();
        $this->runAt($workflowA, $contactA, $locationA);
        Notification::assertSentTo($staff, WorkflowInternalNotification::class);

        [$workflowB, $contactB] = $this->notifyingWorkflowAt($business, $locationB);
        Notification::fake();
        $this->runAt($workflowB, $contactB, $locationB);
        Notification::assertSentTo($staff, WorkflowInternalNotification::class);
    }

    /** THE CENTRAL ONE. Selected-scope staff granted Location A receive a run pinned to A. */
    public function test_selected_scope_staff_receive_a_notification_for_their_granted_location(): void
    {
        [$business, $workspace, $locationA] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        [$workflow, $contact] = $this->notifyingWorkflowAt($business, $locationA);

        Notification::fake();

        $enrollment = $this->runAt($workflow, $contact, $locationA);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);
        Notification::assertSentTo($staff, WorkflowInternalNotification::class);
    }

    /**
     * THE CENTRAL ONE, inverted. Selected-scope staff granted ONLY Location B
     * must never receive a notification for a run pinned to Location A — the
     * exact disclosure the review finding describes.
     */
    public function test_selected_scope_staff_without_access_to_the_runs_location_are_never_notified(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationB);

        [$workflow, $contact] = $this->notifyingWorkflowAt($business, $locationA);

        Notification::fake();

        $enrollment = $this->runAt($workflow, $contact, $locationA);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status, 'The journey must still complete: no recipients is not a failure.');
        Notification::assertNotSentTo($staff, WorkflowInternalNotification::class);
    }

    /** Access granted at enrollment time but revoked before the notification runs must exclude the staff member. */
    public function test_access_revoked_after_enrollment_excludes_the_staff_member(): void
    {
        [$business, $workspace, $locationA] = $this->twoLocationTenant();
        $staff = $this->selectedScopeStaff($workspace, $locationA);

        [$workflow] = $this->publishWorkflow($business, [
            ['key' => (string) \Illuminate\Support\Str::uuid(), 'type' => 'wait', 'config' => ['mode' => 'duration', 'amount' => 1, 'unit' => 'days']],
            $this->notificationStep('A contact arrived.'),
            $this->endStep(),
        ]);
        $contact = $this->contactFor($business);
        $contact->forceFill(['location_id' => $locationA->id])->save();

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $locationA->id, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);
        $this->assertTrue($enrollment->fresh()->isWaiting(), 'Sanity: the run must be parked before the notification for this proof to mean anything.');

        // Revoke the grant while the run is parked, THEN let the notification fire.
        $membership = WorkspaceMembership::query()->where('user_id', $staff->id)->where('workspace_id', $workspace->id)->firstOrFail();
        app(WorkspaceMembershipLocationRepository::class)->unassign($membership, (int) $locationA->id);

        \Illuminate\Support\Facades\DB::table('automation_enrollments')->where('id', $enrollment->id)->update(['resume_at' => now()->subMinute()]);
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment::class]);
        app(\App\Library\Automation\Workflow\Runtime\WorkflowWakeService::class)->wakeDue();

        Notification::fake();
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        // A grant revoked before the notification runs must be re-checked
        // live, not trusted from enrollment time.
        Notification::assertNotSentTo($staff, WorkflowInternalNotification::class);
    }

    /**
     * A Contact transfer after enrollment must never change who is notified:
     * the executor uses the enrollment's PINNED Location, never the
     * Contact's current one.
     */
    public function test_a_contact_transfer_after_enrollment_does_not_change_who_is_notified(): void
    {
        [$business, $workspace, $locationA, $locationB] = $this->twoLocationTenant();
        $staffA = $this->selectedScopeStaff($workspace, $locationA);
        $staffB = $this->selectedScopeStaff($workspace, $locationB);

        [$workflow, $contact] = $this->notifyingWorkflowAt($business, $locationA);

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, $locationA->id, (string) $contact->id);

        // The Contact moves to Location B before the notification step runs.
        $contact->forceFill(['location_id' => $locationB->id])->save();

        Notification::fake();
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        Notification::assertSentTo($staffA, WorkflowInternalNotification::class);
        Notification::assertNotSentTo($staffB, WorkflowInternalNotification::class);
    }
}
