# RFC-004 Plans and Business Feature Entitlements — Deployment Guide

Grounded in the actual repository implementation of RFC-004 Milestones 1–3, verified by direct inspection during Milestone 4, not copied from any prior RFC's deployment guide.

---

## 1. Scope

This guide covers RFC-004 Milestones 1–3, as actually shipped:

- **Milestone 1** — the six-table schema, the plan-catalog/feature-matrix seed, and the versioned `WorkspaceEntitlementBackfillV1` action assigning every pre-existing Workspace a complimentary Core plan.
- **Milestone 2** — `EntitlementManager`, the full `decide()`/`decideBusinessSlotCapacity()` decision engine, plan/status/complimentary/slot mutations, Workspace overrides, Business feature toggles, and Business-slot capacity enforcement wired into every Business-count-increasing operation.
- **Milestone 3** — the admin plan/catalog/entitlement-override HTTP surface, the customer plan/capacity/feature-preference HTTP surface, and the two new `Workspace Plans` permission keys.

It does **not** cover RFC-005 (Business Usage Billing and Wallets) — see §17 below.

---

## 2. Prerequisites

- Laravel 12 / PHP 8.2+ (this repository), already running RFC-001 (Business Core), RFC-002 (Opportunity Engine), and RFC-003 (Workspace and Business Account Core, tagged `rfc-003-workspace-and-business-account-core`).
- A `currencies` table already populated if you intend to set real `workspace_plan_catalog` pricing after deploy (§9 below) — RFC-004 itself seeds every catalog row with `price`/`currency_id` left `null` (§9).
- The disposable `ultimatesms_testing` database for running the regression commands in §14; never point them at a production or otherwise non-disposable database.

---

## 3. Upgrade posture

RFC-004 is purely additive at the schema level: six new tables, no column added to or removed from any existing table. No RFC-001/RFC-002/RFC-003 table, column, route, controller, or view is dropped, renamed, or altered in a breaking way — but Milestone 2's actual integration into RFC-003's Business-count-increasing paths is more involved than a single additive call each, and is described accurately here rather than understated:

- `WorkspaceManager::createBusinessInWorkspace()` and `WorkspaceManager::reassignBusiness()` (for a real cross-Workspace move) each gained one additive `EntitlementManager::assertCanCreateAnotherBusiness()` capacity-assertion call, inserted after the existing Workspace-row lock and before the count-increasing write, inside each method's own existing transaction.
- `BusinessManager`'s legacy onboarding CREATE path (`applyIdentity()`, invoked via `createOrUpdateOnboardingBusiness()`) gained `EntitlementManager` as a constructor dependency and, on its CREATE branch specifically, now: resolves the target Workspace via the existing `WorkspaceManager::resolveLegacyOnboardingWorkspace()`; **explicitly locks that resolved Workspace** via a new narrow `WorkspaceManager::lockForLegacyOnboardingBusinessCreation()` primitive (no authority/active-state check — the legacy path never enforced either); asserts capacity via `EntitlementManager::assertCanCreateAnotherBusiness()`; and, when this path's own zero-candidate resolution provisions a **brand-new** Workspace, creates a one-time initial complimentary-Core `workspace_plan_assignments` row for it via `EntitlementManager::createLegacyOnboardingCompatibilityAssignment()` (RFC-004 §17.4) — a narrow, evidence-based continuation of the Milestone-1 backfill posture for the one remaining moment a Workspace can come into existence without ever having passed through that backfill, never a general customer self-grant path.
- This CREATE-path lock introduces a real cross-operation deadlock possibility: the legacy path's new User→Workspace lock order is the exact inverse of `WorkspaceManager::transferOwnership()`'s existing Workspace→User(s) order. Rather than reordering either side's locks, both `BusinessManager::applyIdentity()`'s CREATE branch and `WorkspaceManager::transferOwnership()` wrap their transactions in Laravel's bounded automatic retry (`DB::transaction($closure, 3)` for the CREATE branch specifically — the update-existing-Business branch remains a single attempt; `transferOwnership()`'s own transaction is retried identically), so either side losing a genuine MySQL deadlock is retried cleanly from the start rather than surfacing an unhandled deadlock to the caller. See §15 below for the full locking/concurrency posture and its test evidence.
- The one intentional retirement is `WorkspaceFirstBusinessInput`/`createWorkspace()`'s optional third parameter, confirmed unused by any production caller before its removal (RFC-004 §17.5) — Workspace creation remains tenancy-only in production either way.

