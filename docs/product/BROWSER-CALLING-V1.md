# Browser calling (Telnyx WebRTC) — V1

Call customers from Contacts and Conversations, and receive calls for one assigned person, inside MotionGrove
in the browser. No mobile app. No recording, transcription, transfer, IVR or queueing in V1.

**Status: built and verified against a deterministic fake provider only. Nothing here has been run against a
real Telnyx account. Do not enable it for customers before the live checklist at the bottom is done.**

## How a call works (server-mediated — the browser never dials)

A browser that holds a Telnyx WebRTC token can ask the SDK to dial *anything its credential connection allows*,
with *any* caller ID. MotionGrove therefore does **not** let the browser dial at all:

1. The page registers the user's own SIP identity (a per-user, per-Business, per-account Telnyx telephony
   credential; the browser only gets a short-lived JWT for it).
2. **Outgoing:** the page asks MotionGrove to call a Contact/Conversation. The server derives the destination from
   the stored contact (never from the request), checks tenant, Business/Location, caller-ID ownership, voice
   readiness and the destination policy (allowed regions, no premium/shared-cost), records the call, and
   **MotionGrove places the call** through its Call Control application: it first rings the user's own browser
   identity (showing the destination as the caller).
3. The page answers that one leg on the person's behalf (they just pressed Call; it must carry the number they asked
   for, within 25 s).
4. When that leg is answered, MotionGrove — after re-checking user, Business, number, destination and call age
   — **transfers the call to the recorded destination with the recorded caller ID**, once. The panel only shows
   "In call" when MotionGrove's server has confirmed the *customer's* leg answered, and the timer starts from the
   server's answer time.
5. **Incoming:** the number is assigned to the same Call Control application. MotionGrove looks the number up to its
   one Business, and rings the one assigned user's browser identity if that browser has heartbeated in the last
   75 s; otherwise the call is turned away and recorded as missed with the reason. Never auto-answered.

### What actually enforces outbound authorization

* A token is issued **only if Telnyx reports the Credential Connection has no outbound voice profile** (it cannot
  reach the phone network). This is re-checked at least every 5 minutes and at every Voice-settings check; if Telnyx
  does not say, calling stays off (fail closed). So manipulating JavaScript or calling the SDK directly cannot place
  a call or change caller ID — *provided Telnyx honours that: a credential connection without an outbound voice
  profile cannot originate calls.* That behaviour is Telnyx's, and is **not yet verified live**.
* Every real call goes through the Call Control application's **outbound voice profile**, which is where Telnyx
  limits countries and spend. It is mandatory (setup refuses without one). MotionGrove's own destination policy
  (`TELNYX_VOICE_ALLOWED_REGIONS`, default `US,CA`; premium-rate/shared-cost always refused) is applied on top.
