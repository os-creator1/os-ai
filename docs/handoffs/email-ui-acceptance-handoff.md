# Handoff: final verification and PR preparation for `fix/platform-email-logo`

Written 2026-10-14 at the end of a short Claude Code session. Read `AGENTS.md`
and `CLAUDE.md` first; this file assumes them.

## 1. Exact state at handoff

| Item | Value |
|---|---|
| Repository | `os-creator1/os-ai` |
| Feature branch | `fix/platform-email-logo` |
| Implementation head (last code commit) | `da6f27e0a308d46dcd48cc87964fe0cc9bb73aa4` |
| `origin/staging` at handoff | `3a5296b671de655b2578ccff3a444463520f75d0` |
| Merge-base of feature and staging | `3a5296b6...` (the feature branch is **already up to date** with current staging; no conflict/merge needed) |
| Working tree before this doc was added | clean (`git status --porcelain` empty, no stashes) |
| Code changes made this session | **None.** The implementation is preserved exactly as at `da6f27e0`. This handoff file is the only addition (the handoff commit sits on top of `da6f27e0`; `git log -1` on the branch is that docs commit). |
| PR | **Not created.** Not merged. Not deployed. |

The container's local checkout started on a stale `staging` (`32263cd9`); this
session fetched and created a tracking branch for the feature branch.

## 2. The four fixes (already implemented; do not reimplement)

Commits on the branch beyond `staging` (oldest first):

1. `112f2e72` Render the owner-configured platform logo in platform emails
2. `36fd83c8` Only reference platform email logo files that exist on disk
3. `aaf1b660` Show one primary page heading: skip the shell title when the page owns one
4. `a7db5c97` Redesign the Custom fields settings page
5. `7c487c78` Redesign the contact profile
6. `da6f27e0` Verification fixes: config-based logo URL, cheaper heading check, mobile copy action

Mapping to the four fixes:

1. **Platform email logo** — `app/Library/Mail/MailBrand.php`,
   `resources/views/vendor/mail/html/layout.blade.php`. `da6f27e0` builds the
   logo URL from `ASSET_URL`/`app.url` instead of `asset()` because queued mail
   has no request, so the URL generator's cached root could be the wrong host.
   Only logo files that exist on disk are referenced.
2. **Custom Fields redesign** —
   `resources/views/customer/settings/custom-fields/index.blade.php` (large
   rewrite). `da6f27e0` also shows the copy (merge field) button in the actions
   cell below 576px because the Merge field column is hidden on phones.
3. **Global duplicate page heading** — new `app/Library/Navigation/PageHeadingResolver.php`
   and `resources/views/panels/breadcrumb.blade.php`; skips the shell title when
   the page owns its own heading. `da6f27e0` added a regex pre-pass so the DOM
   parse only runs when a candidate heading exists (author-reported ~56 ms to
   ~0.5 ms on a 589 KB page; **not re-measured by this session**).
4. **Contact Detail redesign** —
   `resources/views/customer/people/show.blade.php`,
   `resources/views/customer/people/_custom-fields.blade.php`,
   `app/Http/Controllers/Business/ContactDirectoryController.php`.

### Changed files (`git diff --stat staging...branch`: 12 files, +883/-167)

```
app/Http/Controllers/Business/ContactDirectoryController.php
app/Library/Mail/MailBrand.php
app/Library/Navigation/PageHeadingResolver.php            (new)
resources/views/customer/people/_custom-fields.blade.php
resources/views/customer/people/show.blade.php
resources/views/customer/settings/custom-fields/index.blade.php
resources/views/panels/breadcrumb.blade.php
resources/views/vendor/mail/html/layout.blade.php
tests/Feature/Contacts/ContactsPersonFirstTest.php
tests/Feature/CustomFields/CustomFieldsHttpTest.php
tests/Feature/Mail/TransactionalEmailDesignTest.php
tests/Unit/Navigation/PageHeadingResolverTest.php         (new)
```

## 3. Verified vs unverified

**Verified by this session (inspection only):** branch/SHA relationships above,
the list of commits and changed files, working-tree cleanliness, and the
environment facts in section 4.

**NOT verified — nothing below was run by this session:**

- No PHPUnit test of any kind (Mail, Notifications, CustomFields, CustomerShell,
  Navigation, Contacts, Calendar, Workspace, Auth, Analytics, Website).
- No comparison against a staging baseline.
- No rendered UI check at 1440 / 768 / 390 px; no heading, overflow,
  permission, Business-isolation or Location-scoping check.
- No email rendering check (HTTPS image URLs, `config:cache`, queued rendering,
  fallback, Business/Agency white-label preservation).
- No code review of the diff was performed; the section 6 risks are things to
  look at, not findings.

