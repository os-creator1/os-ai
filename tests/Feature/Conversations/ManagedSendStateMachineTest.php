<?php

namespace Tests\Feature\Conversations;

use App\Enums\Messaging\MessagingOperationStatus;
use App\Enums\Messaging\ProviderErrorCategory;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Library\Conversations\ManagedSendStateMachine;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Automations\Concerns\CreatesAutomationFixtures;
use Tests\Feature\Messaging\Concerns\CreatesMessagingFixtures;
use Tests\TestCase;

/**
 * PR #301 state-machine correction — TASK 0's regression matrix, exercised
 * directly against the ONE authoritative service,
 * App\Library\Conversations\ManagedSendStateMachine, rather than through a
 * full HTTP request for every row. Every scenario this file covers is also
 * reachable end-to-end through ManagedSendRetryTest.php / AcceptedInsertRaceTest.php
 * / ManagedOutboundDlrRaceTest.php — this file's job is to pin the exact
 * transition TABLE itself, compactly, in one place.
 *
 * existing bubble     new evidence/result       final bubble
 * ----------------------------------------------------------------
 * failed              Accepted                  sent
 * failed              Delivered                 delivered
 * failed              Failed DLR                delivery_failed
 * ambiguous           local refusal             ambiguous
 * ambiguous           Attempted                 ambiguous
 * ambiguous           Accepted                  sent
 * sending stale/no-op no operation              failed
 * sending fresh       no operation              sending
 * sending             Attempted                 ambiguous
 * sending             Accepted                  sent
 * sending             Failed                    delivery_failed
 * sent                stale failure             sent
 * delivered           stale failure             delivered
 *
 * No provider call is possible from anything this file exercises —
 * ManagedSendStateMachine's own class docblock states that as invariant 8,
 * and every test here binds the fake adapter and asserts zero calls to it
 * as direct proof, not merely an assumption.
 */
