# RFC-005 Amendment 1 Slice 3 — Bootstrap-Defect Exceptional Correction Authorization

**This document is fully self-contained.** No section below requires
consulting an earlier commit or any other contract to understand this
exception's complete rules — every requirement, authorization, and
constraint is stated here in full.

**Status: authorization only. Drafting and merging this document makes
zero application changes. It does not itself perform the correction it
authorizes — the correction requires its own separate commit(s), on
PR #119 itself, made only after this document is merged to `main`.**

---

## 0. Governance

- Drafted on branch `chore/rfc-005-amendment-1-slice-3-exceptional-correction`,
  in an isolated linked worktree
  (`../rfc-005-amendment-1-slice-3-exceptional-correction-worktree`),
  based on `origin/main` at `d976d457f4301e8129044725dc98ed2fa43ce770`.
- Concerns RFC-005 Amendment 1 Slice 3 CONTRACT
  (`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md`, merged via
  PR #117, reconciled with Design Correction 2/PR #118, manually
  authorized via PR #120), implemented on **PR #119**
  (`agent/rfc-005-amendment-1-slice-3-contract`), current implementation
  head **`3858c16c063d3d5c299591cef49f3320ed8e6ef8`**.
- This document changes **exactly one file**: this document itself. No
  `app/`, `database/`, `tests/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting
  it, and it does not touch PR #119's own branch at all.

---

## 1. Correction-round budget — exhausted

The contract's own `AI-AUTONOMY-STATE.json`/§0 governance sets
`maximum_correction_rounds: 2` for this implementation. Both rounds have
already been consumed on PR #119:

- **Correction round 1** — fixed `UsageMeterBackfillPreflightTest`'s
  `tearDown()`/schema-restoration logic: each of the three Slice 3
  tables is now checked and restored to its final shape independently
  (no single all-or-nothing gate), fixture-row cleanup is wrapped so a
  failure there can never block schema restoration, and leftover
  `meter_key IS NULL` test debris is swept before re-attempting any
  migration.
- **Correction round 2** — split every migration blueprint that combined
  a column `->change()` with a `dropColumn()`/`dropUnique()`/
  `dropIndex()` in one `Schema::table()` call (migrations `120001` and
  `120002`) into separate, single-purpose calls — a repo-wide audit
  confirmed this combination pattern existed nowhere else in the
  codebase.

**Post-round-2 verification, performed against the exact resulting
head:**

```
HEAD is now at 3858c16 fix: split combined change()/drop DDL in Slice 3 migrations (Correction Round 2)
php artisan test tests/Feature/Usage --compact
```

The Usage suite still failed broadly, including test classes with no
relationship to `UsageMeter` or any Slice 3 table at all. Because this
run explicitly checked out the exact correction-round-2 head before
running, the stale-head explanation is disproven, and because each
`php artisan test` invocation is a new PHP process,
`RefreshDatabaseState` cannot have carried over from an earlier,
different process. The remaining bootstrap defect is real at
`3858c16`.

With both contractually-permitted correction rounds already spent, the
contract provides **no further automatic correction mechanism** for
this implementation head. Any further change to PR #119 requires a new,
explicit, human-authorized exception — exactly what this document is.

---

## 2. Discovered defect — current record

**Symptom:** `RefreshDatabase`-based tests across the Usage suite fail
broadly, including tests wholly unrelated to `UsageMeter` (e.g. tests
that only enumerate an unrelated table's own column list). A
non-`RefreshDatabase` test (`AutoRechargeFailedPaymentRetryTest`, which
manages its own rows against already-committed data) passed in full —
consistent with the defect living in test-bootstrap/migration
territory rather than in ordinary application behavior.

**Current strongest source-supported remediation target:** the Slice 3
nullability `->change()` operations on `meter_key` columns that
participate in composite or multiple foreign-key constraints:

- `business_usage_rates.meter_key` — `rates_meter_currency_foreign`
  FK `(meter_key, currency_id) → usage_meters(meter_key, currency_id)`,
  and is itself the target of an incoming FK from `usage_meters`
  (`meters_active_rate_foreign`, via the `business_usage_rates_meter_key_id_unique`
  index — not itself altered by any nullability change).
- `business_usage_rate_activations.meter_key` — a plain FK to
  `usage_meters.meter_key`, **and** `activations_meter_rate_foreign`
  FK `(meter_key, rate_id) → business_usage_rates(meter_key, id)`,
  simultaneously.
- `business_usage_reservations.meter_key` — the same two-FK shape as
  activations, targeting `usage_meters.meter_key` and
  `reservations_meter_rate_foreign`.

An existing single-column FK/nullability precedent already merged in
this codebase (`automations.sending_server_id`,
`tracking_logs.sending_server_id` — each toggled by a bare
`->change()` with the FK still attached, in both directions) proves a
**single**, non-composite FK does not need to be dropped for a
nullability-only change. It does **not** prove the **composite/multiple**
FK case behaves identically — that remains a genuine, currently
unconfirmed possibility, not yet proven true or false from source
alone.

**This authorization does not assume the above target is correct.**
The correction it authorizes must first independently confirm, from
source and from a real local run, which exact operation is the true
remaining cause, before applying any fix.

---

## 3. Authorization — one exceptional implementation correction, and only one

This document **explicitly authorizes exactly one exceptional
implementation correction**, beyond and outside the ordinary 2/2
correction-round limit, to PR #119
(`agent/rfc-005-amendment-1-slice-3-contract`), current head `3858c16`,
to be performed **only after this document is merged to `main`**, as a
human decision.

This authorization exists **outside** and **in addition to** the
contract's own two-round correction budget (§1) — it does not reopen,
extend, or reset that budget. It is a one-time, named exception for
repairing the proven remaining `RefreshDatabase`/`migrate:fresh`
bootstrap failure recorded in §2, not a general grant of further
correction capacity.

### 3.1 Path scope

The correction's implementation scope remains **inside the existing
14-path allowlist** already locked by
`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` §10 — no path
outside that list is authorized by this document:

1. `database/migrations/2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php`
2. `database/migrations/2026_08_24_120002_tighten_and_contract_business_usage_rate_activations_table.php`
3. `database/migrations/2026_08_24_120003_tighten_meter_key_on_business_usage_reservations_table.php`
4. `app/Exceptions/Usage/UsageMeterBackfillIncompleteException.php`
5. `app/Exceptions/Usage/UsageMeterRollbackVersionCollisionException.php`
6. `app/Repositories/Contracts/BusinessUsageRateRepository.php`
7. `app/Repositories/Eloquent/EloquentBusinessUsageRateRepository.php`
8. `app/Library/Usage/UsageWalletManager.php`
9. `app/Models/BusinessUsageRate.php`
10. `app/Models/BusinessUsageRateActivation.php`
11. `tests/Feature/Usage/BusinessUsageRateSchemaTest.php`
12. `tests/Feature/Usage/BusinessUsageReservationLedgerSchemaTest.php`
13. `tests/Feature/Usage/UsageMeterBackfillPreflightTest.php`
14. `tests/Feature/Usage/UsageWalletManagerSetActiveRateConcurrencyTest.php`

**Expected changes are limited to the three Slice 3 migration files
(items 1–3)** unless direct source inspection, performed during the
correction itself, proves that repairing the bootstrap defect genuinely
requires touching another path already on this list. No path outside
this fourteen-entry list may be added under this exception's authority
— that is itself a stop-and-report condition (§6).

### 3.2 Required resulting behavior

1. **No scope expansion to `usage_meters`.** The correction may not add,
   alter, or touch any migration, model, repository, or schema element
   belonging to `usage_meters` or `usage_meter_transitions`, in any
   file, for any reason.
2. **No changes to RFC-005 M1–M4 semantics.** No table, method, or
   behavior belonging to those milestones may be altered.
3. **No changes to entitlement behavior.** `EntitlementManager`,
   `UsageAuthorizationGateway`, and the nine existing entitlement
   decision keys remain exactly as already frozen by the Slice 3
   CONTRACT.
4. **No live charging.** This remains schema/migration-bootstrap repair
   work only, exercised through disposable test fixtures exactly as
   every prior slice has been.
5. **The global forward preflight** (migration `120001`'s `up()`,
   checking all three tables' `meter_key IS NULL` counts before any
   Slice 3 contracting DDL) **must remain first**, unchanged in effect,
   regardless of what DDL restructuring the correction performs around
   it.
6. **Rollback Preflights A and B** (migration `120003`'s `down()`,
   meter-resolution then legacy-uniqueness-collision) **must remain
   first**, before any rollback restoration DDL, unchanged in effect.
7. **Existing indexes and unique constraints must remain unchanged**
   unless strictly required by the specific FK operation being
   corrected — no index/unique is dropped, renamed, or added beyond
   what the fix genuinely requires.
8. If the correction's chosen fix involves dropping and recreating a
   local foreign key around a nullability change, it must be dropped
   and recreated with its **exact original name, columns, and
   `restrictOnDelete()` semantics** — no FK may be silently renamed,
   widened, or given different delete/update behavior.
9. No fabricated, copied, inferred, or backfilled meter identity is
   introduced anywhere, forward or in rollback, under any circumstance
   — unchanged from every prior slice's own non-negotiable rule.

---

## 4. Required verification after the correction

1. **Focused Usage suite** (`php artisan test tests/Feature/Usage`) must
   be run and must pass in full, with a genuine positive test count
   reported (a `0`/`No tests found` result is a failure, not a pass,
   per the repository's own non-negotiable rule).
2. **Regression commands** already required by the Slice 3 CONTRACT
   (`php artisan test tests/Feature/Entitlement`,
   `php artisan test tests/Feature/Workspace`) must also be run and
   reported with exact counts.
3. `git diff --check` must be clean.
4. The cumulative diff of PR #119 against current `origin/main` must be
   confirmed to still equal exactly the fourteen-path allowlist in
   §3.1 — no more, no fewer.

---

## 5. No further correction under this exception

This exception authorizes **exactly one** implementation correction
pass. It is not renewable, does not reset or extend the contract's own
two-round correction budget, and does not authorize a second attempt if
the first attempt is incomplete.

**If, during or after this correction, the Usage suite still fails, or
another genuine defect is found** — whether a new issue or an
incomplete fix of the §2 defect itself — **PR #119 must stop and be
reported for a new, separate human decision.** It must not be modified
again under this document's authority. A further defect requires its
own new exception document (or other explicit human authorization),
exactly as this document itself was required once the contract's own
two correction rounds were spent.

---

## 6. Stop conditions

The correction must stop, leave the working tree unstaged beyond what
is already committed, and report rather than proceed, if:

- Any path beyond §3.1's exact fourteen-entry allowlist is found
  necessary.
- Touching `usage_meters` or `usage_meter_transitions` in any way is
  found necessary.
- Any existing test fails for a reason not fixable within the §3.1
  path scope.
- Source-level inspection during the correction disproves the §2
  remediation target entirely and no alternative fix within scope can
  be identified.
- Any change beyond repairing the bootstrap DDL defect is found
  necessary.

---

## 7. PR #119 status

**PR #119 remains Draft.** It must not be merged until:

1. This document is itself merged to `main`, and
2. The single authorized correction (§3) is performed on PR #119, and
3. All required verification (§4) passes, with exact counts reported.

No merge decision is authorized by this document. Merging PR #119
remains a separate, explicit, future human decision. Human-only merge
applies to both this document and PR #119, unchanged from every prior
governance step in this Amendment. Codex review and the automatic AI
Subscription Loop remain disabled for this correction, exactly as they
have been for the entire Slice 3 effort — this is manual Claude Code
work, human-reviewed, with no automatic follow-up assumed or planned.

PR #107 / RFC-005 Milestone 5 remains unselected, unauthorized, and
completely untouched by this document or the correction it authorizes.

---

## 8. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path, this document,
   nothing else, nothing staged.
2. Stage the one file by its exact path only, never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize RFC-005 Amendment 1 Slice 3 exceptional correction`.
4. Push normally to
   `origin chore/rfc-005-amendment-1-slice-3-exceptional-correction`.
   No force push. Do not push `main`.
5. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
6. **Do not merge. Do not begin the correction this document
   authorizes.** Both require this document to be merged first, and the
   correction itself remains its own separate, explicit action against
   PR #119, not performed as part of this commit.
7. PHP/JS tests are not required for this one-file docs-only change and
   are not run — reported honestly as not run, no count fabricated.

---

*End of RFC-005 Amendment 1 Slice 3 Bootstrap-Defect Exceptional
Correction Authorization. This document authorizes exactly one
exceptional implementation correction to PR #119, strictly scoped per
§3, with no further correction permitted under its own authority (§5).
PR #119 remains Draft until the correction and its verification are
complete (§7).*
