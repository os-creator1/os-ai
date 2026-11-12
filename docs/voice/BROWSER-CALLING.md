# Browser calling (CRM softphone)

A customer opens a contact, clicks **Call** beside the phone number, allows the microphone, and speaks to the contact
through the browser. Billing for voice minutes is **not** built yet.

## How audio is connected
Two Call Control legs, bridged by the platform:

1. The browser logs in to Telnyx with the Telnyx WebRTC SDK (`public/vendor/telnyx/webrtc-2.28.2.js`, MIT, vendored
   unmodified) using a short-lived JWT login token minted server-side for a per-user, per-Business telephony credential.
2. `POST …/calls {channel: "browser"}` makes the server dial that browser (`sip:<credential>@sip.telnyx.com`) from the
   business's number. The browser auto-answers a call it just requested and never any other call.
3. When the browser leg answers, the Telnyx webhook (`/webhooks/telnyx-voice`) makes the server dial the contact.
4. When the contact answers, the server bridges the two legs. That bridge is the two-way audio.
5. Either side hanging up closes the other leg and the record (outcome and duration) on the contact's profile.

The browser never receives the Telnyx API key, a SIP password or any permanent credential, and its credential
connection has **no outbound voice profile**, so a browser cannot dial the PSTN itself.

## Telnyx configuration (one-time)
1. **Call Control application** — already used by voice calls (`TELNYX_VOICE_CONNECTION_ID`), webhook
   `https://<host>/webhooks/telnyx-voice`. It must be allowed to dial both PSTN and SIP URIs (outbound voice profile
   with the destination countries enabled, SIP URI calling allowed).
2. **Credential Connection for WebRTC** — Voice → SIP Connections → *Credential Connection*. Enable **WebRTC** and
   inbound calls; **do not attach an outbound voice profile**; no IP restriction. Note its connection id.
3. Telnyx portal → your numbers: the business number is already assigned to the Call Control application (voice
   auto-assignment).

## Forge environment variables
| Variable | Value |
|---|---|
| `TELNYX_MODE` | `live` |
| `TELNYX_API_KEY`, `TELNYX_WEBHOOK_PUBLIC_KEY` | existing |
| `TELNYX_VOICE_CONNECTION_ID` | existing Call Control application id |
| `TELNYX_WEBRTC_CONNECTION_ID` | the Credential Connection id from step 2 |
| `VOICE_CUSTOMER_CALLS_ENABLED` | `true` |
| `VOICE_BROWSER_CALLS_ENABLED` | `true` |
| `VOICE_CUSTOMER_CALL_MAX_SECS` (opt.) | default 600 |
| `VOICE_CUSTOMER_CALL_MAX_CONCURRENT` (opt.) | default 2 per Business |

Grant the `place_voice_calls` permission to the users who may call. Migrate (`2026_11_15_100001`).

## Limits enforced on the server
One live browser call per user; per-Business concurrency and hourly caps; one active call per contact; idempotency key
per request; contact Location access; do-not-contact list; a hard `time_limit_secs` on the contact leg (default 10 min)
and a 25 s ring limit on the browser leg.

## Not included
Recording and consent, voice minute billing, inbound calls, call transfer, a dialpad.
