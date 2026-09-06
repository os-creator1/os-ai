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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runtime pass — the one-time delayed follow-up after a booking link is
 * sent (task-preserved "old useful behavior": exactly one nudge, default
 * 24 hours later, bounded by AgencyProspectingSetting::follow_up_delay_hours).
 * Dispatched with ->delay() from AgencyProspectingRespondJob (and,
 * on resume, from AgencyProspectingController::updateCampaignStatus()),
 * following this repository's own established delayed-job pattern.
 * Re-checks every eligibility condition against freshly-locked, current
 * state — a member that reached stage 6/99, whose campaign paused, or
 * whose channel was disabled since the link was sent, receives no
 * follow-up.
 *
 * Correction 1 — `followup_sent_at` means ONLY "a provider send actually
 * succeeded"; a later inbound reply that makes the nudge inappropriate
 * sets `followup_cancelled_at` instead — never a false `followup_sent_at`.
 * Both fields gate re-dispatch (a cancelled follow-up is never resumed).
 * Idempotency is a durable `operation_key` ("followup:{campaignMemberId}")
 * enforced by AgencyProspectMessage's own unique DB constraint, so a
 * pause/resume cycle (or any other duplicate dispatch) can never send the
 * nudge twice. The provider network call happens OUTSIDE any open DB
 * transaction.
 */
class AgencyProspectingFollowUpJob extends Base
{
    public function __construct(private readonly int $campaignMemberId)
    {
    }

    public function handle(AgencyProspectingMessageSender $sender, EntitlementManager $entitlementManager): void
    {
        $operationKey = 'followup:' . $this->campaignMemberId;

        if ($this->alreadySent($operationKey)) {
            return;
        }

        $snapshot = DB::transaction(function () use ($entitlementManager) {
            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null || $member->isTerminal()) {
                return null;
            }

            if ($member->followup_sent_at !== null || $member->followup_cancelled_at !== null || $member->followup_at === null) {
                return null;
            }

            $workspace = $member->workspace;

            if ($workspace === null || ! $workspace->is_active) {
                return null;
            }

            if (! $entitlementManager->decideForWorkspace($workspace, PlatformFeature::ProspectOutreach->value)->allowed) {
                return null;
            }

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

            // A later inbound reply since the booking link was sent means
            // the prospect already engaged — the nudge is no longer
            // appropriate. Cancelled, never falsely recorded as sent.
            if ($member->last_inbound_at !== null && $member->booking_link_sent_at !== null
                && $member->last_inbound_at->greaterThan($member->booking_link_sent_at)
            ) {
                $member->update(['followup_cancelled_at' => now()]);

                return null;
            }

            return [
                'workspace_id' => $workspace->id,
                'prospect' => $prospect,
                'channel' => $channel,
            ];
        });

        if ($snapshot === null) {
            return;
        }

        $settings = AgencyProspectingSetting::where('workspace_id', $snapshot['workspace_id'])->first();
        $bookingUrl = $settings?->booking_url;
        $body = $bookingUrl
            ? "Just following up — here's the booking link again: {$bookingUrl}"
            : 'Just following up on my last message — let me know if you have any questions!';

        $claim = DB::transaction(function () use ($operationKey, $snapshot, $body) {
            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null || $member->isTerminal()
                || $member->followup_sent_at !== null || $member->followup_cancelled_at !== null
            ) {
                return null;
            }

            if ($this->alreadySent($operationKey)) {
                return null;
            }

            $existing = AgencyProspectMessage::where('operation_key', $operationKey)->first();

            if ($existing !== null) {
                $existing->update(['status' => AgencyProspectMessage::STATUS_PENDING, 'body' => $body]);

                return $existing;
            }

            try {
                return AgencyProspectMessage::create([
                    'workspace_id' => $snapshot['workspace_id'],
                    'campaign_member_id' => $this->campaignMemberId,
                    'channel_id' => $snapshot['channel']->id,
                    'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
                    'purpose' => AgencyProspectMessage::PURPOSE_FOLLOWUP,
                    'operation_key' => $operationKey,
                    'body' => $body,
                    'status' => AgencyProspectMessage::STATUS_PENDING,
                ]);
            } catch (UniqueConstraintViolationException) {
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

        DB::transaction(function () use ($claim, $result): void {
            $claim->update([
                'status' => AgencyProspectMessage::STATUS_SENT,
                'provider_message_id' => $result->providerMessageId,
                'sent_at' => now(),
            ]);

            $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

            if ($member === null) {
                return;
            }

            $member->update([
                'followup_sent_at' => now(),
                'last_outbound_at' => now(),
                'last_provider_message_id' => $result->providerMessageId,
            ]);
        });
    }

    private function alreadySent(string $operationKey): bool
    {
        return AgencyProspectMessage::where('operation_key', $operationKey)
            ->where('status', AgencyProspectMessage::STATUS_SENT)
            ->exists();
    }
}
