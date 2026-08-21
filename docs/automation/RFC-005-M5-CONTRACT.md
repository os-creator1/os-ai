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

**Revision note (this refinement pass):** an independent review found
this contract's original §6.1 (idempotency), its treatment of Business
ownership, and its silence on destination-price variance were each
insufficient. All three are re-audited and resolved below. This
refinement is not a correction round — the contract had not yet been
merged, and no implementation exists to correct.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m5-contract`, in an isolated linked
  worktree (`../rfc-005-m5-contract-worktree`), based on `origin/main` at
  `24fd1730e535d2360bb3a6fef7caf97f3272457c` (merge of PR #105, RFC-005
  M4 — Additional-Slot Agreement and Add-ons). Unchanged this pass — no
  new base commit exists to move to, and none is needed for a docs-only
  refinement.
- **Human product decision, locked, not reopened here:** the first real
  metered feature is `PlatformFeature::Conversations`.
- `maximum_correction_rounds: 2`, matching every prior RFC-004/RFC-005
  milestone contract's own convention. Unconsumed — this refinement is
  not a correction round (no implementation PR exists yet for one to
  apply against).
- Any path required during the future implementation but absent from
  §12's own numbered allowlist is a stop-and-report condition — not a
  silent workaround. The stop threshold is the allowlist's own final
  count **plus one** (§14).
- This refinement makes **zero** application changes. Only this one
  document is touched.
- **Audit discipline — bounded, M5-only, this pass:** the exact current
  code of `ChatBoxController::sent()/reply()`,
  `EloquentCampaignRepository::quickSend()`, `chat_boxes`/
  `chat_box_messages` migrations, `Customer::primaryBusiness()`,
  `Business`/`Workspace` models, `WorkspaceController::storeBusiness()`,
  `CustomerBasedPricingPlan`/`PlansCoverageCountries`/
  `SendingServerBasedPricingPlans` schema and their read sites in
  `quickSend()`, `EntitlementManager::assertPlatformAdministrator()`,
  and `config/usage_billing.php`. No unrelated module was touched.

---

## 1. This contract's own exact file scope

Exactly one file, this document:
`docs/automation/RFC-005-M5-CONTRACT.md`.

---

## 2. Preflight — verified

- `origin/main` unchanged at `24fd1730e535d2360bb3a6fef7caf97f3272457c`
  for this refinement pass — no new fetch was needed since no new
  upstream commit exists to react to.
- No RFC-004 file, and no already-merged RFC-005 M1–M4 file, is modified
  by this document.

---

## 3. Mandatory repository audit — findings

### 3.1 `PlatformFeature::Conversations` — current classification and floor

Unchanged from the prior pass: `Conversations` is one of exactly three
`Available` `PlatformFeature` cases; `platform_feature_usage_classifications`
still backfilled `is_metered = false` for it; M5 changes only its
usage-metering state, never its availability.

### 3.2 `UsageWalletManager` — the exact, already-built machinery M5 reuses

Unchanged from the prior pass: `reserve()`/`commit()`/`release()`/
`evaluateCoarseCapacity()`/`setActiveRate()`/`activateMetering()` all
already exist, fully built, at M1, and require zero new wallet-layer
code. **One addition this pass:** neither `setActiveRate()` nor
`activateMetering()` performs any actor-authority check internally —
both trust the caller. This matters directly for §9's command design.

### 3.3 `RealUsageAuthorizationGateway` and `EntitlementManager` — confirmed unbypassed, no new denial key

Unchanged from the prior pass — `usage_unauthorized` is a pre-existing
`decide()` reason, not a new one; no bypass; `Conversations`' own
availability is untouched.

### 3.4 The current `Conversations` execution path — exact, with a structural correction to the prior audit

Unchanged from the prior pass: `ChatBoxController::sent()`/`::reply()`
are the two real entry points; both end by calling
`EloquentCampaignRepository::quickSend()`; `quickSend()` is shared by
five other, unrelated call sites (bulk Quick-Send campaigns, contact-
group auto-welcome SMS, keyword auto-reply, two third-party API
surfaces) that must remain completely unaffected. The §7 opt-in
discriminator (`$input['conversation_context'] = true`, set only at the
two `ChatBoxController` call sites) remains the resolution.

### 3.5 Business resolution — re-audited: coverage is not the same as correct attribution

**This section is substantially revised.** The prior pass verified only
that `Customer::primaryBusiness()` exists and is the sole `User`/
`Customer` → `Business` mapping, then treated a "zero customers with a
null `primaryBusiness`" precondition as sufficient. Independent review
correctly identified that this proves **coverage**, not **correct
ownership attribution** — a Customer can have a non-null
`primaryBusiness` and still have that Business be the *wrong* one for a
specific Conversation, if their Workspace owns more than one Business.

**Direct audit of the actual ownership model, this pass:**

- `chat_boxes` (migration `2021_03_31_125855_...`): columns `id`, `uid`
  (a per-thread UUID), `user_id`, `from`, `to`, `notification`,
  timestamps. **No `business_id` column, no `workspace_id` column, and
  no foreign key to either table, at any point in the schema's history**
  (confirmed by reading every subsequent `chat_boxes` migration; only
  `sending_server_id` was ever added). `chat_box_messages` (migration
  `2021_03_31_130224_...`): `id`, `box_id`, `message`, `media_url`,
  `sms_type`, `send_by`, `sending_server_id`, timestamps — likewise no
  Business/Workspace concept anywhere.
- `EloquentCampaignRepository::quickSend()` and every method that reads
  `$user`/`$user->customer` throughout `ChatBoxController` operate
  purely on the authenticated `User`/`Customer` — never on a `Business`
  or `Workspace` model, never on a route-bound or session-bound Business
  identifier.
- **No current/active-Business resolver, middleware, or session concept
  exists anywhere in the customer-facing HTTP layer.** A targeted search
  for a "current Business" selector (`currentBusiness`, `current_business`,
  an active-Business session key, an active-Business route-model binding)
  found matches only inside RFC-004/RFC-005's own internal library code
  and test runners — never in any customer-facing controller. A Customer
  today has no way, anywhere in the product, to tell the system "this
  action is on behalf of Business B, not Business A."
- **Multi-Business-per-Workspace is a real, live, reachable capability
  today, not a hypothetical:** `WorkspaceController::storeBusiness()`
  (`app/Http/Controllers/Customer/Workspace/WorkspaceController.php:241`)
  is a genuine, routed action that creates an *additional* Business under
  an existing Workspace. `is_primary` exists specifically to disambiguate
  exactly this case (§3.5 of the prior pass already noted this, but did
  not follow the implication through) — if every Workspace could only
  ever have one Business, the column would be pointless. It is not
  pointless; multi-Business Workspaces are an intended, supported, used
  product shape (this is precisely why RFC-004/M4's additional-slot
  billing exists at all).

**Direct answer to the audit's own question:** *"Does repository reality
prove that every current ChatBox Conversation is intentionally owned by
the primary Business?"* **No. Repository reality proves the opposite —
ChatBox Conversations have no Business ownership concept at all in their
own schema, and the wider product has no mechanism for a User to direct
a Conversation at a specific non-primary Business.** Attributing every
send to `primaryBusiness()` is not "reading existing ownership evidence"
— for a Workspace with more than one Business, it would be **guessing**,
and RFC-005's own M4 has already shown multi-Business Workspaces are a
real, current, revenue-relevant case, not an edge case to wave away.

**This is a genuine structural finding, exactly as the audit
instructions warned it might be — but it is resolvable within bounded
M5 scope without inventing cross-Business selection semantics, by
narrowing scope rather than by guessing:**

> **A qualifying M5 metered send additionally requires the sending
> Customer's Workspace to own exactly one Business.** When a Workspace
> owns more than one Business, `primaryBusiness()` is definitionally
> unambiguous only by construction (there is exactly one Business to
> resolve to, not a choice among several) — for such a Workspace, M5
> metering never engages at all, and every Conversation send for that
> Workspace remains on 100% legacy `sms_unit` behavior, regardless of
> `Conversations.is_metered`'s value. This does not charge Business A
> for a Conversation belonging to Business B, because it never attempts
> to attribute a Conversation to any Business in the ambiguous case —
> it simply declines to meter it at all, deferring true multi-Business
> Conversation attribution to a later milestone that would need to
> build the missing selection mechanism (an explicit product feature,
> out of scope here).

This is a new, additional condition on top of the two already locked
(§5.1 gains a fourth item, §5). It requires no new schema — `Workspace`
already `hasMany(Business::class)` (confirmed, prior pass); the guard is
a single `$business->workspace->businesses()->count() === 1` check (or
equivalent), evaluated once per qualifying-send attempt, no new query
shape.

**The §3.5 precondition from the prior pass is superseded, not
discarded:** a null `primaryBusiness` remains a fail-closed deny (§6
step 1); the *new* multi-Business case is a fail-*open*-to-legacy
non-metering, not a deny — the send itself is not blocked, only its
eligibility for wallet metering is withheld. Both are now explicit,
neither is assumed.

### 3.6 Rate dimension — re-audited: destination/plan/gateway pricing variance is real

**New this pass, per the independent review's third issue.** Re-reading
`EloquentCampaignRepository::quickSend()` lines 95-260 in full:

- `$coverage = CustomerBasedPricingPlan::where('user_id', $user->id)->where('country_id', $country->id)->...->first(...)`,
  falling back to `PlansCoverageCountries::where('plan_id', $activeSubscriptionPlanId)->where('country_id', $country->id)->...->first(...)`
  (lines 99-127). **Confirmed: pricing is looked up per destination
  `country_id`, and a specific Customer's own `CustomerBasedPricingPlan`
  row — where one exists — overrides the plan-level default entirely.**
  Two different Customers on the same plan sending to the same country
  can have two different negotiated rates; the same Customer sending to
  two different countries can have two entirely different rates.
- `$priceOption = json_decode($coverage['options'], true)` (line 207) —
  `plain_sms`/`voice_sms`/`mms_sms`/etc. are keys inside this per-
  (Customer-or-plan, country) JSON blob, not fixed columns.
- **A third, independent dimension:** `if (config('app.gateway_wise_billing')) { $priceOption = json_decode(SendingServerBasedPricingPlans::where('sending_server', $sending_server->id)->where('country_id', $country->id)->first()->options, true); }`
  (lines 209-219) — when this config flag is enabled, the price is
  overridden *again*, this time keyed by the specific `sending_server`
  actually selected for that send, independently of the Customer/plan
  lookup above. Confirmed live in the current codebase, not
  hypothetical — it is a real, existing config toggle, not a proposal.

**Direct answer:** plain-SMS retail price varies by destination country,
independently varies (or is overridden) by the sending Customer's own
negotiated plan, and — conditionally, when gateway-wise billing is
enabled — is overridden again by sending server, independent of both.
Internal/provider cost is not tracked as a distinct figure anywhere in
this legacy pricing model at all (the `options` blob carries only the
customer-facing price component actually read by `quickSend()`; no
sibling "our own cost" field is read anywhere in the method). Currency
is implicit to however `options` was configured per row (no explicit
`currency_id` column on any of `plans_coverage_countries`,
`customer_based_pricing_plans`, or `sending_server_based_pricing_plans`)
— unlike `business_usage_rates.currency_id`, which is a mandatory
explicit FK.

**This is a genuine structural mismatch, not a detail to flatten away.**
`business_usage_rates` supports exactly one `retail_rate_micro`/
`provider_cost_micro`/`unit_label` triple per `feature_key` version, with
one explicit `currency_id`. The legacy pricing surface is at minimum
two-dimensional (country × plan) and conditionally three-dimensional
(× sending server), with no independently-tracked provider cost and no
explicit currency at all. **No single `business_usage_rates` row can
truthfully represent this today.** Flattening it into one number (an
average, a single country's price, a single plan's price chosen
silently) would misrepresent real economics exactly as the audit
instructions forbid.

**Smallest correct solution, identified, not implemented:** narrow M5's
own live-activation scope to a single, explicitly human-named
destination country (and, if `gateway_wise_billing` is enabled in the
target environment, implicitly a single sending server for that
country, since the coverage resolution is deterministic per country
once a sending server is assigned) — i.e. add a **configuration-driven**
rate-scope guard, parallel in kind to the numeric-rate decision (§9.1),
never a hardcoded country in application code:

> A qualifying M5 metered send additionally requires the resolved
> destination `country_id` to appear in
> `config('usage_billing.conversations_metering.authorized_country_ids', [])`
> — an array, empty by default (fail-closed: an unset/empty config means
> **no** country qualifies, even if `Conversations.is_metered = true`).
> This is the fifth and final condition on §5.1. Any send to a
> destination outside this explicit allowlist remains on 100% legacy
> pricing, regardless of every other condition.

This is additive to `config/usage_billing.php` (already an existing,
additive-only RFC-005 config file, §12 — no new config file). It does
not resolve *which* country a human should eventually choose — that
remains unresolved and unfabricated, exactly like §9.1's numeric rate,
and is recorded as a second, parallel pre-activation decision in §9.2.
**The alternative — extending `business_usage_rates` itself to carry a
per-country (or per-country-and-plan) rate dimension — is named here as
the only other structurally sound option, and is explicitly out of
scope for M5**, since it is a real schema expansion RFC-005 §36/§39
never authorized for this milestone.

### 3.7 Testability of the real send path — a genuine pre-existing gap

Unchanged from the prior pass: no existing ChatBox feature tests; no
injectable gateway seam in `SendCampaignSMS`; the resolution remains
stubbing at the `Campaigns::sendPlainSMS()` method boundary directly.

---

## 4. Contract status model

Unchanged from the prior pass.

---

## 5. Exact M5 scope — channel, caller, ownership, and destination, all locked

**Strong default confirmed (channel), unchanged from the prior pass:**
M5 meters plain SMS (`plain`/`unicode`) `Conversations` sends only.

**Caller scope, unchanged from the prior pass:** only sends originating
from `ChatBoxController::sent()`/`::reply()` qualify (§7's discriminator).

**Ownership scope, new this pass (§3.5):** only sends from a Workspace
that owns exactly one Business qualify.

**Destination scope, new this pass (§3.6):** only sends to a destination
country explicitly present in a human-populated configuration allowlist
qualify.

### 5.1 What qualifies as an M5-metered plain SMS send

A `quickSend()` invocation qualifies for RFC-005 wallet metering if and
only if **all** of the following hold:

1. `$input['sms_type']` resolves to the `plain` branch (`plain` or
   `unicode`).
2. The call originated from `ChatBoxController::sent()` or
   `ChatBoxController::reply()` (the §7 `conversation_context` flag).
3. `platform_feature_usage_classifications.conversations.is_metered`
   is `true` at the moment of the call.
4. **(New, §3.5.)** The sending Customer's Workspace owns exactly one
   Business (`$business->workspace->businesses()->count() === 1`).
5. **(New, §3.6.)** The resolved destination `country_id` is present in
   `config('usage_billing.conversations_metering.authorized_country_ids', [])`.

Any send failing any one of these five conditions takes the exact same
legacy `sms_unit` code path it takes today — unconditionally, with zero
behavior change.

### 5.2 What MMS/WhatsApp/Viber/OTP/Voice do during M5

Unchanged from the prior pass — always legacy, unconditionally, for
every channel other than plain/unicode.

### 5.3 How non-qualifying sends remain on existing behavior

The dispatch added to `quickSend()` (§7) is strictly additive and gated
on **all five** §5.1 conditions simultaneously. Any call missing even
one — wrong channel, wrong caller, multi-Business Workspace, non-metered
classification, or out-of-scope destination — takes the exact same code
path it takes today, with zero new branches evaluated beyond the guard
checks themselves. This is directly regression-tested (§13).

### 5.4 How later milestones may extend metering without changing M5 history

Unchanged from the prior pass, and now explicitly joined by two more
extension axes future milestones may choose to widen: the ownership
axis (building a real cross-Business selection mechanism for
multi-Business Workspaces) and the destination axis (either widening the
country allowlist, or a genuine schema extension to carry a rate
dimension). M5's own already-activated rows and reservation/ledger
history remain untouched and immutable regardless of which a future
milestone picks.

---

## 6. Reservation lifecycle — exact, locked order

For a qualifying send (§5.1), inside `EloquentCampaignRepository::quickSend()`,
strictly in this order, replacing (not adding alongside) the existing
`sms_unit` pre-check/decrement for this call only:

1. **Business resolution and ownership-scope guard.** `$business = $user->customer->primaryBusiness;`
   If null: fail closed — deny (§3.5's unreachable-state case, unchanged
   from the prior pass). If non-null but the Workspace owns more than
   one Business (§5.1 item 4): **this send is not a qualifying send at
   all** — control falls through to the existing, unmodified legacy
   code path, exactly as if `conversation_context` had never been set.
2. **Destination-scope guard.** If the resolved `country_id` is not in
   the configured allowlist (§5.1 item 5): likewise not a qualifying
   send — falls through to legacy, unchanged.
3. **Entitlement/usage authorization.** `EntitlementManager::decide($workspace, $business, PlatformFeature::Conversations->value, $actorUserId)`,
   unchanged from the prior pass. Denial → existing error-shaped
   response carrying `usage_unauthorized`; no rate lookup, no
   reservation, no provider call.
4. **Rate lookup.** Implicit inside `reserve()` (§3.2).
5. **Quantity calculation.** Reuse `$sms_count` from
   `SMSCounter::count($message, null)` (§8) — unchanged.
6. **Wallet reserve.** `$reservation = $walletManager->reserve($business, PlatformFeature::Conversations->value, $idempotencyKey, (string) $sms_count);`
   using the idempotency key defined in §6.1 (**substantially revised
   this pass**). Denial → existing error-shaped response; no provider
   send occurs.
7. **Provider send.** Unchanged: `$campaign->sendPlainSMS($preparedData)`.
8. **Commit on durable success.** On `Delivered` status:
   `$walletManager->commit($reservation->reservationId, (string) $sms_count);`,
   `$finalQuantity` explicitly re-passed as the identical `$sms_count`
   (§8).
9. **Release on definite non-send/failure.** Any other terminal outcome:
   `$walletManager->release($reservation->reservationId);`.
10. **Crash/retry/idempotency recovery.** Resolved by §6.1's client-token
    design, not by a per-request server-generated key.

**No provider send may occur after a denied usage decision:** steps 1-3
and 6 all return (or fall through to legacy, for steps 1-2) before step 7
is reached whenever they deny/disqualify — a straight-line sequence.

### 6.1 Idempotency key — durable, client-sourced, re-derived, and proven — REVISED

**The prior pass's design (a fresh `Str::uuid()` minted server-side once
per HTTP request) is withdrawn.** It only deduplicated a retry that
somehow reused the exact same in-memory PHP variable within a single
request's execution — which no real crash/retry scenario in this
synchronous, non-queued send path actually produces. It could not
deduplicate the one retry scenario that genuinely occurs in this code
today, and the prior draft's own "honest limitation" wording incorrectly
treated that as acceptable. It is not: the audit instructions are
explicit that "same HTTP request" is not an adequate scope, and that
legacy double-click behavior does not make double-charging acceptable
for wallet money.

**Direct audit of the actual retry scenario, this pass:** `reply()`'s
own client-side handler, `resources/views/customer/ChatBox/index.blade.php`'s
`enter_chat()` function (lines 421-513): the Send button is disabled
before the AJAX call (line 427) and **re-enabled on both success (line
445) and error (line 491)**; on error, the composed message text is
**not** cleared (only `success` clears it, line 477). This means the
real, designed, expected recovery path after a timeout/5xx/network
failure is: the user sees an error toast, the button re-enables, the
identical message text is still in the textbox, and the user clicks
"Send" again — invoking `enter_chat()` a second time with materially
identical content. This is the retry this contract must protect, and it
is a **second, independent HTTP request** by construction — no
server-side identifier minted fresh per-request can ever equal itself
across two such requests.

**No pre-existing durable identifier survives across this retry
either:** `chat_boxes.uid` is a per-thread UUID, not per-send, and (per
§3.5) is not even guaranteed to exist before a send completes; neither
`Reports` nor `ChatBoxMessage` is persisted until after the provider
responds (confirmed, prior pass). **This is exactly the "no" branch the
audit instructions anticipated: durable send-operation identity does not
already exist. Solving it correctly requires a bounded client-side/view
change — not a new schema row, since the durable persistence point
already exists at `reserve()`'s own `business_usage_reservations.idempotency_key`
column, written inside `reserve()`'s own transaction, strictly before
the provider call (§3.2, unchanged).**

**Exact mechanism, locked:**

- **`ChatBoxController::sent()` (traditional full-page form POST,
  `resources/views/customer/ChatBox/new.blade.php`):** the Blade view
  gains one hidden field, rendered once per page load:
  `<input type="hidden" name="idempotency_token" value="{{ (string) \Illuminate\Support\Str::uuid() }}">`.
  A literal browser "resend form data" (back button + refresh, or a
  double form-submit before navigation) resends this exact hidden value
  unchanged, because it is part of the already-rendered, already-posted
  form body — no JS is needed for this entry point. A genuinely new
  compose action is always a fresh page load, which always renders a
  fresh token.
- **`ChatBoxController::reply()` (AJAX, `index.blade.php`'s
  `enter_chat()`):** the inline script gains a per-compose token held in
  a JS variable scoped to the open conversation, generated lazily the
  first time `enter_chat()` runs for a given not-yet-successfully-sent
  message, included in the `FormData` (`formData.append("idempotency_token", token)`),
  and **only cleared (a fresh token minted for the next message) inside
  the existing `success` branch (line 477, where the textbox itself is
  also cleared)** — never inside the existing `error` branch (line
  490-513), so a user-initiated retry after a failure/timeout resends
  the identical token together with the identical message text, exactly
  mirroring the existing UI's own already-designed retry affordance.
- **Server-side:** both entry points read `$request->input('idempotency_token')`
  (validated `required|uuid` — a new rule added to `SentRequest` for
  `sent()`; validated inline for `reply()`, which has no dedicated
  `FormRequest` class today and gains none, consistent with "smallest
  bounded"). The value passed to `reserve()` is
  `hash('sha256', 'conversations_plain_sms:'.$idempotencyToken)` — a
  fixed namespace prefix, so an identical raw token can never collide
  with a reservation minted by any other feature or call site, even
  though `business_usage_reservations.idempotency_key` is a single
  cross-feature column.

**Proof — two legitimate distinct sends can never collide:** each
distinct compose action mints its own token (a fresh page load for
`sent()`; the `success`-gated regeneration for `reply()`) via
`Str::uuid()`/JS `crypto.randomUUID()`-equivalent, which is
collision-resistant by construction; the namespace prefix additionally
guarantees no cross-feature collision even in the astronomically
unlikely event of a raw UUID collision. Two different real messages
therefore always produce two different `reserve()` idempotency keys,
hence two independent reservations, hence two independent charges —
correct, since two real sends are two real costs.

**Proof — a crash/retry of the same logical send reuses the same key:**
for `sent()`, the token is part of the literal resent HTTP body on any
browser-level resubmission of the same form instance — bit-for-bit
identical, by definition of what a form resend is. For `reply()`, the
token is held in JS state that is not regenerated between the original
failed/timed-out attempt and the user's subsequent same-text retry
click, per the exact `success`-only-clears logic above — so the second
`enter_chat()` invocation submits the identical token. In both cases,
`reserve()`'s own pre-existing `findByIdempotencyKey()` dedup (§3.2,
unchanged) then makes the retry's `reserve()` call a no-op returning the
original reservation id, and `commit()`/`release()` remain idempotent on
that reservation's terminal state — so the retry can never double-charge.

**Remaining, narrower, honestly-stated limitation:** a *literal*
duplicate submission with no error in between — e.g. a true accidental
double-click landing two overlapping in-flight requests before the
button-disable takes visual effect, or a user opening two browser tabs
and composing the same message independently — still produces two
tokens in the `reply()` case (each tab holds independent JS state) and,
for `sent()`, two tokens only if the user reloads `new.blade.php` between
the two submissions (a fresh load always re-mints). A same-tab,
same-load double-click on `reply()`'s Send button, however, is now
*safe*, since `enter_chat()`'s existing `$(".send").attr("disabled", true)`
(line 427) already prevents a second invocation before the first
resolves, and the token is only regenerated after `success` — this was
already true of the button-disable mechanism today and required no
contract change to remain true; §6.1's own change addresses the
network-failure retry case specifically, which the button-disable alone
cannot, since it re-enables on error. This narrower residual case (two
literal concurrent, independently-composed submissions with no shared
client state) is not a "retry of the same logical send" in the sense the
audit instructions used the term — it is two distinct user actions that
happen to carry identical text — and is explicitly out of scope, exactly
as the equivalent case is for M3/M4's own client-initiated payment
retries.

---

## 7. `quickSend()` discriminator — the exact, minimal, additive change

Unchanged from the prior pass in mechanism; the guard condition set it
feeds now includes all five §5.1 conditions rather than two. `ChatBoxController::sent()`/`::reply()`
each gain `$input['conversation_context'] = true;`; `quickSend()` gains
a guard evaluating all of §5.1 in sequence (§6), falling through to
the completely unmodified existing code whenever any condition fails.

---

## 8. Quantity — no new algorithm

Unchanged from the prior pass: `$sms_count` from the existing
`SMSCounter::count($message, null)` call is the sole source of both
`estimatedQuantity` and `finalQuantity`, always identical for M5's scope.

---

## 9. Pricing activation — mechanism authorized, numbers explicitly not

### 9.1 The command, and the actor-authority mechanism — LOCKED, no longer open

**The prior pass left actor-authority resolution as an implementation-
time choice ("authenticated CLI context or explicit `--actor-user-id=`").
Per the independent review, this is now locked, not deferred:**

- `app/Console/Commands/ActivateUsageFeatureRate.php`, signature:
  `usage:activate-rate {feature} {retail-rate-micro} {provider-cost-micro} {unit-label} {currency-code} {--actor-user-id=} {--reason=}`.
- **`--actor-user-id=<id>` is required** (the command fails immediately,
  before touching `UsageWalletManager`, if it is omitted or non-numeric)
  — there is no "authenticated CLI context" fallback; a console command
  has no HTTP session to authenticate from, and inventing an implicit
  actor would itself be the kind of unstated authority resolution the
  review is right to reject.
- **Validated through the existing platform-administrator authority
  seam, exactly:** `EntitlementManager::assertPlatformAdministrator(int $actorUserId)`
  (`app/Library/Entitlement/EntitlementManager.php:1244`) is a private
  method and is not modified or exposed to reach it — instead, the
  command performs the **identical** check it already performs
  internally: `(bool) User::query()->whereKey($actorUserId)->value('is_admin')`.
  This is not an invented parallel authority system; it is a direct,
  literal reuse of the exact same `users.is_admin` boolean column
  `EntitlementManager` itself already reads for the identical purpose,
  confirmed by direct read of that method's body. If `is_admin` is not
  true for the supplied id, the command aborts before calling
  `setActiveRate()`/`activateMetering()` and writes nothing.
- The command then prompts for confirmation, and — only on explicit
  confirmation — calls `setActiveRate()` then `activateMetering()` (in
  that order, matching the existing mandatory sequencing `activateMetering()`
  itself already enforces, §3.2).
- No auto-discovery registration change (`app/Console/Kernel.php`
  already auto-loads `app/Console/Commands/`).
- **This contract does not authorize actually running this command
  against any real environment**, and does not supply any numeric value.

### 9.2 Unresolved human decisions — recorded, not fabricated, both gate go-live

Two independent values remain pre-implementation human decisions,
neither fabricated:

1. **Numeric rate** (unchanged from the prior pass) — exact
   `retail_rate_micro`, `provider_cost_micro`, `unit_label`, and
   settlement currency for `Conversations` plain-SMS metering. RFC-005
   §39 item 1 remains `NON-IMPLEMENTATION-READY` until resolved.
2. **Destination-scope configuration** (new, §3.6) — which destination
   `country_id`(s) populate
   `config('usage_billing.conversations_metering.authorized_country_ids')`.
   Unset/empty by default (fail-closed — no country qualifies, mirroring
   `usage_billing.webhook_event.retention_days`'s own existing
   "no default invented, must fail closed" precedent, §12).

**Lifecycle, stated exactly (resolves the independent review's fifth
issue):** **Option A.** Implementation may begin and be fully completed,
tested, and merged with the mechanism built and proven entirely against
the isolated test database's own fixture-only rate and fixture-only
country allowlist. **M5 is not "complete" in the sense of a live,
revenue-relevant metered feature, and `Conversations.is_metered` must
remain `false` in every real (non-test) environment, until a human has
separately supplied both the numeric rate (item 1) and the destination
allowlist (item 2) and has separately, explicitly run §9.1's command
against that real environment.** The implementation PR itself must not
flip either switch outside its own test suite, must not pre-populate the
config allowlist with any real country in any environment's checked-in
config/`.env.example`, and must not treat "the code merged" as
equivalent to "M5 is live." This mirrors, and now explicitly extends to
both values, the numeric-rate-only framing the prior pass already
applied to item 1 alone.

---

## 10. Existing entitlement behavior — preserved, confirmed by §3.3

Unchanged from the prior pass.

---

## 11. Schema

**No new migration, no new table, no new column.** Unchanged from the
prior pass — every table M5 touches already exists from M1. The two new
§5.1 conditions (ownership, destination) are evaluated against already-
existing columns (`businesses.workspace_id` cardinality via the existing
relation) and a new **configuration** key, not a new schema element.

---

## 12. Exact implementation allowlist — REVISED, expanded from 5 to 10

The prior pass's 5-path allowlist did not account for the client-side
idempotency-token mechanism (§6.1) or for the dedicated command-test
path the independent review's fourth issue required to be made explicit
rather than left ambiguous. Both are now named. The stop threshold is
item 11 — any path needed beyond the ten named here is a stop-and-report
condition.

### Modify (6)

1. `app/Http/Controllers/Customer/ChatBoxController.php` — add
   `$input['conversation_context'] = true;` at the two existing call
   sites (§7). No other line changes.
2. `app/Repositories/Eloquent/EloquentCampaignRepository.php` —
   `quickSend()` gains the full §5.1/§6 guard chain and reserve/commit/
   release wiring, scoped strictly inside the new conditional branch(es);
   every existing unconditional code path is untouched.
3. `app/Http/Requests/ChatBox/SentRequest.php` — add
   `'idempotency_token' => 'required|uuid'` to `rules()`.
4. `resources/views/customer/ChatBox/new.blade.php` — add the one hidden
   `idempotency_token` field (§6.1).
5. `resources/views/customer/ChatBox/index.blade.php` — extend
   `enter_chat()`'s existing inline script with the lazy-generate/
   `success`-only-clear token logic (§6.1); no other behavior in this
   file changes.
6. `config/usage_billing.php` — add the
   `conversations_metering.authorized_country_ids` key (§3.6/§9.2),
   following the file's own existing "additive only, no default
   invented" convention.

### New (4)

7. `app/Console/Commands/ActivateUsageFeatureRate.php` — §9.1's operator
   command.
8. `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` — §13
   tests 1-6, 8, 11-14.
9. `tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`
   — §13 test 7 and the non-ChatBox-caller regressions.
10. `tests/Feature/Usage/ActivateUsageFeatureRateCommandTest.php` — §13
    test 15, dedicated (resolves the independent review's fourth issue
    explicitly: this is its own authorized path, not folded into an
    unrelated file and not silently absent).

### Read-only dependencies (relied upon, never modified)

- `app/Library/Usage/UsageWalletManager.php`
- `app/Library/Entitlement/EntitlementManager.php` (including
  `assertPlatformAdministrator()`'s body, read to reproduce its exact
  `is_admin` check — not called, not modified, not exposed)
- `app/Library/Entitlement/RealUsageAuthorizationGateway.php`
- `app/Library/Entitlement/PlatformFeatureRegistry.php`
- `app/Enums/Entitlement/PlatformFeature.php`
- `app/Enums/Entitlement/PlatformFeatureAvailability.php`
- `app/Models/Customer.php` (`primaryBusiness()`)
- `app/Models/Business.php`, `app/Models/Workspace.php` (including the
  `businesses()` relation used for the new ownership-scope guard)
- `app/Models/User.php` (`is_admin` column, read by the new command)
- `app/Models/PlatformFeatureUsageClassification.php`,
  `app/Models/BusinessUsageRate.php`
- `app/Repositories/Contracts/CampaignRepository.php` (interface,
  unchanged)
- `app/Models/SendCampaignSMS.php` / `app/Models/Campaigns.php`
  (`sendPlainSMS()`, mocked in tests per §3.7, never modified)
- `app/Console/Kernel.php`
- `routes/customer.php`
- `app/Http/Controllers/Customer/CampaignController.php`,
  `app/Http/Controllers/API/CampaignController.php`,
  `app/Http/Controllers/API/CampaignHTTPController.php`,
  `app/Repositories/Eloquent/EloquentContactsRepository.php`,
  `app/Http/Controllers/Customer/DLRController.php` (the five
  non-`Conversations` `quickSend()` callers)
- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
  (`storeBusiness()`, read only to confirm multi-Business creation is
  live — not modified)
- `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`,
  `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php`

**Total mechanically-authorized paths: 10. Stop threshold: 11th path.**

---

## 13. Required tests

New (across the three new test files, §12 items 8-10, or split further
provided every case is covered exactly once):

1. Metered `Conversations` plain-SMS send (single-Business Workspace, in-
   scope destination country) succeeds → exactly one reservation and one
   `commit()`, wallet debited exactly segment-count × retail-rate.
2. Denied wallet (suspended / outstanding debt / insufficient balance) →
   the `sendPlainSMS()` double is never invoked.
3. Provider failure before durable success → reservation released.
4. Retry/replay with the same client-supplied `idempotency_token` (§6.1)
   → no duplicate wallet charge — asserted through the actual
   `sent()`/`reply()` call sites, not only at the `UsageWalletManager`
   unit level.
5. **New this pass, directly proving §6.1's own claims:** (a) two
   distinct compose actions produce two distinct reservations and two
   distinct charges (no false-positive dedup); (b) a simulated
   `reply()` network-failure-then-retry (same JS-held token, same
   message, matching the `error`-branch-does-not-clear-the-token
   behavior) produces exactly one reservation and one charge.
6. Plain SMS segment quantity correctness (unchanged from the prior
   pass).
7. Non-M5 channels (voice/mms/whatsapp/viber/otp) preserve existing
   `sms_unit` behavior with `Conversations.is_metered = true` active.
8. The five non-ChatBox `quickSend()` callers are completely unaffected.
9. Legacy `sms_unit` and the RFC-005 wallet never both charge the same
   send.
10. Business isolation.
11. **Revised this pass:** a Workspace owning more than one Business
    sends a plain-SMS Conversation with `Conversations.is_metered = true`
    and an in-scope destination country active — asserts **zero**
    reservation/ledger rows are created and legacy `sms_unit` behavior
    is exactly preserved (proves §5.1 item 4, replacing the prior pass's
    narrower "null `primaryBusiness`" framing, which is now folded in as
    the unreachable fail-closed sub-case rather than the headline test).
12. **New this pass:** a destination country absent from
    `conversations_metering.authorized_country_ids` preserves legacy
    `sms_unit` behavior even with every other §5.1 condition satisfied
    (proves §5.1 item 5); a destination present in the allowlist, with
    every other condition satisfied, does engage the wallet.
13. `EntitlementManagerNineKeySurfaceUnchangedTest` and
    `PlatformFeatureRegistryTest` re-run unmodified.
14. **New this pass:** `ActivateUsageFeatureRate` refuses to run without
    `--actor-user-id`; refuses to run when the supplied id's `is_admin`
    is not true; succeeds only for a genuine admin id, in the correct
    `setActiveRate()`-then-`activateMetering()` order.

Regression, run in full, not modified except where named above:

- Full `tests/Unit/Usage tests/Feature/Usage` suite.
- Full `tests/Unit/Entitlement tests/Feature/Entitlement tests/Unit/Workspace tests/Feature/Workspace` suite.
- Full test suite (`php artisan test --stop-on-failure`).

---

## 14. Stop conditions

- An 11th path required beyond §12's ten.
- The §3.5 null-`primaryBusiness` precondition query returning a nonzero
  count in any queryable environment.
- Any evidence that segment count/price can legitimately differ between
  reservation and commit for a plain-SMS send.
- Any of the five non-`Conversations` `quickSend()` callers found, during
  implementation, to require a code change to remain unaffected.
- A correction round exceeding 2.
- Any attempt to call `setActiveRate()`/`activateMetering()` against a
  non-test database with a rate this contract did not receive as an
  explicit human-supplied number (§9.2 item 1) — hard stop.
- **New this pass:** any attempt to populate
  `conversations_metering.authorized_country_ids` with a real country in
  any checked-in config or `.env.example` (§9.2 item 2) — hard stop,
  identical in kind to the numeric-rate stop condition.
- **New this pass:** any evidence, found during implementation, that
  `enter_chat()`'s existing button-disable/error-handling behavior no
  longer matches what §6.1 assumes (e.g. a concurrent unrelated UI change
  clears the message text or regenerates state on the `error` branch) —
  stop and re-verify the idempotency proof rather than assuming it still
  holds.

---

## 15. Verification and publication (this document only)

- `git diff --check` clean.
- `git diff origin/main --name-only` shows exactly one path:
  `docs/automation/RFC-005-M5-CONTRACT.md`.
- Commit message: `docs: refine RFC-005 M5 metering contract`, as a new
  commit on the existing branch, not an amend.
- Push `chore/rfc-005-m5-contract`. PR #107 remains Draft, unmerged. No
  implementation begins.
