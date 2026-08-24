# RFC-005 Milestone 5 — Metered Feature Classification

**Status: PROPOSED — READY FOR HUMAN SELECTION REVIEW. NOT AUTHORIZED FOR
IMPLEMENTATION.** Merging this document authorizes drafting this one
document only — it does not write any `app/`, `database/`, `routes/`,
`config/`, or `resources/` file, does not flip `Conversations`'
classification to `is_metered = true`, does not activate any real
retail/provider rate, does not begin M6 (conformance/tag) in any way, and
does not authorize any live charge to any real Business. A separate,
explicit, human-reviewed `docs/automation/AI-AUTONOMY-STATE.json` update
pinning a real implementation PR/branch/SHA remains required before any
M5 product work may begin, per every prior RFC-005 milestone's own
convention.

**Remediation notice (this pass).** This document's own prior text
declared a **STRUCTURAL BLOCKER — NOT SAFE TO MERGE OR IMPLEMENT AS
WRITTEN**, based on `EntitlementManager::decide()` denying `Conversations`
on wallet-health grounds once metering was flipped on. **A fresh
selection review, performed after RFC-005 Amendment 1 fully merged and
closed (`docs/automation/RFC-005-AMENDMENT-1-CLOSURE.md`, main merge
`3ecff57a53f892edbbee9f01e05d49eb3d989ac5`), found that this document's
entire prior text — including every earlier refinement pass — was
drafted and repeatedly refined against a base commit
(`24fd1730e535d2360bb3a6fef7caf97f3272457c`) that is a direct git
ancestor of, and strictly predates, Amendment 1 Slice 1 EXPAND's own
merge.** Every technical claim in the prior text about
`business_usage_rates`, `business_usage_rate_activations`,
`setActiveRate()`/`activateMetering()`'s prerequisites, and the former
structural blocker itself was therefore evaluated against code that no
longer exists. This pass re-audits the entire document line-by-line
against current `main` and corrects every stale claim found. §3.12
(retained, corrected) records that **the former blocker is now retracted
as moot**: Amendment 1 Slice 2 CUTOVER independently decoupled feature
entitlement from wallet health (§5.8 of that slice's own contract), which
is exactly the architectural change the prior §3.12 said M5 had no
authority to make unilaterally — it was made anyway, for unrelated
reasons, before this review, and its effect resolves the exact
contradiction §3.12 identified. Every other section below has been
independently re-verified against current `main`; sections whose prior
content remains accurate are preserved with only citation corrections
noted; sections built on now-superseded meter/rate mechanics are rewritten
in full. Nothing past this point requires reading an earlier commit on
this branch.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-m5-contract`, in its own
  established worktree (`../rfc-005-m5-contract-worktree`). Originally
  based on `origin/main` at `24fd1730e535d2360bb3a6fef7caf97f3272457c`
  (pre-Amendment-1). **This remediation pass merges current
  `origin/main` (`3ecff57a53f892edbbee9f01e05d49eb3d989ac5`, RFC-005
  Amendment 1 closure) into this same branch with a normal merge commit
  — no rebase, no force push — per this repository's own established
  precedent for keeping a long-lived governance/design branch current**
  (e.g. `cac9a17`, "Merge remote-tracking branch 'origin/main' into
  chore/rfc-005-amendment-1-usage-meter-identity-design"). This
  document's own prior revision history already establishes that every
  earlier refinement happened as new commits on this same branch/PR
  (#107), never a successor PR — this remediation continues that same
  established pattern rather than inventing a new one.
- **Human product decision, locked, not reopened by this remediation:**
  the first real metered feature remains `PlatformFeature::Conversations`.
  The fresh selection review that triggered this remediation confirmed
  this selection remains valid — the former blocker that questioned it
  is itself retracted (§3.12).
- `maximum_correction_rounds: 2`, unconsumed — no implementation PR
  exists yet.
- Any path required during the future implementation but absent from
  §12's own numbered allowlist is a stop-and-report condition. The stop
  threshold is the allowlist's own final count **plus one** (§14).
- Drafting/remediating this contract makes **zero** application changes.
  No `app/`, `database/`, `routes/`, `config/`, `resources/`, `tests/`,
  or `docs/automation/AI-AUTONOMY-STATE.json` file is touched by this
  branch — only this one document.
- **Re-audit discipline for this remediation pass, against current
  `main` post-Amendment-1-closure**: `app/Library/Usage/UsageWalletManager.php`
  in full (`reserve()`, `commit()`, `release()`, `evaluateCoarseCapacity()`,
  `setActiveRate()`, `activateMetering()`); `app/Repositories/Contracts/UsageMeterRepository.php`
  and `app/Repositories/Eloquent/EloquentUsageMeterRepository.php`;
  `app/Repositories/Contracts/BusinessUsageRateRepository.php` and
  `app/Repositories/Eloquent/EloquentBusinessUsageRateRepository.php`;
  `app/Models/UsageMeter.php`, `app/Models/BusinessUsageRate.php`,
  `app/Models/BusinessUsageRateActivation.php`; the three Slice 3
  CONTRACT migrations and the four Slice 1 EXPAND migrations that create
  `usage_meters`/`usage_meter_transitions` and add `meter_key`
  everywhere; `app/Library/Entitlement/RealUsageAuthorizationGateway.php`
  and `app/Library/Entitlement/EntitlementManager.php`; and
  `docs/automation/RFC-005-AMENDMENT-1-CLOSURE.md`,
  `docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md`. Everything
  else this document's original audit discipline paragraph named
  (`ChatBoxController`, `EloquentCampaignRepository::quickSend()`,
  `SendCampaignSMS::sendPlainSMS()`, `chat_boxes`/`chat_box_messages`,
  `Customer::primaryBusiness()`, `Business`/`Workspace`,
  `WorkspaceController::storeBusiness()`, the legacy pricing tables, the
  Twilio/TwilioCopilot case blocks) is untouched by Amendment 1 and was
  re-confirmed unchanged rather than re-read line-by-line a second time.

---

## 1. This contract's own exact file scope

Exactly one file, this document: `docs/automation/RFC-005-M5-CONTRACT.md`.

---

## 2. Preflight — verified (this remediation pass)

- `origin/main` confirmed exactly `3ecff57a53f892edbbee9f01e05d49eb3d989ac5`
  (RFC-005 Amendment 1 closure) before this remediation began.
- This branch is `origin/main` at that SHA, merged forward via a normal
  merge commit, plus this document's own commits — no rebase, no force
  push, no history rewrite.
- `bcmath` remains confirmed enabled; no new PHP extension dependency.
- No RFC-004 file, and no already-merged RFC-005 M1–M4 or Amendment 1
  file, is modified by this document. §12's allowlist authorizes a
  precise, numbered set of existing files for the future implementation,
  and no others.

---

## 3. Mandatory repository audit — findings

### 3.1 `PlatformFeature::Conversations` — current classification and floor

Unchanged from the original audit, re-confirmed against current `main`:
`app/Enums/Entitlement/PlatformFeature.php`'s `Conversations` case is
present, unchanged since RFC-004.
`app/Library/Entitlement/PlatformFeatureRegistry.php` still maps
`Conversations->value => PlatformFeatureAvailability::Available` (one of
exactly three `Available` cases alongside `Crm`/`Automations`; every
other case remains `Planned`). M5 changes only `Conversations`'
*usage-metering* state, never its *availability*.
`platform_feature_usage_classifications` still holds one row per
`PlatformFeature` case, backfilled `is_metered = false`,
`active_rate_id = null` for `conversations`, confirmed unmodified by any
M1–M4 or Amendment 1 correction.

### 3.2 `UsageWalletManager` — the exact, current, meter-based machinery M5 reuses

**Rewritten in full.** Read line-by-line from
`app/Library/Usage/UsageWalletManager.php` on current `main`
(post-Amendment-1):

- **`reserve(Business $business, string $featureKey, string $idempotencyKey, ?string $estimatedQuantity = null): ReservationResult`**
  — the `$featureKey` parameter name is frozen from M1 and is **not**
  renamed by Amendment 1, but it is now resolved as a **meter key**, not
  a feature key. Inside `DB::transaction`: locks the wallet row, rolls
  the spend period over, then resolves
  `$meter = $this->meterRepository->findByMeterKey($featureKey)` —
  throwing `NoActiveRateForFeatureException($featureKey)` if **no such
  `usage_meters` row exists at all** (a precondition that did not exist
  before Amendment 1). If a meter is found, its `business_id` (when
  non-null) is checked against the caller's `$business->id`, throwing
  `UsageMeterBusinessScopeMismatchException` on mismatch; its
  `currency_id` is checked against the wallet's own `currency_id`,
  throwing `UsageMeterCurrencyMismatchException` on mismatch; then
  `active_rate_id === null` throws the same `NoActiveRateForFeatureException`
  as before (rate not yet activated on this meter); then `is_metered`
  must be `true` or `UsageMeterNotMeteredException` is thrown. The rate
  itself is then resolved by `$meter->active_rate_id` and cross-checked
  (`$rate->meter_key !== $meter->meter_key`) for integrity, throwing
  `UsageMeterRateIntegrityException` on any mismatch. Only after all of
  this does the existing `wallet_suspended`/`outstanding_debt`/
  `insufficient_balance` denial-before-write logic run. On success, the
  written `business_usage_reservations` row now carries **both**
  `feature_key` (dual-written from `$meter->feature_key`, permanent
  historical snapshot, never dropped by Slice 3) **and** `meter_key`
  (from `$meter->meter_key`, `NOT NULL` since Slice 3) — the ledger entry
  mirrors both. `UsageMeterBackfillIncompleteException`/`UsageMeterRollbackVersionCollisionException`
  are migration-time-only exceptions and never surface here.
- **`commit(int $reservationId, ?string $finalQuantity = null): CommitResult`**
  and **`release(int $reservationId): void`** — unchanged in mechanics
  from the original audit; neither reads meter/rate identity again after
  the reservation row is written (both operate on the immutable
  reservation snapshot). M5's own plain-SMS scope never exercises
  `commit()`'s overage/unused-release sub-cases (§8, unchanged).
- **`evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision`**
  — **materially different from the pre-Amendment-1 version this
  document originally described.** Current body:
  ```php
  public function evaluateCoarseCapacity(Business $business, PlatformFeature $feature): UsageCapacityDecision
  {
      return new UsageCapacityDecision(true);
  }
  ```
  unconditionally, regardless of `is_metered`, wallet state, or any other
  input — with its own docblock explicitly citing "RFC-005 Amendment 1
  §5.8, Slice 2 CUTOVER — feature entitlement must not depend on wallet
  health." §3.12 below states the full consequence of this change.
- **`setActiveRate(string $featureKey, string $retailRateMicro, string $providerCostMicro, string $unitLabel, int $currencyId, int $actorUserId, string $reason): BusinessUsageRate`**
  and **`activateMetering(string $featureKey, int $actorUserId, string $reason): void`**
  — both still exist, both still have this exact, frozen public
  signature, and neither has ever been called by any production code
  path. **But both now require a pre-existing `usage_meters` row for the
  supplied key.** `setActiveRate()` calls
  `$this->meterRepository->findForUpdateByMeterKey($featureKey)` first
  and throws `NoActiveRateForFeatureException($featureKey)` if none
  exists — **this is a new precondition Amendment 1 introduced that did
  not exist when this document was first drafted, and the original
  §9.1 activation-command design never created such a row.** §3.14 and
  §9.1 (both rewritten below) resolve this. `activateMetering()`
  continues to require an already-active rate (`active_rate_id !== null`)
  before it will run — the mandatory rate-then-metering order is
  unchanged.
- Rate identity itself is now **meter-local, not feature-wide**:
  `app/Repositories/Contracts/BusinessUsageRateRepository.php` exposes
  `latestVersionForMeter(string $meterKey): int` (not
  `latestVersionForFeature` — that method has never existed in this
  repository's history) and `findByMeterAndVersion(string $meterKey, int $version)`.
  `EloquentBusinessUsageRateRepository::latestVersionForMeter()` is
  `WHERE meter_key = ? MAX(version)` — strictly scoped to one meter,
  confirmed by direct read. `business_usage_rates`/
  `business_usage_rate_activations` no longer have a `feature_key`
  column at all (dropped by Slice 3 CONTRACT); their sole uniqueness is
  `UNIQUE(meter_key, version)`, not `UNIQUE(feature_key, version)`.

**Conclusion, corrected: M5 needs no new wallet-mechanics code beyond
the two narrow, precisely-bounded widenings §3.8 describes (unchanged
from the original audit) — but its activation workflow (§9.1) and
provisioning story (§3.14) must be rewritten to account for the new,
mandatory `usage_meters` row.**

### 3.3 `RealUsageAuthorizationGateway` and `EntitlementManager` — confirmed unbypassed; wallet health confirmed decoupled

- `app/Library/Entitlement/RealUsageAuthorizationGateway.php` — still
  bound in place of `NullUsageAuthorizationGateway`. `check()`'s body,
  re-read on current `main`:
  ```php
  public function check(Business $business, PlatformFeature $feature): UsageAuthorizationResult
  {
      $decision = $this->manager->evaluateCoarseCapacity($business, $feature);
      if (! $decision->authorized) {
          return new UsageAuthorizationResult(authorized: false, reason: 'usage_unauthorized');
      }
      return new UsageAuthorizationResult(authorized: true);
  }
  ```
  Since `evaluateCoarseCapacity()` (§3.2) now unconditionally returns
  `authorized: true`, **`check()` unconditionally returns
  `authorized: true` for every Business and every feature, today,
  independent of this contract.** The `usage_unauthorized` denial branch
  is therefore currently dead code for every feature — not newly made
  dead by this contract, already dead on `main` before this remediation.
- `app/Library/Entitlement/EntitlementManager.php` — `decide()`'s
  denial-reason surface (nine keys, including `usage_unauthorized`)
  remains exercised end-to-end by
  `tests/Feature/Entitlement/EntitlementManagerNineKeySurfaceUnchangedTest.php`.
  `usage_unauthorized` is not a new key; it is a pre-existing key that
  is, as of current `main`, permanently unreachable via the coarse gate
  for every feature (§3.12 explains why this is correct, not a defect).
  `EntitlementManager` remains the only call path into usage
  authorization; `Conversations`' own availability remains unchanged; no
  `Planned` feature is touched.
- `EntitlementManager::assertPlatformAdministrator(int $actorUserId): void`
  (private, `(bool) $this->userRepository->query()->whereKey($actorUserId)->value('is_admin')`)
  is unchanged, not exposed or modified by this contract — §9.1's
  command reproduces this identical check directly.

### 3.4 The current `Conversations` execution path — six `quickSend()` callers

Unchanged from the original audit — Amendment 1 never touched
`ChatBoxController`, `EloquentCampaignRepository::quickSend()`,
`SendCampaignSMS`, `chat_boxes`/`chat_box_messages`, or any of the six
confirmed `quickSend()` call sites. Re-confirmed unchanged rather than
re-read a second time: `ChatBoxController::sent()`/`::reply()` remain
the two genuine `Conversations` entry points; the other five call sites
(bulk Quick Send, contact-group welcome SMS, DLR auto-reply, third-party
API) remain distinct, non-`Conversations` surfaces. Wiring metering
directly inside `quickSend()` would still silently misclassify all six —
§5 and §7's explicit, additive, opt-in discriminator design (unchanged)
still resolves this.

### 3.5 Business resolution — coverage of `primaryBusiness()` is not the same as correct ownership attribution

Unchanged from the original audit — this concerns `Customer`/`Business`/
`Workspace` ownership, entirely untouched by Amendment 1's meter-identity
redesign. `chat_boxes`/`chat_box_messages` still carry no
`business_id`/`workspace_id` at all; `primaryBusiness()` remains the only
`User`→`Business` mapping; multi-Business Workspaces remain a real, live
capability (`WorkspaceController::storeBusiness()`). The resolution is
unchanged: a qualifying M5 send additionally requires the sending
Customer's Workspace to own **exactly one** Business
(`$business->workspace->businesses()->count() === 1`); a multi-Business
Workspace never engages M5 metering at all and stays on 100% legacy
`sms_unit` behavior.

### 3.6 Rate dimension — a meter-scoped pilot tuple, not a feature-wide limitation

**Rewritten.** The original analysis of *why* a single, scalar pilot
tuple is required — plain-SMS retail price varies by destination
country, by the Customer's own negotiated `CustomerBasedPricingPlan`,
and conditionally by the specific `SendingServer` row — remains
completely valid and is unaffected by Amendment 1; that legacy pricing
surface has not changed. What has changed is what a "singular pilot
tuple" now maps to in the rate schema.

**Pre-Amendment-1 (as this document originally assumed): one
`business_usage_rates` row represented one `feature_key`'s one rate
version — a whole `Conversations` feature could only ever have one
active rate at a time, system-wide, which is why a pilot tuple had to be
pinned by *configuration* (country/plan/server) rather than by *schema*.**

**Current, post-Amendment-1 architecture: rate identity is meter-scoped,
not feature-scoped.** `business_usage_rates`/`business_usage_rate_activations`
have no `feature_key` column at all; their sole uniqueness is
`UNIQUE(meter_key, version)` (`business_usage_rates_meter_key_id_unique`
target for every incoming composite FK). Multiple `usage_meters` rows
can share one `feature_key` (`conversations`) while each owns its own,
completely independent rate-version history — confirmed directly by
`app/Models/UsageMeter.php`'s schema (`feature_key`, `meter_key` UNIQUE,
`business_id` nullable, `currency_id` required) and by
`EloquentBusinessUsageRateRepository::latestVersionForMeter()`'s strict
`WHERE meter_key = ?` scoping.

**Resolution, corrected: the pilot tuple resolves to exactly one
`usage_meters` row (§3.14/§9.1 lock its exact identity), and that one
meter owns its own independent rate history.** The country/plan/server
pinning this document's original design chose remains the correct
*qualifying-send* mechanism (§5.1) — it still exists because legacy
pricing still varies along those dimensions and RFC-005 still tracks
only one `retail_rate_micro`/`provider_cost_micro`/`unit_label` per rate
version — but it is no longer the *only* thing preventing a second,
differently-priced tuple from coexisting. **A future milestone wanting a
second tuple (a different Business, a different country/server
combination, or a second pilot entirely) can provision a second,
independent `usage_meters` row with its own `meter_key`, its own
`business_id` scope, and its own rate history, under the same
`Conversations` feature_key, with zero collision against the first**
— no feature-wide version collision exists, because uniqueness is
meter-scoped. This does not require, and this contract still does not
authorize, any rate-schema expansion (a per-dimension rate map) — the
tuple-to-meter mapping already provides the necessary isolation.

`provider_cost_micro` remains entirely human-supplied and is never
inferred from the legacy `options` blob (unchanged, §9.2).

### 3.7 `ExpireStaleUsageReservations` — unmodified, and now load-bearing

Unchanged from the original audit — this job's mechanics do not touch
meter/rate identity at all; it operates purely on already-written
`business_usage_reservations` rows via `release()` (§3.2, unchanged).

### 3.8 `reserve()`'s atomic provider-call claim — concurrency-safe, database-enforced

Unchanged from the original audit. The `UniqueConstraintViolationException`-
catch-and-verify-then-refetch-or-rethrow widening to `reserve()` and the
new defaulted `createdByThisInvocation` field on `ReservationResult`
concern idempotency-key race handling, entirely orthogonal to meter/rate
resolution, and are unaffected by Amendment 1. Re-confirmed: the meter
resolution steps §3.2 added all happen *before* this widening's own code
region inside the same `DB::transaction`, so the widening's own
correctness argument (the database's own unique index is the sole race
arbiter) is unaffected.

### 3.9 Twilio/TwilioCopilot outcome classification — `SendCampaignSMS.php` authorized, opt-in, machine-readable

Unchanged from the original audit — concerns only the two
Twilio/TwilioCopilot case blocks' post-send outcome marker, entirely
orthogonal to meter/rate identity.

### 3.10 Testability of the real send path — a genuine pre-existing gap

Unchanged from the original audit.

### 3.11 Conversation-origin discriminator — an `$input` array key is forgeable and cannot be trusted

Unchanged from the original audit — the trusted, explicitly-typed
`bool $conversationContext` parameter design on `quickSend()` is
unaffected by Amendment 1.

### 3.12 Former structural blocker — RETRACTED as moot; entitlement and wallet health are now architecturally decoupled

**This section previously declared: "a global `is_metered` classification
cannot represent a tuple-scoped pilot without either widening general
entitlement infrastructure or accepting a real product-visible
contradiction," and stated the document was NOT SAFE TO MERGE OR
IMPLEMENT AS WRITTEN. That finding is retracted by this remediation, for
the following reason, verified directly against current `main`.**

The prior analysis was correct about the code as it existed when
written: `UsageWalletManager::evaluateCoarseCapacity()` at that time
evaluated the calling Business's own wallet state
(`wallet_missing`/`wallet_suspended`/`outstanding_debt`), and
`EntitlementManager::decide()`'s unconditional final step
(`$usageResult = $this->usageAuthorizationGateway->check($currentBusiness, $feature); if (! $usageResult->authorized) { return new EntitlementDecision(false, 'usage_unauthorized'); }`)
meant that flipping `Conversations.is_metered = true` would make
`decide()` — and therefore `decideAvailableFeaturesForBusiness()`'s live,
already-wired presentation path
(`app/Http/Controllers/Customer/Workspace/WorkspaceController.php`) —
start denying `Conversations` for *any* Business whose wallet happened
to be suspended or in debt, or whose destination country/`SendingServer`
fell outside the pinned pilot tuple, **even though the actual `ChatBox`
send for that non-qualifying case would fall through entirely
unaffected to legacy `sms_unit` behavior.** Every one of the five cases
(A–E) the prior text enumerated was a real, reachable instance of the
entitlement/presentation layer disagreeing with the actual send path.

**RFC-005 Amendment 1 Slice 2 CUTOVER (§5.8 of that slice's own merged
contract) independently rewrote `evaluateCoarseCapacity()` to
unconditionally return `authorized: true`, with the explicit stated
reason "feature entitlement must not depend on wallet health."** This
was not done for M5's benefit and was not authorized by this contract —
it was a general architectural decision made and merged before this
remediation pass, for reasons internal to Amendment 1's own scope. Its
effect, verified directly (§3.2, §3.3): `RealUsageAuthorizationGateway::check()`
now always returns `authorized: true`, so `EntitlementManager::decide()`
can no longer deny `Conversations` (or any feature) on
`usage_unauthorized` grounds, for any Business, in any wallet state, at
any classification value. Re-running the prior Cases A–E against this
current behavior: in every case, `decide()`'s answer for `Conversations`
is now **identical** regardless of `is_metered`, wallet state, or pilot-
tuple membership — it depends only on plan/workspace-override
entitlement, exactly as it always has for every other `Available`
feature. There is no longer any wallet-dependent claim for the actual
send path to agree or disagree with. **The contradiction is resolved not
by widening `UsageAuthorizationGateway`, inventing a new `PlatformFeature`
identity, or expanding the classification/rate schema — the three
options the prior text said M5 had no authority to choose — but because
the general infrastructure question was already, separately, resolved in
the direction that eliminates the contradiction entirely.**

**This does not mean the coarse gate is now useless — it means real
usage-spending protection (wallet suspended, outstanding debt,
insufficient balance) is enforced exactly once, at the one place it can
be enforced correctly and consistently: inside `reserve()` itself, at
the moment of actual use (§10 below states this classification
precisely).** A Business is never told "Conversations is unavailable"
for a reason unrelated to its actual send behavior, because the
entitlement layer no longer makes any claim about wallet health at all.

**Conclusion: `Conversations` is structurally safe as the first M5
metered feature under the current, post-Amendment-1 classification/
rate/entitlement model. No further architecture change, no widened
gateway contract, and no new `PlatformFeature` identity is required. §5
and §10 below lock the corrected qualifying-send/denial-classification
design this finding enables.**

### 3.13 The same-token, changed-payload retry hole — now a locked, mandatory server-side rule, not a note

The prior text (formerly §3.13) recorded, as an orthogonal finding not
designed into a fix, that a client retrying with the *same* idempotency
token but a *changed* send-defining payload (country, `SendingServer`,
sender, message) could, if that changed payload no longer satisfies the
§5.1 qualifying conditions, fall through past the existing
`business_usage_reservations` row into the legacy code path a second
time — a double-attempt risk. **This remediation closes that hole as a
required, locked server-side rule.** §6 below (rewritten) states it in
full as the first evaluated step of the reservation lifecycle, ahead of
every §5.1 qualifying condition.

### 3.14 Pilot `usage_meters` provisioning — locked (new section)

**This section did not exist in the original document — Amendment 1's
entire meter-identity concept postdates it.** §3.2/§3.6 above establish
that `setActiveRate()`/`activateMetering()`/`reserve()` all now require
a pre-existing `usage_meters` row for the pilot tuple. This section locks
exactly how that row is provisioned; §9.1 (rewritten) implements it.

- **Meter key, locked format:** `"conversations.pilot.{$pilotBusinessId}"`,
  computed at provisioning time from the already-locked
  `conversations_metering.pilot_business_id` config value (§3.6/§9.2,
  unchanged). This is a stable, deterministic, human-readable value —
  never randomly generated, never re-derived differently across runs —
  and is namespaced under `conversations.` specifically so a future
  second pilot tuple (a different Business) can coexist under a
  different, equally deterministic key with zero collision risk.
- **`feature_key`:** exactly `PlatformFeature::Conversations->value`
  (`'conversations'`) — this is the permanent classification tag tying
  the meter back to its owning feature; it is *not* the meter's identity
  (§3.6).
- **`business_id`:** exactly `conversations_metering.pilot_business_id`
  — **the pilot meter is Business-scoped, per this remediation's own
  requirement**, not global (`business_id = null`). This gives `reserve()`'s
  existing `UsageMeterBusinessScopeMismatchException` check a second,
  independent, schema-enforced layer of pilot-Business exclusivity,
  defense-in-depth alongside (never a replacement for) the §5.1
  qualifying-condition guard chain that is evaluated before `reserve()`
  is ever called.
- **`currency_id`:** resolved from the pilot Business's own
  `business_usage_wallets.currency_id` at provisioning time — **never an
  independently chosen value.** `reserve()` throws
  `UsageMeterCurrencyMismatchException` if the meter's currency does not
  exactly match the calling Business's wallet currency, so provisioning
  with any other value would make the pilot permanently unusable;
  resolving it from the actual wallet is the only value that can ever
  succeed.
- **`description`:** a fixed literal identifying this as the M5 pilot
  meter and naming the locked tuple, e.g. `"RFC-005 Milestone 5 pilot
  meter — Conversations plain/unicode SMS, business/country/sending-
  server pilot tuple."` — never left blank, never templated with a
  human-supplied free-text value at activation time (matching
  `UsageMeterRepository::create()`'s existing non-empty-description
  validation).
- **`updated_by_user_id`:** the same `--actor-user-id` supplied to and
  validated by §9.1's command (identical platform-administrator check,
  §3.3, unchanged).
