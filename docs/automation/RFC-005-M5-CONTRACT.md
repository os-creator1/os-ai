# RFC-005 Milestone 5 — Metered Feature Classification

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This contract authorizes drafting this one document only.** Merging it
authorizes the bounded M5 implementation this document specifies — to be
made as its own separate, later, explicitly bounded implementation PR.
Merging this document does **not** itself write any `app/`, `database/`,
`routes/`, `config/`, or `resources/` file, does not flip
`Conversations`' classification to `is_metered = true`, does not activate
any real retail/provider rate, does not begin M6 in any way, and does not
authorize any live charge to any real Business.

**Revision note (this refinement pass, second round):** an independent
review found that the client-supplied idempotency token (introduced in
the prior refinement) solves HTTP-request identity but not provider-side
retry safety; that the rate-scope fix only bounded destination country,
leaving customer-plan and sending-server variance unresolved; and one
internal inconsistency between §6.1's prose and §12's file scope for
`ChatBoxController::reply()`. All three are re-audited and resolved
below. This is still pre-merge contract refinement — no correction round
is consumed.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m5-contract`, based on `origin/main`
  at `24fd1730e535d2360bb3a6fef7caf97f3272457c` (unchanged this pass).
- **Human product decision, locked, not reopened:** the first real
  metered feature is `PlatformFeature::Conversations`.
- `maximum_correction_rounds: 2`, unconsumed.
- Any path required during implementation but absent from §12's
  numbered allowlist is a stop-and-report condition. Stop threshold is
  the allowlist's final count **plus one**.
- This refinement makes **zero** application changes — only this
  document is touched.
- **Audit discipline, this pass:** `UsageWalletManager::reserve()/commit()/release()`
  re-read line-by-line together with `EloquentBusinessUsageReservationRepository::findByIdempotencyKey()`;
  the `business_usage_reservations` migration's exact columns; the full
  `SendCampaignSMS::sendPlainSMS()` method boundaries (lines 71-13510)
  and its Twilio-gateway branches specifically (lines 460-520, 13511-
  13558 region); `ExpireStaleUsageReservations`; `EntitlementManager::assertPlatformAdministrator()`;
  `ChatBoxController::sent()`/`new()`/`reply()` in full, including exact
  `$input` construction in each; `resources/views/customer/ChatBox/index.blade.php`'s
  `enter_chat()` handler in full (lines 421-513). The vendored Twilio SDK
  package itself is **not present** in this worktree (`vendor/twilio` does
  not exist here — dependencies are not installed in this contract-
  drafting environment) and its exception-hierarchy internals are
  therefore explicitly *not* verified by this audit; §6.1 states exactly
  what this limits.

---

## 1. This contract's own exact file scope

Exactly one file: `docs/automation/RFC-005-M5-CONTRACT.md`.

---

## 2. Preflight — verified

Unchanged: `origin/main` at `24fd1730e535d2360bb3a6fef7caf97f3272457c`;
no RFC-004 or already-merged RFC-005 M1–M4 file is modified.

---

## 3. Mandatory repository audit — findings

### 3.1–3.4

Unchanged from the prior pass: `Conversations` remains the only
classification M5 touches; `UsageWalletManager`'s reserve/commit/release/
rate/classification machinery requires no new wallet-layer code (with
one addition below, §3.4a); `EntitlementManager`/`RealUsageAuthorizationGateway`
remain unbypassed with no new denial key; `quickSend()` is shared by six
call sites and the `conversation_context` discriminator remains the
resolution for caller scope.

### 3.4a `reserve()`'s idempotency lookup — exact mechanics, re-audited

Direct re-read of `UsageWalletManager::reserve()` (line 233) together
with `EloquentBusinessUsageReservationRepository::findByIdempotencyKey()`
(`return $this->query()->where('idempotency_key', $idempotencyKey)->first();`)
confirms, precisely, the independent review's central finding:

- **The lookup is a bare `WHERE idempotency_key = ?`** — not scoped by
  `business_id`, and not filtered by `status` in any way.
- **`reserve()` returns `ReservationResult(true, $existing->id, null)`
  identically whether the row was just created or already existed in
  *any* status** (`Pending`, `Committed`, `Released`, or `Expired` — the
  `business_usage_reservations` migration's `status` column, confirmed
  by direct read, carries no fifth value distinguishing these). The
  caller cannot tell, from `reserve()`'s return value alone, which case
  occurred. **The prior pass's contract proceeded straight to the
  provider call after any `authorized: true` result — this is exactly
  the defect the independent review identified, and it is real.**
- **No `release_reason`/`released_reason` column exists anywhere on
  `business_usage_reservations`** — a `Released`/`Expired` row carries no
  record of *why* it left `Pending` (a genuine definitive rejection vs.
  the pre-existing `ExpireStaleUsageReservations` job's blanket
  30-minute TTL sweep, confirmed unchanged, §3.4b). This rules out any
  design that tries to infer safety-to-retry from the terminal row alone.
- **`idempotency_key` carries a `unique` constraint.** A terminal
  (`Released`/`Expired`) row permanently occupies its key — a genuinely
  new attempt for the same logical send, once a prior attempt has
  reached a terminal state, **must** use a different key. Reusing the
  same key against a terminal row only ever returns that same terminal
  row, forever.

### 3.4b `ExpireStaleUsageReservations` — re-confirmed, unmodified, and now load-bearing

`app/Jobs/Usage/ExpireStaleUsageReservations.php` calls
`UsageWalletManager::expireStaleReservations()` unconditionally across
every feature — no per-`feature_key` carve-out exists, and none is
proposed. This job is not modified by M5. Its existing, already-tested
30-minute (`RESERVATION_TTL_MINUTES`) blanket release behavior becomes
the deliberate backstop for §6.1's ambiguous-outcome handling below —
not a gap M5 works around, but a bound M5 explicitly relies on.

### 3.5 `SendCampaignSMS::sendPlainSMS()` — bounded audit of provider evidence

**`sendPlainSMS()` spans roughly 13,440 lines** (`app/Models/SendCampaignSMS.php:71`
to just before `sendVoiceSMS()` at line 13511), containing a large,
unenumerated number of per-`SendingServer`-type gateway branches (custom
HTTP, several dozen named providers, Twilio, and others). **Auditing
every branch exhaustively is not bounded work and is not attempted.**
Instead, the audit targeted the one gateway type name-checked against
this repository's own confirmed evidence of a durable reference:

- **Twilio (`SendingServer::TYPE_TWILIO`/`TYPE_TWILIOCOPILOT`, lines
  460-520):** `$client->messages->create($phone, [...])` — on a normal
  (non-throwing) return, `$get_response->sid` is a real, provider-
  assigned, durable message identifier, already captured into the
  existing `$get_sms_status` string (`'Delivered|'.$sid` or
  `$status.'|'.$sid`). This **is** a genuine queryable provider
  reference — Twilio's own Messages API can be queried by this SID
  after the fact. No other gateway branch was found, in the bounded time
  available, to expose an equally clear, uniformly-present reference;
  most raw `curl_init()` branches parse an ad hoc provider-specific
  response body with no guaranteed persistent identifier.
- **On a thrown exception** (`catch (ConfigurationException|TwilioException $e)`,
  same lines): the existing code treats *any* such exception uniformly
  as `'Rejected'`. **This audit cannot verify, from this repository
  alone, whether every `TwilioException` genuinely means "Twilio's API
  received and definitively rejected the request" (safe, no message
  queued) as opposed to "the underlying HTTP call failed for a network/
  timeout reason after potentially reaching Twilio's servers" (ambiguous
  — Twilio may have queued the message despite the local exception).**
  `vendor/twilio` is not present in this worktree to inspect the SDK's
  own exception hierarchy, and asserting a clean split without reading
  it would be exactly the kind of unverified claim the audit
  instructions warn against. **`ConfigurationException` specifically is
  documented, by its own name and by general Twilio SDK convention, as a
  pre-call credential/configuration validation failure** — a real
  distinction exists in principle, but this audit does not certify its
  precise boundary against the vendor source, and does not build logic
  that depends on that boundary being exact.

**Direct answer to the audit's four required distinctions:**

- **Definitive pre-send failure:** yes, reachable and safe — every
  denial that occurs before `sendPlainSMS()` is ever called (entitlement
  denial, `reserve()`'s own wallet-capacity denial) is unambiguous by
  construction; no reservation reaching `Pending` is at risk here.
- **Definitive provider rejection/non-delivery:** reachable **only** for
  the sub-case where `sendPlainSMS()` returns *without throwing* and the
  returned status is evidenced as clearly non-accepted (e.g. Twilio's
  `$get_response->status` is present and is not `queued`/`accepted`) —
  this is a real response the provider actually gave us, not a guess.
- **Durable provider success:** reachable identically — a non-throwing
  return with an accepted status, carrying the durable `sid` (Twilio) or
  equivalent.
- **Ambiguous/unknown outcome:** **any thrown exception**, uniformly,
  given the unverified exception-hierarchy limitation above. This audit
  does not attempt to sub-classify exceptions into safe-to-retry vs.
  not — it treats the entire thrown-exception surface as one ambiguous
  bucket, which is the conservative, honest position given what could
  actually be verified.

**No existing reconciliation/manual-resolution mechanism exists today**
for a stuck `Pending` reservation of any kind — only the blanket TTL
release job (§3.4b). §6.1 designs the minimal bounded addition.

### 3.6 Rate dimension — re-audited: country alone does not bound it

**The prior pass's destination-country-only restriction is confirmed
insufficient, exactly as the independent review states.** Re-confirming
`quickSend()` lines 95-219: even within one authorized destination
country, price still varies by (a) the specific Customer's own
`CustomerBasedPricingPlan` row (which, when present, is looked up by
`user_id` + `country_id` and **overrides** the plan-level
`PlansCoverageCountries` default entirely — two different Customers in
the same country can have two different negotiated `plain_sms` prices),
and (b), conditionally, by the specific `sending_server` selected, when
`config('app.gateway_wise_billing')` is enabled (`SendingServerBasedPricingPlans`,
keyed by `sending_server` + `country_id`, overriding again,
independently of (a)). **A destination-country allowlist alone leaves
both of these dimensions unbounded.** No internal/provider-cost figure
is tracked anywhere in this legacy pricing surface at all — only the
customer-facing retail price is read by `quickSend()` — and no
`currency_id` column exists on any of the three legacy pricing tables,
unlike `business_usage_rates.currency_id`'s mandatory explicit FK.

**Resolution — Option A (an explicitly bounded pilot), chosen over B and
C:** Option B (RFC-005's retail rate intentionally *replacing* legacy
per-customer pricing for the scoped send) is not adopted — this contract
carries no merged RFC authority to override a Customer's own negotiated
legacy price with a platform-wide figure, and inventing that authority
here would itself be an unauthorized product decision, not a bounded
implementation choice. Option C (a real per-dimension rate-schema
expansion) is confirmed, again, to be a genuine schema change out of
M5's own authorized scope. **Option A — pinning every variable dimension
to one explicit, human-named value, so that exactly one real,
unambiguous send context remains — is both correct and mechanically
available without new schema:**

> A qualifying M5 metered send additionally requires **all** of:
> - the resolved `Business` id is present in
>   `config('usage_billing.conversations_metering.pilot_business_ids', [])`
>   (an explicit small allowlist of one or more human-designated pilot
>   Businesses — not merely "any Business," closing the customer-plan-
>   variance gap by pinning the Customer as well, since exactly one
>   Customer/`CustomerBasedPricingPlan` context corresponds to each
>   named pilot Business);
> - the resolved destination `country_id` is present in
>   `config('usage_billing.conversations_metering.authorized_country_ids', [])`
>   (unchanged from the prior pass);
> - the resolved `SendingServer`'s own gateway type
>   (`$sending_server->settings`) is one of
>   `SendingServer::TYPE_TWILIO`/`TYPE_TWILIOCOPILOT` — the only branch
>   this audit confirmed exposes a durable provider reference (§3.5) —
>   via a further config gate,
>   `config('usage_billing.conversations_metering.authorized_gateway_types', [])`,
>   populated with those two type constants only when a human has
>   confirmed the pilot Business's own sending-server assignment for the
>   authorized country actually resolves to one of them.
>
> All three lists are empty by default — fail-closed, no country/
> Business/gateway qualifies until a human populates them, mirroring
> `config/usage_billing.php`'s own existing "no default invented, must
> fail closed" convention (`webhook_event.retention_days`).

This pins Business (hence Customer/negotiated-plan), country, and
gateway type simultaneously — the three real variance dimensions this
audit found — leaving exactly one real, nameable pricing context per
authorized combination, which one `business_usage_rates` row can
truthfully represent, **provided** the human supplying that row's
numbers (§9.2) supplies figures that genuinely reflect that one pinned
context's real retail price and real provider cost — not an average, not
a guess, and not the legacy system's own stored `options` price
reinterpreted without independent verification (that stored price is
retail only; it does not prove provider cost, and this contract does not
claim otherwise).

### 3.7 Testability

Unchanged: stub at the `Campaigns::sendPlainSMS()` method boundary,
never the transport layer beneath it.

---

## 4. Contract status model

Unchanged.

---

## 5. Exact M5 scope — five pinned dimensions, all locked

### 5.1 What qualifies as an M5-metered plain SMS send

A `quickSend()` invocation qualifies for RFC-005 wallet metering if and
only if **all** of the following hold:

1. `$input['sms_type']` resolves to the `plain` branch.
2. The call originated from `ChatBoxController::sent()` or `::reply()`
   (`conversation_context` flag, §7).
3. `platform_feature_usage_classifications.conversations.is_metered` is
   `true`.
4. The sending Customer's Workspace owns exactly one Business
   (`$business->workspace->businesses()->count() === 1`) **and** that
   Business's id is present in `conversations_metering.pilot_business_ids`
   (§3.6) — both, not either; the Workspace-cardinality check remains a
   cheap structural safety net independent of the pilot allowlist.
5. The resolved destination `country_id` is present in
   `conversations_metering.authorized_country_ids` (§3.6).
6. The resolved `SendingServer`'s gateway type is present in
   `conversations_metering.authorized_gateway_types` (§3.6).

Any send failing any one condition takes the exact same legacy
`sms_unit` code path unconditionally, with zero behavior change.

### 5.2–5.4

Unchanged in substance from the prior pass: non-plain channels, and
every non-`ChatBoxController` caller, are always legacy; the rate/
classification schema's immutability and versioning govern how a later
milestone could widen scope, without altering M5's own history.

---

## 6. Reservation lifecycle — exact, locked order, with the state machine the independent review required

For a qualifying send (§5.1), inside `EloquentCampaignRepository::quickSend()`:

1. Business/ownership/destination/gateway-type guards (§5.1 items 4-6).
   Any failure → fall through to unmodified legacy code.
2. `EntitlementManager::decide(...)`. Denial → existing error response,
   no reservation, no provider call.
3. **Capture `$preCallAt = Carbon::now();` immediately before calling
   `reserve()`.** This is the mechanism the state machine below depends
   on — not a heuristic tolerance, an exact ordering guarantee (§6.1).
4. `$reservation = $walletManager->reserve($business, PlatformFeature::Conversations->value, $idempotencyKey, (string) $sms_count);`
   using the business-namespaced key (§6.1). Denial (`insufficient_balance`
   etc.) → existing error response, no provider call, no reservation
   row was ever created for this attempt.
5. **New — mandatory reservation-state inspection, exactly per the
   independent review's own instruction not to rely on `reserve()`'s
   own idempotency alone:** fetch
   `$row = app(BusinessUsageReservationRepository::class)->findById($reservation->reservationId);`
   (an existing, already-public contract method — no new repository code)
   and branch on `$row->status`:
   - **`Committed`:** do **not** call the provider. Return the existing
     success-shaped response (a generic "already sent" message — no
     historical `Reports`/`ChatBoxMessage` re-derivation is attempted).
     Done. (Resolves independent-review case A.)
   - **`Released` or `Expired`:** do **not** call the provider using this
     token. Return a distinct, non-error-coded "could not confirm this
     message was sent; compose and send again if you still want to"
     response. The client **must** regenerate its token before any
     further attempt (§6.1). (Resolves case B.)
   - **`Pending`, and `$row->reserved_at >= $preCallAt`:** this is a
     genuinely fresh row created by *this* call (proven, not
     heuristic — `reserve()`'s own `Carbon::now()` for `reserved_at` is
     generated strictly inside its transaction, which cannot begin
     before step 3's capture). **Proceed to the provider call, step 6.**
   - **`Pending`, and `$row->reserved_at < $preCallAt`:** a pre-existing,
     still-unresolved reservation from a *prior* attempt — the
     ambiguous case. Do **not** call the provider. Log
     `Log::warning('m5_conversations_ambiguous_pending_retry', ['reservation_id' => ..., 'business_id' => ...]);`.
     Return the same "still processing, please wait" response as the
     fresh-ambiguous outcome below. The client **must retain** the same
     token (§6.1). (Resolves case C — the retry never reaches the
     provider a second time while the first attempt's true outcome is
     unknown.)
6. **Provider send** (only reached for a genuinely fresh `Pending` row):
   `$campaign->sendPlainSMS($preparedData)`.
7. **Non-throwing return, accepted/`Delivered` status:**
   `$walletManager->commit($reservation->reservationId, (string) $sms_count);`.
8. **Non-throwing return, evidenced non-accepted status** (a real status
   the provider gave us, e.g. Twilio's own non-`queued`/`accepted`
   value): `$walletManager->release($reservation->reservationId);` —
   genuinely definitive, safe. Client regenerates its token.
9. **Any thrown exception (§3.5's uniform ambiguous treatment):** do
   **not** call `release()`. Leave the reservation `Pending`. Log the
   same structured warning as step 5's ambiguous branch. Return the same
   "still processing" response. Client **retains** its token. (Resolves
   case D — an exception can occur after the request reached the
   provider, so releasing immediately is exactly the undercharge risk
   the independent review named; this withdraws the prior pass's §6 step
   9, which released on every exception.)

**Residual, bounded, explicitly-accepted risk:** an ambiguous outcome
(step 5's stale-`Pending` branch, or step 9) left unresolved is released
by the pre-existing, unmodified `ExpireStaleUsageReservations` job after
its existing 30-minute TTL (§3.4b) — defaulting to "not charged." This
is a real, bounded, single-send-value risk (a genuinely-delivered
message whose outcome is never confirmed within 30 minutes goes
unbilled), not a double-charge risk and not a data-integrity risk, and
is symmetric with the pre-existing legacy system's own equivalent gap
(§3.4 of the prior pass: the legacy `sms_unit` decrement also only
happens after a confirmed `Delivered` response, so a crash before that
point already left the legacy system unable to bill a possibly-real
send). M5 does not introduce a new class of risk here; it bounds an
already-existing one to a known 30-minute window and adds a manual
escape hatch (§6.2) the legacy system never had.

### 6.1 Idempotency key — business-namespaced, client-sourced, precisely bounded

**Business-namespacing, closing the independent review's flagged gap:**
`hash('sha256', 'conversations_plain_sms:'.$business->id.':'.$idempotencyToken)`
— the resolved Business id is folded in **before** hashing, so
`reserve()`'s global (non-business-scoped) `findByIdempotencyKey()`
lookup can never resolve another Business's row from a client-supplied
raw token, even in the astronomical-collision case, since the composed
key is unique per `(business, token)` pair by construction, not by trust
in the client alone.

**Client-token generation and propagation, unchanged in mechanism from
the prior pass, corrected in scope (§7/§12) for `reply()`'s manual
`$input` construction and extended for `sent()`'s full-page-reload
retry case:**

- **`ChatBoxController::reply()`:** the JS-held token in
  `index.blade.php`'s `enter_chat()` (unchanged from the prior pass —
  generated lazily, included in `FormData`).
- **`ChatBoxController::sent()`/`::new()`:** **revised this pass.** A
  static per-page-load hidden field alone is insufficient — `sent()`'s
  own error path is a redirect back to a freshly-rendered `new()` page,
  and a fresh page load always renders a fresh hidden value if nothing
  persists it, breaking exactly the retry-linkage the ambiguous-outcome
  state machine (§6) depends on. **Fix:** the in-flight token is held
  server-side in the session (`session('m5_conversation_pending_token')`),
  not only in the rendered HTML:
  - `ChatBoxController::new()` (the GET action): if the session key is
    set, pass its value to the view for the hidden field; otherwise mint
    a fresh `Str::uuid()`, store it in the session, and pass that.
  - `ChatBoxController::sent()` (the POST action): on the success or
    terminal (`Released`/`Expired`-hit) response paths, clear the
    session key (`session()->forget('m5_conversation_pending_token')`)
    so the next page load mints fresh; on the ambiguous response paths
    (step 5's stale-`Pending` branch, or step 9), leave the session key
    untouched, so the next `new()` page load reuses the identical token
    and the retry lands on the same reservation.

**Proof — two legitimate distinct sends can never collide:** each
distinct compose action (a `success`-cleared JS token for `reply()`; a
session-cleared, freshly-minted token for `sent()`) is collision-
resistant by construction, and the business-namespace prefix guarantees
no cross-Business or cross-feature collision even in the residual case.

**Proof — a crash/retry of the same logical send reuses the same key:**
for `reply()`, per the existing `error`-branch-does-not-clear-the-
text-or-token behavior (unchanged, prior pass). For `sent()`, per the
session-persisted token surviving the full-page-reload redirect cycle
(new this pass) — directly closing the gap the independent review's
"provider side effect" concern would otherwise have left open for this
specific entry point.

**Honest residual limitation, narrowed further this pass:** a literal
same-tab, same-session double-click landing two overlapping in-flight
requests before `enter_chat()`'s existing button-disable takes effect
remains outside scope (unchanged from the prior pass) — but two
*sequential* attempts, including across a full page reload, are now
correctly linked for both entry points, which was not true of the prior
pass's `sent()` design.

### 6.2 Manual ambiguous-reservation resolution — new, bounded, no new wallet-layer code

`app/Console/Commands/ResolveAmbiguousUsageReservation.php`:
`usage:resolve-reservation {reservation-id} {--outcome=} {--actor-user-id=} {--reason=}`,
`--outcome` restricted to `sent`/`not-sent`. Same actor-authority
mechanism as §9.1 (`is_admin` check, identical pattern). Calls
`$walletManager->commit($reservationId)` (no `$finalQuantity` override —
the reservation's own already-stored `estimated_quantity` is correct
per §8's equality proof) when `--outcome=sent`, or
`$walletManager->release($reservationId)` when `--outcome=not-sent`.
**Zero new `UsageWalletManager` code** — both methods are already
public and already safe/idempotent on exactly the states this tool
targets (`commit()` rejects a non-`Pending` row; `release()` is
idempotent on a terminal row). This is the operator's tool for
resolving §6 step 5/9's ambiguous cases within the 30-minute TTL window,
using out-of-band evidence (e.g., the target gateway's own dashboard/API,
using the durable reference captured in the logged warning where one
exists, §3.5).

---

## 7. `quickSend()` discriminator

Unchanged mechanism from the prior pass; the guard now evaluates all six
§5.1 conditions. `ChatBoxController::sent()`/`::reply()` each set
`$input['conversation_context'] = true;`.

**Corrected this pass (independent review's third issue):** §12 item 1
previously said `ChatBoxController.php` gains *only* the
`conversation_context` line and "no other line changes." This was
inconsistent with §6.1's own design, and is corrected:

- `sent()`'s existing `$input = $request->except('_token');` (line 164)
  **already, automatically, includes `idempotency_token`** once the view
  submits it as a form field — no additional line is needed in `sent()`
  itself for token propagation (only `conversation_context`, plus the
  session read/clear logic in `new()`/`sent()` per §6.1).
- `reply()`'s `$input` array is **manually constructed key-by-key**
  (`['sender_id' => ..., 'originator' => ..., 'sms_type' => ..., 'message' => ..., 'exist_c_code' => ..., 'user' => ...]`)
  and does **not** automatically forward arbitrary request fields.
  `reply()` therefore requires an explicit second line beyond
  `conversation_context`:
  ```php
  $input['idempotency_token'] = $request->input('idempotency_token');
  ```
  plus inline validation (no dedicated `FormRequest` exists for `reply()`
  today, and none is added, consistent with "smallest bounded"):
  ```php
  if (! $request->filled('idempotency_token') || ! Str::isUuid($request->input('idempotency_token'))) {
      return response()->json(['status' => 'error', 'message' => __('locale.exceptions.something_went_wrong')], 422);
  }
  ```

Both entry points are now mechanically complete for token propagation
and validation; §12's file description is corrected to match.

---

## 8. Quantity

Unchanged: `$sms_count` from `SMSCounter::count()` is the sole source,
identical for `estimatedQuantity` and `finalQuantity`.

---

## 9. Pricing activation

### 9.1 The command and actor-authority mechanism

Unchanged from the prior pass: `app/Console/Commands/ActivateUsageFeatureRate.php`,
`--actor-user-id=<id>` required, validated by reproducing
`EntitlementManager::assertPlatformAdministrator()`'s exact `users.is_admin`
check (read, not modified, not exposed). §6.2's new command reuses the
identical authority-check pattern.

### 9.2 Unresolved human decisions — now three, all gate go-live, none fabricated

1. **Numeric rate** — `retail_rate_micro`, `provider_cost_micro`,
   `unit_label`, currency for the one pinned pilot context (§3.6).
   **`provider_cost_micro` in particular cannot be derived from any
   legacy data this repository stores** — the legacy `options` blob is
   customer-facing retail price only; the human supplying this figure
   must independently know or obtain the real provider cost (e.g. from
   the chosen gateway's own published per-segment pricing for the
   specific pinned country), never the legacy retail figure
   reinterpreted as cost.
2. **Pilot scope configuration** — `pilot_business_ids`,
   `authorized_country_ids`, `authorized_gateway_types` (§3.6), each
   empty/fail-closed by default.
3. **(Unchanged framing, restated):** implementation may begin and be
   fully completed, tested, and merged against fixture-only values for
   all three. `Conversations.is_metered` must remain `false`, and none
   of the three config lists may be populated with a real value, in any
   environment beyond the isolated test database, until a human has
   separately supplied all of them and separately, explicitly run §9.1's
   command. Code merging is not equivalent to M5 being live.

---

## 10. Existing entitlement behavior

Unchanged.

---

## 11. Schema

**Still no new migration, table, or column.** The state machine (§6)
and pilot-scope guards (§5.1) are built entirely from already-existing
columns (`business_usage_reservations.status`/`reserved_at`, already-
existing relations) and configuration, not new schema.

---

## 12. Exact implementation allowlist — REVISED, expanded from 10 to 12

### Modify (6)

1. `app/Http/Controllers/Customer/ChatBoxController.php` —
   `conversation_context` at both entry points; `reply()`'s explicit
   `idempotency_token` propagation + inline validation (§7, corrected
   this pass); `new()`'s session-based pending-token read/mint and
   `sent()`'s session clear-on-resolution logic (§6.1).
2. `app/Repositories/Eloquent/EloquentCampaignRepository.php` —
   `quickSend()` gains the full six-condition guard chain (§5.1), the
   business-namespaced idempotency key, the `$preCallAt` capture, the
   post-`reserve()` status/timestamp inspection and full state-machine
   branching (§6), and the definitive-vs-ambiguous outcome handling on
   the provider response. Every existing unconditional code path is
   untouched.
3. `app/Http/Requests/ChatBox/SentRequest.php` — `'idempotency_token' => 'required|uuid'`.
4. `resources/views/customer/ChatBox/new.blade.php` — hidden field now
   renders a controller-passed token value (§6.1), not an inline
   `Str::uuid()` call.
5. `resources/views/customer/ChatBox/index.blade.php` — `enter_chat()`
   gains the lazy-generate/retain-on-ambiguous/clear-on-success token
   logic, and a third response-status branch (`'pending'`, alongside the
   existing `success`/`error`) that does not regenerate the token and
   shows an informational (non-error) message.
6. `config/usage_billing.php` — adds
   `conversations_metering.pilot_business_ids`,
   `conversations_metering.authorized_country_ids`,
   `conversations_metering.authorized_gateway_types`, all empty by
   default, following the file's own existing convention.

### New (6)

7. `app/Console/Commands/ActivateUsageFeatureRate.php` (§9.1).
8. `app/Console/Commands/ResolveAmbiguousUsageReservation.php` (§6.2 —
   new this pass).
9. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`.
10. `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`.
11. `tests/Feature/Usage/ActivateUsageFeatureRateCommandTest.php`.
12. `tests/Feature/Usage/ResolveAmbiguousUsageReservationCommandTest.php`
    (new this pass — dedicated, not folded into an unrelated file,
    matching the same "no hidden path" discipline already applied to
    item 11).

