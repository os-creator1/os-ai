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

## 0.2 Correction round 2 — final synchronisation

| | |
|---|---|
| Pre-merge branch HEAD | `fdef3da45fe4dc6a5072c579e74111591a49d223` |
| Fetched `origin/main` | `ef0c01346b517fa093d7d95b3384cf25689a0288` |
| Merge commit | `3339c45a50a40def187b86aa42282ef5d1976402` |
| Conflicts | **none** |

Main advanced by 19 commits across three pull requests — #223 (Customer
Experience Slice 5: wallet, payer, balance and spending-control UX),
#224 (Slice 3 messaging provider contract) and #225 (Security
Remediation Slice 0) — touching 89 files, +14 029 / −971.

All 89 files main changed are **byte-identical** between the merge result
and `origin/main`: each path's blob SHA was compared individually, not
just the merge's exit status. The merge touched no path this branch owns,
and this branch's round-0/round-1 work touched no path main changed.

Security Remediation Slice 0's removals are intact after the merge:

| Invariant | State at the merge commit |
|---|---|
| `app/Http/Controllers/Debug/DebugController.php` | absent from `origin/main`, from `HEAD`, and from disk |
| Debug routes in `routes/` | none; `routes/web.php` carries only the §16.A.1 comment recording the removal |
| `tests/Feature/Security` | 172 tests, 1 302 assertions, green |

One honest observation, recorded and **not** changed here:
`app/Http/Controllers.zip` — tracked since the 2026-07-18 baseline import,
long predating this lane and PR #225 — still contains a `DebugController`
entry. It is a dormant archive: not autoloaded, not routable, and it
resurrects nothing. But the source text does still ship in the
repository. It is main's file and outside this branch's allowed paths, so
it is reported rather than deleted.

### 0.2.1 A stale generated artifact, not a code defect

`DebugRouteRemovalTest` failed once immediately after the merge:

```
ErrorException: include(.../app/Http/Controllers/Debug/DebugController.php):
Failed to open stream: No such file or directory
```

`vendor/composer/autoload_classmap.php` — generated, untracked — still
mapped the class main had deleted, so `class_exists()` tried to include a
file that no longer existed. `composer dump-autoload` cleared it and the
suite went green. Recorded because the failure *looks* like the removal
being incomplete and is not: nothing in `app/`, `routes/` or `config/`
references the controller.

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
* `tests/Feature/Usage/SlotAgreementConcurrencyTest.php` (correction round 2)
* `tests/Feature/Usage/ConversationsConcurrencyTest.php` (correction round 2)
* `tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php` (correction round 2)
* `tests/Feature/Usage/Support/concurrent_conversations_send_runner.php` (correction round 2)
* `tests/Feature/Security/BlacklistsSecurityTest.php` (correction round 2)

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

**Resolved in correction round 2.** This task authorised correcting the
two Usage runners on this branch, so all four deferrals above are now
closed — see §9 (K) for the seam and its proofs, and §6.2 for the
resulting suite. `git diff origin/main HEAD -- app/Library/Usage
tests/Unit/Usage` remains empty: the correction touched the two runner
scripts, their two parent tests and nothing else Lane A owns.

Round 2's own audit opened a **new**, smaller deferral in the same class —
nine `tests/Feature/Usage/**` tests whose runtime-generated runners carry
no database guard. They are not broken today. §9.1 records them, the
reason they are latent rather than active, and why correcting them is out
of this round's scope.

---

## 5.1 Deferred corrections (unowned)

Not Lane A's, not scoped to this round, and each recorded with the
evidence needed to act on it without re-deriving anything.

| Finding | Change | Why deferred |
|---|---|---|
| §9 **N** — `AppConfig::setEnv()` writes `base_path('.env')`, so five callers still reach a developer's real environment file from tests | Resolve `app()->environmentFilePath()` instead; identical in production | Production change to a five-caller method, arriving after this round's regression was measured |
| §9.1 — nine `tests/Feature/Usage/**` tests generate runners with no database guard | Give each generated child the `TestDatabaseSafety` guard the named runners now carry | `tests/Feature/Usage/**` is Lane A's; latent, not active |
| §0.2 — `app/Http/Controllers.zip` still contains the `DebugController` source PR #225 removed | Delete the archive once nothing references it | Main's file, predates this lane, outside this branch's allowed paths |

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

