# SETTINGS ENVIRONMENT-FILE ISOLATION REMEDIATION

## 1. Status and base

**Status:** Test-infrastructure remediation. No production code is changed.

**Base:** `origin/main` at `16f53dd5e156a85fe89ae7cf0434bc5bf9845292`
(`Merge pull request #228`), which is the tip of `origin/main` at the time this
branch was cut and is trivially an ancestor of itself.

**Branch:** `agent/baseline-settings-env-isolation`, created in a dedicated
worktree directly from that commit.

**Scope:** six paths, listed in §7. Nothing under `app/`, `config/`,
`database/`, `routes/`, `resources/`, `public/`, `bootstrap/cache/`, `vendor/`
or any dependency, generated asset or environment file is touched.

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

### 3.2 A second writer bypasses that seam entirely

`App\Models\AppConfig::setEnv()` does **not** use `environmentFilePath()`. It
hardcodes `base_path('.env')`:

```php
$file_path = base_path('.env');
```

It is reached from `SettingsController` (`TERMS_OF_USE`, `PRIVACY_POLICY`,
`MAINTENANCE_SECRET_PATH`) and from `BrandingUploadService` (the `APP_LOGO`
family). Two further direct `base_path('.env')` writers exist in
`App\Library\Tool` (the version updater) and
`EloquentSettingsRepository::pusherSettings()`.

**No path-redirection can reach these, and this branch is forbidden from
changing production code.** §5 records exactly what is and is not achieved as
a result.

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
2. snapshots `base_path('.env')` byte-for-byte (§3.2);
3. creates a directory under `sys_get_temp_dir()` — outside the repository —
   named `aibos-env-<pid>-<sequence>-<16 random hex chars>`;
4. copies the **currently active** environment file into it, keeping its
   original basename, so `environmentFile()` still reports `.env.testing`;
5. points the application at the copy.

On restore it hands back both paths, deletes the temporary directory, and puts
`base_path('.env')` back exactly as it was — including deleting it again if
the test created it. Every step is guarded and every field cleared, so calling
restore twice is a harmless no-op.

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

## 5. What is achieved, and one thing that is not

| Required property | Status |
|---|---|
| Every test extending `Tests\TestCase` gets its own disposable copy | **Achieved** — applied on the base class |
| The disposable file lives outside the repository | **Achieved** — under `sys_get_temp_dir()` |
| Seeded from the environment file actually active for that process | **Achieved** |
| The real `.env` and `.env.testing` are byte-identical after tests | **Achieved** — verified by hash after every run in §6 |
| One test's changes never leak into another | **Achieved** |
| Separate processes never share a file or directory | **Achieved** — proven with two genuinely concurrent subprocesses |
| Cleanup after success, assertion failure and uncaught exception | **Achieved** — proven by subprocess probe |
| Re-activating deletes the previous copy | **Achieved** |
| The original environment path and filename are restored | **Achieved** |
| `readEnvValue` reads the active disposable file and decodes correctly | **Achieved** |
| **App production writers write only to the disposable file** | **Achieved for every writer that honours `environmentFilePath()`. NOT achieved for `AppConfig::setEnv()`** |

### 5.1 The honest limitation

`AppConfig::setEnv()` hardcodes `base_path('.env')`. It cannot be redirected
without a production change, and production changes are outside this branch's
allowlist. So for that one writer the property delivered is weaker than the
one requested:

* **requested:** the real file is never opened for writing;
* **delivered:** the write still happens during the test, and the real file is
  restored byte-for-byte at teardown, so it is byte-identical afterwards.

This is deliberate and is not a silent substitution. It is also what keeps
`BrandingUploadValidationTest` working: that test has its own private
`readEnvValue()` reading `base_path('.env')`, which is where its own writer
writes, so it still reads what it just wrote.

**Recommended follow-up, in a branch permitted to change production code:**
route `AppConfig::setEnv()` through `app()->environmentFilePath()` — or
through the existing `PlatformSettingsEnvWriter` — and then delete the
`base_path('.env')` snapshot from this trait. Two further direct writers
(`App\Library\Tool`, `EloquentSettingsRepository::pusherSettings()`) should be
converted in the same pass. That work is **not** authorized here.

### 5.2 A second finding, deliberately not fixed here

`Tests\Feature\Branding\BrandingUploadValidationTest` declares a **private**
`readEnvValue()`. PHP fatals when a subclass narrows an inherited `protected`
method to `private`, so naming the trait method `readEnvValue` would have made
the entire Branding suite unloadable:

```
Fatal error: Access level to Tests\Feature\Branding\BrandingUploadValidationTest::readEnvValue()
must be protected (as in class Tests\TestCase) or weaker
```

Rather than edit a seventh file outside the allowlist, the trait method is
named `readActiveEnvValue()` and `SettingsTestHelpers::readEnvValue()` is a
thin delegate to it. Every settings caller keeps its existing call, the
Branding test keeps its private reader, and the allowlist holds.

That leaves one naive reader still in the tree. It is correct for what it
asserts today (`APP_LOGO`, a plain path with no escaping), and folding it into
the shared helper is a natural follow-up for a branch whose allowlist includes
that file.

---

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

Exactly six, matching the allowlist:

| Path | Change |
|---|---|
| `docs/automation/SETTINGS-ENV-ISOLATION-REMEDIATION.md` | new — this document |
| `tests/Support/UsesTemporaryEnvironmentFile.php` | new — the trait |
| `tests/TestCase.php` | applies the trait in `setUp()`/`tearDown()` |
| `tests/Feature/Support/TemporaryEnvironmentFileTest.php` | new — the proof |
| `tests/Fixtures/EnvironmentIsolationProbeTest.php` | new — subprocess probe, in neither testsuite |
| `tests/Feature/Settings/Concerns/SettingsTestHelpers.php` | `readEnvValue()` delegates to the shared reader |

`tests/Fixtures/` is included by neither testsuite in `phpunit.xml`, so the
probe never runs during a normal suite and is only ever invoked explicitly as
a subprocess.

---

## 8. Known unrelated conditions observed, and not changed here

| Observation | Why it is left alone |
|---|---|
| `Unable to locate Mix file: /js/core/theme-tokens.js` across Settings, Branding and much of the suite | Pre-existing on the base commit; needs a front-end build, not a test change |
| `tests/Feature/Branding` and the Website suite write real files under `public/images/branding/**` and `public/images/websites/` during a run | Pre-existing test design. Removing it means editing those suites, which are outside this allowlist. The artifacts were deleted after each run so the worktree stayed clean |
| One `PHPUnit Deprecations: 1` on every run | Present identically on the pristine base; it comes from `phpunit.xml` schema attributes removed in PHPUnit 11, not from any test |
| The developer's real `.env` already contains `APP_NAME="Test App"` residue | Caused by earlier runs of this same defect. `.env` is on this branch's zero-change list, so the residue is reported, not corrected |
