# RFC-005 Reservation Admission Correction Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

This document authorizes drafting one thing: a bounded, independently governed correction to `UsageWalletManager::reserve()`, connecting three already-designed RFC-005 §15 admission controls that were never wired into reservation admission after Milestones 1–5 closed. Human merge of this contract does **not** itself start implementation — a human must separately, explicitly instruct that implementation begin on the branch locked in §15.B below, exactly as every RFC-003/RFC-004/RFC-005 milestone and correction contract before it has required.

This correction exists because RFC-005 Milestone 6's static conformance audit — performed after M1–M5 had already closed and after all six of M6's own locked regression gates passed cleanly — discovered that `reserve()` silently omits three of the eight admission steps RFC-005 §15 and §13 both explicitly lock. M6 itself remains **BLOCKED** under its own merged Gap Rule (`docs/automation/RFC-005-M6-CONTRACT.md` §3) pending this and six other independently governed corrections. This contract is exactly one of those seven; it does not by itself unblock M6.

---

## 0. Governance

- Drafted on branch `chore/rfc-005-reservation-admission-correction-contract`, in an isolated linked worktree (`../rfc-005-reservation-admission-correction-contract-worktree`), based on `origin/main` at `31b16c55c9b2a3cc7fe1a8c34aa738ae348dddb4` — the RFC-005 Milestone 6 contract's own merge commit (PR [#133](https://github.com/os-creator1/os-ai/pull/133)), confirmed the current tip of `main` and confirmed an ancestor of this branch via direct `git rev-parse` before drafting.
- `agent/rfc-005-m6` is confirmed, at drafting time, to be a **local-only branch in the drafting environment — never pushed to `origin`** (`git ls-remote origin` shows no such ref; the branch exists only as a local ref/worktree on this machine). It is confirmed to carry **zero** authored commits and to be byte-identical to `origin/main` (`git diff origin/main agent/rfc-005-m6` empty). No claim is made that GitHub currently hosts a remote `agent/rfc-005-m6` branch. This contract does not touch, reset, or recreate that branch in any way.
- **This is a new, independently bounded pre-M6 correction contract — not a correction round against M1, M2, M5, or M6's own contracts.** Its `maximum_correction_rounds: 2` budget is its own, freshly opened, **0 of 2 consumed** at initial drafting. No counter is borrowed or altered on `RFC-005-M1-CONTRACT.md`, `RFC-005-M2-CONTRACT.md`, `RFC-005-M5-CONTRACT.md`, or `RFC-005-M6-CONTRACT.md`.
- Locked:
  - `human_only_merge: true`
  - `maximum_correction_rounds: 2`
  - `advance_automatically: false`
  - `start_automatically_after_contract_merge: false`
  - `codex_review_required_for_completion: false`
  - `automatic_model_handoff_required: false`