> **Qualified in correction round 2.** Byte-identical, yes — but not
> untouched. `AppConfig::setEnv()` resolves `base_path('.env')`, which the
> temporary-environment-file trait does not redirect, so
> `tests/Feature/Settings` still *writes* the real file with the same
> bytes. See §9 (N) for the reproduction and §5.1 for the deferral.

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

## 6.2 Result — correction round 2

| | Before (`d2b275e`) | Round 0 | Round 1 | **Round 2 (merged `ef0c0134`)** |
|---|---|---|---|---|
| Tests | 4 992 | 5 026 | 5 070 | **5 196** |
| Assertions | 21 129 | 28 118 | 28 408 | **29 737** |
| Errors | 911 | 0 | 0 | **0** |
| Failures | 35 | 4 | 4 | **0** |
| Distinct failing tests | **947** | 4 | 4 | **0** |

Run through `scripts/test-baseline.php --database=ultimatesms_testing_sync`
on a database reset with `migrate:fresh` immediately beforehand. The
runner's own contract held: it validated the database Laravel actually
resolves before touching it, and it refuses a zero-test success (proven
earlier in this round, when a mis-shaped argument made PHPUnit discover no
tests and the runner exited 5 rather than 0).

**Every one of the 5 196 progress characters is a dot.** No error, no
failure, no skipped, incomplete or risky test — counted from the progress
stream, not inferred from the summary line. The 21 PHPUnit deprecations
are framework-level notices and are not failures.

The four failures round 1 had to defer are gone, and they are gone
because the defect was fixed, not because anything was relaxed: no
assertion was deleted, weakened or made conditional anywhere in this
round, and the concurrency barriers still block on a child-emitted
`LOCKED` line rather than on elapsed time.

The suite ran against **the committed `public/` tree** — the runner found
the manifest already valid and skipped its asset step — so this result
describes exactly what the branch contains, not a locally rebuilt variant
of it.

After the run:

| | |
|---|---|
| `.env` | content unchanged (`16b7a6c1…` before and after). It was *written*, with identical bytes — see §9 (N) |
| `.env.testing` | untouched; mtime unchanged from before the round began |
| Working tree | the seven intended files only |
| `public/` | **0** changed or untracked paths |
| Runtime upload leftovers | **0** |
| Temporary environment directories | **0** |
| Temporary databases | none created by the suite; the canonical `ultimatesms_testing` was neither reset nor written by this lane |

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

---

## 9. Correction round 2 — the subprocess database seam

### K — The two Usage runners hardcoded the canonical database **(3)**, corrected

`tests/Feature/Usage/Support/concurrent_slot_agreement_runner.php` and
`concurrent_conversations_send_runner.php` each opened with

```php
const EXPECTED_DATABASE = 'ultimatesms_testing';
```

and refused to run against anything else. The guard was right in spirit —
a child process that writes must prove where it is pointed — but the
literal made both tests **unrunnable on any isolated database**, which is
precisely why §6.1 recorded four deferred failures in round 1.

Both runners now resolve the same seam every other subprocess in this
repository uses:

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

The properties this preserves, each verified rather than asserted:

| Requirement | How it holds |
|---|---|
| No hardcoded name, no silent fallback | A missing or empty `EXPECTED_TEST_DATABASE` is a **refusal**, not "no expectation" — the handoff not happening means the child cannot know what it is authorised to write |
| One database-safety authority | `Tests\Support\TestDatabaseSafety` only; no second helper was introduced |
| No broad "contains test" acceptance | `acme_test_live` is refused |
| Refusal happens before any write | The guard runs immediately after bootstrap, before the first model call |
| The parent hands its own resolved name down | `childEnvironment()` returns `DB_DATABASE` **and** `EXPECTED_TEST_DATABASE` from `TestDatabaseSafety::activeTestDatabase()` |
| Barriers unchanged | The `LOCKED`-line and arrival-count barriers are untouched; **no sleep was added as synchronisation** |

Proven behaviour, run directly against the runners:

