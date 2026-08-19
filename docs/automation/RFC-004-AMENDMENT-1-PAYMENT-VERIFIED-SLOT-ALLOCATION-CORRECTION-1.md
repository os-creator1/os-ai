# RFC-004 Amendment 1 — Correction Round 1

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This document authorizes drafting itself only.** Merging it authorizes
exactly three narrowly scoped corrections to implementation PR #98
(`agent/rfc-004-amendment-1-slot-allocation`, head
`3775a13ebb76e5d79332ebe309ab001dd711757b` at the time this document was
drafted) — a defect in
`EntitlementManager::allocateAdditionalBusinessSlotsFromVerifiedPayment()`'s
own idempotency comparison, a fixture defect in three new tests, and one
schema-test update required by the amendment's own already-authorized
migration — to be made as their own separate, later, explicitly bounded
correction commit on PR #98 itself. Merging this document does **not**
itself change any `app/`, `database/`, `routes/`, `config/`, or `tests/`
file, and does not itself re-run or satisfy the §12 gates — that remains the
correction commit's own, later, separate obligation (§7).

---

## 0. Governance

- Drafted on branch `chore/rfc-004-amendment-1-correction-1`, based on
  `origin/main` at `365bfdf96006f27dce2adb1087f7a1171af80feb` (merge of PR
  #97, the RFC-004 Amendment 1 contract itself) — `origin/main` has not
  moved since.
- **This is Correction Round 1 of a maximum of 2**, matching the original
  Amendment 1 contract's own `maximum_correction_rounds: 2` (Amendment 1
  §0), which this document does not change. One further correction round
  remains available to Amendment 1's implementation after this one, should
  it be needed; a third would require its own new, separately authorized
  document.
- This document amends only Amendment 1's own §11 allowlist and §14 stop
  threshold, in the two narrow ways §3–§5 below state exactly. Every other
  section of Amendment 1 — §4 (authority boundary), §5 (method signature),
  §6 (schema change itself), §7 (payment-proof requirements), §8
  (idempotency/replay *semantics*, as opposed to their broken
  implementation), §9 (locking/mutation order), §10 (failure table), §13
  (non-scope statement) — is **unchanged and remains fully in force**. This
  document corrects an implementation defect and a test-scope gap; it does
  not revise the contract's own design.
- The three issues corrected here were discovered by the required PR #98
  validation run (§12 gates executed against the disposable
  `ultimatesms_testing` database, gate 1 — `tests/Unit/Entitlement
  tests/Feature/Entitlement` — failing with 6 of 295 tests failing). Each
  finding below is reproduced from that validation run, not re-derived.

---

## 1. Correction 1 — idempotency comparison defect

**Finding, verbatim from validation:** `allocateAdditionalBusinessSlotsFromVerifiedPayment()`
evaluates a replay by recomputing `$toCount` from the Workspace's *current*
`additional_business_slots` — which, on a true replay, is already the value
the first call itself wrote. Recomputing `$toCount = $fromCount +
$additionalSlotsToAdd` from that already-mutated `$fromCount` therefore
produces a value one delta higher than what the first call actually
recorded, so the comparison against the existing transition's own
`to_additional_business_slots` (Amendment 1 §8 case 3) can never match. A
genuine replay of the identical logical payment event incorrectly throws
`PaymentAllocationIdempotencyConflictException` instead of returning the
current assignment unchanged, exactly the failure two tests
(`test_true_replay_is_idempotent_and_writes_no_second_row_or_event`,
`test_repeated_calls_with_the_same_key_serialize_and_the_second_is_idempotent`)
exposed.

**Required correction semantics — exact:**

- A genuine replay must be evaluated by comparing this call's own facts
  against **what the recorded transition itself already says happened**,
  never by re-deriving an expected outcome from the Workspace's current
  (already-mutated) state. The recorded transition row is the durable
  source of truth for "what the first call actually did"; the current
  assignment row is not a reliable stand-in for it once that first call has
  already run.
