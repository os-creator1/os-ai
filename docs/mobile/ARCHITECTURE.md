# MotionGrove Mobile — Phase 1 architecture

Status: Phase 1 (foundation). Scope: a React Native (Expo) iOS/Android client for CRM lead conversations, contacts,
and — in a later milestone — voice calling and push. It is a **new frontend for the existing Laravel platform, not a
second CRM**: every rule below reuses a Laravel service boundary instead of re-implementing it.

Governance: built under `CLAUDE.md` route 3 (explicitly human-authorized manual lane). Claude can own the PR through a verified self-merge under the current `CLAUDE.md` and `AGENTS.md` gates. No deploy, no live SMS, no real calls, no production data.

## 1. What already exists (inspected) and what was missing

| Area | Existing, reusable | Genuinely missing (added or deferred) |
|---|---|---|
| Auth | Laravel Sanctum is installed; `/api/v3` uses `auth:sanctum` but is a **legacy, pre-Workspace** surface (SMS send, contact writes) behind `CustomerAccountAccessApiGate`. Web login is session + email/password + optional 2FA. | A phone token login. **Added** `POST /api/mobile/v1/auth/login` (Sanctum token, ability `mobile`, 30-day expiry), logout, refresh. |
| Conversations read | `ChatBoxController` (`loadChatUsers`, `messages`, `timeline`), tables `chat_boxes` / `chat_box_messages`, `BusinessConversationReadModel`, `UnifiedConversationList`. All web/session, HTML or ad-hoc JSON. | A JSON API. **Added** list + detail endpoints over the same rows and rules. |
| Conversations write | `reply`, `retry`, managed-send state machine, opt-out/DNC, wallet/billing, Telnyx/10DLC provider gating. | **Deferred** (Phase 2). These must be *extracted into a service the web and mobile both call*, not duplicated: see §8. |
| Real time | `MessageReceived` broadcasts on private channel `chat.business.{businessUid}`; authorized in `routes/channels.php` with the same chain as the inbox. `Broadcast::routes()` is session-guarded; `BROADCAST_DRIVER` defaults to `null`. | A token-authenticated broadcast auth route and a Pusher-protocol client. **Deferred** (Phase 2). Phase 1 uses pull-to-refresh. |
| Contacts + Locations | `Contacts`, `contacts_custom_field`, `LocationAccessGuard`, `CrmLocationScope`, permissions `view_contact`, `chat_box`. | **Added** a read-only contact profile endpoint that applies the same Business + Location rules. |
| Calling | Telnyx browser softphone: `BrowserCredentialProvisioner` mints a per-(Business,user) telephony credential and a **short-lived JWT**; `VoiceCallController` (place, browser-session, presence, incoming, answer, decline, hangup, wrap-up); `InboundCallRouter`; agency BYO Telnyx (`AgencyByoVoice`); `CallingAuthority` (`place_voice_calls`); platform kill-switches. All session-authenticated web routes. | Mobile-authenticated wrappers, push-wake for inbound calls (FCM/PushKit), the SDK. **Added** capability-only endpoint; the rest is Phase 2. |
| Tenancy | Workspace → Business (one Business per Workspace), `BusinessRouteAccess`, `WorkspaceManager::userCanAccessBusiness`, entitlements (`conversations`, `crm`) via `EntitlementManager`, view-as sessions for agencies. | Mobile has no session, so no view-as. Agencies see only Businesses they are an ordinary tenant of; acting *as* a managed client is **out of scope** for Phase 1 and refused by construction (see §5). |
| Notifications | Laravel mail/database notifications. **No push channel, no device table.** | **Added** `mobile_devices` + registration endpoint. **No push is sent** in Phase 1. |

## 2. Where the mobile project lives — decision

**`mobile/` inside this repository (monorepo subdirectory).** Reasons: the API contract and its backend tests change in
the same commit as the client that consumes them; one review surface for ChatGPT; no cross-repo versioning for a
pre-1.0 contract; the Laravel toolchain (`composer`, `package.json` for Mix) is untouched because `mobile/` has its own
`package.json`, lockfile and `node_modules`. Generated native folders (`mobile/android`, `mobile/ios`) are **not
committed** (Expo Continuous Native Generation); `npx expo prebuild` recreates them from `app.config.ts`.
Revisit extracting to its own repo when the mobile release cadence diverges from the platform's.

