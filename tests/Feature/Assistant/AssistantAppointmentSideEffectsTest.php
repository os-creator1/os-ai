<?php

namespace Tests\Feature\Assistant;

use App\Enums\Entitlement\WorkspacePlanTier;
use App\Events\Calendar\AppointmentScheduled;
use App\Library\Assistant\AssistantActionService;
use App\Library\Assistant\AssistantContext;
use App\Listeners\Automation\Workflow\EnrollFromAppointmentEvent;
use App\Models\AssistantActionAudit;
use App\Models\AssistantActionProposal;
use App\Models\BusinessLocation;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\Assistant\Concerns\AssistantTestSupport;
use Tests\TestCase;

/**
 * Correction round 2, item 4. CreateAppointmentTool books through AppointmentBookingService::bookWithRoundRobin(), which
 * inserts the appointment in its own transaction (nested inside the executor's transaction here) and then dispatches
 * AppointmentScheduled. That event is ShouldDispatchAfterCommit, so it is held until the OUTERMOST transaction — the
 * executor's, which also records the proposal's result — commits, and is discarded if that transaction rolls back.
 *
 * Its only consumers are the Automations enrolment listener (queued, idempotent on the occurrence key
 * `appointment_scheduled:{id}`) and a Platform analytics trigger. A staff/assistant booking schedules NO customer
 * confirmation or reminder (that ledger is written only by the public booking flow), so no mail, SMS or notification is sent.
 * The proposal fence + the single transaction therefore give exactly-once appointments and exactly-once downstream events;
 * no domain idempotency key is needed, and the Calendar is left unchanged.
 */
class AssistantAppointmentSideEffectsTest extends TestCase
{
    use RefreshDatabase;
    use AssistantTestSupport;

    private AssistantContext $ctx;

    private AssistantActionService $svc;

    private AssistantActionProposal $proposal;

    private int $scheduledEvents = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAssistant();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Event::listen(AppointmentScheduled::class, function (): void {
            $this->scheduledEvents++;
        });