- **This contract does not modify** `docs/automation/AI-AUTONOMY-STATE.json`, `docs/automation/RFC-005-M6-CONTRACT.md`, or `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` — the RFC source is not amended; nothing about it is in contradiction, only unimplemented.
- Precedent used structurally, not copied blindly: `docs/automation/RFC-005-M4-CORRECTION-1.md`/`RFC-005-M4-CORRECTION-2.md` (per-defect section shape, exact allowlist/stop-threshold discipline, explicit non-scope section) and `RFC-005-M5-EXCEPTIONAL-CORRECTION-AUTHORIZATION.md` (a correction contract opened independently of any single milestone's own exhausted round budget).

---

## 1. Repository state verified before drafting

Inspected directly in this exact worktree, at this exact base commit, immediately before writing this contract:

- `app/Library/Usage/UsageWalletManager.php::reserve()` (lines 238–401) checks, in order: `billing_status === Suspended` → `'wallet_suspended'` (line 304); `debt_balance_micro > 0` → `'outstanding_debt'` (line 308); `available_balance_micro < reservedAmountMicro` → `'insufficient_balance'` (line 312). No other check exists between the wallet-period rollover and reservation-row creation.
- `app/Library/Usage/CapEvaluation.php` exists, fully formed (`bool $allowed`, `?string $denialReason`, `?string $remainingHeadroomMicro`), git-blamed to the M2 implementation commit, and is referenced nowhere else in the codebase — confirmed dead.
- `app/Repositories/Contracts/BusinessFeatureUsageLimitRepository.php::findForUpdateByBusinessAndFeature(int $businessId, string $featureKey): ?BusinessFeatureUsageLimit` already exists and is already implemented in `EloquentBusinessFeatureUsageLimitRepository.php` — currently called only by `UsageWalletManager::setFeatureLimit()`.
- `app/Repositories/Contracts/PlatformFeatureUsageSafetyLimitRepository.php::findByFeatureKey(string $featureKey): ?PlatformFeatureUsageSafetyLimit` and `::findForUpdateByFeatureKey(...)` already exist and are already implemented — currently called only by `UsageWalletManager::setSafetyLimit()`.
- `app/Repositories/Contracts/BusinessUsageReservationRepository.php` (full contract read) has no method that aggregates reserved amounts by `business_id + feature_key + period_key`.
- `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` (full contract read) has exactly one method, `create()` — no aggregation method of any kind exists.
- Zero production code anywhere branches on a specific `ReservationResult::$denialReason` string value — confirmed by a full-repository grep; the sole production caller of `reserve()` (`EloquentCampaignRepository.php:760`) checks only `! $result->granted` (line 762).
- All five candidate test files exist: `tests/Feature/Usage/UsageWalletManagerSpendCapTest.php`, `UsageWalletManagerFeatureLimitTest.php`, `UsageWalletManagerSafetyLimitTest.php`, `UsageWalletManagerConcurrencyTest.php`, `ConversationsPlainSmsMeteringTest.php`.

If a future implementation attempt discovers repository reality conflicting with any claim above, that is a STOP-and-report condition — a further correction round against this contract, not a silent workaround.

---

## 2. The confirmed defect — exact, and its exact history

RFC-005 §15 (line 520): *"Evaluation order — unchanged: structural entitlement → Business toggle → `billing_status` → `outstanding_debt` → per-feature limit → Business monthly spend cap → platform safety limit → (reserve path only) available-balance sufficiency."*

RFC-005 §13 (line 433), independently, states the identical order for `reserve()`'s own internal steps: *"evaluate, in order: `billing_status` → `outstanding_debt` → per-feature limit → Business spend cap → platform safety limit → available-balance sufficiency."*

**Structural entitlement and the Business toggle are not this contract's concern** — those are `EntitlementManager::decide()`'s own steps 1–6, already correctly implemented and unaffected by this correction (§3 below). This contract's entire scope is the six steps that occur *inside* `reserve()` itself, of which the current implementation performs exactly three (`billing_status`, `outstanding_debt`, `insufficient_balance`) and omits exactly three (per-feature limit, Business spend cap, platform safety limit). **This is a reserve-time wallet-admission omission, not an entitlement defect** — no entitlement code is incorrect or in scope.

**Exact history, evidence-traced, not reconstructed from memory:**

1. **M1 intentionally deferred all three controls.** `reserve()`'s own docblock (lines 229–236): *"per-feature limit, Business spend cap, and platform safety limit are skipped — their tables do not exist until M2, M1 contract §8 item 1."* Legitimate and correctly scoped at the time — none of the three config tables existed yet.
2. **M2 built the configuration machinery and explicitly named this exact future obligation, but did not connect it.** M2 shipped `business_feature_usage_limits`, `platform_feature_usage_safety_limits`, `setFeatureLimit()`, `setSafetyLimit()`, and authored `CapEvaluation.php` — a value object shaped, by name and by property list, for exactly this admission decision. `docs/automation/RFC-005-M2-CONTRACT.md` line 198 states directly: *"A cap or limit change is prospective only — it changes future reservation-admission evaluation (a capability that has no live caller until M5 activates metering); it never rewrites, reverses, or reinterprets already-committed historical spend."* M2 itself recorded, at implementation time, that this capability would only become consequential once a real caller existed.
3. **`reserve()` was never incrementally widened after M2.** No commit between M2's merge and M5's merge touches this method's admission-check body.
4. **M5 was the first genuine caller and did not close the gap.** M5 activated real metering for the Conversations pilot — the first production invocation of `reserve()` that could ever actually be denied by a cap or limit. `RFC-005-M5-CONTRACT.md`'s full 2322 lines contain zero mentions of "spend cap," "feature limit," "safety limit," or `CapEvaluation` — M5's own scope was meter identity, pilot gating, and response-status mapping only.
5. **M6's static conformance audit is the first process that ever exercised this claim against reality**, discovering it despite all six of M6's own locked regression gates passing cleanly — every existing test legitimately passes because none of them ever asserted the missing behavior.

---

## 3. Entitlement/wallet separation — preserved, not touched

RFC-005 Amendment 1's own design document (`docs/rfcs/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`, line 1243) explicitly freezes `evaluateCoarseCapacity()`'s body as *"the unconditional `return new UsageCapacityDecision(true);` pass-through"* and separately freezes `reserve()`'s own signature (line 1271–1280, naming `reserve()` explicitly in its enumerated frozen-method list) as part of Amendment 1's deliberate decoupling of feature entitlement from wallet health.

**This correction locks, and does not touch:**

- `App\Library\Entitlement\EntitlementManager::decide()` — unchanged, unmodified, not in this contract's path allowlist.
- `App\Library\Entitlement\RealUsageAuthorizationGateway::check()` — unchanged.
- `UsageWalletManager::evaluateCoarseCapacity()` — unchanged; its body remains the unconditional pass-through Amendment 1 froze.

A Business **must** remain able to remain structurally entitled to a metered feature (per `EntitlementManager::decide()`) while a specific `reserve()` call for that same feature is denied purely on reserve-time spending-authority grounds (feature limit, Business spend cap, or platform safety limit). This distinction is the exact one Amendment 1 already established for wallet health generally, extended here to the three new admission controls specifically — not a new architectural decision this contract invents.

---

## 4. Exact admission formulas

### 4.A Business monthly spend cap

Reuses the wallet's own already-correctly-maintained cached counters — no new query needed for this control. **Corrected this round: expressed through explicit, non-negative headroom, not a raw `consumption + candidate > limit` comparison** — the two are not equivalent once a cap has been tightened below already-consumed spend, and a headroom-based formulation is the only one that stays consistent with §5's own zero-candidate rule in every case:

```
if (wallet.monthly_spend_cap_micro !== null):
    currentBusinessConsumption = committed_spend_this_period_micro + reserved_spend_this_period_micro
    businessHeadroom = max(0, monthly_spend_cap_micro - currentBusinessConsumption)
    deny if candidateReservedAmountMicro > businessHeadroom
```

Evaluated **after** the wallet's lazy period rollover (`rollOverPeriodsIfNeeded()`, already the first step of `reserve()`), so the counters are always current-period. `committed_spend_this_period_micro`'s own correctness is already governed, unmodified, by RFC-005 §13's committed-amount formula (`commit()`'s existing, correct implementation) — this correction reads that counter, it does not alter how it is computed or written. `max(0, ...)` exists solely to keep `businessHeadroom` a well-defined non-negative quantity for the comparison above; a negative intermediate value is never itself stored, returned, or treated as a meaningful state anywhere in this design.