### Read-only dependencies (expanded)

- `app/Library/Usage/UsageWalletManager.php`
- `app/Repositories/Contracts/BusinessUsageReservationRepository.php`
  (`findById()` — read directly by the new `quickSend()` state-machine
  check, §6 step 5; already-public, no new method)
- `app/Library/Entitlement/EntitlementManager.php` (including
  `assertPlatformAdministrator()`'s body, reproduced not called)
- `app/Library/Entitlement/RealUsageAuthorizationGateway.php`,
  `app/Library/Entitlement/PlatformFeatureRegistry.php`
- `app/Enums/Entitlement/PlatformFeature.php`,
  `app/Enums/Entitlement/PlatformFeatureAvailability.php`
- `app/Models/Customer.php`, `app/Models/Business.php`,
  `app/Models/Workspace.php`, `app/Models/User.php` (`is_admin`)
- `app/Models/PlatformFeatureUsageClassification.php`,
  `app/Models/BusinessUsageRate.php`
- `app/Repositories/Contracts/CampaignRepository.php`
- `app/Models/SendCampaignSMS.php` / `app/Models/Campaigns.php`
  (`sendPlainSMS()`, mocked in tests, never modified)
- `app/Models/SendingServer.php` (gateway-type constants, e.g.
  `TYPE_TWILIO`/`TYPE_TWILIOCOPILOT`, read only)
- `app/Jobs/Usage/ExpireStaleUsageReservations.php` (relied upon, §6/§3.4b,
  never modified)
- `app/Console/Kernel.php`, `routes/customer.php`
- The five non-`Conversations` `quickSend()` callers (unchanged list
  from the prior pass)
- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
  (`storeBusiness()`, read only)
- `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`,
  `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php`

**Total mechanically-authorized paths: 12. Stop threshold: 13th path.**
This is an explicit, reported expansion from the prior pass's 10 — not a
hidden one — driven directly by §6's state machine (2 files, 2 new
commands, 1 new test file) and §7's `ChatBoxController.php` correction
(no new path, a corrected description of an already-listed one).

