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

**Revision history (historical context only — every rule that survived is
restated in full, normatively, in the numbered sections below; nothing
past this point requires reading an earlier commit on this branch).**
This document was drafted, then refined four times before merge, all as
pre-merge contract refinement consuming zero correction rounds (no
implementation PR has ever existed for one to apply against):

1. Initial draft, selecting `Conversations`' plain-SMS send path as M5's
   scope, reusing the already-built M1 wallet/rate/classification
   machinery.
2. Independent review found the original idempotency design (a fresh
   per-HTTP-request key) did not survive a real retry; found that
   `Customer::primaryBusiness()` proves coverage but not correct
   ownership for a multi-Business Workspace; and found destination-
   country price variance was real. Resolved with a client-sourced
   token, a single-Business-Workspace guard, and a country allowlist.
3. Independent review found the client token alone did not make the
   *provider call* safe against a stuck/racing reservation; found
   country-only scoping left customer-plan and sending-server price
   variance open; and found an internal inconsistency in how
   `ChatBoxController::reply()` was described propagating the token.
   Resolved with an explicit reservation-status state machine, a wider
   pilot allowlist, and a corrected `reply()` description.
4. Independent review found the reservation-status check itself relied
   on an unsound `reserved_at` timestamp comparison; found gateway-*type*
   scoping did not pin the actual price-determining `SendingServer` row;
   and narrowed the manual-resolution command. Resolved with a database-
   enforced atomic claim (`ReservationResult::createdByThisInvocation`)
   and a singular scalar pilot tuple.
5. Independent review found the Twilio-outcome classification (a
   `'Rejected'`-string check) was factually wrong, because
   `sendPlainSMS()`'s own post-processing erases that string before
   `quickSend()` ever sees it; and tightened the unique-constraint catch
   so it cannot mask an unrelated database error. Resolved by authorizing
   a narrow, opt-in addition to `app/Models/SendCampaignSMS.php` that
   returns an explicit, machine-readable outcome marker.

This final consolidation pass makes **zero** design changes of its own —
it exists solely to make the document below self-contained, so that an
implementer reading only this file, at this commit, on `main`, has the
complete and authoritative M5 specification without consulting any
earlier commit on `chore/rfc-005-m5-contract`.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m5-contract`, in an isolated linked
  worktree (`../rfc-005-m5-contract-worktree`), based on `origin/main` at
  `24fd1730e535d2360bb3a6fef7caf97f3272457c` (merge of PR #105, RFC-005
  M4 — Additional-Slot Agreement and Add-ons, including its Correction
  Round 2 and the deterministic same-ordinal retry-race fix). This base
  SHA has not moved across any refinement pass.
- **Human product decision, locked, not reopened by this contract:** the
  first real metered feature is `PlatformFeature::Conversations`. This
  was selected after a bounded, read-only M5 candidate audit (a
  conversation preceding this contract) that inspected
  `PlatformFeatureRegistry`, the `Conversations`/`Automations`/`Crm`
  execution paths, and the legacy `sms_unit` quota stack. That prior
  audit's own findings are re-verified, extended, and — wherever the two
  disagree — superseded by §3 below; this contract is the authoritative
  document.
- `maximum_correction_rounds: 2`, matching every prior RFC-004/RFC-005
  milestone contract's own convention. Unconsumed as of this document —
  no implementation PR exists yet for a correction round to apply
  against; every refinement to this document itself is pre-merge
  drafting, not a correction round.
- Any path required during the future implementation but absent from
  §12's own numbered allowlist is a stop-and-report condition — not a
  silent workaround. The stop threshold is the allowlist's own final
  count **plus one** (§14).
- Drafting and refining this contract makes **zero** application
  changes. No `app/`, `database/`, `routes/`, `config/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by this
  branch — only this one document (§1).
- **Cumulative audit discipline — bounded, M5-only, across every pass
  that produced this document:** `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md`
  §36 item 5, §37, §39 item 11; the M1 contract's own schema/algorithm
  sections for `platform_feature_usage_classifications`,
  `business_usage_rates`, and `UsageWalletManager`'s full public surface;
  the RFC-004 `EntitlementManager::decide()`/`PlatformFeatureRegistry`/
  `RealUsageAuthorizationGateway` seam; the actual current `Conversations`
  execution path (`ChatBoxController`, `EloquentCampaignRepository::quickSend()`,
  `SendCampaignSMS::sendPlainSMS()`, `User::countSMSUnit()`); the
  `chat_boxes`/`chat_box_messages` migrations; `Customer::primaryBusiness()`;
  `Business`/`Workspace` models; `WorkspaceController::storeBusiness()`;
  `CustomerBasedPricingPlan`/`PlansCoverageCountries`/
  `SendingServerBasedPricingPlans` schema and their exact read sites in
  `quickSend()`; `EntitlementManager::assertPlatformAdministrator()`;
  `config/usage_billing.php`; `resources/views/customer/ChatBox/index.blade.php`'s
  `enter_chat()` handler in full; `UsageWalletManager::reserve()`/
  `commit()`/`release()` and `EloquentBusinessUsageReservationRepository::findByIdempotencyKey()`
  read line-by-line; the `business_usage_reservations` migration's exact
  columns; `ExpireStaleUsageReservations`;
  `UsageBillingCheckoutManager::finalizeAddonPurchaseIfPending()`'s
  existing `UniqueConstraintViolationException`-absorption idiom; the
  full `SendCampaignSMS::sendPlainSMS()` method boundary
  (`app/Models/SendCampaignSMS.php` lines 71-13429) end-to-end, including
  its two Twilio/TwilioCopilot case blocks (lines 457-520, confirmed the
  only two occurrences of `SendingServer::TYPE_TWILIO`/`TYPE_TWILIOCOPILOT`
  inside this method) and its shared post-processing (lines 13379-13424);
  and the existing test surface for all of the above (`tests/Feature/Usage/*`,
  `tests/Feature/Entitlement/*`). No unrelated legacy module (Automations,
  Crm/Contacts, the Plan/Subscription billing UI, SEO/Ads/White-Label
  scaffolding) was modified or newly explored beyond confirming it
  remains untouched by this contract's scope. The vendored Twilio SDK
  package itself (`vendor/twilio`) is **not present** in the worktree this
  contract was drafted in — its exception-hierarchy internals are
  therefore explicitly not verified by this audit; §3.9 states exactly
  what this does and does not limit.

---

## 1. This contract's own exact file scope

Exactly one file, this document: `docs/automation/RFC-005-M5-CONTRACT.md`.

---

## 2. Preflight — verified

- `origin/main` confirmed exactly `24fd1730e535d2360bb3a6fef7caf97f3272457c`
  before this document was first drafted, and unchanged through every
  subsequent refinement pass — no new upstream commit has existed for
  this branch to react to.
- This branch (`chore/rfc-005-m5-contract`) is `origin/main` itself at
  that SHA plus this document's own commits — trivially a descendant.
- `bcmath` remains confirmed enabled (RFC-005 M1 contract; re-confirmed
  unchanged through M2–M4; no new PHP extension dependency is introduced
  by M5).
- No RFC-004 file, and no already-merged RFC-005 M1–M4 file, is modified
  by this document. §12's allowlist authorizes a precise, numbered set of
  existing files to be modified by the future implementation, and no
  others.

---

## 3. Mandatory repository audit — findings

### 3.1 `PlatformFeature::Conversations` — current classification and floor

- `app/Enums/Entitlement/PlatformFeature.php` — the `Conversations` case
  is confirmed present, unchanged since RFC-004.
- `app/Library/Entitlement/PlatformFeatureRegistry.php` —
  `PlatformFeature::Conversations->value => PlatformFeatureAvailability::Available`,
  confirmed unchanged. This is one of exactly three `Available` cases
  (`Crm`, `Conversations`, `Automations`); every other case remains
  `Planned`. M5 changes **only** `Conversations`' *usage-metering* state,
  never its *availability* — `PlatformFeatureRegistry` itself is not
  touched by this contract's allowlist, and no `Planned` feature is
  touched at all.
- `platform_feature_usage_classifications` (migration
  `2026_08_16_120004_...`, M1): one row per `PlatformFeature` case,
  backfilled `is_metered = false`, `active_rate_id = null`. Confirmed via
  direct read this row still exists unmodified for `conversations` on
  `origin/main` — no correction round of M1–M4 ever flipped it (M1
  contract §11).

### 3.2 `UsageWalletManager` — the exact, already-built machinery M5 reuses

Read in full from `app/Library/Usage/UsageWalletManager.php` on
`origin/main`:

- **`reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $estimatedQuantity = null): ReservationResult`**
  (line 233) — an idempotency-key lookup first (`findByIdempotencyKey()`);
  inside one `DB::transaction`: locks the wallet row, rolls the spend
  period over if needed, resolves the feature's active rate (throws
  `NoActiveRateForFeatureException` if `active_rate_id` is null — i.e.
  **`reserve()` cannot be called at all until a rate is activated**),
  computes `reservedAmountMicro = round(rate × quantity)`, then denies
  with a specific reason (`wallet_suspended`/`outstanding_debt`/
  `insufficient_balance`) before ever writing a reservation row if the
  wallet cannot support the charge. On success, writes one
  `business_usage_reservations` row (`status = Pending`) and one
  `business_usage_ledger_entries` row, debits `available_balance_micro`,
  credits `reserved_balance_micro`. Already handles auto-recharge
  dispatch. **§3.8 below widens this method's exact concurrency
  behavior; this subsection describes its baseline mechanics.**
- **`commit(int $reservationId, ?string $finalQuantity = null): CommitResult`**
  (line 354) — idempotent: a repeat call on an already-`Committed`
  reservation reconstructs and returns the original `CommitResult`
  without writing anything new. Rejects any other non-`Pending` state
  transition. Computes `finalAmountMicro` from
  `finalQuantity ?? estimated_quantity`, writes the charge ledger entry,
  and correctly handles both overage (final > reserved) and unused-
  release (final < reserved) sub-cases — neither of which M5's own
  plain-SMS scope will ever exercise (§8).
- **`release(int $reservationId): void`** (line 541) — idempotent on an
  already-terminal (`released`/`expired`) reservation; releases the full
  reserved amount back to `available_balance_micro`.