**Previous testing handoff:** none exists in the repository (`docs/handoffs/`
did not exist before this file) and this session had no access to one. The
task statement said only that the four fixes are implemented. If a prior
handoff exists elsewhere, ask the user for it rather than assuming its content.
Any test results the original implementing session (`session_01Ee4bLia1EWQzJ8YVCscsSc`,
referenced in commit trailers) may have obtained are not recorded in the repo
and must be treated as unverified.

## 4. Environment state in this container (as inspected)

- PHP 8.3.6 CLI present. Extensions seen: curl, dom, gd, intl, mbstring,
  mysqli, mysqlnd, pdo_mysql, pdo_sqlite, xml, zip. **`bcmath` was not in the
  `php -m` output**; the CI gate installs it (`bcmath` in its extension list),
  so install/enable it or expect failures where it is used.
- Composer present at `/usr/local/bin/composer` (plugins disabled in this
  non-interactive session). **`vendor/` does not exist; `composer install` has
  NOT been run.** `composer.lock` and `package-lock.json` exist.
- No `.env` / `.env.testing`.
- **No `mysql`, `mysqld` or `mariadb` binary found on PATH.** No MySQL 8 is
  available locally. Installing one was not attempted.
- Node 22 at `/opt/node22/bin/node`. Chromium + Playwright preinstalled
  (`PLAYWRIGHT_BROWSERS_PATH=/opt/pw-browsers`; do not run `playwright install`).
- The GitHub CLI is not available; use `mcp__github__*` tools (load via ToolSearch).
- Outbound HTTPS goes through a proxy; see `/root/.ccr/README.md` if a
  download fails.

## 5. Acceptance plan (execute in this order)

Guardrails from the user: one agent, no subagents, no unrelated development, no
production changes, no parallel database-writing tests, no unnecessary review
loops, do not merge or deploy without approval, do not ask the user to run tests
manually unless there is no automated alternative.

1. **Environment.** `git checkout fix/platform-email-logo`. Try
   `composer install --no-interaction` (needs bcmath). Time-box any local MySQL
   setup; if no isolated MySQL 8 can be started in this container quickly, stop
   and go to step 2 instead of troubleshooting repeatedly. Never touch a
   production or staging database.
2. **If local tests are impossible:** assess an isolated GitHub Actions run
   without disturbing existing CI or autonomous processes. Facts found:
   `.github/workflows/ai-subscription-gate.yml` already uses a `mysql:8.0`
   service with `DB_DATABASE=ultimatesms_testing`, user `ultimatesms_test`,
   and asserts those values; but it is part of the autonomous label loop (only
   it may move `ai:testing` to `ai:awaiting-codex`), so **do not trigger or
   modify it**. Other workflows: `ai-orchestrator-pilot.yml`,
   `ai-subscription-autostart.yml`, `ai-subscription-loop.yml`,
   `ai-subscription-watchdog.yml`, `claude.yml`,
   `rfc-003-m3-aggregate-regression.yml`. A feasible option is a **new,
   separately named, `workflow_dispatch`-only workflow** on the feature branch
   (disposable `mysql:8.0` service, `ultimatesms_testing`, no AI labels, no metered
   secrets). Adding it is a repo change outside the four fixes: tell the user
   and get their go-ahead before committing it, and remove it before the PR.
   Per CLAUDE.md, GitHub Actions is the authoritative disposable MySQL gate on
   the Routine routes; this task is a human-directed manual lane, so check
   `AGENTS.md` ("Two workflows") and report which database was used.
3. **Run suites sequentially** (never in parallel against the same DB), focused
   first. Test directories that exist: `tests/Feature/{Mail,BusinessEmail,
   CustomFields,CustomerShell,Navigation,Contacts,Calendar,Workspace,Auth,
   Analytics,Website}` and `tests/Unit/{Mail,Navigation,Analytics,Website,
   Workspace}`. Start with the four touched files:
   `tests/Feature/Mail/TransactionalEmailDesignTest.php`,
   `tests/Feature/CustomFields/CustomFieldsHttpTest.php`,
   `tests/Feature/Contacts/ContactsPersonFirstTest.php`,
   `tests/Unit/Navigation/PageHeadingResolverTest.php`.
   Every run must report a positive test count (zero tests = failure) and state
   the exact database. Notifications tests: locate with
   `grep -rli notification tests/Feature tests/Unit`.
4. **Baseline comparison.** For any failure, rerun the same test on
   `origin/staging` (`3a5296b6`) with a fresh disposable DB. Fix only failures
   that are genuinely introduced by this branch.
