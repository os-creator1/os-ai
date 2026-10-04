# Contract 15 — Calendar V1 completion notes

Branch `agent/calendar-booking-v1-completion`, from main
`3692660f5a649c30235719853e769023e9f42646`. This is an acceptance pass over the
Sub-slice A–F implementation described in
`15-CALENDAR-BOOKING-AVAILABILITY.md`; nothing here is a redesign.

## Defects found and fixed

| Area | Defect | Fix |
|---|---|---|
| Public scheduler | The slot list advanced by accumulated minutes while the POST resolved the wall-clock label. On a spring-forward day it offered a non-existent `02:30` that silently booked `03:30`; on a fall-back day it offered `01:00`/`01:30` twice and the second (later) instant could never be booked. | `PublicBookingController::instantFor()` is the single label → instant resolver for the page and the POST. A label that names no instant (skipped hour) or two (repeated hour) is neither offered nor accepted, so the instant the guest saw is the instant that is booked. |
| Public scheduler / availability | A Business whose stored timezone is not a real zone caused a 500. | The public routes refuse with the same indistinguishable 404 as every other authority failure; `StaffAvailabilityCalculator` answers "not available". |
| Booking engine | A stale Booking Type model could still create an appointment after the owner deactivated the type (the public page checked `is_active` only before the engine ran). | The `booking_types` row is now **tier 0** of the Calendar lock order (contract §7.1/§7.4). Every new booking — explicit-staff and round-robin — takes it `FOR SHARE` first in its transaction and reads `is_active`, `duration_minutes` and Location from the locked row; `BookingTypeManager::setActive()`/`update()` take it `FOR UPDATE`. A deactivation that commits first makes the waiting booking refuse with `BookingTypeNotBookableException` (a `BookingRefusedException`, so the public adapter reports "no longer available"); a booking that owns the row first finishes before the deactivation proceeds. An earlier revision re-read `is_active` after the staff lock, which did not serialize against the write (correction round 1). Creation only; existing appointments of an inactive type remain reschedulable and resolvable. |
| External sync | A sync whose provider read was in flight while the user disconnected (or the token was revoked) wrote its busy blocks and cursor back onto the ended connection. | `ExternalCalendarSyncService::applyPage()` re-checks the connection is `Active` after taking the tier-2 lock — the same lock `endConnection()` holds — and discards the page otherwise. |
| External refresh | A refresh exchange racing a disconnect could write a rotated refresh token back onto a disconnected row, undoing "disconnect destroys credentials". | `ExternalCalendarConnectionManager::accessTokenFor()` writes only while the row is `Active` and throws `invalidGrant` otherwise. |
| Appointment Location | Nothing at the model layer stopped an appointment's Location being rewritten. | `Appointment::booted()` refuses an update that dirties `business_location_id`. |
| Events | Only `AppointmentScheduled` carried a Location; the other four carried no Business or Location. | All five now carry `businessId` and `businessLocationId` (see below). |

## Lifecycle events — the seam for the later Automations lane

Namespace `App\Events\Calendar`. All implement `ShouldDispatchAfterCommit`, are
dispatched exactly once per committed transition and never for a refused or
rolled-back one. Numeric ids and UTC `Y-m-d H:i:s` strings only, no PII.

| Event | Payload (constructor order) |
|---|---|
| `AppointmentScheduled` | `appointmentId, businessId, businessLocationId, bookingTypeId, staffUserId, contactId, crmOpportunityId?, startAt, endAt, createdByUserId?` |
| `AppointmentRescheduled` | `appointmentId, businessId, businessLocationId, previousStaffUserId, newStaffUserId, previousStartAt, previousEndAt, newStartAt, newEndAt, rescheduledByUserId?` |
| `AppointmentCancelled` | `appointmentId, businessId, businessLocationId, staffUserId, cancelledByUserId?, reason?` |
| `AppointmentCompleted` | `appointmentId, businessId, businessLocationId, staffUserId, completedByUserId?` |
| `AppointmentNoShow` | `appointmentId, businessId, businessLocationId, staffUserId, markedByUserId?` |

