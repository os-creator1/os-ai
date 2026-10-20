# Browser calling (Telnyx WebRTC) — V1

Call customers from Contacts and Conversations, and receive calls for one assigned person, inside MotionGrove
in the browser. No mobile app. No recording, transcription, transfer, IVR or queueing in V1.

## Environment

| Variable | Purpose |
|---|---|
| `TELNYX_VOICE_ENABLED` | `true` turns on **managed** browser calling. Default off. BYO calling does not need it. |
| `TELNYX_VOICE_CONNECTION_ID` | The platform's Telnyx **Credential Connection** (WebRTC enabled). One SIP identity per user/Business is created under it. |
| `TELNYX_API_KEY`, `TELNYX_WEBHOOK_PUBLIC_KEY` | Already used for managed messaging; reused here. |

## Manual Telnyx setup (not automated, nothing is bought or changed by MotionGrove)

Managed (platform account):
1. Create a Credential Connection with WebRTC enabled; put its ID in `TELNYX_VOICE_CONNECTION_ID`.
2. Point that connection's webhook at `https://<app>/webhooks/telnyx-voice` (call events; Ed25519-signed).
3. For **incoming** calls: put the number on a Telnyx **Call Control application** whose webhook is the same URL.
   MotionGrove answers the `call.initiated` event by transferring the call to the assigned user's SIP identity.

BYO Agency (the Agency's own Telnyx account): the same three steps in the Agency's account, using the per-account
webhook URL shown on **Voice settings** (`/webhooks/agency-telnyx-voice/{channel}/{token}`, verified with the public
key the Agency already stored for its channel), and the Credential Connection ID entered on Voice settings.

## In the app

* **Voice settings** (`…/businesses/{business}/voice/settings`, needs `view_numbers`): per number, turn voice on,
  choose who rings for incoming calls, and run the read-only Telnyx check. Until it passes the number is not callable.
* **Call** button: contact page header, and the conversation header. One floating panel serves both.

## Honest limits

* Outbound caller ID is chosen in the browser by the SDK; the server validates the intent (destination, number)
  and reconciles the Telnyx event against it, but cannot stop a user who bypasses the UI from dialing with their own
  token. Mitigations: per-user identities, short-lived tokens, destination-mismatch events are never attributed.
* Incoming calls only ring while MotionGrove is open in the assigned user's browser (heartbeat, 75 s window).
* A page reload or tab close ends a live call. In-app navigation during a call swaps the page content instead of
  reloading, so the call survives; leaving the Workspace asks first.
