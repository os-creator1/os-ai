# RFC-003 Milestone 6 Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged, manual RFC-003 Milestone 6 release-readiness work is directly authorized under this document — no target-marker PR, no inert implementation PR, and no separate authorization PR. See "1. Contract status / authorization model" below.

## Purpose

Close out RFC-003 with its final milestone: a conformance audit against the full RFC-003 architecture (M1A through Milestone 5, all already implemented and merged), a deployment guide grounded in what actually exists in this repository today, a final cross-RFC regression pass, and — only after every gate below passes and a human explicitly authorizes it — the annotated release tag.

## RFC-003 §23/§25 exact scope

RFC-003 §23 defines Milestone 6 as:

> Full regression against RFC-001/RFC-002 suites, deployment guide, `rfc-003-workspace-and-business-account-core` annotated tag gate (§25).

RFC-003 §25, "Release and tag gate," reproduced in full:

> Tagging follows the same posture as RFC-001/RFC-002 (`rfc-001-business-core`, `rfc-002-opportunity-engine`): an annotated tag is created only at the end of Milestone 6, after full regression, not at the end of M1A or M1B.
>
> No tag is created, applied, or proposed as part of drafting or revising this RFC document. Drafting/revising RFC-003 is documentation only and gates nothing by itself; the tag gate applies to the eventual Milestone 6 implementation, not to this document's creation or revision.

This contract preserves that scope exactly: conformance, deployment guide, final regression, and the tag gate — nothing else. It does not reopen, redesign, or re-scope any already-closed RFC-003 milestone.

## Repository state verified before drafting

Inspected directly, not assumed, before writing this contract:

- **RFC-003 migrations** (`database/migrations/`): all six §10.1 migrations exist exactly as named (`2026_07_30_120001_create_workspaces_table.php` through `..._120006_enforce_business_workspace_constraint.php`), plus `2026_07_31_120001_create_workspace_transitions_table.php` — the Milestone 2 durable audit table forward-documented in §19 ("out of scope for M1A/M1B and specified in full when Milestone 2 is implemented").
- **Backfill implementation** (`app/Library/Workspace/Migration/`): `WorkspaceBackfillV1.php` and `WorkspaceBackfillResult.php` exist; `app/Console/Commands/BackfillWorkspacesCommand.php` exists as the thin wrapper §10.3 requires.
- **`WorkspaceManager`** (`app/Library/Workspace/WorkspaceManager.php`): 1,590 lines, implementing the full Milestone 2 responsibility list (§13.2) — lifecycle, membership, scoped assignment, Business creation/reassignment, ownership transfer, locking, the §14.1 access algorithm, and event dispatch.
- **Events** (`app/Events/Workspace/`): all 14 events §19 lists exist by name — `WorkspaceCreated`, `WorkspaceRenamed`, `WorkspaceDeactivated`, `WorkspaceReactivated`, `WorkspaceOwnershipTransferred`, `WorkspaceMembershipCreated`, `WorkspaceMembershipRoleChanged`, `WorkspaceMembershipBusinessAccessScopeChanged`, `WorkspaceMembershipDeactivated`, `WorkspaceMembershipReactivated`, `WorkspaceMembershipBusinessAssigned`, `WorkspaceMembershipBusinessUnassigned`, `BusinessAssignedToWorkspace`, `BusinessReassignedToWorkspace`.
- **Repositories**: `WorkspaceRepository`, `WorkspaceMembershipRepository`, `WorkspaceMembershipBusinessRepository` (§12.1–§12.3), plus `WorkspaceTransitionRepository` (Milestone 2's audit table) all exist under `app/Repositories/Contracts/` and `app/Repositories/Eloquent/`.
- **Exceptions** (`app/Exceptions/Workspace/`): 19 classes exist, a superset of every exception named across §12–§18, including `WorkspaceContextRequiredException` (§13.1), `WorkspaceInvalidIncomingOwnerException`, `CrossWorkspaceAssignmentException`, `InactiveWorkspaceMutationException`, `UnauthorizedWorkspaceManagementException`.
- **Customer HTTP surfaces**: `routes/customer.php` registers the Milestone 3/4 `workspaces.*` group (index, show, store, rename, deactivate, reactivate, businesses.store, businesses.reassign, ownership.transfer, members.*), backed by `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`.
- **Admin HTTP surface**: `routes/admin.php` registers exactly two `GET` routes (`admin.workspaces.index`, `admin.workspaces.show`) behind `EnsureUserIsAdministrator`, backed by `app/Http/Controllers/Admin/WorkspaceController.php` (Milestone 5, closed per `docs/automation/RFC-003-M5-CLOSURE.md`).
- **Test directories** (all confirmed to exist, non-empty): `tests/Unit/Workspace` (5 files), `tests/Feature/Workspace` (50 files), `tests/Unit/Business` (3 files), `tests/Feature/Business` (12 files), `tests/Unit/Opportunity` (10 files), `tests/Feature/Opportunity` (39 files).
- **Deployment-guide precedents**: `docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md` and `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE-DEPLOYMENT.md` both exist and follow a consistent shape (environment variables, migration order, rollout procedure, queue/worker requirements, verification commands, permissions, rollback, out-of-scope) that this contract's deployment guide reuses.
- **Prior RFC tags**: both `rfc-001-business-core` and `rfc-002-opportunity-engine` exist as real **annotated** tag objects locally (`git cat-file -p` confirms `type commit` / `tag <name>` / `tagger` / message — not lightweight refs). **Repository-evidence finding, recorded here rather than silently fixed:** `git ls-remote --tags origin` shows only `rfc-002-opportunity-engine` on the `origin` remote; `rfc-001-business-core` exists locally but was not found pushed to `origin` in this clone. This contract does not attempt to correct that historical inconsistency — it is noted so the eventual M6 tag-verification step (§9 below) knows to verify against `origin` explicitly rather than assume parity with local tag state.

If a future step of this work discovers repository reality conflicting with any claim above, that is a STOP-and-report condition (see "Gap rule" below), not something to silently reconcile.

---

## 1. Contract status / authorization model

Before human merge of this contract PR: **PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged: manual RFC-003 Milestone 6 release-readiness work is directly authorized under this document. Milestone 6 uses the same simplified workflow Milestone 5 established:

```
contract PR → one M6 release-readiness branch/PR → human regression/tag gate → annotated tag
```

Do **not** introduce a target-marker PR, an inert implementation PR, a separate authorization PR, or any `AI-AUTONOMY-STATE.json` churn at any point in this sequence.

Locked:

- `human_only_merge: true`
- `human_only_tag: true`
- `advance_automatically: false`
- `start_automatically_after_contract_merge: false`
- `maximum_correction_rounds: 2`
- `codex_review_required_for_completion: false`
- `automatic_model_handoff_required: false`

No paid model API or usage-credit requirement is authorized at any step. **M6 completion must not automatically start RFC-004.** RFC-004 remains separately designed and authorized work (see "10. M6 completion semantics").

---

## 2. M6 release-readiness branch

After this contract is human-merged, use exactly one branch: `agent/rfc-003-m6`, created from the then-current `main` containing the human merge of this exact M6 contract. No implementation of any kind begins before that contract merge.

---

## 3. Exact M6 file scope

The default M6 release-readiness PR may create **exactly two files**, both new:

1. `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md`
2. `docs/automation/RFC-003-M6-CONFORMANCE.md`

**No product implementation change is authorized by this contract.** Explicitly forbidden by default:

- `app/**`, `routes/**`, `database/**`, `config/**`, `resources/**`, `tests/**`, `cron/**`
- any workflow/CI file
- `docs/automation/AI-AUTONOMY-STATE.json`
- RFC-003 itself (`docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md`)
- any existing M1–M5 contract or closure document
- anything belonging to RFC-004 or RFC-005

No schema change, migration, model, controller, request, route, repository, manager, permission, or view change. No test change. No billing, plan, entitlement, wallet, or Stripe work. No feature expansion of any kind — Milestone 6 is a conformance/release milestone, not a feature milestone.

### Gap rule (important)

Milestone 6 is a conformance/release milestone, **not** a hidden feature-fix milestone. If the read-only conformance audit (§4) discovers that an RFC-003 acceptance criterion is actually missing, incorrectly implemented, or lacks the test coverage the RFC requires:

**STOP.** Report the exact gap — the specific RFC section, the specific missing/incorrect behavior, and the specific evidence (or absence of evidence) found. Do **not** silently modify product code or tests to close the gap. A human-reviewed amendment to this contract, or a new separately bounded contract, is required before any such code/test correction — the same discipline every prior RFC-003 milestone has followed.

---

## 4. RFC-003 conformance document

Lock the purpose of `docs/automation/RFC-003-M6-CONFORMANCE.md`: it must be an **evidence-based conformance matrix**, not a generic summary. For every row, cite concrete implementation and/or test evidence — a file path, a method name, a test method name, a migration filename, an event/exception class name, or a milestone closure document reference. **Do not mark anything PASS merely because it sounds correct; do not fabricate test counts, SHAs, PRs, or evidence.** If evidence is insufficient, mark the row **BLOCKED / GAP** and stop release progression per the gap rule above.

The audit must cover the full RFC-003 architecture end to end, at minimum:

- Workspace schema and invariants (§9.1)
- the one-Workspace-per-Business requirement / Business association (§9.4, §10.7)
- legacy Business adoption/backfill (§10.2–§10.5)
- migration safety/idempotence (§10.3, §10.4, §21.2)
- Workspace ownership (§7.2, §7.3)
- Workspace memberships (§9.2, §7.4)
- admin/staff roles (§7.5, §9.2)
- active/inactive membership semantics (§14.2, §17)
- `business_access_scope` (§7.5, §9.2)
- selected Business assignments (§9.3, §12.3)
- customer Workspace authorization (§14, §14.1)
- Business access isolation (§14.1, §14.2)
- Workspace overview/listing (Milestone 3)
- Business listing (Milestone 3)
- Business creation within a Workspace (§16.1, Milestone 4 Slice 4D)
- Business reassignment (§16.2, Milestone 4 Slice 4E)
- ownership transfer (§15, Milestone 4 Slice 4F)
- lifecycle/state handling — create/rename/deactivate/reactivate (§17, Milestone 4 Slices 4A/4C)
- administrator inspection (Milestone 5)
- the independent platform-admin boundary (§14 path 1, `EnsureUserIsAdministrator`)
- uid/UUID routing assumptions where applicable (`HasUid`, the Milestone 5 `whereUuid()` routing constraint)
- manager/repository authority boundaries (§12.5, §13)
- events/side effects required by the RFC (§19)
- RFC-001 compatibility (§20)
- RFC-002 compatibility (§20)
- explicit RFC-004/RFC-005 deferrals (§26)
- all §24 acceptance criteria (M1A database/domain, M1B database/domain, architecture/quality, documentation)
- all §25 release prerequisites relevant before tagging

The conformance document must record, for the manual regression run required before the M6 PR merges (§6), the **actual** results the human supplies — never invented counts. It is the document of record for "is RFC-003 actually done," not a checklist filled in from memory of what the RFC says should be true.

---

## 5. Deployment guide

Lock the purpose of `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md`: it must be grounded in the actual repository, following the shape already established by `RFC-001-BUSINESS-CORE-DEPLOYMENT.md` and `RFC-002-OPPORTUNITY-ENGINE-DEPLOYMENT.md`. **Do not copy either document's content — describe RFC-003 as it actually exists.** At minimum cover:

- scope (which RFC-003 milestones this guide covers — all of M1A through Milestone 5, now shipped)
- prerequisites
- supported upgrade posture
- deployment ordering
- actual RFC-003 migration ordering (the seven real migration files, in their real timestamp order, matching what "Repository state verified before drafting" above found)
- legacy Business → Workspace adoption/backfill behavior (`WorkspaceBackfillV1`, `workspaces:backfill`), described accurately against what the code actually does
- database constraints / integrity enforcement actually present (unique `uid`, unique `(workspace_id, user_id)`, unique `(workspace_membership_id, business_id)`, the `restrictOnDelete()` foreign keys, the `NOT NULL` enforcement on `businesses.workspace_id`)
- cache clearing, only if actually applicable to this feature (verify before writing — RFC-003 introduces no new `config/*.php` file the way RFC-001's `config/business.php` did; do not invent a config-cache step that has no real corresponding config source)
- queue/worker restart, only if actually applicable (verify whether any RFC-003 code implements `ShouldQueue` before documenting a restart step — do not copy RFC-001/RFC-002's queue sections if RFC-003 has no queued job)
- shared-hosting / no-shell considerations, if applicable
- deployment verification
- Workspace/customer smoke checks
- Business access/isolation smoke checks
- platform-admin inspection smoke checks
- rollback / recovery posture
- what must **not** be rolled back destructively (per §17's restrictive-FK, no-hard-delete posture — backfilled data must not be deleted by a rollback, matching §10.1's note that migration 6's rollback reverts the constraint, not the data, and migration 5's `down()` is a no-op)
- operational failure handling
- exact final regression commands (§6 below)
- release/tag verification (§8/§9 below)

**Do not invent artisan commands, classes, or migrations.** Only document what "Repository state verified before drafting" above (or further inspection during the M6 branch itself) actually confirms exists. Do not add billing, plan, entitlement, Stripe, wallet, or RFC-004/RFC-005 deployment instructions of any kind — those remain out of scope exactly as §4/§26 of the RFC itself state.

---

## 6. Final regression gate

Verified present in this repository before locking these commands (see "Repository state verified before drafting" above): `tests/Unit/Workspace`, `tests/Feature/Workspace`, `tests/Unit/Business`, `tests/Feature/Business`, `tests/Unit/Opportunity`, `tests/Feature/Opportunity` all exist and are non-empty. No repository incompatibility was found, so these manual, human-run regression commands are locked as-is:

```
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
php artisan test --stop-on-failure
```

Purpose, in order: (1) RFC-003 targeted Workspace regression; (2) RFC-001 Business regression; (3) RFC-002 Opportunity regression; (4) complete application regression.

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure, matching the discipline already applied to every prior RFC-003 slice. **The human runs these locally. Claude must never claim any of them passed if PHP is unavailable in its own environment, and must never invent a test count.** The actual results supplied by the human must be recorded in `docs/automation/RFC-003-M6-CONFORMANCE.md` before the M6 release-readiness PR is considered ready to merge. If one fails, Milestone 6 is blocked until the failure is understood and resolved under valid scope — per the gap rule, a real product/test defect discovered here is reported and separately authorized, not silently patched inside the M6 PR.

---

## 7. M6 release-readiness PR gate

Before the single M6 PR may be merged, require:

- exactly the two authorized docs changed — no more, no fewer
- the conformance matrix is complete
- no unresolved GAP/BLOCKED item remains in it
- the deployment guide is complete
- all four required regression commands (§6) manually passed, with actual results recorded
- `git diff --check` clean
- exact scope independently verified (`git status --short` / `git diff --name-only` show only the two authorized files)
- no product/test/governance-state drift
- human review

**Human-only merge.** No tag is created before this PR merges.

---

## 8. Post-merge tag-candidate gate

After the M6 release-readiness PR is human-merged, the human must:

1. `git checkout main`
2. `git pull --ff-only`
3. capture the **exact** `main` HEAD SHA — this is the tag candidate
4. confirm the working tree is clean
5. confirm `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md` exists on `main`
6. confirm `docs/automation/RFC-003-M6-CONFORMANCE.md` exists on `main`
7. confirm no unresolved conformance gap remains
8. confirm no unexpected commit or content drift landed between the M6 PR merge and this check

Then require **one final human-run complete regression** against the exact tag-candidate tree:

```
php artisan test --stop-on-failure
```

It must pass. **Do not fabricate its test count.** If it fails: **NO TAG.** If `main` has moved in a way that changes the tested tree before tagging actually happens, the tag candidate must be reevaluated and retested against the new HEAD before proceeding — a stale tested tree is not an acceptable substitute for testing the actual commit about to be tagged.

---

## 9. Annotated tag gate

**NO CLAUDE-AUTOMATIC TAGGING. NO AUTOMATIC TAG PUSH. NO TAG DURING THE M6 IMPLEMENTATION PR. NO LIGHTWEIGHT TAG.**

Tag creation requires an explicit final human authorization **after** every gate in §6, §7, and §8 has passed. Exact tag name: `rfc-003-workspace-and-business-account-core`. It must be an **annotated** tag (matching the verified `rfc-001-business-core`/`rfc-002-opportunity-engine` precedent — both real annotated tag objects, confirmed via `git cat-file -p` before writing this contract, not lightweight refs).

Fixed annotation message, consistent with the prior RFC tags' style (`RFC-001 Business Core complete`, `RFC-002 Opportunity Engine complete (Milestones 1-6)`):

```
RFC-003 Workspace and Business Account Core · Milestone 6 complete
```

This contract documents the eventual commands; **they must not be executed during contract creation or during the M6 document-implementation PR** — only after explicit human authorization following a passing §8 gate:

```
git tag -a rfc-003-workspace-and-business-account-core -m "RFC-003 Workspace and Business Account Core · Milestone 6 complete" <EXACT_TAG_CANDIDATE_SHA>
git push origin rfc-003-workspace-and-business-account-core
```

Tag verification must prove all of the following before Milestone 6 is considered complete:

- the tag exists on the `origin` remote (`git ls-remote --tags origin` — the repository-evidence finding above showed `rfc-001-business-core` is local-only on this clone; the RFC-003 tag must actually be verified against `origin`, not merely assumed present because it exists locally)
- it is an annotated tag object, not a lightweight ref (`git cat-file -t <tag>` must report `tag`, not `commit`; `git cat-file -p <tag>` must show a `tagger` line and the message above)
- the tag name is exactly `rfc-003-workspace-and-business-account-core`
- the tag resolves to the exact human-approved tag-candidate commit captured in §8
- the annotation message is present and matches

If verification fails on any point, Milestone 6 is not complete.

---

## 10. M6 completion semantics

Milestone 6 — and RFC-003 as a whole — becomes **COMPLETE** only after:

- this M6 contract is merged
- the M6 conformance/deployment PR is merged
- all required human regression gates (§6, §8) pass
- every §25 release condition is satisfied
- explicit human tag authorization occurs
- the annotated tag is created and pushed
- the annotated tag is verified against the exact intended commit (§9)

The verified annotated tag itself is the immutable RFC-003 release marker. **Do not require another redundant post-tag closure PR by default** — the tag, once verified, is the completion record.

**No automatic RFC-004 start.** RFC-004 (Plans and Business Feature Entitlements) remains separately designed and separately authorized work, requiring its own future contract, exactly as RFC-003 itself required before any of its milestones began.

---

## 11. Contract PR itself

This contract-creation branch (`chore/rfc-003-m6-contract`) may change exactly one file: `docs/automation/RFC-003-M6-CONTRACT.md`. Nothing else.

Do not modify `docs/automation/AI-AUTONOMY-STATE.json`. Do not create a target marker. Do not create either of the two future M6 implementation files now. Do not create a tag now.

---

## Forbidden governance / automation (summary)

- No automatic implementation start.
- No automatic merge.
- No force push.
- No direct push to `main`.
- No paid model API or usage-credit requirement.
- No Codex completion requirement.
- No automatic model handoff.
- No tag of any kind created by this contract or by the eventual M6 documentation PR — only by the explicit, separately-authorized §9 procedure.
- No RFC-004 implementation.
- No RFC-005 implementation.
- `advance_automatically` remains `false`.
- `start_automatically_after_contract_merge` remains `false`.

**Implementation is not authorized under this document until it is human-reviewed and merged.** Once merged, manual Milestone 6 release-readiness work may begin directly under this contract, per "1. Contract status / authorization model" above — no further authorization PR is required or expected.
