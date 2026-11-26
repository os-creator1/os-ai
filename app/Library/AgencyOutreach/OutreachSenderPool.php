<?php

namespace App\Library\AgencyOutreach;

use App\Jobs\Outreach\OutreachDeferredSendJob;
use App\Models\AgencyProspectCampaign;
use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectingChannel;
use App\Models\AgencyProspectMessage;
use App\Models\Business;
use App\Models\SendingServer;
use Illuminate\Support\Collection;

/**
 * Which of the agency's own Telnyx numbers an Outreach campaign sends from, and which ONE of
 * them each prospect's conversation is pinned to.
 *
 * THE POOL is the agency's own BYO Telnyx channels (AgencyByoSender's ownership and
 * readiness rules, no second integration) the owner put in Outreach (`outreach_enabled`).
 * Any number of them; there is no fixed limit.
 *
 * A CAMPAIGN'S SELECTION is `sender_channel_ids`. NULL means "never configured": the campaign
 * sends from the agency's default sender exactly as it always did (or from the managed number
 * when the agency has no own sender), so existing single-number campaigns keep working.
 *
 * ASSIGNMENT happens under the member's row lock inside the send pipeline's claim, before the
 * ledger row and before any provider call, and is persisted on the member: a retried or
 * concurrent job reads the same assignment back, and a prospect is never moved to another
 * number when the campaign selection changes. New prospects go to the selected, currently
 * usable number with the fewest prospects of this campaign (ties: lowest channel id), so the
 * campaign's opener allowance spreads about evenly across them.
 */
final class OutreachSenderPool
{
    public const REASON_NO_SENDER = 'no_eligible_sender';

    /** Prefix of the ledger failure reason when a pinned sender cannot send right now. */
    public const REASON_SENDER_UNAVAILABLE = 'sender_unavailable';

    /** The agency's own Telnyx numbers that are in Outreach, any status, oldest first. */
    public function poolChannels(int $workspaceId): Collection
    {
        return AgencyProspectingChannel::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', SendingServer::TYPE_TELNYX)
            ->where('outreach_enabled', true)
            ->with('sendingServer')
            ->orderBy('id')
            ->get();
    }

    /** Explicitly selected for this campaign (null = legacy: the default sender). */
    public function selection(AgencyProspectCampaign $campaign): ?array
    {
        $ids = $campaign->sender_channel_ids;

        return is_array($ids) ? array_values(array_unique(array_map('intval', $ids))) : null;
    }

    /**
     * The numbers this campaign may start NEW conversations from (usable or not).
     * Only channels of the campaign's own Workspace pool can ever appear here.
     */
    public function campaignChannels(AgencyProspectCampaign $campaign, ?Business $business): Collection
    {
        $selected = $this->selection($campaign);

        if ($selected === null) {
            $default = AgencyByoSender::selectedChannel($business);

            return $default !== null && (int) $default->workspace_id === (int) $campaign->workspace_id
                ? collect([$default]) : collect();
        }

        return $this->poolChannels((int) $campaign->workspace_id)
            ->filter(fn (AgencyProspectingChannel $channel) => in_array((int) $channel->id, $selected, true))
            ->values();
    }

    /** Whether this campaign sends through the agency's own Telnyx numbers (else: managed, unchanged). */
    public function usesOwnNumbers(AgencyProspectCampaign $campaign, ?Business $business): bool
    {
        return $this->selection($campaign) !== null || AgencyByoSender::selectedChannel($business) !== null;
    }

    /**
     * May this number be given NEW conversations? The same readiness as sending, plus a webhook
     * key (without it replies could not be received). Reason codes are AgencyByoSender's.
     *
     * @return array{ok: bool, code: ?string, message: ?string}
     */
    public function availability(AgencyProspectingChannel $channel): array
    {
        $state = AgencyByoSender::sendReadiness($channel);

        if ($state['ok'] && ! AgencyByoSender::hasWebhookKey($channel)) {
            return ['ok' => false, 'code' => 'missing_webhook_key', 'message' => 'Add this number\'s Telnyx webhook public key so replies can be received.'];
        }

        return $state;
    }

    /** Selected numbers that can take new conversations right now. */
    public function eligibleChannels(AgencyProspectCampaign $campaign, ?Business $business): Collection
    {
        return $this->campaignChannels($campaign, $business)
            ->filter(fn (AgencyProspectingChannel $channel) => $this->availability($channel)['ok'])
            ->values();
    }

