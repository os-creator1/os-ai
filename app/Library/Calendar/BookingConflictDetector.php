<?php

namespace App\Library\Calendar;

use App\Enums\Calendar\AppointmentStatus;
use App\Enums\Calendar\ExternalCalendarConnectionState;
use App\Models\Appointment;
use App\Models\ExternalCalendarBusyBlock;
use App\Models\ExternalCalendarConnection;
use Carbon\CarbonInterface;

/**
 * Implementation Contract 15 §7.6 — "is this staff member already busy in
 * this interval", written from the start as an EXTENSIBLE UNION OF BUSY
 * SOURCES.
 *
 * That extensibility is a contract requirement, not a flourish: Sub-slice F
 * must be able to register `external_calendar_busy_blocks` as a second source
 * without touching the locking or transaction structure this sub-slice ships
 * and tests. So the union point exists now, with exactly one source in it.
 *
 * MUST BE CALLED WITH THE STAFF MEMBER'S TIER-2 LOCK HELD, inside the
 * transaction that will perform the write (§7.6). The query itself takes no
 * locks — it does not need to, because tier 2 already excludes every other
 * booking transaction for this staff member. Calling it without that lock
 * would produce an answer that is true only until the statement finishes.
 */
class BookingConflictDetector
{
    /**
     * §7.6 step 1 — internal appointments, ACROSS EVERY LOCATION.
     *
     * Cross-Location reach is the whole point of tier 2 (§7.1): a staff member
     * booked at Location A is busy at Location B, and Blueprint §12 requires
     * that to be structural rather than a per-Location check somebody might
     * forget.
     *
     * Only `scheduled` rows conflict. Cancelled, completed and no-show rows
     * are terminal history and never block a new booking.
     *
     * Half-open intervals `[start, end)`: an appointment ending exactly when
     * the next begins is not a conflict, which is what makes back-to-back
     * slots bookable.
     *
     * @param  int|null  $ignoreAppointmentId  the row being rescheduled, which must not
     *                                         conflict with its own current interval
     */
    public function hasConflict(
        int $staffUserId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?int $ignoreAppointmentId = null,
        int $bufferBeforeMinutes = 0,
        int $bufferAfterMinutes = 0
    ): bool {
        // Buffers widen the interval the NEW booking occupies; the appointment
        // itself keeps its real start and end. Every busy source is asked about
        // that occupied interval.
        $occupiedStart = $startAt->copy()->subMinutes($bufferBeforeMinutes);
        $occupiedEnd = $endAt->copy()->addMinutes($bufferAfterMinutes);

        foreach ($this->busySources() as $source) {
            if ($source($staffUserId, $occupiedStart, $occupiedEnd, $ignoreAppointmentId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The union point. Sub-slice F adds its busy-block source here and
     * changes nothing else — no lock, no transaction boundary, no call site.
     *
     * Sources are ADVISORY AND ADDITIVE (§7.6 step 2): a source that has no
     * data, or whose data is stale, contributes nothing, and internal
     * appointments alone remain fully authoritative. That is §11's
     * fail-safe-stale rule expressed structurally.
     *
     * @return array<int, callable(int, CarbonInterface, CarbonInterface, ?int): bool>
     */
    private function busySources(): array
    {
        return [
            fn (int $staffUserId, CarbonInterface $startAt, CarbonInterface $endAt, ?int $ignoreAppointmentId): bool
                => $this->hasOverlappingAppointment($staffUserId, $startAt, $endAt, $ignoreAppointmentId),

            // Implementation Contract 15 §5.6/§7.6/§12.F, Sub-slice F — the
            // second busy source, registered through this same union point
            // with no change to the locking/transaction structure above.
            // Advisory and additive only (§11's fail-safe-stale rule): a
            // staff member with no active external connection, or whose
            // sync cache is empty or stale, contributes nothing here, and
            // internal-appointment checking alone remains fully
            // authoritative.
            fn (int $staffUserId, CarbonInterface $startAt, CarbonInterface $endAt, ?int $ignoreAppointmentId): bool
                => $this->hasOverlappingExternalBusyBlock($staffUserId, $startAt, $endAt),
        ];
    }

    /**
     * §7.6 step 2 — union in any external_calendar_busy_blocks row for this
     * staff member's ACTIVE connection overlapping the candidate interval.
     * Cardinality is at most one active connection per User (§5.5), so this
     * is a single scalar lookup, not a collection.
     */
    private function hasOverlappingExternalBusyBlock(int $staffUserId, CarbonInterface $startAt, CarbonInterface $endAt): bool
    {
        $connectionId = ExternalCalendarConnection::query()
            ->where('user_id', $staffUserId)
            ->where('state', ExternalCalendarConnectionState::Active->value)
            ->value('id');

        if ($connectionId === null) {
            return false;
        }

        return ExternalCalendarBusyBlock::query()
            ->where('external_calendar_connection_id', $connectionId)
            ->where('start_at', '<', $endAt)
            ->where('end_at', '>', $startAt)
            ->exists();
    }

    private function hasOverlappingAppointment(
        int $staffUserId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?int $ignoreAppointmentId
    ): bool {
        // An existing appointment occupies its own interval widened by ITS
        // Booking Type's buffers, so a neighbour's buffer protects it too.
        $query = Appointment::query()
            ->leftJoin('booking_types', 'booking_types.id', '=', 'appointments.booking_type_id')
            ->where('appointments.staff_user_id', $staffUserId)
            ->where('appointments.status', AppointmentStatus::Scheduled->value)
            ->whereRaw('DATE_SUB(appointments.start_at, INTERVAL COALESCE(booking_types.buffer_before_minutes, 0) MINUTE) < ?', [$endAt->copy()->utc()->toDateTimeString()])
            ->whereRaw('DATE_ADD(appointments.end_at, INTERVAL COALESCE(booking_types.buffer_after_minutes, 0) MINUTE) > ?', [$startAt->copy()->utc()->toDateTimeString()]);

        if ($ignoreAppointmentId !== null) {
            $query->where('appointments.id', '!=', $ignoreAppointmentId);
        }

        return $query->exists();
    }
}