- Same `payment_idempotency_key` + same Workspace + same
  `requesting_customer_user_id` + same originally authorized allocation
  facts (i.e., the delta/resulting count the *first* call actually recorded,
  not a delta recomputed against the Workspace's present state) → idempotent
  success: return the current assignment unchanged, write no new transition
  row, dispatch no new event — exactly Amendment 1 §8 case 3's own required
  outcome, now reachable.
- Mismatched reuse of the same key for a genuinely different logical event
  (different Workspace, different requesting customer, or a different
  originally-authorized allocation outcome than what is recorded) must
  still throw `PaymentAllocationIdempotencyConflictException` — Amendment 1
  §8 case 4 and §10's "mismatched"/"stale" row are unchanged by this
  correction.
- The concurrency guarantee (Amendment 1 §8 point 5 — the idempotency-key
  lookup happening after the Workspace row lock, serializing concurrent
  calls for the same Workspace; the unique index as the cross-Workspace
  backstop) is **unchanged** by this correction. This is a comparison-logic
  fix only, not a locking or transaction-boundary change.
- **Authorized path for this correction: `app/Library/Entitlement/EntitlementManager.php` only** —
  already item 2 of Amendment 1 §11's allowlist. This correction is a
  behavior fix confined to the one method Amendment 1 §5 already authorizes;
  it does not add a new method, a new parameter, a new column, or a new
  file. `setAdditionalBusinessSlots()` remains untouched, exactly as
  Amendment 1 §11 item 2 already requires.
- The exact comparison mechanics (e.g., whether the correction reads the
  recorded transition's own `from_additional_business_slots`/
  `to_additional_business_slots` pair directly, or another equivalent
  formulation of "what the first call recorded") are the correction
  commit's own implementation detail, bound by the semantics above and by
  every existing Amendment 1 §9/§10 requirement — not fixed further by this
  document.

---

## 2. Correction 2 — test fixture defect (no product behavior change)

**Finding, verbatim from validation:** three of the new tests in
`tests/Feature/Entitlement/EntitlementManagerAdditionalSlotsTest.php` —
`test_empty_idempotency_key_is_rejected`, `test_empty_provider_reference_is_rejected`,
`test_non_positive_delta_is_rejected` — each build their Workspace fixture
via `assignedWorkspace(WorkspacePlanTier::Core, false, 0)` (non-complimentary)
without first calling the file's own existing `definePricing()` helper.
`assignFirstPlan()`'s own existing, unmodified non-complimentary base-pricing
check (Amendment 1 §3 finding 1's own re-confirmation; unrelated to this
amendment) therefore throws `UndefinedPlanPricingException` during fixture
setup, before `allocateAdditionalBusinessSlotsFromVerifiedPayment()` — the
method each of these three tests actually intends to exercise — is ever
called. This is a defect in the three tests' own fixtures, not in any
product code.

**Authorized correction — exact:** add a call to the file's own existing
`definePricing(WorkspacePlanTier::Core)` helper (or an equivalent minimal
fixture correction limited to defining catalog pricing before the
non-complimentary workspace is created) to each of these three tests only,
so that fixture setup succeeds and each test reaches the evidence-shape
check it was written to validate. **No other test in this file, and no
other line, may change.** No product behavior changes as a result of this
correction — it is a test-fixture-only fix.

- **Authorized path for this correction: `tests/Feature/Entitlement/EntitlementManagerAdditionalSlotsTest.php` only** —
  already item 10 of Amendment 1 §11's allowlist (the correctly-named
  equivalent Amendment 1 §11 item 10 itself anticipated, since
  `EntitlementManagerAdditionalBusinessSlotsTest.php` did not exist at
  implementation time).

---

## 3. Correction 3 — allowlist omission: pre-existing schema-shape test

**Finding, verbatim from validation:** `tests/Feature/Entitlement/WorkspaceEntitlementTransitionSchemaTest.php`
is a pre-existing test (not created or modified by Amendment 1's
implementation) whose `test_exact_columns_and_nullability` method asserts
the **exact, complete** column list of `workspace_entitlement_transitions`.
Amendment 1 §6's own additive migration — already authorized, already item
1 of §11's allowlist, and not itself in question — necessarily changes that
table's exact column list by adding `requesting_customer_user_id` and
`payment_idempotency_key`. This pre-existing test was not named anywhere in
Amendment 1's own §3 repository audit or §11 allowlist, so it now fails for
a reason squarely inside Amendment 1's own intended migration effect, yet
touching it was not authorized — precisely the "twelfth path" scenario
Amendment 1 §14's own first stop condition names. Amendment 1's own §3
audit did not discover this file's coupling to the table's exact shape; that
is the gap this correction closes.

**Authorized correction — exact:**