### 4.B Per-feature monthly limit — keyed by `feature_key`, aggregated across every `meter_key` sharing it

`business_feature_usage_limits` is keyed by `business_id + feature_key`, **not** `meter_key`. Amendment 1 permits multiple `meter_key`s to share one `feature_key`; `business_usage_reservations` and `business_usage_ledger_entries` both deliberately retained their own `feature_key` column through Amendment 1's meter-key tightening specifically as *"a permanent owning-feature snapshot"* (confirmed by direct migration/docblock read). Per-feature consumption must therefore aggregate by `feature_key`, never by a single `meter_key`.

No cached counter exists at this granularity (only wallet-level aggregates exist), so it is computed live, reusing RFC-005 §13's own committed-amount formula, re-scoped by an additional `feature_key` filter — not a new formula. **Corrected this round, same reason as §4.A: the admission check is expressed through explicit, non-negative headroom, not a raw `consumption + candidate > limit` comparison:**

```
reservedPortion  = SUM(business_usage_reservations.reserved_amount_micro
                        WHERE business_id = X AND feature_key = Y
                          AND period_key = wallet.spend_period_key AND status = 'pending')

committedPortion = SUM over business_usage_ledger_entries
                        WHERE business_id = X AND feature_key = Y AND period_key = wallet.spend_period_key:
                        UsageCharge        -> -reserved_delta_micro
                        UsageOverageCharge -> (-available_delta_micro) + debt_delta_micro

featureConsumption = reservedPortion + committedPortion

if (a business_feature_usage_limits row exists for business_id=X, feature_key=Y, and monthly_limit_micro !== null):
    featureHeadroom = max(0, monthly_limit_micro - featureConsumption)
    deny if candidateReservedAmountMicro > featureHeadroom
```

