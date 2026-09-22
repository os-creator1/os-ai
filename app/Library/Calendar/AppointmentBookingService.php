<?php

namespace App\Library\Calendar;

use App\Enums\Calendar\AppointmentStatus;
use App\Events\Calendar\AppointmentCancelled;
use App\Events\Calendar\AppointmentCompleted;
use App\Events\Calendar\AppointmentNoShow;
use App\Events\Calendar\AppointmentRescheduled;
use App\Events\Calendar\AppointmentScheduled;
use App\Exceptions\Calendar\AppointmentSlotUnavailableException;
use App\Exceptions\Calendar\AppointmentStaffChangedException;
use App\Exceptions\Calendar\InvalidAppointmentTransitionException;
use App\Exceptions\Calendar\NoEligibleStaffAvailableException;
use App\Exceptions\Calendar\StaffNotAvailableException;
use App\Exceptions\Calendar\StaffNotEligibleForLocationException;
use App\Models\Appointment;
use App\Models\BookingType;
use App\Models\BookingTypeRoundRobinState;
use App\Models\BusinessLocation;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Implementation Contract 15 §7 — THE canonical appointment lifecycle engine.
 *
 * Double-booking prevention is a hard invariant and MySQL 8.4 cannot express
 * it as DDL, so it is enforced here, in application code, with row locks. Every
 * mutation in this class acquires a PREFIX OF ONE TOTAL ORDER and never
 * reorders it (§7.4):
 *
 *     tier 1  booking_type_round_robin_state (round-robin assignment only)
 *     tier 2  staff_booking_locks, ascending staff_user_id
 *     tier 3  the appointments row(s), ascending id
 *
 * Skipping a tier is allowed; taking one after skipping a lower-numbered tier
 * that is later needed is not. That is the whole deadlock-freedom argument, and
 * it is why cancel/complete/no-show take tier 2 even though they never change
 * an interval: if they took only tier 3 while a booking held tier 2 and wanted
 * tier 3, the two would cycle.
 *
 * Every lifecycle mutation then follows the identical four-step shape (§7.4):
 *   1. re-read status from persistence UNDER the tier-3 lock — never a
 *      route-bound model, a value carried in the request, or a read taken
 *      before the lock;
 *   2. validate the allowed source state (always `scheduled`; the other three
 *      are terminal) and refuse without writing if it no longer holds;
 *   3. perform the single state change, re-running §7.6's check against the
 *      NEW interval for a reschedule;
 *   4. dispatch exactly one event, after commit.
 *
 * Every transaction is wrapped in DB::transaction(..., 3), Contract 01's
 * bounded-retry precedent. The total order means these paths cannot deadlock
 * each other; the retry exists for a victim chosen because of unrelated
 * concurrent work on the same parent rows.
 *
 * Controllers and the public scheduler delegate here; this class is the only
 * thing in the slice that writes an appointment row.
 *
 * NO APPOINTMENT HISTORY. V1 ships no `appointment_transitions` (§5.4, §10,
 * §15); the five events this class dispatches are transient integration
 * notifications for Automations and are explicitly NOT an audit trail.
 */
class AppointmentBookingService
{
    private const TRANSACTION_ATTEMPTS = 3;

    public function __construct(
        private readonly CalendarLocationResolver $locations,
        private readonly StaffBookingLockManager $locks,
        private readonly StaffAvailabilityCalculator $availability,
        private readonly BookingConflictDetector $conflicts,
        private readonly RoundRobinRotation $rotation,
    ) {
    }

