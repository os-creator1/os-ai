# Business Email Foundation — Contract (Slice 1)

Status: implemented on `agent/business-email-foundation` (explicitly human-authorized manual lane).
Governing decision: Automations v2 contract D10 — *"Business-to-contact email
needs its own transport contract. Automations v2 invents no email provider
plumbing"* (`AUTOMATIONS-V2-WORKFLOW-ENGINE-CONTRACT.md` §10, §16, §22). This
document is that foundation. It deliberately does **not** add a `Send email`
Automation node or any email trigger.

## 1. What this slice delivers

1. A canonical, Business-scoped **connected email account** (Google or Microsoft).
2. Provider-neutral OAuth + send abstraction with Google (Gmail API) and
   Microsoft (Graph) adapters.
3. An encrypted credential lifecycle: connect, reconnect, refresh/rotate,
   revoke, disconnect.
4. **One canonical Business → Contact send service**, `BusinessEmailSender`,
   that the manual UI uses today and Automations will use later.
5. A durable, idempotent outbound message ledger and state machine.
6. A minimal **Settings → Email** surface (status, connect/reconnect/
   disconnect, send one email to a Contact).

### Explicitly deferred (later slices)

| Deferred | Why / what it needs |
|---|---|
| Inbound mailbox sync, reply detection, email-received triggers | Needs a mailbox-read scope (see §4.3) and a provider notification + authenticated-pull design. Nothing here reads the mailbox. |
| Conversations email UI / unified SMS+email thread | Avoids a broad Conversations redesign that could collide with other lanes. The message ledger is shaped to feed it. |
| Attachments, HTML bodies | Plain text only. `BusinessEmailProviderCapabilities` reports `htmlSend=false`, `attachments=false`, `inboundSync=false` explicitly. |
| `Send email` node / `SendEmail` in `WorkflowNodeType`, any central Automations registry change | Next slice. |
| Marketing / bulk / campaign email, AI email generation, sequences | Out of scope. |
| Email suppression / unsubscribe ledger | Not present in the repo (see §9). |
| Delivery receipts, bounces, opens | Not tracked; nothing is ever marked "delivered". |

## 2. Data model

Two tables, each with one purpose. No other table is touched.

### `business_email_accounts` — the Business's mailbox

* `business_id` is **UNIQUE**: V1 permits exactly **one** connected email
  identity per Business. That one row is therefore also the **default sender**.
  There is no `is_default`, and no provider/account id is ever stored in a
  workflow. (No authoritative contract authorizes multiple identities, so none
  were invented.)
* `provider` (`google` | `microsoft`), `state` (`pending` | `active` |
  `revoked` | `disconnected`), `mailbox_email`, `external_account_id`,
  `display_name`, `granted_scopes`, `connected_by_user_id`, lifecycle
  timestamps, `failure_classification`, `lock_version` (optimistic lock).
* `refresh_token_encrypted` uses Laravel's `encrypted` cast (the repository
  convention, as `BusinessGoogleConnection`). **No access token is ever
  stored**; one is derived per unit of work. The credential is hidden from
  serialization, and entering `pending` / `revoked` / `disconnected` NULLs it in
  the same conditional UPDATE that changes the state.
* `oauth_state_nonce` / `oauth_state_expires_at` hold the single-use OAuth
  state on the pending row (no extra table), `UNIQUE`.

Why not reuse an existing connection table: `external_calendar_connections` is
per-**User** with a Calendar-only consent; `business_google_connections` is
Business-scoped but its consent is Business Profile. **A Calendar or Business
Profile grant is never reused as permission to send mail** — separate config
(`config/business_email.php`), separate env vars, separate redirect, separate
scopes, separate token record.

### `business_email_messages` — the canonical Business↔Contact ledger

One row per **logical send**. `UNIQUE (business_id, operation_key)` is the
idempotency boundary; the row is also the state machine (§5).

Columns: `business_id`, `location_id` (**NOT NULL**, durable snapshot, RESTRICT),
`business_email_account_id`, `contact_id`, `direction` (only `outbound` is
written now), `source` (`manual` | `automation`), `automation_step_run_id`
(nullable, reserved), `sent_by_user_id`, `operation_key`, `provider`,
`provider_message_id`, `provider_thread_id`, `internet_message_id`,
`from_email` / `to_email` (snapshots), `subject`, `body_text`, `status`,
`attempts`, `claimed_at`, `next_attempt_at`, `failure_category`,
`failure_provider_code` (operator-only), `accepted_at`.

