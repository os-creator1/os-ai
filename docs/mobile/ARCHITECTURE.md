# MotionGrove Mobile — Phase 1 architecture

Status: Phase 1 (foundation). Scope: a React Native (Expo) iOS/Android client for CRM lead conversations, contacts,
and — in a later milestone — voice calling and push. It is a **new frontend for the existing Laravel platform, not a
second CRM**: every rule below reuses a Laravel service boundary instead of re-implementing it.

Governance: built under `CLAUDE.md` route 3 (explicitly human-authorized manual lane). Claude pushes the branch;
ChatGPT opens the PR; the human merges. No deploy, no live SMS, no real calls, no production data.

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
→ Business is Active → `EntitlementManager` (`conversations` / `crm`). Then the record is fetched by **uid AND business_id**,
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
