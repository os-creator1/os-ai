# Implementation Contract 20 — Niche Blueprint System (versioned installation)

**Status:** Planning contract only. Does not authorize implementation.
Contracts 1–14 (Workspace/Agency tenancy migration) are complete on `main`
as of this contract's writing (`30ad21c7`); this slice reuses the
Business/Location scoping and the `EntitlementManager` authority they
established and does not reopen either. Six independently mergeable
sub-slices (§12/§18, A–F) implement this contract in dependency order;
**no sub-slice below may start without its own separate, explicit human
authorization**, matching this repository's route-3 governance
(`CLAUDE.md`).

This contract's own correctness bar is high in exactly three places, each
stated explicitly rather than asserted away: §6's publish-time validation
(which is what makes "never create executable unavailable features"
mechanical rather than aspirational), §7's per-component transaction
boundary and partial-failure rule, and §5.4's installation record — the
single row whose existence is the entire "never silently update or
reactivate" guarantee.

## 1. Objective

Design and, across six dependency-ordered sub-slices, build the V1 Niche
Blueprint System: **one canonical, platform-owned, versioned niche
Blueprint per niche**, whose individual **components** each declare their
own required entitlement, installed into a Business as **rows the Business
owns outright** — filtered to what that Business's plan entitles at the
moment of installation, stamped with version/provenance, and never
afterwards reached back into by a platform-side change.

This contract builds the Blueprint **authority, schema, versioning,
entitlement filter, installation engine and the first component adapter**.
It does not build the target modules a later adapter would install into
(Calendar, Packages, Proposals, Reviews, SEO) — §3.4 proves which of those
exist on `main` at all, and §11 states the rule that makes each future
adapter additive and independently mergeable.

## 2. Governing authority

- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` **§22 (Niche Blueprint
  System)** — the primary product authority, quoted in full in §3.1.
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` **§6 (Signup and
  Provisioning)** — the *only* document that states **when** an initial
  installation happens and **what must already exist** when it does:
  *"a hidden Workspace is created holding exactly one Business (Addendum
  §1) with its Primary Location (§4); the niche selection installs that
  niche's **Blueprint** (§22), filtered to what the chosen plan entitles
  (§21); a setup checklist is seeded on Home from whatever the Blueprint
  and plan leave unconfigured (§8)."* This is load-bearing for §7's
  ordering rule: plan selection precedes installation in the canonical
  flow, so the entitlement filter always has an assigned plan to read.
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` **§21 (Plan Model)** — the
  capability matrix carries one row that is *directly* this slice's
  concern: **"Full Photo Booth growth blueprint depth | | ✓ | ✓"** (Growth
  and Agency, not Core). This is the single clearest statement in any
  document that Core and Growth receive **the same Blueprint at different
  depth**, not two different Blueprints — i.e. the locked one-canonical-
  Blueprint rule expressed as a commercial fact (§6.2).
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` **§30 (Platform Owner)** —
  names **two** distinct sidebar surfaces, *"**Niche Blueprints** and
  **Template Library** manage the platform-level Blueprint catalog (§22)
  and its versioning."* §12.F builds these.
- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` **§9 (Opportunities)** —
  the Photo Booth default pipeline is explicitly *"Blueprint-installed,
  §22"*, which makes the CRM pipeline adapter (§12.D) the first real
  component and fixes its exact content (§5.5).
- `docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md` **§16 (Niche blueprint
  / entitlements)** — the architecture-locked authority, quoted in full in
  §3.1. Per the Addendum's own §0 precedence rule it outranks any older
  RFC on this subject.
- `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md` **row 21** — classes
  this domain PRODUCT-LOCKED, "NOT YET IMPLEMENTED / evidence not
  re-verified", with the instruction: *"Verify existing niche/template
  installation code (if any) against §22's version/provenance/no-silent-
  update rules before reuse."* §3 is that verification, performed
  mechanically and reported in full — including the one place existing
  code is reused verbatim (§3.2) and the three places it is provably
  insufficient (§3.3).
- `docs/product/V1-ACCEPTANCE-MATRIX.md` — **two** rows govern this slice:
  the **"Manage niche Blueprints / Template Library"** row (Platform scope,
  Global, **Platform Administrator**, "Blueprint version/provenance (§22)",
  safety rule *"Update must never silently reactivate components in an
  existing Business (Addendum §16)"*, acceptance *"A new Blueprint version
  is published without touching any live Business until it opts in"*), and
  the **"Sign up"** row's acceptance language *"A prospective owner
  reaches Home with a Primary Location and installed niche Blueprint
  without being blocked by optional setup"*.
- `docs/product/V1-IMPLEMENTATION-ROADMAP.md` — "Slices 15–18" is headed
  *"Independent product modules (Blueprint §12, §14, §15, §17, §18, §22,
  §23)"*, and the wave table's **"Others"** row reads *"Continue any
  remaining product modules (§19 messaging depth, **§22 niche blueprint
  versioning**, §23 AI COO)"*. §22 is therefore explicitly a named,
  scheduled, independent product module — **but it has no numbered row in
  the Slices 15–18 table**, which is precisely why this contract is
  numbered 20 rather than slotted into that table (§16).
- `docs/rfcs/RFC-004` §11/§12 — a `Planned` `PlatformFeature` must never
  become customer-executable before its registry entry is flipped to
  `Available`. This slice does not merely respect that rule; §6.3 makes
  it the Blueprint installer's own fail-closed behaviour.

**No document states any further requirement.** In particular, no document
authorizes Agency-authored Blueprints, per-plan Blueprint variants, or
automatic uninstallation on downgrade — all three are explicit non-goals
with their reasoning stated (§15).

## 3. Current repository reality — recon findings (as of this contract's writing, on `main` @ `30ad21c7`)

Traceability row 21 asked for exactly this verification and recorded none.
Every claim below was produced by direct inspection of `origin/main` at
`30ad21c7b33034618f3ccba0d9098035133983ff`; paths and line numbers are
real.

### 3.1 The two normative authorities, verbatim

Addendum §16, in full — the architecture-locked text:

> There is one canonical niche blueprint — separate Core/Growth/Agency
> copies **MUST NOT** be maintained. Each component declares its required
> entitlement; only components the current plan permits are installed. On
> an upgrade, newly entitled components are surfaced for explicit user
> action to add — they **MUST NEVER** be silently installed or activated
> into an existing Business. New accounts **MAY** receive everything their
> current plan permits during initial installation.

Blueprint §22, in full — the product text:

> ```
> Platform Template Library → Niche Blueprint → entitlement-filtered
> installation → Business-owned copy
> ```
>
> Photo Booth is the first fully built niche. A Blueprint bundles: the
> default pipeline (§9), form/questionnaire definitions (§16), starter
> automations (§13), proposal/contract templates (§18), booking defaults
> (§12), review-request flows (§15), SEO defaults (§15), website questions
> and content logic (§14), package structures (§17), and custom field
> definitions (§5).
>
> Installing a Blueprint gives the Business its **own copy**, filtered to
> what its plan entitles (§21) — not a live link back to the platform
> template. Every installed Blueprint carries version/provenance metadata.
> An update to the platform Blueprint **MUST NOT** silently update or
> reactivate components inside an already-installed Business — an upgrade
> only **surfaces** newly entitled components for the owner to explicitly
> add (Addendum §16). A brand-new account **MAY** receive everything its
> chosen plan currently permits at initial installation.

These two texts agree completely and add nothing to each other except
Blueprint §22's ten-item component list (§3.4 checks each of those ten
against `main`) and its "Photo Booth is the first fully built niche"
sequencing. This contract implements exactly these obligations.

### 3.2 The existing mechanism that is genuinely reusable — `BusinessTemplate*`

**This is the significant recon finding: a working, tested, correctly-
shaped copy-never-link template mechanism already exists on `main`, and
its own docblocks say it was written as the seam this slice joins.**

| Artifact | Path | What it already provides |
|---|---|---|
| `BusinessTemplate` (final readonly) | `app/Library/Crm/Templates/BusinessTemplate.php` | `key`, **`version`**, `name`, `pipelines`. Docblock: *"It is the seam later components join — linked automation recipes, forms, website/form settings — each as another list on this object... `version` is the snapshot identity. Changing what a template contains means a new version; Businesses that received an earlier version keep exactly what they received, because nothing about them points at the template's content."* |
| `BusinessTemplateRegistry` | `app/Library/Crm/Templates/BusinessTemplateRegistry.php` | In-PHP registry; ships exactly one template, `generic` v1. Docblock: *"Niche templates (trades, salons, studios, ...) are deliberately not hard-coded yet."* Bound as a singleton at `app/Providers/AppServiceProvider.php:381`. |
| `BusinessTemplateApplier` | `app/Library/Crm/Templates/BusinessTemplateApplier.php` | **`apply()` / `applyPipelines()` / `copyPipeline()`.** Docblock: *"COPY, NEVER LINK... nothing ever reads the template's content back through them — so editing or re-versioning a template later changes what NEW applications receive, and never what an existing Business already has. IDEMPOTENT PER BLUEPRINT... The Business row is locked for the duration, which serialises two concurrent applications to one Business."* |
| `PipelineBlueprint` / `StageBlueprint` | same directory | Validated-at-construction descriptors; a malformed template fails where it is defined rather than half-way through copying. |
| Provenance columns | `database/migrations/2026_09_13_160001_create_crm_pipelines_table.php:34-36` | `template_key` `string(64)` nullable, `template_version` `unsignedInteger` nullable, `template_pipeline_key` `string(64)` nullable. Migration docblock: *"They are provenance only: the rows below are the Business's own copy, and a later change to the template never reaches back into them."* |
| `CrmStageSemanticKey` | `app/Enums/Crm/CrmStageSemanticKey.php` | One case, `NewInquiry`. Docblock, verbatim: *"A template may give its other stages keys of its own (`qualified`, ...); those are template vocabulary, stored as plain strings, and deliberately not listed here — **a niche template must not need an enum change**."* |
| Locked behaviour | `tests/Feature/Crm/CrmBusinessTemplateTest.php` | Double-application idempotency is already proven by test (lines 63-64, 108). |

**Every one of these is DIRECTLY-REUSABLE and none is modified by this
contract.** §12.D wraps `BusinessTemplateApplier` in an adapter rather
than reimplementing, extending or editing it — the "never duplicate
existing functionality" rule in `CLAUDE.md`, applied literally.

Production call sites of the applier, exhaustively (`git grep` over
`app/` and `config/`): exactly one — `CrmPipelineService`
(`app/Library/Crm/CrmPipelineService.php:37-38`, constructor-injected),
reached from exactly one controller action,
`CrmPipelinesController::setup()` (`app/Http/Controllers/Customer/Business/CrmPipelinesController.php:44`).

**That call is LAZY, not provisioning-time.** A Business gets its standard
pipeline when a user first presses "Set up your pipeline", not when the
Business is created. This is the single most important behavioural fact in
this recon: there is **no installation-at-provisioning path on `main` at
all**, so §7's installation trigger is genuinely net-new and does not
conflict with, replace, or race the existing lazy path (§9).

### 3.3 Where the existing mechanism is provably insufficient — the four gaps this slice closes

`BusinessTemplate*` is the right *shape* and the wrong *scope*. Four
things Addendum §16 and Blueprint §22 require are absent from it, each
verified by reading the class in full:

1. **No entitlement declaration.** `BusinessTemplate` and
   `PipelineBlueprint` carry `key`, `version`, `name`, `stages` — and no
   feature key of any kind. Addendum §16's central clause ("Each component
   declares its required entitlement") has no representation anywhere in
   the existing mechanism.
2. **No installation record.** Idempotency is inferred by probing the
   *destination* table (`CrmPipeline::where('template_key', ...)->exists()`,
   `BusinessTemplateApplier.php:52-56`). That works only because pipelines
   are the sole component type; it cannot express "this component was
   deliberately **skipped** because the plan did not entitle it", which is
   exactly the state Addendum §16's upgrade rule must read back later.
3. **Code-defined, not operator-managed, and unversioned in storage.** A
   new template is a `register()` call in PHP; there is no catalog table,
   no draft/published lifecycle, no deprecation, and nothing the Platform
   Owner surfaces in Blueprint §30 could manage. `version` is an `int`
   property on an in-memory object.
4. **One component type.** `apply()` delegates to `applyPipelines()` and
   nothing else (`BusinessTemplateApplier.php:38-40`). Blueprint §22 names
   ten component categories.

### 3.4 The ten Blueprint §22 component categories, checked against `main`

Derived from the complete `app/Models` listing on `origin/main` plus
targeted greps. **A written contract for a module is not that module
existing** — Contract 16 (Packages) and Contract 17 (Proposals) are
documents, not code, and are marked accordingly.

| Blueprint §22 component | Exists on `main`? | Canonical business-owned-row seam | Adapter shippable now? |
|---|---|---|---|
| Default pipeline (§9) | **YES** — `CrmPipeline`, `CrmPipelineStage` | `BusinessTemplateApplier::copyPipeline()` / `applyPipelines()` | **YES — §12.D builds it** |
| Starter automations (§13) | **PARTIAL** — `Automation`, `AutomationWorkflow`, `AutomationWorkflowVersion`/`Node`/`Edge` exist; **no** recipe/template library (`git grep -il recipe` over `app/` + `database/` returns only the two `Crm/Templates` docblocks) | No copy-a-template-into-a-Business seam exists | No — needs an automation-template seam first |
| Forms / questionnaires (§16) | **NO generic module** — only `QuestionPack` (website guided generation). No `Form`, `FormSubmission`, `Questionnaire` model | none | No |
| Website questions / content logic (§14) | **YES (partial)** — `Website`, `WebsitePage`, `WebsiteRevision`, `WebsiteAsset`, `QuestionPack` | No template-copy seam; `QuestionPack` is *resolved* (`BusinessKnowledgeProfileManager::resolveQuestionPack()`, line 350), never copied | No |
| Custom field definitions (§5) | **PARTIAL** — `ContactsCustomField` (legacy Ultimate SMS, Contact-scoped) | no definition-copy seam | No |
| Package structures (§17) | **NO** — no `Package`, `Product`, `CatalogItem` model or migration. Contract 16 is a planning document only | none | No |
| Proposal / contract templates (§18) | **NO** — no `Proposal`, `Contract`, e-signature model. Contract 17 is not even written | none | No |
| Booking defaults (§12) | **NO** — no `Appointment`, `BookingType`, `Availability` model | none | No |
| Review-request flows (§15) | **NO** — no `Review`, `ReviewRequest`, reputation model | none | No |
| SEO defaults (§15) | **PARTIAL** — `Keywords` only; no `Citation`, no technical-SEO model | none | No |

**One of ten component categories is buildable today.** That is not a
defect in this contract's scope — it is precisely the dependency posture
§11 encodes: build the versioning/provenance/entitlement architecture now
(it is the part every future adapter depends on), ship exactly one real
adapter to prove the architecture end-to-end, and let each further adapter
arrive as its own additive, independently mergeable change once its target
module has a copy seam.

### 3.5 The niche taxonomy already exists — no new taxonomy is invented

| Artifact | Path | Detail |
|---|---|---|
| `BusinessIndustry` enum | `app/Enums/Business/BusinessIndustry.php` | Seven cases, the first being **`case PhotoBoothService = 'photo_booth_service';`** — Photo Booth is *already* a known industry value on `main` |
| `businesses.industry` | `database/migrations/2026_07_18_120001_create_businesses_table.php:15-16` | `string(64)` **NOT NULL**, plus `industry_other` nullable |
| `business_verticals` table | `database/migrations/2026_09_10_120001_create_business_verticals_table.php` | `id`, `key` `string(40)` **unique**, `display_name` `string(80)`, `broad_industry` `string(40)` nullable, `is_active` bool default true, timestamps. Docblock: *"An operator-controlled catalog table, never a PHP enum case per trade — adding a new supported vertical is a pure data-seeding operation."* |
| `BusinessVertical` model | `app/Models/BusinessVertical.php` | — |
| `business_knowledge_profiles.vertical_key` | `database/migrations/2026_09_09_120001_create_business_knowledge_profiles_table.php:23` | `string(40)` nullable, validated against `business_verticals.key` where `is_active = true` by `BusinessKnowledgeProfileManager` |

`business_verticals` is already exactly what Blueprint §22's "Platform
Template Library" needs to hang off: operator-controlled, data-seeded,
keyed, activatable. §5.1 binds a Blueprint to a vertical rather than
creating a second, parallel niche taxonomy.

### 3.6 The entitlement authority — reused, never re-derived

| Artifact | Path | Detail |
|---|---|---|
| `PlatformFeature` enum | `app/Enums/Entitlement/PlatformFeature.php` | 17 cases; stable string feature identity. Docblock: *"A case existing here is not proof the feature is implemented."* |
| `PlatformFeatureRegistry` | `app/Library/Entitlement/PlatformFeatureRegistry.php` | `isKnown()`, `isAvailable()`, `isWorkspaceScoped()`, `isBusinessScoped()`. `AVAILABILITY` currently marks **Available**: `Crm`, `Conversations`, `Automations`, `ProspectOutreach`, `WebsiteGeneration`, `GoogleBusinessProfileModule`, `AiCooBasic`. Everything else is `Planned`, including `Calendar` and `Forms`. |
| `EntitlementManager::decide()` | `app/Library/Entitlement/EntitlementManager.php:148` | `decide(Workspace, Business, string $featureKey, int $actorUserId): EntitlementDecision` — delegates to `snapshotBusinessFeatureDecisions()` (line 180), *"RFC-004 §14's exact 8-step precedence"*. **The sole Business-scoped entitlement authority.** |
| `EntitlementManager::decideAvailableFeaturesForBusiness()` | same file, line 1018 | The established **filter-a-list-by-entitlement** pattern: iterate `PlatformFeature::cases()`, skip `! isAvailable()`, skip `! isBusinessScoped()`, then `decide()` each. §6.3's filter mirrors this shape exactly. |
| `assertPlatformAdministrator()` | same file, line 2334 | `users.is_admin`, throws `AuthorizationException`. The platform-authority precedent §6.1 reuses. |

**`EntitlementDecision::$reason` is load-bearing for this slice**, and its
exact values were read from source (`snapshotBusinessFeatureDecisions()`,
lines ~195-305):

| Reason | Emitted when | This slice maps it to |
|---|---|---|
| `platform_feature_unknown` | `PlatformFeature::tryFrom()` is null | **Refused at publish** (§6.2), never reached at install |
| `platform_feature_unavailable` | `PlatformFeatureRegistry::isAvailable()` false | `skipped_unavailable` (§5.4) |
| `wrong_feature_scope` | feature is Workspace-scoped | **Refused at publish** (§6.2) |
| `workspace_plan_unassigned`, `not_entitled_by_plan`, `denied_by_workspace_override`, `disabled_for_business`, `plan_suspended`, `plan_inactive` | the six entitlement outcomes | `skipped_unentitled` (§5.4). **`workspace_plan_unassigned` is unreachable per-component** — §9.1's whole-run precondition check aborts first, writing nothing |
| `usage_unauthorized` (or gateway reason) | metered-usage gate | `skipped_unentitled` |
| *(allowed)* | — | `installed` |

This is why **no second authority is needed**: the denial reason alone
distinguishes "this module does not exist yet" from "this plan does not
include it", which is exactly the distinction Addendum §16's upgrade rule
depends on (§8).

**Verified, and relied upon in §6.3:** `$actorUserId` is accepted by
`decide()`/`snapshotBusinessFeatureDecisions()` but is **never read** by
any of the eight precedence steps — the decision is a pure function of
`(workspace, business, featureKey)`. This was confirmed by reading the
entire method body, and independently corroborated by the existence of
`decideForWorkspace(Workspace, string)` (line 330), whose own docblock
enumerates precisely which two steps need a Business and never mentions an
actor. A system-initiated installation therefore passes the Workspace
owner's real user id and receives an actor-independent answer — it does
**not** fabricate a system-actor id (§6.4).

### 3.7 Conventions this contract mirrors rather than invents

| Concern | Precedent on `main` | Reuse here |
|---|---|---|
| Immutable published version + draft/superseded lifecycle | `AutomationWorkflowVersion` + `WorkflowVersionState` (`Draft`/`Published`/`Superseded`; `isImmutable()`). Docblock: *"Once `state` leaves draft this row and its graph are immutable."* | §5.2's version lifecycle, verbatim in shape and vocabulary |
| "At most one draft / at most one published" without partial indexes | `database/migrations/2026_09_15_100002_create_automation_workflow_versions_table.php:109-124` — two **STORED generated guard columns** `draft_guard`/`published_guard` with UNIQUE indexes, because *"MySQL has no partial unique index"*; itself mirroring `2026_08_16_140001_create_payment_provider_customers_table.php` and `2026_09_12_100001_create_business_messaging_identities_table.php` | §5.2 uses the identical pattern — a third instance of an already-twice-proven convention, not a new idea |
| Composite FK so a child can only point at a parent of itself | same migration: `published_version_id` references `(id, workflow_id)`, because *"A plain foreign key... would happily accept another workflow's version"* | §5.3's component→version FK and §5.4's installation→blueprint consistency rule |
| Immutable audit row, `created_at` only | `workspace_entitlement_transitions` (`created_at` only, no `updated_at`; `actor_user_id` a plain nullable scalar with **no FK** — *"must never block a legitimate user-deletion feature"*) | §5.4's `installed_by_user_id` column shape exactly |
| Immutability as documented discipline + source-boundary test, not a DB trigger | `WebsiteRevision` (`const UPDATED_AT = null`; enforcement is "no production code path calls `update()`"), `QuestionPack` (model-event validation, docblock explicitly notes it *"cannot protect a hypothetical raw `DB::table(...)->insert()`"*) | §5.2/§5.3 claim exactly this strength and no more (§14 criterion 4) |
| Key + version family with immutable versions | `question_packs`: `unique(['key','version'])`, `is_active`, *"a new question or changed wording ships as a new `version` row under the same `key`, never an in-place edit"* | §5.2's `unique(blueprint_id, version_number)` |
| Post-creation provisioning via event + idempotent listener | `BusinessCreated` (`implements ShouldDispatchAfterCommit`, carries `businessId`, `customerId`) → `InitializeBusinessUsageProfile` (`app/Providers/EventServiceProvider.php:49-50`). Its docblock: the row *"is already committed by the time this listener runs, so an initialization failure here can never roll back that already-created Business, and this listener does not pretend otherwise"*; failure is caught, logged non-sensitively, and corrected by *"the same idempotent backfill command used for pre-existing Businesses"* | §7's installation trigger, §9's backfill command — the same event, the same failure posture, the same recovery story |
| Business-row lock serialising concurrent applications | `BusinessTemplateApplier::applyPipelines()` — `Business::query()->whereKey($business->id)->lockForUpdate()->first()` | §7's per-component lock, taken from the same line |
| Owner-restricted customer write | `EntitlementManager`'s own owner-authority checks for writes the Workspace owner alone may make (as distinct from its separate owner-or-active-admin checks) | §6.5 — the explicit Blueprint add is **owner-only**, reproducing that shape in this domain. **`config/customer-permissions.php` is deliberately NOT touched**: Blueprint §22 names the owner specifically, and a capability key would grant a wider authority than the governing sentence allows |
| Null actor for a system-initiated write | `ReconcileSlotAgreementAllocation` calls its allocation *"with both administratorActorUserId and reason left null — no system-actor or fake-administrator id of any kind"* | §6.4 / §5.4's `installed_by_user_id` NULL on system install |

## 4. Delta from current state to target

Additive build. Nothing on `main` is modified, migrated, or retired:

- `BusinessTemplate`, `BusinessTemplateRegistry`, `BusinessTemplateApplier`,
  `PipelineBlueprint`, `StageBlueprint`, `CrmPipelineService` and
  `CrmPipelinesController` are **read and called, never edited** (§17).
- The existing lazy "Set up your pipeline" path keeps working exactly as
  today, for Businesses that have no Blueprint installation and for those
  that do (§9 states the interaction precisely).
- `crm_pipelines.template_key` / `template_version` /
  `template_pipeline_key` keep their current meaning; the Blueprint's own
  provenance lives in its own installation record (§5.4), not by
  overloading those three columns with a second meaning.
- `EntitlementManager` is **called, never modified** — no new method on it,
  no second entitlement authority.
- **No new `PlatformFeature` case** is introduced. Reasoning, stated
  explicitly because a reviewer should challenge it: Blueprint §21's
  capability matrix has no "Niche Blueprint" row; what it *does* have is
  "Full Photo Booth growth blueprint depth — Growth/Agency", and that fact
  is expressed by the *components'* own `required_feature_key` values
  (§6.2), not by gating the Blueprint system itself. Adding a feature case
  for the plumbing would create a gate no document asks for and would make
  a Core account unable to receive its own entitled Blueprint components.

## 5. Canonical domain model

Four new tables. Every one is justified against `CLAUDE.md`'s "every table
must have a purpose" rule inline.

### 5.1 `niche_blueprints` — Blueprint identity (the Platform Template Library row)

*Purpose: the stable identity a signup's niche selection resolves to, and
the thing the Platform Owner's "Niche Blueprints" surface (§30) lists.
Separate from its versions because identity outlives every version.*

```
id                 bigint PK
uid                uuid UNIQUE                      -- HasUid convention (CrmPipeline, AutomationWorkflowVersion)
key                string(40) UNIQUE                -- e.g. 'photo_booth'; KEY_PATTERN as QuestionPack
display_name       string(80)                       -- matches business_verticals.display_name width
vertical_key       string(40) NULL UNIQUE           -- FK -> business_verticals(key), nullOnDelete
broad_industry     string(40) NULL                  -- a BusinessIndustry value; validated in the domain layer
is_active          boolean default true
created_at / updated_at
```

- **`vertical_key` is UNIQUE and nullable**, which is exactly the
  constraint needed: **at most one Blueprint per vertical**, so signup's
  "niche → Blueprint" resolution is deterministic by the database rather
  than by query order. MySQL permits multiple `NULL`s in a UNIQUE index,
  and that is the desired semantic — a Blueprint not yet bound to a
  vertical simply cannot be resolved by signup, which is fail-closed. This
  avoids repeating `QuestionPack`'s documented determinism problem
  (`assertNoCompetingActiveFamily`, which exists *because* its scope
  columns are nullable and therefore unconstrainable).
- `broad_industry` is the coarse fallback for a Business whose
  `business_knowledge_profiles.vertical_key` is null but whose
  `businesses.industry` is set (§7.1's resolution order).
- Validated in the domain layer against `BusinessIndustry::tryFrom()`,
  mirroring `QuestionPack::assertValid()`'s own `applies_to_industry`
  check — a string column, not an enum column, matching the existing table.

### 5.2 `niche_blueprint_versions` — the immutable published snapshot

*Purpose: Blueprint §22's "every installed Blueprint carries
version/provenance metadata" requires a version that is a durable, stable,
referenceable thing. Blueprint §30 requires "and its versioning" as a
managed surface. This is that row.*

```
id                    bigint PK
uid                   uuid UNIQUE
blueprint_id          bigint FK -> niche_blueprints(id) restrictOnDelete
version_number        unsignedInteger
state                 string(16) default 'draft'     -- draft | published | superseded
notes                 text NULL                       -- operator's release note
published_at          timestamp NULL
published_by_user_id  unsignedBigInteger NULL         -- plain scalar, NO FK (workspace_entitlement_transitions convention)
created_at / updated_at

