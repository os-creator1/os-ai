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
            return DB::transaction(function () use ($business, $number, $contactNumber, $body, $mediaUrls, $smsType, $operationId, $stepRunId, $source): ?ChatBoxMessage {
                $conversation = $this->conversationFor($business, (string) $number->phone_number, $contactNumber);

                if ($conversation === null) {
                    return null;
                }

                // As the legacy two-way send does: the Business has now spoken,
                // so the conversation no longer awaits a reply from it.
                $conversation->reply_by_customer = false;
                $conversation->save();

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
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // A concurrent copy of the same send recorded it first.
            return $this->recordedFor((int) $operationId);
        }
    }

    private function recordedFor(int $operationId): ?ChatBoxMessage
    {
        return ChatBoxMessage::query()->where('business_messaging_operation_id', $operationId)->first();
    }
}
