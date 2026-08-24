# RFC-005 Amendment 1 Closure

Status: CLOSED / COMPLETE

Closure basis: `docs/automation/AI-AUTONOMY-STATE.json`'s own recorded
condition (in effect since Slice 3 CONTRACT preparation) for RFC-005
Milestone 5 eligibility: *"RFC-005 Milestone 5 resumption (PR #107) —
not selected or authorized until all three RFC-005 Amendment 1 slices
(Expand, Cutover, Contract) have merged."* This document verifies, from
actual repository history (`git log`, `git show --stat`, `git
merge-base --is-ancestor`, and the GitHub API — not assumed from prior
conversation), that all three have now merged, and records the exact
completion evidence for each.

RFC-005 Amendment 1 exists to redesign the usage-meter identity model
(`docs/rfcs/RFC-005-AMENDMENT-1-USAGE-METER-IDENTITY.md`) ahead of
Milestone 5, across exactly three slices: **Expand** (add the new
meter-keyed shape alongside the legacy feature-keyed shape),
**Cutover** (switch live behavior to the new shape), and **Contract**
(remove the legacy shape entirely). No RFC-005 Amendment 1 slice has
its own individual closure document in this repository — this is the
first closure document for this Amendment, covering all three slices
together, consistent with the fact that none of them were previously
closed on their own.

## Completion evidence by slice

### Slice 1 — EXPAND