UNIQUE (blueprint_id, version_number)                 -- question_packs' unique(key,version) convention
draft_guard      unsignedBigInteger AS (CASE WHEN state='draft'     THEN blueprint_id END) STORED, UNIQUE
published_guard  unsignedBigInteger AS (CASE WHEN state='published' THEN blueprint_id END) STORED, UNIQUE
```

- `state` reuses `WorkflowVersionState`'s exact vocabulary and semantics.
  **A dedicated enum `NicheBlueprintVersionState` is created** rather than
  reusing `App\Enums\Automation\Workflow\WorkflowVersionState` — the same
  three cases, in this slice's own namespace, because importing an
  Automations-domain enum into the Blueprint domain would couple two
  bounded contexts that have no other relationship.
- The two STORED generated guard columns give MySQL-enforced **"at most
  one draft and at most one published version per Blueprint"**, exactly as
  `automation_workflow_versions` does, for exactly the stated reason
  (MySQL has no partial unique index). Any number of `superseded` rows
  coexist because each yields `NULL` in both guards.
- **`superseded` IS the "archived/deprecated blueprint version" state.** No
  fourth state and no separate archive table: a version is retired by being
  superseded, and it is retained forever because installation records
  reference its `version_number` as provenance (§5.4).
- **Immutability:** once `state` leaves `draft`, this row and its component
  rows are never written again. Enforced by the same technique
  `WebsiteRevision` and `AutomationWorkflowVersion` use — the publishing
  service is the only writer, and a source-boundary test proves no other
  production path calls `update()`/`save()` on a non-draft version or its
  components (§13). **This contract claims no stronger guarantee than its
  own precedents actually provide**, and says so here deliberately.
- **`down()` ordering note** for the implementer: the generated columns and
  their unique indexes are added in the same migration that creates the
  table, mirroring `2026_09_15_100002`'s structure; `down()` drops the
  table, which removes them with it.

### 5.3 `niche_blueprint_components` — the component descriptors

*Purpose: Addendum §16's "Each component declares its required
entitlement" has nowhere else to live. Attached to a **version**, not to
the Blueprint, so that publishing a new version cannot mutate a component
an existing installation was made from.*

```
id                    bigint PK
blueprint_version_id  bigint FK -> niche_blueprint_versions(id) cascadeOnDelete
blueprint_id          bigint                          -- denormalised; see composite FK below
component_key         string(64)                      -- STABLE ACROSS VERSIONS. The installation identity.
component_type        string(40)                      -- adapter discriminator, e.g. 'crm_pipeline'
required_feature_key  string(64) NOT NULL             -- a known, Business-scoped PlatformFeature value. NEVER nullable.
payload               json                            -- adapter-specific descriptor
position              unsignedSmallInteger default 0
created_at / updated_at

UNIQUE (blueprint_version_id, component_key)
FOREIGN KEY (blueprint_version_id, blueprint_id)
        REFERENCES niche_blueprint_versions(id, blueprint_id)  cascadeOnDelete
```

- **`required_feature_key` is `NOT NULL`, and there is no such thing as an
  ungated Blueprint component in V1.** Addendum §16's second sentence —
  *"Each component declares its required entitlement"* — is a universal
  statement, not a default, so a nullable column would put a
  Blueprint-shaped hole straight through the invariant this slice exists
  to enforce: any component published with a `NULL` key would install into
  every Business on every plan without the entitlement authority ever
  being consulted. The column is therefore non-nullable at the database
  layer, and §6.2 rejects any draft component whose key is absent, unknown
  or Workspace-scoped.
  **If a future component has no appropriate `PlatformFeature` identity,
  it cannot be published at all** until the relevant product authority
  defines one — that is the correct outcome, not an obstacle to work
  around, and §15 records it as a deliberate non-goal rather than an
  oversight.
- **`component_key` is the durable identity and the single most important
  column in this schema.** It is what an installation record points at
  (§5.4), so "the Business already has this component" survives every
  subsequent version of the Blueprint. A version may change a component's
  `payload`, `required_feature_key` or `position`; changing its
  `component_key` means it is a **different component**, and the old one
  simply stops appearing in newer versions.
- The **composite FK** `(blueprint_version_id, blueprint_id)` reproduces
  `automation_workflows.published_version_id`'s own reasoning verbatim: a
  plain FK would prove only that the version row exists, not that it
  belongs to the Blueprint this component claims. This requires a
  matching `UNIQUE (id, blueprint_id)` on `niche_blueprint_versions`
  (InnoDB needs a leftmost index on the referenced columns), which
  §5.2's migration adds alongside its other keys.
- `cascadeOnDelete` on `blueprint_version_id` is safe and deliberate: a
  **draft** version may be deleted outright, taking its components with it.
  A published or superseded version is never deleted (§5.2's
  `restrictOnDelete` from `niche_blueprints`, and the retention rule).
- `payload` is validated at **publish** time by the component's own
  adapter (§6.2) — never at install time, and never by the installer
  itself. This is `PipelineBlueprint`'s "validated when it is built, so a
  malformed template fails where it is defined rather than half-way
  through copying" rule, moved to the publish boundary.

### 5.4 `business_blueprint_component_installations` — the installation record

*Purpose: this single table is the entire "**MUST NEVER** be silently
installed or activated" guarantee, the provenance Blueprint §22 requires,
the idempotency key, and the input to the upgrade-surfacing query. It has
no substitute: §3.3 gap 2 proves the destination-probe technique cannot
express a deliberate skip.*

```
id                          bigint PK
business_id                 bigint FK -> businesses(id) cascadeOnDelete
blueprint_id                bigint FK -> niche_blueprints(id) restrictOnDelete
component_key               string(64)
component_type              string(40)
installed_from_version      unsignedInteger              -- the version_number this decision was made from
state                       string(24)                   -- installed | skipped_unentitled | skipped_unavailable | failed
required_feature_key        string(64) NOT NULL          -- copied at decision time; provenance, never re-read as authority
decision_reason             string(48) NULL              -- the EntitlementDecision reason, verbatim (§3.6)
installed_record_type       string(64) NULL              -- e.g. 'crm_pipeline'; NULL unless state = installed
installed_record_id         unsignedBigInteger NULL      -- plain scalar, NO FK: points across bounded contexts
error_code                  string(64) NULL              -- set only when state = failed
installed_at                timestamp NULL
installed_by_user_id        unsignedBigInteger NULL      -- plain scalar, NO FK; NULL for a system install
created_at / updated_at

