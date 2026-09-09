# Mainline baseline reliability remediation — subprocess database seam

Every test in this repository that spawns a *separate OS process* to prove
a race must, before its first write, prove which database it is pointed
at. This branch makes the two Usage concurrency runners and the
Blacklists security subprocess do that through one shared authority,
`Tests\Support\TestDatabaseSafety`.

**This document is not permission to weaken assertions or hide failures.**
No assertion is deleted, relaxed or made conditional anywhere in this
branch.

---

## 0. Why this branch exists, and what it deliberately is not

An earlier branch, `agent/mainline-baseline-reliability-remediation`,
carried this correction *and* a much larger baseline repair — asset
toolchain, environment-file isolation, generated-artifact hygiene, a
supported test runner, two production fixes. Its final commit
(`042f1e91890609d43540cb16c3b2ad66d2e248e6`) touched seven paths, and the
completion report described those seven. But a pull request is judged on
the **whole branch**, not its last commit: opened against `main`, that
branch showed **129 changed files**. PR #226 was closed without merge.

This branch is the clean reconstruction. It is cut fresh from `main` and
contains **only** the subprocess-database-seam correction, in seven paths.
Everything else from the earlier branch is deliberately absent and is
**not** claimed here:

| Not in this branch | Where it was |
|---|---|
| Asset toolchain repair, webpack pin, `package.json` / `package-lock.json` | earlier branch, withdrawn with PR #226 |
| `.gitattributes`, `.gitignore` and generated-artifact hygiene | same |
| `tests/Support/UsesTemporaryEnvironmentFile.php` and the `TestCase` wiring that stops suites writing a developer's `.env` | same |
| `tests/Support/CanonicalJson.php` | same |
| `scripts/test-baseline.php`, `scripts/resolve-test-database.php` | same |
| Production fixes in `BusinessKnowledgeProfileManager` and `BrandingUploadService` | same |
| `tests/Unit/Support/TestDatabaseSafetyTest.php` — the helper's own unit test | same; see §5 |
| Conversion of the Workspace and Entitlement runners onto the shared helper | same |

`tests/Support/TestDatabaseSafety.php` is therefore **new to `main`** here,
introduced by this branch, and used by exactly the five test files below.
Its class docblock describes the general problem it was written for; on
this branch it is applied to the Usage and Blacklists subprocesses only.
Four other subprocess scripts still compare against the literal canonical
name and are untouched — see §5.

---

## 1. Reconstruction provenance

| | |
|---|---|
| Base — `origin/main` at reconstruction time | `0a43ab3a646f36d827b02b69eb840aa5b5688f32` |
| Branch | `agent/mainline-baseline-reliability-remediation-clean`, created directly from that SHA |
| Old commit | `042f1e91890609d43540cb16c3b2ad66d2e248e6` — used as a **patch source and evidence only** |
| Old branch ancestry | **not** merged, rebased, reset or cherry-picked into this branch |

The five modified files had **byte-identical base blobs** under the old
commit's parent and under `0a43ab3a`, so the semantic patch transfers
exactly: the diff this branch produces for those five paths is
character-for-character the old commit's own hunks. `TestDatabaseSafety.php`
is introduced with the identical blob the old commit carried
(`b0b5e2ad5b054590fb858fe0a918bfd5d8825d0b`).

---

## 2. The seam

### 2.1 What was wrong

`tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php` and
`concurrent_conversations_send_runner.php` each opened with

```php
const EXPECTED_DATABASE = 'ultimatesms_testing';
```

and refused to run against anything else. The instinct was right — a child
process that writes must prove where it is pointed — but the literal made
both tests **unrunnable on any isolated database**, so two lanes working
at once had to share the one canonical database and silently corrupt each
other's runs.

### 2.2 What replaces it

```php
$expectedDatabase = getenv('EXPECTED_TEST_DATABASE');

if ($expectedDatabase === false || $expectedDatabase === '') {
    fwrite(STDERR, "Refusing to run: EXPECTED_TEST_DATABASE was not handed "
        . "down by the parent test. Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}

try {
    Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase($expectedDatabase);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'Refusing to run: ' . $e->getMessage()
        . " Aborting before any database write.\n");
    exit(WRONG_DATABASE_EXIT_CODE);
}
```

