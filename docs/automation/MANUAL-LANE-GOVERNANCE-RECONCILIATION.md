# MANUAL-LANE GOVERNANCE RECONCILIATION

**Status:** Governance-only correction. Three paths, all documentation or
governance metadata. No product, test, migration, configuration, workflow or
automation-code change. The merged PR #229 implementation is untouched.

**Base:** `b54441ed53540f085114946715985db1c565b6a7` — the PR #229 merge
commit, `origin/main` at the time this branch was cut.

---

## 1. What this corrects, and what it does not

GitHub's automated review of PR #229 raised two inconsistencies. Both were
real, and both were about **repository governance**, not about the merged
work. This document records them and the correction.

**Neither comment was meaningless, and neither identified a defect in the
Lane E implementation.** PR #229 passed independent review on its own terms:
exactly eleven allowlisted paths, one commit ahead and zero behind, no
generated or production paths, the guarded runners requiring the explicit
database handoff and delegating validation to `Tests\Support\TestDatabaseSafety`,
and that shared authority itself unchanged. What the comments found is that
**`AGENTS.md` did not describe the workflow that produced it.** A reviewer
following `AGENTS.md` literally would have flagged compliant work as
non-compliant — which is exactly what happened.

### Comment 1 — the paused state file versus a human-authorized lane

`AGENTS.md` told reviewers to *"Enforce that file's allowed paths, required
tests, and locked slice contract"* for automation-managed work, while
`docs/automation/AI-AUTONOMY-STATE.json` carried
`implementation_authorized: false`, `allowed_paths: []` and
`gate_label: "ai:paused"`.

Read together and applied to everything, that says no work of any kind may
proceed. But the same state file's `forbidden_scope` closes with:

> "Any future work requires separate, explicit human authorization"

Lane E **had** that authorization: the human issued a concrete task with its
own exact scope, allowlist, branch, worktree and verification requirements.
The gap was that `AGENTS.md` never said how a per-task human authorization
relates to the paused autonomous state file, so the two readings could not be
reconciled from the repository alone. Lane E disclosed the ambiguity in
`USAGE-SUBPROCESS-DATABASE-SAFETY-COMPLETION.md` §1 rather than resolving it,
because resolving it was outside that lane's own allowlist. This document
resolves it.

An adjacent nuance the existing documents already had, and which was not
enough on its own: `docs/automation/AI-SUBSCRIPTION-LOOP.md` defines a
"Manual completion path", but that path is explicitly a manual way to
complete **the locked current slice**, still bounded by the state file's own
`allowed_paths`. A manually authorized lane whose scope comes from its own
task contract — Lane E's shape — is a different thing, and was undocumented.

### Comment 2 — canonical-only database wording

`AGENTS.md` said *"Run only against the disposable `ultimatesms_testing`
database."* That wording predates PR #228, which introduced
`Tests\Support\TestDatabaseSafety` precisely so that a lane could use a
**validated disposable sibling** instead. That helper's own docblock records
why: two lanes sharing the canonical database raced each other in practice —
one lane's `DROP DATABASE` / `CREATE DATABASE` collided with another lane's
live migration, and both then trusted results the other was mutating.

Lane E used `ultimatesms_testing_lane_e`, which the merged helper validates
as a safe sibling, and never reset or wrote the canonical database. That was
both authorized and safer. `AGENTS.md` had simply not been updated to match
the behaviour already merged.

---

## 2. The distinction now recorded

`AGENTS.md` now opens its review rules by making a reviewer choose which of
two routes is in front of them, because they draw scope from different
places.

### A. Autonomous state-loop work

* Governed by `docs/automation/AI-AUTONOMY-STATE.json`.
* A reviewer enforces that file's `allowed_paths`, `required_test_commands`,
  `expected_head_sha` and locked slice contract.
* **It remains paused.** `implementation_authorized: false`,
  `gate_label: "ai:paused"`, `advance_automatically: false`,
  `start_automatically_after_contract_merge: false`, `allowed_paths: []`.
* It has **no standing authority** to start work, select a next slice, or
  merge.

### B. Explicitly human-authorized manual lanes

* The human gives one concrete task through the ChatGPT→Claude review-gated
  workflow.
* **Scope comes from that task's own contract** — its own exact allowlist,
  branch, worktree and verification requirements — not from the state file.
* The state file's `allowed_paths: []` and `implementation_authorized: false`
  describe the **autonomous** contract. They are not a repository-wide ban,
  and they do not retroactively forbid a lane the human authorized directly.