    /**
     * Create with an EXPLICIT staff member (§7.4 row 1: tier 2 only).
     *
     * @throws StaffNotEligibleForLocationException|StaffNotAvailableException|AppointmentSlotUnavailableException
     */
    public function book(
        BookingType $bookingType,
        int $staffUserId,
        int $contactId,
        CarbonInterface $startAt,
        ?int $createdByUserId = null,
        ?int $crmOpportunityId = null
    ): Appointment {
        $location = $this->locationFor($bookingType);
        $endAt = $this->endFor($bookingType, $startAt);

        // §7.2 step 1 — ensure OUTSIDE the transaction, before any lock.
        $this->locks->ensure([$staffUserId]);

        $appointment = DB::transaction(function () use (
            $bookingType, $location, $staffUserId, $contactId, $startAt, $endAt, $createdByUserId, $crmOpportunityId
        ): Appointment {
            $this->locks->lockAscending([$staffUserId]);

            $this->assertBookable($bookingType, $location, $staffUserId, $startAt, $endAt);

            return $this->insert(
                $bookingType,
                $location,
                $staffUserId,
                $contactId,
                $startAt,
                $endAt,
                $createdByUserId,
                $crmOpportunityId
            );
        }, self::TRANSACTION_ATTEMPTS);

        $this->dispatchScheduled($appointment);

        return $appointment;
    }

    /**
     * Create with ROUND-ROBIN assignment (§7.3, §7.4 row 2: tier 1 then tier 2
     * for every candidate).
     *
     * Tier 1 is held for the whole assignment, so two concurrent requests for
     * one Booking Type cannot both read the same "next" and both act on it: the
     * second waits, then computes its successor from the first's COMMITTED
     * result.
     *
     * @throws NoEligibleStaffAvailableException
     */
    public function bookWithRoundRobin(
        BookingType $bookingType,
        int $contactId,
        CarbonInterface $startAt,
        ?int $createdByUserId = null,
        ?int $crmOpportunityId = null
    ): Appointment {
        return $this->bookWithRoundRobinContactResolver(
            $bookingType,
            $startAt,
            static fn (): int => $contactId,
            $createdByUserId,
            $crmOpportunityId
        );
    }

    /**
     * Public booking's narrow adapter: resolve a Contact after tiers 1 and 2
     * are held, inside this service's one real transaction. The callback may
     * write a Contact; a refusal rolls it back with the Appointment and cursor.
     * Existing authenticated callers continue through bookWithRoundRobin().
     *
     * @param callable(): int $resolveContactId
     */
    public function bookWithRoundRobinContactResolver(
        BookingType $bookingType,
        CarbonInterface $startAt,
        callable $resolveContactId,
        ?int $createdByUserId = null,
        ?int $crmOpportunityId = null
    ): Appointment {
        $location = $this->locationFor($bookingType);
        $endAt = $this->endFor($bookingType, $startAt);

        // The pool as configured (§5.1), narrowed to those CURRENTLY eligible
        // (§6's re-derivation — never the pivot alone). Availability and
        // conflicts are decided per candidate inside the transaction, under
        // that candidate's own tier-2 lock.
        $pool = $this->eligiblePool($bookingType, $location);

        if ($pool === []) {
            throw NoEligibleStaffAvailableException::forBookingType(
                (int) $bookingType->id,
                $this->format($startAt),
                $this->format($endAt)
            );
        }

        // §7.2 step 1 — ensure for EVERY staff member the operation could
        // bind, in one batch, outside the transaction.
        $this->locks->ensure($pool);
        $this->ensureRoundRobinState($bookingType);

        $appointment = DB::transaction(function () use (
            $bookingType, $location, $pool, $resolveContactId, $startAt, $endAt, $createdByUserId, $crmOpportunityId
        ): Appointment {
            // Tier 1 first, and held for the whole assignment.
            $state = $this->lockRoundRobinState($bookingType);

            // Tier 2 for every candidate, ascending.
            $this->locks->lockAscending($pool);

            $contactId = $resolveContactId();

            $order = $this->rotation->candidateOrder($pool, $state->last_assigned_staff_user_id === null
                ? null
                : (int) $state->last_assigned_staff_user_id);

            foreach ($order as $candidateId) {
                if (! $this->availability->isAvailable($candidateId, $location, $startAt, $endAt)) {
                    continue;
                }

                if ($this->conflicts->hasConflict($candidateId, $startAt, $endAt)) {
                    continue;
                }

                $appointment = $this->insert(
                    $bookingType,
                    $location,
                    $candidateId,
                    $contactId,
                    $startAt,
                    $endAt,
                    $createdByUserId,
                    $crmOpportunityId
                );

                // §7.3 step 4 — the cursor advances in the SAME transaction as
                // the booking, and therefore only on a committed booking. A
                // refusal or an exception below leaves it exactly as it was.
                DB::table('booking_type_round_robin_state')
                    ->where('id', $state->id)
                    ->update([
                        'last_assigned_staff_user_id' => $candidateId,
                        'last_assigned_at' => now(),
                        'updated_at' => now(),
                    ]);

                return $appointment;
            }

            // §7.3 step 5 — write nothing, leave the cursor untouched. Skipping
            // never moves it, which is what keeps the rotation non-starving.
            throw NoEligibleStaffAvailableException::forBookingType(
                (int) $bookingType->id,
                $this->format($startAt),
                $this->format($endAt)
            );
        }, self::TRANSACTION_ATTEMPTS);

        $this->dispatchScheduled($appointment);

        return $appointment;
    }

