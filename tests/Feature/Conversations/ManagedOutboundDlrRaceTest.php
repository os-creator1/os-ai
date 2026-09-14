<?php

namespace Tests\Feature\Conversations;

use App\Enums\Messaging\MessagingOperationStatus;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Conversations\ConversationSendFailureReason;
use App\Library\Conversations\ManagedSendStateMachine;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\Customer;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * PR #301 state-machine correction, item 2 — a DLR that finalizes the
 * durable operation BEFORE ConversationHistoryWriter::recordManagedOutbound()
 * attaches it to the tracked bubble must still be reflected truthfully.
 *
 * THE RACE. ManagedDispatchDelegate::attempt() sees the dispatcher's
 * Accepted result and calls recordManagedOutbound() — but the durable
 * business_messaging_operations row it names can, in the gap before that
 * write lands, already have been moved on by
 * InboundWebhookAttributionResolver's own delivery-status transition (a
 * failure DLR arriving unusually fast, or — the same class of race in the
 * opposite, non-failure direction — a Delivered confirmation). Before this
 * correction, recordManagedOutbound() never re-read the operation's CURRENT
 * status; it always wrote 'sent', regardless of what the operation had
 * already become.
 *
 * These tests reproduce the ordering directly and deterministically — no
 * real concurrency is needed, because the fix itself is "always re-read the
 * durable row fresh before writing": setting the operation to its
 * post-DLR status BEFORE calling recordManagedOutbound() is the exact
 * condition the fix must handle correctly.
 */
class ManagedOutboundDlrRaceTest extends TestCase
{
    use RefreshDatabase;
    use CreatesAutomationFixtures;
    use CreatesMessagingFixtures;

