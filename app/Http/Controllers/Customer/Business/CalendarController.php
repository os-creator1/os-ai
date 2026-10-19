<?php

namespace App\Http\Controllers\Customer\Business;

use App\Enums\Entitlement\PlatformFeature;
use App\Exceptions\Calendar\AppointmentSlotUnavailableException;
use App\Exceptions\Calendar\BookingRefusedException;
use App\Exceptions\Calendar\InvalidAppointmentTransitionException;
use App\Exceptions\Calendar\NoEligibleStaffAvailableException;
use App\Exceptions\Calendar\StaffBookingLockUnavailableException;
use App\Exceptions\Calendar\StaffNotAvailableException;
use App\Exceptions\Calendar\StaffNotEligibleForLocationException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Customer\Business\Concerns\ResolvesBusinessTenancy;
use App\Library\Calendar\AppointmentBookingService;
use App\Library\Calendar\BookingTypeManager;
use App\Library\Calendar\CalendarLocationResolver;
use App\Library\Calendar\CalendarScheduleService;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\BusinessLocation;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Implementation Contract 15 §6, §12.D — the authenticated Business calendar:
 * a Location picker, a day/week schedule, and the five appointment actions.
 *
 * THREE GATES ON EVERY ACTION, in this order, none optional — the same three
 * Sub-slice B established:
 *   1. Workspace/Business tenancy (BusinessRouteAccess);
 *   2. the Calendar entitlement decision, via
 *      resolveEntitledBusinessTenancy(..., PlatformFeature::Calendar->value);
 *   3. LocationAccessGuard for the exact Location, via CalendarLocationResolver
 *      — fresh on every request, never trusting a Location id merely because
 *      the client submitted it.
 *
 * Gate 2 is why every route here currently 404s even for the account owner:
 * `PlatformFeature::Calendar` is still `Planned` (§11), so EntitlementManager
 * refuses with `platform_feature_unavailable` before any database read. That is
 * the intended state of this sub-slice; Sub-slice E's flip is what makes the
 * surface executable, and nothing here may flip it.
 *
 * THIS CONTROLLER HOLDS NO BOOKING LOGIC. Every write is a single call to
 * AppointmentBookingService, which owns the lock order, the overlap check and
 * the events (§7). No appointment row is written, and no lifecycle state is
 * changed, from HTTP code — a stale view or a slot filled after render simply
 * surfaces the engine's own typed refusal as a message. Nothing here retries a
 * refused booking: a retry that quietly re-submitted would be the one thing
 * that could weaken the engine's checks.
 *
 * NOT built here: public self-booking, Contact find-or-create, external
 * calendars (Sub-slices E and F).
 */
class CalendarController extends Controller
{
    use ResolvesBusinessTenancy;

    /** Monday-first, the convention for a working-week calendar. */
    private const WEEK_STARTS_ON = Carbon::MONDAY;

    /**
     * How many consecutive days the multi-day ("week") view may show. The
     * browser picks one from the width its grid actually has and asks for it
     * with `?days=`; anything else falls back to the classic 7. Presentation
     * only — the data read is still one Location's appointments for the range.
     */
    private const VISIBLE_DAY_OPTIONS = [7, 10, 14];

    public function __construct(
        private readonly AppointmentBookingService $booking,
        private readonly BookingTypeManager $bookingTypes,
        private readonly CalendarLocationResolver $locations,
        private readonly CalendarScheduleService $schedule,
    ) {
    }

    /**
     * The Location picker — and its single-Location shortcut.
     *
     * The list is built ONLY from Locations LocationAccessGuard grants the
     * actor, so it cannot disclose one they cannot reach. An actor with no
     * accessible Location gets a 404, exactly as an unreachable Location does.
     * A single accessible Location skips the picker entirely: there is nothing
     * to choose, and showing a one-item list would only add a click.
     *
     * A Business with NO active Location at all is different: there is nothing
     * to withhold, and the Calendar entry the menu offers must still lead
     * somewhere. That actor — already past the Business tenancy check above —
     * sees an empty picker that says a Location is needed first.
     */
    public function index(Request $request, string $workspaceUid, string $businessUid): View|RedirectResponse
    {
        [$workspace, $business] = $this->tenancy($workspaceUid, $businessUid);

        // The window a link asked for (Home's Bookings card) survives the redirect and the Location picker; show() validates each value.
        $window = array_filter($request->only(['view', 'days', 'date']), fn ($value): bool => is_string($value) && $value !== '');

        $locations = $this->schedule->accessibleLocations($business, (int) Auth::id());

        if ($locations->isEmpty() && ! $business->activeLocations()->exists()) {
            return view('customer.business.calendar.picker', [
                'workspace' => $workspace,
                'business' => $business,
                'locations' => $locations,
            ]);
        }

        if ($locations->isEmpty()) {
            abort(404);
        }

        if ($locations->count() === 1) {
            return redirect()->route('customer.workspaces.businesses.calendar.schedule', [
                $workspaceUid, $businessUid, $locations->first()->uid,
            ] + $window);
        }

        return view('customer.business.calendar.picker', [
            'workspace' => $workspace,
            'business' => $business,
            'locations' => $locations,
            'window' => $window,
        ]);
    }

