# RFC-004 Milestone 4 Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged, manual RFC-004 Milestone 4 release-readiness work is directly authorized under this document — no target-marker PR, no inert implementation PR, and no separate authorization PR. See "1. Contract status / authorization model" below.

## Purpose

Close out RFC-004 with its final milestone: a conformance audit against the full RFC-004 architecture (Milestones 1 through 3, all already implemented and merged), a deployment guide grounded in what actually exists in this repository today, a final cross-RFC regression pass, and — only after every gate below passes and a human explicitly authorizes it — the annotated release tag. This mirrors RFC-003 Milestone 6's exact discipline, per RFC-004 §29's own instruction ("M4 — Full conformance, deployment guide, complete regression, annotated tag. Mirrors RFC-003 Milestone 6 exactly").

## RFC-004 §29/§30 exact scope

RFC-004 §29 defines Milestone 4 as:

> **M4 — Full conformance, deployment guide, complete regression, annotated tag.** Mirrors RFC-003 Milestone 6 exactly: an evidence-based conformance matrix against this RFC's own acceptance criteria (§28), a deployment guide grounded in the actual M1–M3 implementation, the full regression gate, and the `rfc-004-plans-and-business-feature-entitlements` annotated tag — created only after the same post-merge exact-tag-candidate regression gate RFC-003 M6 used, with explicit human authorization, never automatically.

RFC-004 §30, "Release and tag gate," reproduced in full:

> Tagging follows the exact posture RFC-001/RFC-002/RFC-003 already established: an annotated tag is created only at the end of RFC-004's final milestone (M4), after full regression, never at the end of M1–M3. No tag is created, applied, or proposed as part of drafting or revising this RFC document.
>
> Proposed exact tag: `rfc-004-plans-and-business-feature-entitlements`, annotated (not lightweight), verified against the `origin` remote exactly as RFC-003 M6's contract required — including re-confirming, at that future time, whichever tag-verification convention is then current, since `docs/automation/RFC-003-M6-CONTRACT.md` itself already found one historical inconsistency (`rfc-001-business-core` local-only vs. `rfc-002-opportunity-engine` pushed) worth checking against `origin` explicitly rather than assumed.
>
> No tag is created now. No tag is created during M1–M3. No tag is created during M4's own implementation PR — only after M4's documentation PR merges, a post-merge exact-tag-candidate regression passes, and a human explicitly authorizes it.

This contract preserves that scope exactly: conformance, deployment guide, final regression, and the tag gate — nothing else. It does not reopen, redesign, or re-scope any already-closed RFC-004 milestone (M1, M2, or M3), and it does not begin RFC-005.

---

## Repository state verified before drafting

Inspected directly, at base SHA `ab32a35a9a163c70f94350843e182ac9bf60e57a` on `main`, not assumed, before writing this contract:

- **RFC-004 migrations** (`database/migrations/`): all eight §25.1 migrations exist exactly as named and in the exact real timestamp order —
  1. `2026_08_13_120001_create_workspace_plan_catalog_table.php`
  2. `2026_08_13_120002_create_workspace_plan_features_table.php`
  3. `2026_08_13_120003_create_workspace_plan_assignments_table.php`
  4. `2026_08_13_120004_create_workspace_entitlement_overrides_table.php`
  5. `2026_08_13_120005_create_business_feature_toggles_table.php`
  6. `2026_08_13_120006_create_workspace_entitlement_transitions_table.php`
  7. `2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`
  8. `2026_08_13_120008_backfill_workspace_entitlement_assignments.php`