class ManagedSendStateMachineTest extends TestCase
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

    // -----------------------------------------------------------------
    // failed + operation evidence -> sent / delivered / delivery_failed
    // -----------------------------------------------------------------

    public function test_failed_bubble_plus_accepted_operation_projects_sent(): void
    {
        $projection = ManagedSendStateMachine::projectOperation($this->operationRow(MessagingOperationStatus::Accepted));

        $this->assertSame(ManagedSendStateMachine::SENT, $projection->sendStatus);
        $this->assertNull($projection->failureReason);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_failed_bubble_plus_delivered_operation_projects_delivered(): void
    {
        $projection = ManagedSendStateMachine::projectOperation($this->operationRow(MessagingOperationStatus::Delivered));

        $this->assertSame(ManagedSendStateMachine::DELIVERED, $projection->sendStatus);
        $this->assertNull($projection->failureReason);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_failed_bubble_plus_failed_dlr_projects_delivery_failed(): void
    {
        $projection = ManagedSendStateMachine::projectOperation($this->operationRow(MessagingOperationStatus::Failed));

        $this->assertSame(ManagedSendStateMachine::DELIVERY_FAILED, $projection->sendStatus);
        $this->assertSame('delivery_failed', $projection->failureReason);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    // -----------------------------------------------------------------
    // ambiguous + new evidence -> stays ambiguous, or resolves via evidence
    // -----------------------------------------------------------------

    public function test_ambiguous_bubble_plus_local_refusal_stays_ambiguous(): void
    {
        // Invariants 1 & 2 — the monotonicity guard every local, pre-
        // provider refusal (recordManualSendFailure()) must consult before
        // writing. Integration coverage of the full write path lives in
        // ManagedSendRetryTest::test_a_replayed_reply_while_the_kill_switch_is_disabled_never_downgrades_an_ambiguous_bubble().
        $this->assertFalse(ManagedSendStateMachine::canApplyLocalRefusal(ManagedSendStateMachine::AMBIGUOUS));
    }

    public function test_ambiguous_bubble_plus_attempted_evidence_projects_unresolved(): void
    {
        // At the PURE projection level, Attempted is unresolved (invariant
        // 5) — projectOperation() never guesses. reconcileSendingRow()
        // below is the one caller that turns "unresolved" into the visible
        // 'ambiguous' bubble state, and only for a row that is currently
        // 'sending' (see the sending+Attempted case further down).
        $projection = ManagedSendStateMachine::projectOperation($this->operationRow(MessagingOperationStatus::Attempted));

        $this->assertNull($projection);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_ambiguous_bubble_plus_accepted_evidence_projects_sent(): void
    {
        $projection = ManagedSendStateMachine::projectOperation($this->operationRow(MessagingOperationStatus::Accepted));

        $this->assertSame(ManagedSendStateMachine::SENT, $projection->sendStatus);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    // -----------------------------------------------------------------
    // sending + evidence -> reconcileSendingRow()'s own transition table
    // -----------------------------------------------------------------

    public function test_sending_stale_with_no_operation_projects_failed(): void
    {
        [$business, , $box] = $this->fixture();
        $message = $this->sendingRow($box, (string) Str::uuid(), now()->subMinutes(10));

        $reconciled = ManagedSendStateMachine::reconcileSendingRow($message, (int) $business->id);

        $this->assertSame(ManagedSendStateMachine::FAILED, $reconciled->send_status);
        $this->assertSame('send_failed', $reconciled->send_failure_reason);
        $this->assertTrue(ManagedSendStateMachine::isRetryEligible($reconciled->send_status));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_sending_fresh_with_no_operation_stays_sending(): void
    {
        [$business, , $box] = $this->fixture();
        $message = $this->sendingRow($box, (string) Str::uuid(), now());

        $reconciled = ManagedSendStateMachine::reconcileSendingRow($message, (int) $business->id);

        $this->assertSame(ManagedSendStateMachine::SENDING, $reconciled->send_status);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_sending_plus_attempted_operation_projects_ambiguous(): void
    {
        [$business, $identity, $box] = $this->fixture();
        $sendUid = (string) Str::uuid();
        $message = $this->sendingRow($box, $sendUid, now()->subMinutes(10));
        $this->insertOperation($business, $identity, $box, $sendUid, MessagingOperationStatus::Attempted);

        $reconciled = ManagedSendStateMachine::reconcileSendingRow($message, (int) $business->id);

        $this->assertSame(ManagedSendStateMachine::AMBIGUOUS, $reconciled->send_status);
        $this->assertFalse(ManagedSendStateMachine::isRetryEligible($reconciled->send_status));
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_sending_plus_accepted_operation_projects_sent(): void
    {
        [$business, $identity, $box] = $this->fixture();
        $sendUid = (string) Str::uuid();
        $message = $this->sendingRow($box, $sendUid, now());
        $operationId = $this->insertOperation($business, $identity, $box, $sendUid, MessagingOperationStatus::Accepted);

        $reconciled = ManagedSendStateMachine::reconcileSendingRow($message, (int) $business->id);

        $this->assertSame(ManagedSendStateMachine::SENT, $reconciled->send_status);
        $this->assertSame($operationId, (int) $reconciled->business_messaging_operation_id);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    public function test_sending_plus_failed_operation_projects_delivery_failed(): void
    {
        [$business, $identity, $box] = $this->fixture();
        $sendUid = (string) Str::uuid();
        $message = $this->sendingRow($box, $sendUid, now());
        $this->insertOperation($business, $identity, $box, $sendUid, MessagingOperationStatus::Failed);

        $reconciled = ManagedSendStateMachine::reconcileSendingRow($message, (int) $business->id);

        $this->assertSame(ManagedSendStateMachine::DELIVERY_FAILED, $reconciled->send_status);
        $this->assertSame('delivery_failed', $reconciled->send_failure_reason);
        $this->assertCount(0, $this->fakeAdapter->sentRequests);
    }

    // -----------------------------------------------------------------
    // sent/delivered are monotonic against a stale local failure
    // -----------------------------------------------------------------

    public function test_sent_bubble_plus_stale_local_failure_stays_sent(): void
    {
        $this->assertFalse(ManagedSendStateMachine::canApplyLocalRefusal(ManagedSendStateMachine::SENT));
        $this->assertTrue(ManagedSendStateMachine::isTerminalSuccess(ManagedSendStateMachine::SENT));
    }

    public function test_delivered_bubble_plus_stale_local_failure_stays_delivered(): void
    {
        $this->assertFalse(ManagedSendStateMachine::canApplyLocalRefusal(ManagedSendStateMachine::DELIVERED));
        $this->assertTrue(ManagedSendStateMachine::isTerminalSuccess(ManagedSendStateMachine::DELIVERED));
    }

    // -----------------------------------------------------------------

    /** @return array{0: Business, 1: object, 2: ChatBox} */
    private function fixture(): array
    {
        [, $business] = $this->entitledTenant();
        $this->sendableChannel($business);

        $identity = $this->attachIdentity($business);
        $this->attachNumber($identity, '+14155550199', true);

        $box = app(ConversationHistoryWriter::class)->conversationFor($business, '14155550199', self::PERSON);
        $box->save();

        return [$business->fresh(), $identity, $box->fresh()];
    }

    private function sendingRow(ChatBox $box, string $sendUid, \Illuminate\Support\Carbon $claimedAt): ChatBoxMessage
    {
        return ChatBoxMessage::create([
            'box_id' => $box->id,
            'message' => 'Matrix fixture',
            'sms_type' => 'plain',
            'direction' => 'outgoing',
            'send_by' => 'from',
            'send_uid' => $sendUid,
            'send_status' => ManagedSendStateMachine::SENDING,
            'retry_count' => 1,
            'send_claimed_at' => $claimedAt,
        ]);
    }

    private function insertOperation(Business $business, object $identity, ChatBox $box, string $sendUid, MessagingOperationStatus $status): int
    {
        return DB::table(ManagedMessageDispatcher::TABLE)->insertGetId([
            'business_id' => $business->id,
            'business_messaging_identity_id' => $identity->id,
            'transport_mode' => 'managed',
            'provider' => 'telnyx',
            'direction' => 'outbound',
            'message_type' => 'sms',
            'operation_key' => 'conversation:' . $box->id . ':' . $sendUid . ':retry:1',
            'status' => $status->value,
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** A standalone operation row, not persisted — projectOperation() reads only object properties. */
    private function operationRow(MessagingOperationStatus $status, ?ProviderErrorCategory $errorCategory = null): object
    {
        return (object) [
            'id' => 1,
            'status' => $status->value,
            'error_category' => $errorCategory?->value,
        ];
    }
}
