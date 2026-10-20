# Book a Demo (`/book-demo`)

`/book-demo` is MotionGrove's own "Book a Demo" destination. It is **not** a
second calendar: it is an adapter onto the canonical public booking engine
(`PublicBookingController`, `PublicSlotFinder`, `AppointmentBookingService`)
pointed at **one designated Booking Type** of an internal Business. Availability,
notice, buffers, time off, staff eligibility, double-booking locks, timezones,
confirmation/reminder notifications and lead attribution are all inherited.

The marketing website links to `/book-demo`. Which button points where (native
page vs. an external fallback such as Calendly) is the marketing-website
settings' concern, not this feature's.

## What the visitor sees

Pick a date and time (in their own timezone), then enter first and last name,
email, business name, an optional website and an optional phone number. There is
no account. The confirmation email carries a signed link to reschedule or cancel.

## Internal Business and tenancy

The internal Business is an ordinary tenant (Workspace -> Business -> Location),
so everything the booking creates (the appointment, the CRM Contact, the
notifications, the attribution rows) belongs to it and nothing else. Agency
customers are not members of it and see none of it. `/book-demo` adds no login,
role or admin route and grants no platform-owner authority to anyone.

A platform administrator account (`users.is_admin`, `is_customer = false`) has no
customer profile and so cannot own a Business. The internal Workspace is therefore
owned by a **customer login you operate** (it also acts as the demo host).

```bash
php artisan demo-booking:provision --inspect
php artisan demo-booking:provision --owner-email=you@example.com \
    --timezone=America/New_York --confirm-database=<connected db name>
```

`--inspect` changes nothing. When `APP_ENV=production` the run also needs `--production`. The provisioning run reuses an existing
`MotionGrove Internal` Workspace/Business/Location/`MotionGrove Demo` Booking Type
and creates only what is missing; it refuses a Workspace of that name owned by
someone else. A re-run never re-activates a deactivated Business or overwrites the demo type's hosts. It assigns a complimentary plan (no billing) so the Calendar
entitlement is allowed, and prints the value for `DEMO_BOOKING_TYPE_UUID`.

It sets **no working hours**. The host adds weekly availability, time off and
buffers in the Business's normal Calendar settings; duration, notice, booking
window, buffers and reminders are the Booking Type's normal settings. Until
availability exists `/book-demo` offers no times.

## Configuration

| Variable | Purpose |
| --- | --- |
| `DEMO_BOOKING_TYPE_UUID` | `public_booking_uuid` of the demo Booking Type. Unset or invalid: every `/book-demo` route is a 404. |
| `TURNSTILE_SITE_KEY` | Cloudflare Turnstile **site** key. Public; rendered in the page. |
| `TURNSTILE_SECRET_KEY` | Cloudflare Turnstile **secret** key. Server only; never rendered or logged. |
| `TURNSTILE_ALLOWED_HOSTNAMES` | Optional, comma-separated. Hostnames Cloudflare may report for a token. Blank: the request host must match. |

Turnstile setup: Cloudflare dashboard -> Turnstile -> add a widget for the
marketing/app hostname (managed mode) -> copy the site and secret keys into the
environment, then `php artisan config:clear`.

**Fails closed.** Without both Turnstile keys every `/book-demo` route is a 404.
The only bypass is `DEMO_BOOKING_TURNSTILE_BYPASS=true`, honoured **only** when
`APP_ENV` is `local` or `testing`; it is ignored everywhere else and is
deliberately not in `.env.example`.

## Anti-abuse layers

1. Route throttles: 10 requests/minute on the booking write and on guest changes, 60/minute on the read endpoints.
2. A honeypot field (`demo_hp`): a filled value writes nothing and verifies nothing.
3. Cloudflare Turnstile, verified server-side with the secret key and the client IP; anything but `success: true` (including a network failure) refuses the booking, and so does a token issued for a different hostname. Cloudflare rejects a replayed or expired token itself (`timeout-or-duplicate`); the widget is reset after every refused attempt.
4. Server-side validation, then availability re-verified under the engine's locks at confirmation.

## Contacts

With a phone number the Contact is found or created by the engine's own
Location-local phone identity. Without one, identity is the lower-cased email
(serialised on the same lock table under an `e:` key): any Contact of the Location
with that email is reused, otherwise a Contact with an empty phone is created (it
can never be texted) and the usual new-Contact automations are raised. An existing Contact is only ever
filled in where blank; an anonymous submission never overwrites existing data.
Business name and website are stored as custom fields on the internal Business's
contact group.

## Guest cancel / reschedule

The confirmation and reminder emails of the demo Booking Type carry a link to
`/book-demo/manage/{appointment}`. The link is a Laravel signed URL that

- expires (30 days, or at the appointment start, whichever is first),
- embeds the appointment's reschedule count, so rescheduling retires every earlier link,
- only resolves an appointment of the designated Booking Type, still scheduled and in the future,
- is checked under a per-appointment lock, so two clicks cannot both use it.

Cancel and reschedule are `AppointmentBookingService::cancel` / `::reschedule`
(staff locks, full availability re-check, lifecycle events, so reminders follow
the new time or stop). The page works without JavaScript; the reschedule picker
is server-rendered, in the Business timezone.

## The demo form

The Platform Owner can edit every question at `/admin/platform-owner/marketing/demo-form`, including removing all of them: no question is protected, always visible or restored, and a form with no questions can be saved and published. The design default is used only until the first publish. The booking's own essentials are a first name and an email address; when the published questions do not collect them (a visible, required question of the matching type keeps its own answer), the confirmation step shows small attendee inputs for just those. With no questions the page skips the questionnaire and opens the calendar directly. Contact fields (last name, phone, business name) are filled only from questions that exist, and every other answer is mirrored as a `DEMO_*` CRM field.

## Known limitations

- The confirmation page for an honeypot hit shows the generic page with no details.
- The demo page offers no SMS consent: demo notifications are email only.
- After a successful reschedule or cancellation the guest gets one email (a `rescheduled` / `cancellation` row in the Calendar's notification ledger, keyed per reschedule so a replay sends nothing). It goes to the address frozen on their confirmation, is email only, and only for this Booking Type. The reschedule email carries the new time and a fresh manage link; the cancellation email carries no Zoom link and no manage link. Reminders follow the new time, or stop on cancel.
- `DEMO_BOOKING_TYPE_UUID` is trusted deployer configuration: point it only at the internal Business's Booking Type.
