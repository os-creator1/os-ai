# RFC-005 Amendment 1 Slice 3 — Test-Alignment Correction 2 & Environment-Preparation Authorization

**This document is fully self-contained.** No section below requires
consulting an earlier commit or any other contract to understand this
exception's complete rules — every requirement, authorization, and
constraint is stated here in full.

**Status: authorization only. Drafting and merging this document makes
zero application changes. It does not itself perform the correction it
authorizes — the correction requires its own separate commit(s), on
PR #119 itself, made only after this document is merged to `main`.**

**This document extends the still-open, still-uncommitted correction
authorized by the merged PR #124
(`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-TEST-ALIGNMENT-CORRECTION-AUTHORIZATION.md`)
by exactly two additional lines in the same already-authorized file and
method — it does not reopen, reinterpret, or expand any other part of
PR #124's scope. It separately authorizes the exact local-only
environment-preparation steps proven necessary to obtain a genuine
full-suite pass, and separately records, without silently normalizing,
a real pre-existing `package-lock.json` dependency defect that this
document does not fix.**

---

## 0. Governance

- Drafted on branch `chore/rfc-005-amendment-1-slice-3-test-alignment-correction-2`,
  in an isolated linked worktree
  (`../rfc-005-amendment-1-slice-3-test-alignment-correction-2-worktree`),
  based on `origin/main` at `8193315ab7e5a2d5ee27b9108f4c5503af07c262`
  (the merge of PR #124).
- Concerns RFC-005 Amendment 1 Slice 3 CONTRACT
  (`docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md`), implemented
  on **PR #119** (`agent/rfc-005-amendment-1-slice-3-contract`), current
  **committed** implementation head **`9259124711818c97aad36fc746d21d809576fcb5`**
  (unchanged since Exceptional Correction 3). PR #119's working tree
  additionally carries **two uncommitted edits**, already authorized by
  merged PR #124, made and left uncommitted in a prior session:
  `tests/Feature/Usage/UsageMeterSchemaTest.php` and
  `tests/Feature/Usage/UsageMeterTransitionSchemaTest.php`. This document
  does not touch, commit, or discard those edits — it authorizes exactly
  two further lines in the first of those same two files.
- This document changes **exactly one file**: this document itself. No
  `app/`, `database/`, `tests/`, `package.json`, `package-lock.json`, or
  `docs/automation/AI-AUTONOMY-STATE.json` file is touched by drafting
  it, and it does not touch PR #119's own branch at all.

---

## 1. Runtime history and count correction

**Prior verification (PR #124's own required check), migrate:fresh
result:** passed completely. **Focused Usage suite (before PR #124's
authorized edits):** 415 passed, 25 failed, 1964 assertions.

**Latest verification, with PR #124's two authorized edits applied
locally (uncommitted):**

1. `php artisan migrate:fresh --env=testing -vvv` — **passed
   completely**, targeting `ultimatesms_testing` only.
2. `php artisan test tests/Feature/Usage --compact` — **426 passed, 14
   failed, 1995 assertions, 91.18s.**

**Count correction, reproduced directly from the saved raw test-output
log by counting each class's `FAILED` lines independently** (`grep -c
"FAILED.*<ClassName>"`):

| Test class | Failures |
|---|---|
| `NoFakePaymentControlsRenderedTest` | 2 |
| `UsageBillingDashboardAuthorizationTest` | 4 |
| `UsageBillingDashboardStripeIntegrationTest` | 2 |
| `UsageBillingDashboardViewDataTest` | 5 |
| `UsageMeterSchemaTest` | 1 |
| **Total** | **14** |

**Confirmed: 13 dashboard/view failures + 1 schema-assertion failure =
14, matching the suite's own reported `14 failed` exactly.** The prior
session's report of "nine dashboard/view failures" was an arithmetic
error and is superseded by this reproduced, independently-counted
table. `git diff --check` was clean; no commit or push was made; HEAD
remained `9259124711818c97aad36fc746d21d809576fcb5`.

---

## 2. Audit A — the remaining `UsageMeterSchemaTest` failure is stale, and a DEEPER audit found a second stale line the suite run could not yet expose

### 2.1 The one failure the suite run reported

`UsageMeterSchemaTest::test_all_six_meter_key_columns_are_varchar_128_with_compatible_collation`
failed at (post-PR-#124-edit) line 382:

```php
$this->assertSame('YES', $rows['business_usage_rate_activations']['is_nullable']);
```

Actual runtime value: `'NO'`.

**Source-proven correct value: `'NO'`.** Migration
`database/migrations/2026_08_24_120002_tighten_and_contract_business_usage_rate_activations_table.php`
line 43 executes
`$table->string('meter_key', 128)->nullable(false)->change();` on
`business_usage_rate_activations`. This is independently confirmed by
the already-passing, in-scope
`BusinessUsageRateSchemaTest::test_rate_and_activation_meter_key_are_not_null_varchar_128`
(line 115: `$this->assertSame('NO', $column->Null, "{$table}.meter_key
must be NOT NULL after Slice 3.");`, looped over **both**
`business_usage_rates` and `business_usage_rate_activations`, currently
passing 22/22 in that file).

### 2.2 A second stale line, found only by direct assertion-level source audit — not visible in any test run

PHPUnit halts a test method at its **first** failing assertion.
Line 382 failing means **line 383, the very next assertion in the same
method, has never actually executed in any run performed so far** —
its correctness cannot be inferred from "the suite only reported one
failure here." Direct source cross-reference proves it is **also**
stale:

```php
// UsageMeterSchemaTest.php, line 383 — as currently written, NOT YET corrected
$this->assertSame('YES', $rows['business_usage_reservations']['is_nullable']);
```

Migration
`database/migrations/2026_08_24_120003_tighten_meter_key_on_business_usage_reservations_table.php`
line 43 executes the identical
`$table->string('meter_key', 128)->nullable(false)->change();` on
`business_usage_reservations`. This is independently confirmed by the
already-passing, in-scope
`BusinessUsageReservationLedgerSchemaTest::test_reservation_and_ledger_meter_key_final_nullability_and_type`
(lines 325–338, explicitly named "Proof 38 (reservations/ledger
portion) — final meter_key nullability and type across both tables:
**NOT NULL on reservations**, nullable permanently on ledger entries",
asserting `assertSame('NO', $reservationColumn->Null)` at line 330 —
currently passing, 15/15, in that file).

**Line 384 — confirmed correct, requires no change:**

```php
$this->assertSame('YES', $rows['business_usage_ledger_entries']['is_nullable']);
```

No Slice 3 migration (`120001`, `120002`, or `120003`) touches
`business_usage_ledger_entries`'s own column nullability — only its
incoming foreign key (`ledger_meter_rate_foreign`) was dropped/recreated
by Exceptional Correction 3, unrelated to nullability. The same
already-passing `BusinessUsageReservationLedgerSchemaTest` (line 336:
`assertSame('YES', $ledgerColumn->Null)`) confirms `'YES'` (nullable)
remains correct and permanent for this table.

**Corrected conclusion: two lines, not one, require correction — both
in the same already-authorized file and same already-authorized test
method.** No other method in `UsageMeterSchemaTest.php` or
`UsageMeterTransitionSchemaTest.php` was found to have this
early-failure-masking risk (every other method in both files either
expects a `QueryException`/error with no assertion following it, or
asserts a single unrelated fact — confirmed by direct re-inspection of
every method body in both files).

---

## 3. Audit item 5 — exhaustive assertion-level stale-schema re-audit across all of `tests/Feature/Usage`

Direct grep for every `is_nullable`/`->Null`/nullability-style assertion
touching a meter_key-bearing table, across the complete `tests/`
directory (not limited to filenames already suspected):

| File | Assertion | Table | Expected | Status |
|---|---|---|---|---|
| `UsageMeterSchemaTest.php:379` | `is_nullable` | `usage_meters` | `'NO'` | Correct |
| `UsageMeterSchemaTest.php:380` | `is_nullable` | `usage_meter_transitions` | `'NO'` | Correct |
| `UsageMeterSchemaTest.php:381` | `is_nullable` | `business_usage_rates` | `'NO'` | Already fixed by PR #124 |
| `UsageMeterSchemaTest.php:382` | `is_nullable` | `business_usage_rate_activations` | `'NO'` | **Stale — §4 authorizes fix** |
| `UsageMeterSchemaTest.php:383` | `is_nullable` | `business_usage_reservations` | `'NO'` | **Stale — §4 authorizes fix** |
| `UsageMeterSchemaTest.php:384` | `is_nullable` | `business_usage_ledger_entries` | `'YES'` | Correct |
| `BusinessUsageRateSchemaTest.php:115` | `->Null` (loop) | `business_usage_rates`, `business_usage_rate_activations` | `'NO'` | Correct, in-scope, passing |
| `BusinessUsageReservationLedgerSchemaTest.php:330` | `->Null` | `business_usage_reservations` | `'NO'` | Correct, in-scope, passing |
| `BusinessUsageReservationLedgerSchemaTest.php:336` | `->Null` | `business_usage_ledger_entries` | `'YES'` | Correct, in-scope, passing |
| `UsageMeterBackfillPreflightTest.php:496,521,523` | `->Null` | reservations/rates/activations, mid-migration-transition assertions | contextual (transition test) | Correct, in-scope, passing |

No `tests/` file outside `tests/Feature/Usage/` contains any assertion
referencing `meter_key`, `business_usage_rates`, or the other Slice-3
tables' nullability at all (confirmed: a repository-wide grep for
`is_nullable` found matches only in unrelated domains —
`Opportunity`, `Entitlement`, `Workspace` — each testing an unrelated
column on an unrelated table, none referencing any Slice 3 table).