- **Creation vs. pre-existence:** the meter is **created by the M5
  operator command itself** (§9.1), not required to pre-exist through
  any other mechanism — there is no separate "meter provisioning"
  command or migration seed; §9.1 owns this step as its own first
  action.
- **Idempotency:** before creating, the command reads
  `UsageMeterRepository::findByMeterKey($meterKey)`. If no row exists,
  it creates one with the exact fields above. **If a row already exists,
  the command verifies its `feature_key`, `business_id`, and
  `currency_id` exactly match the values this section locks** — if they
  match, provisioning is a no-op (the command proceeds directly to rate
  activation against the existing meter); if any of the three differs,
  **the command hard-fails before calling `setActiveRate()`/
  `activateMetering()` and writes nothing**, since the schema's own
  `usage_meters` design allows no destructive way to correct an
  existing meter's identity in place, and silently proceeding against a
  conflicting meter would risk activating a rate against the wrong
  scope/currency.
- **No fabricated, copied, inferred, or backfilled meter identity is
  introduced anywhere** — every field is either a fixed literal, derived
  from the human-supplied config/CLI values, or read from the pilot
  Business's own already-existing wallet row.

---

## 4. Contract status model

Unchanged from the original: this document may be **PROPOSED** (current
state — human review/selection pending), **AUTHORIZED-FOR-IMPLEMENTATION**
(only after a separate `AI-AUTONOMY-STATE.json` update pins a real
implementation PR/branch/SHA, per §0), or **CLOSED** (only after that
implementation PR merges and is independently verified, mirroring every
prior RFC-005 milestone's own closure convention, most recently
`docs/automation/RFC-005-AMENDMENT-1-CLOSURE.md`). This remediation
leaves the document in **PROPOSED** — it corrects the technical content
so a human can now meaningfully select it, but selection, authorization,
and implementation each remain their own separate, later, explicit
governance actions.