**No `business_feature_usage_limits` row for this Business+feature = unbounded from this control** (the safety limit, §4.C, may still apply). This is not invented — it mirrors the wallet-level cap's own already-established null-is-unbounded convention exactly.

### 4.C Platform safety limit — a third, independent control over the same consumption figure

`platform_feature_usage_safety_limits` is platform-scoped (one row per `feature_key`, confirmed unique; `max_monthly_limit_micro` is `NOT NULL` when a row exists). It is evaluated against the **identical** `featureConsumption` value computed in §4.B — confirmed by direct read of `setFeatureLimit()`'s own existing bound-check (`app/Library/Usage/UsageWalletManager.php:917-923`), which already compares a proposed *configured* feature-limit value against this same platform ceiling. This control is decisive at reserve-time precisely when no Business feature-limit row exists (§4.B unbounded) or when the Business's own configured limit has not yet been tightened to match. **Corrected this round, same headroom form as §4.A/§4.B:**

```
if (a platform_feature_usage_safety_limits row exists for feature_key = Y):
    safetyHeadroom = max(0, max_monthly_limit_micro - featureConsumption)
    deny if candidateReservedAmountMicro > safetyHeadroom
```

**No safety-limit row for this feature = unbounded from this control.** No default ceiling is fabricated. As in §4.A, `max(0, ...)` only keeps the comparison well-defined — a negative intermediate headroom is never itself a meaningful or externally observable state.

---

## 5. Null/boundary semantics — locked, expressed through headroom

**Corrected this round: every row below is now stated in terms of the `max(0, limit − consumption)` headroom each of §4.A/§4.B/§4.C actually computes, not a raw `consumption + candidate > limit` comparison.** The two are not interchangeable once a limit has been tightened below already-consumed spend — expressing everything through non-negative headroom is what keeps the zero-candidate row true in every case, including that one.

| Condition | Behavior |
|---|---|
| No limit/cap row, or configured value `NULL` | Control skipped entirely (no headroom computation for that control at all) |
| Configured limit/cap `= 0`, any existing consumption `>= 0` | `headroom = max(0, 0 - consumption) = 0` — any positive-amount candidate denied; a zero-amount candidate still allowed (`0 > 0` is false) |
| Candidate exactly equals a positive headroom | **Allowed** — the check is `candidate > headroom`, so equality is never a denial |
| Candidate exceeds headroom by one micro-unit | Denied |
| Limit/cap tightened below already-consumed current-period spend | `headroom` clamps to exactly `0` (never negative); historical `committed_spend_this_period_micro`/`featureConsumption`/ledger rows are never touched or rewritten — only *future* reservations are affected, and only because their own headroom is now `0` |
| Candidate reservation amount `= 0` | **Always allowed by all three controls, unconditionally — including when headroom has clamped to `0`.** Because headroom is always `>= 0` by construction, `0 > headroom` can never be true; this is the direct, load-bearing reason the admission check is expressed as non-negative headroom rather than a raw `consumption + candidate > limit` comparison, which would otherwise deny a zero-amount candidate the instant consumption exceeds a newly tightened limit — a result §5 has never intended and RFC-005 §13's own "prospective only" rule forbids |
| Wallet period rollover | Always performed before any of the three new checks run, exactly as it already runs before the three existing checks |

