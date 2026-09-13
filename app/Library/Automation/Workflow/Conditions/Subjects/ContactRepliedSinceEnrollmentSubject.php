<?php

namespace App\Library\Automation\Workflow\Conditions\Subjects;

use App\Enums\Automation\Workflow\ConditionOperator;
use App\Library\Automation\Workflow\Contracts\ConditionSubject;
use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Models\AutomationEnrollment;
use App\Models\Contacts;
use Illuminate\Support\Facades\DB;

/**
 * `contact.replied_since_enrollment` — Automations V2-F §11.
 *
 * "Has this contact written to the Business since they entered this journey?"
 * The question behind "wait two days, and if they have not replied, follow up".
 *
 * THE HISTORY IS THE CONVERSATIONS HISTORY, not a second one. A reply is an
 * incoming message in one of this Business's conversations with this contact:
 * `chat_box_messages.direction = incoming`, in a `chat_boxes` row whose
 * `business_id` is the enrollment's Business and whose `to` is the contact's
 * number. That is the same pair of tables, with the same authoritative Business
 * column, that the inbox and Business Home (BusinessConversationReadModel) read.
 * The inbound Reports row is deliberately NOT used: its `business_id` is the
 * customer's primary Business by fallback, which would let a reply sent to
 * Business B count as a reply in Business A.
 *
 * THE BOUNDARY IS THE ENROLLMENT, strictly after it. A message that arrived
 * before the contact entered — including the very message that enrolled them
 * through the message-received trigger — is not a reply "since enrollment". An
 * enrollment with no `enrolled_at` (the transient one WorkflowSimulator builds)
 * has no boundary to be after, so nothing counts.
 *
 * SAME BUSINESS OR NOTHING. A contact that no longer belongs to the enrollment's
 * Business reads false rather than being looked up somewhere else.
 *
 * WHAT IT CANNOT SEE, stated so it is not mistaken for a bug: the managed
 * webhook (InboundWebhookAttributionResolver) records a billing operation but
 * writes no conversation, so a reply that arrived ONLY through the managed path
 * is not in this history — exactly as it is absent from the inbox today. This
 * subject reads the product's conversation truth; it does not invent another.
 *
 * One bounded EXISTS: the Business's conversations with this number (normally
 * one), then that thread's messages by `box_id`. No expression, no customer SQL,
 * bound parameters only.
 */
final readonly class ContactRepliedSinceEnrollmentSubject implements ConditionSubject
{
    public function key(): string
    {
        return 'contact.replied_since_enrollment';
    }

    public function valueType(): string
    {
        return 'boolean';
    }

    /** @return list<ConditionOperator> */
    public function allowedOperators(): array
    {
        return ConditionOperator::forBoolean();
    }

    public function valueFor(Contacts $contact, AutomationEnrollment $enrollment): mixed
    {
        if ($enrollment->enrolled_at === null || $enrollment->business_id === null) {
            return false;
        }

        $businessId = (int) $enrollment->business_id;

        if ($contact->business_id === null || (int) $contact->business_id !== $businessId) {
            return false;
        }

        $phone = MessageReceivedTriggerSource::normalizePhone((string) $contact->phone);

        if ($phone === '') {
            return false;
        }

        return DB::table('chat_box_messages as m')
            ->join('chat_boxes as b', 'b.id', '=', 'm.box_id')
            ->where('b.business_id', $businessId)
            ->where('b.to', $phone)
            ->where('m.direction', 'incoming')
            ->where('m.created_at', '>', $enrollment->enrolled_at)
            ->exists();
    }
}
