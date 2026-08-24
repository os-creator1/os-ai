# RFC-005 Amendment 1 Slice 3 — Post-Verification Test-Alignment Correction Authorization

**This document is fully self-contained.** No section below requires
consulting an earlier commit or any other contract to understand this
exception's complete rules — every requirement, authorization, and
constraint is stated here in full.

**Status: authorization only. Drafting and merging this document makes
zero application changes. It does not itself perform the correction it
authorizes — the correction requires its own separate commit(s), on
PR #119 itself, made only after this document is merged to `main`.**

**This document does NOT extend, reopen, renew, or reinterpret
Exceptional Correction 3 (PR #123). Exceptional Correction 3's own
authority is fully consumed and its own implementation is separately,
already runtime-proven correct (§1). This is a new, independently-scoped
exception addressing a different defect category — stale pre-Slice-3
test fixtures discovered only after Exceptional Correction 3's own
required verification passed.**

---

## 0. Governance

- Drafted on branch `chore/rfc-005-amendment-1-slice-3-test-alignment-correction`,
  in an isolated linked worktree
  (`../rfc-005-amendment-1-slice-3-test-alignment-correction-worktree`),
  based on `origin/main` at `1efae3aa87665e5a987f8cd12bcbbd8d39a1a49e`
  (the merge of Exceptional Correction 3, PR #123).
- Concerns RFC-005 Amendment 1 Slice 3 CONTRACT
  (`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md`), implemented
  on **PR #119** (`agent/rfc-005-amendment-1-slice-3-contract`), current
  implementation head **`9259124711818c97aad36fc746d21d809576fcb5`**
  (the result of Exceptional Correction 3).
- This document changes **exactly one file**: this document itself. No
  `app/`, `database/`, `tests/`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting
  it, and it does not touch PR #119's own branch at all.

---

## 1. Exceptional Correction 3 is runtime-proven correct — this document addresses a separate, later-discovered gap

**Authoritative real-MySQL runtime verification, at PR #119 head
`9259124711818c97aad36fc746d21d809576fcb5`, performed on the disposable
testing database `ultimatesms_testing`:**

1. `php artisan migrate:fresh --env=testing -vvv` — **passed completely.**
   Every migration completed, including all three Slice 3 migrations
   (`2026_08_24_120001`, `2026_08_24_120002`, `2026_08_24_120003`), with
   no error of any kind. This is direct, positive proof that
   Exceptional Correction 3's six-foreign-key correction (§2 of PR #123)
   fully resolved the MySQL 1833 `ALTER` failure across all prior
   correction rounds — **no further FK/DDL correction is authorized or
   needed, and none is requested by this document.**
2. `php artisan test tests/Feature/Usage --compact` — **415 passed, 25
   failed, 1964 assertions, 98.14s.**

**All four test files inside PR #119's own locked 14-path allowlist
passed completely:**

| File | Result |
|---|---|
| `tests/Feature/Usage/BusinessUsageRateSchemaTest.php` | 22/22 passed |
| `tests/Feature/Usage/BusinessUsageReservationLedgerSchemaTest.php` | 15/15 passed |
| `tests/Feature/Usage/UsageMeterBackfillPreflightTest.php` | 7/7 passed |
| `tests/Feature/Usage/UsageWalletManagerSetActiveRateConcurrencyTest.php` | 2/2 passed |

The Exceptional Correction 3 authorization's own required verification
(§4 of PR #123) is therefore fully satisfied for everything within its
own scope. The 25 remaining failures are **outside PR #119's locked
scope** in both directions — neither is caused by, nor fixable within,
any file Exceptional Correction 3 was authorized to touch. §2 and §3
below record the source audit proving this classification.

---

## 2. Source audit — classification of all 25 failures

### 2.1 Class A — 18 failures: frontend build-environment gap, no repository change of any kind required

Affected tests: `NoFakePaymentControlsRenderedTest` (×2),
`UsageBillingDashboardAuthorizationTest` (×8),
`UsageBillingDashboardStripeIntegrationTest` (×3),
`UsageBillingDashboardViewDataTest` (×5).

All 18 fail identically with:

```
ViewException: Unable to locate Mix file: /js/core/theme-tokens.js.
(View: resources/views/panels/scripts.blade.php)
```

**Source-verified root cause:** `webpack.mix.js` (repository root) line 71
already contains the locked, existing build entry:

```js
.js('resources/js/core/theme-tokens.js', 'public/js/core')
```

The source file `resources/js/core/theme-tokens.js` exists and is
tracked. `package.json`'s existing `production` script
(`mix --production`, invoked via `npm run production`) is the exact,
already-locked command that compiles it into `public/js/core/theme-tokens.js`
and registers the entry in `public/mix-manifest.json`, which
`resources/views/panels/scripts.blade.php` reads via Laravel's `mix()`
helper. **No `webpack.mix.js`, `package.json`, or any other build
configuration change is required or authorized.** The failure exists
solely because this freshly-vendored PR #119 verification worktree never
had `npm install && npm run production` run in it — a local
environment-preparation gap, exactly analogous to the (already resolved,
non-tracked) missing `vendor/` directory.

**Tracked-file caveat, confirmed by source inspection:** unlike
`vendor/`, this repository's `.gitignore` does **not** exclude
`public/mix-manifest.json` or `public/js/**` — both are tracked
(`git ls-files public/mix-manifest.json` returns the path). Running the
build in a verification worktree therefore produces a real tracked-file
diff. This is existing repository policy, not something this document
changes — but it means the diff must be discarded after verification,
not committed to PR #119, exactly as the transient `bootstrap/cache/*.php`
Composer-discovery diff was discarded (via `git restore --source=HEAD`)
during runtime verification. §4 below locks this explicitly.

### 2.2 Class B — 7 failures: proven stale pre-Slice-3 test fixtures, isolated to exactly two files, no production defect

Affected tests: `UsageMeterSchemaTest` (×4: `test_active_rate_composite_fk_rejects_rate_belonging_to_a_different_meter`,
`test_active_rate_composite_fk_accepts_rate_belonging_to_the_same_meter`,
`test_repository_update_can_mutate_authorized_fields`,
`test_all_six_meter_key_columns_are_varchar_128_with_compatible_collation`),
`UsageMeterTransitionSchemaTest` (×3: `test_from_active_rate_composite_fk_rejects_rate_belonging_to_a_different_meter`,
`test_to_active_rate_composite_fk_rejects_rate_belonging_to_a_different_meter`,
`test_matching_meter_and_rate_pair_is_accepted`).

**Source-verified root cause, both files:**

Both `tests/Feature/Usage/UsageMeterSchemaTest.php` and
`tests/Feature/Usage/UsageMeterTransitionSchemaTest.php` self-identify,
in their own class-level docblocks, as **"RFC-005 Amendment 1 §B/§C,
Slice 1 EXPAND"** fixtures — written before Slice 3 CONTRACT existed.
Each file contains its own private `insertRateForMeter()` helper that
inserts directly into `business_usage_rates` including a `feature_key`
column:

```php
// UsageMeterSchemaTest.php lines 77–91 / UsageMeterTransitionSchemaTest.php lines 58–72
return DB::table('business_usage_rates')->insertGetId([
    'feature_key' => 'crm',   // <-- column dropped by Slice 3 migration 120001
    'meter_key' => $meterKey,
    ...
]);
```

Slice 3 CONTRACT's migration
`database/migrations/2026_08_24_120001_tighten_and_contract_business_usage_rates_table.php`
intentionally executes `$table->dropColumn('feature_key')` on
`business_usage_rates` (confirmed by direct source read, and confirmed
already runtime-verified in §1). Every one of the 6 `QueryException`
failures (`SQLSTATE[42S22]: Unknown column 'feature_key'`) traces to a
call through this exact helper. The 7th failure,
`test_all_six_meter_key_columns_are_varchar_128_with_compatible_collation`,
asserts `$this->assertSame('YES', $rows['business_usage_rates']['is_nullable'])`
at line 382 — the Slice-1-EXPAND-era nullable expectation, directly
superseded by Slice 3's intentional `NOT NULL` contraction (also
already runtime-verified passing in §1's `migrate:fresh` run and in the
in-scope `BusinessUsageRateSchemaTest::test_rate_and_activation_meter_key_are_not_null_varchar_128`,
which asserts the correct, current `'NO'`/NOT NULL shape and passed).

**Confirmed NOT a production defect**, by direct comparison:

- `app/Models/BusinessUsageRate.php`'s `$fillable` array contains no
  `feature_key` — production code has zero remaining dependency on the
  dropped column.
- The in-scope, already-passing
  `tests/Feature/Usage/BusinessUsageRateSchemaTest.php` contains the
  correct post-Slice-3 equivalent helper (`insertRate()`, lines 54–67)
  with **no** `feature_key` in its insert, and explicitly asserts the
  column's absence (`test_rate_feature_key_column_and_legacy_unique_index_are_gone`)
  and the correct `NOT NULL` shape
  (`test_rate_and_activation_meter_key_are_not_null_varchar_128`) — both
  passed. This is direct proof the schema itself is correct; only the
  two Slice-1-EXPAND-era test files were never updated to match it.

**Conclusion: both classes are non-defects.** Class A requires zero
repository change (build-only). Class B is fully isolated to two named,
pre-existing test files whose own fixtures predate Slice 3 and were
never aligned to it — not a regression introduced by Exceptional
Correction 3 or any other part of PR #119's authorized diff.

---

## 3. Exhaustive `tests/` audit for any other pre-Slice-3 schema encoding

Full-repository search performed for every test file that (a) inserts
`business_usage_rates.feature_key`, (b) inserts
`business_usage_rate_activations.feature_key`, (c) asserts either
table's `feature_key` column exists, or (d) asserts any of the three
Slice-3-contracted `meter_key` columns
(`business_usage_rates.meter_key`, `business_usage_rate_activations.meter_key`,
already covered above) remains nullable.

**Every file referencing `business_usage_rates` (8 total) or
`business_usage_rate_activations` (5 total) in `tests/`, individually
inspected:**

| File | `business_usage_rates`/`_activations` role | Stale pre-Slice-3 encoding? |
|---|---|---|
| `UsageMeterBackfillPreflightTest.php` | Directly tests the migration's `up()`/`down()` transition; its `assertTrue`/`assertFalse` on `feature_key` existence are checking the transition itself (before/after), not a fixed expectation | No — intentional, in-scope, passed 7/7 |
| `UsageWalletManagerSetActiveRateConcurrencyTest.php` | `feature_key` inserted only into `usage_meters` (permanent column, never dropped) | No — passed 2/2 |
| `BusinessUsageReservationLedgerSchemaTest.php` | `feature_key` inserted/asserted only on `business_usage_reservations`/`business_usage_ledger_entries` — Slice 3 CONTRACT explicitly does **not** drop `feature_key` from either table (confirmed: migrations `120001`/`120002` touch only `business_usage_rates`/`business_usage_rate_activations`); test name literally asserts `feature_key` "survives_slice_3_unchanged" | No — correct and intentional, passed 15/15 |
| `BusinessUsageRateSchemaTest.php` | Correct post-Slice-3 fixture pattern (see §2.2) | No — passed 22/22 |
| `UsageWalletManagerSafetyLimitTest.php` | `feature_key` refers to `platform_feature_usage_safety_limits`/`business_usage_limit_transitions` — unrelated tables, untouched by Slice 3 | No |
| `UsageWalletManagerConcurrencyTest.php` | `feature_key` inserted only into `usage_meters` via repository `create()` | No |
| `UsageMeterTransitionSchemaTest.php` | Stale — see §2.2 | **Yes — Class B** |
| `UsageMeterSchemaTest.php` | Stale — see §2.2 | **Yes — Class B** |

A broader repository-wide grep for the bare string `feature_key` across
all of `tests/` returned 31 files; every match outside the two Class B
files and the six confirmed-clean files above resolves to an unrelated
column on a different table (`platform_feature_usage_*`,
`workspace_entitlement*`, `business_feature_usage_limits`,
`usage_meters.feature_key`, entitlement-domain `feature_key` values) —
none reference `business_usage_rates.feature_key` or
`business_usage_rate_activations.feature_key`.

**Exhaustive result: exactly two files require correction —
`tests/Feature/Usage/UsageMeterSchemaTest.php` and
`tests/Feature/Usage/UsageMeterTransitionSchemaTest.php`. No other file
in the complete `tests/` tree encodes the pre-Slice-3 schema shape.**

---

## 4. Authorization — one narrowly-scoped post-verification test-alignment correction, and only one

This document **explicitly authorizes exactly one new, independently-scoped
correction**, to PR #119 (`agent/rfc-005-amendment-1-slice-3-contract`),
current head `9259124711818c97aad36fc746d21d809576fcb5`, to be performed
**only after this document is merged to `main`**, as a human decision.

This authorization is based on the passing `migrate:fresh` run and the
415/25 focused-suite result recorded in §1, and the complete source
audits in §2 and §3 — not on speculation.

### 4.1 Path scope

The correction's implementation scope is **exactly two test files**,
both already inside PR #119's existing 14-path allowlist's *test*
category — this document does not add any new path to the allowlist,
it authorizes editing two paths already within it:

1. `tests/Feature/Usage/UsageMeterSchemaTest.php`
2. `tests/Feature/Usage/UsageMeterTransitionSchemaTest.php`

**No other path may be touched under this authority.** In particular:

- **No `app/` file may be changed.**
- **No `database/migrations/*` file may be changed** — including
  `2026_08_24_120001`, `2026_08_24_120002`, `2026_08_24_120003`, and
  every Slice 1 migration. Exceptional Correction 3's own runtime-proven
  migration content (§1) is not reopened by this document.
- **No other `tests/Feature/Usage/*` file may be changed** — the seven
  files in §3 confirmed clean must remain untouched.
- **No frontend source, build configuration, or built asset may be
  committed** under this authority (see §4.3).
- `docs/automation/AI-AUTONOMY-STATE.json` is not touched — no
  precedent document in this Slice 3 exception series has required it,
  and this document does not either.

### 4.2 Required resulting behavior (the two named files only)

1. In each file's `insertRateForMeter()` private helper, remove the
   `'feature_key' => 'crm',` array entry from the `business_usage_rates`
   insert — mirroring the already-correct, already-passing
   `BusinessUsageRateSchemaTest::insertRate()` pattern. No other key in
   either helper's array may change.
2. In `UsageMeterSchemaTest::test_all_six_meter_key_columns_are_varchar_128_with_compatible_collation`,
   change the single assertion
   `$this->assertSame('YES', $rows['business_usage_rates']['is_nullable']);`
   to `$this->assertSame('NO', ...)`, matching Slice 3's intentional
   `NOT NULL` contraction and the identical, already-passing assertion
   pattern used for `usage_meters`/`usage_meter_transitions` two lines
   above it in the same test. No other assertion in this test method
   may change.
3. No other line, method, fixture, or docblock in either file may be
   modified. Class-level "Slice 1 EXPAND" docblocks may be updated to
   note the Slice 3 alignment **only** as a comment — no behavioral
   change may result from any docblock edit.
4. No new test method may be added. No existing test method may be
   removed, renamed, skipped, or marked incomplete.

### 4.3 Frontend build output — never part of PR #119's diff

Building the existing, already-locked frontend assets
(`npm run production`, per §2.1) is **required as part of verification**
(§5) but **the resulting tracked-file diff — `public/mix-manifest.json`
and any file under `public/js/`, `public/css/`, `public/css-rtl/`
regenerated by that build — must never be committed to PR #119 or any
branch under this document's authority.** After verification (§5)
completes, any such tracked-file diff must be discarded via
`git restore --source=HEAD -- <exact changed paths>` (the same
non-destructive, exact-path-scoped technique already used during
Exceptional Correction 3's own runtime verification for the
Composer-regenerated `bootstrap/cache/*.php` files), confirmed clean via
`git status --porcelain` before any commit is made. This document does
not change repository policy on whether built assets are normally
tracked (they are, per existing `.gitignore` — confirmed in §2.1) — it
only forbids this specific correction from silently expanding PR #119's
scope through generated build output.

---

## 5. Required verification sequence after the correction

Locked order, to be performed on PR #119 after the two-file correction
(§4) and before any commit:

**A. Prepare dependencies/runtime locally, without any tracked change.**
Same non-destructive technique already used for Exceptional Correction 3's
own verification: install Composer dependencies if `vendor/` is absent,
reverting any regenerated tracked cache file (e.g.
`bootstrap/cache/packages.php`, `bootstrap/cache/services.php`) via
`git restore --source=HEAD` before proceeding.

**B. Build the existing frontend assets** using the repository's
existing, already-locked build command (`npm install` if
`node_modules/` is absent, then `npm run production`, per §2.1) so the
Mix manifest contains `/js/core/theme-tokens.js`. No `webpack.mix.js` or
`package.json` change is authorized or expected. Per §4.3, the resulting
tracked diff (if any) must be discarded via
`git restore --source=HEAD -- <paths>` before the final scope check in
step F, and must never be committed.

**C. `php artisan migrate:fresh --env=testing -vvv`** must complete
without error, on the disposable `ultimatesms_testing` database only.

**D. `php artisan test tests/Feature/Usage --compact`** — only if C
passes — **must pass in full**, with a genuine positive test count
reported (zero failures, zero errors; `No tests found` is not a pass).
If any failure remains, this document's authority is exhausted (§6) —
report and stop; do not attempt a further edit under this authorization.

**E. `git diff --check`** must be clean.

**F. Confirm PR #119's cumulative diff against `origin/main`** equals
**exactly** the existing 14-path allowlist — the same fourteen paths
already locked by
`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` §10 and
re-confirmed unchanged by Exceptional Correction 3 (§3.1 of PR #123).
This document adds no new path to that list; it authorizes editing two
paths already inside it. No `public/` path may appear in this diff.

**G. Confirm, by direct diff inspection, that no `app/` or
`database/migrations/*` file changed** — i.e., that the only two changed
paths anywhere in the cumulative diff beyond what Exceptional Correction 3
already produced are the two files named in §4.1.

**H. Report exact test/assertion counts** (e.g. "`N` passed, `0` failed,
`M` assertions") and the exact two-path diff, honestly, with no count
fabricated.

---

## 6. No further correction under this exception

This exception authorizes **exactly one** implementation correction
pass, scoped to exactly the two files in §4.1. It is not renewable and
does not authorize a second attempt if this one is incomplete, and it
does not reopen, reset, or extend Exceptional Correction 3 or any prior
correction round.

**If, during or after this correction, `migrate:fresh` fails, the Usage
suite still fails for any reason (including a reason unrelated to the
two named files), or any evidence of an actual production/schema defect
is found** — PR #119 must stop and be reported for a new, separate human
decision. It must not be modified again under this document's authority.

---

## 7. Stop conditions

The correction must stop, leave the working tree unstaged beyond what is
already committed, and report rather than proceed, if:

- Any path beyond the two named in §4.1 is found necessary to edit.
- Any `app/`, `database/migrations/*`, or other `tests/Feature/Usage/*`
  file is found to require a change.
- The Mix/frontend failures are found to require any `webpack.mix.js`,
  `package.json`, or other build-configuration change (i.e., §2.1's
  environment-only classification turns out to be wrong).
- Evidence emerges that any of the 7 Class B failures reflects a genuine
  product or schema defect rather than a stale fixture.
- `php artisan migrate:fresh` fails for any reason.
- The focused Usage suite does not pass in full after the correction.
- Any built frontend asset (`public/mix-manifest.json`, `public/js/**`,
  etc.) cannot be cleanly discarded back to its `origin/main` state
  before commit.

---

## 8. PR #119 status

**PR #119 remains Draft.** It must not be merged until:

1. This document is itself merged to `main`, and
2. The single authorized correction (§4) is performed on PR #119, and
3. All required verification (§5) passes, with exact counts and the
   clean `migrate:fresh` and full-suite results reported.

No merge decision is authorized by this document. Merging PR #119
remains a separate, explicit, future human decision. Human-only merge
applies to both this document and PR #119. Codex review and the
automatic AI Subscription Loop remain disabled — manual Claude Code
work, human-reviewed, with no automatic follow-up assumed or planned.

PR #107 / RFC-005 Milestone 5 remains unselected, unauthorized, and
completely untouched by this document or the correction it authorizes.

---

## 9. Verification and publication (this document only)

Performed, in order, before commit:

1. `git status --short` — exactly one untracked path, this document,
   nothing else, nothing staged.
2. Stage the one file by its exact path only, never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize RFC-005 Amendment 1 Slice 3 test-alignment correction`.
4. Push normally to
   `origin chore/rfc-005-amendment-1-slice-3-test-alignment-correction`.
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

*End of RFC-005 Amendment 1 Slice 3 Post-Verification Test-Alignment
Correction Authorization. This document authorizes exactly one
correction to PR #119, strictly scoped to the two named test files in
§4.1, based on the passing real-MySQL `migrate:fresh` run, the 415/25
focused-suite result, and the complete source audits recorded in §2 and
§3 — with no further correction permitted under its own authority (§6).
It does not reopen or extend Exceptional Correction 3, whose own
implementation and verification are independently proven complete (§1).
PR #119 remains Draft until this correction and its full verification
are complete (§8).*