The `feature_key`-on-`business_usage_rates`/`business_usage_rate_activations`
audit already performed and recorded in PR #124 §3 is not repeated here
in full; it remains valid and is not reopened by this document — this
section adds only the assertion-level nullability check PR #124's own
audit did not perform.

**Result: exactly two stale assertions exist in the complete `tests/`
tree beyond what PR #124 already authorized — both named in §2.2, both
in the single already-authorized file `UsageMeterSchemaTest.php`, both
in the single already-authorized method. No other stale pre-Slice-3
schema expectation exists anywhere in `tests/`.**

---

## 4. Audit B — Blade Icons failure: true root cause is NOT a missing icon-manifest cache

### 4.1 What the 13 dashboard/view failures actually are

All 13 fail identically with:

```
Illuminate\Contracts\Container\BindingResolutionException:
Unresolvable dependency resolving [Parameter #1 [ <required> string $manifestPath ]]
in class BladeUI\Icons\IconsManifest
```

This is **not** blade-icons' own "manifest file does not exist"
condition (that failure mode, if it occurred, would surface differently
— inside `IconsManifest`'s own `get()`/`build()` logic, which tolerates
a missing manifest file and rebuilds on demand). This is Laravel's
**container** failing to autowire `IconsManifest` directly, which only
happens when its constructor is invoked **without** going through the
explicit singleton binding that `BladeIconsServiceProvider::register()`
defines:

```php
// vendor/blade-ui-kit/blade-icons/src/BladeIconsServiceProvider.php
$this->app->singleton(IconsManifest::class, function (Application $app) {
    return new IconsManifest($app->make(Filesystem::class), $this->manifestPath(), ...);
});
```

That binding only exists if `BladeIconsServiceProvider::register()` has
actually run — which requires Laravel to know the provider exists,
which (for auto-discovered packages) comes from
`bootstrap/cache/packages.php` / `bootstrap/cache/services.php`.

### 4.2 Direct proof: the tracked, committed bootstrap cache is stale relative to the tracked, committed `composer.lock`

```
grep -c "BladeIconsServiceProvider\|SentinelServiceProvider\|BladeLucideIconsServiceProvider" \
    bootstrap/cache/services.php bootstrap/cache/packages.php
```
returns **`0`** for both files, at the current committed HEAD. Both
files are genuinely **tracked** by this repository (`git ls-files
bootstrap/cache/services.php bootstrap/cache/packages.php` returns both
paths; `.gitignore` has no `bootstrap/cache` entry at all) — this is
not the Laravel-default gitignored setup. Yet `composer.lock` (also
tracked, also unmodified) already includes `blade-ui-kit/blade-icons`,
`laravel/sentinel`, and `technikermathe/blade-lucide-icons` as
dependencies.

This exact discrepancy — and its fix — was already independently
observed and proven, from real execution, in the prior session that
produced PR #119's current runtime-verified state: running
`composer install` regenerates both files via its own,
already-established `post-autoload-dump` script
(`composer.json` lines 132–135: `"post-autoload-dump":
["Illuminate\\Foundation\\ComposerScripts::postAutoloadDump", "@php
artisan package:discover --ansi"]`), and the regenerated files add
**exactly** these three missing providers — nothing else. That
regeneration was performed once already in this engagement, produced
this exact three-provider diff, and was correctly discarded (per the
governance instruction active at the time) before any commit — leaving
today's committed HEAD in the same stale state.