---

## 13. Required tests — reconciled

New:

1. Metered success (all six §5.1 conditions satisfied) → one
   reservation, one `commit()`, correct debit.
2. Denied wallet → provider double never invoked.
3. **New/revised:** retry against an already-`Committed` reservation
   (same token) → provider double is **not** invoked a second time;
   response is the generic already-sent success shape.
4. **New/revised:** retry against a terminal `Released`/`Expired`
   reservation (same token) → provider double is **not** invoked;
   response is the distinct "could not confirm" shape; asserts the
   client-side contract that a fresh token is required next.
5. **New/revised:** a same-token retry landing on a still-`Pending`,
   pre-existing reservation (`reserved_at < preCallAt`, simulated by
   directly seeding a `Pending` row before the second call) → provider
   double is **not** invoked; response is the `'pending'` shape; the
   structured warning is logged.
6. **New/revised:** a thrown exception from the provider double on the
   *first* (genuinely fresh) attempt → `release()` is **not** called;
   reservation remains `Pending`; a subsequent §6.2 command run with
   `--outcome=sent` correctly commits it, and a separate scenario with
   `--outcome=not-sent` correctly releases it.
7. A non-throwing, evidenced non-accepted provider response → `release()`
   **is** called (definitive case remains fast-path safe).
8. **New:** two genuinely distinct compose actions (two different
   tokens) never collide — two reservations, two charges.
