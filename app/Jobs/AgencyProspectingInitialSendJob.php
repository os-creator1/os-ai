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
 * campaign member. Re-checks every eligibility condition against
 * freshly-locked, current state (never trusts anything computed at
 * dispatch time) — Workspace active, entitled, campaign active, channel
 * active, underlying SendingServer active, prospect Active, member not
 * terminal — so a retry (or a member whose state changed between Start
 * and this job actually running) can never send twice or send when it
 * shouldn't.
 *
 * Correction 1 — idempotency is a durable `operation_key`
 * ("initial:{campaignMemberId}") enforced by AgencyProspectMessage's own
 * unique DB constraint, the actual race-proof guarantee — not a
 * transaction-timing assumption. The provider network call happens
 * OUTSIDE any open DB transaction: a short transaction claims the
 * operation and records a pending row, the provider is called, then a
 * second short transaction records the result and mutates timestamps
 * only if the member is still current.
 */
class AgencyProspectingInitialSendJob extends Base
{
    public function __construct(private readonly int $campaignMemberId)
    {
    }

    public function handle(AgencyProspectingMessageSender $sender, EntitlementManager $entitlementManager): void
    {
        $operationKey = 'initial:' . $this->campaignMemberId;

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
        });

        if ($snapshot === null) {
            return;
        }

        $settings = AgencyProspectingSetting::where('workspace_id', $snapshot['workspace_id'])->first();
        $body = AgencyProspectMessageTemplate::render($snapshot['campaign']->opening_message, $snapshot['prospect'], $settings);

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
                // A prior provider failure remains retryable — reuse the
                // same durable operation row rather than claiming a
                // second one.
                $existing->update(['status' => AgencyProspectMessage::STATUS_PENDING, 'body' => $body]);

                return $existing;
            }

            try {
                return AgencyProspectMessage::create([
                    'workspace_id' => $snapshot['workspace_id'],
                    'campaign_member_id' => $this->campaignMemberId,
                    'channel_id' => $snapshot['channel']->id,
                    'direction' => AgencyProspectMessage::DIRECTION_OUTBOUND,
                    'purpose' => AgencyProspectMessage::PURPOSE_INITIAL,
                    'operation_key' => $operationKey,
                    'body' => $body,
                    'status' => AgencyProspectMessage::STATUS_PENDING,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Lost the claim race to a concurrent attempt for this
                // exact member.
                return null;
            }
        });

        if ($claim === null) {
            return;
        }

        $result = $sender->send($snapshot['channel'], $snapshot['channel']->sender_number, $snapshot['prospect']->phone, $body);

        if (! $result->success) {
            // Provider failure — record it, never fake stage progression,
            // never mark the prospect stopped. The member remains
            // retryable via the same operation_key.
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

    private function alreadySent(string $operationKey): bool
    {
        return AgencyProspectMessage::where('operation_key', $operationKey)
            ->where('status', AgencyProspectMessage::STATUS_SENT)
            ->exists();
    }
}
