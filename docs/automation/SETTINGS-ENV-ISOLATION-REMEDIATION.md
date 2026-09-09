# SETTINGS ENVIRONMENT-FILE ISOLATION REMEDIATION

## 1. Status and base

**Status:** Environment-isolation remediation. Exactly one production line
changes (`app/Models/AppConfig.php`); everything else is tests and this
document.

**Base:** `origin/main` at `16f53dd5e156a85fe89ae7cf0434bc5bf9845292`
(`Merge pull request #228`), which is the tip of `origin/main` at the time this
branch was cut and is trivially an ancestor of itself.

**Branch:** `agent/baseline-settings-env-isolation`, created in a dedicated
worktree directly from that commit.

**Scope:** eight paths, listed in §7. Nothing under `config/`, `database/`,
`routes/`, `resources/`, `public/`, `bootstrap/cache/`, `vendor/` or any
dependency, generated asset or environment file is touched.

**Revision — Correction Round 1 (true isolation).** The first revision left
`AppConfig::setEnv()` writing the real `base_path('.env')` during tests and had
the harness snapshot and restore that file afterwards. **That was withdrawn as
insufficient, and the reasoning is recorded here so it is not reintroduced.**
Restoring damage is not isolation:

* two concurrent processes can write and restore the same shared file in
  conflicting orders;
* a kill, PHP fatal or power loss leaves the damage in place, because teardown
  never runs;
* another process can read the test's values out of the real file during the
  window before restoration;
* the requirement was that production writers address only the disposable
  file, not that damage is repaired afterwards.

`AppConfig::setEnv()` now uses `app()->environmentFilePath()` — the same seam
`write_env()` has always used — and the snapshot/restore code is deleted. §5
states precisely what that achieves, and names the writers this branch is not
authorized to change.

---

## 2. The defect, reproduced before anything was written

Both claims below were produced by running a **pristine checkout of the base
commit** in an isolated worktree against an isolated database, with byte
hashes taken immediately before and after.

### 2.1 `tests/Feature/Settings` rewrote the developer's `.env.testing`

| | SHA-256 |
|---|---|
| before | `095665a5ea28a751556d61b82d3922942eba34ad0afd0271f3b0b16d327d8317` |
| after | `362066891d3961d0096d4f42c7eae972bab7d6992e4ab3a176d7594512104d10` |

The diff was not cosmetic:

* `APP_NAME` changed from `"AI Business OS"` to `"Test App"` — the settings
  suite's own fixture payload;