9. Plain SMS segment quantity correctness (unchanged).
10. Non-M5 channels preserve legacy behavior (unchanged).
11. The five non-ChatBox `quickSend()` callers are unaffected (unchanged).
12. Legacy `sms_unit` and the wallet never both charge one send
    (unchanged).
13. Business isolation, **now including:** a client-supplied token that,
    once business-namespaced, cannot resolve another Business's
    reservation even when the raw token portion is identical across two
    different Businesses' requests (proves §6.1's namespacing directly,
    not only asserting no accidental collision under normal operation).
14. A Workspace with more than one Business (§5.1 item 4) stays legacy.
15. A destination/Business/gateway-type combination outside the pilot
    allowlist (§5.1 items 4-6, each tested independently) stays legacy;
    the fully in-scope combination engages the wallet.
16. `EntitlementManagerNineKeySurfaceUnchangedTest`/
    `PlatformFeatureRegistryTest` re-run unmodified.
17. `ActivateUsageFeatureRateCommandTest` (unchanged from the prior
    pass).
18. **New:** `ResolveAmbiguousUsageReservationCommandTest` — requires
    `--actor-user-id`; requires `is_admin`; `--outcome=sent` calls
    `commit()`; `--outcome=not-sent` calls `release()`; rejects an
    invalid `--outcome` value.