* `direction` exists so the later inbound slice appends to the **same** ledger
  rather than creating a second history.
* `from_email`/`to_email` are snapshots: reconnecting to a different mailbox
  never rewrites who an old message was sent from.
* No credential, token or provider payload is ever written here.

## 3. Business and Location semantics

* The account is **Business-wide**. `BusinessEmailSenderResolver::defaultFor(
  Business, ?BusinessLocation)` accepts a Location so the call shape will not
  change if per-Location senders are ever authorized; today the Location is
  only used to refuse a Location that is not this Business's.
* **Every outbound message resolves to exactly one Location (V1).** The
  Blueprint states that "the operational records [definitions] produce are
  Location-bound" and gives every Business a Primary Location. No contract
  permits a NULL Location on a *new* email operational record, so
  `business_email_messages.location_id` is **NOT NULL** (the invariant is
  mechanical, not a controller convention) and `BusinessEmailSender` refuses a
  send it cannot attribute. Resolution order, snapshotted at send time:
  1. the caller's **explicit Location** — must be this Business's *active*
     Location, else refused (`message_invalid`);
  2. otherwise the **authoritative Contact's** persisted Location, when it is a
     valid active Location of this Business;
  3. otherwise the Business's **single active Location** (the fallback already
     canonical for Contacts and Conversations, Contract 06/08B §5);
  4. otherwise **refused with `location_required`** — several active Locations
     and none provable, or none at all. It is never guessed and never persisted
     unscoped; the caller must choose a Location.
* The snapshot is **never re-derived from the Contact afterwards**: a Contact
  who later moves leaves the historical message in its original Location.
* Note (not a contradiction): Contract 06 lets *legacy SMS conversations* stay
  NULL where a multi-Location Business's Location cannot be proven. That is a
  backfill compromise for historical rows; it is not stated for new email
  records and the V1 Blueprint rule governs, so email does not copy it.
## 4. OAuth

### 4.1 Flow

`POST …/businesses/{b}/email/connect/{provider}` → signed state → provider →
`GET /email/oauth/{provider}/callback` (one fixed, tenant-free URI per provider,
because providers match `redirect_uri` exactly; `BusinessEmailOAuthConfig`
refuses to start a flow unless the configured redirect equals it).

State payload is exactly `{b, p, n, e}` (Business id, provider, nonce, expiry),
HMAC-signed with the application key, 10-minute TTL (clamped 60–3600 s). No
redirect target, no user id. Callback order — every failure is a **404** with
**zero** token exchange and no disclosure that a Business exists:

1. state present, signature valid, unexpired
2. route provider equals state provider
3. Business and account come **only** from the signed state
4. the full tenancy chain re-run for the *current* user (`ResolvesBusinessTenancy`)
5. `manage_business_email` permission
6. the callback actor equals the actor who initiated **this** attempt
   (checked *before* the nonce is consumed, so a wrong actor cannot burn it)
7. nonce consumed atomically (conditional UPDATE, exactly one row; replay = 0 rows)
8. only then is the code exchanged

The callback never authenticates or creates a user and never treats the
provider-reported mailbox as authorization; it is stored only as the sender
identity of an already-authorized Business. A grant that returns **no refresh
token** or **lacks the send scope** fails closed and the account stays
`pending` with no credential.

### 4.2 Scopes (minimal)

| Provider | Scopes | Notes |
|---|---|---|
| Google | `openid email https://www.googleapis.com/auth/gmail.send` | Google lets users untick scopes; a grant without `gmail.send` is refused. `access_type=offline`, `prompt=consent`, **no** `include_granted_scopes`. |
| Microsoft | `offline_access User.Read Mail.Send` | Tenant is `common` unless `BUSINESS_EMAIL_MICROSOFT_TENANT` is set (validated to `[A-Za-z0-9.-]`). |

### 4.3 Compliance flag for the next slice