- Add exactly one new path to Amendment 1 §11's allowlist:
  `tests/Feature/Entitlement/WorkspaceEntitlementTransitionSchemaTest.php`
  (existing file, modified) — **item 12**. Amendment 1 §11's allowlist is
  hereby **12 items, not 11**; its own closing sentence ("Total: 11 files
  … No unrelated path may be authorized") is superseded by this document to
  read 12 files, with no other addition.
- This file may be corrected **only** to update
  `test_exact_columns_and_nullability`'s own expected column list (and, if
  structurally necessary for the same test, its nullability-expectation map)
  to include exactly the two already-contracted additive columns —
  `requesting_customer_user_id` and `payment_idempotency_key` — in their
  actual migrated position and nullability (Amendment 1 §6: both nullable;
  `requesting_customer_user_id` positioned after `actor_user_id`;
  `payment_idempotency_key` positioned after `reason`). **No other
  assertion, test method, or line in this file may change.** In particular,
  `test_no_updated_at_column_exists`, `test_audit_identity_columns_have_no_foreign_keys`,
  `test_exactly_three_foreign_keys_all_restrict`,
  `test_composite_workspace_id_created_at_index_exists_in_order`, and
  `test_no_redundant_workspace_id_only_index_exists` are unaffected by
  Amendment 1's migration (it adds no foreign key, no index, no
  `updated_at`) and must remain byte-for-byte unchanged — a diff to any of
  them is itself a stop-and-report condition under Amendment 1 §14 as
  amended by §4 below.
- **No other pre-existing test file, anywhere in the repository, is
  authorized to change by this document.** If the correction commit
  discovers that some other pre-existing test outside
  `tests/Unit/Entitlement`/`tests/Feature/Entitlement`/`tests/Unit/Workspace`/`tests/Feature/Workspace`
  is also coupled to this migration's exact shape, that is itself a new
  stop-and-report condition — not something this document's item-12
  authorization extends to cover.

---

## 4. Updated stop threshold

