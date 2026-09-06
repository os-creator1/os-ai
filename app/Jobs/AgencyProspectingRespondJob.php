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
use Illuminate\Support\Facades\DB;

/**
 * Runtime pass — the AI-driven reply for one inbound message, dispatched
 * only after the webhook has already committed the inbound message and
 * run deterministic STOP detection (this job is never reached for a
 * deterministic hard-stop — the webhook applies AgencyProspectStopAction
 * directly and never enqueues this job in that case). Every eligibility
 * condition is re-checked here against freshly-locked, current state —
 * never trusts anything true at webhook time, since this may run well
 * after the webhook responded.
 *
 * Free-form AI output never mutates state directly: AgencyProspectAiDecision
 * validates the shape, AgencyProspectStageTransitionGuard validates the
 * transition. Any invalid/unparseable/out-of-bounds result sends nothing
 * and advances nothing.
 */
class AgencyProspectingRespondJob extends Base
{
    public function __construct(private readonly int $campaignMemberId)
    {
    }

    public function handle(
        AgencyProspectingAiClient $aiClient,
        AgencyProspectingMessageSender $sender,
        EntitlementManager $entitlementManager,
        AgencyProspectStopAction $stopAction,
    ): void {
        DB::transaction(function () use ($aiClient, $sender, $entitlementManager, $stopAction): void {
            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null || $member->isTerminal()) {
                return;
            }

            $workspace = $member->workspace;
            $campaign = $member->campaign;
            $prospect = $member->prospect;

            if ($workspace === null || ! $workspace->is_active) {
                return;
            }

            if (! $entitlementManager->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value)->allowed) {
                return;
            }

            // A paused campaign must never send an AI reply.
            if ($campaign === null || $campaign->status !== AgencyProspectCampaignStatus::Active) {
                return;
            }

            $channel = $campaign->channel;

            if ($channel === null || ! $channel->isActive() || $channel->sendingServer === null || ! $channel->sendingServer->status) {
                return;
            }

            if ($prospect === null || $prospect->status !== AgencyProspectStatus::Active) {
                return;
            }

            $settings = AgencyProspectingSetting::where('workspace_id', $workspace->id)->first();
            $raw = $aiClient->complete($this->buildPromptMessages($settings, $campaign, $prospect, $member));
            $decision = AgencyProspectAiDecision::fromRawJson($raw);

            if ($decision === null) {
                return;
            }

            if ($decision->isHardNegative()) {
                $stopAction->apply($prospect);

                return;
            }

            $nextStage = $decision->nextStage;

            if ($nextStage !== null && ! AgencyProspectStageTransitionGuard::isAllowed($member->stage, $nextStage)) {
                return;
            }

            $body = $decision->reply;

            if ($decision->sendBookingLink && ! empty($settings?->booking_url)) {
                $body = rtrim($body) . ' ' . $settings->booking_url;
            }

            $message = AgencyProspectMessage::create([
                'workspace_id' => $workspace->id,
                'campaign_member_id' => $member->id,
                'channel_id' => $channel->id,
                'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
                'body' => $body,
                'status' => AgencyProspectMessage::STATUS_PENDING,
            ]);

            $result = $sender->send($channel, $channel->sender_number, $prospect->phone, $body);

            if (! $result->success) {
                $message->update(['status' => AgencyProspectMessage::STATUS_FAILED]);

                return;
            }

            $message->update([
                'status' => AgencyProspectMessage::STATUS_SENT,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
            ]);

            if ($nextStage === 99) {
                $member->update([
                    'last_outbound_at' => now(),
                    'last_provider_message_id' => $result->providerMessageId,
                ]);
                $stopAction->apply($prospect);

                return;
            }

            $updates = [
                'last_outbound_at' => now(),
                'last_provider_message_id' => $result->providerMessageId,
            ];

            if ($decision->proposedSlot !== null) {
                $updates['proposed_slot'] = $decision->proposedSlot;
            }

            if ($nextStage !== null) {
                $updates['stage'] = $nextStage;
            }

            $bookingLinkJustSent = $decision->sendBookingLink && $nextStage === 4 && $member->booking_link_sent_at === null;

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