### 4.3 Exact, source-proven environment-preparation step

**`php artisan package:discover --ansi`** (equivalently, letting
`composer install` run its own already-declared `post-autoload-dump`
script) — this is Laravel's own standard, already-established package
lifecycle command, not an invented one. No `icons:cache` Artisan
command (confirmed present at
`vendor/blade-ui-kit/blade-icons/src/Console/CacheCommand.php`,
signature `icons:cache`) is required to fix this specific failure — that
command generates a separate, optional icon-**set** manifest cache
file used for filesystem-scan performance, and cannot itself run
correctly (or matter) while the service provider that defines it is
unregistered. It is not part of this authorization.

### 4.4 Tracked-file impact

Regenerating `bootstrap/cache/packages.php` and
`bootstrap/cache/services.php` **does** produce a tracked-file diff
(both are committed paths), exactly as it did for the same two files
during PR #119's Composer-dependency-installation step earlier in this
engagement. This is handled identically here: generate locally for the
verification run, discard via `git restore --source=HEAD -- <exact
paths>` before any commit, exactly mirroring §4.3 of PR #124's own
frontend-build-output handling.

---

## 5. Audit C — the `webpack`/`laravel-mix` incompatibility is a genuine, reproducible `package-lock.json` defect, not merely a local quirk

### 5.1 Evidence

- `package.json` line 16: `"laravel-mix": "^6.0.6"` — installed version
  `6.0.49`, within range; not itself the problem.
- `package-lock.json` (`lockfileVersion: 3`) pins the resolved
  `node_modules/webpack` entry to exactly **`5.109.2`** (directly
  confirmed by reading the lockfile's own `"node_modules/webpack"`
  entry). `npm ci` is deterministic from this lockfile — **every clean
  checkout of this exact lockfile will resolve the identical
  `5.109.2`, every time, on any machine.**
- `node_modules/laravel-mix@6.0.49`'s own `package.json` declares
  `"webpack": "^5.60.0"` as its dependency range — a range broad enough
  to legitimately admit `5.109.2` under semver, **but laravel-mix's own
  bundled code is not actually compatible with every version inside
  that range it declares**: `node_modules/laravel-mix/src/webpackPlugins/BuildOutputPlugin.js`
  line 6 does `const { formatSize } = require('webpack/lib/SizeFormatHelpers')`
  — an internal, undocumented webpack module. It exists in
  `webpack@5.76.0` (confirmed present, and a working build, in the
  independent sibling checkout `../public_html`, which already has
  `node_modules/webpack` at that exact version) and does **not** exist
  in `webpack@5.109.2` (confirmed absent by direct file-listing check
  in this exact worktree).
- `package.json` has no `overrides` or `resolutions` field of any kind.
  No `.nvmrc`, no `engines` field, and no doc anywhere in
  `docs/automation/` records a known webpack-version pin or fix — only
  `docs/automation/DESIGN-SYSTEM-CONTRACT.md` confirms Laravel Mix 6 is
  the intended, documented build tool, with no version caveat.

### 5.2 Conclusion — genuine, reproducible defect; no already-supported repository route exists

**`npm ci` followed by `npm run production` is provably expected to
fail, identically, on any clean checkout of this exact committed
`package-lock.json`** — this is not specific to this session's Node
version, this machine, or any transient state; it follows deterministically
from the tracked lockfile's own pinned resolution. **This is a real
pre-existing dependency-lock defect in `package-lock.json`, not merely
an environment-preparation gap** — the proper permanent fix is a
dedicated future correction that adds an explicit `overrides` (npm ≥8)
or equivalent pin for `webpack` to a `laravel-mix`-compatible patch
(`webpack@5.76.0` is proven compatible; the precise minimum/maximum
compatible range is not determined by this audit and is left to that
future correction) and regenerates the lockfile — **this document does
not perform or authorize that fix**, per the explicit instruction that
`package.json`/`package-lock.json` may not be touched under this
authority.