Every pre-existing Workspace receives a real plan assignment as part of this deploy's migration run (§6/§8 below) — there is no post-deploy manual step required to avoid an unassigned-Workspace state.

---

## 4. Deployment ordering

1. Deploy application code (this repository at the commit containing Milestones 1–3).
2. Run `php artisan migrate` — this executes all eight RFC-004 migrations in order (§5 below), including the seed and the backfill, as part of the same migration run.
3. Refresh the permission cache if your deployment process caches config (§7 below).
4. Run the smoke checks (§11–§13 below).
5. Run the regression commands (§14 below) against `ultimatesms_testing` before/as part of your normal release verification — never against the production database.

No separate manual backfill step is required — migration 8 (§5) invokes the backfill action directly during `php artisan migrate`.

---

## 5. Migration sequence

All eight RFC-004 migrations, in their real timestamp order, confirmed present by direct inspection:

| # | Migration | Purpose |
|---|---|---|
| 1 | `2026_08_13_120001_create_workspace_plan_catalog_table.php` | Schema — `workspace_plan_catalog` |
| 2 | `2026_08_13_120002_create_workspace_plan_features_table.php` | Schema — `workspace_plan_features` |
| 3 | `2026_08_13_120003_create_workspace_plan_assignments_table.php` | Schema — `workspace_plan_assignments` |
| 4 | `2026_08_13_120004_create_workspace_entitlement_overrides_table.php` | Schema — `workspace_entitlement_overrides` |
| 5 | `2026_08_13_120005_create_business_feature_toggles_table.php` | Schema — `business_feature_toggles` |
| 6 | `2026_08_13_120006_create_workspace_entitlement_transitions_table.php` | Schema — `workspace_entitlement_transitions` (durable audit) |
| 7 | `2026_08_13_120007_seed_workspace_plan_catalog_and_features.php` | Data — seeds Core/Growth/Agency catalog rows and the plan-feature matrix |
| 8 | `2026_08_13_120008_backfill_workspace_entitlement_assignments.php` | Data — invokes `WorkspaceEntitlementBackfillV1::run()` directly |

DDL (migrations 1–6) and data operations (7–8) are kept separate, matching RFC-003's own precedent — MySQL DDL is not part of the surrounding transaction the way DML is.

---

## 6. Catalog and feature-matrix seeding

Migration 7 seeds exactly three `workspace_plan_catalog` rows:

| Tier | Slots included | Slot max | Unlimited | Additional-slot ratio |
|---|---|---|---|---|
| `core` | 3 | 5 | no | `0.5000` |
| `growth` | 3 | 5 | no | `0.5000` |
| `agency` | 3 | `null` | yes | `null` |

**All three rows are seeded with `price` and `currency_id` left `null`** — RFC-004 does not invent commercial prices at implementation time (§9 below documents the operational consequence of this).

The plan-feature matrix is seeded as 9 rows for Core, 12 for Growth (Core's 9 plus `seo_module`/`google_ads_module`/`meta_ads_module`), and 15 for Agency (Growth's 12 plus `white_label`/`agency_package_capabilities`/`prospect_outreach`). The seed is idempotent: each catalog row and each plan-feature mapping is inserted only if missing, never re-inserted or overwritten, so re-running this migration after a rollback/reapply never clears a since-set price/currency value.

**A seeded plan-feature mapping is a packaging fact only** — it does not by itself make a feature executable. `PlatformFeatureRegistry` (a code-only registry, not a database table) separately marks `crm`, `conversations`, and `automations` as `Available` and every other feature key, including `prospect_outreach` and `white_label`, as `Planned`. A `Planned` feature is denied with `platform_feature_unavailable` regardless of which tier's matrix includes it.

---

## 7. Permission deployment

`config/permissions.php` (an existing, already-deployed file, not a new one) gains exactly two new keys, both under a `Workspace Plans` category distinct from the legacy `Plan` category:

- `view workspace plans`
- `manage workspace plans`