        [$customer, $business, $workspace] = $this->tenant(WorkspacePlanTier::Growth, 'Side Venue', 'Side Account');
        $this->authenticateAs($customer);
        $location = BusinessLocation::query()->where('business_id', $business->id)->firstOrFail();
        DB::table('contacts')->insert([
            'uid' => (string) Str::uuid(), 'customer_id' => $business->customer_id, 'business_id' => $business->id,
            'location_id' => $location->id, 'group_id' => $this->contactGroup($business)->id, 'phone' => '15557770000', 'status' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->modelSays(['action' => 'propose', 'tool' => 'create_appointment', 'args' => ['contact' => '15557770000', 'date' => '2026-09-14', 'time' => '10:00'], 'message' => 'Here is the booking.']);
        $reply = $this->lastReply($this->say($workspace, $business, 'Book an appointment for Monday at 10 for 15557770000')->assertOk());

        $shell = app(\App\Library\Navigation\CustomerShellComposer::class);
        $customerContext = $shell->currentContext($customer->user);
        $entitlements = $shell->currentMenuEntitlements($customerContext);
        $this->ctx = new AssistantContext($workspace, $business, $customer->user, $customerContext, $entitlements,
            app(\App\Library\Dashboard\Home\HomeActionRouter::class)->forActor($customerContext, $customer->user, $entitlements, [$workspace->uid, $business->uid], $location->uid),
            [$workspace->uid, $business->uid]);

        $this->svc = app(AssistantActionService::class);
        $this->proposal = AssistantActionProposal::query()->where('uid', $reply['proposal']['uid'])->firstOrFail();
        $this->scheduledEvents = 0;
    }

    private function appointments(): int
    {
        return DB::table('appointments')->count();
    }

    private function automationJobs(): int
    {
        return count(Queue::pushed(CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === EnrollFromAppointmentEvent::class));
    }

    private function assertNoCustomerFacingMessage(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        $this->assertSame(0, DB::table('appointment_notifications')->count(), 'No confirmation or reminder is scheduled for a staff booking.');
    }

    public function test_an_approved_booking_makes_one_appointment_one_event_and_no_customer_message_and_a_replay_adds_nothing(): void
    {
        $first = $this->svc->approve($this->ctx, $this->proposal->uid);
        $this->assertSame('completed', $first['state']);

        $this->assertSame(1, $this->appointments());
        $this->assertSame(1, $this->scheduledEvents, 'AppointmentScheduled is emitted once, after the executor committed.');
        $this->assertSame(1, $this->automationJobs());

        $again = $this->svc->approve($this->ctx, $this->proposal->uid);
        $this->assertSame('replayed', $again['state']);
        $this->svc->approve($this->ctx, $this->proposal->uid);

        $this->assertSame(1, $this->appointments(), 'A double approval does not book twice.');
        $this->assertSame(1, $this->scheduledEvents);
        $this->assertSame(1, $this->automationJobs());
        $this->assertNoCustomerFacingMessage();
    }

    public function test_a_failure_after_the_booking_rolls_the_appointment_back_with_no_event_and_the_retry_is_exactly_once(): void
    {
        // The executor's own transaction fails AFTER the domain write (while recording the audit event): everything rolls back.
        $armed = true;
        AssistantActionAudit::creating(function (AssistantActionAudit $audit) use (&$armed): void {
            if ($armed && $audit->event === 'executed') {
                throw new \RuntimeException('simulated crash after the booking');
            }
        });

        $failed = $this->svc->approve($this->ctx, $this->proposal->uid);

        $this->assertSame('failed', $failed['state']);
        $this->assertSame(0, $this->appointments(), 'The booking rolled back with the executor transaction.');
        $this->assertSame(0, $this->scheduledEvents, 'The after-commit event was discarded, not delivered for a booking that does not exist.');
        $this->assertSame(0, $this->automationJobs());
        $this->assertNoCustomerFacingMessage();

        // Retry (the customer presses Confirm again): exactly one appointment and one event.
        $armed = false;
        $retry = $this->svc->approve($this->ctx, $this->proposal->uid);
        $this->assertSame('completed', $retry['state']);
        $this->assertSame(1, $this->appointments());
        $this->assertSame(1, $this->scheduledEvents);
        $this->assertSame(1, $this->automationJobs());
        $this->assertNoCustomerFacingMessage();
    }

    public function test_competing_executors_a_timeout_and_a_late_completion_cannot_duplicate_the_booking_or_its_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 15:00:00', 'UTC'));

        // A claims and then stalls (a timeout): its lease lapses and B legitimately takes over under a new fence.
        $a = $this->svc->claimForExecution($this->ctx, $this->proposal);
        $this->assertSame('claimed', $a['kind']);
        Carbon::setTestNow(Carbon::now()->addSeconds((int) config('assistant.action_lease_seconds') + 5));
        $b = $this->svc->claimForExecution($this->ctx, $this->proposal->fresh());
        $this->assertSame('claimed', $b['kind']);
        $this->assertSame(2, $b['fence']);

        // The stale executor wakes up first and tries to complete: it is fenced out before touching anything.
        $late = $this->svc->executeClaimed($this->ctx, $a['proposal'], $a['token'], $a['fence']);
        $this->assertNotSame('completed', $late['state']);
        $this->assertSame(0, $this->appointments());
        $this->assertSame(0, $this->scheduledEvents);

        // The current holder completes: one appointment, one event.
        $done = $this->svc->executeClaimed($this->ctx, $b['proposal'], $b['token'], $b['fence']);
        $this->assertSame('completed', $done['state']);
        $this->assertSame(1, $this->appointments());
        $this->assertSame(1, $this->scheduledEvents);

        // Both executors retry afterwards (late completion, network retry): still one.
        $this->svc->executeClaimed($this->ctx, $a['proposal'], $a['token'], $a['fence']);
        $this->svc->executeClaimed($this->ctx, $b['proposal'], $b['token'], $b['fence']);
        $this->assertSame(1, $this->appointments());
        $this->assertSame(1, $this->scheduledEvents);
        $this->assertSame(1, $this->automationJobs());
        $this->assertNoCustomerFacingMessage();
        Carbon::setTestNow();
    }

    public function test_the_automation_event_is_keyed_on_the_appointment_so_even_a_redelivery_enrolls_once(): void
    {
        $this->svc->approve($this->ctx, $this->proposal->uid);
        $id = (int) DB::table('appointments')->value('id');

        $event = new AppointmentScheduled($id, 1, 1, 1, 1, 1, null, '2026-09-14 10:00:00', '2026-09-14 10:30:00', null);
        $this->assertSame('appointment_scheduled:' . $id, $event->occurrenceKey());
        $this->assertInstanceOf(\Illuminate\Contracts\Events\ShouldDispatchAfterCommit::class, $event);
    }

    // PR #460 review: authorization is not lifecycle — a location archived during the approval window takes no booking.
    public function test_approval_is_refused_when_the_location_was_archived_after_the_preview(): void
    {
        DB::table('business_locations')->update(['lifecycle_state' => 'archived', 'archived_at' => now()]);

        $result = $this->svc->approve($this->ctx, $this->proposal->uid);

        $this->assertNotSame('completed', $result['state']);
        $this->assertSame(0, $this->appointments(), 'Nothing was booked at an archived location.');
        $this->assertSame(0, $this->scheduledEvents);
        $this->assertNoCustomerFacingMessage();
    }
}
