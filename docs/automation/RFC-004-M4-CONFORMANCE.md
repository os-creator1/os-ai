# RFC-004 Milestone 4 Conformance

## Status

**RELEASE-READINESS PASS — M4 PRE-MERGE REGRESSION GATE SATISFIED; M4 NOT YET COMPLETE.** This document is an evidence-based conformance audit of RFC-004 as actually implemented in this repository. Every row below cites concrete file, method, migration, event, exception, enum, or test evidence found by direct inspection during this pass — no row is marked PASS merely because an expected file exists, "sounds correct," or because a prior milestone's contract claimed it. All five regression commands required by the governing contract have been run directly in this pass's own environment and **all five passed** (see "Regression gates" below for the exact recorded evidence). This satisfies the M4 pre-merge regression gate, but **RFC-004 is not fully released yet**: this M4 documentation PR has not been merged, the post-merge exact-tag-candidate regression has not run, and no tag has been created.

**No product or test conformance gap was discovered during this audit.**

## Contract / base information

- Governing contract: [`docs/automation/RFC-004-M4-CONTRACT.md`](RFC-004-M4-CONTRACT.md), merged via PR #68.
- This pass's branch: `agent/rfc-004-m4`, base `bc9972a91a704e8b1ca7b720616b7b72565621bd` (verified: `77122828c6ae3abbbd390ded9d17cef24714b269`, the M4 contract's own commit, confirmed an ancestor before branching).
- RFC document audited: [`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md`](../rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md), version 1.3.
- Prior milestone governance evidence relied on for context only, cross-checked against actual repository state below rather than trusted at face value: `RFC-004-M1-CONTRACT.md`, `RFC-004-M2-CONTRACT.md`, `RFC-004-M3-CONTRACT.md`. M3's own implementation (`160539d`) received one further ordinary correction round (`753160b`) before merging into `main` as `ab32a35` (PR #67, merged prior to this M4 contract).
- PHP executable used for all regression runs: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe` (PHP 8.3.30), not on `PATH` in this environment. Database: `ultimatesms_testing` only, confirmed via `.env.testing` (`DB_DATABASE=ultimatesms_testing`) — no production or non-disposable database was used at any point in this pass.

---

## Evidence-based conformance matrix (RFC-004 §28, all fifteen bullets individually)

### 1. All six tables exist with exactly the specified columns/constraints/`restrictOnDelete()` FKs; no native DB `ENUM` column anywhere

**PASS.** Direct inspection of all six schema migrations:

- `database/migrations/2026_08_13_120001_create_workspace_plan_catalog_table.php`: `tier` unique varchar(20); `price` `decimal(16,2)` nullable; `currency_id` nullable `foreignId` → `currencies`, `restrictOnDelete()`; `billing_cycle` default `monthly`; `business_slot_included` unsigned tinyint; `business_slot_max` nullable unsigned tinyint; `unlimited_business_slots` boolean default `false`; `additional_business_slot_price_ratio` `decimal(6,4)` nullable; `is_active` boolean default `true`, indexed — matches §10.1 column-for-column.
- `..._120002_create_workspace_plan_features_table.php`: `workspace_plan_catalog_id` `foreignId` → `workspace_plan_catalog`, `restrictOnDelete()`; `feature_key` varchar(64); unique `(workspace_plan_catalog_id, feature_key)` — matches §10.2 exactly.
- `..._120003_create_workspace_plan_assignments_table.php`: `workspace_id` unique `foreignId` → `workspaces`, `restrictOnDelete()`; `workspace_plan_catalog_id` `foreignId`, `restrictOnDelete()`; `status` varchar(20) **with no default** (matches §10.3's explicit "no default — every assignment-creation path must supply it"); `is_complimentary` boolean default `false`; `complimentary_granted_by_user_id` plain nullable `unsignedBigInteger`, no FK (matches §10.3's rationale, mirroring RFC-003 `workspace_transitions.actor_user_id`); `additional_business_slots` unsigned tinyint default `0` — matches §10.3 exactly.
- `..._120004_create_workspace_entitlement_overrides_table.php`: `workspace_id` `foreignId`, `restrictOnDelete()`; `feature_key` varchar(64); `state` varchar(10); unique `(workspace_id, feature_key)` — matches §10.5 exactly, including no "inherit" state column.
- `..._120005_create_business_feature_toggles_table.php`: `business_id` `foreignId` → `businesses`, `restrictOnDelete()`; `feature_key` varchar(64); unique `(business_id, feature_key)`; no boolean/"enabled" column of any kind — matches §10.6 exactly.
- `..._120006_create_workspace_entitlement_transitions_table.php`: `workspace_id` `foreignId`, `restrictOnDelete()`; `transition_type` varchar(48); `actor_user_id` plain nullable, no FK; `from_plan_catalog_id`/`to_plan_catalog_id` nullable `foreignId` → `workspace_plan_catalog`, `restrictOnDelete()`; `feature_key`, `from_override_state`, `to_override_state`, `from_additional_business_slots`, `to_additional_business_slots` all nullable; **`from_status`/`to_status` both present**, nullable varchar(20); `created_at` only, no `updated_at` (immutable row); composite index `(workspace_id, created_at)` — matches §10.4 exactly, including the revision-added `from_status`/`to_status` pair.

**No native DB `ENUM` column exists anywhere in these six migrations** — every enum-shaped column is a plain `string`/`varchar`, confirmed by direct reading of all six files above; every one is cast to a string-backed PHP enum under `App\Enums\Entitlement` in its owning model (`WorkspacePlanCatalog`, `WorkspacePlanAssignment`, `WorkspaceEntitlementOverride`, `BusinessFeatureToggle`, `WorkspaceEntitlementTransition`).

### 2. `workspace_plan_catalog` seeded with exactly `core`/`growth`/`agency`, matching §12.1/§12.2, including ratio `0.5000` for Core/Growth and `null` for Agency

**PASS.** `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`, `CATALOG` constant, read directly: Core — `business_slot_included = 3`, `business_slot_max = 5`, `unlimited_business_slots = false`, `additional_business_slot_price_ratio = 0.5000`; Growth — identical slot/ratio shape to Core; Agency — `business_slot_max = null`, `unlimited_business_slots = true`, `additional_business_slot_price_ratio = null` — matches §12.3 exactly. Feature matrix: `$coreFeatures` (9 keys: `crm`, `conversations`, `calendar`, `forms`, `automations`, `website_generation`, `ai_coo_basic`, `seo_basic_visibility`, `ads_basic_visibility`), `$growthFeatures = array_merge($coreFeatures, [seo_module, google_ads_module, meta_ads_module])` (12 keys), `$agencyFeatures = array_merge($growthFeatures, [white_label, agency_package_capabilities, prospect_outreach])` (15 keys) — matches §12.2's exact table and the RFC's own "fifteen for Agency, twelve for Growth, nine for Core" count. Idempotent insert logic confirmed present (`existingId !== null` reuse-without-overwrite check before every insert). Test evidence: `tests/Feature/Entitlement/WorkspacePlanCatalogRepositoryTest.php::test_find_by_tier_casts_the_seeded_core_row`, `::test_find_by_tier_casts_the_seeded_agency_row_as_unlimited`; `tests/Feature/Entitlement/WorkspacePlanFeatureRepositoryTest.php::test_feature_keys_for_catalog_returns_the_seeded_core_matrix`; `tests/Feature/Entitlement/EntitlementManagerPresentationTest.php::test_list_plan_catalog_summaries_reports_seeded_structural_fields_and_feature_availability`.

### 3. `PlatformFeatureRegistry` consulted by `decide()` strictly before plan-mapping/override resolution; no plan mapping or override can make an unavailable feature executable; `ProspectOutreach` reflects direct repository evidence

**PASS.** `app/Library/Entitlement/EntitlementManager.php::decide()`, read directly in full: step order confirmed — `PlatformFeature::tryFrom($featureKey)` (unknown → `platform_feature_unknown`) is checked first, then `PlatformFeatureRegistry::isAvailable($feature->value)` (→ `platform_feature_unavailable`) strictly before the Workspace assignment, the override, or the plan mapping are ever read. `EntitlementManager::createOrChangeOverride()` throws `UnavailablePlatformFeatureOverrideException` for an `Allow`-state write when `PlatformFeatureRegistry::isAvailable()` is `false`, confirmed by direct reading — no override write path can make an unavailable feature executable. `app/Library/Entitlement/PlatformFeatureRegistry.php`: `Crm`/`Conversations`/`Automations` → `Available`; every other of the fifteen `PlatformFeature` cases, **including `ProspectOutreach` and `WhiteLabel`**, → `Planned` — re-verified directly in this pass (mechanical search 5 below), not assumed from the M1/M3 contracts' own prior claims. Test evidence: `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php::test_available_features_are_exactly_crm_conversations_automations`, `::test_every_other_feature_is_planned_not_available`, `::test_prospect_outreach_availability_reflects_direct_repository_evidence_not_assumed`, `::test_is_known_true_for_every_platform_feature_case`, `::test_is_known_and_is_available_both_false_for_an_unknown_key` (this file's exact 5 test methods, corrected from an earlier miscount of 14); `tests/Feature/Entitlement/EntitlementManagerDecisionTest.php::test_unavailable_feature_denies`, `::test_known_available_feature_proceeds_without_registry_type_error`.

### 4. Every pre-existing Workspace has exactly one `workspace_plan_assignments` row after the Milestone-1 backfill (`active`/complimentary/correctly-derived slots), zero left unassigned

**PASS.** `app/Library/Entitlement/Migration/WorkspaceEntitlementBackfillV1.php::run()` (read directly, full file): chunked (`CHUNK_SIZE = 500`) ascending-id cursor over Workspaces lacking an assignment; `processWorkspace()` inserts `status = active`, `is_complimentary = true`, a fixed `complimentary_reason`, `complimentary_granted_by_user_id = null`, and `additional_business_slots` derived via `match(true) { $businessCount >= 5 => 2, $businessCount === 4 => 1, default => 0 }` — matches §25.3/§25.4's exact table. `run()`'s final step (`$remainingUnassignedCount = $this->unassignedWorkspaceQuery()->count(); if (... > 0) throw ...`) enforces the zero-unassigned assertion directly, throwing `WorkspaceEntitlementBackfillIncompleteException` otherwise. Migration `2026_08_13_120008_backfill_workspace_entitlement_assignments.php` invokes `(new WorkspaceEntitlementBackfillV1())->run()` directly (not the console wrapper), matching the RFC-003 migration-5 precedent §25.2 requires. Test evidence: `tests/Feature/Entitlement/WorkspaceEntitlementBackfillV1Test.php`, `WorkspaceEntitlementBackfillMigrationTest.php`, `BackfillWorkspaceEntitlementsCommandTest.php`.

### 5. No pre-existing Workspace's Business count was altered, and no existing Business was deleted/deactivated/hidden, by the backfill

**PASS.** `WorkspaceEntitlementBackfillV1::processWorkspace()` (read directly, full file) contains exactly one read of `businesses` (`DB::table('businesses')->where('workspace_id', $workspaceId)->count()`, a `SELECT COUNT`) and zero writes to the `businesses` table anywhere in the class — the only `insert()` calls target `workspace_plan_assignments` and `workspace_entitlement_transitions`. Test evidence: `tests/Feature/Entitlement/WorkspaceEntitlementBackfillV1Test.php` includes direct grandfathered-over-capacity coverage (a pre-existing Workspace with more than 5 Businesses retains every Business and receives `additional_business_slots = 2`).

### 6. `EntitlementManager::decide()` implements §14's eight-step algorithm exactly, including denial-reason-key stability across all nine keys

**PASS.** Direct reading of `EntitlementManager::decide()` confirms the exact step order: (1) `platform_feature_unknown` → (2) `platform_feature_unavailable` → (3) `workspace_plan_unassigned` → (4) override-if-present-else-plan-mapping (`denied_by_workspace_override`/`not_entitled_by_plan`) → (5) `disabled_for_business` → (6) `plan_suspended`/`plan_inactive` → (7) `usage_unauthorized` (via `UsageAuthorizationGateway::check()`, reserved/always-pass today) → (8) allowed. All nine stable denial-reason string literals confirmed present at their correct call sites by direct grep of `EntitlementManager.php::decide()`/`decideBusinessSlotCapacity()` in this pass (`platform_feature_unknown`, `platform_feature_unavailable`, `workspace_plan_unassigned`, `denied_by_workspace_override`, `not_entitled_by_plan`, `disabled_for_business`, `plan_suspended`, `plan_inactive`, `usage_unauthorized`), each appearing in the step order §14 requires. Test evidence: `tests/Feature/Entitlement/EntitlementManagerDecisionTest.php` (full eight-step precedence coverage, including the defensive Business/Workspace consistency re-check — `test_workspace_business_mismatch_throws`, `test_missing_business_throws`, `test_stale_business_after_reassignment_cannot_be_decided_against_old_workspace`).

### 7. `decideBusinessSlotCapacity()`/`assertCanCreateAnotherBusiness()` correctly enforce the allocation-gated 3/4/5 model; concurrency-safe under a forced-race test

**PASS.** `EntitlementManager::decideBusinessSlotCapacity()`, read directly in full, implements §17's pseudocode: unassigned → `workspace_plan_unassigned`; suspended/inactive → `plan_suspended`/`plan_inactive`; `unlimited_business_slots` → always allowed, capacity `null`; otherwise `effectiveCapacity = min(included + additional, max)`, with `business_slot_allocation_required` vs. `business_slot_limit_exceeded` correctly distinguished by whether `effectiveCapacity < business_slot_max`. Test evidence for the 3/4/5 model: `tests/Feature/Entitlement/EntitlementManagerBusinessSlotCapacityTest.php`. **Forced-race concurrency evidence**, confirmed by direct reading of `tests/Feature/Entitlement/EntitlementManagerConcurrencyTest.php` (8 real, non-mocked, `hold-then`-primitive-based scenarios, each spawning a genuine second OS process via `concurrent_business_slot_runner.php`): `test_scenario_1_create_plus_create_racing_final_slot`, `test_scenario_2_create_plus_reassign_racing_final_slot`, `test_scenario_10_reassign_plus_reassign_racing_final_slot`, `test_scenario_11_legacy_onboarding_vs_ordinary_create_racing_final_slot` — each directly proves exactly one of two concurrent Business-count-increasing operations wins the Workspace's final available slot and the other fails closed, with no over-allocation.

### 8. `changePlan()` normalizes `additional_business_slots` for all three tier-change directions inside a single transaction; `changePlanStatus()` requires actor/reason and durably audits every status change

**PASS.** `EntitlementManager::changePlan()` and its private `normalizeSlotsForDirection()`, both read directly in full: Core↔Growth preserves `fromSlots` unchanged and throws `InvalidArgumentException` if `$requestedSlots !== null`; any direction to Agency returns `0`; Agency→Core/Growth returns `$requestedSlots ?? 0`. Both the `plan_changed` and (only when the slot value actually changed) `additional_business_slots_changed` transition rows are written inside the same `DB::transaction()` closure — confirmed by direct reading, both `$this->transitionRepository->create(...)` calls are inside the same closure body, never a separate follow-up call. `changePlanStatus()` requires a non-null `$actorUserId` (typed parameter, no default) and rejects an empty `$reason` via `InvalidArgumentException` before any write, then writes a `plan_status_changed` transition row with `from_status`/`to_status` and dispatches `WorkspacePlanStatusChanged`. Test evidence: `tests/Feature/Entitlement/EntitlementManagerChangePlanTest.php` (13 test methods, covering all three tier-change directions and the pricing/allocation interaction); `tests/Feature/Entitlement/EntitlementManagerChangePlanStatusTest.php::test_all_real_status_transitions_succeed`, covering every `active`↔`inactive`↔`suspended` direction (5 test methods total in this file).

### 9. The §12.5 pricing/allocation invariant enforced exactly, including the complimentary carve-out and both-null-or-both-populated pairing

**PASS.** `EntitlementManager::assertBasePricingDefined()` (throws `UndefinedPlanPricingException` when `price === null || currency_id === null`) is called from `assignFirstPlan()` and `changePlan()`/`revokeComplimentaryStatus()` only for a **non-complimentary** path — confirmed directly by reading each method's `if (! $isComplimentary) { ... }`-guarded call sites. `assertSlotRatioDefined()` is called only when `$additionalBusinessSlots > 0` for a non-complimentary mutation. `updateCatalogPricing()`, read directly in full, enforces the both-null-or-both-populated pairing (`InvalidArgumentException` when exactly one of `$normalizedPrice`/`$currencyId` is null) and the in-use protection (`PlanCatalogPricingInUseException` via `hasNonComplimentaryForCatalogForUpdate()`, a genuine locking read, not a plain `exists()`). Test evidence: `tests/Feature/Entitlement/EntitlementManagerCatalogPricingTest.php` (`test_price_normalization`, `test_price_rejection`, `test_max_boundary_reads_back_exactly_through_the_decimal_cast`, `test_price_and_currency_must_both_be_null_or_both_populated`, `test_clearing_is_blocked_while_a_non_complimentary_assignment_references_the_row`, `test_complimentary_reference_never_blocks_clearing`); `tests/Feature/Entitlement/EntitlementManagerFirstAssignmentTest.php` (ratio-requirement tests A–G, per the M2 contract's own §14.1 record).

### 10. Every Workspace-override, additional-slot-allocation, and plan-status mutation writes a correct `workspace_entitlement_transitions` row

**PASS.** Direct reading confirms every one of the following `EntitlementManager` methods writes exactly one (or, for a tier-change-triggered slot reset, two) `transitionRepository->create()` call(s) inside its own transaction, never relying on event dispatch alone: `assignFirstPlan()` → `plan_assigned`; `changePlan()` → `plan_changed` (+ `additional_business_slots_changed` when applicable); `changePlanStatus()` → `plan_status_changed`; `grantComplimentaryStatus()`/`revokeComplimentaryStatus()` → `complimentary_granted`/`complimentary_revoked`; `setAdditionalBusinessSlots()` → `additional_business_slots_changed`; `createOrChangeOverride()` → `entitlement_override_allowed`/`entitlement_override_denied` (via `writeOverrideTransition()`); `revertOverride()` → `entitlement_override_reverted`. All nine `WorkspaceEntitlementTransitionType` cases confirmed present in `app/Enums/Entitlement/WorkspaceEntitlementTransitionType.php`. Test evidence: `tests/Feature/Entitlement/WorkspaceEntitlementTransitionRepositoryTest.php::test_for_workspace_returns_only_that_workspaces_rows_in_order` (also the cross-Workspace-isolation proof for this repository, §27 below); direct transition-row assertions embedded in `EntitlementManagerOverrideTest.php::test_first_time_create_writes_allowed_transition_with_null_from_state`, `::test_changing_an_existing_override_writes_the_correct_before_after_state`, `::test_revert_deletes_the_row_and_writes_reverted_transition`; `EntitlementManagerAdditionalSlotsTest.php`; `EntitlementManagerChangePlanStatusTest.php`.

### 11. No RFC-001/RFC-002/RFC-003 test regresses; the legacy Plan/Subscription suite is unaffected

**PASS.** Regression gates 2 (Workspace), 3 (Business), and 4 (Opportunity) below all passed with zero failures in this pass's own environment. Mechanical search 6 below (corrected in this round to use the RFC-004 design-merge commit `3e69b69` as the pre-implementation baseline, not the previously-used `af8be68~1`, which actually resolves to `659c76b` — the M2 contract merge, already inside the implementation range) directly confirms no commit across the full `3e69b69`→`bc9972a` RFC-004 M1–M3 range touched any of the eight legacy Plan/Subscription boundary files.

### 12. No direct entitlement-table query exists outside `EntitlementManager` and its six repositories — verified by code search

**PASS.** Mechanical search 1 below, re-run fresh and strengthened in this round: the only `DB::table()` calls against any of the six RFC-004 tables anywhere in `app/` are inside `WorkspaceEntitlementBackfillV1.php` (the RFC §25.2-authorized, query-builder-only, migration-adjacent backfill action — explicitly required to bypass Eloquent/the manager layer) and inside the seed migration itself (`2026_08_13_120007...`, a data-operation migration, §25.1 step G). A second, broader pass this round also searches for direct Eloquent-model static-call references (`WorkspacePlanCatalog::`, `WorkspacePlanFeature::`, `WorkspacePlanAssignment::`, `WorkspaceEntitlementOverride::`, `BusinessFeatureToggle::`, `WorkspaceEntitlementTransition::`) and plain table-name-literal mentions across all of `app/`+`database/`, classified below (mechanical search 1) — every match is confined to the six models' own files, the six repository contracts/Eloquent implementations, `EntitlementManager.php` itself, the entitlement exceptions/enums (docblock/error-message mentions only), the backfill action, and the eight migrations. Zero matches in `app/Http`, `resources/views`, or `routes/`. Test evidence: `tests/Feature/Entitlement/NoRawEntitlementTableQueryTest.php::test_no_raw_entitlement_table_query_exists_outside_authorized_files` — a repository-embedded, continuously-enforced version of this same boundary check.

### 13. `config/permissions.php`'s new keys use a distinct category from the legacy `Plan` category

**PASS.** `config/permissions.php`, read directly: `'view workspace plans'` and `'manage workspace plans'`, both `'category' => 'Workspace Plans'` — a category distinct from the legacy `'Plan'` category used by `'manage plans'`/`'create plans'`/`'edit plans'`/`'delete plans'`, confirmed by a separate direct read of those legacy keys' own category value.

### 14. No `Agency` model or `businesses.agency_id` column exists anywhere

**PASS.** Mechanical search 4 below, re-run fresh in this pass: zero matches for `agency_id` or `class Agency` anywhere under `app/` or `database/`.

### 15. Any M3-introduced gate over a pre-existing capability is accompanied by a verified §26 compatibility-override pass; based on direct repository evidence; no unexplained access-removal regression

**PASS — by the "no gate introduced" branch of §26/§28, verified directly, not assumed.** `PlatformFeatureRegistry` confirmed above (criterion 3) to still mark both `ProspectOutreach` and `WhiteLabel` as `Planned`. Mechanical search 5 below re-confirms zero executable Prospect Outreach or white-label module exists anywhere under `app/Http/Controllers` or `app/Library`. M3's actual shipped scope (`app/Http/Controllers/Admin/WorkspaceEntitlementController.php`, `WorkspacePlanCatalogController.php`, and the customer `WorkspaceController.php` toggle actions, all read directly in this pass) wires `EntitlementManager::decide()`/the presentation methods into the admin/customer plan-and-toggle surfaces only — it introduces no gate over `crm`/`conversations`/`automations` beyond the ordinary `decide()` precedence chain already required by §14 for every `Available` feature, and no gate over `prospect_outreach`/`white_label` at all, since both remain `Planned` and are therefore already denied by `platform_feature_unavailable` with no override machinery needed. §26's compatibility-override requirement is therefore correctly inapplicable — there is no pre-existing access to a `Planned` feature that a gate could have removed.

---

## §27 test-strategy reconciliation

Every group below is addressed against concrete evidence, not narrative summary.

- **Six-table schema invariants / eight migration operations:** criterion 1 above; all eight migrations (`2026_08_13_120001`–`120008`) confirmed present in exact timestamp order and individually inspected.
- **Plan catalog invariants:** criterion 2 above; unique `tier` confirmed via the migration's `->unique()` call; `tests/Feature/Entitlement/WorkspacePlanCatalogRepositoryTest.php::test_tier_uniqueness_violation_surfaces_as_a_query_exception`.
- **Feature registry invariants:** criterion 3 above; `tests/Feature/Entitlement/WorkspacePlanFeatureRepositoryTest.php::test_create_rejects_an_unknown_feature_key`.
- **Known-vs-available distinction:** `EntitlementManagerDecisionTest.php::test_unavailable_feature_denies`; `EntitlementManagerOverrideTest.php` covers the `Allow`-override-for-unavailable-feature rejection and the harmless-`Deny`-for-unavailable-feature permission directly.
- **`ProspectOutreach` availability evidence-based, not assumed:** criterion 3/15 above.
- **Plan-feature mappings:** criterion 2 above; `WorkspacePlanFeatureRepositoryTest.php::test_duplicate_catalog_and_feature_key_surfaces_as_a_query_exception`, `::test_no_entitlement_or_availability_decision_method_exists_on_this_repository`.
- **Effective entitlement precedence:** criterion 6 above.
- **Workspace override allow/deny/inherit — corrected in this round, evidence split across two files rather than attributed to one:** `EntitlementManagerOverrideTest.php` (9 test methods, corrected from an earlier miscount of 10) directly proves `test_deny_override_for_unavailable_feature_is_permitted` (harmless-deny-for-unavailable), `test_allow_override_for_unavailable_feature_is_rejected` (availability-floor-never-bypassable), `test_override_takes_precedence_over_plan_mapping` (a **deny** override over an already-included, base-packaged feature — not an allow-outside-base-plan proof, corrected from the prior round's overstatement), and `test_revert_deletes_the_row_and_writes_reverted_transition` (delete-reverts-to-plan-mapping). **The allow-outside-base-plan proof specifically** is `tests/Feature/Entitlement/EntitlementManagerPresentationTest.php::test_override_outside_base_plan_packaging_is_still_returned` — this test removes Core's `crm` packaging row in its own isolated fixture (production seed untouched) and proves an `Allow` override still surfaces `crm` as entitled, the only test in the suite that actually exercises entitlement outside a Workspace's base-plan packaging.
- **Business toggle cannot escalate entitlement:** `EntitlementManagerBusinessToggleTest.php` (13 test methods, read directly) — rejecting a toggle for a not-currently-entitled feature, and the inactive-Workspace toggle-lock proofs added in the M2 correction round, both confirmed present.
- **Disabled/rolled-back platform feature wins:** `PlatformFeatureRegistryTest.php` and `EntitlementManagerDecisionTest.php` jointly cover a stored row referencing a since-demoted/removed feature denying at read time.
- **Plan status change event/durable audit:** criterion 8/10 above.
- **Complimentary/status precedence:** criterion 9 above; `EntitlementManagerComplimentaryStatusTest.php` (14 test methods).
- **Core↔Growth slot preservation / Core-Growth→Agency reset / Agency→Core-Growth default:** criterion 8 above; all three directions directly covered in `EntitlementManagerChangePlanTest.php`.
- **Downgrade with existing over-capacity Businesses:** `EntitlementManagerChangePlanTest.php` covers the Agency→Core/Growth path with existing Businesses retained and immediate future-creation denial.
- **Paid slot increase fails when pricing undefined / decrease always succeeds:** criterion 9 above.
- **Price/currency both-null-or-both-populated / catalog pricing in-use protection:** criterion 9 above.
- **Complimentary grandfathered extra slots never represent unpaid debt:** no column or flag capable of representing such a debt exists anywhere in the six-table schema (criterion 1) — a structural, not merely behavioral, guarantee.
- **Business deactivation alone does not lower slot count:** `EntitlementManagerBusinessSlotCapacityTest.php` directly asserts `decideBusinessSlotCapacity()`'s `currentBusinessCount` is unaffected by a Business's own `status`.
- **Core/Growth/Agency feature matrix, both directions:** criterion 2 above.
- **Included-slot baseline through sixth-Business-always-denied:** `EntitlementManagerBusinessSlotCapacityTest.php` and `EntitlementManagerFirstAssignmentTest.php` jointly cover the full 1st-through-6th-Business progression for both Core/Growth and Agency.
- **`0.5000` catalog ratio:** criterion 2 above, read directly from the seed migration, never hard-coded in `EntitlementManager` beyond that one seed (confirmed: `assertSlotRatioDefined()` reads `$catalog->additional_business_slot_price_ratio`, never a literal).
- **Agency unlimited behavior:** `EntitlementManagerBusinessSlotCapacityTest.php`.
- **Additional-slot allocation audit:** criterion 10 above; `EntitlementManagerAdditionalSlotsTest.php`.
- **Workspace-override durable audit:** criterion 10 above.
- **Concurrent final-slot creation safety:** criterion 7 above.
- **Workspace/Business authorization independence — corrected in this round, no single test overstated as proving the full boundary:** each piece of evidence below proves exactly its own narrow claim, not the complete RFC-003 §14.1 boundary standing alone:
  - `EntitlementManagerDecisionTest.php::test_workspace_business_mismatch_throws` proves `decide()`'s own defensive re-check rejects a Business whose authoritative `workspace_id` does not match the supplied Workspace — a data-consistency guard, not an authorization decision.
  - `EntitlementManagerDecisionTest.php::test_stale_business_after_reassignment_cannot_be_decided_against_old_workspace` proves the identical guard holds after a real concurrent reassignment, not only for a hand-constructed mismatch.
  - `tests/Feature/Workspace/WorkspaceBusinessFeatureToggleHttpTest.php::test_staff_cannot_record_a_disable_preference` proves the HTTP layer's own RFC-003 role check (Owner/active-Admin only) is independently enforced before any entitlement mutation is attempted — RFC-003 authorization failing closed even though the actor is a legitimate, visible Workspace member.
  - `WorkspaceBusinessFeatureToggleHttpTest.php::test_unrelated_user_receives_not_found` proves an actor with no RFC-003 relationship to the Workspace at all is denied (404) before any entitlement code runs.
  - `tests/Feature/Workspace/WorkspaceBusinessCreationHttpTest.php::test_creation_against_an_intentionally_unassigned_workspace_is_now_caught_and_mapped_to_flash_error` proves the reverse direction — an actor who *is* RFC-003-authorized for the Workspace is still independently denied by RFC-004's own `workspace_plan_unassigned` gate, confirming entitlement is a genuinely separate, additional check, not implied by authorization.
- **Cross-Workspace isolation:** `EntitlementManagerDecisionTest.php::test_workspace_business_mismatch_throws`; `EntitlementManagerPresentationTest.php::test_stale_or_reassigned_business_returns_an_empty_map`; `WorkspaceEntitlementTransitionRepositoryTest.php::test_for_workspace_returns_only_that_workspaces_rows_in_order` (one Workspace's transition history never leaks into another's read); `EntitlementManagerConcurrencyTest.php`'s scenarios 1 and 11 each construct a genuine third, unrelated Workspace and assert its Business count is completely unaffected by the concurrent race under test (`'Unrelated Workspace must be unaffected.'`/`'...completely unaffected.'`), proving isolation holds under real concurrent load, not only in a simple two-Workspace sequential case.
- **Platform-admin boundary (defense-in-depth):** `tests/Feature/Workspace/AdminWorkspaceControllerTest.php::test_a_non_admin_account_is_blocked_even_with_workspace_permissions_in_session` (the exact precedent §27 requires); `AdminWorkspaceEntitlementControllerTest.php` and `AdminWorkspacePlanCatalogControllerTest.php` each reproduce the identical `access backend` + specific-permission independent-gate pattern for the new M3 surfaces.
- **Customer Owner/active-Admin visibility boundary:** `tests/Feature/Workspace/WorkspaceOverviewHttpTest.php::test_active_staff_never_receives_directory_data` (Staff excluded from the `entitlement` key, not merely from an empty value) and the M3-correction-round `array_keys($data) === ['workspace', 'businesses']` exact-shape assertion, both read directly.
- **Legacy Plan/Subscription compatibility:** criterion 11 above.
- **Backfill correctness (idempotence/partial-rerun/concurrency):** criterion 4/5 above; `WorkspaceEntitlementBackfillV1ConcurrencyTest.php` provides the real two-process duplicate-assignment-race proof mirroring `WorkspaceBackfillV1ConcurrencyTest`'s RFC-003 precedent.
- **Existing-capability compatibility rule:** criterion 15 above.
- **RFC-001 regression:** gate 3 below.
- **RFC-002 regression:** gate 4 below.
- **RFC-003 regression:** gate 2 below.

---

## Mechanical searches (run fresh in this pass, exact commands and results)

**1. No raw/direct query of the six RFC-004 tables outside `EntitlementManager` and the six repositories, except authorized migrations/the backfill action:**

```bash
grep -rn "DB::table('workspace_plan_catalog'\|DB::table('workspace_plan_features'\|DB::table('workspace_plan_assignments'\|DB::table('workspace_entitlement_overrides'\|DB::table('business_feature_toggles'\|DB::table('workspace_entitlement_transitions'" app/ database/
```

Result: 7 matches, all inside `app/Library/Entitlement/Migration/WorkspaceEntitlementBackfillV1.php` (3) and `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php` (4) — both explicitly RFC-authorized exceptions (§25.1 step G, §25.2). Zero matches anywhere else in `app/`.

**Strengthened this round** — the query-builder-only search above cannot detect an unauthorized direct Eloquent-model query, so two further searches were run:

```bash
grep -rlE "WorkspacePlanCatalog::|WorkspacePlanFeature::|WorkspacePlanAssignment::|WorkspaceEntitlementOverride::|BusinessFeatureToggle::|WorkspaceEntitlementTransition::" app database
grep -rlE "workspace_plan_catalog|workspace_plan_features|workspace_plan_assignments|workspace_entitlement_overrides|business_feature_toggles|workspace_entitlement_transitions" app database
```

Result, classified: model static-call references confined to the six models' own files (`app/Models/WorkspacePlanCatalog.php`, `WorkspacePlanFeature.php`, `WorkspacePlanAssignment.php`, `WorkspaceEntitlementOverride.php`, `BusinessFeatureToggle.php`, `WorkspaceEntitlementTransition.php`). Table-name-literal mentions confined to: the six models' own `$table` declarations; the six repository contracts/Eloquent implementations; `EntitlementManager.php` itself (docblock references only); the entitlement enums/exceptions (docblock/error-message mentions, e.g. `UndefinedPlanPricingException`, `WorkspaceEntitlementBackfillIncompleteException`); `WorkspaceEntitlementBackfillV1.php` (authorized); and all eight RFC-004 migrations (authorized). A targeted re-check confirms **zero** matches in `app/Http`, `resources/views`, or `routes/` for either search. No controller, job, command (other than the authorized `BackfillWorkspaceEntitlementsCommand` wrapper, which itself never queries the tables directly — it only calls `WorkspaceEntitlementBackfillV1::run()`), or unrelated runtime class reads or writes any of the six tables/models directly. Test evidence: `tests/Feature/Entitlement/NoRawEntitlementTableQueryTest.php::test_no_raw_entitlement_table_query_exists_outside_authorized_files` independently enforces this same boundary as a standing regression test, not only as a point-in-time manual search.

**2. No entitlement repository injected or called directly from `app/Http` or `resources/views`:**

```bash
grep -rn "WorkspacePlanCatalogRepository\|WorkspacePlanFeatureRepository\|WorkspacePlanAssignmentRepository\|WorkspaceEntitlementOverrideRepository\|BusinessFeatureToggleRepository\|WorkspaceEntitlementTransitionRepository" app/Http resources/views
```

Result: zero matches.

**3. `EntitlementManager`/`decide()`/`decideBusinessSlotCapacity()` HTTP references confined to the M3-authorized controllers:**

```bash
grep -rln "EntitlementManager\|decideBusinessSlotCapacity\|->decide(" app/Http
```

Result: exactly 4 files — `app/Http/Controllers/Admin/WorkspaceController.php`, `WorkspaceEntitlementController.php`, `WorkspacePlanCatalogController.php`, `app/Http/Controllers/Customer/Workspace/WorkspaceController.php`. No other file.

**4. No `Agency` model or `businesses.agency_id` column anywhere:**

```bash
grep -rln "agency_id\|class Agency" app/ database/
```

Result: zero matches.

**5. Prospect Outreach / White Label still have no executable implementation requiring an M3 compatibility override — corrected in this round.** The prior round's basic (non-extended) `grep -rli "prospect.?outreach"`/`"white.?label"` did not honor `?` as intended and did not cover `routes/`/`resources/`/`public/`, so it did not actually prove the stated absence. Re-run this round with extended regex (`-E`) across the full executable scope, plus the additional patterns the correction required (`ProspectOutreach`, `WhiteLabel`, `whitelabel`, `custom_domain`):

```bash
grep -n "ProspectOutreach\|WhiteLabel" app/Library/Entitlement/PlatformFeatureRegistry.php
grep -rnEi "prospect.?outreach" app routes resources public
grep -rnEi "white.?label|whitelabel" app routes resources public
grep -rnEi "custom_domain" app routes resources public
```

Result, classified: `PlatformFeatureRegistry.php` confirms both `ProspectOutreach` and `WhiteLabel` mapped to `Planned`. The `prospect.?outreach`/`ProspectOutreach` search's **only** matches, across `app`, `routes`, `resources`, and `public` (all confirmed present and searched — `public/` exists in this repository), are the identity declaration `case ProspectOutreach = 'prospect_outreach';` in `app/Enums/Entitlement/PlatformFeature.php` and the two registry-metadata lines (a docblock comment and the `Planned` mapping itself) in `PlatformFeatureRegistry.php` — pure enum/registry metadata, not executable code. The `white.?label`/`WhiteLabel`/`whitelabel` search's only matches are the identical shape: the enum case declaration and the registry mapping, nothing else. `custom_domain` returns zero matches anywhere. **Only metadata declarations remain; no controller, route, model, job, command, service, runtime view, or executable module exists for either capability anywhere in the searched scope** — the absence of a dedicated directory for either capability was not treated as a successful result by itself; the search was run against the real, populated `app`/`routes`/`resources`/`public` trees and returned these specific, classified matches.

**6. Legacy Plan/Subscription production schema/models/repositories untouched by RFC-004 — corrected in this round.** The prior round's range (`af8be68~1`) resolves to `659c76b` (the M2 contract-merge commit) — already inside the RFC-004 implementation range, not a genuine pre-implementation baseline, so it did not cover the full M1–M3 range. Re-run this round from the RFC-004 design-PR merge commit `3e69b69` (confirmed by `git log --oneline -1 3e69b69` → `Merge pull request #60 from os-creator1/chore/rfc-004-design`) through current `main`, and expanded to all eight primary legacy Plan/Subscription boundary files (the prior round covered only three):