    private const PERSON = '14155552671';

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->bindFakeAdapter();
    }

    public function test_a_failed_dlr_before_history_attach_projects_delivery_failed_not_sent_on_the_existing_row_path(): void
    {
        [, $business, , $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sendUid = (string) Str::uuid();
        $writer = app(ConversationHistoryWriter::class);

        // The failed FIRST attempt this "retry succeeds" call will update.
        $writer->recordManualSendFailure($business, $box, 'Are you open?', [], 'plain', $sendUid, ConversationSendFailureReason::SendFailed->value);
        $originalMessage = DB::table('chat_box_messages')->where('box_id', $box->id)->sole();
        $this->assertSame('failed', $originalMessage->send_status);

        $operationKey = 'conversation:' . $box->id . ':' . $sendUid . ':retry:1';
        $operationId = DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed', 'provider' => 'telnyx',
            'direction' => 'outbound', 'message_type' => 'sms',
            'operation_key' => $operationKey,
            'status' => MessagingOperationStatus::Accepted->value,
            'provider_message_id' => 'fake_msg_dlr_race',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The DLR arrives and finalizes the operation BEFORE history
        // attachment runs — exactly the ordering the bug report describes.
        DB::table(ManagedMessageDispatcher::TABLE)->where('id', $operationId)->update(['status' => MessagingOperationStatus::Failed->value]);

        $result = $writer->recordManagedOutbound(
            $business, self::PERSON, 'Are you open?', [], 'plain', $operationKey,
            ConversationHistoryWriter::SOURCE_CONVERSATIONS, $sendUid,
        );

        $this->assertNotNull($result);
        $this->assertSame(ManagedSendStateMachine::DELIVERY_FAILED, $result->send_status, 'The durable operation IS Failed — never falsely Sent.');
        $this->assertSame(ConversationSendFailureReason::DeliveryFailed->value, $result->send_failure_reason);
        $this->assertSame($operationId, (int) $result->business_messaging_operation_id, 'The NEW operation is attached, even though it did not end up Accepted.');
        $this->assertSame($originalMessage->id, $result->id, 'The SAME bubble — never a second row.');
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count());
        $this->assertTrue(ManagedSendStateMachine::isRetryEligible($result->send_status), 'Retry is available exactly once — delivery_failed is retry-eligible.');
    }

    public function test_a_failed_dlr_before_history_attach_projects_delivery_failed_not_sent_on_the_brand_new_bubble_path(): void
    {
        [, $business, , $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sendUid = (string) Str::uuid();

        $operationKey = 'conversation:' . $box->id . ':' . $sendUid . ':initial';
        $operationId = DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed', 'provider' => 'telnyx',
            'direction' => 'outbound', 'message_type' => 'sms',
            'operation_key' => $operationKey,
            'status' => MessagingOperationStatus::Accepted->value,
            'provider_message_id' => 'fake_msg_dlr_race_2',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table(ManagedMessageDispatcher::TABLE)->where('id', $operationId)->update(['status' => MessagingOperationStatus::Failed->value]);

        $result = app(ConversationHistoryWriter::class)->recordManagedOutbound(
            $business, self::PERSON, 'A brand new message', [], 'plain', $operationKey,
            ConversationHistoryWriter::SOURCE_CONVERSATIONS, $sendUid,
        );

        $this->assertNotNull($result);
        $this->assertSame(ManagedSendStateMachine::DELIVERY_FAILED, $result->send_status, 'Even a FIRST-EVER write for this send_uid never blindly writes sent.');
        $this->assertSame(ConversationSendFailureReason::DeliveryFailed->value, $result->send_failure_reason);
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count());
    }

    public function test_a_delivered_confirmation_before_history_attach_projects_delivered_not_sent(): void
    {
        [, $business, , $identity] = $this->managedTenant();
        $box = $this->inboundConversation($business, self::PERSON);
        $sendUid = (string) Str::uuid();
        $writer = app(ConversationHistoryWriter::class);

        $writer->recordManualSendFailure($business, $box, 'Are you open?', [], 'plain', $sendUid, ConversationSendFailureReason::SendFailed->value);
        $originalMessage = DB::table('chat_box_messages')->where('box_id', $box->id)->sole();

        $operationKey = 'conversation:' . $box->id . ':' . $sendUid . ':retry:1';
        $operationId = DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed', 'provider' => 'telnyx',
            'direction' => 'outbound', 'message_type' => 'sms',
            'operation_key' => $operationKey,
            'status' => MessagingOperationStatus::Accepted->value,
            'provider_message_id' => 'fake_msg_delivered_race',
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A genuinely Delivered confirmation, finalized before attachment —
        // durable Delivered must not regress to merely 'sent' (let alone
        // 'failed' or 'sending').
        DB::table(ManagedMessageDispatcher::TABLE)->where('id', $operationId)->update(['status' => MessagingOperationStatus::Delivered->value]);

        $result = $writer->recordManagedOutbound(
            $business, self::PERSON, 'Are you open?', [], 'plain', $operationKey,
            ConversationHistoryWriter::SOURCE_CONVERSATIONS, $sendUid,
        );

        $this->assertNotNull($result);
        $this->assertSame(ManagedSendStateMachine::DELIVERED, $result->send_status);
        $this->assertNull($result->send_failure_reason);
        $this->assertSame($operationId, (int) $result->business_messaging_operation_id);
        $this->assertSame($originalMessage->id, $result->id);
        $this->assertSame(1, DB::table('chat_box_messages')->where('box_id', $box->id)->count());
        $this->assertFalse(ManagedSendStateMachine::isRetryEligible($result->send_status), 'A genuinely delivered message never offers Retry.');
    }

    // -----------------------------------------------------------------

    /**
     * @return array{0: Customer, 1: Business, 2: Workspace, 3: \App\Models\BusinessMessagingIdentity}
     */
    private function managedTenant(string $managedNumber = '+14155550199'): array
    {
        [$customer, $business, $workspace] = $this->entitledTenant();
        $this->sendableChannel($business);

        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, $managedNumber, true);

        $customer->user->sms_unit = 1000;
        $customer->user->save();
        $customer->permissions = Customer::customerPermissions();
        $customer->save();

        return [$customer->fresh(), $business->fresh(), $workspace, $identity];
    }

    private function inboundConversation(Business $business, string $person, string $businessDigits = '14155550199'): ChatBox
    {
        $box = app(ConversationHistoryWriter::class)->conversationFor($business, $businessDigits, $person);
        $box->reply_by_customer = true;
        $box->save();

        return $box->fresh();
    }
}