**No already-supported local-only build route was found documented
anywhere in the repository.** The only reason a working build was
obtainable in this session was a `node_modules`-only, `--no-save
--no-package-lock` local install of `webpack@5.76.0` — leaving both
tracked files provably byte-identical to HEAD (verified before and
after) — which is authorized narrowly in §6.3 below **specifically
because** it: (a) installs a version inside laravel-mix's own declared
compatible range, (b) is empirically proven to produce a correct build
in this exact worktree, matching independently-observed working state
in a sibling checkout, and (c) touches zero tracked files. This
authorization is **not** a statement that the underlying
`package-lock.json` defect is acceptable or resolved — §5's finding
stands on its own as a separate, unresolved repository defect requiring
its own future decision.

---

## 6. Authorization

### 6.1 Extension of PR #124's test-alignment correction — exactly two additional lines

**Authorizes** correcting exactly these two additional assertions,
within the single file and single method already authorized by PR #124,
in addition to (not instead of) PR #124's own already-authorized edits
(which remain authorized and are not re-described here):

**File:** `tests/Feature/Usage/UsageMeterSchemaTest.php`
**Method:** `test_all_six_meter_key_columns_are_varchar_128_with_compatible_collation`

1. Line 382: change `$this->assertSame('YES', $rows['business_usage_rate_activations']['is_nullable']);`
   to `$this->assertSame('NO', $rows['business_usage_rate_activations']['is_nullable']);`.
2. Line 383: change `$this->assertSame('YES', $rows['business_usage_reservations']['is_nullable']);`
   to `$this->assertSame('NO', $rows['business_usage_reservations']['is_nullable']);`.

No other line in this method, this file, or
`tests/Feature/Usage/UsageMeterTransitionSchemaTest.php` may change
under this authority. **The path scope remains exactly the same two
files already authorized by PR #124 — this document adds no new path.**

### 6.2 Environment preparation — bootstrap cache regeneration

**Authorizes**, as a local-only verification-preparation step, running
`php artisan package:discover --ansi` (or the equivalent effect of a
`composer install` run), to regenerate
`bootstrap/cache/packages.php` and `bootstrap/cache/services.php` from
the current, unmodified, tracked `composer.lock`. Both files **must**
be restored to their exact HEAD content via
`git restore --source=HEAD -- bootstrap/cache/packages.php
bootstrap/cache/services.php` after verification and **before any
commit**. Neither file may be committed under this authority.

### 6.3 Environment preparation — frontend build

**Authorizes** the same frontend-build procedure PR #124 §4.3/§5.B
already authorized: `npm run production` (after `npm ci` if
`node_modules/` is absent), with the resulting `public/mix-manifest.json`
/ `public/js/**` / `public/css/**` / `public/css-rtl/**` diff discarded
before any commit, exactly as before.

**Additionally and narrowly authorizes**, solely to make that build
command succeed given the proven `package-lock.json` defect (§5):
a **local-only**, `node_modules`-scoped reinstall of
`webpack@5.76.0` via `npm install webpack@5.76.0 --no-save
--no-package-lock`, run *before* `npm run production`. This is
authorized strictly because §5.2 proves it touches zero tracked files
and installs a version within `laravel-mix`'s own declared compatible
range. **This is not a fix to the `package-lock.json` defect and does
not authorize any change to `package.json` or `package-lock.json`.**
The defect itself remains open and unaddressed by this document —
flagged in §5.2 for a separate, future, dedicated correction.

### 6.4 Scope boundaries — unchanged from PR #124, restated

- **No `app/` file may be changed.**
- **No `database/migrations/*` file may be changed.**
- **No model or repository file may be changed.**
- **No other `tests/Feature/Usage/*` file may be changed.**
- **`package.json` and `package-lock.json` may not be changed** under
  this authority, under any circumstance — including to "fix" the §5
  defect. That requires its own separate future authorization.
- **`webpack.mix.js` may not be changed.**
- **No generated/regenerated build or cache output
  (`public/mix-manifest.json`, `public/js/**`, `public/css/**`,
  `public/css-rtl/**`, `bootstrap/cache/packages.php`,
  `bootstrap/cache/services.php`) may be committed to PR #119** under
  any circumstance.
- `docs/automation/AI-AUTONOMY-STATE.json` is not touched.

---

## 7. Required verification sequence after the correction

Locked order, on PR #119, after applying §6.1's two additional edits on
top of PR #124's still-uncommitted two edits, before any commit:

1. **Local runtime preparation**: `vendor/` and `.env.testing` present
   (already established); `node_modules/` present (`npm ci` if absent).
