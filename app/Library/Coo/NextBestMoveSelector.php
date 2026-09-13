<?php

namespace App\Library\Coo;

use App\Enums\Dashboard\AttentionType;
use App\Library\Dashboard\AttentionItem;
use App\Library\Dashboard\BusinessHomePresenter;
use App\Models\Opportunity;

/**
 * Unified Business Home §6.4 (C-2) — which ONE thing the Home recommends.
 *
 * A pure function: no I/O, no persistence, no queue, no score of its own and
 * no AI. It stops at the first match in a FIXED order:
 *
 *   1. A customer is waiting for a reply   (the most time-sensitive fact)
 *   2. The Google connection was lost
 *   3. Automations are failing
 *   4. A Google listing needs attention
 *   5. The head of the Opportunity work queue (RFC-002 ordering, unchanged)
 *   6. The website is not published        (setup, not an emergency)
 *   7. Nothing — the caller says "You're all caught up."
 *
 * The order is code, not configuration; changing it needs a contract
 * amendment. Billing is never a candidate: it has its own strip (§5.2), and a
 * payment problem is not something a "next move" should compete with.
 *
 * Opportunity stays the authoritative recommendation engine. The selector
 * receives its queue head already ordered and never re-scores it.
 */
final class NextBestMoveSelector
{
    /** Rules 1–4: status exceptions ahead of the Opportunity queue. */
    public const BEFORE_OPPORTUNITIES = [
        AttentionType::ConversationsAwaitingReply,
        AttentionType::GoogleConnectionLost,
        AttentionType::AutomationFailing,
        AttentionType::GoogleLocationUnhealthy,
    ];

    /** Rule 6: setup that only matters once nothing more pressing exists. */
    public const AFTER_OPPORTUNITIES = [
        AttentionType::WebsiteUnpublished,
    ];

    /**
     * @param  array<int, AttentionItem>  $attention  items whose fix this actor can reach
     */
    public function select(array $attention, ?Opportunity $queueHead): ?NextBestMove
    {
        $candidates = array_values(array_filter(
            $attention,
            fn (mixed $item) => $item instanceof AttentionItem && ! BusinessHomePresenter::isBilling($item->type),
        ));

        foreach (self::BEFORE_OPPORTUNITIES as $type) {
            if (($item = $this->first($candidates, $type)) !== null) {
                return NextBestMove::fromAttention($item);
            }
        }

        if ($queueHead !== null) {
            return NextBestMove::fromOpportunity($queueHead);
        }

        foreach (self::AFTER_OPPORTUNITIES as $type) {
            if (($item = $this->first($candidates, $type)) !== null) {
                return NextBestMove::fromAttention($item);
            }
        }

        return null;
    }

    /**
     * @param  array<int, AttentionItem>  $candidates
     */
    private function first(array $candidates, AttentionType $type): ?AttentionItem
    {
        foreach ($candidates as $item) {
            if ($item->type === $type) {
                return $item;
            }
        }

        return null;
    }
}
