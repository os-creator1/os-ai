# RFC-004 Milestone 1 Contract

**Status: PROPOSED — NOT AUTHORIZED UNTIL HUMAN MERGE.**

Human merge of this contract PR directly authorizes one bounded M1 implementation branch/PR — there is no target-marker PR, no inert implementation PR, and no separate authorization PR, the same simplified workflow RFC-003 Milestone 5/6 established. `start_automatically_after_contract_merge: false` and `advance_automatically: false` both hold throughout: a human still explicitly decides to start implementation after this contract merges, and nothing here triggers that start automatically.

## Governing document

[`docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md`](../rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md), version **1.2**, confirmed by direct read in full before drafting this contract. RFC-004's design PR (#60) is human-merged. This contract reproduces v1.2's authoritative schema, enums, and backfill design — it does not redesign any of it.

## Base/branch assumptions

- Contract-drafting branch: `chore/rfc-004-m1-contract`.
- Base/HEAD: `3e69b69207c63bb33378b9f290e1ee0eb2800bf3` — verified via `git rev-parse HEAD` before writing, working tree clean.
- After this contract is human-merged, the M1 implementation branch (`agent/rfc-004-m1`, following RFC-003's naming convention) is created from the then-current `main` containing this contract's merge.

---

## 1. Purpose

Implement RFC-004 §25's Milestone 1 scope: the **data/domain foundation only** — six authoritative tables, their models and enums, the code-backed `PlatformFeatureRegistry`, six repository contracts and Eloquent implementations, provider bindings, a deterministic catalog/plan-feature seed, and a deterministic existing-Workspace entitlement backfill (`WorkspaceEntitlementBackfillV1`), plus M1-scoped tests. M1 introduces **no** `EntitlementManager`, **no** HTTP/admin/customer surface, **no** feature-execution gating, and **no** Business-creation slot enforcement — those are RFC-004 M2/M3's scope (RFC-004 §29), not this contract's.

---

## 2. Exact M1 scope

- Six tables (§10.1–§10.6 below).
- Six enums (§11 below).
- One code-backed `PlatformFeatureRegistry`, with every `PlatformFeature` case's availability locked from **direct repository evidence gathered during this contract's own drafting** (§8), not deferred speculation.
- Six models, one per table.
- Six repository contracts + six Eloquent implementations, bound in the existing `AppServiceProvider` bindings array.
- The deterministic catalog + plan-feature-matrix seed (RFC-004 §12.2/§25.1 step G).
- The deterministic existing-Workspace assignment backfill, `WorkspaceEntitlementBackfillV1` (RFC-004 §25.2–§25.4, step H), including its additional-slot derivation.
- M1-scoped tests (§14 below).

## 3. Exact out-of-scope

Not authorized in M1, matching RFC-004 §29 M1's own boundary exactly:

- `EntitlementManager`, `EntitlementDecision`, `BusinessSlotCapacityDecision`.
- `UsageAuthorizationGateway` / `NullUsageAuthorizationGateway`.
- `BusinessSlotAllocationRequiredException`, `BusinessSlotLimitExceededException`, `WorkspacePlanUnassignedException`, `InactiveWorkspacePlanException`, `SuspendedWorkspacePlanException` — all M2 slot/status-enforcement exceptions.
- `UndefinedPlanPricingException` and `PlanCatalogPricingInUseException` — these are M2 manager-mutation-time checks (RFC-004 §12.5); no M1-only schema/seed path requires them, since the M1 seed never creates a non-complimentary assignment or a slot increase (§9 below establishes why).
- Any `WorkspaceManager` change, including the `createBusinessInWorkspace()` slot-enforcement call (RFC-004 §17) — RFC-003's `WorkspaceManager` is untouched by M1.
- `changePlan()`, `changePlanStatus()`, `setAdditionalBusinessSlots()`, Workspace-override mutation methods, Business-toggle mutation methods — all `EntitlementManager` responsibilities (M2).
- Every actor-driven entitlement event (`WorkspacePlanAssigned` beyond the backfill's own write, `WorkspacePlanChanged`, `WorkspacePlanStatusChanged`, `WorkspaceComplimentaryStatusChanged`, `WorkspaceAdditionalBusinessSlotsChanged`, `WorkspaceEntitlementOverrideChanged`, `BusinessFeatureToggleChanged`) — no event class, no dispatch. M1's backfill is a one-time migration action and, matching `WorkspaceBackfillV1`'s own explicit constraint (RFC-003 §10.3), dispatches no Eloquent event of any kind.
- Admin controllers/routes/views; customer controllers/routes/views; `config/permissions.php` changes.
- Any feature-execution gating of any kind.
- Prospect Outreach gating or redesign; white-label implementation or gating.
- RFC-005 wallets/billing/payment methods/payer logic; any Stripe change.
- Any legacy `plans`/`subscriptions`/`SubscriptionLog`/`SubscriptionTransaction`/`CustomerBasedPricingPlan`/`PlanSendingCreditPrice` schema, model, or repository change (§9 below).
- Unrelated CRM/messaging changes.
- `docs/automation/AI-AUTONOMY-STATE.json` or any other workflow/autonomy-state file.
- RFC-004 M2, M3, or M4 work of any kind.
- Any RFC-004 tag.

---

## 4. RFC dependency

RFC-004 v1.2 depends on RFC-001, RFC-002, and RFC-003 (tagged `rfc-003-workspace-and-business-account-core`, complete). M1 additionally depends directly on RFC-003's `workspaces`/`businesses` tables (foreign-key targets, §10 below) and on the existing `currencies` table (confirmed present: `database/migrations/2018_12_01_191808_create_currencies_table.php`) as `workspace_plan_catalog.currency_id`'s FK target.

---

## 5. Targeted repository findings (RFC-003 precedent)

Findings from direct inspection before drafting, scoped to what this contract actually needs — not an indiscriminate repository read:

1. **No `docs/automation/RFC-003-M1A-CONTRACT.md` or `RFC-003-M1B-CONTRACT.md` exists.** RFC-003's M1A/M1B milestones predate this repository's governance-contract-document convention (the earliest surviving contract document is `RFC-003-M3-SLICE-3C-CONTRACT.md`). This contract therefore takes its structural precedent from RFC-003's own §10/§21 (as shipped) and the **actual merged migrations/backfill/tests**, not from a governance document that does not exist — arguably stronger precedent, since it reflects what was actually built and reviewed, not merely proposed.
2. **RFC-003's seven Workspace migrations** confirmed present in `database/migrations/`, exact names: `2026_07_30_120001_create_workspaces_table.php` through `..._120006_enforce_business_workspace_constraint.php` (six same-day, sequentially numbered `_120001`–`_120006`), plus `2026_07_31_120001_create_workspace_transitions_table.php` (one day later, Milestone 2's addition). **This is the exact naming/sequencing convention M1's migrations reproduce** (§10 below): a same-day batch of DDL migrations sequentially suffixed `_120001`, `_120002`, ..., with data-operation migrations following the same sequence, never interleaved ahead of the DDL they depend on.
3. **`app/Library/Workspace/Migration/WorkspaceBackfillV1.php`** (plus its `WorkspaceBackfillResult.php`) and **`app/Console/Commands/BackfillWorkspacesCommand.php`** confirmed present and read in full in an earlier turn of this session: query-builder-only, no Eloquent model/model-event dependency, bounded/chunked traversal (`CHUNK_SIZE = 500`), per-group `DB::transaction()`, a final global remaining-count check that throws a typed exception on failure, and a thin command wrapper with no algorithm of its own. **This is the exact precedent `WorkspaceEntitlementBackfillV1` (§12 below) reproduces**, adapted (not copied blindly) for a per-Workspace rather than per-`customer_id`-group unit of work, since RFC-004's backfill has no multi-candidate-conflict case analogous to RFC-003 §10.4's "two Businesses reference two different Workspaces."
4. **`app/Providers/AppServiceProvider.php`'s single `$bindings` array** (confirmed at lines ~120–152) is where every repository contract→Eloquent binding lives, including all four current RFC-003 Workspace bindings (`WorkspaceRepository::class => EloquentWorkspaceRepository::class`, and three more), added at the end of the array in Workspace's own contiguous group. **M1 adds exactly six lines to this same array**, in a new contiguous group immediately following the Workspace group — no new provider, matching RFC-003's own precedent exactly.
5. **Test-file organization precedent**: `tests/Feature/Workspace/WorkspaceSchemaTest.php` combines the three core-batch tables (`workspaces`, `workspace_memberships`, `workspace_membership_businesses`) in one file, while `WorkspaceTransitionSchemaTest.php` (the Milestone-2 audit-table addition) is a separate file. `WorkspaceBackfillV1Test.php`, `WorkspaceBackfillV1ConcurrencyTest.php`, `WorkspaceBackfillMigrationTest.php`, and `BackfillWorkspacesCommandTest.php` are the four backfill-specific files. `tests/Unit/Workspace/WorkspaceM2EnumsTest.php` is one combined file covering multiple enums. **§14 below reproduces this exact organizational pattern** for RFC-004's six tables (five structural tables combined + the audit table separate) and enums (one combined file).
6. **The `currencies` table exists** (`database/migrations/2018_12_01_191808_create_currencies_table.php`), confirming a valid FK target for `workspace_plan_catalog.currency_id`.
7. **No legacy Plan/Subscription test suite exists anywhere in `tests/`** — confirmed by direct search (`find tests -iname "*plan*" -o -iname "*subscription*"`, excluding Workspace-domain matches, returned nothing). Per this contract's own instruction not to invent test paths, **no legacy Plan/Subscription regression command is included in §15** — the safety guarantee that the legacy stack is untouched is instead verified structurally, by the exact-scope check itself (§16), not by a regression command that has no real target to run.

---

## 6. Feature availability evidence matrix

**Direct repository inspection was performed now, during this contract's own drafting, for every one of the fifteen `PlatformFeature` cases** — not deferred, and not inferred from product intent or RFC-004's own illustrative table. Exact commands run and their results:

| `PlatformFeature` case | Evidence found | Locked M1 availability |
|---|---|---|
| `Crm` | `app/Http/Controllers/Customer/ContactsController.php` exists; a full "Contact Module" route block (`contacts/*`) confirmed in `routes/customer.php`. | **Available** |
| `Conversations` | `app/Http/Controllers/Customer/ChatBoxController.php` exists, confirmed by direct file search. | **Available** |
| `Automations` | `app/Http/Controllers/Customer/AutomationsController.php` exists, confirmed by direct file search. | **Available** |
| `Calendar` | Only unused front-end vendor assets exist (`public/vendors/{css,js}/calendar/fullcalendar.*`, `resources/scss/base/pages/app-calendar.scss`) — bundled theme tooling, not wired to any controller, route, or feature. No application-level calendar capability found. | **Planned** |
| `Forms` | No controller, route, or model matching "Form"/"Survey" found anywhere under `app/Http/Controllers`. | **Planned** |
| `WebsiteGeneration` | Zero matches anywhere in the repository for "website" as an application feature (a distinct admin-only "Pages"/"Blogs"/"Themes" CMS module exists for the *platform's own* marketing site, confirmed unrelated on inspection — it has no relationship to generating or hosting a *Business's* website, so it is not counted as evidence). | **Planned** |
| `AiCooBasic` | One related-but-distinct AI feature exists — `CampaignController@generateAIMessage` (`routes/customer.php`'s `openai/generate` route) — an AI-assisted SMS/campaign message generator. This is narrower in scope than, and not equivalent to, an "AI COO" (a per-Business operating-assistant capability) as RFC-004 §12.2 frames it alongside CRM/calendar/forms. Not counted as evidence for this specific key. | **Planned** |
| `SeoBasicVisibility` | Zero matches for any SEO-related controller/service. | **Planned** |
| `AdsBasicVisibility` | Zero matches for any Ads-related controller/service. | **Planned** |
| `SeoModule` | Zero matches (same search as `SeoBasicVisibility`; no separate "module" vs. "basic" distinction exists in code either way). | **Planned** |
| `GoogleAdsModule` | Zero matches for "GoogleAds" anywhere in `app/`. | **Planned** |
| `MetaAdsModule` | Zero matches for "MetaAds" anywhere in `app/`. | **Planned** |
| `WhiteLabel` | Zero matches for "white label"/"whitelabel" anywhere in `app/`, `config/`, or `routes/`. | **Planned** |
| `AgencyPackageCapabilities` | Zero matches for "agency" anywhere in `app/Models`, `app/Http/Controllers`, or `app/Library` — also independently confirms no `Agency` model exists, consistent with RFC-003 §4/§27 and RFC-004 §3's explicit prohibition. | **Planned** |
| `ProspectOutreach` | Zero matches for "ProspectOutreach"/"prospect outreach"/"ProspectOutreachEngine" anywhere in `app/`. **This directly confirms and closes RFC-004 v1.2 §5 finding 9's own correction** — v1.1 had incorrectly asserted this was `Available`; this contract's own fresh, direct search finds no executable implementation in this repository today. | **Planned** |

**Result: 3 of 15 keys are `Available` (`Crm`, `Conversations`, `Automations`); 12 are `Planned`.** This matches RFC-004 v1.2's own illustrative table exactly for every key — including `ProspectOutreach`, where this contract's independent re-verification arrives at the same `Planned` conclusion v1.2 itself already locked, via a fresh search rather than by trusting the RFC text. `PlatformFeatureRegistry`'s `AVAILABILITY` constant (§11 below) is locked to precisely this table — no speculative "Available" entry for any case this search did not directly confirm.

No conflict between this evidence and RFC-004 v1.2 was found. The **stop/gap rule does not trigger.**

---

## 7. Exact schema/migration obligations

Six tables, reproducing RFC-004 §10.1–§10.6 exactly (no redesign). All migrations DDL-only except the seed and backfill, which are data-operations-only, mirroring RFC-003 §10.1's DDL/data separation exactly.

### 7.1 `workspace_plan_catalog`

- `id` bigint unsigned PK.
- `tier` varchar(20), not null, **unique**.
- `display_name` varchar(255), not null.
- `price` decimal(16,2), **nullable**.
- `currency_id` bigint unsigned, **nullable**, FK → `currencies.id`, `restrictOnDelete()` (enforced only when non-null).
- `billing_cycle` varchar(20), not null, default `monthly`.
- `business_slot_included` unsigned tinyint, not null.
- `business_slot_max` unsigned tinyint, nullable.
- `unlimited_business_slots` boolean, not null, default `false`.
- `additional_business_slot_price_ratio` decimal(6,4), nullable.
- `is_active` boolean, not null, default `true`.
- timestamps.
- Indexes: unique `tier`, index `is_active`.
- **`price` and `currency_id` are always both-null or both-populated** — enforced at the application layer by the seed step (§9) and by every future write path (M2's catalog-mutation guard, RFC-004 §12.5); no plain database constraint expresses this pairing, matching RFC-003's own precedent of enforcing cross-table/cross-column invariants at the application layer, not via a database `CHECK` constraint this codebase's migration convention does not otherwise use.

### 7.2 `workspace_plan_features`

- `id` bigint unsigned PK.
- `workspace_plan_catalog_id` bigint unsigned, not null, FK → `workspace_plan_catalog.id`, `restrictOnDelete()`.
- `feature_key` varchar(64), not null.
- timestamps.
- Unique `(workspace_plan_catalog_id, feature_key)`.
- Packaging only — existence of a row means the tier structurally includes that feature; says nothing about implementation availability (§11). No boolean "included" column, matching RFC-004 §10.2 exactly.

### 7.3 `workspace_plan_assignments`

- `id` bigint unsigned PK.
- `workspace_id` bigint unsigned, not null, **unique**, FK → `workspaces.id`, `restrictOnDelete()`.
- `workspace_plan_catalog_id` bigint unsigned, not null, FK → `workspace_plan_catalog.id`, `restrictOnDelete()`.
- `status` varchar(20), not null, **no default**.
- `is_complimentary` boolean, not null, default `false`.
- `complimentary_reason` text, nullable.
- `complimentary_granted_by_user_id` bigint unsigned, nullable, **no FK** (RFC-004 §10.3's explicit "must never block a legitimate user-deletion feature" rationale, mirroring `workspace_transitions.actor_user_id`).
- `complimentary_granted_at` timestamp, nullable.
- `additional_business_slots` unsigned tinyint, not null, default `0`.
- timestamps.
- Indexes: unique `workspace_id`, index `workspace_plan_catalog_id`, index `status`.

### 7.4 `workspace_entitlement_overrides`

- `id` bigint unsigned PK.
- `workspace_id` bigint unsigned, not null, FK → `workspaces.id`, `restrictOnDelete()`.
- `feature_key` varchar(64), not null.
- `state` varchar(10), not null.
- `reason` text, nullable.
- `created_by_user_id` bigint unsigned, nullable, no FK.
- timestamps.
- Unique `(workspace_id, feature_key)`.

### 7.5 `business_feature_toggles`

- `id` bigint unsigned PK.
- `business_id` bigint unsigned, not null, FK → `businesses.id`, `restrictOnDelete()`.
- `feature_key` varchar(64), not null.
- `reason` text, nullable.
- `created_by_user_id` bigint unsigned, nullable, no FK.
- timestamps.
- Unique `(business_id, feature_key)`.
- Existence = disabled; no "enabled"/"allow" state column, matching RFC-004 §10.6 exactly.

### 7.6 `workspace_entitlement_transitions`

- `id` bigint unsigned PK.
- `workspace_id` bigint unsigned, not null, FK → `workspaces.id`, `restrictOnDelete()`.
- `transition_type` varchar(48), not null — **nine** values structurally supported (§11 below).
- `actor_user_id` bigint unsigned, nullable, no FK.
- `from_plan_catalog_id` bigint unsigned, nullable, FK → `workspace_plan_catalog.id`, `restrictOnDelete()`.
- `to_plan_catalog_id` bigint unsigned, nullable, FK → `workspace_plan_catalog.id`, `restrictOnDelete()`.
- `feature_key` varchar(64), nullable.
- `from_override_state` varchar(10), nullable.
- `to_override_state` varchar(10), nullable.
- `from_additional_business_slots` unsigned tinyint, nullable.
- `to_additional_business_slots` unsigned tinyint, nullable.
- `from_status` varchar(20), nullable.
- `to_status` varchar(20), nullable.
- `reason` text, nullable.
- `created_at` timestamp only — **no `updated_at`**, immutable row, matching `workspace_transitions` exactly.
- Indexes: composite `(workspace_id, created_at)` (serves both the per-Workspace lookup and InnoDB's FK-leftmost-index requirement, exactly matching `workspace_transitions`' documented rationale — no separate bare `workspace_id` index).

### 7.7 Exact migration filenames

Following §5 finding 2's exact naming/sequencing precedent. **The date prefix is the actual implementation date, unknowable at contract-drafting time** — Laravel's migration convention is `YYYY_MM_DD_HHMMSS_description.php`, dated to real creation time, not a value this contract can predict. **The narrowest deterministic rule this contract can lock**: the `HHMMSS` suffix and `description` are exact and fixed; all eight migrations share the same implementation date and are created in this exact sequential order, DDL (1–6) strictly before the data operations (7–8), mirroring RFC-003's own single-day `_120001`–`_120006` batch:

1. `{impl_date}_120001_create_workspace_plan_catalog_table.php`
2. `{impl_date}_120002_create_workspace_plan_features_table.php`
3. `{impl_date}_120003_create_workspace_plan_assignments_table.php`
4. `{impl_date}_120004_create_workspace_entitlement_overrides_table.php`
5. `{impl_date}_120005_create_business_feature_toggles_table.php`
6. `{impl_date}_120006_create_workspace_entitlement_transitions_table.php`
7. `{impl_date}_120007_seed_workspace_plan_catalog_and_features.php`
8. `{impl_date}_120008_backfill_workspace_entitlement_assignments.php`

Migrations 1–6 are DDL-only (`Schema::create`, no data write). Migrations 7–8 are data-operations-only, matching RFC-003 §10.1's DDL/data separation (MySQL DDL is not part of the surrounding transaction the way DML is) — migration 7 is a plain seed (see §9); migration 8 directly instantiates and invokes `WorkspaceEntitlementBackfillV1` (§12), never the console-command wrapper, exactly mirroring migration 5's `(new WorkspaceBackfillV1())->run()` pattern in RFC-003.

---

## 8. Enums / registry — exact obligations

All under `App\Enums\Entitlement`, string-backed, no native DB `ENUM` column anywhere (RFC-003 §9 AD-004 convention, reused).

- `WorkspacePlanTier`: `Core = 'core'`, `Growth = 'growth'`, `Agency = 'agency'` — identity only, no structural rule (RFC-004 §12.1).
- `WorkspacePlanAssignmentStatus`: `Active = 'active'`, `Inactive = 'inactive'`, `Suspended = 'suspended'`.
- `WorkspaceEntitlementOverrideState`: `Allow = 'allow'`, `Deny = 'deny'`.
- `WorkspaceEntitlementTransitionType`: exactly nine cases — `PlanAssigned = 'plan_assigned'`, `PlanChanged = 'plan_changed'`, `PlanStatusChanged = 'plan_status_changed'`, `ComplimentaryGranted = 'complimentary_granted'`, `ComplimentaryRevoked = 'complimentary_revoked'`, `AdditionalBusinessSlotsChanged = 'additional_business_slots_changed'`, `EntitlementOverrideAllowed = 'entitlement_override_allowed'`, `EntitlementOverrideDenied = 'entitlement_override_denied'`, `EntitlementOverrideReverted = 'entitlement_override_reverted'`. **M1 defines all nine cases structurally (the enum, the `transition_type` column's valid domain); only `PlanAssigned` is ever actually written by M1's own code (the backfill, §12) — the other eight are structurally supported, not exercised, until M2's actor-driven mutations exist.**
- `PlatformFeature`: exactly the fifteen RFC-004 §11 keys, verbatim: `Crm`, `Conversations`, `Calendar`, `Forms`, `Automations`, `WebsiteGeneration`, `AiCooBasic`, `SeoBasicVisibility`, `AdsBasicVisibility`, `SeoModule`, `GoogleAdsModule`, `MetaAdsModule`, `WhiteLabel`, `AgencyPackageCapabilities`, `ProspectOutreach`.
- `PlatformFeatureAvailability`: `Available = 'available'`, `Planned = 'planned'`.
- `PlatformFeatureRegistry` (`app/Library/Entitlement/PlatformFeatureRegistry.php`, final class, not an enum — code-backed, static, no database dependency): `isKnown(string $featureKey): bool`, `isAvailable(string $featureKey): bool`. Its `AVAILABILITY` constant is locked **exactly** to §6's evidence matrix — `Crm`/`Conversations`/`Automations` → `Available`; every other case → `Planned`. No case is left unmapped. Identity (`PlatformFeature`) and availability (`PlatformFeatureRegistry`) remain two separate mechanisms, as RFC-004 §11 requires — no database column anywhere lets an admin claim unsupported code exists; only a future code deploy changes an availability value.

---

## 9. Exact seed obligations

Migration 7 (`..._120007_seed_workspace_plan_catalog_and_features.php`), a plain data-operation migration (query-builder inserts, no Eloquent model dependency, matching migration 5/7's precedent):

**`workspace_plan_catalog`** — exactly three rows, **no invented price**:

| `tier` | `display_name` | `price` | `currency_id` | `business_slot_included` | `business_slot_max` | `unlimited_business_slots` | `additional_business_slot_price_ratio` | `is_active` |
|---|---|---|---|---|---|---|---|---|
| `core` | Core | `null` | `null` | `3` | `5` | `false` | `0.5000` | `true` |
| `growth` | Growth | `null` | `null` | `3` | `5` | `false` | `0.5000` | `true` |
| `agency` | Agency | `null` | `null` | `3` | `null` | `true` | `null` | `true` |

`price`/`currency_id` seeded `null`/`null` for all three rows — both-null-or-both-populated is trivially satisfied (both null). **No M2 manager-level paid-assignment pricing check is invoked or needed here**, since the seed never creates a `workspace_plan_assignments` row at all — that is exclusively migration 8's job, and every backfilled assignment is complimentary (§12), which RFC-004 §12.5 confirms never requires catalog pricing. This is exactly why `UndefinedPlanPricingException`/`PlanCatalogPricingInUseException` are correctly out of M1's scope (§3): no M1-authored code path can ever reach the condition either exception exists to guard against.

**`workspace_plan_features`** — the exact RFC-004 §12.2 packaging matrix, realized as flat rows (catalog id resolved by `tier` lookup within the same migration):

- Core (9 rows): `crm`, `conversations`, `calendar`, `forms`, `automations`, `website_generation`, `ai_coo_basic`, `seo_basic_visibility`, `ads_basic_visibility`.
- Growth (12 rows): all 9 Core rows, plus `seo_module`, `google_ads_module`, `meta_ads_module`.
- Agency (15 rows): all 12 Growth rows, plus `white_label`, `agency_package_capabilities`, `prospect_outreach`.

**Packaging is independent of §6/§8's availability lock** — `prospect_outreach` is seeded as an Agency-plan feature (a packaging fact) even though `PlatformFeatureRegistry` marks it `Planned` (an availability fact); RFC-004 §12.2 states explicitly that a `✓` for a `Planned` feature is "a valid, honest seed row," and this contract's seed follows that instruction exactly, not silently omitting rows for `Planned` features.

---

## 10. Exact backfill obligations

`App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1` (final class), invoked directly by migration 8 (`(new WorkspaceEntitlementBackfillV1())->run()`), never through the console-command wrapper — mirroring RFC-003 migration 5's exact precedent (§5 finding 3).

**Algorithm, adapted from `WorkspaceBackfillV1` (not copied blindly, per its own genuinely different unit of work — one Workspace, not a `customer_id` group):**

- Query-builder-only (`DB::table('workspaces')`, `'businesses'`, `'workspace_plan_catalog'`, `'workspace_plan_assignments'`, `'workspace_entitlement_transitions'`) — no Eloquent model, no model event.
- Bounded/chunked traversal over Workspaces lacking a `workspace_plan_assignments` row (left-join / not-exists query, chunked by `workspaces.id`, mirroring `WorkspaceBackfillV1`'s `CHUNK_SIZE = 500` bounded-page pattern).
- **Per-Workspace transaction** — each Workspace's `workspace_plan_assignments` insert and its `workspace_entitlement_transitions` insert commit or roll back together, atomically and independently of every other Workspace (a narrower, simpler unit than `WorkspaceBackfillV1`'s per-`customer_id`-group transaction, since there is no multi-Workspace grouping concern here at all — one Workspace, one assignment, always).
- **Idempotent, safe under partial rerun** — a Workspace that already has an assignment row is skipped entirely (detected by the same left-join/not-exists query that selects candidates); re-running after a partial failure resumes exactly where the previous attempt left off, writing no duplicate assignment and no duplicate `plan_assigned` transition row.
- **Concurrency-safe** — two simultaneous backfill runs racing the same Workspace serialize on the unique `workspace_id` constraint on `workspace_plan_assignments`: the losing insert's constraint violation is caught and treated as "already assigned" (a no-op for that Workspace), mirroring `WorkspaceMembershipRepository::create()`'s documented idempotent-create pattern (RFC-003 §12.2) rather than surfacing a raw database exception.
- **Final zero-unassigned-Workspace assertion** — at the end of a run, a `SELECT COUNT(*)` over Workspaces still lacking an assignment must be zero; a non-zero count is a failed run, surfaced via a new `WorkspaceEntitlementBackfillIncompleteException` (`app/Exceptions/Entitlement/`) carrying the exact remaining count, mirroring `WorkspaceBackfillV1`'s `WorkspaceBackfillIncompleteException` discipline exactly.
- **Deterministic failure behavior** — a failed Workspace's transaction rolls back completely, leaving it exactly as it was (still unassigned, safely re-processable); other Workspaces already committed are unaffected.
- **No Eloquent event dispatched** by the migration/backfill action itself, matching `WorkspaceBackfillV1`'s explicit constraint (RFC-003 §10.3) — actor-driven event dispatch belongs to the manager layer (M2), not this one-time migration action.

**Per-Workspace assignment values** (every Workspace lacking an assignment):

- `workspace_plan_catalog_id` → the seeded **Core** row's id (resolved by `tier = 'core'` lookup).
- `status` → `active`.
- `is_complimentary` → `true`.
- `complimentary_reason` → a fixed, explicit string: `"Pre-RFC-004 Workspace — grandfathered complimentary assignment during Milestone 1 backfill"`.
- `complimentary_granted_by_user_id` → `null` (system migration, not an admin actor).
- `complimentary_granted_at` → the backfill run's own execution timestamp.
- `additional_business_slots` → derived deterministically from the Workspace's **existing Business-row count**, counted via `COUNT(*) FROM businesses WHERE workspace_id = :id` — **every row, regardless of that Business's own `status`**, matching RFC-004 §8/§13's slot-count definition exactly:

  | Existing Business count | `additional_business_slots` |
  |---|---|
  | ≤ 3 | `0` |
  | 4 | `1` |
  | ≥ 5 | `2` |

- A `workspace_entitlement_transitions` row is written in the same transaction: `transition_type = plan_assigned`, `actor_user_id = null`, `to_plan_catalog_id` = the Core catalog row's id, `from_plan_catalog_id = null`, `reason` = the same fixed complimentary-reason string above. **This is the one and only transition row M1's own code ever writes** — the other eight transition types remain structurally supported (§8) but unexercised until M2.
- **No existing Business is deleted, deactivated, or hidden** at any point — the backfill only ever reads `businesses` (to count) and writes to `workspace_plan_assignments`/`workspace_entitlement_transitions`.

**A Workspace with more than 5 existing Businesses is not a failure.** `additional_business_slots` is capped at `2` (the representable Core/Growth maximum); every existing Business is kept; the migration still succeeds for that Workspace; this is the documented, expected **grandfathered-over-capacity** state RFC-004 §25.4 defines. **M1 does not enforce this at Business-creation time — no such enforcement exists in M1 at all** (§3); the state is simply recorded correctly, ready for M2's `EntitlementManager::decideBusinessSlotCapacity()` to correctly deny further creation once M2 ships.

**Supporting files** (mirroring `WorkspaceBackfillResult`/`BackfillWorkspacesCommand` exactly):

- `App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillResult` (final readonly class) — immutable outcome: `workspacesProcessed: int`, `assignmentsCreated: int`, `remainingUnassignedCount: int` (always `0` on a successful return, matching `WorkspaceBackfillResult`'s own "never constructed with a non-zero failure count" discipline).
- `App\Console\Commands\BackfillWorkspaceEntitlementsCommand` — thin wrapper, signature `workspaces:backfill-entitlements`, no algorithm of its own, reports the result or the caught `WorkspaceEntitlementBackfillIncompleteException` with a non-zero exit — mirroring `BackfillWorkspacesCommand` exactly.

---

## 11. Model/repository boundaries

One model per table (six total, `App\Models\*`), matching RFC-003's exact 1:1 model-per-table convention — no combined/shared model.

Repository contracts (`App\Repositories\Contracts\*`, **no `Interface` suffix**, extending `BaseRepository`) and their Eloquent implementations (`App\Repositories\Eloquent\*`) — six authorities, one per table, each a **plain data-access contract**: no business-rule/authority logic, no entitlement decision, no slot-capacity computation. The one cross-table validation any M1 repository performs is the same narrow, `WorkspaceMembershipBusinessRepository`-style check RFC-003 already established — e.g. `WorkspacePlanFeatureRepository`/`WorkspaceEntitlementOverrideRepository`/`BusinessFeatureToggleRepository` may validate a supplied `feature_key` against `PlatformFeature::cases()` before insert (this is validating a value against the code-identity enum, not making an availability or entitlement decision — `PlatformFeatureRegistry::isAvailable()` is never called from a repository).

**No `EntitlementManager` exists in M1** (§3) — no repository method computes effective entitlement, slot capacity, or any decision object; those are exclusively M2's `EntitlementManager` responsibility.

---

## 12. Exact authorized implementation paths

**Fifty-two unique paths total**, every one of them either new or an additive single-array-entry change to one existing file. Category subtotals sum exactly to the total and no path appears in more than one category: 8 migrations + 7 enum/registry + 6 models + 6 repository contracts + 6 Eloquent repositories + 1 provider/bindings edit + 4 backfill/support + 14 tests = **52**.

No path in this list is a glob or pattern matching more than one file. The eight migration entries below are the one partial exception to "fully literal": each resolves to exactly one real file once implemented, but the date component of its filename cannot be fixed at contract-drafting time (§7.7) — see the note immediately following the migration list for the exact deterministic rule that governs those eight entries. All other forty-four entries are complete, literal, exact paths with no unresolved component of any kind.

### Migrations (8 new)

1. `database/migrations/{impl_date}_120001_create_workspace_plan_catalog_table.php`
2. `database/migrations/{impl_date}_120002_create_workspace_plan_features_table.php`
3. `database/migrations/{impl_date}_120003_create_workspace_plan_assignments_table.php`
4. `database/migrations/{impl_date}_120004_create_workspace_entitlement_overrides_table.php`
5. `database/migrations/{impl_date}_120005_create_business_feature_toggles_table.php`
6. `database/migrations/{impl_date}_120006_create_workspace_entitlement_transitions_table.php`
7. `database/migrations/{impl_date}_120007_seed_workspace_plan_catalog_and_features.php`
8. `database/migrations/{impl_date}_120008_backfill_workspace_entitlement_assignments.php`

**These eight entries are governed by a deterministic filename rule, not by fully exact literal paths** (§7.7 has the full rationale for why the date component cannot be fixed now). The rule has four locked parts:

- **Exact directory**: `database/migrations/`.
- **Exact semantic names**: the `description.php` portion of each of the eight filenames above (e.g. `create_workspace_plan_catalog_table.php`) is fixed exactly as written.
- **Exact required ordering/suffix sequence**: the `_120001` through `_120008` suffixes and their order are fixed exactly as written; DDL (1–6) strictly precedes the data operations (7–8).
- **Deterministic date-prefix rule**: all eight share one real calendar date — the actual date M1 is implemented — substituted for `{impl_date}` in `YYYY_MM_DD` form. This is the only unresolved component in this entire 52-path list, and it resolves to exactly one value per migration (not a range or pattern), so it does not create a wildcard/glob or authorize any migration outside these eight semantic names/suffixes. No migration with any other description or suffix is authorized under this contract, regardless of date.

### Enums / registry (7 new)

9. `app/Enums/Entitlement/WorkspacePlanTier.php`
10. `app/Enums/Entitlement/WorkspacePlanAssignmentStatus.php`
11. `app/Enums/Entitlement/WorkspaceEntitlementOverrideState.php`
12. `app/Enums/Entitlement/WorkspaceEntitlementTransitionType.php`
13. `app/Enums/Entitlement/PlatformFeature.php`
14. `app/Enums/Entitlement/PlatformFeatureAvailability.php`
15. `app/Library/Entitlement/PlatformFeatureRegistry.php`

### Models (6 new)

16. `app/Models/WorkspacePlanCatalog.php`
17. `app/Models/WorkspacePlanFeature.php`
18. `app/Models/WorkspacePlanAssignment.php`
19. `app/Models/WorkspaceEntitlementOverride.php`
20. `app/Models/BusinessFeatureToggle.php`
21. `app/Models/WorkspaceEntitlementTransition.php`

### Repository contracts (6 new)

22. `app/Repositories/Contracts/WorkspacePlanCatalogRepository.php`
23. `app/Repositories/Contracts/WorkspacePlanFeatureRepository.php`
24. `app/Repositories/Contracts/WorkspacePlanAssignmentRepository.php`
25. `app/Repositories/Contracts/WorkspaceEntitlementOverrideRepository.php`
26. `app/Repositories/Contracts/BusinessFeatureToggleRepository.php`
27. `app/Repositories/Contracts/WorkspaceEntitlementTransitionRepository.php`

### Eloquent repositories (6 new)

28. `app/Repositories/Eloquent/EloquentWorkspacePlanCatalogRepository.php`
29. `app/Repositories/Eloquent/EloquentWorkspacePlanFeatureRepository.php`
30. `app/Repositories/Eloquent/EloquentWorkspacePlanAssignmentRepository.php`
31. `app/Repositories/Eloquent/EloquentWorkspaceEntitlementOverrideRepository.php`
32. `app/Repositories/Eloquent/EloquentBusinessFeatureToggleRepository.php`
33. `app/Repositories/Eloquent/EloquentWorkspaceEntitlementTransitionRepository.php`

### Provider/bindings (1 modified)

34. `app/Providers/AppServiceProvider.php` — **exactly six additive lines** appended to the existing `$bindings` array (§5 finding 4), immediately after the existing Workspace-repository group. No other line in this file changes.

### Backfill/support (4 new)

35. `app/Library/Entitlement/Migration/WorkspaceEntitlementBackfillV1.php`
36. `app/Library/Entitlement/Migration/WorkspaceEntitlementBackfillResult.php`
37. `app/Console/Commands/BackfillWorkspaceEntitlementsCommand.php`
38. `app/Exceptions/Entitlement/WorkspaceEntitlementBackfillIncompleteException.php`

### Tests (14 new)

39. `tests/Unit/Entitlement/EntitlementEnumsTest.php`
40. `tests/Feature/Entitlement/WorkspaceEntitlementSchemaTest.php`
41. `tests/Feature/Entitlement/WorkspaceEntitlementTransitionSchemaTest.php`
42. `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php`
43. `tests/Feature/Entitlement/WorkspacePlanCatalogRepositoryTest.php`
44. `tests/Feature/Entitlement/WorkspacePlanFeatureRepositoryTest.php`
45. `tests/Feature/Entitlement/WorkspacePlanAssignmentRepositoryTest.php`
46. `tests/Feature/Entitlement/WorkspaceEntitlementOverrideRepositoryTest.php`
47. `tests/Feature/Entitlement/BusinessFeatureToggleRepositoryTest.php`
48. `tests/Feature/Entitlement/WorkspaceEntitlementTransitionRepositoryTest.php`
49. `tests/Feature/Entitlement/WorkspaceEntitlementBackfillV1Test.php`
50. `tests/Feature/Entitlement/WorkspaceEntitlementBackfillV1ConcurrencyTest.php`
51. `tests/Feature/Entitlement/WorkspaceEntitlementBackfillMigrationTest.php`
52. `tests/Feature/Entitlement/BackfillWorkspaceEntitlementsCommandTest.php`

**No unrelated path may be authorized.** If M1's own implementation discovers a genuine need for a path not listed above (e.g. a repository requiring a helper trait), that is a STOP-and-report condition for a bounded contract amendment — not something to add silently.

---

## 13. Exact test scope

Per-file coverage requirements, cross-referenced to this contract's own numbering:

- **`EntitlementEnumsTest.php`** (Unit): every case of all six enums round-trips its string value; `WorkspaceEntitlementTransitionType` has exactly nine cases; `PlatformFeature` has exactly fifteen cases matching RFC-004 §11's exact key list.
- **`WorkspaceEntitlementSchemaTest.php`** (Feature): `workspace_plan_catalog`, `workspace_plan_features`, `workspace_plan_assignments`, `workspace_entitlement_overrides`, `business_feature_toggles` — every column, nullable/default rule, unique constraint (`tier`; `(catalog_id, feature_key)`; `workspace_id` on assignments; `(workspace_id, feature_key)` on overrides; `(business_id, feature_key)` on toggles), and every `restrictOnDelete()` FK rejecting deletion of a referenced parent while a child row exists — inserted/verified directly via the query builder, matching `WorkspaceSchemaTest.php`'s exact pattern.
- **`WorkspaceEntitlementTransitionSchemaTest.php`** (Feature): the audit table's full column set including `from_status`/`to_status`; the composite `(workspace_id, created_at)` index; immutability (`created_at`-only, no `updated_at`); `restrictOnDelete()` on both `from_plan_catalog_id`/`to_plan_catalog_id`.
- **`PlatformFeatureRegistryTest.php`** (Feature): every one of the fifteen `PlatformFeature` cases resolves to exactly the availability §6/§8 locks (`Crm`/`Conversations`/`Automations` → `Available`; the other twelve, including `ProspectOutreach`, → `Planned`); `isKnown()`/`isAvailable()` both return `false` for an arbitrary unknown string.
- **Six repository test files**: each repository's create/find/uniqueness-violation-handling round-trips correctly through its model's casts (e.g. `WorkspacePlanAssignmentRepositoryTest` verifies `status` casts to `WorkspacePlanAssignmentStatus`, `is_complimentary` to bool); no repository performs an entitlement/slot decision (a direct assertion that these classes contain no such method, matching the "data-access only" boundary in §11).
- **`WorkspaceEntitlementBackfillV1Test.php`** (Feature): Core/`active`/`is_complimentary=true` assignment created with the exact fixed reason string; `additional_business_slots` derived correctly for Business counts of `0`–`3` (→`0`), `4` (→`1`), and `≥5` (→`2`); a Workspace with more than 5 Businesses keeps every Business and still succeeds; every Business row is counted regardless of its own `status`; exactly one `plan_assigned` transition row is written per backfilled Workspace, with the correct `to_plan_catalog_id`/`reason`/`actor_user_id = null`; the final zero-unassigned assertion passes on a fully-processed database and the action reports a positive `workspacesProcessed`/`assignmentsCreated` count; no legacy `plans`/`subscriptions`-family table is touched (direct assertion, not merely absence of a regression failure).
- **`WorkspaceEntitlementBackfillV1ConcurrencyTest.php`** (Feature): two simultaneous backfill attempts for the same never-before-assigned Workspace create **exactly one** `workspace_plan_assignments` row, mirroring `WorkspaceBackfillV1ConcurrencyTest`'s exact pattern (holding a lock open for a controlled duration to prove genuine contention, not sequential coincidence); idempotent full-rerun (a second complete run against an already-fully-assigned database makes zero writes and reports zero remaining); partial-rerun safety (a run interrupted after some Workspaces commit resumes correctly on retry, creating no duplicates for the already-committed ones).
- **`WorkspaceEntitlementBackfillMigrationTest.php`** (Feature): migration 8 invokes `WorkspaceEntitlementBackfillV1` directly, not `BackfillWorkspaceEntitlementsCommand` — asserted via the migration's own dependencies/instantiation, mirroring `WorkspaceBackfillMigrationTest`'s exact assertion style.
- **`BackfillWorkspaceEntitlementsCommandTest.php`** (Feature): the command wraps the same action/class the migration uses (running both against identical seed data produces identical results, proving no second implementation exists), and surfaces a non-zero exit code with the exact remaining count on a forced-incomplete scenario.

---

## 14. Exact human-run regression commands

Locked after inspecting the actual test-directory structure (§5 finding 7 — no legacy Plan/Subscription suite exists, so none is included):

```
php artisan test tests/Unit/Entitlement tests/Feature/Entitlement
php artisan test tests/Unit/Workspace tests/Feature/Workspace
php artisan test --stop-on-failure
```

Purpose, in order: (1) RFC-004 M1's own targeted suite; (2) RFC-003 Workspace regression, confirming the additive `AppServiceProvider` change and new sibling test directories break nothing already shipped; (3) complete application regression, the same final gate every prior RFC-003 milestone used.

Every command must exit successfully and discover a positive test count — a zero/"no tests found" result is a failure. **The human runs these locally. Claude must never claim any of them passed if PHP is unavailable in its own environment** (confirmed unavailable in this environment throughout this entire session — `php -v` → command not found), **and must never invent a test count.** The actual results supplied by the human must be recorded honestly in this contract's eventual closure document before M1 is considered complete.

---

## 15. Exact-scope verification

Before the M1 implementation PR may be considered ready to merge: `git status --short` / `git diff --name-only` must show only the 52 paths in §12 (or a strict subset, if a listed test/support file turns out genuinely unnecessary — never a superset); `git diff --check` clean; no legacy `plans`/`subscriptions`/`SubscriptionLog`/`SubscriptionTransaction`/`CustomerBasedPricingPlan`/`PlanSendingCreditPrice` file appears anywhere in the diff; no RFC-003 file (`WorkspaceManager`, any Workspace model/repository/migration/test) appears in the diff.

---

## 16. Legacy Plan/Subscription safety

M1 must not modify or repurpose `plans`, `subscriptions`, `SubscriptionLog`, `SubscriptionTransaction`, `CustomerBasedPricingPlan`, or `PlanSendingCreditPrice` — schema, model, or repository. **No RFC-004 foreign key references any legacy Plan/Subscription table** — confirmed directly against §7's exact schema above: every FK targets `currencies`, `workspaces`, `workspace_plan_catalog`, or `businesses`, none targets `plans`/`subscriptions`/anything in that family. No legacy regression test path is fabricated (§5 finding 7) — the safety guarantee is enforced by the exact-scope check (§15), not by an invented command.

---

## 17. Stop/gap rule

If, during M1's own implementation, repository evidence is found that conflicts materially with RFC-004 v1.2 or with this contract as specified — making M1 unsafe or impossible as written — **implementation must STOP and report**: the exact conflict, the RFC section it contradicts, the repository evidence found, and a proposed bounded correction. **Do not silently revise the RFC. Do not implement around the gap.** Minor implementation-detail choices this contract or the RFC intentionally leaves to ordinary repository convention (e.g. exact PHPDoc style, exact private-method decomposition inside a repository) may be resolved directly during implementation without triggering this rule — the line is whether a *structural* fact this contract or the RFC asserts turns out to be wrong, not whether a stylistic choice needs making.

No such conflict was found while drafting this contract (§6's evidence matrix matched RFC-004 v1.2 exactly, for every one of the fifteen feature keys).

---

## 18. Correction-round policy

`maximum_correction_rounds: 2` — matching every prior RFC-003 contract's bounded-correction discipline exactly. A correction round stays inside the exact §12 path list; it does not expand scope.

---

## 19. Governance

Locked:

- `human_only_merge: true`
- `maximum_correction_rounds: 2`
- `advance_automatically: false`
- `start_automatically_after_contract_merge: false`
- `codex_review_required_for_completion: false`
- `automatic_model_handoff_required: false`

No paid model API or usage-credit requirement at any step. No force push. No push directly to `main`. No RFC-004 tag during M1, at any point.

**Implementation authorization semantics after contract merge:** human merge of this contract authorizes exactly one bounded M1 implementation branch/PR (`agent/rfc-004-m1`, created from the then-current `main` containing this merge) — directly, with no further governance artifact required. There is no target-marker PR, no inert implementation PR, and no separate authorization PR, matching RFC-003 Milestone 5/6's proven simplified workflow. This does **not** mean implementation starts automatically: a human still explicitly decides to begin it, and `start_automatically_after_contract_merge`/`advance_automatically` both remain `false` throughout, exactly as they did for every RFC-003 milestone after Milestone 4.

**M1 completion must not automatically start M2.** M2 requires its own separate, human-reviewed, bounded contract, exactly as this contract required after RFC-004's own design PR merged.

---

## 20. M1 completion criteria

M1 is complete only when:

- this contract is human-merged;
- the M1 implementation PR stayed inside the exact 52 authorized paths (§12/§15), with no unrelated file touched;
- all six tables, their models, enums, `PlatformFeatureRegistry`, six repository contracts + Eloquent implementations, and the `AppServiceProvider` binding addition are complete and match §7/§8/§11 exactly;
- the catalog + plan-feature seed (§9) and the `WorkspaceEntitlementBackfillV1` backfill (§10) are complete, with the backfill's own final zero-unassigned assertion having passed against real data;
- no unresolved GAP/BLOCKED item exists (§17);
- all three required human-run regression commands (§14) pass, with actual results honestly recorded — not fabricated, not assumed;
- `git diff --check` is clean;
- human review is complete and the implementation PR is human-merged.

**No RFC-004 tag is created at any point during M1.** **No automatic M2 start occurs** — M2 requires its own separate contract. **No separate M1 closure PR is required by default** — a closure document may be produced following RFC-003's own closure-document convention if the human reviewer wants one, but this contract does not mandate it as a blocking gate unless an actual unresolved governance need appears at completion time that a closure document would specifically address.

## 21. M2 non-authorization statement

This contract authorizes **M1 only** — the data/domain foundation described above. It does not authorize, propose, or select RFC-004 Milestone 2 (the `EntitlementManager` decision engine, plan/status/slot mutation methods, Workspace-override and Business-toggle mutation methods, actor-driven events, or the `createBusinessInWorkspace()` slot-enforcement call). M2 requires its own separate, human-reviewed, bounded contract, drafted only after M1 is itself complete and closed — the same discipline RFC-003 applied between every one of its own milestones.

**Implementation is not authorized under this document until it is human-reviewed and merged.**
