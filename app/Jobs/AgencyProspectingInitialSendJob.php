<?php

namespace App\Jobs;

use App\Enums\AgencyProspecting\AgencyProspectCampaignStatus;
use App\Enums\AgencyProspecting\AgencyProspectStatus;
use App\Enums\Entitlement\PlatformFeature;
use App\Library\AgencyProspecting\AgencyProspectMessageTemplate;
use App\Library\AgencyProspecting\Contracts\AgencyProspectingMessageSender;
use App\Library\Entitlement\EntitlementManager;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingSetting;
use App\Models\AgencyProspectMessage;
use Illuminate\Support\Facades\DB;

/**
 * Runtime pass — the explicit-Start-only initial outbound send for one
 * campaign member. Re-checks every eligibility condition against
 * freshly-locked, current state (never trusts anything computed at
 * dispatch time) — Workspace active, entitled, campaign active, channel
 * active, underlying SendingServer active, prospect Active, member not
 * terminal — so a retry (or a member whose state changed between Start
 * and this job actually running) can never send twice or send when it
 * shouldn't. Idempotency key: an existing outbound
 * agency_prospect_messages row for this member.
 */
class AgencyProspectingInitialSendJob extends Base
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

            // Idempotency: a retry must never send the opening message a
            // second time once it has genuinely succeeded — but a prior
            // provider FAILURE must remain retryable, so only a row that
            // actually reached "sent" blocks this job.
            if (AgencyProspectMessage::where('campaign_member_id', $member->id)
                ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
                ->where('status', AgencyProspectMessage::STATUS_SENT)
                ->exists()
            ) {
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

            if (empty($campaign->opening_message)) {
                return;
            }

            $settings = AgencyProspectingSetting::where('workspace_id', $workspace->id)->first();
            $body = AgencyProspectMessageTemplate::render($campaign->opening_message, $prospect, $settings);

            $message = AgencyProspectMessage::create([
                'workspace_id' => $workspace->id,
                'campaign_member_id' => $member->id,
                'channel_id' => $channel->id,
                'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
                'body' => $body,
                'status' => AgencyProspectMessage::STATUS_PENDING,
            ]);

            $result = $sender->send($channel, $channel->sender_number, $prospect->phone, $body);

            if ($result->success) {
                $message->update([
                    'status' => AgencyProspectMessage::STATUS_SENT,
                    'provider_message_id' => $result->providerMessageId,
                    'sent_at' => now(),
                ]);

                $member->update([
                    'last_outbound_at' => now(),
                    'last_provider_message_id' => $result->providerMessageId,
                ]);

                return;
            }

            // Provider failure — record it, never fake stage progression,
            // never mark the prospect stopped. The member remains
            // retryable: the idempotency check above only blocks on a
            // "sent" row, so a later re-dispatch of this same job will
            // try again.
            $message->update(['status' => AgencyProspectMessage::STATUS_FAILED]);
        });
    }
}
