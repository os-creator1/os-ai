<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
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
            ->whereNull('followup_at')
            ->whereNull('followup_sent_at')
            ->whereNull('followup_cancelled_at')
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
                    || $member->followup_at !== null || $member->followup_sent_at !== null
                    || $member->followup_cancelled_at !== null
                    || $member->campaign?->status !== AgencyProspectCampaignStatus::Active
                    || ! $member->campaign->isManaged()) {
                    return null;
                }

                $opener = AgencyProspectMessage::query()
                    ->where('campaign_member_id', $member->id)
                    ->where('purpose', AgencyProspectMessage::PURPOSE_INITIAL)
                    ->where('status', AgencyProspectMessage::STATUS_SENT)
                    ->orderBy('id')
                    ->first(['sent_at', 'created_at']);
                if ($opener === null || $message->created_at->lt($opener->sent_at ?? $opener->created_at)
                    || ($member->last_inbound_at !== null && $member->last_inbound_at->gt($message->created_at))) {
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

                $when = $message->created_at->copy()->addHours((int) $next['delay_hours']);
                $member->update([
                    'sequence_sent_ids' => $sent,
                    'last_outbound_at' => $member->last_outbound_at === null || $member->last_outbound_at->lt($message->created_at)
                        ? $message->created_at : $member->last_outbound_at,
                    'followup_at' => $when,
                ]);

                return $when;
            });

            if ($due !== null) {
                OutreachFollowUpJob::dispatch((int) $id)->delay($due);
            }
        }
    }
}
