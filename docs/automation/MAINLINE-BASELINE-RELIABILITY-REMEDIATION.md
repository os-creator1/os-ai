# Mainline baseline reliability remediation

Repairs the recurring deterministic baseline failures, test-isolation
defects, asset-toolchain breakage and generated-artifact churn that cost
time in every feature lane.

**This document is not permission to weaken assertions or hide failures.**
Every change below either fixes a real defect or removes a hidden
dependence on one developer's local environment. No assertion is deleted,
relaxed or made conditional in order to go green.

---

## 0. Correction round 1 — synchronised with main

| | |
|---|---|
| Pre-merge branch HEAD | `727158e312767c2442a20ad43a59cbb8ab49853c` |
| Fetched `origin/main` | `98e063aabf0f8f67bc02190ce761064f8889ed22` |
| Merge commit | `70c5b2db1e255ef08f3ff2c336309b8795858efd` |
| Conflicts | **none** |

Main had advanced by eight commits, all documentation: Lane B's Telnyx
managed-messaging architecture decision (PR #219) and the automation
trigger/recipe expansion contract (PR #220), plus edits to the customer
experience contract. Exactly three files, none of which this branch
touches, so the merge was clean and every merged contract is preserved
byte-for-byte — verified by diffing each against `origin/main` after the
merge. The merge changed no build input: `resources/`, `webpack.mix.js`,
`package.json` and `package-lock.json` are identical before and after.

All assets were regenerated **after** the merge, from the merged tree, on
a clean dependency install. §2's baseline figures were measured on
`d2b275ec` and are left as recorded; §6.1 carries the post-remediation
result.

---

## 1. Baseline

| | |
|---|---|
| Baseline SHA (`origin/main`) | `d2b275ec9dd447c27276007636062815a1b8e957` |
| Branch | `agent/mainline-baseline-reliability-remediation` |
| Reproduced on | Windows 11 (MINGW64), the machine every lane actually develops on |

### 1.1 Environment inventory

| Component | Version |
|---|---|
| PHP (CLI) | 8.3.30 ZTS |
| Composer | 2.9.4 |
| Node | v24.18.0 |
| npm | 11.16.0 |
| MySQL server | 8.4.3 |
| PHPUnit | 11.5.56 |
| laravel-mix (installed) | 6.0.49 |
| webpack (installed, before fix) | 5.109.2 |

`composer install` requires `--ignore-platform-req=ext-pcntl
--ignore-platform-req=ext-posix` on Windows: `laravel/horizon` requires
both, and neither exists in a Windows PHP build. CI runs on Linux and
needs no such flag. This is recorded as a documented local fact, not a
defect to fix.

### 1.2 Required local MySQL grant

Isolated databases, and the temporary databases the historical/enforcement
suites create, both need the test user to hold privileges across the whole
family, not on one literal name:

```sql
GRANT ALL PRIVILEGES ON `ultimatesms\_testing%`.* TO 'ultimatesms_test'@'localhost';
FLUSH PRIVILEGES;
```

Without it, `TemporaryTestDatabase` fails with `1044 Access denied … to
database 'ultimatesms_testing_<suffix>_enforcement_…'` the moment the base
database is anything but the canonical one.

---

## 2. Reproduced failures and classifications

Classification key: **(1)** real production defect · **(2)** stale test
expectation · **(3)** test isolation defect · **(4)** environment-sensitive
assertion · **(5)** line-ending/serialization instability · **(6)** asset
build/toolchain defect · **(7)** deterministic concurrency-test
orchestration defect · **(8)** genuine nondeterministic production race ·
**(9)** active-lane-owned, deferred.

### A — Asset toolchain, and everything downstream of it **(6)**

`npm ci` succeeds; `npm run development` fails:

```
Error: Cannot find module 'webpack/lib/SizeFormatHelpers'
  at node_modules/laravel-mix/src/webpackPlugins/BuildOutputPlugin.js:6
```

Mechanically traced:

* `laravel-mix@6.0.49` `BuildOutputPlugin.js` line 6 requires
  `webpack/lib/SizeFormatHelpers`.
* laravel-mix declares `webpack: ^5.60.0`, so npm resolves the newest
  webpack 5.x.
* `webpack/lib/SizeFormatHelpers.js` was **removed in webpack 5.107.0**.
  Verified by downloading the published tarballs: 5.99.9, 5.101.3,
  5.102.1, 5.104.1, 5.105.4 and 5.106.2 contain it; 5.107.0, 5.107.1,
  5.107.2, 5.108.4, 5.109.0 and 5.110.3 do not.

**There is a SECOND, EARLIER boundary — see §2.1.** Pinning to 5.106.2
(the last version shipping `SizeFormatHelpers`) still fails, because
`webpack@5.106.0` began validating `ProgressPlugin` options and
`webpackbar@5.0.2` passes `name`/`color`/`reporters`/`reporter`. The
effective compatible ceiling is therefore **5.105.4**, not 5.106.2.

Downstream consequences, all one defect:

* the build cannot run, so `public/mix-manifest.json` cannot be
  regenerated;
* the tracked manifest therefore lacks `/js/core/theme-tokens.js`, even
  though `webpack.mix.js` declares that entry point;
* `resources/views/panels/scripts.blade.php` deliberately **throws**
  `MixFileNotFoundException` when that entry is absent and
  `config('app.debug')` is true — which is exactly the test environment;
* every test that renders a page including `panels/scripts` errors with
  `Unable to locate Mix file: /js/core/theme-tokens.js`.

Confirmed instance:
`BusinessKnowledgeProfileControllerTest::test_missing_stale_and_present_fields_are_all_displayed`.
This is **not** a Business Knowledge Profile defect.

### A.1 — The webpack pin, revalidated against the real dependency pair

Both constraints re-proved against the tarballs published today, and
against the actually-installed `laravel-mix@6.0.49` + `webpackbar@5.0.2`:

| webpack | `lib/SizeFormatHelpers` | ProgressPlugin validates options | verdict |
|---|---|---|---|
| 5.104.1 | present | no | compatible |
| 5.105.0 | present | no | compatible |
| **5.105.4** | **present** | **no** | **compatible — the ceiling** |
| 5.106.0 | present | **yes** | breaks `webpackbar@5.0.2` |
| 5.106.2 | present | yes | breaks `webpackbar@5.0.2` |
| 5.107.0 | **removed** | yes | breaks `laravel-mix@6.0.49` |
| 5.110.3 | removed | yes | breaks both |

`5.105.4` is the newest release in the compatible window, so the pin is
the **narrowest** solution — the smallest possible step back from the
floating `^5.60.0` that laravel-mix declares, not an arbitrary downgrade.

Determinism and drift, both proved mechanically:

* `npm ci` from the committed lock installs exactly `5.105.4`, and the
  lock contains **one** `node_modules/webpack` entry resolved to the
  5.105.4 tarball. Only one webpack copy exists on disk.
* The override **cannot silently float**: changing it in `package.json`
  without regenerating the lock makes `npm ci` fail loudly with
  `EUSAGE … Invalid: lock file's webpack@5.105.4 does not satisfy
  webpack@5.104.1`.
* No package in the tree asks for a webpack newer than 5.105.4 — the most
  restrictive declared range is laravel-mix's own `^5.60.0`.
* Both `npm run development` and `npm run production` succeed.

The wider frontend stack is deliberately untouched.

### A.2 — The manifest indexed its own destination directory **(6)**, corrected

`webpack.mix.js` copies `resources/images` into `public/images`, and Mix
records what it finds in the **destination**. `resources/images/websites/`
does not exist — but `public/images/websites/<uuid>/` does, because the
website and branding suites upload real files there and never clean up.

Consequences:

* every build on a machine that has run the suite produced a **different**
  manifest;
* **four such entries reached the previous commit** — one
  `/images/branding/logo_compact/<sha>.png` and three
  `/images/websites/<uuid>/<sha>.png`.

Corrected by removing the untracked uploads before building. The
regenerated manifest has **1187 entries, zero runtime-upload entries**,
and differs from the previously committed one by exactly those 4 removals
— 0 added, 0 values changed. `tests/Unit/Assets/MixManifestIntegrityTest.php`
now fails the suite if any reappear, and `.gitignore` keeps the upload
directories out of a commit.

### B — Branding footer **(4)**

`BrandingAdminFooterRenderTest` asserts the rendered footer contains
`AI Business OS` exactly once. `BrandingPresenter::footerCompanyName()`
correctly resolves the owner-configured `footer_company_name`, else
`config('app.name')`. `config/app.php` declares
`'name' => env('APP_NAME', 'AI Business OS')` — the locked product
default. The local, untracked `.env.testing` sets
`APP_NAME="Test App"`, so the assertion fails.

The production seam is correct. The suite was depending on an arbitrary
developer `.env` value.

### C — Opportunity heartbeat **(4)**

`OpportunityManagerBeginRunTest::test_heartbeat_one_second_past_the_timeout_cutoff_is_abandoned`
errors with `RunAlreadyActiveException`. Instrumented directly:

```
app.timezone            = America/New_York      (from local .env.testing)
intended heartbeat      = 2026-06-01T11:29:59+00:00
raw db heartbeat_at     = 2026-06-01 11:29:59
model heartbeat_at      = 2026-06-01T11:29:59-04:00
cutoff                  = 2026-06-01T07:30:00-04:00
gte(cutoff)             = true      <-- judged healthy, so never abandoned
```

The fixture builds its frozen instant in **UTC**; Eloquent writes the
wall-clock of whatever timezone the value carries, and reads it back in
`config('app.timezone')`. With a non-UTC app timezone the stored instant
shifts by the offset — here four hours — so a heartbeat one second past
the cutoff reads back well inside it.

`config/app.php` defaults to `UTC`, and `AnalyticsDateRange` documents
UTC as the storage timezone. The lease/heartbeat contract itself is
correct; this is a UTC-only assumption in the fixture plus an arbitrary
`.env.testing` value. **No time tolerance is widened.**

### D — Knowledge Profile seam inventory **(4)**

`BusinessKnowledgeProfileSeamTest::test_only_the_manager_writes_the_tracked_tables`
skips the manager with `$path === $managerPath`.
`app_path('Library/Business/BusinessKnowledgeProfileManager.php')` appends
the argument verbatim, producing mixed separators
(`...\app\Library/Business/...`), while `RecursiveDirectoryIterator`
yields all-backslash paths. On Windows the two never compare equal, so
the manager is never skipped and the test fails against the manager's own
authorized writes (37 201 bytes — the exact size quoted in the failure).
Passes on Linux; fails on every Windows checkout.

### E — Knowledge Profile hours no-op — **real production defect (1)**

`BusinessKnowledgeProfileHoursTest::test_no_change_row_for_a_true_hours_no_op`
expects 1 change row after writing identical hours twice; it gets 2.

`updateLocationHours()` decides "changed" with
`$locked->hours !== $normalizedHours`. `business_locations.hours` is a
MySQL `json` column, and **MySQL normalises JSON object key order** (by
key length, then lexicographically). Demonstrated:

```sql
SELECT CAST('{"monday":1,"tuesday":2,"wednesday":3,"notes":null}' AS JSON);
-- {"notes": null, "monday": 1, "tuesday": 2, "wednesday": 3}
```

So the read-back array never strictly equals the freshly normalised one,
and **every repeat write emits a spurious immutable change row in
production**, not only in tests. The no-op guarantee is genuinely broken.

### F — Website JSON comparisons **(5)**

Same MySQL JSON key-order normalisation, but here the production
behaviour is correct and only the test comparison is order-dependent:

* `WebsiteDraftPageServiceSeamTest::test_store_page_persists_every_draft_field_through_the_seam`
* `WebsiteDraftPageServiceSeamTest::test_update_page_persists_every_draft_field_through_the_seam`
  — compare an in-memory `['type' => …, 'data' => …]` fixture with the
  read-back of a `json` column via `assertSame`.
* `WebsiteDraftPublishTest::test_rollback_repoints_the_website_without_mutating_any_revision_row`
  — captures `$revision->snapshot` from the in-memory model *before*
  persistence and compares it with a post-rollback database read.

### G — `robots.txt` byte comparison **(5)**

`WebsiteIndexingTest::test_robots_txt_is_untouched_by_this_feature_branch`
asserts `"User-agent: *\nDisallow:\n"`. The repository has **no
`.gitattributes`**, and `core.autocrlf=true` on Windows, so the file is
checked out as `User-agent: *\r\nDisallow:\r\n`. Passes on Linux CI,
fails on every Windows checkout.

### H — Test database isolation **(3)**

Several suites refuse any database name other than exactly
`ultimatesms_testing`, so two lanes cannot run tests at the same time.
This was reproduced live during this task: `DROP DATABASE` reported
success, `CREATE DATABASE` then failed with "database exists", and eight
tables appeared — because Lane A was concurrently running
`tests/Feature/Usage/Slice5/AutoTopUpPolicyAndValidationTest.php` against
the same canonical database. Neither lane can trust its own results.

### H2 — The suite rewrote the developer's environment file **(3)** and **(1)**

Found while tracing the thirteen `Tests\Feature\Settings` and
`Tests\Feature\Branding` failures. Two compounding defects:

**The suite wrote `.env.testing`.** Every platform-settings save, the
branding upload service and the demo-mode toggle end at
`App\Helpers\write_env()`, which rewrites `app()->environmentFilePath()`
wholesale — `.env.testing` under `APP_ENV=testing`. Proven directly:
after one baseline run, `.env.testing` differed from its pre-run copy in
`NOCAPTCHA_SITEKEY` and `FACEBOOK_CLIENT_ID`, and every CRLF line ending
had been collapsed.

That is the **root cause behind findings B and C**. The offending values
were themselves test residue: `APP_NAME="Test App"`,
`APP_TIMEZONE="America/New_York"`, `APP_FOOTER_COMPANY_NAME="Keep Company"`
and `APP_KEYWORD="keep-keyword"` are all fixture strings from
`PlatformSettingsGeneralWriteTest`. Each run started from an environment
the previous run had edited, in this worktree and in every other lane's.

**The read side looked at a different file.** `readEnvValue()` was
duplicated in two test classes, both hardcoded to `base_path('.env')` —
which the writer never touches under `APP_ENV=testing` — and both trimmed
with `trim($v, "\"\n")`, which leaves a trailing `\r` on a CRLF file and
therefore also leaves the closing quote. Hence the actual value
`AI Business OS"`.

**And a real production defect underneath.**
`BrandingUploadService` persisted its `.env` pointer through
`AppConfig::setEnv()`, which (a) hardcodes `base_path('.env')` regardless
of the active environment file and (b) matches its key by
case-insensitive **substring** over each line, appending nothing when the
key is absent. Neither `.env` nor `.env.example` ships an `APP_LOGO`
line, so **a first logo upload persisted nothing at all** — in
production, not only in tests.

### I — Generated bootstrap caches are tracked **(3)/(6)**

`bootstrap/cache/packages.php` and `bootstrap/cache/services.php` are
tracked in git, but `composer install` regenerates them through
`post-autoload-dump` → `artisan package:discover`. Consequences:

* a fresh checkout plus `composer install` immediately dirties the
  working tree;
* the **committed** copies are stale — they omit the
  `blade-ui-kit/blade-icons`, `laravel/sentinel` and
  `technikermathe/blade-lucide-icons` providers — so restoring them
  breaks icon/provider bindings;
* they embed absolute class paths, so a copied `vendor` directory can
  leave them pointing into another worktree.

`public/mix-manifest.json` is tracked deliberately (compiled assets are
repository policy) and stays tracked; the two `bootstrap/cache` files are
pure local build state and are untracked here.

### J0 — A deadlock in the new runner itself **(6)**, found and fixed here

Recorded because it is exactly the class of defect this branch exists to
remove, and it was introduced by this branch's own first draft.

`scripts/test-baseline.php` initially streamed each child step through
`proc_open()` pipes with `stream_set_blocking($pipe, false)`. **On Windows
that call silently does nothing** — proc_open pipe streams are always
blocking — so the "read stdout, then read stderr, repeat" loop blocks in
`fread()` on one pipe while the child is blocked writing to the other.
`artisan migrate` reproduced it every time: the child parked at 0% CPU
with a sleeping MySQL connection after four migrations, and the runner
waited indefinitely.

Replaced with `['file', …]` descriptors, which cannot deadlock on either
platform, plus a tail of that file for live progress. The step's raw log
is now written by the child directly, which this command has to keep
anyway.

### H3 — The guard validated the ARGUMENT, not the resolved database **(3)**, corrected

Correction round 1. `scripts/test-baseline.php` validated the name the
caller asked for and then ran migrations — a destructive step — without
ever asking Laravel what it would actually connect to. Between the two sit
`.env`, `.env.<APP_ENV>`, a stale `bootstrap/cache/config.php` and
`DATABASE_URL`, any of which redirects the connection.

`scripts/resolve-test-database.php` now boots the framework under exactly
the environment the destructive steps will use and reports the resolved
database, refusing when it is not a permitted disposable one (exit 3),
when it disagrees with the caller's selection (exit 4), when
`DATABASE_URL` is set at all, or when the open connection's own
`SELECT DATABASE()` disagrees with the configuration. The baseline command
runs it **after** clearing caches and **before** migrating, and aborts on
any mismatch.

Proved, each as a real subprocess in
`tests/Feature/Support/ResolvedTestDatabaseGuardTest.php`: empty name,
production-looking name, bare application database, uppercase, quote,
wildcard, path separator, connection URL, semicolon, excessive length,
`DATABASE_URL` present, a **cached configuration that disagrees with the
selection**, and a subprocess attempting to substitute a different
database. The canonical name and an isolated sibling are both still
accepted.

### I2 — The isolation trait leaked on re-activation **(3)**, found by its own new test

Correction round 1. `useTemporaryEnvironmentFile()` overwrote its record
of the active temporary directory without deleting the previous one, so a
second activation in the same process leaked a directory. Normal runs
activate once per test via `Tests\TestCase`, so nothing leaked in
practice — but the new
`tests/Feature/Support/TemporaryEnvironmentFileTest.php` drives the trait
directly and caught it. It now restores before re-activating, which also
guarantees the new copy is seeded from the **real** environment file
rather than from the copy already in force.

### J — Concurrency-test orchestration **(7)/(9)**

Cross-process concurrency tests fail intermittently with
`Holder process never confirmed its lock.` — a fixed-timeout wait for a
child process to print `LOCKED`. Under whole-suite load the child needs
longer than the timeout to boot Laravel, so the parent gives up before
the race window is ever reached.

Non-Usage instances are repaired here. The Usage instances
(`ConversationsConcurrencyTest`, `SlotAgreementConcurrencyTest`) are
**Lane-A-owned and deferred** — see §5.

---

## 3. Allowed paths

Nothing outside this list may be edited on this branch.

### 3.1 Asset toolchain

* `package.json` *(one `overrides` entry)*
* `package-lock.json` *(regenerated by npm)*
* `public/mix-manifest.json` *(produced by the repaired build, never hand-edited)*
* `public/css/**`, `public/css-rtl/**`, `public/js/**`, `public/fonts/geist/**`
  — the assets the repaired build compiles **from this repository's own
  `resources/` sources**, which had been stale since the build broke

**Deliberately NOT committed:** `public/vendors/**`, `public/fonts/**`
(other than the new `geist/` directory) and `public/images/**`. The
production build rewrites 443 of those third-party files, but the diff is
pure re-minification noise from a newer terser — e.g.
`(function(n,t){…})` losing a redundant parenthesis pair — with no
behavioural change. They are restored to their committed bytes and named
in §8 as an expected post-build working-tree artifact rather than
committed as churn.

### 3.2 Repository hygiene

* `.gitattributes` *(new)*
* `.gitignore`
* `bootstrap/cache/packages.php`, `bootstrap/cache/services.php`
  *(untracked; content never hand-edited)*

### 3.3 Deterministic test environment

* `phpunit.xml`

### 3.4 Shared test support

* `tests/Support/TestDatabaseSafety.php` *(new — the one database rule)*
* `tests/Support/CanonicalJson.php` *(new — the one JSON-order rule)*
* `tests/Support/UsesTemporaryEnvironmentFile.php` *(new — env-file isolation)*
* `tests/Unit/Support/TestDatabaseSafetyTest.php` *(new)*
* `tests/TestCase.php` *(applies the env-file isolation to every test)*
* `tests/Feature/Support/TemporaryEnvironmentFileTest.php` *(new — round 1)*
* `tests/Feature/Support/ResolvedTestDatabaseGuardTest.php` *(new — round 1)*
* `tests/Fixtures/EnvironmentIsolationProbeTest.php` *(new — round 1; outside both
  phpunit testsuites, driven only as a subprocess)*
* `tests/Unit/Assets/MixManifestIntegrityTest.php` *(new — round 1)*
* `tests/Feature/Assets/AssetRenderSmokeTest.php` *(new — round 1)*
* `tests/Feature/Branding/BrandingEnvPointerTest.php` *(new — round 1)*
* `tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php`
* `tests/Feature/Workspace/Support/concurrent_workspace_resolver_runner.php`
* `tests/Feature/Workspace/Support/concurrent_backfill_runner.php`
* `tests/Feature/Workspace/Support/run_historical_m1a_suite.php`
* `tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php`
* `tests/Feature/Workspace/Support/TemporaryTestDatabase.php`
* `tests/Feature/Workspace/Support/SlowWorkspaceManager.php`
* `tests/Feature/Workspace/Support/SlowWorkspaceBackfillV1.php`
* `tests/Feature/Settings/Concerns/SettingsTestHelpers.php`

### 3.5 Production fixes

* `app/Library/Business/BusinessKnowledgeProfileManager.php`
  *(the hours no-op comparison only)*
* `app/Library/Branding/BrandingUploadService.php`
  *(its two `.env` pointer writes move to the hardened
  `PlatformSettingsEnvWriter` seam)*

`App\Models\AppConfig::setEnv()` itself is **left alone**. Its
substring-key match and hardcoded `.env` path are the same defect, but it
has five other callers whose behaviour is not reproduced by any failure
in §2, and changing shared behaviour on a reliability branch is exactly
the unrelated-product-change this remediation must not make. Recorded
here so the next person does not rediscover it from scratch.

### 3.6 Affected tests

* `tests/Feature/Business/BusinessKnowledgeProfileSeamTest.php`
* `tests/Feature/Business/BusinessKnowledgeProfileControllerTest.php`
* `tests/Feature/Branding/BrandingAdminFooterRenderTest.php`
* `tests/Feature/Branding/BrandingUploadValidationTest.php`
* `tests/Feature/Opportunity/OpportunityManagerBeginRunTest.php`
* `tests/Feature/Website/Public/WebsiteIndexingTest.php`
* `tests/Feature/Website/WebsiteDraftPageServiceSeamTest.php`
* `tests/Feature/Website/WebsiteDraftPublishTest.php`
* `tests/Feature/Workspace/WorkspaceManagerConcurrencyTest.php`
* `tests/Feature/Workspace/WorkspaceManagerTest.php`
* `tests/Feature/Workspace/WorkspaceBackfillV1ConcurrencyTest.php`
* `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php`

### 3.7 Reliable baseline command

* `scripts/test-baseline.php` *(new — the whole implementation)*
* `scripts/resolve-test-database.php` *(new — round 1; boots the framework
  and reports the database it actually resolves)*
* `composer.json` *(one script entry)*

### 3.8 Documentation

* `docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md` *(this file)*
* `AGENTS.md` *(the test-database rule only)*
* `CLAUDE.md` *(the same rule, which it states independently)*

---

## 4. Active-lane exclusions

**Lane A — Slice 5 wallet/payer.** Not touched under any circumstances:

* `app/Library/Usage/UsageWalletManager.php`
* `app/Library/Usage/BillingProfileManager.php`
* Usage Billing controllers, requests and views
* Slice 5 migrations and Slice 5 contracts
* `tests/Feature/Usage/**`, `tests/Unit/Usage/**`

**Lane B — Telnyx research.** Documentation-only; its decision document
is not touched.

**Lane C — Customer Experience Slice 1A.** Committed and pushed at
`bd23f4b6b941a84786e0681cff9729b9b7ff7913` before this branch was cut.
Its worktree is left untouched.

---

## 5. Deferred corrections (Lane A owned)

Recorded here so they can be applied after Lane A merges. **Do not apply
on this branch.**

| Path | Required change |
|---|---|
| `tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php` | Replace `const EXPECTED_DATABASE = 'ultimatesms_testing'` with `Tests\Support\TestDatabaseSafety::assertMatchesActiveTestDatabase()` |
| `tests/Feature/Usage/Support/concurrent_conversations_send_runner.php` | Same |
| `tests/Feature/Usage/ConversationsConcurrencyTest.php` | Adopt the deterministic `LOCKED`-barrier wait used by the non-Usage concurrency tests |
| `tests/Feature/Usage/SlotAgreementConcurrencyTest.php` | Same |

Until then, those four files pin the suite to the canonical
`ultimatesms_testing` database, and their tests are expected to abort with
exit code 3 when the suite runs against an isolated database. That is a
known, recorded, Lane-A-owned deferral — not a silent failure.

**Re-audited against `origin/main` `98e063aa` (correction round 1).** Lane
A has **not** merged its fix: both runners in current main still carry
`const EXPECTED_DATABASE = 'ultimatesms_testing';`. Its branch
`agent/customer-experience-slice-5-wallet-payer-ux` remains unmerged.
`git diff origin/main HEAD -- tests/Feature/Usage tests/Unit/Usage
app/Library/Usage` is empty, so this branch has touched none of it.

The four deferred failures therefore stand, each naming its own cause:

| Test | Message |
|---|---|
| `ConversationsConcurrencyTest::test_same_idempotency_key_concurrent_reserve_calls_yield_exactly_one_reservation` | `Refusing to run: resolved database is [<isolated>], expected [ultimatesms_testing].` |
| `ConversationsConcurrencyTest::test_concurrent_quicksend_produces_exactly_one_provider_invocation_and_one_accounting_outcome` | same |
| `SlotAgreementConcurrencyTest::test_real_concurrent_allocation_never_double_allocates` | `Holder process never signaled lock acquisition: Refusing to run: …` |
| `SlotAgreementConcurrencyTest::test_real_concurrent_retry_produces_exactly_one_failed_transition` | `P1 must succeed: Refusing to run: …` |

**This branch does not claim a fully green suite.** It claims zero
failures that this branch owns.

---

## 6. Acceptance criteria

1. `npm ci` and both `npm run development` and `npm run production`
   succeed on the documented toolchain.
2. `public/mix-manifest.json` contains `/js/core/theme-tokens.js`,
   produced by the build and never hand-edited.
3. Pages render with `APP_DEBUG=true` **and** `APP_DEBUG=false`.
4. Every failure in §2 A–I is fixed, with its regression coverage intact
   or strengthened.
5. `ultimatesms_testing` and `ultimatesms_testing_<safe suffix>` are both
   accepted; production-like, empty and unsafe names are rejected; a
   subprocess inherits exactly the parent's permitted database.
6. Non-Usage concurrency tests reach their race window deterministically
   and still assert their original invariant.
7. `composer test:baseline` validates the database, clears caches,
   migrates, checks assets, runs the suite, keeps raw logs, and returns a
   truthful exit code without mutating `.env` or touching production.
8. `git status` is clean apart from the artifacts §8 names.

---

## 6.1 Result

| | Before (`d2b275e`) | Round 0 | **Round 1 (merged main)** |
|---|---|---|---|
| Tests | 4992 | 5026 | **5070** |
| Assertions | 21 129 | 28 118 | **28 408** |
| Errors | 911 | 0 | **0** |
| Failures | 35 | 4 | **4** |
| Distinct failing tests | **947** | 4 | **4** |

Every run used an isolated database on the same machine and toolchain —
`ultimatesms_testing_mbr1` for the first two, a freshly created
`ultimatesms_testing_r1` for round 1. The 44 additional tests are the
correction-round-1 coverage: environment-file isolation (10), the
resolved-database guard (16), manifest integrity (4), asset render smoke
(4), branding env pointer (8), plus two further refused name shapes.

`.env` and `.env.testing` are **byte-identical** before and after the
5070-test run, and the working tree carries no vendor, upload, cache, log
or temporary-database artifact.

All four remaining failures are the **Lane-A-owned deferrals recorded in
§5**, and each names its own cause:

```
Refusing to run: resolved database is [ultimatesms_testing_mbr1],
expected [ultimatesms_testing]. Aborting before any database write.
```

They are `ConversationsConcurrencyTest` (2) and
`SlotAgreementConcurrencyTest` (2), whose runners under
`tests/Feature/Usage/Support/` still hardcode the canonical name. Those
files are Lane A's and are not touched here.

Confirming them against the canonical database was deliberately **not**
attempted: Lane A was running `artisan test tests/Feature/Usage` against
`ultimatesms_testing` at the time. That this branch's own 5026-test run
completed on an isolated database *while* Lane A used the canonical one
is the clearest demonstration of what the isolation work buys — before
it, one of the two runs would have silently corrupted the other.

---

## 7. Verification matrix

| # | Verification | Evidence required |
|---|---|---|
| 1 | Each formerly failing test, individually | Named test, exact count |
| 2 | Each affected test file in full | File, test and assertion counts |
| 3 | Concurrency tests, repeated | ≥ 10 consecutive runs |
| 4 | Business Knowledge Profile suites | Counts |
| 5 | Website suites | Counts |
| 6 | Opportunity suites | Counts |
| 7 | Branding suites | Counts |
| 8 | Auth and Theme suites | Counts (branding changed) |
| 9 | Security suites | Counts |
| 10 | Entitlement suites | Counts |
| 11 | Unit suites | Counts |
| 12 | `npm run development` | Exit code, manifest entry |
| 13 | `npm run production` | Exit code, manifest entry |
| 14 | `APP_DEBUG=false` render | Page renders, no exception |
| 15 | Full suite, isolated database | Counts, before/after failure list |
| 16 | Full suite, canonical database | Only when genuinely required |
| 17 | `git diff --check` | Clean |
| 18 | Changed-path allowlist audit | Every path in §3 |
| 19 | Secret-shaped-string sweep | Clean |

No schema change is made, so no migration fresh/rollback/forward cycle is
required beyond the migrate step the baseline command performs.

---

## 7.1 Regenerated-asset classification (correction round 1)

Every file the merged-tree rebuild touches, classified. Only the first
three categories are committed.

| Category | Files | Committed | Evidence |
|---|---|---|---|
| **Required new runtime output** | 0 | — | `theme-tokens.js`, `theme-settings.js` and the three Geist files were already added in the previous commit and rebuild **byte-identical** |
| **Output of a changed source** | 0 | — | The merge changed no build input, and all 48 CSS + 24 JS + 3 font outputs rebuild byte-identical to the committed bytes |
| **Manifest change** | 1 | **yes** | `public/mix-manifest.json` — removes 4 runtime-upload entries (§A.2); 0 added, 0 values changed |
| **Pure rebuild noise** | 443 + 23 | **no** | 443 `public/vendors/**` re-minified by a newer terser (`(function(){…})` losing a redundant parenthesis pair); 23 `public/js/scripts/**` differing only by CRLF vs LF, which vanish under the `.gitattributes` clean filter |

That the source-derived assets rebuild byte-identical is the important
result: it proves the committed CSS/JS/fonts genuinely correspond to the
merged sources rather than being stale artifacts of the old base.

**Build determinism.** Three consecutive clean production builds produced
byte-identical manifests. Every one of the 1187 manifest targets exists on
disk.

**Verified through the real application**, not just on disk:
`tests/Feature/Assets/AssetRenderSmokeTest.php` renders an authenticated
and an unauthenticated page under `APP_DEBUG=true` **and**
`APP_DEBUG=false`, asserting each shell asset is referenced by the HTML,
exists on disk, and that no "Unable to locate Mix file" text appears. Both
modes matter and fail differently: with debug true a missing entry throws
and every page-rendering test errors; with debug false the page renders
while silently referencing an asset that 404s in a browser.

## 7.2 `.gitattributes` hardening (correction round 1)

Audited the tracked tree: **no** `.bat`, `.cmd`, `.ps1`, `.psm1`, `.psd1`,
`.vbs`, `.reg`, `.sln`, `.csproj`, `.vcxproj`, `.vbproj`, `.props` or
`.targets` files exist today. Explicit `text eol=crlf` rules are declared
for all of them anyway, so the first one added cannot be corrupted by the
global LF rule — `cmd.exe` mis-parses an LF batch file, `regedit` rejects
a non-CRLF `.reg`, PowerShell signature blocks are line-ending sensitive,
and Visual Studio rewrites `.sln`/`*proj` as CRLF on every save.

Verified with `git check-attr`:

* `*.woff2`, `*.ico`, `*.eot`, `*.ttf`, `*.zip` → `binary: set`,
  `text: unset` — never line-ending converted;
* `*.svg` stays `text: auto`, `eol: lf` — it is XML text, correctly so;
* `build.bat`, `deploy.cmd`, `Install.ps1`, `App.sln`, `settings.reg` →
  `text: set`, `eol: crlf`.

**Renormalization preview.** `git add --renormalize .` changes the index
content of **zero** files beyond the three this branch edits deliberately
(`.gitattributes`, `.gitignore`, `public/mix-manifest.json`). The stored
blobs were already LF, so adding `.gitattributes` introduces no
repository-wide churn and none is staged.

---

## 8. Expected working-tree artifacts after a build

These are generated, not source, and are deliberately left uncommitted.
Seeing them dirty after `npm run production` is correct behaviour, not a
mistake:

| Path | Why |
|---|---|
| `public/vendors/**` | Third-party assets re-minified by a newer terser; semantically identical output, 443 files of pure churn |
| `public/fonts/**` except `geist/` | Copied verbatim; differs only by line endings |
| `public/images/**` | Copied verbatim, plus runtime upload directories (`branding/logo_compact/`, `websites/`) that suites write into |
| `bootstrap/cache/packages.php`, `services.php` | Now gitignored — regenerated by every `composer install` |
| `storage/logs/test-baseline/**` | Raw logs the baseline command keeps |

`public/images/branding/logo_compact/` and `public/images/websites/`
accumulate files because the branding and website suites upload into the
real `public/` tree and never clean up. That is a genuine leak, but it
writes only into untracked runtime directories, is not one of the
reproduced failures in §2, and fixing it means changing upload behaviour
those suites assert on — so it is recorded here rather than widened into
this branch.
