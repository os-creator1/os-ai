<?php

namespace Tests\Feature\Automations\Workflow\Location;

use App\Enums\Automation\Workflow\StepRunStatus;
use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Runtime\WorkflowAdvancer;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\DTO\LocationSendContext;
use App\Models\AutomationEnrollment;
use App\Models\AutomationStepRun;
use App\Models\AutomationWorkflow;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\ContactGroups;
use App\Models\Contacts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\Actions\Support\BuildsActionWorkflows;
use Tests\Feature\Automations\Workflow\Foundations\Support\BuildsFoundationWorkflows;
use Tests\TestCase;

/**
 * Location-bound SMS — the blocker the previous pass documented.
 *
 * A run is pinned to ONE Location; its text must provably speak for it. A managed
 * number says which Locations use it (Settings -> Text messaging); a Business with
 * a single Location needs no choosing; a Business-wide workflow of a Business that
 * never assigned its number keeps working exactly as before; and nothing ever
 * borrows another Location's — or another Business's — sender.
 */
class LocationSmsSenderTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsFoundationWorkflows;
    use BuildsActionWorkflows;

    private Business $business;

    private BusinessLocation $downtown;

    private BusinessLocation $uptown;

    private ContactGroups $group;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [, $this->business] = $this->entitledTenant();
        $this->sendableChannel($this->business);
        $this->group = $this->contactGroup($this->business, 'Clients');
        $this->downtown = $this->location('Downtown');
    }

    private function location(string $name): BusinessLocation
    {
        return BusinessLocation::create(['business_id' => $this->business->id, 'name' => $name, 'service_mode' => 'storefront', 'country_code' => 'US']);
    }

    private function withTwoLocations(): void
    {
        $this->uptown = $this->location('Uptown');
    }

    /** Give the Business a managed number, optionally assigned to Locations. */
    private function managed(array $assignTo = []): void
    {
        $identity = $this->giveManagedIdentity($this->business);
        $number = app(BusinessMessagingIdentityResolver::class)->resolvePrimaryNumber($identity);

        if ($assignTo !== []) {
            app(BusinessMessagingIdentityResolver::class)->assignLocations($number, $this->business, array_map(fn (BusinessLocation $l): int => (int) $l->id, $assignTo));
        }
    }

    private function scope(string $mode, BusinessLocation ...$locations): array
    {
        return match ($mode) {
            'business' => [],
            'one' => ['business_location_id' => $locations[0]->id],
            'selected' => ['scope_mode' => 'selected', 'business_location_ids' => array_map(fn ($l) => (int) $l->id, $locations)],
        };
    }

    private function textingWorkflow(array $scope): AutomationWorkflow
    {
        return $this->triggerWorkflow(
            $this->business,
            WorkflowTriggerType::ContactCreated,
            ['contact_group_id' => $this->group->id] + $scope,
            [$this->smsStep('Hello there'), $this->endStep()],
        );
    }

    private function contactAt(BusinessLocation $location, string $phone): Contacts
    {
        $contact = $this->contact($this->business, $this->group, $phone);
        DB::table('contacts')->where('id', $contact->id)->update(['location_id' => $location->id]);

        return $contact->fresh();
    }

    private function runJourney(AutomationWorkflow $workflow, Contacts $contact, ?BusinessLocation $pinned): AutomationEnrollment
    {
        $enrollment = app(EnrollmentService::class)->enroll($workflow->fresh(), $contact, 'k-' . $contact->id, 0, $pinned?->id === null ? null : (int) $pinned->id);
        $this->assertNotNull($enrollment);
        app(WorkflowAdvancer::class)->advance($enrollment->fresh());

        return $enrollment->fresh();
    }

    private function smsStatus(AutomationEnrollment $enrollment): AutomationStepRun
    {
        return AutomationStepRun::query()->where('enrollment_id', $enrollment->id)->where('node_type', 'send_sms')->sole();
    }

    private function assertPublishRefused(array $scope): void
    {
        try {
            $this->textingWorkflow($scope);
            $this->fail('This scope cannot text yet, so it must not publish.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('text-message number is not set up', json_encode($exception->errors()));
        }
    }

    // =================================================================
    // A Business of ONE Location needs no choosing
    // =================================================================

    public function test_a_business_with_one_location_texts_for_a_location_limited_workflow_through_its_managed_number(): void
    {
        $this->managed();
        $workflow = $this->textingWorkflow($this->scope('one', $this->downtown));
        $core = $this->captureSendCore(1);

        $enrollment = $this->runJourney($workflow, $this->contactAt($this->downtown, '12025553001'), $this->downtown);

        $this->assertSame(StepRunStatus::Succeeded, $this->smsStatus($enrollment)->status);
        $context = $core->lastPayload()['location_send_context'];
        $this->assertInstanceOf(LocationSendContext::class, $context);
        $this->assertSame((int) $this->downtown->id, $context->locationId);
        $this->assertTrue($context->scopeBound);
    }

    public function test_a_byo_business_with_one_location_texts_for_a_location_limited_workflow(): void
    {
        $workflow = $this->textingWorkflow($this->scope('one', $this->downtown));
        $core = $this->captureSendCore(1);

        $enrollment = $this->runJourney($workflow, $this->contactAt($this->downtown, '12025553002'), $this->downtown);

        $this->assertSame(StepRunStatus::Succeeded, $this->smsStatus($enrollment)->status);
        $this->assertSame(1, $core->count());
    }

    // =================================================================
    // Several Locations: the number must be shown to be theirs
    // =================================================================

    public function test_with_several_locations_an_unassigned_number_cannot_back_a_location_limited_workflow(): void
    {
        $this->withTwoLocations();
        $this->managed();

        $this->assertPublishRefused($this->scope('one', $this->downtown));
        $this->assertPublishRefused($this->scope('selected', $this->downtown, $this->uptown));
    }

    public function test_assigning_the_number_to_the_locations_lets_the_workflow_publish_and_each_run_text_from_its_own_location(): void
    {
        $this->withTwoLocations();
        $this->managed([$this->downtown, $this->uptown]);

        $workflow = $this->textingWorkflow($this->scope('selected', $this->downtown, $this->uptown));
        $core = $this->captureSendCore(2);

        $this->runJourney($workflow, $this->contactAt($this->downtown, '12025553011'), $this->downtown);
        $this->assertSame((int) $this->downtown->id, $core->lastPayload()['location_send_context']->locationId);

        $this->runJourney($workflow, $this->contactAt($this->uptown, '12025553012'), $this->uptown);
        $this->assertSame((int) $this->uptown->id, $core->lastPayload()['location_send_context']->locationId, 'Each run speaks for its own pinned Location.');
    }

    public function test_one_unreached_location_in_a_list_keeps_the_workflow_from_publishing(): void
    {
        $this->withTwoLocations();
        $this->managed([$this->downtown]);

        $this->assertPublishRefused($this->scope('selected', $this->downtown, $this->uptown));
        $this->assertNotNull($this->textingWorkflow($this->scope('one', $this->downtown))->published_version_id);
    }

    public function test_a_byo_business_with_several_locations_cannot_back_a_location_limited_workflow(): void
    {
        $this->withTwoLocations();

        $this->assertPublishRefused($this->scope('one', $this->downtown));
    }

    public function test_a_number_reassigned_after_publish_stops_a_run_that_can_no_longer_prove_it(): void
    {
        $this->withTwoLocations();
        $this->managed([$this->downtown]);
        $workflow = $this->textingWorkflow($this->scope('one', $this->downtown));
        $contact = $this->contactAt($this->downtown, '12025553021');

        // The owner takes Downtown off the number after publish.
        $number = DB::table('business_messaging_numbers')->first();
        DB::table('business_messaging_number_locations')->where('business_messaging_number_id', $number->id)->delete();
        $core = $this->captureSendCore(0);

        $enrollment = $this->runJourney($workflow, $contact, $this->downtown);

        $step = $this->smsStatus($enrollment);
        $this->assertSame(StepRunStatus::Failed, $step->status);
        $this->assertSame('location_sender_unavailable', $step->safe_error_summary);
        $this->assertSame(0, $core->count());
    }

    // =================================================================
    // Business-wide workflows
    // =================================================================

    public function test_a_business_wide_workflow_of_a_business_that_never_assigned_its_number_is_unchanged(): void
    {
        $this->withTwoLocations();
        $this->managed();
        $workflow = $this->textingWorkflow([]);
        $core = $this->captureSendCore(2);

        $a = $this->runJourney($workflow, $this->contactAt($this->downtown, '12025553031'), $this->downtown);
        $b = $this->runJourney($workflow, $this->contactAt($this->uptown, '12025553032'), $this->uptown);

        $this->assertSame(StepRunStatus::Succeeded, $this->smsStatus($a)->status);
        $this->assertSame(StepRunStatus::Succeeded, $this->smsStatus($b)->status);
        $this->assertFalse($core->lastPayload()['location_send_context']->scopeBound);
        $this->assertSame((int) $this->uptown->id, $core->lastPayload()['location_send_context']->locationId, 'It still tells the core which Location the run is pinned to.');
    }

    public function test_a_business_wide_workflow_uses_the_pinned_locations_assignment_when_the_owner_made_one(): void
    {
        $this->withTwoLocations();
        $this->managed([$this->downtown]);
        $workflow = $this->textingWorkflow([]);
        $core = $this->captureSendCore(1);

        $served = $this->runJourney($workflow, $this->contactAt($this->downtown, '12025553041'), $this->downtown);
        $this->assertSame(StepRunStatus::Succeeded, $this->smsStatus($served)->status);

        $refused = $this->runJourney($workflow, $this->contactAt($this->uptown, '12025553042'), $this->uptown);
        $step = $this->smsStatus($refused);
        $this->assertSame(StepRunStatus::Failed, $step->status);
        $this->assertSame('location_sender_unavailable', $step->safe_error_summary, 'The number is assigned elsewhere, so Uptown cannot borrow it.');
        $this->assertSame(1, $core->count());
    }

    public function test_a_business_wide_run_with_no_pinned_location_cannot_use_a_location_assigned_number(): void
    {
        $this->withTwoLocations();
        $this->managed([$this->downtown]);
        $workflow = $this->textingWorkflow([]);
        $core = $this->captureSendCore(0);
        $contact = $this->contact($this->business, $this->group, '12025553051');

        $enrollment = $this->runJourney($workflow, $contact, null);

        $this->assertSame('location_sender_unavailable', $this->smsStatus($enrollment)->safe_error_summary);
        $this->assertSame(0, $core->count());
    }

    public function test_a_byo_business_wide_workflow_with_several_locations_is_unchanged(): void
    {
        $this->withTwoLocations();
        $workflow = $this->textingWorkflow([]);
        $core = $this->captureSendCore(1);

        $enrollment = $this->runJourney($workflow, $this->contactAt($this->uptown, '12025553061'), $this->uptown);

        $this->assertSame(StepRunStatus::Succeeded, $this->smsStatus($enrollment)->status);
        $this->assertSame(1, $core->count());
    }

    public function test_another_businesss_number_assignment_never_helps(): void
    {
        $this->withTwoLocations();
        $this->managed();

        // A second Business assigns ITS number to its own Locations; ours is untouched.
        [, $other] = $this->entitledTenant();
        $theirLocation = BusinessLocation::create(['business_id' => $other->id, 'name' => 'Theirs', 'service_mode' => 'storefront', 'country_code' => 'US']);
        $theirIdentity = $this->giveManagedIdentity($other);
        app(BusinessMessagingIdentityResolver::class)->assignLocations(
            app(BusinessMessagingIdentityResolver::class)->resolvePrimaryNumber($theirIdentity),
            $other,
            [(int) $theirLocation->id],
        );

        $this->assertPublishRefused($this->scope('one', $this->downtown));
    }
}
