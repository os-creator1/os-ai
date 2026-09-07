# WEBSITE GUIDED GENERATION + BUSINESS KNOWLEDGE PROFILE — IMPLEMENTATION CONTRACT

**Status:** DRAFT — CONTRACT ONLY, NOT IMPLEMENTATION-AUTHORIZED
**Lane:** Lane B (Website Generation + Hosting)
**Verified base:** `origin/main` @ `0fc2818bb941b01d9fa6b510e017d12ca0d813c5` (PR #211, B5 Business Analytics, merged)
**Depends on (read-only, not modified by this contract):** `docs/automation/WEBSITE-GENERATION-HOSTING-CONTRACT.md` (Slice A, implemented, merged PR #209), `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md` (Opportunity Engine, implemented), `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md` (entitlement, implemented), `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` (usage wallets, implemented through M6), `docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md` (GBP, contract-only, PR #210 merged)

This document is contract-only. It authorizes no code. Every claim below cites an exact file, class, method, column, enum case, or test. Where no such citation exists, the claim is marked `NOT FOUND` and recorded as a gap, never assumed.

---

## 0. VERIFIED BASE AND EVIDENCE

Mechanically confirmed on this exact commit (`0fc2818`):

- `git log --oneline -5 origin/main` shows, in order: `0fc2818` "Merge pull request #211 from os-creator1/agent/b5-business-analytics", `6820b69` "fix(analytics): remove dangling legacy report routes", `126c150` "Merge pull request #209 from os-creator1/agent/website-generation-hosting", `8f2ca4e` "Merge pull request #210 from os-creator1/agent/google-business-profile-contract", `eb77805` "docs: define Google Business Profile implementation contract".
- Website Slice A is present and fully merged: `app/Models/Website.php`, `app/Models/WebsitePage.php`, `app/Models/WebsiteRevision.php`, `app/Models/WebsiteAsset.php`, the 5 migrations under `database/migrations/2026_09_07_1300*.php`, `app/Http/Controllers/Customer/Business/WebsiteController.php`, `app/Http/Controllers/Public/WebsiteController.php`, and 16 test files under `tests/Feature/Website/`.
- The GBP contract is present at `docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md` (contract-only, no implementation code shipped — confirmed no `app/Models/*GoogleBusinessProfile*` or `business_google_connections`-shaped migration exists on this branch).
- B5 Business Analytics is implemented: its only migration is `database/migrations/2026_09_08_120001_add_analytics_indexes_to_business_scoped_tables.php` — B5 adds indexes to existing tables and reuses the existing `view_reports` customer permission; it introduces **no new `PlatformFeature` case** (`docs/automation/B5-BUSINESS-ANALYTICS-CONTRACT.md` §2.5: *"`App\Enums\Entitlement\PlatformFeature` has no analytics case... B5 introduces no new PlatformFeature case and no entitlement gate of its own."*).

Branch for this contract: `agent/website-guided-generation-contract`, created fresh from `origin/main` @ `0fc2818` (not rebased or reused from the merged `agent/website-generation-hosting` implementation branch).

---

## 1. PROBLEM AND GOAL

Website Slice A (merged) gives a Business exactly one flat, manually-edited website with a bounded 8-component library, AI-assisted first-draft generation, immutable publish/rollback, and a cached public entitlement gate. It has no guided template-selection flow, no completeness-driven question flow, no shared cross-feature business-data seam, and no cost/budget controls on AI generation beyond what already exists in `WebsiteAiDraftGenerator`.

This contract designs **Website Guided Generation**: a COO-guided, template-constrained generation product built strictly on top of Slice A's existing bounded component library, publish/rollback model, and entitlement gate — plus **one new, canonical, Business-scoped Business Knowledge Profile** that Website, and eventually SEO/GBP/COO/CRM/Ads/Automations, all read and write through the same seam, so no feature invents its own copy of "what is this business."

It does not re-open, weaken, or duplicate any Slice A invariant. Where this contract needs to *extend* an existing bound (e.g., the page-count ceiling), it says so explicitly and cites exactly why (§3.4).

---

## 2. EVIDENCE TABLE — EXISTING CAPABILITY VS MISSING CAPABILITY

| Capability | Status | Evidence |
|---|---|---|
| Business identity (name, industry, description, email, phone, website_url, social URLs) | **EXISTS** | `app/Models/Business.php:18-33` fillable; `database/migrations/2026_07_18_120001_create_businesses_table.php` |
| Business industry/category | **EXISTS** | `app/Enums/Business/BusinessIndustry.php:7-13` — `PhotoBoothService`, `EventServices`, `Photographer`, `WeddingVendor`, `HomeServices`, `ProfessionalServices`, `Other` |
| Locations + service areas | **EXISTS** | `app/Models/BusinessLocation.php:14-29` — `service_mode` (`BusinessServiceMode`: Storefront/ServiceArea/Hybrid/Online), `service_radius_km`, `service_area_cities` (JSON) |
| Services | **EXISTS** | `app/Models/BusinessService.php:14-23` — `name`, `slug`, `description`, `starting_price`, `currency_code`, `status`, `sort_order`, `is_primary` |
| Single active primary service invariant | **EXISTS** | `Business::primaryService()` (`app/Models/Business.php:67-72`, `where('is_primary', true)->where('status', BusinessServiceStatus::Active)`); enforced in `EloquentBusinessServiceRepository::resolvePrimary()`/`setPrimary()` |
| Single primary location invariant | **EXISTS** | `Business::primaryLocation()` (`app/Models/Business.php:57-60`); enforced in `EloquentBusinessLocationRepository::upsertPrimary()`/`setPrimary()` |
| Offers/packages, pricing method (fixed/hourly/quote/tiers) | **MISSING** | `BusinessService` has only `starting_price`/`currency_code` — no pricing-method enum, no bundled "offer/package" concept. `Offer`/`Product`/`Package` models: `NOT FOUND` anywhere in `app/Models/` |
| Features/differentiators | **MISSING** | No column anywhere on `businesses`, `business_services`, or `customer_onboardings` |
| Ideal customers / customer problems | **MISSING** | Not present anywhere |
| Credentials, licenses, warranties, guarantees, years operating | **MISSING** | `NOT FOUND` as a column anywhere in `app/Models/` or `database/migrations/`; only unrelated matches (`AppConfig`'s software-license setting, SMS-gateway "credentials") |
| Hours / availability | **MISSING** | Confirmed absent by the GBP contract's own evidence: *"Per-location opening hours do not exist on the platform. They are a general Business fact that Website, SEO and GBP all eventually need, and they belong on `business_locations`, not in a GBP table"* (`GOOGLE-BUSINESS-PROFILE-CONTRACT.md` §36.1.3) |
| Primary marketing/growth goals | **PARTIAL** | `customer_onboardings.primary_goals` (JSON, max 2 of `BusinessGoal` enum: `lead_generation`, `local_seo`, `website_conversion`, `reputation`, `sales_followup`, `automation`) exists but is account-level onboarding intent, not a per-website conversion-journey field |
| Website-specific primary conversion goal / booking journey | **MISSING** | No enum or column for "call vs quote vs consultation vs calendar vs external booking" exists anywhere |
| Brand voice / prohibited claims | **MISSING** | Not present anywhere |
| Reviews/testimonials (verified) | **MISSING** | No review/testimonial model exists (`NOT FOUND`); `WebsiteSectionType::Testimonials` is an AI-fabricated-copy section type only, never tied to a verified review record |
| Priority services/locations for growth | **MISSING** | Not present; only unordered `primary_goals` at the account level |
| Business-scoped image inventory / usage confirmation | **PARTIAL** | `WebsiteAsset` (`app/Models/WebsiteAsset.php`) exists but is strictly Website-scoped (cascade-deleted with the Website, no `purpose`/`usage_confirmed` concept, no cross-feature reuse) |
| Business-scoped "Settings" write surface | **PARTIAL** | No controller literally named `*Settings*` exists, but `App\Http\Controllers\Customer\BusinessController@edit`/`@update` (routes `customer.business.edit`/`customer.business.update`, prefix `/business`) is the real identity-edit surface. It is **flat/V1**: it resolves only `BusinessRepository::findPrimaryByCustomer()` — i.e. **the customer's one "primary" Business**, not any Workspace/Business-uid-scoped Business the way Website Slice A's own tenancy chain (`WorkspaceManager::userCanAccessBusiness()`) supports multiple Businesses per Workspace. This is a real architectural gap between the two subsystems (§3.3). |
| Bounded 8-component Website library | **EXISTS, LOCKED** | `app/Enums/Website/WebsiteSectionType.php:14-21` (`Hero`, `Text`, `ImageText`, `Services`, `Testimonials`, `Faq`, `Cta`, `ContactDetails`); confirmed exactly 8 by `tests/Feature/Website/WebsiteBoundaryTest.php:48-62` |
| Max sections per page (40) | **EXISTS, ENFORCED EVERYWHERE** | `WebsiteSectionValidator::MAX_SECTIONS_PER_PAGE = 40` (`app/Library/Website/WebsiteSectionValidator.php:20`), enforced on every `validate()` call site (draft save, publish-time re-validation, AI output validation) |
| Max pages per Website (20) | **PARTIAL / MECHANICAL GAP** | `WebsiteAiDraftGenerator::MAX_PAGES = 20` (`app/Library/Website/WebsiteAiDraftGenerator.php:29`) bounds only a single AI-generation *batch*, checked once against `count($decoded['pages'])` — it is **not** a general ceiling on a Website's cumulative page count. `WebsiteDraftPageService::createPage()` performs no page-count check at all. The contract doc's own §7 claim ("max 20 pages per Website") is **not code-enforced** as a standing invariant today. This contract must decide whether to close that gap (§3.4). |
| AI generation seam | **EXISTS, LOCKED** | `App\Library\Website\WebsiteAiGenerationClient::complete()` reusing `config('services.openai.*')` exactly (`config/services.php:90-97`); fails closed on missing key/inactive/any `Throwable` |
| Any AI model-routing / cheap-model-first policy | **MISSING** | `NOT FOUND` anywhere in `app/` — the only model reference in the whole codebase is the single hardcoded default `'gpt-4o'` in `config/services.php:93` |
| Any AI-specific token/cost budget or rate limit | **MISSING** | `NOT FOUND` — see Usage Wallet evidence below; no `PlatformFeature` is currently metered |
| Generic per-Business usage wallet/ledger/spend-cap infrastructure (RFC-005) | **EXISTS, FEATURE-GENERIC BUT UNACTIVATED FOR AI** | Tables `business_usage_wallets`, `business_usage_rates`, `business_usage_reservations`, `business_usage_ledger_entries`, `platform_feature_usage_classifications` (one row per `PlatformFeature` case) exist and are keyed generically by feature; `App\Library\Usage\UsageWalletManager::evaluateCoarseCapacity()` (line 1171) currently a stub returning `authorized: true` always; every `PlatformFeature` including `ai_coo_basic` remains `is_metered = false`; the only human-operable activation path (`app/Console/Commands/ActivateConversationsUsageRate.php`) is hardcoded to `PlatformFeature::Conversations` and has never been run |
| Entitlement gate integration point (`EntitlementManager::decide()`, `UsageAuthorizationGateway`) | **EXISTS, LOCKED** | `app/Library/Entitlement/EntitlementManager.php:111-187` (docblocked as RFC-004 §14's precedence chain; the usage-authorization check is its final step before an `allowed: true` decision), `app/Library/Entitlement/Contracts/UsageAuthorizationGateway.php:9-12` |
| `PlatformFeature::WebsiteGeneration` availability/packaging | **EXISTS, Available, Core+Growth+Agency** | `app/Library/Entitlement/PlatformFeatureRegistry.php:49`; packaged into all three tiers by `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php:91-102` |
| `PlatformFeature::AiCooBasic`, `SeoBasicVisibility`, `SeoModule` | **EXISTS AS ENUM ONLY, Planned** | `app/Library/Entitlement/PlatformFeatureRegistry.php:52-55`; zero executable implementation (no controller/model/migration/route) for any of the three |
| A general "AI COO" brain/controller | **MISSING (docs/enum only)** | `NOT FOUND` — the `PlatformFeature::AiCooBasic` case and its Core-tier packaging row are the only artifacts; no executable code exists |
| A general recommendation/opportunity engine that a "Website worker" should plug into | **EXISTS, DESIGNED FOR EXACTLY THIS, UNUSED FOR WEBSITE** | RFC-002 Opportunity Engine (`docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md`), fully implemented: `App\Library\Opportunity\OpportunityProducer` interface (`app/Library/Opportunity/OpportunityProducer.php:17-25`, exactly `workerKey(): OpportunityWorkerKey` + `produce(Business $business): iterable`), `App\Enums\Opportunity\OpportunityWorkerKey` **already reserves** `case Website = 'website';` alongside `BusinessAdvisor`, `Seo`, `Content`, `Sales`, `Reputation` — but **no producer implementation exists for `Website`** (only `BusinessAdvisorOpportunityProducer` exists). RFC-002 §2 states verbatim: *"Enables: SEO, Content, Sales, Reputation, and Website workers (future RFCs)... It does not implement the SEO, Content, Sales, Reputation, or Website workers."* This is the exact, pre-designed seam a future Website-recommendation worker must use — never a new, competing engine. |
| Calendar / booking model | **MISSING** | `PlatformFeature::Calendar` exists and is `Planned`; `NOT FOUND` as any executable model — every "schedul*"/"booking" hit in `app/Models/` is SMS-campaign-send scheduling or an external booking *URL* string on an unrelated AI-prospecting model |
| Form-builder / survey model | **MISSING, AND CONTRACTUALLY FORBIDDEN IN WEBSITE** | `PlatformFeature::Forms` exists and is `Planned`; `tests/Feature/Website/WebsiteBoundaryTest.php` actively asserts no form-builder routes/tables/section-type exist in Website Slice A |
| Offers/Products/Packages sellable-item model | **MISSING** | `NOT FOUND` anywhere |
| Reviews/ratings model | **MISSING** | `NOT FOUND` anywhere; GBP contract explicitly defers all review handling to a future, unbuilt "Slice C" (§36.2.1: *"Excluded from Slice A entirely. Reviews; review replies..."*) |
| SEO product code beyond Website's own per-page fields | **MISSING (docs+enum only)** | `PlatformFeature::SeoBasicVisibility`/`SeoModule` both `Planned`; no SEO controller/service/job exists; no SEO-named contract or RFC file exists anywhere in `docs/` |
| GBP ↔ Website integration | **EXPLICITLY NONE, BY DESIGN** | GBP contract §37.1: *"Website Slice A is not a dependency, and GBP does not read `websites`, `website_pages`, `website_revisions` or `website_assets`."* Also: *"Never auto-publish `/sites/{public_id}` to GBP"* — the noindexed platform-path URL must never be published as a customer's public website URL. |
| Generic audit-log/change-log package or model | **MISSING** | `NOT FOUND` (`spatie/laravel-activitylog` absent from `composer.json`; no `AuditLog`/`ActivityLog`/`ChangeLog` model). Established repository pattern is a **bespoke per-feature ledger table** — e.g. GBP's own `business_google_operations`, justified explicitly: *"is the audit table. No separate GBP events table is created; one would duplicate it"* (`GOOGLE-BUSINESS-PROFILE-CONTRACT.md` §27). This contract follows the same established pattern (§5.3) rather than inventing a generic mechanism. |
| `WebsitePublished` event listeners | **ZERO, CONFIRMED** | `grep -rn "WebsitePublished" app/Providers/` returns nothing; matches the Slice A contract's own claim that it "ships zero listeners for this event" |
| B5 Analytics / Website page-view data | **NOT INTEGRATED, KNOWN STALE DOC** | `docs/automation/B5-BUSINESS-ANALYTICS-CONTRACT.md:507` still reads *"Page views, site conversion \| Website Generation ships page-view data (`PlatformFeature::WebsiteGeneration` is `Planned`)"* — stale relative to Slice A's actual Available flip; not corrected by this contract (out of B5's lane, flagged only) |
| GoHighLevel template exports/screenshots/assets | **ABSENT, CONFIRMED** | `grep -rli "gohighlevel"` across the repository (excluding `vendor`/`node_modules`) returns exactly two files, both prose: `docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md` (one sentence positioning the *product*, not a template, against GoHighLevel as a competitor) and this contract's own reference in `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md` (same kind of prose mention). No export file, screenshot, HTML/CSS bundle, or asset of any kind from GoHighLevel exists anywhere in the repository. §4 below defines the import boundary precisely because of this. |

---

## 3. TENANCY, AUTHORIZATION, AND BOUNDARY DECISIONS

### 3.1 Reused, not reinvented

Guided Generation is a mode of the *existing* Website Slice A resource, not a new tenancy surface. Every guided-generation route runs through the identical chain Slice A's own controller already uses (`app/Http/Controllers/Customer/Business/WebsiteController.php:414-443`, `resolveEntitledBusiness()`): Workspace-by-uid → Business-in-workspace → `WorkspaceManager::userCanAccessBusiness()` → fresh `EntitlementManager::decide(..., PlatformFeature::WebsiteGeneration->value, (int) Auth::id())` → 404 on any failure. No new permission key is introduced beyond the existing `website` customer permission (`config/customer-permissions.php:30-35`).

### 3.2 Business Knowledge Profile tenancy

The Business Knowledge Profile is **Business-scoped** (one row per `business_id`, unique), reachable only through the same `WorkspaceManager::userCanAccessBusiness()` chain used everywhere else in this codebase for Business-scoped data — it introduces **no** parallel authorization path. `SeoBasicVisibility`, `SeoModule`, `GoogleBusinessProfileModule`, `AiCooBasic`, `Automations`, and `Crm` are all already `PlatformFeature::Business`-scoped (default scope per `PlatformFeatureRegistry::SCOPE`, `app/Library/Entitlement/PlatformFeatureRegistry.php:70-72,87`) — so every consumer of the Profile shares the identical Business-scoped authorization primitive already in use platform-wide. Reading the Profile itself requires no new `PlatformFeature` gate (it is not a billable feature; it is shared data), but **writing** it always happens through one of two already-entitled surfaces: Business Settings (no feature gate today — see §3.3) or Website guided setup (gated by `PlatformFeature::WebsiteGeneration`, already Core+Growth+Agency).

### 3.3 The Business Settings tenancy gap — a human-review decision, not silently resolved

`App\Http\Controllers\Customer\BusinessController@edit`/`@update` (`app/Http/Controllers/Customer/BusinessController.php:27-59`, routes `customer.business.edit`/`update`, `routes/customer.php:536-539`) is the only existing "Business Settings" surface, and it operates on `BusinessRepository::findPrimaryByCustomer()` — **the customer's single primary Business**, with no Workspace/Business-uid parameters at all. This predates RFC-003's multi-Business-per-Workspace tenancy model that Website Slice A, Automations, and every other B-lane feature already use.

**This contract does not silently extend or replace that flat surface.** Two paths exist, and which one ships is a human-review decision (§23):

- **Option A (recommended, smaller):** Business Knowledge Profile fields introduced by this contract (§4) get their own dedicated, Workspace/Business-uid-scoped write surface reusing the `resolveEntitledBusiness()` pattern exactly as Website Slice A does — i.e., a new, narrow controller action set under the existing Website guided-setup flow (§11) and/or a future dedicated `customer.workspaces.businesses.profile.*` route group, leaving the legacy flat `BusinessController` untouched.
- **Option B (larger, out of this contract's slice order):** Migrate `BusinessController@edit`/`@update` itself onto the Workspace/Business-uid tenancy chain, unifying the "primary business" V1 concept with the multi-Business model. This is an RFC-003/RFC-001 boundary change and is **explicitly out of scope for this contract** — recorded here only so the gap is not silently worked around.

This contract's implementation slices (§17) assume **Option A**.

### 3.4 Closing the page-count gap

Because the merged §2 evidence shows the "20 pages per Website" bound is enforced only inside `WebsiteAiDraftGenerator`'s batch check, and Guided Generation will create multiple pages deterministically (not exclusively through the AI path), this contract requires (Slice 1, §17) adding an explicit `WebsitePage::count()` ceiling of 20 directly inside `WebsiteDraftPageService::createPage()` — the single seam every page-creation path (manual, AI-generated, guided-generation) already funnels through. This is a **narrow extension of an existing, already-locked bound**, not a new product decision, and requires no entitlement or contract change beyond stating it here. A regression test proving the general ceiling (not just the AI-batch ceiling) is required in Slice 1's test plan (§19).

### 3.5 What this contract explicitly does not touch

Per the Slice A contract's own stop-list and this task's instructions: no change to `app/Library/Website/WebsiteSectionValidator.php`'s 8-type enum or its `match()` field rules beyond what §3.4 states; no new Website-facing form/survey/booking component; no B4 (Automations), B5 (Analytics), or Lane C (GBP) file is read as authoritative or modified; no Slice B custom-domain code.

---

## 4. CENTRAL BUSINESS KNOWLEDGE PROFILE — SCHEMA AND DATA OWNERSHIP

### 4.1 Ownership principle

The canonical facts already living on `businesses`, `business_locations`, `business_services`, and `customer_onboardings` are **never duplicated**. The Business Knowledge Profile owns only the facts §2's evidence table marks `MISSING` or the specific sub-fields marked `PARTIAL`. Every consumer (Website, COO, SEO, GBP, CRM, Ads, Automations) reads identity/contact/location/service facts from their existing tables directly, and reads the *new* facts from the tables below. No feature is permitted to cache or fork a private copy of any of these facts (mirrors the existing repository convention already enforced for Branding: `app/Library/Branding/BrandingPresenter.php`'s own single `Cache::rememberForever` seam is the only place platform branding is read from).

### 4.2 New tables

**`business_knowledge_profiles`** (one row per Business, 1:1):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid, unique | `HasUid` trait, `Str::uuid()` override (mirrors `Website::generateUid()`, `app/Models/Website.php:47-50`) |
| `business_id` | FK → `businesses.id`, unique, cascade delete | one profile per Business |
| `pricing_method` | string(24), nullable | enum-backed: `fixed`, `hourly`, `quote_only`, `package_tiers`, `financing_available` |
| `offers` | json, nullable | bounded array, max 12 entries, each `{name: string≤80, description: string≤300, price_label: string≤40}` — copy-only, never a sellable/bookable entity (no `Offer` model exists or is created; §2) |
| `differentiators` | json, nullable | bounded array of strings, max 6, each ≤120 chars |
| `ideal_customers` | text, nullable | ≤500 chars |
| `customer_problems` | json, nullable | bounded array of strings, max 6, each ≤160 chars |
| `credentials` | json, nullable | bounded array, max 10, each `{label: string≤120, verified: bool}` |
| `years_operating` | unsigned smallint, nullable | |
| `warranties_guarantees` | text, nullable | ≤500 chars |
| `primary_conversion_goal` | string(24), nullable | enum-backed: `call`, `quote_request`, `consultation_booking`, `calendar_booking`, `external_booking_link` |
| `conversion_target` | string(255), nullable | a `tel:`, `mailto:`, or `https://` value, validated through the **existing** `App\Library\Website\WebsiteUrlRules::isValid()` (`app/Library/Website/WebsiteUrlRules.php`) — never a new URL-validation rule |
| `brand_voice` | text, nullable | ≤500 chars |
| `prohibited_claims` | json, nullable | bounded array of strings, max 15, each ≤160 chars — hard-filtered out of every AI generation/rewrite request and re-checked in deterministic post-validation (§8.5) |
| `growth_priority_service_ids` | json, nullable | ordered array of `business_services.id` values belonging to this Business only (validated on write) |
| `growth_priority_location_ids` | json, nullable | ordered array of `business_locations.id` values belonging to this Business only |
| `reviews_source` | string(16), default `'none'` | enum-backed: `none`, `manual_verified` — see §4.5; `gbp_future` is **not** a value this contract creates (would require the GBP contract's own future Slice C; recorded as a human-review decision, §23) |
| `created_at`, `updated_at` | timestamps | |

**`business_hours`** (new column set — recommended as an **addition to `business_locations`**, not a new table, per the GBP contract's own explicit recommendation quoted in §2): `hours` json, nullable, on `business_locations`. Shape: `{"monday": {"open": "09:00", "close": "17:00"} | null, ..., "sunday": ..., "notes": string≤200 nullable}`. This is a **foundational Business fact**, owned by `business_locations` (already the row Website's own `WebsiteSnapshotBuilder::formatAddress()` reads, `app/Library/Website/WebsiteSnapshotBuilder.php:112`), not by this contract's own tables — Website, SEO, and GBP all read it from the same place. Migration ownership: this contract's Slice 1 (§17) adds this column since no other merged or in-flight contract currently claims it (confirmed: GBP contract explicitly declined to own it and named `business_locations` as the correct owner).

**`business_knowledge_profile_field_states`** (per-field provenance/verification — see §5):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `business_knowledge_profile_id` | FK, cascade delete | |
| `field_key` | string(64) | validated against a closed `BusinessKnowledgeProfileFieldKey` enum (§5.1) — never an arbitrary string |
| `source` | string(24) | enum-backed: `onboarding`, `website_setup`, `manual_edit`, `imported` |
| `verification_status` | string(24), default `'unverified'` | enum-backed: `unverified`, `customer_confirmed` |
| `verified_by_user_id` | FK → `users.id`, nullable | |
| `verified_at` | timestamp, nullable | |
| `updated_at` | timestamp | |

Unique composite index `(business_knowledge_profile_id, field_key)` — exactly one state row per tracked field per profile, upserted on every write (mirrors the existing single-row-per-key pattern already used by `platform_feature_usage_classifications`, one row per `PlatformFeature`).

**`business_knowledge_profile_changes`** (append-only audit ledger — see §5.3, following the GBP contract's own established bespoke-ledger pattern rather than a generic package):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `business_id` | FK, cascade delete | denormalized for cheap tenant-scoped queries, mirrors `business_google_operations`' own shape |
| `field_key` | string(64) | |
| `old_value` | text, nullable | JSON-encoded, truncated to 2000 chars |
| `new_value` | text, nullable | JSON-encoded, truncated to 2000 chars |
| `source` | string(24) | same enum as `field_states.source` |
| `actor_user_id` | FK → `users.id`, nullable | null for system-authored writes (e.g. onboarding backfill) |
| `created_at` | timestamp | no `updated_at` — write-once, immutable (mirrors `WebsiteRevision`'s `const UPDATED_AT = null;`, `app/Models/WebsiteRevision.php:21`) |

**`business_media_assets`** (Business-scoped image inventory — see §14; explicitly **not** a duplicate of `WebsiteAsset`):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid, unique | |
| `business_id` | FK, cascade delete | |
| `disk`, `path`, `mime_type`, `size`, `width`, `height`, `content_hash` | same shapes as `website_assets` | reuses the identical magic-byte-validated upload pattern from `App\Library\Website\WebsiteAssetUploadService` (§14.1) — a new `BusinessMediaUploadService` mirrors it exactly, does not extend or modify it |
| `purpose` | string(24) | enum-backed: `logo`, `hero`, `team`, `location`, `work_sample`, `other` |
| `alt_text` | string(160), nullable | |
| `usage_confirmed` | boolean, default false | customer must affirmatively confirm they own/are licensed to use the image before it becomes eligible for Website generation to reference it |
| `usage_confirmed_by`, `usage_confirmed_at` | FK/timestamp, nullable | |
| `created_at`, `updated_at` | timestamps | |

A guided-generation page build references a `business_media_assets` row's **content** by re-uploading it through the existing, unmodified `WebsiteAssetUploadService::store()` at generation time (creating a normal `WebsiteAsset` row scoped to that Website) — never a cross-table foreign key from `website_pages.sections` into `business_media_assets`. This preserves Slice A's existing invariant that a `WebsiteAsset`'s lifecycle (including the permanent-once-published `first_published_at` retention rule, `app/Models/WebsiteAsset.php`) is entirely Website-scoped and untouched by this contract.

### 4.3 Migration ownership and order

Six new migrations, in this order (mirrors Slice A's own precedent of naming migrations by dependency, `WEBSITE-GENERATION-HOSTING-CONTRACT.md` §33):
1. `create_business_knowledge_profiles_table`
2. `add_hours_to_business_locations_table` (single nullable JSON column, no default, backward compatible)
3. `create_business_knowledge_profile_field_states_table`
4. `create_business_knowledge_profile_changes_table`
5. `create_business_media_assets_table`
6. `backfill_business_knowledge_profiles_for_existing_businesses` (one row per existing `Business`, all nullable fields empty, `reviews_source = 'none'` — mirrors the exact backfill-migration pattern already used for `platform_feature_usage_classifications`, `database/migrations/2026_08_16_120008_backfill_platform_feature_usage_classifications.php`)

### 4.4 The single write seam

**`App\Library\Business\BusinessKnowledgeProfileManager`** (new class, mirrors `BusinessManager`'s existing shape exactly — `app/Library/Business/BusinessManager.php:57-192`) is the **only** code path permitted to write to `business_knowledge_profiles`, `business_knowledge_profile_field_states`, or `business_knowledge_profile_changes`. Its public surface:

- `getOrCreate(Business $business): BusinessKnowledgeProfile` — idempotent, creates an empty row if none exists (covers Businesses created before this contract's backfill or via any future path).
- `updateFields(Business $business, array $fields, string $source, int $actorUserId, bool $markVerified = false): BusinessKnowledgeProfile` — validates every key against the closed `BusinessKnowledgeProfileFieldKey` allowlist (§5.1), validates each value's shape (bounded array counts/string lengths per §4.2), writes the profile row, upserts one `field_states` row per changed key, and appends one `business_knowledge_profile_changes` row per changed key — all inside one `DB::transaction()` (mirrors `WebsiteDraftPageService::createPage()`'s transactional shape exactly).
- `completenessCheck(Business $business): BusinessKnowledgeProfileCompleteness` — a plain read-side DTO (never persisted) computed by inspecting the canonical Business/Location/Service tables **and** the profile/field-state tables together, returning exactly which of the fixed set of tracked facts (§5.1) are missing, stale (§5.4), or present, so callers (Website guided setup, and later SEO/COO) never reimplement this check.

No controller, job, or other service is authorized to `Model::create()`/`update()` these three tables directly — mirrors the exact seam discipline already established and mechanically tested for `WebsiteDraftPageService` (`tests/Feature/Website/WebsiteDraftPageServiceSeamTest.php`).

### 4.5 Reviews — deliberately narrow, matching GBP's own deferral

`reviews_source = 'manual_verified'` permits exactly one thing in this contract: a Business owner may, through `BusinessKnowledgeProfileManager::updateFields()`, attach a small number (max 5) of self-attested testimonial strings, each carrying `verification_status = 'customer_confirmed'` at the moment of entry (the same `field_states` mechanism governs this — no separate reviews table). This is **not** a public reviews platform, has no rating/star concept, and is never sourced from Google (GBP's own contract explicitly reserves durable review handling for an unbuilt future "Slice C" — this contract does not build toward that, does not read GBP tables, and does not create a `reviews` table). Website's `Testimonials` section type (already existing, `app/Enums/Website/WebsiteSectionType.php:18`) may render these strings verbatim (never AI-embellished) when present; when absent, AI-authored generic testimonial copy remains explicitly disallowed for this field (§8.5 — AI must never fabricate a review).

---

## 5. PROVENANCE, VERIFICATION, FRESHNESS, AND AUDIT

### 5.1 `BusinessKnowledgeProfileFieldKey` — the closed allowlist

A new PHP enum (`app/Enums/Business/BusinessKnowledgeProfileFieldKey.php`) with exactly the fields tracked in §4.2's `business_knowledge_profiles` table plus `hours` (tracked against its owning `business_locations` row, not the profile row, but sharing the same `field_states` provenance mechanism keyed by `business_id` — the `field_states` table's FK is nullable-flexible enough to record a location-owned fact; the exact composite key is `(business_knowledge_profile_id, field_key)` where `field_key = 'hours'` always resolves back to `Business::primaryLocation()`, never a secondary-location hours fact in v1). No other string is ever accepted as a `field_key` — `BusinessKnowledgeProfileManager::updateFields()` rejects any key not in this enum with a `ValidationException`, exactly mirroring `WebsiteSectionValidator`'s closed-enum rejection behavior for unknown section types.

### 5.2 Verification status semantics

- `unverified`: the value was written by AI inference, an import, or a system default — never shown to a customer as an established fact without a review prompt, and never quoted verbatim in AI-authored public copy without the completeness check first asking the customer to confirm it (§11 step 2-3).
- `customer_confirmed`: the value was explicitly entered or affirmed by an authenticated Business-accessible user (`WorkspaceManager::userCanAccessBusiness()` — owner/admin/staff-with-scope, same population as everyone else in this codebase who can mutate Business data). Only `customer_confirmed` facts are eligible to be marked "verified" in generated copy claims (a direct requirement of §6/§7's product direction — SEO/AI must never fabricate credentials, warranties, years-operating, or reviews).

### 5.3 Audit/change-log behavior

Every `BusinessKnowledgeProfileManager::updateFields()` call appends one `business_knowledge_profile_changes` row per changed `field_key`, following the exact established repository precedent (`business_google_operations`, quoted in §2) of a bespoke, append-only, per-domain ledger rather than a generic activity-log package (none exists in `composer.json`, confirmed). Admin inspection of this ledger is out of this contract's implementation slices (§17) — it exists to make the freshness/verification claims in §5.4 auditable later, not to ship an admin UI now.

### 5.4 Freshness

No new polling/cron clock is introduced. Freshness is derived, at `completenessCheck()` time, from two things: (a) `field_states.verified_at` compared against a fixed `reconfirm_after_days` default of 180 days per field (a single constant on `BusinessKnowledgeProfileFieldKey`, not configurable per-Business in v1 — a human-review decision if per-tier tuning is wanted later, §23), and (b) whichever owning row's own `updated_at` is more recent (e.g., if `businesses.phone` changes after a website was generated, the completeness check surfaces that the underlying fact moved even though `field_states` doesn't track platform-native columns — the check reads `businesses`/`business_locations`/`business_services`' own timestamps directly for those, and only uses `field_states` for genuinely-new profile fields it owns).

---

## 6. TEMPLATE MANIFEST AND RENDERER BOUNDARY

### 6.1 Four-template evidence status — explicit, not invented

**Confirmed absent.** No GoHighLevel export, screenshot, HTML/CSS bundle, or design asset of any kind exists anywhere in this repository (§2 evidence row). This contract does **not** claim to have inspected, and does not describe the visual content of, the four templates the product direction references. Implementing the four templates is **blocked** until the human supplies the exact materials listed in §6.2.

### 6.2 Required import materials (human-review deliverable, tracked in §23)

For each of the four approved templates, the human must supply:
1. A static export or a set of full-page screenshots (desktop + mobile breakpoint) of every distinct page type the template uses.
2. The exact page list and, per page, the exact ordered list of visual sections it contains, each one mapped by a human (not inferred by this contract) onto one of Website Slice A's existing 8 `WebsiteSectionType` cases (§2) — or flagged as **unsupported and requiring a future, separately-contracted section-type extension** if no existing type fits. This contract does not invent a 9th section type to fit unseen material.
3. The template's color palette, font pairing, and button/spacing conventions, translated into Slice A's existing bounded `websites.theme` JSON shape (`WEBSITE-GENERATION-HOSTING-CONTRACT.md` §18 — font-family allowlist, primary/secondary color, button style, content width, header/footer variant; no arbitrary CSS).
4. Any imagery the template itself supplies as stock/placeholder art, with an explicit license/usage statement — this contract's own image policy (§14) never fabricates business imagery, so template-supplied stock art must be either (a) genuinely license-cleared for reuse across customers, or (b) excluded, with the missing-image checklist (§14.3) covering the gap per-customer.

### 6.3 Template manifest schema (once materials exist)

**`website_templates`** (new table; deliberately not customer-editable — an operator/platform-seeded catalog, mirroring how `websites.theme` is already bounded and how `WebsiteSectionValidator`'s rules are code, not data):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `key` | string(40), unique | e.g. `template_a`, `template_b` — stable identifier, never the display name |
| `display_name` | string(80) | |
| `theme` | json | a value conforming exactly to Slice A's existing `websites.theme` shape — no new theme dimension |
| `page_manifest` | json | ordered list of `{page_type: string, is_home: bool, allowed_section_types: string[8-enum-subset], default_section_order: string[]}` — every `allowed_section_types`/`default_section_order` entry **must** be one of the existing 8 `WebsiteSectionType` values; the manifest is validated against that enum at seed time, not at request time, so an invalid template can never reach a customer |
| `preview_image_path` | string(255), nullable | a platform-owned static asset, never customer-uploaded |
| `is_active` | boolean, default true | operator-controlled retirement switch |
| `created_at`, `updated_at` | timestamps | |

### 6.4 Renderer boundary

The renderer (`resources/views/public/website/page.blade.php` and its 8 component partials — all pre-existing, unmodified) already accepts exactly the `{type, data}` section shape `WebsiteSectionValidator` validates. A template's `page_manifest` is consumed **only** at generation time (§8) to decide which pages/sections/order to create via the existing `WebsiteDraftPageService::createPage()` — it is never read at render time, and the renderer itself gains no new template-awareness. This keeps the render path exactly as narrow and already-tested as it is today (`tests/Feature/Website/Public/WebsitePublicRenderingTest.php`).

---

## 7. QUESTION-PACK DESIGN

### 7.1 General framework

**`question_packs`** (new table, operator-seeded, versioned):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `key` | string(40) | e.g. `general_v1`, `roofing_v1`, `photobooth_v1` |
| `applies_to_industry` | string(40), nullable | a `BusinessIndustry` enum value, or null for the general/base pack |
| `version` | unsigned int | packs are immutable once referenced by any completed generation; a new question or changed wording ships as a new `version` row under the same `key`, never an in-place edit — mirrors `WebsiteRevision`'s own immutability discipline |
| `questions` | json | ordered array of `{field_key: BusinessKnowledgeProfileFieldKey value, prompt: string, input_type: 'text'|'textarea'|'select'|'multi_select'|'boolean', options: string[] nullable, required: bool}` |
| `is_active` | boolean | |
| `created_at`, `updated_at` | timestamps | |

Every `field_key` in a pack's `questions` array is validated at seed time against `BusinessKnowledgeProfileFieldKey` (§5.1) — a question pack can only ever collect facts the Profile already knows how to store; it cannot invent a new fact shape at question-authoring time. This is the mechanism that keeps industry variance (roofing vs. photobooth) cheap: **the field taxonomy is fixed and general; only the wording/options per industry vary.**

### 7.2 Pack selection

`BusinessKnowledgeProfileManager::completenessCheck()` selects: the pack where `applies_to_industry === $business->industry` and `is_active = true`, at the highest `version`; if none matches the Business's specific `BusinessIndustry`, falls back to the `general_v1` pack (`applies_to_industry = null`). This is a plain, deterministic lookup — no AI involved in pack selection.

### 7.3 Worked examples (illustrative content only — not seeded by this contract; an operator authors the real copy)

- **Roofing** (`BusinessIndustry::HomeServices` is the closest existing enum case — `roofing` itself is not a distinct `BusinessIndustry` value today; a human-review decision (§23) is whether to add a `Roofing` case or keep roofing questions under the general `HomeServices` pack): questions targeting `pricing_method` (financing available?), `credentials` (license number, insurance), `offers` (repair/replacement/inspection as separate offer entries), `primary_conversion_goal` defaulting to `quote_request`, emergency-availability captured via the new `business_locations.hours` "notes" field rather than inventing a new column.
- **Photobooth** (`BusinessIndustry::PhotoBoothService`, already exists): questions targeting `offers` (package tiers with `price_label`), `differentiators` (booth styles), `service_area_cities` (already exists on `business_locations`, reused not duplicated), `primary_conversion_goal` defaulting to `calendar_booking` or `external_booking_link`, deposit/travel-fee facts captured as `offers[].description` text rather than new columns.

### 7.4 Extension cost

Adding a fifth industry pack is: one seeded `question_packs` row (or a version bump of an existing one) plus, only if genuinely new fact shapes are needed, a `BusinessKnowledgeProfileFieldKey` enum addition and a migration — never new code paths in the controller, generator, or validator. This is the "inexpensive to extend" property the product direction requires, achieved by keeping the field taxonomy fixed and industry variance confined to data (question wording) rather than code.

---

## 8. GENERATION ARCHITECTURE, AI REQUEST/OUTPUT SCHEMAS, AND COST CONTROLS

### 8.1 The 80/20 split, mapped onto existing code

| Owned by deterministic code (existing or narrow extension) | Owned by AI (bounded) |
|---|---|
| Allowed page/section structures — `website_templates.page_manifest` (§6.3) validated against the existing `WebsiteSectionType` enum | Draft copy for each section, from structured facts |
| Responsive layout, heading hierarchy, CTA placement — existing Blade partials, unmodified | FAQ suggestions (bounded count, from `WebsiteSectionType::Faq`'s existing `items max:20` rule) |
| Metadata constraints (`seo_title max:70`, `meta_description max:160` — `WebsiteDraftPageService::validateAttributes()`, unchanged) | Titles/meta descriptions within those existing limits |
| Schema envelope — Slice A's existing snapshot/publish model, unmodified | Recommending which `primary_conversion_goal` fits the collected facts (a suggestion the customer confirms, never silently applied) |
| Business identifiers/contact facts, map/location facts — read directly from `businesses`/`business_locations`, never AI-authored | One bounded final quality pass (§8.6) |
| Accessibility/internal-link/technical-SEO validation (§8.5, new deterministic validators) | Targeted single-section rewrite on demand (§8.7) |
| Template styling/rendering — existing renderer, unmodified | |

### 8.2 One bounded structured generation request

**`App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient`** (new class, deliberately mirroring `WebsiteAiGenerationClient`'s exact fail-closed shape — `app/Library/Website/WebsiteAiGenerationClient.php:21-43` — reusing the identical `config('services.openai.*')` seam, never a new config key) issues **one** request per generation attempt: a single chat completion with `response_format: {type: 'json_object'}`, containing the entire selected template's `page_manifest`, every `customer_confirmed` and `unverified`-but-present Profile fact (with `unverified` facts explicitly labeled as such in the prompt so the model never treats them as more certain than they are), and the `prohibited_claims` list as a hard instruction. This mirrors `WebsiteAiDraftGenerator::buildContext()`/`buildMessages()` exactly, extended to read from the Profile (§4) rather than only the five raw `Business` fields Slice A's own generator reads today.

### 8.3 Request/output schema

Request messages: `[{role: 'system', content: <fixed instruction text, ≤4000 tokens, includes the exact allowed section-type list, the field-length limits from §4.2, and the prohibited_claims list>}, {role: 'user', content: <JSON-encoded {template_key, pages: [{page_type, is_home}], facts: {field_key: value|null, verification: 'confirmed'|'unverified'}[]}>}]`.

Expected output (schema-validated before any persistence, via a new `GuidedGenerationOutputValidator` that wraps the existing `WebsiteSectionValidator::validate($sections, $validAssetUids, allowAssetReferences: false)` call per page — identical `allowAssetReferences: false` discipline `WebsiteAiDraftGenerator` already enforces, §2):
```json
{
  "pages": [
    {
      "page_type": "home",
      "is_home": true,
      "seo_title": "string ≤70",
      "meta_description": "string ≤160",
      "sections": [{"type": "hero", "data": { /* exact existing WebsiteSectionType::Hero shape */ }}, ...]
    }
  ],
  "warnings": ["string — e.g. 'no credentials confirmed; omitted from copy'"]
}
```
`warnings` is a new, additive output field (not present in Slice A's own `WebsiteAiDraftGenerator` schema) — it is how the AI is required to surface, to the customer review step (§11 step 10), any fact it could not confidently use (e.g., an `unverified` credential it declined to state as fact). It is never used to bypass deterministic validation; a warning does not make otherwise-invalid output acceptable.

### 8.4 Idempotency, retries, ceilings

- **Idempotency key**: `sha256(business_id . template_key . profile_updated_at . field_states_max_updated_at)` — a repeated generation request with unchanged inputs returns the previously-stored draft rather than issuing a new AI call (stored in a new `website_guided_generation_attempts` table, §8.8). This is the same "store generated results, never regenerate on page load" requirement as Slice A's own publish/revision model already enforces for published content — this contract extends the identical discipline to the *draft* generation step, which Slice A's `WebsiteAiDraftGenerator` does not currently need (it only ever runs once, before any page exists).
- **Retry ceiling**: exactly one corrective retry on schema-validation failure, identical to `WebsiteAiDraftGenerator::generate()`'s existing "at most one bounded automatic retry" (`app/Library/Website/WebsiteAiDraftGenerator.php`, confirmed in §2). No unbounded retry loop is ever introduced.
- **Token/output ceilings**: request-side, the system prompt is capped at a fixed ≤4000-token budget (enforced by truncating the least-recently-verified, lowest-priority Profile facts first if the full fact set would exceed it — deterministic truncation order, never AI-decided); response-side, `max_tokens` is set on the `OpenAI::client()->chat()->create()` call (a new parameter Slice A's own `WebsiteAiGenerationClient::complete()` call does not currently pass — this contract's client passes it explicitly, sized to the template's page count).
- **Per-Business usage reservation and ledger integration**: every generation attempt calls `UsageWalletManager`-shaped reservation logic **once RFC-005's existing, currently-unactivated metering path is turned on for a new `PlatformFeature` this contract does not itself define** (see §9) — until then, generation attempts are recorded in `website_guided_generation_attempts` (§8.8) for count-based (not dollar-based) monthly caps, gated in application code, not through the wallet.
- **Monthly caps and per-feature limits**: see §9.
- **Cheap-model-first routing**: `config('services.openai.model')` remains the single configured model (no `NOT FOUND` model-tiering exists to build on, §2); this contract adds one new, narrowly-scoped config value `config('services.website_guided_generation.model')` defaulting to the same `env('OPENAI_MODEL', 'gpt-4o')` value, so an operator can point guided generation at a cheaper model independently of other AI seams **without inventing a general routing policy** — escalation (e.g., retry-on-a-stronger-model) is explicitly **not** built in this contract; the one retry (§8.4) reuses the same configured model.
- **No standard AI-image generation**: confirmed nowhere in scope; §14 defines the missing-image checklist instead.

### 8.5 Deterministic post-validation (new, beyond Slice A's existing `WebsiteSectionValidator`)

A new `GuidedGenerationOutputValidator` runs, in order, after schema validation and before any page is created:
1. Every `WebsiteSectionValidator::validate()` call (reused, unmodified) — malformed sections fail closed exactly as today.
2. **Prohibited-claims scan**: case-insensitive substring match of every `prohibited_claims` entry against every generated string field; any match fails the attempt (counted toward the retry ceiling, §8.4).
3. **Unverified-fact scan**: any generated copy that states a `credentials`, `years_operating`, `warranties_guarantees`, or `reviews_source` fact as settled truth when that field's `field_states.verification_status` is `unverified` fails the attempt — this is the concrete mechanical enforcement of §10's "never fabricate credentials/warranties" rule, checked in code, not trusted to prompt instructions alone.
4. **Accessibility validation**: every image-bearing section (`hero.background_image`, `image_text.image`, `services.items[].image`) must carry non-empty `alt_text` sourced from `business_media_assets.alt_text` (§14) — a section referencing an asset with no `alt_text` fails closed rather than publishing with an empty `alt` attribute.
5. **Internal-link validation**: any CTA URL that is a relative/internal-looking path must resolve to an actual page in the same generation batch (by `page_type`); a dangling internal link fails the attempt.
6. **Technical SEO validation**: exactly one page per batch has `is_home = true` (reuses `WebsitePublisher::validateDraft()`'s existing exactly-one-homepage rule by construction — the batch is run through the same check before commit), and no two pages in the batch share a slug (reuses `WebsiteDraftPageService`'s existing per-Website slug-uniqueness check, since the batch is committed page-by-page through that exact seam, §8.6).

### 8.6 Commit path — reuses `WebsiteDraftPageService`, never bypasses it

Once a batch passes §8.5, each page is created via `WebsiteDraftPageService::createPage()` (unmodified) exactly as `WebsiteAiDraftGenerator::generate()` already does today (§2) — Guided Generation adds no second way to write `website_pages`. The bounded final quality pass (a second, optional AI call reviewing the already-validated draft for tone/consistency only, never re-touching structure) is out of Slice 1/2's implementation order (§17) and, if built later, must run **before** commit, on the same validated batch, never as a post-commit mutation.

### 8.7 Targeted section rewrite

A customer may request a rewrite of exactly one existing, already-created section (identified by `website_pages.uid` + section index) through a new, narrow endpoint. The rewrite request reuses the identical `GuidedWebsiteGenerationClient`/`GuidedGenerationOutputValidator` pipeline scoped to one section's schema only, and commits through `WebsiteDraftPageService::updatePage()` (unmodified) — never a whole-site regeneration. This directly satisfies the "targeted rewrites, never mandatory whole-site regeneration" safeguard.

### 8.8 `website_guided_generation_attempts` (new table — the idempotency/retry/audit record)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid | |
| `website_id` | FK, cascade delete | |
| `idempotency_key` | string(64), indexed | sha256 per §8.4 |
| `attempt_type` | string(24) | `full_generation`, `section_rewrite`, `quality_pass` |
| `status` | string(24) | `pending`, `succeeded`, `failed`, `retried` |
| `warnings` | json, nullable | the `warnings` array from §8.3's output schema |
| `failure_reason`, `created_at`, `completed_at` | | |

No raw AI prompt/response body is stored here (mirrors Slice A's own AI seam, which stores no OpenAI transcript anywhere — confirmed by `WebsiteAiSeamUnchangedTest`'s existing no-secret-leakage assertion, §2) — only the bounded metadata needed for idempotency and count-based caps.

---

## 9. COST/BUDGET CONTROLS AND USAGE-LEDGER INTEGRATION

### 9.1 What exists and what this contract does with it

RFC-005's usage wallet infrastructure (§2 evidence row) is real, generic, and keyed by `PlatformFeature` — but every feature remains `is_metered = false`, and the only activation command is hardcoded to `Conversations`. This contract does **not** activate metering for AI generation (that would require a new, separately-authorized `ActivateWebsiteGuidedGenerationUsageRate`-shaped command and a real numeric rate — an RFC-005 M5-style operator step, explicitly out of this contract's slice order). Instead:

- **v1 (this contract's implementation slices):** count-based caps only, enforced in application code by counting rows in `website_guided_generation_attempts` scoped to `business_id` and a rolling 30-day window, checked before issuing an AI request — no wallet, no dollar amount, no `UsageAuthorizationGateway` change.
- **Future (explicitly not this contract):** once RFC-005 metering is genuinely activated for a `website_guided_generation` (or reused `ai_coo_basic`) feature classification, the count-based cap is replaced by a real `UsageWalletManager::evaluateCoarseCapacity()`-backed reservation, and `EntitlementManager::decide()`'s existing usage-authorization step (`app/Library/Entitlement/EntitlementManager.php:180-184`) starts returning real `usage_unauthorized` denials for this feature — no code path in this contract needs to change for that future flip; it is designed to be dormant-compatible from day one.

### 9.2 Exact v1 caps (defaults — see §13 for the trial-specific numbers, and §23 for what remains a human decision)

| Cap | Default | Enforced by |
|---|---|---|
| Full generations per Business per calendar month | 4 (Core), 8 (Growth), unlimited-but-rate-limited at 1/hour (Agency) | count of `attempt_type = 'full_generation'` rows |
| Section rewrites per Business per calendar month | 20 (Core), 40 (Growth), 100 (Agency) | count of `attempt_type = 'section_rewrite'` rows |
| Concurrent in-flight attempts per Website | 1 | a `pending`-status row for the same `website_id` blocks a new attempt (returns a "generation already in progress" response, not a queue) |

These are recorded as defaults this contract recommends, not permanently locked numbers — see §23 for the exact human-review framing (mirrors this contract's own trial-limits framing in §13).

---

## 10. COO AND SEO SAFETY BOUNDARY

### 10.1 No competing "Website Worker" — the exact existing seam to use instead

RFC-002's Opportunity Engine (§2 evidence row) already reserves `OpportunityWorkerKey::Website = 'website'` and already states its own intent (*"Enables: SEO, Content, Sales, Reputation, and Website workers (future RFCs)"*) precisely so that no future feature invents a second recommendation brain. This contract's Slice 3+ (§17) — explicitly **not** required for a functioning generation product and therefore deferred — is to implement `App\Library\Opportunity\WebsiteOpportunityProducer implements OpportunityProducer` (same interface Business Advisor already implements, `app/Library/Opportunity/OpportunityProducer.php:17-25`), reusing `BusinessKnowledgeProfileManager::completenessCheck()` as its fact source exactly the way `BusinessAdvisorOpportunityProducer` already reuses `InitialBusinessSnapshotBuilder` (`app/Library/Opportunity/BusinessAdvisorOpportunityProducer.php:59-89`). This is the "AI COO is the central brain" requirement satisfied with zero new orchestration engine — the COO, whenever `PlatformFeature::AiCooBasic` eventually ships real code, is expected to consume the same `Opportunity`/`OpportunityRun` rows every other worker produces, including this one.

### 10.2 Core vs Growth/Agency behavior

| Tier | Website Guided Generation behavior |
|---|---|
| **Core** | Generation, correct bounded structure, basic metadata, accessibility checks (§8.5), bounded schema, publishing/revisions/rollback (all pre-existing Slice A), and on-demand single-section rewrite (§8.7). No scheduled/ongoing analysis. |
| **Growth** | Everything in Core, plus: once `WebsiteOpportunityProducer` (§10.1) exists, its runs are scheduled (reusing whatever cron/queue mechanism RFC-002's existing `RunBusinessAdvisorOpportunityProducer` job pattern already establishes — `app/Jobs/Opportunity/RunBusinessAdvisorOpportunityProducer.php` — never a new scheduler). Opportunity types surfaced: missing/incomplete Profile facts affecting the live published Website, GBP/Website consistency (once GBP Slice A ships and only by comparing already-public facts — never by GBP reading Website tables, preserving GBP's own stated non-dependency, §2), and service/location content-gap opportunities (e.g., an active `business_service` with no corresponding Website page). |
| **Agency** | Everything in Growth, at the higher rate-limits in §9.2, plus (out of this contract's slices, recorded for completeness) White Label consumption of the same generation pipeline for agency-managed sub-businesses — no new tenancy model, since Website Slice A's existing multi-Business-per-Workspace chain already supports this. |

This mapping is intentionally silent on packaging `PlatformFeature`s beyond what already exists (`WebsiteGeneration` — Core/Growth/Agency, `AiCooBasic`/`SeoBasicVisibility`/`SeoModule` — still `Planned`, unmodified by this contract). No new `PlatformFeatureRegistry` entry or packaging-migration change ships in this contract's slices; the Growth/Agency behaviors above become active only once `WebsiteOpportunityProducer` itself ships (Slice 3+, explicitly deferred).

### 10.3 SEO boundary — draft-only, human-approved, never fabricated

Everything this contract's AI touches produces a **draft** subject to the exact same human-approved publish gate Slice A already enforces (`WebsitePublisher::publish()`, requiring an explicit customer action — no code path in this contract calls `publish()` automatically). SEO-specific claims — rankings, reviews, credentials, prices, hours, locations, warranties, service areas — are never AI-fabricated: §8.5's deterministic validator (steps 2-3) is the concrete enforcement, not merely a prompt instruction. Page content, uploaded filenames, alt text, and any future imported template material (§6.2) are treated as **untrusted input** to any AI call — the existing `WebsiteSectionValidator`'s strict allowlist-per-type shape already prevents arbitrary content from reaching a prompt unvalidated; this contract's `GuidedWebsiteGenerationClient` never concatenates raw uploaded filenames or alt text into a prompt without the same validation pass. No silent auto-publishing exists anywhere in this design.

---

## 11. GENERATION STATE MACHINE

1. **Select Business** — existing `resolveEntitledBusiness()` chain (§3.1); no change.
2. **COO completeness evaluation** — `BusinessKnowledgeProfileManager::completenessCheck()` (§4.4) runs synchronously (it is a set of indexed reads, not an AI call) and returns the exact missing/stale fields.
3. **Ask missing questions** — the selected `question_pack` (§7.2), filtered to only the fields `completenessCheck()` flagged; already-`customer_confirmed`, non-stale fields are never re-asked.
4. **Request missing assets** — driven by the missing-image checklist (§14.3), never a blocking hard-stop; a customer may proceed without every image, accepting the deterministic fallback (§14.4).
5. **Recommend/show four template choices** — reads `website_templates` (§6.3), filtered to `is_active = true`; if the templates do not yet exist (§6.1's current blocked state), this step cannot ship — recorded as a stop condition (§22).
6. **Customer selects template** — a plain write of `website_id`/chosen `website_templates.key` reference (a new nullable `template_key` column on `websites`, the only Slice-A-table column this contract adds, since Slice A's own `websites` table has no template concept — everything else about `websites` is reused unmodified).
7. **Generate structured draft** — §8.2-8.4.
8. **Deterministic validation** — §8.5.
9. **Optional bounded quality pass** — §8.6's second paragraph; deferred slice.
10. **Customer reviews warnings and claims** — the `warnings` array (§8.3) and any `unverified`-fact omissions are surfaced in the existing page-form/preview UI (`resources/views/customer/business/website/page-form.blade.php`, unmodified structurally, extended with a warnings panel).
11. **Preview** — Slice A's existing `WebsiteController::preview()`, unmodified.
12. **Human-approved publish** — Slice A's existing `WebsitePublisher::publish()`, unmodified.
13. **Immutable revision and rollback** — Slice A's existing `WebsiteRevision`/`WebsitePublisher::rollback()`, unmodified.
14. **Later COO recommendations triggered only by meaningful events** — §10.1/§10.2; a "meaningful event" is defined narrowly as: a `WebsitePublished` dispatch (already exists, zero listeners today — this contract's Slice 3+ would be the first listener, and it would only *enqueue* an Opportunity-engine run, never mutate Website data itself), a `BusinessKnowledgeProfileManager::updateFields()` call that changes a fact referenced by the live published snapshot, or a new active `business_service`/`business_location` with no corresponding published page. No time-based "just check periodically for no reason" trigger is introduced beyond whatever cadence RFC-002's own existing worker-scheduling convention already uses for `BusinessAdvisor`.

### Failure, cancellation, retry, partial-generation, supersession, stale-data behavior

- **Failure** (AI fails closed, or the single retry also fails): the attempt is marked `failed` in `website_guided_generation_attempts`; zero pages are created (mirrors `WebsiteAiDraftGenerator::generate()`'s existing "returns false, never partial" behavior exactly — this contract's batch commit in §8.6 is likewise all-or-nothing per page but the OVERALL batch may partially succeed at the page level only after §8.5 validation passes for that page individually; a page that fails validation is simply omitted from the created set and reported in `warnings`, never silently invented).
- **Cancellation**: a customer may abandon a `pending` attempt; it is marked `failed` with `failure_reason = 'cancelled'` on the next request for that Website (no background cancellation signal needed since generation is synchronous within one request per §8.2's single bounded call).
- **Retry**: exactly the one bounded retry already described (§8.4); a customer-initiated "try again" after a terminal `failed` status is a **new** attempt with a **new** idempotency key (since retrying implies something about the input may need to change, even if it's just the customer's own edited answers).
- **Partial generation**: see Failure above — page-level granularity, never silently fabricated content to "complete" a batch.
- **Supersession**: a new `full_generation` attempt against a Website that already has draft pages is rejected by reusing `WebsiteAiDraftGenerator`'s existing "AI generation is only available before any pages exist on this Website" rule's *spirit* — but Guided Generation's own generator must explicitly check `$website->pages()->exists()` **only for the specific pages the new template would create** (a template swap after initial generation is an explicit, customer-visible "replace this page's content" action per page, going through §8.7's rewrite path, never a silent bulk supersession).
- **Stale-Business-data**: if `completenessCheck()` detects a `field_states.verified_at` past the 180-day `reconfirm_after_days` threshold (§5.4) for a fact actually used in the *live published* snapshot, the next COO evaluation (state 2, on next visit, or the Growth-tier scheduled Opportunity run, §10.2) surfaces a "please confirm this hasn't changed" prompt — it never silently re-generates or silently re-publishes.

---

## 12. TRIAL AND DOMAIN BOUNDARIES

### 12.1 Domain rules — settled

Bring-your-own-domain only. The customer owns and pays their own registrar. The platform never registers, purchases, renews, warehouses, or takes ownership of any domain. The customer only *connects* an owned domain to their generated Website. Ownership verification, routing, automatic SSL, detach, and expiry behavior all belong to a future custom-domain/hosting slice and are **not implemented here** — this matches Slice A's own already-locked §40 boundary (*"CUSTOM DOMAINS — SLICE B BOUNDARY (future contract, not designed here)"*) exactly; this contract adds nothing to that boundary and does not touch `routes/public.php`'s hostname-agnostic routing.

### 12.2 Trial — recommended default, explicitly not settled

The user has not approved exact numbers. This contract recommends the following **as a starting proposal for human review**, reasoned from the cost controls already defined in §8-9:

| Trial parameter | Recommended default | Cost-control reasoning |
|---|---|---|
| Businesses | 1 | matches the existing one-Website-per-Business invariant exactly; no new tenancy concept needed for a trial |
| Full generations | 1 | bounds AI spend to exactly one `full_generation` attempt (plus its one built-in retry, §8.4) — the single largest-token AI call in this whole design |
| Generated pages | ≤6 | small enough that even a worst-case 40-section-per-page (§2, already-enforced ceiling) trial draft stays within a bounded, predictable token/output cost, while large enough to demonstrate a real multi-page site (home + 3-5 interior pages) |
| Targeted rewrites | 3 | lets a trial customer meaningfully iterate without opening the door to unlimited AI spend per trial account |
| Platform preview | temporary, tied to trial expiry | reuses Slice A's existing `preview()` action (§11 step 11) unmodified — no new preview mechanism |
| Custom domain connection | at most 1, optional | reuses whatever domain-connection mechanism a future Slice B ships; this contract does not build it, and a trial account without a domain simply never exercises that step |
| AI-generated images | 0 (none) | matches §14's platform-wide "no standard AI-image generation" rule exactly — this is not a trial-specific restriction, it applies to every tier |
| Recurring/scheduled SEO monitoring | none | the Growth-tier scheduled Opportunity-engine behavior (§10.2) is explicitly not part of a trial, matching Core's own "no scheduled/ongoing analysis" row |
| Expiry behavior | the draft (and any published revision) is retained; public serving stops (the existing `WebsitePublicEntitlementGate`, §2, already 404s any Business whose entitlement decision denies — a trial-expired Business is simply routed through the exact same `not_entitled_by_plan`/`plan_inactive` denial reasons `EntitlementManager::decide()` already returns, §2); restoration is immediate on payment because nothing was deleted, only entitlement-gated |

This table is a **recommendation**, not a locked decision — see §23 item 1.

---

## 13. IMAGE AND ALT-TEXT POLICY

### 13.1 No standard AI-image generation

Confirmed nowhere in scope (§2); this contract does not add an image-generation API call anywhere.

### 13.2 Upload-first, license-aware

`business_media_assets` (§4.2) is the customer's own upload inventory, using the exact magic-byte/content-hash security pattern already proven in `WebsiteAssetUploadService` (§2) via a new, narrowly-scoped `BusinessMediaUploadService` that mirrors it method-for-method (never modifying or extending the existing Website-scoped service). Every upload requires `usage_confirmed = true` before it becomes eligible for generation to reference — an unconfirmed upload is visible to the customer but invisible to `GuidedWebsiteGenerationClient`'s context-building step.

### 13.3 Missing-image checklist

`completenessCheck()` (§4.4) additionally computes, per selected template's `page_manifest` (§6.3), which image-bearing section slots (hero background, per-service image, team photo, etc.) have no eligible (`usage_confirmed = true`) `business_media_assets` row of the matching `purpose`. This checklist is surfaced at generation-journey step 4 (§11) as a plain list — never a fabricated placeholder image silently inserted without the customer's knowledge.

### 13.4 Deterministic fallback when an image is genuinely missing

A section whose manifest calls for an image but has none eligible is generated **without** that image field populated (the existing `WebsiteSectionValidator` rules already treat `background_image`/`image` as `nullable`, §2) — the deterministic renderer (unmodified) already handles an absent image gracefully (a themed background color/gradient, per the existing bounded `websites.theme`). No stock photo, no AI-generated image, no silently-substituted business imagery is ever inserted.

---

## 14. EVENT/PUBLISH INTEGRATION

No change to `WebsitePublished`'s payload (`app/Events/Website/WebsitePublished.php` — `websiteId`, `websiteRevisionId`, `businessId`, unmodified) or dispatch sites (`WebsitePublisher::publish()`/`rollback()`, unmodified). This contract's only event-integration work is the future, deferred (§10.1) `WebsiteOpportunityProducer` becoming the **first** listener of `WebsitePublished` — and even then, per RFC-002's own worker contract (`app/Library/Opportunity/OpportunityProducer.php:12-15`, *"performs no writes, no transactions, no repository calls, and no external I/O"*), that listener would only ever **enqueue an Opportunity-engine run**, never mutate Website or Business data directly.

---

## 15. IMPLEMENTATION SLICES AND DEPENDENCY ORDER

| Slice | Scope | Depends on | Allowlist |
|---|---|---|---|
| **Slice 1 — Business Knowledge Profile foundation** | 6 migrations (§4.3), `BusinessKnowledgeProfile`/`BusinessKnowledgeProfileFieldState`/`BusinessKnowledgeProfileChange` models, `BusinessKnowledgeProfileManager` (§4.4), `BusinessKnowledgeProfileFieldKey` enum (§5.1), the §3.4 page-count-ceiling fix inside `WebsiteDraftPageService::createPage()` | none (pure additive schema + one narrow existing-file extension) | `database/migrations/*business_knowledge_profile*`, `database/migrations/*add_hours_to_business_locations*`, `app/Models/BusinessKnowledgeProfile*.php`, `app/Library/Business/BusinessKnowledgeProfileManager.php`, `app/Enums/Business/BusinessKnowledgeProfileFieldKey.php`, `app/Library/Website/WebsiteDraftPageService.php` (page-count check only) |
| **Slice 2 — Question packs + completeness UI** | `question_packs` table/model, `completenessCheck()`, the guided-setup controller actions/views (Option A, §3.3) | Slice 1 | new controller under `app/Http/Controllers/Customer/Business/`, new views under `resources/views/customer/business/website/`, `database/migrations/*question_packs*` |
| **Slice 3 — Template manifest + selection** | `website_templates` table/model, template-selection UI, `websites.template_key` column | Slice 2, **and** §6.2's human-supplied import materials (blocked otherwise) | `database/migrations/*website_templates*`, `database/migrations/*add_template_key_to_websites*` |
| **Slice 4 — Generation pipeline** | `GuidedWebsiteGenerationClient`, `GuidedGenerationOutputValidator`, `website_guided_generation_attempts` table, full-generation + section-rewrite endpoints, §9.2 count-based caps | Slice 3 | `app/Library/Website/GuidedGeneration/*.php`, `database/migrations/*guided_generation_attempts*`, narrow additions to `app/Http/Controllers/Customer/Business/WebsiteController.php` for the two new actions |
| **Slice 5 (deferred, separately contracted) — Business media assets** | `business_media_assets` table, `BusinessMediaUploadService`, missing-image checklist | Slice 1 | `database/migrations/*business_media_assets*`, `app/Library/Business/BusinessMediaUploadService.php` |
| **Slice 6 (deferred, separately contracted) — Opportunity Engine integration** | `WebsiteOpportunityProducer`, its job, `WebsitePublished` listener | Slice 4 | `app/Library/Opportunity/WebsiteOpportunityProducer.php`, `app/Jobs/Opportunity/RunWebsiteOpportunityProducer.php`, `app/Providers/EventServiceProvider.php` (one new listener registration only) |

No slice touches `app/Http/Controllers/Public/WebsiteController.php`, `app/Library/Website/WebsitePublisher.php`, `app/Library/Website/WebsiteSnapshotBuilder.php`, `app/Library/Website/WebsitePublicEntitlementGate.php`, `app/Enums/Website/WebsiteSectionType.php`'s case list, or any B3/B4/B5/GBP file.

---

## 16. ACCEPTANCE CRITERIA

1. A Business with an empty Knowledge Profile completes `completenessCheck()` and receives exactly the industry-appropriate question set, never a generic unbounded prompt.
2. Answering questions writes through `BusinessKnowledgeProfileManager::updateFields()` only, verified by a mechanical seam test (mirroring `WebsiteDraftPageServiceSeamTest`) asserting no other code path writes the three new profile tables.
3. Selecting a template and generating produces a Website whose every page/section passes the exact existing `WebsiteSectionValidator` rules, with zero new section types introduced.
4. A generation batch containing an unverified credential/warranty/years-operating claim is rejected by §8.5 step 3, not merely discouraged by prompt wording.
5. A repeated generation request with unchanged inputs returns the stored draft, never a second AI call (idempotency, §8.4).
6. Publishing a guided-generated Website behaves identically, under test, to publishing a manually-built one — same `WebsitePublisher`, same revision/rollback guarantees.
7. A trial Business past its expiry is denied publicly by the existing `EntitlementManager`/`WebsitePublicEntitlementGate` chain, with its draft and any prior published revision intact and immediately restorable.
8. No test, migration, or code path in this contract's slices references a domain-registration API, a `website_domains` table, or any DNS/TLS/ACME concept.

---

## 17. FOCUSED TEST PLAN

- **Profile seam**: mechanical grep-style test proving only `BusinessKnowledgeProfileManager` writes the three new tables (mirrors `WebsiteDraftPageServiceSeamTest`).
- **Field-key allowlist**: an unknown `field_key` is rejected; every `BusinessKnowledgeProfileFieldKey` case round-trips correctly.
- **Completeness check**: fixtures with fully-populated, partially-populated, and empty profiles each return the exact expected missing/stale field set; a fact past `reconfirm_after_days` is flagged stale even when technically present.
- **Page-count ceiling** (Slice 1 fix, §3.4): the 21st manual `pages.store` call is rejected with the same error shape as the existing AI-batch ceiling; a regression test proves this without touching the AI generator.
- **Question pack selection**: industry-specific pack wins when present; falls back to `general_v1` otherwise; an inactive/older-version pack is never selected.
- **Template manifest validation**: seeding a `website_templates` row with a section type outside the 8-enum allowlist fails at seed/migration time, never silently reaching a customer.
- **Generation idempotency**: two identical requests within the same idempotency window produce exactly one `website_guided_generation_attempts` row and one set of pages.
- **Retry ceiling**: a schema-invalid AI response triggers exactly one retry, then a `failed` attempt with zero pages created.
- **Deterministic validation — prohibited claims**: a `prohibited_claims` entry appearing anywhere in generated output fails the attempt.
- **Deterministic validation — unverified facts**: an `unverified` credential stated as settled fact in generated copy fails the attempt; the same fact once `customer_confirmed` passes.
- **Accessibility**: an image-bearing section referencing an asset with empty `alt_text` fails closed.
- **Section rewrite**: rewriting one section never touches any other section or page; commits through `WebsiteDraftPageService::updatePage()` only.
- **Cost caps**: the (N+1)th full generation within the monthly window for a given tier is rejected before any AI call is made.
- **Concurrency**: a second full-generation request while one is `pending` for the same Website is rejected without creating a second attempt row.
- **Business media assets**: an unconfirmed (`usage_confirmed = false`) upload is never included in `GuidedWebsiteGenerationClient`'s context; confirming it makes it eligible.
- **Missing-image checklist**: a template requiring a hero image with zero eligible uploads surfaces exactly that gap, and generation proceeds with the image field empty rather than fabricated.
- **Trial expiry**: a trial-tier Business past its recommended limits (§12.2) is denied via the existing entitlement chain, and republishing after upgrade requires no data restoration step (nothing was deleted).
- **Boundary regression**: a `WebsiteBoundaryTest`-shaped test proving this contract introduces no form-builder, no calendar/booking table, no `Offer`/`Product` sellable-item table, no reviews/ratings table beyond the narrow `reviews_source`/self-attested-testimonial mechanism in §4.5, and no domain-registration code.
- **Cross-lane regression**: full existing `tests/Feature/Website/**`, `tests/Feature/Entitlement/**`, and `tests/Feature/Business/**` suites remain green with zero modification required to any existing test file.

---

## 18. MIGRATION/ROLLBACK PLAN

All six Slice 1 migrations (§4.3) are purely additive (new tables, one new nullable column on `business_locations`) — every `down()` drops exactly what its `up()` created, in reverse dependency order, mirroring Slice A's own proven migrate/rollback discipline (`tests/Feature/Website/WebsiteMigrationsTest.php`'s pattern). The backfill migration (item 6) is idempotent (`firstOrCreate`-shaped) and safe to re-run. No existing table's existing column is altered, renamed, or dropped anywhere in this contract's slices. Later slices (`question_packs`, `website_templates`, `website_guided_generation_attempts`, `business_media_assets`) follow the identical additive-only discipline.

---

## 19. EXPLICIT EXCLUSIONS

- Custom domains, DNS, TLS/ACME, CDN, `website_domains` table (§12.1) — Slice B, not this contract.
- A generic free-canvas editor, arbitrary HTML/JSON-LD, or arbitrary scripts anywhere in the customer-facing editing surface.
- A blog CMS or a native form/lead-capture component (Website's own `WebsiteBoundaryTest` already forbids this and this contract adds nothing that would violate it).
- A calendar/booking/appointment engine — conversion journeys resolve to an external `tel:`/`mailto:`/`https://` target only (§4.2's `conversion_target`), never an internal booking system.
- An `Offer`/`Product`/`Package` sellable-item/checkout model — `offers` in §4.2 is copy-only text for website generation, never a cart/checkout/booking entity.
- A public reviews platform or any GBP-review ingestion (§4.5).
- Standard AI image generation (§14.1).
- A generic AI model-routing/escalation policy beyond the single narrow config override in §8.4.
- Real RFC-005 usage-wallet metering activation for AI generation (§9.1) — count-based caps only in this contract's slices.
- Any modification to `app/Http/Controllers/Public/WebsiteController.php`, the render path, or the 8-type component enum's case list.
- Any B3 (Platform Settings), B4 (Automations), B5 (Analytics), or GBP (Lane C) file.

---

## 20. STOP CONDITIONS

- If `origin/main` advances with product code (not documentation) touching `app/Models/Website*.php`, `app/Library/Website/*.php`, or the entitlement/usage files this contract cites, before implementation begins, re-verify every cited line number and evidence claim before proceeding — do not assume staleness is cosmetic.
- If the four template import materials (§6.2) are not supplied, Slice 3 (and therefore Slice 4's template-dependent context) cannot begin; Slices 1-2 may still proceed independently since they do not depend on template content.
- If a human decides Option B (§3.3) instead of Option A, this contract's Slice 2 controller/route design must be revisited before implementation — do not silently default to Option A once told otherwise.
- If any implementation step would require weakening `WebsiteSectionValidator`, introducing a 9th section type, or lifting the 40-section/20-page ceilings rather than merely closing the existing enforcement gap (§3.4), stop and treat that as a new contract decision, not an in-flight adjustment.

---

## 21. HUMAN-REVIEW DECISIONS

1. **Trial limits (§12.2)** — the exact numbers in that table are a costed recommendation, not an approved policy. A human must approve (or adjust) them before Slice 4 ships trial-gating logic.
2. **Business Settings tenancy (§3.3)** — Option A (new, narrow, Workspace/Business-uid-scoped write surface for Profile fields only) vs. Option B (migrate the legacy flat `BusinessController` onto the multi-Business tenancy model). This contract assumes Option A; a human must confirm.
3. **`Roofing` as a distinct `BusinessIndustry` enum case** (§7.3) vs. folding roofing questions under the existing `HomeServices` case — either is cheap under this design; a human should pick one before Slice 2's question-pack seeding.
4. **`reviews_source = 'gbp_future'`** (§4.5) — whether to reserve this enum value now for a later GBP-reviews integration, or add it only when that future contract actually exists. This contract does not add it today.
5. **Per-tier `reconfirm_after_days` tuning** (§5.4) — currently one fixed 180-day constant for all tiers; a human may want Growth/Agency to reconfirm more frequently given their scheduled-analysis behavior (§10.2).
6. **§9.2's exact monthly generation/rewrite caps** — recommended defaults, not locked; a human should approve before Slice 4.
7. **Whether `WebsiteOpportunityProducer` (Slice 6) is authorized at all in the near term**, given it is the first-ever `WebsitePublished` listener and the first real code behind `OpportunityWorkerKey::Website` — this contract designs the seam but does not assume permission to build it.

---

`WEBSITE GUIDED GENERATION + BUSINESS KNOWLEDGE PROFILE CONTRACT — READY FOR HUMAN/CHATGPT REVIEW`
