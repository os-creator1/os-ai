# V1 Final Acceptance 03 — Calendar / Booking

Branch `agent/v1-acceptance-03-calendar-booking`, from `origin/main`
`44976df8d809e45b4a552c9375c8524eac7cfe0e`. Database used for every run:
`ultimatesms_testing_v1_acceptance_calendar` (disposable sibling accepted by
`TestDatabaseSafety`). No live Google/Microsoft call anywhere; the repository's
`FakeCalendarProviderClient` is bound over both provider clients.

**Method.** Closure pass. Existing suites were reused as evidence
(`tests/Feature/Calendar`, 348 tests, green at this main before any change).
Because every existing Calendar HTTP test pushes the test-only
`SeedCalendarEntitlementSnapshot` shim, the new journeys in
`tests/Feature/V1Acceptance/CalendarBooking/` deliberately run **without** it:
real Core plan, real `EntitlementManager`, real tenancy chain, real
`LocationAccessGuard`, real customer shell. 46 tests / 666 assertions.

No production code was changed: no defect was found.

## Matrix

| Requirement | Result | Evidence | Notes |
|---|---|---|---|
| A. Booking type created via real UI/route, Location bound, duration persists | ACCEPTED | `BookingTypeJourneyTest` (create/edit/list round trip; duration drives public slot sizing) | |
| A. Foreign Business/Location cannot be injected | ACCEPTED | `BookingTypeJourneyTest::test_a_foreign_business_or_location_can_never_be_injected`, sibling-route test | Body fields `business_location_id`/`business_id`/`created_by_user_id` ignored; foreign coordinates 404 |
| A. Deactivate/reactivate | ACCEPTED | `test_deactivate_and_reactivate_follow_the_domain_rules` | Inactive: public 404, staff form 404, existing appointments still reschedulable (contract: creation only); same public URL after reactivation |
| A. Staff pool: no foreign/stale member | ACCEPTED | `test_staff_pool_nominations_are_re_derived…` | Stale row flagged, never authority |
| B. Owner configures availability; Location-scoped | ACCEPTED | `StaffAvailabilityJourneyTest` | Rules at A grant nothing at B; rule id from A not removable via B |
| B. Selected-location staff cannot reach/write foreign Location; staff self-only; no foreign/stale/wrong-location target | ACCEPTED | same file | See note 1 on status code |
| B. Time off user-global | ACCEPTED | `test_time_off_is_user_global…` | |
| C. Public page reachable, correct type, unavailable slots hidden | ACCEPTED | `PublicSchedulerJourneyTest` | See note 2 |
| C. Exactly one appointment, correct Business/Location/Contact, one Scheduled event | ACCEPTED | `test_a_guest_booking_creates_exactly_one…` | |
| C. Replay / double submit / stale page | ACCEPTED | `test_replay_and_double_submit…`, `test_a_stale_page…` | Refused attempts leave no appointment, Contact or event |
| C. Invalid / expired / foreign identifiers | ACCEPTED | `test_invalid_expired_and_malformed…`, `test_unknown_foreign_and_unservable…`, inactive Business/Workspace test | All unservable states render one identical 404 |
| D. Same slot / overlap / back-to-back, public and staff UI | ACCEPTED | `DoubleBookingJourneyTest` | |
| D. Same staff across Locations | ACCEPTED | `test_the_same_staff_member_is_never_double_booked_across_locations` | Both directions, public and staff UI |
| D. Concurrency under real locks | ACCEPTED (existing evidence) | `Calendar/AppointmentBookingConcurrencyTest` (real two-process races incl. public-contact bookings), green in the 348-test baseline | No new multi-process HTTP harness written, per the closure-pass credit rule; lock semantics untouched |
| E. Connection state respected; busy blocks booking at every Location | ACCEPTED | `ExternalCalendarJourneyTest` (Google + Outlook data-provider run) | Pending/disconnected/revoked rows inert; active blocks that user only |
| E. Sync token / delta / cursor-invalid fallback / outage | ACCEPTED | same file | Full → incremental, tombstone, idempotent replay, moved event, one fallback full read, outage keeps last good cache |
| E. Stale / disconnected / revoked / reconnect | ACCEPTED | same file | Revoked purges blocks, UI offers connect again, reconnect works. See note 3 |
| E. No token/secret in UI | ACCEPTED | same file | Page and public page asserted free of tokens, code, nonce, provider event ids; credential encrypted at rest |
| F. Schedule / view / reschedule / cancel / complete / no-show | ACCEPTED | `LifecycleAuthorityJourneyTest::test_full_lifecycle…` | Each event exactly once; repeated terminal action emits nothing |
| F. Location stable; Contact/Opportunity links | ACCEPTED | same | Opportunity can only be set through the engine (no UI path exists); preserved through reschedule/cancel |
| F. Reschedule occurrence identity deterministic | ACCEPTED | same | `appointment_rescheduled:{id}:1`, `:2` |
| G. Location authority by URL/AJAX route; picker does not widen; owner reaches all | ACCEPTED | `test_location_authority_by_url…` | Calendar has no persisted Location switcher: the Location is always explicit in the URL and the picker lists only accessible Locations |
| H. Contact creation/reuse | ACCEPTED | `ContactLinkageJourneyTest` | Identity = phone within the booked Location (contract §5.8): reused across phone formats and for CRM-known Contacts; sibling Location = separate Contact by contract; never cross-Business; location-less legacy Contact is not captured |
| I. Canonical events and occurrence keys | ACCEPTED | `LifecycleAuthorityJourneyTest` | `appointment_scheduled/cancelled/rescheduled` emitted with Business+Location; Automations untouched |
| J. Nav / entry points / empty states | ACCEPTED | `test_nav_reachability…`, booking-type and availability empty states, public link on Booking Types list | Mobile/responsive: not exercised (no cheap harness) — DEFERRED, non-blocking |
| K. Foreign ids fail closed; staff ACL; Platform/customer boundary | ACCEPTED | `LifecycleAuthorityJourneyTest`, `BookingTypeJourneyTest`, `ExternalCalendarJourneyTest` | |
| K. View As has no credential-management authority | ACCEPTED (by construction) | `ExternalCalendarConnectionController` resolves only `Auth::id()`'s own connection; `test_the_connection_surface_is_per_user…` proves another user sees and affects only their own | No dedicated View-As session test was written (credit rule); the surface has no client-targeting parameter to abuse |

## Notes and deferred items (none blocking V1)

1. `StaffAvailabilityController` documents the authority refusal as 403, but the
   app-wide handler renders every `AuthorizationException` as **401**. Refusal and
   no-write are proven; the status is the application's existing convention.
2. The public booking page names the booking type, duration and timezone but does
   not display the Location name. Binding is by the type's public UUID and is
   correct. DEFERRED (new copy, not a defect).
3. The connection page shows "Connected" for an active connection whose syncs are
   failing; there is no stale-sync warning. Safe (last good cache holds, bookings
   unaffected). DEFERRED (new feature).
4. Mobile/responsive check not run. DEFERRED.

## Baseline

`tests/Feature/Calendar` at `44976df8`: 348 tests, 1,536 assertions, 0 failures.
No failure occurred anywhere, so no pristine-main comparison was needed.

V1 CALENDAR/BOOKING ACCEPTANCE: ACCEPTED
