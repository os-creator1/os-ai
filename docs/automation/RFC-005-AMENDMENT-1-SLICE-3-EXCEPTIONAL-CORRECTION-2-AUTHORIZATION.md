# RFC-005 Amendment 1 Slice 3 — Incoming-FK Bootstrap Exceptional Correction Authorization (2)

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

- Drafted on branch `chore/rfc-005-amendment-1-slice-3-exceptional-correction-2`,
  in an isolated linked worktree
  (`../rfc-005-amendment-1-slice-3-exceptional-correction-2-worktree`),
  based on `origin/main` at `5083ebfa7cefbbf67eeeef8fa374800b00e05719`
  (the merge of the first exceptional correction authorization, PR #121).
- Concerns RFC-005 Amendment 1 Slice 3 CONTRACT
  (`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md`), implemented
  on **PR #119** (`agent/rfc-005-amendment-1-slice-3-contract`), current
  implementation head **`7e4f5eb3f699f97114cb5c1c5af1c87b23c01ded`**
  (the result of the first exceptional correction).
- This document changes **exactly one file**: this document itself. No
  `app/`, `database/`, `tests/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting
  it, and it does not touch PR #119's own branch at all.

---

## 1. Correction budget — exhausted, and the first exceptional correction already consumed

The contract's own `maximum_correction_rounds: 2` was fully consumed
before any exceptional authorization existed:

- **Correction round 1** — `UsageMeterBackfillPreflightTest` tearDown/
  schema-restoration robustness.
- **Correction round 2** — separated `change()` from
  `dropColumn()`/`dropUnique()`/`dropIndex()` into distinct
  `Schema::table()` calls in migrations `120001`/`120002`.

A first exceptional correction, authorized by
`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-EXCEPTIONAL-CORRECTION-AUTHORIZATION.md`
(merged via **PR #121**), was then performed and consumed — it dropped
and recreated every **local, outgoing** foreign key on `meter_key`
(`rates_meter_currency_foreign` on `business_usage_rates`; both FKs on
`business_usage_rate_activations`; both FKs on
`business_usage_reservations`) around each table's nullability change,
resulting in implementation head `7e4f5eb3f699f97114cb5c1c5af1c87b23c01ded`.

**That correction was proven incomplete by a real, runtime-captured
MySQL error** — recorded in full in §2 below — not by another round of
speculation. **Both the ordinary correction budget (2/2) and the first
exceptional correction (1/1) remain exhausted and are not reopened,
extended, or reset by this document.** This is a second, separate,
independently-scoped exception.

---

## 2. Runtime-proven defect — exact record

**Command run, at the exact head under correction:**

```
php artisan migrate:fresh --env=testing -vvv
```

**Exact captured failure:**

- **Migration:** `2026_08_24_120001_tighten_and_contract_business_usage_rates_table`
- **SQL:**
  ```sql
  alter table `business_usage_rates`
  modify `meter_key` varchar(128) not null
  ```
- **MySQL error:**
  ```
  SQLSTATE[HY000] General error: 1833
  Cannot change column 'meter_key':
  used in foreign key constraint 'meters_active_rate_foreign'
  of table 'ultimatesms_testing.usage_meters'
  ```

**Diagnosis:** `business_usage_rates.meter_key` is the target of an
**incoming** composite foreign key from a different table —
`usage_meters.meters_active_rate_foreign`, `(meter_key, active_rate_id)
→ business_usage_rates(meter_key, id)`, `restrictOnDelete()` — created
by the already-merged Slice 1 migration
`2026_08_22_120003_add_active_rate_foreign_to_usage_meters_table.php`.
The first exceptional correction (PR #121) dropped and recreated only
`business_usage_rates`' own **outgoing** FK
(`rates_meter_currency_foreign`); it did not, and under its own §3.1
path scope and explicit "no scope expansion to `usage_meters`"
condition, **could not** touch the incoming FK living on `usage_meters`
— that table was outside its authority entirely. MySQL error 1833 is
raised precisely because a column referenced by another table's foreign
key cannot be modified while that foreign key still exists, regardless
of how many of the column's own outgoing constraints have already been
handled.

This is the exact, complete, runtime-captured explanation for every
observed `RefreshDatabase` bootstrap failure across every prior round:
`migrate:fresh` throws inside this one `ALTER TABLE` statement, before
any test body ever runs, and — because `RefreshDatabaseState::$migrated`
is only set after that call returns successfully — every subsequent
`RefreshDatabase`-using test in the same process independently
re-attempts and re-fails the identical `migrate:fresh` call in its own
`setUp()`, which is why the failure appeared broad, uniform, and
unrelated to any individual test's own body.

---

## 3. Authorization — one exceptional implementation correction, and only one

This document **explicitly authorizes exactly one new exceptional
implementation correction**, beyond and outside both the exhausted
ordinary 2/2 budget and the already-consumed first exceptional
correction (PR #121), to PR #119
(`agent/rfc-005-amendment-1-slice-3-contract`), current head `7e4f5eb`,
to be performed **only after this document is merged to `main`**, as a
human decision.

This authorization is based on the runtime-proven MySQL error 1833
recorded in §2 — not on speculation, and not on any theory this
document itself introduces.

### 3.1 Path scope

The correction's implementation scope remains **inside the existing
14-path allowlist** already locked by
`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` §10:

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

**The correction must remain inside
`database/migrations/2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php`
(item 1) unless direct source proof, gathered during the correction
itself, demonstrates another already-allowlisted path is genuinely
essential.** No path outside this fourteen-entry list may be added
under this exception's authority — that is itself a stop-and-report
condition (§6).

**The already-merged Slice 1 migration
`database/migrations/2026_08_22_120003_add_active_rate_foreign_to_usage_meters_table.php`
must not be modified.** This exception permits touching the
**`usage_meters` table's schema at runtime, transiently, from within
migration `120001`'s own `up()`/`down()`** — dropping and recreating
exactly the one named foreign key in §3.2 — but does not authorize
editing that Slice 1 migration file, or any other file that defines or
touches `usage_meters`, to accomplish this. The correction is achieved
entirely from within the already-allowlisted `120001` file's own
migration logic.

### 3.2 Required resulting behavior

1. **Touching `usage_meters` is permitted for exactly one purpose:**
   dropping and recreating `meters_active_rate_foreign` around
   `business_usage_rates.meter_key`'s nullability change. No column,
   data row, index other than this one named foreign key, or any other
   element of `usage_meters` or `usage_meter_transitions` may be added,
   altered, or touched.
2. **`meters_active_rate_foreign` must be recreated with byte-identical
   semantics to its current, already-merged definition:**
   - name: `meters_active_rate_foreign`
   - local table: `usage_meters`
   - local columns: `meter_key`, `active_rate_id` (in that order)
   - target: `business_usage_rates(meter_key, id)` (in that order)
   - `restrictOnDelete()`
3. **`business_usage_rates_meter_key_id_unique`** — the existing target
   index this foreign key references — **remains completely untouched.**
   It is not dropped, recreated, renamed, or altered in any way; it
   already correctly accommodates the nullability change with no
   modification needed.
4. **`rates_meter_currency_foreign`** (already handled by the first
   exceptional correction) continues to be dropped and recreated with
   its own existing exact name/columns/semantics, unchanged from PR
   #121's own authorization.
5. **The global forward preflight** (migration `120001`'s `up()`,
   checking all three tables' `meter_key IS NULL` counts before any
   Slice 3 contracting DDL) **must remain first**, unchanged in effect.
6. **Rollback Preflights A and B** (migration `120003`'s `down()`,
   meter-resolution then legacy-uniqueness-collision) **must remain
   first**, before any rollback restoration DDL, unchanged in effect
   and unchanged in file.
7. **Locked `up()` ordering, migration `120001`, after the existing
   global forward preflight:**
   1. Drop the incoming `meters_active_rate_foreign` (on `usage_meters`).
   2. Drop the local `rates_meter_currency_foreign` (on
      `business_usage_rates`).
   3. `meter_key` → `NOT NULL` (the bare `change()`, now unblocked).
   4. Recreate `rates_meter_currency_foreign`, exact original
      definition.
   5. Recreate `meters_active_rate_foreign`, exact original definition.
   6. Continue the existing, already-correct `feature_key` contraction
      steps (drop `business_usage_rates_feature_key_version_unique`,
      drop `feature_key`) unchanged.
8. **Locked `down()` ordering, migration `120001`, before loosening
   `meter_key`:**
   1. Drop `meters_active_rate_foreign`.
   2. Drop `rates_meter_currency_foreign`.
   3. `meter_key` → nullable (the bare `change()`, now unblocked).
   4. Recreate `rates_meter_currency_foreign`.
   5. Recreate `meters_active_rate_foreign`.
   This sequence is reached, in a normal whole-batch rollback, only
   after migration `120003`'s own `down()` (which Laravel runs first in
   a batch rollback) has already passed both of its rollback preflights
   — unchanged from the existing, locked contract ordering.
9. Every `Schema::table()` call performs exactly one operation — no
   `change()` is combined with a `dropForeign()`/`foreign()` call in the
   same blueprint closure, continuing Correction Round 2's own proven
   discipline.
10. No fabricated, copied, inferred, or backfilled meter identity is
    introduced anywhere, forward or in rollback, under any circumstance.

---

## 4. Required verification after the correction

1. **`php artisan migrate:fresh --env=testing -vvv`** (or the equivalent
   safe local command that targets only the testing database) must
   complete without error — this is the exact command that captured the
   §2 failure, and its clean completion is the direct proof this
   specific defect is resolved.
2. **Focused Usage suite** (`php artisan test tests/Feature/Usage`) must
   be run and must pass in full, with a genuine positive test count
   reported.
3. **Regression commands** already required by the Slice 3 CONTRACT
   (`php artisan test tests/Feature/Entitlement`,
   `php artisan test tests/Feature/Workspace`) must also be run and
   reported with exact counts.
4. `git diff --check` must be clean.
5. The cumulative diff of PR #119 against current `origin/main` must be
   confirmed to still equal exactly the fourteen-path allowlist in
   §3.1 — no more, no fewer.

---

## 5. No further correction under this exception

This exception authorizes **exactly one** implementation correction
pass. It is not renewable, does not reset or extend the ordinary
correction budget or the first exceptional correction, and does not
authorize a second attempt if this one is incomplete.

**If, during or after this correction, `migrate:fresh` still fails, the
Usage suite still fails, or another genuine defect is found** —
whether a new issue or an incomplete fix of the §2 defect itself —
**PR #119 must stop and be reported for a new, separate human
decision.** It must not be modified again under this document's
authority. A further defect requires its own new exception document
(or other explicit human authorization), exactly as this document
itself was required once the first exceptional correction proved
incomplete.

---

## 6. Stop conditions

The correction must stop, leave the working tree unstaged beyond what
is already committed, and report rather than proceed, if:

- Any path beyond §3.1's exact fourteen-entry allowlist is found
  necessary.
- Editing `2026_08_22_120003_add_active_rate_foreign_to_usage_meters_table.php`
  (or any other Slice 1 migration or file defining `usage_meters`) is
  found necessary.
- Touching any `usage_meters` column, data row, or index other than
  `meters_active_rate_foreign` is found necessary.
- `business_usage_rates_meter_key_id_unique` is found to require any
  change.
- Any existing test fails for a reason not fixable within the §3.1
  path scope.
- `php artisan migrate:fresh` still fails after this correction, for
  the same or a different reason.

---

## 7. PR #119 status

**PR #119 remains Draft.** It must not be merged until:

1. This document is itself merged to `main`, and
2. The single authorized correction (§3) is performed on PR #119, and
3. All required verification (§4) passes, with exact counts and the
   clean `migrate:fresh` run reported.

No merge decision is authorized by this document. Merging PR #119
remains a separate, explicit, future human decision. Human-only merge
applies to both this document and PR #119. Codex review and the
automatic AI Subscription Loop remain disabled for this correction —
manual Claude Code work, human-reviewed, with no automatic follow-up
assumed or planned.

PR #107 / RFC-005 Milestone 5 remains unselected, unauthorized, and
completely untouched by this document or the correction it authorizes.

---

## 8. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path, this document,
   nothing else, nothing staged.
2. Stage the one file by its exact path only, never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize RFC-005 Amendment 1 Slice 3 exceptional correction 2`.
4. Push normally to
   `origin chore/rfc-005-amendment-1-slice-3-exceptional-correction-2`.
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

*End of RFC-005 Amendment 1 Slice 3 Incoming-FK Bootstrap Exceptional
Correction Authorization (2). This document authorizes exactly one
exceptional implementation correction to PR #119, strictly scoped per
§3, based on the runtime-proven MySQL error 1833 recorded in §2, with
no further correction permitted under its own authority (§5). PR #119
remains Draft until the correction and its verification are complete
(§7).*
