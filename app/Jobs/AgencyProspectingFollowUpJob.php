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
 *
 * Correction 2 — the same conservative, true at-most-once policy as the
 * other two jobs: once ANY AgencyProspectMessage row exists for
 * "followup:{campaignMemberId}", an automatic execution never calls the
 * provider again, regardless of that row's status. The claim transaction
 * immediately preceding the provider call re-fetches and re-checks EVERY
 * eligibility condition against current state — including re-evaluating
 * "a later inbound reply since the booking link was sent" at claim time,
 * not just at the earlier snapshot — so a reply that arrives between the
 * snapshot and the claim still correctly cancels the nudge instead of
 * sending it. `followup_sent_at` means only "a provider send actually
 * succeeded"; a cancellation (at either the snapshot or claim stage) sets
 * `followup_cancelled_at` instead, never a false `followup_sent_at`.
 */
class AgencyProspectingFollowUpJob extends Base
{
    public function __construct(private readonly int $campaignMemberId)
    {
    }

    public function handle(AgencyProspectingMessageSender $sender, EntitlementManager $entitlementManager): void
    {
        $operationKey = 'followup:' . $this->campaignMemberId;

        if ($this->operationClaimed($operationKey)) {
            return;
        }

        $snapshot = $this->readEligibleSnapshot($entitlementManager);

        if ($snapshot === null) {
            return;
        }

        $settings = AgencyProspectingSetting::where('workspace_id', $snapshot['workspace_id'])->first();
        $bookingUrl = $settings?->booking_url;
        $body = $bookingUrl
            ? "Just following up — here's the booking link again: {$bookingUrl}"
            : 'Just following up on my last message — let me know if you have any questions!';

        $claim = DB::transaction(function () use ($operationKey, $entitlementManager, $body) {
            if ($this->operationClaimed($operationKey)) {
                return null;
            }

            $eligible = $this->lockAndCheckEligibility($entitlementManager);

            if ($eligible === null) {
                return null;
            }

            try {
                return [
                    'message' => AgencyProspectMessage::create([
                        'workspace_id' => $eligible['workspace_id'],
                        'campaign_member_id' => $this->campaignMemberId,
                        'channel_id' => $eligible['channel']->id,
                        'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
                        'purpose' => AgencyProspectMessage::PURPOSE_FOLLOWUP,
                        'operation_key' => $operationKey,
                        'body' => $body,
                        'status' => AgencyProspectMessage::STATUS_PENDING,
                    ]),
                    'channel' => $eligible['channel'],
                    'prospect' => $eligible['prospect'],
                ];
            } catch (UniqueConstraintViolationException) {
                return null;
            }
        });

        if ($claim === null) {
            return;
        }

        $result = $sender->send($claim['channel'], $claim['channel']->sender_number, $claim['prospect']->phone, $body);

        if (! $result->success) {
            $claim['message']->update(['status' => AgencyProspectMessage::STATUS_FAILED]);

            return;
        }

        DB::transaction(function () use ($claim, $result): void {
            $claim['message']->update([
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

    /**
     * @return array{workspace_id: int, channel: \App\Models\AgencyProspectingChannel, prospect: \App\Models\AgencyProspect}|null
     */
    private function readEligibleSnapshot(EntitlementManager $entitlementManager): ?array
    {
        $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->first();

        if ($member === null || $member->isTerminal()) {
            return null;
        }

        if ($member->followup_sent_at !== null || $member->followup_cancelled_at !== null || $member->followup_at === null) {
            return null;
        }

        if ($this->laterInboundCancelsFollowup($member)) {
            // Persist the cancellation here too (not only at claim time)
            // so the common, non-racing case is recorded immediately
            // rather than left to depend on ever reaching the claim
            // transaction at all — this snapshot layer would otherwise
            // short-circuit handle() before the claim's own re-check
            // (Correction 2, Section 10) ever runs.
            DB::transaction(function (): void {
                $locked = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

                if ($locked !== null && $locked->followup_cancelled_at === null && $locked->followup_sent_at === null) {
                    $locked->update(['followup_cancelled_at' => now()]);
                }
            });

            return null;
        }

        return $this->checkCommonEligibility($member, $entitlementManager);
    }

    /**
     * The authoritative re-check, performed only inside the claim
     * transaction against a freshly locked member row.
     *
     * @return array{workspace_id: int, channel: \App\Models\AgencyProspectingChannel, prospect: \App\Models\AgencyProspect}|null
     */
    private function lockAndCheckEligibility(EntitlementManager $entitlementManager): ?array
    {
        $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->lockForUpdate()->first();

        if ($member === null || $member->isTerminal()) {
            return null;
        }

        if ($member->followup_sent_at !== null || $member->followup_cancelled_at !== null || $member->followup_at === null) {
            return null;
        }

        // Re-evaluate "a later inbound reply" against CURRENT state, not
        // the earlier snapshot — a reply that arrived between the
        // snapshot and this claim must still cancel the nudge here.
        if ($this->laterInboundCancelsFollowup($member)) {
            $member->update(['followup_cancelled_at' => now()]);

            return null;
        }

        return $this->checkCommonEligibility($member, $entitlementManager);
    }

    private function laterInboundCancelsFollowup(AgencyProspectCampaignMember $member): bool
    {
        return $member->last_inbound_at !== null && $member->booking_link_sent_at !== null
            && $member->last_inbound_at->greaterThan($member->booking_link_sent_at);
    }

    /**
     * @return array{workspace_id: int, channel: \App\Models\AgencyProspectingChannel, prospect: \App\Models\AgencyProspect}|null
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
            'channel' => $channel,
            'prospect' => $prospect,
        ];
    }

    private function operationClaimed(string $operationKey): bool
    {
        return AgencyProspectMessage::where('operation_key', $operationKey)->exists();
    }
}