Negative headroom is never introduced as an externally meaningful state anywhere in this design — `max(0, ...)` exists purely to keep the comparison well-defined; no historical accounting of any kind is altered by this correction.

---

## 6. Denial order and stable denial keys

Locked evaluation order inside `reserve()`, exactly per §13/§15: `billing_status` → `outstanding_debt` → **per-feature limit** → **Business spend cap** → **platform safety limit** → `available_balance`. If multiple controls would independently deny the same candidate reservation, the earliest-listed control's denial reason is returned — later controls are never evaluated once an earlier one has already denied.

No exact string is pre-locked anywhere in RFC-005 or any merged contract for these three controls. Following the exact, unbroken repository convention already used by the three existing keys (`wallet_suspended`, `outstanding_debt`, `insufficient_balance` — flat, snake_case, noun-phrase, no "_exceeded"/"_reached" suffix anywhere in this family), this contract locks, as a human-reviewed governance decision:

- `'feature_limit'` — per-feature monthly limit denial
- `'business_spend_cap'` — Business monthly spend cap denial
- `'platform_safety_limit'` — platform safety limit denial

Each is distinguishable from the other two and from all three existing keys. Confirmed by full-repository grep (§1 above): zero production callers branch on any specific `ReservationResult::$denialReason` string today, so introducing these three keys carries zero production blast radius; only test assertions will reference them.

---

## 7. Idempotency — already correct, preserved unmodified

`reserve()`'s existing `findByIdempotencyKey($idempotencyKey)` lookup (line 240) runs **before** the wallet lock and before any admission check, returning early with the already-created reservation's id whenever a match is found. The three new checks, inserted after this point in the method body, structurally cannot re-run against a retry of an already-successful reservation. This means the scenario the correction must prevent — *successful request → operator tightens a cap → an exact retry of the same request appears newly denied* — cannot occur, as a direct consequence of control flow already in place, requiring no new special-casing. M5's Business-namespaced idempotency-key derivation (`conversationsIdempotencyKey()`) is untouched by this contract. The duplicate-race loser path (`UniqueConstraintViolationException` catch, lines 382–393) is likewise unmodified — it already runs entirely outside the three new checks' reach.

---

## 8. Concurrency and linearization

Reproducing the read-only audit's conclusions exactly, re-verified in this worktree:

- **A. Two same-Business reservations racing final Business-cap headroom.** Already fully serialized: `reserve()` locks the wallet row (`findForUpdateByBusinessId`) for its entire transaction before any admission check; every reservation/ledger write for that Business happens only under that same lock. The second concurrent call only proceeds after the first's transaction fully commits or rolls back, so it observes the first's already-committed state. **No change needed.**
- **B. Two same-Business, same-feature reservations racing feature headroom.** `setFeatureLimit()` already locks the feature-limit row via the existing `findForUpdateByBusinessAndFeature()` (business-scoped, never shared across Businesses); `reserve()` must call this same existing method for its own read. Because the row is Business-scoped, this introduces **zero cross-Business contention** and cannot deadlock in either direction — `setFeatureLimit()` never locks the wallet, so no cycle is possible.
- **C. Same Business/feature racing platform-safety headroom.** `reserve()` must use the existing **plain**, non-locking `findByFeatureKey()` — deliberately, not `findForUpdateByFeatureKey()`. Locking this platform-global row from `reserve()` would serialize every Business's reservations for that feature against one shared row, which is explicitly rejected: a reservation whose transaction is genuinely concurrent with an in-flight `setSafetyLimit()` commit may observe either value at that exact boundary, which is provably acceptable under "prospective only" semantics (any reservation beginning after the setter's commit is guaranteed, by ordinary read-after-commit visibility, to see the new value; only the literally-overlapping instant is racy, and that is inherent to any prospective-only design, not a defect this contract must close).
- **D. Unrelated Businesses.** Fully independent for the spend cap and feature limit (separate wallet rows, separate feature-limit rows); for the shared safety-limit row, a plain read never blocks another reader or a `FOR UPDATE` writer under MySQL's default REPEATABLE READ (confirmed: `config/database.php:59` sets no isolation override) — genuinely zero contention between different Businesses.

