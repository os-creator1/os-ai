# Native Zoom meetings

One shared integration for every MotionGrove calendar. It is not specific to `/book-demo`.

| Calendar | How it is served |
| --- | --- |
| Growth Business | its own Business-scoped connections |
| Agency's own operating Business | its own Business-scoped connections (never reused for clients) |
| Agency-managed client Business | the client's own independent connections (an Agency cannot connect, disconnect or retry them: view-as prohibits it) |
| MotionGrove Internal (`/book-demo`) | the same integration; no special code |

## How it works

* **Connection** (`zoom_connections`): one Zoom account authorized by one staff user for one Business, unique on
  `(business_id, user_id)`. OAuth authorization-code flow against a user-managed Zoom app. No Zoom password or pasted
  token is accepted anywhere. Tokens are encrypted at rest (model `encrypted` cast) and never logged. The signed `state`
  is encrypted, expires after 15 minutes, is bound to the Business and the user, and is single-use; the callback also
  requires the same signed-in user, who must still have access to the Business.
* **Meeting location** (booking type default, per-appointment override): `zoom`, `in_person`, `phone`, `custom`,
  or blank (exactly the behaviour before this existed). Zoom is never forced. The choice is **snapshotted** on the
  appointment, so later edits to the booking type never rewrite existing appointments.
* **Host rule**: a booking type is either hosted by the staff member assigned to each booking (their own connection in
  this Business) or by one explicitly chosen shared host (which must be a connection of the same Business). Nothing falls
  back to another staff member, another Business or an Agency owner's connection.
* **Lifecycle** (`AppointmentScheduled / Rescheduled / Cancelled`, dispatched after the booking transaction commits):
  one queued job, `SyncZoomMeeting`, reconciles the appointment with Zoom. It always reads the appointment's *current*
  state, so duplicate, late or out-of-order jobs cannot restore an old time or create a second meeting. Creating saves
  the meeting id with a conditional update; losing that race deletes the extra meeting. A reschedule PATCHes the same
  meeting (the join link is kept). A cancel DELETEs it (an already-missing meeting counts as deleted). The Zoom API is
  never called inside a booking transaction or lock.
* **Never the host link**: only the meeting `id` and the participant `join_url` are stored. The privileged `start_url`
  is discarded inside `ZoomClient`. A link that is not an `https://*.zoom.us/j/<id>` participant link is treated as missing.
* **Emails**: a Zoom confirmation or reminder is held until the meeting is ready *and* at the appointment's current time
  (`ZoomLinkGate`). It then carries a "Join Zoom meeting" button, the plain URL, and the link in the `.ics`, the Google
  Calendar link and SMS. Custom templates may use `{{join_url}}`. Non-Zoom appointments are unchanged.
* **Failure**: retries are bounded (6 attempts, backoff 30s / 2m / 10m / 30m / 1h; 429 waits for `Retry-After`). A revoked
  authorization or any non-retryable error fails at once. On failure the appointment is marked `failed`, the host is
  emailed, the guest receives an honest confirmation ("your Zoom link will follow", no button), reminders are skipped, and
  when a person fixes it (reconnect Zoom, then **Retry Zoom**) the guest gets the link in one follow-up email.
  `calendar:reconcile-zoom` (every 5 minutes) re-queues appointments stuck in `pending`; it never retries `failed` ones.
* **Public booking**: a Zoom booking type whose host is not connected is closed (404) and shows a setup requirement in the
  booking-type list. A manual appointment refuses with a clear message. No appointment is ever created that cannot get a link.

### Known limits (stated plainly)

* Zoom's create call has no idempotency key, so a lost response cannot be made exactly-once by the client. We make it safe
  instead: before the call the appointment records `zoom_create_started_at` and takes a database lease
  (`zoom_create_lease_until`, 120 s, so a crashed worker just expires). Any later attempt while the outcome is unknown first
  LISTS the host's scheduled meetings (`GET /users/me/meetings?type=scheduled`, scope `meeting:read:list_meetings`), adopts
  ours (matched by the per-appointment agenda `MotionGrove appointment <uid>`, else identical topic and start) and only
  creates when none exists. If the list cannot be read (scope missing) the booking is held as `create_unverifiable`, never
  re-created blindly. A cancel during an uncertain create finds and deletes the meeting Zoom made.
  Provider-side limits we cannot remove: the list could in theory lag behind a create that just succeeded (a second meeting
  could then be made; it is never shared, because only the saved meeting's link is emailed, and a lost save race deletes the
  extra), and the list only covers upcoming scheduled meetings. Existing connections need the new list scope on the Zoom app;
  none were live when this was added.
* Disconnecting Zoom removes our credentials; meetings already created stay in the Zoom account and keep their stored
  join links, but can no longer be updated or cancelled by us.
* Cancel and reschedule send no new customer email today (existing calendar behaviour, unchanged here). The reminder
  schedule follows the new time and the `.ics` `SEQUENCE` updates a calendar entry.

## Where it appears in the product

* **Calendar → Zoom** (sub-navigation, shown only where Zoom is configured and the plan allows it): connect or reconnect your Zoom account,
  see the connected account and status, disconnect, and see every host connected in this Business.
* **Booking type → Meeting location**: default / Zoom meeting / In person / Phone call / Custom link or location, plus the Zoom host
  (the person who gets each booking, or one shared host of this Business).
