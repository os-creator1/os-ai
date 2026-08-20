# RFC-004 Amendment 2 — Correction Round 1

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

**This document authorizes drafting itself only.** Merging it authorizes
exactly two narrowly scoped corrections to Amendment 2's own §11 allowlist
and §14 stop threshold — a repository-persistence gap in
`updateCatalogPricing()`'s catalog-mutation path, and two omitted
old-signature call-site files — to be made as part of that same,
still-unimplemented, explicitly bounded implementation once this document
is merged. **No implementation commit exists yet for Amendment 2**: the
attempt correctly stopped at §14's own stop condition before committing or
pushing anything, and reported the discrepancy for explicit human
authorization rather than silently expanding scope. Merging this document
does **not** itself change any `app/`, `database/`, `routes/`, `config/`,
or `tests/` file, and does not itself re-run or satisfy the §12 gates —
that remains the corrected implementation's own, later, separate
obligation (§4).

---

## 0. Governance

- Drafted on branch `chore/rfc-004-amendment-2-correction-1`, in an
  isolated linked worktree (`../rfc-004-amendment-2-correction-1-worktree`),
  based on `origin/main` at `914d5fc90b9fa62028577009d1d33fcd1384d2a4`
  (merge of PR #100, the RFC-004 Amendment 2 contract itself) —
  `origin/main` has not moved since.
- **This is Correction Round 1 of a maximum of 2**, matching Amendment 2's
  own `maximum_correction_rounds: 2` (Amendment 2 §0), which this document
  does not change. One further correction round remains available to
  Amendment 2's implementation after this one, should it be needed; a third
  would require its own new, separately authorized document.
- This document amends only Amendment 2's own §11 allowlist and §14 stop
  threshold, in the two narrow ways §A–§B below state exactly. Every other
  section of Amendment 2 — §4 (authority boundary), §5 (method signature),
  §6 (validation/invariants), §7 (locking/concurrency), §8 (durable
  audit/schema), §9 (stable-price boundary), §10 (failure behavior), §13
  (non-scope statement) — is **unchanged and remains fully in force**. This
  document corrects two repository-audit gaps; it does not revise the
  amendment's own design.
- **The two issues corrected here were discovered by the implementation
  attempt itself** (not yet committed or pushed — no PR exists for
  Amendment 2's implementation), which correctly hit §14's own stop
  condition ("any path beyond §11's now-twelve-item allowlist... is found
  necessary") rather than silently proceeding, and reported both findings
  for explicit human authorization before any commit was made. Each finding
  below is reproduced from that implementation attempt, not re-derived.
- The implementation's working tree, in the isolated worktree
  `../rfc-004-amendment-2-catalog-pricing-operator-impl-worktree` on branch
  `agent/rfc-004-amendment-2-catalog-pricing-operator`, remains exactly as
  left when the stop condition was hit: the original 9 allowlisted paths
  modified/created, nothing staged, nothing committed, nothing pushed. This
  document does not touch that branch or worktree in any way (§7).

---

## A. Correction A — repository persistence assumption was incorrect

**Finding, verbatim from the implementation attempt:** Amendment 2 §3 item
6 claimed extending `updateCatalogPricing()` to also persist
`additional_business_slot_price_ratio` "requires no repository change,"
describing `WorkspacePlanCatalogRepository`/`EloquentWorkspacePlanCatalogRepository`
as plain, unrestricted data access. Direct read of the actual Eloquent
implementation during the implementation attempt found this false:

```php
public function update(WorkspacePlanCatalog $catalog, array $attributes): WorkspacePlanCatalog
{
    $catalog->fill(Arr::only($attributes, [
        'price',
        'currency_id',
        'is_active',
    ]));
    $catalog->save();

    return $catalog;
}
```

`Arr::only()`'s hardcoded allowlist silently discards any
`additional_business_slot_price_ratio` key passed in `$attributes` —
confirmed directly: a ratio value passed through this path never persists,
the column reads back unchanged.

**Required correction — exact:**

- Add exactly one string, `'additional_business_slot_price_ratio'`, to the
  existing `Arr::only()` array above. **No other line in this method, and
  no other method on this class, may change.**
- **A direct Eloquent/model-save workaround inside `EntitlementManager`
  (setting `$updated->additional_business_slot_price_ratio` directly on the
  model and calling `->save()` in place of the repository call) is
  explicitly *not* authorized as a permanent solution and must not remain
  in the implementation.** The implementation attempt used exactly this
  workaround to keep the change inside the original 9-path allowlist while
  the repository was believed off-limits; that reasoning no longer applies
  once this document authorizes the repository path directly. Once this
  document is merged, that workaround must be **replaced** by the
  repository allowlist correction above — `EntitlementManager` must
  continue calling `WorkspacePlanCatalogRepository::update()` for the
  entire catalog mutation (`price`, `currency_id`, and now
  `additional_business_slot_price_ratio` together, in one call), exactly as
  Amendment 2 §5/§7 originally intended, with no separate model-level save
  step.
- **Authorized path for this correction:**
  `app/Repositories/Eloquent/EloquentWorkspacePlanCatalogRepository.php` —
  new item 10 of Amendment 2 §11's allowlist. This is a data-access-only
  change (widening one existing allowlist array) — it adds no new method,
  no business-rule logic, no invariant enforcement to this class, matching
  the class's own existing docblock ("no pricing-invariant/business-rule
  logic here — enforced exclusively by `EntitlementManager`") exactly.
  `WorkspacePlanCatalogController` and `CurrencyRepository` remain
  untouched and out of scope, as Amendment 2 §11/§13 already state.

---

## B. Correction B — two executable old-signature call-site files were omitted

**Finding, verbatim from the implementation attempt:** Amendment 2 §5's own
audit claimed "only one call site exists to update" for
`updateCatalogPricing()`, confirmed (it claimed) by "the same
repository-wide search that found no controller/route caller." A repository-wide
grep for `updateCatalogPricing(` performed during the implementation
attempt, after the originally-allowlisted test file was already updated and
passing, found **two further real, executing call sites** still using the
old four-argument signature, neither named anywhere in Amendment 2 §3 or
§11:

1. `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php` — four
   call sites across two test methods
   (`test_scenario_6_catalog_clear_vs_paid_assign_first_plan`,
   `test_scenario_7_catalog_clear_vs_revoke_complimentary`). Confirmed by
   an actual gate run: both tests fail with `ArgumentCountError` once the
   signature widens, exactly as the missing-argument mismatch predicts.
2. `tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php`
   — one call site (the `catalog-clear` CLI case), a subprocess helper the
   concurrency tests shell out to as a genuinely separate OS process to
   exercise real database-level locking. Not yet reached by a gate run
   (Gate 1's own regression stopped first on the two `ArgumentCountError`
   failures above), but confirmed by direct read to use the identical old
   signature and therefore subject to the identical break.

**Required correction — exact, for both files, mechanical migration only:**

- Update every existing `updateCatalogPricing()` call site in both files to
  the new six-argument signature (Amendment 2 §5):
  - **Preserve existing price/currency behavior** — the `$price` and
    `$currencyId` arguments passed at each call site are unchanged.
  - **Pass `null` for `$additionalBusinessSlotPriceRatio`** at every one of
    these call sites — none of them is testing or exercising ratio
    behavior; introducing a non-null ratio at any of these sites would be
    an uncontracted behavior change, not a mechanical migration.
  - **Pass a deterministic, non-empty string for `$reason`** at every call
    site (Amendment 2 §5/§6 — mandatory, non-empty). The exact literal
    string is the correction commit's own implementation detail; it must
    be fixed/deterministic (not randomly generated), consistent with every
    other fixture `reason` string already used elsewhere in these two
    files.
  - **No assertion in `EntitlementManagerConcurrencyTest.php` may change**,
    and **no behavior of `concurrent_business_slot_runner.php` may change**
    beyond the call-site argument list itself — these are the exact same
    concurrency scenarios, proving the exact same locking/serialization
    guarantees, before and after this correction. A diff to any assertion,
    any scenario's setup, or the runner's own control flow is itself a
    stop-and-report condition under §4 below, not part of this correction.
- **Authorized paths for this correction:**
  `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php` (new
  item 11) and
  `tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php`
  (new item 12) of Amendment 2 §11's allowlist.

---

## Recorded — no scope expansion needed for these three items

The implementation attempt also encountered the following three items,
each already resolved within an already-allowlisted path, requiring no
correction and no allowlist change:

1. **Migration constraint-name shortening.** MySQL's default
   `{table}_{column}_foreign`/`{table}_{column}_index` auto-generated
   identifier names exceeded the 64-character limit for
   `workspace_plan_catalog_pricing_changes`'s own long table name. The
   implementation attempt gave both the foreign key and (by removing a
   redundant explicit index already covered by the FK's own implicit
   InnoDB index) resolved this entirely within
   `database/migrations/{...}_create_workspace_plan_catalog_pricing_changes_table.php`
   — already item 2 of Amendment 2 §11's allowlist. This remains within
   that already-authorized path; no correction and no scope expansion is
   required.
2. **Core catalog test-fixture correction.** A new test incorrectly
   assumed `workspace_plan_catalog`'s seeded Core row starts with
   `additional_business_slot_price_ratio = null` — it is actually seeded
   at `0.5000` already (RFC-004 §12.1/§25), unlike `price`/`currency_id`,
   which do start `null`. The implementation attempt corrected the test's
   own expectation to match this already-documented seed fact, entirely
   within `tests/Feature/Entitlement/EntitlementManagerCatalogPricingTest.php`
   — already item 8 of Amendment 2 §11's allowlist. This remains within
   that already-authorized path; no correction and no scope expansion is
   required.
3. **The attempted direct-model-save workaround.** Recorded here for
   completeness alongside the two items above: unlike items 1–2, this one
   is **not** left as-is — §A above requires it to be removed and replaced
   by the repository allowlist correction once this document is merged.

---

## 4. Updated stop threshold

Amendment 2 §14's first stop condition — "Any path beyond §11's nine-item
allowlist is found necessary — the tenth path" — is amended to read: **any
path beyond §11's now-twelve-item allowlist (as amended by §A–§B above) is
found necessary — the thirteenth path.** Every other Amendment 2 §14 stop
condition is restated unchanged and remains fully in force, including
(non-exhaustively, for emphasis given this round's own findings): any
change to `workspace_entitlement_transitions`, `workspace_plan_catalog`'s
own columns, `WorkspacePlanCatalogController`, or `CurrencyRepository`
being found necessary; any RFC-005-owned file, table, or namespace being
found necessary to reference; any existing test failing for a reason not
fixable within the (now twelve-item) allowlist; any §12 regression gate
landing lower than its own stated baseline; any genuine need for a
customer-facing surface, a fourth plan tier, a metered pricing model, or
any other broader schema/product redesign; and any actual retail price,
currency, or ratio value being found necessary to choose or hard-code.

**The corrected Amendment 2 implementation allowlist is therefore exactly
12 paths:** the original 9 from the merged contract (PR #100), plus item
10 (`EloquentWorkspacePlanCatalogRepository.php`, §A), plus item 11
(`EntitlementManagerConcurrencyTest.php`, §B), plus item 12
(`concurrent_business_slot_runner.php`, §B).

---

## 5. Required re-verification

The entire Amendment 2 §12 gate sequence must be rerun **from the
beginning** against the disposable `ultimatesms_testing` database after the
correction commit described in §A–§B above is made and pushed to the
Amendment 2 implementation branch (`agent/rfc-004-amendment-2-catalog-pricing-operator`):

1. `php artisan test tests/Feature/Entitlement/EntitlementManagerCatalogPricingTest.php
   tests/Feature/Entitlement/WorkspacePlanCatalogPricingChangeRepositoryTest.php`
   — zero failures required; exact passing/assertion count reported by
   that run, never assumed from the prior (pre-correction) run.
2. `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement` —
   zero failures required (including, now, `EntitlementManagerConcurrencyTest.php`'s
   own scenarios 6 and 7) — exact count reported, never assumed to match
   any prior round's number.
3. `php artisan test tests/Unit/Workspace tests/Feature/Workspace` — zero
   failures required, exact count reported.
4. `php artisan test --stop-on-failure` — must exit `0`.
5. A dedicated re-confirmation that `setAdditionalBusinessSlots()`,
   `changePlan()`, and every other existing `EntitlementManager` method's
   own existing tests still pass unmodified, verbatim — this amendment
   touches only `updateCatalogPricing()`'s own mutation path and the two
   mechanical call-site migrations in §B; no other method's behavior
   changes.

Before any of the above: `.env.testing`'s `APP_NAME` and any branding
overrides must be confirmed neutral first (this session's own established
local-environment hazard, unrelated to this amendment's code), matching
the same precaution Amendment 2's own §12 item 6 already states.

---

## 6. Explicit non-scope statement

This correction document authorizes exactly §A and §B above and nothing
else. **Every other Amendment 2 invariant and non-goal (§6, §13) is
preserved unchanged and restated here by reference** — in particular, this
document does **not**:

- perform any Stripe, payment-provider, checkout, or webhook work of any
  kind;
- implement RFC-005 Milestone 4, or any other RFC-005 milestone, in any
  way;
- add a customer-facing pricing editor, controller, route, or admin view
  of any kind (Amendment 2 §4/§13 remain fully in force —
  `WorkspacePlanCatalogController` stays read-only and untouched);
- introduce, relax, or otherwise touch any commercial-policy cap on
  `additional_business_slot_price_ratio` beyond `DECIMAL(6,4)`'s own
  storage boundary (Amendment 2 §6, locked — unchanged by this document);
- change the audit table's `from_currency_id`/`to_currency_id` columns
  away from nullable plain scalars with no foreign key (Amendment 2 §8,
  locked — unchanged by this document);
- change `billing_cycle` or `is_active` mutability, or any other Amendment
  2 §6 validation/invariant;
- authorize any change to `workspace_entitlement_transitions`,
  `workspace_plan_catalog`'s own columns, `WorkspacePlanCatalogController`,
  or `CurrencyRepository` — all remain out of scope exactly as Amendment 2
  §11/§13 already state;
- invent, choose, or record any actual retail price, currency, or ratio
  value;
- extend `maximum_correction_rounds` beyond the 2 Amendment 2 §0 already
  set — this document consumes round 1 of that existing budget, it does
  not grant a new one.

---

## 7. Contract self-audit

1. Both audit-error findings are addressed by name, with exact required
   correction detail for each (§A–§B). ✓
2. Correction A adds exactly one string to one existing method's existing
   allowlist array — no new method, no new business-rule logic, no
   widening beyond that (§A). ✓
3. The rejected workaround (direct model save inside `EntitlementManager`)
   is named explicitly and its removal is required, not left ambiguous
   (§A). ✓
4. Correction B is confirmed mechanical only — no assertion or
   concurrency-behavior change authorized or implied (§B). ✓
5. The three items requiring no scope expansion are recorded for
   completeness, distinguished clearly from the two items that do require
   correction (§ "Recorded"). ✓
6. The allowlist expansion is exact (9 → 12, three named paths only) and
   the stop threshold is updated to match (10 → 13) (§A/§B/§4). ✓
7. Re-verification requires the entire §12 gate sequence rerun from the
   beginning, not assumed from the pre-correction partial run, and no test
   count is fabricated by this document itself (§5). ✓
8. Every other Amendment 2 invariant and non-goal is restated as
   unchanged, matching the original document's own list exactly (§6). ✓
9. No retail price, currency, or ratio value is invented anywhere in this
   document. ✓
10. `maximum_correction_rounds: 2` is preserved, not extended; this is
    explicitly recorded as round 1 of that existing budget (§0/§6). ✓
11. This document itself changes exactly one file, verified mechanically
    before commit (§8). ✓

---

## 8. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path,
   `docs/automation/RFC-004-AMENDMENT-2-CATALOG-PRICING-OPERATOR-CORRECTION-1.md`,
   nothing else, nothing staged.
2. Stage the one file by its exact path only
   (`git add docs/automation/RFC-004-AMENDMENT-2-CATALOG-PRICING-OPERATOR-CORRECTION-1.md`),
   never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize RFC-004 Amendment 2 correction round 1`.
4. Push normally to `origin chore/rfc-004-amendment-2-correction-1`. No
   force push. Do not push `main`.
5. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
6. **Do not merge. Do not resume the Amendment 2 implementation this
   document corrects.** Both require this document to be merged first;
   applying §A–§B against the still-uncommitted implementation worktree
   remains its own separate, later, explicitly bounded action — not this
   commit.
7. PHP/JS tests are not required for this one-file docs-only change and
   are not run — reported honestly as not run, no count fabricated.

---

*End of RFC-004 Amendment 2 — Correction Round 1. This document authorizes
drafting itself only. The two corrections it specifies (§A–§B) require
their own separate, later, explicitly bounded implementation commit against
the still-uncommitted Amendment 2 implementation worktree, after which the
full §12 gate set must be run again in full (§5). Amendment 2's own merged
contract (PR #100) is unmoved and unmodified by this document except for
the exact §11/§14 amendments stated above.*