| Property | How it holds |
|---|---|
| No hardcoded name, no silent fallback | A missing or empty `EXPECTED_TEST_DATABASE` is a **refusal**, not "no expectation". The handoff not happening means the child cannot know what it is authorised to write, so it must not write |
| One authority | `Tests\Support\TestDatabaseSafety` decides every permitted name; no second helper exists |
| No broad "contains test" acceptance | A name is permitted only when it is `ultimatesms_testing` or that plus a safe suffix. `acme_test_live` is refused |
| Production-shaped names refused | Suffix segments `prod`, `production`, `live`, `staging`, `backup`, `master`, `main`, `real` are rejected — matched per underscore-separated segment, so `ultimatesms_testing_domain` is still accepted |
| Refusal precedes any write | The guard runs immediately after bootstrap, before the first model call |
| The parent hands down what it resolved | `childEnvironment()` returns `DB_DATABASE` **and** `EXPECTED_TEST_DATABASE`, both from `TestDatabaseSafety::activeTestDatabase()`, which validates before returning |
| Barriers unchanged | The child-emitted `LOCKED` line and the arrival-count barrier are untouched. **No sleep was added as synchronisation, and no test was serialised** |

### 2.3 The Blacklists subprocess

`tests/Feature/Security/BlacklistsSecurityTest` spawns a generated script
through `proc_open` that **creates and deletes users, customers and
blacklists** — and did so with no database check at all, which is strictly
worse than a wrong guard. The generated child now carries the same guard,
with the parent's resolved name baked in through
`var_export(TestDatabaseSafety::activeTestDatabase(), true)`.

---

## 3. Allowed paths

Exactly seven, and the aggregate diff against `origin/main` contains no
others:

* `docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md` *(new — this file)*
* `tests/Support/TestDatabaseSafety.php` *(new — the one database rule)*
* `tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php`
* `tests/Feature/Usage/Support/concurrent_conversations_send_runner.php`
* `tests/Feature/Usage/SlotAgreementConcurrencyTest.php`
* `tests/Feature/Usage/ConversationsConcurrencyTest.php`
* `tests/Feature/Security/BlacklistsSecurityTest.php`

Zero changes under `public/`, `bootstrap/cache/`, `vendor/`,
`node_modules/`, application production code, migrations, dependency
files, unrelated configuration, or generated assets. No production build
was run for this reconstruction, because none is needed to establish a
seven-file test-only change.

---

## 4. Verification

All runs on an isolated disposable database, `ultimatesms_testing_clean`
— a name this branch's own helper validates — reset with `migrate:fresh`
(250 migrations, 0 pending) beforehand. The canonical
`ultimatesms_testing` was never reset or written by these runs, which is
the whole point of the change.

### 4.1 The guard, proven directly against the runners

Fourteen probes, run against the real runner scripts rather than a
restatement of them. Every refusal exits **3** and names its own reason,
before any database write:

| Handoff | `concurrent_slot_agreement_runner.php` | `concurrent_conversations_send_runner.php` |
|---|---|---|
| `EXPECTED_TEST_DATABASE` unset | exit 3 — *"was not handed down by the parent test"* | exit 3 — same |
| set to empty string | exit 3 — same | exit 3 — same |
| mismatched (`ultimatesms_testing_other`) | exit 3 — *"the parent process is using […], and a spawned child must resolve the very same disposable database"* | exit 3 — same |
| `acme_test_live` (merely contains "test") | exit 3 | exit 3 |
| active database `ultimatesms_production` | exit 3 — *"it is not the canonical test database or a suffixed sibling of it"* | exit 3 — same |
| **correct handoff** | passes the guard, reaches mode dispatch | passes the guard, reaches mode dispatch |

The name rules were exercised on their own, with the active database and
the handoff both set to the same refused value, so the *name* is what is
rejected rather than a mismatch:

| Database | Refusal |
|---|---|
| `acme_test_live` | not the canonical test database or a suffixed sibling of it |
| `ultimatesms_testing_prod` | its suffix contains the segment `"prod"` |
| `ultimatesms_sms` | not the canonical test database or a suffixed sibling of it |
| `ultimatesms` | not the canonical test database or a suffixed sibling of it |

