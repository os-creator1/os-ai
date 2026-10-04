<?php

declare(strict_types=1);

namespace App\Library\Growth\Readers;

use App\Enums\Entitlement\PlatformFeature;
use App\Library\Growth\GrowthFactReader;
use App\Library\Growth\GrowthFactSet;
use App\Library\Growth\GrowthThresholds;
use App\Library\Growth\Readers\Concerns\BucketsByLocation;
use App\Models\Business;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Conversation facts: which inbound conversations are still waiting for a
 * Business reply. ONE query regardless of conversation count.
 *
 * What the canonical data can PROVE (Growth Center §7):
 *   - an inbound customer message and its time   chat_box_messages.direction = 'incoming'
 *   - a Business reply and its time              direction = 'outgoing' whose send_status is
 *                                                NULL (legacy/automation/quick send) or
 *                                                sending / sent / delivered. A reply that
 *                                                FAILED to send (failed, delivery_failed)
 *                                                is not a reply the customer received.
 *   - ownership                                  chat_boxes.business_id and .location_id
 * A conversation is "awaiting reply" when its newest inbound message is older
 * than conversation_awaiting_hours and no qualifying reply came after it.
 * Messages with a NULL direction (legacy rows nothing could classify) are
 * ignored entirely — reply state is never inferred from vague activity.
 *
 * Only messages inside conversation_lookback_days are read; an awaiting
 * conversation's inbound message and any later reply both fall inside that
 * window, so older history cannot change the answer and is never scanned.
 *
 * Known limit, documented rather than guessed at: an inbound message that is
 * itself a courtesy ("thanks!") or an opt-out keyword still counts as
 * awaiting — the product has no canonical "needs a reply" flag to read.
 *
 * Fact shape (domain `conversations`):
 *   active_count  int  conversations with any inbound message in the window
 *   truncated     bool more than ROW_CAP active conversations
 *   by_location   array<int, bucket> keyed by Location id (0 = none);
 *                 bucket {count, value_minor, currency, mixed_currency, uids, oldest_hours}
 */
final class GrowthConversationFactReader implements GrowthFactReader
{
    use BucketsByLocation;

    private const ROW_CAP = 5000;

    public function domain(): string
    {
        return 'conversations';
    }

    public function feature(): ?PlatformFeature
    {
        return PlatformFeature::Conversations;
    }

    public function read(Business $business, CarbonImmutable $now, GrowthThresholds $thresholds): GrowthFactSet
    {
        $windowStart = $now->subDays($thresholds->get('conversation_lookback_days'));
        $awaitingBefore = $now->subHours($thresholds->get('conversation_awaiting_hours'));

        $rows = DB::table('chat_boxes as c')
            ->join('chat_box_messages as m', 'm.box_id', '=', 'c.id')
            ->where('c.business_id', $business->id)
            ->where('m.created_at', '>=', $windowStart)
            ->groupBy('c.id', 'c.uid', 'c.location_id')
            ->havingRaw("MAX(CASE WHEN m.direction = 'incoming' THEN m.created_at END) IS NOT NULL")
            ->select(['c.uid', 'c.location_id'])
            ->selectRaw("MAX(CASE WHEN m.direction = 'incoming' THEN m.created_at END) AS last_in")
            ->selectRaw("MAX(CASE WHEN m.direction = 'outgoing' AND (m.send_status IS NULL OR m.send_status IN ('sending','sent','delivered')) THEN m.created_at END) AS last_out")
            ->orderBy('c.id')
            ->limit(self::ROW_CAP + 1)
            ->get();

        $truncated = $rows->count() > self::ROW_CAP;

        if ($truncated) {
            $rows = $rows->take(self::ROW_CAP);
        }

        $byLocation = [];

        foreach ($rows as $row) {
            $lastIn = CarbonImmutable::parse($row->last_in);
            $replied = $row->last_out !== null && CarbonImmutable::parse($row->last_out)->gte($lastIn);

            if ($replied || $lastIn->gt($awaitingBefore)) {
                continue;
            }

            $key = $this->locationKey($row->location_id);
            $previousOldest = $byLocation[$key]['oldest_hours'] ?? 0;
            $bucket = $this->addToBucket($byLocation[$key] ?? $this->emptyBucket(), $row->uid, null, null);
            $bucket['oldest_hours'] = max($previousOldest, (int) floor($lastIn->diffInHours($now)));
            $byLocation[$key] = $bucket;
        }

        return GrowthFactSet::available($this->domain(), [
            'active_count' => $rows->count(),
            'truncated' => $truncated,
            'by_location' => $byLocation,
        ]);
    }
}
