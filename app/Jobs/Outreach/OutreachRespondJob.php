<?php

namespace App\Jobs\Outreach;

use App\Jobs\Base;
use App\Library\AgencyOutreach\OutreachClassification;
use App\Library\AgencyOutreach\OutreachDecision;
use App\Library\AgencyOutreach\OutreachEligibility;
use App\Library\AgencyOutreach\OutreachInboundLedger;
use App\Library\AgencyOutreach\OutreachReplyComposer;
use App\Library\AgencyOutreach\OutreachScript;
use App\Library\AgencyOutreach\OutreachSendPipeline;
use App\Library\AgencyOutreach\OutreachSendPlan;
use App\Library\AgencyOutreach\OutreachStageMachine;
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
 *   compose the exact stage message, preceded by an answer to a question (FAQ field, or the
 *           one bounded model call for a question the FAQ cannot place)
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

        $replyKey = 'outreach:reply:' . $inbound->id;

        if (AgencyProspectMessage::query()->where('operation_key', $replyKey)->exists()) {
            return;
        }

        $eligible = $eligibility->check($member, true);

        if (! $eligible->ok()) {
            $ledger->markHandled($inbound->id, null, $eligible->reason);

            return;
        }

        $linkRepeatUsed = AgencyProspectMessage::query()
            ->where('campaign_member_id', $member->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('intent', 'link_resend')
            ->exists();

        $classification = OutreachClassification::of((string) $inbound->body, $linkRepeatUsed);
        $decision = OutreachStageMachine::decide($member->stage, $classification);

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

        $composed = $composer->compose(
            $eligible->workspace,
            $eligible->prospect,
            $decision,
            $classification->intent,
            (int) $inbound->id,
            $lastOutbound === null ? null : (string) $lastOutbound,
        );

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
            stageTo: $decision->stageTo,
            intent: $decision->action === OutreachDecision::RESEND_LINK ? 'link_resend' : $classification->intent,
            scriptVersion: OutreachScript::forWorkspace($eligible->workspace)->version(),
            suppressAfterManualReply: true,
        ));

        // 'already_claimed' means a concurrent run owns this reply: it is not a reason for silence.
        $reason = $result->claimed()
            ? ($result->send?->isSent() ? null : $result->send?->reason)
            : ($result->skipReason === 'already_claimed' ? null : $result->skipReason);

        $ledger->markHandled($inbound->id, $classification->intent, $reason);
    }
}
