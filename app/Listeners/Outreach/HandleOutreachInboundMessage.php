<?php

namespace App\Listeners\Outreach;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStage;
use App\Events\Conversation\InboundMessageReceived;
use App\Jobs\Outreach\OutreachRespondJob;
use App\Library\AgencyOutreach\AgencyOutreachBusinessResolver;
use App\Library\AgencyOutreach\OutreachClassification;
use App\Library\AgencyOutreach\OutreachConversationLinker;
use App\Library\AgencyOutreach\OutreachInboundLedger;
use App\Library\AgencyOutreach\OutreachReplyTiming;
use App\Library\AgencyOutreach\OutreachSequence;
use App\Library\AgencyOutreach\OutreachStageMachine;
use App\Library\AgencyOutreach\OutreachStopClassifier;
use App\Library\AgencyOutreach\OutreachStopService;
use App\Library\AgencyProspecting\AgencyProspectPhoneNormalizer;
use App\Models\AgencyProspect;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Business;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Agency Outreach V1 (contract §9) — carries an attributed inbound message to the
 * Outreach engine, off the webhook request.
 *
 * It does nothing unless ALL of these hold: the message arrived through canonical
 * (managed) messaging; the Business it was attributed to is the Agency Workspace's OWN
 * resolvable Business; the sender's number is a prospect of that same Workspace; and that
 * prospect has a live membership in a `managed` campaign. A conversation of any other
 * Business, a number that is not a prospect, or a prospect of another Agency is ignored
 * without a trace — so Outreach can never touch a client's or another Agency's data.
 *
 * What it does, once per message (the ledger key `outreach:in:{occurrenceKey}` is unique,
 * so a redelivered event writes nothing new): links the member to its Conversation, writes
 * the inbound ledger row from the latest incoming message of that Conversation, stamps
 * `last_inbound_at`, cancels a pending follow-up, applies a hard opt-out immediately (before
 * any reply work is queued), and otherwise queues OutreachRespondJob. A redelivery whose
 * inbound is still unhandled re-queues the job, which is itself idempotent — so an event
 * lost between "row written" and "job queued" is recovered, never doubled.
 *
 * `$tries = 1`, like every automation listener: a redelivery is already harmless.
 */
class HandleOutreachInboundMessage implements ShouldQueue
{
    public int $tries = 1;

    public function __construct(
        private readonly OutreachConversationLinker $linker,
        private readonly OutreachInboundLedger $ledger,
        private readonly OutreachStopService $stops,
        ?OutreachReplyTiming $timing = null,
    ) {
        $this->timing = $timing ?? new OutreachReplyTiming();
    }

    private readonly OutreachReplyTiming $timing;