This slice requests **no mailbox-read scope**. Inbound reply detection will need
`gmail.readonly` (a Google *restricted* scope — OAuth verification and an annual
security assessment) and `Mail.Read` for Graph. That materially changes the
privacy/compliance posture (read access to the owner's whole mailbox, not just
Contact mail) and is therefore a **human decision gate before the inbound
slice**, together with how non-Contact mail must be filtered/never stored.

### 4.4 Refresh, rotation, revoke, disconnect

* `accessTokenFor()` exchanges the refresh token per unit of work. A rotated
  refresh token (Microsoft) is stored encrypted and **only while the account is
  still `active`**, so a refresh racing a disconnect can never resurrect a
  credential on a destroyed row (tested).
* `invalid_grant` / a 401 → account `revoked`, credential destroyed; temporary
  provider failures only record `failure_classification`.
* `disconnect()` attempts provider-side revocation first (Google
  `oauth2.googleapis.com/revoke`; Microsoft has no public delegated revoke
  endpoint, so it is a documented no-op), then destroys the credential locally
  regardless of the provider result.
* Every state change is one conditional UPDATE guarded by `lock_version`; a lost
  race raises `BusinessEmailConcurrencyException`, never a blind retry. No
  provider call happens inside a transaction.
* Reconnect is offered from none / pending / revoked / disconnected; an
  *active* account is never swapped in place (disconnect first).

### 4.5 Configuration (env)

`BUSINESS_EMAIL_GOOGLE_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT`;
`BUSINESS_EMAIL_MICROSOFT_CLIENT_ID`, `_CLIENT_SECRET`, `_REDIRECT`, `_TENANT`;
optional `BUSINESS_EMAIL_STATE_TTL_SECONDS`, `BUSINESS_EMAIL_CONNECT_TIMEOUT`,
`BUSINESS_EMAIL_REQUEST_TIMEOUT`. Each `*_REDIRECT` must equal
`{APP_URL}/email/oauth/{google|microsoft}/callback`. A provider with missing or
mismatched configuration is simply not offered and fails before any row, nonce
or provider call exists.

## 5. Outbound send

### 5.1 The one service

`App\Library\BusinessEmail\BusinessEmailSender::send(BusinessEmailSendRequest): BusinessEmailMessage`

It receives **application identity only** and resolves the account, sender
identity, recipient address and provider adapter itself. Callers never see a
provider, an account id or a credential.

**The Contact is re-derived, never trusted.** The `Contacts` model a caller
passes is only a pointer to an id. The sender re-reads the Contact from
persistence by that id **constrained to the request Business**, and uses *that*
row for everything after: the Location resolution, the recipient address
(always read from the database) and the persisted `contact_id`. A model whose
in-memory `business_id` was forged to this Business, or whose `location_id` /
email is stale, therefore cannot reach another Business's Contact or send to an
outdated Location or address. A foreign, deleted or unknown Contact is one
indistinguishable refusal (`contact_unavailable`) with no row and no provider
call.

### 5.2 State machine and vocabulary

`queued → sending → accepted | failed | unconfirmed`

* **queued** — a local row exists; **no** provider call has been made.
* **sending** — a worker holds the claim; the provider call is (or was) in flight.
* **accepted** — the provider's API accepted the send request. **Not
  "delivered"**: no receipt/bounce/read signal is tracked and there is
  deliberately no `delivered` state (a test pins this).
* **failed** — the provider call did not succeed; `failure_category` says why.
  Retryable categories may be attempted again (bounded, §5.4).
* **unconfirmed** — a `sending` claim went stale (crash) or the outcome was
  ambiguous (timeout *after* the request was dispatched). The provider may have
  sent it, so it is **never** automatically re-sent; a human resolves it.

### 5.3 Idempotency

`UNIQUE(business_id, operation_key)`. The row is created **before** the provider
call, the call is outside any transaction, and the outcome is written back
conditionally on the claim. **A replay is bound to the original logical request.** Before a recorded row is
returned or retried, the new request must match the persisted logical identity:
the authoritative Contact, the *normalized* subject and body (trimmed, control
characters collapsed — incidental whitespace converges), the `source`, the
`automation_step_run_id` (both-ways, so a missing step is also a mismatch) and
the Location **when the caller names one**. Any mismatch fails
deterministically with `idempotency_conflict` and never reaches a provider. A
Location the caller left to be derived is not compared (the stored snapshot is
authoritative), and provider state, tokens, attempt counters and the sending
user are never compared. A repeated call with the same, matching key:

* `accepted` / `unconfirmed` → returns the recorded row, **no** provider call;
* `sending` inside its lease → returns the row (a live worker owns it); past the
  lease → becomes `unconfirmed`, never re-sent;
* `queued` → claims and sends;
* `failed` → re-attempts **only** if the category is retryable, attempts remain,
  and `next_attempt_at` has elapsed;
* works after the account was disconnected (replay is answered from the row);
* keys are scoped per Business (two Businesses may reuse a string).

A racing insert is absorbed: the unique-violation loser receives the winner's
row and is held to the **same** `assertSameLogicalRequest()` contract as an
ordinary replay before anything can reach a provider (a concurrent request with a
different payload gets `idempotency_conflict`), then is handled like any
recorded row — it is never blindly re-claimed, so a winner that is already
`sending`, `accepted`, `unconfirmed` or permanently `failed` is not re-sent. Graph sends are draft-then-send so ids exist; an ambiguous
failure on the send step is `unconfirmed`, never a second draft+send.

### 5.4 Failure taxonomy (provider-neutral)

| Category | Retryable | Meaning |
|---|---|---|
| `disconnected_account` | no | No active account |
| `authentication_expired` | no | Credential rejected; reconnect (account → `revoked` on `invalid_grant`/401) |
| `recipient_invalid` | no | Not exactly one valid address / provider rejected it |
| `permission_denied` | no | Grant lacks send permission |
| `provider_rate_limited` | **yes** | 429 / quota-style 403 |
| `temporary_provider_failure` | **yes** | 5xx / 408 / connection failure |
| `permanent_provider_failure` | no | Other 4xx |
| `send_limit_exceeded` | no | Local abuse bound (below) |
| `contact_unavailable` | no | Contact not in this Business |
| `message_invalid` | no | Bad subject/body/key, or a foreign/archived explicit Location |
| `location_required` | no | No Location could be proven (several active, or none): the caller must choose one |
| `idempotency_conflict` | no | The operation key was already used for a materially different request |

Raw provider payloads are never exposed or stored. `failure_provider_code` is a
sanitized ≤64-char token (`[A-Za-z0-9_.:-]`), operator-only, hidden from
serialization and never rendered to a customer. The log line carries ids and the
code only — never a token, recipient, subject or body.

Pre-flight refusals (no account, foreign/unknown Contact, no usable address,
invalid content, abuse bound) throw `BusinessEmailSendRefusedException` with
**zero** rows and **zero** provider calls. Provider-stage failures are persisted.

### 5.5 Recipient rules

A Contact has no email column: its email is a `contacts_custom_field` value with
tag `EMAIL` (as `ContactDirectory` reads it). A Contact with **no** valid
address, or **several different** valid addresses, is refused — the sender never
guesses a recipient. Addresses are normalized (trim, lowercase), strictly
validated, and control characters / header-injection attempts are rejected. A
Contact outside the Business is indistinguishable from a missing one.

### 5.6 Abuse and rate bounds

Counted from the ledger over the last hour (`config/business_email.php`):
**200 per Business** and **5 per Contact** (soft bounds under concurrency).
Retries: at most **3 attempts** per operation key, with backoff `60 s` then
`300 s`; no automatic infinite retry anywhere; HTTP timeouts bounded (5 s
connect / 20 s request).

### 5.7 Content and security

Plain text only; no HTML is accepted, stored or rendered, so there is no raw
HTML execution surface. Subject/body are length-bounded (200 / 20 000). All
MIME header values (From, To, Subject, Message-ID domain) are stripped of
control characters, so no field can inject another header (tested, including the
Message-ID domain).

### 5.8 Usage / metering — no policy invented

The Customer-Experience contract classes "email delivery" as a **non-transport
paid service** *where applicable* (`CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-
AUTOMATIONS-CONTRACT.md` §§ "BYO does not make a Business free", T-BYO-5).
Sending through the Business's **own** Google/Microsoft account has **no
per-send platform cost**, so this slice **does not reserve, debit or invent a
fee**. If a platform-paid premium email provider is ever added, it plugs in
behind `BusinessEmailProvider` (via `BusinessEmailProviderRegistry`) and the
sender gains a reserve/settle step there; that is a **pricing decision for a
human**, not made here.

