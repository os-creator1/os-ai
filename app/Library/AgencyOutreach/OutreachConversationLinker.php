<?php

namespace App\Library\AgencyOutreach;

use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Reports;

/**
 * Ties a prospect to its canonical Conversation (contract §8) — never creates one.
 *
 * A conversation's identity is (customer, Business, the Business's number, the
 * person's number), the person's number normalised by
 * MessageReceivedTriggerSource::normalizePhone() (the one scheme chat_boxes.to is
 * written in). The match here is the Agency's OWN Business + that person's number, so a
 * conversation of any other Business is never linked: it cannot be, the Business id is
 * part of the query.
 *
 * Also answers one conversation question the responder needs: has a person already
 * replied by hand after the latest inbound message? (manual-outbound suppression, §13).
 */
final class OutreachConversationLinker
{
    public function conversationFor(Business $business, AgencyProspect $prospect): ?ChatBox
    {
        $to = MessageReceivedTriggerSource::normalizePhone((string) $prospect->phone);

        if ($to === '') {
            return null;
        }

        return ChatBox::query()
            ->where('business_id', (int) $business->id)
            ->where('to', $to)
            ->orderByDesc('id')
            ->first();
    }

    /** Sets member.chat_box_id when it is empty or points at a conversation that no longer exists. */
    public function link(AgencyProspectCampaignMember $member, Business $business, AgencyProspect $prospect): ?ChatBox
    {
        $box = $this->conversationFor($business, $prospect);

        if ($box === null) {
            return null;
        }

        $current = $member->chat_box_id;

        if ($current === null || ! ChatBox::query()->where('id', $current)->where('business_id', (int) $business->id)->exists()) {
            AgencyProspectCampaignMember::query()->where('id', $member->id)->update(['chat_box_id' => $box->id]);
            $member->chat_box_id = $box->id;
        }

        return $box;
    }

    /**
     * True when a PERSON (Conversations, not an automatic send) sent a message in this
     * conversation after the latest inbound message — the owner already answered, so an
     * automatic reply to that inbound would be a double reply.
     */
    public function ownerRepliedAfterLatestInbound(Business $business, AgencyProspect $prospect): bool
    {
        $box = $this->conversationFor($business, $prospect);

        if ($box === null) {
            return false;
        }

        $latestInbound = ChatBoxMessage::query()
            ->where('box_id', $box->id)
            ->where('direction', Reports::DIRECTION_INCOMING)
            ->max('id');

        if ($latestInbound === null) {
            return false;
        }

        return ChatBoxMessage::query()
            ->where('box_id', $box->id)
            ->where('direction', Reports::DIRECTION_OUTGOING)
            ->where('source', ConversationHistoryWriter::SOURCE_CONVERSATIONS)
            ->where('id', '>', $latestInbound)
            ->exists();
    }

    /** The body of the latest incoming message of the prospect's conversation, with its id. */
    public function latestInbound(Business $business, AgencyProspect $prospect): ?ChatBoxMessage
    {
        $box = $this->conversationFor($business, $prospect);

        if ($box === null) {
            return null;
        }

        return ChatBoxMessage::query()
            ->where('box_id', $box->id)
            ->where('direction', Reports::DIRECTION_INCOMING)
            ->orderByDesc('id')
            ->first();
    }
}
