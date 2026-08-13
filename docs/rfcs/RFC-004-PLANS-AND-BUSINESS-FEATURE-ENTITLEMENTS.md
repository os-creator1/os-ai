# RFC-004 — Plans and Business Feature Entitlements

**Status:** DRAFT — DESIGN ONLY — IMPLEMENTATION NOT AUTHORIZED
**Version:** 1.0
**Priority:** P1
**Target framework:** Laravel 12 / PHP 8.2
**Architecture constraint:** Extend the existing Ultimate SMS controller → repository → library/manager → model structure. Do not introduce a new generic service-layer convention.
**Depends on:** RFC-001 Business Core, RFC-002 Opportunity Engine, RFC-003 Workspace and Business Account Core (tagged `rfc-003-workspace-and-business-account-core`)
**Enables:** RFC-005 Business Usage Billing and Wallets

Merging the future RFC-004 design PR does **not** automatically authorize implementation. After this RFC is accepted, Milestone 1 requires its own separate, human-reviewed, bounded implementation contract — the same discipline RFC-003 followed for every one of its milestones.

---

## 1. Purpose

RFC-003 gave every customer a Workspace — a universal tenancy container that can hold one Business or many, with owner/member roles and per-membership Business-access scoping. It deliberately implemented no commercial packaging: every Workspace today has unlimited Business slots and unlimited feature access, because RFC-003 §4 explicitly deferred "plans, Workspace plan assignment, or Business slot limits" and "per-Business feature toggles or a platform feature registry" to this RFC.

RFC-004 answers one question: **what commercial tier is a Workspace on, what does that tier structurally entitle it to, and how does the platform decide — cheaply, deterministically, and auditably — whether a given feature may execute for a given Business right now?** It introduces the Core/Growth/Agency plan tiers, a platform feature registry, Workspace-level plan assignment and admin overrides, Business-level feature toggles, and Business-slot capacity enforcement (3 included, up to 5 for Core/Growth, unlimited for Agency).

RFC-004 does **not** implement billing execution, usage metering, wallets, or Stripe integration — those are RFC-005's exclusive scope, deliberately walled off here (§19).

---

## 2. Goals

- Formalize Core/Growth/Agency as data-driven commercial tiers assigned to a Workspace, never a separate tenancy model.
- Introduce one authoritative platform feature registry with stable, code-defined machine keys.
- Define a deterministic, precedence-ordered effective-access-decision algorithm: platform support → Workspace plan entitlement → Workspace admin override → Business feature toggle → billing state → (RFC-005) usage authorization.
- Enforce Business/location slot capacity (3 included, 4–5 as paid add-ons, 6+ requires Agency) at the same transactional boundary RFC-003 already uses for Business creation, safely under concurrency.
- Give the platform admin an explicit, audited complimentary-assignment mechanism, including for the platform owner's own Workspace.
- Preserve RFC-003's authorization boundary exactly: plan entitlement is an additional gate layered on top of Workspace/Business authorization, never a replacement for it, and never a way to bypass it.
- Leave a clean, minimal, already-satisfiable seam for RFC-005 so usage-authorization can be added later without reshaping anything RFC-004 ships.

---

## 3. Non-goals