UNIQUE (business_id, blueprint_id, component_key)        -- THE idempotency key
INDEX  (business_id, state)                              -- the upgrade-surfacing selector
```

- **The UNIQUE key is the mechanism.** An installation run may only call an
  adapter for a `component_key` that has no row, or whose row is in state
  `failed`. A row in state `installed` is never re-run — that is what makes
  "never silently update or reactivate" true by construction rather than
  by the installer behaving.
- **Skip states are provenance, never authority.** `skipped_unentitled`
  and `skipped_unavailable` each record *why a past run declined to
  install*, and nothing more. **Neither state grants, withholds or
  suppresses visibility on any later render**, and no skip row is ever
  consulted to decide whether a component may be added. `installed` is
  the **only** state that permanently removes a component from §8.1's
  addable query; every other row — and every absent row — is re-decided by
  `EntitlementManager::decide()` on every single render.
  The practical consequence, which §8.1 states in full and §13.6 proves:
  a component recorded `skipped_unavailable` while its `PlatformFeature`
  was `Planned` is **not** surfaced while that remains true (because
  `decide()` returns `platform_feature_unavailable`), and **is** surfaced
  the moment that feature is flipped `Available` and the plan entitles it
  — with no record rewrite, migration or backfill. A stale skip row can
  never durably cut a Business off from part of its own Blueprint.
- `installed_record_id` is a plain `unsignedBigInteger` with **no foreign
  key**, deliberately: it points at a row in whichever bounded context the
  adapter wrote to (`crm_pipelines` today, others later), and a polymorphic
  FK is not expressible. This mirrors `workspace_entitlement_transitions`'
  own justification for FK-less identity columns. The consequence is stated
  honestly: **this column is provenance for a human reading an audit trail,
  not a referential guarantee**, and nothing in this slice dereferences it
  to make a decision.
- `required_feature_key` and `decision_reason` are copied in as
  **provenance of the decision at the time it was made**. The installer
  never reads them back as authority; `EntitlementManager::decide()` is
  re-asked on every later run (§8).
- **State transitions are a field rewrite, not a new row**, and there is
  deliberately **no `*_transitions` audit table**. `CLAUDE.md` requires
  every table to have a purpose, and no governing document asks for
  Blueprint-install history. The only transitions possible are
  `skipped_unentitled → installed` (an explicit user action, §8) and
  `failed → installed|failed` (a retry, §7.4); both leave `installed_at`
  and `installed_by_user_id` telling the true story of the write that
  stuck. This is a deliberate scope decision, recorded in §15 so a later
  reader can reverse it knowingly rather than discover it by surprise.

### 5.5 The first Blueprint's content — Photo Booth v1

Blueprint §9 fixes the pipeline exactly, and it is the only component
§12.D ships:

```
New Lead → Auto Follow-Up → In Contact → Proposal Sent → Invoice Sent →
Questionnaire Sent → Questionnaire Submitted → Done
```

Two constraints from `main` apply, both already satisfied:

1. `PipelineBlueprint` requires `$stages[0]->semanticKey === 'new_inquiry'`
   (`PipelineBlueprint.php:39-41`). **"New Lead" is the display name over
   semantic key `new_inquiry`** — names are the Business's to change, the
   semantic key is what the product addresses (`CrmStageSemanticKey`'s own
   docblock). No conflict, and no enum change (the enum's docblock
   explicitly forbids needing one).
2. Blueprint §9's **Lost** state and **Booked** badge are *not* pipeline
   stages ("Booked is not a pipeline stage — it is a separate state/badge")
   and are therefore **not** Blueprint components. They belong to the CRM
   domain's own model, not to this slice (§15).

The component descriptor for it:

```json
{
  "component_key": "photo_booth_default_pipeline",
  "component_type": "crm_pipeline",
  "required_feature_key": "crm",
  "payload": {
    "template_key": "photo_booth",
    "template_version": 1,
    "pipeline_key": "sales",
    "name": "Sales pipeline",
    "stages": [
      {"name": "New Lead",               "semantic_key": "new_inquiry"},
      {"name": "Auto Follow-Up",         "semantic_key": "auto_follow_up"},
      {"name": "In Contact",             "semantic_key": "in_contact"},
      {"name": "Proposal Sent",          "semantic_key": "proposal_sent"},
      {"name": "Invoice Sent",           "semantic_key": "invoice_sent"},
      {"name": "Questionnaire Sent",     "semantic_key": "questionnaire_sent"},
      {"name": "Questionnaire Submitted","semantic_key": "questionnaire_submitted"},
      {"name": "Done",                   "semantic_key": "done"}
    ]
  }
}
```

`required_feature_key: "crm"` is `PlatformFeature::Crm`, which
`PlatformFeatureRegistry` marks **`Available`** — so this component is
genuinely installable today, which is the point of choosing it first.

## 6. Authority / security contract

### 6.1 Platform side — authoring, publishing, deprecating

The Acceptance Matrix row is unambiguous: **Platform Administrator**,
Global scope. The domain service therefore calls the existing
`users.is_admin` check, reproducing `EntitlementManager::assertPlatformAdministrator()`'s
exact shape and exception (`AuthorizationException`) in the Blueprint
domain rather than calling into `EntitlementManager` for an authorization
concern that is not its own.

Every platform-side write — create Blueprint, create/edit draft version,
add/edit/remove a draft's components, publish, supersede — requires it.
There is no Agency path and no customer path to any of these (§15).

### 6.2 Publish-time validation — the fail-closed gate

**Publishing is where every correctness check happens**, because a
published version is immutable and is the thing installations are made
from. `publishVersion()` refuses, atomically and with a typed exception,
if **any** component of the draft fails any of:

1. `component_type` has **no registered adapter**. This is the rule that
   makes adapters genuinely additive (§11): you cannot publish a version
   naming a component whose installer does not exist.
2. `required_feature_key` is **absent, empty or NULL**. Every component
   must declare an entitlement (§5.3); a component that does not is
   unpublishable, and no default, fallback or implicit feature is ever
   substituted for a missing one.
3. `PlatformFeatureRegistry::isKnown($key)` is false → the
   `platform_feature_unknown` case, caught here so it can never reach an
   install.
4. `PlatformFeatureRegistry::isBusinessScoped($key)` is false → the
   `wrong_feature_scope` case. A Workspace-scoped feature (today only
   `ProspectOutreach`) can never gate a Business-installed component.
5. The adapter's own `validateDescriptor($payload)` throws — the
   `PipelineBlueprint` "fails where it is defined" rule (§5.3).
6. `component_key` is not unique within the version (also a DB constraint).

Note carefully what is **not** checked at publish: `isAvailable()`. A
component whose feature is still `Planned` is a **legitimate, publishable**
component — it is simply skipped at every installation until the feature is
flipped `Available`. That is how a Blueprint stays canonical and complete
while the platform's modules arrive over time, instead of being rewritten
each time one ships.

### 6.3 Install-time filtering — one authority, no re-derivation

For each component of the published version, in `position` order:

```
decision = EntitlementManager::decide($workspace, $business, $key, $ownerUserId)
if (decision.allowed)                                   -> INSTALL
if (decision.reason === 'platform_feature_unavailable') -> record skipped_unavailable
otherwise                                               -> record skipped_unentitled
```

**There is no bypass branch, because there is no ungated component.**
`required_feature_key` is `NOT NULL` (§5.3) and was validated as known and
Business-scoped at publish (§6.2), so `decide()` is consulted for **every
component of every installation, without exception** — no component can
reach an adapter without an allowed entitlement decision naming it. This
is the mechanical form of Addendum §16's "only components the current plan
permits are installed".

Every denial reason is recorded verbatim in `decision_reason`. The
installer **never** calls `PlatformFeatureRegistry` itself and **never**
re-derives entitlement — `decide()` already applies the availability floor
before any plan read (§3.6), so calling both would create exactly the
second, driftable authority that `decideAvailableFeaturesForBusiness()`'s
own docblock warns against.

`installed_record_type`/`installed_record_id` are populated only on
`INSTALL`. **No component is ever installed in an "activated" state that
the ordinary feature gate would not itself permit** — the adapter writes a
business-owned row, and that row remains subject to `decide()` on every
later read, exactly like any other row of its type. This is how "Blueprint
installation must never bypass module-specific authorization or create
executable unavailable features" is satisfied mechanically: the Blueprint
has no privileged write path, only the same one a customer action would
take.

### 6.4 Actor identity

- **System-initiated install** (signup, §7; backfill, §9): the job carries
  no actor. `installed_by_user_id` is written **NULL**, following the
  `ReconcileSlotAgreementAllocation` null-actor precedent — no fabricated
  system-actor or fake-administrator id of any kind.
  `EntitlementManager::decide()` requires an `int $actorUserId` by
  signature; the job passes the Workspace owner's real user id, and §3.6
  proves this is decision-neutral (no precedence step reads it). The
  contract is explicit that these are two different things: the *decision
  input* is a signature requirement satisfied with a real id, and the
  *recorded actor* is genuinely absent and recorded as such.
- **User-initiated add** (§8): `installed_by_user_id` is the acting user's
  id, and their authority is checked per §6.5.

### 6.5 Customer side — the explicit "add" action

**This action is OWNER-ONLY, and no new customer capability key is
created.**

Blueprint §22 is specific about the actor: *"an upgrade only **surfaces**
newly entitled components for the **owner** to explicitly add"*. That is
the authority, and this contract implements it literally rather than
broadening it. A capability key would hand the decision to any staff
member the owner granted it to, which is a **wider** authority than the
governing sentence describes — and inventing a permission surface no
document asks for is exactly the kind of scope creep this contract's own
§15 forbids elsewhere.

The distinction is deliberate and worth stating plainly: **deciding which
Blueprint components a Business has is an ownership decision; using what
those components produced is a staff decision.** Staff continue to use
every installed module exactly as that module's own feature permissions
already allow — installing a pipeline does not change who may work deals
in it — but staff do not change the set of installed components.

Three independent gates, none substituting for another, in this order:

1. **Tenancy** — `ResolvesBusinessTenancy`, the same trait every
   `Customer\Business\*` controller already uses. Answers "may this actor
   reach this specific Business at all".
2. **Ownership** — the actor must be the owner of the Workspace that owns
   this Business. Reproduced in this domain from the owner-check shape
   `EntitlementManager` already uses for owner-restricted writes; it is
   **not** satisfied by Workspace Admin status, by staff membership, by
   any `config/customer-permissions.php` key, or by platform-administrator
   status (a platform admin manages the Blueprint *catalog* per §6.1, and
   is never a customer's consent authority — Blueprint §30).
3. **Entitlement of the component being added** — `decide()` is re-asked
   at the moment of the add, inside the same transaction and under the same
   lock as the write (§7.3). A stale "addable" list can never install an
   unentitled component.

**`config/customer-permissions.php` is not modified by this slice.**

There is **no** customer-reachable path that creates, edits, publishes or
deprecates a Blueprint or a version — the customer surface is strictly
"list what I have, list what I could add, add one of them".

## 7. Transaction / concurrency boundary

### 7.1 Blueprint resolution (read-only, before any write)

For a Business, in order, first match wins:

1. `niche_blueprints` where `vertical_key` = the Business's
   `business_knowledge_profiles.vertical_key` and `is_active = true`.
2. `niche_blueprints` where `broad_industry` = `businesses.industry` and
   `vertical_key IS NULL` and `is_active = true`. If more than one matches,
   **resolution fails closed and installs nothing** — it does not pick one.
   (`broad_industry` is deliberately not UNIQUE: a vertical-bound Blueprint
   and a broad fallback may share an industry, and constraining it would
   forbid that legitimate shape.)
3. No match → **no installation, no records written, not an error.** A
   Business in a niche with no Blueprint is an ordinary, supported state.

### 7.2 Initial installation — per-component transactions, never one big one

The run resolves the Blueprint and its **`published`** version once, then
processes components one at a time. **Each component gets its own
transaction**, and within it, in this order:

1. `Business::query()->whereKey($id)->lockForUpdate()->first()` — the exact
   line `BusinessTemplateApplier::applyPipelines()` uses, serialising two
   concurrent runs against one Business.
2. Re-read the installation record for `(business_id, blueprint_id,
   component_key)` **inside the lock**. If it exists in state `installed`,
   `skipped_unentitled` or `skipped_unavailable` → **skip entirely**. Only
   an absent row, or a `failed` row, proceeds.
3. Evaluate the entitlement filter (§6.3).
4. On INSTALL, call the adapter; on skip, write the record and commit.
5. Write/overwrite the installation record with the outcome.

**Why per-component and not one transaction for the run:** a ten-component
install whose seventh component fails must keep the first six. One
enclosing transaction would discard all of them and leave a Business with a
Blueprint it was told it received. The UNIQUE key makes the resulting
partial state safe to resume (§7.4).

The unique constraint is the real serialisation guarantee; the row lock is
the performance-sane way to reach it. A losing concurrent run sees the
committed record at step 2 and skips.

### 7.3 The explicit add (§8) — same boundary, one component

§7.2's inner loop for exactly one `component_key`, plus the §6.5 tenancy
and **owner** checks **before** the transaction and the §6.5 entitlement
re-check **inside** it — with **one deliberate difference from §7.2, and
it is the point of this whole path**:

> §7.2 **skips** a record in `skipped_unentitled` or `skipped_unavailable`,
> because an automated run must never reverse a skip. §7.3 **proceeds and
> overwrites** it, because the owner's explicit action is precisely the
> thing Addendum §16 says *may* reverse one.

So the short-circuit rules differ by exactly one state:

| Record state under the lock | §7.2 automated run | §7.3 explicit add |
|---|---|---|
| absent | install | install |
| `failed` | retry | retry |
| `skipped_unentitled` / `skipped_unavailable` | **skip** | **install, overwriting the record** |
| `installed` | skip | **no-op success** |

If the record is already `installed` by the time the lock is held (someone
else added it concurrently), the action is a **no-op success**, not an
error — the user's intent is already satisfied. In every other case the
§6.5 entitlement re-check under the lock is what decides, so a component
that became unentitled between render and submit is refused here even
though it was listed.

### 7.4 Partial failure, rollback and retry

- An adapter that throws rolls back **its own component's transaction
  only**. Nothing it partially wrote survives.
- The run then writes a `failed` record (in its own, separate transaction,
  so the failure is durable even though the work was not) with
  `error_code`, and **continues to the next component**. One broken
  component never blocks the other nine.
- Re-running installation retries `failed` and never-attempted components,
  and touches nothing else. This is the same idempotent-backfill recovery
  story `InitializeBusinessUsageProfile` already documents.
- **There is no "uninstall" and no compensating rollback of a *successful*
  component.** Once a business-owned row exists it belongs to the Business;
  removing it is the Business's own action through that module's own UI,
  never the Blueprint's (§15).

## 8. Plan changes — upgrade and downgrade

### 8.1 Upgrade — surface, never install

On a plan upgrade **nothing is written and no job runs.** This is not an
implementation convenience; it is the literal requirement, and making
upgrade a pure read is the strongest possible form of "MUST NEVER be
silently installed or activated".

The customer surface (§12.E) answers "what could I add?" with a live query
at render time:

```sql
-- components of the Blueprint's currently published version ...
SELECT c.*
FROM niche_blueprint_components c
JOIN niche_blueprint_versions v ON v.id = c.blueprint_version_id
LEFT JOIN business_blueprint_component_installations i
       ON i.business_id  = :business_id
      AND i.blueprint_id = c.blueprint_id
      AND i.component_key = c.component_key
