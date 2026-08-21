# RFC-005 Milestone 5 — Metered Feature Classification

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This contract authorizes drafting this one document only.** Merging it
authorizes the bounded M5 implementation this document specifies — to be
made as its own separate, later, explicitly bounded implementation PR.
Merging this document does **not** itself write any `app/`, `database/`,
`routes/`, `config/`, or `resources/` file, does not flip
`Conversations`' classification to `is_metered = true`, does not activate
any real retail/provider rate, does not begin M6 (conformance/tag) in any
way, and does not authorize any live charge to any real Business.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m5-contract`, in an isolated linked
  worktree (`../rfc-005-m5-contract-worktree`), based on `origin/main` at
  `24fd1730e535d2360bb3a6fef7caf97f3272457c` (merge of PR #105, RFC-005
  M4 — Additional-Slot Agreement and Add-ons, including its Correction
  Round 2 and the deterministic same-ordinal retry-race fix).
- **Human product decision, locked, not reopened here:** the first real
  metered feature is `PlatformFeature::Conversations`. This was selected
  after a bounded, read-only M5 candidate audit (conversation preceding
  this contract) that inspected `PlatformFeatureRegistry`, the
  `Conversations`/`Automations`/`Crm` execution paths, and the legacy
  `sms_unit` quota stack. That audit's own findings are re-verified,
  extended, and superseded in scope by §3 below wherever the two
  disagree — this contract is the authoritative document going forward.
- `maximum_correction_rounds: 2`, matching every prior RFC-004/RFC-005
  milestone contract's own convention.
- Any path required during the future implementation but absent from
  §12's own numbered allowlist is a stop-and-report condition — not a
  silent workaround. The stop threshold is the allowlist's own final
  count **plus one** (§14).
- Drafting this contract makes **zero** application changes. No `app/`,
  `database/`, `routes/`, `config/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by this
  branch — only this one new document (§1).
- **Audit discipline — bounded, M5-only:** `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`
  §36 item 5, §37, §39 item 11 (read in full, again, directly from
  `origin/main`); the M1 contract's own schema/algorithm sections for
  `platform_feature_usage_classifications`, `business_usage_rates`,
  `UsageWalletManager::reserve()/commit()/release()/setActiveRate()/activateMetering()/evaluateCoarseCapacity()`
  (read in full, current code, not the M1 contract's prose, since the
  merged code is the only authority on exact current behavior); the
  RFC-004 `EntitlementManager::decide()`/`PlatformFeatureRegistry`/
  `RealUsageAuthorizationGateway` seam; the actual current `Conversations`
  execution path (`ChatBoxController`, `CampaignRepository::quickSend()`,
  `SendCampaignSMS::sendPlainSMS()`, `User::countSMSUnit()`); and the
  existing test surface for all of the above (`tests/Feature/Usage/*`,
  `tests/Feature/Entitlement/*`). No unrelated legacy module (Automations,
  Crm/Contacts, the Plan/Subscription billing UI, SEO/Ads/White-Label
  scaffolding) was modified or newly explored beyond confirming they
  remain untouched by this contract's scope.

---

## 1. This contract's own exact file scope

Exactly one file, this document:
`docs/automation/RFC-005-M5-CONTRACT.md`.

---

## 2. Preflight — verified

- `git fetch origin`; `origin/main` confirmed exactly
  `24fd1730e535d2360bb3a6fef7caf97f3272457c` before this document was
  drafted.
- This worktree's branch (`chore/rfc-005-m5-contract`) is `origin/main`
  itself at that SHA — trivially a descendant.
- `bcmath` remains confirmed enabled (RFC-005 M1 contract; re-confirmed
  unchanged through M2–M4; no new PHP extension dependency is introduced
  by M5).
- No RFC-004 file, and no already-merged RFC-005 M1–M4 file, is modified
  by this document. §12's allowlist authorizes exactly two existing files
  to be *modified* by the future implementation, both named explicitly.

---

## 3. Mandatory repository audit — findings

### 3.1 `PlatformFeature::Conversations` — current classification and floor

- `app/Enums/Entitlement/PlatformFeature.php` — `Conversations` case
  confirmed present, unchanged since RFC-004.
- `app/Library/Entitlement/PlatformFeatureRegistry.php` —
  `PlatformFeature::Conversations->value => PlatformFeatureAvailability::Available`,
  confirmed unchanged. This is one of exactly three `Available` cases
  (`Crm`, `Conversations`, `Automations`); every other case remains
  `Planned`. M5 changes **only** `Conversations`' *usage-metering* state,
  never its *availability* — `PlatformFeatureRegistry` itself is not
  touched by this contract's allowlist.
- `platform_feature_usage_classifications` (migration
  `2026_08_16_120004_...`, M1): one row per `PlatformFeature` case,
  backfilled `is_metered = false`, `active_rate_id = null`. Confirmed via
  direct read this row still exists unmodified for `conversations` on
  `origin/main` — no correction round of M1–M4 ever flipped it (M1
  contract §11, re-confirmed).

### 3.2 `UsageWalletManager` — the exact, already-built machinery M5 reuses

Read in full from `app/Library/Usage/UsageWalletManager.php` on
`origin/main`:

- **`reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $estimatedQuantity = null): ReservationResult`**
  (line 233) — idempotency-key lookup first (`findByIdempotencyKey()`); a
  repeat call with the same key is a pure no-op returning the existing
  reservation id (line 235-239). Inside one `DB::transaction`: locks the
  wallet row, rolls the spend period over if needed, resolves the
  feature's active rate (throws `NoActiveRateForFeatureException` if
  `active_rate_id` is null — i.e. **`reserve()` cannot be called at all
  until a rate is activated**), computes `reservedAmountMicro = round(rate
  × quantity)`, then denies with a specific reason
  (`wallet_suspended`/`outstanding_debt`/`insufficient_balance`) before
  ever writing a reservation row if the wallet cannot support the charge.
  On success, writes one `business_usage_reservations` row (`status =
  Pending`) and one `business_usage_ledger_entries` row, debits
  `available_balance_micro`, credits `reserved_balance_micro`. Already
  handles auto-recharge dispatch.
- **`commit(int $reservationId, ?string $finalQuantity = null): CommitResult`**
  (line 354) — idempotent: a repeat call on an already-`Committed`
  reservation reconstructs and returns the original `CommitResult`
  without writing anything new (line 377-385). Rejects any other non-
  `Pending` state transition. Computes `finalAmountMicro` from
  `finalQuantity ?? estimated_quantity`, writes the charge ledger entry,
  and correctly handles both overage (final > reserved) and unused-
  release (final < reserved) sub-cases — neither of which M5's own
  plain-SMS scope will ever exercise (§5).
- **`release(int $reservationId): void`** (line 541) — idempotent on an
  already-terminal (`released`/`expired`) reservation; releases the full
  reserved amount back to `available_balance_micro`.