| Handoff | Result |
|---|---|
| `EXPECTED_TEST_DATABASE` unset | exit 3 — "EXPECTED_TEST_DATABASE was not handed down by the parent test" |
| set empty | exit 3 — same |
| `ultimatesms_testing_other` (mismatch) | exit 3 — "the parent process is using [ultimatesms_testing_other], and a spawned child must resolve the very same disposable database" |
| `acme_test_live` (merely contains "test") | exit 3 |
| active database `ultimatesms_production` | exit 3 — "it is not the canonical test database or a suffixed sibling of it" |
| correct handoff | passes the guard and reaches mode dispatch |

### L — A Security subprocess wrote with no guard at all **(3)**, corrected

The subprocess audit found `tests/Feature/Security/BlacklistsSecurityTest`
spawning a generated script through `proc_open` that **creates and deletes
users, customers and blacklists** with no database check whatsoever — a
strictly worse position than K, which at least refused. The generated
child now carries the same guard, with the parent's resolved name baked in
via `var_export(TestDatabaseSafety::activeTestDatabase(), true)`.

### M — The refusal message was double-framed

`assertMatchesActiveTestDatabase()`'s mismatch message carried its own
`Refusing to run:` prefix and `Aborting before any database write.`
suffix, which the callers add too, so a real refusal read:

```
Refusing to run: Refusing to run: resolved database is [...]. Aborting
before any database write. Aborting before any database write.
```

The helper's message is now a bare statement, matching the shape its
unsafe-name sibling already had. Cosmetic, but a guard message is read
exactly once — when something has gone wrong — and it should be legible.


### N — `AppConfig::setEnv()` still writes the developer's real `.env` **(3)**, found here, deferred

Found by watching `.env`'s modification time during round 2's own
verification, not by a failing test — which is exactly why it survived
rounds 0 and 1.

`tests/Support/UsesTemporaryEnvironmentFile` redirects
`app()->environmentFilePath()`, and `TestCase` applies it to every test,
so nothing that resolves the environment file *through the framework* can
reach a developer's file. Three code paths do not resolve it through the
framework:

| Path | Call |
|---|---|
| `app/Models/AppConfig.php:423` | `$file_path = base_path('.env')` |
| `app/Library/Tool.php:850, 859` | `file_get_contents(base_path('.env'))` / `file_put_contents(...)` |
| `app/Repositories/Eloquent/EloquentSettingsRepository.php:312, 321` | same pair |

`base_path()` is not redirected and must not be — it is the application
root, used for everything. So `AppConfig::setEnv()` and its five remaining
callers write the real file. This was known when
`app/Library/Branding/BrandingUploadService.php` was routed through
`PlatformSettingsEnvWriter` in round 0 (the comment at its line 96 says
so); what was not established is that the *other* callers still reach it
from tests.

**Reproduced, measured, bounded:**

```
before: md5 16b7a6c1…  mtime 14:51:13
php vendor/bin/phpunit tests/Feature/Settings   →  57 tests, 172 assertions, green
after:  md5 16b7a6c1…  mtime 14:55:41
```

The suite **does write** the developer's `.env` — the mtime moves — but
the bytes are unchanged, because `SettingsController` calls
`AppConfig::setEnv('TERMS_OF_USE', …)` and `('PRIVACY_POLICY', …)` with
the values already in the file. Both keys are present in this machine's
`.env` and in neither `.env.example`, which is the signature of exactly
this append.

So §6.1's round-1 statement — `.env` byte-identical before and after — is
**true but incomplete**. The file is byte-identical; it is not untouched.
Today the write is idempotent; the moment a settings test asserts a value
different from the developer's, it stops being idempotent and the
developer's environment changes underneath them. That is the same defect
class as §H2, one step short of firing.

**The fix, and why it is not applied here.** One line in
`app/Models/AppConfig.php` — resolve `app()->environmentFilePath()`
instead of `base_path('.env')` — makes the existing trait cover all five
remaining callers, and changes nothing in production, where the two
expressions are equal. It is small and it is right. It is also a
**production** change to a method five call sites depend on, arriving
after this round's full regression had already been measured, and outside
the correction list this round was scoped to. Landing it would mean
claiming a regression result that never covered it. It is therefore
recorded here, with its reproduction, as the next correction.

### 9.1 Full subprocess audit