WHERE c.blueprint_id = :blueprint_id
  AND v.state = 'published'
  -- ... that this Business has not already installed.
  -- `installed` is the ONLY state that permanently excludes a component.
  AND (i.id IS NULL OR i.state <> 'installed')
ORDER BY c.position, c.id;
```

Each surviving row is then passed through `decide()`, and only
`allowed === true` rows are shown.

**The query deliberately filters on `installed` alone, and lets `decide()`
be the single visibility authority for everything else.** This is the one
design decision in §8 worth stating explicitly, because the obvious
alternative is wrong: a predicate that also excluded
`skipped_unavailable` would exclude it *permanently*, so a component
skipped while its feature was `Planned` could never appear even after that
feature shipped — the Business would be silently and durably cut off from
part of its own Blueprint by a stale row. Filtering on `installed` only
means **no skip decision is ever durable**; every one is re-derived from
`decide()` on every render, which is exactly the property §6.3's
single-authority rule exists to give.

This one query therefore covers all three upgrade cases uniformly:

- a component skipped as unentitled at install time → row in
  `skipped_unentitled`, now entitled → `decide()` allows → **surfaced**;
- a component skipped as unavailable → row in `skipped_unavailable`;
  while the feature is still `Planned`, `decide()` returns
  `platform_feature_unavailable` → **not surfaced**; once the feature is
  flipped `Available` and the plan entitles it → **surfaced**, with no
  migration, backfill or record rewrite needed;
- a component introduced by a **later Blueprint version**, which the
  Business has never seen → **no row at all** (`i.id IS NULL`) →
  **surfaced** if entitled.

**Implementer's note:** because a `skipped_unavailable` or
`skipped_unentitled` row can legitimately become addable later, the add
path (§7.3) must **overwrite** an existing non-`installed` record rather
than refuse on its presence. Only a record already in state `installed`
short-circuits the add into a no-op success. §13.6 and §12.E's own test
list require exactly this sequence to be proven.

### 8.2 Downgrade — nothing happens, and that is the specification

No document states a downgrade behaviour, so this contract states the only
one consistent with "Business-owned copy":

**A downgrade never uninstalls, deletes, archives or deactivates anything.**
The rows belong to the Business. A component whose feature the new plan no
longer entitles becomes unreachable through **the ordinary feature gate
that already governs it** — `decide()` denies the feature, and that
module's own controller/menu already respects that denial today. The
Blueprint system takes no action and writes nothing.

The installation record is **not** rewritten to a skipped state on
downgrade: it records what was installed and when, which remains true. If
the plan is later upgraded again, the component is already `installed` and
is correctly **not** re-offered.

## 9. Installation trigger, backfill, and the existing lazy path

### 9.1 Triggers — two events, one idempotent entry point

**Initial installation needs a Business *and* a plan assignment, and on
`main` today those two facts do not reliably arrive in one order.** The
canonical signup flow (Blueprint §6) assigns the plan first, but Agency
client provisioning (Contract 07) can create the Workspace and its
Business *before* that new Workspace has any plan assignment at all. A
single `BusinessCreated` trigger would therefore abort (correctly, per the
precondition below) and leave a legitimate, fully-entitled new account
permanently without its Blueprint until an operator noticed and ran a
command — which is not an acceptable ordinary path for a valid account.

So there are **two** triggers into **one** idempotent entry point:

| Event | Already on `main` | What the listener does |
|---|---|---|
| `App\Events\Business\BusinessCreated` (`businessId`, `customerId`) | Yes — `ShouldDispatchAfterCommit`, already has a listener registered in `EventServiceProvider` | Install for that Business. **No plan assignment → abort, zero records** (the precondition below). |
| `App\Events\Entitlement\WorkspacePlanAssigned` (`workspaceId`, `workspacePlanCatalogId`, `actorUserId`) | Yes — `ShouldDispatchAfterCommit`, dispatched by `EntitlementManager::assignFirstPlan()` and `createLegacyOnboardingCompatibilityAssignment()` | Resolve that Workspace's canonical sole Business (Addendum §1). **No Business → no-op.** Otherwise install for it. |

Both dispatch the same queued `InstallNicheBlueprintForBusiness` job, and
**duplicate invocation is harmless by construction** — §7.2's
`UNIQUE (business_id, blueprint_id, component_key)` plus the
re-read-under-lock already make a second run a no-op, so the two triggers
need no coordination, no de-duplication flag and no ordering guarantee
between them. In organic onboarding both may fire; exactly one installs
and the other finds every record already written.

This closes the race in both directions:

- **plan first, then Business** (canonical signup) → `BusinessCreated`
  installs; the earlier `WorkspacePlanAssigned` was a no-op because no
  Business existed yet;
- **Business first, then plan** (Agency client provisioning) →
  `BusinessCreated` aborts with zero records; the later
  `WorkspacePlanAssigned` performs the initial installation
  **automatically**, with no operator action.

**`WorkspacePlanChanged` is deliberately NOT a trigger.** It is a separate
event on `main`, and wiring it here would silently install newly entitled
components into an established Business on every upgrade — the single
thing Addendum §16 forbids outright. Upgrade stays pure-read surfacing
(§8.1); downgrade stays inert (§8.2). §13.5 proves both.

Each listener is deliberately modelled on `InitializeBusinessUsageProfile`'s
documented posture, for the same reasons it states:

- the Business row is already committed, so a failure here can never roll
  back the Business, and this listener does not pretend otherwise;
- failure is caught and logged non-sensitively, never propagated into the
  signup request;
- recovery is the same idempotent re-run used for pre-existing Businesses.

**Ordering against plan assignment — a precondition, not a per-component
outcome.** Blueprint §6's canonical flow places plan selection *before*
account creation, so an assigned plan normally exists when the job runs.
The job does **not** assume it, and the way it handles the exception is
load-bearing:

> **Before the per-component loop begins**, the run checks that the
> Workspace has a plan assignment at all. If it does not, the run
> **aborts immediately, writing no installation records of any kind**, and
> is retried automatically by the `WorkspacePlanAssigned` trigger the
> moment that first assignment arrives.

It deliberately does **not** record every component as
`skipped_unentitled` with reason `workspace_plan_unassigned`. That would
be a wrong and irreversible outcome: under §7.2 a skip record is never
revisited by a later run, so a Business whose plan landed a moment late
would be permanently denied its own initial installation and would have to
add every component by hand — the exact opposite of Addendum §16's "New
accounts **MAY** receive everything their current plan permits during
initial installation". **No plan assignment is a precondition failure, not
an entitlement decision**, and only genuine entitlement decisions are
recorded. This is why §3.6's table maps `workspace_plan_unassigned` to
`skipped_unentitled` only for a component evaluated inside a run that was
allowed to start — it is unreachable at the whole-run level, because the
precondition check fires first.

**Fail-closed, never fail-open** — in every case the job either proves
entitlement or installs nothing.

### 9.2 Backfill / re-run command

One artisan command, `blueprint:install-missing`, idempotent, safe to run
repeatedly, operating over a single Business or all Businesses, bounded by
exactly the same §7.2 rules.

It is a **recovery** path, not the ordinary mechanism for any valid
account. A legitimate first plan assignment is handled automatically by
§9.1's `WorkspacePlanAssigned` trigger and never needs this command.

It exists for precisely four situations, all of which share the property
that **no entitlement decision was ever recorded**:

1. a listener failed, was never dispatched, or its queued job was lost;
2. a Business that predates this slice, or predates its Blueprint being
   published (no records at all);
3. individual components recorded `failed` (§7.4);
4. any other operator-diagnosed gap where records are absent.

It is **not** a path for anything previously recorded as
`skipped_unentitled` or `skipped_unavailable`. Those are decisions, and
under Addendum §16 the only thing that reverses a skip into an install is
the owner's own explicit action (§8.1) — never a command, a plan change,
or a new Blueprint version. Running this command against an upgraded
Workspace installs nothing, by design; the newly entitled components are
surfaced by §8.1 instead.

Per `CLAUDE.md`'s route-3 rules this command is never run against anything
but a `TestDatabaseSafety`-approved database during development.

Per `CLAUDE.md`'s route-3 rules this command is never run against anything
but a `TestDatabaseSafety`-approved database during development.

### 9.3 Interaction with the existing lazy "Set up your pipeline" path

Both paths coexist, and the interaction is defined rather than left to
chance:

- A Business whose Blueprint installed a pipeline already has one, so
  `CrmPipelinesController::setup()` is simply not surfaced to it by the
  existing CRM UI's own empty-state logic. Nothing changes in that
  controller.
- If it *is* called anyway, `BusinessTemplateApplier::applyPipelines()`'s
  own idempotency applies per `(template_key, template_pipeline_key)`. The
  Blueprint's pipeline is written with `template_key = 'photo_booth'`, the
  legacy path's with `template_key = 'generic'` — **different keys, so the
  legacy call would add a second, separate pipeline.** That is the existing,
  unmodified, already-tested behaviour of "+ New pipeline", it is not a
  defect, and this contract does not change it. Stated here explicitly so a
  later reader does not mistake it for one.
- `CrmPipelineService`, `CrmPipelinesController` and `BusinessTemplateApplier`
  are **not modified by any sub-slice of this contract.**

## 10. Component adapter architecture

One small interface, one singleton registry, one adapter per target module.

```php
interface NicheBlueprintComponentAdapter
{
    /** The component_type discriminator this adapter claims. */
    public function componentType(): string;