- **`evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision`**
  (line 790) — the exact seam `RealUsageAuthorizationGateway::check()`
  calls. Returns `authorized: true` unconditionally when the feature's
  classification is null or `is_metered = false` — this is why M1–M4 are
  "provably behaviorally identical to `NullUsageAuthorizationGateway`"
  (the gateway's own docblock). Once `Conversations.is_metered = true`,
  this method starts returning `wallet_missing` /
  `wallet_suspended` / `outstanding_debt` / `authorized: true` — the
  **same three denial reasons** `reserve()` itself re-derives more
  precisely (plus `insufficient_balance`, which the coarse gate
  deliberately does not check, since it is non-mutating and cheap by
  design — RFC-005 §14).
- **`setActiveRate(string $featureKey, string $retailRateMicro, string $providerCostMicro, string $unitLabel, int $currencyId, int $actorUserId, string $reason): BusinessUsageRate`**
  (line 702) and **`activateMetering(string $featureKey, int $actorUserId, string $reason): void`**
  (line 755) — **both already exist, fully built, at M1.** Neither has
  ever been called by any production code path (M1 contract's own
  docblock: *"Present at M1; never called by any M1 production code path
  ... no metered feature is authorized until M5"*). `activateMetering()`
  itself throws `NoActiveRateForFeatureException` if no rate is active
  yet — the two calls have a mandatory order (rate first, then
  metering), already enforced by the existing code, not something M5
  needs to add.

**Conclusion: M5 needs zero new wallet-layer code.** The entire
reserve/commit/release/rate/classification machinery is complete,
tested (via `UsageWalletManager*Test.php`), and behaviorally proven.
M5's job is exclusively (a) wiring the *call sites* into the real
`Conversations` send path, and (b) building the narrow human-operated
mechanism to actually flip the two already-existing switches — never
inventing new wallet mechanics.

### 3.3 `RealUsageAuthorizationGateway` and `EntitlementManager` — confirmed unbypassed, no new denial key

- `app/Library/Entitlement/RealUsageAuthorizationGateway.php` — already
  bound in `AppServiceProvider` in place of `NullUsageAuthorizationGateway`
  (M1 contract §36 item 1). `check()` returns exactly one denial reason
  string, `'usage_unauthorized'`, regardless of which internal coarse-
  capacity reason fired — the internal reason is deliberately never
  surfaced past this boundary (RFC-005 §14, re-confirmed by direct read
  of the docblock and body).
- `app/Library/Entitlement/EntitlementManager.php` — `decide()`'s
  denial-reason surface (`disabled_for_business`, `not_entitled_by_plan`,
  `denied_by_workspace_override`/`allowed_by_workspace_override`, and
  `usage_unauthorized` among them) is already exercised end-to-end by
  `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`,
  which already exists and already asserts this exact nine-key surface.
  **`usage_unauthorized` is not a new key M5 introduces** — it already
  exists in `decide()`'s reason space today, currently unreachable in
  practice only because every classification is `is_metered = false`.
  M5 makes it reachable for `Conversations` specifically; it does not
  add a tenth key. This satisfies mandatory decision 7 directly: no new
  denial key, `EntitlementManager` is the only call path and is never
  bypassed, `Conversations`' availability is untouched, and no `Planned`
  feature is touched at all.

### 3.4 The current `Conversations` execution path — exact, with a structural correction to the prior audit

- `app/Http/Controllers/Customer/ChatBoxController.php` — `sent()` (line
  143) and `reply()` (line 355) are the two real HTTP entry points a
  Business user actually calls. Both build an `$input` array and end by
  calling `$this->campaigns->quickSend($campaign, $input)` — `sent()` at
  line 290, `reply()` at line 489.
- `app/Repositories/Eloquent/EloquentCampaignRepository.php::quickSend(Campaigns $campaign, array $input): JsonResponse`
  (line 60) — a `switch ($sms_type)` (line 301) dispatches to
  `sendPlainSMS()`/`sendVoiceSMS()`/`sendMMS()`/`sendWhatsApp()`/
  `sendViber()`/`sendOTP()` on the `Campaigns` model (via the
  `SendCampaignSMS` trait). The per-channel unit price is read from a
  `CustomerBasedPricingPlan`/`PlansCoverageCountries` coverage row (lines
  235-258), segment count comes from `SMSCounter::count($message, ...)`
  (line 221-223, `'WHATSAPP'` mode selected only for the whatsapp
  channel), and `price = sms_count * unit_price` (line 260) — fully
  computable **before** the provider call, from data already resolved at
  that point. The legacy balance check is a pre-send guard (line 262:
  `if ($user->sms_unit != '-1' && $price > $user->sms_unit)` → error
  response, no send attempted). The real provider call happens next
  (lines 301-349). Only on a `Delivered` status (line 352-353) is
  `sms_unit` actually decremented, inside its own `DB::transaction` (line
  355-358): `$user->update(['sms_unit' => $user->sms_unit - $price])`.
  A non-delivered/failed send simply never decrements — there is no
  separate refund path needed for `quickSend()` specifically (unlike the
  bulk-campaign-builder methods elsewhere in the same file, which are out
  of scope here, §5).
- **`User::countSMSUnit()`** (`app/Models/User.php:330`) exists but is
  **not** what `quickSend()` calls for its own deduction — `quickSend()`
  decrements `sms_unit` directly via `update()` (line 356-357), not via
  `countSMSUnit()`. `countSMSUnit()` is the method the *Automation* path
  uses instead (`Automation::track_message()`, out of scope for M5).
  This is a precise correction of the earlier candidate-audit's
  shorthand — the mechanism (a raw, unlocked, delivery-gated `sms_unit`
  decrement) is identical in spirit between the two callers, but they are
  two distinct call sites in two distinct files, and only the
  `quickSend()` one is in scope for `Conversations`.

- **Structural correction to scope, found during this contract's own
  audit and not fully resolved by the prior candidate-selection audit:**
  `quickSend()` is **not exclusive to `ChatBoxController`.** A direct
  repository search for every call site found six:
  1. `ChatBoxController::sent()`/`::reply()` — the genuine `Conversations`
     one-to-one send (in scope for M5).
  2. `Customer\CampaignController::postQuickSend()`/
     `postVoiceQuickSend()`/`postMMSQuickSend()`/`postWhatsAppQuickSend()`
     — the "Quick Send" **bulk** campaign feature (up to 100 recipients
     per call, looped internally), a distinct product surface, not
     `Conversations`.
  3. `EloquentContactsRepository` — system-triggered welcome/signup SMS
     fired on contact-group events, not a human-initiated conversation.
  4. `DLRController` — keyword auto-reply/unsubscribe-notification sends
     triggered by inbound-message processing, not `Conversations`.
  5. `API\CampaignController` / `API\CampaignHTTPController` — third-
     party API-triggered sends, a distinct external-integration surface.

  **Wiring wallet metering directly inside `quickSend()` itself would
  silently misclassify all six call sites as `Conversations` usage —
  a real correctness defect, not a hypothetical one.** §5 and §7 resolve
  this with an explicit, additive, opt-in discriminator rather than by
  redesigning the shared method or duplicating its channel-dispatch
  logic in `ChatBoxController`.

### 3.5 Business resolution — `Customer::primaryBusiness()`

- `app/Models/Customer.php:131` —
  `public function primaryBusiness(): HasOne { return $this->hasOne(Business::class, 'customer_id', 'user_id')->where('is_primary', true); }`.
  This is the only existing `User`/`Customer` → `Business` mapping
  anywhere in the repository; there is no alternative to invent from.
  `app/Models/Business.php` confirms `belongsTo(Workspace::class)`, and
  `Workspace::class` confirms `hasMany(Business::class)` — a Workspace
  can own multiple Businesses, so a bare "first Business" lookup would be
  ambiguous; `is_primary` is the deliberate disambiguator already present
  in the schema and already used by `WorkspaceBackfillV1`'s own naming
  resolution (`app/Library/Workspace/Migration/WorkspaceBackfillV1.php:224`).
- **Architecturally sufficient, but its universal coverage is not
  verified by this audit and must not be assumed.** This audit is a
  repository-code review, not a data query against a live/staging
  database — it cannot confirm that every real Customer capable of
  sending a ChatBox message today has a non-null `primaryBusiness`. RFC-
  003/004's own Business-account-boundary backfill work is the reason to
  expect 100% coverage, but expecting it is not the same as having
  verified it.
- **Per mandatory decision 3's own instruction, this is recorded as a
  required precondition, not assumed:** the M5 implementation's first
  step, before any code change, must directly query
  `ultimatesms_testing`'s production-shaped schema (or, if available, a
  read-only staging/production replica) for the count of Customers with
  at least one ChatBox row (`chat_boxes.user_id`) whose
  `customer->primaryBusiness` is null. If that count is nonzero, the
  implementation must **stop and report** rather than invent fallback
  cross-Business selection semantics — exactly as instructed. This
  contract does not resolve that count itself (no live/staging query was
  run while drafting a docs-only contract); it names the exact check the
  implementation phase must run first.
- **If the count is confirmed zero** (the expected, but unverified,
  outcome): a null `primaryBusiness` becomes an unreachable state for any
  real Conversations sender, and the implementation may treat it as a
  hard-deny (fail-closed, log-and-block, never a silent legacy fallback)
  without needing bespoke product-decision input, since it would indicate
  a data-integrity break in an already-locked invariant rather than a
  legitimate product state.

### 3.6 Testability of the real send path — a genuine pre-existing gap

- No feature test exists today for `ChatBoxController`, `quickSend()`, or
  any `SendCampaignSMS::send*()` method (confirmed: no
  `tests/Feature/**/*ChatBox*` file exists on `origin/main`).
- `SendCampaignSMS::sendPlainSMS()` (`app/Models/SendCampaignSMS.php:71`)
  branches per `SendingServer` gateway configuration into `Http::` facade
  calls, raw `curl_init()` calls, and the Twilio PHP SDK's own `Client`
  (`new Client($sending_server->account_sid, $sending_server->auth_token)`,
  lines 469/502) — there is no single injectable gateway interface, unlike
  RFC-005 M3/M4's own `PaymentProviderGateway`/`FakePaymentProviderGateway`
  seam. `Http::fake()` alone cannot deterministically intercept every
  branch.
- **This is a pre-existing legacy-code limitation, not something this
  contract proposes to refactor** — rewriting `SendCampaignSMS` into an
  injectable-gateway shape would be a large, out-of-scope redesign
  forbidden by "must not introduce a new billing architecture" and by
  the smallest-bounded-scope instruction generally.
- **Resolution for M5's own tests (recorded here so the future
  implementation does not have to rediscover it):** stub at the
  `Campaigns::sendPlainSMS()` method boundary directly (a Mockery partial
  mock or a test-only subclass bound into the container for the duration
  of the test), rather than attempting to fake the transport layer
  underneath it. This is a standard PHPUnit/Mockery technique requiring
  no production-code seam change, and is exactly analogous to how M1–M4's
  own tests never needed to fake real Stripe HTTP traffic once
  `PaymentProviderGateway` existed as a swappable dependency — the
  difference here is the swap point is a concrete method call, not an
  interface, which the test doubles around rather than the container
  resolves around.

