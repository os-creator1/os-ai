# Mainline baseline reliability remediation

Repairs the recurring deterministic baseline failures, test-isolation
defects, asset-toolchain breakage and generated-artifact churn that cost
time in every feature lane.

**This document is not permission to weaken assertions or hide failures.**
Every change below either fixes a real defect or removes a hidden
dependence on one developer's local environment. No assertion is deleted,
relaxed or made conditional in order to go green.

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
  5.107.2, 5.108.4, 5.109.0 and 5.110.3 do not. **5.106.2 is the last
  version that ships it.**

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

| | Before (`d2b275e`) | After |
|---|---|---|
| Tests | 4992 | 5026 (+34 new safety tests) |
| Assertions | 21 129 | 28 118 |
| Errors | 911 | 0 |
| Failures | 35 | 4 |
| Distinct failing tests | **947** | **4** |

Both runs used an isolated database (`ultimatesms_testing_mbr1`) on the
same machine and toolchain.

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
