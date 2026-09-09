# Repository agent guidance

This Laravel 12 / PHP 8.2 repository extends Ultimate SMS into AI Business OS.
Follow the existing controller -> repository -> library -> model structure and
the approved RFCs under `docs/rfcs/`.

## Verification

### Which database a test run may use

Run only against a **disposable** database. Since PR #228 that means one of
exactly two things, and nothing else:

- the canonical disposable database `ultimatesms_testing`; or
- a clearly derived disposable sibling of it that
  `Tests\Support\TestDatabaseSafety` accepts, such as
  `ultimatesms_testing_lane_e`.

`Tests\Support\TestDatabaseSafety` is the single authority on that question.
Do not restate its policy anywhere, and do not add a second helper.

- **Fail closed.** A database name must be validated through
  `TestDatabaseSafety` before the first write. Unset, empty, malformed,
  mismatched, unsafe and production-looking names are refused, and a name is
  never accepted merely because it contains the word "test"
  (`acme_test_live` is refused).
- **Never a production-looking name.** Suffix segments such as `prod`,
  `production`, `live`, `staging`, `backup`, `master`, `main` and `real` are
  rejected, matched per underscore-separated segment.
- **Concurrent database-writing lanes must use distinct validated siblings.**
  Two lanes sharing one database silently corrupt each other's results; that
  has already happened once, which is why the sibling form exists.
- **No destructive parallel lane may share a database with another lane.** A
  suite that may drop, truncate or `migrate:fresh` owns its database alone
  for the duration.
- **A task may explicitly require the canonical database** when exact
  canonical-baseline reproduction is the point, or when a suite genuinely
  pins it — `tests/Feature/Workspace/Support/TemporaryTestDatabase.php` still
  does. When a task says so, use `ultimatesms_testing` and say so in the
  report.
- **Every report must state the exact database used.** "Ran the suite" is not
  a result; "ran against `ultimatesms_testing_lane_e`, 250 migrations, 0
  pending" is.

### Everything else

- Run focused tests before broader regression tests.
- A command that exits zero but discovers zero tests is a failure.
- Report the exact test count and exact changed-file list.
- Never use production-looking credentials, databases, or deployment targets.
- Leave no tracked file modified at the end of a task. Runtime-generated
  tracked files (`bootstrap/cache/*.php`, `public/mix-manifest.json`) may be
  regenerated while verifying, but must be restored to the branch's own
  `HEAD` with a path-scoped `git restore` before the final report, and the
  final `git status --short` must be empty.

## Code Review Rules

### Two workflows, governed separately

Work reaches this repository through one of exactly two routes. They have
different sources of scope, and a reviewer must first decide which one is in
front of them.

This section exists because the repository previously did not encode that
distinction. PR #229 was directly authorized by the human and its
implementation was technically correct, but the rules as they stood did not
describe the route that produced it — so the review findings against it were
legitimate governance findings, not a misreading. The rules below fix that
prospectively.

**A. Autonomous state-loop work.** Driven by the Routine and governed by
`docs/automation/AI-AUTONOMY-STATE.json`.

- Read that file before reviewing an automation-managed pull request.
- Enforce its `allowed_paths`, `required_test_commands`,
  `expected_head_sha` and locked slice contract.
- Treat product work from a later slice as scope creep even when it looks
  useful.
- `implementation_authorized: false` and `gate_label: "ai:paused"` mean the
  autonomous loop may not start anything. It is currently paused, and it has
  no standing authority to begin work, select a next slice, or merge.

**B. Explicitly human-authorized manual lanes.** The human gives Claude one
concrete task through the ChatGPT→Claude review-gated workflow.

- **Scope comes from that task's own contract**, not from the state file. The
  task states its own exact allowlist, branch, worktree and verification
  requirements, and the reviewer enforces *those*.
- The state file's `allowed_paths: []` and `implementation_authorized: false`
  describe the **autonomous** contract. They are not a repository-wide ban,
  and they do not retroactively forbid a lane the human authorized directly.
  That reading is what the state file itself anticipates when its
  `forbidden_scope` closes with *"Any future work requires separate, explicit
  human authorization"* — a per-task human instruction **is** that separate
  explicit authorization.
- Claude commits and pushes; **Claude does not open the PR**. ChatGPT reviews
  the pushed branch independently and opens it. The human alone merges.
- A manual lane does **not** activate, resume or bypass the autonomous loop,
  and does not change any field in the state file unless its own task says
  to.
- Authorization is **per task and does not persist**. Finishing one lane
  grants no authority to start another, to widen scope, or to invent work.

Neither route may merge, force-push, push to `main`, or bypass required
tests. `merge_policy` stays `human_only` for both.

The reconciliation that produced this section, and the two PR #229 review
comments that exposed the ambiguity, are recorded in
`docs/automation/MANUAL-LANE-GOVERNANCE-RECONCILIATION.md`.

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
