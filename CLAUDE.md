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

Work reaches this repository through one of exactly three routes. **Every
rule below carries an explicit route tag.** There is no default and no
catch-all: a rule that does not name a route is a defect in this document,
not a rule that silently binds everything. Routes 1 and 2 take their branch,
scope, database and verification from the state file's locked slice; route 3
takes all four from its own task contract.

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

### State labels — **routes 1 and 2 only**

These labels are the autonomous state loop's own control surface. **Route 3
does not use them**, does not transition them, and must never claim a
transition that did not occur. A manual lane reports to the human in prose
instead.

- `ai:implement`: implement exactly the locked current slice.
- `ai:testing`: a real pushed commit is waiting for the free deterministic
  GitHub scope/test gate.
- `ai:awaiting-codex`: implementation or correction is waiting for review.
- `ai:codex-reviewed`: process the trusted Codex review for the current head.
- `ai:ready-for-human`: all bounded gates passed; stop for a human merge choice.
- `ai:needs-human`: a guard, contradiction, or correction limit stopped the run.
- `ai:paused`: do nothing.

## Non-negotiable rules

Each rule names the routes it governs. Where a rule's obligation is the same
everywhere but its *mechanism* differs, both mechanisms are stated.

- **Branch-only development.** *(all routes)* Never push directly to `main`
  or `master`. *(routes 1 and 2)* Work exclusively on the pull request branch
  named in the automation state. *(route 3)* Work exclusively on the branch
  its own task contract names.
- **Never merge a pull request, and never open one.** *(all routes)* ChatGPT
  reviews the pushed branch and opens the PR; the human alone merges.
- **No metered model credentials.** *(all routes)* Do not call OpenAI,
  Anthropic Console, or another paid model API from repository workflows or
  scripts.
- **Which database.** *(routes 1 and 2)* Use only `ultimatesms_testing`. Do
  not improvise a database inside a Routine or while manually completing its
  locked slice; GitHub Actions is the authoritative disposable MySQL test
  gate for those routes. *(route 3)* Use the database its own task contract
  names, which `AGENTS.md` permits to be `ultimatesms_testing` or a validated
  derived disposable sibling accepted by `Tests\Support\TestDatabaseSafety`;
  lanes writing to a database concurrently must use **distinct** approved
  siblings, and no destructive lane may share one.
- **Never a production-looking database.** *(all routes)* `TestDatabaseSafety`
  refuses them, and a name is never acceptable merely because it contains
  "test".
- **Never touch production-looking data or secrets.** *(all routes)* The
  prohibition is absolute. The stop mechanism differs: *(routes 1 and 2)* stop
  with `ai:needs-human`. *(route 3)* stop the work and report the blocker to
  the human in the lane's normal manual report — do **not** claim a label
  transition unless one actually occurred.
- **Do not claim unverified tests.** *(all routes)* Never report a test run
  that did not happen. The verification differs: *(routes 1 and 2)* GitHub
  Actions runs the locked focused commands after each pushed implementation
  or correction. *(route 3)* Run the exact local or remote verification the
  lane's own task contract requires, and report exactly what was run.
- **Reject zero-test success.** *(all routes)* A test command must report a
  positive test count; `No tests found` is a failure even when the command
  exits zero.
- **Require real progress.** *(routes 1 and 2)* A requested
  implementation/correction must create and push a commit; an unchanged
  branch head is a failed run. *(route 3)* Likewise commit and push whenever
  implementation or correction was requested. The one exception: a genuinely
  inspection-only task whose contract **explicitly prohibited changes** may
  report without a commit, and must say so plainly.
- **Report exact evidence.** *(routes 1 and 2)* Every completion comment
  states the starting and final SHA, exact changed files, exact test counts,
  and the label transition. *(route 3)* Report the starting and final SHA,
  the exact changed paths, the exact tests run and their counts, and the
  final clean status, as that lane's task requires — and never invent or
  claim an automation-label transition.