### 4.2 Suites

| Run | Result |
|---|---|
| `TestDatabaseSafety` unit suite — 38 tests, 44 assertions, green | Run from the earlier branch **temporarily** to prove the helper being introduced here, then deleted; it is not an allowlisted path and is **not** committed (§5) |
| `SlotAgreementConcurrencyTest` + `ConversationsConcurrencyTest` | 7 tests, 38 assertions, green |
| Same pair, **ten consecutive runs** | 7 tests / 38 assertions every time, all exit 0 — determinism, with the barriers untouched |
| `BlacklistsSecurityTest::test_admin_index_search_export_still_see_all_tenants_rows` — the test that drives the newly guarded `proc_open` child | 1 test, 5 assertions, green |
| `tests/Unit/Usage` | 21 tests, 46 assertions, green |

### 4.3 Head-to-head against pristine `origin/main`

The reconstruction was reverted in place — the six files restored to
`0a43ab3a`'s content on disk — the suites re-run, and the reconstruction
restored. Same machine, same database, same migration state, so the only
variable is this branch's diff:

| Suite | pristine `main` | this branch | delta |
|---|---|---|---|
| `tests/Feature/Usage` | 998 tests, 4 770 assertions, 67 errors, **4 failures** | 998 tests, **4 792** assertions, 67 errors, **0 failures** | **−4 failures, +22 assertions** |
| `tests/Feature/Usage/Slice5` | 92 tests, 789 assertions, 33 errors | 92 tests, 789 assertions, 33 errors | identical |
| `tests/Feature/Security` | 172 tests, 752 assertions, 108 errors | 172 tests, 752 assertions, 108 errors | identical |
| `tests/Unit/Usage` | 21 tests, 46 assertions, green | 21 tests, 46 assertions, green | identical |

The four failures this branch removes are exactly the four the hardcoded
constant caused, each reporting its own cause on `main`:

```
Refusing to run: resolved database is [ultimatesms_testing_clean],
expected [ultimatesms_testing]. Aborting before any database write.
```

* `ConversationsConcurrencyTest::test_same_idempotency_key_concurrent_reserve_calls_yield_exactly_one_reservation`
* `ConversationsConcurrencyTest::test_concurrent_quicksend_produces_exactly_one_provider_invocation_and_one_accounting_outcome`
* `SlotAgreementConcurrencyTest::test_real_concurrent_allocation_never_double_allocates`
* `SlotAgreementConcurrencyTest::test_real_concurrent_retry_produces_exactly_one_failed_transition`

On this branch the string `Refusing to run` appears **zero** times in the
whole `tests/Feature/Usage` log. The +22 assertions are the assertions
those four tests were aborting before reaching — they are now executed,
not added.

### 4.4 The errors are `main`'s, not this branch's

The 67 + 33 + 108 errors above are identical on both sides and have
**exactly one cause**, with no second cause anywhere in any log:

```
Illuminate\Foundation\MixFileNotFoundException:
Unable to locate Mix file: /js/core/theme-tokens.js.
(View: resources/views/panels/scripts.blade.php)
```

`origin/main` ships a 1 179-entry `public/mix-manifest.json` with no
`/js/core/theme-tokens.js` key, and no such file exists on disk, while
`resources/views/panels/scripts.blade.php` requires it. So **every test
that renders an authenticated page errors on current `main`**. Neither
`public/` nor `resources/` is touched by this branch. This is the breakage
the asset work in the withdrawn PR #226 repaired; that work is
deliberately not here, so the errors remain, unchanged and unmasked.

They are reported rather than worked around: no assertion was relaxed and
no test was skipped to make a number look better.

---

### 4.5 Full regression, and the same run on pristine `main`

The whole suite was run twice on the same machine, against the same
isolated database, each after its own `migrate:fresh`. The only variable
is this branch's diff.