- **`evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision`**
  (line 790) — the exact seam `RealUsageAuthorizationGateway::check()`
  calls. Returns `authorized: true` unconditionally when the feature's
  classification is null or `is_metered = false` — this is why M1–M4 are
  "provably behaviorally identical to `NullUsageAuthorizationGateway`"
  (the gateway's own docblock). Once `Conversations.is_metered = true`,
  this method starts returning `wallet_missing`/`wallet_suspended`/
  `outstanding_debt`/`authorized: true` — the same three denial reasons
  `reserve()` itself re-derives more precisely (plus
  `insufficient_balance`, which the coarse gate deliberately does not
  check, since it is non-mutating and cheap by design, RFC-005 §14).
- **`setActiveRate(string $featureKey, string $retailRateMicro, string $providerCostMicro, string $unitLabel, int $currencyId, int $actorUserId, string $reason): BusinessUsageRate`**
  (line 702) and **`activateMetering(string $featureKey, int $actorUserId, string $reason): void`**
  (line 755) — **both already exist, fully built, at M1.** Neither has
  ever been called by any production code path (M1 contract's own
  docblock: *"Present at M1; never called by any M1 production code path
  ... no metered feature is authorized until M5"*). `activateMetering()`
  itself throws `NoActiveRateForFeatureException` if no rate is active
  yet — the two calls have a mandatory order (rate first, then
  metering), already enforced by the existing code, not something M5
  needs to add. **Neither method performs any actor-authority check
  internally — both trust the caller completely.** This is why §9.1's
  new operator command must perform its own authority check before
  calling either.

**Conclusion: M5 needs no new wallet-mechanics code beyond the two
narrow, precisely-bounded widenings §3.8 describes.** The entire
reserve/commit/release/rate/classification machinery is otherwise
complete, tested, and behaviorally proven. M5's job is exclusively (a)
wiring the *call sites* into the real `Conversations` send path, (b)
building the narrow human-operated mechanism to actually flip the two
already-existing switches, and (c) the two small, explicitly-authorized
concurrency/evidence corrections in §3.8 and §3.9 — never inventing new
wallet mechanics beyond those.

### 3.3 `RealUsageAuthorizationGateway` and `EntitlementManager` — confirmed unbypassed, no new denial key

- `app/Library/Entitlement/RealUsageAuthorizationGateway.php` — already
  bound in `AppServiceProvider` in place of `NullUsageAuthorizationGateway`
  (M1 contract §36 item 1). `check()` returns exactly one denial reason
  string, `'usage_unauthorized'`, regardless of which internal coarse-
  capacity reason fired — the internal reason is deliberately never
  surfaced past this boundary (RFC-005 §14).
- `app/Library/Entitlement/EntitlementManager.php` — `decide()`'s
  denial-reason surface (`disabled_for_business`, `not_entitled_by_plan`,
  `denied_by_workspace_override`/`allowed_by_workspace_override`, and
  `usage_unauthorized` among them, nine keys total) is already exercised
  end-to-end by `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`,
  which already exists and already asserts this exact nine-key surface.
  **`usage_unauthorized` is not a new key M5 introduces** — it already
  exists in `decide()`'s reason space today, currently unreachable in
  practice only because every classification is `is_metered = false`.
  M5 makes it reachable for `Conversations` specifically; it does not
  add a tenth key. `EntitlementManager` remains the only call path into
  usage authorization, never bypassed; `Conversations`' own availability
  remains unchanged; no `Planned` feature is touched at all.
- `app/Library/Entitlement/EntitlementManager.php:1244` also defines a
  private method, `assertPlatformAdministrator(int $actorUserId): void`,
  whose exact body is `(bool) $this->userRepository->query()->whereKey($actorUserId)->value('is_admin')`,
  throwing an `AuthorizationException` if that value is not true. This
  method is private and is not exposed or modified by this contract —
  §9.1's new command reproduces this identical check directly against
  the `users.is_admin` column, rather than requiring `EntitlementManager`
  to expose a new public method.

### 3.4 The current `Conversations` execution path — six `quickSend()` callers

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
  95-260), segment count comes from `SMSCounter::count($message, ...)`
  (lines 221-223, `'WHATSAPP'` mode selected only for the whatsapp
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
  uses instead (`Automation::track_message()`, out of scope for M5). The
  mechanism (a raw, unlocked, delivery-gated `sms_unit` decrement) is
  identical in spirit between the two callers, but they are two distinct
  call sites in two distinct files, and only the `quickSend()` one is in
  scope for `Conversations`.
- **`quickSend()` is not exclusive to `ChatBoxController`.** A direct
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
  silently misclassify all six call sites as `Conversations` usage — a
  real correctness defect, not a hypothetical one.** §5 and §7 resolve
  this with an explicit, additive, opt-in discriminator rather than by
  redesigning the shared method or duplicating its channel-dispatch logic
  in `ChatBoxController`.

### 3.5 Business resolution — coverage of `primaryBusiness()` is not the same as correct ownership attribution

`app/Models/Customer.php:131` —
`public function primaryBusiness(): HasOne { return $this->hasOne(Business::class, 'customer_id', 'user_id')->where('is_primary', true); }`.
This is the only existing `User`/`Customer` → `Business` mapping
anywhere in the repository; there is no alternative to invent from.
`app/Models/Business.php` confirms `belongsTo(Workspace::class)`, and
`Workspace` confirms `hasMany(Business::class)` — a Workspace can own
multiple Businesses, so a bare "first Business" lookup would be
ambiguous; `is_primary` is the deliberate disambiguator already present
in the schema.

**Confirming a non-null `primaryBusiness()` exists for a Customer proves
coverage, not correct ownership attribution.** A Customer can have a
non-null `primaryBusiness` and still have that Business be the *wrong*
one for a specific Conversation, if their Workspace owns more than one
Business. Direct audit of the actual ownership model:

- `chat_boxes` (migration `2021_03_31_125855_...`): columns `id`, `uid`
  (a per-thread UUID), `user_id`, `from`, `to`, `notification`,
  timestamps. **No `business_id` column, no `workspace_id` column, and
  no foreign key to either table, at any point in the schema's history**
  (confirmed by reading every subsequent `chat_boxes` migration; only
  `sending_server_id` was ever added). `chat_box_messages` (migration
  `2021_03_31_130224_...`): `id`, `box_id`, `message`, `media_url`,
  `sms_type`, `send_by`, `sending_server_id`, timestamps — likewise no
  Business/Workspace concept anywhere.
- `quickSend()` and every method reading `$user`/`$user->customer`
  throughout `ChatBoxController` operate purely on the authenticated
  `User`/`Customer` — never on a `Business` or `Workspace` model, never
  on a route-bound or session-bound Business identifier.
- **No current/active-Business resolver, middleware, or session concept
  exists anywhere in the customer-facing HTTP layer.** A targeted search
  for a "current Business" selector found matches only inside RFC-004/
  RFC-005's own internal library code and test runners — never in any
  customer-facing controller. A Customer today has no way, anywhere in
  the product, to tell the system "this action is on behalf of Business
  B, not Business A."
- **Multi-Business-per-Workspace is a real, live, reachable capability
  today, not a hypothetical:** `WorkspaceController::storeBusiness()`
  (`app/Http/Controllers/Customer/Workspace/WorkspaceController.php:241`)
  is a genuine, routed action that creates an *additional* Business
  under an existing Workspace. `is_primary` exists specifically to
  disambiguate exactly this case — if every Workspace could only ever
  have one Business, the column would be pointless. It is not
  pointless; multi-Business Workspaces are an intended, supported, used
  product shape (this is precisely why RFC-004/M4's additional-slot
  billing exists at all).

**Direct answer:** repository reality does *not* prove that every
current ChatBox Conversation is intentionally owned by the primary
Business — ChatBox Conversations have no Business ownership concept at
all in their own schema, and the wider product has no mechanism for a
User to direct a Conversation at a specific non-primary Business.
Attributing every send to `primaryBusiness()` unconditionally would, for
a Workspace with more than one Business, be **guessing**, not reading
existing evidence.

**Resolved within bounded M5 scope by narrowing, not by guessing:** a
qualifying M5 metered send additionally requires the sending Customer's
Workspace to own **exactly one** Business
(`$business->workspace->businesses()->count() === 1`, §5.1 item 4). When
a Workspace owns more than one Business, `primaryBusiness()` is
definitionally unambiguous only by construction (there is exactly one
Business to resolve to, not a choice among several) — for such a
Workspace, M5 metering never engages at all, and every Conversation send
for that Workspace remains on 100% legacy `sms_unit` behavior. This does
not charge Business A for a Conversation belonging to Business B,
because it never attempts to attribute a Conversation to any Business in
the ambiguous case — it declines to meter it, deferring true
multi-Business Conversation attribution to a later milestone that would
need to build the missing selection mechanism (an explicit product
feature, out of scope here). This requires no new schema — the guard is
a single cardinality check against an already-existing relation.

**A null `primaryBusiness()` is a separate, fail-closed case, not the
same as the multi-Business case:** it is an unreachable state once a
data-integrity precondition holds (every real ChatBox-sending Customer
has a non-null `primaryBusiness`) — the implementation's first step,
before any code change, must directly verify this precondition against
`ultimatesms_testing` (or a read-only staging/production replica): the
count of Customers with at least one `chat_boxes` row whose
`customer->primaryBusiness` is null. If that count is nonzero, the
implementation must stop and report rather than invent fallback
cross-Business selection semantics (§14). If the count is confirmed
zero (the expected outcome, given RFC-003/004's own Business-account-
boundary backfill work), a null `primaryBusiness` encountered anyway in
production is a hard-deny (fail-closed, log-and-block, never a silent
legacy fallback) — this is distinct from the multi-Business case, which
falls through to legacy rather than denying.

### 3.6 Rate dimension — a singular, scalar pilot tuple, not a country allowlist

Re-reading `quickSend()` lines 95-260 in full confirms plain-SMS retail
price varies along three independent dimensions, not one:

- **Destination country:** `$coverage = CustomerBasedPricingPlan::where('user_id', $user->id)->where('country_id', $country->id)->...->first(...)`,
  falling back to `PlansCoverageCountries::where('plan_id', $activeSubscriptionPlanId)->where('country_id', $country->id)->...->first(...)`
  (lines 99-127) — pricing is looked up per destination `country_id`.
- **Customer's own negotiated plan:** a specific Customer's own
  `CustomerBasedPricingPlan` row, where one exists, **overrides** the
  plan-level `PlansCoverageCountries` default entirely — two different
  Customers in the same country can have two different negotiated
  `plain_sms` prices (`$priceOption = json_decode($coverage['options'], true)`,
  line 207; `plain_sms`/`voice_sms`/`mms_sms`/etc. are keys inside this
  per-(Customer-or-plan, country) JSON blob, not fixed columns).
- **`SendingServer` identity:** `if (config('app.gateway_wise_billing')) { $priceOption = json_decode(SendingServerBasedPricingPlans::where('sending_server', $sending_server->id)->where('country_id', $country->id)->first()->options, true); }`
  (lines 209-219) — when this config flag is enabled, price is
  overridden *again*, keyed by the specific `sending_server` row
  actually selected, independently of the Customer/plan lookup above.
  **This table is keyed by the specific `sending_server` row id, not by
  gateway type** — two different `SendingServer` rows can both be
  configured `settings = TYPE_TWILIO` while carrying two different
  `SendingServerBasedPricingPlans` rows (and therefore two different
  `plain_sms` prices) for the identical country.
  `ChatBoxController::sent()` additionally permits the customer to
  select a specific `sending_server` explicitly (`$request->sending_server`,
  confirmed in the controller's existing code), so this is a live,
  reachable variance, not a hypothetical one.

No internal/provider-cost figure is tracked anywhere in this legacy
pricing surface at all — only the customer-facing retail price is read
by `quickSend()` — and no `currency_id` column exists on any of
`plans_coverage_countries`, `customer_based_pricing_plans`, or
`sending_server_based_pricing_plans`, unlike `business_usage_rates.currency_id`'s
mandatory explicit FK.

**`business_usage_rates` supports exactly one `retail_rate_micro`/
`provider_cost_micro`/`unit_label` triple per `feature_key` version, with
one explicit `currency_id`.** The legacy pricing surface is at minimum
two-dimensional (country × plan) and conditionally three-dimensional
(× sending server), with no independently-tracked provider cost and no
explicit currency at all. No single `business_usage_rates` row can
truthfully represent this in general. Flattening it into one number (an
average, a single country's price, a single plan's price chosen
silently) would misrepresent real economics.

**Resolution — a singular, explicitly human-authorized pilot tuple,
scoped to pin every variable dimension to one exact, nameable, real
context.** Two alternatives were considered and rejected: (a) the RFC-005
retail rate intentionally *replacing* legacy per-customer pricing for
the scoped send — not adopted, since this contract carries no merged RFC
authority to override a Customer's own negotiated legacy price with a
platform-wide figure, and inventing that authority here would itself be
an unauthorized product decision; (b) a real per-dimension rate-schema
expansion — not adopted, since it is a genuine schema change RFC-005
§36/§39 never authorized for this milestone. The adopted design pins
every dimension to one explicit, scalar, human-supplied value:

```php
// config/usage_billing.php
'conversations_metering' => [
    'pilot_business_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_BUSINESS_ID'),
    'pilot_country_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_COUNTRY_ID'),
    'pilot_sending_server_id' => env('USAGE_BILLING_CONVERSATIONS_PILOT_SENDING_SERVER_ID'),
],
```

Each is a **single nullable scalar**, `null` by default — fail-closed, no
default invented, matching this file's own existing convention
(`usage_billing.webhook_event.retention_days`). A qualifying send
requires the resolved Business id, destination `country_id`, and
resolved `SendingServer` id to **exactly equal all three configured
values simultaneously** — one tuple, not a membership test against any
list. Pinning the Business also pins the Customer (hence the negotiated-
plan dimension, since exactly one `CustomerBasedPricingPlan` context
corresponds to one named pilot Business), and pinning the exact
`SendingServer` id (not merely its gateway type) pins the price
`SendingServerBasedPricingPlans` dimension precisely.

**The resolved `SendingServer` is additionally, separately, asserted to
be `TYPE_TWILIO`/`TYPE_TWILIOCOPILOT` (`$sendingServer->settings`) — this
is a code-level capability assertion, not a fourth pilot dimension.** It
exists because §3.9's outcome-classification mechanism is proven correct
only for this one gateway family; if a human ever pointed
`pilot_sending_server_id` at a non-Twilio row, this assertion fails
closed rather than silently applying an unverified classification
mechanism to a different gateway's response shape.

**M5 supports exactly one pilot tuple, deliberately — the smaller M5
design is one tuple, not a general rollout.** No mechanism for multiple
tuples is built. A future milestone wanting a second pilot (or a general
rollout) would need either to prove, out-of-band, that a second tuple's
real economics are identical to the first (unlikely, since price is
precisely the thing that varies per tuple) or to build the real
per-dimension schema extension named above as out of scope.

**Stated exactly:** once the pilot tuple is activated (`is_metered =
true` **and** all three config values populated **and** a real rate set,
§9), the RFC-005 `business_usage_rates` active rate is the sole,
authoritative charge for a qualifying send matching that exact tuple.
**Legacy `sms_unit` is not also deducted for that send** — exactly one
authoritative charging path, always.

`provider_cost_micro` remains entirely human-supplied and is never
inferred from the legacy `options` blob, which is retail-only and proves
nothing about real provider cost for any tuple (§9.2).

### 3.7 `ExpireStaleUsageReservations` — unmodified, and now load-bearing

`app/Jobs/Usage/ExpireStaleUsageReservations.php` calls
`UsageWalletManager::expireStaleReservations()` unconditionally across
every feature — no per-`feature_key` carve-out exists, and none is
proposed. This job is not modified by M5. Its existing, already-tested
30-minute (`RESERVATION_TTL_MINUTES`) blanket release behavior becomes
the deliberate backstop for §6's ambiguous-outcome handling — not a gap
M5 works around, but a bound M5 explicitly relies on.

### 3.8 `reserve()`'s atomic provider-call claim — concurrency-safe, database-enforced

**A timestamp-based approach was considered and is explicitly rejected:**
capturing a "before I called `reserve()`" timestamp and comparing it to
the returned row's `reserved_at` to infer "did I create this row" is
**not concurrency-safe.** Counterexample: two concurrent requests A and
B, both carrying the identical business-namespaced token, both capture a
timestamp before either has written anything; if A's insert commits
first, B's subsequent lookup can return that same row with a
`reserved_at` that is `>=` **both** A's and B's own captured timestamps,
since nothing in that design serializes "capture timestamp" against
"another process's insert." **Additionally,
`business_usage_reservations.reserved_at` is declared
`$table->timestamp('reserved_at')` with no fractional-second
precision** (confirmed by direct read of the migration) — even absent
the ordering counterexample, sub-second concurrent requests could
produce identical stored timestamps, making strict inequality comparison
unreliable on its own terms. **No timestamp comparison of any kind is
part of this contract's design.**

**Correct mechanism: an atomic database fact.**
`business_usage_reservations.idempotency_key` already carries a `unique`
constraint. The established precedent for turning a unique-constraint
race into an exactly-once claim already exists in this codebase —
`UsageBillingCheckoutManager::finalizeAddonPurchaseIfPending()` catches
`Illuminate\Database\UniqueConstraintViolationException` around a racing
`creditFromFunding()` ledger insert and treats the caught exception as
proof a concurrent/earlier caller already won. `reserve()` is widened to
apply the identical idiom to its own reservation insert:

- Inside `reserve()`'s existing `DB::transaction()` closure, the
  reservation `create()` call is left otherwise unchanged, but a
  `UniqueConstraintViolationException` thrown by that specific insert is
  now allowed to propagate out of the closure. `DB::transaction()`'s own
  existing rollback behavior (unchanged Laravel semantics) then undoes
  *everything* this losing invocation did inside that transaction —
  including its own wallet-balance debit — before the exception reaches
  `reserve()`'s own code.
- `reserve()` catches `UniqueConstraintViolationException` around the
  `DB::transaction(...)` call itself (not inside the closure — the
  closure must be allowed to fully roll back first):
  ```php
  try {
      $result = DB::transaction(function () use (...) { /* existing body, unchanged shape */ });
  } catch (UniqueConstraintViolationException $exception) {
      $existing = $this->reservationRepository->findByIdempotencyKey($idempotencyKey);

      if ($existing === null) {
          throw $exception; // not this reservation's own key collision — do not mask it
      }

      return new ReservationResult(true, $existing->id, null, false);
  }
  ```
  **The catch performs a verification step before declaring idempotent
  success:** it re-fetches by the exact expected `idempotency_key`, and
  only treats the caught exception as "someone else already reserved
  this" if that re-fetch actually finds a matching row. If it does not —
  meaning the violation was for some other unique constraint entirely,
  not this reservation's own key — the original exception is **re-
  thrown**, never swallowed. A transaction can, in principle, touch more
  than one unique-constrained insert; blindly treating *any*
  unique-constraint failure as "idempotent success" would mask a
  genuinely unrelated bug. Guaranteed correctness of the found-row case:
  MySQL's own unique-index locking behavior ensures a losing insert can
  only ever fail with a duplicate-key error once the winning row is
  already committed and visible, whether the loser's insert blocked on
  the index lock first or failed immediately — so a fresh
  `findByIdempotencyKey()` lookup, run after the losing transaction has
  fully rolled back, is guaranteed to find the winning row if the
  violation really was this reservation's own key.
- **`ReservationResult` (`app/Library/Usage/ReservationResult.php`)
  gains one new field, a fourth, defaulted constructor parameter —
  confirmed backward compatible by direct search: every existing
  `new ReservationResult(...)` call site (five total, all inside
  `UsageWalletManager.php`, none elsewhere in the repository) uses
  purely positional arguments and none supplies a fourth value today, so
  appending one defaulted parameter changes no existing call site's
  behavior:**
  ```php
  final readonly class ReservationResult
  {
      public function __construct(
          public bool $granted,
          public ?int $reservationId,
          public ?string $denialReason,
          public bool $createdByThisInvocation = false,
      ) {
      }
  }
  ```
  **The default is `false`, not `true`** — a `true` default paired with a
  denial (`granted: false`) would be misleading on its own terms, even
  though no caller inspects the field in that branch today. Locked
  semantics, exactly:
  - **Authorized, newly-created reservation** (the sole winner of the
    unique-key insert): `createdByThisInvocation = true`, passed
    explicitly at `reserve()`'s successful-creation return site.
  - **Authorized, pre-existing/race-lost reservation** (the fast
    pre-check hit, or the catch-and-refetch branch above):
    `createdByThisInvocation = false`, passed explicitly for clarity
    even though it matches the default.
  - **Denied/no reservation** (`granted: false`, any of the three
    existing wallet-capacity denial sites): `createdByThisInvocation = false`
    by the corrected default — no call-site change needed at those three
    sites, since none of them pass a fourth argument.

**This satisfies every concurrency requirement:** only the invocation
that atomically wins the unique-key insert ever learns
`createdByThisInvocation === true`, and every other concurrent or later
invocation — whether it hit the fast pre-check or raced into and lost
the insert — learns `false`; a genuinely fresh `Pending` row is only ever
produced by exactly one invocation; two genuinely concurrent processes
supplying the identical business-namespaced token produce exactly one
reservation (the database's unique constraint admits only one), exactly
one `createdByThisInvocation === true` result, and therefore exactly one
provider invocation and exactly one accounting outcome — the loser
always takes the non-provider-call branch (§6 step 4), deterministically,
regardless of timing; no transaction remains open across the provider
call (the transaction fully closes, one way or the other, before
`reserve()` even returns); and the mechanism is the database's own
unique-index enforcement, never a timestamp.

### 3.9 Twilio/TwilioCopilot outcome classification — `SendCampaignSMS.php` authorized, opt-in, machine-readable

**Direct end-to-end re-read of `sendPlainSMS()`'s two Twilio/TwilioCopilot
case blocks (lines 457-520, confirmed the only two occurrences of
`SendingServer::TYPE_TWILIO`/`TYPE_TWILIOCOPILOT` inside this method's
own boundary) together with the method's shared post-processing (lines
13379-13424, confirmed the only such block) establishes the following
exactly:**

```php
// inside the Twilio/TwilioCopilot case blocks:
try {
    $client = new Client($sending_server->account_sid, $sending_server->auth_token);
    $get_response = $client->messages->create($phone, [...]);
    if ($get_response->status == 'queued' || $get_response->status == 'accepted') {
        $get_sms_status = 'Delivered|' . $get_response->sid;
        $customer_status = 'Delivered';
    } else {
        $get_sms_status = $get_response->status . '|' . $get_response->sid;
        $customer_status = ucfirst($get_response->status);
    }
} catch (ConfigurationException|TwilioException $e) {
    $get_sms_status = $e->getMessage();
    $customer_status = 'Rejected';
}

// ... later, shared across every gateway branch, before Reports::create():
$cost = substr_count($get_sms_status, 'Delivered') == 1 ? $data['cost'] : '0';
if (! isset($customer_status)) {
    $customer_status = $get_sms_status;
}
$customer_status = substr_count($customer_status, 'Delivered') == 1 ? 'Delivered' : 'Failed';
$reportsData = [
    ...
    'status' => $get_sms_status,
    'customer_status' => $customer_status,
    ...
];
$status = Reports::create($reportsData);
```

**The catch block does not rethrow — `quickSend()` never receives a PHP
exception from this path; it receives an ordinary return value.**
**Critically, the shared post-processing's final line unconditionally
collapses `$customer_status` to exactly `'Delivered'` or `'Failed'` — the
caught-exception path's `'Rejected'` value is overwritten to `'Failed'`
here and never reaches the returned `Reports` row as `'Rejected'` at
all.** `quickSend()` itself reads `$data->status` (mapped from the raw,
unmangled `$get_sms_status`) — for the caught-exception path, that is
`$e->getMessage()`, an arbitrary, provider/SDK-dependent string with no
proven consistent shape across every possible exception. **Neither
`$data->status` nor `$data->customer_status` can safely distinguish
"Twilio's API genuinely rejected this" from "the local code caught an
exception of uncertain origin" using any string-pattern heuristic** — no
existing field, and no reliably-shaped substring/delimiter check,
provides this distinction; the exact provider-reference format and the
full space of exception-message text are not proven to support one.

**Conclusion: no currently-returned or currently-persisted field
provides the required distinction. The smallest bounded production
change is authorized: `app/Models/SendCampaignSMS.php` is authorized for
modification, M5-only, opt-in, narrowly scoped to the two
Twilio/TwilioCopilot case blocks plus one shared attachment point:**

- **Input flag, locked name:** `$preparedData['m5_conversations_usage_tracking'] = true;`
  — set only by `quickSend()`'s own new M5-guarded branch (§6 step 5),
  for a qualifying send, and by no other caller anywhere in the
  repository.
- **Inside the two Twilio/TwilioCopilot case blocks only:** when
  `($data['m5_conversations_usage_tracking'] ?? false) === true`, an
  additional local variable, `$m5Outcome`, is set alongside the
  existing, **completely unchanged** `$get_sms_status`/`$customer_status`
  assignments:
  - Accepted (`queued`/`accepted`, unchanged branch): `$m5Outcome = 'accepted';`.
  - Non-throwing, non-accepted (unchanged branch): `$m5Outcome = 'definitive_rejection';`.
  - Caught exception (unchanged catch body, otherwise untouched):
    `$m5Outcome = 'ambiguous_exception';`.
  When the flag is absent or `false`, **none of this new code executes at
  all** — `$m5Outcome` is simply never set, exactly as today.
- **At the method's existing return point** (immediately after
  `$status = Reports::create($reportsData);`, before `return $status;`):
  `if (isset($m5Outcome)) { $status->m5_outcome = $m5Outcome; }`. **This
  is a non-persisted, dynamic Eloquent attribute** — it is not a key of
  `$reportsData`, so `Reports::create()` neither receives nor persists
  it; setting a property on an already-created model instance after the
  fact has no database effect whatsoever. `quickSend()` reads
  `$data->m5_outcome` only inside its own new M5-guarded branch; the
  property is simply absent for every other caller, channel, or gateway.

**Proof this satisfies every required constraint:**

- **No schema change:** `m5_outcome` is never written to `$reportsData`
  and never reaches the `reports` table — confirmed by the attachment
  point being strictly *after* `Reports::create()` returns.
- **Legacy `Reports` persistence is completely unchanged:** every key in
  `$reportsData` is built by code this contract does not touch; the new
  lines add a variable and a post-creation property assignment, nothing
  more.
- **Every non-M5 caller sees exactly its current behavior:** the new
  code is gated entirely behind
  `$data['m5_conversations_usage_tracking'] ?? false`, which is `false`
  for bulk Quick Send, API sends, contact-triggered sends, DLR replies,
  and every other existing caller of `sendPlainSMS()` — none of them is
  modified to pass this key, so the added branches are dead code for
  them.
- **Only the M5 qualifying path consumes the marker:** `quickSend()`'s
  new branch is the only reader of `$data->m5_outcome` anywhere in the
  repository.
- **The durable provider reference (`$get_response->sid`) is preserved
  exactly as before** — the new code adds `$m5Outcome`; it does not alter
  `$get_sms_status`'s own existing `'Delivered|'.$sid` /
  `$status.'|'.$sid` construction, so the `sid` remains embedded in
  `$data->status` for `accepted`/`definitive_rejection` outcomes
  precisely as it is today.

**Locked outcome semantics — actions on each marker value, exact:**

| `m5_outcome` | Action |
|---|---|
| `accepted` | `commit($reservation->reservationId, (string) $sms_count)` |
| `definitive_rejection` | `release($reservation->reservationId)`; client regenerates its token |
| `ambiguous_exception` | leave the reservation `Pending`; do **not** call `release()`; do **not** retry the provider with the same token; client retains its token; eligible for §6.2's manual resolver or the existing 30-minute TTL backstop (§3.7) |
| *(marker unexpectedly absent)* | fail safe — treat identically to `ambiguous_exception`; can only be reached by a programming defect elsewhere in the same guarded branch, since the flag is always set immediately before the provider call; asserted against directly in tests (§13) |

**Vendor-SDK limitation, stated exactly, and why it does not block this
mechanism:** `vendor/twilio` is not present in the worktree this contract
was drafted in, so this audit does not verify, and does not depend on,
the precise internal exception hierarchy Twilio's own SDK uses to
distinguish a definitive API rejection from a network/timeout failure.
The mechanism above does not need that distinction — it treats the
entire caught-exception surface as one `ambiguous_exception` bucket,
uniformly, which is the conservative, honest position regardless of
what the vendor SDK's internals turn out to be. If a future
implementation phase, working in an environment where the vendor package
is actually installed, can verify a safe finer split, that would be a
smaller-scope refinement to propose separately — not something this
contract assumes or requires.

### 3.10 Testability of the real send path — a genuine pre-existing gap

- No feature test exists today for `ChatBoxController`, `quickSend()`, or
  any `SendCampaignSMS::send*()` method (confirmed: no
  `tests/Feature/**/*ChatBox*` file exists on `origin/main`).
- `SendCampaignSMS::sendPlainSMS()` branches per `SendingServer` gateway
  configuration into `Http::` facade calls, raw `curl_init()` calls, and
  the Twilio PHP SDK's own `Client` — there is no single injectable
  gateway interface, unlike RFC-005 M3/M4's own
  `PaymentProviderGateway`/`FakePaymentProviderGateway` seam.
  `Http::fake()` alone cannot deterministically intercept every branch.
- **This is a pre-existing legacy-code limitation, not something this
  contract proposes to refactor** — rewriting `SendCampaignSMS` into an
  injectable-gateway shape would be a large, out-of-scope redesign,
  forbidden by "must not introduce a new billing architecture."
- **Resolution for M5's own tests:** stub at the `Campaigns::sendPlainSMS()`
  method boundary directly (a Mockery partial mock or a test-only
  subclass bound into the container for the duration of the test),
  rather than attempting to fake the transport layer underneath it. This
  is a standard PHPUnit/Mockery technique requiring no production-code
  seam change beyond §3.9's own narrow, already-authorized addition.

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
  implementation PR, and it does not authorize a human to skip either
  unresolved decision recorded in §9.2.

---

## 5. Exact M5 scope

### 5.1 What qualifies as an M5-metered plain SMS send

A `quickSend()` invocation qualifies for RFC-005 wallet metering if and
only if **all** of the following hold:

1. `$input['sms_type']` resolves to the `plain` branch (`plain` or
   `unicode`, per the existing `$db_sms_type` normalization already in
   `ChatBoxController::sent()` and hard-coded in `::reply()` — `unicode`
   is not a separate rate today, only a separate character encoding, so
   it is included in M5's scope, not deferred).
2. The call originated from `ChatBoxController::sent()` or `::reply()` —
   mechanically distinguished by the `conversation_context` input key
   (§7), set only at those two call sites.
3. `platform_feature_usage_classifications.conversations.is_metered` is
   `true` at the moment of the call (i.e. a human has already run the
   §9.1 activation command in that environment).
4. The sending Customer's Workspace owns exactly one Business
   (`$business->workspace->businesses()->count() === 1`, §3.5), **and**
   that Business's id equals `conversations_metering.pilot_business_id`
   exactly (§3.6) — both conditions, not either.
5. The resolved destination `country_id` equals
   `conversations_metering.pilot_country_id` exactly (§3.6).
6. The resolved `SendingServer`'s id equals
   `conversations_metering.pilot_sending_server_id` exactly, **and**
   that server's `settings` is `TYPE_TWILIO`/`TYPE_TWILIOCOPILOT` (§3.6's
   capability assertion).

Any send failing any one of these six conditions takes the exact same
legacy `sms_unit` code path unconditionally, with zero behavior change.

### 5.2 What MMS/WhatsApp/Viber/OTP/Voice do during M5

Unconditionally unchanged: the existing pre-send `sms_unit` balance
check, the existing provider call, and the existing post-`Delivered`
`sms_unit` decrement — exactly as today, for every channel other than
plain/unicode, regardless of `Conversations.is_metered`'s value, forever
within M5's own scope. Extending wallet metering to any other channel is
explicitly a later-milestone decision (§5.4), never silently bundled
into M5.

### 5.3 How non-qualifying sends remain on existing behavior

The dispatch added to `quickSend()` (§7) is strictly additive and gated
on **all six** §5.1 conditions simultaneously. Any call missing even
one — wrong channel, wrong caller, multi-Business Workspace, non-metered
classification, or a Business/country/`SendingServer` outside the exact
pilot tuple — takes the exact same code path it takes today, with zero
new branches evaluated beyond the guard checks themselves, zero new
queries issued beyond those checks, and zero new columns written. This
is mechanically provable by the exact guard clause's own structure (§7)
and is directly regression-tested (§13).

### 5.4 How later milestones may extend metering without changing M5 history

`business_usage_rates` is itself immutable and versioned
(`unique(feature_key, version)`, no `updated_at`) — a future milestone
that wants to meter, say, a second channel, a second Business, or a
wider rollout would need either (a) a new `PlatformFeature` case
dedicated to a new scope (clean, no schema change, but a new
entitlement-surface decision), (b) building the missing cross-Business
selection mechanism for multi-Business Workspaces (an explicit product
feature, out of scope here), or (c) a schema widening to a genuine
per-dimension rate map (a real schema expansion, explicitly out of scope
for both M5 and this contract). This contract takes no position on which
future milestones should choose — it only confirms M5 itself requires
none of them, and that whichever a future milestone picks, M5's own
already-activated `conversations` rate/classification rows and its own
reservation/ledger history remain untouched and immutable, per the
existing schema's own append-only/immutable-rate design.

---

## 6. Reservation lifecycle — exact, locked order

For a qualifying send (§5.1), inside `EloquentCampaignRepository::quickSend()`,
strictly in this order, replacing (not adding alongside) the existing
`sms_unit` pre-check/decrement for this call only:

1. **Business resolution and ownership-scope guard.**
   `$business = $user->customer->primaryBusiness;` If null: per §3.5,
   this is an unreachable state once the precondition check passes; if
   it is ever hit in production despite that, fail closed — deny the
   send with the existing `'not_enough_balance'`-shaped error response,
   log a warning, do **not** fall back to legacy `sms_unit` deduction. If
   non-null but the Workspace owns more than one Business, or the
   Business does not exactly equal `pilot_business_id` (§5.1 item 4):
   **this send is not a qualifying send at all** — control falls through
   to the existing, unmodified legacy code path, exactly as if
   `conversation_context` had never been set.
2. **Destination and `SendingServer` pilot-tuple guards (§5.1 items
   5-6).** If the resolved `country_id` does not exactly equal
   `pilot_country_id`, or the resolved `SendingServer`'s id does not
   exactly equal `pilot_sending_server_id`, or that server's `settings`
   is not `TYPE_TWILIO`/`TYPE_TWILIOCOPILOT`: likewise not a qualifying
   send — falls through to legacy, unchanged.
3. **Entitlement/usage authorization.** Resolve the Business's
   `Workspace` (`$business->workspace`) and call the existing
   `EntitlementManager::decide($workspace, $business, PlatformFeature::Conversations->value, $actorUserId)`
   unchanged. If `!$decision->allowed`: return the existing error-shaped
   JSON response carrying `$decision->reason` (which will read
   `usage_unauthorized` for every wallet-capacity denial, per §3.3); no
   rate lookup, no reservation, no provider call occurs after this
   point.
4. **Wallet reserve and atomic claim check.**
   `$reservation = $walletManager->reserve($business, PlatformFeature::Conversations->value, $idempotencyKey, (string) $sms_count);`
   using the business-namespaced key (§6.1). A wallet-capacity denial
   (`wallet_suspended`/`outstanding_debt`/`insufficient_balance`) →
   return the existing error-shaped response carrying
   `$reservation->denialReason`; **no provider send occurs; no
   reservation row was created for this attempt.** Otherwise, inspect
   `$reservation->createdByThisInvocation` (§3.8):
   ```php
   if (! $reservation->createdByThisInvocation) {
       $row = app(BusinessUsageReservationRepository::class)->findById($reservation->reservationId);
       return match ($row->status) {
           UsageReservationStatus::Committed => /* existing "already sent" success response */,
           UsageReservationStatus::Released, UsageReservationStatus::Expired => /* existing "could not confirm this message was sent; compose and send again if you still want to" response; client must regenerate its token */,
           UsageReservationStatus::Pending => /* existing "still processing, please wait" response; client retains its token; log the structured warning named in step 7 below */,
       };
   }
   ```
   **Only when `$reservation->createdByThisInvocation === true`** does
   control proceed to step 5 — this is the exact, sole authority to call
   the provider, decided by the database's own unique-constraint
   enforcement (§3.8), never by any timestamp.
5. **Set the M5 outcome-tracking flag, then send.**
   `$preparedData['m5_conversations_usage_tracking'] = true;`
   (immediately before the call — the one line that activates §3.9's
   marker logic for this call only), then
   `$campaign->sendPlainSMS($preparedData)`.
6. **Outcome classification, driven by `$data->m5_outcome` (§3.9), never
   by any status-string heuristic:**
   - `$data->m5_outcome === 'accepted'` →
     `$walletManager->commit($reservation->reservationId, (string) $sms_count);`
     — `$finalQuantity` explicitly re-passed as the identical
     `$sms_count` value (§8), not defaulted to null, keeping the
     estimated-equals-final equality auditable in the call site itself.
   - `$data->m5_outcome === 'definitive_rejection'` →
     `$walletManager->release($reservation->reservationId);` — client
     regenerates its token.
   - `$data->m5_outcome === 'ambiguous_exception'` → leave the
     reservation `Pending`; do **not** call `release()`; log
     `Log::warning('m5_conversations_ambiguous_outcome', ['reservation_id' => $reservation->reservationId, 'business_id' => $business->id]);`;
     return the existing "still processing" response; client **retains**
     its token.
   - **Marker unexpectedly absent** (a defensive fallback that should be
     unreachable by construction, since step 5 always sets the flag
     immediately before the call): treat identically to
     `ambiguous_exception`.

**No provider send may occur after a denied usage decision:** steps 1-4
all return, or fall through to legacy, before step 5 is ever reached
whenever they deny or disqualify — a straight-line sequence, never a
try/catch that could allow a later step to run after an earlier denial.

**No DB transaction remains open across the provider call:** `reserve()`'s
own transaction (including its catch-and-refetch branch, §3.8) fully
closes before step 4's claim check is even evaluated — the atomic claim
itself depends on that transaction having already committed or rolled
back, so this property is mechanically guaranteed, not merely asserted.

**Concurrency requirement, stated exactly:** two genuinely concurrent
processes supplying the identical business-namespaced token produce
exactly one reservation, exactly one `createdByThisInvocation === true`
result, and therefore exactly one provider invocation and exactly one
accounting outcome — the loser always takes step 4's non-provider-call
branch, deterministically, regardless of timing (§3.8, §13).

**Residual, bounded, explicitly-accepted risk:** an ambiguous outcome
(step 4's stale-`Pending` branch, or step 6's `ambiguous_exception`
branch) left unresolved is released by the pre-existing, unmodified
`ExpireStaleUsageReservations` job after its existing 30-minute TTL
(§3.7), defaulting to "not charged." This is a real, bounded, single-
send-value risk (a genuinely-delivered message whose outcome is never
confirmed within 30 minutes goes unbilled), not a double-charge risk and
not a data-integrity risk, and is symmetric with the pre-existing legacy
system's own equivalent gap (§3.4: the legacy `sms_unit` decrement also
only happens after a confirmed `Delivered` response, so a crash before
that point already left the legacy system unable to bill a possibly-real
send). M5 does not introduce a new class of risk here; it bounds an
already-existing one to a known 30-minute window and adds a manual
escape hatch (§6.2) the legacy system never had.

### 6.1 Idempotency key — business-namespaced, client-sourced, precisely bounded

**Derivation:** `hash('sha256', 'conversations_plain_sms:'.$business->id.':'.$idempotencyToken)`
— the resolved Business id is folded in **before** hashing, so
`reserve()`'s global (non-business-scoped) `findByIdempotencyKey()`
lookup can never resolve another Business's row from a client-supplied
raw token, even in the astronomical-collision case, since the composed
key is unique per `(business, token)` pair by construction, not by trust
in the client alone.

**No pre-existing durable "send attempt" identifier exists in this
legacy path to derive a key from:** unlike RFC-005 M3/M4's
`business_funding_attempts` row (created *before* any provider call,
carrying its own `local_idempotency_key`), `quickSend()` never persists
anything before calling the provider — the only persisted records
(`Reports::create()`, `ChatBoxMessage::create()`) are written *after*
the provider responds, seeded from the response itself. `chat_boxes.uid`
is a per-thread UUID, not per-send, and is not even guaranteed to exist
before a send completes. **The idempotency token is therefore
client-sourced**, using a durable persistence point that already exists
at `reserve()`'s own `business_usage_reservations.idempotency_key`
column, written inside `reserve()`'s own transaction, strictly before
the provider call.

**Exact mechanism, per entry point:**

- **`ChatBoxController::sent()` (traditional full-page form POST):** a
  static per-page-load hidden field alone is insufficient — `sent()`'s
  own error path is a redirect back to a freshly-rendered `new()` page,
  and a fresh page load always renders a fresh hidden value if nothing
  persists it, breaking the retry-linkage the state machine depends on.
  **The in-flight token is held server-side in the session**
  (`session('m5_conversation_pending_token')`), not only in the
  rendered HTML:
  - `ChatBoxController::new()` (the GET action rendering
    `resources/views/customer/ChatBox/new.blade.php`): if the session
    key is set, pass its value to the view for the hidden field;
    otherwise mint a fresh `(string) \Illuminate\Support\Str::uuid()`,
    store it in the session, and pass that. The view renders
    `<input type="hidden" name="idempotency_token" value="{{ $pendingIdempotencyToken }}">`.
  - `ChatBoxController::sent()` (the POST action): on the success or
    terminal (`Released`/`Expired`-hit) response paths, clear the
    session key (`session()->forget('m5_conversation_pending_token')`)
    so the next page load mints fresh; on the ambiguous response paths
    (§6 step 4's stale-`Pending` branch, or step 6's
    `ambiguous_exception` branch), leave the session key untouched, so
    the next `new()` page load reuses the identical token and the retry
    lands on the same reservation.
- **`ChatBoxController::reply()` (AJAX, `resources/views/customer/ChatBox/index.blade.php`'s
  `enter_chat()` handler):** the inline script gains a per-compose token
  held in a JS variable scoped to the open conversation, generated
  lazily the first time `enter_chat()` runs for a given not-yet-
  successfully-sent message, included in the `FormData`
  (`formData.append("idempotency_token", token)`), and **only cleared
  (a fresh token minted for the next message) inside the existing
  `success` branch** (where the composed textbox itself is also
  cleared) — **never** inside the existing `error` branch, so a
  user-initiated retry after a failure/timeout resends the identical
  token together with the identical message text, exactly mirroring the
  existing UI's own already-designed retry affordance. The existing
  `enter_chat()` handler already disables the Send button before the
  AJAX call and re-enables it on both `success` and `error`; on `error`
  the composed message text is **not** cleared — this is the real,
  designed, expected recovery path after a timeout/5xx/network failure,
  and it is the retry this mechanism protects.

**Server-side, both entry points:** read `$request->input('idempotency_token')`
(validated `required|uuid` — a rule added to `SentRequest` for `sent()`;
validated inline for `reply()`, which has no dedicated `FormRequest`
class today and gains none). See §7 for the exact propagation code at
each entry point.

**Proof — two legitimate distinct sends can never collide:** each
distinct compose action (a `success`-cleared JS token for `reply()`; a
session-cleared, freshly-minted token for `sent()`) is collision-
resistant by construction, and the business-namespace prefix guarantees
no cross-Business or cross-feature collision even in the residual case.
Two different real messages therefore always produce two different
`reserve()` idempotency keys, hence two independent reservations, hence
two independent charges — correct, since two real sends are two real
costs.

**Proof — a crash/retry of the same logical send reuses the same key:**
for `reply()`, per the `error`-branch-does-not-clear-the-text-or-token
behavior above — the second `enter_chat()` invocation submits the
identical token. For `sent()`, per the session-persisted token surviving
the full-page-reload redirect cycle. In both cases, `reserve()`'s own
pre-existing `findByIdempotencyKey()` dedup and its new atomic-claim
widening (§3.8), together with `commit()`/`release()`'s own existing
idempotency on a reservation's terminal state, guarantee the retry can
never double-charge.

**Honest, bounded residual limitation:** a literal same-tab, same-session
double-click landing two overlapping in-flight requests before
`enter_chat()`'s existing button-disable takes visual effect, or a user
opening two browser tabs and composing the same message independently,
remains outside this mechanism's scope — each produces its own token
(each tab holds independent JS state; for `sent()`, only if the user
reloads `new.blade.php` between the two submissions). This is not a
"retry of the same logical send" in the sense this contract addresses —
it is two distinct user actions that happen to carry identical text —
and is explicitly out of scope, exactly as the equivalent case is for
M3/M4's own client-initiated payment retries. A same-tab, same-load
double-click on `reply()`'s Send button specifically remains safe today,
independent of this contract, via the existing button-disable mechanism
(`$(".send").attr("disabled", true)`), which this contract does not
need to touch to remain true.

### 6.2 Manual ambiguous-reservation resolution — bounded, cannot become a generic mutation tool

`app/Console/Commands/ResolveAmbiguousUsageReservation.php`:
`usage:resolve-reservation {reservation-id} {--outcome=} {--actor-user-id=} {--reason=}`,
`--outcome` restricted to `sent`/`not-sent`. **Before calling `commit()`
or `release()`, the command must verify, in order, and perform zero
mutation if any check fails:**

1. The reservation exists (`findById()` returns non-null).
2. Its `status` is exactly `Pending` — never `Committed` (already
   resolved, nothing to do) and never `Released`/`Expired` (already
   terminal, nothing to do).
3. Its `feature_key` is exactly `PlatformFeature::Conversations->value`
   — this command is a `Conversations`-pilot-specific tool, not a
   general reservation-mutation command; it must refuse any other
   feature's reservation outright.
4. The supplied `--actor-user-id` resolves to a genuine platform
   administrator — the same `is_admin` check as §9.1, reproduced
   directly, not calling or modifying `EntitlementManager`.
5. `--reason` is supplied and non-empty.
6. The reservation's own persisted `business_id` equals the
   *currently-configured* `conversations_metering.pilot_business_id`
   (§3.6) — provable directly from persisted data plus current config,
   without needing country/`SendingServer` columns on the reservation
   row itself, since `quickSend()`'s own guard (§5.1) is the *only* code
   path that ever creates a `conversations`-feature_key reservation at
   all — a row satisfying checks 3 and 6 together is mechanically
   proven to have originated from the M5 pilot path.

Any failure at any of the six checks aborts with a clear message and
writes nothing. Only when all six pass does the command call
`commit($reservationId)` (for `--outcome=sent`, using the reservation's
own already-stored `estimated_quantity` — no `$finalQuantity` override,
per §8's equality proof) or `release($reservationId)` (for
`--outcome=not-sent`). **Zero new `UsageWalletManager` code** — both
methods are already public and already safe/idempotent on exactly the
states this tool targets. This is the operator's tool for resolving §6
step 4/6's ambiguous cases within the 30-minute TTL window, using
out-of-band evidence (e.g. the target gateway's own dashboard/API, using
the durable reference captured in the logged warning where one exists,
§3.9).

---

## 7. `quickSend()` discriminator — the exact, minimal, additive change

`ChatBoxController::sent()` (immediately before its existing call to
`$this->campaigns->quickSend($campaign, $input)`) and
`ChatBoxController::reply()` (immediately before its existing call) each
gain exactly one new array key:

```php
$input['conversation_context'] = true;
```

`EloquentCampaignRepository::quickSend()` gains, immediately after the
existing `$sms_type`/`$db_sms_type` resolution and before the existing
`switch ($sms_type)` dispatch, a guard evaluating all six §5.1
conditions in sequence.

Every one of the five other `quickSend()` call sites (§3.4 items 2-5)
never sets `conversation_context` — the guard is `false` for all of
them, unconditionally, and the existing code executes completely
unmodified in that case. This is the exact mechanism §5.3 refers to.

**Token propagation at each entry point, exact, mechanically complete:**

- `sent()`'s existing `$input = $request->except('_token');` **already,
  automatically, includes `idempotency_token`** once the view submits it
  as a form field — no additional line is needed in `sent()` itself for
  token propagation (only `conversation_context`, plus the session
  read/clear logic in `new()`/`sent()` per §6.1).
- `reply()`'s `$input` array is **manually constructed key-by-key**
  (`['sender_id' => ..., 'originator' => ..., 'sms_type' => ..., 'message' => ..., 'exist_c_code' => ..., 'user' => ...]`)
  and does **not** automatically forward arbitrary request fields.
  `reply()` therefore requires an explicit second line beyond
  `conversation_context`:
  ```php
  $input['idempotency_token'] = $request->input('idempotency_token');
  ```
  plus inline validation (no dedicated `FormRequest` exists for
  `reply()` today, and none is added):
  ```php
  if (! $request->filled('idempotency_token') || ! Str::isUuid($request->input('idempotency_token'))) {
      return response()->json(['status' => 'error', 'message' => __('locale.exceptions.something_went_wrong')], 422);
  }
  ```

Both entry points are mechanically complete for token propagation and
validation.

---

## 8. Quantity — no new algorithm

`$sms_count` from the existing `SMSCounter::count($message, null)` call
(§3.4) is the sole source of quantity, for both `estimatedQuantity`
(passed to `reserve()`) and `finalQuantity` (passed to `commit()`).
**They are identical for every M5-scoped send:** segment count is a pure
function of the message text, computed once, before the provider call,
and nothing about the provider's response can change it (no repository
evidence anywhere in `sendPlainSMS()` or its `Reports` persistence
recomputes or overrides segment count from a provider-returned value).
`commit()`'s overage/unused-release sub-logic (§3.2) therefore never
activates for an M5-scoped send — it exists in `UsageWalletManager` for
other, non-M5 reasons (period rollover interactions, future milestones
with genuinely uncertain post-hoc quantities) and is exercised by its own
existing tests, not by M5's.

