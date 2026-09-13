<?php

namespace Tests\Feature\Automations\Workflow\MessageReceived;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Events\Conversation\InboundMessageReceived;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Library\Automation\Workflow\WorkflowLimits;
use App\Listeners\Automation\Workflow\EnrollFromInboundMessage;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\MessageReceived\Support\BuildsInboundFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\TestCase;

/**
 * Automations V2-F §9.1 / T-WF-25 — "a message is received".
 *
 * The four locked rules, each proved in both directions: exactly one subscribed
 * contact, never a workflow's own output, a bounded causation chain, and the
 * same-workflow cooldown.
 */
class MessageReceivedTriggerTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use BuildsWorkflows;
    use BuildsInboundFixtures;

    protected function setUp(): void
    {
        parent::setUp();

        Bus::fake([AdvanceWorkflowEnrollment::class]);
    }

    // =================================================================
    // A genuine inbound message
    // =================================================================

    public function test_a_genuine_inbound_message_enrolls_once_and_starts_the_journey(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155551001');

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551001'));

        $this->assertSame(1, $result['enrolled']);
        $this->assertSame((int) $contact->id, $result['contact_id']);
        $this->assertSame(1, $this->enrollmentsFor($workflow));

        $enrollment = AutomationEnrollment::query()->sole();
        $this->assertSame(WorkflowTriggerType::MessageReceived, $enrollment->trigger_type);
        $this->assertStringStartsWith('report:', $enrollment->trigger_occurrence_key, 'The message itself is the occurrence.');
        $this->assertSame(0, (int) $enrollment->causation_depth);

        Bus::assertDispatched(AdvanceWorkflowEnrollment::class, 1);
    }

    public function test_the_managed_path_enrolls_through_the_same_source(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $this->contact($business, $this->contactGroup($business), '14155551002');

        // The managed event carries E.164 with a plus; it is normalized onto the
        // same digits every contact row and report uses.
        $result = $this->messageSource()->handleInboundMessage($this->managedInbound($business, '14155551002', 9001));

        $this->assertSame(1, $result['enrolled']);
        $this->assertSame('operation:9001', AutomationEnrollment::query()->sole()->trigger_occurrence_key);
        $this->assertSame(1, $this->enrollmentsFor($workflow));
    }

    public function test_a_duplicate_delivery_of_the_same_message_is_idempotent(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $this->contact($business, $this->contactGroup($business), '14155551003');

        $event = $this->legacyInbound($business, '14155551003');

        $this->assertSame(1, $this->messageSource()->handleInboundMessage($event)['enrolled']);

        // Mark the first journey finished so neither the active-contact guard nor
        // the cooldown is what refuses the second: the occurrence key must.
        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);
        $this->enrolledAt(AutomationEnrollment::query()->sole(), Carbon::now()->subDays(3));

        $second = $this->messageSource()->handleInboundMessage($event);

        $this->assertSame(0, $second['enrolled'], 'The same message is the same occurrence.');
        $this->assertSame(1, $this->enrollmentsFor($workflow));
    }

    // =================================================================
    // Rule 1 — exactly one subscribed contact
    // =================================================================

    public function test_two_subscribed_contacts_with_the_number_is_ambiguous_and_enrolls_nobody(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $this->contact($business, $this->contactGroup($business, 'One'), '14155551004');
        $this->contact($business, $this->contactGroup($business, 'Two'), '14155551004');

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551004'));

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(1, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_AMBIGUOUS_CONTACT] ?? 0);
        $this->assertSame(0, $this->enrollmentsFor($workflow));
    }

    public function test_an_unknown_number_enrolls_nobody(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551999'));

        $this->assertSame(1, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_AMBIGUOUS_CONTACT] ?? 0);
        $this->assertSame(0, $this->enrollmentsFor($workflow));
    }

    public function test_one_subscribed_match_beside_an_unsubscribed_one_is_not_ambiguous(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $subscribed = $this->contact($business, $this->contactGroup($business, 'Live'), '14155551005');
        $unsubscribed = $this->contact($business, $this->contactGroup($business, 'Old'), '14155551005');
        DB::table('contacts')->where('id', $unsubscribed->id)->update(['status' => 'unsubscribe']);

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551005'));

        $this->assertSame(1, $result['enrolled']);
        $this->assertSame((int) $subscribed->id, (int) AutomationEnrollment::query()->sole()->contact_id);
        $this->assertSame(1, $this->enrollmentsFor($workflow));
    }

    public function test_an_unsubscribed_contact_is_never_enrolled_by_a_message(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155551006');
        DB::table('contacts')->where('id', $contact->id)->update(['status' => 'unsubscribe']);

        $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551006'));

        $this->assertSame(0, $this->enrollmentsFor($workflow));
    }

    // =================================================================
    // Tenancy
    // =================================================================

    public function test_a_contact_in_another_business_cannot_be_enrolled(): void
    {
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();

        $workflowA = $this->messageReceivedWorkflow($businessA);

        // The number belongs to a contact of Business B only.
        $this->contact($businessB, $this->contactGroup($businessB), '14155551007');

        // A message attributed to A, from B's contact's number.
        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($businessA, '14155551007'));

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(1, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_AMBIGUOUS_CONTACT] ?? 0);
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_a_message_to_one_business_never_enrolls_into_anothers_workflow(): void
    {
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();

        $workflowB = $this->messageReceivedWorkflow($businessB);

        // The same number is a contact in BOTH Businesses.
        $this->contact($businessA, $this->contactGroup($businessA), '14155551008');
        $this->contact($businessB, $this->contactGroup($businessB), '14155551008');

        $this->messageSource()->handleInboundMessage($this->legacyInbound($businessA, '14155551008'));

        $this->assertSame(0, $this->enrollmentsFor($workflowB), "Business A's message must never reach Business B's workflow.");
    }

    public function test_an_unknown_business_enrolls_nobody(): void
    {
        [, $business] = $this->entitledTenant();
        $this->messageReceivedWorkflow($business);
        $this->contact($business, $this->contactGroup($business), '14155551009');

        $result = $this->messageSource()->handleInboundMessage(
            InboundMessageReceived::fromManagedOperation(999999, '+14155551009', 1),
        );

        $this->assertSame(1, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_NO_BUSINESS] ?? 0);
        $this->assertSame(0, AutomationEnrollment::query()->count());
    }

    public function test_only_message_received_workflows_listen(): void
    {
        [, $business] = $this->entitledTenant();
        [$contactCreated] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ContactCreated);
        $this->contact($business, $this->contactGroup($business), '14155551010');

        $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551010'));

        $this->assertSame(0, $this->enrollmentsFor($contactCreated));
    }

    public function test_a_paused_workflow_is_not_enrolled_by_a_message(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $this->contact($business, $this->contactGroup($business), '14155551011');
        DB::table('automation_workflows')->where('id', $workflow->id)->update(['status' => 'paused']);

        $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551011'));

        $this->assertSame(0, $this->enrollmentsFor($workflow));
    }

    // =================================================================
    // Rule 4 — the same-workflow cooldown
    // =================================================================

    public function test_a_second_message_inside_the_cooldown_does_not_re_enroll_into_the_same_workflow(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $this->contact($business, $this->contactGroup($business), '14155551012');

        $this->assertSame(1, $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551012'))['enrolled']);

        // Finished, so the active-contact guard is not what refuses the second.
        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);
        $this->enrolledAt(AutomationEnrollment::query()->sole(), Carbon::now()->subHours(WorkflowLimits::MESSAGE_RECEIVED_COOLDOWN_HOURS)->addMinute());

        // A DIFFERENT message — a new occurrence — still inside 24 hours.
        $second = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551012'));

        $this->assertSame(0, $second['enrolled']);
        $this->assertSame(1, $second['skipped'][MessageReceivedTriggerSource::SKIPPED_COOLDOWN] ?? 0);
        $this->assertSame(1, $this->enrollmentsFor($workflow));
    }

    public function test_the_cooldown_expires_after_exactly_its_hours(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $this->contact($business, $this->contactGroup($business), '14155551013');

        $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551013'));
        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);

        // Enrolled exactly 24 hours ago: the window is over.
        $this->enrolledAt(AutomationEnrollment::query()->sole(), Carbon::now()->subHours(WorkflowLimits::MESSAGE_RECEIVED_COOLDOWN_HOURS));

        $second = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551013'));

        $this->assertSame(1, $second['enrolled'], 'Once the cooldown has passed, a new message enrolls again.');
        $this->assertSame(2, $this->enrollmentsFor($workflow));
    }

    public function test_the_cooldown_is_per_workflow_not_per_contact(): void
    {
        [, $business] = $this->entitledTenant();
        $first = $this->messageReceivedWorkflow($business, null, 'First');
        $this->contact($business, $this->contactGroup($business), '14155551014');

        $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551014'));

        // A second workflow published after the first message is not in any
        // cooldown for this contact.
        $second = $this->messageReceivedWorkflow($business, null, 'Second');
        DB::table('automation_enrollments')->update(['status' => 'completed', 'completed_at' => now()]);

        $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551014'));

        $this->assertSame(1, $this->enrollmentsFor($first), 'The first workflow is still cooling down.');
        $this->assertSame(1, $this->enrollmentsFor($second), 'The second workflow has its own clock.');
    }

    public function test_an_enrollment_by_another_trigger_starts_no_cooldown(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155551015');

        // An enrollment into the same workflow row that did not come from a
        // received message (the trigger type is what the cooldown reads).
        $manual = app(EnrollmentService::class)->enroll($workflow, $contact, 'manual-occurrence');
        $this->assertNotNull($manual);
        DB::table('automation_enrollments')->where('id', $manual->id)->update([
            'trigger_type' => WorkflowTriggerType::ManualEnrollment->value,
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551015'));

        $this->assertSame(1, $result['enrolled']);
    }

    // =================================================================
    // Rule 2 — never a workflow's own output (T-WF-25)
    // =================================================================

    public function test_a_reply_to_a_workflows_own_automation_message_does_not_re_trigger_it(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155551016');

        // The workflow ran for this contact before and sent them a message —
        // far enough back that the cooldown is not what decides this.
        $earlier = app(EnrollmentService::class)->enroll($workflow, $contact, 'report:earlier');
        DB::table('automation_enrollments')->where('id', $earlier->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->enrolledAt($earlier, Carbon::now()->subDays(5));
        $this->outbound($business, '14155551016', $this->stepRunOn($earlier));

        // The contact's auto-responder answers that message.
        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551016'));

        $this->assertSame(0, $result['enrolled']);
        $this->assertSame(1, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_SELF_REPLY] ?? 0);
        $this->assertSame(1, $this->enrollmentsFor($workflow), 'No second journey off its own output.');
    }

    public function test_a_manually_sent_message_in_between_is_not_automation_output(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155551017');

        $earlier = app(EnrollmentService::class)->enroll($workflow, $contact, 'report:earlier');
        DB::table('automation_enrollments')->where('id', $earlier->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->enrolledAt($earlier, Carbon::now()->subDays(5));

        // The automation sent something, then a PERSON replied from the inbox.
        $this->outbound($business, '14155551017', $this->stepRunOn($earlier));
        $this->outbound($business, '14155551017');

        // The contact is answering the person, not the automation.
        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551017'));

        $this->assertSame(1, $result['enrolled'], 'A human message carries no automation mark and must not suppress the trigger.');
        $this->assertSame(0, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_SELF_REPLY] ?? 0);
    }

    public function test_only_the_message_before_this_inbound_decides_not_one_sent_after(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155551018');

        $earlier = app(EnrollmentService::class)->enroll($workflow, $contact, 'report:earlier');
        DB::table('automation_enrollments')->where('id', $earlier->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->enrolledAt($earlier, Carbon::now()->subDays(5));

        // Human message, THEN the inbound, THEN an automation message after it.
        $this->outbound($business, '14155551018');
        $inbound = $this->legacyInbound($business, '14155551018');
        $this->outbound($business, '14155551018', $this->stepRunOn($earlier));

        $result = $this->messageSource()->handleInboundMessage($inbound);

        $this->assertSame(1, $result['enrolled'], 'What came after the message cannot be what it was replying to.');
    }

    public function test_automation_output_from_a_different_workflow_does_not_count_as_self(): void
    {
        [, $business] = $this->entitledTenant();
        $producer = $this->messageReceivedWorkflow($business, null, 'Producer');
        $listener = $this->messageReceivedWorkflow($business, null, 'Listener');
        $contact = $this->contact($business, $this->contactGroup($business), '14155551019');

        // Park both workflows' cooldowns out of the way; only the producer ran.
        $producerRun = app(EnrollmentService::class)->enroll($producer, $contact, 'report:producer');
        DB::table('automation_enrollments')->where('id', $producerRun->id)->update(['status' => 'completed', 'completed_at' => now()]);
        $this->enrolledAt($producerRun, Carbon::now()->subDays(5));
        $this->outbound($business, '14155551019', $this->stepRunOn($producerRun));

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551019'));

        $this->assertSame(1, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_SELF_REPLY] ?? 0, 'The producer does not re-trigger.');
        $this->assertSame(1, $result['enrolled'], 'A different workflow may still take the message.');
        $this->assertSame(1, $this->enrollmentsFor($listener));

        // One link further down a chain of automations.
        $this->assertSame(1, (int) AutomationEnrollment::query()->where('workflow_id', $listener->id)->value('causation_depth'));
    }

    // =================================================================
    // Rule 3 — causation depth
    // =================================================================

    public function test_a_chain_of_automations_answering_automations_stops_at_the_depth_limit(): void
    {
        [, $business] = $this->entitledTenant();
        $producer = $this->messageReceivedWorkflow($business, null, 'Deep producer');
        $listener = $this->messageReceivedWorkflow($business, null, 'Deep listener');
        $contact = $this->contact($business, $this->contactGroup($business), '14155551020');

        $producerRun = app(EnrollmentService::class)->enroll($producer, $contact, 'report:deep');
        DB::table('automation_enrollments')->where('id', $producerRun->id)->update([
            'status' => 'completed',
            'completed_at' => now(),
            'causation_depth' => WorkflowLimits::MAX_CAUSATION_DEPTH,
        ]);
        $this->enrolledAt($producerRun, Carbon::now()->subDays(5));
        $this->outbound($business, '14155551020', $this->stepRunOn($producerRun));

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551020'));

        $this->assertSame(1, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_CAUSATION_DEPTH] ?? 0);
        $this->assertSame(0, $this->enrollmentsFor($listener));
    }

    public function test_a_mark_that_resolves_to_another_business_names_no_producer_here(): void
    {
        [, $businessA] = $this->entitledTenant();
        [, $businessB] = $this->entitledTenant();
        $workflowA = $this->messageReceivedWorkflow($businessA);
        $workflowB = $this->messageReceivedWorkflow($businessB);
        $contactA = $this->contact($businessA, $this->contactGroup($businessA), '14155551021');
        $contactB = $this->contact($businessB, $this->contactGroup($businessB), '14155551021');

        $runB = app(EnrollmentService::class)->enroll($workflowB, $contactB, 'report:b');

        // A row in A carrying a step run of B's journey can only be corrupt; it
        // must not let B's history decide anything about A.
        $this->outbound($businessA, '14155551021', $this->stepRunOn($runB));

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($businessA, '14155551021'));

        $this->assertSame(1, $result['enrolled']);
        $this->assertSame(0, (int) AutomationEnrollment::query()->where('workflow_id', $workflowA->id)->value('causation_depth'));
    }

    public function test_a_mark_whose_step_run_no_longer_exists_names_no_producer(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155551023');

        $earlier = app(EnrollmentService::class)->enroll($workflow, $contact, 'report:gone');
        $this->outbound($business, '14155551023', $this->stepRunOn($earlier));

        // The journey — and with it the step run — is deleted. The message keeps
        // its now-dangling mark, because the mark is a reference rather than an
        // enforced key; it must not be read as this workflow's own output.
        DB::table('automation_enrollments')->where('id', $earlier->id)->delete();

        $result = $this->messageSource()->handleInboundMessage($this->legacyInbound($business, '14155551023'));

        $this->assertSame(0, $result['skipped'][MessageReceivedTriggerSource::SKIPPED_SELF_REPLY] ?? 0);
        $this->assertSame(1, $result['enrolled']);
        $this->assertSame(0, (int) AutomationEnrollment::query()->where('workflow_id', $workflow->id)->value('causation_depth'));
    }

    // =================================================================
    // The event and its listener
    // =================================================================

    public function test_the_event_is_after_commit_and_the_listener_is_queued_on_automation(): void
    {
        $this->assertTrue(is_subclass_of(InboundMessageReceived::class, ShouldDispatchAfterCommit::class));
        $this->assertTrue(is_subclass_of(EnrollFromInboundMessage::class, ShouldQueue::class));

        $listener = app(EnrollFromInboundMessage::class);
        $this->assertSame('automation', $listener->queue);
        $this->assertSame(1, $listener->tries, 'Redelivery is harmless, so no retry is needed.');

        Event::fake([InboundMessageReceived::class]);
        Event::assertListening(InboundMessageReceived::class, EnrollFromInboundMessage::class);
    }

    public function test_the_occurrence_keys_of_the_two_paths_cannot_collide(): void
    {
        $this->assertSame('report:42', InboundMessageReceived::fromLegacyReport(1, '1415', 42)->occurrenceKey);
        $this->assertSame('operation:42', InboundMessageReceived::fromManagedOperation(1, '+1415', 42)->occurrenceKey);
    }

    public function test_the_listener_runs_the_source_end_to_end(): void
    {
        [, $business] = $this->entitledTenant();
        $workflow = $this->messageReceivedWorkflow($business);
        $this->contact($business, $this->contactGroup($business), '14155551022');

        app(EnrollFromInboundMessage::class)->handle($this->legacyInbound($business, '14155551022'));

        $this->assertSame(1, $this->enrollmentsFor($workflow));
    }
}
