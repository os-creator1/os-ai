<?php

namespace App\Library\AgencyOutreach;

use App\Models\AgencyProspectCampaignMember;
use App\Models\AgencyProspectMessage;
use App\Models\Business;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The shared outbound SMS budget of ONE sending number.
 *
 * Every Outreach send (reply, opener, follow-up, resumed send, across every campaign on that
 * number) reserves here under the number's slot row lock, so the limit is global to the number,
 * atomic across workers, and never a sleep: a refused reservation tells the caller WHEN to try
 * again and the pipeline defers the send with a delayed job.
 *
 *  - Spacing: at most one send per effective interval. The interval is the strictest of the
 *    default, the per-sender override, the provider floor and the campaign's own minimum.
 *  - Manual and every other managed outbound message of the same Business are read from the
 *    messaging operations ledger: they CONSUME the budget (the next Outreach send waits behind
 *    them) but are never themselves blocked by it, so a person always goes first.
 *  - Openers (new conversations) are the lowest priority: they wait while any reply or follow-up
 *    is deferred, and are capped per 24h per number. The cap never touches replies.
 */
final class OutreachSendBudget
{
    public const REASON_RATE = 'rate_limited';
    public const REASON_OPENER_CAP = 'opener_daily_cap';

    public function __construct(private readonly OutreachMessageSender $sender)
    {
    }

    public static function enabled(): bool
    {
        // A deferred send needs a queue that can hold a delayed job; on `sync` it would loop inline.
        return (bool) config('outreach.send_pacing.enabled', true) && OutreachReplyTiming::queueCanDefer();
    }

    /**
     * Reserve the next send of this number, or say when to retry. A grant consumes the slot.
     *
     * @return array{granted: bool, reason: ?string, retryAt: ?Carbon}
     */
    public function reserve(Business $business, AgencyProspectMessage $row): array
    {
        $sender = $this->sender->sendingNumber($business);
        $key = substr(($sender['number'] ?? 'default') . ':' . $sender['transport'], 0, 64);
        $interval = $this->interval($sender, $row);

        return DB::transaction(function () use ($business, $row, $key, $interval): array {
            DB::table('outreach_send_slots')->insertOrIgnore([
                'business_id' => (int) $business->id, 'sender_key' => $key, 'created_at' => now(), 'updated_at' => now(),
            ]);

            $slot = DB::table('outreach_send_slots')
                ->where('business_id', (int) $business->id)->where('sender_key', $key)->lockForUpdate()->first();

            $now = now();
            $opener = $row->purpose === AgencyProspectMessage::PURPOSE_INITIAL;

            // Bind this attempt to the EXACT sender slot while its row is locked.
            // A later sender change can replace the association before a retry.
            DB::table('agency_prospect_messages')->where('id', $row->id)
                ->update(['outreach_send_slot_id' => $slot->id]);

            // The 24h cap is based on ACTUAL successful deliveries, not ledger claim
            // timestamps (which could precede a deferred send by days). Count only
            // genuinely in-flight reservations alongside those deliveries. This is
            // per sending-number slot, rather than a workspace-wide pooled cap.
            if ($opener && ($cap = (int) config('outreach.send_pacing.opener_daily_cap', 50)) > 0) {
                $windowStart = $now->copy()->subDay();
                $scope = AgencyProspectMessage::query()
                    ->where('workspace_id', $row->workspace_id)
                    ->where('outreach_send_slot_id', $slot->id)
                    ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
                    ->where('purpose', AgencyProspectMessage::PURPOSE_INITIAL)
                    ->where('id', '!=', $row->id);

                $sent = (clone $scope)->where('status', AgencyProspectMessage::STATUS_SENT)
                    ->where('sent_at', '>', $windowStart);
                $pendingCount = (clone $scope)->where('status', AgencyProspectMessage::STATUS_PENDING)->count();

                if ($sent->count() + $pendingCount >= $cap) {
                    $oldest = $sent->orderBy('sent_at')->first(['sent_at']);
                    // When only in-flight attempts use the budget, check back
                    // conservatively instead of assuming their claim time is a send.
                    $retry = $oldest?->sent_at?->copy()->addDay()->addSecond()
                        ?? $now->copy()->addMinute();

                    return ['granted' => false, 'reason' => self::REASON_OPENER_CAP, 'retryAt' => $retry];
                }
            }

            $free = $slot->next_free_at === null ? $now->copy()->subSecond() : Carbon::parse($slot->next_free_at);

            // A person (or any other managed send) used the number: Outreach waits behind it.
            $other = DB::table('business_messaging_operations')
                ->where('business_id', (int) $business->id)->where('direction', 'outbound')->max('occurred_at');

            if ($other !== null) {
                $free = $free->max(Carbon::parse($other)->addSeconds($interval));
            }

            // Priority: an opener never jumps ahead of a reply or follow-up that is already waiting.
            if ($opener && $this->higherPriorityWaiting($row)) {
                $free = $free->max($now->copy()->addSeconds($interval));
            }

            if ($now->lt($free)) {
                return ['granted' => false, 'reason' => self::REASON_RATE, 'retryAt' => $free];
            }

            DB::table('outreach_send_slots')->where('id', $slot->id)->update(['next_free_at' => $now->copy()->addSeconds($interval), 'updated_at' => $now]);

            return ['granted' => true, 'reason' => null, 'retryAt' => null];
        });
    }

    /** Spread retries a little so contenders for the same slot do not all return at the same instant. */
    public function jitter(Carbon $at, AgencyProspectMessage $row): Carbon
    {
        return $at->copy()->addSeconds(random_int(0, 3));
    }

    /** @param array{number: ?string, transport: string} $sender */
    private function interval(array $sender, AgencyProspectMessage $row): int
    {
        $interval = max(0, (int) config('outreach.send_pacing.interval_seconds', 10));
        $perSender = config('outreach.send_pacing.sender_interval_seconds', [])[$sender['number'] ?? ''] ?? null;
        $provider = config('outreach.send_pacing.provider_min_interval_seconds', [])[$sender['transport']] ?? null;
        $campaign = AgencyProspectCampaignMember::query()->whereKey($row->campaign_member_id)->first()?->campaign?->min_send_interval_seconds;

        return max($interval, (int) $perSender, (int) $provider, (int) $campaign);
    }

    private function higherPriorityWaiting(AgencyProspectMessage $row): bool
    {
        return AgencyProspectMessage::query()
            ->where('workspace_id', $row->workspace_id)
            ->where('direction', AgencyProspectMessage::DIRECTION_OUTBOUND)
            ->where('status', AgencyProspectMessage::STATUS_PAUSED)
            ->where('failure_reason', self::REASON_RATE)
            ->where('purpose', '!=', AgencyProspectMessage::PURPOSE_INITIAL)
            ->exists();
    }
}
