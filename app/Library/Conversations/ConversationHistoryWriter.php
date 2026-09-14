<?php

namespace App\Library\Conversations;

use App\Library\Automation\Workflow\Runtime\AutomationSendContext;
use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Library\Messaging\BusinessMessagingIdentityResolver;
use App\Library\Messaging\ManagedMessageDispatcher;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Reports;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one writer of managed conversation history — what a person and a
 * Business on managed messaging said to each other, in the SAME two tables the
 * legacy path has always written: `chat_boxes` and `chat_box_messages`.
 *
 * ONE CONVERSATION IDENTITY. Every managed message, in either direction, lands
 * on the conversation keyed by (the Business's owning customer, the Business,
 * the Business's own number, the external number), both numbers normalized by
 * MessageReceivedTriggerSource::normalizePhone() — the one scheme
 * `contact.replied_since_enrollment` joins `chat_boxes.to` against. A reply and
 * the message it answers therefore always share one thread, and the orientation
 * is the domain one (`from` = the Business's number, `to` = the person).
 *
 * INBOUND (#285) is recorded as it always was: inside the caller's transaction,
 * with the operation row it belongs to.
 *
 * OUTBOUND is recorded only after the managed provider has ACCEPTED the send,
 * from ManagedDispatchDelegate::attempt() — the one seam every managed send
 * crosses. It is a consequence of a send that happened, never a send itself:
 * nothing here calls a provider, meters, reserves or touches a report.
 *
 * EXACTLY ONCE, by identity. The history row carries the send's own
 * `business_messaging_operations` id under a unique index. A replayed request
 * or a retried job reaches the dispatcher with the same operation key, gets the
 * recorded result back without a second provider call, and arrives here again —
 * where the existing row is found (or the unique index refuses a racing copy)
 * and nothing is written twice. Never by body, time or phone.
 */
final class ConversationHistoryWriter
{
    /** A person pressed Send in Conversations. */
    public const SOURCE_CONVERSATIONS = 'conversations';

    /** Any other single send through quickSend() — Outreach, an automation, an API caller. */
    public const SOURCE_QUICK_SEND = 'quick_send';

    /** A campaign send through Campaigns::sendSMS(). */
    public const SOURCE_CAMPAIGN = 'campaign';

    public function __construct(
        private readonly BusinessMessagingIdentityResolver $identities,
        private readonly AutomationSendContext $sendContext,
    ) {
    }

    /**
     * The canonical conversation between the Business's number and a person —
     * existing, or new and not yet saved. Null when either number normalizes to
     * nothing.
     */
    public function conversationFor(Business $business, string $businessNumber, string $contactNumber): ?ChatBox
    {
        $from = MessageReceivedTriggerSource::normalizePhone($businessNumber);
        $to = MessageReceivedTriggerSource::normalizePhone($contactNumber);

        if ($from === '' || $to === '') {
            return null;
        }

        $conversation = ChatBox::query()->firstOrNew([
            'user_id' => $business->customer_id,
            'business_id' => (int) $business->id,
            'from' => $from,
            'to' => $to,
        ]);

        if (! $conversation->exists) {
            $conversation->uid = (string) Str::uuid();
        }

        return $conversation;
    }

    /**
     * #285 — a managed inbound message, unchanged: the conversation is marked as
     * awaiting the Business, its unread count rises, and the legacy "has AI
     * already answered" flag is reset. The caller holds the transaction.
     *
     * @param  list<string>  $mediaUrls
     */
    public function recordManagedInbound(
        Business $business,
        string $businessNumber,
        string $contactNumber,
        ?string $body,
        array $mediaUrls,
        string $messageType,
    ): void {
        $conversation = $this->conversationFor($business, $businessNumber, $contactNumber);

        if ($conversation === null) {
            return;
        }

        $conversation->reply_by_customer = true;
        $conversation->save();

        $conversation->update([
            'notification' => $conversation->notification + 1,
        ]);

        // `ai_replied` is deliberately not $fillable (see ChatBox::$fillable),
        // exactly like the legacy writer this mirrors — raw, for the same
        // reason: resetting "has AI already answered this thread" is not a
        // mass-assignable conversation attribute.
        DB::table('chat_boxes')->where('id', $conversation->id)->update([
            'reply_by_customer' => true,
            'ai_replied' => false,
        ]);

        ChatBoxMessage::create([
            'box_id' => $conversation->id,
            'message' => $body,
            'media_url' => $mediaUrls === [] ? null : implode(',', $mediaUrls),
            'sms_type' => $messageType,
            'direction' => Reports::DIRECTION_INCOMING,
        ]);
    }

    /**
     * A managed outbound message the provider has accepted.
     *
     * `$body` is the final text that was sent — spintax already resolved by the
     * caller. The Business's number is the one the dispatcher sent from: the
     * Business's single active primary managed number, resolved the same way.
     *
     * Returns the history row (new or already recorded), or null when there is
     * nothing to anchor it to — no recorded operation for this key, or no usable
     * number — in which case nothing is written.
     *
     * $sendUid IS THE RETRY SEAM (item 2/4), and is optional so every existing
     * caller (Outreach quick send, an automation, a campaign — none of which
     * offer a customer Retry) is completely unaffected. When given, it is the
     * stable identity of ONE logical, customer-visible message: a prior row
     * already carrying it — a failed first attempt, or an accepted send a
     * later DLR marked failed — is UPDATED in place (the new operation now
     * represents that same bubble) rather than a second row being created, so
     * a successful retry can never produce a duplicate bubble. A caller that
     * never passes $sendUid always creates fresh, exactly as before.
     *
     * @param  list<string>  $mediaUrls
     */
    public function recordManagedOutbound(
        Business $business,
        string $contactNumber,
        ?string $body,
        array $mediaUrls,
        ?string $smsType,
        string $operationKey,
        ?string $source,
        ?string $sendUid = null,
    ): ?ChatBoxMessage {
        $operationId = DB::table(ManagedMessageDispatcher::TABLE)
            ->where('business_id', (int) $business->id)
            ->where('operation_key', $operationKey)
            ->where('direction', 'outbound')
            ->value('id');

        if ($operationId === null) {
            return null;
        }

        $recorded = $this->recordedFor((int) $operationId);

        if ($recorded !== null) {
            return $recorded;
        }

        $identity = $this->identities->resolveForBusiness($business);
        $number = $identity !== null ? $this->identities->resolvePrimaryNumber($identity) : null;

        if ($number === null) {
            return null;
        }

        // Read now, while the automation step (if any) is still sending — the
        // same moment the `reports` stamp is taken on a non-managed send.
        $stepRunId = $this->sendContext->currentStepRunId();

        try {
            return DB::transaction(function () use ($business, $number, $contactNumber, $body, $mediaUrls, $smsType, $operationId, $stepRunId, $source, $sendUid): ?ChatBoxMessage {
                $conversation = $this->conversationFor($business, (string) $number->phone_number, $contactNumber);

                if ($conversation === null) {
                    return null;
                }

                // As the legacy two-way send does: the Business has now spoken,
                // so the conversation no longer awaits a reply from it.
                $conversation->reply_by_customer = false;
                $conversation->save();

                $existing = $sendUid !== null ? $this->recordedForSendUid((int) $conversation->id, $sendUid) : null;

                // Correction round 5 (state machine), item 2 — the CURRENT
                // durable operation status, re-read and locked inside THIS
                // transaction, never assumed to still be the Accepted result
                // ManagedDispatchDelegate::attempt() saw moments ago: a DLR
                // can finalize Accepted -> Delivered/Failed in the gap
                // between dispatch() returning and this write landing
                // (InboundWebhookAttributionResolver's own transition
                // transaction takes the SAME lock on this row before it
                // moves the operation, so these two transactions correctly
                // serialize against each other rather than racing).
                $operationRow = $sendUid !== null
                    ? DB::table(ManagedMessageDispatcher::TABLE)->where('id', $operationId)->lockForUpdate()->first()
                    : null;

                // Unresolved (null) is unreachable in practice at this call
                // site — dispatch() only finalizes an operation to Accepted
                // or Rejected before this method is ever reached, and
                // Rejected is projected too — but the fallback stays the
                // conservative 'sent' rather than crashing, matching what
                // this code path always did before this projection existed.
                $projection = $operationRow !== null
                    ? (ManagedSendStateMachine::projectOperation($operationRow) ?? new ManagedSendProjection(ManagedSendStateMachine::SENT, null))
                    : null;

                if ($existing !== null) {
                    // A retry just succeeded: the SAME bubble now points at
                    // the operation that actually got accepted, and carries
                    // no failure reason any more (or, per the projection
                    // above, whatever the operation's CURRENT durable state
                    // actually authorizes — never blindly 'sent').  The
                    // operation this row used to name is left exactly as it
                    // was — the durable audit trail of every attempt lives
                    // there, in business_messaging_operations, not in this
                    // pointer.
                    $existing->update([
                        'business_messaging_operation_id' => (int) $operationId,
                        'send_status' => $projection->sendStatus,
                        'send_failure_reason' => $projection->failureReason,
                    ]);

                    return $existing->fresh();
                }

                return ChatBoxMessage::create([
                    'box_id' => $conversation->id,
                    'message' => $body,
                    'media_url' => $mediaUrls === [] ? null : implode(',', $mediaUrls),
                    'sms_type' => $smsType === 'mms' ? 'mms' : 'plain',
                    'direction' => Reports::DIRECTION_OUTGOING,
                    'send_by' => 'from',
                    'business_messaging_operation_id' => (int) $operationId,
                    'automation_step_run_id' => $stepRunId,
                    'source' => $source,
                    'send_uid' => $sendUid,
                    'send_status' => $projection?->sendStatus,
                    'send_failure_reason' => $projection?->failureReason,
                    'retry_count' => $sendUid !== null ? 1 : null,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent copy of the same send recorded it first — found,
            // as always, by the operation id THIS accepted result itself
            // belongs to.
            $recorded = $this->recordedFor((int) $operationId);

            if ($recorded !== null) {
                return $recorded;
            }

            // Correction round 4, item 4 — the row that now exists for this
            // exact (box_id, send_uid) is not one recordedFor() above can
            // find (it carries no business_messaging_operation_id, or a
            // different one): the insert above lost the unique-key race to
            // a CONCURRENT FAILURE WRITE instead, one whose own read of
            // "does a row already exist" happened a moment before this
            // writer's own insert — recordManualSendFailure() has no way to
            // see a row this transaction had not committed yet. The
            // provider ACCEPTED this send regardless of which write landed
            // in the table first, so whichever row is sitting there now
            // must be promoted to the truthful 'sent' state — never a
            // second bubble for the same logical message.
            if ($sendUid === null) {
                return null;
            }

            $conversation = $this->conversationFor($business, (string) $number->phone_number, $contactNumber);

            if ($conversation === null) {
                return null;
            }

            return DB::transaction(function () use ($conversation, $sendUid, $operationId): ?ChatBoxMessage {
                $existing = ChatBoxMessage::query()
                    ->where('box_id', $conversation->id)
                    ->where('send_uid', $sendUid)
                    ->lockForUpdate()
                    ->first();

                if ($existing === null) {
                    // Lost the unique-key race to something this writer
                    // cannot identify at all (never observed in practice —
                    // the composite key is scoped to exactly the writers
                    // this class documents). Nothing safe to promote.
                    return null;
                }

                // Correction round 5 (state machine), item 2 — the SAME
                // fresh, locked re-read of the durable operation applies to
                // this promotion path too: the insert-race winner is never
                // blindly written as 'sent'.
                $operationRow = DB::table(ManagedMessageDispatcher::TABLE)->where('id', $operationId)->lockForUpdate()->first();
                $projection = ManagedSendStateMachine::projectOperation($operationRow)
                    ?? new ManagedSendProjection(ManagedSendStateMachine::SENT, null);

                $existing->update([
                    'business_messaging_operation_id' => (int) $operationId,
                    'send_status' => $projection->sendStatus,
                    'send_failure_reason' => $projection->failureReason,
                ]);

                return $existing->fresh();
            });
        }
    }

    /**
     * A manual Conversations send that did NOT reach an accepted provider
     * state — refused before commitment (item 1 Class A), or the managed
     * dispatcher's own rejection. The bubble this writes is the customer's
     * truthful record of "this was tried and did not go out"; it is never
     * written for a send the provider accepted (item 1 Class B stays exactly
     * as it already was — an accepted send whose history write later fails is
     * never turned into a reported failure by this or any other writer).
     *
     * IDENTITY: (box_id, $sendUid), always — the same composite key
     * recordManagedOutbound() looks up by. $sendUid is CLIENT-chosen,
     * untrusted input; a first attempt with no prior row IN THIS
     * conversation creates one, and a retry that failed again updates the
     * SAME row in place, exactly as a successful retry does, so a
     * repeatedly-failing message still shows as ONE bubble — but a uid
     * another Business's (or another thread's) conversation already used is
     * never found here at all, and never rewrites that stranger's row.
     *
     * FAILS CLOSED if the supplied conversation does not genuinely belong
     * to the supplied Business — defense in depth beyond the caller's own
     * tenancy resolution, since this is the seam that would otherwise let a
     * mismatched pair silently write into the wrong Business's history.
     *
     * MONOTONIC WITH RESPECT TO SUCCESS (correction round 3, item 1). A
     * failure write NEVER downgrades a bubble that already proves the
     * provider accepted this logical message ('sent' or 'delivered'). This
     * closes a genuine race: reply() has no claim/lock of its own (unlike
     * retry()), so a double-submitted first send reaches
     * ManagedMessageDispatcher::dispatch() twice with the SAME operation
     * key. The loser finds the winner's operation row still 'attempted'
     * (not yet finalized), and dispatch()'s own resultFromRecordedOperation()
     * reads an in-flight 'attempted' row as not-accepted — so the loser's
     * copy of attemptManagedSend() can reach this method with a failure
     * result for a send the winner's copy is, at the same moment, recording
     * as accepted. Whichever write lands second must never win if it is the
     * failure. The row is locked for the comparison and the update
     * together, so this is race-free against a concurrent success write
     * too. business_messaging_operations is never touched here — the
     * durable per-attempt audit trail is exactly as full either way.
     *
     * @param  list<string>  $mediaUrls
     *
     * @throws \InvalidArgumentException when $conversation does not belong to $business
     */
    public function recordManualSendFailure(
        Business $business,
        ChatBox $conversation,
        string $body,
        array $mediaUrls,
        string $smsType,
        string $sendUid,
        string $failureReasonCode,
    ): ChatBoxMessage {
        if ((int) $conversation->business_id !== (int) $business->id) {
            throw new \InvalidArgumentException(
                'recordManualSendFailure() refuses a conversation that does not belong to the supplied Business.',
            );
        }

        // Correction round 4, item 1 — the bubble state a failure lands in
        // is DERIVED from the reason, not always 'failed': an Ambiguous
        // outcome (see ConversationSendFailureReason) gets its own
        // 'ambiguous' state, which is deliberately never offered a Retry.
        $reason = ConversationSendFailureReason::tryFrom($failureReasonCode);
        $sendStatus = $reason?->bubbleStatus() ?? 'failed';

        return DB::transaction(function () use ($conversation, $body, $mediaUrls, $smsType, $sendUid, $failureReasonCode, $sendStatus): ChatBoxMessage {
            $existing = ChatBoxMessage::query()
                ->where('box_id', $conversation->id)
                ->where('send_uid', $sendUid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                // Correction round 5 (state machine), item 1 — sent/delivered
                // AND ambiguous are all protected from a local, pre-provider
                // refusal (invariants 1 & 2): a stale replay of an old local
                // failure result must never downgrade any of them, however
                // it got re-triggered (a changed kill-switch setting, a
                // repeated request). Only durable operation evidence —
                // reconciliation, via ManagedSendStateMachine::projectOperation() —
                // may resolve ambiguous further.
                if (! ManagedSendStateMachine::canApplyLocalRefusal($existing->send_status)) {
                    return $existing;
                }

                $existing->update([
                    'send_status' => $sendStatus,
                    'send_failure_reason' => $failureReasonCode,
                ]);

                return $existing->fresh();
            }

            return ChatBoxMessage::create([
                'box_id' => $conversation->id,
                'message' => $body,
                'media_url' => $mediaUrls === [] ? null : implode(',', $mediaUrls),
                'sms_type' => $smsType === 'mms' ? 'mms' : 'plain',
                'direction' => Reports::DIRECTION_OUTGOING,
                'send_by' => 'from',
                'send_uid' => $sendUid,
                'send_status' => $sendStatus,
                'send_failure_reason' => $failureReasonCode,
                'retry_count' => 1,
            ]);
        });
    }

    /**
     * Conversations failed-send/retry (item 5) — a provider that ACCEPTED a
     * managed send and a later delivery-status callback then reported as
     * failed. The bubble that send already has is turned into a truthful
     * Delivery failed state — never a second row, and the caller's own
     * transaction, not a new one here.
     *
     * ONLY 'sent' -> 'delivery_failed'. An operation whose bubble has since
     * moved on to a later attempt — recordManagedOutbound() repoints
     * business_messaging_operation_id to the NEW attempt the moment a retry
     * is accepted — no longer owns this operation id at all, so a late or
     * out-of-order DLR for a superseded attempt finds no row here and
     * safely does nothing to whatever the bubble now shows.
     */
    public function markManagedOutboundDeliveryFailed(int $operationId): void
    {
        ChatBoxMessage::query()
            ->where('business_messaging_operation_id', $operationId)
            ->where('send_status', ManagedSendStateMachine::SENT)
            ->update([
                'send_status' => ManagedSendStateMachine::DELIVERY_FAILED,
                'send_failure_reason' => ConversationSendFailureReason::DeliveryFailed->value,
            ]);
    }

    private function recordedFor(int $operationId): ?ChatBoxMessage
    {
        return ChatBoxMessage::query()->where('business_messaging_operation_id', $operationId)->first();
    }

    /**
     * NEVER by send_uid alone (item 1) — it is client-chosen, untrusted
     * input, and two different conversations (any two Businesses, or two
     * threads of the same Business) may legitimately carry the identical
     * value with no relationship to each other at all.
     */
    private function recordedForSendUid(int $boxId, string $sendUid): ?ChatBoxMessage
    {
        return ChatBoxMessage::query()->where('box_id', $boxId)->where('send_uid', $sendUid)->first();
    }
}
