# Contract 15 — Booking Type settings V1

Lane `agent/booking-type-settings-v1`, stacked on `agent/public-booking-experience-v1`
(`5aae91d2`). It makes Booking Type scheduling configurable while reusing the
existing Calendar engine. It does not redesign the public page or replace any
Calendar service.

## Inventory (what already existed)

- `booking_types`: name, description, duration, colour, active, one Location,
  `public_booking_uuid`. No window, notice, buffer or interval columns anywhere
  (checked the model, manager, calculator, conflict detector, finder and views).
- Working hours: `staff_availability_rules` (weekly, per staff per Location,
  local time in the Business timezone) and `staff_time_off` (user-global UTC
  intervals). The Staff availability screen already edits both.
- Hard-coded in the public scheduler: 30 days ahead, starts every 30 minutes,
  start must be after now.

## Schema (one migration, no new table)

`2026_10_27_090001_add_scheduling_settings_to_booking_types_table`

| Column | Type | Default |
|---|---|---|
| `booking_window_days` | smallint unsigned | 30 |
| `minimum_notice_minutes` | int unsigned | 0 |
| `buffer_before_minutes` | smallint unsigned | 0 |
| `buffer_after_minutes` | smallint unsigned | 0 |
| `slot_interval_minutes` | smallint unsigned | 30 |
| `meeting_instructions` | text, nullable | null |

Every default equals the old behaviour, so existing Booking Types are unchanged.
`BookingType::windowDays()` etc. fall back to the same defaults for a model that
has not re-read its row.

## Settings and where they are enforced

| Setting | Meaning | Enforced in |
|---|---|---|
| Booking window | Rolling: today through today + N Business days (7/14/30/60/90 presets, 1–365 accepted) | `PublicSlotFinder` (dates, slots, page) and `store` |
| Minimum notice | A start must be strictly after now and not before now + notice (none/1/2/4/12/24/48 h presets) | `PublicSlotFinder`, `store` (UTC arithmetic, server only) |
| Slot interval | Start times every 15/30/45/60 minutes counted from local midnight; independent of duration | `PublicSlotFinder`, `store` (`onGrid`) |
| Buffer before / after | The NEW booking occupies `[start − before, end + after)` | `BookingConflictDetector`, therefore the engine (book, round-robin, reschedule) and the finder |
| Meeting instructions | Free text shown on the booking page and confirmation | views |

Fixed date ranges are not supported (a rolling window is what the engine
expresses cleanly); a later enhancement.

### Buffers in detail

- The Appointment keeps its real `start_at`/`end_at`; no longer duration is
  stored. Timestamps stay UTC.
- Conflicts are checked against the buffered interval for internal appointments
  and external-calendar busy blocks.
- An existing appointment occupies its own interval widened by ITS Booking
  Type's buffers (join on `booking_types`), so a neighbour's buffer protects it
  too even when the new booking has none.
- Working-hours and time-off checks use the real interval only: a buffer may
  extend past the end of a working window.
- `AppointmentBookingService` reads the buffers from the row it locked for the
  booking (tier 0), not the caller's possibly stale model. Internal staff
  bookings obey buffers as well (one rule everywhere); notice and window apply
  to the public scheduler only, so staff can still book a walk-in.

### Timezone

The Business timezone owns the grid, the window and the stored appointment; the
visitor timezone only affects labelling and which visitor day a slot is on
(unchanged). Notice is `now()` in UTC plus minutes, so it cannot depend on the
visitor's zone. Nothing local is written to storage.

## Readiness and fail-closed behaviour

`BookingTypeManager::publicBookingReadiness()` reports why a type is not
bookable: inactive, no one assigned, assigned staff have no working hours, or
invalid scheduling settings (`BookingType::schedulingProblem()`). The public
routes 404 for the same conditions (no staff, inactive, invalid settings), as
before for the first two. The Booking Types list shows "Not bookable yet" with
the reason; the editor shows a warning and hides Copy link / Open when not ready.

## Editor

Cards: Basic information (name, description, location and meeting
instructions, colour) · Scheduling (duration, start times, minimum notice,
booking window) · Buffers (before, after) · Public booking page (readiness,
URL, Copy link, Open) · Status · Team. The Team card states that availability
comes from assigned staff working hours and links to Staff availability; it
lists the assigned people and warns when there are none. The create form uses
the same fields. Preset lists also show a stored value that is not a preset.
Updates only change settings that were submitted, so an older caller never
resets them.

## Date overrides

Staff time off (user-global, UTC) already works as the "blocked dates" mechanism
and is in the same Staff availability screen. There is no per-date extra-hours
override; that would be a new availability subsystem and is deferred.

## Tests

`tests/Feature/Calendar/BookingTypeSettingsTest.php` (22 tests): defaults,
7/14/30/60-day windows, notice (incl. Tokyo visitor), buffer before, buffer
after, a neighbour's own buffers, real appointment duration, external busy +
buffer, double-booking through a buffered neighbour, 15- and 45-minute
intervals, off-grid starts, fail-closed readiness, the editor, partial updates,
validation, forged staff/location ids, UTC storage and a booking race.