| | pristine `origin/main` `0a43ab3a` | **this branch** | delta |
|---|---|---|---|
| Tests | 5 118 | 5 118 | 0 |
| Assertions | 22 033 | **22 056** | **+23** |
| Errors | 970 | 970 | **0** |
| Failures | **21** | **17** | **−4** |
| Risky | 1 | 1 | 0 |

Comparing the two failure lists name by name:

* **Fixed by this branch — 4**, and they are exactly the four the
  hardcoded constant caused:
  * `ConversationsConcurrencyTest::test_same_idempotency_key_concurrent_reserve_calls_yield_exactly_one_reservation`
  * `ConversationsConcurrencyTest::test_concurrent_quicksend_produces_exactly_one_provider_invocation_and_one_accounting_outcome`
  * `SlotAgreementConcurrencyTest::test_real_concurrent_allocation_never_double_allocates`
  * `SlotAgreementConcurrencyTest::test_real_concurrent_retry_produces_exactly_one_failed_transition`
* **Introduced by this branch — none.** Not one test fails, errors or
  turns risky on this branch that does not do the same on `main`.

The +23 assertions are the assertions those four tests were aborting
before reaching. They are now executed; none was added.

**This branch does not claim a green suite, and must not be read as
claiming one.** 970 errors and 17 failures remain, every one of them
`main`'s own and reproduced on `main`:

| Remaining problem | Count | Cause |
|---|---|---|
| Errors | 968 | `MixFileNotFoundException: /js/core/theme-tokens.js` (§4.4) |
| Error | 1 | `WorkspaceTransitionsMigrationSchemaTest` — `Workspace/Support/TemporaryTestDatabase.php` requires the literal canonical database and refuses on an isolated one |
| Error | 1 | `OpportunityManagerBeginRunTest::test_heartbeat_one_second_past_the_timeout_cutoff_is_abandoned` — `RunAlreadyActiveException` |
| Failures | 9 | `EntitlementManagerConcurrencyTest` (8) and `WorkspaceManagerConcurrencyTest` (1) — the same hardcoded-canonical-database defect this branch fixes for Usage, in support files outside its seven paths |
| Failures | 8 | Branding upload validation, two Business Knowledge Profile, three Website, `WorkspaceManagerTest` — pre-existing defects in files this branch does not touch |

For comparison: the withdrawn branch reported 5 196 tests and a green
suite. It could, because it also carried the asset repair, the
environment-file isolation and the two production fixes that clear these
same errors and failures — 78 of its extra tests were its own added
coverage. None of that is in this reconstruction, so none of it is claimed
here.

### 4.6 Why no untouched suite can be affected

Every file in the repository that names `Tests\Support\TestDatabaseSafety`
is one of this branch's own seven paths:

```
tests/Support/TestDatabaseSafety.php
tests/Feature/Security/BlacklistsSecurityTest.php
tests/Feature/Usage/ConversationsConcurrencyTest.php
tests/Feature/Usage/SlotAgreementConcurrencyTest.php
tests/Feature/Usage/Support/concurrent_conversations_send_runner.php
tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php
```

Nothing else can reach the new class, and the five modified files are
modified only where §2 describes. That is the structural reason the
name-by-name failure comparison above comes out at exactly −4 / +0.

### 4.7 Generated and runtime files were kept out

Two contamination sources were found and neutralised — both of the kind
that put 129 files into the withdrawn pull request:

| Source | What it does | Handling |
|---|---|---|
| `bootstrap/cache/packages.php`, `bootstrap/cache/services.php` | **Tracked on `main`**, and rewritten by every `composer install` and several `artisan` calls | Restored to `main`'s content immediately before the commit, after all test runs |
| `public/images/websites/**`, `public/images/branding/logo_compact/**` | The Website and Branding suites upload into the real `public/` tree and never clean up; seven files appeared during the regression | Deleted before the commit; `public/` carries zero changed or untracked paths |

No production build was run, so no `public/mix-manifest.json` churn, no
recompiled vendor assets, and no source maps could enter the commit. The
final audit in §6 shows the resulting diff.

---

## 5. Deferred — explicitly out of scope for this branch

Recorded so a reviewer sees them, and so nobody mistakes silence for
absence. **Do not fix these here.**