Every site in `tests/` and `scripts/` that spawns an OS process
(`new Process`, `proc_open`, `exec`, `shell_exec`):

| Site | Writes to the database | Guard |
|---|---|---|
| `Usage/Support/concurrent_slot_agreement_runner.php` | yes | **corrected this round** |
| `Usage/Support/concurrent_conversations_send_runner.php` | yes | **corrected this round** |
| `Security/BlacklistsSecurityTest.php` | yes | **corrected this round** |
| `Entitlement/EntitlementManagerConcurrencyTest.php` + `Support/concurrent_business_slot_runner.php` | yes | present |
| `Entitlement/WorkspaceEntitlementBackfillV1ConcurrencyTest.php` | yes | present |
| `Workspace/WorkspaceManagerConcurrencyTest.php` | yes | present |
| `Workspace/WorkspaceBackfillV1ConcurrencyTest.php` | yes | present |
| `Workspace/Support/concurrent_backfill_runner.php` | yes | present |
| `Workspace/Support/concurrent_workspace_resolver_runner.php` | yes | present |
| `Workspace/Support/run_historical_m1a_suite.php` | yes | present |
| `Workspace/Support/run_workspace_enforcement_suite.php` | yes | present |
| `Support/ResolvedTestDatabaseGuardTest.php` | yes | present (it *is* the guard's own test) |
| `Support/TemporaryEnvironmentFileTest.php` | no — the probe reads environment files only | n/a |
| `scripts/test-baseline.php` | yes | present |

**Deferred, Lane A owned.** Nine further tests under
`tests/Feature/Usage/**` generate a runner into `sys_get_temp_dir()` at
runtime and spawn it:

`AutoRechargeFailedPaymentRetryTest`, `ConcurrentTopUpConcurrencyTest`,
`PayerAssignmentConcurrencyTest`, `ProviderRefundDisputeConcurrencyTest`,
`RefundablePaidAvailableAccountingTest`,
`Slice5/AutoRechargeRollingWindowConcurrencyTest`,
`UsageWalletBackfillV1ConcurrencyTest`,
`UsageWalletManagerConcurrencyTest`,
`UsageWalletManagerSetActiveRateConcurrencyTest`.

None hardcodes a database name, so none is broken today: they inherit the
caller's real environment, and `phpunit.xml` pins no `DB_DATABASE`, so the
OS environment is the only source and inheritance is correct. But none
carries a guard either. The hazard is latent rather than active — a lane
that selected its database in-process (a `<server>` entry, a `putenv()`
without an OS-level export) would have those children silently resolve
`.env`'s database instead. Correcting them means editing nine
`tests/Feature/Usage/**` files Lane A owns, well beyond the two runners
this round was scoped to, so they are recorded here as the next
correction rather than taken.

### 9.2 Build determinism (correction round 2)

`npm ci` on the merged tree resolves webpack **5.105.4** (the pin),
laravel-mix 6.0.49, webpackbar 5.0.2; `npm ci --dry-run` reports no drift.
Merged main changed no build input — only `resources/lang/en/locale.php`
and three Blade views, none of which is a compiled-asset source. `terser`
(5.50.0) and `terser-webpack-plugin` (5.6.1) resolve **identically** on
`origin/main` and on this branch; webpack is the only differing entry, and
webpack does not minify.

Three production builds were run:

| Build | Starting tree | Result |
|---|---|---|
| #1 | runtime uploads present | **discarded** — the manifest indexed 4 upload artifacts (the §A.2 mechanism, reproduced) |
| #2 | build #1's output | 1 299 files, byte-identical to #1 |
| #3 | `public/` reset to the committed state | 1 299 files, **byte-identical to #1** |

So a second clean build produces no diff, and the build is reproducible
from the committed state rather than only from a warm tree.

The corrected manifest carries **1 187 entries**, **zero** of which point
into a runtime upload directory, and **zero** of which name a file that
does not exist on disk. `/js/core/theme-tokens.js` and
`/js/scripts/pages/theme-settings.js` both resolve to real generated
files. `MixManifestIntegrityTest` reproduced the pollution as a failure
before the fix and passes after it.

### 9.3 Why nothing under `public/` is committed this round

Every one of the 471 files a production build leaves dirty was classified
individually, by comparing each against its `HEAD` blob with carriage
returns stripped:

| Class | Count | Disposition |
|---|---|---|
| Required new output | **0** | Nothing untracked appeared; round 0 already committed the new outputs |
| Changed-source output | **0** | Main changed no compiled-asset source |
| Identical content, line endings only | 28 (including `mix-manifest.json`) | Restored — committing them would only re-add CRLF that `.gitattributes` normalises straight back |
| Third-party re-minification churn | 443, all `public/vendors/js/**` | Restored |

The 443 are not this branch's doing. `public/vendors/js/**` has been
touched by exactly **one** commit in the repository's history — the
2026-07-18 baseline import — so those blobs are the vendor's own shipped
minified files, while `webpack.mix.js` line 61 re-minifies them through
`mix.scripts()` on every build. Any production build on `origin/main`
produces the same delta, with the same terser. Committing them would put
443 files of semantically identical third-party churn into a reliability
branch.

Result: `public/` is left exactly as `HEAD` has it, and the round-2 commit
contains only source.

### 9.4 Database and migration verification (correction round 2)

All on the isolated `ultimatesms_testing_sync`; the canonical
`ultimatesms_testing` was neither reset nor written by this lane's runs.

| Check | Result |
|---|---|
| `scripts/resolve-test-database.php` | exit 0, `RESOLVED_DATABASE=ultimatesms_testing_sync` — the database Laravel *resolves*, cross-checked against `SELECT DATABASE()` |
| `migrate:fresh` | 251 steps DONE |
| `migrate:status` | 250 ran, **0 pending** |
| `rollback --step=3` → forward | 0 pending |
| `rollback --step=4` → forward | 0 pending |
| `rollback --step=1` → forward | 0 pending |

Slice 5 added **four** migrations, not three — `2026_09_11_120001`
through `120004`. `120004`
(`require_deliberate_ceiling_for_enabled_auto_recharge_on_business_usage_wallets`)
arrived with Slice 5's own correction round 2 and is easy to miss when
counting from the original contract.

### 9.5 Focused suites (correction round 2)

All on the isolated `ultimatesms_testing_sync`, after `migrate:fresh`.

| Suite | Tests | Assertions |
|---|---|---|
| Lane C infrastructure — `Unit/Support/TestDatabaseSafetyTest`, `Unit/Assets/MixManifestIntegrityTest`, `Feature/Assets`, `Feature/Support`, `Feature/Branding` | 131 | 744 |
| `tests/Feature/Security` | 172 | 1 302 |
| `tests/Feature/Workspace` | 774 | 2 279 |
| `tests/Feature/Business` | 543 | 8 213 |
| `tests/Feature/GoogleBusinessProfile` | 136 | 1 610 |
| `tests/Feature/Usage/Slice5` | 92 | 1 082 |
| `tests/Unit/Usage` | 21 | 46 |
| `tests/Feature/Usage` | 998 | 5 190 |
| Render and dashboard — `Feature/Dashboards`, `Auth/AuthActiveThemeRenderingTest`, `Auth/AuthPageRenderTest`, `Feature/Branding`, `Settings/PlatformSettingsIndexRenderTest`, `Feature/Theme`, `Feature/Assets`, `Website/Public/WebsitePublicRenderingTest` | 188 | 1 188 |
| Concurrency bundle — both corrected Usage tests plus `Workspace` ×2 and `Entitlement` | 18 | 124 |

Every one green, with no assertion weakened, skipped or made conditional.

**Determinism of the corrected concurrency tests.** Ten consecutive runs
of `SlotAgreementConcurrencyTest` + `ConversationsConcurrencyTest`, each
**7 tests / 38 assertions**, all exit 0 — and deliberately run on a
*second* isolated database, `ultimatesms_testing_sync_conc`, **while the
full 5 196-test regression was running against
`ultimatesms_testing_sync`**. Two independent suites, two databases, one
MySQL server, no interference: that is the property this whole lane
exists to buy, and before the round-2 correction these two tests were the
only ones that could not participate in it.

An earlier set of five runs on `ultimatesms_testing_sync` produced the
same 7/38 result, so 15 consecutive runs are on record with identical
counts.
