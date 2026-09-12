<?php

namespace Tests\Feature\Automations\Workflow\Actions;

use App\Enums\Automation\Workflow\EnrollmentStatus;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Executors\InternalNotificationNodeExecutor;
use App\Library\Automation\Workflow\Runtime\NodeExecutorRegistry;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\Business;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Models\WorkspaceMembershipBusiness;
use App\Notifications\WorkflowInternalNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-B — "Notify the team".
 *
 * Everything worth testing here is about the recipient SET. A notification that
 * reaches one person too few is an annoyance; one that reaches a person from
 * another Workspace, or a member who was deliberately given access to only some
 * Businesses, is a tenancy leak that happens to arrive by email.
 */
class InternalNotificationExecutorTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsActionWorkflows;

    private function user(string $label): User
    {
        return User::create([
            'first_name' => $label,
            'last_name' => 'Member',
            'email' => strtolower($label) . uniqid('', true) . '@example.test',
            'status' => true,
            'is_admin' => false,
            'is_customer' => true,
            'active_portal' => 'customer',
        ]);
    }

    private function memberWithScope(
        Workspace $workspace,
        User $user,
        WorkspaceBusinessAccessScope $scope,
        bool $isActive = true,
        ?Business $assigned = null,
    ): WorkspaceMembership {
        $membership = WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceMembershipRole::Staff->value,
            'business_access_scope' => $scope->value,
            'is_active' => $isActive,
        ]);

        if ($assigned !== null) {
            WorkspaceMembershipBusiness::create([
                'workspace_membership_id' => $membership->id,
                'business_id' => $assigned->id,
            ]);
        }

        return $membership;
    }

    /** @return array{0: Business, 1: Workspace, 2: \App\Models\AutomationWorkflow, 3: \App\Models\Contacts} */
    private function notifyingWorkflow(): array
    {
        [, $business, $workspace] = $this->entitledTenant();
        [$workflow] = $this->publishWorkflow($business, [$this->notificationStep('A contact arrived.'), $this->endStep()]);
        $contact = $this->contactFor($business);

        return [$business, $workspace, $workflow, $contact];
    }

    private function runWorkflow(\App\Models\AutomationWorkflow $workflow, \App\Models\Contacts $contact): \App\Models\AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, (string) $contact->id);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $enrollment->fresh();
    }

    /** 13. The exact recipient set: the owner and everyone who can see the Business. */
    public function test_the_exact_recipient_set_is_notified(): void
    {
        [$business, $workspace, $workflow, $contact] = $this->notifyingWorkflow();

        $wide = $this->user('Wide');
        $this->memberWithScope($workspace, $wide, WorkspaceBusinessAccessScope::All);

        $assigned = $this->user('Assigned');
        $this->memberWithScope($workspace, $assigned, WorkspaceBusinessAccessScope::Selected, assigned: $business);

        Notification::fake();

        $enrollment = $this->runWorkflow($workflow, $contact);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);

        $ownerId = (int) $business->customer_id;

        Notification::assertSentTo(User::find($ownerId), WorkflowInternalNotification::class);
        Notification::assertSentTo($wide, WorkflowInternalNotification::class);
        Notification::assertSentTo($assigned, WorkflowInternalNotification::class);

        Notification::assertCount(3);
    }

    /** 14a. An inactive membership is not a recipient. */
    public function test_an_inactive_member_is_excluded(): void
    {
        [, $workspace, $workflow, $contact] = $this->notifyingWorkflow();

        $inactive = $this->user('Inactive');
        $this->memberWithScope($workspace, $inactive, WorkspaceBusinessAccessScope::All, isActive: false);

        Notification::fake();

        $this->runWorkflow($workflow, $contact);

        Notification::assertNotSentTo($inactive, WorkflowInternalNotification::class);
        Notification::assertCount(1);
    }

    /** 14b. A `selected` member without this Business assigned is not a recipient. */
    public function test_a_member_without_access_to_this_business_is_excluded(): void
    {
        [$business, $workspace, $workflow, $contact] = $this->notifyingWorkflow();

        // Scope `selected`, but assigned to a DIFFERENT Business in the same
        // workspace — the case a scope-only check would get wrong.
        // workspace_id and status are guarded on Business — they are tenancy and
        // lifecycle state, not mass-assignable input — so they are set directly.
        $otherBusiness = new Business();
        $otherBusiness->uid = (string) \Illuminate\Support\Str::uuid();
        $otherBusiness->workspace_id = $workspace->id;
        $otherBusiness->customer_id = $business->customer_id;
        $otherBusiness->name = 'Second Business';
        $otherBusiness->status = $business->status;
        $otherBusiness->save();

        $elsewhere = $this->user('Elsewhere');
        $this->memberWithScope($workspace, $elsewhere, WorkspaceBusinessAccessScope::Selected, assigned: $otherBusiness);

        Notification::fake();

        $this->runWorkflow($workflow, $contact);

        Notification::assertNotSentTo($elsewhere, WorkflowInternalNotification::class);
        Notification::assertCount(1);
    }

    /** 14c. A member of another Workspace entirely is never a candidate. */
    public function test_a_member_of_another_workspace_is_excluded(): void
    {
        [, , $workflow, $contact] = $this->notifyingWorkflow();

        [, , $otherWorkspace] = $this->entitledTenant();
        $outsider = $this->user('Outsider');
        $this->memberWithScope($otherWorkspace, $outsider, WorkspaceBusinessAccessScope::All);

        Notification::fake();

        $this->runWorkflow($workflow, $contact);

        Notification::assertNotSentTo($outsider, WorkflowInternalNotification::class);
        Notification::assertCount(1);
    }

    /** 15. The owner who is also a member is notified once, not twice. */
    public function test_a_recipient_is_never_notified_twice(): void
    {
        [$business, $workspace, $workflow, $contact] = $this->notifyingWorkflow();

        $owner = User::find((int) $business->customer_id);

        // The owner also holds a membership — the ordinary case, and the one
        // that produces a duplicate if recipients are a list rather than a set.
        $this->memberWithScope($workspace, $owner, WorkspaceBusinessAccessScope::All);

        Notification::fake();

        $this->runWorkflow($workflow, $contact);

        Notification::assertSentToTimes($owner, WorkflowInternalNotification::class, 1);
        Notification::assertCount(1);
    }

    /** 16. Both contracted channels, and only those. */
    public function test_the_notification_uses_the_database_and_mail_channels(): void
    {
        [$business, , $workflow, $contact] = $this->notifyingWorkflow();

        Notification::fake();

        $this->runWorkflow($workflow, $contact);

        Notification::assertSentTo(
            User::find((int) $business->customer_id),
            WorkflowInternalNotification::class,
            function (WorkflowInternalNotification $notification, array $channels): bool {
                $this->assertEqualsCanonicalizing(['database', 'mail'], $channels);

                return true;
            },
        );
    }

    /** 16b. And the database payload really is written, identifying the work. */
    public function test_the_database_channel_records_an_identifiable_payload(): void
    {
        [$business, , $workflow, $contact] = $this->notifyingWorkflow();

        // Only the MAILER is faked here, not the notification system: the point
        // of this test is that the `database` channel genuinely writes a row, so
        // the notification has to be delivered for real.
        \Illuminate\Support\Facades\Mail::fake();

        $enrollment = $this->runWorkflow($workflow, $contact);

        $this->assertSame(
            EnrollmentStatus::Completed,
            $enrollment->status,
            'step run: ' . json_encode(DB::table('automation_step_runs')
                ->where('node_type', 'internal_notification')->first()),
        );

        $row = DB::table('platform_database_notifications')
            ->where('type', WorkflowInternalNotification::class)
            ->where('notifiable_id', (int) $business->customer_id)
            ->first();

        $this->assertNotNull($row, 'The database channel must persist the notification.');

        $data = json_decode((string) $row->data, true);

        $this->assertSame((int) $business->id, $data['business_id']);
        $this->assertSame('A contact arrived.', $data['message']);
        $this->assertSame((string) $business->name, $data['business_name']);

        // Identifiable, but the contact's full number does not travel by email.
        $this->assertStringContainsString('*', $data['contact']);
        $this->assertStringNotContainsString((string) $contact->phone, $data['contact']);
    }

    /**
     * A notified journey continues rather than ending at the step.
     *
     * The executor also has an empty-recipient guard, which is deliberately NOT
     * tested here: `businesses.customer_id` is a foreign key onto `users`, so a
     * Business with no owner cannot be constructed, and every membership path
     * narrows an already-non-empty set. The guard is defence in depth against a
     * future where that stops being true — proving it would mean breaking the
     * constraint that makes it unreachable, which tests the fixture rather than
     * the code.
     */
    public function test_the_journey_continues_past_the_notification(): void
    {
        [, , $workflow, $contact] = $this->notifyingWorkflow();

        Notification::fake();

        $enrollment = $this->runWorkflow($workflow, $contact);

        $this->assertSame(EnrollmentStatus::Completed, $enrollment->status);

        $stepRun = DB::table('automation_step_runs')->where('node_type', 'internal_notification')->first();
        $this->assertSame(\App\Enums\Automation\Workflow\StepRunStatus::Succeeded->value, $stepRun->status);
        $this->assertStringContainsString('Notified', (string) $stepRun->safe_result_summary);
    }

    public function test_the_registry_resolves_this_executor(): void
    {
        $this->assertInstanceOf(
            InternalNotificationNodeExecutor::class,
            app(NodeExecutorRegistry::class)
                ->for(\App\Enums\Automation\Workflow\WorkflowNodeType::InternalNotification),
        );
    }
}
