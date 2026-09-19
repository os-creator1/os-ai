<?php

namespace App\Library\Calendar;

use App\Exceptions\Calendar\StaffBookingLockUnavailableException;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 15 §7.1 tier 2 + §7.2 — the ONE implementation of
 * "serialize this staff member's whole timeline".
 *
 * Nothing else in this slice may lock `staff_booking_locks`; every scheduling
 * and lifecycle mutation comes through here, so the ensure-then-lock sequence
 * and the ascending-id ordering exist in exactly one place and cannot drift
 * between call sites.
 *
 * WHY A DEDICATED ROW AT ALL. MySQL 8.4 has no temporal-exclusion constraint,
 * so "no two scheduled appointments for one staff member may overlap" has to
 * be enforced by locking a serialization point around the check-and-write.
 * This mirrors OpportunityManager::beginRun()'s technique, but deliberately
 * NOT its locked table: locking the `users` row would contend with every
 * unrelated write to that row (profile edits, auth), so the lock target is a
 * dedicated row that exists for nothing else (§7).
 */
class StaffBookingLockManager
{
    /**
     * §7.2 step 1 — ensure, OUTSIDE the transaction.
     *
     * Must be called before DB::beginTransaction(), for every staff member the
     * operation could bind — the one explicit staff member, or the entire
     * round-robin candidate set — in ONE batch.
     *
     * `insertOrIgnore` is idempotent by construction *because*
     * `staff_booking_locks.staff_user_id` is the primary key (§7.2): INSERT
     * IGNORE suppresses a duplicate-key error, so two concurrent first-ever
     * bookings both succeed here and neither sees an exception.
     *
     * Running it outside the transaction is deliberate, not incidental: an
     * INSERT of the same key inside two concurrent transactions makes one wait
     * on the other's insert-intention lock for the whole transaction, turning
     * first use into a latency cliff.
     *
     * @param  array<int, int>  $staffUserIds
     */
    public function ensure(array $staffUserIds): void
    {
        $ids = $this->normalize($staffUserIds);

        if ($ids === []) {
            return;
        }

        $now = now();

        DB::table('staff_booking_locks')->insertOrIgnore(array_map(
            static fn (int $id): array => [
                'staff_user_id' => $id,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $ids
        ));
    }

    /**
     * §7.2 step 2/3 + §7.4 tier 2 — lock, INSIDE the transaction, in ascending
     * staff_user_id order.
     *
     * The ascending order is the deadlock-freedom argument: two operations
     * that both need staff 7 and 9 always take 7 then 9, so they queue rather
     * than cycle. Callers never sort for themselves — this method is the only
     * thing that decides the order, which is why it sorts rather than trusting
     * its input.
     *
     * A missing row is only possible if it was deleted between ensure() and
     * here (a User deletion cascades it away). §7.2 step 3 allows exactly one
     * re-ensure and one re-select; if it is still absent the operation FAILS
     * rather than proceeding unserialized, and it never retries unboundedly.
     *
     * @param  array<int, int>  $staffUserIds
     * @return array<int, int>  the ids actually locked, ascending
     *
     * @throws StaffBookingLockUnavailableException
     */
    public function lockAscending(array $staffUserIds): array
    {
        $ids = $this->normalize($staffUserIds);

        foreach ($ids as $id) {
            if ($this->lockRow($id) !== null) {
                continue;
            }

            // One re-ensure, one re-select — then fail closed.
            $this->ensure([$id]);

            if ($this->lockRow($id) === null) {
                throw StaffBookingLockUnavailableException::forStaff($id);
            }
        }

        return $ids;
    }

    /**
     * Convenience for the common single-staff case; still goes through the
     * same ordering path so there is no second code shape to audit.
     */
    public function ensureAndLock(int $staffUserId): void
    {
        $this->ensure([$staffUserId]);
        $this->lockAscending([$staffUserId]);
    }

    private function lockRow(int $staffUserId): ?object
    {
        return DB::table('staff_booking_locks')
            ->where('staff_user_id', $staffUserId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * Deduplicated, positive, ascending — the canonical tier-2 order.
     *
     * @param  array<int, int|string>  $staffUserIds
     * @return array<int, int>
     */
    private function normalize(array $staffUserIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn ($id): int => (int) $id, $staffUserIds),
            static fn (int $id): bool => $id > 0
        )));

        sort($ids, SORT_NUMERIC);

        return $ids;
    }
}