**If your deployment process runs `php artisan config:cache`**, that cache must be refreshed (`php artisan config:clear && php artisan config:cache`, or your normal cache-refresh step) after this deploy so the two new keys are picked up — confirmed necessary because `config/permissions.php` is a real, already-cached config file this deploy modifies, unlike RFC-003, which introduced no config file at all.

**Queue/worker restart: not applicable.** Direct inspection (`grep -rl ShouldQueue app/Events/Entitlement app/Library/Entitlement app/Listeners`) found zero matches — no RFC-004 event, manager, or listener implements `ShouldQueue`. Every entitlement mutation is synchronous, inside the same request/transaction that performs it. No queue worker or Horizon supervisor needs to be restarted for this deploy.

---

## 8. `WorkspaceEntitlementBackfillV1` behavior

`app/Library/Entitlement/Migration/WorkspaceEntitlementBackfillV1.php`, invoked directly by migration 8 (not via the console command) during `php artisan migrate`:

- **Query-builder-only** — no Eloquent model or model event is used anywhere in this class.
- **Chunked, ascending-id cursor** (page size 500) over every Workspace lacking a `workspace_plan_assignments` row, so memory usage stays flat regardless of table size.
- For each such Workspace, inserts a `workspace_plan_assignments` row: tier **Core**, `status = active`, `is_complimentary = true`, a fixed `complimentary_reason` string, `complimentary_granted_by_user_id = null` (system provenance, not an admin actor), and `additional_business_slots` derived from that Workspace's **existing** Business-row count at backfill time — `0` for ≤3, `1` for exactly 4, `2` for ≥5 — plus a matching `plan_assigned` row in `workspace_entitlement_transitions`.
- **Idempotent, safe under partial rerun**: a Workspace that already has an assignment row is skipped entirely. Concurrency safety relies on the unique `workspace_id` constraint on `workspace_plan_assignments` — a losing concurrent insert's `QueryException` is narrowly matched against MySQL driver error code `1062` **and** the exact constraint name `workspace_plan_assignments_workspace_id_unique` before being treated as "already assigned, not a real failure"; every other database error (FK violation, NOT NULL violation, an unrelated unique violation, a connection error) is rethrown unmodified, never broadly suppressed.
- **Final zero-unassigned assertion**: after processing every page, the action re-counts Workspaces still lacking an assignment and throws `WorkspaceEntitlementBackfillIncompleteException` (carrying the exact remaining count) if that count is non-zero — a failed migration run is therefore unambiguous, never a silent partial state.

**Exact standalone command**, for a manual re-run or verification outside the migration itself:

```bash
php artisan workspaces:backfill-entitlements
```

(`app/Console/Commands/BackfillWorkspaceEntitlementsCommand.php`, signature `workspaces:backfill-entitlements`, confirmed by direct inspection — a thin wrapper around the same `WorkspaceEntitlementBackfillV1::run()` call migration 8 makes, with no algorithm of its own.) Running it again after migration 8 has already completed is a safe no-op, per the idempotence guarantee above.

---

## 9. Preservation of existing Businesses; grandfathered-over-capacity behavior

**No existing Business is ever deleted, deactivated, or hidden by the backfill** — `WorkspaceEntitlementBackfillV1::processWorkspace()` performs exactly one `SELECT COUNT(*)` against `businesses` and zero writes to that table anywhere in the class.