---

## 9. Pricing activation — mechanism authorized, numbers explicitly not

### 9.1 The command and actor-authority mechanism

**§3.2 already confirms `setActiveRate()` and `activateMetering()`
exist and require no new wallet-layer code.** What is missing is a
human-operable way to call them, with an actor-authority check neither
method performs internally. M5 authorizes building exactly one new,
narrow, human-run mechanism:

- A new Artisan console command,
  `app/Console/Commands/ActivateUsageFeatureRate.php`, signature:
  `usage:activate-rate {feature} {retail-rate-micro} {provider-cost-micro} {unit-label} {currency-code} {--actor-user-id=} {--reason=}`,
  requiring every value as an explicit argument (no defaults for any
  monetary or unit value), validating `feature` against
  `PlatformFeatureRegistry::isKnown()`.
- **`--actor-user-id=<id>` is required** (the command fails immediately,
  before touching `UsageWalletManager`, if it is omitted or non-numeric)
  — there is no "authenticated CLI context" fallback; a console command
  has no HTTP session to authenticate from, and inventing an implicit
  actor would itself be an unstated authority resolution.
- **Validated through the existing platform-administrator authority
  seam, exactly:** `EntitlementManager::assertPlatformAdministrator(int $actorUserId)`
  (`app/Library/Entitlement/EntitlementManager.php:1244`) is a private
  method and is not modified or exposed to reach it — instead, the
  command performs the **identical** check it already performs
  internally: `(bool) User::query()->whereKey($actorUserId)->value('is_admin')`.
  This is not an invented parallel authority system; it is a direct,
  literal reuse of the exact same `users.is_admin` boolean column
  `EntitlementManager` itself already reads for the identical purpose.
  If `is_admin` is not true for the supplied id, the command aborts
  before calling `setActiveRate()`/`activateMetering()` and writes
  nothing.