5. **Rendered UI** at 1440, 768 and 390 px in an authenticated Business session
   (Playwright + preinstalled Chromium; seed data only in the disposable DB).
   Check: exactly one meaningful page heading per screen (also across the
   shell/breadcrumb); Custom Fields tabs, edit, archive/restore, copy merge
   field, ordering; Contact detail incl. dynamic custom fields, save, booking and
   messaging actions; permissions, Business isolation, Location scoping; no
   horizontal overflow or broken layout.
6. **Email branding:** platform emails use the owner-configured MotionGrove logo
   (no "M" monogram, no legacy branding); image URLs are HTTPS; behaviour is
   correct with `php artisan config:cache`, when rendered inside a queued job
   (no request), and with the fallback when the logo file is missing; Business
   and Agency white-label branding remain intact.
7. **Delivery:** report exact test counts/failures/blockers, final SHA, and
   `git status --short` (must be empty; restore runtime-generated tracked files
   like `bootstrap/cache/*.php` and `public/mix-manifest.json` with a path-scoped
   `git restore`). Only if every acceptance check passes, prepare a PR
   **targeting `staging`** — see the rule conflict below.

## 6. Known risks / items to examine

- Large template rewrites (Custom Fields +386/-, contact `show.blade.php`
  +222/-) have unknown test coverage; the touched tests add only ~170 lines.
- `PageHeadingResolver` inspects rendered HTML with a regex pre-pass then a DOM
  parse. Risk of false positives/negatives on pages with multiple or hidden
  headings; the cheaper pre-pass was only author-measured.
- `MailBrand` now builds the URL from `ASSET_URL`/`app.url`: verify behaviour
  when `ASSET_URL` is unset, `app.url` is HTTP, or `config:cache` is active.
- Mobile copy button added below 576px: check for duplicate buttons/IDs at the
  breakpoint and for view-only users.
- Contact detail changes touch `ContactDirectoryController`: confirm Business
  isolation and Location scoping were not weakened.
- The local `bcmath`/MySQL gaps above may block local verification entirely.

## 7. Project rules that bind this work

From `CLAUDE.md` (route 3, "separately and explicitly human-authorized manual
lane": this task's scope comes from the user's task contract, not the state
file; the paused autonomous loop must never be activated or bypassed):

- **Claude never creates the pull request and never merges it** across all
  routes; ChatGPT reviews the pushed branch and opens the PR, the human
  merges. **This conflicts with the user's instruction "Prepare a PR targeting
  `staging`".** Resolve by asking the user, or by preparing the PR title/body
  as text and pushing the branch rather than opening the PR; the user's
  messages in this session did not explicitly override CLAUDE.md. The system
  prompt additionally forbids creating a PR unless explicitly asked.
- Branch-only: never push to `main`/`master`; work only on the task's branch
  (here `fix/platform-email-logo`; the platform session instructions also name
  `staging` as the designated development branch, so confirm with the user
  before pushing anything to `staging` — pushing to `staging` was NOT done).
- No metered model credentials (OpenAI/Anthropic API) in scripts or workflows.
- Never touch production-looking databases, data, credentials or deployment
  targets; on a blocker, stop and report in prose (do not claim `ai:*` label
  transitions; labels belong to routes 1 and 2).
- Never report tests that were not run; a test command with zero discovered
  tests is a failure; report starting/final SHA, changed paths, exact tests and
  counts, final clean status.
- Commit and push when implementation/correction is requested.

From `AGENTS.md`:

- Tests may only use `ultimatesms_testing` or a derived sibling accepted by
  `Tests\Support\TestDatabaseSafety` (e.g. `ultimatesms_testing_lane_e`); the
  class is the single authority and must not be restated or duplicated. Names
  with segments such as prod/live/staging/backup/master/main/real are refused.
- Concurrent DB-writing lanes need distinct siblings; destructive suites own
  their DB alone. Every report must state the exact database used.
- Run focused tests before broader ones; report exact counts and changed files;
  leave no tracked file modified (restore generated files; final
  `git status --short` empty).
- Workspace authorization: ownership, active membership, direct Business
  ownership, `users.parent_id` and platform-admin are separate paths; inactive
  membership grants nothing; Business scope (`all`/`selected`) is independent of
  role (`admin`/`staff`).
- Automation: no metered credentials; only the deterministic AI Subscription Test
  Gate moves `ai:testing` to `ai:awaiting-codex`; never merge, force-push or push
  to `main`; no bypassing required tests.

## 8. Exact next action

1. `git fetch origin && git checkout fix/platform-email-logo && git status` and
   confirm HEAD is the handoff commit on top of `da6f27e0`.
2. Ask the user (one short question) whether to open the PR yourself or only
   deliver the pushed branch plus PR text, given the CLAUDE.md rule above.
3. Run `composer install --no-interaction`; then follow section 5 starting with
   the four touched test files against an isolated MySQL 8, or fall back to a
   separate dispatch-only GitHub Actions workflow with the user's go-ahead.