RFC-004 does not implement: Business usage wallets, ledgers, payers, or auto-recharge (RFC-005, §19); Stripe or any payment-gateway integration change; a redesign of the legacy `plans`/`subscriptions` SMS-credit system (§7); an `Agency` model or `businesses.agency_id` (explicitly forbidden, matching RFC-003 §4/§27); a redesign of the Prospect Outreach Engine's existing safety rules (§13.1); a full white-label implementation beyond gating an existing bounded capability if one exists (§13.2); implementation of SEO/Ads/AI modules that do not yet exist in code, beyond defining their stable entitlement keys (§12.4); any change to RFC-003's Workspace/Business tenancy, ownership, membership, or `business_access_scope` model (§9's isolation invariant); any change to RFC-001/RFC-002 behavior beyond the one additive Business-creation call site named in §17.

---

## 4. Dependency on RFC-001, RFC-002, RFC-003

- **RFC-001** established `Business` (`businesses.customer_id`) as the aggregate root with locations/services. RFC-004 gates *feature access on* a Business; it never changes what a Business *is*.
- **RFC-002** built the Opportunity Engine on top of a single Business. Prospect Outreach (§13.1) is an Opportunity-Engine-adjacent capability that RFC-004 only gates; RFC-004 does not touch `Opportunity`/`OpportunityRun` code.
- **RFC-003** established the Workspace as the universal tenancy container, `WorkspaceManager` as the sole domain authority for Workspace/Business/membership mutation, and the §14.1 `userCanAccessBusiness()` authorization algorithm. RFC-004 is layered *on top of* that algorithm, never inside or in place of it (§9, §14). RFC-003 is complete and tagged `rfc-003-workspace-and-business-account-core` — this RFC treats that tag's tree as its starting point and does not reopen any RFC-003 milestone.

---

## 5. Current repository findings

Findings from direct inspection before drafting this RFC (do not assume; every claim below was verified against the actual files):

1. **The `plans`/`subscriptions` stack is the original (2018-era) Ultimate SMS reseller SMS-credit billing system, not a Workspace SaaS tier system.** `database/migrations/2018_12_01_193935_create_plan_table.php` is among the very first migrations in this codebase, predating RFC-001 by years. `plans.user_id` is nullable with `onDelete('cascade')` (a plan may belong to a specific reseller sub-account or be global); `plans.options` is a JSON text blob (`Plan::defaultOptions()`) whose keys are entirely SMS-domain: `sms_max`, `whatsapp_max`, `sending_limit`, `sending_quota`, `sender_id_verification`, `create_sub_account`, `api_access`. `PlanRepository`'s methods (`updateSpeedLimits()`, `updatePricing()`, `updateSenderID()`, `updateCreditPrice()`, coverage-country batch operations) are all SMS-sending-plan-shaped.
2. **`Subscription` belongs to `user_id`, not `workspace_id` or `business_id`.** `database/migrations/2019_03_09_065029_create_subscriptions_table.php`: `user_id` (not nullable), `plan_id`, `payment_method_id`, `status` enum (`new`/`pending`/`active`/`ended`/`renew`), `options` (JSON, SMS credit-warning/notification settings). `Subscription implements HasQuotaInterface` and `use HasQuota` — it is wired directly into the SMS sending-rate/credit-quota system (`App\Library\RateTracker`), a completely different concern from Workspace commercial packaging.
3. **Every foreign key in the legacy Plan/Subscription schema uses `onDelete('cascade')`**, the opposite of RFC-003's `restrictOnDelete()` posture for tenancy-critical data. Reusing these tables for Workspace plan assignment would inherit a deletion policy this RFC's commercial/audit requirements cannot accept.
4. **`SubscriptionLog`, `SubscriptionTransaction`, `CustomerBasedPricingPlan`, `PlanSendingCreditPrice`** are all further SMS-billing machinery: country/sending-server-based per-credit pricing, payment transaction logs — none of it maps to "Workspace commercial tier" or "Business feature entitlement" by any reasonable extension.
5. **RFC-003's Workspace domain is exactly as its own closure documents describe** (cross-checked against `RFC-003-M4-CLOSURE.md`, `RFC-003-M5-CLOSURE.md`, `RFC-003-M6-CONFORMANCE.md`, all already verified in this repository): `WorkspaceManager` (`app/Library/Workspace/WorkspaceManager.php`, ~1,590 lines) is the sole authority for Workspace/membership/Business mutation and the `userCanAccessBusiness()` algorithm; `WorkspaceRepository`/`WorkspaceMembershipRepository`/`WorkspaceMembershipBusinessRepository`/`WorkspaceTransitionRepository` are plain data-access contracts with no `Interface` suffix, bound in `AppServiceProvider::register()`'s `$bindings` array (`WorkspaceRepository::class => EloquentWorkspaceRepository::class`, confirmed at line 148); `Workspace`/`WorkspaceMembership`/`WorkspaceMembershipBusiness` models exist with the relationships RFC-003 §11 specifies; `config/permissions.php` already carries `'view workspace'` (Milestone 5) alongside the legacy `'manage plans'`/`'create plans'`/`'edit plans'`/`'delete plans'` keys (category `Plan`) — a naming collision RFC-004 must avoid by choosing a distinct permission category (§20).
6. **`payment_methods`** (`database/migrations/2018_07_27_112022_create_payment_methods_table.php`) exists and is referenced by `subscriptions.payment_method_id`. RFC-004 does not use it directly — payment execution is RFC-005's boundary (§18/§19) — but its existence confirms a payment-method concept already exists in this codebase for RFC-005 to eventually build on, and RFC-004's plan-assignment `status` enum is deliberately shaped to be compatible with it later without RFC-004 needing to reference it now.
7. **RFC-001/RFC-002/RFC-003 precedent is consistent and directly reusable**: repository-contract naming with no `Interface` suffix; `App\Library\{Domain}\{Domain}Manager` for orchestration; `App\Enums\{Domain}\*` for string-backed PHP enums (no native DB `ENUM` columns); durable `*_transitions` audit tables reserved for the highest-stakes mutations only (RFC-002's `opportunity_transitions`, RFC-003's `workspace_transitions`); `tests/Unit/{Domain}` + `tests/Feature/{Domain}` test-directory pairing.

---

## 6. Legacy Plan/Subscription compatibility decision

**Decision: (B) — introduce a distinct Workspace-plan/entitlement domain, entirely separate from the legacy `plans`/`subscriptions` tables, models, and repositories.** The legacy stack is left completely untouched — no column added, no relationship added, no shared model, no shared repository.

**Justification**, directly from §5's findings: the legacy system is keyed to `user_id`, not `workspace_id`; its cascading-delete FK policy is incompatible with RFC-003's restrictive-delete tenancy posture; its `options` JSON blob encodes SMS-sending-quota concerns (credits, sender IDs, DLT compliance) that have no conceptual overlap with "does this Workspace's plan include the SEO module." Reusing it would conflate four genuinely distinct concerns this RFC must keep separable:

| Concern | System of record |
|---|---|
| SMS sending credits/quota, sender-ID/DLT compliance | Legacy `plans`/`subscriptions` (untouched) |
| Workspace commercial tier (Core/Growth/Agency) and Business-slot entitlement | **New**, this RFC |
| Business/platform feature entitlement | **New**, this RFC |
| Business usage-wallet balance and metered billing | RFC-005 (future) |

A customer's existing SMS plan/subscription is completely orthogonal to which Workspace commercial tier their Workspace is on — a Core-tier Workspace and an Agency-tier Workspace can both, independently, be on any legacy SMS sending plan or none at all. This RFC's schema (§10) references neither `plans.id` nor `subscriptions.id`. If a future RFC ever needs to correlate the two (e.g. bundling SMS credits into a Workspace tier), that is an explicit, separately reviewed decision — not something RFC-004 infers or wires implicitly.

**Current-state compatibility matrix:**

| Legacy concept | RFC-004 concept | Relationship |
|---|---|---|
| `plans` (SMS sending plan) | `workspace_plan_catalog` (commercial tier) | None — independent domains, independent tables |
| `subscriptions` (per-user SMS billing state) | `workspace_plan_assignments` (per-Workspace tier assignment) | None — independent domains, independent tables |
| `Plan::options` JSON (sms_max, sending_quota, ...) | `workspace_plan_features` (plan → feature key mapping) | None in shape; both are "what does this plan include," but for disjoint feature sets |
| `payment_methods` | Not referenced by RFC-004 | Deferred to RFC-005, which will decide how `workspace_plan_assignments.status` transitions relate to actual payment collection |

---

## 7. Terminology

| Term | Meaning |
|---|---|
| Plan tier | One of `Core`, `Growth`, `Agency` — a fixed, code-defined identity (`WorkspacePlanTier` enum) with tier-specific structural rules (slot counts). Not a database row's primary identity; the database row (`workspace_plan_catalog`) holds that tier's mutable commercial data (price, active state). |
| Plan catalog | The `workspace_plan_catalog` table — one row per tier, holding pricing/display data. Data/catalog, not hard-coded. |
| Platform feature | A stable, code-defined machine key (`PlatformFeature` enum) identifying one gateable capability, independent of whether it is entitled to anyone yet. |
| Plan-feature mapping | `workspace_plan_features` — which platform features a given plan-catalog row includes. |
| Workspace plan assignment | `workspace_plan_assignments` — the one active plan tier a Workspace currently has, plus its billing status and complimentary flag. |
| Workspace entitlement override | `workspace_entitlement_overrides` — an explicit, audited, per-feature admin exception (`allow` or `deny`) layered on top of the Workspace's plan-derived entitlement. |
| Business feature toggle | `business_feature_toggles` — an explicit, per-Business, per-feature *disable* narrowing what the Workspace would otherwise entitle that Business to. Never a grant beyond Workspace entitlement. |
| Effective entitlement | The final allow/deny decision for (Workspace, Business, feature), computed by walking the precedence chain in §14. |
| Complimentary assignment | A plan assignment explicitly marked as not requiring a recurring charge, with normal entitlements and normal slot rules still applying unless separately overridden. |
| Business slot | One unit of Business-creation capacity under a Workspace's assigned tier (§13). |

---

## 8. Authoritative invariants

- Every Workspace has at most one active `workspace_plan_assignments` row at a time (unique `workspace_id`). A Workspace with no row is treated as unassigned — see §16 for the deterministic backfill guarantee that this state does not persist for any pre-existing Workspace.
- `workspace_plan_catalog` price/currency changes never retroactively change what an *already-created* Business's slot cost was — RFC-004 does not implement billing execution (§19); this invariant matters only for RFC-005's future consumption of this data.
- A `PlatformFeature` key that does not exist in the code-defined registry can never be entitled, overridden, or toggled — every write path validates against the registry before persisting.
- A Business feature toggle can only ever narrow access (`disabled`), never widen it beyond the Workspace's effective entitlement (§9, §14, §15).
- Disabling a feature at the platform-registry or Workspace-plan level always overrides any Business-level enabled state — there is no path for a Business to retain access to a feature the Workspace has lost.
- Every commercially significant entitlement mutation (plan assignment, plan change, complimentary status change, override change) is both event-dispatched and, for plan assignment/change/complimentary status specifically, durably audited in `workspace_plan_transitions` (§21).
- Business-slot capacity enforcement never trusts a UI-only check; it is enforced inside the same locked transaction RFC-003's `WorkspaceManager::createBusinessInWorkspace()` already uses (§17).
- Nothing in this RFC's schema forecloses RFC-005: `workspace_plan_assignments.status` is a plan/billing lifecycle flag only, never a usage-wallet balance or ledger.

---

## 9. RFC-003 isolation remains authoritative

No entitlement feature introduced by this RFC may weaken: Workspace isolation, Business isolation, owner/member authorization, `all`/`selected` Business-access scopes, or the independent platform-admin boundary (`EnsureUserIsAdministrator`, unmodified). **Plan entitlement is an additional gate, evaluated only after RFC-003's own authorization has already granted access** — never a substitute for it and never evaluated first. A user who is fully entitled to a feature by plan but not authorized for the Workspace or Business under RFC-003 §14.1 still cannot access it; conversely, a user authorized under §14.1 but not entitled by plan is blocked by this RFC's gate. The two systems are evaluated independently and both must pass (§14's algorithm assumes RFC-003 authorization has already succeeded before it is even invoked — it is not part of the entitlement decision chain itself, it is a precondition for calling it at all).

---

## 10. Proposed data model

All new tables use `id`, `created_at`, `updated_at` (except the audit table, which follows `workspace_transitions`' `created_at`-only, immutable-row convention). No database-native `ENUM` columns — string columns cast to string-backed PHP enums under `App\Enums\Entitlement`, matching RFC-003 §9's AD-004 convention.

### 10.1 `workspace_plan_catalog`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `tier` | varchar(20) | no | — | `WorkspacePlanTier`: `core`\|`growth`\|`agency`. Unique. |
| `display_name` | varchar(255) | no | — | Admin/customer-facing name |
| `price` | decimal(16,2) | no | — | Data, not hard-coded (§12) |
| `currency_id` | bigint unsigned FK | no | — | References existing `currencies.id` — reuses the same currency catalog the legacy `plans` table already uses |
| `billing_cycle` | varchar(20) | no | `monthly` | `monthly`\|`yearly` — deliberately narrower than legacy `Plan`'s billing-cycle set; RFC-005 may extend if a real product need arises |
| `business_slot_included` | unsigned tinyint | no | — | `3` for Core/Growth; `3` for Agency too (documentation value only — see `unlimited_business_slots`) |
| `business_slot_max` | unsigned tinyint, nullable | yes | `null` | `5` for Core/Growth; `null` for Agency (see `unlimited_business_slots`, which is authoritative — `business_slot_max` is descriptive/UI-only when unlimited) |
| `unlimited_business_slots` | boolean | no | `false` | `true` only for `agency`. Authoritative for slot enforcement (§13) — `business_slot_max` is never consulted when this is `true` |
| `is_active` | boolean | no | `true` | An inactive catalog row cannot receive new assignments, but existing assignments referencing it are unaffected (matches RFC-003's `is_active`-is-tenancy-not-deletion posture) |
| timestamps | — | no | — | |

Indexes: unique `tier`, index `is_active`.
Foreign key: `currency_id` → `currencies.id`, `restrictOnDelete()`.

Seeded once, at Milestone 1, with exactly three rows (`core`, `growth`, `agency`) — see §16.

### 10.2 `workspace_plan_features`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_plan_catalog_id` | bigint unsigned FK | no | — | Which tier this mapping belongs to |
| `feature_key` | varchar(64) | no | — | Validated against `PlatformFeature` at the application layer before insert — no plain FK can express "must be a valid enum case" |
| timestamps | — | no | — | |

Indexes/constraints: unique `(workspace_plan_catalog_id, feature_key)`.
Foreign key: `workspace_plan_catalog_id` → `workspace_plan_catalog.id`, `restrictOnDelete()`.

Existence of a row = that tier includes that feature. No boolean column — an absent row is the only "not included" representation, avoiding a redundant `is_included = false` row that would never legitimately exist.

### 10.3 `workspace_plan_assignments`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_id` | bigint unsigned FK | no | — | The Workspace this assignment belongs to. Unique — one active assignment per Workspace, matching §8 |
| `workspace_plan_catalog_id` | bigint unsigned FK | no | — | The current tier |
| `status` | varchar(20) | no | — | `WorkspacePlanAssignmentStatus`: `active`\|`inactive`\|`suspended`. No default — every assignment-creation path must supply it explicitly, matching RFC-003 §9.2's `business_access_scope` no-default precedent |
| `is_complimentary` | boolean | no | `false` | Independent of `status` — a complimentary assignment can still be `active`/`suspended` |
| `complimentary_reason` | text, nullable | yes | `null` | Required at the application layer whenever `is_complimentary` is set `true` (§15) |
| `complimentary_granted_by_user_id` | bigint unsigned, nullable, no FK | yes | `null` | Plain scalar, not a FK — matches RFC-003 `workspace_transitions.actor_user_id`'s "must never block a legitimate user-deletion feature" rationale. `null` only for the one-time Milestone-1 backfill (§16), never for an admin-initiated grant |
| `complimentary_granted_at` | timestamp, nullable | yes | `null` | |
| timestamps | — | no | — | |

Indexes: unique `workspace_id`, index `workspace_plan_catalog_id`, index `status`.
Foreign keys: `workspace_id` → `workspaces.id`, `restrictOnDelete()`; `workspace_plan_catalog_id` → `workspace_plan_catalog.id`, `restrictOnDelete()`.

### 10.4 `workspace_plan_transitions` (durable audit — mirrors `workspace_transitions`)

Limited to the commercially significant mutations only, following RFC-003 §19's "not every event needs a durable row" discipline.

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_id` | bigint unsigned FK | no | — | |
| `transition_type` | varchar(40) | no | — | `WorkspacePlanTransitionType`: `plan_assigned`\|`plan_changed`\|`complimentary_granted`\|`complimentary_revoked` |
| `actor_user_id` | bigint unsigned, nullable, no FK | yes | `null` | `null` only for the Milestone-1 backfill's `plan_assigned` rows |
| `from_plan_catalog_id` | bigint unsigned, nullable, FK | yes | `null` | Null for the initial assignment |
| `to_plan_catalog_id` | bigint unsigned, nullable, FK | yes | `null` | Null only for a pure complimentary-status change that does not also change tier |
| `reason` | text, nullable | yes | `null` | |
| `created_at` | timestamp | no | — | No `updated_at` — immutable row, matching `workspace_transitions` |

Indexes: composite `(workspace_id, created_at)` (serves both the per-Workspace lookup and InnoDB's FK-leftmost-index requirement, exactly matching `workspace_transitions`' documented rationale).
Foreign keys: `workspace_id` → `workspaces.id`; `from_plan_catalog_id`/`to_plan_catalog_id` → `workspace_plan_catalog.id`, all `restrictOnDelete()`.

### 10.5 `workspace_entitlement_overrides`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_id` | bigint unsigned FK | no | — | |
| `feature_key` | varchar(64) | no | — | Validated against `PlatformFeature` at the application layer |
| `state` | varchar(10) | no | — | `WorkspaceEntitlementOverrideState`: `allow`\|`deny`. No `inherit` value is ever stored — see below |
| `reason` | text, nullable | yes | `null` | Required at the application layer whenever an override is created (§15) |
| `created_by_user_id` | bigint unsigned, nullable, no FK | yes | `null` | |
| timestamps | — | no | — | |

Indexes/constraints: unique `(workspace_id, feature_key)`.
Foreign key: `workspace_id` → `workspaces.id`, `restrictOnDelete()`.

**Why "inherit" is never a stored row rather than a third enum value:** an explicit `inherit` row would be a no-op that means exactly the same thing as no row at all, while adding a case every reader must handle identically to absence. Modeling the tri-state as {no row = inherit, row with `state = allow`, row with `state = deny`} keeps the table's only rows meaningful (an actual exception exists) and "revert to inherit" a plain delete — auditable via the `WorkspaceEntitlementOverrideChanged` event (§21), not via row retention.

### 10.6 `business_feature_toggles`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `business_id` | bigint unsigned FK | no | — | |
| `feature_key` | varchar(64) | no | — | Validated against `PlatformFeature` at the application layer |
| `reason` | text, nullable | yes | `null` | Optional — Business-level toggles are lower-stakes than Workspace overrides |
| `created_by_user_id` | bigint unsigned, nullable, no FK | yes | `null` | The Workspace owner/admin who disabled it |
| timestamps | — | no | — | |

Indexes/constraints: unique `(business_id, feature_key)`.
Foreign key: `business_id` → `businesses.id`, `restrictOnDelete()`.

**No boolean/"enabled" column and no "allow" state, by design (§15):** a Business toggle can only ever disable — the RFC's own invariant ("never grant a feature the Workspace is not effectively entitled to") means there is no legitimate "explicitly enabled beyond default" state to store. A row's mere existence *is* "disabled here"; absence is "enabled, subject to Workspace entitlement." Re-enabling deletes the row.

### 10.7 No JSON blob for authoritative state

Every table above is fully relational and queryable — no entitlement decision requires deserializing a JSON column, unlike the legacy `Plan`/`Subscription::options` blobs this RFC deliberately does not extend (§6). The only place a JSON-like structure might seem tempting — "which features does this plan include" — is instead the fully-indexed, uniquely-constrained `workspace_plan_features` pivot table, so "does plan X include feature Y" is a single indexed lookup, not an application-layer JSON decode.

---

## 11. Feature-registry architecture

**Decision: hybrid — feature *identity* is code-backed; feature *packaging* (which plan includes which feature, and Workspace/Business exceptions) is database-backed.**

**Justification:** a feature cannot be gated unless code exists to check the gate at the point of use — you cannot enable access to a controller action, view section, or job that has no corresponding code. The registry of "what capabilities the platform structurally supports" is therefore `App\Enums\Entitlement\PlatformFeature`, a string-backed PHP enum, matching RFC-003's `WorkspaceMembershipRole`/`WorkspaceBusinessAccessScope` convention exactly — adding a new feature key is a code change (a new enum case) by necessity, since the feature's actual implementation is also a code change. But *which plan tier includes which feature*, and *which Workspace/Business has an exception*, are commercial/operational decisions an admin must be able to change without a deploy — hence §10.2/§10.5/§10.6 being ordinary database tables, not additional enum cases or hard-coded arrays.

```php
enum PlatformFeature: string
{
    case Crm = 'crm';
    case Conversations = 'conversations';
    case Calendar = 'calendar';
    case Forms = 'forms';
    case Automations = 'automations';
    case WebsiteGeneration = 'website_generation';
    case AiCooBasic = 'ai_coo_basic';
    case SeoBasicVisibility = 'seo_basic_visibility';
    case AdsBasicVisibility = 'ads_basic_visibility';
    case SeoModule = 'seo_module';
    case GoogleAdsModule = 'google_ads_module';
    case MetaAdsModule = 'meta_ads_module';
    case WhiteLabel = 'white_label';
    case AgencyPackageCapabilities = 'agency_package_capabilities';
    case ProspectOutreach = 'prospect_outreach';
}
```

`PlatformFeature::cases()` is the single source of truth every write path (`workspace_plan_features`, `workspace_entitlement_overrides`, `business_feature_toggles`) validates `feature_key` against before persisting — never a free-text string accepted from a controller. No feature-name string literal is scattered through controllers or views; every reference goes through `PlatformFeature::{Case}->value` or the enum itself.

**Distinguishing entitlement architecture from actual implementation availability (§12.4):** every case above may exist in the enum before its underlying module is built. `SeoModule`, `GoogleAdsModule`, `MetaAdsModule`, `WhiteLabel`, `AgencyPackageCapabilities`, and `ProspectOutreach` (this last one *does* have an existing implementation — the Opportunity Engine's Prospect Outreach capability, RFC-002) are examples where the *entitlement key* is defined now so plan packaging (§12) is stable and complete, while the *underlying capability* may or may not exist in code yet. An entitlement row (`workspace_plan_features`) asserting a plan includes `SeoModule` is a packaging fact, not a claim that a `SeoModule` controller exists — a caller checking entitlement for a not-yet-built module will correctly get "entitled" and must independently know the feature isn't wired to any UI/route yet, exactly as an ungated, unbuilt feature naturally behaves today. This RFC does not require every enum case to have a corresponding implementation before Milestone 1 ships.

---

## 12. Plan model / Core-Growth-Agency semantics

### 12.1 Tier identity vs. commercial data

`WorkspacePlanTier` (`core`\|`growth`\|`agency`) is a fixed, code-defined enum — only three tiers exist, and each carries tier-*structural* rules (slot counts, §13) that are product decisions fixed in this RFC's text, not admin-configurable. `workspace_plan_catalog` (§10.1) holds each tier's *commercial* data (price, display name, active state) — genuinely data/catalog, changeable by an admin without a deploy, exactly as required. This split resolves the tension between "pricing must be data" and "the tier's slot rules are fixed product decisions": identity+structure = enum, money+packaging = table.

### 12.2 Plan feature matrix (Milestone 1 seed data, §16)

| Feature | Core | Growth | Agency |
|---|---|---|---|
| CRM / contacts | ✓ | ✓ | ✓ |
| Conversations / messaging surfaces | ✓ | ✓ | ✓ |
| Calendar | ✓ | ✓ | ✓ |
| Forms / surveys | ✓ | ✓ | ✓ |
| Automations | ✓ | ✓ | ✓ |
| Website generation / hosting | ✓ | ✓ | ✓ |
| Basic AI COO capability | ✓ | ✓ | ✓ |
| Basic SEO visibility | ✓ | ✓ | ✓ |
| Basic Ads visibility | ✓ | ✓ | ✓ |
| SEO module | | ✓ | ✓ |
| Google Ads module | | ✓ | ✓ |
| Meta Ads module | | ✓ | ✓ |
| White-label | | | ✓ |
| Agency package capabilities | | | ✓ |
| Prospect Outreach | | | ✓ |

This table is realized as fifteen `workspace_plan_features` rows for Agency, twelve for Growth, nine for Core — seeded once at Milestone 1 (§16), editable afterward through the admin surface (§22) without a deploy.

### 12.3 Slot policy per tier

See §13 for the full slot model. `workspace_plan_catalog` values: Core → `business_slot_included = 3`, `business_slot_max = 5`, `unlimited_business_slots = false`. Growth → identical slot shape to Core (the RFC's product direction gives Growth the same 3-included/5-max structure as Core, differing only in feature packaging per §12.2). Agency → `unlimited_business_slots = true` (`business_slot_max` is `null` and not consulted).

### 12.4 Entitlement architecture vs. implementation availability

Restated from §11: this RFC defines the complete Core/Growth/Agency feature matrix above, including keys for modules that may not exist in code yet (`SeoModule`, `GoogleAdsModule`, `MetaAdsModule`, `WhiteLabel`, `AgencyPackageCapabilities`). Implementing those modules is explicitly out of scope for RFC-004 (§3) — this RFC only guarantees that when they are built, the plan they should be gated behind is already a stable, seeded fact, not a design question to redo.

---

## 13. Business-slot semantics

- **Core/Growth:** the first 3 Businesses in a Workspace are included in the base price. Businesses 4 and 5 may exist as paid additional slots, each commercially priced at 50% of the tier's normal plan price (a product/billing fact this RFC records but does not execute — see §18; RFC-004 owns *whether* the Workspace is allowed to have the slot, not the invoicing of it). 5 is the hard maximum for Core/Growth. A 6th Business requires the Workspace to be on Agency.
- **Agency:** unlimited Businesses/locations (`unlimited_business_slots = true`).
- **Slot entitlement vs. RFC-005 usage-wallet billing:** these are different concerns. RFC-004 answers "is this Workspace *allowed* to have a 4th Business right now" — a yes/no structural question enforced at Business-creation time (§17). Whether/how the resulting 50%-priced slot is actually invoiced, charged, or reflected in a usage ledger is RFC-005's exclusive domain (§19); RFC-004 records the commercial rule (`business_slot_included`/`business_slot_max` on the catalog) cleanly enough for RFC-005 to consume later, but implements no charge itself.
- **Effective slot count** for a Workspace = `count(businesses WHERE workspace_id = :id)` (all Businesses, regardless of individual `status`, since a Business row existing under the Workspace is what consumes tenancy capacity — RFC-003 draws no distinction here and neither does this RFC). Compared against the assigned tier's `business_slot_max` (or bypassed entirely when `unlimited_business_slots = true`).
- **Denial, not deletion:** attempting to create a 6th Business on Core/Growth is denied at creation time (§17) with a stable denial reason (§14). No existing Business is ever removed, hidden, or deactivated as a consequence of a slot limit, at any point, including during backfill (§16).

---

## 14. Entitlement precedence algorithm

Preconditions: RFC-003 §14.1 authorization (`userCanAccessBusiness()`) has already granted the acting user access to this Business, and the caller has already resolved the Business's owning Workspace. This algorithm is never invoked to *establish* Workspace/Business authorization — only to answer, given already-authorized access, whether a specific feature may execute.

```text
function effectiveEntitlement(workspace, business, featureKey, actorUserId):
    if featureKey not in PlatformFeature::cases():
        return denied('platform_feature_unknown')

    override = WorkspaceEntitlementOverride.find(workspace, featureKey)
    if override is not null:
        workspaceEntitled = (override.state == ALLOW)
        denialReasonIfNot = override.state == DENY
            ? 'denied_by_workspace_override'
            : unreachable  // ALLOW never denies here
    else:
        assignment = WorkspacePlanAssignment.find(workspace)
        workspaceEntitled = PlanFeatureMapping.exists(assignment.plan_catalog_id, featureKey)
        denialReasonIfNot = 'not_entitled_by_plan'

    if not workspaceEntitled:
        return denied(denialReasonIfNot)

    if BusinessFeatureToggle.exists(business, featureKey):
        return denied('disabled_for_business')

    billingState = effectiveBillingState(assignment)  // see §18
    if billingState != ACTIVE and billingState != COMPLIMENTARY:
        return denied(billingState == SUSPENDED ? 'plan_suspended' : 'plan_inactive')

    usageAuthorization = UsageAuthorizationGateway::check(business, featureKey)  // RFC-005 seam, §19
    if not usageAuthorization.authorized:
        return denied(usageAuthorization.reason)  // never reachable before RFC-005 ships (§19)

    return allowed()
```

**Precedence, explicit and total (no ambiguity left to a caller's interpretation):**

1. **Platform support** is the hard floor. An unknown feature key is always denied, regardless of any override or plan — this can only happen from a bug or a stale key, never a legitimate state.
2. **Workspace effective entitlement** = the override if one exists for this exact `(workspace, feature)` pair, else the plan-feature mapping. An override, when present, **completely replaces** the plan-mapping answer for that feature — it does not merely add to it. This is the one deliberate point where an admin override may grant a feature outside the Workspace's base plan (see §15's explicit "yes" and its justification).
3. **Business feature toggle** can only narrow a `true` Workspace entitlement to `false` for one specific Business — it is consulted only after Workspace entitlement has already passed, and can never flip a Workspace `false` to a Business-level `true` (there is no data path in §10.6 that could even represent that).
4. **Billing state** gates *feature entitlement decisions* specifically — it never gates RFC-003 tenancy access (login, viewing owned data, the Workspace overview). This mirrors RFC-003 §14.2's own forward-documented distinction verbatim: "RFC-004's entitlement rule may disable paid execution while `is_active` stays `true` and ordinary account access continues."
5. **Usage authorization** is the RFC-005 seam (§19) — until RFC-005 ships, this step is a fixed pass-through that always returns `authorized: true`, so the chain degrades to exactly steps 1–4 today, with the exact same call shape (`UsageAuthorizationGateway::check()`) that RFC-005 will implement for real without any caller needing to change.

Every denial carries one of a small, stable set of reason keys (`platform_feature_unknown`, `not_entitled_by_plan`, `denied_by_workspace_override`, `disabled_for_business`, `plan_inactive`, `plan_suspended`; a `usage_unauthorized`-shaped key is reserved for RFC-005 and never returned today) — suitable for tests, UI messaging, logs, and eventual RFC-005 billing-decision integration without redesign.

---

## 15. Workspace overrides

An admin override may grant a feature **outside** the Workspace's assigned plan's base mapping. **This is explicit and intentional — stated here rather than left unresolved, per this RFC's own requirement.** Justification: sales exceptions, support goodwill, and pilot/beta access are legitimate, common SaaS operational needs, and an admin-only, reason-required, fully audited override is a safer way to satisfy them than an ad hoc code change or a manually-edited plan-mapping row that would affect every Workspace on that tier. The override can **never** exceed step 1 of §14 (platform support) — an override's `feature_key` is validated against `PlatformFeature::cases()` exactly like every other write path, so an admin cannot invent a feature the platform doesn't structurally support.

Every override write requires: the acting admin's `created_by_user_id`, a non-empty `reason`, and dispatches `WorkspaceEntitlementOverrideChanged` (§21) — fully auditable, matching the RFC's explicit requirement. Reverting to inherit is a delete of the override row, also event-dispatched.

**Precedence between platform support, plan mapping, Workspace override, and Business toggle** (restated compactly from §14): platform support (floor) → Workspace override if present, else plan mapping (Workspace-level entitlement) → Business toggle (narrows only, never widens). This order is total and admits no ambiguity: a `deny` override always wins over the plan; an `allow` override always wins over the plan; a Business toggle can only ever remove access the Workspace step already granted.

---

## 16. Business feature toggles

A Workspace owner or active Admin (RFC-003 §7.3's existing authority levels — reused unmodified, not redefined here) may disable a specific feature for a specific Business under their Workspace, provided the Workspace is effectively entitled to that feature at all (§14 step 3). **A Business toggle can never grant a feature the Workspace is not entitled to** — enforced structurally, not just by convention: §10.6's table has no "enabled beyond default" state to write, and the write path (§20) rejects any attempt to create a toggle for a feature the Workspace does not currently have effective entitlement to, since there would be nothing meaningful to disable.

**Creation defaults for a new Business (deterministic):** a newly created Business (via RFC-003's existing `WorkspaceManager::createBusinessInWorkspace()`/`reassignBusiness()`) starts with **zero** `business_feature_toggles` rows — every feature the Workspace is effectively entitled to is available to the new Business by default, matching §10.6's "absence = enabled" model. No RFC-003 code path needs to change to satisfy this default; it falls out naturally from never writing a toggle row unless an owner/admin explicitly disables something afterward.

---

## 17. Business-creation slot enforcement

RFC-003's `WorkspaceManager::createBusinessInWorkspace()` remains the sole Business-creation orchestration entry point — RFC-004 does not duplicate or parallel it. **Exactly one additive call is inserted into that existing method**, at the point after it locks the Workspace row (`findForUpdate()`, already present per RFC-003 §18) and before it creates the new `Business` row:

```php
$this->entitlementManager->assertCanCreateAnotherBusiness($lockedWorkspace);
```

`EntitlementManager::assertCanCreateAnotherBusiness(Workspace $workspace): void` (new, `app/Library/Entitlement/EntitlementManager.php`) reads the Workspace's current `workspace_plan_assignments` row (locked implicitly by the caller already holding the Workspace row lock — see below) and its catalog tier's slot policy, counts current Businesses for that `workspace_id`, and throws `BusinessSlotLimitExceededException` (new, `app/Exceptions/Entitlement/`) if at capacity and not unlimited. `createBusinessInWorkspace()` propagates this exception uncaught, exactly as it already propagates `InactiveWorkspaceMutationException`/`UnauthorizedWorkspaceManagementException` today — no new try/catch pattern, no duplicated authority logic.

**Concurrency safety — two simultaneous requests must not both consume the last slot:** `createBusinessInWorkspace()` already locks the Workspace row (`findForUpdate()`) for the duration of its transaction before this call executes (RFC-003 §18). Because the slot count (`COUNT(*) FROM businesses WHERE workspace_id = ?`) is read *after* that lock is held, and the Business insert happens inside the same transaction before the lock releases, two concurrent calls for the same Workspace serialize on that existing lock exactly the way RFC-003 already serializes every other Workspace mutation — the second call's count read (which only proceeds after the first transaction commits or rolls back) reflects the first call's result. No new lock, no new transaction boundary, no new concurrency primitive is introduced; this reuses RFC-003's existing locking discipline unmodified.

---

## 18. Billing-state boundary

`workspace_plan_assignments.status` (`active`\|`inactive`\|`suspended`) is the plan/billing-lifecycle flag this RFC owns, orthogonal to `is_complimentary`. **Effective billing state**, as consumed by §14 step 4:

- `is_complimentary = true` → always treated as `COMPLIMENTARY` for entitlement purposes, regardless of `status` — normal entitlements apply, no billing-state denial, matching §16's (product) complimentary semantics exactly. (An admin could still separately set `status = suspended` on a complimentary assignment for an unrelated operational reason — that combination is legal and denies via `plan_suspended` even though complimentary, since suspension is a distinct signal from "requires payment.")
- `is_complimentary = false`, `status = active` → `ACTIVE`, entitlements apply normally.
- `is_complimentary = false`, `status = inactive` → `INACTIVE` — plan lapsed/cancelled; feature entitlement denied (`plan_inactive`).
- `is_complimentary = false`, `status = suspended` → `SUSPENDED` — an operational hold (e.g. a payment failure once RFC-005 exists, or an admin-initiated suspension); feature entitlement denied (`plan_suspended`).

**This governs feature-entitlement decisions only — it never gates RFC-003 tenancy access.** A Workspace with `status = inactive` remains fully reachable: its owner can still log in, view the overview, see its Businesses and members, exactly as RFC-003 §14.2 already forward-documents ("RFC-004's entitlement rule may disable paid execution while `is_active` stays `true` and ordinary account access continues"). RFC-004 introduces no new Workspace/Business visibility restriction of any kind — only feature-*execution* denial, evaluated exclusively through §14's algorithm at the specific point a feature-gated action is attempted.

**Relationship to `Subscription::status`:** none, by design (§6). RFC-004 defines its own `WorkspacePlanAssignmentStatus` enum rather than reusing `Subscription`'s `new`/`pending`/`active`/`ended`/`renew` set, because that set is shaped for the legacy per-user SMS billing lifecycle (a `pending` initial-payment state, a `renew` transition state) that does not map cleanly onto a Workspace's plan-entitlement lifecycle. No `trial`/`past_due` state exists in this repository's legacy Subscription model today (confirmed absent from its status constants, §5 finding 2) — so no such compatibility behavior needs to be documented; if RFC-005 later needs a trial or past-due concept, it is a new, explicitly designed addition to `WorkspacePlanAssignmentStatus`, not something inherited from legacy code today.

**Explicitly not implemented by RFC-004:** any Stripe webhook, usage ledger, wallet, auto-recharge, or Business-payer logic (§19). `workspace_plan_assignments.status` is written only by the admin-facing plan-assignment/complimentary-management actions this RFC defines (§22) — no automated billing-event handler exists yet to write it, and none is added here.

---

## 19. RFC-005 usage-authorization boundary

RFC-004 owns **structural** feature entitlement — the answer to "does this Workspace's plan, as modified by any admin override, structurally include this feature, and has this Business not had it disabled." RFC-005 will own **Business usage wallets, balances, payer selection, the usage ledger, reservation/release for uncertain-cost operations, auto-recharge, monthly usage caps, low-balance notifications, zero-balance usage suspension, usage webhooks/idempotency, Agency client rebilling, and any Stripe usage-billing change** — RFC-003 §26.2's deferral list, unchanged and un-narrowed by this RFC.

The seam is `UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature): UsageAuthorizationResult` (new, minimal interface only — `app/Library/Entitlement/Contracts/UsageAuthorizationGateway.php`), called as the final step of §14's algorithm. **RFC-004 ships exactly one implementation of this interface**: `NullUsageAuthorizationGateway`, which always returns `authorized: true` for every call, unconditionally. This is the "deterministic behavior rather than a broken dependency" the product requirement demands for non-metered features before RFC-005 exists — every feature is authorized today because no metering exists yet to deny anything, and the call shape RFC-005 will need is already exercised by every entitlement decision made from Milestone 1 onward, so RFC-005 can bind a real implementation later with zero change to `EntitlementManager` or any caller.

---

## 20. Manager/repository authority

Following RFC-003's Library/Manager + Repository convention exactly — no new generic service layer.

- **`App\Library\Entitlement\EntitlementManager`** (new) — the sole authority for: computing effective entitlement (§14), assigning/changing a Workspace's plan, granting/revoking complimentary status, creating/reverting Workspace overrides, creating/removing Business toggles, and `assertCanCreateAnotherBusiness()` (§17). Every mutating method re-checks authority/state even when the caller already checked, matching RFC-001/RFC-003's manager posture.
- **Repository contracts** (new, no `Interface` suffix, extending `BaseRepository`, bound in `AppServiceProvider::register()`'s existing `$bindings` array exactly like RFC-003's): `WorkspacePlanCatalogRepository`, `WorkspacePlanAssignmentRepository`, `WorkspacePlanTransitionRepository`, `WorkspaceEntitlementOverrideRepository`, `BusinessFeatureToggleRepository`. Each is a plain data-access contract — no business-rule/authority logic beyond the one same-Workspace-style validation `WorkspaceMembershipBusinessRepository` already models for its own cross-table check (§10.5/§10.6's `feature_key` validity check belongs to the repository layer; the *entitlement decision* belongs to `EntitlementManager`).
- **Feature-access decision:** `EntitlementManager::decide(Workspace $workspace, Business $business, PlatformFeature $feature, int $actorUserId): EntitlementDecision` — the one method every controller/job/other feature code must call. `EntitlementDecision` is a small immutable value object (`allowed: bool`, `reason: ?string`), not a bare boolean, so denial reasons are always available to the caller (§14).
- **Slot-capacity decision:** `EntitlementManager::assertCanCreateAnotherBusiness(Workspace $workspace): void` (§17) — throws rather than returning a boolean, matching RFC-003's `assertActorIsOwner()`-style "assert" methods that already throw typed exceptions on failure.

**No direct entitlement-table query is authorized anywhere outside `EntitlementManager` and its repositories** — every controller, job, or future feature-gated code path calls `EntitlementManager::decide()`, never `WorkspacePlanFeatureRepository` or the raw tables directly. This mirrors RFC-003's own "controllers never duplicate `WorkspaceManager` logic" discipline.

---

## 21. Events and audit

Following RFC-003 §19's exact convention (immutable events, IDs and scalar metadata only, dispatched after commit):

- `WorkspacePlanAssigned` — first-ever assignment for a Workspace.
- `WorkspacePlanChanged` — tier change on an existing assignment.
- `WorkspaceComplimentaryStatusChanged` — `is_complimentary` flipped either direction.
- `WorkspaceEntitlementOverrideChanged` — an override created, changed (allow↔deny), or reverted to inherit (deleted).
- `BusinessFeatureToggleChanged` — a Business toggle created or removed.

**Durable audit table** (`workspace_plan_transitions`, §10.4) is written for exactly `plan_assigned`, `plan_changed`, `complimentary_granted`, `complimentary_revoked` — the commercially significant subset, matching RFC-003's own restraint (`workspace_transitions` covers only ownership transfer and cross-Workspace reassignment out of fourteen total events). Override and toggle changes are event-only, not durably audited in a separate table — they are reversible, lower-stakes, and more frequent, the same reasoning RFC-003 applied to e.g. membership role/scope changes.

---

## 22. Admin/customer surfaces

Minimum surfaces this RFC's implementation milestones (§25) must eventually cover — full HTTP/UI design is deferred to those bounded contracts, not specified route-by-route here, matching how RFC-003 itself only specified milestone *scope* at the RFC stage and left exact routes to each slice's contract.

**Platform admin** (behind the existing `EnsureUserIsAdministrator` boundary, unmodified, plus new permission keys — §5 finding 5's collision-avoidance: use a distinct category, e.g. `'view workspace plans'`/`'manage workspace plans'` under a new `Workspace Plans` category, never reusing the legacy `'manage plans'`/`Plan` category):

- Inspect the plan catalog and plan-feature mapping (read; mutation is a narrower, separately-gated capability).
- Inspect and change a Workspace's plan assignment.
- Grant/revoke complimentary status, including for the platform owner's own Workspace — no special-cased mechanism: the same general complimentary-assignment action, applied to whichever Workspace the acting admin happens to own, is architecturally sufficient (there is nothing that distinguishes "the owner's Workspace" from any other Workspace in this schema).
- Create/revert Workspace entitlement overrides.
- View the "effective entitlement" explanation for a given (Workspace, Business, feature) — a direct surface over `EntitlementManager::decide()`'s `EntitlementDecision`, never a re-derivation.

**Workspace owner/admin** (customer-side, reusing RFC-003's existing owner/active-Admin authority check — never a new authorization algorithm):

- Inspect the Workspace's current plan tier and its feature list.
- Inspect Business-slot usage/capacity (e.g. "3 of 5 used").
- Inspect available features per Business.
- Enable/disable a Business-level feature toggle, only for a feature the Workspace is currently effectively entitled to (§16).

**Explicitly not permitted:** an ordinary Workspace member (any role) granting themselves or anyone else a plan entitlement, override, or complimentary status — those remain exclusively platform-admin actions, gated by the new admin permission keys layered on the unmodified `EnsureUserIsAdministrator` boundary.

---

## 23. Failure modes

- Unknown `feature_key` reaching any write path (catalog mapping, override, toggle) → rejected at the application layer before persistence, never silently accepted (§11).
- Attempting to create a Business toggle for a feature the Workspace is not currently entitled to → rejected; there is nothing to disable (§16).
- Attempting to set `is_complimentary = true` without a `reason` → validation failure (§15).
- A Workspace with no `workspace_plan_assignments` row reaching `EntitlementManager::decide()` (should be structurally impossible post-backfill, §16, but checked defensively like RFC-003 §14.1's "workspace is null" case) → treated as `INACTIVE`, denying every feature-gated action with `plan_inactive`, never silently treated as `active`/entitled-to-everything.
- Concurrent Business-creation racing the last slot → the second request fails closed with `BusinessSlotLimitExceededException` (§17), never a silent over-allocation.
- A dangling `workspace_plan_features`/`workspace_entitlement_overrides`/`business_feature_toggles` row referencing a deleted `PlatformFeature` case (a case removed in a future code change) → the entitlement check must treat any `feature_key` not currently in `PlatformFeature::cases()` as `platform_feature_unknown` (denied), even though a stored row exists — code-registry validity is re-checked at read time, not only at write time, so a future enum-case removal fails closed automatically rather than requiring a companion data migration to be safe.

---

## 24. Backward compatibility

- No RFC-001, RFC-002, or RFC-003 table, column, route, controller, or view is dropped, renamed, or altered in a breaking way.
- RFC-003's `WorkspaceManager::createBusinessInWorkspace()` gains exactly one additive call (§17) — its existing signature, transaction boundary, and every other behavior is unchanged.
- The legacy `plans`/`subscriptions` stack (§6) is untouched — every existing SMS-plan/subscription behavior continues exactly as today.
- Every existing Workspace gains a deterministic plan assignment via the Milestone-1 backfill (§16) — no existing Workspace, Business, or member loses any access as a direct result of this RFC shipping (billing-state denial only ever applies going forward from an explicit, later, admin-initiated status change — never as a side effect of the backfill itself, which always assigns `status = active` with `is_complimentary = true`, §16).
- `config/permissions.php` gains new keys under a new category, never colliding with or reusing the legacy `Plan` category's keys (§5 finding 5, §22).

---

## 25. Test strategy

Mirroring RFC-003's `tests/Unit/{Domain}` + `tests/Feature/{Domain}` pairing, under a new `Entitlement` domain directory (`tests/Unit/Entitlement`, `tests/Feature/Entitlement`) so RFC-004's suite is independently targetable exactly as RFC-003's regression commands targeted `tests/*/Workspace`.

Concrete groups (no fabricated counts — exact test methods are each bounded milestone's own responsibility to write and report, per RFC-003's established discipline):

- **Plan catalog invariants:** exactly three seeded tiers; unique `tier`; catalog price/currency changes never retroactively alter an existing assignment's already-decided entitlement.
- **Feature registry invariants:** every `PlatformFeature` case is a valid `feature_key` everywhere it's accepted; an unknown key is rejected at every write path (§23).
- **Plan-feature mappings:** the seeded matrix (§12.2) matches exactly; unique `(catalog_id, feature_key)`.
- **Effective entitlement precedence:** the full §14 algorithm, step by step — platform-support floor, override-replaces-mapping, toggle-narrows-only, billing-state gate, usage-authorization pass-through.
- **Workspace override allow/deny/inherit:** allow grants outside the base plan; deny overrides an included feature; deleting an override reverts to plan-mapping-derived state exactly.
- **Business toggle cannot escalate entitlement:** attempting to create a toggle for a not-entitled feature is rejected; a toggle never appears as a "grant."
- **Disabled platform feature wins:** removing a `PlatformFeature` case denies every stored row referencing it, even without a data migration (§23).
- **Inactive/suspended plan behavior:** both deny feature execution; neither denies RFC-003 tenancy access (§18).
- **Complimentary behavior:** complimentary bypasses billing-state denial regardless of `status`'s other value except `suspended`; normal entitlement/slot rules still apply.
- **Core/Growth/Agency feature matrix:** the exact §12.2 table, both directions (included features pass, excluded features deny with `not_entitled_by_plan`).
- **Core/Growth 3 included slots:** the 1st–3rd Business creation succeeds with no additional check surfaced.
- **Core/Growth slot 4 and 5 eligibility:** succeed, remaining within `business_slot_max`.
- **Core/Growth 6th Business denied:** `BusinessSlotLimitExceededException`, no Business row created.
- **Agency unlimited Business slots:** creation never denied by slot count regardless of existing count.
- **Concurrent final-slot creation safety:** two simultaneous creation attempts for the last available slot — exactly one succeeds, the other fails closed, no over-allocation (mirroring RFC-003's `WorkspaceBackfillV1ConcurrencyTest`/`WorkspaceManagerConcurrencyTest` pattern).
- **Workspace/Business authorization remains independent:** a fully-entitled-but-unauthorized user is still denied by RFC-003 §14.1 before entitlement is even reached; an authorized-but-unentitled user is denied by §14 despite passing RFC-003's own check.
- **Cross-Workspace isolation:** an entitlement/override/toggle for one Workspace never leaks into another Workspace's decision.
- **Platform-admin boundary:** the new admin surfaces (§22) require both `access backend` (existing blanket gate) and the new permission keys independently — mirroring `AdminWorkspaceControllerTest`'s exact non-admin-forged-permission defense-in-depth test.
- **Legacy Plan/Subscription compatibility:** the legacy suite (wherever it currently lives) is unaffected by this RFC's migrations/models — a direct regression check that no legacy Plan/Subscription test starts failing.
- **RFC-001 regression:** `tests/Unit/Business tests/Feature/Business` unaffected.
- **RFC-002 regression, where affected:** `tests/Unit/Opportunity tests/Feature/Opportunity` — specifically Prospect Outreach gating (§13.1), everything else unaffected.
- **RFC-003 regression:** `tests/Unit/Workspace tests/Feature/Workspace` unaffected, including the one additive `createBusinessInWorkspace()` call site (§17) not breaking any existing Business-creation test.

---

## 26. Acceptance criteria

- All five new tables (§10) exist with exactly the specified columns, constraints, and `restrictOnDelete()` foreign keys; no native DB `ENUM` column exists anywhere in this RFC's schema.
- `workspace_plan_catalog` is seeded with exactly `core`/`growth`/`agency`, matching §12.1's slot policy and §12.2's feature matrix exactly.
- Every pre-existing RFC-003 Workspace has exactly one `workspace_plan_assignments` row after the Milestone-1 backfill, with `status = active` and `is_complimentary = true` (§16), and zero Workspaces are left unassigned.
- `EntitlementManager::decide()` implements §14's algorithm exactly, including denial-reason-key stability.
- `WorkspaceManager::createBusinessInWorkspace()`'s slot-capacity call (§17) is concurrency-safe under a forced-race test, with no over-allocation possible.
- No RFC-001/RFC-002/RFC-003 test regresses; the legacy Plan/Subscription suite is unaffected.
- No direct entitlement-table query exists outside `EntitlementManager` and its repositories (§20) — verified by code search, not merely believed.
- `config/permissions.php`'s new keys use a distinct category from the legacy `Plan` category (§5 finding 5).
- No `Agency` model or `businesses.agency_id` column exists anywhere (§3, RFC-003 §4/§27 precedent preserved).

---

## 27. Implementation milestone plan

Following RFC-003's exact governance discipline: **RFC design PR → bounded implementation milestones, each its own contract → PR → human merge, no automatic next-milestone start → final conformance/deployment/tag milestone.** No target-marker PR, no inert implementation PR, no separate authorization PR at any step — the simplified workflow RFC-003 Milestone 5/6 already established and proved out.

**M1 — Domain/schema/catalog/feature-registry/Workspace-plan foundation.** All five tables (§10), all new enums (`WorkspacePlanTier`, `WorkspacePlanAssignmentStatus`, `WorkspaceEntitlementOverrideState`, `WorkspacePlanTransitionType`, `PlatformFeature`), all new repository contracts + Eloquent implementations (§20), the plan-catalog seed (§12.2), and the deterministic backfill/migration assigning every existing Workspace a complimentary Core assignment (§16). No `EntitlementManager`, no HTTP surface, no slot enforcement yet — pure data-layer foundation, exactly mirroring RFC-003 M1A's own scope discipline (schema + models + repositories, no manager/HTTP).

**M2 — Entitlement engine, Workspace overrides, Business toggles, slot-capacity enforcement.** `EntitlementManager` (§20) implementing §14's full algorithm, the `NullUsageAuthorizationGateway` (§19), Workspace override create/revert, Business toggle create/remove, and the one additive `createBusinessInWorkspace()` call (§17) with its concurrency test. All events (§21) and the `workspace_plan_transitions` audit writes. No HTTP surface yet — mirrors RFC-003 Milestone 2's "full manager, no HTTP" scope.

**M3 — Admin/customer surfaces + capability integration/gating + focused regression.** The minimum surfaces in §22, new permission keys, and — only where a real existing capability needs gating (Prospect Outreach per §13.1; a white-label capability *only if* the repository is found, at M3 implementation time, to already contain a bounded existing capability that merely needs gating, per §3/§13.2's explicit constraint) — wiring `EntitlementManager::decide()` into that existing code path. No new feature module is built in M3 merely because its entitlement key exists (§3, §12.4).

**M4 — Full conformance, deployment guide, complete regression, annotated tag.** Mirrors RFC-003 Milestone 6 exactly: an evidence-based conformance matrix against this RFC's own acceptance criteria (§26), a deployment guide grounded in the actual M1–M3 implementation, the full regression gate, and the `rfc-004-plans-and-business-feature-entitlements` annotated tag — created only after the same post-merge exact-tag-candidate regression gate RFC-003 M6 used, with explicit human authorization, never automatically.

This is four milestones, not the six-small-governance-milestone pattern the task explicitly warns against — each one bounded, each one independently stoppable and separately contracted, matching RFC-003's own "each milestone stops and reports independently" discipline (RFC-003 §23's closing line). If repository evidence discovered during M1's own implementation reveals a safer split (e.g. the backfill warranting its own sub-stop the way RFC-003 split M1A/M1B), that discovery is reported at that milestone's own contract/closure, not decided speculatively here.

---

## 28. Release and tag gate

Tagging follows the exact posture RFC-001/RFC-002/RFC-003 already established: an annotated tag is created only at the end of RFC-004's final milestone (M4), after full regression, never at the end of M1–M3. No tag is created, applied, or proposed as part of drafting or revising this RFC document — drafting/revising is documentation only and gates nothing by itself, exactly matching RFC-003 §25's own language.

Proposed exact tag: `rfc-004-plans-and-business-feature-entitlements`, annotated (not lightweight), verified against the `origin` remote exactly as RFC-003 M6's contract required (§9 of that contract) — including re-confirming, at that future time, whichever tag-verification convention is then current, since `docs/automation/RFC-003-M6-CONTRACT.md` itself already found one historical inconsistency (`rfc-001-business-core` local-only vs. `rfc-002-opportunity-engine` pushed) worth checking against `origin` explicitly rather than assumed.

No tag is created now. No tag is created during M1–M3. No tag is created during M4's own implementation PR — only after M4's documentation PR merges, a post-merge exact-tag-candidate regression passes, and a human explicitly authorizes it.

---

## 29. Explicit RFC-005 deferrals

Deferred to RFC-005 — Business Usage Billing and Wallets (unchanged from RFC-003 §26.2, restated here as this RFC's own boundary, §19):

- Business usage wallets, balances, and the append-only usage ledger.
- Business payment methods and Workspace payer fallback; selected/default payer policy by tier.
- Reservation/release for uncertain-cost operations.
- Auto-recharge (threshold/amount, monthly caps, ability to disable).
- Low-balance notifications and zero-balance usage suspension.
- Usage webhooks and idempotency.
- Agency client rebilling.
- Any Stripe integration change, including usage-billing-specific webhooks.

RFC-004's `UsageAuthorizationGateway` seam (§19) exists precisely so none of the above requires reshaping anything this RFC ships — RFC-005 binds a real implementation later; `EntitlementManager` and every caller of `decide()` are unaffected.

Also explicitly out of scope for RFC-004 itself (§3, restated): a Prospect Outreach Engine redesign; a full white-label implementation beyond gating an existing bounded capability, if one is found to already exist at M3; implementation of any SEO/Ads/AI module that does not yet exist merely because its entitlement key is defined; unrelated CRM or messaging changes; any RFC-003 Workspace/Business tenancy redesign.

---

## 30. Open questions

None left genuinely undecidable from product requirements and repository evidence. Every architectural choice this RFC needed to make — legacy compatibility (§6), feature-registry architecture (§11), tier-identity-vs-catalog-data split (§12.1), override-may-grant-outside-plan (§15), business-toggle-can-only-narrow (§16), billing-state-vs-tenancy-access boundary (§18), the RFC-005 seam shape (§19) — was resolved with an explicit recommendation and justification above, per this RFC's own quality requirement not to leave major architectural choices as "TBD."

One item is deliberately left to the *implementation* milestone that will actually resolve it, not because it is undecidable in principle but because deciding it now would be speculative ahead of that milestone's own repository inspection: **whether an existing bounded white-label capability actually exists in this repository today**, for §13.2/§27 M3's "only gate what already exists" constraint. This is not an open RFC-level design question — the design rule (gate, don't build) is fully decided — it is a fact to be verified by M3's own contract at the time M3 is actually drafted, exactly as RFC-003 repeatedly deferred file-existence verification to each slice's own contract-drafting inspection rather than guessing at RFC-drafting time.