    /**
     * Reschedule in time, between staff members, or both (§7.4 rows 3 and 4).
     *
     * ATOMICALLY ALL-OR-NOTHING (§7.6): either the new interval clears every
     * check a fresh booking would face, or the original row is left completely
     * untouched — the refusal is raised before any UPDATE and the transaction
     * rolls back regardless.
     *
     * When the staff member changes, BOTH staff locks are taken, ascending, and
     * the NEW staff member's own cross-Location conflicts are re-checked. The
     * old staff member is locked too, because their timeline is also changing:
     * the interval is leaving it.
     *
     * @throws InvalidAppointmentTransitionException|AppointmentStaffChangedException|StaffNotAvailableException|AppointmentSlotUnavailableException|StaffNotEligibleForLocationException
     */
    public function reschedule(
        Appointment $appointment,
        CarbonInterface $newStartAt,
        ?int $newStaffUserId = null,
        ?int $rescheduledByUserId = null
    ): Appointment {
        $appointmentId = (int) $appointment->id;
        $currentStaffUserId = (int) $appointment->staff_user_id;
        $targetStaffUserId = $newStaffUserId ?? $currentStaffUserId;

        $bookingType = $appointment->bookingType ?? BookingType::query()->findOrFail($appointment->booking_type_id);
        $location = $this->locationFor($bookingType);
        $newEndAt = $this->endFor($bookingType, $newStartAt);

        $staffToLock = array_unique([$currentStaffUserId, $targetStaffUserId]);

        $this->locks->ensure($staffToLock);

        $result = DB::transaction(function () use (
            $appointmentId, $currentStaffUserId, $staffToLock, $targetStaffUserId, $bookingType, $location,
            $newStartAt, $newEndAt
        ): array {
            // Tier 2 before tier 3, always.
            $this->locks->lockAscending($staffToLock);

            $locked = $this->lockAppointment($appointmentId);

            $this->assertHeldStaffOwnsAppointment($locked, $currentStaffUserId);

            $this->assertScheduled($locked, 'rescheduled');

            $previousStaffUserId = (int) $locked->staff_user_id;
            $previousStartAt = Carbon::parse($locked->start_at);
            $previousEndAt = Carbon::parse($locked->end_at);

            // The new staff member — which may be the same one — must clear
            // exactly the checks a fresh booking would face, against the NEW
            // interval. The appointment's own row is excluded so it never
            // conflicts with the interval it is leaving.
            $this->assertBookable(
                $bookingType,
                $location,
                $targetStaffUserId,
                $newStartAt,
                $newEndAt,
                $appointmentId
            );

            DB::table('appointments')
                ->where('id', $appointmentId)
                ->update([
                    'staff_user_id' => $targetStaffUserId,
                    'start_at' => $newStartAt,
                    'end_at' => $newEndAt,
                    'reschedule_count' => DB::raw('reschedule_count + 1'),
                    'updated_at' => now(),
                ]);

            return [
                'previousStaffUserId' => $previousStaffUserId,
                'previousStartAt' => $previousStartAt,
                'previousEndAt' => $previousEndAt,
            ];
        }, self::TRANSACTION_ATTEMPTS);

        // §10 — both staff ids are ALWAYS populated; equal on a same-staff
        // reschedule, so a consumer detects a move by comparing them. The
        // payload is built from the values THIS transaction wrote, never from
        // a re-read after commit, which could already reflect a later change.
        AppointmentRescheduled::dispatch(
            $appointmentId,
            $result['previousStaffUserId'],
            $targetStaffUserId,
            $this->format($result['previousStartAt']),
            $this->format($result['previousEndAt']),
            $this->format($newStartAt),
            $this->format($newEndAt),
            $rescheduledByUserId
        );

        return Appointment::query()->findOrFail($appointmentId);
    }

