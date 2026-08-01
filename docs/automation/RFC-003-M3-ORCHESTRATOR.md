# RFC-003 Milestone 3 -- Bounded AI Orchestration Pilot

This document describes `.github/workflows/ai-orchestrator-pilot.yml` and its
helper script `.github/scripts/openai_orchestrator.py`: a **manually
triggered, bounded, one-slice pilot** that uses OpenAI to plan and review a
single Slice 3B implementation, and Claude Code to write it, on the existing
PR #2 branch (`feature/rfc-003-m3`).

It is not a general-purpose autonomous loop. It runs once per dispatch,
touches exactly one slice, makes at most three OpenAI calls and at most two
Claude Code invocations, and always stops for a human rather than guessing
when something is ambiguous, unverifiable, or out of scope.

## What it does, in order

1. Validates its own script (syntax check + a mocked-input self-test) before
   trusting it to make any decision.
2. Resolves PR #2 via `gh pr view` and refuses to proceed unless the PR is
   open, based on `main`, and not from a fork.
3. Checks out the PR's actual head branch, verifies the test database is
   exactly `ultimatesms_testing`, installs PHP/Composer dependencies, and
   proves the runner can execute a real focused test
   (`WorkspaceRepositoryTest`) before doing anything else.
4. **OpenAI plan call** -- turns the locked Slice 3B contract (below) into a
   concrete, bounded implementation instruction, or returns `NEEDS_HUMAN` if
   the contract is ambiguous.
5. **Claude implementation** -- one bounded Claude Code run (max 30 turns)
   that implements exactly that instruction on the PR branch, runs focused
   tests, commits, and pushes.
6. A scope guard checks the changed-file list for anything under
   `.github/`, `.env*`, `phpunit.xml`, or `config/database.php` and stops the
   pilot immediately if any of those were touched.
7. Focused tests run again against whatever Claude actually produced.
8. **OpenAI review call** -- evaluates the diff and test results against
   RFC-003, the Slice 3B contract, and the safety boundaries listed below.
   Returns `READY`, `FIX`, or `NEEDS_HUMAN`.
9. If `FIX`: one more bounded Claude Code run applying only the exact
   findings, then tests re-run, then an **OpenAI final review call**
   (`READY` or `NEEDS_HUMAN` only -- no second correction round).
10. Posts one `[AI-ORCHESTRATOR-PILOT]` comment on the PR reporting exactly
    what happened, then stops. It never merges, closes the PR, or pushes to
    `main`.

## The locked Slice 3B contract

The planning-stage OpenAI call is given this contract verbatim and is told
not to invent scope beyond it (see `SLICE_3B_CONTRACT` in
`openai_orchestrator.py`):

- add read-only Workspace overview
- add read-only Workspace membership list
- membership list audience is Workspace owner plus active Admin only
- Staff cannot access the membership list
- direct Business ownership alone grants no Workspace-level surface
- active memberships only are listed
- display member name, role, scope, and selected-Business assignment count
- do not display email, numeric IDs, inactive rows, or selected-Business names
- inactive Workspace remains addressable to owner/active members
- inactive Workspace Business access remains blocked
- inaccessible and unknown Workspace uid both return 404
- GET only
- no mutation forms, buttons, routes, or actions
- no Slice 3C Business-list or navigation work

## How to run it

From a repository collaborator's GitHub UI: **Actions -> AI Orchestrator
Pilot (RFC-003 M3) -> Run workflow**, choose the branch that has this
workflow file, and (optionally) override `pr_number` (default `"2"`) or
`slice` (default `"RFC-003 Milestone 3 Slice 3B"`).