* `MAIL_DRIVER` changed from `array` to `"smtp"`;
* every blank line in the file was deleted;
* the suite's fixture values were appended wholesale, **including
  secret-shaped ones** (the suite's fake `OPENAI_API_KEY` fixture among them).

### 2.2 The same run also modified `.env`

| | SHA-256 |
|---|---|
| before | `9c6021963c81a7a9eef1e1c5b5e95a96c74affcd5b3f6d03a05444598bb640ce` |
| after | `9569dc2b120b7c122adbb8f86129996322e35a47a72075fa8061d481b4fd8a94` |

`tests/Feature/Branding` reproduced both effects independently.

### 2.3 Why this is an isolation break, not an inconvenience

The next run — in this lane, or in any other lane sharing the machine — starts
from an environment the previous run edited. That is a silent, cross-run and
cross-lane dependency, and it produces failures that look like product defects.

It is not hypothetical residue: the developer's real `.env` at this commit
already begins

```
APP_NAME="Test App"
APP_TITLE="Test Title"
```

which is exactly `SettingsTestHelpers::baseGeneralSettingsPayload()`. A
previous suite run wrote it there, and it has been in place since.

---

## 3. Root causes — there are three, and they are separate

### 3.1 The write path rewrites the active environment file wholesale

Every platform-settings save, the branding upload service and the demo-mode
toggle reach `App\Helpers\write_env()`
(`app/Helpers/namespaced_helpers.php`), which reads
`app()->environmentFilePath()`, merges one key, and writes the whole file
back. Under `APP_ENV=testing` — set by `phpunit.xml` — that path is
`.env.testing`.

`Illuminate\Foundation\Application` exposes exactly the seam needed:
`useEnvironmentPath()` and `loadEnvironmentFrom()`. Redirecting them redirects
every writer that honours `environmentFilePath()`.

### 3.2 A second writer bypassed that seam entirely — now corrected

`App\Models\AppConfig::setEnv()` did **not** use `environmentFilePath()`. It
hardcoded the real file:

```php
$file_path = base_path('.env');              // before
$file_path = app()->environmentFilePath();   // after
```

That one line, plus a docblock, is the entire production change on this branch.
Nothing else about `AppConfig` moves, no caller is altered, and no signature or
return value changes.

**It is behaviour-preserving in production.** There the application's
environment file *is* `base_path('.env')`, so the expression resolves to the
identical path it always did. Behaviour differs only where the framework has
loaded a different file — exactly the case the defect lived in.

It also fixes a correctness bug that was hiding behind the isolation bug. Under
`APP_ENV=testing` Laravel loads `.env.testing`, so `setEnv()` was writing to a
file the framework had not loaded, and a value it wrote was invisible to the
environment actually in force. Every other writer — `write_env()`, and
`PlatformSettingsEnvWriter` through it — had always used the correct seam;
`setEnv()` was the sole exception.

It is reached from `SettingsController` (`TERMS_OF_USE`, `PRIVACY_POLICY`,
`MAINTENANCE_SECRET_PATH`), `BrandingUploadService` (the `APP_LOGO` family),
`EloquentSettingsRepository` (`TRAI_DLT`, `GATEWAY_WISE_BILLING`), and
`AppConfig` itself for uploaded assets. All of those now follow it onto the
disposable file with no change of their own.

§8 inventories every environment-file writer in the repository; §5.1 and §5.2
name the ones that remain hardcoded.

### 3.3 The read side addressed a different file, and parsed it naively

`SettingsTestHelpers::readEnvValue()` read `base_path('.env')` — the file
`write_env()` never touches under `APP_ENV=testing` — so the settings suite
asserted against a file its own writes had not reached, and was really reading
whatever the developer's `.env` happened to contain.

It also decoded with `trim($value, "\"\n")`, which cannot strip the trailing
`\r` of a CRLF line and therefore leaves the closing quote attached. That is
the origin of actual values such as `AI Business OS"`.

---

## 4. The remediation

### 4.1 One trait, applied at the base class

`Tests\Support\UsesTemporaryEnvironmentFile` is applied by `Tests\TestCase`,
which every test in this repository extends. `setUp()` activates it and
`tearDown()` restores — and PHPUnit runs `tearDown()` after a pass, after a
failed assertion and after an uncaught exception, so every outcome is covered.

On activation the trait:

1. records the application's current environment path and filename;
2. creates a directory under `sys_get_temp_dir()` — outside the repository —
   named `aibos-env-<pid>-<sequence>-<16 random hex chars>`;
3. copies the **currently active** environment file into it, keeping its
   original basename, so `environmentFile()` still reports `.env.testing`;
4. points the application at the copy.

On restore it hands back both paths and deletes the temporary directory. Every
step is guarded and every field cleared, so calling restore twice is a harmless
no-op.

**It never reads, writes or snapshots the real environment files.** There is no
`base_path('.env')` in any executable line of the trait. That is deliberate:
see the withdrawal note in §1.

Re-activating restores first, so a second activation seeds from the **real**
file rather than from the copy already in force. That is what makes one test's
writes unable to reach the next test.

### 4.2 Uniqueness across processes

The directory name carries the process id, a per-process monotonic sequence
and 16 random hex characters. Two concurrent PHPUnit processes cannot be handed
the same directory, and a single process re-activating never reuses one.

### 4.3 The decoder is a single left-to-right scan, and that matters

`App\Helpers\format_dotenv_value()` escapes in a fixed order — backslash, then
double quote, then newline — and always wraps the result in double quotes.
`readActiveEnvValue()` inverts it with one scan that consumes each escape
sequence whole.

**A `str_replace(['\n', '\"', '\\\\'], …)` pass is not equivalent and is
wrong.** The encoded form of the six characters `C:\new` is `C:\\new`. A
leading `\n` replacement matches the *second* backslash together with the
following `n`, decoding it to `C:` + backslash + a real newline + `ew`. The
scan cannot make that mistake, because it consumes `\\` as one unit before it
ever looks at the `n`. `roundTripValues()` covers this case by name
(`backslash before n`).

Covered and asserted: CRLF files, quotes, escaped quotes, backslashes, real
newlines, absent keys (`null`), explicitly empty keys (`''`), unquoted values,
and a key that is a prefix of another key.

### 4.4 One documented behaviour that is a loss, not a bug

`format_dotenv_value()` maps `"\r\n"`, `"\n"` and `"\r"` all onto the single
escape `\n`. A value containing CRLF therefore comes back containing LF. That
is the production writer's real, deliberate contract, so
`test_a_carriage_return_inside_a_value_is_normalised_to_a_newline_by_the_writer()`
asserts the exact normalisation rather than a faithful round trip. Asserting a
faithful round trip would fail, and "fixing" it would mean editing production
code this branch must not touch.

---

## 5. What is achieved

| Required property | Status |
|---|---|
| Every test extending `Tests\TestCase` gets its own disposable copy | **Achieved** — applied on the base class |
| The disposable file lives outside the repository | **Achieved** — under `sys_get_temp_dir()` |
| Seeded from the environment file actually active for that process | **Achieved** |
| **Production writers write only to the disposable file** | **Achieved** for `write_env()`, `write_envs()`, `PlatformSettingsEnvWriter` and — as of this round — `AppConfig::setEnv()`. See §5.1 for the one caller this branch may not change |
| The real `.env` and `.env.testing` are never opened for writing by a test | **Achieved** — asserted by **mtime**, not only by bytes, before any teardown runs |
| One test's changes never leak into another | **Achieved** |
| Separate processes never share a file or directory | **Achieved** — proven with two genuinely concurrent subprocesses, both driving `AppConfig::setEnv()` |
| Cleanup after success, assertion failure and uncaught exception | **Achieved** — proven by subprocess probe |
| Real files survive a forced kill | **Achieved** — proven by `SIGKILL`-equivalent termination mid-test |
| Another process cannot observe a test value through the real files | **Achieved** — proven by reading both real files from a second PHP process |
| Re-activating deletes the previous copy | **Achieved** |
| The original environment path and filename are restored | **Achieved** |
| `readEnvValue` reads the active disposable file and decodes correctly | **Achieved** |

**The snapshot/restore fallback is gone.** `UsesTemporaryEnvironmentFile` no
longer contains `base_path('.env')` in any executable line, holds no snapshot
of the real file, and never writes it. The properties above are proven by
assertions that run **during** the test, before teardown, so they demonstrate
the file was never touched rather than that it was repaired.

### 5.1 One writer this branch is not authorized to change — reported, not silently fixed

`RefreshDatabase` (378 test files) runs `migrate:fresh` inside the test
process. Three migrations write the real file directly:

```
database/migrations/2025_05_27_152009_add_default_time_format_to_settings.php:14,34
database/migrations/2025_06_17_155331_add_open_ai_environment_value_on_env_file.php:11,46
database/migrations/2025_06_30_122030_create_env_value_for_terms_of_use_n_privarcy_policy.php:9,39
```

**Exact call chain:**

```
PHPUnit boot
  → Illuminate\Foundation\Testing\RefreshDatabase::refreshTestDatabase()
    → Artisan::call('migrate:fresh')
      → <each migration>::up()
        → file_put_contents(base_path('.env'), …)
```

**Measured, not inferred.** Running `php artisan migrate:fresh` alone against
this worktree moved `.env`'s mtime from `1788974923` to `1788974994` while its
SHA-256 stayed `9c60219…b640ce`.

Bytes are unchanged **only because of local state**, and that is not a
guarantee. Each migration strips its own keys, appends them with fixed values,
and rejoins with `PHP_EOL`. This `.env` already ends with exactly those keys in
exactly that order from earlier runs, and `PHP_EOL` is `\r\n` on this Windows
host, matching the file's CRLF endings. On Linux or CI, `PHP_EOL` is `\n`, so
the same migrations would rewrite a CRLF `.env` to LF — a real byte change. A
`.env` with a different key order would also change.

`database/` is outside this branch's allowlist, so **these are reported, not
modified.** The brief's instruction was to stop and report the exact call chain
rather than expand scope, which is what this section does.

**Recommended follow-up:** give the three migrations the same one-line
treatment `AppConfig::setEnv()` received. They are guarded by
`file_exists($envPath)`, so switching to `app()->environmentFilePath()` is
equally behaviour-preserving in production.

### 5.2 Two further hardcoded writers, not reached by these suites

| Writer | Reachability |
|---|---|
| `App\Library\Tool::versionSeeder('3.4.0')` lines 850, 859 | The inherited version updater. Not called by `tests/Feature/Settings` or `tests/Feature/Branding`, and not by any code path either suite exercises |
| `EloquentSettingsRepository::pusherSettings()` lines 312, 321 | Reached only from the Pusher settings save; no test in either suite posts it |

Both still hardcode `base_path('.env')`. Neither was modified, because neither
is on the allowlist and neither is reached by the suites in scope. They belong
in the same follow-up as the migrations.

### 5.3 What this branch does NOT claim

It does **not** claim repository-wide absolute environment isolation. It claims
exactly this, and the evidence in §6 supports exactly this:

* every production writer **reached by a test through application code** now
  addresses the disposable file;
* the real files are never opened for writing by those writers, proven by
  mtime;
* the migration bootstrap still opens the real `.env`, is measured, is named
  above, and is left to a branch permitted to touch `database/`.

### 5.4 A behaviour the correction exposed

`AppConfig::setEnv()` is an **update-only** writer: it rewrites lines that
already match its key and adds nothing when none does.

While it wrote the real `.env` this was invisible, because that file happened
to contain `APP_LOGO` from earlier runs.
`BrandingUploadValidationTest::test_a_valid_png_logo_upload_…` therefore
appeared to pass while asserting against a file the application had not loaded.

Now that the writer and the reader address the same file, the test states its
precondition explicitly: it seeds `APP_LOGO` with the bundled default first,
asserts that value, performs the upload, then asserts the value was **replaced**
with the content-addressed path. That is strictly stronger than before — it now
proves a write happened rather than that some value existed.

The underlying `setEnv()` limitation is unchanged and deliberately so: the
brief called for the smallest behaviour-preserving correction, not a redesign.
A deployed `.env` carries `APP_LOGO`, so production is unaffected.

## 6. Verification

Isolated worktree, isolated database `ultimatesms_testing_lane_d`. The
canonical `ultimatesms_testing` database was never reset, migrated, truncated
or written to.

Reference hashes for the worktree environment files, unchanged throughout:

| File | SHA-256 |
|---|---|
| `.env` | `9c6021963c81a7a9eef1e1c5b5e95a96c74affcd5b3f6d03a05444598bb640ce` |
| `.env.testing` | `095665a5ea28a751556d61b82d3922942eba34ad0afd0271f3b0b16d327d8317` |

### 6.1 Focused isolation suite

`tests/Feature/Support/TemporaryEnvironmentFileTest.php` — **28 tests, 79
assertions, 0 failures**. It covers:

* the active file is a disposable copy, outside the repository, and exists;
* the original basename and `environmentFile()` are preserved;
* the copy is seeded from the active file;
* each activation is unique and deletes the previous directory;
* a `write_env()` write lands in the copy and neither real file changes;
* a value written before re-activation does not survive it;
* restoring returns the original path and filename, and is idempotent;
* the real `.env` is restored after a direct hardcoded-path write;
* eleven write/read round trips, including `backslash before n`;
* absent versus explicitly empty keys;
* a CRLF file, unquoted values, and prefix-colliding keys;
* **subprocess probes** for normal completion, assertion failure and uncaught
  exception, each asserting the child's temporary directory is gone and both
  real files are unchanged;
* **two genuinely concurrent subprocesses** — both started before either is
  waited on — asserting different pids, different directories, and both
  cleaned up;
* no temporary directory belonging to this process outlives the suite.

### 6.2 Repeated runs — determinism

Five consecutive focused runs, each followed by a hash check:

| Run | Result | `.env` | `.env.testing` | Leftover temp dirs |
|---|---|---|---|---|
| 1 | 28 tests, 79 assertions | identical | identical | 0 |
| 2 | 28 tests, 79 assertions | identical | identical | 0 |
| 3 | 28 tests, 79 assertions | identical | identical | 0 |
| 4 | 28 tests, 79 assertions | identical | identical | 0 |
| 5 | 28 tests, 79 assertions | identical | identical | 0 |

### 6.3 Settings and Branding, against the pristine baseline

| Suite | Pristine base | With this branch | Delta |
|---|---|---|---|
| `tests/Feature/Settings` | 57 tests, 103 assertions, **21 errors, 12 failures** | 57 tests, 120 assertions, **21 errors, 0 failures** | **−12 failures**, +17 assertions, errors unchanged |
| `tests/Feature/Branding` | 53 tests, 234 assertions, **19 errors**, 0 failures | 53 tests, 234 assertions, **19 errors**, 0 failures | unchanged |

Every one of the 21 Settings errors and all 19 Branding errors is the same
pre-existing, unrelated failure:

```
Unable to locate Mix file: /js/core/theme-tokens.js
(View: resources/views/panels/scripts.blade.php)
```

That asset is produced by a front-end build this branch does not run and must
not repair. It was verified to be the *only* distinct missing asset, and every
error block was classified individually rather than assumed.

The twelve Settings failures that this branch removes were all the read-side
defect of §3.3 — assertions comparing a written value against whatever
`base_path('.env')` contained, for example:

```
Failed asserting that two strings are identical.
- 'keep-this-secret'
+ '
'
```

### 6.4 Full repository regression, both sides

Both runs were executed in the same worktree against the same isolated
database, one after the other. The pristine side was produced by checking the
two modified files back out from `origin/main` and moving the three new files
aside, then restoring them afterwards.

| | Tests | Assertions | Errors | Failures | Deprecations | Risky |
|---|---|---|---|---|---|---|
| pristine `origin/main` | 5118 | 22005 | 976 | 28 | 14 | 1 |
| this branch | 5146 | 22104 | 975 | 16 | 14 | 1 |

The 28 extra tests are this branch's own
`TemporaryEnvironmentFileTest` (28 tests). Comparing the two runs by **distinct
failing or erroring test name**:

* **13 tests fixed**
* **0 tests newly broken**

```
Tests\Feature\Opportunity\OpportunityManagerBeginRunTest
    ::test_heartbeat_one_second_past_the_timeout_cutoff_is_abandoned
Tests\Feature\Settings\PlatformSettingsGeneralWriteTest
    ::test_valid_app_name_is_saved
    ::test_explicit_empty_app_keyword_clears_it
    ::test_explicit_empty_custom_script_clears_it
    ::test_explicit_empty_footer_company_name_clears_it
    ::test_explicit_empty_footer_copyright_text_clears_it
    ::test_saving_advanced_custom_script_preserves_platform_and_appearance_values
    ::test_saving_appearance_section_preserves_platform_and_advanced_values
    ::test_saving_platform_section_preserves_appearance_and_advanced_values
    ::test_submitting_license_alongside_a_valid_field_does_not_mutate_license
    ::test_the_timezone_side_effect_no_longer_mutates_user_id_one
Tests\Feature\Settings\PlatformSettingsSecretHandlingTest
    ::test_blank_openai_api_key_preserves_the_existing_key
    ::test_blank_smtp_password_preserves_the_existing_password
```

The `OpportunityManagerBeginRunTest` entry is worth naming. It is not a
settings test and this branch does not touch it. It was failing on pristine
main because an **earlier suite had written `APP_TIMEZONE` into the shared
environment file**, and it stops failing once each test gets its own copy.
That is the cross-suite leak of §2.3 caught in the act, and it is the clearest
evidence that this defect was producing failures which looked like unrelated
product bugs.

The remaining 975 errors and 16 failures are identical in both runs. They are
pre-existing and unrelated: the `theme-tokens.js` Mix asset accounts for the
overwhelming majority (3864 occurrences in the run log, and it is the **only**
distinct missing asset), and the residue is concurrency tests plus one test
that hard-codes the literal database name `ultimatesms_testing` and therefore
fails under any isolated lane database. **None is repaired here**, per the
brief.

### 6.5 Environment integrity after the full run

After the complete 5146-test regression on this branch:

| Check | Result |
|---|---|
| `.env` | byte-identical to its starting hash |
| `.env.testing` | byte-identical to its starting hash |
| `aibos-env-*` directories left in the system temp directory | 0 |
| Developer's real files in the primary checkout | untouched throughout |

The pristine run, by contrast, modified `.env.testing` again — reconfirming the
defect at the end of the exercise as well as at the start.

---

## 7. Changed paths

Exactly eight, matching the expanded allowlist:

| Path | Change |
|---|---|
| `docs/automation/SETTINGS-ENV-ISOLATION-REMEDIATION.md` | new — this document |
| **`app/Models/AppConfig.php`** | **one line**: `setEnv()` resolves `app()->environmentFilePath()` instead of `base_path('.env')`, plus a docblock |
| `tests/Support/UsesTemporaryEnvironmentFile.php` | new — the trait; snapshot/restore removed this round |
| `tests/TestCase.php` | applies the trait in `setUp()`/`tearDown()` |
| `tests/Feature/Support/TemporaryEnvironmentFileTest.php` | new — the proof |
| `tests/Fixtures/EnvironmentIsolationProbeTest.php` | new — subprocess probe, in neither testsuite; drives both writers |
| `tests/Feature/Settings/Concerns/SettingsTestHelpers.php` | `readEnvValue()` delegates to the shared reader |
| **`tests/Feature/Branding/BrandingUploadValidationTest.php`** | private `base_path('.env')` reader deleted; reads through the inherited helper; seeds `APP_LOGO` so the assertion proves a replacement |

`tests/Fixtures/` is included by neither testsuite in `phpunit.xml`, so the
probe never runs during a normal suite and is only ever invoked explicitly as
a subprocess.

---

## 8. Exhaustive environment-file writer inventory

Produced by mechanical search across `app/`, `database/`, `routes/`, `config/`,
`bootstrap/` and `tests/` for `base_path('.env')`, `environmentFilePath()`,
`useEnvironmentPath()`, `loadEnvironmentFrom()`, `file_put_contents` / `fopen` /
`fwrite` / `file_get_contents` against an environment file, `write_env()`,
`write_envs()`, `AppConfig::setEnv()` and `PlatformSettingsEnvWriter`.

| Path and line | Pattern | Classification | Addresses which file, now |
|---|---|---|---|
| `app/Helpers/namespaced_helpers.php:96,113` — `write_env()` | read + `file_put_contents` | **active runtime writer** | Active env file (correct before and after) |
| `app/Helpers/namespaced_helpers.php:142` — `write_envs()` | delegates to `write_env()` | **active runtime writer** | Active env file |
| `app/Helpers/namespaced_helpers.php:148,151` — `reset_app_url()` | read + `write_env()` | **active runtime writer** | Active env file |
| `app/Library/Settings/PlatformSettingsEnvWriter.php:38` | delegates to `write_env()` | **active runtime writer** | Active env file |
| `app/Models/AppConfig.php:423` — `setEnv()` | `file()` + `fopen`/`fwrite` | **active runtime writer** | **Active env file — corrected this round** |
| `app/Models/AppConfig.php:409` | calls `setEnv()` | active runtime writer | Follows `setEnv()` |
| `app/Http/Controllers/Admin/SettingsController.php:629,656,745` | calls `setEnv()` | active runtime writer | Follows `setEnv()` |
| `app/Library/Branding/BrandingUploadService.php:85,117` | calls `setEnv()` | active runtime writer | Follows `setEnv()` |
| `app/Repositories/Eloquent/EloquentSettingsRepository.php:363,375` | calls `setEnv()` | active runtime writer | Follows `setEnv()` |
| `app/Library/Tool.php:850,859` — `versionSeeder('3.4.0')` | read + `file_put_contents` | **active runtime writer, NOT reached by these suites** | **Still `base_path('.env')`** — §5.2 |
| `app/Repositories/Eloquent/EloquentSettingsRepository.php:312,321` — `pusherSettings()` | read + `file_put_contents` | **active runtime writer, NOT reached by these suites** | **Still `base_path('.env')`** — §5.2 |
| `database/migrations/2025_05_27_152009_…:14,34` | read + `file_put_contents` | **migration-only writer, REACHED via `RefreshDatabase`** | **Still `base_path('.env')`** — §5.1 |
| `database/migrations/2025_06_17_155331_…:11,46` | read + `file_put_contents` | **migration-only writer, REACHED via `RefreshDatabase`** | **Still `base_path('.env')`** — §5.1 |
| `database/migrations/2025_06_30_122030_…:9,39` | read + `file_put_contents` | **migration-only writer, REACHED via `RefreshDatabase`** | **Still `base_path('.env')`** — §5.1 |
| `tests/Feature/Settings/PlatformSettingsEnvWriteSafetyTest.php:30,31` | `useEnvironmentPath` / `loadEnvironmentFrom` | **test-only**, pre-existing; redirects to its own scratch file | Its own scratch file |
| `tests/Support/UsesTemporaryEnvironmentFile.php` | `useEnvironmentPath` / `loadEnvironmentFrom` / read | **test-only** | Disposable copy only; no `base_path('.env')` in any executable line |
| `tests/Feature/Support/TemporaryEnvironmentFileTest.php` | reads `base_path('.env')` | **test-only reader**, deliberate — it exists to assert the real files are untouched | Reads only, never writes |
| `config/app.php:66` | mentions `setEnv()` | **comment** | n/a |
| `app/Library/Settings/PlatformSettingsEnvWriter.php:10,14`, `app/Library/Branding/BrandingUploadService.php:28,87`, `EloquentSettingsRepository.php:75,78,122,159` | mention `setEnv()`/`write_env()` | **comment/documentation** | n/a |

**Summary.** Every writer reached by a test through application code now
addresses the disposable file. Five hardcoded `base_path('.env')` writers
remain: three migrations that the suites **do** reach through
`RefreshDatabase` (§5.1, measured), and two runtime writers the suites do
**not** reach (§5.2). All five are outside this branch's allowlist and are
reported rather than modified.

---

## 9. Known unrelated conditions observed, and not changed here

| Observation | Why it is left alone |
|---|---|
| `Unable to locate Mix file: /js/core/theme-tokens.js` across Settings, Branding and much of the suite | Pre-existing on the base commit; needs a front-end build, not a test change |
| `tests/Feature/Branding` and the Website suite write real files under `public/images/branding/**` and `public/images/websites/` during a run | Pre-existing test design. Removing it means editing those suites, which are outside this allowlist. The artifacts were deleted after each run so the worktree stayed clean |
| One `PHPUnit Deprecations: 1` on every run | Present identically on the pristine base; it comes from `phpunit.xml` schema attributes removed in PHPUnit 11, not from any test |
| The developer's real `.env` already contains `APP_NAME="Test App"` residue | Caused by earlier runs of this same defect. `.env` is on this branch's zero-change list, so the residue is reported, not corrected |