    /**
     * The appointments BOOKED (created) in a period, across every Location the actor may reach: the records behind Home's Bookings card,
     * which counts by creation, not by the day of the visit (so a booking made today for next month is here, and the schedule's 14-day window
     * cannot hold a longer period). Cancelled bookings are not counted by the card and are not listed. An invalid or missing range is ignored:
     * the list is simply every booking, never an error and never wider than the actor's Locations.
     */
    public function bookings(Request $request, string $workspaceUid, string $businessUid): View
    {
        [$workspace, $business] = $this->tenancy($workspaceUid, $businessUid);

        $locations = $this->schedule->accessibleLocations($business, (int) Auth::id());
        abort_if($locations->isEmpty(), 404);

        $query = Appointment::query()
            ->whereIn('business_location_id', $locations->pluck('id')->all())
            ->where('status', '!=', \App\Enums\Calendar\AppointmentStatus::Cancelled->value)
            ->with(['bookingType:id,name', 'location:id,uid,name']);

        $created = \App\Library\Dashboard\Home\HomeDestinationRange::fromRequest($request, $business);

        if ($created !== null) {
            $query->where('created_at', '>=', $created->startUtc)->where('created_at', '<', $created->endUtc);
        }

        $appointments = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('customer.business.calendar.bookings', [
            'workspace' => $workspace,
            'business' => $business,
            'appointments' => $appointments,
            'contacts' => $this->schedule->contactSummaries($business, $appointments->getCollection()),
            'timezone' => $this->timezoneFor($business),
            'created' => $created,
        ]);
    }