Or with `gh` (from a machine that already has `gh` authenticated -- this
project's own guardrail is that the pilot's own actions never install or
require a PAT, not that operators can't use their own `gh` login):

```bash
gh workflow run ai-orchestrator-pilot.yml --ref chore/ai-orchestrator-pilot \
  -f pr_number=2 -f slice="RFC-003 Milestone 3 Slice 3B"
```

It cannot be triggered by a PR comment, by `@claude`, or by any push -- only
`workflow_dispatch`.

## How to stop it

- **While running:** cancel the run from the Actions tab (Actions -> the
  running "AI Orchestrator Pilot" run -> Cancel workflow). `contents: write`
  means an in-flight Claude step could have an uncommitted or half-pushed
  change at the moment of cancellation -- check `git log` on the PR branch
  afterward.
- **Before it starts a second time on the same PR:** `cancel-in-progress` is
  `false`, so a second dispatch for the same `pr_number` queues rather than
  running concurrently; cancel the queued run from the Actions tab if you
  don't want it to start.
- **Permanently, for now:** see "How to disable it" below.

## Exact limits

| Limit | Value |
|---|---|
| Trigger | `workflow_dispatch` only |
| Job timeout | 120 minutes |
| Concurrency | one run per `pr_number`, new runs queue (`cancel-in-progress: false`) |
| OpenAI calls | max 3 (plan, review, final review) -- one per workflow step, structurally impossible to exceed |
| Claude Code invocations | max 2 (implementation, one optional correction) -- gated by `if:` conditions on the prior OpenAI decision |
| Claude max turns | 30 per invocation |
| OpenAI model | `gpt-5.6-luna` |
| OpenAI reasoning effort | low (plan), medium (review, final review) |
| OpenAI max output tokens | ~2500 per call |
| Test database | `ultimatesms_testing` only, verified before any test runs and refused otherwise |
| Merge / close PR / push main / force-push / edit workflow files | never (see Safety boundaries) |

## Safety boundaries and how each is enforced

| Boundary | Enforcement mechanism |
|---|---|
| Never merge | The workflow never calls `gh pr merge`; Claude's `--disallowedTools` blocks `gh pr merge:*` |
| Never close the PR | The workflow never calls `gh pr close`; nothing in Claude's tool grant can close a PR |
| Never push to main | Claude's `--disallowedTools` blocks `git push origin main`, `git push *main`, `git checkout main`; the workflow itself never pushes anywhere |
| Never force-push | Blocked in `--disallowedTools` |
| Never modify workflow files from inside the pilot | Claude's system prompt instructs this; **structurally enforced** by the post-implementation/post-correction scope guard (`check-scope`), which fails the run if any changed file starts with `.github/`, `.env`, `.pilot-tooling/`, or equals `phpunit.xml` / `config/database.php` -- this is the compensating control for the fact that the action's tool-allowlist syntax cannot itself restrict `Edit`/`Write` by path |
| Test database must be `ultimatesms_testing` | A dedicated step greps `.env.testing` for exactly `DB_DATABASE=ultimatesms_testing` and refuses to proceed otherwise, before Composer install or any test run |
| No PAT | Only `secrets.GITHUB_TOKEN` (the run's own auto-issued token) is used, via `gh`'s default auth and `actions/checkout`'s default credential persistence |
| Untrusted PR text can't become shell syntax | PR title/body are written straight to files by `jq` and only ever consumed as **file content** by the Python script's `--pr-title-file`/`--pr-body-file` args -- never interpolated into a `${{ }}` expression inside a `run:` block |
| OpenAI-generated text is data, not executable | `claude_prompt` is written to a file, exported to `$GITHUB_ENV` with a random per-run delimiter, and passed to `claude-code-action` via its dedicated `prompt` input -- never concatenated into the `claude_args` CLI-flags string, so it cannot inject extra flags into the Claude CLI invocation the way string concatenation would allow |
| Required test commands can't be arbitrary shell | `run-tests` parses each command with `shlex.split` and only executes it via `subprocess.run(argv, shell=False)` if every token matches a strict allow-list (`php artisan test`/`vendor/bin/phpunit` plus `--filter=<identifier>` or a `tests/Feature|Unit/...` path only) -- anything else is rejected, not executed |
| Scope creep beyond Slice 3B | `claude_prompt` is scanned against a fail-closed keyword guard (merge, push-to-main, production DB, workflow/env edits, Slice 3C navigation/Business-list language) before it is ever handed to Claude; any hit forces the decision to `NEEDS_HUMAN` |

**Known gap, documented rather than hidden:** the Claude Code action's
`allowedTools`/`disallowedTools` syntax scopes `Bash` commands by prefix but
does not scope `Edit`/`Write` by file path. The system prompt tells Claude
never to touch `.github/workflows/`, `.pilot-tooling/`, or `.env*`, but that
is an instruction, not a hard constraint on the tool. The scope guard is the
real enforcement: it inspects what was *actually* changed after each Claude
run and stops the pilot before any further stage (including the review call)
if a forbidden path was touched. Because the pilot never merges anything, a
human still reviews the PR before this could reach `main` even in the worst
case.

## Expected cost range

Rough order of magnitude for one full run (plan -> implement -> review ->
`READY`, no correction needed):

- **OpenAI:** 2 calls (plan at low effort, review at medium effort) against
  a context of roughly CLAUDE.md + RFC-003 + diff + test output, capped at
  2500 output tokens each. Low-hundreds-of-thousands of input tokens across
  both calls at worst, well under $1 at `gpt-5.6-luna` pricing as of this
  writing -- **check current OpenAI pricing before relying on this
  estimate**, it is not re-verified by the workflow itself.
- **Claude:** one bounded run at up to 30 turns, `claude-sonnet-5`, on a
  small read-only feature slice (a controller action, a view, a route, and
  tests). Typically well under the turn cap for a slice this size.
- **Worst case** (a correction round is needed): roughly double both of the
  above -- 3 OpenAI calls, 2 Claude runs.

There is no dollar cap enforced by this workflow. The call/turn limits above
are the real spending guardrail -- see "OpenAI budgets are not the
enforcement mechanism" below.

## Secret names

- `secrets.ANTHROPIC_API_KEY` -- already existed in the repository, reused
  as-is.
- `secrets.OPENAI_API_KEY` -- added for this pilot.
- `secrets.GITHUB_TOKEN` -- the repository's own auto-issued Actions token,
  not a PAT. Used for `gh pr view`, `gh pr comment`, and (via
  `actions/checkout`'s default credential persistence) the `git push` that
  Claude performs.

No secret value is ever printed, echoed, or written to a file by this
workflow or script. `openai_orchestrator.py` reads `OPENAI_API_KEY` from the
environment only (never as a CLI argument) and redacts it from any error
text before that text is logged.

## Failure states

The pilot fails closed -- meaning it stops and reports rather than guessing
-- in each of these cases:

- The PR cannot be resolved, is closed/merged, is based on something other
  than `main`, or is from a fork.
- `.env.testing` is missing, or does not declare exactly
  `DB_DATABASE=ultimatesms_testing`, or references a production-looking
  database name.
- Composer/PHP setup or migration fails.
- An OpenAI response fails schema validation, is unparsable, or the model
  refuses -- the stage reports `NEEDS_HUMAN` with the validation error as a
  blocking question.
- A `claude_prompt` trips the scope guard (references a merge, a push to
  main, production data, a workflow/env-file edit, or Slice 3C work) --
  forced to `NEEDS_HUMAN` before Claude ever sees it.
- Claude's implementation touches a forbidden path (`.github/`, `.env*`,
  `.pilot-tooling/`, `phpunit.xml`, `config/database.php`) -- the scope
  guard stops the run before the review stage.
- A required test command doesn't match the safe allow-list, or a focused
  test fails -- surfaced to the review stage as evidence; a second
  unresolved failure after the one permitted correction round means
  `NEEDS_HUMAN`, not a second attempt.
- More than one correction round would be needed -- structurally
  impossible, since `final_review` can only return `READY` or `NEEDS_HUMAN`.

Every one of these ends in the PR comment reporting `NEEDS_HUMAN` or
`FAILED` rather than the pilot silently stopping with no record.

## How to disable it

Manual-dispatch-only workflows carry no ongoing risk of unexpected runs, but
to disable it entirely:

```bash
gh workflow disable ai-orchestrator-pilot.yml
```

or delete/rename `.github/workflows/ai-orchestrator-pilot.yml` in a follow-up
PR. There is no cron, webhook, or comment trigger to also remove -- disabling
or deleting the workflow file is sufficient.

## How to inspect API token usage

- **OpenAI:** the final PR comment reports summed `input_tokens` /
  `output_tokens` across all OpenAI calls in the run, taken directly from
  each API response's `usage` field. For authoritative, itemized usage and
  spend, check the OpenAI platform dashboard (Usage) for the project holding
  `OPENAI_API_KEY`.
- **Claude:** `claude_args` sets `display_report: true`, so
  `claude-code-action` writes a cost/usage report to that step's GitHub
  Actions Job Summary. The PR comment links the run; open the run and check
  the job summary for the Claude steps for itemized cost, since the action
  does not currently expose cost as a plain step output this workflow can
  read directly.

## OpenAI budgets are not the only enforcement mechanism

If the OpenAI project behind `OPENAI_API_KEY` has a monthly budget or spend
cap configured, treat that as a backstop, not the primary control. A budget
cap only stops spending after it's exhausted, mid-run, potentially leaving
the pilot in a partially-completed state with no clean stop. **The
workflow's own call/turn limits -- 3 OpenAI calls, 2 Claude invocations, 30
turns per Claude invocation, one correction round, one PR -- are the primary
guardrail**, because they bound the pilot's blast radius before any spend
occurs, deterministically, on every single run, regardless of what budget
configuration exists on the OpenAI side at the time.

## Setup validation performed before this pilot's first commit

- YAML parsed successfully (`js-yaml`, since no Python interpreter was
  available in the environment used to build this pilot -- **run
  `python3 -m py_compile .github/scripts/openai_orchestrator.py` yourself
  before the first real dispatch** as an extra check; the workflow also
  does this automatically as its first real step).
- `python3 .github/scripts/openai_orchestrator.py selftest` exercises schema
  validation, the scope guard, and the test-command allow-list against fixed
  mocked input, with no network access and no `OPENAI_API_KEY` required.
  Run automatically as the workflow's second step, on every dispatch.
- Confirmed `workflow_dispatch` is the workflow's only trigger.
- Confirmed no application, test, or domain file changed in the branch that
  added this pilot -- only the three files listed in the PR (this doc, the
  workflow, and the script).
- Confirmed no secret values appear in the diff (the MySQL credentials in
  the workflow's `services:` block are the repository's own already-public,
  non-secret `.env.testing` placeholder values, not real credentials).
- Reviewed workflow permissions (narrowest set that supports the flow, no
  `deployments`/`packages`/`security-events`/environments) and every place
  untrusted text (PR title/body, OpenAI output) touches a shell or an
  action input -- see the Safety boundaries table above.