---

## 5. Exact M5 scope

### 5.1 What qualifies as an M5-metered plain SMS send

**Rewritten to state the meter-based conditions explicitly, and to place
the §3.13/§6 idempotency pre-check ahead of qualification evaluation.**
A `quickSend()` invocation is evaluated for RFC-005 wallet metering if
and only if it carries `$conversationContext = true` (§3.11, §7,
unchanged). §6 below states the mandatory idempotency-token pre-check
that runs first, resolving any already-existing reservation for the same
token before any of the following conditions are (re-)evaluated. Subject
to that pre-check, a send **qualifies** for a *new* metering attempt if
and only if **all** of the following hold:

1. `$input['sms_type']` resolves to the `plain` branch (`plain` or
   `unicode` — `unicode` is a character-encoding distinction only, not a
   separate rate, and remains in scope, unchanged from the original
   design).
2. The call originated from `ChatBoxController::sent()` or `::reply()`,
   mechanically distinguished by the trusted `bool $conversationContext`
   parameter (§3.11, unchanged).
3. The resolved pilot meter (§3.14) exists, `is_metered = true` on that
   meter, and it has an `active_rate_id` — i.e. a human has already run
   §9.1's activation command in this environment. **This replaces the
   original condition's reference to
   `platform_feature_usage_classifications.conversations.is_metered`,
   which is no longer the activation authority (§3.2, §3.6): the legacy
   classification row's own `is_metered`/`active_rate_id` are never read
   by the corrected `reserve()`/qualifying-send path at all.**