`createdByUserId` is null for public self-booking. The events are a
notification mechanism, **not** an audit trail (contract §10): V1 persists no
appointment history, so a consumer that needs the previous interval or staff
must take it from `AppointmentRescheduled`.

Consumer rules: listen to these classes (they are plain events, so a listener
runs after the booking transaction has committed); scope by `businessId` /
`businessLocationId`; treat `appointmentId` as the idempotency key together with
the event class. This lane does **not** touch `WorkflowTriggerType`,
`WorkflowNodeType`, `NodeTypeRegistry` or the Automation builder/compiler/runtime.

## Verified-as-already-correct (covered by new acceptance tests)

* External busy blocks and native appointments share one conflict detector
  (`BookingConflictDetector`), and the booking path and the sync write path take
  the same `staff_booking_locks` tier-2 lock before they read/write
  (`ExternalCalendarV1AcceptanceTest`).
* A busy block committed while a booking waits on the staff lock refuses that
  booking (`AppointmentBookingConcurrencyTest`, real second process).
* A webhook is only a signal: a forged proof pulls nothing, and event data in a
  notification body is never written; only the authenticated pull creates a
  block.
* OAuth scopes are the read-only minimum; a rotated refresh token is stored
  encrypted; a revoked grant revokes the connection and purges its blocks; one
  user's connection is invisible to and unaffected by another user.

## Test database

All DB-writing runs used the disposable sibling `ultimatesms_testing_calendar_v1`
(accepted by `Tests\Support\TestDatabaseSafety`), sequentially.

## Calendar polish: grid, width and in-page tab switching

A presentation-only pass over the existing Calendar screen. No route, controller
authorization, booking rule, availability calculation, external sync, automation
event or domain model changed.

* **Why the grid looked empty.** The vendored FullCalendar 5.7.2 script does not
  inject its own CSS, and its stylesheet
  (`public/vendors/css/calendars/fullcalendar.min.css`) was never linked. Without
  it the grid had no table layout, no borders and collapsed day columns. The
  schedule now links that stylesheet (and loads it on demand when the schedule is
  reached from another tab), and the line colour is `--color-border` instead of
  the near-white `--color-border-subtle`. Hour lines are solid, half-hour lines
  dotted and fainter, day separators explicit.
* **Visible days.** The week view takes `?days=7|10|14` (anything else is 7; the
  parameter is omitted from URLs when it is 7). The browser chooses the count from
  the grid's own width, never the viewport's: each day column keeps at least
  150 px, so 7 days up to a ~1550 px grid, 10 days from ~1550 px and 14 days from
  ~2160 px; a grid too narrow for 110 px columns scrolls sideways instead of
  squeezing. Whole-week spans (7, 14) stay Monday-aligned; a 10-day span starts on
  its date, and its "Today" control and its no-date default are the start of the
  current week. Previous/next move by the visible span.
* **Tab switching.** Calendar view, Booking types and Staff availability still
  have their own authorized routes and URLs. A plain click fetches the destination
  with `?fragment=1`, which the same controller action answers with only
  `#calendar-content` (layouts `_frame` / `_fragment`), and swaps it in; the
  shell, title and tab strip are never replaced. The real URL is pushed with
  `history.pushState`, Back/Forward re-fetch the section, and any failure (error,
  redirect, no script) falls back to ordinary navigation. Each navigation aborts
  the previous request and discards any response that is no longer the latest, so
  the last click always wins. The schedule's own Today/previous/next/Day/Week
  links use the same mechanism. FullCalendar is created/destroyed per swap; the
  events ride along as inert `application/json`.
* **Verification.** `CalendarSectionNavigationTest` (server contract), plus a real
  browser pass at 1024, 1440, 1920 and 2560 px (live swap, rapid clicks with a
  deliberately late response, Back/Forward, direct URLs, lazy FullCalendar load).
  Test database: the disposable `ultimatesms_testing_calendar_polish`.