    /** Publish-time descriptor validation (§6.2). Throws on a malformed payload. */
    public function validateDescriptor(array $payload): void;

    /**
     * Copy this component into rows the Business owns. Called inside the
     * caller's transaction, with the Business row already locked (§7.2).
     * Never called for a component the entitlement filter rejected.
     */
    public function install(Business $business, array $payload, ?int $actorUserId): InstalledComponentReference;
}
```

`InstalledComponentReference` is a tiny readonly value object carrying
`(string $recordType, int $recordId)`, which the installer writes into
`installed_record_type`/`installed_record_id` (§5.4).

`NicheBlueprintComponentAdapterRegistry` is bound as a singleton in
`AppServiceProvider`, immediately adjacent to the existing
`BusinessTemplateRegistry` binding at line 381 — the same one-line,
low-conflict, already-proven shape.

**The first adapter, `CrmPipelineComponentAdapter` (§12.D), contains no
copying logic of its own.** It translates the JSON payload into a
`BusinessTemplate` + `PipelineBlueprint` + `StageBlueprint` graph — the
existing validated descriptors — and calls the existing
`BusinessTemplateApplier::copyPipeline()`. `PipelineBlueprint`'s
constructor then performs most of `validateDescriptor()`'s work for free,
including the `new_inquiry`-first rule and the semantic-key pattern. This
is the reuse the `CLAUDE.md` rules demand, and it is why §12.D is an S-
sized sub-slice rather than an L-sized one.

**`copyPipeline()` is used rather than `applyPipelines()`** because the
Blueprint installer performs its own idempotency check against the
installation record (§7.2 step 2) and must not have a second, different
idempotency rule underneath it. `copyPipeline()` is the unconditional
single-blueprint copy; the installation record decides whether it is
reached at all. This keeps exactly one idempotency authority.

## 11. Dependency posture — how future adapters stay additive

Stated as three mechanical rules, so a future lane does not have to
re-derive them:

1. **An adapter may only be written once its target module has a canonical
   "create a business-owned row" seam.** §3.4 is the current inventory;
   nine of ten categories do not yet qualify.
2. **A published version may not name a `component_type` with no registered
   adapter** (§6.2 check 1). Publishing is therefore the enforcement point,
   and a Blueprint can never reference an installer that does not exist.
3. **Adding an adapter is an additive change to exactly two things** — a
   new class implementing the interface, and one registration line — plus
   a **new Blueprint version** that includes the component. It touches no
   existing adapter, no installer code, and no existing installation
   record. Two adapters authored in parallel lanes conflict only on the
   registration line, the same low-conflict shape as a
   `CustomerMenuBuilder` entry.

The resulting order for each future module is invariant: *target module
ships → its copy seam exists → adapter ships → new Blueprint version
published naming the component → existing Businesses see it surfaced (if
entitled), new Businesses receive it at install.* **At no point is an
existing Business silently changed.**

### Dependency map

| This slice depends on | Status on `main` | Nature |
|---|---|---|
| `EntitlementManager::decide()` | Exists (line 148) | Called, unmodified — hard |
| `PlatformFeature` / `PlatformFeatureRegistry` | Exists | Read, unmodified — hard |
| `business_verticals` / `BusinessVertical` | Exists | FK target — hard |
| `businesses.industry`, `business_knowledge_profiles.vertical_key` | Exists | Read — hard |
| `BusinessCreated` + `EventServiceProvider` | Exists | One additive listener line — hard |
| `WorkspacePlanAssigned` (`App\Events\Entitlement`) | Exists — `ShouldDispatchAfterCommit`, dispatched by `EntitlementManager::assignFirstPlan()` and `createLegacyOnboardingCompatibilityAssignment()` | Subscribed to, **event unmodified**; one additive listener line (§9.1) — hard |
| `BusinessTemplateApplier` (§12.D only) | Exists | Called, unmodified — hard for D, not for A–C |
| Contracts 01/02/04/07 (Agency + Location ACL) | Merged (`AgencyClientWorkspaceRelationship`, `WorkspaceMembershipLocation`, `ViewAsSession` all present) | Reused implicitly via tenancy; no new requirement |

| Depends on this slice | Why |
|---|---|
| Any future Blueprint component adapter (Calendar, Packages, Proposals, Forms, Reviews, SEO, Automations, Website, Custom fields) | Needs §5's schema, §10's interface and §6's publish gate to exist |
| Blueprint §8's Home setup checklist ("seeded from whatever the Blueprint and plan leave unconfigured") | Needs the installation records as its input — **not built here** (§15) |

## 12. Exact implementation allowlist — six dependency-ordered sub-slices

Ordering principle, taken from Contract 16's corrected shape: **no
customer-executable route exists until the gates that protect it exist.**
A–D build schema and domain code with no HTTP surface at all; E and F add
the two surfaces.

### Sub-slice A — Schema + models + adapter seam (no behaviour)

- **Files/domains**: four migrations (§5.1–§5.4); `NicheBlueprint`,
  `NicheBlueprintVersion`, `NicheBlueprintComponent`,
  `BusinessBlueprintComponentInstallation` Eloquent models (casts,
  relations, `HasUid` where §5 specifies a `uid`) — **no services, no
  controllers, no views**; `NicheBlueprintVersionState` and
  `BlueprintComponentInstallationState` enums;
  `NicheBlueprintComponentAdapter` interface,
  `InstalledComponentReference` value object,
  `NicheBlueprintComponentAdapterRegistry` (empty) + its singleton binding
  in `AppServiceProvider`.
- **Prerequisites**: none beyond Contracts 1–14 (merged).
- **Schema**: exactly §5.1–§5.4, including both STORED generated guard
  columns and the composite FK's supporting `UNIQUE (id, blueprint_id)`.
- **Tenancy/security**: N/A — nothing is reachable.
- **Concurrency**: N/A — no write paths.
- **Tests**: migration/constraint tests (every UNIQUE including the two
  guards; the composite FK; `restrictOnDelete` vs `cascadeOnDelete` exactly
  as §5 specifies; FK-less `installed_record_id`/`installed_by_user_id`/
  `published_by_user_id`); a test proving two `published` versions of one
  Blueprint cannot coexist and two `draft`s cannot either; model factory
  smoke tests; registry-binding test.
- **Risk**: Low. **Model**: Sonnet 5 sufficient.

### Sub-slice B — Publishing authority (platform-side domain service, no HTTP)

- **Files/domains**: `NicheBlueprintPublisher` (or equivalent) in
  `app/Library/NicheBlueprint/` — create Blueprint, create/edit draft,
  add/edit/remove draft components, `publishVersion()`, `supersede()`;
  `assertPlatformAdministrator()`-shaped check (§6.1); the typed publish
  exceptions.
- **Prerequisites**: A.
- **Schema**: none.
- **Tenancy/security**: platform-administrator only, on every method.
- **Concurrency**: `publishVersion()` runs in one transaction, locking the
  `niche_blueprints` row, so two concurrent publishes cannot both win the
  `published_guard`.
- **Tests**: every §6.2 refusal (unknown adapter type; unknown feature key;
  Workspace-scoped feature key; adapter descriptor rejection; duplicate
  `component_key`); non-admin refused on every method; a published version
  and its components are immutable afterwards (source-boundary test);
  publishing v2 supersedes v1 and v1's components are untouched;
  **publishing writes to no `business_*` table at all** (the Acceptance
  Matrix's own "without touching any live Business" language, tested
  directly).
- **Risk**: Medium. **Model**: Opus-class recommended — this is the
  fail-closed gate.

### Sub-slice C — Installation engine (no adapters, no HTTP)

- **Files/domains**: `NicheBlueprintInstaller` — §7.1 resolution, §6.3
  filter, §7.2 per-component transaction loop, §7.4 failure handling;
  `InstallNicheBlueprintForBusiness` job; **both** §9.1 listeners —
  `BusinessCreated` and `WorkspacePlanAssigned` — and their two additive
  `EventServiceProvider` lines; `blueprint:install-missing` command.
- **Prerequisites**: A, B.
- **Schema**: none.
- **Tenancy/security**: no customer surface. `decide()` is the only
  entitlement authority (§6.3).
- **Concurrency**: exactly §7.2 — `lockForUpdate()` on the Business, record
  re-read inside the lock, one transaction per component.
- **Tests**: exercised with **test-only adapters** (an installing one, a
  throwing one), since no real adapter exists until D. Full §13 list
  applies here for everything not adapter-specific: skip-unentitled,
  skip-unavailable, idempotent re-run, partial failure keeps earlier
  successes, concurrent runs install once, resolution miss writes nothing,
  ambiguous broad-industry match installs nothing — plus **the whole of
  §13.5's five-case trigger matrix (A–E)**, which is this sub-slice's
  highest-value test set and must not be deferred to D or E.
- **Risk**: High — this is the slice where a mistake silently mutates a
  live Business. **Model**: Opus-class required.

### Sub-slice D — First real adapter + Photo Booth Blueprint v1

- **Files/domains**: `CrmPipelineComponentAdapter` + its registration line;
  a seeder (or `blueprint:` command) creating the `photo_booth` Blueprint,
  its v1 version and the §5.5 component, and publishing it. **No edit to
  any file under `app/Library/Crm/`.**
- **Prerequisites**: A, B, C.
- **Schema**: none. Seed data only.
- **Tenancy/security**: N/A (adapter is called only by the installer).
- **Concurrency**: inherits C's boundary; the adapter itself opens no
  transaction.
- **Tests**: the adapter produces a `CrmPipeline` with the §5.5 stages in
  order, first stage semantic key `new_inquiry`; `crm_pipelines.template_key`
  = `photo_booth` and `template_version` = 1; end-to-end — a Business
  created on a Crm-entitled plan ends with a Photo Booth pipeline and an
  `installed` record pointing at it; `validateDescriptor()` rejects a
  payload whose first stage is not `new_inquiry`; the legacy
  `CrmPipelinesController::setup()` path still behaves exactly as before
  (§9.3), proven by re-running the existing `CrmBusinessTemplateTest`
  unchanged.
- **Risk**: Low. **Model**: Sonnet 5 sufficient.

### Sub-slice E — Customer surface (list installed / list addable / explicit add)

- **Files/domains**: `Customer\Business\NicheBlueprintController` (index +
  add), its routes, its Blade views, and the §8.1 query as a read service.
  **No change to `config/customer-permissions.php` — this slice adds no
  capability key (§6.5).**
- **Prerequisites**: A, B, C, D (E must not front domain code that is not
  merged).
- **Schema**: none.
- **Tenancy/security**: exactly §6.5's three gates, in order, none
  substituting for another.
- **Concurrency**: exactly §7.3.
- **Tests**: the §13 adversarial authorization matrix in full, including
  **non-owner staff and Workspace Admin both refused** the add; the add
  action re-checks entitlement under the lock and refuses a component that
  became unentitled between render and submit; adding an already-installed
  component is a no-op success; a `skipped_unavailable` component is not
  listed while `Planned` and **is** listed once flipped `Available` and
  entitled, and can then be added (§8.1's implementer note).
- **Risk**: Medium. **Model**: Opus-class recommended.

### Sub-slice F — Platform Owner surface (Niche Blueprints + Template Library)

- **Files/domains**: `Admin\NicheBlueprintController` and
  `Admin\BlueprintTemplateLibraryController` (Blueprint §30 names two
  surfaces), their routes and views, over Sub-slice B's service.
- **Prerequisites**: A, B. **Parallel-safe with D and E** — it fronts B, not
  C.
- **Schema**: none.
- **Tenancy/security**: platform administrator on every route (§6.1); no
  customer or Agency path exists.
- **Concurrency**: inherits B.
- **Tests**: non-admin refused on every route; publishing through the UI
  hits B's service rather than writing directly; the version list shows
  `draft`/`published`/`superseded` correctly.
- **Risk**: Low. **Model**: Sonnet 5 sufficient.

## 13. Required tests

Beyond each sub-slice's own list (§12), these are **required, not
optional**, because each maps to a sentence in Addendum §16 or the
Acceptance Matrix:

1. **One canonical Blueprint** — the same Blueprint installs into a Core
   Business and a Growth Business, and the two receive **different subsets
   of the same components**; no second Blueprint row, no per-tier variant
   (Addendum §16 sentence 1; Blueprint §21's "Full Photo Booth growth
   blueprint depth" row).
2. **Entitlement filter** — a component whose feature the plan excludes is
   recorded `skipped_unentitled` and **no business-owned row is created**
   (Addendum §16 sentence 2).
3. **Never silently installed on upgrade** — install on Core; upgrade the
   Workspace to Growth; assert **zero writes** to every business-owned
   table and zero changes to every installation record; then assert the
   component *is* surfaced by §8.1's query (Addendum §16 sentence 3 — the
   central requirement).
4. **Never silently reactivated by a platform change** — install v1;
   publish v2 that changes an already-installed component's payload and
   adds a new component; assert the Business's existing rows and records
   are byte-identical, and that the new component is surfaced, not
   installed (Acceptance Matrix: *"A new Blueprint version is published
   without touching any live Business until it opts in"*).
5. **New account receives everything entitled, whichever order the
   Business and the plan arrive in** (Addendum §16 sentence 4; §9.1). All
   five cases are required, and all belong to Sub-slice C:
   - **A.** plan assignment exists *before* `BusinessCreated` → installs
     exactly once, every `Available`-and-entitled component `installed`.
   - **B.** Business created with **no** plan assignment → the run aborts
     and **zero** installation records exist (not even skips).
   - **C.** the first `WorkspacePlanAssigned` then fires for that
     Workspace → the initial installation happens **automatically**, with
     no command and no operator action.
   - **D.** **both** triggers fire for the same account → still exactly
     one installation record per component and exactly one business-owned
     row per component; the second invocation is a proven no-op.
   - **E.** `WorkspacePlanChanged` (an upgrade on an established account)
     → **zero** automatic installs, zero new installation records, zero
     business-owned rows; the newly entitled components appear only in
     §8.1's addable query. `WorkspacePlanAssigned` for a Workspace with no
     Business is likewise a no-op.
6. **Unavailable feature is never installed, and a skip is never durable**
   — a component whose `required_feature_key` is `Planned` is recorded
   `skipped_unavailable`, creates nothing, and is **not** surfaced as
   addable; then, with that feature flipped `Available` and the plan
   entitling it, **the same component becomes surfaced and addable with no
   record rewrite, migration or backfill**, and adding it overwrites the
   existing `skipped_unavailable` record rather than failing on it
   (§8.1's implementer note — this is the test that proves the
   `state <> 'installed'` predicate was chosen correctly).
7. **Idempotent re-run** — running installation twice produces identical
   records and creates no duplicate business-owned rows.
8. **Partial failure** — with a deliberately throwing adapter in the
   middle, earlier components stay installed, the failure is recorded
   `failed` with an `error_code`, later components still run, and a re-run
   retries only the failed one.
9. **Concurrency** — two simultaneous installation runs for one Business
   produce exactly one `installed` record and one business-owned row per
   component.
10. **Downgrade is inert** — downgrade after install writes nothing,
    deletes nothing, and leaves every installation record unchanged (§8.2).
11. **Adversarial authorization matrix** (Sub-slice E), proving the add is
    **owner-only** (§6.5): owner of a *different* Workspace; **an active
    staff member of the correct Business — refused**; **an active
    Workspace Admin of the correct Workspace — refused**; a platform
    administrator who is not the owner — refused; the correct owner but a
    component that is unentitled — refused; guessed foreign Business uid;
    guessed foreign `component_key`; and non-admin against every Sub-slice
    F route. The two staff/Admin refusals are the load-bearing cases: they
    are what prove no capability-shaped back door was introduced.
12. **Publish never touches a Business** — the source-boundary/observation
    test named in §12.B.
13. **Existing behaviour unchanged** — `CrmBusinessTemplateTest`,
    `PlatformFeatureRegistryTest` and the `EntitlementManager` suites run
    unmodified and pass (this slice edits none of their subjects).

Per `CLAUDE.md`, every one of these must report a **positive test count**;
`No tests found` is a failure even at exit code zero.

## 14. Acceptance criteria

1. Exactly one `niche_blueprints` row exists per niche, and at most one per
   `business_verticals.key` — enforced by the UNIQUE index, not by
   convention (§5.1).
2. `niche_blueprint_components.required_feature_key` is **`NOT NULL` at
   the database layer**, every published component names a known,
   Business-scoped `PlatformFeature`, and there is **no code path — none —
   that installs a component without an allowed
   `EntitlementManager::decide()` result naming it**. No second authority
   anywhere in the slice (§5.3, §6.2, §6.3, proven by §13.2).
3. Installing produces **rows the Business owns**, with no live link back
   to any `niche_blueprint_*` row: nothing in the codebase reads a
   Blueprint table to determine a Business's current configuration
   (§5.4, §14.5).
4. At most one `draft` and at most one `published` version per Blueprint,
   enforced by MySQL (§5.2); a non-draft version and its components are
   never updated by any production code path, proven by a source-boundary
   test of the same kind `WebsiteRevision`'s invariant already uses — **and
   claimed at exactly that strength, not stronger**.
5. A platform-side publish writes to **zero** business-owned tables
   (§13.12).
6. A plan upgrade installs and activates **nothing**. `WorkspacePlanChanged`
   is not wired to any installation path; the only write paths into a
   Business are (a) an **owner-initiated**, entitlement-rechecked explicit
   add and (b) a genuinely new account's initial install, triggered by
   `BusinessCreated` or the **first** `WorkspacePlanAssigned` (§8.1, §9.1,
   §13.3, §13.5.E).
7. A plan downgrade removes, archives and deactivates **nothing** (§8.2,
   §13.10).
8. A component whose `PlatformFeature` is `Planned` is never installed and
   not surfaced **while it remains `Planned`** — and becomes surfaceable,
   with no record rewrite, once it is `Available` and entitled, because a
   skip row is provenance and never authority (§5.4, §6.3, §8.1, §13.6).
9. The explicit add is **owner-only**: no staff member, Workspace Admin,
   platform administrator or capability key can change which Blueprint
   components a Business has, and `config/customer-permissions.php` is not
   modified by this slice (§6.5, §13.11).
10. The initial install is not lost when a Business is created before its
    Workspace's first plan assignment; the `WorkspacePlanAssigned` trigger
    completes it automatically, and firing both triggers still yields
    exactly one installed row per component (§9.1, §13.5.B–D).
11. A published version can never name a `component_type` with no
    registered adapter (§6.2, §12.B tests).
12. Re-running installation is idempotent, and a partial failure never
    rolls back a successful component (§7.4, §13.7–8).
13. `installed_by_user_id` is NULL for a system-initiated install — no
    fabricated system actor anywhere in the slice (§6.4).
14. No file under `app/Library/Crm/`, `app/Library/Entitlement/`, or
    `app/Library/Workspace/` is modified by any sub-slice (§4, §17).
15. `git diff --check` clean and a clean working tree at the end of each
    sub-slice's own commit.

## 15. Non-goals

- **Agency-authored or Agency-customized Blueprints.** No document
  authorizes them. Blueprint §30 places the Blueprint catalog on the
  Platform Owner's sidebar; Addendum §16 says "one canonical niche
  blueprint"; Blueprint §28 and Addendum §2 give an Agency relationship
  authority over client Workspaces, never over platform content. An Agency
  provisioning a client (Contract 07) triggers **the same** install path
  for the client's Business, filtered by **the client's own plan**, not the
  Agency's. `PlatformFeature::WhiteLabel` (currently `Planned`) governs
  branding, never Blueprint content. **No per-Agency Blueprint table.**
- **Per-plan Blueprint variants** — forbidden outright by Addendum §16
  sentence 1.
- **Ungated Blueprint components.** `required_feature_key` is `NOT NULL`
  (§5.3) and there is no default, fallback or implicit feature. A
  component whose product area has no `PlatformFeature` identity is
  **unpublishable until the relevant product authority defines one** —
  deliberately, because the alternative is a Blueprint-shaped bypass of
  the entitlement invariant Addendum §16 locks. Defining a new
  `PlatformFeature` case for a future component is that future slice's
  work, under RFC-004's own rules, not this one's.
- **A customer capability key for Blueprint management** (§6.5) — Blueprint
  §22 names the **owner**, and a capability key would grant staff a wider
  authority than that sentence allows. `config/customer-permissions.php`
  is untouched.
- **Uninstall / auto-uninstall on downgrade** (§8.2) — the rows are the
  Business's.
- **Installing on plan upgrade.** `WorkspacePlanChanged` is deliberately
  not wired to any installation path (§9.1); only the *first*
  `WorkspacePlanAssigned` for an account can install, and only because
  that account has no Blueprint yet.
- **A `*_transitions` audit table for installation state** (§5.4) — no
  document asks for install history, and `CLAUDE.md` forbids a table
  without a purpose. Recorded here so a later slice may add one knowingly.
- **A new `PlatformFeature` case for the Blueprint system itself** (§4) —
  it would gate a Core account out of its own entitled components.
- **Home's setup checklist** (Blueprint §8: "seeded on Home from whatever
  the Blueprint and plan leave unconfigured") — a Home concern that
  *consumes* §5.4's records; this contract produces them and stops.
- **Building any of the nine target modules in §3.4**, or their adapters —
  each is its own slice, gated by §11's rules.
- **Modifying `BusinessTemplate`/`Registry`/`Applier`, `CrmPipelineService`,
  `CrmPipelinesController`, `EntitlementManager`, `PlatformFeatureRegistry`,
  or the `PlatformFeature` enum** in any way.
- **Changing the legacy lazy "Set up your pipeline" path** (§9.3).
- **Blueprint §9's Lost state and Booked badge** — explicitly not pipeline
  stages, therefore not Blueprint components (§5.5).
- **Reopening the Workspace/Agency tenancy migration (Contracts 1–14)** or
  the account-lifecycle work (Contract 03) in any way.

## 16. Merge prerequisites

None hard at the whole-slice level. The Roadmap classes §22 as an
independent product module that "may start as soon as a lane is free", and
Contracts 1–14 are already merged as of `30ad21c7`. This slice touches
none of `WorkspaceManager`, `CustomerAccountAccessResolver`,
`EntitlementManager`, or the Agency relationship table — the Roadmap's own
four-way independence test for slices 15–18, which this slice also passes.

Per-sub-slice prerequisites are stated in §12: **A → B → C → D → E**, with
**F parallel to D and E** once B is merged.

This contract is numbered **20** rather than being inserted into the
Roadmap's "Slices 15–18" table because that table has no §22 row; the
Roadmap names §22 only in its wave-plan "Others" row. Adding a row to that
table is a Roadmap edit this contract does not perform (it is a
documentation-only contract for one slice, not a re-plan), and the
Contract Index's own note already covers independent product modules
having no contract in the original 14-priority factory scope.

## 17. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Slice 15 (Calendar), 16 (Packages), 17 (Proposals), 18 (SEO) | none today — each is a target module this slice does not build. Each becomes a **future adapter** author (§11), conflicting only on the adapter-registry line | Parallel-safe; sequencing is §11's rule, not a file conflict |
| `app/Providers/AppServiceProvider.php` | one additive singleton binding beside line 381 | Low risk — the same shape every prior slice's binding used |
| `app/Providers/EventServiceProvider.php` | one additive listener under the existing `BusinessCreated` key (lines 49-50) | Low risk |
| `config/customer-permissions.php` | **not modified** — this slice adds no capability key (§6.5) | No conflict at all, including with Contract 16's own `packages_products` key |
| `app/Events/Entitlement/WorkspacePlanAssigned.php` | **read/subscribed, not modified** (§9.1) | No conflict by construction |
| Contract 03 / any future lifecycle work dispatching `WorkspacePlanAssigned` | this slice adds a listener to that event | Low risk — a new subscriber is additive; but any future slice that starts dispatching `WorkspacePlanAssigned` for something **other** than a genuine first assignment would newly reach this installer, so that slice must re-read §9.1 before doing so |
| CRM domain (`app/Library/Crm/*`) | **read and called, never modified** (§4, §12.D) | No conflict by construction |
| `EntitlementManager` | **called, never modified** | No conflict by construction |
| Contract 03 (account lifecycle) | `decide()`'s `plan_suspended`/`plan_inactive` reasons are consumed as `skipped_unentitled` (§3.6); no shared file | Parallel-safe |
| Future Home setup-checklist work | reads `business_blueprint_component_installations` | Read-only consumer; must land after Sub-slice C |

## 18. Implementation prompts

Each sub-slice is handed to a fresh session independently, **once
explicitly authorized by a human** — this contract authorizes nothing.
Every prompt assumes Contracts 1–14 and every lower-lettered sub-slice are
already merged to `main`. Every prompt inherits `CLAUDE.md`'s route-3
rules: branch-only, never open or merge a PR, `TestDatabaseSafety`-approved
database only, report exact SHAs/paths/test counts, never claim an
unverified test run.

### 18.A — Schema, models, adapter seam

Implement Contract 20 §5.1–§5.4 and §10's interface/registry. Create the
four migrations exactly as §5 specifies — including both STORED generated
guard columns on `niche_blueprint_versions` with their UNIQUE indexes
(mirroring `2026_09_15_100002_create_automation_workflow_versions_table.php`,
which you should read first), the `UNIQUE (id, blueprint_id)` that the
components table's composite FK requires, and the deliberately FK-less
`installed_record_id`, `installed_by_user_id` and `published_by_user_id`
columns (the `workspace_entitlement_transitions` convention — read that
migration's docblock). **`niche_blueprint_components.required_feature_key`
is `NOT NULL`** — there is no ungated Blueprint component in V1 (§5.3), so
do not make it nullable "for flexibility"; a migration test must assert the
non-nullability explicitly. Add the four Eloquent models with casts and
relations only, the two enums, the adapter interface, the
`InstalledComponentReference` value object, the empty adapter registry, and
its singleton binding beside the existing `BusinessTemplateRegistry`
binding in `AppServiceProvider`. **No services, controllers, routes or
views.** Write the §12.A tests. Nothing in this sub-slice is reachable by
any user.

### 18.B — Publishing authority

Implement Contract 20 §6.1 and §6.2. Build the platform-side domain
service: create Blueprint, create/edit a draft version, add/edit/remove a
draft's components, `publishVersion()`, `supersede()`. Every method
asserts platform-administrator authority in the shape
`EntitlementManager::assertPlatformAdministrator()` uses (`users.is_admin`,
`AuthorizationException`) — reproduce that check in this domain, do not
call into `EntitlementManager` for it. **`publishVersion()` is the
fail-closed gate and is the highest-correctness code in this slice:**
refuse the publish, atomically, if any component has an unregistered
`component_type`, a **missing/empty** `required_feature_key`, a
`required_feature_key` that is not a known `PlatformFeature`, a
`required_feature_key` that is Workspace-scoped, a payload its adapter
rejects, or a duplicate `component_key`. Never substitute a default or
fallback feature for a missing one. **Do NOT
check `isAvailable()` at publish** — §6.2 explains why a `Planned` feature
is legitimately publishable. Write the §12.B tests, including the one
proving a publish writes to zero business-owned tables. **No HTTP surface.**

### 18.C — Installation engine

Implement Contract 20 §6.3, §6.4, §7 and §9. Build the installer, the
queued job, **both** §9.1 listeners — `BusinessCreated` **and**
`App\Events\Entitlement\WorkspacePlanAssigned` — and the
`blueprint:install-missing` command.

**Both triggers are required, and `WorkspacePlanChanged` is not one of
them.** `WorkspacePlanAssigned` already exists on `main`
(`ShouldDispatchAfterCommit`, carrying `workspaceId`,
`workspacePlanCatalogId`, `actorUserId`; dispatched by
`EntitlementManager::assignFirstPlan()` and
`createLegacyOnboardingCompatibilityAssignment()`) — subscribe to it,
never modify it. Its listener resolves the Workspace's canonical sole
Business and no-ops when there is none. Wiring `WorkspacePlanChanged`
would silently install into established Businesses on every upgrade, which
Addendum §16 forbids outright; §13.5.E is the test that proves you did not.

**Critical, get this exactly right.** The per-component transaction
boundary in §7.2 is the whole slice: lock the Business row with the same
`lockForUpdate()` call `BusinessTemplateApplier::applyPipelines()` uses
(read it first), **re-read the installation record inside that lock**, and
only proceed for an absent record or one in state `failed`. One
transaction per component, never one for the run — a later component's
failure must never roll back an earlier component's success. Entitlement
comes **only** from `EntitlementManager::decide()`; map its `reason`
exactly as §6.3's table says and record it verbatim. Never call
`PlatformFeatureRegistry` yourself — `decide()` already applies the
availability floor, and a second check is precisely the driftable duplicate
authority this codebase's own docblocks warn against. `installed_by_user_id`
is **NULL** for a system install; pass the Workspace owner's real user id
to `decide()` (§3.6 proves that argument is decision-neutral) and do not
invent a system-actor id. There is **no** "no feature key → install"
branch: `required_feature_key` is `NOT NULL`, so `decide()` is consulted
for every component without exception. Model both listeners on
`InitializeBusinessUsageProfile` — read it first — for its failure posture.
Test with test-only adapters; no real adapter exists yet. **§13.5's
five-case trigger matrix (A–E) belongs to this sub-slice** and is its
highest-value test set.

### 18.D — CRM pipeline adapter + Photo Booth Blueprint v1

Implement Contract 20 §5.5 and §10's first adapter. The adapter **contains
no copying logic**: translate the JSON payload into `BusinessTemplate` +
`PipelineBlueprint` + `StageBlueprint` and call the existing
`BusinessTemplateApplier::copyPipeline()`. Use `copyPipeline()`, not
`applyPipelines()` — §10 explains that the installation record is the only
idempotency authority and a second one underneath it is a defect. **Do not
modify any file under `app/Library/Crm/`.** Seed and publish the
`photo_booth` Blueprint v1 with the single component in §5.5 — first stage
"New Lead" over semantic key `new_inquiry`, `required_feature_key` `crm`.
Re-run `tests/Feature/Crm/CrmBusinessTemplateTest.php` unchanged to prove
the legacy path is untouched.

### 18.E — Customer surface

Implement Contract 20 §6.5, §7.3 and §8.1. Build the Business-scoped
controller (list installed, list addable, add one), its routes and views.

**Do NOT add a capability key, and do NOT modify
`config/customer-permissions.php`.** The add is **owner-only** (§6.5):
Blueprint §22 names the owner, and a capability key would let the owner
delegate to staff an authority that sentence does not grant. Three gates,
in order, none substituting for another: tenancy via the existing
`ResolvesBusinessTenancy` trait, then **the actor is the owner of the
Workspace owning this Business** — not satisfied by Workspace Admin, by
staff membership, or by `is_admin` — then `decide()` re-asked **inside the
transaction and under the Business lock** at the moment of the add. The
adversarial tests that matter most here are the ones proving an active
staff member and an active Workspace Admin are both **refused**
(§13.11). The addable query is §8.1's, and every candidate
row it returns is passed through `decide()` before display. Adding an
already-installed component is a no-op success, not an error. Pay close
attention to §8.1's implementer note about `skipped_unavailable` rows
becoming addable once their feature is flipped `Available` — §13's test for
that sequence is required.

### 18.F — Platform Owner surface

Implement Contract 20 §6.1's HTTP surface over Sub-slice B's service.
Blueprint §30 names **two** sidebar entries — "Niche Blueprints" and
"Template Library" — so build both, over the same domain service; do not
collapse them into one. Platform-administrator authority on every route.
This sub-slice adds no domain logic whatsoever: every write goes through
Sub-slice B's service, and a route that writes directly to a
`niche_blueprint_*` table is a defect. Parallel-safe with D and E.