4. The sending Customer's Workspace owns exactly one Business
   (`$business->workspace->businesses()->count() === 1`, §3.5), **and**
   that Business's id equals `conversations_metering.pilot_business_id`
   exactly (§3.6, §3.14) — both conditions, not either.
5. The resolved destination `country_id` equals
   `conversations_metering.pilot_country_id` exactly (§3.6).
6. The resolved `SendingServer`'s id equals
   `conversations_metering.pilot_sending_server_id` exactly, **and**
   that server's `settings` is `TYPE_TWILIO`/`TYPE_TWILIOCOPILOT` (§3.6's
   capability assertion, unchanged).
7. The resolved pilot meter's own `business_id` equals the resolved
   Business's id — a schema-level restatement of condition 4, verified
   independently by `reserve()`'s own `UsageMeterBusinessScopeMismatchException`
   check (§3.14) as defense-in-depth, never the sole enforcement point.
8. The resolved pilot meter's own `currency_id` equals the resolved
   Business's wallet `currency_id` — verified independently by
   `reserve()`'s own `UsageMeterCurrencyMismatchException` check (§3.14),
   likewise defense-in-depth, not the sole enforcement point (in
   practice this can never fail if §3.14's provisioning was followed,
   since the meter's currency is resolved from this exact wallet at
   creation time).

