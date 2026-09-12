<?php

namespace App\Jobs;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\AgencyProspecting\AgencyProspectAiDecision;
use App\Library\AgencyProspecting\AgencyProspectStageTransitionGuard;
use App\Library\AgencyProspecting\AgencyProspectStopAction;
use App\Library\AgencyProspecting\AgencyProspectUrlPolicy;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingAiClient;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingMessageSender;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\AgencyProspectMessage;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runtime pass — the AI-driven reply for one EXACT inbound message.
 *
 * Correction 2 — four hardening changes over Correction 1:
 *
 * 1. True at-most-once: once ANY AgencyProspectMessage row exists for
 *    "ai_reply:{inboundMessageId}" — pending, failed, or sent — an
 *    automatic execution never calls the provider again.
 * 2. The claim transaction immediately preceding the provider call
 *    re-fetches and re-checks every eligibility condition (Workspace,
 *    entitlement, campaign, channel, prospect, member) against CURRENT
 *    state, never the earlier snapshot.
 * 3. The stage-transition guard is re-evaluated inside that SAME claim
 *    transaction against the member's freshly LOCKED, current stage —
 *    not the stage read when the AI call was made — since another
 *    inbound/AI job may have advanced the member in between. The exact
 *    stage validated at claim time is carried into the post-send
 *    bookkeeping transaction, which refuses to apply the decision's
 *    stage/booking-link/follow-up effects if the member's stage no
 *    longer matches (something else already moved it) — the send itself
 *    is still recorded as having genuinely happened.
 * 4. The exact inbound message is verified to belong to the SAME
 *    Workspace as the target member (not merely the same member id) —
 *    a malformed/forged cross-Workspace ledger row fails closed.
 *
 * Booking-link safety (Correction 1, tightened here): AgencyProspectAiDecision
 * strips every URL-like token from the AI's own reply text
 * (AgencyProspectUrlPolicy::sanitizeAiText — not just bare http(s) links),
 * and a configured booking_url is only ever honored when it independently
 * passes AgencyProspectUrlPolicy::isValidHttpUrl() (http/https only).
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
        $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->first();

        if ($member === null) {
            return;
        }

        $inbound = AgencyProspectMessage::where('id', $this->inboundMessageId)
            ->where('campaign_member_id', $this->campaignMemberId)
            ->where('direction', AgencyProspectMessage::DIRECTION_INBOUND)
            ->first();

        if ($inbound === null || (int) $inbound->workspace_id !== (int) $member->workspace_id) {
            // The two ids do not bind to a real, matching, same-Workspace
            // inbound message — never guess/reply to a different
            // conversation, and never trust a malformed/forged
            // cross-Workspace ledger row.
            return;
        }

        $operationKey = 'ai_reply:' . $this->inboundMessageId;

        if ($this->operationClaimed($operationKey)) {
            return;
        }

        $snapshot = $this->readEligibleSnapshot($member->id, $entitlementManager);

        if ($snapshot === null) {
            return;
        }

        $settings = AgencyProspectingSetting::where('workspace_id', $snapshot['workspace_id'])->first();
        $raw = $aiClient->complete(
            $this->buildPromptMessages($settings, $snapshot['campaign'], $snapshot['prospect'], $snapshot['member']),
            $member->workspace,
        );
        $decision = AgencyProspectAiDecision::fromRawJson($raw);

        if ($decision === null) {
            return;
        }

        // Unified Home contract A-2 — the durable classification of this
        // EXACT inbound message, independent of whatever the decision goes
        // on to do (reply, stop, nothing). Reuses AgencyProspectAiDecision's
        // own validated ::INTENTS vocabulary verbatim; never a second
        // classifier, never a guess. The `WHERE intent IS NULL` guard makes
        // the write idempotent: once any value lands, a retried job's
        // decision (the AI is not deterministic, so a retry may classify
        // differently) can never overwrite it — first classification wins,
        // so this inbound message can never acquire contradictory durable
        // intent from a retry.
        $this->persistIntent($inbound, $decision->intent);

        if ($decision->isHardNegative() || $decision->nextStage === 99) {
            $stopAction->apply($snapshot['prospect']);

            return;
        }

        $claim = DB::transaction(function () use ($operationKey, $decision, $settings, $entitlementManager) {
            if ($this->operationClaimed($operationKey)) {
                return null;
            }

            $eligible = $this->lockAndCheckEligibility($this->campaignMemberId, $entitlementManager);

            if ($eligible === null) {
                return null;
            }

            $currentStage = $eligible['member']->stage;

            // Correction 2, Section 5 — the decisive transition check
            // against the freshly LOCKED, CURRENT stage, never the stage
            // read when the AI call was made.
            if ($decision->nextStage !== null && ! AgencyProspectStageTransitionGuard::isAllowed($currentStage, $decision->nextStage)) {
                return null;
            }

            // Re-resolve settings fresh under the lock — booking_url may
            // have changed since the earlier snapshot.
            $currentSettings = AgencyProspectingSetting::where('workspace_id', $eligible['workspace_id'])->first();

            if ($decision->sendBookingLink && ! AgencyProspectUrlPolicy::isValidHttpUrl($currentSettings?->booking_url)) {
                return null;
            }

            $body = $decision->reply;

            if ($decision->sendBookingLink) {
                $body = rtrim($body) . ' ' . $currentSettings->booking_url;
            }

            try {
                return [
                    'message' => AgencyProspectMessage::create([
                        'workspace_id' => $eligible['workspace_id'],
                        'campaign_member_id' => $this->campaignMemberId,
                        'channel_id' => $eligible['channel']->id,
                        'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
                        'purpose' => AgencyProspectMessage::PURPOSE_AI_REPLY,
                        'operation_key' => $operationKey,
                        'body' => $body,
                        'status' => AgencyProspectMessage::STATUS_PENDING,
                    ]),
                    'channel' => $eligible['channel'],
                    'prospect' => $eligible['prospect'],
                    'body' => $body,
                    'expected_stage' => $currentStage,
                ];
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        });

        if ($claim === null) {
            return;
        }

        $result = $sender->send($claim['channel'], $claim['channel']->sender_number, $claim['prospect']->phone, $claim['body']);

        if (! $result->success) {
            $claim['message']->update(['status' => AgencyProspectMessage::STATUS_FAILED]);

            return;
        }

        DB::transaction(function () use ($claim, $result, $decision): void {
            $claim['message']->update([
                'status' => AgencyProspectMessage::STATUS_SENT,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
            ]);

            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null || $member->isTerminal()) {
                // The send genuinely happened (recorded above); never
                // overwrite a conversation that became terminal in the
                // meantime with stale bookkeeping.
                return;
            }

            $updates = [
                'last_outbound_at' => now(),
                'last_provider_message_id' => $result->providerMessageId,
            ];

            // Correction 2, Section 5 — only apply the decision's
            // stage/booking-link/follow-up effects if the member's
            // CURRENT stage still matches the exact stage the transition
            // was validated against at claim time. If something else
            // already moved the member since then, the send still
            // genuinely happened (recorded above), but its stage effects
            // are discarded rather than risk moving state backwards or
            // clobbering a newer state.
            $bookingLinkJustSent = false;

            if ($member->stage === $claim['expected_stage']) {
                if ($decision->proposedSlot !== null) {
                    $updates['proposed_slot'] = $decision->proposedSlot;
                }

                if ($decision->nextStage !== null) {
                    $updates['stage'] = $decision->nextStage;
                }

                $bookingLinkJustSent = $decision->sendBookingLink && $decision->nextStage === 4 && $member->booking_link_sent_at === null;

                if ($bookingLinkJustSent) {
                    $settings = AgencyProspectingSetting::where('workspace_id', $member->workspace_id)->first();
                    $updates['booking_link_sent_at'] = now();
                    $updates['followup_at'] = now()->addHours($settings?->follow_up_delay_hours ?? 24);
                }
            }

            $member->update($updates);

            if ($bookingLinkJustSent) {
                AgencyProspectingFollowUpJob::dispatch($member->id)->delay($updates['followup_at']);
            }
        });
    }

    /**
     * @return array{workspace_id: int, member: AgencyProspectCampaignMember, campaign: \App\Models\AgencyProspectCampaign, prospect: \App\Models\AgencyProspect, channel: \App\Models\AgencyProspectingChannel}|null
     */
    private function readEligibleSnapshot(int $memberId, EntitlementManager $entitlementManager): ?array
    {
        $member = AgencyProspectCampaignMember::where('id', $memberId)->first();

        if ($member === null || $member->isTerminal()) {
            return null;
        }

        $eligible = $this->checkCommonEligibility($member, $entitlementManager);

        if ($eligible === null) {
            return null;
        }

        return array_merge($eligible, ['member' => $member]);
    }

    /**
     * @return array{workspace_id: int, member: AgencyProspectCampaignMember, campaign: \App\Models\AgencyProspectCampaign, prospect: \App\Models\AgencyProspect, channel: \App\Models\AgencyProspectingChannel}|null
     */
    private function lockAndCheckEligibility(int $memberId, EntitlementManager $entitlementManager): ?array
    {
        $member = AgencyProspectCampaignMember::where('id', $memberId)->lockForUpdate()->first();

        if ($member === null || $member->isTerminal()) {
            return null;
        }

        $eligible = $this->checkCommonEligibility($member, $entitlementManager);

        if ($eligible === null) {
            return null;
        }

        return array_merge($eligible, ['member' => $member]);
    }

    /**
     * @return array{workspace_id: int, campaign: \App\Models\AgencyProspectCampaign, prospect: \App\Models\AgencyProspect, channel: \App\Models\AgencyProspectingChannel}|null
     */
    private function checkCommonEligibility(AgencyProspectCampaignMember $member, EntitlementManager $entitlementManager): ?array
    {
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
            'campaign' => $campaign,
            'prospect' => $prospect,
            'channel' => $channel,
        ];
    }

    private function operationClaimed(string $operationKey): bool
    {
        return AgencyProspectMessage::where('operation_key', $operationKey)->exists();
    }

    private function persistIntent(AgencyProspectMessage $inbound, string $intent): void
    {
        AgencyProspectMessage::where('id', $inbound->id)->whereNull('intent')->update(['intent' => $intent]);
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
