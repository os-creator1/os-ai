# Contract 15 — Public booking experience V1

Lane `agent/public-booking-experience-v1`. This is the customer-facing front of
the Calendar. It is **not** a Calendar backend change: availability, conflict
detection, locking, round-robin, the Appointment model and the
`AppointmentScheduled` automation event are exactly the ones Contract 15 already
defines. This lane adds a scheduler UI, two read-only JSON questions it asks
while a guest browses, and an AJAX form of the existing booking POST.

## What a guest sees

`/book/{public_booking_uuid}` is one centred card, with no Business OS shell.

- **Left panel** — the Business's initial (there is no Business logo in the
  product, so none is invented), Business name, the staff member's name only
  when exactly one person serves the Booking Type, Booking Type name,
  description, duration, where (address only if the Location's address is
  public; "Online" for online Locations; otherwise the Location name) and the
  time zone the times are shown in.
- **Right panel** — "Select a date & time", a month calendar (previous/next,
  open days selectable, closed days disabled, today marked, selection obvious),
  a time zone picker, then the times for the chosen day.
- **Steps** — date → time → details → confirmation, in one card with no page
  loads. Back keeps the month, the date and the chosen time. On a phone the
  Business information stacks above the scheduler and, once a date is chosen,
  the list of times replaces the calendar (with a "Choose another date" button).
- **Without JavaScript** the page still works: `?date=YYYY-MM-DD` renders that
  day's times and the details form as plain HTML, and the form posts normally.

## Public routes

| Route | Purpose | Throttle |
|---|---|---|
| `GET /book/{uuid}` | The page (`?date=` renders that day server-side) | none (as before) |
| `GET /book/{uuid}/dates?month=YYYY-MM&tz=` | Open dates in a month | 60/min |
| `GET /book/{uuid}/slots?date=YYYY-MM-DD&tz=` | Offered times on a day | 60/min |
| `POST /book/{uuid}` | Book. Plain form: redirect to `/confirmed`. `X-Requested-With: XMLHttpRequest`: JSON (201 booked, 409 slot taken, 422 invalid) | 10/min |
| `GET /book/{uuid}/confirmed` | Plain-form confirmation page (shows the booking summary from the flashed session when present) | none |

Every route re-runs the six-check authority stack in `resolve()` and answers
every refusal with the same 404. The JSON responses are `Cache-Control: no-store`.

## Availability source

`App\Library\Calendar\PublicSlotFinder` asks the **canonical**
`StaffAvailabilityCalculator` (working hours, time off, the Business timezone)
and `BookingConflictDetector` (internal appointments across all Locations plus
external-calendar busy blocks) for each candidate start. Nothing is computed in
the browser. The only shortcut is skipping weekdays on which no eligible staff
member has any availability rule; every remaining candidate is still checked by
the canonical pair, and `AppointmentBookingService` re-verifies under lock when
the guest confirms.

The month endpoint stops at the first offered slot of each day rather than
listing every slot of the month.

Offer rules (unchanged from the previous scheduler): starts on the half hour in
the Business timezone, from now through today + 30 days (Business date), never
in the past, never a wall-clock time that does not exist or occurs twice on a
DST day.

## Timezones

- The **Business** timezone owns the grid, the window and the stored
  appointment. The guest POSTs a Business-local `date` + `time`; the instant is
  resolved by `PublicSlotFinder::instantFor()` (moved verbatim from the
  controller) and booked in UTC like every other Calendar write.
- The **visitor** timezone is a display hint only. The browser reports its zone
  (`Intl`), the guest can change it, and the server (not the browser) converts:
  it returns, per slot, the visitor-local label plus the Business-local
  `date`/`time` to book with. A visitor day can therefore contain slots from two
  Business days (and the reverse). An unknown `tz` falls back to the Business
  timezone. A guest-supplied zone can never move a slot.

## Storage requirement (existing, now documented)

Calendar timestamps (`appointments.start_at`/`end_at`) are stored as UTC clock
values and read back with `Carbon::parse(...)->utc()`. That is only correct when
`APP_TIMEZONE=UTC`. A deployment with any other `APP_TIMEZONE` shows internal
Calendar times shifted by the offset (found when a dev `.env` with
`America/New_York` showed a 10:00 CDT booking at 14:00). This lane does not
change it; set `APP_TIMEZONE=UTC`.

## Contact details

First name, last name, **email** (new, required) and phone. Phone remains
required because Contacts are identified by phone at a Location. The email is
stored on the Contact as the standard `EMAIL` custom field. A Contact Group's
default fields are only phone and names, so the booking transaction adds the
`EMAIL` field to the Business's group (group row locked first) when missing.
Custom booking questions are not built here; when they exist they belong to the
shared custom-field/form system.

## Confirmation

Booking Type, Business, date, time range, timezone, duration and where, in the
visitor's timezone. "Add to Google Calendar" is a plain link and "Download .ics"
is built in the browser from the same summary; there is no new server
infrastructure. No confirmation email or SMS is sent by this lane (none exists
in the booking path), so the page does not claim one.

## Branding

Only what exists: the Business name, the Booking Type name, and the Booking
Type's `color` as the accent when it is a plain `#rrggbb` (anything else is
ignored, never echoed). Default accent and canvas are the design-system tokens.
Agency white-label settings are client-chrome only and are not applied.

## Booking Type settings

Superseded by `15-BOOKING-TYPE-SETTINGS-V1.md`: window, notice, buffers and start-time
interval are now Booking Type settings (this lane shipped with fixed values: 30 days,
no notice or buffers, 30-minute starts).

The 404 cause fixed: a Booking Type that is active but has no eligible staff has
no public page by design (`eligibleStaffIds() === []` → 404), yet the list
offered its link. `BookingTypeManager::publicBookingReadiness()` now says
whether the link will work and why not (inactive / no one assigned / no working
hours); the list shows "Not bookable yet" with the reason, and the Edit page has
a "Public booking page" card.

## Concurrency

Unchanged and reused: Booking Type row (tier 0), round-robin state (tier 1) and
per-staff locks (tier 2) inside one transaction, with availability and conflict
re-checked per candidate under lock. A losing request gets 409 (AJAX) or a
validation error (form) and leaves no appointment and no Contact. The client
additionally ignores stale responses (latest request wins) and locks the submit
button while a booking is in flight.

## Tests

`tests/Feature/Calendar/PublicBookingExperienceTest.php` (23 tests) plus the
existing public-booking suites (updated for the required email). The
`blade_comments_never_mention…` test guards the template defect behind the
Calendar footer leak: a Blade comment that contains the literal verbatim or php
directive makes Blade pair it with a later closer and print the comment and the
script as page text.