* **New appointment → Meeting location**: override the booking type for a single appointment.
* **Appointment details**: Meeting row with a **Join Zoom** button (authorized staff only; participant link), a clear failure message with
  **Retry Zoom**, or "creating the Zoom meeting…".
* **Guest manage page** (`/book-demo/manage/...`): the participant join link only.

## Eligibility

A Business is eligible when its own Workspace holds an **active Growth or Agency** plan (Growth Businesses, an Agency's own
Business, the internal Business with its complimentary Growth plan), **or** when its Workspace is an **actively managed Client
Workspace** of an Agency Workspace whose plan is an **active Agency** plan. The second rule exists because an Agency can resell
only Core or Growth tiers and a client's entitlement is its own plan row, so without it a Core-plan client would be locked out
of a capability its Agency's own subscription includes. Clients are not required to buy Growth. It is a read-only check: no
Stripe, price, entitlement or subscription row is written. A terminated relationship, a lapsed Agency plan, a Core workspace
that nobody manages, and a client of a non-Agency manager are all ineligible. Core is not eligible. A dedicated platform
feature key would be the proper long-term gate; adding one needs an explicit decision.

Isolation is unchanged: each Business needs its own Zoom authorization by one of its own users, a connection is never shared
across Businesses or with the Agency, and view-as (an Agency operator inside a client) cannot connect, disconnect or retry.
So "an Agency connects Zoom for a client" means the client's own user authorizes the client's own Zoom account.

## Configuration

```
ZOOM_CLIENT_ID=
ZOOM_CLIENT_SECRET=
ZOOM_REDIRECT_URI=https://<platform host>/zoom/oauth/callback
```

The integration is inert until all three are set and the redirect is `https`.

## Zoom Developer settings (Zoom App Marketplace → Develop → Build app)

1. Create an **OAuth app** (Zoom now labels it a *General app*) and choose **User-managed** (not account-level, not Server-to-Server).
2. **App credentials**: copy the Client ID and Client Secret into `ZOOM_CLIENT_ID` / `ZOOM_CLIENT_SECRET`. Zoom issues separate
   *Development* and *Production* credential pairs. Use the matching pair for the environment (staging may use Development).
3. **Redirect URL for OAuth** and **OAuth allow list**: `https://<platform host>/zoom/oauth/callback`, exactly, for each environment
   (for example `https://staging.getmotiongrove.com/zoom/oauth/callback`). Avoid loopback/dev URLs in the production entry.
4. **Scopes** (granular): `meeting:write:meeting`, `meeting:update:meeting`, `meeting:delete:meeting`, `meeting:read:list_meetings` (creation recovery), and optionally
   `user:read:user` (only to show which Zoom account is connected).
5. **Activation**: Development credentials authorize only the app owner's own Zoom account (and, for beta, members of that account).
   To let other customers' Zoom accounts connect (Growth and Agency customers), the app must be submitted and approved via the
   Marketplace ("Share your app with others" / publish). Plan for Zoom's review before customer launch.

## Zoom account compatibility: what is and is not confirmed

Confirmed from Zoom's developer documentation: the create/update/delete-meeting endpoints and granular scopes above; user-OAuth
access tokens last one hour and refresh tokens rotate (always store the newest); development and production credentials are
separate; a user-managed app can only act for the user who authorized it.

**Not confirmed (could not be verified from the documentation reachable while building this):**
whether a **Zoom Basic (free)** host may create scheduled meetings through the API. Basic accounts can schedule meetings in the
product; verify the API with the account you will use (`POST /users/me/meetings`) before relying on it. Basic accounts also cap
meetings at **40 minutes**, which is shorter than a longer demo and is enforced by Zoom, not by MotionGrove. Whether paid Zoom is
required for this integration is therefore unconfirmed, not assumed either way.

## Live acceptance test (needs a real, authorized Zoom account; not run in CI)

Use a Development-credential app and your own Zoom account on staging, with `ZOOM_*` set and the redirect registered.
1. **Connect**: Calendar → Zoom → Connect; approve on zoom.us; expect "Connected" with your Zoom email. Repeat as a *managed client*
   user in a client Business (a separate Zoom account if possible); the Agency's connection must not appear there.
2. **Create**: a booking type with Meeting location = Zoom; book a slot. Within a minute the appointment shows Join Zoom, the guest
   email has the button, the plain URL and the `.ics` link; the meeting appears in your Zoom account with agenda
   `MotionGrove appointment <uid>` and the right time and duration.
3. **Basic account**: repeat step 2 with a Zoom Basic (free) host. Record whether `POST /users/me/meetings` succeeds, and the 40-minute cap.
4. **List recovery**: `GET /users/me/meetings?type=scheduled` with the connected token returns the new meeting *with its `agenda`*
   (confirms the lookup works and the scope is granted). Then simulate a lost response (block the response, or set
   `zoom_create_started_at` on a fresh pending appointment): the next run must adopt the existing meeting, not create another.
5. **Reschedule**: move the appointment; the same meeting (same link) shows the new time. **Cancel**: the meeting disappears from Zoom.
6. **Token expiry/rotation**: wait more than an hour (or set `access_expires_at` in the past): the next sync must refresh and
   store the rotated refresh token.
7. **Revoke**: remove the app at zoom.us/account/apps; the next sync must fail at once, mark the appointment failed, email the host,
   and show a reconnect hint; reconnect and press Retry Zoom: the guest receives the link exactly once.
8. **Marketplace**: a second, unrelated Zoom account must be refused by a Development app, which is why customers need the
   published app (Zoom's review) before launch.
