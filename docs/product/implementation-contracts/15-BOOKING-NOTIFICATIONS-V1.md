# Contract 15 — Booking Confirmations & Reminders V1

Status: implemented on `agent/booking-notifications-v1` (base `686341e1`).
Related: `15-PUBLIC-BOOKING-EXPERIENCE-V1.md`, `15-BOOKING-TYPE-SETTINGS-V1.md`,
`15-CALENDAR-V1-COMPLETION.md` (§ events), `docs/automation/BUSINESS-EMAIL-FOUNDATION-CONTRACT.md`.

## 1. Authority

**The Calendar owns the built-in transactional confirmation and reminders.** They are
not an Automation recipe, and no Automation produces them. Reasons: Business
workflows are not server-seeded (recipes are client-side starter documents the owner
must publish); the Wait step cannot express "N hours before the start" and does not
move on a reschedule; there is no reminder trigger; Automation email needs a
connected mailbox.

Business Automations keep reacting to the same `AppointmentScheduled / Rescheduled /
Cancelled` events for OPTIONAL workflows. The Calendar listener
(`KeepAppointmentNotificationsInStep`) sits beside `EnrollFromAppointmentEvent`, never
instead of it. An owner who also builds an Automation that texts on
`appointment_scheduled` will send a second, *optional* message — that is their
Automation, clearly separate from the built-in one.

## 2. Model

`appointment_notifications` — one row per (appointment, channel, occurrence):

| Column | Meaning |
|---|---|
| `kind` | `confirmation` \| `reminder` |
| `channel` | `email` \| `sms` |
| `occurrence_key` | `confirmation`, or `reminder:{offsetMinutes}:{startUtc YmdHi}` |
| `appointment_start_at` | the start the row is bound to (a reschedule leaves old rows pointing at the old start) |
| `scheduled_for` | UTC moment it is due |
| `recipient_email`, `recipient_phone`, `sms_consent_at` | **booking-time snapshot** |
| `status` / `reason` | `pending → sending → sent`, or `skipped` / `failed` / `cancelled` with a reason code |
| `dispatched_at`, `attempted_at`, `sent_at` | queueing throttle, claim time, delivery time |

`UNIQUE(appointment_id, channel, occurrence_key)` is the idempotency guarantee.

Booking Type settings (`booking_types`): `notify_email` (default on), `notify_sms`
(default off — texting needs a ready sender and the guest's consent), `reminder_offsets`
(JSON minutes-before; NULL = defaults 24 h + 2 h, `[]` = no reminders).

## 3. Flow

1. `PublicBookingController::store` books through the canonical engine, then calls
   `AppointmentNotificationScheduler::recordPublicBooking` (best-effort: a failure is
   reported and never fails the booking; the sweep recovers any lost confirmation job).
2. The scheduler writes one confirmation row and one reminder row per offset, per enabled
   channel. A reminder whose moment has passed is stored `skipped / past_due`, never sent
   late. A channel with no address or no consent is stored `skipped` with the reason.
3. `SendAppointmentNotification` (after-commit job, ledger id only) →
   `AppointmentNotificationSender::deliver`.
4. `calendar:dispatch-due-reminders` (every minute) queues due pending rows. It is bounded
   (`--limit`), overlap-safe, and re-queues a row whose job was lost (>10 min).
5. `AppointmentCancelled` → pending rows cancelled. `AppointmentRescheduled` → pending
   reminders bound to the old start cancelled (`rescheduled`), new reminders created for
   the new start for every (channel, offset) the appointment owed (including an offset
   already sent for the old time). Moving back to an earlier start re-opens its cancelled rows.

## 4. Idempotency and safety

- **Insert:** `INSERT IGNORE` on the unique occurrence: booking replay, redelivered event
  and re-run scheduler create nothing twice.
- **Send:** `UPDATE … SET status='sending' WHERE status='pending'` is the claim; only the
  winner proceeds. A retried job, second worker or re-run sweep is a no-op. A crash after
  the claim leaves `sending` (outcome unknown) and is never re-sent; `failed()` marks it.
- **SMS:** deterministic `managed_operation_key = calendar:appt:{id}:sms:{occurrence_key}`
  also dedupes at the managed dispatcher.
- **Re-checked at send time** (the listener is a convenience, not the safety): appointment
  still `scheduled`; reminder still bound to the appointment's current start; appointment
  not yet started; Business still active; SMS: Contact still `subscribed`, booking-time
  consent present, canonical path ready.

## 5. Channels

- **Email:** Platform transactional mail (same transport as Documents and receipts) to the
  frozen `recipient_email`. From address is the platform's; From display name is the
  Business. No Reply-To (a Business has no canonical customer-facing email yet). Contains
  Business, Booking Type, date, start–end, timezone, location, meeting instructions, a Google
  Calendar link and an `.ics` attachment (stable UID = appointment uid, `SEQUENCE` = reschedule
  count). **No reschedule/cancel links**: no customer-facing action exists.
- **SMS:** `CampaignRepository::checkQuickSendValidation` + `quickSend` through
  `BusinessSmsSendingPath::resolveForLocation` — the Location-aware rule lifted out of
  `AutomationSmsDispatcher` (which now delegates to it) so there is one copy. Only to a
  subscribed Contact with a phone and the booking-time **transactional** consent.

## 6. Consent

The public form shows an unchecked box — "Text me the confirmation and reminders for this
appointment … not marketing consent" — only when `notify_sms` is on **and** the Business
could actually text for that Location. Ticking it sets `sms_consent_at` on the SMS rows. It
never subscribes anyone: an existing Contact's status is untouched, and an opt-out before a
reminder stops that reminder. Consent submitted for a channel that was not offered is ignored.

## 7. Timezone

Appointments are UTC. Offsets are exact elapsed time before the start instant; message text
renders in the Business timezone with the zone named (`America/New York (EDT)`), correct
across DST.

## 8. Readiness

`BookingNotificationReadiness` answers with the same authority the sender uses. The Booking
Type editor's **Customer notifications** card shows "not ready" reasons; a booking always
succeeds, and the ledger records the skip reason.

## 9. Returning-Contact email

The confirmation goes to the email typed for this booking (snapshot). The Contact's stored
`EMAIL` is not overwritten (existing find-or-create matching is unchanged), and later Contact
edits never rewrite a scheduled reminder's recipient.

## 10. Deferred (post-V1)

- **Quiet hours** (none exist in the product). Reminders send at their scheduled moment.
- Customer reschedule/cancel links (need a hashed-token public route).
- Staff-created appointments: V1 confirms and reminds **public bookings** only (staff
  bookings capture no guest-entered email/phone/consent snapshot).
- A notice to the customer when staff reschedule or cancel.
- **Owner internal notification:** the Activity Center reader is Documents-specific, so a
  Calendar item would need a new reader — not a clean fit. Not built.
- Business-level default reminder configuration (Booking Type only in V1).
- Email suppression ledger (none exists platform-wide).
