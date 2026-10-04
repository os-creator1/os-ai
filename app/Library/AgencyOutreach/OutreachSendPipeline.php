<?php

namespace App\Library\AgencyOutreach;

use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Jobs\Outreach\OutreachFollowUpJob;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Business;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The one path every automatic Outreach message takes — reply, opener, follow-up and
 * a paused send being resumed — so the guarantees are written once.
 *
 *   1. CLAIM, under the member's row lock: eligibility is re-checked against fresh
 *      rows, the stage must still be the one the decision was made from
 *      (compare-and-set), no other send for this member may be in flight, and a ledger
 *      row is inserted under the operation key. The unique key is what makes a
 *      duplicate job run lose and send nothing.
 *   2. SEND, outside any transaction: OutreachMessageSender (canonical, idempotent on
 *      the same key).
 *   3. SETTLE: the ledger row records exactly what happened; a SENT message then moves
 *      the member (stage, follow-up schedule, conversation link) under the lock again,
 *      by compare-and-set, and never overwrites a member that became terminal meanwhile.
 *
 * `paused` (insufficient balance) is a ledger state, not a failure: the member keeps
 * its stage and the row is re-sent under the SAME key by resume().
 */
final class OutreachSendPipeline
{
    /** Eligibility refusals that only HOLD a paused send; anything else cancels it. */
    private const RECOVERABLE = ['manual_hold', 'campaign_not_active', 'no_business', 'workspace_inactive', 'not_entitled', 'no_sending_number', 'verification_incomplete'];

    public function __construct(
        private readonly OutreachEligibility $eligibility,
        private readonly OutreachMessageSender $sender,
        private readonly OutreachConversationLinker $linker,
    ) {
    }

    /**
     * @param  (Closure(AgencyProspectCampaignMember, OutreachEligibilityResult): ?string)|null  $guard
     *         extra, send-specific check run inside the lock; a returned string is the skip reason
     */
    public function run(int $memberId, OutreachSendPlan $plan, ?Closure $guard = null): OutreachPipelineResult
    {
        $claim = DB::transaction(function () use ($memberId, $plan, $guard) {
            $member = AgencyProspectCampaignMember::query()->where('id', $memberId)->lockForUpdate()->first();

            if ($member === null) {
                return 'member_missing';
            }

            if (AgencyProspectMessage::query()->where('operation_key', $plan->operationKey)->exists()) {
                return 'already_claimed';
            }

            $eligible = $this->eligibility->check($member, $plan->requireAiActive);

            if (! $eligible->ok()) {
                return $eligible->reason;
            }

            if ($member->stage->value !== $plan->stageFrom) {
                return 'stage_changed';
            }

            if ($this->inFlight($member->id, null, true)) {
                return 'send_in_flight';
            }

            if ($guard !== null && ($reason = $guard($member, $eligible)) !== null) {
                return $reason;
            }

            if ($plan->suppressAfterManualReply && $this->linker->ownerRepliedAfterLatestInbound($eligible->business, $eligible->prospect)) {
                return 'manual_reply_sent';
            }

            try {
                $row = AgencyProspectMessage::create([
                    'workspace_id' => $member->workspace_id,
                    'campaign_member_id' => $member->id,
                    'channel_id' => null,
                    'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
                    'purpose' => $plan->purpose,
                    'operation_key' => $plan->operationKey,
                    'body' => $plan->body,
                    'status' => AgencyProspectMessage::STATUS_PENDING,
                    'intent' => $plan->intent,
                    'source' => $plan->source,
                    'stage_from' => $plan->stageFrom,
                    'stage_to' => $plan->stageTo,
                    'script_version' => $plan->scriptVersion,
                ]);
            } catch (UniqueConstraintViolationException) {
                return 'already_claimed';
            }

            return ['row' => $row, 'business' => $eligible->business, 'prospect' => $eligible->prospect];
        });

        if (is_string($claim)) {
            return new OutreachPipelineResult(skipReason: $claim);
        }

        return $this->deliver($claim['row'], $claim['business'], $claim['prospect']);
    }

    /**
     * Re-sends ONE paused ledger row under its original operation key.
     *
     * Returns null when the row is not (or no longer) paused. A row whose member can no
     * longer be texted (opted out, booked, ...) is closed as failed with the reason; one
     * held back only by something recoverable (campaign paused, AI paused, ...) stays paused.
     */
    public function resume(int $messageId): ?OutreachPipelineResult
    {
        $claim = DB::transaction(function () use ($messageId) {
            $row = AgencyProspectMessage::query()->where('id', $messageId)->lockForUpdate()->first();

            if ($row === null
                || $row->direction !== AgencyProspectMessage::DIRECTION_OUTBOUND
                || $row->status !== AgencyProspectMessage::STATUS_PAUSED) {
                return 'not_paused';
            }

            $member = AgencyProspectCampaignMember::query()->where('id', $row->campaign_member_id)->lockForUpdate()->first();
            $eligible = $this->eligibility->check($member, true);

            if (! $eligible->ok()) {
                if (! in_array($eligible->reason, self::RECOVERABLE, true)) {
                    $row->update(['status' => AgencyProspectMessage::STATUS_FAILED, 'failure_reason' => $eligible->reason]);
                    $this->cancelFollowUpIfFollowUp($row);
                }

                return $eligible->reason;
            }

            if ($row->stage_from !== null && $member->stage->value !== (int) $row->stage_from) {
                $row->update(['status' => AgencyProspectMessage::STATUS_FAILED, 'failure_reason' => 'stage_changed']);
                $this->cancelFollowUpIfFollowUp($row);

                return 'stage_changed';
            }

            if ($row->purpose === AgencyProspectMessage::PURPOSE_AI_REPLY
                && $this->linker->ownerRepliedAfterLatestInbound($eligible->business, $eligible->prospect)) {
                $row->update(['status' => AgencyProspectMessage::STATUS_FAILED, 'failure_reason' => 'manual_reply_sent']);

                return 'manual_reply_sent';
            }

            if ($this->inFlight($member->id, $row->id, false)) {
                return 'send_in_flight';
            }

            $row->update(['status' => AgencyProspectMessage::STATUS_PENDING, 'failure_reason' => null]);

            return ['row' => $row->fresh(), 'business' => $eligible->business, 'prospect' => $eligible->prospect];
        });

        if (is_string($claim)) {
            return $claim === 'not_paused' ? null : new OutreachPipelineResult(skipReason: $claim);
        }

        return $this->deliver($claim['row'], $claim['business'], $claim['prospect']);
    }

