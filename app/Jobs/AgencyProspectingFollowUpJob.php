<?php

namespace App\Jobs;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingMessageSender;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\AgencyProspectMessage;
use Illuminate\Support\Facades\DB;

/**
 * Runtime pass — the one-time delayed follow-up after a booking link is
 * sent (task-preserved "old useful behavior": exactly one nudge, default
 * 24 hours later, bounded by AgencyProspectingSetting::follow_up_delay_hours).
 * Dispatched with ->delay() from AgencyProspectingRespondJob, following
 * this repository's own established delayed-job pattern
 * (ScheduleCampaign/AutomationJob). Re-checks every eligibility condition
 * against freshly-locked, current state — a member that reached stage 6/99,
 * whose campaign paused, or whose channel was disabled since the link was
 * sent, receives no follow-up. Sent at most once: followup_sent_at is the
 * sole idempotency guard, set only after a successful provider send.
 */
class AgencyProspectingFollowUpJob extends Base
{
    public function __construct(private readonly int $campaignMemberId)
    {
    }

    public function handle(AgencyProspectingMessageSender $sender, EntitlementManager $entitlementManager): void
    {
        DB::transaction(function () use ($sender, $entitlementManager): void {
            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null || $member->isTerminal()) {
                return;
            }

            if ($member->followup_sent_at !== null || $member->followup_at === null) {
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

            // A later inbound reply since the booking link was sent means
            // the prospect already engaged — the nudge is no longer
            // appropriate. Recorded as sent (never retried) rather than
            // silently rescheduled.
            if ($member->last_inbound_at !== null && $member->booking_link_sent_at !== null
                && $member->last_inbound_at->greaterThan($member->booking_link_sent_at)
            ) {
                $member->update(['followup_sent_at' => now()]);

                return;
            }

            $settings = AgencyProspectingSetting::where('workspace_id', $workspace->id)->first();
            $bookingUrl = $settings?->booking_url;
            $body = $bookingUrl
                ? "Just following up — here's the booking link again: {$bookingUrl}"
                : 'Just following up on my last message — let me know if you have any questions!';

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

            $member->update([
                'followup_sent_at' => now(),
                'last_outbound_at' => now(),
                'last_provider_message_id' => $result->providerMessageId,
            ]);
        });
    }
}