    /** @throws InvalidAppointmentTransitionException|AppointmentStaffChangedException */
    public function cancel(Appointment $appointment, ?int $cancelledByUserId = null, ?string $reason = null): Appointment
    {
        $fresh = $this->resolveTerminal($appointment, AppointmentStatus::Cancelled, 'cancelled', $cancelledByUserId, $reason);

        AppointmentCancelled::dispatch((int) $fresh->id, (int) $fresh->staff_user_id, $cancelledByUserId, $reason);

        return $fresh;
    }

    /** @throws InvalidAppointmentTransitionException|AppointmentStaffChangedException */
    public function complete(Appointment $appointment, ?int $completedByUserId = null): Appointment
    {
        $fresh = $this->resolveTerminal($appointment, AppointmentStatus::Completed, 'completed', $completedByUserId, null);

        AppointmentCompleted::dispatch((int) $fresh->id, (int) $fresh->staff_user_id, $completedByUserId);

        return $fresh;
    }

    /** @throws InvalidAppointmentTransitionException|AppointmentStaffChangedException */
    public function markNoShow(Appointment $appointment, ?int $markedByUserId = null): Appointment
    {
        $fresh = $this->resolveTerminal($appointment, AppointmentStatus::NoShow, 'marked no-show', $markedByUserId, null);

        AppointmentNoShow::dispatch((int) $fresh->id, (int) $fresh->staff_user_id, $markedByUserId);

        return $fresh;
    }

    /**
     * Cancel, complete and no-show are one transition shape: tier 2 then tier
     * 3, re-read the staff assignment and status under the lock, require the
     * lock held to be the appointment's own staff timeline and the status to
     * be `scheduled`, write one terminal state. Sharing the implementation is
     * what guarantees they cannot drift apart — "complete and no-show cannot
     * both succeed" is true because both run this same guard (§7.4).
     *
     * The staff id used for tier 2 comes from the caller's model, i.e. from
     * BEFORE any lock. If the appointment was moved to another staff member in
     * the meantime this fails closed with AppointmentStaffChangedException,
     * writing nothing and dispatching nothing: the newly discovered staff
     * member's tier-2 lock is deliberately NOT taken here, since acquiring
     * tier 2 after tier 3 would break the canonical lock order.
     *
     * @throws InvalidAppointmentTransitionException|AppointmentStaffChangedException
     */
    private function resolveTerminal(
        Appointment $appointment,
        AppointmentStatus $target,
        string $attempted,
        ?int $actorUserId,
        ?string $reason
    ): Appointment {
        $appointmentId = (int) $appointment->id;
        $staffUserId = (int) $appointment->staff_user_id;

        $this->locks->ensure([$staffUserId]);

        DB::transaction(function () use ($appointmentId, $staffUserId, $target, $attempted, $actorUserId, $reason): void {
            // Tier 2 even though no interval changes: taking only tier 3 here
            // would let this path and a booking path acquire the two tiers in
            // opposite orders, which is exactly the cycle §7.4 forbids.
            $this->locks->lockAscending([$staffUserId]);

            $locked = $this->lockAppointment($appointmentId);

            $this->assertHeldStaffOwnsAppointment($locked, $staffUserId);

            $this->assertScheduled($locked, $attempted);

            $update = [
                'status' => $target->value,
                'resolved_at' => now(),
                'resolved_by_user_id' => $actorUserId,
                'updated_at' => now(),
            ];

            if ($target === AppointmentStatus::Cancelled) {
                $update['cancellation_reason'] = $reason;
            }

            DB::table('appointments')->where('id', $appointmentId)->update($update);
        }, self::TRANSACTION_ATTEMPTS);

        return Appointment::query()->findOrFail($appointmentId);
    }

