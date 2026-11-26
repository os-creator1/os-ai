<?php

namespace App\Jobs\Outreach;

use App\Jobs\Base;
use App\Library\AgencyOutreach\OutreachClassification;
use App\Library\AgencyOutreach\OutreachDecision;
use App\Library\AgencyOutreach\OutreachEligibility;
use App\Library\AgencyOutreach\OutreachInboundLedger;
use App\Library\AgencyOutreach\OutreachQuestionMatcher;
use App\Library\AgencyOutreach\OutreachUnansweredQuestions;
use App\Library\AgencyOutreach\OutreachReplyComposer;
use App\Library\AgencyOutreach\OutreachFaq;
use App\Library\AgencyOutreach\OutreachScript;
use App\Library\AgencyOutreach\OutreachSequence;
use App\Library\AgencyOutreach\OutreachSendPipeline;
use App\Library\AgencyOutreach\OutreachSendPlan;
use App\Library\AgencyOutreach\OutreachStageMachine;
use App\Library\AgencyOutreach\OutreachStepTone;
use App\Library\AgencyOutreach\OutreachStopService;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;

/**
 * The Outreach responder for ONE exact inbound message (contract §4/§5/§9/§13).
 * The old AgencyProspectingRespondJob is untouched and only ever serves `channel`
 * campaigns; this serves `managed` ones.
 *
 *   read    the member + inbound (the pair must belong to one Workspace, or nothing happens)
 *   decide  deterministic: stop class, question intent, then OutreachStageMachine
 *   write   an opt-out / rejection is applied and NOTHING is sent
 *   match   every question in the reply against the Agency's approved answers
 *           (OutreachQuestionMatcher): deterministic first, then at most ONE bounded model call
 *           that may only SELECT approved answer ids for what is left
 *   compose the approved answers in the prospect's order, then the exact stage message
 *           (or the answers alone when the prospect asked to hold the booking pitch);
 *           whatever stayed unanswered goes to the Unanswered questions queue, once
 *   send    through OutreachSendPipeline: member row lock, compare-and-set on the stage the
 *           decision was made from, eligibility re-check at send time (AI paused, campaign
 *           active, prospect active, Blacklists, owner already replied by hand ...), ledger
 *           claim under `outreach:reply:{inboundMessageId}`, canonical send, settle
 *
 * `$tries = 1` (Base): a second run for the same inbound finds the reply key claimed and
 * does nothing, which is what makes a redelivered event or a duplicated job harmless.
 * Whatever the outcome, the inbound row ends `handled`, with the reason when no reply went.
 */
class OutreachRespondJob extends Base
{
    public function __construct(
        private readonly int $campaignMemberId,
        private readonly int $inboundMessageId,
    ) {
    }