A pre-existing Workspace with **more than 5 Businesses** (possible under RFC-003's own unlimited-slot era) is not treated as an error: the backfill assigns `additional_business_slots = 2` (the representable Core/Growth maximum) and keeps every Business. This Workspace becomes **grandfathered-over-capacity**: `decideBusinessSlotCapacity()` correctly reports the Workspace already at or above its effective capacity, so any *new* Business-creation attempt is denied (`business_slot_limit_exceeded`) going forward, until the Workspace is upgraded to Agency, granted further allocation where the tier's maximum allows it, or its Business-row count falls — which, per RFC-004 §13/§25.4, can only happen through an authorized reassignment to a different Workspace, never through Business deactivation. **This is expected, deterministic, non-blocking backfill behavior, not a failure condition.**

Every allocated slot from this backfill, including for an already-over-capacity Workspace, is complimentary — RFC-005's future usage-billing work must not later interpret it as unpaid recurring-slot debt (RFC-004 §13/§25.4).

**Zero-unassigned verification**, if you want to confirm the backfill's own final-assertion guarantee independently after deploy:

```sql
SELECT COUNT(*) FROM workspaces w
LEFT JOIN workspace_plan_assignments wpa ON wpa.workspace_id = w.id
WHERE wpa.id IS NULL;
```

Expected result: `0`. A non-zero count here after `php artisan migrate` has completed indicates migration 8 did not run to completion — re-run `php artisan migrate` (idempotent, per §8 above) rather than manually inserting assignment rows.

**Operational note on catalog pricing — corrected in this round.** Every seeded catalog row's `price`/`currency_id` are `null` by default (§6). This is a valid, intentional state — a complimentary assignment (which every backfilled assignment is) never requires catalog pricing at all, and remains fully usable indefinitely with unpriced catalog rows. Attempting a **non-complimentary** assignment against an unpriced catalog row fails closed with `UndefinedPlanPricingException`, by design — this remains fail-closed until pricing exists.

**`EntitlementManager::updateCatalogPricing()` is the only method that can set that pricing, but Milestone 3 deliberately shipped no HTTP endpoint for it** — confirmed directly: no route in `routes/admin.php` calls it, and no admin controller action exposes it (RFC-004 §18/M3 contract scope explicitly excludes catalog-pricing mutation from the HTTP surface). This means:

- There is currently **no supported operator-facing way** to set real, non-null catalog pricing in this deployment.
- **Do not improvise pricing through raw production SQL** against `workspace_plan_catalog.price`/`currency_id` — doing so bypasses `updateCatalogPricing()`'s own validation (the both-null-or-both-populated pairing, the exact-decimal normalization, the in-use protection against clearing a price still referenced by a non-complimentary assignment) and can leave the table in a state `EntitlementManager` itself would never have written.
- Before ordinary **non-complimentary** plan assignment can be operationalized for real customers, either a trusted manager-backed operator tool (an artisan command or `tinker` invocation that calls `EntitlementManager::updateCatalogPricing()` directly, never raw SQL) or a later, separately authorized HTTP surface for catalog-pricing mutation is required. Until then, this deployment's plan-assignment capability is limited in practice to **complimentary** assignments, which is the only kind the Milestone-1 backfill itself ever created and the only kind exercisable through the admin surface's `assignPlan()` action without first solving this gap.

---

## 10. Database constraints / integrity enforcement

Confirmed by direct inspection of all six schema migrations:

- `workspace_plan_catalog.tier` — unique.
- `workspace_plan_features` — unique `(workspace_plan_catalog_id, feature_key)`.
- `workspace_plan_assignments.workspace_id` — unique (at most one assignment per Workspace).
- `workspace_entitlement_overrides` — unique `(workspace_id, feature_key)`.
- `business_feature_toggles` — unique `(business_id, feature_key)`.
- Every foreign key across all six tables (`workspace_id`, `workspace_plan_catalog_id`, `currency_id`, `business_id`, `from_plan_catalog_id`, `to_plan_catalog_id`) is `restrictOnDelete()` — none uses `cascade`, deliberately the opposite posture from the legacy `plans`/`subscriptions` schema.
- No native database `ENUM` column exists anywhere in this schema — every enum-shaped column is a plain `varchar`/`string`, cast to a string-backed PHP enum at the application layer.

---

## 11. Admin smoke checks

Behind the existing `EnsureUserIsAdministrator` boundary, with the two new permission keys (§7):

1. An admin with `access backend` + `view workspace plans` can open the workspace-plan-catalog index and see exactly three tiers (Core/Growth/Agency) with their seeded structural fields — but sees no mutation form.
2. The same admin, opening a Workspace's own admin page, sees its "Plan & Entitlement" read card — but not the "Mutate Plan" card, unless they also hold `manage workspace plans`.
3. An admin with `manage workspace plans` (with or without `view workspace plans`) sees the "Mutate Plan" card and can assign a first (complimentary) plan to an unassigned Workspace, change its tier/status, grant/revoke complimentary status, allocate additional slots, and create/revert a Workspace entitlement override.
4. A non-admin account, even with `access backend`/`view workspace plans`/`manage workspace plans` forged into its session, is still blocked — confirming the independent `EnsureUserIsAdministrator` boundary is not bypassable by permission alone.

---

## 12. Customer visibility and Business-feature preference smoke checks

1. A Workspace Owner or active Admin sees a new "Plan & Capacity" card on their Workspace overview, showing the assigned tier, plan feature list, and the full included/additional/effective Business-slot breakdown — sourced directly from `EntitlementManager`, never recomputed in the view.
2. An active Staff member sees **no** entitlement data at all on the same page — not an empty card, the `entitlement` view-data key itself is absent, matching the existing Staff-omission precedent for the membership directory.
3. An Owner/active Admin can record a "disable preference" for a currently-entitled feature on one of their Businesses, and remove it again — every rendered row carries the fixed notice "Runtime enforcement pending. This preference is stored at the Business level but the legacy module does not yet consult it," never implying a live on/off switch.
4. A recorded disable preference still shows a "Remove disable preference" action even when the feature becomes separately denied by an unrelated Workspace override — proving the preference state is never inferred from the effective decision.

---

## 13. Plan/status/complimentary/slot/override verification

Exercise directly against a test Workspace (never production data) before considering this deploy fully verified:

1. Assign a first plan (Core, complimentary) to an unassigned Workspace — succeeds, writes one `plan_assigned` transition row.
2. Attempt to create a 4th Business without an additional-slot allocation — denied with `business_slot_allocation_required`. Allocate one additional slot; the 4th Business now succeeds.
3. Change the Workspace's status to `suspended` — every feature-gated `decide()` call for that Workspace now denies with `plan_suspended`, and a `plan_status_changed` transition row is written with the correct `from_status`/`to_status`. Restore to `active`.
4. Create a Workspace `deny` override for `crm` (an already-included, base-packaged `Available` feature on every seeded tier) — `decide()` now returns `denied_by_workspace_override` for `crm` on that Workspace instead of the plan-derived allow. Revert the override — the decision returns to the plan-mapping-derived allowed state. Confirm the corresponding `entitlement_override_denied`/`entitlement_override_reverted` rows were written to `workspace_entitlement_transitions`. **(An `allow`-override-outside-base-plan smoke check is not operationally possible against a normally-seeded deployment:** with the seeded catalog (§6), `crm`/`conversations`/`automations` — the only three currently-`Available` features — are already packaged into every tier, including Core, so there is no `Available` feature outside base-plan packaging for a real deployed Workspace to demonstrate an `allow` override against. That specific behavior is proven in the automated test suite's own isolated fixture instead, by `EntitlementManagerPresentationTest::test_override_outside_base_plan_packaging_is_still_returned`, which removes Core's `crm` packaging row inside that one test's own transaction only — never in a real deployment's production catalog data.)
5. Change the Workspace's tier from Core to Growth — `additional_business_slots` is preserved unchanged; change from Growth to Agency — it resets to `0` atomically in the same operation, with both a `plan_changed` and an `additional_business_slots_changed` transition row written.

---

## 14. Final regression commands

Verified present and non-empty in this repository before locking these commands: `tests/Unit/Entitlement`, `tests/Feature/Entitlement`, `tests/Unit/Workspace`, `tests/Feature/Workspace`, `tests/Unit/Business`, `tests/Feature/Business`, `tests/Unit/Opportunity`, `tests/Feature/Opportunity`.

```bash
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test tests/Unit/Business tests/Feature/Business
php artisan test tests/Unit/Opportunity tests/Feature/Opportunity
php artisan test --stop-on-failure
```

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure. Run only against the disposable `ultimatesms_testing` database. See `docs/automation/RFC-004-M4-CONFORMANCE.md` for this Milestone's actual recorded results.

---

## 15. Operational failure handling

- A failed migration 8 run (backfill) is unambiguous: `WorkspaceEntitlementBackfillIncompleteException` is thrown with the exact remaining unassigned count. Re-running `php artisan migrate` (or `php artisan workspaces:backfill-entitlements` directly) is safe and resumes correctly — already-assigned Workspaces are skipped, never duplicated.
- A rejected entitlement mutation (`UndefinedPlanPricingException`, `PlanCatalogPricingInUseException`, `WorkspacePlanUnassignedException`, `WorkspacePlanAlreadyAssignedException`, `InvalidAdditionalBusinessSlotsException`, `BusinessSlotAllocationRequiredException`, `BusinessSlotLimitExceededException`, `UnavailablePlatformFeatureOverrideException`) always leaves the database in its prior, consistent state — every mutation runs inside one `DB::transaction()` closure per `EntitlementManager` method, confirmed by direct inspection.
- **Locking/deadlock posture, corrected in this round — the prior claim of "no new lock, no new deadlock class introduced" was false and is withdrawn.** The actual posture:
  - Two concurrent requests racing a Workspace's last available Business slot via `createBusinessInWorkspace()` and/or `reassignBusiness()` serialize on the same `findForUpdate()` Workspace-row lock RFC-003 already uses for every other Workspace mutation.
  - The legacy onboarding CREATE path (§3 above) added a genuinely new, explicit Workspace-row lock (`WorkspaceManager::lockForLegacyOnboardingBusinessCreation()`) that did not exist before RFC-004.
  - That new lock's acquisition order (User row, then Workspace row) is the **exact inverse** of `WorkspaceManager::transferOwnership()`'s existing order (Workspace row, then User row(s)) — a real, new, cross-operation MySQL deadlock class that did not exist before this integration.
  - Rather than reordering either side's locks, `BusinessManager::applyIdentity()`'s CREATE branch and `WorkspaceManager::transferOwnership()` each wrap their transaction in a bounded, automatic 3-attempt retry (`DB::transaction($closure, 3)`), so a genuine deadlock between the two is retried cleanly from the start instead of surfacing to the caller. Test evidence: `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php::test_scenario_9_legacy_onboarding_vs_transfer_ownership` directly proves no unhandled deadlock escapes this specific interaction.
  - Catalog-pricing mutation races (`updateCatalogPricing()` against a concurrent non-complimentary assignment/allocation) and the reassign-vs-Business-toggle interaction (`disableBusinessFeature()`'s source-Workspace-then-Business lock order versus `reassignBusiness()`'s own multi-Workspace ascending-order lock) each have their own documented locking boundary, exercised directly by further scenarios in the same `EntitlementManagerConcurrencyTest.php` file — they are not identical to the final-slot race above and are not claimed to be.

---

## 16. Shared-hosting / no-shell considerations

If shell access to run `php artisan migrate`/`php artisan test` is unavailable, migrations 1–8 (including the seed and backfill) still run automatically through any control-panel "run migrations" action that ultimately invokes Laravel's migration runner — no separate manual step is required beyond that, since migration 8 invokes the backfill action directly rather than requiring the console command to be run by hand. The `php artisan workspaces:backfill-entitlements` command (§8) exists only as an optional, safe, idempotent manual re-run/verification tool — it is never required for a normal deploy to complete successfully.

---

## 17. Rollback / recovery posture

**Corrected in this round — the prior version of this section dangerously overstated what restrictive foreign keys actually protect during a rollback. Read this section in full before ever running a migration rollback against a deployment where RFC-004 has been live.**

**A routine `php artisan migrate:rollback` of the batch containing RFC-004's migrations is destructive after any live RFC-004 use, and this is not prevented by the schema's foreign keys.** The mechanics, verified directly:

- Migrations 8 and 7's `down()` methods are documented, intentional no-ops (migration 8: reverting a completed backfill could delete real assignment/transition rows created afterward by ordinary application use, indistinguishable from backfill-authored ones; migration 7: deleting the seed rows would remove catalog rows a completed backfill's assignments still reference). **This does not stop a batch rollback** — Laravel runs every migration's `down()` in one batch in reverse order regardless of whether an individual one is a no-op. After 8 and 7 do nothing, rollback continues straight through **6 → 5 → 4 → 3 → 2 → 1**.
- Migrations 6 down to 1 each perform an ordinary `Schema::dropIfExists()` on their own table. Run in that reverse order, this **drops the six RFC-004 tables in exactly the order their own outgoing foreign keys permit** — `workspace_entitlement_transitions` (6) references `workspaces`/`workspace_plan_catalog` but nothing drops those tables yet, so it drops cleanly; the same holds all the way down to `workspace_plan_catalog` (1) last. **The rollback succeeds mechanically, with no foreign-key error at any step, and erases every `workspace_plan_assignments`, `workspace_entitlement_transitions`, `workspace_entitlement_overrides`, `business_feature_toggles`, `workspace_plan_features`, and `workspace_plan_catalog` row in the database** — including every real admin/customer entitlement mutation made since deploy, not only backfill-authored data.
- **`restrictOnDelete()` protects row-level referenced-row deletion — it does not preserve data when the owning table itself is dropped.** A `restrictOnDelete()` foreign key on `workspace_plan_assignments.workspace_id` stops someone from deleting a `workspaces` row while an assignment still references it; it does nothing to stop `DROP TABLE workspace_plan_assignments` itself. **Do not rely on any foreign-key failure to interrupt a correctly-ordered batch rollback of these six tables — it will not happen.**

