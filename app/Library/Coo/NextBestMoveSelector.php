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
     * Contract 19 §12 19.C — a fixed, stable, non-colliding identifier for
     * each Attention slot in the pool, keyed by AttentionType value. Used
     * only as `coo_insights.subject_id` for a MoveExplanation row about an
     * Attention-based move (an Opportunity-based move uses the Opportunity's
     * own id instead — see NextBestMoveSubject). Small and fixed because the
     * pool itself is "code, not configuration" (class docblock); adding a
     * pool slot is the same contract amendment that adding one to
     * BEFORE_OPPORTUNITIES/AFTER_OPPORTUNITIES already requires.
     */
    private const ATTENTION_SUBJECT_IDS = [
        AttentionType::ConversationsAwaitingReply->value => 1,
        AttentionType::GoogleConnectionLost->value => 2,
        AttentionType::AutomationFailing->value => 3,
        AttentionType::GoogleLocationUnhealthy->value => 4,
        AttentionType::WebsiteUnpublished->value => 5,
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

        $poolKey = $this->selectPoolKey(
            array_map(static fn (AttentionItem $item): AttentionType => $item->type, $candidates),
            $queueHead !== null,
        );

        if ($poolKey === null) {
            return null;
        }

        if ($poolKey === NextBestMove::KIND_OPPORTUNITY) {
            /** @var Opportunity $queueHead */
            return NextBestMove::fromOpportunity($queueHead);
        }

        /** @var AttentionItem $item */
        $item = $this->first($candidates, AttentionType::from($poolKey));

        return NextBestMove::fromAttention($item);
    }

    /**
     * The same fixed order as select() (rules 1–7), decoupled from the
     * AttentionItem/Opportunity objects select() renders from. Contract 19
     * §12 19.C's background insight generation knows only which Attention
     * types are currently raised and whether an Opportunity queue head
     * exists — never their rendered text, severity or URL — so it determines
     * the identical selected slot through this method instead of
     * reconstructing rendering data it has no authorization-safe way to
     * produce (§8.3 — no contact/message data reaches the AI pipeline).
     *
     * @param  array<int, AttentionType>  $raisedTypes
     * @return string|null  an AttentionType value, NextBestMove::KIND_OPPORTUNITY, or null for "nothing"
     */
    public function selectPoolKey(array $raisedTypes, bool $hasQueueHead): ?string
    {
        foreach (self::BEFORE_OPPORTUNITIES as $type) {
            if (in_array($type, $raisedTypes, true)) {
                return $type->value;
            }
        }

        if ($hasQueueHead) {
            return NextBestMove::KIND_OPPORTUNITY;
        }

        foreach (self::AFTER_OPPORTUNITIES as $type) {
            if (in_array($type, $raisedTypes, true)) {
                return $type->value;
            }
        }

        return null;
    }

    /** @see ATTENTION_SUBJECT_IDS */
    public static function attentionSubjectId(AttentionType $type): ?int
    {
        return self::ATTENTION_SUBJECT_IDS[$type->value] ?? null;
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
