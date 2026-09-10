# MANUAL-LANE GOVERNANCE RECONCILIATION

**Status:** Governance-only correction. Four paths, all documentation or
governance metadata. No product, test, migration, configuration, workflow or
automation-code change. The merged PR #229 implementation is untouched.

The contradictions are removed **in the source documents themselves**. An
earlier revision of this branch added a clarifying object to the state file
while leaving its absolute sentences in place; that was not enough, because a
later clarification does not make an earlier absolute sentence consistent.
Those sentences are now edited directly.

**Base:** `b54441ed53540f085114946715985db1c565b6a7` — the PR #229 merge
commit, `origin/main` at the time this branch was cut.

---

## 1. What this corrects, and what it does not

GitHub's automated review of PR #229 raised two inconsistencies. Both were
real, both were about **repository governance**, and both were legitimate
findings. This document records them and the correction.

**Stated precisely, because the distinction matters.** Lane E had direct
human authorization, and its implementation was technically correct: exactly
eleven allowlisted paths, one commit ahead and zero behind, no generated or
production paths, the guarded runners requiring the explicit database handoff
and delegating validation to `Tests\Support\TestDatabaseSafety`, and that
shared authority itself unchanged. **But the repository's own instructions
had not encoded that authorization route.** Read literally, `AGENTS.md` and
the state file said the work was not permitted. So the review findings were
correct as findings about the rules, and the rules — not the review — were
what needed fixing.

This document does **not** claim the previous instructions were already
unambiguous, and it does not treat the automated review as having applied the
wrong workflow. There was no documented right workflow to apply. The
contradictions are removed prospectively by this branch, in the source
documents themselves rather than by explanation.

### Comment 1 — the paused state file versus a human-authorized lane

`AGENTS.md` told reviewers to *"Enforce that file's allowed paths, required
tests, and locked slice contract"* for automation-managed work, while
`docs/automation/AI-AUTONOMY-STATE.json` carried
`implementation_authorized: false`, `allowed_paths: []` and
`gate_label: "ai:paused"`.

The state file went further still, in prose: *"This closure state authorizes
no further work of any kind."* Read together and applied to everything, that
says no work of any kind may proceed. The same file's `forbidden_scope` also
carried absolutes — *"No implementation is currently authorized"*, *"No
product, test, schema, config, or route change of any kind"* — with nothing
scoping them to the loop.

Those sentences genuinely contradicted the file's own closing line:

> "Any future work requires separate, explicit human authorization"

Lane E **had** that authorization. But an absolute sentence is not made
consistent by a later clarification elsewhere, so the first pass of this
branch — which added a `governance_scope` object and left the absolutes
standing — did not actually remove the contradiction. **This revision edits
the absolute sentences themselves**, so the file no longer says two
incompatible things. Lane E had earlier disclosed the ambiguity in
`USAGE-SUBPROCESS-DATABASE-SAFETY-COMPLETION.md` §1 rather than resolving it,
because resolving it was outside that lane's own allowlist.

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
both authorized and safer. `AGENTS.md` had not been updated to match the
behaviour already merged, and `CLAUDE.md` carried the same canonical-only
absolute under **"Non-negotiable rules"**, a heading whose own preamble said
those rules applied to the Routine and to manually-run Claude Code alike.
Both are corrected here.

---

## 2. The distinction now recorded

`AGENTS.md` now opens its review rules by making a reviewer choose which of
two routes is in front of them, because they draw scope from different
places. `CLAUDE.md` draws the same line one level finer, separating three
execution routes, because it also has to account for a human running Claude
Code interactively against the Routine's *own* locked slice:

| Route | Scope comes from | Database |
|---|---|---|
| **1. Autonomous Claude Routine** | `AI-AUTONOMY-STATE.json`'s locked slice | `ultimatesms_testing` only; GitHub Actions is the authoritative gate |
| **2. Manual completion of that same locked slice** | the same state file | `ultimatesms_testing` only — identical restrictions, only execution differs |
| **3. Separately human-authorized manual lane** | **its own task contract**, governed by `AGENTS.md` | canonical **or** a validated `TestDatabaseSafety` sibling; distinct siblings when lanes write concurrently |

Route 3 is the one that had no documented home. Routes 1 and 2 are unchanged
by this branch, and **the Routine's canonical-database restriction is not
weakened** — it is stated as binding routes 1 and 2, exactly as it always
did.

### Every CLAUDE.md rule audited for route applicability

An intermediate revision of this branch scoped the database rule but left a
catch-all: *"where it does not say so, the rule binds route 3 identically."*
That was wrong for four items, because several rules describe autonomous-loop
**mechanics** a manual lane does not use — a route-3 lane has no
`ai:needs-human` label to set, no GitHub Actions gate running locked focused
commands, and no label transition to report. The catch-all quietly asserted
otherwise.