    // -----------------------------------------------------------------
    // Shared guards
    // -----------------------------------------------------------------

    /**
     * §6 eligibility + §5.2/§5.3 availability + §7.6 conflicts, in that order.
     * Every one of these runs with the staff member's tier-2 lock already held.
     */
    private function assertBookable(
        BookingType $bookingType,
        BusinessLocation $location,
        int $staffUserId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?int $ignoreAppointmentId = null
    ): void {
        // §6 — re-derived through LocationAccessGuard right now. A
        // booking_type_staff row is configuration intent and never authorizes.
        if (! $this->locations->isEligible($staffUserId, $location)) {
            throw StaffNotEligibleForLocationException::forStaff($staffUserId, (int) $location->id);
        }

        if (! $this->availability->isAvailable($staffUserId, $location, $startAt, $endAt)) {
            throw StaffNotAvailableException::forStaff($staffUserId, $this->format($startAt), $this->format($endAt));
        }

        if ($this->conflicts->hasConflict($staffUserId, $startAt, $endAt, $ignoreAppointmentId)) {
            throw AppointmentSlotUnavailableException::forStaff(
                $staffUserId,
                $this->format($startAt),
                $this->format($endAt)
            );
        }
    }

    /**
     * §7.4 steps 1 and 2 — the status is re-read from persistence under the
     * tier-3 lock and validated there. Nothing earlier in the request is
     * trusted.
     */
    private function assertScheduled(object $lockedRow, string $attempted): void
    {
        $status = AppointmentStatus::from((string) $lockedRow->status);

        if ($status !== AppointmentStatus::Scheduled) {
            throw InvalidAppointmentTransitionException::from((int) $lockedRow->id, $status, $attempted);
        }
    }

    /**
     * §7.4 — the staff member whose tier-2 lock this transaction holds was
     * chosen from the caller's model BEFORE the locks were granted. Re-read
     * from the tier-3-locked row, the appointment must still be on that same
     * staff timeline; otherwise a mutation would run under the wrong staff
     * lock (or under none for the staff member it actually belongs to).
     * Refused before any write, so the transaction rolls back and no event
     * is dispatched.
     *
     * @throws AppointmentStaffChangedException
     */
    private function assertHeldStaffOwnsAppointment(object $lockedRow, int $heldStaffUserId): void
    {
        if ((int) $lockedRow->staff_user_id !== $heldStaffUserId) {
            throw AppointmentStaffChangedException::forAppointment(
                (int) $lockedRow->id,
                $heldStaffUserId,
                (int) $lockedRow->staff_user_id
            );
        }
    }

    /** Tier 3. */
    private function lockAppointment(int $appointmentId): object
    {
        $locked = DB::table('appointments')->where('id', $appointmentId)->lockForUpdate()->first();

        if ($locked === null) {
            abort(404);
        }

        return $locked;
    }

