# Removal of the dormant `app/Http/Controllers.zip` archive

A zip archive of legacy controller sources had been tracked inside `app/`
since the repository's baseline import. Nothing read it. It nonetheless
shipped a verbatim copy of `DebugController` — the class Security
Remediation Slice 0 (PR #225) deleted — together with six other
controllers that no longer exist in the application at all.

This branch deletes the archive. It changes no production code, no route,
no dependency and no generated file.

---

## 1. What was removed

| | |
|---|---|
| Path | `app/Http/Controllers.zip` |
| Git blob | `cd8935a6269f6939783d4bb393a32e265ede48a9` |
| Stored size | **280 515 bytes** |
| SHA-256 of the file content | `0578ee3fb48669b34962e0e28cee28e08e3c7e586ad8bb2a4abac9a2c7e13b47` |
| Uncompressed total | 2 080 220 bytes (≈ 1.98 MiB) |
| Entries | 75 — **68 `.php` files** and 7 directories |
| Entry timestamps | 2023-11-15 … 2026-02-07 |
| Commits that ever touched it | **1** — `2e3b7f5` *"chore: establish baseline with verified business core"*, 2026-07-18 |

It was added once, by the baseline import, and never updated again. Every
byte in it is a snapshot of a tree that has since moved on.

## 2. What the archive contained, and how stale it is

Archived `.php` files by directory:

| Directory | Files |
|---|---|
| `Controllers/Admin/` | 29 |
| `Controllers/Customer/` | 18 |
| `Controllers/Auth/` | 6 |
| `Controllers/API/` | 6 |
| `Controllers/` (top level) | 6 |
| `Controllers/User/` | 2 |
| `Controllers/Debug/` | 1 |

Compared against the 94 controllers the application actually has today —
the comparison was done by extracting the archive into a scratch directory
**outside the repository**, never into a tracked path:

| Relationship to the live tree | Count |
|---|---|
| Archived copy is byte-identical to the live file (pure duplicate) | 44 |
| Archived copy is **stale** — the live file has since changed | 17 |
| **No live counterpart at all** — the code was deleted from the application | **7** |

The seven with no live counterpart are the reason this matters:

* `Controllers/Debug/DebugController.php`
* `Controllers/UpdateController.php`
* `Controllers/InstallerController.php`
* `Controllers/Customer/ReportsController.php`
* `Controllers/Admin/PluginsController.php`
* `Controllers/Admin/ThemeCustomizerController.php`
* `Controllers/Admin/AiSettingsController.php`

### 2.1 The archived `DebugController` is the removed one, in full

`Controllers/Debug/DebugController.php`, 17 260 bytes, dated 2026-02-04,
**431 lines**, declaring `class DebugController` with exactly six public
methods:

```
index  removeJobs  addGateways  removeContacts  cacheClear  updateCampaignCache
```

That matches the file
`docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md`
§5.4 describes and §16.A.1 ordered deleted — 431 lines, six public methods
— including the handlers behind the five unauthenticated `GET` routes that
truncated tables and deleted contacts.

## 3. The live application is unaffected

Verified on this branch, at `origin/main`
`35219efd4fbcd7f5d7a4d346868f37f488a637a5`:

| Check | Result |
|---|---|
| `app/Http/Controllers/Debug/DebugController.php` | **absent** |
| `app/Http/Controllers/Debug/` directory | **absent** |
| Files tracked under `app/Http/Controllers/Debug` | **0** |
| Any PHP file declaring `class DebugController` | **none** — the only textual match anywhere is the literal inside `tests/Feature/Security/NoHardcodedGatewayCredentialsTest.php`, which is the test asserting that no such declaration exists |
| Debug route registrations in `routes/` | **none**. `add-gateways`, `remove-jobs`, `remove-contacts`, `cache-clear` and `update-campaign-cache` each appear exactly once, inside the `routes/web.php` comment block that records their removal — no `Route::get`/`post`/`any`/`match` registers any of them |

Deleting the archive removes the last copy of that source from the
repository. It resurrects nothing and it removes nothing the application
uses.

## 4. Nothing consumed the archive

Searched across the whole tracked tree at
`35219efd4fbcd7f5d7a4d346868f37f488a637a5`:

| Search | Result |
|---|---|
| `app/Http/Controllers.zip` | **0** references |
| `Controllers.zip` (any context) | 2 hits, both **documentation prose** — `docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md` §5 item 3, which recorded this archive as a deferred finding, and one narrative line in `docs/automation/USAGE-SUBPROCESS-DATABASE-SAFETY-COMPLETION.md`. Neither is a consumer |
| Composer autoload | PSR-4 over `app/` plus two explicit `files` entries; PSR-4 class-maps `.php` only, so a `.zip` was never autoloadable. No classmap entry names it |
| Composer scripts | `post-autoload-dump`, `post-root-package-install`, `post-create-project-cmd`, `post-update-cmd` — none touches it |
| npm scripts | `development`, `watch`, `watch-poll`, `hot`, `production` — all `laravel-mix`; `package.json` does not contain the string `zip` |
| `webpack.mix.js` | never references `app/` or any `.zip` |
| GitHub workflows and `.github/scripts/` | no reference. The only `app/Http` string is a documentation line in the disabled `openai_orchestrator.py` pilot, naming an unrelated `WorkspaceController.php` |
| Deployment / build scripts | none tracked (no `Dockerfile`, `Procfile`, `Makefile`, or tracked shell/PowerShell deployment script) |
| Archive-extraction code (`ZipArchive`, `->extractTo(`, `zip_open(`, `PharData`, `unzip`) | one user only: `app/Repositories/Eloquent/EloquentLanguageRepository.php`, which builds and extracts **language packs** under `storage/tmp` and the language directory. It never names `app/Http/Controllers.zip` |

`ext-zip` and `madnest/madzipper` appear in `composer.json` because of that
language-pack feature, not because of this file.

### 4.1 Why the existing guard never caught it

`tests/Feature/Security/NoHardcodedGatewayCredentialsTest::test_no_php_source_file_anywhere_declares_a_debug_controller_class`
walks `app_path()` recursively but skips every entry whose extension is not
`php`:

```php
if ($file->getExtension() !== 'php') {
    continue;
}
```

So the archive was invisible to the very test written to prove that
`DebugController` no longer exists in `app/`. The guard is not wrong — it
is a source-file guard — but it is worth recording that a compressed copy
sat inside its search root and was skipped. Removing the archive closes
that gap without changing the test.

## 5. Why keeping it is a real cost

* **Deleted code should stay deleted.** Slice 0 removed `DebugController`
  because five unauthenticated `GET` routes could truncate tables and
  delete contacts. Keeping a complete, readable copy of those six method
  bodies in the repository preserves the exact implementation an attacker
  or a careless restore would want, and makes "we removed it" less true
  than it reads.
* **Six more deleted controllers travel with it**, including the installer
  and updater — surfaces whose old implementations are the least useful
  thing to keep lying around.
* **44 of the entries are byte-for-byte duplicates and 17 are stale
  forks.** A reader who opens the archive cannot tell which is which
  without doing the comparison in §2. Stale duplicates of production code
  invite edits to the wrong copy and confuse code search, grep and
  security review.
* **It cannot drift back into correctness.** One commit touched it, in
  July; nothing updates it, and nothing can, because nothing reads it.
* **It is 280 KB of binary in `app/`**, a directory that should contain
  source. Binary blobs there defeat diffing, review and every text-based
  scan the repository already relies on.

There is no offsetting benefit: git history already preserves every one of
these files at every revision they ever had, including the deleted ones.
`git show 2e3b7f5:app/Http/Controllers.zip` retrieves the archive itself
for anyone who ever needs it.

## 6. Exact verification

Base: `origin/main` `35219efd4fbcd7f5d7a4d346868f37f488a637a5`. Branch cut
directly from that commit. The archive was inspected by streaming its blob
to a scratch path outside the repository; **nothing was extracted into a
tracked path**, and `git status` confirmed that after the comparison.

| Check | Result |
|---|---|
| Archive tracked before the change | yes — `100644 cd8935a6… 0 app/Http/Controllers.zip` |
| Archive after `git rm` | absent from disk and from the index |
| Repository-wide reference search | §4 — no consumer |
| Composer script search | §4 — none |
| npm script search | §4 — none |
| GitHub-workflow search | §4 — none |
| Deployment/build-script search | §4 — none tracked |
| Route search for `DebugController` | §3 — comment only, no registration |
| Tracked PHP source search for `class DebugController` | §3 — none |
| Application boot smoke test | recorded below |
| Focused routing/security suites | recorded below |
| `git diff --check` | clean |
| Secret-shaped-string sweep on this document | clean |
| Changed paths against `origin/main` | exactly **2** |
| Final `git status --short` | empty |

No production build was run: this change cannot affect compiled assets, so
rebuilding would only risk `public/` churn.

### 6.1 Executed checks

Database used: **`ultimatesms_testing_archive`** — a derived disposable
sibling that `Tests\Support\TestDatabaseSafety::isSafeTestDatabaseName()`
accepts, chosen so this lane could not collide with any other. Reset with
`migrate:fresh`: **250 migrations ran, 0 pending**.

**Application boot.** `php artisan --version` → `Laravel Framework 12.64.0`
with the archive already deleted from disk. `php artisan route:list` could
not be used: it aborts in this local environment on
`services.stripe.secret must not be empty`, a pre-existing configuration
condition unrelated to this change. The router was therefore booted
in-process instead, which exercises the same registration path:

| | |
|---|---|
| Routes registered | **986** |
| Routes matching a removed debug URI (`add-gateways`, `remove-jobs`, `remove-contacts`, `cache-clear`, `update-campaign-cache`) | **0** |
| Routes whose action mentions "Debug" | 6 — all `_debugbar/*` from the third-party `barryvdh/laravel-debugbar` package, unrelated to the removed controller |
| `class_exists('App\Http\Controllers\Debug\DebugController')` | **false**, against a Composer autoloader regenerated *after* the deletion |

**Focused routing and security suites**, run with the archive deleted and
then again with it restored to `origin/main`'s content — same machine, same
database, same migration state, so the deletion is the only variable:

| Suite | archive removed (this branch) | archive restored (`origin/main`) | delta |
|---|---|---|---|
| `Security/DebugRouteRemovalTest` | 7 tests, 13 assertions, 5 errors | 7 tests, 13 assertions, 5 errors | **identical** |
| `Security/NoHardcodedGatewayCredentialsTest` | 2 tests, 2 assertions, **green** | 2 tests, 2 assertions, **green** | **identical** |
| `tests/Feature/Security` | 172 tests, 752 assertions, 108 errors | 172 tests, 752 assertions, 108 errors | **identical** |

`DebugRouteRemovalTest::test_no_route_anywhere_truncates_a_table_or_deletes_contacts_and_debug_controller_no_longer_exists`
— the test that asserts the class is gone — **passes** with the archive
deleted: 1 test, 7 assertions.

**The errors are `main`'s, not this branch's.** All 108 (and the 5 inside
`DebugRouteRemovalTest`) have exactly one cause, with no second cause in
any log:

```
Illuminate\Foundation\MixFileNotFoundException:
Unable to locate Mix file: /js/core/theme-tokens.js.
(View: resources/views/panels/scripts.blade.php)
```

`origin/main` ships a 1 179-entry `public/mix-manifest.json` with no
`/js/core/theme-tokens.js` key and no such file on disk, while
`resources/views/panels/scripts.blade.php` requires it, so every test that
renders an authenticated page errors. `public/` is untouched by this
branch, and the control run reproduces the same counts exactly. The errors
are reported rather than worked around: nothing was skipped or relaxed.

**Runtime-generated tracked files.** `composer install` rewrote
`bootstrap/cache/packages.php` and `bootstrap/cache/services.php` during
verification. Both were restored to the branch's own `HEAD` with a
path-scoped `git restore` after the last test run, per `AGENTS.md`, and the
final `git status --short` is empty apart from the two intended paths.

---

## 7. One follow-up this creates

`docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md` §5 item 3
and one narrative line in
`docs/automation/USAGE-SUBPROCESS-DATABASE-SAFETY-COMPLETION.md` describe
this archive as still present, because it was when they were written. Both
are historical records of deferred findings, and both are outside this
branch's two-path allowlist, so neither is edited here. This document is
the record that the finding is now closed.