## 6. Permissions and View As

* `manage_business_email` (new, default **false**, like
  `manage_google_business_profile`) governs connect / reconnect / disconnect.
  Not every staff member can connect or disconnect the Business's mailbox.
* Sending uses the existing conversation/contact keys (`chat_box` + `view_contact`);
  no parallel authorization system. Viewing the page needs either email key.
* Every action runs Workspace → Business → `BusinessRouteAccess` → active
  Business via `ResolvesBusinessTenancy`; failures are 404 (never 403), and a
  missing permission is a 401 (same as `authorize()`).
* **View As** (cross-Workspace agency): connect, disconnect, send and the OAuth
  callback are added to `ViewAsProhibitedActions` — a viewing agency actor never
  connects a client's mailbox nor sends mail *as* the client. Reading the status
  page stays allowed. (Letting an agency operator send as a client is a product
  decision that was not made here.) Financial/owner-only consent restrictions
  are untouched.
* **Location ACL (Contract 08B semantics, via the canonical
  `LocationAccessGuard` — no second ACL algorithm).** Business tenancy is not
  enough for a Location-bound Contact. `BusinessEmailLocationScope` is a thin
  adapter over the guard: a Contact with a persisted `location_id` is
  visible, selectable and sendable only when the actor can access that
  Location (re-checked from persistence); a Contact whose Location was never
  proven (`NULL`, legacy) is **not denied on its own**, exactly like
  `ContactsController` / `ChatBoxController`. An inaccessible Contact uid, an
  unknown uid and another Business's uid are the **same 404** with no message
  row and no provider call. The actor's Location reach is taken **once** per
  request and pushed into SQL (the picker's `whereNull OR whereIn`, the history
  list's `whereIn`), so lists stay bounded and the query count does not grow
  with rows. An explicit Location chosen on the form must be an active Location
  of the Business that the actor may use, else 404. The sender itself carries no
  actor ACL (Automations run without an actor); the ACL lives on the customer
  surface.
* No Email entitlement gate: no `PlatformFeature` exists for email and none was
  invented.

## 7. Settings → Email surface

`GET /{workspace}/businesses/{business}/email` — status (provider, mailbox,
connected-at, reconnect hint), Connect / Reconnect / Disconnect, a send form
(Contact picker bounded to **50**, per-render idempotency token, plain text),
and the **10** most recent emails. Everything on the page is scoped by the actor's Location reach: the picker, the
Location chooser (shown only when the Business has more than one active
Location, and listing only Locations the actor may use) and the history list.
Query count is constant in the number of Contacts and messages (tested). Entry: Business Settings hub → Communication → **Email**.

## 8. Automations integration seam (for the next slice)

Do **not** add `SendEmail` here. The follow-up slice consumes this foundation as
follows. **Status:** consumed — `send_email` now exists as an Automations node
(`SendEmailNodeExecutor`, `AUTOMATIONS-MERGED-FOUNDATIONS-INTEGRATION.md` §7), calling
`BusinessEmailSender::send()` with `automation:{workflow}:{step run}:email` and
`BusinessEmailSource::Automation`. Retryable failures are terminal for the step
(the engine never re-runs an External step), and suppression remains open (§9).

**Send action API.** Call
`app(BusinessEmailSender::class)->send(new BusinessEmailSendRequest(...))`.

**Required inputs**

| Input | Notes |
|---|---|
| `business` | The enrollment's Business (never from workflow config). |
| `contact` | The enrolled Contact (a Contact outside the Business → `contact_unavailable`). |
| `subject`, `bodyText` | Plain text, bounded. |
| `operationKey` | **Deterministic** per step run, e.g. `automation:{workflow_id}:{step_run_id}:email`. Same key ⇒ same logical send ⇒ never a second provider call. |
| `source` | `BusinessEmailSource::Automation`. |
| `automationStepRunId` | Persisted on the row (attribution; see below). |
| `location` | Optional; otherwise the authoritative Contact's Location → the single active Location → **refused `location_required`** (never NULL). An Automation run already resolves to exactly one Location, so it should pass it. |
| `sentByUserId` | `null` for automation. |

**Sender resolution.** "Send from this Business's default email identity" =
`BusinessEmailSenderResolver::defaultFor($business)`: the Business's single
`active` account. No account id or provider is ever stored in a workflow.

**Failure semantics for the executor**

* `BusinessEmailSendRefusedException` (`category`) — nothing happened, no row:
  record the category as the step failure. Terminal for that step. This includes
  `location_required` and `idempotency_conflict` (a changed payload under an
  existing key is a caller bug and is never sent).
* Returned `status = accepted` → step success.
* `failed` + retryable category (`provider_rate_limited`,
  `temporary_provider_failure`) → release/re-run the step later; calling again
  with the **same** key is safe and is gated by `next_attempt_at` and the
  3-attempt budget (an early re-run makes no provider call).
* `failed` + non-retryable → terminal step failure with the category.
* `unconfirmed` → terminal for the step and **must not** be auto-retried
  (surface for a human).
* `sending` (fresh lease) → another worker owns it; treat as in progress.

**Usage.** No wallet reserve today (see §5.8). Slot to add it: inside
`BusinessEmailSender::attempt()` before the provider call, keyed on the same
`operationKey`.

**Events.** This slice emits **no** domain events: there is no reliable
consumer yet and no vanity events were created. The durable record is the
`business_email_messages` row itself (transactionally consistent, Business- and
Location-attributed, minimal PII). The later slice should dispatch an
after-commit event from the sender's finalize step (`accepted` / `failed`)
carrying only `message_id` / `business_id`.

**Occurrence identity.** An outbound-email occurrence is
`business_email_message:{id}` (equivalently the `uid`); it is unique and stable
because the row is created once per `(business_id, operation_key)`. A future
`email_received` / `email_replied` trigger should use the inbound ledger row id
in the same way (`business_email_message:{id}`) so a redelivered notification
composes the same occurrence key and loses the same unique claim — the identical
pattern `MessageReceivedTriggerSource` uses for SMS (`report:{id}`).

**Self-reply prevention.** Outbound Automation mail is distinguishable by
`source = automation` + `automation_step_run_id` on the row (the email analogue
of `reports.automation_step_run_id`). The inbound slice must (a) skip any
message whose `from` is the connected mailbox and any `provider_message_id` /
`internet_message_id` already in the ledger, and (b) resolve the answered
message by `provider_thread_id` so `MessageReceivedTriggerSource`-style rules
(never re-trigger off its own send; causation depth; cooldown) can be applied.

**Remaining blockers before `Send email` / email triggers**

1. Add `send_email` to the Automations enums/registry/compiler and a node
   executor calling the service above (central files — a separate lane).
2. Decide the **suppression / unsubscribe** model (§9) before any
   promotional-style Automation email launches.
3. Decide the metering position if/when a platform-paid provider exists (§5.8).
4. Human decision on the mailbox-read scope for inbound (§4.3).
5. Inbound design: provider notification (Gmail `watch`+Pub/Sub, Graph
   subscription) as an *untrusted signal*, authenticated pull as truth, cursor,
   renewal, dedupe, safe tenant-scoped Contact matching with an explicit
   ambiguous state (a Business may hold several Contacts with one email), and
   a decision not to persist non-Contact mailbox mail.

## 9. Consent and suppression

No email suppression/unsubscribe ledger exists in the repository, and SMS
`STOP`/opt-out semantics are **not** reused for email. This slice sends only
**manual, person-initiated** emails to a Contact; it does no compliance policy.
A canonical Business email suppression ledger is a **declared dependency** for
any marketing-like or Automation-driven email.

## 10. Verification

Own disposable database: `ultimatesms_testing_email` (accepted by
`Tests\Support\TestDatabaseSafety`; not shared with the Website or Tags lanes).
No real Google/Microsoft call is made: provider adapters are exercised only with
`Http::fake()` under `Http::preventStrayRequests()`, and service/HTTP tests use
an in-memory `FakeBusinessEmailProvider`. Test files:
`tests/Feature/BusinessEmail/{BusinessEmailSenderTest,BusinessEmailConnectionTest,
BusinessEmailProviderAdapterTest,BusinessEmailSettingsHttpTest}.php`.