    /**
     * Day or week view of ONE authorized Location.
     *
     * Navigation (previous / next / today / day↔week) is plain links carrying
     * `view` and `date`, so there is no client-side data endpoint and therefore
     * no second read surface to authorize: the appointments in view are
     * rendered by this one request, from this one Location.
     */
    public function show(Request $request, string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $timezone = $this->timezoneFor($business);
        $view = $request->query('view') === 'day' ? 'day' : 'week';
        $days = $this->visibleDays($request->query('days'));
        $anchor = $this->anchorDate($request->query('date'), $timezone);

        // With no date given, a span that is not whole weeks opens on the
        // start of the current week (the same rule as its "Today" control)
        // rather than on today itself.
        if ($view === 'week' && $days % 7 !== 0 && ! $this->isCalendarDate($request->query('date'))) {
            $anchor = Carbon::parse($this->todayAnchor($view, $days, $timezone), $timezone)->startOfDay();
        }

        [$localFrom, $localTo] = $this->rangeFor($view, $anchor, $days);

        $appointments = $this->schedule->appointmentsForRange($location, $localFrom->copy()->utc(), $localTo->copy()->utc());
        $contacts = $this->schedule->contactSummaries($business, $appointments);

        return view('customer.business.calendar.schedule', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'timezone' => $timezone,
            'view' => $view,
            'days' => $days,
            'anchor' => $anchor,
            'rangeFrom' => $localFrom,
            'rangeTo' => $localTo,
            'previous' => $this->shift($anchor, $view, $days, -1),
            'next' => $this->shift($anchor, $view, $days, 1),
            'todayAnchor' => $this->todayAnchor($view, $days, $timezone),
            'dayAnchor' => $this->dayAnchor($view, $days, $anchor, $localFrom, $localTo, $timezone),
            'appointments' => $appointments,
            'contacts' => $contacts,
            'events' => $this->eventsFor($appointments, $contacts, $timezone, [$workspaceUid, $businessUid, $locationUid]),
            'hasSiblingLocations' => $this->schedule->accessibleLocations($business, (int) Auth::id())->count() > 1,
        ]);
    }

    /**
     * The booking form. Contact choice is a server-side search over EXISTING
     * Contacts (Sub-slice E owns find-or-create), narrowed to those bookable at
     * this Location.
     */
    public function create(Request $request, string $workspaceUid, string $businessUid, string $locationUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $timezone = $this->timezoneFor($business);
        $search = (string) $request->query('q', '');

        return view('customer.business.calendar.create', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'timezone' => $timezone,
            'bookingTypes' => $this->bookingTypes->forLocation($location)->where('is_active', true)->values(),
            'staff' => $this->locations->eligibleStaff($workspace, $business, $location),
            'search' => $search,
            'contacts' => $this->schedule->searchBookableContacts($business, $location, $search),
            'prefillDate' => $this->anchorDate($request->query('date'), $timezone)->toDateString(),
            'prefillTime' => $this->validTime($request->query('time')) ?? '09:00',
        ]);
    }

    /**
     * Create through the canonical engine. `staff` is a user id for an explicit
     * assignment, or `auto` for round-robin; either way it is ONE call.
     */
    public function store(Request $request, string $workspaceUid, string $businessUid, string $locationUid): RedirectResponse|\Illuminate\Http\JsonResponse
    {
        [, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);

        $validated = $request->validate([
            'booking_type_uid' => ['required', 'string'],
            'contact_uid' => ['required', 'string'],
            'staff' => ['required', 'string'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            // Optional per-appointment meeting location; blank = the booking type's default.
            'meeting_location_type' => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Library\Calendar\Zoom\ZoomHostResolver::TYPES)],
            'meeting_location_custom' => ['nullable', 'string', 'max:500', 'required_if:meeting_location_type,custom'],
        ]);
        $meetingLocation = ($validated['meeting_location_type'] ?? null) === null ? null : [
            'type' => $validated['meeting_location_type'],
            'custom' => $validated['meeting_location_custom'] ?? null,
        ];

        // Existence and reach: a Booking Type of another Location, an inactive
        // one, or a Contact this Location may not book are all indistinguishable
        // from "not there" — 404, never a hint that the row exists.
        $bookingType = $this->bookingTypes->findForLocation($location, $validated['booking_type_uid']) ?? abort(404);
        abort_unless($bookingType->isActive(), 404);

        $contact = $this->schedule->findBookableContact($business, $location, $validated['contact_uid']) ?? abort(404);

        $startAt = $this->utcFromLocal($validated['date'], $validated['time'], $this->timezoneFor($business));
        $actorId = (int) Auth::id();

        try {
            $appointment = $validated['staff'] === 'auto'
                ? $this->booking->bookWithRoundRobin($bookingType, (int) $contact->id, $startAt, $actorId, null, $meetingLocation)
                : $this->booking->book($bookingType, $this->staffId($validated['staff']), (int) $contact->id, $startAt, $actorId, null, $meetingLocation);
        } catch (BookingRefusedException $refusal) {
            return $this->refused($refusal)->withInput();
        }

        $showUrl = route('customer.workspaces.businesses.calendar.appointments.show', [
            $workspaceUid, $businessUid, $locationUid, $appointment->uid,
        ]);

        // Home V2 action drawer: the SAME write, answered as JSON so the drawer can report
        // success only once the engine has confirmed the booking.
        if ($request->expectsJson()) {
            return response()->json(['status' => 'success', 'message' => 'Appointment booked.', 'url' => $showUrl], 201);
        }

        return redirect($showUrl)->with('flash_success', 'Appointment booked.');
    }

    public function showAppointment(string $workspaceUid, string $businessUid, string $locationUid, string $appointmentUid): View
    {
        [$workspace, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $appointment = $this->appointment($location, $appointmentUid);
        $timezone = $this->timezoneFor($business);

        return view('customer.business.calendar.appointment', [
            'workspace' => $workspace,
            'business' => $business,
            'location' => $location,
            'appointment' => $appointment,
            'timezone' => $timezone,
            'startLocal' => Carbon::parse($appointment->start_at)->utc()->setTimezone($timezone),
            'endLocal' => Carbon::parse($appointment->end_at)->utc()->setTimezone($timezone),
            'contact' => $this->schedule->contactSummaries($business, collect([$appointment]))[(int) $appointment->contact_id] ?? null,
            'staff' => $this->locations->eligibleStaff($workspace, $business, $location),
        ]);
    }

    public function reschedule(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $appointmentUid): RedirectResponse
    {
        [, $business, $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $appointment = $this->appointment($location, $appointmentUid);

        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            'staff' => ['nullable', 'integer'],
        ]);

        $newStart = $this->utcFromLocal($validated['date'], $validated['time'], $this->timezoneFor($business));

        try {
            $this->booking->reschedule(
                $appointment,
                $newStart,
                isset($validated['staff']) ? (int) $validated['staff'] : null,
                (int) Auth::id()
            );
        } catch (BookingRefusedException $refusal) {
            return $this->refused($refusal)->withInput();
        }

        return $this->backToAppointment($workspaceUid, $businessUid, $locationUid, $appointmentUid)
            ->with('flash_success', 'Appointment rescheduled.');
    }

    /** A safe, idempotent retry of a failed or stuck Zoom meeting. Re-queues the same reconcile job; it never creates a second meeting. */
    public function zoomRetry(string $workspaceUid, string $businessUid, string $locationUid, string $appointmentUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $appointment = $this->appointment($location, $appointmentUid);

        if (app(\App\Library\Calendar\Zoom\ZoomMeetingService::class)->resetForRetry((int) $appointment->id)) {
            \App\Jobs\Calendar\SyncZoomMeeting::dispatch((int) $appointment->id);

            return $this->backToAppointment($workspaceUid, $businessUid, $locationUid, $appointmentUid)
                ->with('flash_success', 'Retrying the Zoom meeting. The link appears here as soon as it is ready.');
        }

        return $this->backToAppointment($workspaceUid, $businessUid, $locationUid, $appointmentUid)
            ->with('flash_error', 'There is nothing to retry for this appointment.');
    }

    public function cancel(Request $request, string $workspaceUid, string $businessUid, string $locationUid, string $appointmentUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $appointment = $this->appointment($location, $appointmentUid);

        $validated = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);

        return $this->transition(
            fn () => $this->booking->cancel($appointment, (int) Auth::id(), $validated['reason'] ?? null),
            $workspaceUid, $businessUid, $locationUid, $appointmentUid,
            'Appointment cancelled.'
        );
    }

    public function complete(string $workspaceUid, string $businessUid, string $locationUid, string $appointmentUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $appointment = $this->appointment($location, $appointmentUid);

        return $this->transition(
            fn () => $this->booking->complete($appointment, (int) Auth::id()),
            $workspaceUid, $businessUid, $locationUid, $appointmentUid,
            'Appointment marked completed.'
        );
    }

    public function noShow(string $workspaceUid, string $businessUid, string $locationUid, string $appointmentUid): RedirectResponse
    {
        [, , $location] = $this->scope($workspaceUid, $businessUid, $locationUid);
        $appointment = $this->appointment($location, $appointmentUid);

        return $this->transition(
            fn () => $this->booking->markNoShow($appointment, (int) Auth::id()),
            $workspaceUid, $businessUid, $locationUid, $appointmentUid,
            'Appointment marked no-show.'
        );
    }

    // -----------------------------------------------------------------
    // Gates
    // -----------------------------------------------------------------

    /**
     * Gates 1 and 2: tenancy, then the Calendar entitlement decision.
     *
     * @return array{0: Workspace, 1: Business}
     */
    private function tenancy(string $workspaceUid, string $businessUid): array
    {
        return $this->resolveEntitledBusinessTenancy($workspaceUid, $businessUid, PlatformFeature::Calendar->value);
    }

    /**
     * Gates 1–3. A Location of another Business, or one this actor cannot
     * reach, is indistinguishable from one that does not exist.
     *
     * @return array{0: Workspace, 1: Business, 2: BusinessLocation}
     */
    private function scope(string $workspaceUid, string $businessUid, string $locationUid): array
    {
        [$workspace, $business] = $this->tenancy($workspaceUid, $businessUid);

        $location = $this->locations->resolveForActor($business, $locationUid, (int) Auth::id());

        if ($location === null) {
            abort(404);
        }

        return [$workspace, $business, $location];
    }

    /** Scoped to the Location: a guessed uid from a sibling Location does not resolve. */
    private function appointment(BusinessLocation $location, string $appointmentUid): Appointment
    {
        return $this->schedule->findAppointment($location, $appointmentUid) ?? abort(404);
    }

    // -----------------------------------------------------------------
    // Refusals — the engine's typed exceptions, surfaced, never retried
    // -----------------------------------------------------------------

    /**
     * Runs one lifecycle transition and surfaces the engine's refusal.
     *
     * A cancel/complete/no-show that loses to a concurrent change lands here as
     * InvalidAppointmentTransitionException having written nothing — that is
     * the engine working, and the message tells the user the appointment has
     * already moved on rather than pretending it succeeded.
     */
    private function transition(callable $mutation, string $workspaceUid, string $businessUid, string $locationUid, string $appointmentUid, string $success): RedirectResponse
    {
        try {
            $mutation();
        } catch (BookingRefusedException $refusal) {
            return $this->refused($refusal, $this->backToAppointment($workspaceUid, $businessUid, $locationUid, $appointmentUid));
        }

        return $this->backToAppointment($workspaceUid, $businessUid, $locationUid, $appointmentUid)->with('flash_success', $success);
    }

    private function refused(BookingRefusedException $refusal, ?RedirectResponse $to = null): RedirectResponse
    {
        // A JSON caller (the Home action drawer) gets the same plain-language refusal, with a
        // 422, instead of a redirect it cannot tell apart from success.
        if (request()->expectsJson()) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException(
                response()->json(['status' => 'error', 'message' => $this->refusalMessage($refusal)], 422)
            );
        }

        return ($to ?? back())->with('flash_error', $this->refusalMessage($refusal));
    }

    /**
     * Plain-language copy for each typed refusal. Deliberately not the
     * exception's own message, which names internal ids.
     */
    private function refusalMessage(BookingRefusedException $refusal): string
    {
        return match (true) {
            $refusal instanceof AppointmentSlotUnavailableException
                => 'That time is no longer free for this staff member — it may have just been booked. Pick another time.',
            $refusal instanceof StaffNotAvailableException
                => 'This staff member is not available then (outside their working hours, or on time off).',
            $refusal instanceof StaffNotEligibleForLocationException
                => 'This staff member can no longer be scheduled at this location.',
            $refusal instanceof NoEligibleStaffAvailableException
                => 'No eligible staff member is free at that time.',
            $refusal instanceof InvalidAppointmentTransitionException
                => 'This appointment has already changed — it may have been cancelled, completed or marked no-show. Reload to see its current state.',
            $refusal instanceof \App\Exceptions\Calendar\ZoomHostUnavailableException
                => 'This appointment is set to Zoom, but its host has not connected Zoom. Connect Zoom in Calendar → Settings → Integrations → Zoom, or choose another meeting location.',
            $refusal instanceof StaffBookingLockUnavailableException
                => 'The booking could not be completed just now. Nothing was changed; please try again.',
            default => 'The booking could not be completed. Nothing was changed.',
        };
    }

    // -----------------------------------------------------------------
    // Time handling — the Business's timezone is the one source of truth (§3.3)
    // -----------------------------------------------------------------

    private function timezoneFor(Business $business): string
    {
        return (string) ($business->timezone ?: config('app.timezone', 'UTC'));
    }

    private function isCalendarDate(mixed $date): bool
    {
        return is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    }

    private function anchorDate(mixed $date, string $timezone): Carbon
    {
        if ($this->isCalendarDate($date)) {
            try {
                return Carbon::createFromFormat('!Y-m-d', $date, $timezone)->startOfDay();
            } catch (\Throwable) {
                // fall through to today
            }
        }

        return Carbon::now($timezone)->startOfDay();
    }

    /**
     * The local wall-clock range a view covers, as half-open [from, to).
     *
     * Built with calendar arithmetic on local dates rather than fixed 24-hour
     * offsets, so a DST day is 23 or 25 hours long and the range still covers
     * exactly that local day.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rangeFor(string $view, Carbon $anchor, int $days = 7): array
    {
        if ($view === 'day') {
            return [$anchor->copy()->startOfDay(), $anchor->copy()->addDay()->startOfDay()];
        }

        // Whole-week spans (7 and 14 days) stay Monday-aligned, so the
        // classic week is unchanged. A span that is not a whole number of
        // weeks (10 days) starts on the anchor itself: aligning it to Monday
        // would make "previous"/"next" move by an unpredictable amount.
        $from = $days % 7 === 0
            ? $anchor->copy()->startOfWeek(self::WEEK_STARTS_ON)->startOfDay()
            : $anchor->copy()->startOfDay();

        return [$from, $from->copy()->addDays($days)->startOfDay()];
    }

    /** The anchor date one visible range earlier (-1) or later (1). */
    private function shift(Carbon $anchor, string $view, int $days, int $direction): string
    {
        return $anchor->copy()->addDays(($view === 'day' ? 1 : $days) * $direction)->toDateString();
    }

    /**
     * The date the "Today" control carries. A span that is not whole weeks
     * starts exactly on its anchor (see rangeFor()), so "Today" for it is the
     * start of the current week — otherwise the first view would open on a
     * Saturday and hide the days of this week that already happened.
     */
    private function todayAnchor(string $view, int $days, string $timezone): string
    {
        $today = Carbon::now($timezone)->startOfDay();

        return ($view === 'week' && $days % 7 !== 0
            ? $today->startOfWeek(self::WEEK_STARTS_ON)
            : $today)->toDateString();
    }

    /**
     * The date the "Day" control carries: the anchor, except that a
     * non-whole-week span's anchor is its first day, so "Day" prefers today
     * when today is inside the range being looked at.
     */
    private function dayAnchor(string $view, int $days, Carbon $anchor, Carbon $from, Carbon $to, string $timezone): string
    {
        $today = Carbon::now($timezone)->startOfDay();

        if ($view === 'week' && $days % 7 !== 0 && $today->greaterThanOrEqualTo($from) && $today->lessThan($to)) {
            return $today->toDateString();
        }

        return $anchor->toDateString();
    }

    private function visibleDays(mixed $days): int
    {
        $days = is_string($days) && ctype_digit($days) ? (int) $days : 7;

        return in_array($days, self::VISIBLE_DAY_OPTIONS, true) ? $days : 7;
    }

    private function utcFromLocal(string $date, string $time, string $timezone): CarbonInterface
    {
        return Carbon::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $timezone)->utc();
    }

    private function validTime(mixed $time): ?string
    {
        return is_string($time) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) === 1 ? $time : null;
    }

    /**
     * A staff id from the `staff` field. Anything non-numeric is a validation
     * failure rather than a silent 0 that the engine would then reject with a
     * confusing "not eligible" message.
     */
    private function staffId(string $value): int
    {
        if (! ctype_digit($value)) {
            throw ValidationException::withMessages(['staff' => 'Choose a staff member, or automatic assignment.']);
        }

        return (int) $value;
    }

    // -----------------------------------------------------------------
    // Presentation
    // -----------------------------------------------------------------

    /**
     * FullCalendar events for the visible range.
     *
     * Times are sent as the Business's LOCAL WALL-CLOCK, with no offset, and
     * the page runs FullCalendar with `timeZone: 'UTC'` so it displays them
     * verbatim. FullCalendar 5.7.2 (the vendored build) supports only 'local'
     * and 'UTC' without a timezone plugin, so this is the way to show a named
     * Business timezone correctly regardless of the viewer's browser timezone.
     *
     * @param  \Illuminate\Support\Collection<int, Appointment>  $appointments
     * @param  array<int, array{uid: string, name: ?string, phone: string}>  $contacts
     * @param  array<int, string>  $scope
     * @return list<array<string, mixed>>
     */
    private function eventsFor($appointments, array $contacts, string $timezone, array $scope): array
    {
        return $appointments->map(function (Appointment $appointment) use ($contacts, $timezone, $scope): array {
            $start = Carbon::parse($appointment->start_at)->utc()->setTimezone($timezone);
            $end = Carbon::parse($appointment->end_at)->utc()->setTimezone($timezone);
            $contact = $contacts[(int) $appointment->contact_id] ?? null;

            $who = $contact['name'] ?? $contact['phone'] ?? 'Customer';
            $type = $appointment->bookingType?->name ?? 'Appointment';

            return [
                'id' => $appointment->uid,
                'title' => $type . ' · ' . $who,
                'start' => $start->format('Y-m-d\TH:i:s'),
                'end' => $end->format('Y-m-d\TH:i:s'),
                'url' => route('customer.workspaces.businesses.calendar.appointments.show', array_merge($scope, [$appointment->uid])),
                'classNames' => ['calendar-status-' . $appointment->status->value],
                'extendedProps' => ['status' => $appointment->status->value],
            ];
        })->values()->all();
    }

    private function backToAppointment(string $workspaceUid, string $businessUid, string $locationUid, string $appointmentUid): RedirectResponse
    {
        return redirect()->route('customer.workspaces.businesses.calendar.appointments.show', [
            $workspaceUid, $businessUid, $locationUid, $appointmentUid,
        ]);
    }
}