**NO SETTER PATH MODIFICATION AUTHORIZED.** `setSpendCap()`, `setFeatureLimit()`, and `setSafetyLimit()` require no change of any kind — all three already lock exactly the row `reserve()`'s own new reads need to interoperate safely with, using methods that already exist. This was verified, not assumed: had any setter required modification to make this correction race-safe, this contract would have stopped drafting and reported the contradiction instead of silently broadening scope (per §11's own stop-condition discipline).

---

## 9. Minimum production path allowlist — every path individually verified necessary

1. `app/Library/Usage/UsageWalletManager.php` — `reserve()` gains the three new checks; a new private helper (e.g. `evaluateCap()`) returning `CapEvaluation`, called three times, is authorized inside this same file. **Verified necessary**: this is the only file containing `reserve()`.
2. `app/Repositories/Contracts/BusinessUsageReservationRepository.php` — one new method aggregating pending `reserved_amount_micro` by `business_id + feature_key + period_key`. **Verified necessary**: confirmed absent from the current 6-method contract (§1).
3. `app/Repositories/Eloquent/EloquentBusinessUsageReservationRepository.php` — the corresponding implementation. **Verified necessary.**
4. `app/Repositories/Contracts/BusinessUsageLedgerEntryRepository.php` — one new method implementing §4.B's committed-formula sum, filtered by `business_id + feature_key + period_key`. **Verified necessary**: confirmed the contract has only `create()` today.
5. `app/Repositories/Eloquent/EloquentBusinessUsageLedgerEntryRepository.php` — the corresponding implementation. **Verified necessary.**

**Removed from the candidate list, verified unnecessary:** no new method is needed on `BusinessFeatureUsageLimitRepository` or `PlatformFeatureUsageSafetyLimitRepository` — `findForUpdateByBusinessAndFeature()` and `findByFeatureKey()` already exist and are already implemented (§1, §8). Neither contract/Eloquent pair is in this allowlist.

**`app/Library/Usage/CapEvaluation.php` is explicitly authorized to remain completely unchanged.** Its existing three-property shape (`allowed`, `denialReason`, `remainingHeadroomMicro`) already matches exactly what all three new checks need; it is *consumed* by the new code in `UsageWalletManager.php`, not edited itself.

No raw `DB::` query is authorized from `UsageWalletManager.php` for either new aggregation — both live inside their owning Eloquent repository, per this repository's own established manager/repository boundary (RFC-005 §24: *"No raw query against any billing table is permitted outside its owning manager and repository"*).

**Explicitly forbidden, with no exception found requiring otherwise:** any controller, any route file, `config/**`, any database migration, any Eloquent model, `App\Library\Entitlement\EntitlementManager`, `App\Library\Entitlement\RealUsageAuthorizationGateway`, `docs/automation/AI-AUTONOMY-STATE.json`, and any dependency/package file.

---

## 10. Explicit non-scope

This correction connects three already-designed M2 admission controls into `reserve()`. It does **not** redesign, and the implementation must not touch:

- the reservation state machine (`pending`/`committed`/`released`/`expired`)
- `commit()`/`release()` behavior or their existing committed-amount formula
- rate snapshots or rate versioning
- Amendment 1 meter identity (`meter_key`, `UsageMeter`, `usage_meter_transitions`)
- any ledger entry-type delta formula
- committed-spend or reserved-spend *historical* semantics — only their *read*, never their *write* path
- the wallet calendar-month rollover algorithm
- overage/debt handling
- auto-recharge
- payer selection or billing contact
- any provider/payment (Stripe) behavior
- M5's Conversations-pilot provider-outcome handling

---

## 11. Test authority — required / not required, individually verified

| Candidate file | Verdict | Why |
|---|---|---|
| `tests/Feature/Usage/UsageWalletManagerSpendCapTest.php` | **REQUIRED** | Currently tests only `setSpendCap()`'s configuration behavior (`test_set_and_clear_spend_cap`, `test_a_cap_below_already_committed_spend_is_allowed_and_does_not_touch_history`, etc.) — zero existing test asserts `reserve()` is actually denied by an exceeded cap. New test methods must be added to this file. |
| `tests/Feature/Usage/UsageWalletManagerFeatureLimitTest.php` | **REQUIRED** | Same gap as above, for the per-feature limit — including the Amendment-1-critical proof that consumption aggregates across every `meter_key` sharing one `feature_key`, and that a different `feature_key` remains fully independent. |
| `tests/Feature/Usage/UsageWalletManagerSafetyLimitTest.php` | **REQUIRED** | Same gap, for the platform safety limit — including the case where it is the *sole* decisive control (no Business feature-limit row configured). |
| `tests/Feature/Usage/UsageWalletManagerConcurrencyTest.php` | **REQUIRED** | New forced-race scenarios per §8.B/8.D: two same-Business/same-feature reservations racing final feature headroom must resolve to exactly one winner; unrelated Businesses must remain provably independent. |
| `tests/Feature/Usage/ConversationsPlainSmsMeteringTest.php` | **REQUIRED** | Proves the real M5 production path: a denied qualifying send (via any of the three new controls) reaches no provider call and creates no reservation/ledger mutation — mirroring the pattern this file already proves for `insufficient_balance`. |

The implementation must prove, at minimum, everywhere applicable across these five files: each of the three controls independently denies `reserve()`; the exact §6 denial-order precedence when multiple controls would deny simultaneously; a candidate exactly equal to positive headroom is allowed and a candidate one micro-unit above headroom is denied (§5); no-row/`NULL` skips that control; a cap/limit tightened below already-consumed spend clamps that control's headroom to exactly `0` — never negative — so historical counters are never touched while a zero-amount candidate remains allowed and any positive-amount candidate is denied (§5); feature-usage aggregation spans every `meter_key` sharing a `feature_key`, while a different `feature_key` remains independent; current-period isolation (a stale prior-period reservation does not pollute new-period admission); a released or expired reservation reopens the headroom it previously consumed; committed usage (including overage) consumes future headroom exactly per the existing §13 formula; an idempotent retry never consumes headroom twice; a same-Business race cannot oversubscribe any control; unrelated Businesses remain fully independent. No test may duplicate proof an existing test already supplies exactly.

---

## 12. Implementation test gates

Required, at minimum, before this correction's own implementation PR is considered ready for human merge:

```
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan migrate:fresh --env=testing
C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test tests/Unit/Usage tests/Feature/Usage
git diff --check
```

`migrate:fresh` is required as environment hygiene (establishing a clean baseline before the focused suite runs) despite this correction introducing **no schema change of any kind** — consistent with the standard practice already used before every prior M1–M5 test run in this engagement. No live or provider network call is authorized or required at any point. This correction's own gates are the **focused Usage suite only** — matching every prior M1–M5 correction round's own convention of a focused gate for the correction's own merge, with the full aggregate regression reserved for M6 alone. **This correction's implementation test gate does not replace, satisfy, or substitute for M6's own six locked regression gates**, which will be rerun in full from the fully-corrected `main` only after this and every other pre-M6 remediation is merged.