    private function deliver(AgencyProspectMessage $row, Business $business, AgencyProspect $prospect): OutreachPipelineResult
    {
        $result = $this->sender->send($business, (string) $prospect->phone, (string) $row->body, (string) $row->operation_key);

        $row = $this->settle($row, $result);

        return new OutreachPipelineResult(null, $result, $row);
    }

    private function settle(AgencyProspectMessage $row, OutreachSendResult $result): AgencyProspectMessage
    {
        $followUpAt = null;

        DB::transaction(function () use ($row, $result, &$followUpAt): void {
            if ($result->isSent()) {
                $attributes = ['status' => AgencyProspectMessage::STATUS_SENT, 'failure_reason' => null, 'sent_at' => now()];

                try {
                    $row->update($attributes + ['provider_message_id' => $result->providerMessageId]);
                } catch (UniqueConstraintViolationException) {
                    $row->update($attributes);
                }

                $followUpAt = $this->applySentEffects($row, $result);

                return;
            }

            $row->update([
                'status' => $result->status === OutreachSendResult::PAUSED
                    ? AgencyProspectMessage::STATUS_PAUSED
                    : AgencyProspectMessage::STATUS_FAILED,
                'failure_reason' => $result->reason,
            ]);

            // A follow-up that could not be sent for any reason but "no money yet" is over:
            // it is one message, once, and the ledger row records why.
            if ($result->status !== OutreachSendResult::PAUSED) {
                $this->cancelFollowUpIfFollowUp($row);
            }
        });

        if ($followUpAt !== null) {
            OutreachFollowUpJob::dispatch((int) $row->campaign_member_id)->delay($followUpAt);
        }

        return $row->fresh();
    }

    /**
     * Moves the member after a SENT message. Under the member lock, by compare-and-set,
     * and a member that became terminal in the meantime is left exactly as it is.
     *
     * @return \Illuminate\Support\Carbon|null when a follow-up must be dispatched, the time it is due
     */
    private function applySentEffects(AgencyProspectMessage $row, OutreachSendResult $result): ?\Illuminate\Support\Carbon
    {
        $member = AgencyProspectCampaignMember::query()->where('id', $row->campaign_member_id)->lockForUpdate()->first();

        if ($member === null || $member->isTerminal()) {
            return null;
        }

        $updates = [
            'last_outbound_at' => now(),
            'last_provider_message_id' => $result->providerMessageId,
        ];

        $followUpAt = null;
        $from = $row->stage_from === null ? null : (int) $row->stage_from;
        $to = $row->stage_to === null ? null : (int) $row->stage_to;

        if ($row->purpose === AgencyProspectMessage::PURPOSE_FOLLOWUP) {
            $updates['followup_sent_at'] = now();
        } elseif ($from !== null && $to !== null && $from !== $to && $member->stage->value === $from) {
            $updates['stage'] = $to;

            // Message 3 is the calendar link: the one follow-up is scheduled from now.
            if ($to === AgencyProspectStage::BookingLinkSent->value) {
                $updates['booking_link_sent_at'] = now();

                $script = OutreachScript::forWorkspace($member->workspace);

                if ($script->followUpEnabled()
                    && $member->followup_sent_at === null
                    && $member->followup_cancelled_at === null) {
                    $followUpAt = now()->addHours($script->followUpDelayHours());
                    $updates['followup_at'] = $followUpAt;
                }
            }
        }

        $member->update($updates);

        $prospect = AgencyProspect::query()->find($member->prospect_id);
        $business = AgencyOutreachBusinessResolver::forWorkspace($member->workspace);

        if ($prospect !== null && $business !== null) {
            $this->linker->link($member, $business, $prospect);
        }

        return $followUpAt;
    }

    private function cancelFollowUpIfFollowUp(AgencyProspectMessage $row): void
    {
        if ($row->purpose !== AgencyProspectMessage::PURPOSE_FOLLOWUP) {
            return;
        }

        AgencyProspectCampaignMember::query()
            ->where('id', $row->campaign_member_id)
            ->whereNull('followup_sent_at')
            ->whereNull('followup_cancelled_at')
            ->update(['followup_cancelled_at' => now()]);

        Log::info('outreach.followup_not_sent', ['member_id' => (int) $row->campaign_member_id, 'reason' => $row->failure_reason]);
    }

    /** Another outbound for this member is claimed (pending) or, when `$paused`, held (paused) and not yet resolved. */
    private function inFlight(int $memberId, ?int $exceptMessageId, bool $paused): bool
    {
        return AgencyProspectMessage::query()
            ->where('campaign_member_id', $memberId)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->whereIn('status', $paused ? [AgencyProspectMessage::STATUS_PENDING, AgencyProspectMessage::STATUS_PAUSED] : [AgencyProspectMessage::STATUS_PENDING])
            ->when($exceptMessageId !== null, fn ($q) => $q->where('id', '!=', $exceptMessageId))
            ->exists();
    }
}
