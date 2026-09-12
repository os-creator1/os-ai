<?php

namespace App\Library\Conversations;

use App\Models\Business;
use App\Models\ChatBox;
use Carbon\CarbonImmutable;

/**
 * Customer Experience Redesign — Slice 2B §15: the whole of 2B's contribution
 * to Dashboard Slice 4. Two Business-isolated counts, nothing else.
 *
 * Every query is `business_id = ?` and nothing wider: no cross-Business
 * aggregate, no delivery metric, no join to Reports (chat_box_messages has no
 * FK path to delivery state), and no Dashboard code. A NULL-business legacy
 * conversation is never counted, for the same reason it is never shown.
 *
 * Naming note, recorded so it is not mistaken for an oversight: Slice 4's
 * contract anticipates this method as `conversationsStarted()`. 2B ships it as
 * `startedCount()` per its own §15, and Slice 4's contract already states it
 * consumes whatever 2B actually shipped rather than building its own.
 */
final class BusinessConversationReadModel
{
    /**
     * Conversations started in the half-open range [start, end), so adjacent
     * windows can never double-count a boundary.
     *
     * This seam does no timezone arithmetic of its own (§15): both boundaries
     * are bound exactly as given, and Laravel formats a date binding WITHOUT
     * converting its timezone. `created_at` is written in the application
     * timezone (`config('app.timezone')`), so the caller owns the conversion —
     * the contract's `$startUtc`/`$endUtc` names hold only where that
     * timezone is UTC. Recorded for Dashboard Slice 4.
     */
    public function startedCount(Business $business, CarbonImmutable $startUtc, CarbonImmutable $endUtc): int
    {
        return ChatBox::query()
            ->where('business_id', $business->id)
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<', $endUtc)
            ->count();
    }

    /**
     * Unified Home §3.1 (A-1) — startedCount() for SEVERAL Businesses, in
     * ONE grouped statement, so the Agency Account Home's cross-client band
     * never asks this seam once per client.
     *
     * Same table, same half-open bounds and the same rule that a
     * NULL-business legacy conversation is never counted; the ids come from
     * the caller already authorized, and each keeps its own figure. This
     * stays the only door to chat_boxes: Dashboard and B5 still never query
     * that table themselves.
     *
     * @param  array<int, int>  $businessIds  already authorized Business ids
     * @return array<int, int>  business id => conversations started in [start, end)
     */
    public function startedCountsForBusinesses(array $businessIds, CarbonImmutable $startUtc, CarbonImmutable $endUtc): array
    {
        $ids = array_values(array_unique(array_map('intval', $businessIds)));

        if ($ids === []) {
            return [];
        }

        $counts = [];

        $rows = ChatBox::query()
            ->selectRaw('business_id, COUNT(*) AS started')
            ->whereIn('business_id', $ids)
            ->where('created_at', '>=', $startUtc)
            ->where('created_at', '<', $endUtc)
            ->groupBy('business_id')
            ->get();

        foreach ($rows as $row) {
            $counts[(int) $row->business_id] = (int) $row->started;
        }

        return $counts;
    }

    /**
     * Conversations with at least one unread inbound message.
     */
    public function unreadCount(Business $business): int
    {
        return ChatBox::query()
            ->where('business_id', $business->id)
            ->where('notification', '!=', 0)
            ->count();
    }
}