2. **Bootstrap cache regeneration** (§6.2): `php artisan package:discover --ansi`.
3. **Frontend build** (§6.3): local-only `webpack@5.76.0` reinstall,
   then `npm run production`.
4. **`php artisan migrate:fresh --env=testing -vvv`** — must pass, on
   `ultimatesms_testing` only.
5. **`php artisan test tests/Feature/Usage --compact`** — must pass
   **in full** — zero failures, zero errors, genuine positive count
   reported. If any failure remains, this document's authority is
   exhausted (§8) — report and stop.
6. **`git diff --check`** — must be clean.
7. **Discard all generated output**: restore
   `bootstrap/cache/packages.php`, `bootstrap/cache/services.php`, and
   every `public/**` path the build touched, to exact HEAD content, via
   exact-path `git restore --source=HEAD`, never a broad `git checkout .`
   or `git clean`. Must not revert any of the four authorized test
   edits (PR #124's two plus this document's two).
8. **Confirm tracked changes are exactly** the two files named in
   PR #124 §4.1 (`UsageMeterSchemaTest.php`,
   `UsageMeterTransitionSchemaTest.php`) — nothing else.
9. **Confirm PR #119's cumulative diff against `origin/main`** equals
   exactly the fourteen-path allowlist already locked by
   `docs/automation/RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md` §10 — no
   new path is added by this document.
10. **Report exact test/assertion counts**, honestly, with no count
    fabricated.

---

## 8. No further correction under this exception

This exception authorizes **exactly one** additional correction pass —
the two lines in §6.1 — plus the environment-preparation steps in §6.2
and §6.3. It does not reopen, reset, or extend PR #124, Exceptional
Correction 3, or any prior correction round, and it is not renewable.

**If, after applying §6.1's two edits and the §6.2/§6.3 preparation,
`migrate:fresh` fails, the Usage suite still fails for any reason
(including a reason not identified by this audit), or any evidence of
an actual production/schema defect is found** — PR #119 must stop and
be reported for a new, separate human decision.

---

## 9. Stop conditions

- Any path beyond the two lines in §6.1 is found necessary to edit.
- Any `app/`, `database/migrations/*`, other `tests/Feature/Usage/*`,
  `package.json`, `package-lock.json`, or `webpack.mix.js` file is
  found to require a change.
- The bootstrap-cache regeneration (§6.2) is found to require any
  change beyond restoring the exact three providers already identified,
  or produces any other diff.
- `php artisan migrate:fresh` fails for any reason.
- The focused Usage suite does not pass in full after the correction.
- Any generated output cannot be cleanly discarded back to its
  `origin/main` state before commit.

---

## 10. PR #119 status

**PR #119 remains Draft.** It must not be merged until this document is
merged, the correction (§6.1) and environment preparation (§6.2/§6.3)
are performed, and all required verification (§7) passes.

No merge decision is authorized by this document. Human-only merge
applies to both this document and PR #119. Codex review and the
automatic AI Subscription Loop remain disabled.

PR #107 / RFC-005 Milestone 5 remains unselected, unauthorized, and
completely untouched by this document.

---

## 11. Verification and publication (this document only)

1. `git status --short` — exactly one untracked path, this document,
   nothing else, nothing staged.
2. Stage the one file by its exact path only, never `git add -A`/`.`.
3. Commit exactly:
   `docs: authorize RFC-005 Amendment 1 Slice 3 test-alignment correction 2`.
4. Push normally to
   `origin chore/rfc-005-amendment-1-slice-3-test-alignment-correction-2`.
   No force push. Do not push `main`.
5. Open a Draft PR into `main` if tooling permits; otherwise report the
   exact GitHub comparison URL.
6. **Do not merge. Do not begin the correction this document
   authorizes.**
7. PHP/JS tests are not required for this one-file docs-only change and
   are not run — reported honestly as not run, no count fabricated.

---

*End of RFC-005 Amendment 1 Slice 3 Test-Alignment Correction 2 &
Environment-Preparation Authorization. This document authorizes exactly
two additional test-assertion corrections (§6.1), within the file and
method already authorized by PR #124, plus two local-only,
zero-tracked-file-impact environment-preparation steps (§6.2, §6.3) —
one of which (§6.3's webpack reinstall) exists only because of a
separately-recorded, unresolved `package-lock.json` defect (§5) that
this document explicitly does not fix. PR #119 remains Draft until the
correction and its full verification are complete (§10).*
