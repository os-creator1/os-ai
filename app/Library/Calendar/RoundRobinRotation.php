<?php

namespace App\Library\Calendar;

/**
 * Implementation Contract 15 §7.3 step 2 — the candidate order, as a pure
 * function of persisted state.
 *
 * Purity is the correctness argument, not a style preference: two workers
 * computing this under the same tier-1 lock must compute the same answer, so
 * it depends on nothing but the eligible set and the stored cursor — never on
 * insertion order, names, appointment counts, wall-clock time or randomness.
 */
class RoundRobinRotation
{
    /**
     * Every eligible id greater than the cursor, ascending, followed by every
     * id less than or equal to it, ascending — the rotation continues at the
     * SUCCESSOR of whoever was last assigned and wraps exactly once.
     *
     * A null cursor, or a cursor whose User is no longer eligible, simply
     * yields the eligible set ascending. That is what makes adding or removing
     * staff unable to corrupt the rotation: the successor is computed over the
     * ids present right now, so a vanished cursor means "start at the lowest"
     * rather than an error or a reset.
     *
     * NOTE what this function does NOT do: it never decides that a candidate
     * is unavailable, and it never advances anything. The caller walks this
     * order and the cursor moves only on a committed booking (§7.3 step 4) —
     * which is exactly why skipping an unavailable member cannot starve them.
     *
     * @param  array<int, int>  $eligibleStaffUserIds
     * @return array<int, int>
     */
    public function candidateOrder(array $eligibleStaffUserIds, ?int $cursor): array
    {
        $eligible = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $eligibleStaffUserIds),
            static fn (int $id): bool => $id > 0
        )));

        sort($eligible, SORT_NUMERIC);

        if ($eligible === [] || $cursor === null) {
            return $eligible;
        }

        $after = array_values(array_filter($eligible, static fn (int $id): bool => $id > $cursor));
        $upToAndIncluding = array_values(array_filter($eligible, static fn (int $id): bool => $id <= $cursor));

        return array_merge($after, $upToAndIncluding);
    }
}