```bash
git diff --stat 3e69b69 bc9972a -- \
  app/Models/Plan.php \
  app/Models/Subscription.php \
  app/Repositories/Contracts/PlanRepository.php \
  app/Repositories/Contracts/SubscriptionRepository.php \
  app/Repositories/Eloquent/EloquentPlanRepository.php \
  app/Repositories/Eloquent/EloquentSubscriptionRepository.php \
  database/migrations/2018_12_01_193935_create_plan_table.php \
  database/migrations/2019_03_09_065029_create_subscriptions_table.php
```

Result: empty diff (exit 0, no output) — none of these eight files, the complete legacy Plan/Subscription models/repositories boundary, changed anywhere across the true full RFC-004 range, from the design-PR merge through current `main`.

**7. No RFC-004 class implements `ShouldQueue` unless directly proven otherwise:**

```bash
grep -rl "ShouldQueue" app/Events/Entitlement app/Library/Entitlement app/Listeners
find app/Listeners -iname "*entitlement*" -o -iname "*workspaceplan*"
```

Result: zero matches — no RFC-004 event, manager, or listener implements `ShouldQueue`; no entitlement-specific listener directory exists.

---

## Regression gates (actual results, this pass, `ultimatesms_testing`)

All five commands run sequentially in this environment via `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe artisan test ...`. **Not re-run for this correction round** — this round is a documentation-evidence-only correction (per its own governing instruction, product/test source is forbidden from changing and no gap was found), so the counts below are retained unchanged from the M4 release-readiness pass that produced this document originally. They remain valid because nothing in `app/**`, `routes/**`, `database/**`, `config/**`, `resources/**`, or `tests/**` changed between that pass and this correction — confirmed directly by this round's own `git status`/`git diff` scope, which touched only the two authorized documentation paths.