* Claude commits and pushes. **Claude does not open the PR.** ChatGPT reviews
  the pushed branch independently and opens it. The human alone merges.
* A manual lane does not activate, resume or bypass the autonomous loop.
* **Authorization is per task and does not persist.** Finishing one lane
  grants no authority to start another, to widen scope, or to invent work.

Neither route may merge, force-push, push to `main`, or bypass required
tests. `merge_policy` stays `human_only` for both.

**What was deliberately not created:** no broad or perpetual implementation
authority, no standing permission for Claude to invent or start a task, no
change of the autonomous state to active, and no change to `merge_policy`.

---

## 3. The database-policy correction

`AGENTS.md`'s Verification section now permits exactly two things and nothing
else:

* the canonical disposable database `ultimatesms_testing`; or
* a clearly derived disposable sibling that `Tests\Support\TestDatabaseSafety`
  accepts, such as `ultimatesms_testing_lane_e`.

with these requirements:

| Requirement | Recorded in `AGENTS.md` |
|---|---|
| Names must fail closed through `TestDatabaseSafety` before the first write | yes |
| Production-looking names refused (`prod`, `production`, `live`, `staging`, `backup`, `master`, `main`, `real` as suffix segments) | yes |
| Merely containing "test" is never enough — `acme_test_live` is refused | yes |
| Concurrent database-writing lanes must use **distinct** validated siblings | yes |
| No destructive parallel lane may share a database | yes |
| A task may **explicitly require** the canonical database for exact canonical-baseline reproduction | yes |
| Reports must state the exact database used | yes |

This restates no policy of its own: `TestDatabaseSafety` remains the single
authority, and it is **unchanged by this branch**. The pin that genuinely
still requires the canonical database —
`tests/Feature/Workspace/Support/TemporaryTestDatabase.php` — is named in
`AGENTS.md` so the exception is concrete rather than theoretical.

---

## 4. State-file consumer audit

Every reference to `docs/automation/AI-AUTONOMY-STATE.json` in the repository
was located mechanically (excluding `vendor/` and `node_modules/`): **86
files — 2 executable scripts, 4 workflows, 80 documents.**

### The executable consumers, and every field each reads

| Consumer | Fields read | Method |
|---|---|---|
| `.github/scripts/ai_subscription_autostart.js` | `repository`, `base_branch`, `merge_policy`, `advance_automatically`, `implementation_authorized`, `status`, `active_pull_request`, `expected_head_sha`, `contract_source`, `head_branch` | `JSON.parse(fs.readFileSync(...))` |
| `.github/scripts/ai_subscription_labels.js` | `implementation_authorized`, `base_branch`, `head_branch`, `expected_head_sha` | `JSON.parse(fs.readFileSync(...))` |
| `.github/scripts/ai_subscription_gate.js` | via `--state`; `loadState()` validates a fixed field list | `JSON.parse` |
| `.github/workflows/ai-subscription-autostart.yml` | passes the path to the scripts above | — |
| `.github/workflows/ai-subscription-gate.yml` | `repository`, `active_pull_request` | `JSON.parse` |
| `.github/workflows/ai-subscription-watchdog.yml` | `repository`, `active_pull_request` | `JSON.parse` |
| `.github/workflows/rfc-003-m3-aggregate-regression.yml` | `repository`, `active_pull_request` | `JSON.parse` |

**No consumer enumerates keys.** There is no `Object.keys` walk, no
`additionalProperties: false`, no allow-list of permitted keys, and no schema
validator anywhere in the control plane. Every one reads named fields from a
parsed object, so an added key is inert by construction.

### Proof the clarification is compatible

The added `governance_scope` object is **documentation only**. Mechanically
verified:

```
keys added  : ["governance_scope"]
keys removed: []
DEEP-EQUAL after removing the added key: PASS
All 10 consumed fields unchanged: PASS
```

Re-running each consumer's own decision function against the file before and
after gives identical answers:

| Decision | before | after |
|---|---|---|
| `ai_subscription_autostart.js` refuses to auto-start | **true** | **true** |
| `ai_subscription_labels.js` authorizes `start` | false | false |
| `ai_subscription_labels.js` authorizes `resume` | false | false |
| `ai_subscription_labels.js` authorizes `pause` | true | true |
| `ai_subscription_labels.js` authorizes `review-submitted` | true | true |

