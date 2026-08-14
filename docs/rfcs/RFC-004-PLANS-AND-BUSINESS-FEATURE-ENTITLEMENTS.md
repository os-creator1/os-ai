# RFC-004 — Plans and Business Feature Entitlements

**Status:** DRAFT — DESIGN ONLY — IMPLEMENTATION NOT AUTHORIZED
**Version:** 1.3
**Priority:** P1
**Target framework:** Laravel 12 / PHP 8.2
**Architecture constraint:** Extend the existing Ultimate SMS controller → repository → library/manager → model structure. Do not introduce a new generic service-layer convention.
**Depends on:** RFC-001 Business Core, RFC-002 Opportunity Engine, RFC-003 Workspace and Business Account Core (tagged `rfc-003-workspace-and-business-account-core`)
**Enables:** RFC-005 Business Usage Billing and Wallets

Merging the future RFC-004 design PR does **not** automatically authorize implementation. After this RFC is accepted, Milestone 1 requires its own separate, human-reviewed, bounded implementation contract — the same discipline RFC-003 followed for every one of its milestones.

**v1.1 revision note:** that revision corrected fourteen design defects found in v1.0 review: a missing sixth table (the durable audit table was undercounted); a conflation of "feature key is known" with "feature is implemented" that would have let a plan mapping or admin override make an unbuilt module executable; a slot model that let every Core/Growth Workspace silently reach 5 Businesses with no allocation step; a catalog schema that forced inventing unfrozen prices; a self-contradictory complimentary/status precedence; an uncounted `WorkspacePlanFeatureRepository`; under-scoped durable audit coverage for high-value admin overrides and slot allocation; and a backfill section that was referenced repeatedly but never actually written.

**v1.2 revision note:** this revision corrects eight further defects found in v1.1 review: `workspace_plan_assignments.status` changes were not durably audited despite directly controlling feature execution and Business creation (added `plan_status_changed`, now **nine** transition types); Workspace tier changes left `additional_business_slots` behavior for Core↔Growth / →Agency / Agency→Core/Growth entirely undefined; the pricing-nullability invariant did not yet close the additional-slot-increase and catalog-price-mutation cases it needed to; complimentary Workspaces' additional-slot billing treatment was implicit rather than explicitly stated; the grandfathered-over-capacity recovery wording incorrectly implied Business *deactivation* could lower slot consumption, directly contradicting this RFC's own "`COUNT` of every Business row regardless of status" slot-count definition; and `ProspectOutreach`'s `PlatformFeatureRegistry` availability was asserted as confirmed `Available` on RFC-002's implementation without this RFC's own inspection ever having performed a direct file-level check for it.