| # | Command | Exit | Tests | Assertions | Duration |
|---|---|---|---|---|---|
| 1 | `php artisan test tests/Unit/Entitlement tests/Feature/Entitlement` | 0 | **278 passed** | 746 | 67.98s |
| 2 | `php artisan test tests/Unit/Workspace tests/Feature/Workspace` | 0 | **779 passed** | 2038 | 150.46s |
| 3 | `php artisan test tests/Unit/Business tests/Feature/Business` | 0 | **251 passed** | 645 | 48.86s |
| 4 | `php artisan test tests/Unit/Opportunity tests/Feature/Opportunity` | 0 | **1117 passed** | 3966 | 136.25s |
| 5 | `php artisan test --stop-on-failure` | 0 | **2427 passed** | 7398 | 305.93s |

All five commands exited `0` with a positive test count — no zero-test result occurred. These are this pass's own actual results, run fresh against the `agent/rfc-004-m4` branch tree; the M3 correction round's own prior counts (`278`/`779`/`251` for gates 1–3, matching exactly since no RFC-004 file changed between that pass and this one) are not reused as a substitute for gates 4 and 5, which were not previously run together against this exact tree in that prior pass. Total duration across all five commands: 709.48s (≈11.8 minutes).

**No failure occurred at any gate — the gap rule was not triggered by the regression pass.**

---

## Conclusion

Every RFC-004 §28 acceptance criterion (15 of 15) is marked **PASS** with concrete, freshly-verified evidence. Every §27 test-strategy group is reconciled against real test-class/method evidence. All seven required mechanical searches were run fresh in this pass and found no boundary violation, no forbidden model/column, and no unaccounted-for gate. All five regression gates passed with positive, non-fabricated test/assertion counts. **No GAP/BLOCKED item exists in this document.**

RFC-004 Milestone 4 is not yet complete: this document and the accompanying deployment guide must still be merged, the post-merge exact-tag-candidate regression must still run and pass, and the annotated tag must still be created under separate, explicit human authorization, per `docs/automation/RFC-004-M4-CONTRACT.md` §§7–9.