    /**
     * The member's sender, assigning one if needed. MUST be called with the member row locked.
     *
     * @return int|null|string the channel id; null = not an own-number send (managed, unchanged);
     *                         a string = why nothing could be assigned
     */
    public function assign(AgencyProspectCampaignMember $member, AgencyProspectCampaign $campaign, Business $business): int|string|null
    {
        if ($member->sender_channel_id !== null) {
            return (int) $member->sender_channel_id;
        }

        if (! $this->usesOwnNumbers($campaign, $business)) {
            return null;
        }

        // A conversation that already started (before this feature) keeps the number it started
        // on: never silently moved. Found from its own Conversation's number.
        if ($member->last_outbound_at !== null || $member->chat_box_id !== null) {
            $existing = $member->chat_box_id !== null
                ? AgencyByoSender::channelForConversation($business, (int) $member->chat_box_id)
                : null;

            if ($existing !== null && (int) $existing->workspace_id === (int) $member->workspace_id) {
                return $this->persist($member, (int) $existing->id);
            }

            if ($member->last_outbound_at !== null && $this->selection($campaign) === null) {
                // Legacy history on a managed number: unchanged behaviour, nothing pinned.
                return null;
            }
        }

        $candidates = $this->campaignChannels($campaign, $business);

        if ($candidates->isEmpty()) {
            return self::REASON_NO_SENDER;
        }

        // Serialise assignment across this Workspace's workers: lock the candidate channel rows
        // (member row first, then channels — nothing locks them in the other order).
        AgencyProspectingChannel::query()
            ->whereIn('id', $candidates->pluck('id')->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $eligible = $candidates->filter(fn (AgencyProspectingChannel $channel) => $this->availability($channel->fresh('sendingServer'))['ok']);

        if ($eligible->isEmpty()) {
            return self::REASON_NO_SENDER;
        }

        $load = AgencyProspectCampaignMember::query()
            ->where('campaign_id', (int) $campaign->id)
            ->whereIn('sender_channel_id', $eligible->pluck('id')->all())
            ->selectRaw('sender_channel_id, COUNT(*) as aggregate')
            ->groupBy('sender_channel_id')
            ->pluck('aggregate', 'sender_channel_id');

        $chosen = $eligible
            ->sortBy(fn (AgencyProspectingChannel $channel) => sprintf('%012d-%012d', (int) ($load[$channel->id] ?? 0), (int) $channel->id))
            ->first();

        return $this->persist($member, (int) $chosen->id);
    }

    /**
     * The estimated share of a campaign-wide opener allowance per number: 50 over 3 = 17, 17, 16.
     *
     * @return list<int>
     */
    public static function distribution(int $cap, int $numbers): array
    {
        if ($numbers <= 0) {
            return [];
        }

        $base = intdiv(max(0, $cap), $numbers);
        $extra = max(0, $cap) % $numbers;

        return array_map(fn (int $i) => $base + ($i < $extra ? 1 : 0), range(0, $numbers - 1));
    }

    /**
     * Sends held because this number could not send are retried once it can again — the normal
     * recovery path (its owner reconnected or re-enabled it). Each is re-checked at the send.
     *
     * @return int how many held sends were queued
     */
    public function resumeHeldFor(AgencyProspectingChannel $channel): int
    {
        if (! AgencyByoSender::sendReadiness($channel->fresh('sendingServer'))['ok']) {
            return 0;
        }

        $ids = AgencyProspectMessage::query()
            ->where('workspace_id', (int) $channel->workspace_id)
            ->where('channel_id', (int) $channel->id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('status', AgencyProspectMessage::STATUS_PAUSED)
            ->where('failure_reason', 'like', self::REASON_SENDER_UNAVAILABLE . ':%')
            ->orderBy('id')
            ->pluck('id');

        foreach ($ids as $id) {
            OutreachDeferredSendJob::dispatch((int) $id);
        }

        return $ids->count();
    }

    public static function isSenderHold(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::REASON_SENDER_UNAVAILABLE . ':');
    }

    private function persist(AgencyProspectCampaignMember $member, int $channelId): int
    {
        // Only ever fills an empty assignment: an existing one is never overwritten.
        AgencyProspectCampaignMember::query()
            ->whereKey($member->id)
            ->whereNull('sender_channel_id')
            ->update(['sender_channel_id' => $channelId, 'sender_assigned_at' => now()]);

        $member->sender_channel_id = (int) AgencyProspectCampaignMember::query()->whereKey($member->id)->value('sender_channel_id');

        return (int) $member->sender_channel_id;
    }
}