The control plane's own self-tests, exactly as
`ai-subscription-autostart.yml` runs them, all pass against the modified
file:

| Self-test | Result |
|---|---|
| `ai_subscription_autostart.js --selftest` | PASS |
| `ai_subscription_labels.js --selftest` | PASS |
| `ai_subscription_gate.js --selftest` | PASS |
| `fire_claude_routine.js --selftest` | PASS |

**One pre-existing condition, reported rather than hidden.**
`ai_subscription_gate.js validate-state` fails on this file with *"State
field `allowed_paths` must be a non-empty array."* It fails **identically on
the unmodified file from `b54441e`** — this branch neither caused nor changed
it. It is a direct consequence of the loop being paused: `loadState()`
requires `allowed_paths`, `required_new_paths` and `required_test_commands`
to be non-empty, and a paused contract has all three empty. That command is
only invoked by the autostart workflow, which runs when a start-authorizing
contract is merged — that is, when the loop is active and those arrays are
populated. It is not reachable while paused. **No change was made to fix it**,
because doing so would require editing either automation code or the paused
contract's own fields, both outside this branch's allowlist and neither
needed for this correction.

*(`ai_subscription_gate.js --selftest` also fails on a Windows host when
`RUNNER_TEMP` is unset, because it falls back to `/tmp`. With `RUNNER_TEMP`
set, as GitHub Actions always does, it passes. Environmental, not
repository-related.)*

---

## 5. JSON validation

Parsed with two mechanically independent implementations:

| Parser | Result |
|---|---|
| Node.js `JSON.parse` — the same runtime every consumer uses | parsed OK, 32 top-level keys |
| PHP `json_decode(..., JSON_THROW_ON_ERROR)` — an unrelated implementation | parsed OK, 32 top-level keys, `json_last_error` = "No error" |

Both agree on every governance-critical value:

| Field | Value |
|---|---|
| `implementation_authorized` | `false` |
| `merge_policy` | `"human_only"` |
| `gate_label` | `"ai:paused"` |
| `advance_automatically` | `false` |
| `start_automatically_after_contract_merge` | `false` |
| `allowed_paths` | `[]` |
| `expected_head_sha` | `"2132c2dde528bf2d9e56989d4e400da6f50f8337"` |
| `status` | `"rfc_005_complete_tagged"` |
| `active_pull_request` | `null` |

---

## 6. Contradiction search

| Searched for | Result |
|---|---|
| tests may run "only" against `ultimatesms_testing` | The only remaining match in `AGENTS.md` is the corrected sentence, which now reads "only against a **disposable** database" and then names both permitted forms. One residual match in `CLAUDE.md` — see below |
| all manually authorized work is forbidden | none |
| `implementation_authorized` applied indiscriminately to manual lanes | none — both `AGENTS.md` matches now scope it explicitly to the autonomous loop |
| derived disposable test databases forbidden | none |

**One residual item, deliberately left alone.** `CLAUDE.md` still carries
*"**Use only `ultimatesms_testing`.** Do not improvise a database inside a
Routine."* Read in full, that sentence is already scoped to the autonomous
**Routine**, which uses the GitHub Actions gate rather than a local database,
so it does not actually contradict the corrected `AGENTS.md` policy for
manual lanes. Its bold lead-in is nevertheless ambiguous when quoted alone.
`CLAUDE.md` is **outside this branch's three-path allowlist**, so it was not
edited. It is recorded here so a future authorized task can align the wording
deliberately rather than a lane widening its own scope to do it silently.

---

## 7. Scope

Exactly three paths:

1. `AGENTS.md` — the two corrected sections.
2. `docs/automation/AI-AUTONOMY-STATE.json` — one added documentation-only
   `governance_scope` object. No existing key or value changed.
3. `docs/automation/MANUAL-LANE-GOVERNANCE-RECONCILIATION.md` — this file,
   newly added.

Zero changes to `app/`, `tests/`, `database/`, `routes/`, `config/`,
`resources/`, `public/`, `bootstrap/cache/`, `vendor/`, `node_modules/`,
dependency files, workflows, automation code, `.env`, `.env.testing`,
`CLAUDE.md`, or any previously merged remediation document.
`tests/Support/TestDatabaseSafety.php` is unchanged, and all eleven paths
merged by PR #229 are unchanged.

No PHP regression was run. This correction changes no executable behaviour,
and repeating PR #229's evidence would prove nothing new.