## 3. Stack

* React Native 0.88, **Expo SDK 58**, TypeScript (strict), React Navigation native-stack.
  The initial MVP intentionally uses a typed React Navigation stack in `src/navigation`, not Expo Router; the matching `mobile/AGENTS.md` documents this choice. A Router migration is a separate, explicitly scoped decision.
* **Native development builds** (`expo-dev-client`), not Expo Go: required for the Telnyx/WebRTC native modules later.
* `expo-secure-store` (Keychain / Android Keystore) for the token; `expo-notifications` for push registration.
* Tests: `jest-expo` + React Native Testing Library. Backend: PHPUnit feature tests.

## 4. Telnyx React Native Voice SDK / Voice Commons — evaluation

Package: `@telnyx/react-voice-commons-sdk` 1.2.2 (MIT) over `@telnyx/react-native-voice-sdk`.

* **Fit: good.** It supports **token-based login** (`createTokenConfig(jwt)` → `voipClient.loginWithToken`) with a JWT. That
  maps exactly to the existing server flow (`BrowserCredentialProvisioner::loginSession`), so **the app never holds an
  API key, a SIP username or a SIP password**. We must not use its `createCredentialConfig(user, password)` path.
* **Works with Expo** via a development build (documented as supported; not Expo Go).
* **Native requirements (Phase 2):** peer deps `react-native-webrtc ^155`, `@react-native-async-storage/async-storage`;
  dependency `react-native-voip-push-notification`.
  **iOS:** CallKit + PushKit (VoIP push certificate/APNs key, `voip` background mode, microphone usage string — already in
  `app.config.ts`), capability *Push Notifications*.
  **Android:** Firebase Cloud Messaging (`google-services.json`, FCM v1 service account held **server-side only**),
  `TelnyxFirebaseMessagingService`, ConnectionService/foreground-service-microphone permissions, `RECORD_AUDIO`.
* **Why it is not installed in Phase 1:** it drags in WebRTC native binaries and Firebase/APNs configuration that have no
  consumer until calling exists. The Phase 1 binary stays small and its build stays diagnosable. Phase 2 adds it and the
  config plugins in one focused change.
* **Push-launched cold start gotcha** (from the SDK docs): guard auto-login with
  `TelnyxVoipClient.isLaunchedFromPushNotification()` or the call fails with a double login.
* **Server prerequisite already met:** the platform's WebRTC credential connection has **no outbound voice profile**, so
  a leaked JWT cannot dial the PSTN; calls exist only when the platform dials the leg (`docs/voice/BROWSER-CALLING.md`).

## 5. Authentication, authorization, tenancy

### Token login
`POST /api/mobile/v1/auth/login {email, password, device{installation_id, platform, name?, app_version?}}`

* Sanctum personal access token, **ability exactly `mobile`**, **30-day `expires_at`**, hashed at rest by Sanctum. The
  phone stores it in Keychain/Keystore only.
* Same credential rules as the web login (account must be enabled). Unknown email, wrong password and disabled account
  return **one identical 401** `invalid_credentials`. 5 failures per (email,IP) → 429 (lock covers the correct password too).
* **Two-factor accounts are refused** (`403 two_factor_unsupported`), never bypassed. A mobile 2FA challenge is a Phase 2 item.
* One live token per installation: signing in or refreshing retires the previous token and device row.
* `auth/refresh` rotates the token; `auth/logout` deletes the token **and** the device row (the phone stops being a push target).
* `EnsureMobileToken` requires the explicit `mobile` ability (a `*` wildcard token does **not** qualify) and re-checks
  `users.status` on every request, so disabling an account ends the phone session immediately.
* `RejectMobileTokenOnLegacyApi` is added to `/api/v3`: a mobile token can never call the legacy SMS/contact-write API.

