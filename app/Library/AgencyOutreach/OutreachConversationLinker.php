<?php

namespace App\Library\AgencyOutreach;

use App\Library\Automation\Workflow\Triggers\MessageReceivedTriggerSource;
use App\Library\Conversations\ConversationHistoryWriter;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingChannel;
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
    /**
     * @param  string|null  $senderNumber  the agency number the prospect's conversation is pinned to:
     *         that number's conversation is preferred (a prospect may have threads with several of the
     *         agency's numbers); null = the most recent conversation, as before
     */
    public function conversationFor(Business $business, AgencyProspect $prospect, ?string $senderNumber = null): ?ChatBox
    {
        $to = MessageReceivedTriggerSource::normalizePhone((string) $prospect->phone);

        if ($to === '') {
            return null;
        }

        $from = $senderNumber === null ? '' : MessageReceivedTriggerSource::normalizePhone($senderNumber);

        if ($from !== '') {
            $pinned = ChatBox::query()
                ->where('business_id', (int) $business->id)
                ->where('to', $to)
                ->where('from', $from)
                ->orderByDesc('id')
                ->first();

            if ($pinned !== null) {
                return $pinned;
            }
        }

        return ChatBox::query()
            ->where('business_id', (int) $business->id)
            ->where('to', $to)
            ->orderByDesc('id')
            ->first();
    }

    /** Sets member.chat_box_id when it is empty or points at a conversation that no longer exists. */
    public function link(AgencyProspectCampaignMember $member, Business $business, AgencyProspect $prospect, ?string $senderNumber = null): ?ChatBox
    {
        $box = $this->conversationFor($business, $prospect, $senderNumber);

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
    public function ownerRepliedAfterLatestInbound(Business $business, AgencyProspect $prospect, ?string $senderNumber = null): bool
    {
        $box = $this->conversationFor($business, $prospect, $senderNumber);

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

    /** The number the member's conversation is pinned to (null = not pinned: managed / legacy). */
    public function senderNumberFor(AgencyProspectCampaignMember $member): ?string
    {
        if ($member->sender_channel_id === null) {
            return null;
        }

        $number = AgencyProspectingChannel::query()
            ->whereKey((int) $member->sender_channel_id)
            ->where('workspace_id', (int) $member->workspace_id)
            ->value('sender_number');

        return $number === null ? null : (string) $number;
    }

    /** The body of the latest incoming message of the prospect's conversation, with its id. */
    public function latestInbound(Business $business, AgencyProspect $prospect, ?string $senderNumber = null, ?int $conversationBoxId = null): ?ChatBoxMessage
    {
        // The exact conversation an inbound was filed under, when known and when it is this
        // Business's conversation with this prospect; otherwise the pinned / latest one.
        $box = null;

        if ($conversationBoxId !== null) {
            $box = ChatBox::query()
                ->whereKey($conversationBoxId)
                ->where('business_id', (int) $business->id)
                ->where('to', MessageReceivedTriggerSource::normalizePhone((string) $prospect->phone))
                ->first();
        }

        $box ??= $this->conversationFor($business, $prospect, $senderNumber);

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
