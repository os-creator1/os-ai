<?php

namespace Tests\Feature\Messaging;

use App\Enums\Automation\Workflow\WorkflowTriggerType;
use App\Enums\Messaging\InboundWebhookEventKind;
use App\Events\Conversation\InboundMessageReceived;
use App\Http\Controllers\Customer\DLRController;
use App\Jobs\Automation\Workflow\AdvanceWorkflowEnrollment;
use App\Library\Automation\Workflow\Conditions\Subjects\ContactRepliedSinceEnrollmentSubject;
use App\Library\Automation\Workflow\Contracts\EnrollmentService;
use App\Library\Conversations\BusinessConversationReadModel;
use App\Library\Messaging\DTO\InboundWebhookEvent;
use App\Models\AutomationEnrollment;
use App\Models\Business;
use App\Models\PhoneNumbers;
use App\Models\SendingServer;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Automations\Workflow\MessageReceived\Support\BuildsInboundFixtures;
use Tests\Feature\Automations\Workflow\Runtime\Support\BuildsWorkflows;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * Managed inbound → canonical conversation history bridge.
 *
 * `InboundWebhookAttributionResolver::persistInbound()` used to leave a
 * managed Telnyx inbound message with exactly one lasting effect: an
 * operational row in `business_messaging_operations`, a table Inbox,
 * `BusinessConversationReadModel` and `contact.replied_since_enrollment`
 * have never read. `ContactRepliedSinceEnrollmentSubject`'s own docblock
 * named this gap explicitly. This suite proves the bridge that closes it —
 * one canonical `chat_boxes` / `chat_box_messages` write per authoritatively
 * attributed managed message, in the same orientation and idempotency the
 * legacy path has always used — without duplicating history on redelivery,
 * inventing a second conversation store, or disturbing self-reply
 * protection, the cooldown, or STOP/consent handling.
 */
class ManagedInboundConversationHistoryTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use CreatesMessagingFixtures;
    use BuildsWorkflows;
    use BuildsInboundFixtures;

    private function managedMessage(
        string $profileId,
        string $destination,
        string $from,
        string $providerMessageId,
        string $body = 'hello',
    ): InboundWebhookEvent {
        return new InboundWebhookEvent(
            kind: InboundWebhookEventKind::MessageReceived,
            messagingProfileId: $profileId,
            destinationNumber: $destination,
            fromNumber: $from,
            body: $body,
            mediaUrls: [],
            providerMessageId: $providerMessageId,
            deliveryStatus: null,
            occurredAt: CarbonImmutable::now(),
        );
    }

    /**
     * An entitled Business (so it can own contacts, groups and published
     * workflows) that is also wired for managed messaging.
     *
     * @return array{0: Business, 1: \App\Models\BusinessMessagingIdentity, 2: \App\Models\BusinessMessagingNumber}
     */
    private function entitledManagedBusiness(): array
    {
        [, $business] = $this->entitledTenant();
        $identity = $this->attachIdentity($business);
        $number = $this->attachNumber($identity, $this->uniqueNumber());

        return [$business, $identity, $number];
    }

    /**
     * A Business whose own assigned number receives LEGACY inbound traffic —
     * the exact fixture InboundProducersTest uses, reproduced here so this
     * suite can prove the legacy path unchanged without depending on another
     * test class's private helper.
     *
     * @return array{0: Business, 1: SendingServer, 2: PhoneNumbers}
     */
    private function legacyReceivingBusiness(): array
    {
        [, $business] = $this->entitledTenant();
        $owner = User::query()->findOrFail($business->customer_id);

        $server = SendingServer::create([
            'name' => 'Managed bridge fixture ' . uniqid(),
            'user_id' => $owner->id,
            'settings' => SendingServer::TYPE_TWILIO,
            'status' => true,
            'two_way' => true,
            'plain' => true,
            'account_sid' => 'ACtest',
            'auth_token' => 'authtest',
        ]);

        $phone = PhoneNumbers::create([
            'user_id' => $owner->id,
            'business_id' => $business->id,
            'number' => '14155558100',
            'status' => 'assigned',
            'capabilities' => json_encode(['sms']),
            'price' => 0,
            'billing_cycle' => 'monthly',
            'frequency_amount' => 1,
            'frequency_unit' => 'month',
            'validity_date' => now()->addMonth(),
        ]);

        return [$business, $server, $phone];
    }

    private function sendManagedInbound(
        string $profileId,
        string $destination,
        string $from,
        string $providerMessageId,
        string $body = 'hello',
    ): void {
        $this->fakeAdapter->queueInboundWebhook($this->managedMessage($profileId, $destination, $from, $providerMessageId, $body));
        $this->postJson(route('inbound.telnyx_managed'), ['probe' => true])->assertOk();
    }

    // =================================================================
    // History creation and idempotency
    // =================================================================

    public function test_managed_inbound_creates_canonical_incoming_history_once(): void
    {
        $this->bindFakeAdapter();
        [$business, $identity, $number] = $this->entitledManagedBusiness();

        $this->sendManagedInbound(
            $identity->messaging_profile_id,
            $number->phone_number,
            '+14155559001',
            'pm_hist_1',
            'hello there',
        );

        $box = DB::table('chat_boxes')->where('business_id', $business->id)->sole();
        $this->assertSame((int) $business->customer_id, (int) $box->user_id);
        $this->assertSame('14155559001', $box->to);

        $message = DB::table('chat_box_messages')->where('box_id', $box->id)->sole();
        $this->assertSame('incoming', $message->direction);
        $this->assertSame('hello there', $message->message);
    }

    public function test_a_duplicate_managed_webhook_does_not_duplicate_history(): void
    {
        $this->bindFakeAdapter();
        [$business, $identity, $number] = $this->entitledManagedBusiness();

        foreach ([1, 2] as $_) {
            $this->sendManagedInbound($identity->messaging_profile_id, $number->phone_number, '+14155559002', 'pm_hist_dup');
        }

        $this->assertSame(1, DB::table('chat_boxes')->where('business_id', $business->id)->count());
        $this->assertSame(1, DB::table('chat_box_messages')->count());
    }

    public function test_foreign_or_mismatched_attribution_creates_no_history(): void
    {
        $this->bindFakeAdapter();
        [, $identity] = $this->entitledManagedBusiness();

        // Known profile, but a destination number nobody owns: the resolver
        // fails closed before persistInbound() is ever called.
        $this->sendManagedInbound($identity->messaging_profile_id, '+14155559999', '+14155559003', 'pm_hist_unknown');

        $this->assertSame(0, DB::table('chat_boxes')->count());
        $this->assertSame(0, DB::table('chat_box_messages')->count());
    }

    public function test_conflicting_dual_signal_attribution_creates_no_history(): void
    {
        $this->bindFakeAdapter();
        [, $identityA] = $this->entitledManagedBusiness();
        [, , $numberB] = $this->entitledManagedBusiness();

        // Business A's Messaging Profile paired with Business B's number:
        // the two signals disagree, so nothing is attributed to either.
        $this->sendManagedInbound($identityA->messaging_profile_id, $numberB->phone_number, '+14155559010', 'pm_hist_conflict');

        $this->assertSame(0, DB::table('chat_boxes')->count());
        $this->assertSame(0, DB::table('chat_box_messages')->count());
    }

    public function test_legacy_inbound_history_is_unchanged(): void
    {
        [$business, $server, $phone] = $this->legacyReceivingBusiness();

        DLRController::inboundDLR('14155559004', 'legacy hello', $server, 0, $phone->number);

        $box = DB::table('chat_boxes')->where('business_id', $business->id)->sole();
        $message = DB::table('chat_box_messages')->where('box_id', $box->id)->sole();

        $this->assertSame('legacy hello', $message->message);
        $this->assertSame('incoming', $message->direction);
    }

    // =================================================================
    // Downstream consumers
    // =================================================================

    public function test_business_conversation_read_model_sees_the_managed_inbound_message(): void
    {
        $this->bindFakeAdapter();
        [$business, $identity, $number] = $this->entitledManagedBusiness();

        $this->sendManagedInbound($identity->messaging_profile_id, $number->phone_number, '+14155559005', 'pm_hist_readmodel');

        $readModel = app(BusinessConversationReadModel::class);
        $counts = $readModel->periodCounts($business, CarbonImmutable::now()->subMinute(), CarbonImmutable::now()->addMinute());

        $this->assertSame(1, $counts['incoming']);
        $this->assertSame(1, $readModel->unreadCount($business));
    }

    public function test_replied_since_enrollment_is_false_before_and_true_after_a_managed_reply(): void
    {
        $this->bindFakeAdapter();
        [$business, $identity, $number] = $this->entitledManagedBusiness();

        [$workflow] = $this->publishWorkflow($business, [$this->endStep()], WorkflowTriggerType::ManualEnrollment);
        $contact = $this->contact($business, $this->contactGroup($business), '14155559006');

        $enrollment = app(EnrollmentService::class)->enroll($workflow, $contact, 'manual:14155559006');
        $this->assertNotNull($enrollment);
        $this->enrolledAt($enrollment, Carbon::now()->subHours(2));

        $subject = new ContactRepliedSinceEnrollmentSubject();
        $this->assertFalse($subject->valueFor($contact->fresh(), $enrollment->fresh()));

        $this->sendManagedInbound($identity->messaging_profile_id, $number->phone_number, '+14155559006', 'pm_hist_replied');

        $this->assertTrue($subject->valueFor($contact->fresh(), $enrollment->fresh()));
    }

    public function test_v2_message_received_still_enrolls_exactly_once_via_managed_inbound(): void
    {
        $this->bindFakeAdapter();
        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [$business, $identity, $number] = $this->entitledManagedBusiness();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155559007');

        $this->sendManagedInbound($identity->messaging_profile_id, $number->phone_number, '+14155559007', 'pm_hist_enroll');

        $this->assertSame(1, AutomationEnrollment::query()->where('workflow_id', $workflow->id)->count());

        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole();
        $this->assertSame((int) $contact->id, (int) $enrollment->contact_id);
    }

    // =================================================================
    // No recursive producer / event loop
    // =================================================================

    public function test_the_bridge_dispatches_no_extra_domain_event(): void
    {
        $this->bindFakeAdapter();
        Bus::fake([AdvanceWorkflowEnrollment::class]);
        Event::fake([InboundMessageReceived::class]);

        [, $identity, $number] = $this->entitledManagedBusiness();

        $this->sendManagedInbound($identity->messaging_profile_id, $number->phone_number, '+14155559008', 'pm_hist_loop');

        // One real message, one domain event: writing the conversation
        // history inside persistInbound() dispatches nothing of its own.
        Event::assertDispatchedTimes(InboundMessageReceived::class, 1);
    }

    public function test_a_managed_reply_to_the_workflows_own_output_does_not_uncontrollably_re_enroll(): void
    {
        $this->bindFakeAdapter();
        Bus::fake([AdvanceWorkflowEnrollment::class]);

        [$business, $identity, $number] = $this->entitledManagedBusiness();
        $workflow = $this->messageReceivedWorkflow($business);
        $contact = $this->contact($business, $this->contactGroup($business), '14155559009');

        $this->sendManagedInbound($identity->messaging_profile_id, $number->phone_number, '+14155559009', 'pm_hist_first');
        $enrollment = AutomationEnrollment::query()->where('workflow_id', $workflow->id)->sole();

        // Mark this workflow's own step run as having produced an outbound
        // message to that same contact (T-WF-25's durable mark).
        $stepRunId = $this->stepRunOn($enrollment);
        $this->outbound($business, '14155559009', $stepRunId);

        // The contact answers again, through the managed transport.
        $this->sendManagedInbound($identity->messaging_profile_id, $number->phone_number, '+14155559009', 'pm_hist_self_reply');

        // Still exactly one enrollment — self-reply protection and the
        // cooldown, both unaffected by the new chat history, refused a
        // second one; the bridge did not open a new door into the workflow.
        $this->assertSame(1, AutomationEnrollment::query()->where('workflow_id', $workflow->id)->count());

        // Yet the conversation history the bridge writes is complete: both
        // managed messages are there, because the bridge has no notion of
        // causation depth — that guard lives entirely in the trigger source.
        $this->assertSame(2, DB::table('chat_box_messages')->where('direction', 'incoming')->count());
    }
}
