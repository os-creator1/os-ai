<?php

namespace App\Jobs;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\AgencyProspecting\AgencyProspectAiDecision;
use App\Library\AgencyProspecting\AgencyProspectStageTransitionGuard;
use App\Library\AgencyProspecting\AgencyProspectStopAction;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingMessageSender;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\AgencyProspectMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runtime pass — the AI-driven reply for one EXACT inbound message,
 * dispatched only after the webhook has already committed that inbound
 * message and run deterministic STOP detection (this job is never reached
 * for a deterministic hard-stop, nor for a Paused campaign — the webhook
 * applies AgencyProspectStopAction directly, or simply never enqueues this
 * job, in those cases). Every eligibility condition is re-checked here
 * against freshly-locked, current state — never trusts anything true at
 * webhook time, since this may run well after the webhook responded.
 *
 * Correction 1 — this job is bound to the exact inbound
 * AgencyProspectMessage that caused it (never just "the conversation"),
 * so a forged/stale/mismatched pair of ids can never bind to the wrong
 * message. That same inbound-message identity anchors a durable
 * `operation_key` ("ai_reply:{inboundMessageId}") on the AgencyProspectMessage
 * table's own unique constraint — the actual race-proof idempotency
 * guarantee against duplicate automatic sends, not a transaction-timing
 * assumption. The external AI/provider calls are made OUTSIDE any open DB
 * transaction; only the short claim-and-record steps around them are
 * transactional.
 *
 * Free-form AI output never mutates state directly: AgencyProspectAiDecision
 * validates the shape (including the send_booking_link <=> stage-4
 * biconditional and unconditional URL-stripping of the AI's own reply
 * text), AgencyProspectStageTransitionGuard validates the transition, and
 * this job additionally refuses to honor send_booking_link unless a real
 * booking_url is actually configured. Any invalid/unparseable/out-of-bounds
 * result sends nothing and advances nothing. A validated decision
 * requesting the terminal stage (or an explicit hard-negative intent) stops
 * the conversation immediately — no outbound sales copy is ever sent after
 * a stop decision.
 */
class AgencyProspectingRespondJob extends Base
{
    public function __construct(
        private readonly int $campaignMemberId,
        private readonly int $inboundMessageId,
    ) {
    }

