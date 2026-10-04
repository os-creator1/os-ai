<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The inbound half of the Outreach ledger (`agency_prospect_messages`, direction
 * `inbound`): one row per prospect message, keyed `outreach:in:{occurrenceKey}`
 * (unique), so a redelivered event is a no-op, and a row that moves
 * `received` -> `handled` exactly once when the responder is done with it.
 *
 * `failure_reason` on an inbound row is the reason the engine did NOT reply
 * (manual_hold, opted_out, duplicate_of_last_outbound, ...) — the audit answer to
 * "why did nobody answer this?".
 */
final class OutreachInboundLedger
{
    public const STATUS_HANDLED = 'handled';

    public static function key(string $occurrenceKey): string
    {
        return 'outreach:in:' . $occurrenceKey;
    }

    /**
     * Records the inbound message once.
     *
     * @return array{0: AgencyProspectMessage, 1: bool} the row and whether THIS call created it
     */
    public function record(AgencyProspectCampaignMember $member, string $occurrenceKey, string $body): array
    {
        $key = self::key($occurrenceKey);

        $existing = AgencyProspectMessage::query()->where('operation_key', $key)->first();

        if ($existing !== null) {
            return [$existing, false];
        }

        try {
            $row = AgencyProspectMessage::create([
                'workspace_id' => $member->workspace_id,
                'campaign_member_id' => $member->id,
                'channel_id' => null,
                'direction' => AgencyProspectMessage::DIRECTION_INBOUND,
                'operation_key' => $key,
                'body' => $body,
                'status' => AgencyProspectMessage::STATUS_RECEIVED,
                'received_at' => now(),
            ]);

            return [$row, true];
        } catch (UniqueConstraintViolationException) {
            return [AgencyProspectMessage::query()->where('operation_key', $key)->firstOrFail(), false];
        }
    }

    /** Marks an inbound row handled, once; the intent and the no-reply reason are first-write-wins. */
    public function markHandled(int $inboundMessageId, ?string $intent = null, ?string $reason = null): void
    {
        AgencyProspectMessage::query()
            ->where('id', $inboundMessageId)
            ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->update([
                'status' => self::STATUS_HANDLED,
            ]);

        if ($intent !== null) {
            AgencyProspectMessage::query()->where('id', $inboundMessageId)->whereNull('intent')->update(['intent' => $intent]);
        }

        if ($reason !== null) {
            AgencyProspectMessage::query()->where('id', $inboundMessageId)->whereNull('failure_reason')->update(['failure_reason' => $reason]);
        }
    }
}