- The command then prompts for confirmation, and — only on explicit
  confirmation — calls `setActiveRate()` then `activateMetering()` (in
  that order, matching the existing mandatory sequencing
  `activateMetering()` itself already enforces, §3.2).
- No auto-discovery registration change (`app/Console/Kernel.php`
  already auto-loads `app/Console/Commands/`).
- **This contract does not authorize actually running this command
  against any real environment, and does not supply any numeric
  value.** Doing so remains a separate, explicit, human action after the
  M5 implementation PR merges — never seeded via a migration, never
  defaulted, never invoked by any test against `Conversations` in
  particular except with an obviously-fixture-only rate.

### 9.2 Unresolved human decisions — recorded, not fabricated, both gate go-live

Two independent sets of values remain pre-implementation human
decisions, neither fabricated:

1. **Numeric rate** — exact `retail_rate_micro`, `provider_cost_micro`,
   `unit_label`, and settlement currency for the one pinned pilot
   context (§3.6). RFC-005 §39 item 1 ("exact initial retail usage rates
   per eventually-metered feature") remains explicitly
   `NON-IMPLEMENTATION-READY` until resolved, and item 10 only
   *recommends* USD/`decimal_places = 2`, without confirming it.
   **`provider_cost_micro` in particular cannot be derived from any
   legacy data this repository stores** — the legacy `options` blob is
   customer-facing retail price only; the human supplying this figure
   must independently know or obtain the real provider cost (e.g. from
   the chosen gateway's own published per-segment pricing for the
   specific pinned country), never the legacy retail figure
   reinterpreted as cost.
2. **Pilot tuple configuration** — the three scalar values,
   `pilot_business_id`, `pilot_country_id`, `pilot_sending_server_id`
   (§3.6), each `null`/fail-closed by default.

**Lifecycle, stated exactly:** implementation may begin and be fully
completed, tested, and merged with the mechanism built and proven
entirely against the isolated test database's own fixture-only rate and
fixture-only pilot tuple. **M5 is not "complete" in the sense of a live,
revenue-relevant metered feature, and `Conversations.is_metered` must
remain `false`, and all three pilot config values must remain unset, in
every real (non-test) environment, until a human has separately supplied
both the numeric rate (item 1) and the pilot tuple (item 2) and has
separately, explicitly run §9.1's command against that real
environment.** The implementation PR itself must not flip either switch
outside its own test suite, must not pre-populate any pilot config value
with a real value in any environment's checked-in config/`.env.example`,
and must not treat "the code merged" as equivalent to "M5 is live."

---

## 10. Existing entitlement behavior — preserved

`PlatformFeatureRegistry::isAvailable()` is not modified by this
contract's allowlist; no `Planned` feature is touched; no unavailable or
merely-`Planned` `PlatformFeature` becomes executable because of this
contract, exactly as RFC-004 §11/§14's own floor requires.
`EntitlementManager::decide()` remains the only call path into usage
authorization — never bypassed, since the new `quickSend()` branch calls
`decide()` itself (§6 step 3), exactly as any other entitled feature
would. `usage_unauthorized` is a pre-existing key in `decide()`'s reason
space (§3.3), not a new one; the existing nine-key surface is unchanged
and re-verified (§13). `Conversations`' own availability (`Available`)
is unchanged — only its usage-authorization outcome becomes
conditionally reachable once a human activates metering for the pilot
tuple.

---

## 11. Schema

**No new migration, no new table, no new column.** Every table M5
touches (`platform_feature_usage_classifications`, `business_usage_rates`,
`business_usage_rate_activations`, `platform_feature_usage_classification_transitions`,
`business_usage_reservations`, `business_usage_ledger_entries`,
`business_usage_wallets`) already exists from M1, unmodified since. The
guard conditions in §5.1 (Workspace-Business cardinality, the pilot
tuple) are evaluated against already-existing columns and relations plus
new **configuration** values, not new schema elements.
`ReservationResult`'s new field (§3.8) is a plain PHP value object
property, not a schema element. `SendCampaignSMS.php`'s `m5_outcome`
marker (§3.9) is a transient, non-persisted Eloquent property, not a
schema element — confirmed by its attachment point being strictly after
`Reports::create()` returns.

---

## 12. Exact implementation allowlist

**Total mechanically-authorized paths: 17. Stop threshold: 18th path.**
Any path required during implementation but absent from this list is a
stop-and-report condition, not a silent addition.

### Modify (9)

1. **`app/Http/Controllers/Customer/ChatBoxController.php`** — add
   `$input['conversation_context'] = true;` at the two existing call
   sites (§7). `sent()` requires no additional line for token
   propagation (§7); `reply()` requires the explicit
   `$input['idempotency_token'] = $request->input('idempotency_token');`
   line plus inline UUID validation (§7). `new()` (the GET action) and
   `sent()` (the POST action) gain the session-based pending-token
   read/mint and clear-on-resolution logic (§6.1). No other line in this
   file changes.
2. **`app/Repositories/Eloquent/EloquentCampaignRepository.php`** —
   `quickSend()` gains the full six-condition guard chain (§5.1), the
   business-namespaced idempotency key derivation (§6.1), the
   `reserve()` call and `createdByThisInvocation`-driven atomic-claim
   check (§6 step 4), the `m5_conversations_usage_tracking` flag set
   immediately before the provider call (§6 step 5), and the
   `$data->m5_outcome`-driven outcome classification (§6 step 6). Every
   existing unconditional code path for every other case is untouched.
3. **`app/Http/Requests/ChatBox/SentRequest.php`** — add
   `'idempotency_token' => 'required|uuid'` to `rules()`.
4. **`resources/views/customer/ChatBox/new.blade.php`** — the hidden
   `idempotency_token` field renders a controller-passed token value
   (§6.1), not an inline `Str::uuid()` call.
5. **`resources/views/customer/ChatBox/index.blade.php`** —
   `enter_chat()`'s existing inline script gains the lazy-generate/
   retain-on-ambiguous/clear-on-success token logic (§6.1), and a third
   response-status branch (alongside the existing `success`/`error`)
   for the `'still processing'` case that does not regenerate the token
   and shows an informational (non-error) message. No other behavior in
   this file changes.
6. **`config/usage_billing.php`** — adds
   `conversations_metering.pilot_business_id`,
   `conversations_metering.pilot_country_id`,
   `conversations_metering.pilot_sending_server_id`, three nullable
   scalars, all `null` by default, following the file's own existing
   "additive only, no default invented" convention.
7. **`app/Library/Usage/UsageWalletManager.php`** — `reserve()` gains
   the `UniqueConstraintViolationException`-catch-and-verify-then-
   refetch-or-rethrow logic (§3.8), reusing the exact existing idiom
   from `UsageBillingCheckoutManager::finalizeAddonPurchaseIfPending()`.
   No other method on this class changes; every other milestone's own
   use of `reserve()`/`commit()`/`release()` is unaffected (the new
   fourth `ReservationResult` field is additive/defaulted and ignored by
   every caller that does not read it).
8. **`app/Library/Usage/ReservationResult.php`** — one new defaulted
   constructor parameter, `createdByThisInvocation`, defaulting to
   `false` (§3.8). Confirmed backward compatible against all five
   existing call sites, all internal to `UsageWalletManager.php`.
9. **`app/Models/SendCampaignSMS.php`** — the two Twilio/TwilioCopilot
   case blocks (lines 457-520) gain the flag-gated `$m5Outcome`
   assignment; the method's existing return point gains the one-line
   non-persisted attachment
   (`if (isset($m5Outcome)) { $status->m5_outcome = $m5Outcome; }`,
   §3.9). No other line in this ~13,440-line method changes; every
   other gateway branch and every other caller is untouched.

### New (8)

10. **`app/Console/Commands/ActivateUsageFeatureRate.php`** — §9.1's
    operator command.
11. **`app/Console/Commands/ResolveAmbiguousUsageReservation.php`** —
    §6.2's manual-resolution command, with all six safety checks.
12. **`tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`** — the
    core metering-lifecycle test cases (§13 items 1-6, 8-14, 17).
13. **`tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`** —
    the non-ChatBox-caller and non-M5-flag regression cases (§13 items
    7, 15, 16).
14. **`tests/Feature/Usage/ActivateUsageFeatureRateCommandTest.php`** —
    §13 item 18.
15. **`tests/Feature/Usage/ResolveAmbiguousUsageReservationCommandTest.php`** —
    §13 item 19, covering all six §6.2 checks.
16. **`tests/Feature/Usage/Support/concurrent_conversations_send_runner.php`** —
    a real cross-process test-support runner, modeled directly on the
    already-merged RFC-005 M4 precedent
    (`tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php`'s
    own bootstrap/database-guard/exit-code shape), needed because
    proving a genuine concurrent-process race (§13 item 20) requires two
    real OS processes, which single-process PHPUnit execution cannot
    produce on its own.
17. **`tests/Feature/Usage/ConversationsConcurrencyTest.php`** — the
    PHPUnit test file driving item 16's runner, kept as its own
    dedicated file rather than folded into item 12 (§13 items 20-21).

### Read-only dependencies (relied upon, never modified)

- `app/Library/Usage/UsageWalletManager.php` (every method other than
  `reserve()`'s own widening, item 7)
- `app/Repositories/Contracts/BusinessUsageReservationRepository.php`
  (`findById()` — read directly by the new `quickSend()` state-machine
  check, §6 step 4; already-public, no new method)
- `Illuminate\Database\UniqueConstraintViolationException` (framework
  class, already used elsewhere in this codebase for the identical
  purpose)
- `app/Library/Usage/UsageBillingCheckoutManager.php` (read only, to
  confirm the exact precedent idiom item 7 reuses — not modified)
- `app/Library/Entitlement/EntitlementManager.php` (including
  `assertPlatformAdministrator()`'s body, reproduced not called)
- `app/Library/Entitlement/RealUsageAuthorizationGateway.php`
- `app/Library/Entitlement/PlatformFeatureRegistry.php`
- `app/Enums/Entitlement/PlatformFeature.php`
- `app/Enums/Entitlement/PlatformFeatureAvailability.php`
- `app/Models/Customer.php` (`primaryBusiness()`)
- `app/Models/Business.php`, `app/Models/Workspace.php` (including the
  `businesses()` relation used for the ownership-scope guard)
- `app/Models/User.php` (`is_admin` column, read by both new commands)
- `app/Models/PlatformFeatureUsageClassification.php`,
  `app/Models/BusinessUsageRate.php`
- `app/Repositories/Contracts/CampaignRepository.php` (interface,
  unchanged — `quickSend()`'s signature does not change)
- `app/Models/SendCampaignSMS.php` / `app/Models/Campaigns.php` beyond
  item 9's own narrow addition (`sendPlainSMS()`'s remaining ~13,400
  lines, mocked in tests per §3.10, never modified)
- `app/Models/Reports.php` (the model `sendPlainSMS()` already returns —
  read only, to confirm setting an unmapped dynamic property is safe and
  non-persisting)
- `app/Models/SendingServer.php` (`TYPE_TWILIO`/`TYPE_TWILIOCOPILOT`
  constants; `sending_server_based_pricing_plans` schema)
- `app/Jobs/Usage/ExpireStaleUsageReservations.php` (relied upon, §3.7,
  never modified)
- `app/Console/Kernel.php` (auto-discovery already covers new commands)
- `routes/customer.php` (ChatBox routes already wire to `sent()`/`reply()`;
  no route change needed)
- `app/Http/Controllers/Customer/CampaignController.php`,
  `app/Http/Controllers/API/CampaignController.php`,
  `app/Http/Controllers/API/CampaignHTTPController.php`,
  `app/Repositories/Eloquent/EloquentContactsRepository.php`,
  `app/Http/Controllers/Customer/DLRController.php` (the five non-
  `Conversations` `quickSend()` callers, §3.4 — read to confirm
  unaffected, regression-tested in item 13, never modified)
- `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`
  (`storeBusiness()`, read only, to confirm multi-Business creation is
  live — not modified)
- `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`,
  `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php` (existing
  regressions, re-run, not modified)

---

## 13. Required tests

New, across the seven new test files (§12 items 12-17), or split further
at the implementation's own discretion provided every case below is
covered exactly once:

1. Metered `Conversations` plain-SMS send, all six §5.1 conditions
   satisfied → `reserve()`'s `createdByThisInvocation === true` →
   `m5_conversations_usage_tracking` set → provider double returns an
   `accepted` `m5_outcome` → exactly one reservation, exactly one
   `commit()` call, wallet debited exactly segment-count × retail-rate.
2. Denied wallet (suspended / outstanding debt / insufficient balance)
   → the `sendPlainSMS()` double is never invoked.
3. Genuine non-throwing, non-accepted Twilio response, M5 flag set →
   `m5_outcome === 'definitive_rejection'` → `release()` **is** called;
   client regenerates its token.
4. Twilio caught `ConfigurationException`/`TwilioException`, M5 flag set
   → `m5_outcome === 'ambiguous_exception'` → reservation remains
   `Pending`; `release()` is **not** called; client retains its token.
5. **Isolation of the opt-in flag:** the identical Twilio caught-
   exception scenario **without** the M5 flag set (any non-qualifying
   call) → `Reports`' persisted `status`/`customer_status` are byte-for-
   byte identical to today's existing behavior, and no `m5_outcome`
   property exists on the returned object.
6. Retry against an already-`Committed` reservation (same token) →
   `createdByThisInvocation === false` → provider double is **not**
   invoked; response is the generic already-sent success shape.
7. Retry against a terminal `Released`/`Expired` reservation (same
   token) → provider double is **not** invoked; response is the
   distinct "could not confirm" shape; client must regenerate its token
   for any further attempt.
8. A same-token retry landing on a still-`Pending`, pre-existing
   reservation (`createdByThisInvocation === false`, status `Pending`,
   simulated by directly seeding a `Pending` row before the second
   call) → provider double is **not** invoked; response is the "still
   processing" shape; the structured warning is logged; client retains
   its token.
9. **Real concurrent same-token race** (two genuine OS processes, via
   the runner in §12 item 16) → exactly one reservation row, exactly one
   `createdByThisInvocation === true` result, exactly one provider
   invocation (asserted via a recording fake/double shared across the
   two processes), exactly one accounting outcome.
10. **Same-key second caller arriving after the first reservation's
    creation but before its completion** → zero second provider
    invocation (the losing process's `createdByThisInvocation` is
    `false`; it takes §6 step 4's non-provider-call branch, never
    step 5).
11. **No timestamp-based freshness heuristic exists anywhere in the
    shipped code path** — a direct assertion (e.g. absence of any
    `reserved_at`/pre-call-timestamp comparison in the relevant source)
    that the claim decision is driven solely by
    `createdByThisInvocation`.
12. An `UniqueConstraintViolationException` thrown for a reason *other
    than* this reservation's own `idempotency_key` (simulated by
    seeding a distinct unique-constraint collision inside a test double
    of the transaction) is **re-thrown**, not swallowed.
13. `ReservationResult`'s corrected default: a denial path
    (`granted: false`) is asserted to carry
    `createdByThisInvocation === false`.
14. Two genuinely distinct compose actions (two different tokens) never
    collide — two reservations, two charges.
15. Plain SMS segment quantity correctness — reservation's
    `estimated_quantity` and commit's `final_quantity` both equal the
    real `SMSCounter::count()` output for a representative multi-segment
    message.
16. Non-M5 channels (voice/mms/whatsapp/viber/otp) sent through
    `ChatBoxController` preserve their existing `sms_unit` charging
    behavior unchanged, with `Conversations.is_metered = true` active.
17. **The five non-ChatBox `quickSend()` callers are completely
    unaffected** — at least one representative call through
    `Customer\CampaignController::postQuickSend()` (or the closest
    practical fixture) with `Conversations.is_metered = true` active,
    proving no reservation/ledger row is created and legacy `sms_unit`
    behavior is exactly preserved.
18. Legacy `sms_unit` and the RFC-005 wallet never both charge the same
    send — a single successful metered send is asserted to leave
    `sms_unit` completely unchanged, and a single successful
    non-qualifying send is asserted to leave the wallet completely
    unchanged.
19. Business isolation — two Businesses' reservations/ledger entries
    never cross-contaminate for concurrent/successive sends, **including**
    a direct assertion that a client-supplied raw token, once business-
    namespaced, cannot resolve another Business's reservation even when
    the raw token portion is identical across two different Businesses'
    requests.
20. A Workspace owning more than one Business stays legacy, **even when**
    one of its Businesses matches `pilot_business_id` (proves the §5.1
    item 4 cardinality guard independently of the tuple-match guard).
21. Each pilot dimension tested independently: the same Business and
    country but a **different** `SendingServer` id (even if also
    Twilio-type) stays legacy; an out-of-pilot Business stays legacy; an
    out-of-pilot country stays legacy; a `SendingServer` matching the
    pilot id but of a non-Twilio gateway type stays legacy; the fully
    in-scope tuple engages the wallet.
22. `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`
    re-run unmodified — still exactly nine keys, `usage_unauthorized`
    now reachable via a real `Conversations` call in addition to its
    existing synthetic coverage.
23. `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php` re-run
    unmodified — confirms no `Planned` feature became executable and
    `Conversations` availability is unchanged.
24. **`ActivateUsageFeatureRateCommandTest`:** the command requires
    every numeric/label argument explicitly; refuses to run without
    `--actor-user-id`; refuses to run when the supplied id's `is_admin`
    is not true; succeeds only for a genuine admin id, in the correct
    `setActiveRate()`-then-`activateMetering()` order.
25. **`ResolveAmbiguousUsageReservationCommandTest`, covering all six
    §6.2 checks:** rejects a nonexistent reservation id; rejects a
    non-`Pending` reservation (`Committed` and `Released` cases each
    tested); rejects a non-`Conversations` `feature_key` reservation
    (proving the command cannot be used as a generic wallet-mutation
    tool); rejects a missing/non-admin `--actor-user-id`; rejects an
    empty `--reason`; rejects a reservation whose `business_id` does not
    match the currently-configured pilot Business id; only the
    fully-valid case mutates, and `--outcome=sent` calls `commit()`
    while `--outcome=not-sent` calls `release()`.

Regression, run in full, not modified except where named above:

- Full `tests/Unit/Usage tests/Feature/Usage` suite.
- Full `tests/Unit/Entitlement tests/Feature/Entitlement tests/Unit/Workspace tests/Feature/Workspace` suite.
- Full test suite (`php artisan test --stop-on-failure`).

---

## 14. Stop conditions

- An 18th path required beyond §12's seventeen.
- The §3.5 null-`primaryBusiness` precondition query returning a nonzero
  count in any environment the implementation can query.
- Any evidence that `SendCampaignSMS::sendPlainSMS()`'s segment count or
  price can legitimately differ between reservation and commit for a
  plain-SMS send (would contradict §8's "no second algorithm, always
  equal" finding and require re-opening the quantity decision).
- Any of the five non-`Conversations` `quickSend()` callers found,
  during implementation, to require a code change to remain unaffected —
  §7's guard is designed to need none; if reality disagrees, stop and
  report rather than expanding the guard's own footprint ad hoc.
- A correction round exceeding 2 (`maximum_correction_rounds`, §0).
- Any attempt, by any actor, to call `setActiveRate()`/`activateMetering()`
  against a non-test database with a rate this contract did not receive
  as an explicit human-supplied number (§9.2 item 1) — hard stop, not a
  correction-round item.
- Any attempt to populate any of the three §9.2 item-2 pilot config
  values with a real value in checked-in config/`.env.example` — hard
  stop, same class as the numeric-rate stop condition.
- Any evidence that `enter_chat()`'s existing button-disable/error-
  handling behavior, or `sent()`'s redirect-on-error shape, no longer
  matches what §6.1 assumes.
- Any evidence that `ReservationResult`'s new fourth field breaks an
  existing caller not accounted for in §3.8's five-call-site count.
- Any evidence that a gateway other than Twilio/TwilioCopilot is ever
  selected as `pilot_sending_server_id` without the §5.1 item 6
  capability assertion catching it first.
- Any evidence that `$m5Outcome`'s post-creation attachment to `$status`
  in `SendCampaignSMS.php` has any persistence side effect whatsoever
  (would contradict §3.9's own "no schema change" proof and require
  immediate re-audit).
- Any evidence that a caller other than `quickSend()`'s own M5-guarded
  branch ever sets `m5_conversations_usage_tracking`.

---

## 15. Verification and publication (this document only)

- `git diff --check` clean.
- `git diff origin/main --name-only` shows exactly one path:
  `docs/automation/RFC-005-M5-CONTRACT.md`.
- Commit message: `docs: consolidate RFC-005 M5 implementation contract`,
  as a new commit on the existing branch, not an amend.
- Push branch `chore/rfc-005-m5-contract`. PR #107 remains Draft,
  unmerged. No implementation begins.