    public function handle(
        AgencyProspectingAiClient $aiClient,
        AgencyProspectingMessageSender $sender,
        EntitlementManager $entitlementManager,
        AgencyProspectStopAction $stopAction,
    ): void {
        $inbound = AgencyProspectMessage::where('id', $this->inboundMessageId)
            ->where('campaign_member_id', $this->campaignMemberId)
            ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->first();

        if ($inbound === null) {
            // The two ids do not bind to a real, matching inbound
            // message — never guess/reply to a different conversation.
            return;
        }

        $operationKey = 'ai_reply:' . $this->inboundMessageId;

        if ($this->alreadySent($operationKey)) {
            return;
        }

        $snapshot = DB::transaction(function () use ($entitlementManager) {
            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null || $member->isTerminal()) {
                return null;
            }

            $workspace = $member->workspace;

            if ($workspace === null || ! $workspace->is_active) {
                return null;
            }

            if (! $entitlementManager->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value)->allowed) {
                return null;
            }

            // A paused campaign must never send an AI reply.
            $campaign = $member->campaign;

            if ($campaign === null || $campaign->status !== AgencyProspectCampaignStatus::Active) {
                return null;
            }

            $channel = $campaign->channel;

            if ($channel === null || ! $channel->isActive() || $channel->sendingServer === null || ! $channel->sendingServer->status) {
                return null;
            }

            $prospect = $member->prospect;

            if ($prospect === null || $prospect->status !== AgencyProspectStatus::Active) {
                return null;
            }

            return [
                'workspace_id' => $workspace->id,
                'member' => $member,
                'campaign' => $campaign,
                'prospect' => $prospect,
                'channel' => $channel,
            ];
        });

        if ($snapshot === null) {
            return;
        }

        $settings = AgencyProspectingSetting::where('workspace_id', $snapshot['workspace_id'])->first();
        $raw = $aiClient->complete($this->buildPromptMessages($settings, $snapshot['campaign'], $snapshot['prospect'], $snapshot['member']));
        $decision = AgencyProspectAiDecision::fromRawJson($raw);

        if ($decision === null) {
            return;
        }

        if ($decision->isHardNegative() || $decision->nextStage === 99) {
            $stopAction->apply($snapshot['prospect']);

            return;
        }

        if ($decision->nextStage !== null && ! AgencyProspectStageTransitionGuard::isAllowed($snapshot['member']->stage, $decision->nextStage)) {
            return;
        }

        // The one cross-field check raw-JSON validation alone cannot make:
        // whether a real booking_url is actually configured. Any other
        // outcome sends nothing and advances nothing — never a partial
        // stage-4 with no link.
        if ($decision->sendBookingLink && empty($settings?->booking_url)) {
            return;
        }

        $body = $decision->reply;

        if ($decision->sendBookingLink) {
            $body = rtrim($body) . ' ' . $settings->booking_url;
        }

        $claim = DB::transaction(function () use ($operationKey, $snapshot, $body) {
            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null || $member->isTerminal()) {
                return null;
            }

            if ($this->alreadySent($operationKey)) {
                return null;
            }

            $existing = AgencyProspectMessage::where('operation_key', $operationKey)->first();

            if ($existing !== null) {
                // A prior attempt for this exact inbound message failed —
                // reuse the same durable operation row for the retry
                // rather than claiming a second one.
                $existing->update(['status' => AgencyProspectMessage::STATUS_PENDING, 'body' => $body]);

                return $existing;
            }

            try {
                return AgencyProspectMessage::create([
                    'workspace_id' => $snapshot['workspace_id'],
                    'campaign_member_id' => $this->campaignMemberId,
                    'channel_id' => $snapshot['channel']->id,
                    'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
                    'purpose' => AgencyProspectMessage::PURPOSE_AI_REPLY,
                    'operation_key' => $operationKey,
                    'body' => $body,
                    'status' => AgencyProspectMessage::STATUS_PENDING,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost the claim race to a concurrent attempt for this
                // exact inbound message.
                return null;
            }
        });

        if ($claim === null) {
            return;
        }

        $result = $sender->send($snapshot['channel'], $snapshot['channel']->sender_number, $snapshot['prospect']->phone, $body);

        if (! $result->success) {
            $claim->update(['status' => AgencyProspectMessage::STATUS_FAILED]);

            return;
        }

        DB::transaction(function () use ($claim, $result, $decision, $settings): void {
            $claim->update([
                'status' => AgencyProspectMessage::STATUS_SENT,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
            ]);

            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null || $member->isTerminal()) {
                // The send genuinely happened (recorded above); the
                // conversation became terminal in the meantime (e.g. a
                // concurrent STOP) — never overwrite that with stale
                // stage bookkeeping.
                return;
            }

            $updates = [
                'last_outbound_at' => now(),
                'last_provider_message_id' => $result->providerMessageId,
            ];

            if ($decision->proposedSlot !== null) {
                $updates['proposed_slot'] = $decision->proposedSlot;
            }

            if ($decision->nextStage !== null) {
                $updates['stage'] = $decision->nextStage;
            }

            $bookingLinkJustSent = $decision->sendBookingLink && $decision->nextStage === 4 && $member->booking_link_sent_at === null;

            if ($bookingLinkJustSent) {
                $updates['booking_link_sent_at'] = now();
                $updates['followup_at'] = now()->addHours($settings?->follow_up_delay_hours ?? 24);
            }

            $member->update($updates);

            if ($bookingLinkJustSent) {
                AgencyProspectingFollowUpJob::dispatch($member->id)->delay($updates['followup_at']);
            }
        });
    }

    private function alreadySent(string $operationKey): bool
    {
        return AgencyProspectMessage::where('operation_key', $operationKey)
            ->where('status', AgencyProspectMessage::STATUS_SENT)
            ->exists();
    }

    /**
     * @return array<int, array{role: string, content: string}>
     */
    private function buildPromptMessages(
        ?AgencyProspectingSetting $settings,
        \App\Models\AgencyProspectCampaign $campaign,
        \App\Models\AgencyProspect $prospect,
        AgencyProspectCampaignMember $member,
    ): array {
        $context = [
            'Agency name: ' . ($settings?->agency_name ?? 'Not configured'),
            'Offer: ' . ($settings?->offer ?? 'Not configured'),
            'Niche: ' . ($settings?->niche ?? 'Not configured'),
            'Value proposition: ' . ($settings?->value_proposition ?? 'Not configured'),
            'Pricing context: ' . ($settings?->pricing_context ?? 'Not configured'),
            'Qualification context: ' . ($settings?->qualification_context ?? 'Not configured'),
            'Geography context: ' . ($settings?->geography_context ?? 'Not configured'),
            'Tone: ' . ($settings?->tone ?? 'Not configured'),
            'FAQs/objections: ' . ($settings?->faqs_objections ?? 'Not configured'),
            'Booking context: ' . ($settings?->booking_context ?? 'Not configured'),
            'Follow-up policy: ' . ($settings?->follow_up_policy ?? 'Not configured'),
            'Campaign context: ' . ($campaign->context ?? 'Not configured'),
            'Prospect company: ' . $prospect->company_name,
            'Prospect contact: ' . ($prospect->contact_name ?? 'Unknown'),
            'Current stage: ' . $member->stage->value,
        ];

        $systemPrompt = "You are an SMS sales development assistant for an agency prospecting external businesses. "
            . "Use only the context below — never invent an agency name, niche, offer, or booking URL not given here. "
            . "Keep replies concise and SMS-appropriate (under 320 characters). "
            . "Respond with STRICT JSON ONLY, no prose, matching exactly this shape: "
            . '{"intent":"positive|question|qualification|booking|scheduling|soft_negative|hard_negative|other",'
            . '"reply":"...","next_stage":null_or_one_of_2_3_4_5_99,"send_booking_link":true_or_false,"proposed_slot":null_or_string}. '
            . "You may never set next_stage to 6 — booking is confirmed manually, never by you. "
            . "You may never move a conversation backwards to a lower stage. "
            . "send_booking_link and next_stage=4 must always be set together, never one without the other. "
            . "If the prospect is clearly not interested or hostile, set intent to hard_negative."
            . "\n\nContext:\n" . implode("\n", $context);

        $history = $member->messages()
            ->orderByDesc('id')
            ->limit(12)
            ->get()
            ->reverse()
            ->map(fn (AgencyProspectMessage $message) => [
                'role' => $message->direction === AgencyProspectMessage::DIRECTION_INBOUND ? 'user' : 'assistant',
                'content' => $message->body,
            ])
            ->values()
            ->all();

        return array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $history,
        );
    }
}
