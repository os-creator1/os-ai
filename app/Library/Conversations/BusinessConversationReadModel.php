<?php

namespace App\Library\Conversations;

use App\Models\Business;
use App\Models\ChatBox;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

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
     * The two directions `chat_box_messages.direction` records. The column is
     * nullable and legacy rows predate it; a row with no direction is counted
     * as neither, because it cannot be proven to be either.
     */
    private const DIRECTION_INCOMING = 'incoming';

    private const DIRECTION_OUTGOING = 'outgoing';

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
     * Unified Business Home §2.6 (H-4) — conversations in which the CUSTOMER
     * wrote at least once inside the half-open window [start, end).
     *
     * Conversations, not messages: five incoming messages in one thread are
     * one conversation, exactly as the inbox shows it. Outbound volume,
     * provider acceptance and send attempts are not part of this figure and
     * are not read here at all.
     *
     * One statement, scoped by `chat_boxes.business_id` like every other read
     * in this class, so a NULL-business legacy conversation is never counted
     * and no other tenant's thread can enter the total.
     */
    public function incomingCount(Business $business, CarbonInterface $startUtc, CarbonInterface $endUtc): int
    {
        return $this->periodCounts($business, $startUtc, $endUtc)['incoming'];
    }

    /**
     * §2.6 (H-4) — of the conversations the customer wrote in during the
     * window, those the Business answered inside that same window.
     *
     * THE DEFINITION, and why it is not "count outbound messages". A reply is
     * a property of a CONVERSATION: the Business answered, or it did not.
     * Sending five messages in one thread is one answered conversation, never
     * five replies, and an outbound message that went out BEFORE the customer
     * wrote is not an answer to it. So a conversation counts once, when an
     * outgoing message follows the first incoming message of the window,
     * both inside the window. A reply typed by a person and a reply sent by
     * an automation both count — the model records no difference between
     * them, and the tile says so rather than implying a human answered.
     *
     * Order is `chat_box_messages.id`, the insert order, which is also the
     * order the contract uses for the latest message (§2.6). Timestamps are
     * used for the window, never for ordering: two messages can share a
     * second, and an id cannot tie.
     *
     * One statement: one grouped pass over this Business's messages in the
     * window.
     */
    public function repliedCount(Business $business, CarbonInterface $startUtc, CarbonInterface $endUtc): int
    {
        return $this->periodCounts($business, $startUtc, $endUtc)['replied'];
    }

    /**
     * Both period figures in ONE statement, which is how the Business Home
     * reads them (contract §16 budgets one query for the pair).
     *
     * One grouped pass over this Business's messages inside the window gives,
     * per conversation, the first incoming message and the last outgoing one;
     * the outer aggregate then counts conversations, never messages. The two
     * single-purpose methods above delegate here, so there is exactly one
     * definition of each figure and no second formula to drift.
     *
     * @return array{incoming: int, replied: int}
     */
    public function periodCounts(Business $business, CarbonInterface $startUtc, CarbonInterface $endUtc): array
    {
        $messages = DB::getTablePrefix() . 'chat_box_messages';

        // Driven by this Business's own conversations, each looked up through
        // chat_box_messages(box_id, created_at): the plan EXPLAIN chose over a
        // grouped pass, which scanned every tenant's messages (H-4).
        $perConversation = DB::table('chat_boxes as b')
            ->where('b.business_id', $business->id)
            ->selectRaw(
                "(SELECT MIN(m.id) FROM {$messages} m WHERE m.box_id = b.id AND m.direction = ?"
                . ' AND m.created_at >= ? AND m.created_at < ?) AS first_incoming_id,'
                . " (SELECT MAX(m.id) FROM {$messages} m WHERE m.box_id = b.id AND m.direction = ?"
                . ' AND m.created_at >= ? AND m.created_at < ?) AS last_outgoing_id',
                [self::DIRECTION_INCOMING, $startUtc, $endUtc, self::DIRECTION_OUTGOING, $startUtc, $endUtc],
            );

        $row = DB::query()
            ->fromSub($perConversation, 'c')
            ->selectRaw(
                'SUM(CASE WHEN first_incoming_id IS NOT NULL THEN 1 ELSE 0 END) AS incoming,'
                . ' SUM(CASE WHEN first_incoming_id IS NOT NULL AND last_outgoing_id > first_incoming_id THEN 1 ELSE 0 END) AS replied',
            )
            ->first();

        return [
            'incoming' => (int) ($row->incoming ?? 0),
            'replied' => (int) ($row->replied ?? 0),
        ];
    }

    /**
     * §2.6 (H-4) — how many conversations are waiting for this Business RIGHT
     * NOW. This is current state, never a figure about the selected period:
     * changing the Business performance period must not change it.
     *
     * A conversation is waiting when its LATEST message is the customer's and
     * that message is older than the grace period — five minutes by default,
     * so Home never tells an owner they are late the moment a message lands.
     * The scan covers conversations touched in the last 30 days: a thread
     * abandoned months ago is not something anyone is about to answer, and
     * calling it "waiting" forever would make the number useless.
     *
     * WHAT THIS DATA MODEL CAN AND CANNOT SAY. `chat_box_messages` records a
     * direction and nothing else: there is no flag distinguishing a system,
     * internal or provider-generated message from an ordinary one, so no such
     * distinction is claimed here. Every incoming row is a message that
     * reached this Business, and every outgoing row is a message it sent,
     * whoever or whatever composed it. A row whose direction was never
     * recorded (the column is nullable, and legacy rows predate it) is
     * counted as neither: it cannot be proven to be a customer waiting.
     *
     * One statement: the per-box latest id over the `box_id` index — whose
     * InnoDB leaf already carries the primary key, so MAX(id) per box needs
     * no further index — joined back to that one row.
     */
    public function awaitingReplyCount(Business $business, ?CarbonInterface $now = null): int
    {
        $now = CarbonImmutable::parse($now ?? CarbonImmutable::now());
        $graceCutoff = $now->subMinutes($this->graceMinutes());
        $scanFloor = $now->subDays($this->scanDays());

        $latest = DB::table('chat_box_messages as m')
            ->whereIn('m.box_id', $this->conversationIds($business, $scanFloor))
            ->groupBy('m.box_id')
            ->selectRaw('MAX(m.id) as last_message_id');

        return (int) DB::table('chat_box_messages as last')
            ->joinSub($latest, 'latest', 'latest.last_message_id', '=', 'last.id')
            ->where('last.direction', self::DIRECTION_INCOMING)
            ->where('last.created_at', '<', $graceCutoff)
            ->count();
    }

    /**
     * This Business's conversation ids, as a SUBQUERY rather than a join.
     *
     * Tenancy is identical either way — `chat_boxes.business_id = ?`, so a
     * NULL-business legacy conversation is never included — but the subquery
     * form gives the optimizer the one restriction it needs to read
     * `chat_box_messages` through its `box_id` index instead of scanning
     * every tenant's messages. EXPLAIN, not taste, chose this (H-4).
     */
    private function conversationIds(Business $business, ?CarbonInterface $touchedSince = null): \Closure
    {
        return function ($query) use ($business, $touchedSince): void {
            $query->select('id')
                ->from('chat_boxes')
                ->where('business_id', $business->id);

            if ($touchedSince !== null) {
                $query->where('updated_at', '>=', $touchedSince);
            }
        };
    }

    private function graceMinutes(): int
    {
        return max(0, (int) config('conversations.awaiting_reply_grace_minutes', 5));
    }

    private function scanDays(): int
    {
        return max(1, (int) config('conversations.awaiting_reply_scan_days', 30));
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