Any send failing any one of conditions 1–2 or 4–8, or for which
condition 3 does not hold, takes the exact same legacy `sms_unit` code
path unconditionally, with zero behavior change — **except** where §6's
idempotency pre-check finds an existing reservation for the same token,
in which case that reservation's own state governs the response
regardless of whether these conditions would otherwise pass or fail.

### 5.2 What MMS/WhatsApp/Viber/OTP/Voice do during M5

Unchanged from the original: unconditionally unchanged legacy behavior
for every channel other than plain/unicode, regardless of
`Conversations`' meter/classification state, forever within M5's own
scope.

### 5.3 How non-qualifying sends remain on existing behavior

Unchanged from the original: strictly additive dispatch, gated on every
§5.1 condition simultaneously (subject to §6's pre-check), zero new
branches/queries/columns for a non-qualifying send.

### 5.4 How later milestones may extend metering without changing M5 history

**Rewritten to correct the stale schema citation and reflect the
meter-scoped extensibility §3.6 establishes.** `business_usage_rates` is
itself immutable and versioned (`UNIQUE(meter_key, version)`, no
`updated_at`) — **the corrected mechanism for a future milestone that
wants to meter a second tuple (a second Business, a second country/
server combination, or a genuinely wider rollout) is to provision a
second, independent `usage_meters` row (§3.14's own pattern) with its
own `meter_key` and its own rate history, under the same `Conversations`
`feature_key` — no new `PlatformFeature` case, no schema widening, and no
collision against the pilot meter's own version history, since
uniqueness is meter-scoped, not feature-scoped.** A future milestone
extending to a wholly different feature would still need its own
`PlatformFeature`/classification decision, unrelated to this mechanism.
Building the missing cross-Business selection mechanism for
multi-Business Workspaces (§3.5) remains a separate, explicit,
out-of-scope product feature. This contract takes no position on which
future milestones should choose — it confirms M5 itself requires none of
them, and that whichever a future milestone picks, M5's own already-
activated pilot meter/rate/classification rows and its own reservation/
ledger history remain untouched and immutable.

---

## 6. Reservation lifecycle — exact, locked order

**Step 0, new, mandatory, evaluated before any §5.1 condition (closes
the §3.13 retry hole):** for any `quickSend()` call carrying
`$conversationContext = true` **and** a present `idempotency_token`
(from `SentRequest`'s validated `idempotency_token` field, §7, or the
retry-token flow §6.1 governs), the business-namespaced reservation
idempotency key (§6.1, unchanged derivation) is computed **first**, and
`BusinessUsageReservationRepository::findByIdempotencyKey()` is checked
**before** any §5.1 condition is (re-)evaluated for this specific
invocation. If a reservation already exists for that key:

- **`Committed`** — no provider call is made. The response is the
  already-sent success outcome (reconstructed exactly as `commit()`'s
  own idempotent repeat-call behavior already does, §3.2 unchanged), and
  the client-side retry token is cleared (§6.1's `'clear'` action).
- **`Pending`** — no provider call is made. The response is a
  still-processing outcome, and the client-side retry token is retained
  (§6.1's `'retain'` action) so a legitimate later retry can resolve the
  same reservation once it reaches a terminal state.
- **`Released`/`Expired`** — no provider call is made **under the same
  token**. The response is a terminal-retry outcome (this specific
  attempt did not complete; the reservation itself is closed), and the
  client-side retry token is cleared (§6.1's `'clear'` action) — a
  genuinely new send requires a genuinely new token, not a further reuse
  of one already bound to a closed reservation.

**This check runs strictly before §5.1's qualifying-condition evaluation
and is unconditional on payload content.** A changed destination
country, `SendingServer`, sender, or message body under the same
logical token **cannot** cause the request to fall through this check
into a fresh §5.1 qualification attempt (and therefore cannot fall
through into the legacy provider path a second time) while a reservation
already exists for that token — the reservation's own state is always
authoritative for a repeated token, independent of whatever the current
request's payload now says. Only when no reservation exists for the
computed key at all does §5.1's own qualifying-condition chain run, as
before.

The remaining steps (qualification, `reserve()` call and
`createdByThisInvocation`-driven atomic-claim check, the
`m5_conversations_usage_tracking` flag set immediately before the
provider call, the provider call itself, and the `$data->m5_outcome`-
driven outcome classification/`commit()`/`release()` dispatch) are
unchanged from the original design and are restated in full by the
§12 allowlist's own file-by-file descriptions.

### 6.1 Idempotency key — business-namespaced, client-sourced, precisely bounded

Unchanged from the original design (derivation, compose-scoped token
lifecycle, the `m5_token_action` `'retain'`/`'clear'` table). **UI
addition, this remediation:** where practical, a retry that surfaces the
still-processing/terminal-retry outcome (§6, step 0) should restore all
relevant send-defining form values the client already had in hand for
that compose action — `sending_server`, `country_code`/destination,
`sender_id`, `recipient`, and `message` — so a legitimate human retry
does not need to re-enter them. **This is a UI convenience only; the
server-side rule in §6 step 0 is authoritative regardless of what the UI
does or fails to restore**, and no server-side decision may ever depend
on the client having restored these values correctly.

### 6.2 Manual ambiguous-reservation resolution — bounded, cannot become a generic mutation tool

Unchanged from the original design — this section concerns operator
resolution of a `Pending` reservation stuck past a provider-outcome
ambiguity window, entirely orthogonal to meter/rate identity, and is
unaffected by Amendment 1.

---

## 7. `quickSend()` discriminator — a trusted PHP parameter, never a request-input key

Unchanged from the original design. `CampaignRepository::quickSend()`
widens to accept a trusted `bool $conversationContext = false` parameter,
passed as a literal `true` only from `ChatBoxController::sent()`/
`::reply()`; never derived from `$input`.

---

## 8. Quantity — no new algorithm

Unchanged from the original design and re-confirmed still supported by
current `main` (§9 below, item 9): `SMSCounter::count()` remains the
authoritative local segment count, computed once and passed identically
to both `reserve()`'s `$estimatedQuantity` and `commit()`'s
`$finalQuantity` — M5 never reconciles a provider-reported segment count
against the pre-send estimate, and never introduces a second quantity
algorithm.

---

## 9. Pricing activation — mechanism authorized, numbers explicitly not

### 9.1 The command and actor-authority mechanism

**Rewritten to add the mandatory meter-provisioning step (§3.14) ahead
of rate activation, and to state the corrected call sequence explicitly
by meter key.**

- A new Artisan console command,
  `app/Console/Commands/ActivateConversationsUsageRate.php`, signature:
  `usage:activate-conversations-rate {retail-rate-micro} {provider-cost-micro} {unit-label} {currency-code} {--actor-user-id=} {--reason=}`
  — unchanged surface from the original design. There is no `{feature}`
  argument and no `{meter-key}` argument: the pilot meter key is
  computed internally from the locked format (§3.14), never accepted as
  free-form operator input, for the same reason the original design gave
  for hard-coding the feature: there is no other value this command's
  own source code can ever legitimately act on.
- **`--actor-user-id=<id>` is required**, validated via the identical
  `users.is_admin` check §3.3 describes (unchanged) — the command aborts
  before touching `UsageMeterRepository` or `UsageWalletManager` if this
  fails.
- The command then, in this exact locked order:
  1. Resolves the pilot Business (`conversations_metering.pilot_business_id`)
     and its wallet's `currency_id` (§3.14). Fails closed if either
     config value is unset or the Business/wallet cannot be resolved.
  2. Computes the locked meter key (§3.14) and reads
     `UsageMeterRepository::findByMeterKey()`. If absent, creates the
     meter with the exact fields §3.14 locks. If present, verifies exact
     identity match and either proceeds (match) or hard-fails writing
     nothing (mismatch) — §3.14's own idempotency/conflict rule.
  3. Prompts for confirmation, displaying the resolved meter key,
     Business, currency, and the human-supplied rate values.
  4. Only on explicit confirmation, calls `setActiveRate($meterKey, ...)`
     then `activateMetering($meterKey, ...)` — in that order, matching
     `activateMetering()`'s own already-enforced mandatory sequencing
     (§3.2, unchanged) — passing the **meter key** computed in step 2 as
     the frozen `$featureKey` parameter of each, per §3.2's own
     clarification that this parameter is now meter-scoped.
  5. Preserves the same transition/audit evidence
     `setActiveRate()`/`activateMetering()` already write internally
     (`business_usage_rate_activations`, `usage_meter_transitions`) —
     the command introduces no separate audit mechanism of its own.
- **The legacy `platform_feature_usage_classifications` row for
  `conversations` is never written by this command** — it is not the
  activation authority (§3.6, §5.1 item 3) and remains exactly as M1
  backfilled it (`is_metered = false`, `active_rate_id = null`),
  confirmed as a required, explicitly tested invariant (§13).
- No auto-discovery registration change needed
  (`app/Console/Kernel.php` already auto-loads `app/Console/Commands/`).
- **This contract does not authorize actually running this command
  against any real environment, and does not supply any numeric value.**
  Unchanged from the original design.

### 9.2 Unresolved human decisions — recorded, not fabricated, both gate go-live

Unchanged from the original design: the exact numeric rate
(`retail_rate_micro`/`provider_cost_micro`/`unit_label`/currency) and
the pilot tuple configuration (`pilot_business_id`/`pilot_country_id`/
`pilot_sending_server_id`) both remain pre-implementation human
decisions, neither fabricated here. **Lifecycle, restated:**
implementation may be completed, tested, and merged entirely against
fixture-only values; `Conversations.is_metered` (on the legacy
classification row, which — per §5.1 item 3 — is no longer even
consulted by the qualifying-send path, but is retained as a
human-facing/reporting signal) and all three pilot config values must
remain unset in every real environment until a human has separately
supplied both sets of values and separately, explicitly run §9.1's
command.

---

## 10. Entitlement vs. wallet denial semantics — corrected, explicit classification

**Rewritten in full, per this remediation's own required classification.**
`EntitlementManager::decide()` remains authoritative, and unchanged in
its own responsibility, for:

- feature availability (`PlatformFeatureRegistry::isAvailable()`);
- plan entitlement;
- workspace/business override decisions
  (`denied_by_workspace_override`/`allowed_by_workspace_override`);
- every one of its existing nine denial-reason keys, unchanged in count
  and meaning (§3.3, `EntitlementManagerNineKeySurfaceUnchangedTest.php`).

`decide()` does **not**, and after this remediation's own audit is
confirmed to never again, deny `Conversations` — or any feature — on
wallet-health grounds (§3.12). Wallet/meter health is a **usage-spending**
concern, evaluated exclusively inside `reserve()` (§3.2), never an
**entitlement** concern. The exact classification, locked:

| Condition | Classification | Where evaluated |
|---|---|---|
| Feature not `Available`, not entitled by plan, denied by workspace override | **Feature/user authorization** | `EntitlementManager::decide()` |
| `usage_meters` row for the pilot key missing, or exists but has no active rate, or `is_metered = false` on that meter | **Metering configuration** — not yet activated, not a denial | `UsageWalletManager::reserve()` (`NoActiveRateForFeatureException`/`UsageMeterNotMeteredException`) |
| Wallet suspended | **Usage spending denial** | `reserve()` (`wallet_suspended`) |
| Outstanding debt | **Usage spending denial** | `reserve()` (`outstanding_debt`) |
| Insufficient balance | **Usage spending denial** | `reserve()` (`insufficient_balance`) |
| Meter `business_id` does not match the resolved Business | **Configuration/integrity failure** | `reserve()` (`UsageMeterBusinessScopeMismatchException`) |
| Meter/wallet currency mismatch | **Configuration/integrity failure** | `reserve()` (`UsageMeterCurrencyMismatchException`) |
| Rate/meter cross-reference mismatch | **Configuration/integrity failure** | `reserve()` (`UsageMeterRateIntegrityException`) |

**No new public `EntitlementManager` denial key is introduced or
required** — every metering-configuration and usage-spending outcome
above is already handled entirely within `UsageWalletManager`'s existing
exception surface, none of which is surfaced through `decide()` at all
(§3.3). A qualifying send that fails one of the lower rows in this table
never reaches `EntitlementManager` a second time — it is caught inside
the `quickSend()` guard chain itself (§6/§7) and the request either
proceeds on legacy behavior or surfaces a plain send-time error, exactly
as any other `reserve()` failure would today for a hypothetical future
metered feature.

---

## 11. Schema

**Rewritten to correct the stale "unmodified since M1" claim and the
incomplete table list.** No new migration, no new table, no new column
is introduced by this contract. Every table M5 touches already exists on
current `main`, but **not** all "unmodified since M1" as the original
text claimed:

- `platform_feature_usage_classifications` — unmodified since M1
  (confirmed, §3.1). Retained as a human-facing/reporting signal only;
  no longer the activation authority (§3.6, §5.1, §9.1).
- `business_usage_rates`, `business_usage_rate_activations` — **modified
  by RFC-005 Amendment 1** (Slices 1 EXPAND and 3 CONTRACT): `feature_key`
  dropped; `meter_key` added and `NOT NULL`; uniqueness is
  `UNIQUE(meter_key, version)`, not `UNIQUE(feature_key, version)`. M5
  writes to these tables exclusively through `setActiveRate()`, which
  already produces schema-correct rows.
- `usage_meters`, `usage_meter_transitions` — **new since M1, added by
  Amendment 1 Slice 1 EXPAND, not mentioned in the original text at
  all.** M5 now depends on these directly: §3.14/§9.1's provisioning
  step creates exactly one `usage_meters` row; `activateMetering()`
  already writes to `usage_meter_transitions` internally (§3.2,
  unchanged mechanism, just now correctly identified as in scope).
- `business_usage_reservations`, `business_usage_ledger_entries` — their
  own schema is unmodified since M1 in the columns M5 touches; both now
  carry a dual-written `meter_key` alongside the pre-existing
  `feature_key` on every row `reserve()`/`commit()`/`release()` write
  (§3.2), a behavior already built into `UsageWalletManager` and not
  something M5's own implementation needs to add.
- `business_usage_wallets` — unmodified since M1.

The guard conditions in §5.1 are evaluated against already-existing
columns/relations plus new **configuration** values (§9.2), not new
schema elements. `ReservationResult`'s new field (§3.8) is a plain PHP
value-object property. `SendCampaignSMS.php`'s `m5_outcome` marker
(§3.9) is a transient, non-persisted Eloquent property.

---

## 12. Exact implementation allowlist

**Re-audited against current `main` in full (item required by this
remediation). Result: the file set is unchanged — still 18 paths — from
the original design.** Every Amendment-1-affected mechanic (meter
resolution, rate versioning, activation prerequisites) routes entirely
through `app/Library/Usage/UsageWalletManager.php`, already item 8
below, and through `UsageMeterRepository`/`EloquentUsageMeterRepository`,
which already exist (merged by Amendment 1) and require no modification
— they are consumed read-only by item 11's corrected internal design.
No new production file is required; no old path is made unnecessary.

**Total mechanically-authorized paths: 18. Stop threshold: 19th path.**
Any path required during implementation but absent from this list is a
stop-and-report condition, not a silent addition.

### Modify (10)

1. **`app/Repositories/Contracts/CampaignRepository.php`** — `quickSend()`
   widens from `quickSend(Campaigns $campaign, array $input)` to
   `quickSend(Campaigns $campaign, array $input, bool $conversationContext = false)`.
   Unchanged from the original design.
2. **`app/Repositories/Eloquent/EloquentCampaignRepository.php`** — the
   same widening; `quickSend()` gains the full guard chain (§5.1, §6
   step 0), the business-namespaced idempotency key derivation (§6.1),
   the `reserve()` call and atomic-claim check (§6), the
   `m5_conversations_usage_tracking` flag, and the outcome-classification
   dispatch. Unchanged from the original design.
3. **`app/Http/Controllers/Customer/ChatBoxController.php`** — `sent()`/
   `reply()` pass the trusted `true` literal; token propagation/
   redirect-decision logic per §6.1. Unchanged from the original design.
4. **`app/Http/Requests/ChatBox/SentRequest.php`** — adds
   `'idempotency_token' => 'required|uuid'`. Unchanged.
5. **`resources/views/customer/ChatBox/new.blade.php`** — hidden
   `idempotency_token` field renders a controller-passed token value.
   Unchanged.
6. **`resources/views/customer/ChatBox/index.blade.php`** — the
   `pendingConversationTokens` map, the `'processing'` response branch,
   and the `m5_token_action`-driven clear/retain logic (§6.1). **This
   remediation additionally requires: where practical, restoring the
   relevant send-defining form values on a still-processing/terminal-
   retry response (§6.1's UI addition)** — sending_server, country
   code, sender id, recipient, message — as a client-side convenience
   only, never a server-side dependency.
7. **`config/usage_billing.php`** — adds
   `conversations_metering.pilot_business_id`,
   `conversations_metering.pilot_country_id`,
   `conversations_metering.pilot_sending_server_id`, all nullable, all
   `null` by default. Unchanged.
8. **`app/Library/Usage/UsageWalletManager.php`** — `reserve()` gains
   the `UniqueConstraintViolationException`-catch-and-verify-then-
   refetch-or-rethrow logic (§3.8). **No other method on this class
   changes** — critically, `evaluateCoarseCapacity()`, `setActiveRate()`,
   and `activateMetering()` are **not** modified by this contract; they
   already behave exactly as §3.2/§3.14/§9.1 require on current `main`.
9. **`app/Library/Usage/ReservationResult.php`** — one new defaulted
   constructor parameter, `createdByThisInvocation` (§3.8). Unchanged.
10. **`app/Models/SendCampaignSMS.php`** — the two Twilio/TwilioCopilot
    case blocks gain the flag-gated `$m5Outcome` assignment and the
    one-line non-persisted attachment (§3.9). Unchanged.

### New (8)

11. **`app/Console/Commands/ActivateConversationsUsageRate.php`** —
    §9.1's operator command: meter provisioning (§3.14) then rate
    activation, both by meter key. Internal design corrected by this
    remediation; file path and count unchanged.
12. **`app/Console/Commands/ResolveAmbiguousUsageReservation.php`** —
    §6.2's manual-resolution command, all six safety checks. Unaffected
    by Amendment 1 (operates on already-written reservation rows, not
    meter/rate identity) — unchanged.
13. **`tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php`** —
    the core metering-lifecycle test cases (§13 below).
14. **`tests/Feature/Usage/QuickSendNonConversationCallersUnaffectedTest.php`** —
    the M5-flag opt-in isolation, forged-input, and five-non-ChatBox-
    caller regression cases.
15. **`tests/Feature/Usage/ActivateConversationsUsageRateCommandTest.php`** —
    meter provisioning (create/idempotent/conflict), rate activation,
    actor-authority, and legacy-classification-untouched assertions
    (§13).
16. **`tests/Feature/Usage/ResolveAmbiguousUsageReservationCommandTest.php`** —
    all six §6.2 checks.
17. **`tests/Feature/Usage/Support/concurrent_conversations_send_runner.php`** —
    real cross-process test-support runner, modeled on the merged
    RFC-005 M4 precedent.
18. **`tests/Feature/Usage/ConversationsConcurrencyTest.php`** — the
    same-token concurrency proof driving item 17's runner.

### Read-only dependencies (relied upon, never modified)

- `app/Library/Usage/UsageWalletManager.php` (every method other than
  item 8's own widening).
- `app/Repositories/Contracts/UsageMeterRepository.php` and
  `app/Repositories/Eloquent/EloquentUsageMeterRepository.php` — item
  11's `findByMeterKey()`/`create()` calls (§3.14).
- `app/Repositories/Contracts/BusinessUsageRateRepository.php` and
  `app/Repositories/Eloquent/EloquentBusinessUsageRateRepository.php`.
- `app/Models/UsageMeter.php`, `app/Models/BusinessUsageRate.php`,
  `app/Models/BusinessUsageRateActivation.php`.
- `app/Library/Entitlement/EntitlementManager.php`,
  `app/Library/Entitlement/RealUsageAuthorizationGateway.php`,
  `app/Library/Entitlement/PlatformFeatureRegistry.php`.
- `app/Jobs/Usage/ExpireStaleUsageReservations.php`.
- `app/Repositories/Contracts/BusinessUsageReservationRepository.php`
  and its Eloquent implementation — item 2's/§6's
  `findByIdempotencyKey()` call.

---

## 13. Required tests

**Rewritten to reflect the corrected meter-based design (this
remediation's own required test groups).** Every test below must run
against the disposable testing database, use only real repository/
manager calls (never a raw insert bypassing `UsageMeterRepository`/
`UsageWalletManager` for anything under test), and report a genuine
positive count.

1. Pilot `usage_meters` row is created by the activation command with
   exactly the locked identity (§3.14): `meter_key`, `feature_key`,
   `business_id`, `currency_id`, `description`.
2. Re-running the activation command against an already-provisioned,
   matching meter is idempotent — no duplicate row, no error.
3. Re-running the activation command against an existing meter whose
   `business_id`/`currency_id`/`feature_key` conflicts hard-fails,
   writing nothing.
4. Meter-local rate creation via `setActiveRate()` correctly uses
   `latestVersionForMeter()` and produces a `business_usage_rates` row
   scoped to the pilot `meter_key`.
5. `activateMetering()` writes a `usage_meter_transitions` row and
   updates `is_metered = true` on the pilot meter.
6. `platform_feature_usage_classifications.conversations` remains
   untouched (`is_metered = false`, `active_rate_id = null`) after the
   full activation command runs.
7. `reserve()` resolves the pilot meter by `meter_key`, and the written
   `business_usage_reservations`/`business_usage_ledger_entries` rows
   carry both `feature_key` and `meter_key` correctly.
8. A definitive-accepted provider outcome commits the reservation
   exactly once.
9. A definitive-rejected provider outcome releases the reservation.
10. An ambiguous provider outcome leaves the reservation `Pending`.
11. Same-token concurrent requests (real OS-process test, item 17's
    runner) yield exactly one provider call and one committed/released
    reservation — the race loser never calls the provider.
12. A changed-payload retry under the same idempotency token cannot
    escape §6 step 0's pre-check into a fresh legacy-path attempt while
    a reservation already exists for that token — covering `Pending`,
    `Committed`, and `Released`/`Expired` prior states.
13. Non-`ChatBox` `quickSend()` callers (bulk Quick Send, contact-group
    welcome SMS, DLR auto-reply, API-triggered sends) remain on legacy
    behavior, unconditionally, regardless of `$conversationContext`.
14. Non-plain/unicode channels (MMS/WhatsApp/Viber/OTP/Voice) remain on
    legacy behavior regardless of metering state.
15. A multi-Business Workspace's `ChatBox` sends remain on legacy
    behavior, never guessing an attribution.
16. Each of §5.1's tuple dimensions (Business, country, `SendingServer`)
    independently gates qualification — a mismatch on any single
    dimension alone routes to legacy.
17. The local `SMSCounter::count()` segment count is the exact value
    passed to both `reserve()` and `commit()` — no provider-reported
    reconciliation occurs.
18. No double charge occurs between legacy `sms_unit` and the RFC-005
    wallet for any single qualifying send.
19. `EntitlementManager::decide()`'s outcome for `Conversations` is
    identical regardless of wallet state, meter existence, or
    `is_metered` value on either the legacy classification row or the
    pilot meter (§3.12/§10) — the coarse gate never denies on these
    grounds.
20. `reserve()`'s own wallet-state denials
    (`wallet_suspended`/`outstanding_debt`/`insufficient_balance`) still
    correctly stop the provider call before any send is attempted.
21. `ActivateConversationsUsageRateCommandTest` and
    `ResolveAmbiguousUsageReservationCommandTest` cover actor-authority
    enforcement (non-admin rejected before any write) for both
    commands.

Every test above must run against the disposable testing database only,
per this repository's own established discipline (`ultimatesms_testing`,
never a production/development database).

---

## 14. Stop conditions

Implementation must stop, leave the working tree unstaged beyond what is
already committed, and report rather than proceed, if:

- Any path beyond §12's 18-entry allowlist (the 19th path) is found
  necessary.
- Any change to `EntitlementManager`, `RealUsageAuthorizationGateway`,
  `PlatformFeatureRegistry`, or any of the nine existing entitlement
  decision keys is found necessary.
- Any change to `UsageWalletManager::evaluateCoarseCapacity()`,
  `setActiveRate()`, or `activateMetering()` beyond item 8's own narrow
  `reserve()` widening is found necessary.
- Any schema/migration change of any kind is found necessary.
- The pilot meter's locked identity (§3.14) cannot be provisioned
  exactly as specified without a conflicting pre-existing row (the
  documented hard-fail path, not a silent workaround).
- The §6 step 0 idempotency pre-check cannot be implemented without a
  schema or `UsageWalletManager` public-method change beyond item 8.
- Any fabricated, copied, inferred, or backfilled meter identity is
  found necessary anywhere.
- `platform_feature_usage_classifications.conversations` is found to
  require any write from the M5 implementation.
- No touching pull request #107 is not applicable here (this document
  *is* PR #107) — restated as: no other PR/branch may be merged or
  modified as a side effect of implementing this contract.

---

## 15. Verification and publication (this document only)

Performed, in order, before commit, this remediation pass:

1. `git status --short` — exactly one modified path, this document,
   nothing else.
2. Stage the one file by its exact path only, never `git add -A`/`.`.
3. Commit with a message identifying this as the post-Amendment-1
   remediation pass.
4. Push normally to `origin chore/rfc-005-m5-contract` (the same branch
   PR #107 already uses) — no force push, no rebase; `origin/main` was
   merged into this branch first, with a normal merge commit, before any
   content edit.
5. PR #107 remains Draft. Do not merge, do not mark ready for review,
   do not modify `docs/automation/AI-AUTONOMY-STATE.json`, do not begin
   implementation.
6. PHP/JS tests are not required for this docs-only change and are not
   run — reported honestly as not run, no count fabricated.

---

*End of RFC-005 Milestone 5 — Metered Feature Classification contract,
remediated against current `main` following RFC-005 Amendment 1's
closure. The former structural blocker (§3.12) is retracted as moot; the
meter-based rate/activation mechanics (§3.2, §3.6, §3.14, §9.1, §11) are
corrected throughout; the same-token retry hole (§3.13, §6) is closed as
a locked rule; the entitlement/wallet-denial classification (§10) is
stated explicitly. `PlatformFeature::Conversations` remains the locked
product selection. This document remains PROPOSED — implementation
requires a separate, later, explicit `AI-AUTONOMY-STATE.json`
authorization after a human merges this remediation.*