---

## 4. Contract status model

Identical to every prior RFC-005 milestone contract:

- `PROPOSED` (this document, now) → human review → `MERGED` (human
  merges this PR) → a **separate**, later, explicitly bounded
  implementation PR is opened against this contract → `ai:testing` /
  `ai:awaiting-codex` / `ai:ready-for-human` per the existing state-label
  rules in the repository root `CLAUDE.md` → human merge of the
  implementation PR (never automatic).
- Merging *this* document authorizes drafting the implementation only.
  It does not itself authorize skipping human review of the
  implementation PR, and it does not authorize a human to skip the
  numeric-rate decision recorded as unresolved in §8.

---

## 5. Exact M5 scope — channel and caller, both locked

**Strong default confirmed, not silently pretended:** repository
evidence (§3.4) confirms the existing `business_usage_rates` schema
supports exactly one active `unit_label`/`retail_rate_micro`/
`provider_cost_micro` triple per `feature_key` at a time
(`platform_feature_usage_classifications.active_rate_id`, a single
nullable FK — not a per-channel map). `Conversations` sends today span
six channels (`plain`/`unicode`, `voice`, `mms`, `whatsapp`, `viber`,
`otp`) with six independently-priced coverage-table rates
(`plain_sms`/`voice_sms`/`mms_sms`/`whatsapp_sms`/`viber_sms`/`otp_sms`).
**No repository evidence proves these can be safely represented as one
rate** — they are different provider costs today, by design, in the
existing pricing coverage table. The strong default therefore applies
exactly as instructed:

> **M5 meters plain SMS `Conversations` sends only** (`sms_type` values
> `plain` and `unicode`, which `quickSend()` itself already normalizes to
> the single `plain` `unit_price`/`sendPlainSMS()` branch — `unicode` is
> not a separate rate today, only a separate character encoding, so it is
> included in M5's scope, not deferred).

**Additionally, and beyond what the strong default alone specifies (§3.4's
structural finding):** M5's scope is **plain/unicode sends, invoked
specifically through `ChatBoxController::sent()`/`::reply()`** — not
every `quickSend()` call with `sms_type` `plain`/`unicode` regardless of
caller. The five other call sites (§3.4 item 2-5) remain **entirely
untouched, on 100% legacy `sms_unit` behavior**, in M5, regardless of
channel.

### 5.1 What qualifies as an M5-metered plain SMS send

A `quickSend()` invocation qualifies for RFC-005 wallet metering if and
only if **all** of the following hold:

1. `$input['sms_type']` resolves to the `plain` branch (`plain` or
   `unicode`, per the existing `$db_sms_type` normalization already in
   `ChatBoxController::sent()` and hard-coded in `::reply()`).
2. The call originated from `ChatBoxController::sent()` or
   `ChatBoxController::reply()` — mechanically distinguished by a new,
   explicit, additive input key (§7) set only at those two call sites.
3. `platform_feature_usage_classifications.conversations.is_metered`
   is `true` at the moment of the call (i.e. a human has already run the
   §9 activation command in that environment).

### 5.2 What MMS/WhatsApp/Viber/OTP/Voice do during M5

Unconditionally unchanged: the existing pre-send `sms_unit` balance
check, the existing provider call, and the existing post-`Delivered`
`sms_unit` decrement — exactly as today, for every channel other than
plain/unicode, regardless of `Conversations.is_metered`'s value, forever
within M5's own scope. Extending wallet metering to any other channel is
explicitly a later-milestone decision (§5.4), never silently bundled
into M5.

### 5.3 How non-M5 channels and non-ChatBox callers remain on existing behavior

The dispatch added to `quickSend()` (§7) is strictly additive and
gated on **both** conditions in §5.1 items 1-2 simultaneously. Any call
missing either condition — every non-plain channel, and every call from
the other five call sites regardless of channel — takes the exact same
code path it takes today, with zero new branches evaluated, zero new
queries issued, and zero new columns read. This is mechanically provable
by the exact new conditional's own guard clause (§7), and is directly
regression-tested (§13, tests 7 and 9-of-original plus the two additional
non-ChatBox-caller regression tests §13 requires beyond the user's
original list).

### 5.4 How later milestones may extend metering without changing M5 history

`business_usage_rates` is itself immutable and versioned
(`unique(feature_key, version)`, no `updated_at`) — a future milestone
that wants to meter, say, MMS separately would need either (a) a new
`PlatformFeature` case dedicated to that channel (clean, no schema
change, but a new entitlement-surface decision), or (b) a schema
widening to a per-channel rate map keyed under one `feature_key` (a real
schema expansion, explicitly out of scope for both M5 and this
contract). This contract takes no position on which future milestones
should choose — it only confirms M5 itself requires neither, and that
whichever a future milestone picks, M5's own already-activated
`conversations` rate/classification rows and its own reservation/ledger
history remain untouched and immutable, per the existing schema's own
append-only/immutable-rate design.

---

## 6. Reservation lifecycle — exact, locked order

For a qualifying send (§5.1), inside `EloquentCampaignRepository::quickSend()`,
strictly in this order, replacing (not adding alongside) the existing
`sms_unit` pre-check/decrement for this call only:

1. **Business resolution.** `$business = $user->customer->primaryBusiness;`
   If null: per §3.5, this is an unreachable state once the
   precondition check passes; if it is ever hit in production despite
   that, fail closed — deny the send with the existing
   `'not_enough_balance'`-shaped error response, log a warning, do
   **not** fall back to legacy `sms_unit` deduction (that would violate
   "exactly one authoritative charging path," §5.1 item 2's own
   guarantee that a qualifying send is always wallet-governed).
2. **Entitlement/usage authorization.** Resolve the Business's
   `Workspace` (`$business->workspace`) and call the existing
   `EntitlementManager::decide($workspace, $business, PlatformFeature::Conversations->value, $actorUserId)`
   unchanged. If `!$decision->allowed`: return the existing error-shaped
   JSON response carrying `$decision->reason` (which will read
   `usage_unauthorized` for every wallet-capacity denial, per §3.3); no
   rate lookup, no reservation, no provider call occurs after this
   point.
3. **Rate lookup.** Implicit inside `reserve()` itself (§3.2) — not a
   separate call the new code needs to make.
4. **Quantity calculation.** Reuse the exact, already-computed
   `$sms_count` from `SMSCounter::count($message, null)` (line 221-223)
   — the same value already used for the legacy `$price` calculation.
   No second segment-count algorithm is introduced (§8).
5. **Wallet reserve.** `$reservation = $walletManager->reserve($business, PlatformFeature::Conversations->value, $idempotencyKey, (string) $sms_count);`
   using the idempotency key defined in §6.1. If
   `!$reservation->authorized`: return the existing error-shaped
   response carrying `$reservation->denialReason`
   (`wallet_suspended`/`outstanding_debt`/`insufficient_balance`,
   mapped to the same user-facing "not enough balance"-style message
   the legacy path already returns for its own `insufficient_balance`
   case); **no provider send occurs.**
6. **Provider send.** Unchanged: `$campaign->sendPlainSMS($preparedData)`.
7. **Commit on durable success.** On `Delivered` status (the same
   existing check, line 352-353): `$walletManager->commit($reservation->reservationId, (string) $sms_count);`
   — `$finalQuantity` is explicitly re-passed as the identical
   `$sms_count` value (§8), not defaulted to null, keeping the
   estimated-equals-final equality auditable in the call site itself.
8. **Release on definite non-send/failure.** Any other terminal outcome
   (provider returns a non-`Delivered` status, or the provider call
   throws): `$walletManager->release($reservation->reservationId);`.
   The legacy `sms_unit` column is not touched in either branch for a
   qualifying send.
9. **Crash/retry/idempotency recovery.** `reserve()`'s own idempotency-
   key dedup (§3.2) already makes a retried call with the same key a
   no-op that returns the original reservation id; `commit()`/`release()`
   are already idempotent on a reservation's terminal state (§3.2).
   Together these guarantee a retry can never double-charge **provided
   the same idempotency key is supplied** — which §6.1 defines precisely,
   including its one honestly-stated limitation.

**No provider send may occur after a denied usage decision:** steps 2
and 5 both return before step 6 is reached whenever they deny — this is
a straight-line sequence, not a try/catch that could allow a later step
to run after an earlier denial; the implementation must preserve that
straight-line shape (a required test, §13, asserts the provider mock is
never invoked in the denied cases).

### 6.1 Idempotency key — exact, and its one honest limitation

No pre-existing durable "send attempt" identifier exists in this legacy
path to derive a key from: unlike RFC-005 M3/M4's `business_funding_attempts`
row (created *before* any provider call, carrying its own
`local_idempotency_key`), `quickSend()` never persists anything before
calling the provider — the only persisted record (`Reports::create()`,
`SendCampaignSMS.php:13419` and siblings) is written *after* the provider
responds, seeded from the response itself. **The idempotency key is
therefore a fresh `(string) Str::uuid()`, generated exactly once per
HTTP request, at the top of `quickSend()`'s new §7 branch, threaded
unchanged through `reserve()`.** This is a real, deterministic,
already-established pattern (identical in kind, if not in source, to
M3/M4's own locally-generated idempotency keys) — not invented for this
contract, only newly applied here.

**Honest limitation, recorded rather than silently accepted:** because
the key is minted fresh per HTTP request rather than derived from a
client-supplied token, two genuinely independent HTTP requests (e.g. a
user double-clicking "Send" before the page responds) receive two
different keys and are **not** deduplicated by this mechanism — each
reserves and (on success) commits its own charge, exactly as the legacy
`sms_unit` path also does today (it has never deduplicated a double-
click either; the balance check merely happens twice, potentially
sending the message twice at the user's own action). M5 does not
regress this; it does not fix it either. A future milestone wanting a
stronger guarantee would need a client-supplied idempotency token, which
is out of scope here and not invented by this contract.

---

## 7. `quickSend()` discriminator — the exact, minimal, additive change

`ChatBoxController::sent()` (immediately before its existing call to
`$this->campaigns->quickSend($campaign, $input)`, line 290) and
`ChatBoxController::reply()` (immediately before its existing call, line
489) each gain exactly one new array key:

```php
$input['conversation_context'] = true;
```

`EloquentCampaignRepository::quickSend()` gains, immediately after the
existing `$sms_type`/`$db_sms_type` resolution and before the existing
`switch ($sms_type)` dispatch, a single guard:

```php
$isConversationSend = ($input['conversation_context'] ?? false) === true
    && in_array($db_sms_type, ['plain', 'unicode'], true);
```

Every one of the six §3.4 call sites other than `ChatBoxController`'s two
never sets `conversation_context` — `$isConversationSend` is `false` for
all of them, unconditionally, and the existing code executes completely
unmodified in that case. This is the exact mechanism §5.3 refers to.

---

## 8. Quantity — no new algorithm

`$sms_count` from the existing `SMSCounter::count($message, null)` call
(§3.4/§6 step 4) is the sole source of quantity, for both
`estimatedQuantity` (passed to `reserve()`) and `finalQuantity` (passed
to `commit()`). **They are identical for every M5-scoped send:** segment
count is a pure function of the message text, computed once, before the
provider call, and nothing about the provider's response can change it
(no repository evidence anywhere in `sendPlainSMS()` or its `Reports`
persistence recomputes or overrides segment count from a provider-
returned value). `commit()`'s overage/unused-release sub-logic (§3.2)
therefore never activates for an M5-scoped send — it exists in
`UsageWalletManager` for other, non-M5 reasons (period rollover
interactions, future milestones with genuinely uncertain post-hoc
quantities) and is exercised by its own existing tests, not by M5's.

---

## 9. Pricing activation — mechanism authorized, numbers explicitly not

**§3.2 already confirms `setActiveRate()` and `activateMetering()` exist
and require no new wallet-layer code.** What is missing is a human-
operable way to call them. M5 authorizes building exactly one new,
narrow, human-run mechanism:

- A new Artisan console command,
  `app/Console/Commands/ActivateUsageFeatureRate.php`, signature along
  the lines of
  `usage:activate-rate {feature} {retail-rate-micro} {provider-cost-micro} {unit-label} {currency-code} {--reason=}`,
  requiring every value as an explicit argument (no defaults for any
  monetary or unit value), validating `feature` against
  `PlatformFeatureRegistry::isKnown()`, prompting for confirmation before
  calling `setActiveRate()` then `activateMetering()` inside one
  transaction-scoped operation, and requiring the operator's own admin
  user id (resolved from an authenticated CLI context or an explicit
  `--actor-user-id=` argument — exact resolution mechanism left to the
  implementation phase, not a product decision).
- This command performs no auto-discovery registration change (`app/Console/Kernel.php`
  already auto-loads `app/Console/Commands/`, confirmed by direct read,
  §3 — read-only dependency, not modified).
- **This contract does not authorize actually running this command
  against any real environment, and does not supply any numeric value
  for it to run with.** Doing so remains a separate, explicit, human
  action after the M5 implementation PR merges — never seeded via a
  migration, never defaulted, never invoked by any test against
  `Conversations` in particular except with an obviously-fixture-only
  rate.

### 9.1 Unresolved human decision — recorded, not fabricated

**No exact `retail_rate_micro`, `provider_cost_micro`, `unit_label`, or
settlement currency for `Conversations` plain-SMS metering has been
human-specified anywhere in the merged RFC-005 design document, any
merged milestone contract, or this conversation.** RFC-005 §39 item 1
("exact initial retail usage rates per eventually-metered feature")
remains explicitly `NON-IMPLEMENTATION-READY` until resolved, and item
10 only *recommends* USD/`decimal_places = 2`, without confirming it.
**This contract leaves all four values as a pre-implementation human
decision.** The M5 implementation PR must not fabricate seed values for
any of them, must not call `setActiveRate()`/`activateMetering()` against
any non-test database with invented numbers, and must not merge with
`Conversations.is_metered = true` in any environment beyond its own
isolated test database's own test-fixture rates.

---

## 10. Existing entitlement behavior — preserved, confirmed by §3.3

Restated for completeness against the mandatory-decision checklist:
`PlatformFeatureRegistry::isAvailable()` is not modified; no `Planned`
feature is touched; `EntitlementManager::decide()` remains the only
call path into usage authorization (no bypass — the new `quickSend()`
branch calls `decide()` itself, exactly as any other entitled feature
would, per §6 step 2); `usage_unauthorized` is a pre-existing key, not a
new one; `Conversations`' own availability (`Available`) is unchanged —
only its *usage*-authorization outcome becomes conditionally reachable.

---

## 11. Schema

**No new migration, no new table, no new column.** Every table M5
touches (`platform_feature_usage_classifications`, `business_usage_rates`,
`business_usage_rate_activations`, `platform_feature_usage_classification_transitions`,
`business_usage_reservations`, `business_usage_ledger_entries`,
`business_usage_wallets`) already exists from M1, unmodified since. This
is the direct consequence of §3.2's finding that the wallet layer
requires no new code.

---

## 12. Exact implementation allowlist

Numbered; the stop threshold is item 6 — any path needed beyond the five
named here is a stop-and-report condition, not a silent addition.

### Modify (2)

1. `app/Http/Controllers/Customer/ChatBoxController.php` — add
   `$input['conversation_context'] = true;` at the two existing call
   sites named in §7. No other line in this file changes.
2. `app/Repositories/Eloquent/EloquentCampaignRepository.php` —
   `quickSend()` gains the §7 guard and the §6 reserve/commit/release
   wiring, scoped strictly inside the new conditional branch; the
   existing unconditional code for every other case is untouched.

### New (3)

3. `app/Console/Commands/ActivateUsageFeatureRate.php` — §9's operator
   command.
4. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` — §13
   tests 1-6, 8-11.
5. `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`
   — §13 tests 7 and the two additional non-ChatBox-caller regressions
   this contract's own §3.4 finding requires.

### Read-only dependencies (relied upon, never modified)

- `app/Library/Usage/UsageWalletManager.php`
- `app/Library/Entitlement/EntitlementManager.php`
- `app/Library/Entitlement/RealUsageAuthorizationGateway.php`
- `app/Library/Entitlement/PlatformFeatureRegistry.php`
- `app/Enums/Entitlement/PlatformFeature.php`
- `app/Enums/Entitlement/PlatformFeatureAvailability.php`
- `app/Models/Customer.php` (`primaryBusiness()`)
- `app/Models/Business.php`, `app/Models/Workspace.php`
- `app/Models/PlatformFeatureUsageClassification.php`,
  `app/Models/BusinessUsageRate.php`
- `app/Repositories/Contracts/CampaignRepository.php` (interface,
  unchanged — `quickSend()`'s signature does not change)
- `app/Models/SendCampaignSMS.php` / `app/Models/Campaigns.php`
  (`sendPlainSMS()`, mocked in tests per §3.6, never modified)
- `app/Console/Kernel.php` (auto-discovery already covers new commands)
- `routes/customer.php` (ChatBox routes already wire to `sent()`/`reply()`;
  no route change needed)
- `app/Http/Controllers/Customer/CampaignController.php`,
  `app/Http/Controllers/API/CampaignController.php`,
  `app/Http/Controllers/API/CampaignHTTPController.php`,
  `app/Repositories/Eloquent/EloquentContactsRepository.php`,
  `app/Http/Controllers/Customer/DLRController.php` (the five non-
  `Conversations` `quickSend()` callers, §3.4 — read to confirm
  unaffected, regression-tested in item 5, never modified)
- `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`,
  `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php` (existing
  regressions, re-run, not modified)

**Total mechanically-authorized paths: 5. Stop threshold: 6th path.**

---

## 13. Required tests

New (in the two new files, §12 items 4-5, or split further at the
implementation's own discretion provided every case below is covered
exactly once):

1. Metered `Conversations` plain-SMS send succeeds → exactly one
   reservation created and exactly one `commit()` call, wallet debited
   exactly the segment-count × retail-rate amount.
2. Denied wallet (suspended / outstanding debt / insufficient balance)
   → the `sendPlainSMS()` mock/double is never invoked.
3. Provider failure (non-`Delivered` status or thrown exception) before
   a durable success → the reservation is released, wallet's available
   balance restored.
4. Retry/replay with the same idempotency key → no duplicate wallet
   charge (`reserve()`'s own dedup + `commit()`'s own idempotency,
   exercised end-to-end through the new call site, not just at the
   `UsageWalletManager` unit level where it is already covered).
5. Same send idempotency key → exactly-once accounting, asserted
   directly against `business_usage_ledger_entries` row counts.
6. Plain SMS segment quantity is correct — reservation's
   `estimated_quantity` and commit's `final_quantity` both equal the
   real `SMSCounter::count()` output for a representative multi-segment
   message, proving no second algorithm was introduced.
7. Non-M5 channels (voice/mms/whatsapp/viber/otp) sent through
   `ChatBoxController` preserve their existing `sms_unit` charging
   behavior unchanged, with `Conversations.is_metered = true` active —
   proving the channel half of the §7 guard.
8. **The five non-ChatBox `quickSend()` callers (§3.4 item 2-5) are
   completely unaffected** — at least one representative call through
   `Customer\CampaignController::postQuickSend()` (or the closest
   practical fixture) with `Conversations.is_metered = true` active,
   proving no reservation/ledger row is created and legacy `sms_unit`
   behavior is exactly preserved. This is the audit's own §3.4 finding,
   beyond the originally-anticipated list, and is not optional.
9. Legacy `sms_unit` and the RFC-005 wallet never both charge the same
   send — a single successful metered send is asserted to leave
   `sms_unit` completely unchanged, and a single successful non-
   qualifying send is asserted to leave the wallet completely
   unchanged.
10. Business isolation — two Businesses' reservations/ledger entries
    never cross-contaminate for concurrent/successive sends.
11. Unavailable/unenrolled Business (no wallet row, or null
    `primaryBusiness` under the §3.5 precondition's own fail-closed
    branch) cannot be charged — the send is denied, not silently
    unmetered and not silently legacy-charged.
12. `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`
    — re-run unmodified, still exactly nine keys, `usage_unauthorized`
    now reachable via a real `Conversations` call in addition to its
    existing synthetic coverage.
13. `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php` — re-run
    unmodified; confirms no `Planned` feature became executable and
    `Conversations` availability is unchanged.
14. `ActivateUsageFeatureRateCommandTest` (within §12 item 3's own test
    coverage, may live alongside it) — the command requires every
    numeric/label argument explicitly, refuses to run without
    confirmation, and `activateMetering()` fails correctly if invoked
    before any rate exists (already covered at the `UsageWalletManager`
    unit level — this test proves the command surfaces that failure
    correctly, not that it re-implements the check).

Regression, run in full, not modified except where named above:

- Full `tests/Unit/Usage tests/Feature/Usage` suite.
- Full `tests/Unit/Entitlement tests/Feature/Entitlement tests/Unit/Workspace tests/Feature/Workspace` suite.
- Full test suite (`php artisan test --stop-on-failure`).

---

## 14. Stop conditions

- A 6th path required beyond §12's five.
- The §3.5 precondition query (non-null `primaryBusiness` for every
  real ChatBox-sending Customer) returning a nonzero count in any
  environment the implementation can query.
- Any evidence that `SendCampaignSMS::sendPlainSMS()`'s segment count or
  price can legitimately differ between reservation and commit for a
  plain-SMS send (would contradict §8's "no second algorithm, always
  equal" finding and require re-opening the quantity decision).
- Any of the five non-`Conversations` `quickSend()` callers (§3.4) found,
  during implementation, to require a code change to remain unaffected —
  the §7 guard is designed to need none; if reality disagrees, stop
  and report rather than expanding the guard's own footprint ad hoc.
- A correction round exceeding 2 (`maximum_correction_rounds`, §0).
- Any attempt, by any actor, to call `setActiveRate()`/`activateMetering()`
  against a non-test database with a rate this contract did not receive
  as an explicit human-supplied number (§9.1) — this is a hard stop, not
  a correction-round item.

---

## 15. Verification and publication (this document only)

- `git diff origin/main --name-only` from this branch must show exactly
  one path: `docs/automation/RFC-005-M5-CONTRACT.md`.
- Commit message: `docs: draft RFC-005 M5 metered feature classification contract`.
- Push branch `chore/rfc-005-m5-contract`; open a PR against `main`,
  docs-only; keep it open/Draft for human review. Do not merge. Do not
  begin the implementation worktree/branch until this contract itself is
  merged.
