# Autonomy Phase 2 Contract

## Locked automatic-start control-plane contract

This bootstrap contract removes the manual **Run workflow → start** bridge after
a human approves and merges a bounded implementation contract. It does not
authorize automatic contract creation, automatic review approval, product work,
or automatic merging.

### Authorized behavior

After this control plane is merged, a future same-repository pull request may
start its named implementation target automatically only when all of the
following are true:

1. The event is a human-merged pull request targeting the repository's default
   branch.
2. The merged pull request changes exactly two files:
   `docs/automation/AI-AUTONOMY-STATE.json` and the Markdown file named by that
   state's `contract_source`.
3. The state retains `merge_policy: human_only` and
   `advance_automatically: false`.
4. The state explicitly sets
   `start_automatically_after_contract_merge: true`,
   `implementation_authorized: true`, and
   `status: ready_to_implement_after_human_contract_merge`.
5. The state pins a distinct, same-repository, open draft implementation pull
   request by number, base branch, head branch, and full starting SHA.
6. The target is not paused, failed, testing, awaiting review, under correction,
   or already implementing.

Only then may the workflow call the existing trusted controller to write the
progress lease, replace stale terminal labels with `ai:implement`, and fire the
saved Claude subscription Routine.

### Fail-closed behavior

- Unrelated merges and state changes with automatic start disabled are ignored.
- A malformed start-authorizing state, extra changed file, forked contract,
  stale target SHA, non-draft target, identity mismatch, or active/blocked label
  fails before any lease, label, or Routine call.
- If the label transition succeeds but the Routine call fails, the workflow
  removes active labels, applies `ai:needs-human`, posts bounded evidence, and
  stops.
- The workflow checks out only trusted default-branch automation and never the
  merged contract's head.

### Authorized implementation paths

- `.github/scripts/ai_subscription_autostart.js`
- `.github/scripts/ai_subscription_gate.js`
- `.github/scripts/ai_subscription_labels.js`
- `.github/workflows/ai-subscription-autostart.yml`
- `docs/automation/AI-AUTONOMY-STATE.json`
- `docs/automation/AUTONOMY-PHASE-2-CONTRACT.md`

### Required verification

- `node .github/scripts/ai_subscription_autostart.js --selftest`
- `node .github/scripts/ai_subscription_gate.js --selftest`
- `node .github/scripts/ai_subscription_labels.js --selftest`
- `node .github/scripts/fire_claude_routine.js --selftest`
- JavaScript syntax checks for every changed script
- YAML parsing for the new workflow
- exact changed-file enforcement against the six paths above

### Explicit exclusions

- No RFC-003 Milestone 4 product implementation
- No automatic generation or approval of a later contract
- No automatic merge, force-push, push to `main`, or tag
- No `OPENAI_API_KEY`, `ANTHROPIC_API_KEY`, metered model API, or usage-credit
  enablement
- No database, environment, dependency, application, route, migration, or test
  change
- No weakening of the deterministic positive-test gate, current-head Codex
  trust checks, correction limit, or human merge decision

### Activation result

Once this bootstrap PR is human-merged, the controller is ready but inert. A
separate Milestone 4 contract must create or identify its draft implementation
PR and merge the exact two-file state/contract authorization described above.
That merge will be the first automatic implementation start; the user will no
longer need to dispatch the subscription-loop workflow manually.