**Therefore:** after this deployment has run (migrations 1–8 applied) and any real entitlement mutation has occurred — the Milestone-1 backfill itself counts as real data — **do not run a routine `php artisan migrate:rollback` covering this batch.** Preferred recovery paths, in order of preference:

1. **Forward repair/redeploy.** Fix the underlying issue and deploy a new migration or code fix on top of the existing, additive RFC-004 schema — leave the six tables and their data intact rather than reverting them.
2. **Roll back application code only, while retaining the RFC-004 database schema and data.** This is likely safer than rolling back the database, since the six tables are purely additive and no RFC-001/RFC-002/RFC-003 code path depends on their absence — but it must still be reviewed for compatibility with whatever code version you are rolling back to (a rollback target predating Milestone 3, for example, simply will not reference the admin/customer entitlement surfaces, which is safe; a rollback target predating Milestone 1 has never heard of these tables at all, which is also safe, since nothing in that older code queries them).
3. **Any actual physical schema/data removal** (dropping the RFC-004 tables for a genuine, deliberate uninstall) requires a separately reviewed backup-and-data-migration plan, executed deliberately and with a verified backup in hand — never an automatic or routine migration rollback.

---

## 18. Release/tag verification

No RFC-004 tag exists yet, locally or on `origin`, as of this Milestone-4 pass (confirmed directly via `git tag -l` and `git ls-remote --tags origin`). The eventual `rfc-004-plans-and-business-feature-entitlements` annotated tag is created only after this Milestone-4 documentation PR is human-merged and the post-merge exact-tag-candidate regression (`php artisan test --stop-on-failure`, run again against the exact `main` commit about to be tagged) passes — both gated by explicit human authorization, never automatic, per `docs/automation/RFC-004-M4-CONTRACT.md` §§8–9. Once created, verify:

```bash
git cat-file -t rfc-004-plans-and-business-feature-entitlements   # must print "tag"
git cat-file -p rfc-004-plans-and-business-feature-entitlements   # must show a tagger line and the exact message
git ls-remote --tags origin | grep rfc-004-plans-and-business-feature-entitlements
```

---

## 19. Explicit RFC-005 deferral

This deploy introduces no billing, wallet, usage-metering, or Stripe behavior of any kind. `EntitlementManager`'s `UsageAuthorizationGateway` seam is bound to `NullUsageAuthorizationGateway`, which unconditionally returns `authorized: true` for every call — deterministic, non-blocking behavior until RFC-005 (Business Usage Billing and Wallets) exists and binds a real implementation. The additional-Business-slot **charge** itself is not collected by anything in this deploy — `EntitlementManager::setAdditionalBusinessSlots()`/`changePlan()` are the authoritative allocation mutations RFC-005's future checkout flow is expected to call once payment succeeds, but no such checkout flow exists yet. Do not deploy any RFC-005 code alongside this guide's scope.

---

## 20. Out of scope

- Any Stripe, payment-method, wallet, or usage-ledger deployment step (RFC-005).
- Setting real, non-null catalog pricing — a deliberate post-deploy operational decision, not part of this deploy (§9).
- Wiring `EntitlementManager::decide()` into any legacy module beyond what Milestone 3 already shipped — no additional runtime gating is introduced by this guide.
- Milestone 4's own conformance/tag process beyond §18 above — see `docs/automation/RFC-004-M4-CONTRACT.md` and `docs/automation/RFC-004-M4-CONFORMANCE.md` for that governance record.