### Tenant isolation
Every Business route is `workspaces/{workspaceUid}/businesses/{businessUid}/…` and runs `MobileBusinessAccess::resolve`,
the **same authorities in the same order** as `ChatBoxController::resolveBusiness()` and the `chat.business.{uid}`
broadcast channel: active Workspace → Business inside it → `BusinessRouteAccess` → permission (`chat_box` / `view_contact`)
→ per-member module visibility (`MemberModuleVisibility`, so a hidden Conversations or Contacts module stays hidden in mobile) → Business is Active → `EntitlementManager` (`conversations` / `crm`). Then the record is fetched by **uid AND business_id**,
and the Location ACL (`CrmLocationScope`, `LocationAccessGuard`) applies. **Any** failure — foreign Business, mixed
Workspace/Business pair, missing uid, numeric id in place of a uid, NULL-business legacy row, un-entitled plan, missing
permission — is the **same 404 `not_found`**, so a probe learns nothing.

### Business / account switching
Switching is a **client choice of path parameters**, not server session state. `GET /businesses` returns only Businesses
the actor may open (with feature flags); the app persists the last selection and drops it if the server stops listing it.
Switching *account* (a different person) = sign out + sign in; Phase 1 keeps one token per phone.

### Secrets policy
No provider API key, Telnyx credential, SIP password, FCM/APNs key or webhook secret is bundled in or sent to the app.
`app.config.ts` exposes one public value: the API origin (`EXPO_PUBLIC_API_URL`, https enforced outside dev bundles).
Telephony access will be a **short-lived JWT minted per session by the existing provisioner**, never persisted.

## 6. API contract — `/api/mobile/v1`

Errors: `{"error":{"code","message"}}` with a real HTTP status. Success: `{"data":…}`. Responses carry `Cache-Control: no-store`.

| Method & path | Purpose | Reuses |
|---|---|---|
| `POST auth/login` | token login (public, throttled) | `Hash`, Sanctum |
| `POST auth/refresh` · `POST auth/logout` | rotate / end session | Sanctum |
| `GET businesses` | the switcher: Businesses + feature flags | `WorkspaceRepository`, `WorkspaceManager`, `EntitlementManager` |
| `PUT/DELETE devices/current` | register / clear push token for **this** token's device | new `mobile_devices` |
| `GET …/conversations?filter=all\|unread\|read&search=&page=` | SMS lead conversations, pinned then newest first, 30/page | `chat_boxes` rules of `loadChatUsers`, `CrmLocationScope`, `ChatBox::displayNamesFor` |
| `GET …/conversations/{uid}?limit=&before_id=` | thread (read-only; does **not** clear unread) | `messages`, `LocationAccessGuard`, `resolveDisplayContact` |
| `GET …/contacts/{uid}` | contact profile | `Contacts`, `contacts_custom_field`, Location ACL |
| `GET …/calls/capability` | would calling work here? never contacts Telnyx | `ConversationCalling`, `CallingAuthority` |

Versioning: breaking changes go to `/v2`; the app sends its version on login/registration for server-side deprecation.

## 7. Device registration

`mobile_devices(uid, user_id, personal_access_token_id UNIQUE, installation_id, platform, push_provider, push_token,
push_token_hash, app_version, device_name, last_seen_at)`. The push token is an **address**, stored per device row, hidden
from serialization, never returned. A push token is unique to one install: registering it on a second account (shared
phone) strips it from the first account's row. Logout, token rotation by a new login on the same installation, and user
deletion remove/replace the row. **No notification is sent in Phase 1** — there is deliberately no sender.

## 8. Phase 2 plan and the exact next milestone

**Milestone 2 — "Reply and receive" (real messaging), then Milestone 3 — "Call" (voice).**

M2 (messaging), in order:
1. Extract the body of `ChatBoxController::reply/retry` (managed send, opt-out, wallet, provider gate) into a
   `ConversationReplyService` called by **both** the web controller and a new `POST …/conversations/{uid}/reply` with a
   client `idempotency_key`; add `POST …/read`. Web tests must stay green unchanged.
2. Token-authenticated broadcast auth (`POST /api/mobile/v1/broadcasting/auth`) reusing the `chat.business.{uid}` callback,
   and a Pusher-protocol client for live thread updates.