The catch-all is removed. `CLAUDE.md` now states that **every rule carries an
explicit route tag, with no default**, and that an untagged rule is a defect
in the document rather than a rule binding everything. All ten rules, the
"State labels" section and the "Three workflows" preamble were audited one by
one:

| Rule | Routes 1 & 2 | Route 3 | Note |
|---|---|---|---|
| **State labels** section | ✅ governs | ❌ does not apply | Route 3 does not use, transition or claim these labels |
| Branch-only development | ✅ | ✅ | Same prohibition on `main`; branch source differs — automation state vs the lane's task contract |
| Never merge or open a PR | ✅ | ✅ | Identical on every route |
| No metered model credentials | ✅ | ✅ | Identical on every route |
| Which database | ✅ canonical only | ✅ task-authorized canonical **or** validated sibling | Distinct siblings required for concurrent writers |
| Never a production-looking database | ✅ | ✅ | Identical on every route |
| Never touch production data or secrets | ✅ | ✅ | Prohibition identical; **stop mechanism differs** — `ai:needs-human` vs stopping and reporting the blocker in the manual report, without claiming a label transition |
| Do not claim unverified tests | ✅ | ✅ | Prohibition identical; **verification differs** — GitHub Actions locked focused commands vs the exact verification the lane's task contract requires |
| Reject zero-test success | ✅ | ✅ | Identical on every route |
| Require real progress | ✅ | ✅ | Both must commit and push when implementation or correction was requested; route 3 may report without a commit **only** when its task explicitly prohibited changes |
| Report exact evidence | ✅ with label transition | ✅ without | Route 3 reports starting and final SHA, exact changed paths, tests and clean status, and never invents a label transition |

The correction is semantic, not cosmetic: the sentence that exposed the
problem was not merely deleted, and no bullet was left ambiguous.

Across all three routes, without exception: Claude never opens the PR and
never merges it; ChatGPT reviews the pushed branch and opens the PR; the
human alone merges; and no production-looking database, real data, or
production-looking credential is ever permitted.

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
authority, and it is **unchanged by this branch**.