- **Backfill implementation** (`app/Library/Entitlement/Migration/`): `WorkspaceEntitlementBackfillV1.php` and `WorkspaceEntitlementBackfillResult.php` exist; `app/Console/Commands/BackfillWorkspaceEntitlementsCommand.php` exists as the thin wrapper §25.2 requires.
- **`EntitlementManager`** (`app/Library/Entitlement/EntitlementManager.php`): confirmed present, implementing `decide()`, `decideBusinessSlotCapacity()`/`assertCanCreateAnotherBusiness()`, `assignFirstPlan()`, `changePlan()`, `changePlanStatus()`, `grantComplimentaryStatus()`/`revokeComplimentaryStatus()`, `setAdditionalBusinessSlots()`, `createOrChangeOverride()`/`revertOverride()`, `disableBusinessFeature()`/`enableBusinessFeature()`, `updateCatalogPricing()`, and (added in M3) `listPlanCatalogSummaries()`, `getWorkspaceEntitlementSummary()`, `decideAvailableFeaturesForBusiness()`.
- **Repositories** (`app/Repositories/Contracts/`, `app/Repositories/Eloquent/`): exactly six exist, one per §10 table, confirmed by directory listing — `WorkspacePlanCatalogRepository`, `WorkspacePlanFeatureRepository`, `WorkspacePlanAssignmentRepository`, `WorkspaceEntitlementTransitionRepository`, `WorkspaceEntitlementOverrideRepository`, `BusinessFeatureToggleRepository`.
- **Events** (`app/Events/Entitlement/`): exactly seven files exist, matching §21's list by name — `WorkspacePlanAssigned`, `WorkspacePlanChanged`, `WorkspacePlanStatusChanged`, `WorkspaceComplimentaryStatusChanged`, `WorkspaceAdditionalBusinessSlotsChanged`, `WorkspaceEntitlementOverrideChanged`, `BusinessFeatureToggleChanged`.
- **Exceptions** (`app/Exceptions/Entitlement/`): eleven classes exist, a superset of every exception §17/§23 name (including `WorkspaceEntitlementBackfillIncompleteException` for the migration's own zero-unassigned assertion).
- **Enums** (`app/Enums/Entitlement/`): `PlatformFeature` (fifteen cases), `PlatformFeatureAvailability`, `WorkspacePlanTier`, `WorkspacePlanAssignmentStatus`, `WorkspaceEntitlementOverrideState`, `WorkspaceEntitlementTransitionType` confirmed present. `PlatformFeatureRegistry` confirmed present with `Crm`/`Conversations`/`Automations` marked `Available` and every other case, including `ProspectOutreach` and `WhiteLabel`, marked `Planned` — matching §26/§29 M3's rule that a gate is introduced only where direct repository evidence confirms an executable implementation; M3 introduced no such gate for either, consistent with both remaining `Planned`.
- **No `Agency` model or `businesses.agency_id` column** exists anywhere in `app/Models` — confirmed absent by direct search (§28, §3).
- **Admin HTTP surfaces** (`routes/admin.php`, `app/Http/Controllers/Admin/`): `WorkspaceController` (enriched in M3 with Gate-conditional entitlement summary/explanation), `WorkspacePlanCatalogController` (read-only catalog index), `WorkspaceEntitlementController` (eight mutation actions) all confirmed present, registered under the existing `EnsureUserIsAdministrator`-gated group with no literal `admin/` URI segment or `admin.` name prefix written into the route file itself.
- **Customer HTTP surfaces** (`routes/customer.php`, `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`): `disableBusinessFeature()`/`enableBusinessFeature()` actions and the Owner/active-Admin-only `entitlement` view-data key confirmed present.
- **Permissions** (`config/permissions.php`): `'view workspace plans'`/`'manage workspace plans'` confirmed present under a distinct `Workspace Plans` category — never colliding with the legacy `Plan` category (§5 finding 5, §22, §28).
- **Test directories** (all confirmed to exist, non-empty, by direct listing): `tests/Unit/Entitlement` (1 file), `tests/Feature/Entitlement` (29 files), `tests/Unit/Workspace`, `tests/Feature/Workspace`, `tests/Unit/Business`, `tests/Feature/Business`, `tests/Unit/Opportunity` (10 files), `tests/Feature/Opportunity` (39 files).
- **M1/M2/M3 contracts** (`docs/automation/`): `RFC-004-M1-CONTRACT.md`, `RFC-004-M2-CONTRACT.md`, `RFC-004-M3-CONTRACT.md` all confirmed present and merged. `RFC-004-M3-CONTRACT.md` was itself corrected across two ordinary rounds plus one residual structural fix before its own merge (`df5c02e`), and its resulting implementation (`160539d`) received one further ordinary correction round (`753160b`, fixing two test-fixture defects — no production-code change) before merge into `main` as `ab32a35` (PR #67). No M4-relevant contradiction was found between what these contracts locked and what §28/§29 of the RFC itself require.
- **Deployment-guide precedents**: `docs/rfcs/RFC-001-BUSINESS-CORE-DEPLOYMENT.md`, `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE-DEPLOYMENT.md`, and `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md` all exist and follow a consistent shape (scope, prerequisites, migration order, backfill behavior, database integrity, cache/queue steps only where actually applicable, deployment verification/smoke checks, rollback posture, regression commands, release/tag verification) that this contract's deployment-guide requirements (below) reuse.
- **`RFC-003-M6-CONTRACT.md`/`RFC-003-M6-CONFORMANCE.md`**: confirmed present and used directly as this contract's own governing precedent — both the M6 contract's exact section structure and the M6 conformance document's evidence-based-matrix format (a `PASS`/`BLOCKED-GAP` row per RFC section, each citing a concrete file/method/migration/test) are reused below.
- **Existing annotated tags**, verified directly via `git cat-file -t <tag>` (all report `tag`, confirming annotated objects, not lightweight refs) and `git ls-remote --tags origin`:
  - `rfc-001-business-core` — exists locally; **confirmed still absent from `origin`** in this clone (`git ls-remote --tags origin` does not list it). This is the exact historical inconsistency `RFC-003-M6-CONTRACT.md` already recorded rather than silently reconciled — re-verified here, still present, not fixed by this contract (fixing it is out of this contract's scope; it is recorded so the eventual §9 tag-verification step below knows to check `origin` explicitly for the RFC-004 tag rather than assume parity with local tag state).
  - `rfc-002-opportunity-engine` — exists locally and confirmed pushed to `origin` (both the tag object and its dereferenced commit are listed).
  - `rfc-003-workspace-and-business-account-core` — exists locally and confirmed pushed to `origin`; `git cat-file -p` shows `tagger Matt <gvidas@jazminmedia.com>` and the exact message `RFC-003 Workspace and Business Account Core · Milestone 6 complete`, directly confirming the annotation-message convention this contract's §9 (below) follows for RFC-004's own tag.
  - No RFC-004 tag of any kind exists yet, locally or on `origin` — confirmed by the same `git tag -l` / `git ls-remote --tags origin` inspection.

If a future step of this work discovers repository reality conflicting with any claim above, that is a STOP-and-report condition (see "Gap rule" below), not something to silently reconcile.

---

## 1. Contract status / authorization model

Before human merge of this contract PR: **PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

After this exact one-file contract PR is human-reviewed and merged: manual RFC-004 Milestone 4 release-readiness work is directly authorized under this document. Milestone 4 uses the same simplified workflow RFC-003 Milestone 6 and RFC-004 Milestones 1–3 already established:

```
contract PR → one M4 release-readiness branch/PR → human regression/tag gate → annotated tag
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

No paid model API or usage-credit requirement is authorized at any step. No force push, and no direct push to `main`, is authorized at any step. **M4 completion must not automatically start RFC-005.** RFC-005 (Business Usage Billing and Wallets) remains separately designed and separately authorized work, requiring its own future RFC and contract, exactly as RFC-004 itself required before any of its own milestones began.

---

## 2. M4 release-readiness branch

After this contract is human-merged, use exactly one branch: `agent/rfc-004-m4`, created from the then-current `main` containing the human merge of this exact M4 contract. No implementation of any kind begins before that contract merge.

---

## 3. Exact M4 file scope

The default M4 release-readiness PR may create **exactly two files**, both new:

1. `docs/automation/RFC-004-M4-CONFORMANCE.md`
2. `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md`

**No product implementation change is authorized by this contract.** Explicitly forbidden by default:

- `app/**`, `routes/**`, `database/**`, `config/**`, `resources/**`, `tests/**`, `cron/**`
- any workflow/CI file
- `docs/automation/AI-AUTONOMY-STATE.json`
- RFC-004 itself (`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md`)
- any existing M1–M3 contract or closure document
- anything belonging to RFC-005 (or any other RFC)

No schema change, migration, model, controller, request, route, repository, manager, permission, or view change. No test change. No billing, plan, entitlement, wallet, or Stripe work. No feature expansion of any kind, including wiring `EntitlementManager::decide()` into any additional legacy module — Milestone 4 is a conformance/release milestone, not a feature milestone.

### Gap rule (important)

Milestone 4 is a conformance/release milestone, **not** a hidden correction milestone. If the read-only conformance audit (§4 below) discovers that an RFC-004 requirement — an acceptance criterion (§28), a specific behavior locked in §10–§27, or a specific proof required by §27's test strategy — is actually missing, incorrectly implemented, or lacks the test coverage the RFC requires:

**STOP.** Report exactly:

- the exact RFC-004 section and/or §28 acceptance-criterion bullet at issue
- the exact implementation or test evidence found (file, method, migration, test class/method, or mechanical code-search result) — or its explicit absence
- exactly what is missing, incorrect, or contradictory

Do **not** silently modify product code or tests to close the gap, and do not modify product code or tests inside M4 under any circumstance. A human-reviewed amendment to this contract, or a new separately bounded correction contract, is required before any such code/test correction — the same discipline every prior RFC-003/RFC-004 milestone has followed.

---

## 4. RFC-004 conformance document

Lock the purpose of `docs/automation/RFC-004-M4-CONFORMANCE.md`: it must be an **evidence-based conformance matrix**, not a generic narrative summary — following `docs/automation/RFC-003-M6-CONFORMANCE.md`'s exact proven format (one row/section per RFC concern, each citing concrete evidence, marked `PASS` only when that evidence is concrete). For every row, cite concrete implementation and/or test evidence: a migration filename and its exact constraint, a class and method name, a repository boundary, an enum/registry entry, an event/transition type, a controller/route/permission/view, an exact test class and test method, a mechanical code-search result, relevant M1–M3 contract/merge evidence (commit SHA, PR number), or actual regression results. **Do not mark anything PASS merely because it sounds correct or because a prior milestone's contract claimed it; do not fabricate test counts, SHAs, PR numbers, or evidence of any kind.** If evidence is insufficient or contradictory, mark the row **BLOCKED / GAP** and stop release progression per the gap rule above.

The audit must cover every RFC-004 §28 acceptance criterion **individually** — one addressable row/section per bullet of §28, not merged or summarized together — at minimum:

- all six new tables (§10) exist with exactly the specified columns, constraints, and `restrictOnDelete()` foreign keys, including `workspace_entitlement_transitions.from_status`/`to_status`, and no native DB `ENUM` column exists anywhere in this RFC's schema
- `workspace_plan_catalog` is seeded with exactly `core`/`growth`/`agency`, matching §12.1's slot policy and §12.2's feature matrix exactly, including `additional_business_slot_price_ratio = 0.5000` for Core/Growth and `null` for Agency
- `PlatformFeatureRegistry` exists and is consulted by `EntitlementManager::decide()` strictly before any plan-mapping/override resolution; no plan mapping or override can make an unavailable feature executable, verified by a direct test; `ProspectOutreach`'s seeded availability value reflects Milestone 1's own direct repository evidence, not the RFC's illustrative default
- every pre-existing RFC-003 Workspace has exactly one `workspace_plan_assignments` row after the Milestone-1 backfill, with `status = active`, `is_complimentary = true`, and a correctly-derived `additional_business_slots` value, and zero Workspaces are left unassigned
- no pre-existing Workspace's Business count was altered, and no existing Business was deleted/deactivated/hidden, by the backfill, verified directly
- `EntitlementManager::decide()` implements §14's eight-step algorithm exactly, including denial-reason-key stability across all nine keys
- `EntitlementManager::decideBusinessSlotCapacity()`/`assertCanCreateAnotherBusiness()` correctly enforces the allocation-gated 3/4/5 model — a 4th or 5th Business never succeeds without the corresponding allocation — and is concurrency-safe under a forced-race test
- `EntitlementManager::changePlan()` correctly normalizes `additional_business_slots` for all three tier-change directions inside a single transaction, and `EntitlementManager::changePlanStatus()` requires actor/reason and durably audits every status change — both verified directly
- the pricing/allocation invariant (§12.5) is enforced exactly as specified for plan assignment, slot increase, slot decrease, and catalog price/currency mutation, including the complimentary carve-out and the both-null-or-both-populated pairing — verified directly, not merely believed
- every Workspace-override, additional-slot-allocation, and plan-status mutation writes a correct `workspace_entitlement_transitions` row, verified directly, not merely believed from event dispatch
- no RFC-001/RFC-002/RFC-003 test regresses; the legacy Plan/Subscription suite is unaffected
- no direct entitlement-table query exists outside `EntitlementManager` and its six repositories — verified by code search, not merely believed
- `config/permissions.php`'s new keys use a distinct category from the legacy `Plan` category
- no `Agency` model or `businesses.agency_id` column exists anywhere
- any M3-introduced gate over a pre-existing capability is accompanied by a verified compatibility-override pass per §26, based on direct repository evidence of an executable implementation (never product intent alone), showing no unexplained access-removal regression — **or**, if M3 introduced no such gate at all (as the repository evidence above found for both `ProspectOutreach` and `WhiteLabel`, both remaining `Planned`), a direct citation proving that finding still holds and that no gate/override machinery for either was introduced

The document must **also reconcile** RFC-004 §27's detailed test strategy with the implemented evidence — not merely list §28's acceptance criteria in isolation. At minimum, address each of the following against concrete test-class/method evidence:

- all six tables and all eight migration operations (§25.1 steps A–H)
- the seeded catalog, feature matrix, and the exact `0.5000` additional-slot price ratio
- `PlatformFeatureRegistry` availability behavior, including the known-vs-available distinction and the disabled/rolled-back-feature-denies-existing-rows proof
- deterministic existing-Workspace backfill correctness, idempotence, partial-rerun safety, and concurrent-run safety (no duplicate assignment)
- all nine denial keys (`platform_feature_unknown`, `platform_feature_unavailable`, `workspace_plan_unassigned`, `not_entitled_by_plan`, `denied_by_workspace_override`, `disabled_for_business`, `plan_inactive`, `plan_suspended`, `usage_unauthorized` reserved) and the exact §14 eight-step decision precedence
- plan/status/complimentary/pricing invariants, including the corrected billing-state precedence (§18) and the full §12.5 pricing/allocation rule set
- capacity and concurrency behavior (the 3/4/5 allocation-gated model, forced-race safety for the final available slot)
- plan-change slot normalization for all three tier-change directions (§17.1)
- override/toggle/allocation/status transition auditing — every `workspace_entitlement_transitions` transition type (nine total) and every corresponding event
- authorization and cross-Workspace isolation (RFC-003 §14.1 remaining independent of and prerequisite to RFC-004's own gate; an entitlement/override/toggle/allocation/status change for one Workspace never leaking into another Workspace's decision)
- admin/customer surfaces and permission boundaries (the M3-built `WorkspacePlanCatalogController`/`WorkspaceEntitlementController`/admin `WorkspaceController` enrichment/customer `WorkspaceController` enrichment, the `EnsureUserIsAdministrator` + `view workspace plans`/`manage workspace plans` defense-in-depth, and the customer-side Owner/active-Admin-only visibility rule)
- no direct entitlement-table access outside the authorized boundary (`EntitlementManager` and its six repositories) — a fresh mechanical code-search result recorded directly in this document, not assumed from M3's own prior self-audit
- existing-capability compatibility findings from M3 (§26) — the `ProspectOutreach`/`WhiteLabel` `Planned` findings above, and confirmation no compatibility-override pass was silently skipped for a capability that should have required one
- RFC-001, RFC-002, RFC-003, and legacy Plan/Subscription compatibility — each suite's regression result recorded directly, plus a direct citation that the legacy `plans`/`subscriptions` tables/models/repositories remain completely untouched (§6)

**Nothing may be marked PASS without concrete evidence cited directly in the row.** Insufficient evidence is **BLOCKED/GAP**, which invokes the gap rule above — it is not resolved by writing a plausible-sounding narrative. Never fabricate counts, SHAs, PR numbers, or test results anywhere in this document.

---

## 5. Deployment guide

Lock the purpose of `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md`: it must be grounded in the actual repository, following the shape already established by `RFC-001-BUSINESS-CORE-DEPLOYMENT.md`, `RFC-002-OPPORTUNITY-ENGINE-DEPLOYMENT.md`, and `RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE-DEPLOYMENT.md`. **Do not copy any of their content — describe RFC-004 as it actually exists.** At minimum cover:

- scope: RFC-004 Milestones 1–3 (schema/catalog/registry/backfill; entitlement engine/mutations/slot enforcement; admin/customer surfaces)
- prerequisites and supported upgrade posture
- deployment ordering
- all eight real migrations, in their real timestamp order (matching "Repository state verified before drafting" above)
- catalog/feature-matrix seeding — the exact seeded rows and the plan-feature matrix, described accurately against what the seed migration actually inserts
- `WorkspaceEntitlementBackfillV1` and the actual `workspaces:...` (or equivalently named) artisan command, described accurately against what the code actually does — do not assume the command name without re-confirming it directly
- idempotence, partial-rerun, and concurrency posture of the backfill
- zero-unassigned-Workspace verification (the exact final assertion query/behavior)
- preservation of existing Businesses and the grandfathered-over-capacity behavior for a Workspace that already exceeded 5 Businesses before the backfill
- required permissions (`view workspace plans`/`manage workspace plans`) and admin/customer smoke checks
- plan/status/slot/override/toggle verification steps
- pricing and complimentary-assignment operational considerations (the §12.5 invariant, catalog rows seeded with null price/currency by default)
- database integrity and the restrictive (`restrictOnDelete()`) foreign keys actually present
- cache/queue/worker steps **only if repository evidence proves they apply** — verify before writing; do not copy a queue-restart section from a prior deployment guide if no RFC-004 code implements `ShouldQueue`
- shared-hosting/no-shell considerations, if applicable
- safe rollback/recovery behavior and what must **not** be deleted destructively (backfilled/seeded data must not be deleted by a rollback; migration 7's seed `down()` is a documented no-op per the RFC's own migration-safety posture — confirm this directly against the actual migration file rather than assuming it from this contract's description)
- operational failure handling
- exact regression commands (§6 below)
- release/tag verification (§8/§9 below)
- explicit RFC-005 (billing/wallet/Stripe) deferral, matching §19/§31 of the RFC exactly

**Do not invent artisan commands, migrations, classes, configuration, queues, or rollback guarantees.** Only document what "Repository state verified before drafting" above (or further inspection during the M4 branch itself) actually confirms exists.

---

## 6. Locked pre-merge regression gates

Verified present in this repository before locking these commands (see "Repository state verified before drafting" above): `tests/Unit/Entitlement`, `tests/Feature/Entitlement`, `tests/Unit/Workspace`, `tests/Feature/Workspace`, `tests/Unit/Business`, `tests/Feature/Business`, `tests/Unit/Opportunity`, `tests/Feature/Opportunity` all exist and are non-empty. No repository incompatibility was found, so these manual, human-run regression commands are locked as-is, run against the disposable `ultimatesms_testing` database:

```bash
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
php artisan test --stop-on-failure
```

Purpose, in order: (1) RFC-004's own targeted Entitlement regression; (2) RFC-003 Workspace regression; (3) RFC-001 Business regression; (4) RFC-002 Opportunity regression; (5) complete application regression.

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure. **Never fabricate a test count or assertion count.** The actual results (exact pass/fail counts and assertion counts) must be recorded directly in `docs/automation/RFC-004-M4-CONFORMANCE.md` before the M4 release-readiness PR is considered ready to merge. If one fails, Milestone 4 is blocked until the failure is understood and resolved under valid scope — per the gap rule, a real product/test defect discovered here is reported and separately authorized, not silently patched inside the M4 PR.

---

## 7. M4 release-readiness PR gate

Before the single M4 PR may be merged, require:

- exactly the two authorized documents changed — no more, no fewer
- the conformance matrix is complete, addressing every §28 acceptance criterion individually and reconciling §27's test strategy per §4 above
- no unresolved `GAP`/`BLOCKED` item remains in it
- the deployment guide is complete per §5 above
- all five regression commands (§6) passed, with actual pass/fail and assertion counts recorded
- `git diff --check` clean
- exact scope independently verified (`git status --short` / `git diff --name-only` show only the two authorized files)
- no product/test/governance-state drift
- human review

**Human-only merge.** No tag is created before this PR merges.

---

## 8. Post-merge tag-candidate gate

After the M4 release-readiness PR is human-merged, the human must:

1. `git checkout main`
2. `git pull --ff-only`
3. capture the **exact** `main` HEAD SHA — this is the tag candidate
4. confirm the working tree is clean
5. confirm `docs/automation/RFC-004-M4-CONFORMANCE.md` exists on `main`
6. confirm `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS-DEPLOYMENT.md` exists on `main`
7. confirm no unresolved conformance gap remains
8. confirm no unexpected commit or content drift landed between the M4 PR merge and this check

Then require **one final human-run complete regression** against the exact tag-candidate tree:

```bash
php artisan test --stop-on-failure
```

It must pass. **Do not fabricate its test count.** If it fails: **NO TAG.** If `main` has moved in a way that changes the tested tree before tagging actually happens, the tag candidate must be reevaluated and retested against the new HEAD before proceeding — a stale tested tree is not an acceptable substitute for testing the actual commit about to be tagged.

---

## 9. Annotated tag gate

**NO CLAUDE-AUTOMATIC TAGGING. NO AUTOMATIC TAG PUSH. NO TAG DURING THE M4 IMPLEMENTATION PR. NO LIGHTWEIGHT TAG.**

Tag creation requires an explicit final human authorization **after** every gate in §6, §7, and §8 has passed. Exact tag name: `rfc-004-plans-and-business-feature-entitlements`. It must be an **annotated** tag (matching the verified `rfc-001-business-core`/`rfc-002-opportunity-engine`/`rfc-003-workspace-and-business-account-core` precedent — all three real annotated tag objects, confirmed via `git cat-file -t`/`git cat-file -p` before writing this contract, not lightweight refs).

Fixed annotation message, consistent with the prior RFC tags' style (`RFC-001 Business Core complete`, `RFC-002 Opportunity Engine complete (Milestones 1-6)`, `RFC-003 Workspace and Business Account Core · Milestone 6 complete`):

```
RFC-004 Plans and Business Feature Entitlements · Milestone 4 complete
```

This contract documents the eventual commands; **they must not be executed during contract creation or during the M4 document-implementation PR** — only after explicit human authorization following a passing §8 gate:

```bash
git tag -a rfc-004-plans-and-business-feature-entitlements -m "RFC-004 Plans and Business Feature Entitlements · Milestone 4 complete" <EXACT_TAG_CANDIDATE_SHA>
git push origin rfc-004-plans-and-business-feature-entitlements
```

Tag verification must prove all of the following before Milestone 4 is considered complete:

- `git cat-file -t <tag>` reports `tag`, not `commit` — an annotated tag object, not a lightweight ref
- `git cat-file -p <tag>` shows a `tagger` line and contains the exact annotation message above
- the tag name is exactly `rfc-004-plans-and-business-feature-entitlements`
- it resolves to the exact, explicitly human-approved tag-candidate commit captured in §8
- the tag exists on the `origin` remote (`git ls-remote --tags origin`) — re-verified explicitly rather than assumed, exactly as the repository-evidence finding above (`rfc-001-business-core` confirmed still local-only on `origin` in this clone) requires

If verification fails on any point, Milestone 4 is not complete.

No redundant post-tag closure PR is required by default. RFC-004 is complete only after the annotated tag is pushed and independently verified against every point above.

---

## 10. M4 completion semantics

Milestone 4 — and RFC-004 as a whole — becomes **COMPLETE** only after:

- this M4 contract is merged
- the M4 conformance/deployment PR is merged
- all required human regression gates (§6, §8) pass
- every relevant RFC-004 §28 acceptance criterion is satisfied with recorded evidence and no unresolved gap
- explicit human tag authorization occurs
- the annotated tag is created and pushed
- the annotated tag is verified against the exact intended commit (§9)

The verified annotated tag itself is the immutable RFC-004 release marker.

**No automatic RFC-005 start.** RFC-005 (Business Usage Billing and Wallets) remains separately designed and separately authorized work, requiring its own future RFC and contract, exactly as RFC-004 itself required before any of its own milestones began.

---

## 11. Contract PR itself

This contract-creation branch (`chore/rfc-004-m4-contract`) may change exactly one file: `docs/automation/RFC-004-M4-CONTRACT.md`. Nothing else.

Do not modify `docs/automation/AI-AUTONOMY-STATE.json`. Do not create a target marker. Do not create either of the two future M4 implementation files now. Do not create a tag now.

---

## Forbidden governance / automation (summary)

- No automatic implementation start.
- No automatic merge.
- No force push.
- No direct push to `main`.
- No paid model API or usage-credit requirement.
- No Codex completion requirement.
- No automatic model handoff.
- No tag of any kind created by this contract or by the eventual M4 documentation PR — only by the explicit, separately-authorized §9 procedure.
- No RFC-005 implementation.
- `advance_automatically` remains `false`.
- `start_automatically_after_contract_merge` remains `false`.

**Implementation is not authorized under this document until it is human-reviewed and merged.** Once merged, manual Milestone 4 release-readiness work may begin directly under this contract, per "1. Contract status / authorization model" above — no further authorization PR is required or expected.