Amendment 1 §14's first stop condition — "Any path beyond §11's
eleven-item allowlist is found necessary — the twelfth path" — is amended
to read: **any path beyond §11's now-twelve-item allowlist (as amended by
§3 above) is found necessary — the thirteenth path.** Every other Amendment
1 §14 stop condition is restated unchanged and remains fully in force,
including (non-exhaustively, for emphasis given this round's own findings):
`setAdditionalBusinessSlots()`'s signature/body/authorization check
requiring any change; the two `workspace_entitlement_transitions` columns
being found insufficient; any RFC-005-owned file, table, or namespace being
found necessary to reference; any test in the four named directories
failing for a reason not fixable within the (now twelve-item) allowlist;
any §12 regression count landing lower than its stated baseline; or any
genuine need to expose the new method to an HTTP surface.

---

## 5. Disposition of the local Workspace-gate failure (not a code correction)

The same validation run's gate 2
(`php artisan test tests/Unit/Workspace tests/Feature/Workspace`) failed
with 200 of 779 tests failing, root-caused to `public/js/core/theme-tokens.js`
being absent from this machine's built assets and absent from its
`public/mix-manifest.json`. This amendment's implementation touches zero
frontend/JS/Mix files (Amendment 1 §11's allowlist contains no such path,
and PR #98's actual diff contains none). **This is recorded as a local
environment/build-state issue, not an Amendment 1 code defect, and this
document authorizes no code correction for it.**

The subsequent validation pass **may** rebuild frontend assets locally
(e.g., `npm run dev`/`npm run production` against `webpack.mix.js`) purely
to restore this machine's expected test-running environment so that gate 2
can be evaluated meaningfully. **Any resulting generated asset changes
(`public/js/**`, `public/mix-manifest.json`, or any other build output) must
not be committed to PR #98** unless separately, explicitly authorized —
they are local build artifacts, not part of this amendment's authorized
path set (§11), and committing them would itself be a stop-and-report
"unrelated path" condition.

---

## 6. Required re-verification

The full Amendment 1 §12 regression gate set must be run again, in full,
against the disposable `ultimatesms_testing` database after the correction
commit described in §1–§3 above is pushed to PR #98:

1. `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement` —
   zero failures required; the exact passing count is reported by that run,
   not assumed here (Amendment 1 §12 item 1's own baseline arithmetic — 278
   plus the new test methods this amendment's implementation added — still
   applies as the floor).
2. `php artisan test tests/Unit/Workspace tests/Feature/Workspace` — must
   report the same passing count Amendment 1 §12 item 2 requires, once the
   local build-state issue in §5 above is independently resolved (or, if it
   cannot be resolved in the environment performing re-verification, that
   gate's result must be reported honestly as blocked by the named
   environment issue — never fabricated, and never silently skipped).
3. `php artisan test --stop-on-failure` — must exit `0`.
4. A dedicated re-confirmation that `setAdditionalBusinessSlots()`'s own
   existing non-admin-actor authorization tests still pass unmodified,
   verbatim — Amendment 1 §12 item 4, unchanged.

No PHP/JS tests are run for **this** docs-only correction document itself,
matching Amendment 1 §16.8's own precedent — reported honestly as not run,
no count fabricated.

---

## 7. Explicit non-scope statement

This correction document authorizes exactly the three corrections in §1–§3
and nothing else. It explicitly does **not**:

- authorize any change to `setAdditionalBusinessSlots()`, its authorization
  check, or its own existing tests;
- authorize any new migration, table, column, enum, event, route,
  controller, or view;
- authorize any RFC-005-owned file, table, or namespace to be referenced;
- authorize any change to any test outside the two named files in §1–§3;
- authorize any commit of generated frontend/build assets to PR #98 (§5);
- begin, draft, or authorize RFC-005 Milestone 4 in any way — Amendment 1
  §13's own non-scope statement is unchanged and fully restated here by
  reference;
- extend `maximum_correction_rounds` beyond the 2 Amendment 1 §0 already
  set — this document consumes round 1 of that existing budget, it does not
  grant a new one.

---

## 8. Contract self-audit

1. All three validation-identified issues are addressed by name, with exact
   required correction semantics for each (§1–§3). ✓
2. Each correction is confined to an already-authorized Amendment 1 §11
   path, except the one explicitly new item-12 addition, itself named,
   scoped to exactly two lines' worth of expected-column-list content, and
   justified by direct reference to the migration that necessitates it
   (§3). ✓
3. The allowlist expansion is exact (11 → 12, one named path only) and the
   stop threshold is updated to match (12 → 13) (§3, §4). ✓
4. No correction authorized here weakens, relaxes, or bypasses any
   Amendment 1 invariant — `EntitlementManager` remains sole allocation
   authority, no admin actor is fabricated, `setAdditionalBusinessSlots()`
   remains untouched, no RFC-005 dependency is introduced. ✓
5. The unrelated local Workspace-gate failure is recorded and disposed of
   without authorizing any code change for it, and generated-asset commits
   to PR #98 are explicitly prohibited absent separate authorization (§5).
   ✓
6. Re-verification via the full §12 gate set is required, not assumed, and
   no test count is fabricated by this document itself (§6). ✓
7. This document itself changes exactly one file, verified mechanically
   before commit (§9). ✓
8. `maximum_correction_rounds: 2` is preserved, not extended; this is
   explicitly recorded as round 1 of that existing budget (§0, §7). ✓

---

## 9. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path,
   `docs/automation/RFC-004-AMENDMENT-1-PAYMENT-VERIFIED-SLOT-ALLOCATION-CORRECTION-1.md`,
   nothing else, nothing staged.
2. Stage the one file by its exact path only
   (`git add docs/automation/RFC-004-AMENDMENT-1-PAYMENT-VERIFIED-SLOT-ALLOCATION-CORRECTION-1.md`),
   never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize RFC-004 Amendment 1 correction round 1`.
4. Push normally to `origin chore/rfc-004-amendment-1-correction-1`. No
   force push. Do not push `main`.
5. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
6. **Do not merge. Do not begin the correction this document authorizes.**
   Both require this document to be merged first; implementing §1–§3
   against PR #98 remains its own separate, later, explicitly bounded
   commit on that existing PR — not a new PR.
7. PHP/JS tests are not required for this one-file docs-only change and are
   not run — reported honestly as not run, no count fabricated.

---

*End of RFC-004 Amendment 1 — Correction Round 1. This document authorizes
drafting itself only. The three corrections it specifies require their own
separate, later, explicitly bounded correction commit on PR #98 (§1–§3),
after which the full §12 gate set must be run again in full (§6). Amendment
1's own tagged foundation
(`rfc-004-plans-and-business-feature-entitlements`) and its own contract
(merged in PR #97) are unmoved and unmodified by this document.*
