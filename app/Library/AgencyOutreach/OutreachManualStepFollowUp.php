<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Jobs\Outreach\OutreachFollowUpJob;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Business;
use App\Models\ChatBox;
use App\Models\ChatBoxMessage;
use App\Models\Reports;
use App\Library\Conversations\ConversationHistoryWriter;
use Illuminate\Support\Facades\DB;

/**
 * Reconcile ONLY confirmed, manually sent Conversation messages against an active
 * managed Outreach member. If the manual SMS supplied the next scripted reply step,
 * arm its timed successor just as an Outreach-owned send does.
 *
 * Runs after the manual-message history transaction commits. No provider request,
 * no SMS send, no global scan or change to manual-message ownership.
 */
final class OutreachManualStepFollowUp
{
    public function reconcile(int $messageId): void
    {
        $message = ChatBoxMessage::query()->find($messageId);
        if ($message === null
            || $message->direction !== Reports::DIRECTION_OUTGOING
            || $message->source !== ConversationHistoryWriter::SOURCE_CONVERSATIONS
            || ! in_array((string) $message->send_status, ['sent', 'delivered'], true)
            || trim((string) $message->message) === '') {
            return;
        }

        $box = ChatBox::query()->find((int) $message->box_id);
        $business = $box === null ? null : Business::query()->find((int) $box->business_id);
        if ($business === null || $business->workspace_id === null) {
            return;
        }

        // No contact-number guess and no cross-tenant scan: only members already linked
        // to THIS Business's actual Conversation may be reconciled.
        $ids = AgencyProspectCampaignMember::query()
            ->where('chat_box_id', $box->id)
            ->where('workspace_id', (int) $business->workspace_id)
            ->whereIn('campaign_id', AgencyProspectCampaign::query()
                ->where('sending_mode', AgencyProspectCampaign::MODE_MANAGED)
                ->where('status', AgencyProspectCampaignStatus::Active->value)
                ->select('id'))
            // Even a pending earlier timer needs reconciliation when the owner
            // manually delivers a LATER reply step in the same sequence.
            ->whereNotIn('stage', [6, 99])
            ->orderByDesc('id')
            ->limit(5)
            ->pluck('id');

        foreach ($ids as $id) {
            $due = DB::transaction(function () use ($id, $message, $business): ?\Illuminate\Support\Carbon {
                $member = AgencyProspectCampaignMember::query()->whereKey($id)->lockForUpdate()->first();
                if ($member === null || $member->isTerminal()
                    || (int) $member->chat_box_id !== (int) $message->box_id
                    || (int) $member->workspace_id !== (int) $business->workspace_id
                    || $member->campaign?->status !== AgencyProspectCampaignStatus::Active
                    || ! $member->campaign->isManaged()) {
                    return null;
                }

                // The moment the SMS was actually CONFIRMED sent (the accepted attempt), not when its
                // bubble was first created: a failed message retried hours later was sent at the retry.
                $sentAt = self::confirmedAt($message);

                $opener = AgencyProspectMessage::query()
                    ->where('campaign_member_id', $member->id)
                    ->where('purpose', AgencyProspectMessage::PURPOSE_INITIAL)
                    ->where('status', AgencyProspectMessage::STATUS_SENT)
                    ->orderBy('id')
                    ->first(['sent_at', 'created_at']);
                if ($opener === null || $sentAt->lt($opener->sent_at ?? $opener->created_at)
                    || ($member->last_inbound_at !== null && $member->last_inbound_at->gt($sentAt))) {
                    return null;
                }

                $workspace = $member->workspace;
                $prospect = $member->prospect;
                if ($workspace === null || $prospect === null
                    || (int) (AgencyOutreachBusinessResolver::forWorkspace($workspace)?->id ?? 0) !== (int) $business->id) {
                    return null;
                }

                $sequence = OutreachSequence::forWorkspace($workspace);
                $sent = $sequence->sentIdsFor($member);
                $next = $sequence->nextTimeStep($sent);
                if ($next === null) {
                    return null;
                }

                $previous = null;
                foreach ($sequence->enabledSteps() as $step) {
                    if ($step['id'] === $next['id']) {
                        break;
                    }
                    $previous = $step;
                }

                // A manual message unrelated to the immediate timed predecessor must never arm
                // the timer. Matching is exact rendered text, not substring or fuzzy inference.
                if ($previous === null || $previous['advance_on'] !== OutreachSequence::REPLY
                    || ! in_array($previous['id'], $sent, true)
                    || ! OutreachSequence::sameText(
                        (string) $message->message,
                        OutreachScriptRenderer::render(OutreachScriptTokens::canonicalise((string) $previous['body']), $workspace, $prospect)
                    )) {
                    return null;
                }

                // A pending timer may belong to the predecessor's EARLIER timed step.
                // If this manual reply advanced beyond it, replace the deadline with the
                // new successor's delay; otherwise the old job would send the new step early.
                // Once the new predecessor is persisted, repeated callbacks cannot move its timer.
                if (self::timerPending($member)
                    && in_array($previous['id'], (array) $member->sequence_sent_ids, true)) {
                    return null;
                }

                // The legacy follow-up that was already sent or cancelled is never re-armed.
                if ($next['id'] === OutreachSequence::LEGACY_FOLLOWUP
                    && ($member->followup_sent_at !== null || $member->followup_cancelled_at !== null)) {
                    return null;
                }

                $when = $sentAt->copy()->addHours((int) $next['delay_hours']);
                $updates = [
                    'sequence_sent_ids' => $sent,
                    'sequence_evidence' => $sequence->evidenceFor($member) ?: null,
                    'last_outbound_at' => $member->last_outbound_at === null || $member->last_outbound_at->lt($sentAt)
                        ? $sentAt : $member->last_outbound_at,
                    // Arm exactly as a scripted send does: a fresh pending timer, historical state cleared.
                    'followup_at' => $when,
                    'followup_sent_at' => null,
                    'followup_cancelled_at' => null,
                ];

                if (OutreachSequence::stepHasCalendarLink($previous)) {
                    // For the legacy link follow-up the cancellation boundary is
                    // booking_link_sent_at, not last_outbound_at. An inbound BEFORE
                    // this confirmed manual link must not cancel the due nudge.
                    $updates['booking_link_sent_at'] = $sentAt;
                    if ($member->stage->value < AgencyProspectStage::BookingLinkSent->value) {
                        $updates['stage'] = AgencyProspectStage::BookingLinkSent->value;
                    }
                }

                $member->update($updates);

                return $when;
            });

            if ($due !== null) {
                OutreachFollowUpJob::dispatch((int) $id)->delay($due);
            }
        }
    }

    /** A timer is pending only while it is set and neither sent nor cancelled. */
    private static function timerPending(AgencyProspectCampaignMember $member): bool
    {
        return $member->followup_at !== null
            && $member->followup_sent_at === null
            && $member->followup_cancelled_at === null;
    }

    /**
     * When the message was actually confirmed sent: the accepted attempt's messaging operation
     * (a retry re-points the SAME bubble at the attempt that succeeded), else the bubble's own time.
     */
    private static function confirmedAt(ChatBoxMessage $message): \Illuminate\Support\Carbon
    {
        $occurred = $message->business_messaging_operation_id === null ? null : DB::table('business_messaging_operations')
            ->where('id', (int) $message->business_messaging_operation_id)->value('occurred_at');

        return \Illuminate\Support\Carbon::parse($occurred ?? $message->created_at);
    }
}
