# CLAUDE.md

## Subscription-based Claude Routine

Claude automation in this repository runs through a Claude Code Routine using
the owner's Claude subscription. The former API-backed GitHub Action and the
OpenAI/Claude orchestrator pilot are disabled.

The Routine follows the saved prompt in
`docs/automation/CLAUDE-ROUTINE-PROMPT.md` and the locked task in
`docs/automation/AI-AUTONOMY-STATE.json`.

A human developer may also run Claude Code manually/interactively for the
locked current slice instead of dispatching the Routine. See
`docs/automation/AI-SUBSCRIPTION-LOOP.md` ("Manual completion path") for the
exact conditions under which manual implementation, manually-run tests, and
human review satisfy completion without Codex review or an automatic Routine
handoff.

## Three workflows, and which rules bind each

Work reaches this repository through one of exactly three routes. The
"Non-negotiable rules" below are written for routes 1 and 2. Route 3 draws
its branch, worktree, allowlist, database and verification requirements from
its own task contract instead, and the per-rule notes say where that differs.

1. **The autonomous Claude Routine.** Dispatched automatically, following
   `docs/automation/CLAUDE-ROUTINE-PROMPT.md` and the locked task in
   `docs/automation/AI-AUTONOMY-STATE.json`. It is currently **paused**
   (`implementation_authorized: false`, `gate_label: "ai:paused"`).
2. **Manual completion of that same locked slice.** A human runs Claude Code
   interactively to finish the Routine's own locked current slice. Same
   scope, same state file, same restrictions — only the execution differs.
3. **A separately and explicitly human-authorized manual lane.** The human
   issues one concrete task through the ChatGPT→Claude review-gated
   workflow. **Scope comes from that task's own contract**, not from the
   state file, and `AGENTS.md` governs it. Such a lane:
   - uses the branch, worktree, exact allowlist, database and verification
     requirements named in its own task contract;
   - may use the canonical `ultimatesms_testing` **or** a validated derived
     disposable sibling that `Tests\Support\TestDatabaseSafety` accepts, per
     `AGENTS.md`;
   - must use a **distinct** `TestDatabaseSafety`-approved sibling when it
     writes to a database concurrently with another lane, and must not share
     a database with any other destructive lane;
   - creates **no standing authority** — authorization is per task, and
     finishing one lane grants none to start another or to invent work;
   - never activates, resumes or bypasses the paused autonomous loop.

Across all three routes, without exception: **Claude never creates the pull
request and never merges it** — ChatGPT reviews the pushed branch and opens
the PR, and the human alone merges. **No production-looking database, no real
data, and no production-looking credential or deployment target is ever
permitted**, on any route.

### State labels

- `ai:implement`: implement exactly the locked current slice.
- `ai:testing`: a real pushed commit is waiting for the free deterministic
  GitHub scope/test gate.
- `ai:awaiting-codex`: implementation or correction is waiting for review.
- `ai:codex-reviewed`: process the trusted Codex review for the current head.
- `ai:ready-for-human`: all bounded gates passed; stop for a human merge choice.
- `ai:needs-human`: a guard, contradiction, or correction limit stopped the run.
- `ai:paused`: do nothing.

## Non-negotiable rules

These bind routes 1 and 2 as written. Where route 3 differs, the rule says
so; where it does not say so, the rule binds route 3 identically.

- **Branch-only development.** Never push directly to `main` or `master`.
  Routes 1 and 2 work exclusively on the pull request branch named in the
  automation state. Route 3 works exclusively on the branch its own task
  contract names.
- **Never merge pull requests, and never open one.** A human makes the merge
  decision; ChatGPT opens the PR. Binds every route.
- **No metered model credentials.** Do not call OpenAI, Anthropic Console, or
  another paid model API from repository workflows or scripts. Binds every
  route.
- **Routes 1 and 2: use only `ultimatesms_testing`.** Do not improvise a
  database inside a Routine or while manually completing its locked slice.
  GitHub Actions is the authoritative disposable MySQL test gate for those
  routes. **Route 3** uses the database its own task contract names, which
  `AGENTS.md` permits to be `ultimatesms_testing` or a validated derived
  disposable sibling accepted by `Tests\Support\TestDatabaseSafety`;
  concurrent database-writing lanes must use distinct approved siblings.
- **Never a production-looking database.** No route may run against a
  production-looking name; `TestDatabaseSafety` refuses them, and a name is
  never acceptable merely because it contains "test".
- **Never touch production-looking data or secrets.** Stop with
  `ai:needs-human` if a task appears to require either. Binds every route.
- **Do not claim unverified tests.** GitHub Actions runs the locked focused
  commands after each pushed implementation or correction.
- **Reject zero-test success.** A test command must report a positive test
  count; `No tests found` is a failure even when the command exits zero.
- **Require real progress.** A requested implementation/correction must create
  and push a commit. An unchanged branch head is a failed run.
- **Report exact evidence.** Every completion comment states the starting and
  final SHA, exact changed files, exact test counts, and label transition.