**Correction
(`docs/automation/WORKSPACE-ENTITLEMENT-DATABASE-SAFETY-COMPLETION.md`,
raised as a P2 finding on this branch's own PR #230).** The paragraph this
replaces named `tests/Feature/Workspace/Support/TemporaryTestDatabase.php`
as *the* pin that genuinely still required the canonical database, as if it
were the only one. It was not — at this document's own base
(`b54441ed53540f085114946715985db1c565b6a7`), eight files genuinely pinned
the literal canonical name, exactly as
`docs/automation/MAINLINE-BASELINE-RELIABILITY-REMEDIATION.md` §5 had
already enumerated correctly:
`tests/Feature/Entitlement/Support/concurrent_business_slot_runner.php`,
`tests/Feature/Workspace/Support/concurrent_workspace_resolver_runner.php`,
`tests/Feature/Workspace/Support/concurrent_backfill_runner.php`,
`tests/Feature/Workspace/Support/run_historical_m1a_suite.php`,
`tests/Feature/Workspace/Support/run_workspace_enforcement_suite.php`,
`tests/Feature/Workspace/Support/TemporaryTestDatabase.php`,
`tests/Feature/Workspace/WorkspaceManagerConcurrencyTest.php` and
`tests/Feature/Workspace/WorkspaceManagerTest.php`. This section's own
earlier wording narrowed that eight-file finding down to one example
without saying so, which is exactly the kind of inconsistency this
document's own §1 exists to remove rather than explain away. The
`agent/baseline-workspace-entitlement-db-safety` branch converts all eight
onto `TestDatabaseSafety`; a full repository sweep performed on that branch
after the conversion found no executable literal canonical-name pin
remaining anywhere, outside `TestDatabaseSafety::CANONICAL` itself and its
own unit test's fixture data. **The general policy row above — "a task may
explicitly require the canonical database for exact canonical-baseline
reproduction" — is unaffected by this correction and remains true**: it was
never conditioned on any one file continuing to pin it, and a future task
may still explicitly require the canonical database for its own stated
reason.

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

### Proof the changes are compatible

Two kinds of change were made to the state file: one added
`governance_scope` object, and edits to **explanatory string values** in
`current_slice`, `forbidden_scope` and a new `forbidden_scope_applies_to`
key. Neither kind touches an authorization value.

**Are the edited strings machine-consumed?** Checked directly:

| Field | Machine use | Effect of this edit |
|---|---|---|
| `forbidden_scope` | **Not referenced anywhere under `.github/`** | none possible |
| `forbidden_scope_applies_to` | new key, referenced nowhere | none possible |
| `governance_scope` | new key, referenced nowhere | none possible |
| `current_slice` | `ai_subscription_gate.js` `loadState()` requires it to be a **non-empty string**; `rfc-003-m3-aggregate-regression.yml` compares it for exact equality with `'Milestone 3 aggregate regression'` | still a non-empty string; still not equal to that sentinel, exactly as before — that comparison was already false and remains false |

Mechanically verified against the pre-correction file:

```
keys added  : ["forbidden_scope_applies_to", "governance_scope"]
keys removed: []
All 12 machine-consumed authorization fields unchanged: PASS
```

The twelve fields checked are every field any consumer reads for an
authorization decision: `repository`, `base_branch`, `head_branch`,
`merge_policy`, `advance_automatically`, `implementation_authorized`,
`status`, `active_pull_request`, `expected_head_sha`, `contract_source`,
`require_exact_scope`, `start_automatically_after_contract_merge` — plus
`allowed_paths`, `required_new_paths`, `required_test_commands`,
`gate_label`, `success_label` and `failure_label`, all likewise unchanged.

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

Searched across the whole repository, excluding `vendor/` and
`node_modules/`. Every match is classified; **no active contradictory
instruction remains.**

| Searched for | Matches | Classification |
|---|---|---|
| `"no further work of any kind"` | 0 active | **Removed.** `current_slice` now says "no further AUTONOMOUS state-loop work and grants no standing implementation authority of any kind", and states that a separate explicit human instruction may authorize one manual lane |
| `"No implementation is currently authorized"` | 0 active | **Removed.** Now "No implementation is authorized for the autonomous state loop, which holds no standing implementation authority" |
| `"No product, test, schema, config, or route change of any kind"` | 0 active | **Removed.** Now scoped to "the autonomous state loop's own contract", naming the manual lane's own allowlist as what binds instead |
| `"Use only ultimatesms_testing"` / ``"Use only `ultimatesms_testing`"`` | 0 active | **Removed.** `CLAUDE.md` now reads "Routes 1 and 2: use only `ultimatesms_testing`" and states what route 3 uses |
| canonical-only database instructions | 0 active | `AGENTS.md` names both permitted forms; `CLAUDE.md` scopes the canonical rule to routes 1 and 2 |
| autonomous-only fields applied to all manual lanes | 0 active | `AGENTS.md`, `CLAUDE.md` and the state file all now scope them explicitly |
| historical mentions in merged contract documents | 2 | **Not active instructions, and each is self-scoped in its own text** — see below |

Every surviving match of a searched phrase, enumerated:

| File | Line | Why it is not an active contradiction |
|---|---|---|
| `docs/automation/MANUAL-LANE-GOVERNANCE-RECONCILIATION.md` | §1, §6 | This document, quoting the superseded wording deliberately so the correction record and this search table are readable |
| `docs/automation/RFC-005-M6-CONTRACT.md` | 413 | *"Authorizes no next RFC, no design module, and no further work of any kind **beyond recording completion**"* — a requirement placed on one specific past closure PR, describing what **that PR** could authorize. Dated, self-limiting, already executed |
| `docs/automation/RFC-005-TEST-COVERAGE-COMPLETION-CONTRACT.md` | 404 | *"No implementation is currently authorized **by this document**"* — self-scoped by its own words to that contract, and the sentence continues by naming the separate authorization PR that would lift it |

Neither historical document is edited. Both are previously merged remediation
contracts, outside this branch's allowlist, and rewriting merged history is
not this correction's business. Their wording binds their own past slices,
not the repository at large.

**`CLAUDE.md` is no longer a deferred item.** The previous revision of this
document listed it as a residual ambiguity left alone because it sat outside
that pass's allowlist. The allowlist was widened for this correction, and
`CLAUDE.md` is now corrected in full: it distinguishes the three routes
explicitly, and its "Non-negotiable rules" say per rule which routes they
bind. The Routine's own canonical-database restriction is **not weakened** —
it is stated as binding routes 1 and 2, exactly as before.

---

## 7. Scope

Exactly four paths:

1. `AGENTS.md` — the two corrected sections, plus accurate retrospective
   wording about PR #229.
2. `CLAUDE.md` — the three-route distinction, and per-rule scoping of the
   "Non-negotiable rules" including the canonical-database rule.
3. `docs/automation/AI-AUTONOMY-STATE.json` — absolute human-readable
   wording in `current_slice` and `forbidden_scope` scoped to the autonomous
   loop; two added documentation-only keys. **No authorization value
   changed.**
4. `docs/automation/MANUAL-LANE-GOVERNANCE-RECONCILIATION.md` — this file.

Zero changes to `app/`, `tests/`, `database/`, `routes/`, `config/`,
`resources/`, `public/`, `bootstrap/cache/`, `vendor/`, `node_modules/`,
dependency files, workflows, automation scripts, `.env`, `.env.testing`, or
any previously merged remediation document other than this one.
`tests/Support/TestDatabaseSafety.php` is unchanged, and all eleven paths
merged by PR #229 are unchanged.

No PHP regression was run. This correction changes no executable behaviour,
and repeating PR #229's evidence would prove nothing new.
