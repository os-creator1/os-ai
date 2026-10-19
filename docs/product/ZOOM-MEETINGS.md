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

* If Zoom creates a meeting but the response is lost (timeout), the retry creates another; the first is an orphan that was
  never shared with anyone. Zoom's create call has no idempotency key.
* Disconnecting Zoom removes our credentials; meetings already created stay in the Zoom account and keep their stored
  join links, but can no longer be updated or cancelled by us.
* Cancel and reschedule send no new customer email today (existing calendar behaviour, unchanged here). The reminder
  schedule follows the new time and the `.ics` `SEQUENCE` updates a calendar entry.

## Eligibility

A Business is eligible when its Workspace holds an **active Growth or Agency** plan (this covers Growth Businesses, an Agency's
own Business, each managed client Business through its Client Workspace, and the internal Business, which has a complimentary
Growth plan). It reads the existing plan assignment and changes no Stripe, price, entitlement or subscription row. Core is not
eligible. A dedicated platform feature key would be the proper long-term gate; adding one touches the feature registry, customer
permissions and plan packaging, so it needs an explicit decision.

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
4. **Scopes** (granular): `meeting:write:meeting`, `meeting:update:meeting`, `meeting:delete:meeting`, and optionally
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