| # | Finding | Why deferred |
|---|---|---|
| 1 | `AppConfig::setEnv()` resolves `base_path('.env')`, which no test seam redirects, so `tests/Feature/Settings` writes the developer's real environment file. Measured: the file's mtime moves while its bytes stay identical, because `SettingsController` writes back the values already there. Same class for `Tool.php` and `EloquentSettingsRepository`. | Production change to a method with five call sites; outside this branch's seven paths |
| 2 | Nine `tests/Feature/Usage/**` tests generate a runner into `sys_get_temp_dir()` at runtime and spawn it with no database guard: `AutoRechargeFailedPaymentRetryTest`, `ConcurrentTopUpConcurrencyTest`, `PayerAssignmentConcurrencyTest`, `ProviderRefundDisputeConcurrencyTest`, `RefundablePaidAvailableAccountingTest`, `Slice5/AutoRechargeRollingWindowConcurrencyTest`, `UsageWalletBackfillV1ConcurrencyTest`, `UsageWalletManagerConcurrencyTest`, `UsageWalletManagerSetActiveRateConcurrencyTest`. None hardcodes a name, so none is broken today — they inherit the caller's environment, and `phpunit.xml` pins no `DB_DATABASE`. The hazard is latent, not active | Outside this branch's seven paths |
| 3 | `app/Http/Controllers.zip`, tracked since the 2026-07-18 baseline import, still contains the `DebugController` source that Security Remediation Slice 0 (PR #225) deleted. Dormant: not autoloaded, not routable, so nothing is resurrected — but the source text still ships | Outside this branch's seven paths |

Two further gaps this reconstruction creates on purpose, by honouring the
seven-path allowlist:

* **`TestDatabaseSafety` arrives without its unit test.**
  `tests/Unit/Support/TestDatabaseSafetyTest.php` (38 tests) is not an
  allowlisted path. The helper's behaviour is instead proven here by
  direct negative-case probes against the real runners (§4) — but the unit
  test should follow in the next change.
* **A family of Workspace and Entitlement support files still pins the
  canonical name.** `Entitlement/Support/concurrent_business_slot_runner.php`,
  `Workspace/Support/concurrent_workspace_resolver_runner.php`,
  `Workspace/Support/concurrent_backfill_runner.php`,
  `Workspace/Support/run_historical_m1a_suite.php`,
  `Workspace/Support/run_workspace_enforcement_suite.php`,
  `Workspace/Support/TemporaryTestDatabase.php`,
  `Workspace/Support/EnforcementWorkspaceTestCase.php` and
  `Workspace/Support/VerifiesEnforcementWorkspaceDatabase.php` each compare
  against the literal `ultimatesms_testing`. They are safe as they stand —
  they refuse rather than write to the wrong place — but they cannot run on
  an isolated database, which is visible in §4.5 as nine failures and one
  error whenever the suite is pointed anywhere but the canonical database.
  Converting them onto this helper is the natural next change, and is
  outside this branch's seven paths.

---

## 6. Final mechanical audit

Run against `origin/main` `0a43ab3a646f36d827b02b69eb840aa5b5688f32`
immediately before the commit, after every test run had finished and the
generated files described in §4.7 had been restored or deleted:

| Check | Result |
|---|---|
| Changed-path count vs `origin/main` | **7** |
| Changed-path listing | the seven in §3, and nothing else |
| `public/`, `bootstrap/cache/`, `vendor/`, `node_modules/` | **0** changed or untracked paths |
| Application code, migrations, dependency files, configuration, generated assets | untouched |
| `git diff --check` | clean |
| Secret-shaped strings in added lines | none |
| Local absolute paths in added lines | none |
| Production-looking database names | only as refused fixtures in this document and in the helper's own rejection examples |
| Working tree after the audit | no untracked or modified path outside the seven |
| `origin/main` re-fetched before committing | unchanged at `0a43ab3a` |

The branch was created directly from `0a43ab3a` with
`git worktree add -b …`. The earlier commit
`042f1e91890609d43540cb16c3b2ad66d2e248e6` was read for its patch content
only: `git merge-base --is-ancestor` confirms it is **not** an ancestor of
this branch, and the earlier branch was never merged, rebased, reset or
cherry-picked here.
