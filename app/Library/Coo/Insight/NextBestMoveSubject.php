<?php

namespace App\Library\Coo\Insight;

use App\Enums\Dashboard\AttentionType;
use App\Library\Coo\NextBestMove;
use App\Library\Coo\NextBestMoveSelector;
use App\Models\CooInsight;
use App\Models\Opportunity;

/**
 * Contract 19 §5.1, §12 19.C — the one place a MoveExplanation row's
 * (subject_type, subject_id) and the fact_ref it explains are derived from a
 * pool key, so the generation side (which only knows the pool key and,
 * for an Opportunity, the Opportunity itself) and the display side (which has
 * a fully-rendered NextBestMove) always agree on the same identity for the
 * same move.
 *
 * An Opportunity-based move reuses CooInsight::SUBJECT_OPPORTUNITY with the
 * Opportunity's own id — already the exact shape
 * CooInsightInvalidator::invalidateForOpportunityWork() expects, so a
 * MoveExplanation about an Opportunity is retired the moment that
 * Opportunity completes, is dismissed, or its execution succeeds or fails,
 * with no new invalidation code. An Attention-based move has no row of its
 * own, so it uses SUBJECT_NEXT_BEST_MOVE with NextBestMoveSelector's fixed,
 * small subject id for that Attention type.
 */
final class NextBestMoveSubject
{
    /**
     * @return array{type: string, id: int, explains: string}|null null only
     *   when the pool key names an Attention type this method does not
     *   recognise, which never happens for a value selectPoolKey() itself
     *   returned.
     */
    public static function forPoolKey(string $poolKey, ?Opportunity $opportunity): ?array
    {
        if ($poolKey === NextBestMove::KIND_OPPORTUNITY) {
            if ($opportunity === null) {
                return null;
            }

            return [
                'type' => CooInsight::SUBJECT_OPPORTUNITY,
                'id' => (int) $opportunity->id,
                'explains' => 'opportunity.' . $opportunity->type,
            ];
        }

        $type = AttentionType::tryFrom($poolKey);
        $id = $type === null ? null : NextBestMoveSelector::attentionSubjectId($type);

        if ($id === null) {
            return null;
        }

        return [
            'type' => CooInsight::SUBJECT_NEXT_BEST_MOVE,
            'id' => $id,
            'explains' => 'attention.' . $poolKey,
        ];
    }

    /** The same identity, computed from an already-rendered NextBestMove (the display side). */
    public static function forMove(NextBestMove $move): ?array
    {
        if ($move->isOpportunity()) {
            return self::forPoolKey(NextBestMove::KIND_OPPORTUNITY, $move->opportunity);
        }

        return self::forPoolKey($move->attention->type->value, null);
    }
}