3. Push **sender**: a `MobilePushNotifier` (Expo push API via a fakeable HTTP port) fired from the inbound-message path
   with a payload of `{business_uid, conversation_uid}` only (no message text on the lock screen by default), plus
   token-hygiene on `DeviceNotRegistered`.
4. Mobile 2FA challenge.

M3 (calling): `POST …/calls/session` wrapping `BrowserCredentialProvisioner::loginSession` (→ JWT only); mobile wrappers
over place / answer / decline / hangup / wrap-up; `isLaunchedFromPushNotification` guard; install the Telnyx SDK + config
plugins; FCM/PushKit credentials held **server-side**; inbound call push-wake.


## 9. Phase 2A — working SMS conversations (reply, retry, read)

**One send path.** The desktop's private reply/retry logic moved, unchanged, into
`App\Library\Conversations\ConversationSender`; `ChatBoxController` and the mobile API both call it. It still funnels into
`CampaignRepository::quickSend()` and the managed dispatcher (wallet reservation, blacklist/STOP, readiness, per-operation
idempotency, history writer). There is no second delivery engine. One behavioural addition, for **both** clients: a contact
whose status is `unsubscribe` is refused even when no blacklist row mirrors it (import suppression, manual unsubscribe); that
refusal creates no failed bubble, so no Retry is offered.

| Endpoint | Behaviour |
|---|---|
| `POST …/conversations/{uid}/reply {body, idempotency_key(uuid)}` | Same tenancy chain as reads, then `ConversationSender::reply`. The key is the message's `send_uid`. A repeat of a key already recorded **never reaches the provider** and is never re-billed (answered from the stored bubble). 200 `{data:{message}}`; refusal 422 `{error:{code,message,retryable,send_uid}}`. |
| `POST …/conversations/{uid}/retry {send_uid}` | Deliberate new attempt for a `failed`/`delivery_failed` bubble, fresh gates, same bubble, one provider call, claim-locked against double taps. |
| `POST …/conversations/{uid}/read` | Sets `chat_boxes.notification = 0` — the same unread counter the desktop list, Home and the inbound writer use. Written without touching `updated_at`, so reading never re-sorts the inbox. `GET` conversation never clears unread. |

Message objects now carry `status` (`sending|sent|delivered|failed|delivery_failed|ambiguous`, null when untracked), `send_uid`,
`failure_reason`, `status_message`, `retryable`. Stored `direction` values `incoming|inbound|in` all mean inbound.

Error codes: `insufficient_balance`, `messaging_not_ready`, `messaging_unavailable`, `delivery_failed`, `send_failed`,
`byo_*`, `opted_out`, `send_refused` (desktop-worded refusal such as blacklist), `validation_failed`, `not_found`.
Locked accounts remain a uniform 404 (no oracle), as for reads.

**Replay safety:** Mobile reply requires a managed or selected BYO sender with durable operation idempotency; legacy non-managed SMS is refused before any provider call because repeating a timed-out legacy request can cause a duplicate SMS. The shared service passes `require_managed` for mobile to fail closed if the managed/BYO identity disappears between the preflight and dispatch. Desktop legacy sending remains unchanged. Mobile conversation reads reconcile stale managed `sending` claims via the same locked state machine as desktop; the provider is never called by a read.

**Known limits (Phase 2B):** no push/real-time (refresh or reopen to see inbound and new delivery states), text only (no
MMS upload), the desktop inbox clears its unread badge in the browser only, so a conversation read on the phone stays read
on desktop but one opened on desktop is not persisted as read until the desktop adopts the same endpoint.


## 10. Phase 2B — real-time messages and push notifications

**No second messaging system.** Both features hang off seams that already exist:

| Trigger | Existing seam used | Mobile reaction |
|---|---|---|
| Inbound SMS (managed *and* legacy) | `InboundMessageReceived` — the authoritative, after-commit domain event emitted once per stored message, with its own webhook de-duplication | queued `NotifyMobileOfInboundMessage` → live event + push |
| Delivery receipt (delivered / failed) | `InboundWebhookAttributionResolver` transition + `ConversationHistoryWriter` (now also moves a bubble `sent → delivered`, like it already moved `sent → delivery_failed`); new `OutboundDeliveryStateChanged` after-commit event | live `status` event (never a push) |
| Outbound send / retry | `ConversationSender` (shared with desktop) | live `outbound` event (never a push) |
| Mark read | the Phase 2A read endpoint | live `read` event |