    public function handle(InboundMessageReceived $event): void
    {
        // Only canonical messaging carries Outreach conversations (contract §9). The legacy
        // inbound path ("report:{id}") belongs to BYO channels, which have their own runtime.
        if (! str_starts_with($event->occurrenceKey, 'operation:')) {
            return;
        }

        $business = Business::query()->find($event->businessId);

        if ($business === null || $business->workspace === null) {
            return;
        }

        $agencyBusiness = AgencyOutreachBusinessResolver::forWorkspace($business->workspace);

        if ($agencyBusiness === null || (int) $agencyBusiness->id !== (int) $business->id) {
            return;
        }

        $phone = AgencyProspectPhoneNormalizer::normalize($event->senderPhone);

        if ($phone === null) {
            return;
        }

        $prospect = AgencyProspect::query()
            ->where('workspace_id', (int) $business->workspace_id)
            ->where('phone', $phone)
            ->first();

        if ($prospect === null) {
            return;
        }

        $member = $this->liveManagedMember($prospect);

        if ($member === null) {
            // Ended campaigns no longer claim normal replies, but an inbound
            // withdrawal of consent still applies to that same prospect.
            // The central carrier STOP filter handles only whole-message
            // keywords; Outreach also recognizes clear phrases such as
            // "please stop texting me" and "remove my number".
            $last = $this->linker->latestInbound($agencyBusiness, $prospect);
            if ($last !== null && OutreachStopClassifier::classify((string) $last->message) === OutreachStopClassifier::OPT_OUT) {
                $endedMember = AgencyProspectCampaignMember::query()
                    ->where('prospect_id', $prospect->id)
                    ->where('workspace_id', $prospect->workspace_id)
                    ->whereHas('campaign', fn ($query) => $query
                        ->where('sending_mode', AgencyProspectCampaign::MODE_MANAGED)
                        ->where('status', AgencyProspectCampaignStatus::Ended->value))
                    ->orderByDesc('id')
                    ->first();

                if ($endedMember !== null) {
                    $this->stops->apply($endedMember, true);
                }
            }

            return;
        }

        // The conversation this inbound was filed under (the receiving number's), else the one
        // pinned to the member's assigned number — never another number's older thread.
        $senderNumber = $this->linker->senderNumberFor($member);
        $incoming = $this->linker->latestInbound($agencyBusiness, $prospect, $senderNumber, $event->conversationBoxId);

        if ($incoming === null) {
            return;
        }

        [$inbound, $created] = $this->ledger->record($member, $event->occurrenceKey, (string) $incoming->message);

        if ($created) {
            $this->noteInbound($member);
        }

        $this->linker->link($member, $agencyBusiness, $prospect, $senderNumber);

        if ($inbound->status === OutreachInboundLedger::STATUS_HANDLED) {
            return;
        }

        $member->refresh();

        // A hard opt-out / rejection is applied NOW, not left to a queue worker.
        // The same decision the responder makes, so a stop is never held back by reply pacing.
        $classification = OutreachClassification::of((string) $inbound->body);
        $sequence = OutreachSequence::forWorkspace($business->workspace);
        $decision = OutreachStageMachine::decide($member->stage, $classification, $sequence, $sequence->sentIdsFor($member));

        if ($decision->stops()) {
            $this->stops->apply($member, $decision->action === 'opt_out', $inbound->id);

            return;
        }

        // Pace the answer: the due moment is chosen once and stored, so a redelivery re-queues the
        // SAME moment. A delayed queue job carries the wait; no worker or request ever sleeps.
        $due = $this->timing->dueAt($inbound, $business->workspace, $classification);
        $pending = OutreachRespondJob::dispatch($member->id, $inbound->id);

        if ($due !== null && $due->isFuture()) {
            $pending->delay($due);
        }
    }

    /** The prospect's one non-terminal membership of a `managed` campaign (the one-open-conversation invariant). */
    private function liveManagedMember(AgencyProspect $prospect): ?AgencyProspectCampaignMember
    {
        return AgencyProspectCampaignMember::query()
            ->where('prospect_id', $prospect->id)
            ->where('workspace_id', $prospect->workspace_id)
            ->whereNotIn('stage', [AgencyProspectStage::Booked->value, AgencyProspectStage::StoppedOptOut->value])
            ->whereIn('campaign_id', AgencyProspectCampaign::query()
                ->where('workspace_id', $prospect->workspace_id)
                ->where('sending_mode', AgencyProspectCampaign::MODE_MANAGED)
                // An ended campaign retains history but must never capture
                // future inbound replies for a newly started campaign.
                ->whereIn('status', [AgencyProspectCampaignStatus::Active->value, AgencyProspectCampaignStatus::Paused->value])
                ->select('id'))
            ->orderByDesc('id')
            ->first();
    }

    private function noteInbound(AgencyProspectCampaignMember $member): void
    {
        AgencyProspectCampaignMember::query()->where('id', $member->id)->update(['last_inbound_at' => now()]);

        AgencyProspectCampaignMember::query()
            ->where('id', $member->id)
            ->whereNull('followup_sent_at')
            ->whereNull('followup_cancelled_at')
            ->whereNotNull('followup_at')
            ->update(['followup_cancelled_at' => now()]);
    }
}