    public function handle(
        OutreachEligibility $eligibility,
        OutreachStopService $stops,
        OutreachReplyComposer $composer,
        OutreachSendPipeline $pipeline,
        OutreachInboundLedger $ledger,
        OutreachQuestionMatcher $matcher,
        OutreachUnansweredQuestions $unansweredQueue,
    ): void {
        $member = AgencyProspectCampaignMember::query()->where('id', $this->campaignMemberId)->first();

        if ($member === null) {
            return;
        }

        $inbound = AgencyProspectMessage::query()
            ->where('id', $this->inboundMessageId)
            ->where('campaign_member_id', $member->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->first();

        // The ids must bind to ONE real inbound message of the SAME Workspace as the member:
        // a forged or cross-Workspace pairing never replies to anyone.
        if ($inbound === null || (int) $inbound->workspace_id !== (int) $member->workspace_id) {
            return;
        }

        if ($inbound->status === OutreachInboundLedger::STATUS_HANDLED) {
            return;
        }

        // Not due yet: the delayed dispatch scheduled for the stored moment answers it. An early or
        // duplicate run changes nothing and never marks the message handled.
        if ($inbound->reply_due_at !== null && $inbound->reply_due_at->isFuture()) {
            return;
        }

        $replyKey = 'outreach:reply:' . $inbound->id;

        if (AgencyProspectMessage::query()->where('operation_key', $replyKey)->exists()) {
            return;
        }

        $eligible = $eligibility->check($member, true);

        if (! $eligible->ok()) {
            $ledger->markHandled($inbound->id, null, $eligible->reason);

            return;
        }

        // A newer message from the prospect supersedes this reply: answering the older one now would
        // be a stale reply. The newest message has its own due moment and answers the conversation.
        if ($this->hasNewerInbound($member->id, $inbound->id)) {
            $ledger->markHandled($inbound->id, null, 'superseded_by_newer_inbound');

            return;
        }

        $linkRepeatUsed = AgencyProspectMessage::query()
            ->where('campaign_member_id', $member->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('intent', 'link_resend')
            ->exists();

        $classification = OutreachClassification::of((string) $inbound->body, $linkRepeatUsed);
        $sequence = OutreachSequence::forWorkspace($eligible->workspace);
        $faq = OutreachFaq::forWorkspace($eligible->workspace);
        $campaignId = $member->campaign_id === null ? null : (int) $member->campaign_id;
        $plan = $matcher->match($faq, (string) $inbound->body, $campaignId);
        $decision = OutreachStageMachine::decide($member->stage, $classification, $sequence, $sequence->sentIdsFor($member), $plan->hasRequests());

        if ($decision->stops()) {
            $stops->apply($member, $decision->action === OutreachDecision::OPT_OUT, $inbound->id);

            return;
        }

        if (! $decision->sends()) {
            $ledger->markHandled($inbound->id, $classification->intent, $decision->reason);

            return;
        }

        $lastOutbound = AgencyProspectMessage::query()
            ->where('campaign_member_id', $member->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('status', AgencyProspectMessage::STATUS_SENT)
            ->orderByDesc('id')
            ->value('body');

        // A plain "no" must not be answered with "Great!" or a booking pitch: hold it for the owner.
        if ($decision->action === OutreachDecision::SEND_STAGE
            && $decision->stepId !== null
            && OutreachStepTone::isBareNegative((string) $inbound->body)) {
            $stepBody = (string) ($sequence->find($decision->stepId)['body'] ?? '');

            if (OutreachStepTone::assumesYes($stepBody) || OutreachStepTone::isBookingPitch($stepBody)) {
                $ledger->markHandled($inbound->id, $classification->intent, 'negative_reply_held');

                return;
            }
        }

        // The one bounded model call for the whole inbound, only for what deterministic matching
        // left and only when the Agency lets AI help. It can select approved ids, nothing else.
        if ($plan->unresolved !== []
            && $faq->fallback() === OutreachFaq::FALLBACK_AI
            && OutreachScript::forWorkspace($eligible->workspace)->aiEnabled()) {
            $plan = $matcher->resolveWithAi($plan, $faq, $eligible->workspace, (string) $inbound->body, $campaignId, (int) $inbound->id);
        }

        $composed = $composer->compose(
            $eligible->workspace,
            $eligible->prospect,
            $decision,
            $classification->intent,
            (int) $inbound->id,
            $lastOutbound === null ? null : (string) $lastOutbound,
            $sequence->deliveredByContent($member),
            $plan,
        );

        // Recorded once per (inbound, wording) whatever happens to the reply; teaching from the
        // queue changes future replies only and never texts this prospect.
        $unansweredQueue->record($member, $inbound, $composed->unanswered);

        if (! $composed->hasText()) {
            $ledger->markHandled($inbound->id, $classification->intent, $composed->reason);

            return;
        }

        $result = $pipeline->run($member->id, new OutreachSendPlan(
            operationKey: $replyKey,
            body: (string) $composed->text,
            source: $composed->source,
            purpose: AgencyProspectMessage::PURPOSE_AI_REPLY,
            stageFrom: $decision->stageFrom,
            // A held booking pitch is not delivered: the stage stays and the step stays pending.
            stageTo: $composed->stepDeferred ? $decision->stageFrom : $decision->stageTo,
            intent: $decision->action === OutreachDecision::RESEND_LINK ? 'link_resend' : $classification->intent,
            scriptVersion: OutreachScript::forWorkspace($eligible->workspace)->version(),
            suppressAfterManualReply: true,
            stepId: $composed->stepDeferred ? null : $decision->stepId,
        ), fn () => $this->hasNewerInbound($member->id, $inbound->id) ? 'superseded_by_newer_inbound' : null);

        // 'already_claimed' means a concurrent run owns this reply: it is not a reason for silence.
        $deferred = in_array($result->send?->reason, [\App\Library\AgencyOutreach\OutreachSendBudget::REASON_RATE, \App\Library\AgencyOutreach\OutreachSendBudget::REASON_OPENER_CAP], true);
        $reason = $result->claimed()
            ? ($result->send?->isSent() || $deferred ? null : $result->send?->reason)
            : ($result->skipReason === 'already_claimed' ? null : $result->skipReason);

        $ledger->markHandled($inbound->id, $classification->intent, $reason);
    }

    private function hasNewerInbound(int $memberId, int $inboundId): bool
    {
        return AgencyProspectMessage::query()
            ->where('campaign_member_id', $memberId)
            ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->where('id', '>', $inboundId)
            ->exists();
    }
}