Regression, unchanged: full `Usage`, full `Entitlement`/`Workspace`,
full suite.

---

## 14. Stop conditions

Unchanged from the prior pass, plus:

- A 13th path required beyond §12's twelve.
- Any evidence that `enter_chat()`'s existing button-disable/error-
  handling behavior, or `sent()`'s redirect-on-error shape, no longer
  matches what §6.1 assumes.
- Any attempt to populate any of the three §9.2 item-2 config lists with
  a real value in checked-in config/`.env.example` — hard stop, same
  class as the numeric-rate stop condition.
- Any attempt to infer a `TwilioException`'s definitive-vs-ambiguous
  nature from application code without having directly verified the
  installed vendor SDK's own exception hierarchy in the actual
  implementation environment (unlike this contract-drafting worktree,
  where `vendor/twilio` is absent) — if the implementation phase can
  verify this precisely and it would meaningfully narrow the ambiguous
  bucket, that is a valid, smaller-scope refinement to propose, not
  something to assume here.

---

## 15. Verification and publication (this document only)

- `git diff --check` clean.
- `git diff origin/main --name-only` shows exactly one path.
- Commit message: `docs: close RFC-005 M5 retry and rate-scope gaps`, a
  new commit, not an amend.
- Push `chore/rfc-005-m5-contract`. PR #107 remains Draft, unmerged. No
  implementation begins.
