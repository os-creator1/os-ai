# Repository agent guidance

This Laravel 12 / PHP 8.2 repository extends Ultimate SMS into AI Business OS.
Follow the existing controller -> repository -> library -> model structure and
the approved RFCs under `docs/rfcs/`.

## Verification

- Run only against a **disposable test database**: `ultimatesms_testing`, or
  an isolated sibling named `ultimatesms_testing_<safe suffix>` (lowercase
  letters, digits and underscores only). `Tests\Support\TestDatabaseSafety`
  is the single authority on which names are permitted; a production-like,
  empty or unsafe name is refused before anything runs. Isolated names exist
  so two lanes can test at the same time without overwriting each other's
  database — which used to happen silently.
- Prefer the supported runner, which validates the database, clears caches,
  migrates, checks the compiled assets exist, keeps raw logs, and returns a
  truthful exit code:
  `composer test:baseline -- --database=ultimatesms_testing_<your suffix>`.
  It never writes `.env` or `.env.testing`.
- The local MySQL user needs `GRANT ALL PRIVILEGES ON `ultimatesms\_testing%`.*`
  so isolated siblings and the temporary databases some suites create can be
  created and dropped.
- Run focused tests before broader regression tests.
- A command that exits zero but discovers zero tests is a failure.
- Report the exact test count and exact changed-file list.
- Never use production-looking credentials, databases, or deployment targets.

## Code Review Rules

### Active automation contract

- Read `docs/automation/AI-AUTONOMY-STATE.json` before reviewing an
  automation-managed pull request.
- Enforce that file's allowed paths, required tests, and locked slice contract.
- Treat product work from a later slice as scope creep even when it looks
  useful.

### Workspace authorization

- Workspace ownership, active membership, direct Business ownership,
  `users.parent_id`, and platform-admin access are separate authorization
  paths. Do not allow one to silently imply another.
- Owner role wins over an anomalous coexisting membership row.
- Inactive membership grants no Workspace-derived access.
- Business scope (`all` or `selected`) is independent from member role
  (`admin` or `staff`).

### Automation safety

- Active automation must not reference `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`,
  or another metered model credential.
- Only the deterministic `AI Subscription Test Gate` may move
  `ai:testing` to `ai:awaiting-codex`.
- The state loop may change labels and comments, but it must never merge,
  force-push, push to `main`, or bypass required tests.
- A successful AI action requires a real pushed commit when code changes were
  requested. An unchanged branch head is not implementation success.
- Only reviews from the official `chatgpt-codex-connector` GitHub App may move
  a PR from `ai:awaiting-codex` to `ai:codex-reviewed`.
