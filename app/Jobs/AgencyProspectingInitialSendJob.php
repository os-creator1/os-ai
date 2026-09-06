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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Runtime pass — the explicit-Start-only initial outbound send for one
 * campaign member.
 *
 * Correction 2 — a conservative, true at-most-once automatic-delivery
 * policy: once ANY AgencyProspectMessage row exists for this operation's
 * durable key ("initial:{campaignMemberId}") — pending, failed, or sent —
 * an automatic execution of this job NEVER calls the provider again. A
 * crashed-mid-send or provider-failed attempt is never silently retried
 * by this job; a missed message is preferred over a duplicated one. (A
 * future explicit, human-initiated "retry failed send" action could use
 * different, deliberate semantics — not implemented here.) The claim
 * transaction immediately preceding the provider call re-fetches and
 * re-checks EVERY authoritative eligibility condition against current
 * state (never the earlier snapshot read, which exists only to build the
 * outbound body) — Workspace active, entitled, campaign Active, channel
 * active, SendingServer active, prospect Active, member non-terminal, and
 * that no operation row has appeared in the meantime — immediately before
 * atomically creating the operation row. The provider network call itself
 * always happens strictly outside any open DB transaction.
 */
class AgencyProspectingInitialSendJob extends Base
{
    public function __construct(private readonly int $campaignMemberId)
    {
    }

    public function handle(AgencyProspectingMessageSender $sender, EntitlementManager $entitlementManager): void
    {
        $operationKey = 'initial:' . $this->campaignMemberId;

        if ($this->operationClaimed($operationKey)) {
            return;
        }

        $snapshot = $this->readEligibleSnapshot($entitlementManager);

        if ($snapshot === null) {
            return;
        }

        $settings = AgencyProspectingSetting::where('workspace_id', $snapshot['workspace_id'])->first();
        $body = AgencyProspectMessageTemplate::render($snapshot['campaign']->opening_message, $snapshot['prospect'], $settings);

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
                        'purpose' => AgencyProspectMessage::PURPOSE_INITIAL,
                        'operation_key' => $operationKey,
                        'body' => $body,
                        'status' => AgencyProspectMessage::STATUS_PENDING,
                    ]),
                    'channel' => $eligible['channel'],
                    'prospect' => $eligible['prospect'],
                ];
            } catch (UniqueConstraintViolationException) {
                // Lost the claim race to a concurrent attempt for this
                // exact member.
                return null;
            }
        });

        if ($claim === null) {
            return;
        }

        $result = $sender->send($claim['channel'], $claim['channel']->sender_number, $claim['prospect']->phone, $body);

        if (! $result->success) {
            // Provider failure — record it for visibility; the operation
            // row's mere existence now permanently forecloses any further
            // automatic attempt for this member (Correction 2's
            // conservative at-most-once policy).
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

            if ($member === null || $member->isTerminal()) {
                // The send genuinely happened (recorded above); never
                // overwrite a conversation that became terminal in the
                // meantime with stale bookkeeping.
                return;
            }

            $member->update([
                'last_outbound_at' => now(),
                'last_provider_message_id' => $result->providerMessageId,
            ]);
        });
    }

    /**
     * A read-only pass used only to decide whether it is even worth
     * building the outbound body, and to build it. Never the authority
     * for whether the send may actually proceed — the claim transaction
     * re-checks everything fresh immediately before claiming.
     *
     * @return array{workspace_id: int, campaign: \App\Models\AgencyProspectCampaign, prospect: \App\Models\AgencyProspect, channel: \App\Models\AgencyProspectingChannel}|null
     */
    private function readEligibleSnapshot(EntitlementManager $entitlementManager): ?array
    {
        $member = AgencyProspectCampaignMember::where('id', $this->campaignMemberId)->first();

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

        if (empty($campaign->opening_message)) {
            return null;
        }

        return [
            'workspace_id' => $workspace->id,
            'campaign' => $campaign,
            'prospect' => $prospect,
            'channel' => $channel,
        ];
    }

    /**
     * The authoritative eligibility re-check, performed only inside the
     * claim transaction against a freshly locked member row. Identical
     * conditions to readEligibleSnapshot() by design (Correction 2,
     * Section 4) — this is the one that actually gates the provider call.
     *
     * @return array{workspace_id: int, channel: \App\Models\AgencyProspectingChannel, prospect: \App\Models\AgencyProspect}|null
     */
    private function lockAndCheckEligibility(EntitlementManager $entitlementManager): ?array
    {
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

        if (empty($campaign->opening_message)) {
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
