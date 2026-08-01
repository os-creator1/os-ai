# Subscription-only AI development loop

## Purpose

This replaces the paid OpenAI -> Claude -> OpenAI GitHub Actions pilot with a
GitHub label loop that uses the owner's existing Claude Pro and ChatGPT Plus
allowances. No active repository workflow receives `OPENAI_API_KEY` or
`ANTHROPIC_API_KEY`.

The first rollout remains human-merge-only. Claude implements, a free GitHub
Actions job proves scope and focused tests against disposable MySQL, Codex
reviews, Claude applies at most two bounded corrections, and the loop stops at
`ai:ready-for-human`.

## Flow

1. Dispatch `AI Subscription Loop` with command `start` for the active PR.
2. GitHub writes a trusted progress lease, applies `ai:implement`, and
   calls the saved Claude Routine's official subscription-backed API trigger.
3. Claude implements the locked slice, creates a real commit, changes the
   state to `ai:testing`, and pushes.
4. Codex auto-review starts from that push. In parallel,
   `AI Subscription Test Gate` rejects forbidden paths, missing required
   files, failed tests, and zero discovered tests.
5. After tests pass, the gate applies `ai:awaiting-codex` and waits for a
   current-head review or clean-result comment from the exact official Codex
   App identity.
6. The gate writes a correction lease, applies `ai:codex-reviewed`, and
   calls the same Claude Routine API trigger.
7. Claude either fixes verified findings and returns the pushed head to
   `ai:testing`, or applies `ai:ready-for-human` and stops.

## One-time account setup

### Claude

1. Open Claude Code Routines and create a cloud Routine for
   `os-creator1/os-ai`.
2. Use the normal subscription model. Do not enable usage credits/overage.
3. Paste the fenced prompt from `CLAUDE-ROUTINE-PROMPT.md` as its saved prompt.
4. Install/authorize the Claude GitHub App for this repository when prompted.
5. Edit the Routine, choose **Add another trigger -> API**, copy its official
   `/fire` URL, generate the trigger-only bearer token, and store both as
   repository secrets named `CLAUDE_ROUTINE_URL` and
   `CLAUDE_ROUTINE_TOKEN`. Never paste the token into a PR or workflow file.
6. The old GitHub label trigger may be removed; the trusted workflows now call
   the Routine only after writing the required lease and state label.
7. In Claude Settings -> Usage, leave usage credits/overage disabled. When the
   Pro allowance is exhausted, a run must stop rather than bill extra.

### Codex

1. Set up Codex cloud for `os-creator1/os-ai`.
2. Enable **Auto review** for this repository with **Review trigger: On every
   push**. Leave **Enable credits use** off.
3. Keep the repository's `AGENTS.md`; it supplies the review invariants.
   The trusted gate does not act on the Codex result until deterministic tests
   pass and the reviewed commit matches the current PR head.

### GitHub

1. Merge the automation setup into `main` before starting the loop; review
   events use the workflow from the default branch.
2. Add the two Claude Routine secrets described above. They are trigger-only
   subscription credentials, not metered Anthropic API keys.
3. Update PR #2 from `main` so its branch contains the state and agent guidance.
4. Run Actions -> AI Subscription Loop -> Run workflow with PR `2` and command
   `start`. The workflow writes a trusted pre-work SHA lease before it calls
   the Routine.
5. After confirming both former workflows are disabled, remove the old
   `OPENAI_API_KEY` and `ANTHROPIC_API_KEY` Actions secrets if no unrelated
   workflow uses them.

The default trusted Codex identities are `chatgpt-codex-connector` and
`chatgpt-codex-connector[bot]`. If OpenAI changes the official App login, set a
repository variable named `CODEX_REVIEWER_LOGIN` to the exact new login (or a
comma-separated exact allow-list). Never use substring matching.

## Operator controls

- `start` or `resume`: clear other managed states and apply `ai:implement`.
- `ai:testing`: do not intervene; GitHub is checking the exact pushed head.
- `pause`: clear active/terminal managed states and apply `ai:paused`.
- `ai:needs-human`: inspect the evidence comment; do not resume until the
  contract or environment issue is explicitly resolved.
- `ai:ready-for-human`: inspect the final diff/checks and make the merge choice.

## Cost boundary

- Claude Routine work, including official API-triggered runs, draws down the
  existing Claude subscription allowance; the trigger does not use a metered
  Anthropic API key.
- Codex GitHub review draws down the existing ChatGPT/Codex allowance.
- Standard GitHub-hosted runners are free while this repository is public.
- With Claude usage credits disabled and no model API keys referenced by active
  workflows, reaching a plan limit stops work instead of creating an API bill.

## Deterministic gate

The gate is triggered only by a new commit on the controlled PR or a manual
rerun. It reads its state and scripts from `main`, not from the untrusted PR
head. It requires a trusted lease, a different pushed SHA, the exact configured
base/head/repository identities, allowed changed paths, required new files, and
positive test counts from every locked command. A real failure, a missing trusted Codex result, or a failed Routine trigger
applies `ai:needs-human`; a stale queued run changes nothing.

