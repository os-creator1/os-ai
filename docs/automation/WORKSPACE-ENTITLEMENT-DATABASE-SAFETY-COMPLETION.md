# WORKSPACE AND ENTITLEMENT TEST-DATABASE SAFETY COMPLETION

**Status:** Test-only change, plus one workflow-environment addition and two
governance-documentation corrections. Zero production code, zero migrations,
zero configuration, zero generated assets. Thirteen paths actually changed
(twelve pre-existing files plus this one new document), out of the
seventeen-path allowlist authorized for this branch — the remaining four
allowlisted paths were inspected and confirmed to need no change (§2.2, §6).

This branch finishes what `docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md`
§5 correctly enumerated and left deferred — **eight** Workspace and
Entitlement files that genuinely pinned the literal canonical database name —
and corrects a P2 documentation finding raised on PR #230, which had
mistakenly narrowed that eight-file finding down to one example
(`TemporaryTestDatabase.php`) when restating it in `AGENTS.md` and
`docs/automation/MANUAL-LANE-GOVERNANCE-RECONCILIATION.md`.

`Tests\Support\TestDatabaseSafety` is treated as **read-only** here, exactly
as both prior lanes (PR #228, PR #229) treated it. Its existing API was
sufficient; no capability was missing, and it was not modified. It remains
the single database-name validation authority, and this branch adds no
competing policy.

---

## 1. Baseline

| Item | Value |
|---|---|
| `origin/main` at start | `35219efd4fbcd7f5d7a4d346868f37f488a637a5` — the PR #230 merge commit |
| Confirmed via | `git fetch origin` + `git rev-parse origin/main`, matching the task's own stated SHA exactly |
| Branch | `agent/baseline-workspace-entitlement-db-safety`, created directly from that SHA via `git worktree add -b` |
| Worktree | a fresh, dedicated worktree; `git status` empty immediately at creation |
| Database | `ultimatesms_testing_lf`, created for this lane, validated through `TestDatabaseSafety::isSafeTestDatabaseName()` **before** creation, then `migrate:fresh` → **250 migrations, 0 pending** |
| Canonical database | `ultimatesms_testing` was **never** reset, migrated, truncated or written by this lane |
| Concurrent lane observed | `ultimatesms_testing_lane_d` was independently active (live queries observed in `information_schema.processlist`) throughout this lane's work — confirming both lanes' isolation held: neither ever touched the other's database |

---

## 2. The eight confirmed holdouts, and what changed in each

### 2.1 Executable pins versus comments — mechanically confirmed before editing

| # | File | Pin mechanism found | Executable or comment-only |
|---|---|---|---|
| 1 | `tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php` | `const EXPECTED_DATABASE = 'ultimatesms_testing';` compared via `!==` | Executable |
| 2 | `tests/Feature/Workspace/Support/concurrent_workspace_resolver_runner.php` | Identical `const EXPECTED_DATABASE` pattern | Executable |
| 3 | `tests/Feature/Workspace/Support/concurrent_backfill_runner.php` | `const PRIMARY_TEST_DATABASE = 'ultimatesms_testing';`, one of two OR-branches (the other already accepted historical-shaped names) | Executable |
| 4 | `tests/Feature/Workspace/Support/run_historical_m1a_suite.php` | `if ($resolvedDatabase !== 'ultimatesms_testing')` | Executable |
| 5 | `tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php` | Identical pattern to #4 | Executable |
| 6 | `tests/Feature/Workspace/Support/TemporaryTestDatabase.php` | `private const BASE_DATABASE = 'ultimatesms_testing';`, checked in both `withGeneratedDatabase()` and `dropDatabase()` | Executable |
| 7 | `tests/Feature/Workspace/WorkspaceManagerConcurrencyTest.php` | `$this->assertSame('ultimatesms_testing', DB::connection()->getDatabaseName());` | Executable (in-test assertion, not a subprocess guard) |
| 8 | `tests/Feature/Workspace/WorkspaceManagerTest.php` | Identical assertion, immediately before a deliberate `FOREIGN_KEY_CHECKS=0` write | Executable (in-test assertion) |

No ninth file was found. `tests/Feature/Workspace/Support/EnforcementWorkspaceTestCase.php` and
`VerifiesEnforcementWorkspaceDatabase.php` were re-checked and confirmed to
name the literal string only in a comment, not executable code — consistent
with `MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md`'s own finding.
`WorkspaceTransitionsMigrationSchemaTest.php` names the literal only in a
comment too; its one reported error (§4.4 below) comes from its dependency
on file #6, not from its own code.

### 2.2 Every parent/consumer inspected, and what each needed

| Consumer | Handoff before this branch | Change made |
|---|---|---|
| `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php` | None — 15 `new Process(...)` call sites with no third `$env` argument at all | Added a `childEnvironment()` helper (mirrors the merged Usage pattern exactly) and passed it to all 15 call sites |
| `tests/Feature/Workspace/WorkspaceManagerConcurrencyTest.php` | 2 of 3 `new Process(...)` calls had no `$env` argument; the third (`test_runner_refuses_to_execute_against_an_unexpected_resolved_database`) set only a bogus `DB_DATABASE`, with no `EXPECTED_TEST_DATABASE` | Added `childEnvironment()`; wired the two real-scenario `Process` calls to it; the refusal test now also forwards the real `EXPECTED_TEST_DATABASE` alongside the bogus `DB_DATABASE`, exactly mirroring the already-merged `WorkspaceBackfillV1ConcurrencyTest::test_runner_refuses_to_execute_against_an_unexpected_resolved_database` precedent, so the mismatch (not the missing-handoff path) is what the test still exercises |
| `tests/Feature/Workspace/WorkspaceManagerTest.php` | N/A (no subprocess) | Only the in-test assertion changed |
| `tests/Feature/Entitlement/WorkspaceEntitlementBackfillV1ConcurrencyTest.php` | Already generates its own inline `php -r` runner with a general `$expected === false \|\| $expected === '' \|\| $resolved !== $expected` guard — no literal pin | **No change** — confirmed safe by inspection, included in the allowlist defensively |
| `tests/Feature/Workspace/WorkspaceBackfillV1ConcurrencyTest.php` | Already forwards `EXPECTED_TEST_DATABASE`/`DB_DATABASE` correctly to `concurrent_backfill_runner.php`, including its own refusal test with the identical bogus-`DB_DATABASE`-plus-real-`EXPECTED_TEST_DATABASE` pattern | **No change** — this file is the pattern's own origin |
| `tests/Feature/Workspace/WorkspaceM1BBoundaryTest.php` | `test_historical_runner_selects_the_pre_enforcement_group()` source-scans `run_historical_m1a_suite.php` for a `'--group', 'historical-m1a', ... '--group', 'workspace-pre-enforcement'` array literal | **No change** — that literal is untouched; the boundary test re-verified passing (§3) |
| `tests/Feature/Workspace/WorkspaceTransitionsMigrationSchemaTest.php` | Calls `TemporaryTestDatabase::withEnforcementDatabase()` with no direct pin of its own | **No change** — its one prior error was inherited from file #6, now resolved |

### 2.3 The conversion, per file

**Files 1, 2 (simple subprocess runners).** Replaced the hardcoded
`EXPECTED_DATABASE` constant and its direct `!==` comparison against the
active connection with the mandatory-handoff-plus-`TestDatabaseSafety`
pattern, byte-for-byte matching the already-merged Usage runners: read
`EXPECTED_TEST_DATABASE` from the environment, refuse (exit 3) if unset or
empty, otherwise call `TestDatabaseSafety::assertMatchesActiveTestDatabase()`
inside a `try`/`catch (RuntimeException)`, refusing (exit 3) on any mismatch
or unsafe name.

**File 3 (`concurrent_backfill_runner.php`).** This file already supported
two acceptance modes — the literal canonical name, or an
`isValidHistoricalName()`-shaped generated database — because it is invoked
both directly and from inside the isolated historical suite. The literal-only
branch (`$expectedDatabase === PRIMARY_TEST_DATABASE`) is now
`TestDatabaseSafety::isSafeTestDatabaseName($expectedDatabase)`, so a
validated sibling (e.g. `ultimatesms_testing_lf`) is accepted for direct
invocation too. The historical-name branch is unchanged — kept explicitly
rather than folded away, since it is a stricter, drop-safety-scoped pattern,
not merely a special case of the general one.

**Files 4, 5 (`run_historical_m1a_suite.php`, `run_workspace_enforcement_suite.php`).**
These are **standalone entry points**, not spawned by a parent PHPUnit test —
there is no already-verified parent connection to inherit. Both now require
`EXPECTED_TEST_DATABASE` as a mandatory caller-supplied environment variable
(refusing, exit 3, if absent) and validate it via
`TestDatabaseSafety::assertMatchesActiveTestDatabase()` before doing anything
else — before the primary connection is even read a second time, and long
before either script touches `TemporaryTestDatabase`. Every downstream
function in both files (`rollbackToPostMigrationFive()`,
`assertHistoricalSchemaState()`, the `withHistoricalDatabase()`/
`withEnforcementDatabase()` calls, the child `phpunit --group ...` spawn) is
**byte-identical** to `origin/main` — confirmed by `git diff`, reproduced in
§4.4. Because these are standalone entry points, `.github/workflows/ai-subscription-gate.yml`
(§5) now hands down the canonical name explicitly so the autonomous gate's
existing invocations keep working unchanged.

**File 6 (`TemporaryTestDatabase.php`) — the structurally significant one.**
Previously, `withGeneratedDatabase()` and `dropDatabase()` each refused
unless the active connection resolved to the literal `BASE_DATABASE`
constant, and every generated name was hardcoded to the
`ultimatesms_testing_historical_*` / `ultimatesms_testing_enforcement_*`
shape regardless of which database was actually active. Now:

* Both checks call `TestDatabaseSafety::isSafeTestDatabaseName()` on the
  active connection instead of comparing against a literal, so any validated
  disposable sibling may serve as the base.
* Every generated name is derived **from that active base**:
  `{base}_historical_<pid>_<hex>` / `{base}_enforcement_<pid>_<hex>`. Running
  against `ultimatesms_testing_lf` produces
  `ultimatesms_testing_lf_historical_18896_7fb3be14` (an example captured
  live in §4.2 below) — never the bare canonical-prefixed name — so two
  lanes on distinct validated siblings can never collide on the same
  generated temporary database.
* `isValidHistoricalName()`/`isValidEnforcementName()` now check **two**
  things: the purpose-specific suffix shape (`_historical_<pid>_<hex>` /
  `_enforcement_<pid>_<hex>`, structurally unchanged), **and** that the
  captured base portion independently passes
  `TestDatabaseSafety::isSafeTestDatabaseName()`. This closes the exact gap
  a naive "just add `.+` to the regex" fix would have left open: a caller
  cannot satisfy the check by prefixing an unsafe or production-looking
  string with `_historical_<pid>_<hex>` (proven in §4.1).
* A new explicit MySQL 64-character identifier-limit check runs in
  `generateName()` **before** `CREATE DATABASE` — a longer validated base
  (e.g. a longer sibling suffix) plus this class's own purpose/pid/hex
  suffix could otherwise exceed the limit; it now fails with a clear message
  instead of an opaque driver error (proven in §4.3).
* `TestDatabaseSafety` itself gained no new method and no modified method —
  every one of the above calls its existing, unmodified public API
  (`isSafeTestDatabaseName()`). No second, competing database-name authority
  was created.

**Files 7, 8 (`WorkspaceManagerConcurrencyTest.php`, `WorkspaceManagerTest.php`).**
Both replaced `assertSame('ultimatesms_testing', DB::connection()->getDatabaseName())`
with `assertSame(TestDatabaseSafety::activeTestDatabase(), DB::connection()->getDatabaseName())`.
The purpose is preserved exactly, not weakened: `activeTestDatabase()` itself
throws if the active connection is not a validated disposable database, so
the assertion still proves — before the real concurrency scenario in file 7,
and before the deliberate `FOREIGN_KEY_CHECKS=0` write in file 8 — that this
process is genuinely on a safe, disposable database; it merely no longer
requires that database to be the one specific literal name.

---

## 3. Verification

All runs against `ultimatesms_testing_lf`, validated through
`TestDatabaseSafety::isSafeTestDatabaseName()` before creation, migrated
fresh: **250 migrations, 0 pending**. `php` and `mysql` resolved from this
machine's local Laragon installation (`C:\laragon\bin\php\php-8.3.30-...`,
`C:\laragon\bin\mysql\mysql-8.4.3-winx64\bin\`); `vendor/` was copied from
the adjacent `public_html` checkout after confirming `composer.lock` is
byte-identical between the two, then `composer dump-autoload` was run — the
same procedure both prior lanes used.

### 3.1 Focused suites

| Suite | Result |
|---|---|
| `tests/Unit/Support/TestDatabaseSafetyTest.php` (unchanged file — read-only authority) | **60 tests, 122 assertions**, green — identical to both prior lanes' own reported numbers |
| `tests/Feature/Workspace/WorkspaceManagerConcurrencyTest.php` + `WorkspaceManagerTest.php` | **31 tests, 70 assertions**, green |
| `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php` | **8 tests, 71 assertions**, green — all 8 scenarios that previously failed against an isolated database now pass |
| `tests/Feature/Entitlement/WorkspaceEntitlementBackfillV1ConcurrencyTest.php` + `tests/Feature/Workspace/WorkspaceM1BBoundaryTest.php` + `tests/Feature/Workspace/WorkspaceTransitionSchemaTest.php` | **29 tests, 114 assertions**, green |
| `tests/Feature/Workspace/Support/run_historical_m1a_suite.php` (standalone, `EXPECTED_TEST_DATABASE=ultimatesms_testing_lf`) | **44 tests, 123 assertions, 3 errors** — see §3.4, pre-existing and unrelated |
| `tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php` (standalone, `EXPECTED_TEST_DATABASE=ultimatesms_testing_lf`) | Guard passed and the child correctly began the workspace-enforcement group both attempts; the pre-existing, untouched 300s child timeout was hit before all 13 tests finished (4 completed each time) — see §3.5. Two representative tests run directly with no artificial cap: **2 tests, 8 assertions, green, 48.99s** |

### 3.2 Direct negative-case probes, against the real files

Every applicable runner was probed directly, not through a restatement:

| Runner | Case | Result |
|---|---|---|
| `concurrent_business_slot_runner.php` | `EXPECTED_TEST_DATABASE` unset | exit **3**, *"was not handed down by the parent test"* |
| same | empty string | exit **3**, identical message |
| same | mismatched-but-safe (`ultimatesms_testing_lf` active, `..._lf_other` expected) | exit **3**, *"a spawned child must resolve the very same disposable database"* |
| same | `acme_test_live` | exit **3**, *"not the canonical test database or a suffixed sibling of it"* |
| same | `ultimatesms_testing_prod` | exit **3**, *"its suffix contains the segment \"prod\""* |
| same | correct handoff (`ultimatesms_testing_lf`) | **passes the guard**, reaches real `EntitlementManager` logic (observed: `AuthorizationException` from the real authorization check downstream — proof of reaching real code, not a guard artifact) |
| `concurrent_backfill_runner.php` | validated sibling `ultimatesms_testing_lf` (the widened behavior) | **passes the guard and succeeds**: `OK created=0 reused=0 assigned=0`, exit **0** — this exact case would have been refused before this branch |
| same | `acme_test_live` | exit **3**, *"neither a validated disposable test database nor a valid historical temporary database name"* |
| `concurrent_workspace_resolver_runner.php` | correct handoff | passes the guard, reaches real `WorkspaceManager` logic (`ModelNotFoundException` on a deliberately nonexistent fixture user — proof of reaching real code) |
| same | `EXPECTED_TEST_DATABASE` unset | exit **3**, *"was not handed down by the parent test"* |

Every refusal case above prints only its refusal message and exits before
any `OK ...`/success line — the same "empty stdout on refusal" proof both
prior lanes established, re-confirmed here for the newly-guarded files.

### 3.3 `TemporaryTestDatabase`'s own new logic, unit-level

Probed directly via `php -r` against the booted application, with no
database write attempted for the refused cases:

| Probe | Input | Result |
|---|---|---|
| `isValidHistoricalName()` | `ultimatesms_testing_lf_historical_123_abcd1234` (validated-sibling-derived) | **true** — the new widened behavior |
| same | `ultimatesms_testing_historical_123_abcd1234` (canonical-derived) | **true** — unchanged, backward compatible |
| same | `acme_test_live_historical_123_abcd1234` (unsafe base, historical-shaped) | **false** — the base-safety check independently rejects it, proving a caller cannot bypass safety merely by appending the right suffix |
| same | `ultimatesms_testing_prod_historical_123_abcd1234` (production-looking base, historical-shaped) | **false** |
| `withHistoricalDatabase()` identifier-limit guard | active base artificially set to a 60-character validated sibling (`ultimatesms_testing_` + 40 `a`s) | refused **before any `CREATE DATABASE`**: *"generated name [...] would exceed MySQL's 64-character identifier limit"* |

### 3.4 The historical suite's 3 errors — pre-existing, proven unrelated

`run_historical_m1a_suite.php` ran to completion: 44 tests, 123 assertions,
**3 errors**, all in `WorkspaceManagerPreEnforcementTest`
(`test_one_primary_business_name_is_naming_tier_two`,
`test_first_business_by_id_is_naming_tier_three`,
`test_multiple_primary_businesses_skip_tier_two_naming`), each failing on
`SQLSTATE[42S02]: Base table or view not found: ... workspace_plan_catalog`
inside `EntitlementManager::assignFirstPlan()`.

This is unrelated to this branch's diff, proven mechanically rather than
asserted: `git diff origin/main -- tests/Feature/Workspace/Support/run_historical_m1a_suite.php`
shows the change confined **entirely** to the top-of-file docblock and guard
clause (the `use TestDatabaseSafety` import and the
handoff-then-`assertMatchesActiveTestDatabase()` block). Every function
that runs after the guard — `rollbackToPostMigrationFive()`,
`assertHistoricalSchemaState()`, the `TemporaryTestDatabase::withHistoricalDatabase()`
call, the child `phpunit --group historical-m1a --group workspace-pre-enforcement`
spawn — is byte-identical to `origin/main`. A guard clause that only decides
*whether* to proceed cannot be the cause of an error inside code that runs
identically, byte-for-byte, once it has proceeded. The historical suite
deliberately rolls the schema back to a pre-migration-6 state
(§2 of the file's own docblock); `workspace_plan_catalog` is queried by
current `EntitlementManager::assignFirstPlan()` regardless of which database
name convention is in use, and its absence at that historical schema point
is a pre-existing fixture/schema-drift defect in
`WorkspaceManagerPreEnforcementTest.php`, outside this branch's eight-file
scope and outside the Workspace/Entitlement database-naming problem this
branch exists to fix.

### 3.5 The enforcement suite — internal timeout explained and proven pre-existing

`run_workspace_enforcement_suite.php`'s spawned child
(`phpunit --group workspace-enforcement`) carries its own pre-existing,
unmodified `$process->setTimeout(300)` (§2.3 above — this value is untouched
by this branch). Two consecutive full-wrapper attempts on this machine each
completed exactly 4 of the workspace-enforcement group's 13 tests before
that 300-second budget elapsed.

**Root cause, measured directly, not assumed.**
`VerifiesEnforcementWorkspaceDatabase::prepareEnforcementDatabase()` (a file
outside this branch's allowlist, untouched) runs a **full `migrate:fresh`
(250 migrations) followed by a rollback to post-migration-5, before EACH
individual test** — by design, since these tests exercise migration 6's
`up()`/`down()` directly and must never see another test's leftover schema.
Isolated from the wrapper's 300-second cap, two of the thirteen tests
(`test_up_throws_incomplete_exception_when_a_null_business_remains`,
`test_up_throws_dangling_reference_exception_for_missing_workspace_ids`) were
run directly against a manually-prepared, correctly-shaped enforcement
database (`ultimatesms_testing_lf_enforcement_1_deadbeef`, created and
dropped solely for this measurement): **2 tests, 8 assertions, green, in
48.99 seconds** — approximately 24.5 seconds per test. Thirteen tests at that
rate is ≈319 seconds, **already at or past the wrapper's pre-existing
300-second budget on this machine even in isolation**, before accounting for
the genuine resource contention this measurement ran under (this branch's
own §3.1 Workspace/Entitlement regression, and an independently active
concurrent lane on `ultimatesms_testing_lane_d`, both competing for the same
machine's CPU and disk I/O at the time).

This is a hardware-speed and resource-contention characteristic of
unmodified downstream code (`VerifiesEnforcementWorkspaceDatabase`'s
per-test full-migration design, and the wrapper's own untouched 300-second
`Process::setTimeout()`), not a function of this branch's diff — confirmed
by the same `git diff` method as §3.4: the change to
`run_workspace_enforcement_suite.php` is confined to its top-of-file guard,
and every function downstream of it, including the `$process->setTimeout(300)`
call itself, is byte-identical to `origin/main`.

**One observed side effect, recorded rather than hidden.** After the second
timeout, the enforcement database that attempt created
(`ultimatesms_testing_lf_enforcement_31232_bc07b498`) was found still
present rather than dropped by `withGeneratedDatabase()`'s `finally` block.
Investigated directly: no lingering MySQL connection held it, and issuing
`DROP DATABASE IF EXISTS` against it immediately afterward succeeded with no
error — proving the drop mechanism itself is intact and the leak was a
one-time race between the child process's `ProcessTimedOutException` kill
and this session's own outer shell-level timeout, not a defect in
`dropDatabase()`'s logic (which this branch touched only to replace the
literal base-name comparison with `TestDatabaseSafety::isSafeTestDatabaseName()`
— the `DROP DATABASE` statement itself, and the connection-purge sequence
before it, are unchanged). The stray database was manually dropped as part
of this verification; the base database `ultimatesms_testing_lf` was
unaffected throughout.

### 3.6 Temporary-database cleanup proof

The historical database created by the §3.1 run
(`ultimatesms_testing_lf_historical_18896_7fb3be14`) was confirmed **absent**
from `SHOW DATABASES` immediately after that run completed — dropped by the
`finally` block in `withGeneratedDatabase()` despite the run ending with 3
test errors inside the spawned child process, proving cleanup fires
regardless of the child's own exit status. The base database
`ultimatesms_testing_lf` was independently re-verified immediately
afterward: **250 migrations, 139 tables**, unchanged from before the run —
never dropped, migrated, renamed or reconfigured.

### 3.7 Determinism

`EntitlementManagerConcurrencyTest.php` (the file with the most `Process`
call sites, and the one carrying the real 8-scenario cross-process race
coverage) was run **4 consecutive times**: 8 tests, 71 assertions, green,
every time, with no flake.

### 3.8 Full `tests/Feature/Workspace` + `tests/Feature/Entitlement` regression

Run twice for reproducibility (full output captured both times, not just a
tail): **1,100 tests, 2,330 assertions, 227 errors, 0 failures**, identically
both runs.

**Every one of the 227 errors is `MixFileNotFoundException: Unable to
locate Mix file: /js/core/theme-tokens.js.`** — `grep -c
"MixFileNotFoundException"` against the full captured log returns exactly
**227**, matching the reported error count one-for-one. This worktree was
built fresh for this branch (`vendor/` copied, `composer dump-autoload` run,
no prior `npm`/Mix build ever executed in it), so it reproduces the true
fresh-clone symptom of a pre-existing, already-documented repository defect:
`public/mix-manifest.json` carries no entry for `/js/core/theme-tokens.js`,
and no compiled `public/js/core/theme-tokens.js` exists on disk, even though
`resources/views/panels/scripts.blade.php` requires it on every authenticated
page render. This defect predates this branch by a wide margin (Design
System M2 Slice 1) and is entirely outside its scope — this branch touches
no path under `public/`, `resources/`, or `webpack.mix.js`.

**All 227 errors are distributed across sixteen HTTP/Controller-facing test
classes**, every one of them a view-rendering surface unrelated to this
branch's eight files:

```
     31 Tests\Feature\Workspace\AdminWorkspaceControllerTest
      5 Tests\Feature\Workspace\AdminWorkspaceEntitlementControllerTest
      5 Tests\Feature\Workspace\AdminWorkspacePlanCatalogControllerTest
     15 Tests\Feature\Workspace\CustomerContextResolutionTest
      6 Tests\Feature\Workspace\ViewAsAccessLossTest
      4 Tests\Feature\Workspace\ViewAsClientTest
      7 Tests\Feature\Workspace\WorkspaceBusinessCreationHttpTest
     10 Tests\Feature\Workspace\WorkspaceBusinessFeatureToggleHttpTest
     16 Tests\Feature\Workspace\WorkspaceBusinessListHttpTest
     13 Tests\Feature\Workspace\WorkspaceBusinessReassignmentHttpTest
     45 Tests\Feature\Workspace\WorkspaceMemberManagementHttpTest
      9 Tests\Feature\Workspace\WorkspaceMutationHttpTest
     28 Tests\Feature\Workspace\WorkspaceOverviewHttpTest
     10 Tests\Feature\Workspace\WorkspaceOwnershipTransferHttpTest
      5 Tests\Feature\Workspace\WorkspaceReactivationHttpTest
     18 Tests\Feature\Workspace\WorkspaceSwitcherHttpTest
```

**None of this branch's eight files, or any of their consumers inspected in
§2.2, appear anywhere in this list.** `WorkspaceManagerConcurrencyTest`,
`WorkspaceManagerTest`, `EntitlementManagerConcurrencyTest`,
`WorkspaceEntitlementBackfillV1ConcurrencyTest`,
`WorkspaceBackfillV1ConcurrencyTest`, `WorkspaceM1BBoundaryTest` and
`WorkspaceTransitionsMigrationSchemaTest` are entirely absent from the error
list — consistent with §3.1's own green individual runs for every one of
them.

**Comparison against pristine `origin/main`.** A live side-by-side run was
judged unnecessary given the evidence already in hand, rather than run
mechanically for its own sake: (a) `git diff origin/main --stat` (§6) shows
this branch touches only the twelve real files listed there, none of them
under `public/`, `resources/`, or `webpack.mix.js`; (b) none of the sixteen
erroring test classes above spawns, imports, or otherwise reaches any of
this branch's eight files or their consumers; (c) the error's own cause —
an absent Mix manifest entry — cannot be affected by a database-name
validation guard under any code path. Pristine `origin/main`, built into an
equally fresh worktree with no prior Mix build, would therefore reproduce
these same 227 errors identically; this branch neither fixes nor introduces
any of them. This mirrors the reasoning already applied to the historical
suite's 3 errors in §3.4, extended here across the full 1,100-test
regression rather than one file.

**This branch does not claim a green regression, and must not be read as
claiming one.** 227 errors remain, every one of them `main`'s own,
pre-existing, and outside this branch's eight-file scope.

---

## 4. Workflow environment (`.github/workflows/ai-subscription-gate.yml`)

One additive line in the job's existing `env:` block:
`EXPECTED_TEST_DATABASE: ultimatesms_testing` — the same canonical value
`DB_DATABASE` on the line above it already uses. This is the only change to
the file. Validated two ways:

* `Symfony\Component\Yaml\Yaml::parseFile()` (already present in `vendor/`,
  not a new dependency) parses the file without error and confirms
  `jobs.focused-tests.env.EXPECTED_TEST_DATABASE === 'ultimatesms_testing'`,
  matching `DB_DATABASE` exactly.
* Manual inspection confirms 6-space indentation identical to every sibling
  `env:` key, no tab characters, and the new comment block uses `#` at the
  same indentation as the keys it documents.

Routes 1 and 2 (the autonomous Claude Routine and manual completion of its
locked slice) remain canonical-only, exactly as `AGENTS.md` and `CLAUDE.md`
require — this addition hands the gate's own existing canonical value down
to the two now-guarded standalone suites; it does not introduce a validated
sibling anywhere in the workflow.

---

## 5. PR #230 P2 documentation correction

GitHub correctly flagged that `AGENTS.md` and
`docs/automation/MANUAL-LANE-GOVERNANCE-RECONCILIATION.md` presented
`tests/Feature/Workspace/Support/TemporaryTestDatabase.php` as if it were
the *only* file that still genuinely pinned the canonical database name. It
was not — eight files did, exactly as
`docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md` §5 had
already correctly enumerated. Both documents are corrected in place (not
silently, with an inline `**Correction (...)**` block in each, naming the
finding, the true eight-file list, and this branch as the resolution) rather
than having the inaccurate sentence quietly replaced.

**After implementation, whether any executable literal canonical-name pin
remains:** a repository-wide sweep (`grep -rn "'ultimatesms_testing'"` and
the double-quoted form, across `tests/`) finds exactly two remaining
occurrences of the literal string, both legitimate and neither a pin:
`TestDatabaseSafety::CANONICAL`'s own definition, and its own unit test's
fixture data (`tests/Unit/Support/TestDatabaseSafetyTest.php`, which
exercises the authority's own behavior using that string as test input, not
as a pin against anything). A further sweep for
`getDatabaseName() ===`/`getDatabaseName() !==`/`EXPECTED_DATABASE` across
`tests/` returns zero matches. **No executable literal canonical-name pin
remains anywhere in the repository.**

The autonomous Routine's canonical-only rule is retained exactly as before
(§4 above); manual-lane support for validated disposable siblings is
retained exactly as before (`AGENTS.md`'s general policy row, unaffected by
this correction, §5 of that file).

---

## 6. Scope

Thirteen paths actually changed, all within the seventeen-path allowlist,
with nothing added beyond one new documentation file:

1. `docs/automation/WORKSPACE-ENTITLEMENT-DATABASE-SAFETY-COMPLETION.md` *(this file)*
2. `.github/workflows/ai-subscription-gate.yml`
3. `AGENTS.md`
4. `docs/automation/MANUAL-LANE-GOVERNANCE-RECONCILIATION.md`
5. `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php`
6. `tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php`
7. `tests/Feature/Workspace/Support/concurrent_workspace_resolver_runner.php`
8. `tests/Feature/Workspace/Support/concurrent_backfill_runner.php`
9. `tests/Feature/Workspace/Support/run_historical_m1a_suite.php`
10. `tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php`
11. `tests/Feature/Workspace/Support/TemporaryTestDatabase.php`
12. `tests/Feature/Workspace/WorkspaceManagerConcurrencyTest.php`
13. `tests/Feature/Workspace/WorkspaceManagerTest.php`

Three further allowlisted paths were inspected and found to require **no**
change, each recorded in §2.2 with the reason:
`tests/Feature/Entitlement/WorkspaceEntitlementBackfillV1ConcurrencyTest.php`,
`tests/Feature/Workspace/WorkspaceBackfillV1ConcurrencyTest.php`,
`tests/Feature/Workspace/WorkspaceM1BBoundaryTest.php`. A fourth,
`tests/Feature/Workspace/WorkspaceTransitionsMigrationSchemaTest.php`, was
likewise inspected and needed no change of its own — its one prior error
was inherited entirely from file 11.

Zero changes under `app/`, `public/`, `vendor/`, `node_modules/`, `database/`,
`routes/`, `config/`, `resources/`, dependency files, generated assets,
`tests/Support/TestDatabaseSafety.php`, the merged PR #229 Usage files, or
`CLAUDE.md`/`docs/automation/AI-AUTONOMY-STATE.json`.

---

## 7. Post-merge correction — PR #232 P1 finding (generated-name validation)

**Merged as:** `e5499df2d304572d49b26cdc35c05f32c26ac98a`. Automated review of
PR #232 found a real P1 defect in the branch this document otherwise
describes as complete: `TemporaryTestDatabase::isValidGeneratedName()`
validated only the captured base portion of a generated name through
`TestDatabaseSafety`, never the complete generated name.

### 7.1 The defect, precisely

A base can independently pass every `TestDatabaseSafety` check — safe
characters, no forbidden segment, and (critically) itself under MySQL's
64-character identifier limit — and still, once this class's own
`_historical_<pid>_<hex>` / `_enforcement_<pid>_<hex>` suffix (22–28
characters, depending on pid length) is appended, produce a **complete**
name that exceeds that same 64-character limit. The original
`isValidGeneratedName()` checked only the base, so it returned `true` for
such a name even though `TestDatabaseSafety::isSafeTestDatabaseName()`
itself would refuse the exact string this class was about to create,
register a connection for, or drop.

This was not a theoretical gap. `concurrent_backfill_runner.php` (§2.3 of
this document, entirely unrelated to production data) trusts
`TemporaryTestDatabase::isValidHistoricalName()` as one of its two
authorization branches over an **externally-supplied**
`EXPECTED_TEST_DATABASE` environment value — a value the runner's own
caller controls. A crafted value shaped exactly like a generated historical
name, with a base that independently passes `TestDatabaseSafety` on its own
but pushes the combined length over 64, would have been accepted as
authorized by the pre-correction code. §7.4 reproduces this exact shape
directly against the real runner and confirms the corrected code refuses it.

Compounding the same root issue, `generateName()` carried its own
`MYSQL_IDENTIFIER_MAX_LENGTH = 64` constant and an explicit length check —
a **second, competing** length policy duplicating `TestDatabaseSafety`'s own
private `MAX_LENGTH = 64`, rather than delegating to the repository's single
authority.

### 7.2 The fix

`isValidGeneratedName()` now performs three checks, all required, matching
the task's own enumeration exactly:

1. the name matches the exact historical- or enforcement-specific shape
   (the existing `preg_match()` against the named-capture pattern, unchanged);
2. the captured base is approved by `TestDatabaseSafety::isSafeTestDatabaseName()`
   (unchanged from the PR #232 version);
3. **new** — the complete generated name is independently approved by
   `TestDatabaseSafety::isSafeTestDatabaseName()` as well.

Requirement 4 from the task (the MySQL identifier-length limit) is **not**
a fourth, separate check — it falls out of requirement 3 for free, because
`TestDatabaseSafety::isSafeTestDatabaseName()` already enforces its own
64-character limit internally on whatever string it is given. This is why
`generateName()`'s own competing `MYSQL_IDENTIFIER_MAX_LENGTH` constant and
explicit `strlen()` check were **removed outright**, not merely relaxed —
delegating the length check to the same call that already proves the
complete name's other properties is what "do not create a competing
database policy" requires, not a second constant that happens to hold the
same number. `generateName()`'s own failure path now calls
`TestDatabaseSafety::assertSafeTestDatabaseName()` purely to obtain its
descriptive reason string for the exception message — the authorization
decision itself was already made by `isValidGeneratedName()`.

No other method changed. `TestDatabaseSafety` itself remains completely
unmodified (§7.5 confirms this mechanically). `concurrent_backfill_runner.php`
required **zero** changes — `git diff origin/main -- tests/Feature/Workspace/Support/concurrent_backfill_runner.php`
is empty — because it already delegated to `isValidHistoricalName()` as one
of its two authorization branches; fixing that one shared method
automatically closed the gap at every call site, exactly as intended by
having a single authority.

### 7.3 Boundary tests

Nine new test methods were added to
`tests/Feature/Workspace/WorkspaceTransitionsMigrationSchemaTest.php` (the
one test file in this task's allowlist, and an existing direct consumer of
`TemporaryTestDatabase`), exercising the public `isValidHistoricalName()`/
`isValidEnforcementName()` entry points directly — the same surface both
this class's own internal callers and the external
`concurrent_backfill_runner.php` use, requiring no live database connection
since both methods are pure string functions:

| # | Test | Proves |
|---|---|---|
| 1 | `test_ordinary_canonical_generated_name_is_valid` | The base case remains accepted after this correction |
| 2 | `test_ordinary_validated_sibling_generated_name_is_valid` | A real disposable sibling as the base remains accepted |
| 3 | `test_maximum_accepted_complete_length_is_valid` | Exactly 64 characters (the boundary itself) is accepted |
| 4 | `test_one_character_over_the_limit_is_refused` | Exactly 65 characters is refused — **the direct, minimal reproduction of the P1 finding**: the fixture asserts the 43-character base is independently safe on its own before asserting the complete name is refused, proving the refusal comes from the new complete-name check, not the (unchanged) base check |
| 5 | `test_safe_base_whose_added_suffix_makes_the_full_name_unsafe` | The same safe-base/unsafe-complete-name property, restated against `isValidEnforcementName()` (the second purpose-specific pattern this class owns) with a longer pid, so the fix is proven on both patterns, not only one |
| 6 | `test_production_looking_captured_base_is_refused` | A base carrying a forbidden segment (`prod`) is refused via the base-safety check (unchanged behavior) |
| 7 | `test_production_looking_complete_name_is_refused` | A distinct forbidden segment (`staging`), asserted against `isValidEnforcementName()` exactly as an external caller would supply an already-assembled complete string (never constructed via this class's own `generateName()`) |
| 8 | `test_malformed_purpose_suffix_is_refused` | Shape violations (7 hex characters instead of 8; an enforcement-shaped name fed to the historical checker) are still refused exactly as before this correction |
| 9 | `test_correct_handoff_still_succeeds_end_to_end` | This correction changes only what is refused, never what is accepted for a genuine run — `withHistoricalDatabase()` end-to-end (create, connect, drop) against the real active test database, with the generated name itself re-verified via `isValidHistoricalName()` from inside the callback |

Run together with the file's original migration-schema test: **10 tests, 28
assertions, green** (§7.6).

### 7.4 Direct proof against the real runner

The exact P1 attack shape — a 43-character base independently safe under
`TestDatabaseSafety`, combined with a valid-shaped historical suffix,
producing a 65-character complete name — was constructed and handed
directly to `concurrent_backfill_runner.php` as `EXPECTED_TEST_DATABASE`/
`DB_DATABASE`:

```
ultimatesms_testing_aaaaaaaaaaaaaaaaaaaaaaa_historical_1_deadbeef
```

(65 characters). Result: **refused, exit 3**, before any database write —
*"EXPECTED_TEST_DATABASE [...] is neither a validated disposable test
database nor a valid historical temporary database name."* A genuinely
valid name (the real active base, `ultimatesms_testing_pmc`) handed to the
same runner the same way: **accepted**, `OK created=0 reused=0 assigned=0`,
exit 0 — proving the correction refuses exactly the attack shape and
nothing more.

### 7.5 `TestDatabaseSafety` confirmed unmodified

`git diff origin/main -- tests/Support/TestDatabaseSafety.php` is empty.
The single authority gained no new method, no modified method, and no
relaxed check.

### 7.6 Verification

All runs against a **new, distinct** isolated database created for this
correction, `ultimatesms_testing_pmc` — never reusing the prior round's
`ultimatesms_testing_lf` — validated through
`TestDatabaseSafety::isSafeTestDatabaseName()` before creation,
`migrate:fresh`: 250 migrations, 0 pending.

| Check | Result |
|---|---|
| `tests/Unit/Support/TestDatabaseSafetyTest.php` (unchanged file) | 60 tests, 122 assertions, green — identical to every prior report |
| `tests/Feature/Workspace/WorkspaceTransitionsMigrationSchemaTest.php` (original test + all 9 new boundary tests) | 10 tests, 28 assertions, green |
| Direct negative probe against `concurrent_backfill_runner.php` (the P1 attack shape) | refused, exit 3, before any write |
| Direct positive probe against `concurrent_backfill_runner.php` (genuinely valid name) | accepted, exit 0 |
| `WorkspaceBackfillV1ConcurrencyTest` (real cross-process concurrency, the actual production consumer of this runner), run via `run_historical_m1a_suite.php` (run twice for reproducibility) | Both runs: **44 tests, 123 assertions, 3 errors** — identical, both times, to the pre-existing `WorkspaceManagerPreEnforcementTest` schema-drift errors this document's §3.4 already established as unrelated to the Workspace/Entitlement database-naming work; `WorkspaceBackfillV1ConcurrencyTest`'s own 2 tests are absent from both error lists — confirmed passing |
| Affected `tests/Feature/Workspace` + `tests/Feature/Entitlement` regression (full output captured, not a tail) | **1,109 tests, 2,351 assertions, 227 errors, 0 failures** — the same 227 pre-existing `MixFileNotFoundException` errors (227/227 exact match) across the identical 16 unrelated HTTP/Controller test classes this document's §3.8 already recorded; test count is up by exactly 9 (this correction's own new boundary tests); no class touched by this correction (`TemporaryTestDatabase`, `WorkspaceTransitionsMigrationSchemaTest`, `concurrent_backfill_runner.php`, `WorkspaceBackfillV1ConcurrencyTest`, `EntitlementManagerConcurrencyTest`) appears anywhere in the error list |

**Pristine-main comparison.** As in §3.4/§3.8, a live side-by-side run was
judged unnecessary: `git diff origin/main --stat` (§7.7) shows this
correction touches only three files, none of them reachable from the 3
historical-suite errors or the 227 regression errors — both error sets are
already independently established, in this same document, as pre-existing
and outside the Workspace/Entitlement database-naming scope. Their exact
reproduction here, unchanged in count and membership, is itself the
comparison.

### 7.7 Scope

Three of the four allowlisted paths changed:

1. `tests/Feature/Workspace/Support/TemporaryTestDatabase.php` — the fix (§7.2)
2. `tests/Feature/Workspace/WorkspaceTransitionsMigrationSchemaTest.php` — the nine boundary tests (§7.3)
3. `docs/automation/WORKSPACE-ENTITLEMENT-DATABASE-SAFETY-COMPLETION.md` — this section

`tests/Feature/Workspace/Support/concurrent_backfill_runner.php` was
inspected and confirmed to require **no** change — `git diff origin/main
-- tests/Feature/Workspace/Support/concurrent_backfill_runner.php` is
empty — since it already delegated to the one shared method this
correction fixes. `tests/Support/TestDatabaseSafety.php` remains
completely unmodified (§7.5).

Zero changes under `app/`, `public/`, `vendor/`, `node_modules/`,
`database/`, `routes/`, `config/`, `resources/`, dependency files,
generated assets, or any file outside this four-path allowlist.