**v1.3 revision note:** this revision corrects one genuine repository-vs-RFC contradiction found during Milestone 2's own mandatory pre-implementation audit (required by this RFC's and RFC-003's shared discipline of verifying repository reality before locking an implementation contract) — not a discretionary redesign. v1.2 (§17, §24) stated that `WorkspaceManager::createBusinessInWorkspace()` is "the sole Business-creation orchestration entry point" and therefore the only site needing a slot-capacity assertion. Direct inspection of the actual M1-era repository found that statement false: `BusinessManager::applyIdentity()`'s legacy onboarding path calls `WorkspaceManager::resolveLegacyOnboardingWorkspace()` and then `BusinessRepository::createForCustomerInWorkspace()` directly, bypassing `createBusinessInWorkspace()` entirely; `WorkspaceManager::createWorkspace()` accepts an optional `WorkspaceFirstBusinessInput` and can itself insert a Business; and `WorkspaceManager::reassignBusiness()` increases the destination Workspace's Business-row count on a real cross-Workspace move. §17 and §24 are corrected to state the general invariant this RFC actually requires — every Business-count-increasing operation, not only `createBusinessInWorkspace()`, must evaluate capacity while holding the destination Workspace's row lock and before its count-increasing write — and to enumerate every current such operation by name. §17 additionally locks a narrowly-scoped, evidence-based compatibility rule for the legacy onboarding path (never previously slot-gated) and for a brand-new Workspace the legacy resolver may itself provision (which, post-M1-backfill, has no plan assignment and would otherwise be denied immediately by M2's fail-closed algorithm). Direct inspection also found `WorkspaceController::store()` is the only production caller of `createWorkspace()`, and it never supplies `WorkspaceFirstBusinessInput` — no production caller supplies one — so §17/§24 also retire that optional parameter as dead capability rather than leaving it as an uncovered capacity-enforcement gap; Workspace creation becomes tenancy-only going forward, exactly as it is already used in production.

**The major architecture is unchanged and remains valid**: universal Workspace tenancy, a distinct RFC-004 domain fully separate from legacy SMS Plan/Subscription, Core/Growth/Agency tiers, six authoritative tables, the feature-identity-vs-availability split, Workspace-level plan assignment, feature entitlements, Workspace overrides, Business feature toggles, the allocation-gated Business-slot model, the RFC-005 usage/billing boundary, and four implementation milestones.

---

## 1. Purpose

RFC-003 gave every customer a Workspace — a universal tenancy container that can hold one Business or many, with owner/member roles and per-membership Business-access scoping. It deliberately implemented no commercial packaging: every Workspace today has unlimited Business slots and unlimited feature access, because RFC-003 §4 explicitly deferred "plans, Workspace plan assignment, or Business slot limits" and "per-Business feature toggles or a platform feature registry" to this RFC.

RFC-004 answers one question: **what commercial tier is a Workspace on, what does that tier structurally entitle it to, and how does the platform decide — cheaply, deterministically, and auditably — whether a given feature may execute for a given Business right now?** It introduces the Core/Growth/Agency plan tiers, a platform feature registry that distinguishes a known feature key from an actually-implemented one, Workspace-level plan assignment and admin overrides, Business-level feature toggles, and Business-slot capacity enforcement with an explicit paid-allocation step for slots 4 and 5.

RFC-004 does **not** implement billing execution, usage metering, wallets, or Stripe integration — those are RFC-005's exclusive scope, deliberately walled off here (§19).

---

## 2. Goals

- Formalize Core/Growth/Agency as data-driven commercial tiers assigned to a Workspace, never a separate tenancy model.
- Introduce one authoritative platform feature registry with stable, code-defined machine keys, and a separate, also code-backed, implementation-availability distinction so a planned-but-unbuilt feature can never become executable through a plan mapping or an admin override.
- Define a deterministic, precedence-ordered effective-access-decision algorithm: platform support → implementation availability → Workspace assignment resolved → Workspace plan entitlement/override → Business feature toggle → billing/operational state → (RFC-005) usage authorization.
- Enforce Business/location slot capacity (3 included, an explicit paid allocation step for 4 and 5, 6+ requires Agency) at the same transactional boundary RFC-003 already uses for Business creation, safely under concurrency, with the allocation quantity itself an authoritative, auditable, admin-controlled mutation, and with deterministic, same-transaction slot normalization whenever the tier itself changes.
- Give the platform admin an explicit, durably audited complimentary-assignment mechanism, including for the platform owner's own Workspace, and an equally durably audited operational status-change mechanism.
- Preserve RFC-003's authorization boundary exactly: plan entitlement is an additional gate layered on top of Workspace/Business authorization, never a replacement for it, and never a way to bypass it.
- Leave a clean, minimal, already-satisfiable seam for RFC-005 so usage-authorization can be added later without reshaping anything RFC-004 ships.
- Never let a backfilled or newly-gated capability silently remove access an existing Workspace already legitimately had.
- Never assert a capability is implemented in this repository without direct repository evidence.

---

## 3. Non-goals

RFC-004 does not implement: Business usage wallets, ledgers, payers, or auto-recharge (RFC-005, §19); Stripe or any payment-gateway integration change or actual payment collection for additional Business slots (§13, §17); a redesign of the legacy `plans`/`subscriptions` SMS-credit system (§6); an `Agency` model or `businesses.agency_id` (explicitly forbidden, matching RFC-003 §4/§27); a redesign of the Prospect Outreach Engine's safety rules, product intent preserved conceptually without asserting an unverified implementation as repository fact (§5 finding 9, §26); a full white-label implementation beyond gating an existing bounded capability if one exists (§26, §29 M3); implementation of SEO/Ads/AI/Calendar/Forms/Website-generation modules that do not yet exist in code, beyond defining their stable entitlement keys and marking them not-yet-available (§11, §12.4); any change to RFC-003's Workspace/Business tenancy, ownership, membership, or `business_access_scope` model (§9's isolation invariant); any change to RFC-001/RFC-002 behavior beyond the one additive Business-creation call site named in §17; a Business-deletion mechanism of any kind — RFC-003 provides none, and RFC-004 does not invent one (§25.4).

---

## 4. Dependency on RFC-001, RFC-002, RFC-003

- **RFC-001** established `Business` (`businesses.customer_id`) as the aggregate root with locations/services. RFC-004 gates *feature access on* a Business; it never changes what a Business *is*.
- **RFC-002** built the Opportunity Engine on top of a single Business. Prospect Outreach (§26) is, by product intent, an Opportunity-Engine-adjacent capability that RFC-004 only gates if and when it is confirmed to exist (§5 finding 9); RFC-004 does not touch `Opportunity`/`OpportunityRun` code.
- **RFC-003** established the Workspace as the universal tenancy container, `WorkspaceManager` as the sole domain authority for Workspace/Business/membership mutation, and the §14.1 `userCanAccessBusiness()` authorization algorithm. RFC-004 is layered *on top of* that algorithm, never inside or in place of it (§9, §14). RFC-003 is complete and tagged `rfc-003-workspace-and-business-account-core` — this RFC treats that tag's tree as its starting point and does not reopen any RFC-003 milestone.

---

## 5. Current repository findings

Findings from direct inspection before drafting this RFC (do not assume; every claim below was verified against the actual files):

1. **The `plans`/`subscriptions` stack is the original (2018-era) Ultimate SMS reseller SMS-credit billing system, not a Workspace SaaS tier system.** `database/migrations/2018_12_01_193935_create_plan_table.php` is among the very first migrations in this codebase, predating RFC-001 by years. `plans.user_id` is nullable with `onDelete('cascade')` (a plan may belong to a specific reseller sub-account or be global); `plans.options` is a JSON text blob (`Plan::defaultOptions()`) whose keys are entirely SMS-domain: `sms_max`, `whatsapp_max`, `sending_limit`, `sending_quota`, `sender_id_verification`, `create_sub_account`, `api_access`. `PlanRepository`'s methods (`updateSpeedLimits()`, `updatePricing()`, `updateSenderID()`, `updateCreditPrice()`, coverage-country batch operations) are all SMS-sending-plan-shaped.
2. **`Subscription` belongs to `user_id`, not `workspace_id` or `business_id`.** `database/migrations/2019_03_09_065029_create_subscriptions_table.php`: `user_id` (not nullable), `plan_id`, `payment_method_id`, `status` enum (`new`/`pending`/`active`/`ended`/`renew`), `options` (JSON, SMS credit-warning/notification settings). `Subscription implements HasQuotaInterface` and `use HasQuota` — it is wired directly into the SMS sending-rate/credit-quota system (`App\Library\RateTracker`), a completely different concern from Workspace commercial packaging.
3. **Every foreign key in the legacy Plan/Subscription schema uses `onDelete('cascade')`**, the opposite of RFC-003's `restrictOnDelete()` posture for tenancy-critical data. Reusing these tables for Workspace plan assignment would inherit a deletion policy this RFC's commercial/audit requirements cannot accept.
4. **`SubscriptionLog`, `SubscriptionTransaction`, `CustomerBasedPricingPlan`, `PlanSendingCreditPrice`** are all further SMS-billing machinery: country/sending-server-based per-credit pricing, payment transaction logs — none of it maps to "Workspace commercial tier" or "Business feature entitlement" by any reasonable extension.
5. **RFC-003's Workspace domain is exactly as its own closure documents describe** (cross-checked against `RFC-003-M4-CLOSURE.md`, `RFC-003-M5-CLOSURE.md`, `RFC-003-M6-CONFORMANCE.md`, all already verified in this repository): `WorkspaceManager` (`app/Library/Workspace/WorkspaceManager.php`, ~1,590 lines) is the sole authority for Workspace/membership/Business mutation and the `userCanAccessBusiness()` algorithm; `WorkspaceRepository`/`WorkspaceMembershipRepository`/`WorkspaceMembershipBusinessRepository`/`WorkspaceTransitionRepository` are plain data-access contracts with no `Interface` suffix, bound in `AppServiceProvider::register()`'s `$bindings` array (`WorkspaceRepository::class => EloquentWorkspaceRepository::class`, confirmed at line 148); `Workspace`/`WorkspaceMembership`/`WorkspaceMembershipBusiness` models exist with the relationships RFC-003 §11 specifies; `config/permissions.php` already carries `'view workspace'` (Milestone 5) alongside the legacy `'manage plans'`/`'create plans'`/`'edit plans'`/`'delete plans'` keys (category `Plan`) — a naming collision RFC-004 must avoid by choosing a distinct permission category (§20/§22).
6. **`payment_methods`** (`database/migrations/2018_07_27_112022_create_payment_methods_table.php`) exists and is referenced by `subscriptions.payment_method_id`. RFC-004 does not use it directly — payment execution is RFC-005's boundary (§18/§19) — but its existence confirms a payment-method concept already exists in this codebase for RFC-005 to eventually build on, and RFC-004's plan-assignment `status` enum is deliberately shaped to be compatible with it later without RFC-004 needing to reference it now.
7. **RFC-001/RFC-002/RFC-003 precedent is consistent and directly reusable**: repository-contract naming with no `Interface` suffix; `App\Library\{Domain}\{Domain}Manager` for orchestration; `App\Enums\{Domain}\*` for string-backed PHP enums (no native DB `ENUM` columns); durable `*_transitions` audit tables reserved for the highest-stakes mutations only (RFC-002's `opportunity_transitions`, RFC-003's `workspace_transitions`); `tests/Unit/{Domain}` + `tests/Feature/{Domain}` test-directory pairing.
8. **Existing customer-facing modules confirmed present in `routes/customer.php`** (inspected earlier in this same session's RFC-003 work): a Contact module, an Automations module, and a ChatBox/conversations module, among others, all already implemented and routed. This directly grounds §11's implementation-availability distinction — some `PlatformFeature` keys correspond to capabilities that already exist today, not only to planned ones.
9. **This RFC's own inspection did not include a direct file-level search confirming an executable Prospect Outreach implementation** — added in this revision, correcting an unverified claim in v1.1. §11/§26 therefore treat `ProspectOutreach`'s `PlatformFeatureRegistry` availability as unverified — provisionally `Planned`, not `Available` — pending Milestone 1's own direct repository check, rather than assumed from RFC-002's general Opportunity Engine scope or from the product-requirements framing that originated this RFC's Agency-tier packaging decision (§12.2). Finding 8 above remains valid evidence for `Crm`/`Conversations`/`Automations` specifically; it was never evidence for `ProspectOutreach`, and v1.1 should not have treated it as such.

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
| Plan tier | One of `Core`, `Growth`, `Agency` — a fixed, code-defined *identity only* (`WorkspacePlanTier` enum). All structural/commercial data for a tier (slot counts, pricing, allocation ratio) lives in `workspace_plan_catalog` (§10.1/§12.1), never duplicated in code. |
| Plan catalog | The `workspace_plan_catalog` table — one row per tier, holding pricing/display/slot-policy data. Data/catalog, not hard-coded, and the sole authority for slot policy (§12.1). |
| Platform feature | A stable, code-defined machine key (`PlatformFeature` enum) identifying one gateable capability, independent of whether it is entitled to anyone yet, and independent of whether it is actually implemented yet (§11). |
| Feature availability | Whether a known `PlatformFeature` key corresponds to an actually-implemented, executable capability today (`PlatformFeatureRegistry`, §11) — distinct from, and checked before, plan/override entitlement. Never inferred from product intent or an external/future migration plan (§5 finding 9). |
| Plan-feature mapping | `workspace_plan_features` — which platform features a given plan-catalog row includes. |
| Workspace plan assignment | `workspace_plan_assignments` — the one active plan tier a Workspace currently has, plus its operational status, complimentary flag, and additional Business-slot allocation. |
| Workspace entitlement override | `workspace_entitlement_overrides` — an explicit, audited, per-feature admin exception (`allow` or `deny`) layered on top of the Workspace's plan-derived entitlement. Can never override feature *unavailability* (§11, §14). |
| Business feature toggle | `business_feature_toggles` — an explicit, per-Business, per-feature *disable* narrowing what the Workspace would otherwise entitle that Business to. Never a grant beyond Workspace entitlement. |
| Effective entitlement | The final allow/deny decision for (Workspace, Business, feature), computed by walking the precedence chain in §14. |
| Complimentary assignment | A plan assignment explicitly marked as not requiring a recurring charge, orthogonal to operational `status` (§18) — normal entitlements and normal slot rules still apply unless separately overridden/allocated; waives the charge for already-allocated additional Business slots too, never the allocation limits themselves (§13). |
| Business slot | One unit of Business-creation capacity under a Workspace's assigned tier. `included` slots are free; `additional` slots (Core/Growth only) are an explicit, priced, admin-allocated grant (§13). |
| Entitlement transition | A durably audited row in `workspace_entitlement_transitions` (§10.4, §21) for a commercially significant mutation. |

---

## 8. Authoritative invariants

- Every Workspace has at most one `workspace_plan_assignments` row at a time (unique `workspace_id`). A Workspace with no row is treated as unassigned and denies every feature-gated decision with `workspace_plan_unassigned` (§14) — see §25 for the deterministic backfill guarantee that this state does not persist for any pre-existing Workspace.
- `workspace_plan_catalog` price/currency/ratio changes never retroactively change what an *already-created* Business's slot cost was — RFC-004 does not implement billing execution (§19); this invariant matters only for RFC-005's future consumption of this data.
- A known `PlatformFeature` key is not, by itself, proof the feature may execute. Implementation availability (`PlatformFeatureRegistry`, §11) is checked before any plan mapping or override is even consulted, is based strictly on direct repository evidence found at implementation time (never on product intent alone, §5 finding 9), and no plan mapping or Workspace override can ever make an unavailable feature executable (§14).
- A Business feature toggle can only ever narrow access (`disabled`), never widen it beyond the Workspace's effective entitlement (§9, §14, §16).
- Disabling a feature at the platform-availability, plan-mapping, or Workspace-override level always overrides any Business-level enabled state — there is no path for a Business to retain access to a feature the Workspace has lost.
- A Workspace's effective additional Business-slot capacity is `min(business_slot_included + additional_business_slots, business_slot_max)` for Core/Growth, and unconditionally unlimited for Agency (`additional_business_slots` must remain `0` and is never consulted for Agency) — §13. A tier change deterministically normalizes `additional_business_slots` in the same locked transaction as the tier change itself (§17).
- Every commercially significant entitlement mutation (plan assignment, plan change, plan status change, complimentary status change, additional-slot allocation change, Workspace override create/change/revert) is both event-dispatched and durably audited in `workspace_entitlement_transitions` (§21) — **status changes included, added in this revision (§18).**
- Business-slot capacity enforcement never trusts a UI-only check; it is enforced inside the same locked transaction RFC-003's `WorkspaceManager::createBusinessInWorkspace()` already uses (§17).
- A non-complimentary plan assignment must never be created or changed to reference a catalog row with a null `price` or `currency_id` (§10.1, §12.5); the identical rule applies to **increasing** `additional_business_slots` for a non-complimentary assignment, which additionally requires `additional_business_slot_price_ratio` to be defined — decreasing/revoking slots is never blocked by pricing state (§12.5, §13, §17). `price` and `currency_id` are always both-null or both-populated, never one without the other, and neither may be cleared on a catalog row still referenced by a non-complimentary assignment (§12.5).
- A complimentary assignment waives the recurring charge for its plan **and** for any already-allocated additional Business slots, but never waives the slot allocation limits, the effective-capacity rule, an explicit `inactive`/`suspended` status, or any future RFC-005 metered cost (§13, §18).
- Business-slot consumption is `COUNT` of every Business row belonging to a Workspace, regardless of that Business's own `status` — deactivating a Business never reduces slot consumption; only an authorized reassignment to a different Workspace (RFC-003 §16.2) can, since RFC-003 provides no Business-deletion mechanism and RFC-004 invents none (§25.4).
- Nothing in this RFC's schema forecloses RFC-005: `workspace_plan_assignments.status` is a plan/operational lifecycle flag only, never a usage-wallet balance or ledger.

---

## 9. RFC-003 isolation remains authoritative

No entitlement feature introduced by this RFC may weaken: Workspace isolation, Business isolation, owner/member authorization, `all`/`selected` Business-access scopes, or the independent platform-admin boundary (`EnsureUserIsAdministrator`, unmodified). **Plan entitlement is an additional gate, evaluated only after RFC-003's own authorization has already granted access** — never a substitute for it and never evaluated first. A user who is fully entitled to a feature by plan but not authorized for the Workspace or Business under RFC-003 §14.1 still cannot access it; conversely, a user authorized under §14.1 but not entitled by plan is blocked by this RFC's gate. The two systems are evaluated independently and both must pass (§14's algorithm assumes RFC-003 authorization has already succeeded before it is even invoked — it is not part of the entitlement decision chain itself, it is a precondition for calling it at all).

---

## 10. Proposed data model

**Six** authoritative RFC-004 tables. All use `id`, `created_at`, `updated_at` (except the audit table, §10.4, which follows `workspace_transitions`' `created_at`-only, immutable-row convention). No database-native `ENUM` columns — string columns cast to string-backed PHP enums under `App\Enums\Entitlement`, matching RFC-003 §9's AD-004 convention.

### 10.1 `workspace_plan_catalog`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `tier` | varchar(20) | no | — | `WorkspacePlanTier`: `core`\|`growth`\|`agency`. Unique. |
| `display_name` | varchar(255) | no | — | Admin/customer-facing name |
| `price` | decimal(16,2), **nullable** | yes | `null` | Data, not hard-coded (§12). **Nullable** — see §8/§12.5: a tier may be seeded with a defined structural shape before its commercial price is frozen. Always both-null or both-populated with `currency_id` (§12.5) |
| `currency_id` | bigint unsigned FK, **nullable** | yes | `null` | References existing `currencies.id`. Nullable in lockstep with `price` — never one without the other (§12.5) |
| `billing_cycle` | varchar(20) | no | `monthly` | `monthly`\|`yearly` — deliberately narrower than legacy `Plan`'s billing-cycle set; RFC-005 may extend if a real product need arises |
| `business_slot_included` | unsigned tinyint | no | — | `3` for Core/Growth/Agency (documentation value for Agency only — see `unlimited_business_slots`) |
| `business_slot_max` | unsigned tinyint, nullable | yes | `null` | `5` for Core/Growth; `null` for Agency (see `unlimited_business_slots`, which is authoritative — `business_slot_max` is never consulted when this is `true`) |
| `unlimited_business_slots` | boolean | no | `false` | `true` only for `agency`. Authoritative for slot enforcement (§13) — every other slot column on this row is ignored when this is `true` |
| `additional_business_slot_price_ratio` | decimal(6,4), nullable | yes | `null` | `0.5000` for Core/Growth (§13); `null` for Agency, which has no additional-slot concept. Commercial catalog **data**, so RFC-005 never has to hard-code `0.5`. Must be non-null whenever a paid additional-slot increase is possible for that tier (§12.5) |
| `is_active` | boolean | no | `true` | An inactive catalog row cannot receive new assignments, but existing assignments referencing it are unaffected (matches RFC-003's `is_active`-is-tenancy-not-deletion posture) |
| timestamps | — | no | — | |

Indexes: unique `tier`, index `is_active`.
Foreign key: `currency_id` → `currencies.id`, `restrictOnDelete()` (only enforced when non-null).

Seeded once, at Milestone 1, with exactly three rows (`core`, `growth`, `agency`) — see §25. **This table, not `WorkspacePlanTier`, is the sole authority for slot/ratio policy** (§12.1) — manager logic reads these columns; it never hard-codes `3`, `5`, or `0.5000` anywhere beyond this one seed. `price`/`currency_id` may never be cleared while a non-complimentary assignment references this row (§12.5).

### 10.2 `workspace_plan_features`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_plan_catalog_id` | bigint unsigned FK | no | — | Which tier this mapping belongs to |
| `feature_key` | varchar(64) | no | — | Validated against `PlatformFeature` at the application layer before insert — no plain FK can express "must be a valid enum case" |
| timestamps | — | no | — | |

Indexes/constraints: unique `(workspace_plan_catalog_id, feature_key)`.
Foreign key: `workspace_plan_catalog_id` → `workspace_plan_catalog.id`, `restrictOnDelete()`.

Existence of a row = that tier includes that feature *structurally* — this is a packaging fact only, independent of whether the feature is actually implemented (§11/§14 check availability separately, before this mapping is even consulted). No boolean column — an absent row is the only "not included" representation.

Access authority: `WorkspacePlanFeatureRepository` (§20).

### 10.3 `workspace_plan_assignments`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_id` | bigint unsigned FK | no | — | The Workspace this assignment belongs to. Unique — one assignment per Workspace, matching §8 |
| `workspace_plan_catalog_id` | bigint unsigned FK | no | — | The current tier |
| `status` | varchar(20) | no | — | `WorkspacePlanAssignmentStatus`: `active`\|`inactive`\|`suspended`. No default — every assignment-creation path must supply it explicitly, matching RFC-003 §9.2's `business_access_scope` no-default precedent. May only be changed via `EntitlementManager::changePlanStatus()`, requiring actor + reason and durably audited (§18, §21) |
| `is_complimentary` | boolean | no | `false` | Orthogonal to `status` (§18) — a complimentary assignment can still be `active` or `suspended`. Waives recurring charges for the plan and for already-allocated additional slots; never waives allocation limits or `status` itself (§13) |
| `complimentary_reason` | text, nullable | yes | `null` | Required at the application layer whenever `is_complimentary` is set `true` (§15) |
| `complimentary_granted_by_user_id` | bigint unsigned, nullable, no FK | yes | `null` | Plain scalar, not a FK — matches RFC-003 `workspace_transitions.actor_user_id`'s "must never block a legitimate user-deletion feature" rationale. `null` only for: (1) the one-time Milestone-1 backfill (§25); and (2) the narrowly-scoped §17.4 legacy auto-provisioned-Workspace compatibility assignment — never for an ordinary/admin-initiated complimentary grant, which must always record the acting user |
| `complimentary_granted_at` | timestamp, nullable | yes | `null` | |
| `additional_business_slots` | unsigned tinyint | no | `0` | Core/Growth: `0`, `1`, or `2` — the number of explicitly allocated paid slots beyond the tier's included 3 (§13). Agency: must remain `0`; ignored by slot logic regardless (§8). Normalized deterministically on every tier change (§17) |
| timestamps | — | no | — | |

Indexes: unique `workspace_id`, index `workspace_plan_catalog_id`, index `status`.
Foreign keys: `workspace_id` → `workspaces.id`, `restrictOnDelete()`; `workspace_plan_catalog_id` → `workspace_plan_catalog.id`, `restrictOnDelete()`.

### 10.4 `workspace_entitlement_transitions` (durable audit)

Named for what it actually covers — both plan-level and Workspace-entitlement-level commercially significant mutations, corrected from an earlier "plan transitions" framing that undercounted its scope. Limited to the commercially significant subset only, following RFC-003 §19's "not every event needs a durable row" discipline (§21).

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `workspace_id` | bigint unsigned FK | no | — | |
| `transition_type` | varchar(48) | no | — | `WorkspaceEntitlementTransitionType`: `plan_assigned`\|`plan_changed`\|`plan_status_changed`\|`complimentary_granted`\|`complimentary_revoked`\|`additional_business_slots_changed`\|`entitlement_override_allowed`\|`entitlement_override_denied`\|`entitlement_override_reverted` — **nine values, `plan_status_changed` added in this revision** |
| `actor_user_id` | bigint unsigned, nullable, no FK | yes | `null` | `null` only for system-authored `plan_assigned` rows from: (1) the historical Milestone-1 backfill; and (2) the §17.4 legacy auto-provisioned-Workspace compatibility assignment — every ordinary actor-driven transition still requires its actor |
| `from_plan_catalog_id` | bigint unsigned, nullable, FK | yes | `null` | Set for `plan_changed`; null for `plan_assigned` and every other transition type |
| `to_plan_catalog_id` | bigint unsigned, nullable, FK | yes | `null` | Set for `plan_assigned`/`plan_changed`; null for every other type |
| `feature_key` | varchar(64), nullable | yes | `null` | Set only for `entitlement_override_*` transition types |
| `from_override_state` | varchar(10), nullable | yes | `null` | Set only when an override changes an *existing* override's state or is reverted (the state it had immediately before); null when creating a first-time override |
| `to_override_state` | varchar(10), nullable | yes | `null` | Set only for `entitlement_override_allowed`/`entitlement_override_denied`; null for `entitlement_override_reverted` |
| `from_additional_business_slots` | unsigned tinyint, nullable | yes | `null` | Set only for `additional_business_slots_changed` — including a tier-change-triggered normalization (§17), not only a direct allocation call |
| `to_additional_business_slots` | unsigned tinyint, nullable | yes | `null` | Set only for `additional_business_slots_changed` |
| `from_status` | varchar(20), nullable | yes | `null` | **Added in this revision.** Set only for `plan_status_changed` |
| `to_status` | varchar(20), nullable | yes | `null` | **Added in this revision.** Set only for `plan_status_changed` |
| `reason` | text, nullable | yes | `null` | Required (non-null) for `plan_status_changed` specifically (§18); optional elsewhere per each mutation's own rule |
| `created_at` | timestamp | no | — | No `updated_at` — immutable row, matching `workspace_transitions` |

The minimum nullable-column set above makes every one of the **nine** transition types fully deterministic and reconstructable without any JSON column (§10.6).

Indexes: composite `(workspace_id, created_at)` (serves both the per-Workspace lookup and InnoDB's FK-leftmost-index requirement, exactly matching `workspace_transitions`' documented rationale).
Foreign keys: `workspace_id` → `workspaces.id`; `from_plan_catalog_id`/`to_plan_catalog_id` → `workspace_plan_catalog.id`, all `restrictOnDelete()`.

Access authority: `WorkspaceEntitlementTransitionRepository` (§20).

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

**Why "inherit" is never a stored row rather than a third enum value:** an explicit `inherit` row would be a no-op that means exactly the same thing as no row at all, while adding a case every reader must handle identically to absence. Modeling the tri-state as {no row = inherit, row with `state = allow`, row with `state = deny`} keeps the table's only rows meaningful (an actual exception exists) and "revert to inherit" a plain delete — durably audited via `workspace_entitlement_transitions` (§10.4, §15), not via row retention.

### 10.6 `business_feature_toggles`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `business_id` | bigint unsigned FK | no | — | |
| `feature_key` | varchar(64) | no | — | Validated against `PlatformFeature` at the application layer |
| `reason` | text, nullable | yes | `null` | Optional — Business-level toggles are lower-stakes than Workspace overrides (§21) |
| `created_by_user_id` | bigint unsigned, nullable, no FK | yes | `null` | The Workspace owner/admin who disabled it |
| timestamps | — | no | — | |

Indexes/constraints: unique `(business_id, feature_key)`.
Foreign key: `business_id` → `businesses.id`, `restrictOnDelete()`.

**No boolean/"enabled" column and no "allow" state, by design (§16):** a Business toggle can only ever disable — the RFC's own invariant ("never grant a feature the Workspace is not effectively entitled to") means there is no legitimate "explicitly enabled beyond default" state to store. A row's mere existence *is* "disabled here"; absence is "enabled, subject to Workspace entitlement." Re-enabling deletes the row.

Access authority: `BusinessFeatureToggleRepository` (§20). Toggle changes are event-only, not durably audited in `workspace_entitlement_transitions` — see §21's explicit justification.

**Six tables total: `workspace_plan_catalog`, `workspace_plan_features`, `workspace_plan_assignments`, `workspace_entitlement_overrides`, `business_feature_toggles`, `workspace_entitlement_transitions`.**

No JSON blob for authoritative state: every table above is fully relational and queryable — no entitlement decision requires deserializing a JSON column, unlike the legacy `Plan`/`Subscription::options` blobs this RFC deliberately does not extend (§6).

---

## 11. Feature-registry architecture

**Decision: hybrid, with two separate code-backed concerns kept explicitly distinct — identity and availability — plus database-backed packaging.**

**Feature identity** — `App\Enums\Entitlement\PlatformFeature`, a string-backed PHP enum, matching RFC-003's `WorkspaceMembershipRole`/`WorkspaceBusinessAccessScope` convention:

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

`PlatformFeature::cases()` is the single source of truth every write path (`workspace_plan_features`, `workspace_entitlement_overrides`, `business_feature_toggles`) validates `feature_key` against before persisting — never a free-text string accepted from a controller. `PlatformFeature::ProspectOutreach` remains a valid, stable entitlement key regardless of its availability status below — identity and availability are independent concerns (§7).

**Feature availability — a separate, equally code-backed concern.** v1.0 incorrectly treated "the key exists in `PlatformFeature::cases()`" as sufficient for a plan mapping or override to make a feature executable; v1.1 corrected that by adding `PlatformFeatureRegistry`. **This revision further corrects `PlatformFeatureRegistry`'s own illustrative content**, which had itself asserted `ProspectOutreach` as confirmed `Available` without direct file-level evidence (§5 finding 9):

```php
enum PlatformFeatureAvailability: string
{
    case Available = 'available';
    case Planned = 'planned';
}

final class PlatformFeatureRegistry
{
    // Illustrative shape only — see the caveat below. Exact per-key values
    // must be re-verified against actual repository modules at Milestone 1
    // implementation time, not assumed from this table.
    private const AVAILABILITY = [
        PlatformFeature::Crm->value => PlatformFeatureAvailability::Available,           // existing Contact module
        PlatformFeature::Conversations->value => PlatformFeatureAvailability::Available, // existing ChatBox module
        PlatformFeature::Automations->value => PlatformFeatureAvailability::Available,   // existing Automations module
        PlatformFeature::Calendar->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::Forms->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::WebsiteGeneration->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::AiCooBasic->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::SeoBasicVisibility->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::AdsBasicVisibility->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::SeoModule->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::GoogleAdsModule->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::MetaAdsModule->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::WhiteLabel->value => PlatformFeatureAvailability::Planned,       // pending §26/§29 M3 verification
        PlatformFeature::AgencyPackageCapabilities->value => PlatformFeatureAvailability::Planned,
        PlatformFeature::ProspectOutreach->value => PlatformFeatureAvailability::Planned, // corrected in v1.2 — unverified in this RFC's own inspection, see §5 finding 9; M1 must directly confirm or correct
    ];

    public static function isKnown(string $featureKey): bool { /* in PlatformFeature::cases() */ }

    public static function isAvailable(string $featureKey): bool
    {
        return self::isKnown($featureKey)
            && (self::AVAILABILITY[$featureKey] ?? null) === PlatformFeatureAvailability::Available;
    }
}
```

**Caveat, stated explicitly rather than glossed over:** `Crm`/`Conversations`/`Automations` are marked `Available` above on the strength of this session's own direct confirmation that a Contact module, a ChatBox module, and an Automations module already exist and are routed (§5 finding 8) — a real, if narrow, evidentiary basis. **`ProspectOutreach` is marked `Planned` in this revision, corrected from v1.1, which asserted it was `Available` "on RFC-002's own shipped implementation" — that was an unverified claim, not confirmed repository evidence; this RFC's actual inspection never performed a direct file-level check for it (§5 finding 9).** Every other case is marked `Planned` because no equivalent direct confirmation exists in this RFC's own repository inspection — not because they are confirmed absent. **Milestone 1's own implementation must re-verify every value in this table against actual repository modules before shipping it**, exactly as §26/§29 already requires for white-label specifically; this table demonstrates the mechanism's shape, it is not a substitute for that verification. **A capability existing outside this repository, with an intent to migrate it in later, does not make it `Available` here today** — availability is always this repository's own current, directly-inspected code, never a roadmap intent, a product-requirements description, or another RFC's general scope statement.

**The absolute rule this registry enforces (§14, §15):** a plan mapping or a Workspace override may make an *available* feature entitled outside its normal packaging (an override may grant Growth-only access to a Core Workspace, for instance) — but **neither may ever make an unavailable feature executable.** `platform_feature_unavailable` is checked and can deny before any override or plan mapping is even resolved (§14), and no override write path accepts a `feature_key` that fails `PlatformFeatureRegistry::isAvailable()` for an `allow` override specifically (a `deny` override for an unavailable feature is harmless and permitted, though redundant, since the feature is already denied). This is a deliberate, code-only switch — **no database column lets an admin claim unsupported code exists**; only a code deploy changes a feature's availability.

---

## 12. Plan model / Core-Growth-Agency semantics

### 12.1 Tier identity vs. commercial/structural data

`WorkspacePlanTier` (`core`\|`growth`\|`agency`) is a fixed, code-defined **identity enum only** — it carries no structural rules, no slot counts, no ratios. **`workspace_plan_catalog` (§10.1) is the sole authority for every structural/commercial fact about a tier**: `business_slot_included`, `business_slot_max`, `unlimited_business_slots`, `additional_business_slot_price_ratio`, `price`, `currency_id`. There is exactly one place slot/ratio policy lives — the seeded catalog row — and `EntitlementManager` (§20) always reads it from there, never from a hard-coded `3`/`5`/`0.5000` literal anywhere in manager logic beyond the one seed migration (§25).

### 12.2 Plan feature matrix (Milestone 1 seed data, §25)

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

This table is realized as fifteen `workspace_plan_features` rows for Agency, twelve for Growth, nine for Core — seeded once at Milestone 1 (§25), editable afterward through the admin surface (§22) without a deploy. **This table is packaging only** — it says nothing about implementation availability (§11); a `✓` for a `Planned`-availability feature (which now includes `Prospect Outreach`, corrected in this revision) is a valid, honest seed row meaning "this tier will include it once built/confirmed," and §14's algorithm denies execution via `platform_feature_unavailable` until that changes.

### 12.3 Slot policy per tier

See §13 for the full slot model. Seeded `workspace_plan_catalog` values: Core → `business_slot_included = 3`, `business_slot_max = 5`, `unlimited_business_slots = false`, `additional_business_slot_price_ratio = 0.5000`. Growth → identical slot/ratio shape to Core (the RFC's product direction gives Growth the same 3-included/5-max/50%-ratio structure as Core, differing only in feature packaging per §12.2). Agency → `unlimited_business_slots = true`, `business_slot_max = null`, `additional_business_slot_price_ratio = null` (no additional-slot concept — unlimited from the start).

### 12.4 Entitlement architecture vs. implementation availability

Restated from §11: this RFC defines the complete Core/Growth/Agency feature matrix above, including keys for modules that may not exist in code yet. Implementing those modules is explicitly out of scope for RFC-004 (§3) — this RFC only guarantees that when they are built (or, for `ProspectOutreach` specifically, confirmed to already exist, §5 finding 9), the plan they should be gated behind is already a stable, seeded fact, and that until then, `PlatformFeatureRegistry` denies execution deterministically rather than an unbuilt or unverified module silently appearing "entitled."

### 12.5 The pricing/allocation invariant, in full

A `workspace_plan_catalog` row may exist, be `is_active`, and carry a fully-defined slot/ratio policy while `price`/`currency_id` remain `null` — product requirements did not freeze actual base prices at RFC-drafting time, and this RFC does not invent them (§8). This subsection states the complete, closed set of rules this invariant enforces — expanded in this revision to cover slot allocation and catalog mutation, not only initial plan assignment:

- **Plan assignment.** A **non-complimentary** (`is_complimentary = false`) `workspace_plan_assignments` row must never be created or changed to reference a catalog row whose `price` or `currency_id` is null — `EntitlementManager` (§20) checks this at write time and rejects the mutation (`UndefinedPlanPricingException`) rather than silently representing an undefined paid state. A **complimentary** assignment never needs catalog pricing at all and is never subject to this check — complimentary means the charge is waived, not that a price was decided.
- **Increasing `additional_business_slots`.** For a **non-complimentary** assignment, increasing `additional_business_slots` above its current value (whether via a direct allocation call or as part of an Agency→Core/Growth tier change, §17) requires `workspace_plan_catalog.price`, `.currency_id`, **and** `.additional_business_slot_price_ratio` to all be non-null on the referenced catalog row — rejected otherwise with the same `UndefinedPlanPricingException` (it is the same underlying condition: an attempt to represent a paid commercial state the catalog has not yet defined). For Core/Growth, `additional_business_slot_price_ratio` must remain configured and valid whenever a paid additional slot can legitimately be allocated for that tier.
- **Increasing `additional_business_slots` for a complimentary assignment is never subject to the check above** — matching the plan-assignment rule exactly, and closing what was ambiguous in v1.1: an admin may grant a complimentary Workspace additional Business slots as a matter of policy regardless of whether commercial pricing has been frozen for that tier yet, since no charge occurs either way (§8, §13).
- **Decreasing or revoking `additional_business_slots` is always permitted**, regardless of catalog pricing state — removing commercial capacity must never be blocked by missing price configuration.
- **`price` and `currency_id` are never independently nullable** — they are either both null or both populated. `EntitlementManager`'s catalog-mutation path enforces this pairing at write time, the same application-layer discipline RFC-003 uses for its own cross-table invariants that no plain database constraint can express.
- **Catalog price/currency protection.** `price` or `currency_id` on an existing `workspace_plan_catalog` row must never be cleared (set to null) while any **non-complimentary** `workspace_plan_assignments` row still references that catalog row. `EntitlementManager`'s catalog-mutation path checks for such a reference and rejects the mutation (`PlanCatalogPricingInUseException`, new) rather than silently creating an undefined-pricing state underneath an already-paid Workspace. A complimentary assignment referencing the row does not block this clearing, since it was never subject to the pricing requirement in the first place.
- **Exact-decimal boundary (added in this correction, narrow — clarifying an implementation detail already implied by `price DECIMAL(16,2)`, §10.1, not a new invariant).** `price` is a `DECIMAL(16,2)` column and must be treated as an exact decimal value across the entire mutation boundary — accepted, validated, and passed to the catalog-mutation path as a decimal string, never as a PHP binary `float`. A `float` round-trip through this boundary risks silent precision loss against an exact `DECIMAL` column; this RFC does not permit it anywhere `price` is written.

---

## 13. Business-slot semantics

- **Core/Growth included capacity:** the first 3 Businesses in a Workspace are included in the base price, with no allocation step required.
- **Core/Growth additional capacity — an explicit, allocated, priced grant, not an automatic extension.** Businesses 4 and 5 require the Workspace's `workspace_plan_assignments.additional_business_slots` to be explicitly `1` or `2` respectively (§10.3) — a Core/Growth Workspace with `additional_business_slots = 0` cannot create a 4th Business at all, regardless of `business_slot_max`. Each allocated additional slot is commercially priced at `workspace_plan_catalog.additional_business_slot_price_ratio` (`0.5000`, i.e. 50%) of the tier's normal plan price, subject to §12.5's pricing/allocation invariant. 5 (`business_slot_included + 2`) is the hard maximum for Core/Growth; a 6th Business is always denied regardless of allocation and requires the Workspace to be on Agency.
- **Agency:** unlimited Businesses/locations (`unlimited_business_slots = true`); `additional_business_slots` is not a meaningful concept for Agency and must remain `0` (§8, §10.3).
- **Effective capacity formula (Core/Growth):** `min(business_slot_included + additional_business_slots, business_slot_max)`. With `additional_business_slots = 0` → capacity 3; `= 1` → capacity 4; `= 2` → capacity 5. **A 4th or 5th Business must never succeed merely because `business_slot_max = 5`** — the allocation must actually have happened.
- **Slot entitlement vs. RFC-005 usage-wallet billing:** these are different concerns. RFC-004 owns the *structural allocation quantity* — whether the Workspace has been granted an additional slot at all (§17's `EntitlementManager` mutation). Whether/how that allocation is actually invoiced, charged, or reflected in a usage ledger is RFC-005's exclusive domain (§19); RFC-004 records the commercial ratio (`additional_business_slot_price_ratio`) cleanly enough for RFC-005 to consume later — and RFC-005's eventual checkout flow is expected to call the **same** authoritative allocation mutation this RFC defines (§17) once payment succeeds, so slot enforcement is never redesigned when RFC-005 ships.
- **Complimentary Workspaces and additional-slot billing (added in this revision, cross-referencing §18).** `is_complimentary = true` waives the recurring plan charge **and** any recurring charge that would otherwise correspond to already-allocated additional Business slots — a complimentary Workspace's `additional_business_slots` value (however it was set, including via the Milestone-1 backfill, §25.4) represents real, honored capacity, never an unpaid balance. Complimentary status does **not** waive: the slot allocation limits themselves (the `{0,1,2}`/`business_slot_max` bounds still apply exactly), the effective-capacity rule, an explicit `inactive`/`suspended` operational status (§18), or any future RFC-005 metered-usage cost — unless RFC-005 later explicitly says otherwise for a specific charge type. This matters concretely because the Milestone-1 backfill may assign `additional_business_slots = 1` or `2` to an existing complimentary Workspace that already has 4 or 5 Businesses (§25.4). **RFC-005 must never later interpret those grandfathered complimentary slot allocations as unpaid recurring-slot debt.**
- **Effective slot count** for a Workspace = `count(businesses WHERE workspace_id = :id)` — every Business row belonging to the Workspace, **regardless of that Business's own `status`**, since a Business row existing under the Workspace is what consumes tenancy capacity; RFC-003 draws no distinction here and neither does this RFC. **Deactivating a Business never reduces this count** (§8, §25.4) — only an authorized Business reassignment to a different Workspace (RFC-003 §16.2) can, since RFC-003 provides no Business-deletion mechanism and RFC-004 does not invent one.
- **Denial, not deletion:** attempting to create a Business beyond effective capacity is denied at creation time (§17) with a stable denial reason (§14/§17). No existing Business is ever removed, hidden, or deactivated as a consequence of a slot limit, at any point, including during backfill (§25) or a tier downgrade (§17).
- **Allocation authority:** only the platform admin, under RFC-004's admin authority (§22), may allocate or revoke an additional Business slot, or authorize one as part of a tier change (§17). A Workspace customer may inspect their allocation and effective capacity (§22) but may never self-grant an unpaid additional slot.

---

## 14. Entitlement precedence algorithm

Preconditions: RFC-003 §14.1 authorization (`userCanAccessBusiness()`) has already granted the acting user access to this Business, and the caller has already resolved the Business's owning Workspace. This algorithm is never invoked to *establish* Workspace/Business authorization — only to answer, given already-authorized access, whether a specific feature may execute.

**Defensive consistency check (added in this revision, narrow — not a redesign):** `EntitlementManager::decide()` additionally, defensively re-reads the Business and verifies that its authoritative `workspace_id` equals the supplied Workspace's `id` before consulting any RFC-004 entitlement state. This check does **not** grant access, does **not** evaluate ownership or membership, and is **not** a substitute for the RFC-003 §14.1 authorization above, which must still have passed independently before `decide()` is ever called. It exists only to prevent stale or mismatched aggregate inputs — e.g. a caller holding a `Business` object from before a concurrent reassignment to a different Workspace — from mixing one Workspace's entitlement state with a Business that no longer (or never did) belong to it.

```text
function effectiveEntitlement(workspace, business, featureKey, actorUserId):
    if featureKey not in PlatformFeature::cases():
        return denied('platform_feature_unknown')

    if not PlatformFeatureRegistry::isAvailable(featureKey):
        return denied('platform_feature_unavailable')

    assignment = WorkspacePlanAssignment.find(workspace)
    if assignment is null:
        return denied('workspace_plan_unassigned')   // never dereferenced before this check

    override = WorkspaceEntitlementOverride.find(workspace, featureKey)
    if override is not null:
        workspaceEntitled = (override.state == ALLOW)
        denialReasonIfNot = 'denied_by_workspace_override'
    else:
        workspaceEntitled = WorkspacePlanFeature.exists(assignment.workspace_plan_catalog_id, featureKey)
        denialReasonIfNot = 'not_entitled_by_plan'

    if not workspaceEntitled:
        return denied(denialReasonIfNot)

    if BusinessFeatureToggle.exists(business, featureKey):
        return denied('disabled_for_business')

    billingState = effectiveBillingState(assignment)  // §18, corrected precedence
    if billingState == SUSPENDED:
        return denied('plan_suspended')
    if billingState == INACTIVE:
        return denied('plan_inactive')
    // billingState is ACTIVE or COMPLIMENTARY here

    usageAuthorization = UsageAuthorizationGateway::check(business, featureKey)  // RFC-005 seam, §19
    if not usageAuthorization.authorized:
        return denied(usageAuthorization.reason)  // reserved key, never reachable before RFC-005 ships

    return allowed()
```

**Precedence, explicit and total (no ambiguity left to a caller's interpretation):**

1. **Feature key known** — the hard floor. An unknown key is always denied (`platform_feature_unknown`), regardless of any override or plan.
2. **Implementation available** (§11) — checked before the Workspace assignment is even resolved, and strictly before any override is consulted. An unavailable feature is always denied (`platform_feature_unavailable`), and **no override can ever reach or bypass this step** — an `allow` override write is itself rejected at creation time for an unavailable feature (§11), so this state can never even be reached with a contradicting override on file.
3. **Workspace assignment resolved** — a Workspace with no `workspace_plan_assignments` row denies with `workspace_plan_unassigned` before any field of a (non-existent) assignment is dereferenced.
4. **Workspace effective entitlement** = the override if one exists for this exact `(workspace, feature)` pair, else the plan-feature mapping. An override, when present, **completely replaces** the plan-mapping answer for that feature. This is the one deliberate point where an admin override may grant a feature outside the Workspace's base plan (§15) — bounded strictly by step 2 having already passed.
5. **Business feature toggle** can only narrow a `true` Workspace entitlement to `false` for one specific Business — consulted only after Workspace entitlement has already passed, and can never flip a Workspace `false` to a Business-level `true`.
6. **Billing/operational state** (§18) gates *feature entitlement decisions* specifically — it never gates RFC-003 tenancy access (login, viewing owned data, the Workspace overview).
7. **Usage authorization** is the RFC-005 seam (§19) — until RFC-005 ships, this step is a fixed pass-through that always returns `authorized: true`.
8. **Allowed.**

Stable denial reasons (nine total, one reserved): `platform_feature_unknown`, `platform_feature_unavailable`, `workspace_plan_unassigned`, `not_entitled_by_plan`, `denied_by_workspace_override`, `disabled_for_business`, `plan_inactive`, `plan_suspended`, and `usage_unauthorized` (reserved for RFC-005, never returned today) — suitable for tests, UI messaging, logs, and eventual RFC-005 billing-decision integration without redesign.

---

## 15. Workspace overrides

An admin override may grant a feature **outside** the Workspace's assigned plan's base mapping — **but never an unavailable one** (§11, §14 step 2). **This is explicit and intentional — stated here rather than left unresolved, per this RFC's own requirement.** Justification: sales exceptions, support goodwill, and pilot/beta access are legitimate, common SaaS operational needs, and an admin-only, reason-required, durably audited override is a safer way to satisfy them than an ad hoc code change or a manually-edited plan-mapping row that would affect every Workspace on that tier.

Every override write requires: the acting admin's `created_by_user_id`, a non-empty `reason`, dispatches `WorkspaceEntitlementOverrideChanged` (§21), and writes an `entitlement_override_allowed`/`entitlement_override_denied` row to `workspace_entitlement_transitions` (§10.4) — **durable audit, not event-only**: an override may grant a paid capability outside the base plan, which is a high-value enough mutation that event dispatch alone (easily missed by a listener) is insufficient (§21). Reverting to inherit is a delete of the override row, which also dispatches the event and writes an `entitlement_override_reverted` transition row recording the state it had immediately before removal.

**Precedence between platform support, availability, plan mapping, Workspace override, and Business toggle** (restated compactly from §14): known key (floor) → available (floor, never bypassable by an override) → Workspace override if present, else plan mapping (Workspace-level entitlement) → Business toggle (narrows only, never widens). This order is total and admits no ambiguity.

---

## 16. Business feature toggles

A Workspace owner or active Admin (RFC-003 §7.3's existing authority levels — reused unmodified, not redefined here) may disable a specific feature for a specific Business under their Workspace, provided the Workspace is effectively entitled to that feature at all (§14 step 5). **A Business toggle can never grant a feature the Workspace is not entitled to** — enforced structurally, not just by convention: §10.6's table has no "enabled beyond default" state to write, and the write path (§20) rejects any attempt to create a toggle for a feature the Workspace does not currently have effective entitlement to, since there would be nothing meaningful to disable.

**Creation defaults for a new Business (deterministic):** a newly created Business (via RFC-003's existing `WorkspaceManager::createBusinessInWorkspace()`/`reassignBusiness()`) starts with **zero** `business_feature_toggles` rows — every feature the Workspace is effectively entitled to is available to the new Business by default, matching §10.6's "absence = enabled" model. No RFC-003 code path needs to change to satisfy this default; it falls out naturally from never writing a toggle row unless an owner/admin explicitly disables something afterward.

---

## 17. Business-creation slot enforcement, the slot decision API, and plan-change normalization

**Corrected in v1.3.** v1.2 stated that `WorkspaceManager::createBusinessInWorkspace()` was "the sole Business-creation orchestration entry point" and therefore the only site needing a capacity assertion. Milestone 2's own mandatory pre-implementation audit found that false against the actual repository: `BusinessManager::applyIdentity()`'s legacy onboarding path calls `BusinessRepository::createForCustomerInWorkspace()` directly (bypassing `createBusinessInWorkspace()` entirely); `WorkspaceManager::reassignBusiness()` increases the destination Workspace's Business-row count on a real cross-Workspace move; and `WorkspaceManager::createWorkspace()` accepted an optional first-Business payload (retired below, §17.4, since no production caller ever supplied one). **The invariant this RFC actually requires, stated generally:**

**Every operation that increases the number of Business rows belonging to a destination Workspace must evaluate the authoritative capacity decision — `EntitlementManager::assertCanCreateAnotherBusiness()` (or the underlying `decideBusinessSlotCapacity()`) — while holding that destination Workspace's row lock, and before the count-increasing write occurs. No caller may duplicate the slot arithmetic itself.**

As of this revision, exactly three operations increase a destination Workspace's Business count and are therefore each individually bound by this invariant: `WorkspaceManager::createBusinessInWorkspace()` (§17, immediately below), `WorkspaceManager::reassignBusiness()` for a real cross-Workspace move (§17.2), and the legacy onboarding path via `BusinessManager`/`WorkspaceManager::resolveLegacyOnboardingWorkspace()` (§17.3). A same-Workspace `reassignBusiness()` call is not count-increasing and is unaffected. Should a future milestone introduce another count-increasing path, it inherits this same invariant by construction, not by a fresh case-by-case decision.

`createBusinessInWorkspace()` itself keeps its exact existing requirements: the target Workspace is locked first (`findForUpdate()`, already present per RFC-003 §18), its existing active-state and RFC-003 authority checks remain exactly as they are, and **exactly one additive call is inserted into that existing method**, after the lock and before the `Business` insert, in the same transaction, with no controller-side slot arithmetic:

```php
$this->entitlementManager->assertCanCreateAnotherBusiness($lockedWorkspace);
```

**Slot decisions are explainable, not only exception-shaped** — UI and tests must never re-derive slot math independently of `EntitlementManager`. The manager exposes an underlying immutable decision object:

```php
final readonly class BusinessSlotCapacityDecision
{
    public function __construct(
        public int $currentBusinessCount,
        public int $includedSlots,
        public int $additionalSlotsAllocated,
        public ?int $effectiveCapacity,  // null when unlimited
        public bool $unlimited,
        public bool $allowed,
        public ?string $denialReason,
    ) {}
}
```

`EntitlementManager::decideBusinessSlotCapacity(Workspace $workspace): BusinessSlotCapacityDecision`:

```text
function decideBusinessSlotCapacity(workspace):
    assignment = WorkspacePlanAssignment.find(workspace)
    if assignment is null:
        return decision(allowed=false, denialReason='workspace_plan_unassigned')

    billingState = effectiveBillingState(assignment)  // §18
    if billingState == SUSPENDED:
        return decision(allowed=false, denialReason='plan_suspended')
    if billingState == INACTIVE:
        return decision(allowed=false, denialReason='plan_inactive')

    catalog = assignment.catalog
    currentCount = count(businesses where workspace_id = workspace.id)

    if catalog.unlimited_business_slots:
        return decision(currentCount, catalog.business_slot_included, 0, null, unlimited=true, allowed=true, null)

    effectiveCapacity = min(catalog.business_slot_included + assignment.additional_business_slots, catalog.business_slot_max)

    if currentCount < effectiveCapacity:
        return decision(currentCount, catalog.business_slot_included, assignment.additional_business_slots, effectiveCapacity, false, allowed=true, null)

    if effectiveCapacity < catalog.business_slot_max:
        // a further additional-slot allocation could still raise capacity
        return decision(..., allowed=false, denialReason='business_slot_allocation_required')

    // already at the tier's absolute maximum (business_slot_max)
    return decision(..., allowed=false, denialReason='business_slot_limit_exceeded')
```

`EntitlementManager::assertCanCreateAnotherBusiness(Workspace $workspace): void` calls `decideBusinessSlotCapacity()` internally and throws a typed exception matching `denialReason` (`WorkspacePlanUnassignedException`, `InactiveWorkspacePlanException`/`SuspendedWorkspacePlanException`, `BusinessSlotAllocationRequiredException`, or `BusinessSlotLimitExceededException` — all new, `app/Exceptions/Entitlement/`) when `allowed` is `false`. `createBusinessInWorkspace()` propagates whichever exception uncaught, exactly as it already propagates `InactiveWorkspaceMutationException`/`UnauthorizedWorkspaceManagementException` today — no new try/catch pattern, no duplicated authority logic, and the UI/admin/customer surfaces (§22) call `decideBusinessSlotCapacity()` directly for display rather than re-implementing the arithmetic.

**Concurrency safety — two simultaneous requests must not both consume the last slot:** `createBusinessInWorkspace()` already locks the Workspace row (`findForUpdate()`) for the duration of its transaction before this call executes (RFC-003 §18). Because the slot count is read *after* that lock is held, and the Business insert happens inside the same transaction before the lock releases, two concurrent calls for the same Workspace serialize on that existing lock exactly the way RFC-003 already serializes every other Workspace mutation. No new lock, no new transaction boundary, no new concurrency primitive is introduced.

**Additional-slot allocation is a separate, admin-only, durably audited mutation:** `EntitlementManager::setAdditionalBusinessSlots(Workspace $workspace, int $count, int $actorUserId, ?string $reason): void` — validates `$count` is `0`, `1`, or `2` for a Core/Growth-assigned Workspace (rejecting any other value, and rejecting a non-zero value outright for an Agency-assigned Workspace, since Agency has no additional-slot concept, §8/§13), enforces §12.5's pricing/allocation invariant when `$count` increases, always permits a decrease regardless of pricing state, dispatches `WorkspaceAdditionalBusinessSlotsChanged` (§21), and writes an `additional_business_slots_changed` row to `workspace_entitlement_transitions` (§10.4) recording the before/after count. **This is the one authoritative mutation point for the allocation quantity** — RFC-004 does not execute the corresponding charge/checkout itself (§3, §13), but RFC-005's eventual paid-checkout flow is expected to call this exact method once payment succeeds, so slot enforcement is never redesigned when RFC-005 ships.

### 17.1 Plan-tier change and slot normalization (added in this revision)

`EntitlementManager::changePlan(Workspace $workspace, WorkspacePlanTier $newTier, int $actorUserId, ?string $reason = null, ?int $additionalBusinessSlots = null): void` changes a Workspace's assigned tier and deterministically normalizes `additional_business_slots` **as part of the identical locked transaction** — never a separate, later, or "fake" follow-up action:

- **Core ↔ Growth:** `additional_business_slots` is **preserved unchanged** — a lateral tier change never resets or requires re-allocating existing paid capacity. `$additionalBusinessSlots` must be `null` for this case (any desired slot change is a separate `setAdditionalBusinessSlots()` call, kept out of this method's own scope).
- **Core/Growth → Agency:** `additional_business_slots` is **atomically reset to `0`** as part of the same mutation — Agency is unlimited and must never retain a stale additional-slot count that no longer means anything. Because the slot value actually changed, both the `plan_changed` transition (recording the tier change, `from_plan_catalog_id`/`to_plan_catalog_id`) **and** an `additional_business_slots_changed` transition (recording the `0`-reset, `from_additional_business_slots`/`to_additional_business_slots`, §10.4/§21) are written inside the same transaction and the same method call, with `WorkspacePlanChanged` and `WorkspaceAdditionalBusinessSlotsChanged` both dispatched after commit — satisfying "do not require a fake separate user action" by construction, not by convention.
- **Agency → Core/Growth:** `additional_business_slots` **defaults to `0`** — RFC-004 never automatically grants a paid additional slot on downgrade. The caller may optionally pass `$additionalBusinessSlots` (`1` or `2`) to allocate additional capacity **as part of the same admin operation**; when non-zero, this is subject to the identical pricing-required check §12.5 defines for any other slot increase (non-complimentary requires catalog price/currency/ratio all defined; complimentary never requires it, §12.5/§13). **Existing Businesses are never removed, deactivated, or hidden** as a result of a downgrade (§8, §13) — a Workspace whose existing Business-row count already exceeds the resulting effective capacity becomes grandfathered-over-capacity, the same posture §25.4 defines for the one-time backfill, now equally reachable via an admin-initiated downgrade: future Business creation is denied (`business_slot_allocation_required`/`business_slot_limit_exceeded` as applicable) until additional slots are explicitly allocated, the tier is upgraded again, or the Workspace's actual Business-**row** count falls — which, per §8/§13/§25.4, can only happen through an authorized reassignment to a different Workspace (RFC-003 §16.2), never through Business deactivation.

This is the only path that changes `workspace_plan_assignments.workspace_plan_catalog_id`; it reuses the same normalization/pricing-check logic `setAdditionalBusinessSlots()` uses rather than duplicating it, and dispatches `WorkspacePlanChanged` (always) and `WorkspaceAdditionalBusinessSlotsChanged` (only when the slot value actually changed as part of this call).

### 17.2 Cross-Workspace reassignment slot enforcement (added in this revision)

`WorkspaceManager::reassignBusiness()` keeps its existing architecture exactly: it derives the source and target Workspace IDs, deduplicates and sorts them ascending, locks every distinct Workspace involved in that ascending order, then locks the Business and re-verifies its authoritative source `workspace_id`, then checks authority and active-state for both Workspaces. **For a real cross-Workspace move** (source and target IDs differ) — after every one of those existing lock/consistency/authority/active-state checks has already passed, but **before** removing the source Workspace's membership assignment grants and **before** `BusinessRepository::reassignWorkspace()` — it now additionally calls the authoritative target-capacity assertion using the already-locked target Workspace:

```php
$this->entitlementManager->assertCanCreateAnotherBusiness($lockedTargetWorkspace);
```

A **same-Workspace** reassignment call (source ID equals target ID) is not count-increasing and remains exactly the existing authorized no-op — it must never fail merely because that Workspace happens to already be at capacity. The destination-capacity failure for a real move uses the identical typed exceptions ordinary Business creation uses (`WorkspacePlanUnassignedException`, `InactiveWorkspacePlanException`/`SuspendedWorkspacePlanException`, `BusinessSlotAllocationRequiredException`, `BusinessSlotLimitExceededException`) — no separate exception family for reassignment.

**Concurrency:** because the target Workspace is already locked (as part of `reassignBusiness()`'s own existing ascending-order multi-Workspace locking) before this assertion runs, and the assertion, the grant cleanup, and `reassignWorkspace()` all execute inside that same already-open transaction before the lock releases, two concurrent operations racing to consume a destination Workspace's final slot — whether both are `createBusinessInWorkspace()` calls, both `reassignBusiness()` calls, or one of each — serialize on that one lock exactly as every other Workspace mutation already does (§18 of RFC-003). No new lock, no new transaction boundary, no new concurrency primitive is introduced. The existing opposite-direction-reassignment deadlock-prevention behavior (ascending-ID lock order for two concurrent reassignments swapping Businesses between the same two Workspaces) is unaffected by this addition — the capacity assertion runs strictly after all locks for the operation are already held, never introducing a new lock acquired out of that established order.

### 17.3 Legacy onboarding compatibility (added in this revision)

The legacy onboarding Business-creation path (`BusinessManager::applyIdentity()`, called from `createOrUpdateOnboardingBusiness()`) has never been routed through `createBusinessInWorkspace()` and never carried RFC-003's owner-or-active-Admin authority requirement — it creates the customer's own first/onboarding Business directly. **This revision does not silently replace that path with `createBusinessInWorkspace()`** — doing so would newly impose an authority requirement (and its associated failure mode) the legacy path never had, which is exactly the kind of behavior change RFC-004 is bound not to introduce without a deliberate decision. Instead, a bounded compatibility integration guarantees only the one new thing this RFC actually requires — capacity enforcement — without touching authority or ownership semantics:

- `BusinessManager::applyIdentity()` calls `WorkspaceManager::resolveLegacyOnboardingWorkspace()` exactly as it already does, unchanged, to select (or provision, §17.4 below) the target Workspace.
- Immediately afterward, still inside `applyIdentity()`'s own existing transaction, `BusinessManager` explicitly locks that selected Workspace via a new narrow `WorkspaceManager` locking primitive (`findForUpdate()` only — no authority check, no active-state check, since the legacy path has never enforced either and this integration does not newly add them) and then calls `EntitlementManager::assertCanCreateAnotherBusiness()` on the now-locked Workspace directly — `BusinessManager` calls the authoritative capacity assertion itself rather than computing slot arithmetic of its own, and no raw entitlement-table query appears anywhere in `BusinessManager`.
- Only once that assertion passes does `BusinessRepository::createForCustomerInWorkspace()` execute, inside the same lock, the same transaction.
- The Workspace-locking step is a genuinely new, explicit lock acquisition performed by `BusinessManager` immediately after resolution — it is not assumed to be inherited implicitly from `resolveLegacyOnboardingWorkspace()`'s own internal transaction (which, being nested inside `applyIdentity()`'s outer transaction via Laravel's savepoint-based nested-transaction behavior, would in practice keep any lock taken inside it open for the rest of the outer transaction too — but Milestone 2's implementation contract locks the *explicit* re-lock design specifically so this guarantee never depends on a reader correctly reasoning through that nested-transaction subtlety).
- `resolveLegacyOnboardingWorkspace()`'s own existing candidate-resolution behavior is unchanged: when it is going to throw (multiple preferred or multiple fallback candidates), it still locks no candidate Workspace at all, exactly as today — this integration only ever locks the one, final, already-resolved Workspace, never a candidate under consideration.

### 17.4 Newly auto-provisioned legacy Workspace compatibility (added in this revision)

`resolveLegacyOnboardingWorkspace()` may provision a brand-new Workspace when no existing candidate is found. A brand-new Workspace created after the Milestone 1 backfill has, by construction, no `workspace_plan_assignments` row — Milestone 2's fail-closed capacity algorithm would otherwise deny the legacy onboarding Business immediately with `workspace_plan_unassigned`, breaking existing onboarding for every genuinely new customer. This revision locks a narrow compatibility rule closing that gap, deliberately scoped no wider than it needs to be:

- **Only** when `resolveLegacyOnboardingWorkspace()`'s own provisioning step creates a brand-new Workspace record, the system creates an initial Core/`active`/complimentary `workspace_plan_assignments` row for it — inside the same transaction, before the legacy Business insert can occur — with `additional_business_slots = 0` (a brand-new Workspace has no existing Businesses to derive a nonzero value from).
- This is a narrow **continuation** of Milestone 1's own pre-RFC-004 complimentary-Core backfill posture (§25.3) applied at the one remaining moment a Workspace can still come into existence without ever having passed through that backfill — **not** a general customer self-grant path, and **not** available to an ordinarily (directly) created Workspace (§17.4/§22 — `WorkspaceController::store()`/`WorkspaceManager::createWorkspace()` never trigger it).
- The fixed, explicit reason string is: `"Legacy onboarding Workspace — auto-provisioned complimentary Core assignment continuing Milestone 1's backfill posture for a brand-new Workspace created by the legacy resolver."` `complimentary_granted_by_user_id` is `null` — system provenance, permitted only for this one narrowly-defined case and the already-existing Milestone 1 backfill (§10.3) — never a general admin-bypass precedent.
- A normal `plan_assigned` `workspace_entitlement_transitions` row is written for it, exactly like any other first assignment (§10.4/§21) — no separate transition type is invented. No `complimentary_granted` transition is written for the *initial* assignment, since `is_complimentary` is part of the row's initial state, not a subsequent change to it (matching §10.4's own existing distinction that `complimentary_granted`/`complimentary_revoked` describe a *change* to an existing assignment, not its creation). `WorkspacePlanAssigned` is dispatched after commit, exactly like any other first assignment.
- Normal actor-driven first plan assignment (§20) remains a wholly separate `EntitlementManager` authority, requiring an explicit actor and reason — this compatibility case is never a substitute for it and is never reachable for any Workspace that isn't, at this exact moment, being provisioned for the first time by the legacy resolver itself.

### 17.5 Retirement of Workspace-creation first-Business capability (added in this revision)

Milestone 2's audit directly confirmed `WorkspaceController::store()` is `createWorkspace()`'s only production caller, and it has only ever supplied `createWorkspace($ownerUserId, $name)` — never a `WorkspaceFirstBusinessInput`. No other production caller of `createWorkspace()` exists. This RFC therefore corrects its own prior assumption that Workspace creation may silently insert an unentitled first Business: `WorkspaceFirstBusinessInput` and `createWorkspace()`'s optional third parameter are retired as unused capability, not carried forward into Milestone 2's capacity-enforcement work. Workspace creation becomes **tenancy-only** — `createWorkspace()` creates exactly a Workspace row, nothing else, exactly matching how it is already used in production today. This RFC does **not** invent an automatic complimentary plan assignment for an ordinarily-created Workspace, either — doing so would let any customer create unlimited free Core Workspaces through the ordinary creation surface. An ordinary newly-created Workspace may legitimately exist unassigned; any attempt to create a Business inside it is fail-closed (`workspace_plan_unassigned`, §14) until a legitimate plan assignment — an ordinary actor-driven one (§20) — exists for it, exactly like any other unassigned Workspace.

---

## 18. Billing-state boundary

`workspace_plan_assignments.status` (`active`\|`inactive`\|`suspended`) is the operational lifecycle flag this RFC owns. **Effective billing state, corrected precedence** (§14 step 6, §17):

```text
if assignment.status == suspended:
    SUSPENDED -> deny (plan_suspended)
else if assignment.status == inactive:
    INACTIVE -> deny (plan_inactive)
else if assignment.status == active AND assignment.is_complimentary:
    COMPLIMENTARY -> allow
else if assignment.status == active:
    ACTIVE -> allow
```

**`status` is checked first and is an operational gate; `is_complimentary` is an orthogonal commercial-payment attribute consulted only once `status` has already permitted evaluation to continue.** Complimentary means the recurring plan charge — and any charge corresponding to already-allocated additional Business slots (§13) — is waived, and normal feature/slot entitlements remain; it does not bypass an explicit `inactive` or `suspended` status. An admin who wants a complimentary Workspace to also be denied must set `status` accordingly; complimentary alone never overrides an operational hold.

**Status mutation is itself an authoritative, audited `EntitlementManager` operation (added in this revision).** `workspace_plan_assignments.status` may only be changed via `EntitlementManager::changePlanStatus(Workspace $workspace, WorkspacePlanAssignmentStatus $status, int $actorUserId, string $reason): void` — never written directly by any other code path. Because a status change directly controls both feature execution (§14) and Business-creation ability (§17), it is treated as commercially/security significant exactly like a plan or slot change: the method **requires** a non-null `$actorUserId` and a non-empty `$reason` (both mandatory, unlike some lower-stakes mutations elsewhere in this RFC), dispatches `WorkspacePlanStatusChanged` after commit, and writes a `plan_status_changed` row to `workspace_entitlement_transitions` recording `from_status`/`to_status` (§10.4/§21). The Milestone-1 backfill's initial `status = active` write (§25.3) is the one exception with a null actor, matching every other backfill-authored transition row's system-migration provenance — it is a `plan_assigned` transition, not a `plan_status_changed` one, since there is no "from" status for a first-ever assignment.

**This governs feature-entitlement and slot-creation decisions only — it never gates RFC-003 tenancy access.** A Workspace with `status = inactive` remains fully reachable: its owner can still log in, view the overview, see its Businesses and members, exactly as RFC-003 §14.2 already forward-documents ("RFC-004's entitlement rule may disable paid execution while `is_active` stays `true` and ordinary account access continues"). RFC-004 introduces no new Workspace/Business visibility restriction of any kind — only feature-*execution* and Business-*creation* denial, evaluated exclusively through §14/§17's algorithms at the specific point a gated action is attempted.

**Relationship to `Subscription::status`:** none, by design (§6). RFC-004 defines its own `WorkspacePlanAssignmentStatus` enum rather than reusing `Subscription`'s `new`/`pending`/`active`/`ended`/`renew` set, because that set is shaped for the legacy per-user SMS billing lifecycle. No `trial`/`past_due` state exists in this repository's legacy Subscription model today (confirmed absent from its status constants, §5 finding 2) — if RFC-005 later needs a trial or past-due concept, it is a new, explicitly designed addition to `WorkspacePlanAssignmentStatus`, not something inherited from legacy code today.

**Explicitly not implemented by RFC-004:** any Stripe webhook, usage ledger, wallet, auto-recharge, or Business-payer logic (§19). `workspace_plan_assignments.status` is written only by `EntitlementManager::changePlanStatus()` — no automated billing-event handler exists yet to write it, and none is added here.

---

## 19. RFC-005 usage-authorization boundary

RFC-004 owns **structural** feature entitlement and Business-slot allocation. RFC-005 will own **Business usage wallets, balances, payer selection, the usage ledger, reservation/release for uncertain-cost operations, auto-recharge, monthly usage caps, low-balance notifications, zero-balance usage suspension, usage webhooks/idempotency, Agency client rebilling, and any Stripe usage-billing change, including the actual payment collection for a Core/Growth additional Business slot** — RFC-003 §26.2's deferral list, unchanged and un-narrowed by this RFC.

The seam is `UsageAuthorizationGateway::check(Business $business, PlatformFeature $feature): UsageAuthorizationResult` (new, minimal interface only — `app/Library/Entitlement/Contracts/UsageAuthorizationGateway.php`), called as the final step of §14's algorithm. **RFC-004 ships exactly one implementation of this interface**: `NullUsageAuthorizationGateway`, which always returns `authorized: true` for every call, unconditionally — the "deterministic behavior rather than a broken dependency" the product requirement demands for non-metered features before RFC-005 exists. RFC-005 can bind a real implementation later with zero change to `EntitlementManager` or any caller.

The additional-Business-slot **charge** itself follows the identical pattern: RFC-004's `setAdditionalBusinessSlots()`/`changePlan()` (§17) are the authoritative allocation mutations; RFC-005's future checkout flow calls them after payment succeeds, rather than RFC-004 inventing a temporary payment-adjacent mechanism now.

---

## 20. Manager/repository authority

Following RFC-003's Library/Manager + Repository convention exactly — no new generic service layer.

- **`App\Library\Entitlement\EntitlementManager`** (new) — the sole authority for: computing effective entitlement (§14), assigning/changing a Workspace's plan with slot normalization (§17.1), changing a plan's operational status with mandatory actor/reason and durable audit (§18), granting/revoking complimentary status, allocating/revoking additional Business slots (§17), creating/reverting Workspace overrides, creating/removing Business toggles, `decideBusinessSlotCapacity()`/`assertCanCreateAnotherBusiness()` (§17), and the catalog price/currency mutation guard (§12.5). Every mutating method re-checks authority/state even when the caller already checked, matching RFC-001/RFC-003's manager posture.
- **Repository contracts** (new, no `Interface` suffix, extending `BaseRepository`, bound in `AppServiceProvider::register()`'s existing `$bindings` array exactly like RFC-003's) — **six repositories, one per table (§10), with none left without a named access authority**:
  1. `WorkspacePlanCatalogRepository`
  2. `WorkspacePlanFeatureRepository`
  3. `WorkspacePlanAssignmentRepository`
  4. `WorkspaceEntitlementTransitionRepository`
  5. `WorkspaceEntitlementOverrideRepository`
  6. `BusinessFeatureToggleRepository`

  Each is a plain data-access contract — no business-rule/authority logic beyond the one same-Workspace/valid-feature-key-style validation `WorkspaceMembershipBusinessRepository` already models for its own cross-table check; the *entitlement decision* belongs exclusively to `EntitlementManager`.
- **Feature-access decision:** `EntitlementManager::decide(Workspace $workspace, Business $business, string $featureKey, int $actorUserId): EntitlementDecision` — the one method every controller/job/other feature code must call. Raw/stored feature identity crosses this read-decision boundary as a plain `string` — the caller is never required to already possess a valid `PlatformFeature` enum instance — and is converted internally via `PlatformFeature::tryFrom($featureKey)` before any entitlement-table lookup; conversion failure denies immediately with `platform_feature_unknown` (§14 step 1), never reaching a plan/override/toggle read. Every mutation method remains strongly typed on `PlatformFeature` exactly as elsewhere in this RFC — this string boundary applies to `decide()` alone. `EntitlementDecision` is a small immutable value object (`allowed: bool`, `reason: ?string`), not a bare boolean.
- **Slot-capacity decision:** `EntitlementManager::decideBusinessSlotCapacity()`/`assertCanCreateAnotherBusiness()` (§17).

**No direct entitlement-table query is authorized anywhere outside `EntitlementManager` and its six repositories** — every controller, job, or future feature-gated code path calls `EntitlementManager::decide()` or `decideBusinessSlotCapacity()`, never a repository or the raw tables directly. This mirrors RFC-003's own "controllers never duplicate `WorkspaceManager` logic" discipline.

---

## 21. Events and audit

Following RFC-003 §19's exact convention (immutable events, IDs and scalar metadata only, dispatched after commit):

- `WorkspacePlanAssigned` — first-ever assignment for a Workspace.
- `WorkspacePlanChanged` — tier change on an existing assignment.
- `WorkspacePlanStatusChanged` — **added in this revision** (§18) — `status` changed.
- `WorkspaceComplimentaryStatusChanged` — `is_complimentary` flipped either direction.
- `WorkspaceAdditionalBusinessSlotsChanged` — `additional_business_slots` changed, whether via a direct allocation call or a tier-change normalization (§17/§17.1).
- `WorkspaceEntitlementOverrideChanged` — an override created, changed (allow↔deny), or reverted to inherit (deleted).
- `BusinessFeatureToggleChanged` — a Business toggle created or removed.

**Durable audit table** (`workspace_entitlement_transitions`, §10.4) is written for **nine** transition types — expanded in this revision from eight, adding `plan_status_changed`: `plan_assigned`, `plan_changed`, `plan_status_changed`, `complimentary_granted`, `complimentary_revoked`, `additional_business_slots_changed`, `entitlement_override_allowed`, `entitlement_override_denied`, `entitlement_override_reverted`.

**Why plan status changes now require durable audit (added in this revision):** `status` directly controls both feature execution (§14) and Business-creation ability (§17) — an operational hold or release is exactly the kind of security/commercially significant mutation this table exists for, and event dispatch alone risks being missed by a listener with no queryable durable record left behind.

**Why Workspace overrides and slot allocations require durable audit (corrected in v1.1, unchanged here):** a Workspace override may grant a paid capability outside a Workspace's base plan (§15); a slot-allocation change represents an intended charge (§17). Both durably audited for the same reason as status changes above.

**Business-level feature toggle changes remain event-only.** No direct repository evidence was found of an existing durable audit facility for Business-scoped, lower-stakes, reversible toggles worth reusing here, and they are meaningfully lower-stakes and more frequent than a Workspace-level override, status change, or plan/slot change — matching RFC-003's own restraint (`workspace_transitions` covers only two of RFC-003's fourteen events).

---

## 22. Admin/customer surfaces

Minimum surfaces this RFC's implementation milestones (§29) must eventually cover — full HTTP/UI design is deferred to those bounded contracts, not specified route-by-route here, matching how RFC-003 itself only specified milestone *scope* at the RFC stage and left exact routes to each slice's contract.

**Platform admin** (behind the existing `EnsureUserIsAdministrator` boundary, unmodified, plus new permission keys — §5 finding 5's collision-avoidance: use a distinct category, e.g. `'view workspace plans'`/`'manage workspace plans'` under a new `Workspace Plans` category, never reusing the legacy `'manage plans'`/`Plan` category):

- Inspect the plan catalog and plan-feature mapping (read; mutation is a narrower, separately-gated capability).
- Inspect and change a Workspace's plan assignment and its operational status, both with mandatory reason (§17.1, §18).
- Grant/revoke complimentary status, including for the platform owner's own Workspace — no special-cased mechanism: the same general complimentary-assignment action, applied to whichever Workspace the acting admin happens to own, is architecturally sufficient.
- Allocate/revoke a Core/Growth Workspace's additional Business slots (§17) — the sole write authority for this quantity; a customer may never self-grant one.
- Create/revert Workspace entitlement overrides.
- View the "effective entitlement" explanation for a given (Workspace, Business, feature) — a direct surface over `EntitlementManager::decide()`'s `EntitlementDecision`, never a re-derivation — and the "slot capacity" explanation over `decideBusinessSlotCapacity()`'s `BusinessSlotCapacityDecision`.

**Workspace owner/admin** (customer-side, reusing RFC-003's existing owner/active-Admin authority check — never a new authorization algorithm):

- Inspect the Workspace's current plan tier and its feature list.
- Inspect Business-slot usage/capacity, including included vs. allocated-additional vs. effective capacity (e.g. "3 included + 1 additional = 4 of 5 used") — sourced directly from `decideBusinessSlotCapacity()`, never recomputed independently by the UI.
- Inspect available features per Business.
- Enable/disable a Business-level feature toggle, only for a feature the Workspace is currently effectively entitled to (§16).

**Explicitly not permitted:** an ordinary Workspace member (any role) granting themselves or anyone else a plan entitlement, plan status change, override, complimentary status, or additional Business slot — those remain exclusively platform-admin actions, gated by the new admin permission keys layered on the unmodified `EnsureUserIsAdministrator` boundary.

---

## 23. Failure modes

- Unknown `feature_key` reaching any write path (catalog mapping, override, toggle) → rejected at the application layer before persistence, never silently accepted (§11).
- An `allow`-override write for a feature `PlatformFeatureRegistry::isAvailable()` reports `false` for → rejected at the application layer (§11, §15) — a `deny` override for the same feature is harmless and permitted.
- Attempting to create a Business toggle for a feature the Workspace is not currently entitled to → rejected; there is nothing to disable (§16).
- Attempting to set `is_complimentary = true` without a `reason` → validation failure (§15).
- Attempting `changePlanStatus()` without a non-null actor or a non-empty reason → validation failure (§18) — both are mandatory for this specific mutation.
- Attempting to create/change a **non-complimentary** assignment against a catalog row with a null `price`/`currency_id` → rejected with `UndefinedPlanPricingException` (§12.5) — never silently represented.
- Attempting to **increase** `additional_business_slots` for a **non-complimentary** assignment (directly or via `changePlan()`'s Agency→Core/Growth path) against a catalog row missing `price`, `currency_id`, or `additional_business_slot_price_ratio` → rejected with `UndefinedPlanPricingException` (§12.5).
- Attempting to **decrease** `additional_business_slots` for any assignment, regardless of catalog pricing state → always succeeds (§12.5).
- Attempting to clear `price` or `currency_id` on a catalog row still referenced by a **non-complimentary** assignment → rejected with `PlanCatalogPricingInUseException` (§12.5).
- Attempting to set exactly one of `price`/`currency_id` to null while leaving the other populated → rejected at the application layer; the pair is always both-null or both-populated (§12.5).
- Attempting `setAdditionalBusinessSlots()`/`changePlan()`'s optional allocation with a value outside `{0,1,2}` for Core/Growth, or any non-zero value for Agency → validation failure (§17/§17.1).
- A Workspace with no `workspace_plan_assignments` row reaching `EntitlementManager::decide()`/`decideBusinessSlotCapacity()` (should be structurally impossible post-backfill, §25, but checked defensively like RFC-003 §14.1's "workspace is null" case) → denies every feature-gated and Business-creation action with `workspace_plan_unassigned`, never silently treated as entitled-to-everything.
- Concurrent Business-count-increasing operations racing a destination Workspace's last available slot — whether both are creations, both cross-Workspace reassignments, or one of each (§17.2) — fail closed for whichever loses the race, with the applicable typed exception (§17), never a silent over-allocation.
- A dangling `workspace_plan_features`/`workspace_entitlement_overrides`/`business_feature_toggles` row referencing a deleted `PlatformFeature` case (a case removed in a future code change) → treated as `platform_feature_unknown` (denied) at read time, even though a stored row exists — code-registry validity is re-checked at read time, not only at write time.
- A `PlatformFeatureRegistry` entry changed from `Available` to `Planned` in a future code change (a capability temporarily disabled/rolled back, or found on closer inspection never to have been genuinely available) → every existing plan mapping/override referencing it immediately denies with `platform_feature_unavailable`, with no data migration required.

---

## 24. Backward compatibility

- No RFC-001, RFC-002, or RFC-003 table, column, route, controller, or view is dropped, renamed, or altered in a breaking way, **except** `WorkspaceFirstBusinessInput` and `createWorkspace()`'s optional third parameter, retired in this revision as confirmed-unused capability (§17.5) — every actual production caller of `createWorkspace()` is unaffected, since none ever supplied it.
- **Corrected in v1.3.** Every Business-count-increasing operation gains exactly the one additive capacity assertion its own existing transaction needs, at the point its destination Workspace is already locked and before its count-increasing write (§17) — `WorkspaceManager::createBusinessInWorkspace()` (§17), `WorkspaceManager::reassignBusiness()` for a real cross-Workspace move (§17.2), and the legacy onboarding path (§17.3/§17.4). Each operation's existing signature, transaction boundary, authority rules, and every other behavior are otherwise unchanged — the legacy onboarding path in particular gains no new authority requirement it did not already have.
- The legacy `plans`/`subscriptions` stack (§6) is untouched — every existing SMS-plan/subscription behavior continues exactly as today.
- Every existing Workspace gains a deterministic plan assignment via the Milestone-1 backfill (§25) — no existing Workspace, Business, or member loses any access as a direct result of this RFC shipping.
- `config/permissions.php` gains new keys under a new category, never colliding with or reusing the legacy `Plan` category's keys (§5 finding 5, §22).
- No pre-RFC-004 capability that customers could already legitimately reach is silently gated away by Milestone 3 without a compatibility pass (§26) — and no capability is gated at all unless direct repository evidence, not product intent, confirms it exists (§5 finding 9, §11, §26).

---

## 25. Migration, backfill and upgrade safety

### 25.1 Conceptual migration ordering

Eight migrations, DDL and data kept separate exactly as RFC-003 §10.1 established (MySQL DDL is not part of the surrounding transaction the way DML is):

- **A.** Schema for `workspace_plan_catalog` — DDL only.
- **B.** Schema for `workspace_plan_features` — DDL only.
- **C.** Schema for `workspace_plan_assignments` — DDL only.
- **D.** Schema for `workspace_entitlement_overrides` — DDL only.
- **E.** Schema for `business_feature_toggles` — DDL only.
- **F.** Schema for `workspace_entitlement_transitions` — DDL only, including `from_status`/`to_status` (§10.4).
- **G.** Deterministic catalog/feature-matrix seed — data operation only. Inserts exactly the three `workspace_plan_catalog` rows (§12.3) and the nine/twelve/fifteen `workspace_plan_features` rows (§12.2).
- **H.** Deterministic existing-Workspace assignment backfill — data operation only, described in full below.

### 25.2 Backfill action

A versioned action, `App\Library\Entitlement\Migration\WorkspaceEntitlementBackfillV1`, following the exact `WorkspaceBackfillV1` precedent (RFC-003 §10.3):

- **Query-builder-only** — no Eloquent model, no model event, no `HasUid`-style creation hook.
- **Bounded/chunked traversal** over Workspaces lacking a `workspace_plan_assignments` row.
- **Per-Workspace transaction** — each Workspace's assignment-plus-audit-row write commits or rolls back atomically and independently of every other Workspace, mirroring `WorkspaceBackfillV1`'s per-`customer_id`-group transaction boundary.
- **Idempotent, safe under partial rerun** — a Workspace that already has an assignment row is skipped entirely (no duplicate assignment, no duplicate `plan_assigned` transition row); re-running after a partial failure resumes exactly where the previous attempt left off.
- **Final zero-unassigned-Workspace assertion** — at the end of a run, `SELECT COUNT(*) FROM workspaces LEFT JOIN workspace_plan_assignments ... WHERE workspace_plan_assignments.id IS NULL` must be zero; a non-zero count is a failed run, surfaced with the exact remaining count, mirroring `WorkspaceBackfillV1`'s null-count failure discipline exactly (RFC-003 §10.4).
- **Deterministic failure behavior** — no partial/ambiguous state; a failed group's transaction rolls back completely, leaving that Workspace exactly as it was before the run (still unassigned, safely re-processable).
- **No Eloquent events dispatched from the migration/backfill action itself** — matching `WorkspaceBackfillV1`'s explicit constraint (RFC-003 §10.3); ordinary event dispatch (§21) is a concern of the *manager* layer for actor-initiated mutations, not of this one-time migration action.
- **Migration rerun/concurrency tests required** (§27) — mirroring `WorkspaceBackfillV1ConcurrencyTest`'s exact pattern: two simultaneous backfill attempts must not create two assignment rows for the same Workspace (serialized via a row-level lock on the Workspace, or the unique `workspace_id` constraint on `workspace_plan_assignments` caught and treated as "already assigned," matching RFC-003 §12.2's documented idempotent-create pattern).

### 25.3 Backfill assignment values

For every pre-existing Workspace lacking an assignment:

- Tier: **Core**.
- `status`: **active**.
- `is_complimentary`: **true**.
- `complimentary_reason`: a fixed, explicit string (e.g. `"Pre-RFC-004 Workspace — grandfathered complimentary assignment during Milestone 1 backfill"`).
- `complimentary_granted_by_user_id`: **null** — a system migration, not an admin actor (§10.3).
- `complimentary_granted_at`: set to the backfill's own execution timestamp.
- A `workspace_entitlement_transitions` row is written with `transition_type = plan_assigned`, `actor_user_id = null`, `to_plan_catalog_id` = the Core catalog row's id, `reason` = the same fixed string above.
- **No existing Business is deleted, deactivated, or hidden** at any point during this process (§8, §13).

### 25.4 Additional-slot backfill, preserving existing Business counts

`additional_business_slots` is computed deterministically per Workspace from its **existing** Business-row count at backfill time (`count(businesses WHERE workspace_id = :id)`, the same query §13/§17 use — every row, regardless of `status`):

| Existing Business count | `additional_business_slots` |
|---|---|
| ≤ 3 | `0` |
| 4 | `1` |
| ≥ 5 | `2` |

**A pre-existing Workspace with more than 5 Businesses (a state RFC-003's own unlimited-slot era made entirely possible) is not treated as corrupt data.** The backfill assigns `additional_business_slots = 2` (the representable maximum for Core/Growth) and **keeps every existing Business** — none is deleted, deactivated, or hidden. This Workspace's operational state is described as **grandfathered-over-capacity**: `decideBusinessSlotCapacity()` (§17) correctly reports `currentCount ≥ effectiveCapacity` and `effectiveCapacity == business_slot_max`, so any *new* Business-creation attempt is denied with `business_slot_limit_exceeded` from that point forward, until the Workspace is either upgraded to Agency (unlimited), granted a further additional-slot allocation where the tier's maximum allows it, or its own Business-**row** count later falls to or below its entitled capacity. **Corrected in this revision: the only existing mechanism by which a Workspace's Business-row count can fall is an authorized Business reassignment to a different Workspace (RFC-003 §16.2)** — v1.1 incorrectly listed "reassignment/deactivation" as interchangeable recovery paths, contradicting §13's own `COUNT(all Business rows, regardless of status)` slot-count definition. **Deactivating a Business does not reduce this count, since the row still belongs to the Workspace**, and RFC-003 provides no Business-deletion mechanism for RFC-004 to rely on or invent (§3, §8). **The migration itself still succeeds** for this Workspace — over-capacity grandfathered state is an expected, deterministic, non-blocking outcome of the backfill, not a failure condition (§25.2's "final zero-unassigned assertion" is about *assignment* existing, not about every Workspace being under capacity). The identical grandfathered-over-capacity posture, and the identical corrected recovery rule, applies equally to a Workspace that reaches over-capacity via an admin-initiated Agency→Core/Growth downgrade rather than via this one-time backfill (§17.1).

This preserves every existing Business while establishing deterministic future enforcement — exactly the same "backfill never removes access, enforcement applies only going forward" posture RFC-003 §10's own backfill used for the `businesses.workspace_id` invariant. Every allocated slot from this backfill, including for a Workspace already at 4 or 5 Businesses, is complimentary (§25.3) — per §13's clarified complimentary-slot semantics, this represents honored capacity, never unpaid debt for RFC-005 to later collect against.

---

## 26. Existing-capability compatibility

**A backfilled Core assignment, or a newly introduced feature gate, must never silently remove access to a capability that existed and was reachable before RFC-004 introduced its plan gate.** This is a general rule that governs how Milestone 3 (§29) may introduce any new gate over a pre-existing capability:

**Before M3 gates any pre-RFC-004 capability that is not part of Core's baseline packaging** (§12.2), M3 must first establish a deterministic compatibility predicate — provable from actual repository state (usage history, existing feature flags, existing customer records, or an equivalent concrete signal) — identifying which Workspaces could legitimately use that capability before the gate existed. For every Workspace the predicate identifies, M3 must create a reasoned Workspace `allow` override (§15) for that feature, using the normal audited override mechanism, before or atomically with enabling the new gate for everyone else. **If no reliable compatibility predicate can be proven from repository state, M3 must not introduce that capability's gate in RFC-004 at all** — the gap is reported and the gate deferred to a later, separately reviewed milestone, rather than risking an unexpected access removal.

**Prospect Outreach, conditionally** (corrected in this revision — §5 finding 9, §4, §11): it is packaged as Agency-only (§12.2), and product intent treats it as an Opportunity-Engine-adjacent capability with pre-existing safety rules (campaign-membership-based enrollment and similar) to be preserved, not redesigned, if it exists. **This RFC's own inspection did not directly confirm an executable Prospect Outreach implementation in this repository** — its `PlatformFeatureRegistry` entry is therefore provisionally `Planned` (§11), not `Available`. Exactly two outcomes at Milestone 1/3, decided strictly by repository evidence found at that time, never by this RFC's product-intent framing alone:

- **If M1's direct inspection confirms an executable implementation exists and is reachable today with no Workspace/plan restriction**, it is promoted to `Available`, and M3 must then determine which Workspaces have actually used or could legitimately have used it, granting each an explicit `allow` override before or with enabling the Agency-only gate — exactly this section's general rule, applied.
- **If M1 cannot prove an executable implementation exists in this repository**, `ProspectOutreach` remains `Planned`/unavailable, and **M3 does not introduce any gate for it at all** — there is no pre-existing access to protect, and §14's `platform_feature_unavailable` check already denies it deterministically without any override machinery being invoked. A Prospect Outreach implementation existing **outside this repository**, with an intent to migrate it in later, does not change this outcome — availability is always this repository's own current, directly-inspected code (§11).

**White-label follows the identical evidence-first rule** if, and only if, M3's own repository inspection finds a real existing bounded capability to gate (§32's open item). Neither case is a permanent special bypass mechanism: both use the ordinary, fully audited Workspace-override path (§10.5, §15, §21) — a normal, inspectable exception, not a parallel code path, and neither is gated at all without first proving the underlying implementation actually exists.

---

## 27. Test strategy

Mirroring RFC-003's `tests/Unit/{Domain}` + `tests/Feature/{Domain}` pairing, under a new `Entitlement` domain directory (`tests/Unit/Entitlement`, `tests/Feature/Entitlement`) so RFC-004's suite is independently targetable exactly as RFC-003's regression commands targeted `tests/*/Workspace`.

Concrete groups (no fabricated counts — exact test methods are each bounded milestone's own responsibility to write and report, per RFC-003's established discipline):

- **Six-table schema invariants:** all six tables (§10) exist with their exact constraints, including `workspace_entitlement_transitions.from_status`/`to_status`; no fifth-table miscount anywhere in fixtures/factories.
- **Plan catalog invariants:** exactly three seeded tiers; unique `tier`; catalog price/currency/ratio changes never retroactively alter an existing assignment's already-decided entitlement; a structurally-seeded tier with null price/currency is valid on its own (§12.5).
- **Feature registry invariants:** every `PlatformFeature` case is a valid `feature_key` everywhere it's accepted; an unknown key is rejected at every write path.
- **Known-vs-available feature distinction:** a known-but-`Planned` feature is denied with `platform_feature_unavailable` even when a plan mapping includes it; an `allow`-override write for a `Planned` feature is rejected; a `deny`-override write for a `Planned` feature is permitted (redundant but harmless).
- **`ProspectOutreach` availability is evidence-based, never assumed:** a direct test asserting `PlatformFeatureRegistry::isAvailable(PlatformFeature::ProspectOutreach->value)` reflects whatever Milestone 1's own repository inspection actually found — not a hard-coded expectation carried over from this RFC's illustrative table.
- **Plan-feature mappings:** the seeded matrix (§12.2) matches exactly; unique `(catalog_id, feature_key)`; `WorkspacePlanFeatureRepository` boundary — no direct table access outside it.
- **Effective entitlement precedence:** the full §14 eight-step algorithm, in order, including the "never dereference assignment before the unassigned check" ordering.
- **Workspace override allow/deny/inherit:** allow grants outside the base plan (for an available feature); deny overrides an included feature; deleting an override reverts to plan-mapping-derived state exactly; an override can never bypass `platform_feature_unavailable`.
- **Business toggle cannot escalate entitlement:** attempting to create a toggle for a not-entitled feature is rejected; a toggle never appears as a "grant."
- **Disabled/rolled-back platform feature wins:** removing or demoting a `PlatformFeature`/availability entry denies every stored row referencing it, even without a data migration.
- **Plan status change event and durable audit (added in this revision):** `changePlanStatus()` requires actor and reason, rejecting both omissions; dispatches `WorkspacePlanStatusChanged`; writes a `plan_status_changed` transition with correct `from_status`/`to_status` for every one of the `active`↔`inactive`↔`suspended` transitions.
- **Complimentary/status precedence, corrected:** `suspended` denies even when `is_complimentary = true`; `inactive` denies even when `is_complimentary = true`; `active` + complimentary allows without requiring catalog price/currency; `active` + non-complimentary requires catalog price/currency to have been set at assignment time.
- **Core ↔ Growth preserves additional slots (added in this revision):** `changePlan()` between Core and Growth in either direction leaves `additional_business_slots` numerically unchanged, with no `additional_business_slots_changed` transition written.
- **Core/Growth → Agency resets additional slots to zero atomically (added in this revision):** a single `changePlan()` call writes both a `plan_changed` and an `additional_business_slots_changed` transition in the same transaction; `additional_business_slots` is `0` afterward regardless of its prior value.
- **Agency → Core/Growth defaults additional slots to zero (added in this revision):** `changePlan()` with no explicit `$additionalBusinessSlots` argument results in `additional_business_slots = 0`; passing `1`/`2` explicitly allocates that many, subject to the pricing/allocation invariant.
- **Downgrade with existing over-capacity Businesses (added in this revision):** an Agency Workspace with 7 Businesses downgraded to Core keeps every Business, ends with `additional_business_slots = 0` (or the explicitly-authorized value), and immediately denies further Business creation.
- **Paid slot increase fails when pricing is undefined (added in this revision):** `setAdditionalBusinessSlots()`/`changePlan()`'s allocation path rejected with `UndefinedPlanPricingException` for a non-complimentary assignment against a catalog row missing price/currency/ratio.
- **Slot decrease succeeds even if pricing becomes undefined (added in this revision):** a decrease is never blocked, even against a catalog row whose pricing was subsequently cleared.
- **Price/currency both-null-or-both-populated invariant (added in this revision):** an attempt to set exactly one of the pair is rejected.
- **Catalog pricing cannot be cleared underneath a non-complimentary assignment (added in this revision):** rejected with `PlanCatalogPricingInUseException`; permitted once no non-complimentary assignment references the row, and always permitted while only complimentary assignments reference it.
- **Complimentary grandfathered extra slots do not represent unpaid recurring slot debt (added in this revision):** a direct assertion that a complimentary assignment's `additional_business_slots > 0` never surfaces as any kind of billing obligation anywhere in RFC-004's own data model — there is no column or flag capable of representing one.
- **Business deactivation alone does not lower slot count (added in this revision):** deactivating a Business under a Workspace already at effective capacity leaves `decideBusinessSlotCapacity()`'s `currentBusinessCount` and `allowed` result unchanged; only a reassignment to a different Workspace changes it.
- **Core/Growth/Agency feature matrix:** the exact §12.2 table, both directions.
- **Core/Growth included-slot baseline:** the 1st–3rd Business creation succeeds with `additional_business_slots = 0` and no allocation.
- **Core/Growth fourth Business denied without allocation:** `additional_business_slots = 0`, 4th creation attempt fails with `business_slot_allocation_required`.
- **Core/Growth fourth succeeds with one allocation:** `additional_business_slots = 1` permits exactly the 4th.
- **Core/Growth fifth denied with only one allocation:** `additional_business_slots = 1`, 5th creation attempt fails with `business_slot_allocation_required`.
- **Core/Growth fifth succeeds with two allocations:** `additional_business_slots = 2` permits the 5th.
- **Core/Growth sixth always denied:** regardless of allocation value, `business_slot_limit_exceeded`; requires Agency.
- **0.5000 catalog ratio:** seeded exactly, read by `EntitlementManager`, never hard-coded elsewhere.
- **Agency unlimited behavior:** creation never denied by slot count regardless of existing count; `additional_business_slots` remains `0` and is never consulted.
- **Additional-slot allocation audit:** `setAdditionalBusinessSlots()` dispatches `WorkspaceAdditionalBusinessSlotsChanged` and writes an `additional_business_slots_changed` transition row with correct from/to values; rejects out-of-range values and any non-zero value for Agency.
- **Workspace-override durable audit:** every override create/change/revert writes the correct `entitlement_override_*` transition row with correct `feature_key`/`from_override_state`/`to_override_state`.
- **Concurrent final-slot creation safety:** two simultaneous creation attempts for the last available effective-capacity slot — exactly one succeeds, the other fails closed, no over-allocation.
- **Workspace/Business authorization remains independent:** a fully-entitled-but-unauthorized user is still denied by RFC-003 §14.1 before entitlement is even reached; an authorized-but-unentitled user is denied by §14 despite passing RFC-003's own check.
- **Cross-Workspace isolation:** an entitlement/override/toggle/allocation/status change for one Workspace never leaks into another Workspace's decision.
- **Platform-admin boundary:** the new admin surfaces (§22) require both `access backend` (existing blanket gate) and the new permission keys independently — mirroring `AdminWorkspaceControllerTest`'s exact non-admin-forged-permission defense-in-depth test.
- **Legacy Plan/Subscription compatibility:** the legacy suite is unaffected by this RFC's migrations/models.
- **Backfill correctness:** additional-slot counts derived correctly for 0/1/2 cases; a pre-existing Workspace with more than 5 Businesses survives the backfill with every Business intact, `additional_business_slots = 2`, and grandfathered-over-capacity behavior; post-backfill new-Business-creation attempts correctly fail for an over-capacity grandfathered Workspace; migration idempotence and partial-rerun safety; concurrent backfill-run safety (no duplicate assignment).
- **Existing-capability compatibility rule:** if and only if M1 finds `ProspectOutreach` `Available`, M3's compatibility-override pass is directly tested — a Workspace identified by the compatibility predicate retains access via its override after the Agency-only gate goes live; a Workspace not identified by the predicate is correctly gated. If M1 finds it `Planned`, a test instead asserts no gate/override machinery for it is introduced at all.
- **RFC-001 regression:** `tests/Unit/Business tests/Feature/Business` unaffected.
- **RFC-002 regression, where affected:** `tests/Unit/Opportunity tests/Feature/Opportunity` — specifically Prospect Outreach gating if and only if it is found `Available`; everything else unaffected.
- **RFC-003 regression:** `tests/Unit/Workspace tests/Feature/Workspace` unaffected, including the one additive `createBusinessInWorkspace()` call site not breaking any existing Business-creation test.

---

## 28. Acceptance criteria

- All **six** new tables (§10) exist with exactly the specified columns, constraints, and `restrictOnDelete()` foreign keys — including `workspace_entitlement_transitions.from_status`/`to_status` — and no native DB `ENUM` column exists anywhere in this RFC's schema.
- `workspace_plan_catalog` is seeded with exactly `core`/`growth`/`agency`, matching §12.1's slot policy and §12.2's feature matrix exactly, including `additional_business_slot_price_ratio = 0.5000` for Core/Growth and `null` for Agency.
- `PlatformFeatureRegistry` exists and is consulted by `EntitlementManager::decide()` strictly before any plan-mapping/override resolution; no plan mapping or override can make an unavailable feature executable, verified by a direct test; `ProspectOutreach`'s seeded availability value reflects Milestone 1's own direct repository evidence, not this RFC's illustrative default.
- Every pre-existing RFC-003 Workspace has exactly one `workspace_plan_assignments` row after the Milestone-1 backfill (§25), with `status = active`, `is_complimentary = true`, and a correctly-derived `additional_business_slots` value, and zero Workspaces are left unassigned.
- No pre-existing Workspace's Business count was altered, and no existing Business was deleted/deactivated/hidden, by the backfill (§25.4), verified directly.
- `EntitlementManager::decide()` implements §14's eight-step algorithm exactly, including denial-reason-key stability across all nine keys.
- `EntitlementManager::decideBusinessSlotCapacity()`/`assertCanCreateAnotherBusiness()` correctly enforces the allocation-gated 3/4/5 model (§13/§17) — a 4th or 5th Business never succeeds without the corresponding allocation — and is concurrency-safe under a forced-race test.
- `EntitlementManager::changePlan()` correctly normalizes `additional_business_slots` for all three tier-change directions (§17.1) inside a single transaction, and `EntitlementManager::changePlanStatus()` requires actor/reason and durably audits every status change (§18) — both verified directly.
- The pricing/allocation invariant (§12.5) is enforced exactly as specified for plan assignment, slot increase, slot decrease, and catalog price/currency mutation, including the complimentary carve-out and the both-null-or-both-populated pairing — verified directly, not merely believed.
- Every Workspace-override, additional-slot-allocation, and plan-status mutation writes a correct `workspace_entitlement_transitions` row (§10.4/§21), verified directly, not merely believed from event dispatch.
- No RFC-001/RFC-002/RFC-003 test regresses; the legacy Plan/Subscription suite is unaffected.
- No direct entitlement-table query exists outside `EntitlementManager` and its six repositories (§20) — verified by code search, not merely believed.
- `config/permissions.php`'s new keys use a distinct category from the legacy `Plan` category (§5 finding 5).
- No `Agency` model or `businesses.agency_id` column exists anywhere (§3, RFC-003 §4/§27 precedent preserved).
- Any M3-introduced gate over a pre-existing capability is accompanied by a verified compatibility-override pass per §26, is based on direct repository evidence of an executable implementation (never product intent alone, §5 finding 9), and shows no unexplained access-removal regression.

---

## 29. Implementation milestone plan

Following RFC-003's exact governance discipline: **RFC design PR → bounded implementation milestones, each its own contract → PR → human merge, no automatic next-milestone start → final conformance/deployment/tag milestone.** No target-marker PR, no inert implementation PR, no separate authorization PR at any step — the simplified workflow RFC-003 Milestone 5/6 already established and proved out.

**M1 — Domain/schema/catalog/feature-registry/Workspace-plan foundation.** All **six** tables (§10, including `from_status`/`to_status`), all new enums (`WorkspacePlanTier`, `WorkspacePlanAssignmentStatus`, `WorkspaceEntitlementOverrideState`, `WorkspaceEntitlementTransitionType` — **nine** cases — `PlatformFeature`, `PlatformFeatureAvailability`), the `PlatformFeatureRegistry` (§11, with every per-key availability value — `ProspectOutreach` explicitly included — re-verified against real repository modules at this milestone's own implementation time, not assumed from this RFC's illustrative table), all six new repository contracts + Eloquent implementations (§20), the plan-catalog and plan-feature seed (§12.2/§25.1 steps A–G), and the versioned `WorkspaceEntitlementBackfillV1` action assigning every existing Workspace a complimentary Core assignment with correctly-derived additional-slot counts (§25). No `EntitlementManager` decision logic, no HTTP surface, no slot *enforcement* yet — pure data-layer foundation, exactly mirroring RFC-003 M1A's own scope discipline.

**M2 — Entitlement engine, plan/status/slot mutations, Workspace overrides, Business toggles, slot-capacity enforcement.** `EntitlementManager` (§20) implementing §14's full algorithm and §17's slot-decision API, the `NullUsageAuthorizationGateway` (§19), `changePlan()` with deterministic slot normalization (§17.1), `changePlanStatus()` with mandatory actor/reason and durable audit (§18), Workspace override create/revert (with durable audit, §15/§21), Business toggle create/remove, `setAdditionalBusinessSlots()` (with durable audit and the pricing/allocation invariant, §12.5/§17/§21), and — **corrected in v1.3, no longer only one call site** — the authoritative capacity assertion integrated at every one of §17's actual Business-count-increasing operations: `createBusinessInWorkspace()`'s one additive call (§17), `reassignBusiness()`'s target-capacity assertion for a real cross-Workspace move (§17.2), and the legacy onboarding path's bounded compatibility integration including the newly-auto-provisioned-Workspace initial assignment (§17.3/§17.4) — each with its own concurrency test. All events (§21) and all nine `workspace_entitlement_transitions` transition-type writes. No HTTP surface yet.

**M3 — Admin/customer surfaces + capability integration/gating + focused regression.** The minimum surfaces in §22, new permission keys, and — only where M1/M3's own direct repository evidence confirms an executable implementation exists (§5 finding 9, §11, §26; Prospect Outreach and white-label alike — neither is assumed available by this RFC's product-intent framing alone) and only after the §26 existing-capability compatibility pass is complete for it — wiring `EntitlementManager::decide()` into that existing code path. No new feature module is built in M3 merely because its entitlement key exists.

**M4 — Full conformance, deployment guide, complete regression, annotated tag.** Mirrors RFC-003 Milestone 6 exactly: an evidence-based conformance matrix against this RFC's own acceptance criteria (§28), a deployment guide grounded in the actual M1–M3 implementation, the full regression gate, and the `rfc-004-plans-and-business-feature-entitlements` annotated tag — created only after the same post-merge exact-tag-candidate regression gate RFC-003 M6 used, with explicit human authorization, never automatically.

This remains four milestones, not a six-small-governance-milestone pattern — each one bounded, each one independently stoppable and separately contracted, matching RFC-003's own "each milestone stops and reports independently" discipline. If repository evidence discovered during M1's own implementation reveals a safer split, that discovery is reported at that milestone's own contract/closure, not decided speculatively here.

---

## 30. Release and tag gate

Tagging follows the exact posture RFC-001/RFC-002/RFC-003 already established: an annotated tag is created only at the end of RFC-004's final milestone (M4), after full regression, never at the end of M1–M3. No tag is created, applied, or proposed as part of drafting or revising this RFC document.

Proposed exact tag: `rfc-004-plans-and-business-feature-entitlements`, annotated (not lightweight), verified against the `origin` remote exactly as RFC-003 M6's contract required — including re-confirming, at that future time, whichever tag-verification convention is then current, since `docs/automation/RFC-003-M6-CONTRACT.md` itself already found one historical inconsistency (`rfc-001-business-core` local-only vs. `rfc-002-opportunity-engine` pushed) worth checking against `origin` explicitly rather than assumed.

No tag is created now. No tag is created during M1–M3. No tag is created during M4's own implementation PR — only after M4's documentation PR merges, a post-merge exact-tag-candidate regression passes, and a human explicitly authorizes it.

---

## 31. Explicit RFC-005 deferrals

Deferred to RFC-005 — Business Usage Billing and Wallets (unchanged from RFC-003 §26.2, restated here as this RFC's own boundary, §19):

- Business usage wallets, balances, and the append-only usage ledger.
- Business payment methods and Workspace payer fallback; selected/default payer policy by tier.
- Reservation/release for uncertain-cost operations.
- Auto-recharge (threshold/amount, monthly caps, ability to disable).
- Low-balance notifications and zero-balance usage suspension.
- Usage webhooks and idempotency.
- Agency client rebilling.
- Actual payment collection for a Core/Growth additional Business-slot allocation (§13/§17) — RFC-004 owns the allocation quantity and its authoritative mutation; RFC-005 owns charging for it.
- Any Stripe integration change, including usage-billing-specific webhooks.

RFC-004's `UsageAuthorizationGateway` seam (§19) and its `setAdditionalBusinessSlots()`/`changePlan()` mutations (§17/§17.1) exist precisely so none of the above requires reshaping anything this RFC ships.

Also explicitly out of scope for RFC-004 itself (§3, restated): a Prospect Outreach Engine redesign; a full white-label implementation beyond gating an existing bounded capability, if one is found to already exist at M3; implementation of any SEO/Ads/AI/Calendar/Forms/Website-generation module that does not yet exist merely because its entitlement key is defined; unrelated CRM or messaging changes; any RFC-003 Workspace/Business tenancy redesign; any Business-deletion mechanism.

---

## 32. Open questions

None left genuinely undecidable from product requirements and repository evidence. Every architectural choice this RFC needed to make — legacy compatibility (§6), feature-registry identity-vs-availability architecture (§11), tier-identity-vs-catalog-data split (§12.1), the pricing/allocation invariant in full (§12.5), the allocation-gated slot model (§13), plan-status durable audit (§18), plan-change slot normalization (§17.1), override-may-grant-outside-plan-but-never-outside-availability (§15), business-toggle-can-only-narrow (§16), the corrected billing-state precedence (§18), the RFC-005 seam shape (§19), and the durable-audit scope for overrides/allocations/status changes (§21) — was resolved with an explicit recommendation and justification above.

Two items are deliberately left to the *implementation* milestones that will actually resolve them, not because they are undecidable in principle but because deciding them now would be speculative ahead of that milestone's own repository inspection, exactly as RFC-003 repeatedly deferred file-existence verification to each slice's own contract-drafting inspection:

1. **The exact per-key `PlatformFeatureRegistry` availability values** (§11) — this RFC's own table is explicitly illustrative and caveated, and in this revision defaults `ProspectOutreach` conservatively to `Planned` (§5 finding 9) rather than asserting an unverified `Available`; M1 must re-verify every value, including this one, against actual repository modules before shipping it.
2. **Whether an existing bounded white-label capability actually exists in this repository today** (§26, §29 M3) — the design rule (gate an existing capability, don't build a new one; and if gating, first run the §26 compatibility pass) is fully decided; only the underlying fact is left to M3's own contract-drafting inspection.