    /**
     * Tier 1, step 1 — §7.2's ensure, OUTSIDE the transaction, for the
     * round-robin state row: an insertOrIgnore that tolerates losing the race
     * (the unique key on booking_type_id is what makes that safe).
     *
     * It must not run inside the transaction, and no plain SELECT may precede
     * the tier-1 lock there: under REPEATABLE READ the first plain read pins
     * the transaction's snapshot, and a snapshot taken before this request's
     * lock wait ends cannot see the booking the previous tier-1 holder
     * committed — so the conflict check would miss it and double-book.
     */
    private function ensureRoundRobinState(BookingType $bookingType): void
    {
        DB::table('booking_type_round_robin_state')->insertOrIgnore([[
            'booking_type_id' => (int) $bookingType->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]]);
    }

    /**
     * Tier 1, step 2 — the locking read, inside the transaction and as its
     * FIRST statement. A locking read is a current read and does not pin the
     * snapshot, so the snapshot is taken only after the lock is granted.
     */
    private function lockRoundRobinState(BookingType $bookingType): object
    {
        $bookingTypeId = (int) $bookingType->id;

        $locked = DB::table('booking_type_round_robin_state')
            ->where('booking_type_id', $bookingTypeId)
            ->lockForUpdate()
            ->first();

        if ($locked !== null) {
            return $locked;
        }

        // Only possible if the row was deleted after ensureRoundRobinState():
        // one re-ensure and one re-select (§7.2 step 3), then fail closed.
        $this->ensureRoundRobinState($bookingType);

        $locked = DB::table('booking_type_round_robin_state')
            ->where('booking_type_id', $bookingTypeId)
            ->lockForUpdate()
            ->first();

        if ($locked === null) {
            throw NoEligibleStaffAvailableException::forBookingType($bookingTypeId, '', '');
        }

        return $locked;
    }

    /**
     * §5.1/§6 — the configured pool, narrowed to those currently eligible.
     * Availability and conflicts are deliberately NOT applied here: they are
     * decided per candidate inside the transaction, under that candidate's own
     * lock, because an answer computed outside the lock is already stale.
     *
     * @return array<int, int>
     */
    private function eligiblePool(BookingType $bookingType, BusinessLocation $location): array
    {
        return $bookingType->staff()
            ->orderBy('users.id')
            ->pluck('users.id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $this->locations->isEligible($id, $location))
            ->values()
            ->all();
    }

    private function locationFor(BookingType $bookingType): BusinessLocation
    {
        return $bookingType->location ?? BusinessLocation::query()->findOrFail($bookingType->business_location_id);
    }

    private function endFor(BookingType $bookingType, CarbonInterface $startAt): CarbonInterface
    {
        return Carbon::instance($startAt->toDateTime())->addMinutes((int) $bookingType->duration_minutes);
    }

    private function insert(
        BookingType $bookingType,
        BusinessLocation $location,
        int $staffUserId,
        int $contactId,
        CarbonInterface $startAt,
        CarbonInterface $endAt,
        ?int $createdByUserId,
        ?int $crmOpportunityId
    ): Appointment {
        return Appointment::create([
            'business_location_id' => $location->id,
            'booking_type_id' => $bookingType->id,
            'staff_user_id' => $staffUserId,
            'contact_id' => $contactId,
            'crm_opportunity_id' => $crmOpportunityId,
            'created_by_user_id' => $createdByUserId,
            'status' => AppointmentStatus::Scheduled,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'reschedule_count' => 0,
        ]);
    }

    private function dispatchScheduled(Appointment $appointment): void
    {
        AppointmentScheduled::dispatch(
            (int) $appointment->id,
            (int) $appointment->business_location_id,
            (int) $appointment->booking_type_id,
            (int) $appointment->staff_user_id,
            (int) $appointment->contact_id,
            $appointment->crm_opportunity_id === null ? null : (int) $appointment->crm_opportunity_id,
            $this->format($appointment->start_at),
            $this->format($appointment->end_at),
            $appointment->created_by_user_id === null ? null : (int) $appointment->created_by_user_id,
        );
    }

    /** Event payloads carry timestamps as UTC strings, never model instances. */
    private function format(CarbonInterface|string $moment): string
    {
        return Carbon::parse($moment)->utc()->toDateTimeString();
    }
}