* Webhooks are Ed25519-verified (managed: platform key; BYO: that Agency's key plus the URL token).
* If the credential connection cannot be locked down in a given Telnyx account, **leave voice off** — the smallest
  secure change is exactly this lockdown; there is no safe way to allow direct browser dialing on a shared
  connection (a connection-level caller-ID override applies to every user of it).

## Environment

| Variable | Purpose |
|---|---|
| `TELNYX_VOICE_ENABLED` | `true` turns on **managed** browser calling. Default off. BYO calling does not need it. |
| `TELNYX_VOICE_CONNECTION_ID` | Platform **Credential Connection** (WebRTC) for SIP identities. **No outbound voice profile.** |
| `TELNYX_VOICE_CALL_CONTROL_APP_ID` | Platform **Call Control application** that places/receives calls. Needs an outbound voice profile. |
| `TELNYX_VOICE_ALLOWED_REGIONS` | ISO regions MotionGrove will dial. Default `US,CA`. |
| `TELNYX_API_KEY`, `TELNYX_WEBHOOK_PUBLIC_KEY` | Already used for managed messaging; reused here. |
| `TELNYX_VOICE_DRIVER` | `fake` binds an in-memory provider for browser verification only; ignored in production. Leave unset. |

## Manual Telnyx setup (MotionGrove buys/creates/changes nothing)

Managed: (1) Credential Connection with WebRTC, **no outbound voice profile**; (2) Call Control application with
an **outbound voice profile** and webhook URL exactly `https://<app>/webhooks/telnyx-voice`; (3) assign each calling
number to that Call Control application (number → Voice settings). BYO Agency: the same three, in the Agency's
account, with the webhook URL shown on Voice settings (`/webhooks/agency-telnyx-voice/{channel}/{token}`), then
enter both IDs there.

Use **Voice settings** (`…/businesses/{business}/voice/settings`, needs `view_numbers`): turn voice on per number,
choose who rings, run the check. The check reads Telnyx (GET only) and separately reports:

* **Outgoing ready** — number in the right account and active; credential connection exists, is active and cannot
  dial; Call Control app exists, is active, has an outbound profile and MotionGrove's exact webhook URL.
* **Incoming ready** — additionally the number itself is assigned to that Call Control application. A number that is
  not is shown "Incoming: not ready", and is never offered for inbound presence.

Existing SMS routing is untouched: nothing reassigns numbers or edits messaging profiles.

## Call legs and identifiers

All events of one call share `call_session_id`. The record is keyed on it.

| | leg A (the call's originating leg) | leg B |
|---|---|---|
| outgoing | MotionGrove's `dial` to the user's SIP identity (`call_control_id` stored at dial time; correlated by our `client_state`) | created by the bridge `transfer` to the customer |
| incoming | the customer's inbound leg (`call.initiated`, `to` = our number) | created by the `transfer` to the user's SIP identity |

"Customer answered" (outgoing) / "user answered" (incoming) = `call.answered` on a leg that is **not** the stored
originating leg. Leg A answering only triggers the bridge. Outcomes come from `call.hangup` cause/source; replayed,
late and out-of-order events are idempotent (unique provider event id; status ranks only move forward; a late answer
that predates a recorded unanswered end corrects it to completed).

## In the app

* **Call** button: contact page header and conversation header. One floating panel serves both. Authorized users
  always see it; when calling is not set up it is disabled with the reason. Users without the Conversations
  permission, and Agency view-as sessions, never see it.
* While a call is live, in-app links swap the page content (same code path for every page, only during a call) so the
  call, the SDK client and the audio element survive navigation. Leaving the Workspace, logging out or switching
  Business asks first, then hangs up and marks the browser offline.

## Honest limits

* Incoming calls only ring while MotionGrove is open in the assigned user's browser. A reload/tab close ends a call.
* One call at a time per user; a second incoming call while busy is turned away.
* One registered browser tab leads per Business (others offer "use calling here").
* A user registered for the incoming number's account who starts a call from a different account scope re-registers
  their browser, so their incoming presence for the first account drops during the call.

## Release blockers (do not activate for customers until all are closed)

1. **Provider-enforced restriction is unconfirmed.** Outbound authorization rests on Telnyx refusing PSTN calls from a
   credential connection that has no outbound voice profile. MotionGrove verifies the *configuration* (and fails closed),
   but only a live test proves Telnyx enforces it. Until item 1 of the checklist below passes, treat the architecture as
   not proven secure.
2. Live verification of every item in the checklist below.
3. A cancelled call, or one the browser reported ended, can never bridge (tested); a rejected provider answer closes the
   call. These are MotionGrove-side guarantees and do not replace blocker 1.

## Not verified with a real Telnyx account (live checklist)

1. A Credential Connection without an outbound voice profile really cannot originate calls from a JWT-authenticated
   SDK client (try `newCall` from a console with a real token).
2. `GET /credential_connections/{id}` and `/call_control_applications/{id}` return `outbound.outbound_voice_profile_id`
   and `webhook_event_url` as assumed (the readiness check fails closed if not).
3. `POST /calls` to `sip:<sip_username>@sip.telnyx.com` rings a registered browser; the SDK delivers it as an inbound
   call whose caller number equals the `from` we set; `client_state` is echoed in webhooks.
4. `transfer` of that leg to a PSTN number with a different `from` works and emits `call.answered` /
   `call.hangup` for the new leg on the same `call_session_id`, with the hangup causes the mapping assumes.
5. Incoming: number on the Call Control application delivers `call.initiated` (`direction: incoming`), and a
   transfer to the user's SIP identity produces the expected leg events.
6. SDK `hold`/`unhold` behaviour and media quality on desktop and mobile browsers.
