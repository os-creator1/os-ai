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
    private const RECOVERABLE = ['manual_hold', 'campaign_not_active', 'no_business', 'workspace_inactive', 'not_entitled', 'no_sending_number', 'verification_incomplete', 'sequence_paused'];

    public function __construct(
        private readonly OutreachEligibility $eligibility,
        private readonly OutreachMessageSender $sender,
        private readonly OutreachConversationLinker $linker,
        private readonly OutreachSendBudget $budget,
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

            // A step is delivered to a prospect once, whatever order the sends arrive in.
            if ($plan->stepId !== null
                && $plan->intent !== 'link_resend'
                && in_array($plan->stepId, OutreachSequence::forWorkspace($member->workspace)->sentIdsFor($member), true)) {
                return 'step_already_sent';
            }

            // A newer reply to the same conversation replaces an older one still WAITING for its
            // send slot: that older text is stale and must neither block nor follow this one.
            if ($plan->purpose === AgencyProspectMessage::PURPOSE_AI_REPLY) {
                AgencyProspectMessage::query()
                    ->where('campaign_member_id', $member->id)
                    ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
                    ->where('purpose', AgencyProspectMessage::PURPOSE_AI_REPLY)
                    ->where('status', AgencyProspectMessage::STATUS_PAUSED)
                    ->whereIn('failure_reason', [OutreachSendBudget::REASON_RATE, OutreachSendBudget::REASON_OPENER_CAP])
                    ->update(['status' => AgencyProspectMessage::STATUS_FAILED, 'failure_reason' => 'superseded_by_newer_reply']);
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
                    'sequence_step_id' => $plan->stepId,
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
        // Locate the member BEFORE the claim transaction. A non-locking read
        // inside a REPEATABLE READ transaction would freeze the campaign status
        // before waiting for End's member lock and could resume after End commits.
        // We only trust the ID: the ledger row is re-read under its lock below.
        $locator = AgencyProspectMessage::query()->whereKey($messageId)->first(['id', 'campaign_member_id']);

        if ($locator === null) {
            return null;
        }

        $claim = DB::transaction(function () use ($messageId, $locator) {
            // Same lock order as claim, settlement and End: member first,
            // then ledger. Eligibility runs after the member lock is held.
            $member = AgencyProspectCampaignMember::query()
                ->whereKey($locator->campaign_member_id)
                ->lockForUpdate()
                ->first();
            if ($member === null) {
                return 'member_missing';
            }

            $row = AgencyProspectMessage::query()->whereKey($messageId)->lockForUpdate()->first();

            if ($row === null
                || $row->direction !== AgencyProspectMessage::DIRECTION_OUTBOUND
                || $row->status !== AgencyProspectMessage::STATUS_PAUSED
                || (int) $row->campaign_member_id !== (int) $member->id) {
                return 'not_paused';
            }

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

            // A deferred reply is only worth sending while it still answers the LATEST message.
            if ($row->purpose === AgencyProspectMessage::PURPOSE_AI_REPLY
                && preg_match('/^outreach:reply:(\d+)$/', (string) $row->operation_key, $m) === 1
                && AgencyProspectMessage::query()
                    ->where('campaign_member_id', $member->id)
                    ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
                    ->where('id', '>', (int) $m[1])
                    ->exists()) {
                $row->update(['status' => AgencyProspectMessage::STATUS_FAILED, 'failure_reason' => 'superseded_by_newer_inbound']);

                return 'superseded_by_newer_inbound';
            }

            if ($this->inFlight($member->id, $row->id, false)) {
                return 'send_in_flight';
            }

            $row->update(['status' => AgencyProspectMessage::STATUS_PENDING, 'failure_reason' => null, 'deferred_until' => null]);

            return ['row' => $row->fresh(), 'business' => $eligible->business, 'prospect' => $eligible->prospect];
        });

        if (is_string($claim)) {
            return $claim === 'not_paused' ? null : new OutreachPipelineResult(skipReason: $claim);
        }

        return $this->deliver($claim['row'], $claim['business'], $claim['prospect']);
    }

    private function deliver(AgencyProspectMessage $row, Business $business, AgencyProspect $prospect): OutreachPipelineResult
    {
        // The shared budget of this sending number. Over budget = the row stays claimed (same operation
        // key, nothing recorded as delivered) and is retried by a delayed job; nothing sleeps.
        if (OutreachSendBudget::enabled()) {
            $slot = $this->budget->reserve($business, $row);

            if (! $slot['granted']) {
                return $this->defer($row, (string) $slot['reason'], $slot['retryAt']);
            }
        }

        $result = $this->sender->send($business, (string) $prospect->phone, (string) $row->body, (string) $row->operation_key);

        $row = $this->settle($row, $result);

        return new OutreachPipelineResult(null, $result, $row);
    }

    private function defer(AgencyProspectMessage $row, string $reason, \Illuminate\Support\Carbon $retryAt): OutreachPipelineResult
    {
        $at = $this->budget->jitter($retryAt, $row);

        DB::transaction(function () use ($row, $reason, $at): void {
            // Match claim/settle's lock order (member, then message).
            AgencyProspectCampaignMember::query()->whereKey($row->campaign_member_id)->lockForUpdate()->first(['id']);
            $row->update(['status' => AgencyProspectMessage::STATUS_PAUSED, 'failure_reason' => $reason, 'deferred_until' => $at]);
        });

        \App\Jobs\Outreach\OutreachDeferredSendJob::dispatch((int) $row->id)->delay($at);

        return new OutreachPipelineResult(null, OutreachSendResult::paused($reason), $row->fresh());
    }

    private function settle(AgencyProspectMessage $row, OutreachSendResult $result): AgencyProspectMessage
    {
        $followUpAt = null;

        DB::transaction(function () use ($row, $result, &$followUpAt): void {
            // Match claim/End's lock order (member, then message).
            // A provider call has already finished, so settlement must not
            // deadlock against End and leave a delivered SMS stuck PENDING.
            AgencyProspectCampaignMember::query()
                ->whereKey($row->campaign_member_id)
                ->lockForUpdate()
                ->first(['id']);

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

        $sequence = OutreachSequence::forWorkspace($member->workspace);
        $sentBefore = $sequence->sentIdsFor($member);
        $stepId = $row->sequence_step_id;

        if ($row->purpose === AgencyProspectMessage::PURPOSE_FOLLOWUP) {
            $updates['followup_sent_at'] = now();
        } elseif ($from !== null && $to !== null && $from !== $to && $member->stage->value === $from) {
            $updates['stage'] = $to;

            // The message that carries the calendar link moves the prospect to "link sent".
            if ($to === AgencyProspectStage::BookingLinkSent->value) {
                $updates['booking_link_sent_at'] = now();
            }
        }

        // A campaign opener that IS the first scripted step carries no step id of its own, but
        // it has just delivered that step: record it and arm whatever timed step follows,
        // exactly as a scripted send of that step would.
        $openerDeliveredStep = $stepId === null
            && $row->purpose === AgencyProspectMessage::PURPOSE_INITIAL
            && $sentBefore !== [];

        if ($stepId !== null || $openerDeliveredStep) {
            // Progress is the set of step ids delivered: editing the sequence later can
            // neither repeat a step nor skip or reorder one for this prospect.
            $sentNow = array_values(array_unique($stepId === null ? $sentBefore : [...$sentBefore, $stepId]));
            $updates['sequence_sent_ids'] = $sentNow;
            // Which send each unattributed (opener / hand-sent) step rests on: stored so a later
            // reword, disable or rebuild of the step can never hand that evidence to another step.
            $updates['sequence_evidence'] = $sequence->evidenceFor($member) ?: null;

            // The very next thing due may be a timed step: one timer at a time, and a
            // legacy follow-up that was already sent or cancelled is never re-armed.
            $next = $sequence->nextTimeStep($sentNow);

            if ($next !== null
                && ! ($next['id'] === OutreachSequence::LEGACY_FOLLOWUP
                    && ($member->followup_sent_at !== null || $member->followup_cancelled_at !== null))) {
                $followUpAt = now()->addHours((int) $next['delay_hours']);
                $updates['followup_at'] = $followUpAt;
                $updates['followup_sent_at'] = null;
                $updates['followup_cancelled_at'] = null;
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
