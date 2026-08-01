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
2. GitHub applies `ai:implement`.
3. A Claude Code Routine triggered by that label implements the locked slice,
   creates a real commit, changes the state to `ai:testing`, and pushes.
4. `AI Subscription Test Gate` rejects forbidden paths, missing required
   files, failed tests, and zero discovered tests. On success it applies
   `ai:awaiting-codex` and comments `@codex review`.
5. Codex reviews through the ChatGPT GitHub integration.
6. This repository's label workflow accepts only a current-head review from
   the official Codex App and changes the state to `ai:codex-reviewed`.
7. The Claude Routine handles that label. It either fixes verified findings
   and returns the pushed head to `ai:testing`, or applies
   `ai:ready-for-human` and stops.

## One-time account setup

### Claude

1. Open Claude Code Routines and create a cloud Routine for
   `os-creator1/os-ai`.
2. Use the normal subscription model. Do not enable usage credits/overage.
3. Paste the fenced prompt from `CLAUDE-ROUTINE-PROMPT.md` as its saved prompt.
4. Install/authorize the Claude GitHub App for this repository when prompted.
5. Add two GitHub triggers to the same Routine:
   - event `pull_request.labeled`, filter Labels includes `ai:implement`;
   - event `pull_request.labeled`, filter Labels includes
     `ai:codex-reviewed`.
6. In Claude Settings -> Usage, leave usage credits/overage disabled. When the
   Pro allowance is exhausted, a run must stop rather than bill extra.

### Codex

1. Set up Codex cloud for `os-creator1/os-ai`.
2. Enable Code review for the repository. Automatic review is optional because
   the trusted GitHub test gate explicitly posts `@codex review` after every
   proven pushed head.
3. Keep the repository's `AGENTS.md`; it supplies the review invariants.

### GitHub

1. Merge the automation setup into `main` before starting the loop; review
   events use the workflow from the default branch.
2. Update PR #2 from `main` so its branch contains the state and agent guidance.
3. Run Actions -> AI Subscription Loop -> Run workflow with PR `2` and command
   `start`. The workflow writes a trusted pre-work SHA lease before it applies
   the Claude trigger label.
4. After confirming both former workflows are disabled, remove the old
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

- Claude Routine work draws down the existing Claude subscription allowance.
- Codex GitHub review draws down the existing ChatGPT/Codex allowance.
- Standard GitHub-hosted runners are free while this repository is public.
- With Claude usage credits disabled and no model API keys referenced by active
  workflows, reaching a plan limit stops work instead of creating an API bill.

## Deterministic gate

The gate is triggered only by a new commit on the controlled PR or a manual
rerun. It reads its state and scripts from `main`, not from the untrusted PR
head. It requires a trusted lease, a different pushed SHA, the exact configured
base/head/repository identities, allowed changed paths, required new files, and
positive test counts from every locked command. A real failure applies
`ai:needs-human`; a stale queued run changes nothing.