**Who is told — one authority.** `MobileRecipients` decides recipients **at dispatch time, server-side**, and the channel
authorization re-asks the same question at subscribe time: enabled account with a live (unexpired, unrevoked) mobile session →
`MobileBusinessAccess::resolve` (active Workspace, account not locked, membership/ownership, Conversations module visible,
`chat_box`, Business active, entitlement) → the conversation's Location reachable. Anyone else gets nothing.

**Live events** use the platform's existing Pusher-protocol broadcaster (`BROADCAST_DRIVER=pusher`; works with a self-hosted
Soketi via `PUSHER_HOST`). `MobileConversationChanged` is broadcast (queued) on a **per-user, per-Business private channel**
`private-mobile.business.{businessUid}.user.{userUid}` and is deliberately **content-free**:
`{type: received|outbound|status|read, business_uid, conversation_uid}`. The phone re-reads through the normal API, which applies
tenancy/Location/permission rules. (The web `chat.business.{uid}` channel is unchanged and is not used by mobile: its events carry
message content and are not Location-filtered.) Auth: `POST /api/mobile/v1/broadcasting/auth` with a mobile-scoped token; only that
channel shape, only the caller's own uid, only while `MobileRecipients::mayReceive` holds. `GET /realtime/config` returns the **public**
app key/cluster/host only.

**Push** (Expo's free service) is queued and never blocks the webhook; it is OFF unless `MOBILE_PUSH_ENABLED=true`. Payload is fixed
generic text plus two opaque uids — title `MotionGrove`, body `New message received.`, `data {v:1,t:'message',b:<business uid>,c:<conversation uid>}`.
No text, name, number or Business name. Exactly-once per device: `mobile_push_deliveries` has a UNIQUE `(device, occurrence key)`,
claimed before the provider call, so webhook replays and queue retries cannot double-send; a transport failure releases the claim.
Malformed tokens and Expo's `DeviceNotRegistered` clear the token. Logged-out, expired, revoked, disabled and locked sessions are
never targets. Messages older than `mobile.fresh_message_seconds` (imports/backfills) never push. A provider outage is logged
and swallowed — the inbound message is already stored.

**Phone.** `RealtimeProvider` keeps one subscription for (user, selected Business), drops events for any other Business, emits
`resync` on every (re)connect and on app resume so missed messages are re-read from the API, and tears down on Business switch,
sign-out, 401 or lock. Detail and list screens merge instead of reloading (de-duplicated by id/uid; older pages kept). A push tap goes through
`NotificationRouter`: payload shape is validated, then the **server is asked again** which Businesses the person may open; the tapped
Business must be listed with Conversations enabled, is selected explicitly, and only then is the conversation opened (where a 404 just
shows "not found"). Notification permission is requested only on an explicit tap (banner), not at launch.

### Enabling real-time and push on production (operator prerequisites — not done by this PR)
1. Run the new migrations (`mobile_push_deliveries`; `mobile_devices` from Phase 1).
2. Real-time: `BROADCAST_DRIVER=pusher` with `PUSHER_APP_ID/KEY/SECRET/CLUSTER` (or `PUSHER_HOST/PORT/SCHEME` for Soketi), and a running queue worker (broadcast events are queued).
3. Push: `MOBILE_PUSH_ENABLED=true`; optional `EXPO_ACCESS_TOKEN` if Expo "enhanced push security" is on; a queue worker; outbound HTTPS to `exp.host`.
4. Mobile build: EAS project id in `app.config.ts` (`extra.eas.projectId`), Android FCM credentials and iOS APNs key uploaded to **Expo** (never to this repo), then a new development/production build.

### Limits
Expo push *receipts* (delayed delivery errors) are not polled; `DeviceNotRegistered` is handled from the immediate ticket. Existing socket
subscriptions at the provider cannot be revoked server-side on logout/lock — they receive only content-free notices and any re-read is
refused by the API; the app disconnects locally. Inbound MMS media is not previewed. Mobile 2FA and native calling are later phases.