- Contract: [`RFC-005-AMENDMENT-1-SLICE-1-EXPAND-CONTRACT.md`](RFC-005-AMENDMENT-1-SLICE-1-EXPAND-CONTRACT.md),
  authorized via PR [#112](https://github.com/os-creator1/os-ai/pull/112)
  ("docs: define RFC-005 Amendment 1 Slice 1 expand contract"), merged
  as `60f976f4e3b27c4ccd054756c4f283fe87b8b048`.
- Implementation PR [#113](https://github.com/os-creator1/os-ai/pull/113)
  ("feat: expand RFC-005 UsageMeter foundation"), merged as
  `0ba7f6362b56d5e6fae9a95df93decdbee8606c2` (26 changed files).
- Adds the new meter-keyed shape (`usage_meters`,
  `usage_meter_transitions`, `meter_key` columns added everywhere)
  alongside the legacy feature-keyed shape, per the merged design's §O
  "Slice 1 — EXPAND".

### Slice 2 — CUTOVER

- Contract: [`RFC-005-AMENDMENT-1-SLICE-2-CUTOVER-CONTRACT.md`](RFC-005-AMENDMENT-1-SLICE-2-CUTOVER-CONTRACT.md),
  authorized via PR [#115](https://github.com/os-creator1/os-ai/pull/115)
  ("docs: authorize RFC-005 Amendment 1 Slice 2 CUTOVER"), merged as
  `0dd9ce64c13ef819d86a23152e789c0b9a085a86`.
- Implementation PR [#114](https://github.com/os-creator1/os-ai/pull/114)
  ("RFC-005 Amendment 1 — Slice 2 CUTOVER implementation target"),
  merged as `054ce63f0facc1b0e3077a2a394ae24e2f20c70e` (16 changed
  files).
- Switches live behavior (`setActiveRate()`, `reserve()`, and related
  repository/read paths) onto the meter-keyed shape, per the merged
  design's §P "Slice 2 — CUTOVER".

### Slice 3 — CONTRACT (schema contraction)

- Contract: [`RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md`](RFC-005-AMENDMENT-1-SLICE-3-CONTRACT.md),
  originally authorized via PR
  [#117](https://github.com/os-creator1/os-ai/pull/117) ("docs: define
  RFC-005 Amendment 1 Slice 3 CONTRACT"), merged as
  `dad79349e8ba429e024293be4383edebd9636389`; reconciled with merged
  Design Correction 2 in the same document (commit `0cb2963`, part of
  the same PR history); implementation baseline pinned via PR
  [#120](https://github.com/os-creator1/os-ai/pull/120) ("chore:
  authorize RFC-005 Amendment 1 Slice 3 manual implementation"), merged
  as `d976d457f4301e8129044725dc98ed2fa43ce770`.
- Three bounded exceptional corrections, each addressing a distinct
  real-MySQL-proven defect in the composite foreign-key handling around
  `business_usage_rates.meter_key`'s `NOT NULL` tightening (never
  authorized speculatively — each followed a genuine failed
  `migrate:fresh` run):
  - Exceptional Correction 1: PR [#121](https://github.com/os-creator1/os-ai/pull/121),
    merged as `5083ebfa7cefbbf67eeeef8fa374800b00e05719`.
  - Exceptional Correction 2: PR [#122](https://github.com/os-creator1/os-ai/pull/122),
    merged as `5fdf682d5bd3fc8792cf070a2c52fd6f7984e2f0`.
  - Exceptional Correction 3 (complete six-foreign-key set, source-audited):
    PR [#123](https://github.com/os-creator1/os-ai/pull/123), merged as
    `1efae3aa87665e5a987f8cd12bcbbd8d39a1a49e`.
- Two bounded post-verification test-alignment corrections, addressing
  stale pre-Slice-3 test fixtures discovered only after real
  `migrate:fresh`/Usage-suite execution proved the schema itself
  correct:
  - Test-Alignment Correction 1: PR [#124](https://github.com/os-creator1/os-ai/pull/124),
    merged as `8193315ab7e5a2d5ee27b9108f4c5503af07c262`.
  - Test-Alignment Correction 2: PR [#125](https://github.com/os-creator1/os-ai/pull/125),
    merged as `1d013f7254ffa8924340665ab7d1c823bb0dd2bc`.
- **Implementation PR [#119](https://github.com/os-creator1/os-ai/pull/119),
  final head `11d4c485399feb3b1097c9e08df3ecf44a19cdac`, human-merged as
  `3d512d5c981792f32dbb1fad941e9cb158455c7a` (16 changed files, matching
  the exact cumulative allowlist authorized across PR #117/#120–#125 —
  no more, no fewer).**
- Removes the legacy feature-keyed shape entirely:
  `business_usage_rates.feature_key` and
  `business_usage_rate_activations.feature_key` are dropped;
  `business_usage_rates.meter_key`, `business_usage_rate_activations.meter_key`,
  and `business_usage_reservations.meter_key` are `NOT NULL`;
  `business_usage_ledger_entries.meter_key` remains permanently
  nullable; rate identity/uniqueness is fully meter-local
  (`UNIQUE(meter_key, version)`); `setActiveRate()`'s allocator is
  `latestVersionForMeter()`, with no feature-wide retry loop.
- Final verification, on the merged head, before human merge:
  - `php artisan migrate:fresh --env=testing -vvv` — PASS, on the
    disposable `ultimatesms_testing` database only.
  - `php artisan test tests/Feature/Usage --compact` — **440 passed, 0
    failed, 2035 assertions**, full focused suite.
  - `git diff --check` — clean.
  - Final independent source-based pre-merge review — **APPROVE**, no
    blocking findings, covering final schema, `setActiveRate()`
    behavior, migration `up()`/`down()` audits for all three Slice 3
    migrations, rollback safety (both global preflights exercised by
    real execution), test quality across all six PR-owned/updated
    Usage test files, governance/scope, and a repository-wide
    stale-reference search.
  - GitHub deterministic test gate on the exact merged head — SUCCESS.

Every RFC-005 Amendment 1 Slice 3 CONTRACT requirement is therefore
closed, with the schema contraction complete and runtime-proven, and no
further correction pending or authorized against PR #119.

## RFC-005 Amendment 1 does not authorize Milestone 5

**This closure does NOT authorize RFC-005 Milestone 5 implementation.**

All three Amendment 1 slices (Expand, Cutover, Contract) have now
merged, which satisfies the exact condition
`docs/automation/AI-AUTONOMY-STATE.json` has recorded since Slice 3's
own contract preparation for when Milestone 5 resumption
(PR [#107](https://github.com/os-creator1/os-ai/pull/107), "docs:
define RFC-005 M5 metered feature classification", branch
`chore/rfc-005-m5-contract`) becomes **eligible to be selected**. That
condition being satisfied is not, by itself, an authorization: PR #107
remains an open, unmerged, Draft governance/contract proposal, and
**this closure does not select, resume, re-review, modify, or merge
it.** A separate, independent, human-reviewed action is required to
actually pick PR #107 back up — reviewing it (or a revised version of
it) fresh against the now-final post-Slice-3 schema, and merging it as
its own explicit governance decision — before any RFC-005 Milestone 5
implementation work may begin. `docs/automation/AI-AUTONOMY-STATE.json`
is returned to an idle state by this same PR — see below.

No RFC-005 Amendment 1 slice CONTRACT document other than Slice 3's own
is modified by this closure (Slice 1 and Slice 2's contract documents
already merged and remain historical, unannotated, exactly as this
Amendment's own established practice has left them since each slice's
own merge — this closure does not retroactively edit them).

## Automation state after this closure

`docs/automation/AI-AUTONOMY-STATE.json` is returned to an idle,
non-authorized state by this same governance PR: `active_pull_request:
null`, `head_branch: "none"`, `implementation_authorized: false`,
`current_slice` records RFC-005 Amendment 1 as closed with Milestone 5
not yet selected, `next_candidate` names Milestone 5 (PR #107) by title
and number only — explicitly **not selected or authorized** — and
`contract_source` is `null`. `merge_policy` remains `human_only`;
`advance_automatically` and `start_automatically_after_contract_merge`
remain `false`. `completed_pull_request`, `completed_product_head_sha`,
and `completed_merge_commit_sha` are updated to Slice 3's final product
evidence (PR #119, `11d4c485399feb3b1097c9e08df3ecf44a19cdac`,
`3d512d5c981792f32dbb1fad941e9cb158455c7a`).

No product implementation, Milestone 5 work, or PR #107 action of any
kind is authorized by this document.
