# RFC-005 Amendment 1 Slice 3 — Complete Incoming-FK Set Exceptional Correction Authorization (3)

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

- Drafted on branch `chore/rfc-005-amendment-1-slice-3-exceptional-correction-3`,
  in an isolated linked worktree
  (`../rfc-005-amendment-1-slice-3-exceptional-correction-3-worktree`),
  based on `origin/main` at `5fdf682d5bd3fc8792cf070a2c52fd6f7984e2f0`
  (the merge of the second exceptional correction authorization, PR #122).
- Concerns RFC-005 Amendment 1 Slice 3 CONTRACT
  (`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md`), implemented
  on **PR #119** (`agent/rfc-005-amendment-1-slice-3-contract`), current
  implementation head **`e7b1b213f7f0e034c44ecdc3caa6560a1a513e59`**
  (the result of the second exceptional correction).
- This document changes **exactly one file**: this document itself. No
  `app/`, `database/`, `tests/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting
  it, and it does not touch PR #119's own branch at all.

---

## 1. Correction budget — exhausted, and both prior exceptional corrections consumed

- **Ordinary correction rounds:** exhausted 2/2 (round 1: `UsageMeterBackfillPreflightTest`
  restoration robustness; round 2: `change()`/drop DDL separation).
- **Exceptional Correction 1** (PR #121, consumed 1/1): dropped/recreated
  every **local, outgoing** FK on `meter_key` across all three Slice 3
  tables. Proven incomplete by runtime MySQL error 1833 naming the
  **incoming** FK `usage_meters.meters_active_rate_foreign`.
- **Exceptional Correction 2** (PR #122, consumed 1/1): dropped/recreated
  `meters_active_rate_foreign` around the change. Proven incomplete by a
  **second** runtime MySQL error 1833, this time naming a **different**
  incoming FK, `business_usage_rate_activations.activations_meter_rate_foreign`.

**Both prior exceptional corrections remain consumed and are not
reopened, extended, or reset by this document.** This is a third,
separate, independently-scoped exception — and, per the complete source
audit in §2, the last one this specific defect should require.

---

## 2. Runtime-proven defect and complete source audit

**Second runtime failure, at implementation head `e7b1b213f7f0e034c44ecdc3caa6560a1a513e59`:**

```
php artisan migrate:fresh --env=testing -vvv
```

```
SQLSTATE[HY000] General error: 1833
Cannot change column 'meter_key':
used in foreign key constraint 'activations_meter_rate_foreign'
of table 'ultimatesms_testing.business_usage_rate_activations'

SQL: alter table `business_usage_rates` modify `meter_key` varchar(128) not null
```

**Root pattern now established across two consecutive real failures:**
`business_usage_rates.meter_key` is part of the composite target
`(meter_key, id)` used by `business_usage_rates_meter_key_id_unique`,
and **every** foreign key anywhere in the schema that references that
composite target blocks this exact `ALTER TABLE ... MODIFY` until it is
dropped first. Correcting one such FK at a time, discovered only by
repeatedly re-running `migrate:fresh` and reading the next error, is
what produced Exceptional Corrections 1 and 2. This document instead
requires the **complete** set be identified and handled together.

**Complete source audit performed** (searching every migration file in
`database/migrations/` for any `foreign(...)->on('business_usage_rates')`,
plus the broader unqualified string `business_usage_rates` across all
15 files that mention it, plus a repository-wide scan for
`FOREIGN KEY`/`ADD CONSTRAINT` outside the standard Laravel Blueprint
pattern):

**Confirmed complete set of composite foreign keys referencing
`business_usage_rates(meter_key, id)`** — the only kind of foreign key
capable of blocking `meter_key`'s `ALTER`:

1. `usage_meters.meters_active_rate_foreign` — local `(meter_key,
   active_rate_id)`, defined by
   `2026_08_22_120003_add_active_rate_foreign_to_usage_meters_table.php`.
   Already handled by Exceptional Correction 2.
2. `business_usage_rate_activations.activations_meter_rate_foreign` —
   local `(meter_key, rate_id)`, defined by
   `2026_08_22_120004_add_meter_key_to_business_usage_rate_activations_table.php`.
   The FK named in the §2 runtime error above — not yet handled.
3. `business_usage_reservations.reservations_meter_rate_foreign` —
   local `(meter_key, rate_id)`, defined by
   `2026_08_22_120006_add_meter_key_to_business_usage_reservations_table.php`.
   Not yet handled.
4. `usage_meter_transitions.umt_from_rate_same_meter_foreign` — local
   `(meter_key, from_active_rate_id)`, defined by
   `2026_08_22_120005_create_usage_meter_transitions_table.php`. Not
   yet handled.
5. `usage_meter_transitions.umt_to_rate_same_meter_foreign` — local
   `(meter_key, to_active_rate_id)`, defined by the same migration as
   item 4. Not yet handled.
6. `business_usage_ledger_entries.ledger_meter_rate_foreign` — local
   `(meter_key, rate_id)`, defined by
   `2026_08_22_120007_add_meter_key_to_business_usage_ledger_entries_table.php`.
   Not yet handled.

**Confirmed out of scope, and confirmed not blocking, by the same
audit:** four additional foreign keys exist that reference
`business_usage_rates.id` alone, as a single, non-composite column,
predating the Amendment entirely (M1): `business_usage_rate_activations`'
original `rate_id` FK, `business_usage_reservations`' `rate_id` FK,
`business_usage_ledger_entries`' `rate_id` FK, and
`platform_feature_usage_classifications.active_rate_id`'s FK. None of
these four include `meter_key` in their column set, so none of them
can be affected by, or block, a `meter_key`-only `ALTER`. **This audit
found no seventh composite foreign key referencing `meter_key`
anywhere in the repository.** Items 1–6 above are the complete set.

---

## 3. Authorization — one exceptional implementation correction, and only one

This document **explicitly authorizes exactly one new exceptional
implementation correction**, beyond and outside the exhausted ordinary
2/2 budget and both already-consumed exceptional corrections (PR #121,
PR #122), to PR #119 (`agent/rfc-005-amendment-1-slice-3-contract`),
current head `e7b1b21`, to be performed **only after this document is
merged to `main`**, as a human decision.

This authorization is based on the two consecutive runtime-proven MySQL
1833 errors recorded in §2 and the complete source audit that followed
them — not on speculation, and not on a one-FK-at-a-time approach.

### 3.1 Path scope

The correction's implementation scope remains **inside the existing
14-path allowlist** already locked by
`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` §10. The
expected change is **only**:

1. `database/migrations/2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php`

No path outside the fourteen-entry list may be added under this
exception's authority. **No Slice 1 migration may be edited** —
specifically, none of `2026_08_22_120003_add_active_rate_foreign_to_usage_meters_table.php`,
`2026_08_22_120004_add_meter_key_to_business_usage_rate_activations_table.php`,
`2026_08_22_120005_create_usage_meter_transitions_table.php`,
`2026_08_22_120006_add_meter_key_to_business_usage_reservations_table.php`,
or `2026_08_22_120007_add_meter_key_to_business_usage_ledger_entries_table.php`
may be modified. Runtime schema access from `120001` is permitted only
to drop/recreate the six named incoming foreign keys in §3.2 and the
already-authorized local `rates_meter_currency_foreign` handling
carried over from Exceptional Correction 1.

### 3.2 Required resulting behavior

1. **All six** foreign keys identified in §2 must be dropped and
   recreated, with byte-identical semantics to their current,
   already-merged definitions:

   | # | Name | Local table | Local columns | Target |
   |---|------|-------------|----------------|--------|
   | 1 | `meters_active_rate_foreign` | `usage_meters` | `meter_key, active_rate_id` | `business_usage_rates(meter_key, id)` |
   | 2 | `activations_meter_rate_foreign` | `business_usage_rate_activations` | `meter_key, rate_id` | `business_usage_rates(meter_key, id)` |
   | 3 | `reservations_meter_rate_foreign` | `business_usage_reservations` | `meter_key, rate_id` | `business_usage_rates(meter_key, id)` |
   | 4 | `umt_from_rate_same_meter_foreign` | `usage_meter_transitions` | `meter_key, from_active_rate_id` | `business_usage_rates(meter_key, id)` |
   | 5 | `umt_to_rate_same_meter_foreign` | `usage_meter_transitions` | `meter_key, to_active_rate_id` | `business_usage_rates(meter_key, id)` |
   | 6 | `ledger_meter_rate_foreign` | `business_usage_ledger_entries` | `meter_key, rate_id` | `business_usage_rates(meter_key, id)` |

   All six use `restrictOnDelete()`, matching their existing definitions
   exactly.
2. **No table other than `business_usage_rates` has any column, row,
   index, or other constraint changed.** Touching `usage_meters`,
   `business_usage_rate_activations`, `business_usage_reservations`,
   `usage_meter_transitions`, and `business_usage_ledger_entries` is
   permitted for exactly the one purpose of dropping/recreating their
   own single named incoming foreign key each — nothing else on any of
   those five tables changes.
3. **`business_usage_rates_meter_key_id_unique`** — the target index
   all six foreign keys reference — **remains completely untouched.**
4. **`rates_meter_currency_foreign`** (already handled by Exceptional
   Correction 1) continues to be dropped and recreated with its own
   existing exact name/columns/semantics, unchanged.
5. **The global forward preflight** (migration `120001`'s `up()`) must
   remain first, unchanged in effect.
6. **Rollback Preflights A and B** (migration `120003`'s `down()`) must
   remain first, before any rollback restoration DDL, unchanged in
   effect and unchanged in file.
7. **Locked `up()` ordering, migration `120001`, after the existing
   global forward preflight:**
   1. Drop all six incoming foreign keys (§3.2 item 1), in any stable
      order, each in its own `Schema::table()` call.
   2. Drop the local `rates_meter_currency_foreign`.
   3. `business_usage_rates.meter_key` → `NOT NULL` (the bare
      `change()`, now fully unblocked).
   4. Recreate `rates_meter_currency_foreign`, exact original
      definition.
   5. Recreate all six incoming foreign keys, exact original
      definitions, each in its own call.
   6. Continue the existing, already-correct `feature_key` contraction
      steps (drop `business_usage_rates_feature_key_version_unique`,
      drop `feature_key`) unchanged.
8. **Locked `down()` behavior, migration `120001`, before loosening
   `meter_key`:** reached, in a normal whole-batch rollback, only after
   migration `120003`'s own `down()` has already passed both rollback
   preflights.
   1. Drop all six incoming foreign keys.
   2. Drop `rates_meter_currency_foreign`.
   3. `meter_key` → nullable (the bare `change()`).
   4. Recreate `rates_meter_currency_foreign`.
   5. Recreate all six incoming foreign keys.
9. Every `Schema::table()` call performs exactly one operation — no
   `change()`, `dropForeign()`, or `foreign()` call is combined with
   another in the same blueprint closure, continuing Correction Round
   2's own proven discipline.
10. No fabricated, copied, inferred, or backfilled meter identity is
    introduced anywhere, forward or in rollback, under any
    circumstance.

---

## 4. Required verification after the correction

1. **`php artisan migrate:fresh --env=testing -vvv`** must complete
   without error, in full, with no further `1833` (or any other) error
   on any of the six foreign keys or `rates_meter_currency_foreign`.
2. **Only if item 1 passes**, the **focused Usage suite**
   (`php artisan test tests/Feature/Usage --compact`) must be run and
   must pass in full, with a genuine positive test count reported.
3. `git diff --check` must be clean.
4. The cumulative diff of PR #119 against current `origin/main` must be
   confirmed to still equal exactly the fourteen-path allowlist in
   §3.1 — no more, no fewer.
5. Direct source verification, before commit, that each recreated
   foreign key's local columns, target columns, and `restrictOnDelete()`
   semantics match its original, already-merged definition exactly —
   not merely that the migration "looks similar."

---

## 5. No further correction under this exception

This exception authorizes **exactly one** implementation correction
pass. It is not renewable, does not reset or extend the ordinary
correction budget or either prior exceptional correction, and does not
authorize a second attempt if this one is incomplete.

**If, during or after this correction, `migrate:fresh` still fails, the
Usage suite still fails, or another genuine defect is found** — whether
a new issue, an incompletely-recreated foreign key, or a seventh
constraint this document's audit did not find — **PR #119 must stop and
be reported for a new, separate human decision.** It must not be
modified again under this document's authority. A further defect
requires its own new exception document (or other explicit human
authorization).

---

## 6. Stop conditions

The correction must stop, leave the working tree unstaged beyond what
is already committed, and report rather than proceed, if:

- Any path beyond §3.1's single-entry allowlist is found necessary.
- Editing any Slice 1 migration is found necessary.
- Touching any column, row, or constraint on `usage_meters`,
  `business_usage_rate_activations`, `business_usage_reservations`,
  `usage_meter_transitions`, or `business_usage_ledger_entries` other
  than each table's own one named incoming foreign key is found
  necessary.
- `business_usage_rates_meter_key_id_unique` is found to require any
  change.
- A seventh composite foreign key referencing `business_usage_rates(meter_key,
  id)` is discovered that §2's audit missed.
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
automatic AI Subscription Loop remain disabled — manual Claude Code
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
   `docs: authorize RFC-005 Amendment 1 Slice 3 exceptional correction 3`.
4. Push normally to
   `origin chore/rfc-005-amendment-1-slice-3-exceptional-correction-3`.
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

*End of RFC-005 Amendment 1 Slice 3 Complete Incoming-FK Set Exceptional
Correction Authorization (3). This document authorizes exactly one
exceptional implementation correction to PR #119, strictly scoped per
§3, based on the two consecutive runtime-proven MySQL 1833 errors and
the complete source audit recorded in §2, with no further correction
permitted under its own authority (§5). PR #119 remains Draft until the
correction and its verification are complete (§7).*
