<?php

namespace App\Library\Calendar;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Contacts;
use App\Library\Workspace\LocationAccessGuard;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Implementation Contract 15 §6, §12.D — the READ side of the authenticated
 * calendar: which Locations an actor may pick, and which appointments a
 * Location's day/week view may show.
 *
 * It holds no booking logic. Every WRITE goes through AppointmentBookingService;
 * this class only answers "what may this actor see", and it answers by asking
 * LocationAccessGuard — the sole Location authority — never by inventing a
 * second mechanism.
 *
 * THE INVARIANT this class exists to keep: an appointment is only ever read
 * through a Location that has ALREADY been authorized for the actor (by
 * CalendarLocationResolver::resolveForActor), and every query below is
 * constrained to that one Location's id. There is deliberately no method that
 * reads appointments across Locations, so a caller cannot render, count or
 * fetch one from a Location the actor cannot reach — and a guessed appointment
 * uid from a sibling Location simply does not resolve (Addendum §4).
 */
class CalendarScheduleService
{
    public function __construct(private readonly LocationAccessGuard $guard)
    {
    }

    /**
     * The Locations of this Business the actor may work in — the ONLY source
     * for the Location picker.
     *
     * Active Locations only (an archived Location has no calendar), each one
     * re-checked through LocationAccessGuard right now. A Location the actor
     * cannot reach is absent, not disabled, so the picker can never disclose
     * that it exists — not by name, not by count.
     *
     * @return Collection<int, BusinessLocation>
     */
    public function accessibleLocations(Business $business, int $actorUserId): Collection
    {
        return $business->activeLocations()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->filter(fn (BusinessLocation $location): bool => $this->guard->userCanAccessLocation($actorUserId, $location))
            ->values();
    }

    /**
     * Appointments at ONE already-authorized Location overlapping [from, to).
     *
     * Half-open, matching BookingConflictDetector, so an appointment ending
     * exactly at the range start is not shown on the next day.
     *
     * @return Collection<int, Appointment>
     */
    public function appointmentsForRange(BusinessLocation $location, CarbonInterface $from, CarbonInterface $to): Collection
    {
        return Appointment::query()
            ->where('business_location_id', $location->id)
            ->where('start_at', '<', $to)
            ->where('end_at', '>', $from)
            ->with(['bookingType:id,name,color,duration_minutes', 'staff:id,first_name,last_name,email'])
            ->orderBy('start_at')
            ->get();
    }

    /**
     * Scoped to the Location, so a uid belonging to a sibling Location — or to
     * another Business entirely — is simply not found. The caller turns null
     * into 404, indistinguishable from an appointment that does not exist.
     */
    public function findAppointment(BusinessLocation $location, string $appointmentUid): ?Appointment
    {
        return Appointment::query()
            ->where('business_location_id', $location->id)
            ->where('uid', $appointmentUid)
            ->with(['bookingType', 'staff:id,first_name,last_name,email'])
            ->first();
    }

    /**
     * A Contact this actor may book at this Location.
     *
     * Contacts belong to one Location (Addendum §5), and `contacts.location_id`
     * is nullable by design for legacy rows. So a Contact is bookable here when
     * it belongs to THIS Business and is either attributed to THIS Location or
     * not attributed to any. One attributed to a SIBLING Location is refused:
     * attaching it would quietly cross the Location boundary that §5.8 forbids
     * for public booking, and a staff surface must not be weaker than that.
     *
     * This is a lookup of an EXISTING Contact only. Find-or-create for a
     * booking is Sub-slice E's seam (§5.8.5) and is deliberately not built here.
     */
    public function findBookableContact(Business $business, BusinessLocation $location, string $contactUid): ?Contacts
    {
        $contact = Contacts::query()
            ->where('business_id', $business->id)
            ->where('uid', $contactUid)
            ->first();

        if ($contact === null) {
            return null;
        }

        if ($contact->location_id !== null && (int) $contact->location_id !== (int) $location->id) {
            return null;
        }

        return $contact;
    }

    /**
     * Existing Contacts matching a search that are bookable at this Location
     * (same rule as findBookableContact), for the booking form's picker.
     *
     * Reuses ContactDirectory's own matching so the picker searches exactly as
     * the Contacts list does; only the Location rule is added here.
     *
     * @return list<array{uid: string, name: ?string, phone: string}>
     */
    public function searchBookableContacts(Business $business, BusinessLocation $location, string $search, int $limit = 20): array
    {
        $search = trim($search);

        if ($search === '') {
            return [];
        }

        $directory = app(\App\Library\Contacts\ContactDirectory::class);

        $ids = Contacts::query()
            ->where('business_id', $business->id)
            ->whereIn('id', $directory->matchingContactIds($business, $search))
            ->where(function ($query) use ($location): void {
                $query->whereNull('location_id')->orWhere('location_id', $location->id);
            })
            ->orderByDesc('id')
            ->limit($limit)
            ->pluck('id')
            ->all();

        return array_values($directory->summaries($business, $ids));
    }

    /**
     * Name and phone for the Contacts of a set of appointments, keyed by
     * contact id, for display. Constrained to this Business.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return array<int, array{uid: string, name: ?string, phone: string}>
     */
    public function contactSummaries(Business $business, Collection $appointments): array
    {
        $ids = $appointments->pluck('contact_id')->unique()->values()->all();

        if ($ids === []) {
            return [];
        }

        return app(\App\Library\Contacts\ContactDirectory::class)->summaries($business, $ids);
    }
}