---

## 13. Relationship to the other six planned remediations

This contract authorizes **Reservation Admission only** — the first of seven independently governed pre-M6 remediations identified by M6's static conformance audit. It does **not** authorize, and has no bearing on:

- Funding Provider-Flow correction
- Receipt Boundary correction
- Job/Event Dispatch Completion
- Admin Usage Billing Surface correction
- Provider Refund/Dispute Outcome Handling
- RFC-005 §35 Test-Coverage Completion

Each remains a separate, future, independently authorized governance object. **Merging and implementing this correction alone does not unblock RFC-005 Milestone 6.** M6 remains frozen until every required remediation is merged and a fresh, full static conformance audit passes with zero unresolved gaps.

---

## 14. M6 resumption rule

This contract does not touch, reset, recreate, or resume `agent/rfc-005-m6` in any way. After **all** separately-authorized pre-M6 corrections — this one and the remaining six — are merged: discard the zero-commit `agent/rfc-005-m6` branch; recreate it fresh from the fully-corrected `main`; repeat the full static conformance audit from scratch (not merely re-check the items this contract addresses); rerun all six of M6's own locked regression gates; only then write `docs/automation/RFC-005-M6-CONFORMANCE.md` and the RFC-005 deployment guide.

---

## 15. Exact file scope — this governance PR versus the future implementation PR

**Corrected this round: the prior text conflated this contract-drafting PR's own scope with the future implementation PR's scope, producing an apparent self-contradiction against §9/§11's own allowlists. The two are distinguished explicitly below.**

**A. This governance-contract PR** (`chore/rfc-005-reservation-admission-correction-contract`) may change **exactly one file**: `docs/automation/RFC-005-RESERVATION-ADMISSION-CORRECTION-CONTRACT.md`. It may not touch `app/**`, `tests/**`, `database/**`, `routes/**`, `config/**`, `resources/**`, or any other path — this PR is documentation only.

**B. The future implementation PR** — created only on `agent/rfc-005-reservation-admission-correction`, only after this contract is human-merged, and only after a *separate*, explicit human instruction to begin (§0) — may change **only** the exact paths locked in §9 (five production paths) and §11 (five test paths), and nothing else. `app/**`/`tests/**` are not blanket-forbidden to that future PR; they are forbidden **everywhere except** those ten named paths.

**C. The following remain forbidden to the future implementation PR as well, with no narrower exception anywhere in this contract** (a human-reviewed correction round against this same contract, or a new separately-authorized governance document, is required before any of these may be touched):

- `docs/automation/AI-AUTONOMY-STATE.json`
- `docs/automation/RFC-005-M6-CONTRACT.md`
- `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` (the governing RFC source)
- any other existing RFC-005 M1–M6 contract, closure, or Amendment 1 governance document
- any database migration
- any route file
- any `config/**` file
- any `resources/**` file
- any dependency, package, or workflow file
- any `app/**` or `tests/**` path **not** explicitly named in §9 or §11

---

## Forbidden governance / automation (summary)

- No automatic implementation start, ever, from this document alone.
- No automatic merge.
- No force push. No direct push to `main`.
- No paid model API or usage-credit requirement.
- No Codex completion requirement. No automatic model handoff.
- No tag of any kind — RFC-005 tagging remains exclusively M6's own eventual, separately-authorized concern.
- No activation of the Conversations pilot or any other real-environment meter/rate/pilot-tuple mutation.
- No resolution of any RFC-005 §39 open commercial/product decision.
- No implementation of any of the other six planned remediations.
- No resumption, reset, or recreation of `agent/rfc-005-m6`.
- `advance_automatically` remains `false`. `start_automatically_after_contract_merge` remains `false`.

**Implementation is not authorized under this document until it is human-reviewed and merged, and even then only after a separate, explicit human instruction to begin.**
