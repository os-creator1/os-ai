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
handoff. All non-negotiable rules below apply to both paths equally.

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

- **Branch-only development.** Work exclusively on the pull request branch
  named in the automation state. Never push directly to `main` or `master`.
- **Never merge pull requests.** A human makes the merge decision.
- **No metered model credentials.** Do not call OpenAI, Anthropic Console, or
  another paid model API from repository workflows or scripts.
- **Use only `ultimatesms_testing`.** Do not improvise a database inside a
  Routine. GitHub Actions is the authoritative disposable MySQL test gate.
- **Never touch production-looking data or secrets.** Stop with
  `ai:needs-human` if a task appears to require either.
- **Do not claim unverified tests.** GitHub Actions runs the locked focused
  commands after each pushed implementation or correction.
- **Reject zero-test success.** A test command must report a positive test
  count; `No tests found` is a failure even when the command exits zero.
- **Require real progress.** A requested implementation/correction must create
  and push a commit. An unchanged branch head is a failed run.
- **Report exact evidence.** Every completion comment states the starting and
  final SHA, exact changed files, exact test counts, and label transition.
