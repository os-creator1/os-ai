<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Workspace\LocationAccessScope;
use App\Enums\Workspace\WorkspaceBusinessAccessScope;
use App\Enums\Workspace\WorkspaceMembershipRole;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Models\AutomationEnrollment;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Models\Customer;
use App\Models\Workspace;
use App\Notifications\WorkflowInternalNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\Feature\Crm\Concerns\CreatesCrmFixtures;
use Tests\Feature\Forms\Concerns\CreatesFormsFixtures;
use Tests\TestCase;

/**
 * Automations Location run-scope — who an internal notification tells.
 *
 * The note names a contact and a workflow, so for a journey pinned to a Location
 * the audience is narrowed by the platform's own Location ACL: the owner always,
 * a member only if LocationAccessGuard lets them into THAT Location. A journey
 * with no pinned Location keeps the Business-level audience.
 */
class LocationNotificationRecipientsTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCrmFixtures;
    use CreatesFormsFixtures;
    use BuildsFoundationWorkflows;

    private Customer $owner;

    private Business $business;

    private Workspace $workspace;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private Customer $staffDowntown;

    private Customer $staffUptown;

    private Customer $staffAll;

    private Customer $inactive;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [$this->owner, $business, $this->workspace] = $this->formsTenant();
        $this->business = $this->activate($business);
        $this->downtown = $this->formsLocation($this->business, 'Downtown');
        $this->uptown = $this->formsLocation($this->business, 'Uptown');
        $this->staffDowntown = $this->staffGrantedOnly($this->workspace, $this->downtown);
        $this->staffUptown = $this->staffGrantedOnly($this->workspace, $this->uptown);
        $this->staffAll = $this->staffWithFullReach($this->workspace);

        $this->inactive = $this->createCustomer();
        $this->createMembership($this->workspace, $this->inactive->user, [
            'role' => WorkspaceMembershipRole::Staff,
            'business_access_scope' => WorkspaceBusinessAccessScope::All,
            'location_access_scope' => LocationAccessScope::All,
            'is_active' => false,
        ]);

        Notification::fake();
    }

    private function journey(?BusinessLocation $bound): AutomationWorkflow
    {
        return $this->triggerWorkflow(
            $this->business,
            WorkflowTriggerType::ManualEnrollment,
            $bound === null ? [] : ['business_location_id' => $bound->id],
            [['key' => (string) Str::uuid(), 'type' => 'internal_notification', 'config' => ['message' => 'Heads up']], $this->endStep()],
        );
    }

    private function runAt(AutomationWorkflow $workflow, ?BusinessLocation $pinned): AutomationEnrollment
    {
        $contact = $this->crmContact($this->business);
        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'k-' . $contact->id, 0, $pinned?->id === null ? null : (int) $pinned->id);
        $this->assertNotNull($enrollment);
        app(WorkflowAdvancer::class)->advance($enrollment);

        return $enrollment->fresh();
    }

    /** @return list<int> user ids told */
    private function told(): array
    {
        $ids = [];

        foreach ([$this->owner, $this->staffDowntown, $this->staffUptown, $this->staffAll, $this->inactive] as $customer) {
            if (Notification::sent($customer->user, WorkflowInternalNotification::class)->isNotEmpty()) {
                $ids[] = (int) $customer->user_id;
            }
        }

        sort($ids);

        return $ids;
    }

    private function ids(Customer ...$customers): array
    {
        $ids = array_map(fn (Customer $customer): int => (int) $customer->user_id, $customers);
        sort($ids);

        return $ids;
    }

    public function test_a_bound_journey_tells_only_the_owner_and_members_who_can_access_its_location(): void
    {
        $this->runAt($this->journey($this->downtown), $this->downtown);

        $this->assertSame(
            $this->ids($this->owner, $this->staffDowntown, $this->staffAll),
            $this->told(),
            'The Uptown-only member and the inactive member learn nothing about a Downtown contact.',
        );
    }

    public function test_the_other_locations_journey_tells_the_other_locations_staff(): void
    {
        $this->runAt($this->journey($this->uptown), $this->uptown);

        $this->assertSame($this->ids($this->owner, $this->staffUptown, $this->staffAll), $this->told());
    }

    public function test_a_journey_with_no_pinned_location_keeps_the_business_level_audience(): void
    {
        $this->runAt($this->journey(null), null);

        $this->assertSame(
            $this->ids($this->owner, $this->staffDowntown, $this->staffUptown, $this->staffAll),
            $this->told(),
            'Existing behaviour: owner plus every ACTIVE member of the Business.',
        );
    }

    public function test_a_business_wide_workflow_still_narrows_a_note_about_a_located_fact(): void
    {
        // The workflow is Business-wide, but THIS journey is pinned to Downtown by its fact.
        $this->runAt($this->journey(null), $this->downtown);

        $this->assertSame($this->ids($this->owner, $this->staffDowntown, $this->staffAll), $this->told());
    }
}
