# SETTINGS ENVIRONMENT-FILE ISOLATION REMEDIATION

## 1. Status and base

**Status:** Environment-isolation remediation. Six single-seam production
corrections; everything else is the test harness and this document.

**The guarantee.** The automated test system, including the migrations it runs
in-process, never writes a repository environment file. Verified by bytes
**and** mtime across the complete regression, with the assertions running
during each test rather than after teardown.

**Base:** `origin/main` at `16f53dd5e156a85fe89ae7cf0434bc5bf9845292`
(`Merge pull request #228`), which is the tip of `origin/main` at the time this
branch was cut and is trivially an ancestor of itself.

**Branch:** `agent/baseline-settings-env-isolation`, created in a dedicated
worktree directly from that commit.

**`origin/main` advanced during this round**, from `16f53dd` to
`559a8200de6d0e66bef6a0397868774303073b3c` (PRs #229, #230, #231). This branch
is **not** merged or rebased onto it; the merge base remains `16f53dd`, and the
fourteen-path audit is taken against that base. **Overlap is zero** — computed
mechanically, no path changed on `origin/main` since the merge base appears in
this branch's fourteen:

| Changed on `origin/main` | Overlap with this branch |
|---|---|
| `AGENTS.md`, `CLAUDE.md`, `docs/automation/AI-AUTONOMY-STATE.json`, two new governance documents | none |
| `app/Http/Controllers.zip` removed, plus its removal note | none |
| Nine `tests/Feature/Usage/**` subprocess runners and `tests/Unit/Support/TestDatabaseSafetyTest.php`, guarded against the wrong database (PR #229) | none |

PR #229 is worth naming: it hardens the same hand-rolled subprocess runners
this document records as outside the isolation boundary (§9), but against the
wrong *database* rather than the environment file. The two corrections are
complementary and touch different files.

**Scope:** fourteen paths, listed in §7. Nothing under `config/`, `routes/`,
`resources/`, `public/`, `bootstrap/cache/`, `vendor/` or any dependency,
generated asset or environment file is touched, and no schema or migration
ordering changes.

### 1.2 Post-merge corrections, and synchronization with current main

The fourteen-path work above merged as PR #233
(`1928306271c26bb8464f795e0a10eeb5f14df581`). Two review findings against it
were valid and are corrected on
`agent/settings-env-isolation-post-merge-correction`:

| Finding | Correction |
|---|---|
| The focused suite hardcoded `.env.testing` as the selected file, and would fail on a clean checkout where that untracked file is absent | Selection is derived the way the framework derives it, and both checkout shapes are proven (§4.2, §6.2a) |
| Forced termination killed the child on `ENVPATH=`, which prints *before* the writers run, so the kill often landed before either had written | The probe signals only after both writers have run and both values have been read back; the parent waits for that signal (§6.3) |

A third defect was found while verifying and corrected in the same round: an
over-strict assertion that the repository `.env.testing` must not *contain* the
migration keys, which fails on any machine where `artisan migrate` has ever
been run normally — correct behaviour, not a defect (§6.2b).

A fourth was closed in the round after that: the cleanup race that occasionally
left an empty directory (§6.9).

**Synchronization.** `origin/main` advanced to
`b8bab0a677406c9bb98ba5f22fb97f0ba312fac5` (Lane F, PR #234) and was brought in
with an ordinary merge — no rebase, no force-push. Lane F touches
`docs/automation/WORKSPACE-ENTITLEMENT-DATABASE-SAFETY-COMPLETION.md`,
`tests/Feature/Workspace/Support/TemporaryTestDatabase.php` and
`tests/Feature/Workspace/WorkspaceTransitionsMigrationSchemaTest.php`;
**overlap with this branch is zero**, computed by intersecting the two changed-
path sets, and the merge reported no conflicts.

### 1.1 Two withdrawn positions, recorded so neither returns

**Withdrawn: snapshot-and-restore.** An early revision left
`AppConfig::setEnv()` writing the repository `.env` during tests and had the
harness snapshot and restore it afterwards. Restoring damage is not isolation:

* two concurrent processes can write and restore the same shared file in
  conflicting orders;
* a kill, PHP fatal or power loss leaves the damage in place, because teardown
  never runs;
* another process can read the test's values out of the real file during the
  window before restoration;
* the requirement was that production writers address only the disposable
  file, not that damage is repaired afterwards.

That code is deleted. A test asserts mechanically that no `base_path('.env')`
survives in any executable line of the harness.

**Withdrawn: activation from `setUp()`, and the claims that rested on it.** The
next revision corrected `AppConfig::setEnv()` but still activated isolation
from `Tests\TestCase::setUp()`, after `parent::setUp()`. That is after the
kernel has bootstrapped and after `RefreshDatabase` has run `migrate:fresh`, so
three environment-writing migrations still addressed the repository file —
measurably, by mtime.

Three statements made under that design are therefore **withdrawn**:

| Withdrawn claim | Replaced by |
|---|---|
| "Migration writes remain outside the solution" | The three migrations are corrected (§5) and covered by a direct test (§6.5) |
| "This branch does not claim repository-wide test isolation" | The test system, including its in-process migrations, never writes a repository environment file (§5.3) |
| "Real-file mtime movement is acceptable, attributed to migrations" | **mtime movement is a failure.** Every assertion now checks bytes *and* mtime, and the full regression finishes with both unchanged |

Isolation now installs **before the console kernel bootstraps** (§4.1), and
six writers resolve the active environment path (§5).

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

## 3. Root causes — four, and they are separate

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

`setEnv()` is reached from `SettingsController` (`TERMS_OF_USE`,
`PRIVACY_POLICY`, `MAINTENANCE_SECRET_PATH`), `BrandingUploadService` (the
`APP_LOGO` family), `EloquentSettingsRepository` (`TRAI_DLT`,
`GATEWAY_WISE_BILLING`), and `AppConfig` itself for uploaded assets. All of
those follow it onto the disposable file with no change of their own.

It was not the only one. Five further writers hardcoded the same path — the
version updater, the Pusher settings save, and three migrations. §5 lists all
six corrections; §8 inventories every environment-file reference in the
repository.

### 3.3 The read side addressed a different file, and parsed it naively

`SettingsTestHelpers::readEnvValue()` read `base_path('.env')` — the file
`write_env()` never touches under `APP_ENV=testing` — so the settings suite
asserted against a file its own writes had not reached, and was really reading
whatever the developer's `.env` happened to contain.

It also decoded with `trim($value, "\"\n")`, which cannot strip the trailing
`\r` of a CRLF line and therefore leaves the closing quote attached. That is
the origin of actual values such as `AI Business OS"`.

### 3.4 And the harness activated too late to cover bootstrap or migrations

Correcting the writers is necessary but not sufficient. `RefreshDatabase` runs
`migrate:fresh` **inside the test process**, during `setUpTraits()` — which
Laravel calls after `refreshApplication()` has already created and bootstrapped
the application. A harness that activates from a subclass's `setUp()` body has
not run yet at either point.

Measured on the previous revision: `php artisan migrate:fresh` alone moved the
repository `.env` mtime from `1788974923` to `1788974994` while its SHA-256
stayed `9c60219…b640ce`. Bytes survived only by coincidence of local state —
the migrations rejoin with `PHP_EOL`, so on Linux the same code rewrites a CRLF
file to LF.

§4.1 is the fix: install before the kernel bootstraps.

---

## 4. The remediation

### 4.1 Isolation is installed before the kernel bootstraps, not in setUp()

This is the whole design, and the placement is the point.

Laravel's `Illuminate\Foundation\Testing\TestCase::setUp()` runs
`setUpTheTestEnvironment()`, whose order is fixed:

```
setUpTheTestEnvironment()
  1. refreshApplication()
        -> Tests\CreatesApplication::createApplication()
              a. $app = require bootstrap/app.php     <- Application exists, nothing booted
              b. $app->make(Kernel::class)->bootstrap()
                    - LoadEnvironmentVariables        <- chooses and reads the env file
                    - LoadConfiguration
  2. setUpTraits()
        -> RefreshDatabase -> migrate:fresh           <- the three env-writing migrations
  3. the subclass's own setUp() body
```

Anything a subclass installs after `parent::setUp()` arrives at step 3 — after
the environment has been read at 1b and after every migration has run at 2.
That is why the earlier revision, which activated from
`Tests\TestCase::setUp()`, could not protect bootstrap or migrations, and why
`migrate:fresh` was measured moving the repository `.env` mtime.

Installation therefore happens at **step 1a to 1b**, inside
`Tests\CreatesApplication::createApplication()`, between the `require` and the
kernel bootstrap. It is the only point that is both late enough to have an
`Application` to configure and early enough that nothing has read the
environment.

On installation the trait:

1. asks the un-booted application for its environment path (the repository
   root) and base filename (`.env`);
2. **reproduces Laravel's own file-selection rule** to decide which file would
   have been loaded (section 4.2);
3. creates a directory under `sys_get_temp_dir()` — outside the repository —
   named `aibos-env-<pid>-<sequence>-<16 random hex chars>`;
4. copies the selected file into it **under the same name**, so the copy holds
   exactly the bytes Laravel would otherwise have loaded;
5. points the application at that directory *and* that filename.

Every later stage — `LoadEnvironmentVariables`, `LoadConfiguration`,
`RefreshDatabase`, the migrations, the test body, and teardown — sees only the
copy.

Setting the filename as well as the directory matters. Once
`environmentFile()` is `.env.testing`, the bootstrapper's own re-check looks
for `.env.testing.testing`, does not find it, and leaves the selection alone,
so the copy is what Dotenv loads.

The trait uses **native filesystem calls, never the `File` facade**, because at
step 1a no facade root exists yet.

### 4.2 The selection rule is reproduced, not guessed

`Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::checkForSpecificEnvironmentFile()`
decides which file is active:

* if running in console and a `--env` option is present, try `<base>.<option>`;
* otherwise read `APP_ENV` through `Illuminate\Support\Env`, and if it is
  truthy try `<base>.<APP_ENV>`;
* in both cases the suffixed file is chosen **only if it exists**; anything
  else keeps the base name.

`frameworkSelectedEnvironmentFile()` mirrors that exactly, including reading
`APP_ENV` through `Illuminate\Support\Env` so a value supplied by
`phpunit.xml`'s `<server>` element resolves through the same repository and
adapter chain the bootstrapper uses.

**Which file that selects depends on the checkout, and no test may assume.**
`.gitignore` matches `.env.*`, so `.env.testing` is untracked and **absent on a
clean checkout**. With `APP_ENV=testing` supplied by `phpunit.xml`, Laravel then
correctly selects `.env`, because the suffixed file only wins when it exists.
Both outcomes are correct:

| Checkout | Selected |
|---|---|
| Workstation that has `.env.testing` | `.env.testing` |
| Clean checkout or CI, no `.env.testing` | `.env` |

Post-merge correction: the focused suite originally hardcoded `.env.testing` in
three assertions and would have failed on a clean checkout — on correct
behaviour. Those assertions now derive the expected filename the same way the
framework does, and a data-provided test drives the real installer against two
scratch directories, one with `.env.testing` and one without, to prove both
selections. See §6.2.

### 4.3 Teardown, re-activation and uniqueness

`Tests\TestCase::tearDown()` restores the application's original path and
filename and deletes the directory. PHPUnit runs `tearDown()` after a pass,
after a failed assertion and after an uncaught exception, so every outcome is
covered. Restoring twice is a harmless no-op.

`refreshApplication()` can run more than once in a single test; each
installation discards the previous directory first, so a recreated application
never reuses or leaks a copy.

The directory name carries the process id, a per-process monotonic sequence and
16 random hex characters, so two concurrent PHPUnit processes cannot be handed
the same directory.

### 4.4 The decoder is a single left-to-right scan, and that matters

`App\Helpers\format_dotenv_value()` escapes in a fixed order — backslash, then
double quote, then newline — and always wraps the result in double quotes.
`readActiveEnvValue()` inverts it with one scan that consumes each escape
sequence whole.

**A `str_replace` pass over the three escape sequences is not equivalent and is
wrong.** The encoded form of the six characters `C:\new` is `C:\\new`. A
leading `\n` replacement matches the *second* backslash together with the
following `n`, decoding it to `C:` plus a backslash plus a real newline plus
`ew`. The scan cannot make that mistake, because it consumes the doubled
backslash as one unit before it ever looks at the `n`. `roundTripValues()`
covers this case by name (`backslash before n`).

Covered and asserted: CRLF files, quotes, escaped quotes, backslashes, real
newlines, absent keys (`null`), explicitly empty keys, unquoted values, and a
key that is a prefix of another key.

### 4.5 One documented behaviour that is a loss, not a bug

`format_dotenv_value()` maps CRLF, LF and lone CR all onto the single escape
`\n`. A value containing CRLF therefore comes back containing LF. That is the
production writer's real, deliberate contract, so
`test_a_carriage_return_inside_a_value_is_normalised_to_a_newline_by_the_writer()`
asserts the exact normalisation rather than a faithful round trip.

---

## 5. The six writer corrections

Every correction is the same shape: resolve the environment file the
application is **currently** using instead of hardcoding `base_path('.env')`,
once per operation, and reuse that one resolved path for the existence guard,
the read and the write. None changes a signature, a caller, a schema or a
migration's ordering.

All six are behaviour-preserving in production, where the application's
environment file *is* `base_path('.env')`, so each expression resolves to the
path it always did.

| Path | Before | After |
|---|---|---|
| `app/Models/AppConfig.php` — `setEnv()` | `base_path('.env')` | `app()->environmentFilePath()` |
| `app/Library/Tool.php` — `versionSeeder('3.4.0')` | two separate `base_path('.env')` calls, one read one write | one resolved `$envPath`, used by both |
| `app/Repositories/Eloquent/EloquentSettingsRepository.php` — `pusherSettings()` | same two-call pattern | same single-resolution correction |
| `database/migrations/2025_05_27_152009_...` | `$envPath = base_path('.env')` | `$envPath = app()->environmentFilePath()` |
| `database/migrations/2025_06_17_155331_...` | `$envPath = base_path('.env')` | `$envPath = app()->environmentFilePath()` |
| `database/migrations/2025_06_30_122030_...` | `$envPath = base_path('.env')` | `$envPath = app()->environmentFilePath()` |

The three migrations already resolved the path once into `$envPath` and reused
it for their `file_exists()` guard, read and write, so each needed exactly one
line changed. Their missing-file behaviour is untouched: they still do nothing
when the file is absent.

`Tool::versionSeeder()` and `pusherSettings()` previously called
`file_get_contents()` with no existence check, which emits a warning and
returns `false` on a missing file. Both now guard with `is_file()` and fall
back to an empty string — the same outcome the surrounding `try`/`catch`
already produced, without the warning.

### 5.1 Why `setEnv()` also needed it for correctness, not only isolation

Under `APP_ENV=testing` Laravel loads `.env.testing`, so `setEnv()` was writing
to a file the framework had not loaded. A value it wrote was invisible to the
environment actually in force. `write_env()` — and `PlatformSettingsEnvWriter`
through it — had always used the correct seam; these were the exceptions.

### 5.2 A behaviour the correction exposed

`AppConfig::setEnv()` is an **update-only** writer: it rewrites lines that
already match its key and adds nothing when none does.

While it wrote the repository `.env` this was invisible, because that file
happened to contain `APP_LOGO` from earlier runs.
`BrandingUploadValidationTest::test_a_valid_png_logo_upload_...` therefore
appeared to pass while asserting against a file the application had not loaded.

Now that the writer and the reader address the same file, the test states its
precondition explicitly: it seeds `APP_LOGO` with the bundled default, asserts
that value, performs the upload, then asserts the value was **replaced** with
the content-addressed path. That is strictly stronger than before — it proves a
write happened rather than that some value existed.

`setEnv()`'s own semantics are deliberately unchanged: the brief called for the
smallest behaviour-preserving seam correction, not a redesign. A deployed
`.env` carries `APP_LOGO`, so production is unaffected.

### 5.3 What is guaranteed, and what is not claimed

**Guaranteed:** the automated test system and its in-process migrations never
write a repository environment file. Proven by bytes *and* mtime, asserted
during each test rather than after teardown, and confirmed across the full
regression.

**Not claimed:** that every possible application command in every possible
invocation is isolated. The guarantee is specifically about the test system. A
production or console invocation resolves `app()->environmentFilePath()` to
`base_path('.env')` and writes it, exactly as before — which is correct.

**Environment-file readers, listed separately from writers.** These read a
repository environment file and never write one:

| Path | Why it reads a repository file |
|---|---|
| `tests/Feature/Support/TemporaryEnvironmentFileTest.php` | Reads `.env` and `.env.testing` deliberately, to assert they were not written. It is the one file exempted from the mechanical writer sweep, by name |

No other executable line in `app/`, `database/` or `tests/` references a
hardcoded environment path. A test asserts this mechanically, using PHP's
tokenizer so comments and docblocks cannot satisfy or trip it.

## 6. Verification

Isolated worktree, isolated database `ultimatesms_testing_lane_d`, PHP 8.3.30.
The canonical `ultimatesms_testing` database was never reset, migrated,
truncated or written to.

Reference values for the repository environment files, unchanged throughout:

| File | SHA-256 | mtime (epoch) |
|---|---|---|
| `.env` | `9c6021963c81a7a9eef1e1c5b5e95a96c74affcd5b3f6d03a05444598bb640ce` | `1788967564` |
| `.env.testing` | `095665a5ea28a751556d61b82d3922942eba34ad0afd0271f3b0b16d327d8317` | `1788967318` |

The developer's own copies, in the primary checkout, were untouched throughout:

| File | SHA-256 | mtime (epoch) |
|---|---|---|
| `.env` | `9c6021963c81a7a9eef1e1c5b5e95a96c74affcd5b3f6d03a05444598bb640ce` | `1788387874` |
| `.env.testing` | `7364d15b99e09adacfe4a845e30bee00404da195b6f17e2934f6da03f64e065c` | `1787208791` |

Every writer assertion compares a **fingerprint of bytes, mtime and existence**
for both files, and runs during the test rather than after teardown. mtime is
included deliberately: a writer that rewrites identical bytes still moves it,
and that is precisely the signal the migration defect produced.

### 6.1 Focused isolation suite

`tests/Feature/Support/TemporaryEnvironmentFileTest.php` — **41 tests, 364
assertions, 0 failures.**

Pre-bootstrap and lifecycle coverage:

| Assertion | Proves |
|---|---|
| The active environment file is a disposable copy, outside the repository | Installation happened |
| Kernel bootstrap loaded configuration from the copy — written value read back through `env()` after re-running `LoadEnvironmentVariables` | Isolation precedes configuration, by content not just by path |
| The copy carries the same bytes as the file Laravel would have loaded, and that file is `.env.testing` | The framework's selection rule is reproduced correctly |
| The original basename and `environmentFile()` are preserved | The bootstrapper's own re-check cannot re-point the application |
| `migrate:fresh`, driven directly, leaves both repository files unchanged by bytes and mtime, while the migrations' keys appear in the copy | **The blocker this round closes** |
| A `beforeApplicationDestroyed` callback still sees the disposable copy, and migrating inside one changes no repository file | The teardown-ordering regression, pinned |
| Recreating the application yields a new copy and deletes the previous one | No reuse, no leak |

Writer coverage, each asserting the full fingerprint immediately afterwards:

| Writer | Result |
|---|---|
| `App\Helpers\write_env()` | writes the copy; repository files unchanged |
| `App\Models\AppConfig::setEnv()` | writes the copy; repository files unchanged |
| `App\Library\Tool::versionSeeder('3.4.0')` | writes the copy; repository files unchanged |
| `EloquentSettingsRepository::pusherSettings()` | writes the copy; repository files unchanged |
| The three migrations, via `migrate:fresh` | write the copy; repository files unchanged |

Plus: a repository `.env` is never created when absent; a second PHP process
reading both repository files sees nothing the test wrote; eleven write/read
round trips including `backslash before n`; absent versus explicitly empty
keys; CRLF files; unquoted values; prefix-colliding keys.

### 6.2 Mechanical source guarantees

Two tests inspect source, and both use PHP's **tokenizer** so a
`base_path('.env')` inside a comment or docblock neither satisfies nor trips
them:

* no executable line of `tests/Support/UsesTemporaryEnvironmentFile.php`
  references a repository path, and the identifiers `realDotEnvSnapshot`,
  `guardRealDotEnvFile` and `restoreRealDotEnvFile` are absent — **the
  snapshot-and-restore fallback provably does not exist**;
* no executable line under `app/`, `database/` or `tests/` contains a hardcoded
  environment path, with exactly one named exemption:
  `tests/Feature/Support/TemporaryEnvironmentFileTest.php`, which reads the
  repository files on purpose to assert they were not written.

### 6.2a Both checkout shapes, proven

The suite must not require the untracked `.env.testing`. Two independent
proofs:

**In-suite, both shapes at once.**
`test_the_installer_selects_the_file_laravel_would_have_selected()` builds two
scratch directories — one holding only `.env`, one holding both — points the
application at each as an un-booted application sees the repository root, and
drives the **real** installer. It asserts the selected filename, that the copy
carries the right source file's bytes, that a production write lands in the
copy, and that the scratch source is byte- and mtime-identical afterwards. No
repository file is involved, and nothing untracked is created or deleted.

**End to end, as a clean checkout.** The whole focused suite was also run with
the workstation's `.env.testing` moved aside and a single `.env` in place —
exactly the clean-checkout and CI shape:

| Shape | Result | `.env` | `.env.testing` |
|---|---|---|---|
| Workstation (`.env` + `.env.testing`) | 43 tests, 400 assertions, 0 failures | unchanged | unchanged |
| **Clean checkout (`.env` only)** | **43 tests, 400 assertions, 0 failures** | unchanged | never created |

Identical counts in both shapes. A pre-flight check confirmed the clean-checkout
run genuinely selected `.env` and resolved the isolated lane database before any
test was allowed to run.

### 6.2b One further over-strict assertion, corrected

`test_refresh_database_migration_preparation_does_not_touch_the_repository_files()`
asserted that the repository `.env.testing` does not *contain* `APP_TIME_FORMAT=`,
`OPENAI_ACTIVE=` or `TERMS_OF_USE=`.

That is not a property of a correct system. Running `php artisan migrate`
outside a test is *supposed* to write the active environment file, which in an
ordinary CLI invocation is the repository one — so any developer who has ever
migrated normally legitimately has those keys, and the assertion failed on
correct behaviour. It was found exactly that way here, by a routine
`artisan migrate:fresh` used to prepare the lane database.

It is replaced by the property that actually matters and does not depend on
machine history: the repository files' full contents are captured before and
compared after, the active path is asserted to be neither repository file and
outside the repository, and the migration keys are asserted present in the
disposable copy.

### 6.3 All probe outcomes, as real subprocesses

`tests/Fixtures/EnvironmentIsolationProbeTest.php` drives **both**
`write_env()` and `AppConfig::setEnv()`, and runs as a subprocess under every
outcome:

| Outcome | Result |
|---|---|
| Normal completion | Temp directory removed; both repository files unchanged; no marker leaked |
| Assertion failure | Same |
| Uncaught exception | Same |
| **Forced termination** (`SIGKILL`-equivalent, **after both writers have run**) | Both repository files unchanged by bytes **and** mtime; neither writer's value observable in either; the child's directory removed by the parent |

**Post-merge correction to the forced-termination case.** The parent used to
kill the child as soon as it saw `ENVPATH=`, and a comment claimed that meant
the child had "booted, activated isolation and run both writers". That was
false: the probe prints its path *before* `writeMarker()` runs, so the kill
routinely landed before either production writer had touched anything. The test
proved that booting is safe, not that a kill *mid-write* is — which is the case
that matters, because that is when a writer could plausibly hold a repository
file open.

`test_probe_blocks_until_killed()` now emits a distinct `PROBE-WROTE` line that
is printed **only after** both writers have run **and** both values have been
read back out of the disposable copy. A probe that cannot confirm its own
writes prints `PROBE-WRITE-FAILED` and fails instead of signalling. Having
signalled, it blocks — bounded, so a parent that dies cannot strand it — until
killed.

The parent, in order:

1. waits for `PROBE-WROTE` rather than `ENVPATH=`;
2. asserts the reported values are exactly `probe-blocking` and
   `appconfig-blocking`, so the child provably reached `write_env()` **and**
   `AppConfig::setEnv()`;
3. asserts the child is still running, then kills it with signal 9;
4. asserts both repository files are byte- and mtime-identical;
5. asserts neither writer's key or value appears in either repository file;
6. removes **only** the child's own directory, after asserting that path sits
   under the system temp directory.

Seventeen assertions, run eight consecutive times, clean every time.

### 6.4 Concurrency

Two children started before either is waited on, both driving
`AppConfig::setEnv()`. Asserted: different pids, different disposable paths,
different directories, no cross-process value leakage, neither repository file
mutated by bytes or mtime, and both temp directories removed. Repeated four
times, clean each time.

### 6.5 Repeated runs — determinism

Five consecutive focused runs, each followed by a bytes **and** mtime check:

| Run | Result | `.env` | `.env.testing` | Leftover temp dirs |
|---|---|---|---|---|
| 1–5 | 41 tests, 364 assertions | unchanged | unchanged | 0 |

### 6.6 Settings and Branding

| Suite | Pristine base | This branch |
|---|---|---|
| `tests/Feature/Settings` | 57 tests, 103 assertions, 21 errors, **12 failures** | 57 tests, 120 assertions, 21 errors, **0 failures** |
| `tests/Feature/Branding` | 53 tests, 234 assertions, 19 errors, 0 failures | 53 tests, **235** assertions, 19 errors, 0 failures |
| `tests/Feature/Automations` (uses `UsesFreshSchema`, migrates during teardown) | — | 86 tests, 20 errors; both repository files unchanged |

Every one of those errors is the same pre-existing, unrelated
`Unable to locate Mix file: /js/core/theme-tokens.js`, classified block by
block rather than assumed.

### 6.7 Full repository regression, head to head

Both sides run in the same worktree, against the same isolated database, with
the same `-d memory_limit=1536M`. The pristine side was produced by checking
the ten modified files back out from `origin/main` and moving the three new
files aside, then restoring them.

| | Tests | Assertions | Errors | Failures |
|---|---|---|---|---|
| pristine `origin/main` | 5118 | 22006 | 976 | 28 |
| this branch | 5159 | 22369 | 975 | 17 |

The 41 extra tests are this branch's own suite. Compared by distinct failing or
erroring test name: **13 fixed, 1 apparent regression.**

The 13 fixed are the twelve Settings read-side assertions plus
`OpportunityManagerBeginRunTest::test_heartbeat_one_second_past_the_timeout_cutoff_is_abandoned`.
That last one is not a settings test and this branch does not touch it. It was
failing on pristine main because an earlier suite had written `APP_TIMEZONE`
into the shared environment file — the cross-suite leak caught in the act, and
the clearest evidence the defect produced failures that looked like unrelated
product bugs.

### 6.8 The one apparent regression, reproduced on pristine main

```
Tests\Feature\Usage\ConcurrentTopUpConcurrencyTest
    ::test_a_held_lock_for_one_business_does_not_block_an_unrelated_businesss_confirmation
```

Not classified as flaky by assertion. **The exact test was run ten consecutive
times against a working tree reverted to pristine `origin/main`** — `AppConfig`
back on `base_path('.env')`, no pre-boot hook in `CreatesApplication`, the
harness files absent — and it failed on the fifth repetition:

```
pristine rep 5 :: Tests: 1, Assertions: 1, Failures: 1
```

One failure in ten, on unmodified code, with none of this branch loaded. The
failure is a spawned-subprocess lock handshake, and nothing here touches
locking, transactions or subprocess spawning for the wallet.

The same was established for the `UsageWalletManager*ConcurrencyTest` pair in
the previous round: one failure in eight isolated pristine runs. The pristine
full-run totals were also reproduced across two independent runs
(5118 / 22006 / 976 / 28 both times), so the baseline itself is stable; it is
these individual subprocess-timing tests that are not.

### 6.9 The cleanup race, closed

**The defect.** `UsesTemporaryEnvironmentFile::removeDirectory()` ended with a
single suppressed `@rmdir()`. Under repeated forced-termination runs on Windows
that lost a race with a just-released handle roughly **one run in eight**,
leaving an **empty** `aibos-env-*` directory: the contained file was gone, only
the directory remained.

**The fix.** The final `rmdir` is now retried over a short, deterministic
bound. Nothing else about the deletion changes.

| Property | Value |
|---|---|
| Maximum attempts | `REMOVE_ATTEMPTS = 20` |
| Delay between attempts | `REMOVE_RETRY_MICROSECONDS = 10_000` (10 ms) |
| Worst-case duration | ~200 ms, and only when the directory genuinely refuses |
| Stops early when | `rmdir` succeeds, or the directory has gone by any other means |
| Stat cache | cleared before the first check and between attempts, so a retry never re-reads a stale `is_dir()` |
| Return value | `true` only when the directory is actually gone; a survivor returns `false` |

**The safety boundary is unchanged, and deliberately narrow.**

* It operates only on the exact directory it is handed — one this trait created
  under `sys_get_temp_dir()`, named with the creating process's own pid.
* Contained files are removed by the same recursive walk as before.
* It never widens to a parent, never globs a directory to delete, never shells
  out, and never touches the system temp directory itself.
* The process-level sweep remains pid-scoped, so a concurrent lane's directory
  can never be a candidate.
* A directory that survives every attempt is **left in place and reported as
  not removed**. Success is never claimed for a directory that is still there.
* Neither the sweep nor the teardown path throws, so a shutdown function can
  never mask a test result. Tests still assert their own directory is gone.

**Evidence.**

| Run | Count | Result | Orphans from this lane |
|---|---|---|---|
| Forced termination, consecutive | **20** | all pass, 17 assertions each | **0**, and 0 at every intermediate check |
| Focused suite, consecutive | 5 | 48 tests, 456 assertions each | **0** |
| Concurrent probe coverage | 5 | 2 tests, 30 assertions each | **0** |
| Focused suite, clean-checkout shape | 2 | 48 tests, 456 assertions each | **0** |
| Settings / Branding / Automations | 1 each | 57 / 53 / 86 tests, baseline errors only | **0** |

Before the fix, twenty forced-termination runs would have been expected to
leave two or three empty directories; they left none.

**A measurement correction worth recording.** An initial count reported one or
two leftovers after the focused suite and briefly looked like a surviving leak.
It was not. The counter globbed `aibos-env-*` across the whole system temp
directory, so it was also counting the **live** directories of a concurrent
lane running its own suite in `cx-slice-3-messaging-impl-worktree` — process id
4500 was confirmed alive and mid-run, holding the directory in question. The
pid-scoped sweep is correct to leave those alone.

Every count in the table above is therefore **lane-scoped**: the directory set
is captured before and after each run, and a new directory counts as an orphan
only if the process that created it is no longer alive. That is the measurement
the guarantee actually needs, and it is what the final sweep uses.

## 7. Changed paths

Exactly fourteen, matching the allowlist.

**Production seam corrections (six):**

| Path | Change |
|---|---|
| `app/Models/AppConfig.php` | `setEnv()` resolves `app()->environmentFilePath()` |
| `app/Library/Tool.php` | `versionSeeder('3.4.0')` resolves the path once and reuses it for its read and write; adds an `is_file()` guard |
| `app/Repositories/Eloquent/EloquentSettingsRepository.php` | `pusherSettings()` — same correction |
| `database/migrations/2025_05_27_152009_add_default_time_format_to_settings.php` | one line: `$envPath` resolves the active file |
| `database/migrations/2025_06_17_155331_add_open_ai_environment_value_on_env_file.php` | one line |
| `database/migrations/2025_06_30_122030_create_env_value_for_terms_of_use_n_privarcy_policy.php` | one line |

**Test harness (seven):**

| Path | Change |
|---|---|
| `tests/CreatesApplication.php` | installs the disposable copy between the Application's construction and the kernel bootstrap — the pre-boot seam |
| `tests/TestCase.php` | applies the trait and restores in `tearDown()`; **no longer activates in `setUp()`** |
| `tests/Support/UsesTemporaryEnvironmentFile.php` | the trait: pre-boot install, framework-faithful file selection, native filesystem calls, no snapshot |
| `tests/Feature/Support/TemporaryEnvironmentFileTest.php` | the proof, including the pre-boot, migration and mechanical-sweep tests |
| `tests/Fixtures/EnvironmentIsolationProbeTest.php` | subprocess probe, in neither testsuite; drives both `write_env()` and `AppConfig::setEnv()` |
| `tests/Feature/Settings/Concerns/SettingsTestHelpers.php` | `readEnvValue()` delegates to the shared reader |
| `tests/Feature/Branding/BrandingUploadValidationTest.php` | private `base_path('.env')` reader deleted; reads through the inherited helper; seeds `APP_LOGO` so the assertion proves a replacement |

**And this document.**

No schema, migration ordering, dependency, generated asset or environment file
changes.

`tests/Fixtures/` is included by neither testsuite in `phpunit.xml`, so the
probe never runs during a normal suite and is only ever invoked explicitly as
a subprocess.

---

## 8. Exhaustive environment-file reference inventory

Produced by mechanical search across `app/`, `database/`, `routes/`, `config/`,
`bootstrap/` and `tests/` for `base_path('.env')`, `environmentFilePath()`,
`useEnvironmentPath()`, `loadEnvironmentFrom()`, `file_put_contents` / `fopen` /
`fwrite` / `file_get_contents` against an environment file, `write_env()`,
`write_envs()`, `AppConfig::setEnv()` and `PlatformSettingsEnvWriter`.

### 8.1 Writers — every one addresses the active environment file

| Path | Pattern | Classification | Addresses |
|---|---|---|---|
| `app/Helpers/namespaced_helpers.php` — `write_env()` | read + `file_put_contents` | active runtime writer | Active env file (correct before and after) |
| `app/Helpers/namespaced_helpers.php` — `write_envs()` | delegates to `write_env()` | active runtime writer | Active env file |
| `app/Helpers/namespaced_helpers.php` — `reset_app_url()` | read + `write_env()` | active runtime writer | Active env file |
| `app/Library/Settings/PlatformSettingsEnvWriter.php` | delegates to `write_env()` | active runtime writer | Active env file |
| `app/Models/AppConfig.php` — `setEnv()` | `file()` + `fopen`/`fwrite` | active runtime writer | **Active env file — corrected** |
| `app/Library/Tool.php` — `versionSeeder('3.4.0')` | read + `file_put_contents` | active runtime writer | **Active env file — corrected** |
| `app/Repositories/Eloquent/EloquentSettingsRepository.php` — `pusherSettings()` | read + `file_put_contents` | active runtime writer | **Active env file — corrected** |
| `database/migrations/2025_05_27_152009_…` | read + `file_put_contents` | migration-only writer, reached in-process via `RefreshDatabase` | **Active env file — corrected** |
| `database/migrations/2025_06_17_155331_…` | read + `file_put_contents` | migration-only writer, reached in-process | **Active env file — corrected** |
| `database/migrations/2025_06_30_122030_…` | read + `file_put_contents` | migration-only writer, reached in-process | **Active env file — corrected** |
| `app/Models/AppConfig.php:409`, `SettingsController` (3 sites), `BrandingUploadService` (2), `EloquentSettingsRepository` (2) | call `setEnv()` | active runtime callers | Follow `setEnv()`, unchanged |

**No executable line in `app/`, `database/` or `routes/` references a hardcoded
environment path.** Asserted mechanically by
`test_no_executable_hardcoded_environment_writer_remains()`, which walks every
PHP file under `app/`, `database/` and `tests/` and inspects **tokenized
executable text only**, so a `base_path('.env')` inside a comment or docblock
neither satisfies nor trips it.

### 8.2 Readers — recorded separately, and they write nothing

| Path | Reads what | Why |
|---|---|---|
| `tests/Feature/Support/TemporaryEnvironmentFileTest.php` | the repository `.env` and `.env.testing` | Deliberately, to assert they were not written and their mtimes did not move. It is the one path the sweep exempts, by name. It never writes either file |
| `tests/Support/UsesTemporaryEnvironmentFile.php` | the file the framework selects, once, at install time | To seed the disposable copy with the bytes Laravel would have loaded. A read only; the write goes to the copy |

### 8.3 Test-only environment redirection, pre-existing

| Path | Pattern |
|---|---|
| `tests/Feature/Settings/PlatformSettingsEnvWriteSafetyTest.php` | Uses `useEnvironmentPath()` / `loadEnvironmentFrom()` to point at its own scratch file. Pre-existing, unchanged, and unaffected: it simply redirects again inside an already-disposable environment |

### 8.4 Comments and documentation

`config/app.php`, `PlatformSettingsEnvWriter`, `BrandingUploadService` and
`EloquentSettingsRepository` mention `setEnv()` or `write_env()` in prose. The
corrected files carry comments naming the previous `base_path('.env')` so the
change is legible. None is executable, and the tokenizer-based sweep ignores
them all.

---

## 9. Known conditions observed, and not changed here

**The empty-leftover-directory race is CLOSED.** It is documented in §6.9 and
is no longer deferred.



| Observation | Why it is left alone |
|---|---|
| `Unable to locate Mix file: /js/core/theme-tokens.js` across Settings, Branding and much of the suite | Pre-existing on the base commit; needs a front-end build, not a test change |
| `tests/Feature/Branding` and the Website suite write real files under `public/images/branding/**` and `public/images/websites/` during a run | Pre-existing test design. Removing it means editing those suites, which are outside this allowlist. The artifacts were deleted after each run so the worktree stayed clean |
| One `PHPUnit Deprecations: 1` on every run | Present identically on the pristine base; it comes from `phpunit.xml` schema attributes removed in PHPUnit 11, not from any test |
| The developer's real `.env` already contains `APP_NAME="Test App"` residue | Caused by earlier runs of this same defect. `.env` is on this branch's zero-change list, so the residue is reported, not corrected |
| Spawned-subprocess concurrency tests fail intermittently — `UsageWalletManager*ConcurrencyTest` with `Holder process never confirmed its lock.`, and `ConcurrentTopUpConcurrencyTest::test_a_held_lock_...` | Reproduced on pristine `origin/main` in both cases: 1 failure in 8 isolated runs for the first pair, 1 in 10 for the second, with none of this branch loaded (see 6.8). Not caused here and not repaired here |
| `EntitlementManagerConcurrencyTest` and `WorkspaceManagerConcurrencyTest` failures | Same class of spawned-subprocess timing flakiness, present identically on pristine main |
| `WorkspaceManagerTest::test_missing_onboarding_business_reference_throws` asserts the literal database name `ultimatesms_testing` | Fails under any isolated lane database. Pre-existing; a test-side hard-coding this branch is not scoped to change |
| PHPUnit fatally exhausted a 512 MB `memory_limit` while building its final report | The suite already used 488 MB on pristine main and 486 MB on the pristine baseline — 95% of the ceiling before this branch existed. The eleven tests this branch adds crossed it. Both sides of the comparison are therefore run with `-d memory_limit=1536M`; `php.ini` and `phpunit.xml` are untouched |
| Several concurrency tests spawn children that `require bootstrap/app.php` directly | Those children bypass `Tests\CreatesApplication`, so the pre-boot hook does not run and their active environment file is the repository one. Every such child was searched for migration and environment-write calls: **exactly one contains any**, and it never executes during a suite run — see the next row. The others only read configuration and touch the database, so no repository environment file is written. Recorded as a real boundary rather than glossed: isolation covers children that boot through `Tests\TestCase`, including the probe, and does not cover a child that hand-rolls its own bootstrap |
| `tests/Feature/Workspace/Support/run_historical_m1a_suite.php` calls `Artisan::call('migrate')` after hand-rolling its own bootstrap | It belongs to the `historical-m1a` group, which `phpunit.xml` **excludes**, and the only reference to it in the suite reads its source as text (`WorkspaceM1BBoundaryTest`) rather than executing it. It therefore never runs, and the full regression confirms both repository files unchanged. If it were ever run manually it would write the repository environment file, because it does not use `Tests\CreatesApplication`. Out of this branch's allowlist; recorded for whoever revives that suite |
