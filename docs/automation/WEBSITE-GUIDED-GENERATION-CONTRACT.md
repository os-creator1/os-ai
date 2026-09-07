# WEBSITE GUIDED GENERATION + BUSINESS KNOWLEDGE PROFILE — IMPLEMENTATION CONTRACT

**Status:** DRAFT — CONTRACT ONLY, NOT IMPLEMENTATION-AUTHORIZED
**Lane:** Lane B (Website Generation + Hosting)
**Verified base:** `origin/main` @ `0fc2818bb941b01d9fa6b510e017d12ca0d813c5` (PR #211, B5 Business Analytics, merged)
**Correction pass:** this revision applies a surgical correction round over the original contract (atomicity, alt-text ownership, sensitive-fact exclusion, vertical/question-pack scalability, per-location multi-period hours, testimonial schema, financing/pricing separation, idempotency-key completeness, internal-link handling, and locked trial/tenancy/freshness/cap decisions). Every correction is called out inline where it changes prior text.
**Depends on (read-only, not modified by this contract):** `docs/automation/WEBSITE-GENERATION-HOSTING-CONTRACT.md` (Slice A, implemented, merged PR #209), `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md` (Opportunity Engine, implemented), `docs/rfcs/RFC-004-PLANS-AND-BUSINESS-FEATURE-ENTITLEMENTS.md` (entitlement, implemented), `docs/rfcs/RFC-005-BUSINESS-USAGE-BILLING-AND-WALLETS.md` (usage wallets, implemented through M6), `docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md` (GBP, contract-only, PR #210 merged)

This document is contract-only. It authorizes no code. Every claim below cites an exact file, class, method, column, enum case, or test. Where no such citation exists, the claim is marked `NOT FOUND` and recorded as a gap, never assumed.

---

## 0. VERIFIED BASE AND EVIDENCE

Mechanically confirmed on this exact commit (`0fc2818`):

- `git log --oneline -5 origin/main` shows, in order: `0fc2818` "Merge pull request #211 from os-creator1/agent/b5-business-analytics", `6820b69` "fix(analytics): remove dangling legacy report routes", `126c150` "Merge pull request #209 from os-creator1/agent/website-generation-hosting", `8f2ca4e` "Merge pull request #210 from os-creator1/agent/google-business-profile-contract", `eb77805` "docs: define Google Business Profile implementation contract".
- Website Slice A is present and fully merged: `app/Models/Website.php`, `app/Models/WebsitePage.php`, `app/Models/WebsiteRevision.php`, `app/Models/WebsiteAsset.php`, the 5 migrations under `database/migrations/2026_09_07_1300*.php`, `app/Http/Controllers/Customer/Business/WebsiteController.php`, `app/Http/Controllers/Public/WebsiteController.php`, and 16 test files under `tests/Feature/Website/`.
- The GBP contract is present at `docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md` (contract-only, no implementation code shipped — confirmed no `app/Models/*GoogleBusinessProfile*` or `business_google_connections`-shaped migration exists on this branch).
- B5 Business Analytics is implemented: its only migration is `database/migrations/2026_09_08_120001_add_analytics_indexes_to_business_scoped_tables.php` — B5 adds indexes to existing tables and reuses the existing `view_reports` customer permission; it introduces **no new `PlatformFeature` case** (`docs/automation/B5-BUSINESS-ANALYTICS-CONTRACT.md` §2.5: *"`App\Enums\Entitlement\PlatformFeature` has no analytics case... B5 introduces no new PlatformFeature case and no entitlement gate of its own."*).
- Rendering evidence re-verified for this correction pass: `resources/views/public/website/components/hero.blade.php` renders `background_image` as a CSS `background-image` on the `<section>` element (no `<img>` tag exists in that partial, so no HTML `alt` attribute is possible for it); `resources/views/public/website/components/image_text.blade.php` and `resources/views/public/website/components/services.blade.php` both render their image field as a real `<img src="{{ $assetsByUid[...]['url'] }}" alt="{{ $assetsByUid[...]['alt_text'] ?? '' }}">`, reading `alt_text` from the `assetsByUid` map — which is built, in both the preview and public render paths, from `WebsiteAsset` rows (`app/Http/Controllers/Customer/Business/WebsiteController.php::preview()`, `app/Library/Website/WebsiteSnapshotBuilder.php`), never from section JSON. Section JSON itself (`WebsiteSectionValidator`'s per-type rules, `app/Library/Website/WebsiteSectionValidator.php:75-130`) has no `alt_text` key on any of the 8 types. §13 corrects the contract's prior, mechanically false claim that a section "carries" alt text.

Branch for this contract: `agent/website-guided-generation-contract`, created fresh from `origin/main` @ `0fc2818` (not rebased or reused from the merged `agent/website-generation-hosting` implementation branch). This correction pass adds no new commit base and does not merge or rebase.

---

## 1. PROBLEM AND GOAL

Website Slice A (merged) gives a Business exactly one flat, manually-edited website with a bounded 8-component library, AI-assisted first-draft generation, immutable publish/rollback, and a cached public entitlement gate. It has no guided template-selection flow, no completeness-driven question flow, no shared cross-feature business-data seam, and no cost/budget controls on AI generation beyond what already exists in `WebsiteAiDraftGenerator`.

This contract designs **Website Guided Generation**: a COO-guided, template-constrained generation product built strictly on top of Slice A's existing bounded component library, publish/rollback model, and entitlement gate — plus **one new, canonical, Business-scoped Business Knowledge Profile** that Website, and eventually SEO/GBP/COO/CRM/Ads/Automations, all read and write through the same seam, so no feature invents its own copy of "what is this business."

It does not re-open, weaken, or duplicate any Slice A invariant. Where this contract needs to *extend* an existing bound (the page-count ceiling, §3.4) or *narrowly extend* an existing rule (internal-link acceptance in `WebsiteUrlRules`, §8.6), it says so explicitly, cites exactly why, and never claims the file is "unmodified" when it is not.

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
| Pricing method, financing availability | **MISSING** | `BusinessService` has only `starting_price`/`currency_code` — no pricing-method enum, no financing flag. `Offer`/`Product`/`Package` models: `NOT FOUND` anywhere in `app/Models/` |
| Features/differentiators | **MISSING** | No column anywhere on `businesses`, `business_services`, or `customer_onboardings` |
| Ideal customers / customer problems | **MISSING** | Not present anywhere |
| Credentials, licenses, warranties, guarantees, years operating | **MISSING** | `NOT FOUND` as a column anywhere in `app/Models/` or `database/migrations/`; only unrelated matches (`AppConfig`'s software-license setting, SMS-gateway "credentials") |
| Hours / availability | **MISSING** | Confirmed absent by the GBP contract's own evidence: *"Per-location opening hours do not exist on the platform. They are a general Business fact that Website, SEO and GBP all eventually need, and they belong on `business_locations`, not in a GBP table"* (`GOOGLE-BUSINESS-PROFILE-CONTRACT.md` §36.1.3) |
| Vertical/specialty classification narrower than `BusinessIndustry` | **MISSING** | `BusinessIndustry` (`app/Enums/Business/BusinessIndustry.php:7-13`) has exactly 7 broad cases; no narrower "roofing"/"wedding photography"-style specialty concept exists anywhere. §7 defines a data-driven `vertical_key`, never a new enum case per trade. |
| Primary marketing/growth goals | **PARTIAL** | `customer_onboardings.primary_goals` (JSON, max 2 of `BusinessGoal` enum: `lead_generation`, `local_seo`, `website_conversion`, `reputation`, `sales_followup`, `automation`) exists but is account-level onboarding intent, not a per-website conversion-journey field |
| Website-specific primary conversion goal / booking journey | **MISSING** | No enum or column for "call vs quote vs consultation vs calendar vs external booking" exists anywhere |
| Brand voice / prohibited claims | **MISSING** | Not present anywhere |
| Reviews/testimonials (verified) | **MISSING** | No review/testimonial model exists (`NOT FOUND`); `WebsiteSectionType::Testimonials` is an AI-fabricated-copy section type only, never tied to a verified review record |
| Priority services/locations for growth | **MISSING** | Not present; only unordered `primary_goals` at the account level |
| Business-scoped image inventory / usage confirmation | **PARTIAL** | `WebsiteAsset` (`app/Models/WebsiteAsset.php`) exists but is strictly Website-scoped (cascade-deleted with the Website, no `purpose`/`usage_confirmed` concept, no cross-feature reuse). It does own `alt_text` today, read into every render via `assetsByUid` (§0) — this contract's Business-scoped media inventory (§13, Slice 5) is the *source* alt text a `WebsiteAsset` copy inherits, never a duplicate storage location. |
| Business-scoped "Settings" write surface | **PARTIAL** | No controller literally named `*Settings*` exists, but `App\Http\Controllers\Customer\BusinessController@edit`/`@update` (routes `customer.business.edit`/`customer.business.update`, prefix `/business`) is the real identity-edit surface. It is **flat/V1**: it resolves only `BusinessRepository::findPrimaryByCustomer()` — i.e. **the customer's one "primary" Business**, not any Workspace/Business-uid-scoped Business the way Website Slice A's own tenancy chain (`WorkspaceManager::userCanAccessBusiness()`) supports multiple Businesses per Workspace. **Locked (§22 decision 2):** this contract's Profile write surface is a new, narrow, Workspace/Business-uid-scoped path (Option A); the legacy flat `BusinessController` is not migrated here. |
| Bounded 8-component Website library | **EXISTS, LOCKED** | `app/Enums/Website/WebsiteSectionType.php:14-21` (`Hero`, `Text`, `ImageText`, `Services`, `Testimonials`, `Faq`, `Cta`, `ContactDetails`); confirmed exactly 8 by `tests/Feature/Website/WebsiteBoundaryTest.php:48-62` |
| Max sections per page (40) | **EXISTS, ENFORCED EVERYWHERE** | `WebsiteSectionValidator::MAX_SECTIONS_PER_PAGE = 40` (`app/Library/Website/WebsiteSectionValidator.php:20`), enforced on every `validate()` call site (draft save, publish-time re-validation, AI output validation) |
| Max pages per Website (20) | **PARTIAL / MECHANICAL GAP** | `WebsiteAiDraftGenerator::MAX_PAGES = 20` (`app/Library/Website/WebsiteAiDraftGenerator.php:29`) bounds only a single AI-generation *batch*, checked once against `count($decoded['pages'])` — it is **not** a general ceiling on a Website's cumulative page count. `WebsiteDraftPageService::createPage()` performs no page-count check at all. This contract closes that gap (§3.4). |
| AI generation seam | **EXISTS, LOCKED** | `App\Library\Website\WebsiteAiGenerationClient::complete()` reusing `config('services.openai.*')` exactly (`config/services.php:90-97`); fails closed on missing key/inactive/any `Throwable` |
| Any AI model-routing / cheap-model-first policy | **MISSING** | `NOT FOUND` anywhere in `app/` — the only model reference in the whole codebase is the single hardcoded default `'gpt-4o'` in `config/services.php:93` |
| Any AI-specific token/cost budget or rate limit | **MISSING** | `NOT FOUND` — see Usage Wallet evidence below; no `PlatformFeature` is currently metered |
| Generic per-Business usage wallet/ledger/spend-cap infrastructure (RFC-005) | **EXISTS, FEATURE-GENERIC BUT UNACTIVATED FOR AI** | Tables `business_usage_wallets`, `business_usage_rates`, `business_usage_reservations`, `business_usage_ledger_entries`, `platform_feature_usage_classifications` (one row per `PlatformFeature` case) exist and are keyed generically by feature; `App\Library\Usage\UsageWalletManager::evaluateCoarseCapacity()` (line 1171) currently a stub returning `authorized: true` always; every `PlatformFeature` including `ai_coo_basic` remains `is_metered = false`; the only human-operable activation path (`app/Console/Commands/ActivateConversationsUsageRate.php`) is hardcoded to `PlatformFeature::Conversations` and has never been run |
| Entitlement gate integration point (`EntitlementManager::decide()`, `UsageAuthorizationGateway`) | **EXISTS, LOCKED** | `app/Library/Entitlement/EntitlementManager.php:111-187` (docblocked as RFC-004 §14's precedence chain; the usage-authorization check is its final step before an `allowed: true` decision), `app/Library/Entitlement/Contracts/UsageAuthorizationGateway.php:9-12` |
| `PlatformFeature::WebsiteGeneration` availability/packaging | **EXISTS, Available, Core+Growth+Agency** | `app/Library/Entitlement/PlatformFeatureRegistry.php:49`; packaged into all three tiers by `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php:91-102` |
| `PlatformFeature::AiCooBasic`, `SeoBasicVisibility`, `SeoModule` | **EXISTS AS ENUM ONLY, Planned** | `app/Library/Entitlement/PlatformFeatureRegistry.php:52-55`; zero executable implementation (no controller/model/migration/route) for any of the three |
| A general "AI COO" brain/controller | **MISSING (docs/enum only)** | `NOT FOUND` — the `PlatformFeature::AiCooBasic` case and its Core-tier packaging row are the only artifacts; no executable code exists |
| A general recommendation/opportunity engine that a "Website worker" should plug into | **EXISTS, DESIGNED FOR EXACTLY THIS, UNUSED FOR WEBSITE** | RFC-002 Opportunity Engine (`docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md`), fully implemented: `App\Library\Opportunity\OpportunityProducer` interface (`app/Library/Opportunity/OpportunityProducer.php:17-25`, exactly `workerKey(): OpportunityWorkerKey` + `produce(Business $business): iterable`), `App\Enums\Opportunity\OpportunityWorkerKey` **already reserves** `case Website = 'website';` alongside `BusinessAdvisor`, `Seo`, `Content`, `Sales`, `Reputation` — but **no producer implementation exists for `Website`** (only `BusinessAdvisorOpportunityProducer` exists). RFC-002 §2 states verbatim: *"Enables: SEO, Content, Sales, Reputation, and Website workers (future RFCs)... It does not implement the SEO, Content, Sales, Reputation, or Website workers."* This is the exact, pre-designed seam a future Website-recommendation worker must use — never a new, competing engine. **Locked (§22 decision 7): not implementation-authorized in this contract's near-term slices.** |
| Calendar / booking model | **MISSING** | `PlatformFeature::Calendar` exists and is `Planned`; `NOT FOUND` as any executable model — every "schedul*"/"booking" hit in `app/Models/` is SMS-campaign-send scheduling or an external booking *URL* string on an unrelated AI-prospecting model |
| Form-builder / survey model | **MISSING, AND CONTRACTUALLY FORBIDDEN IN WEBSITE** | `PlatformFeature::Forms` exists and is `Planned`; `tests/Feature/Website/WebsiteBoundaryTest.php` actively asserts no form-builder routes/tables/section-type exist in Website Slice A |
| Offers/Products/Packages sellable-item model | **MISSING** | `NOT FOUND` anywhere |
| Reviews/ratings model | **MISSING** | `NOT FOUND` anywhere; GBP contract explicitly defers all review handling to a future, unbuilt "Slice C" (§36.2.1: *"Excluded from Slice A entirely. Reviews; review replies..."*) |
| SEO product code beyond Website's own per-page fields | **MISSING (docs+enum only)** | `PlatformFeature::SeoBasicVisibility`/`SeoModule` both `Planned`; no SEO controller/service/job exists; no SEO-named contract or RFC file exists anywhere in `docs/` |
| GBP ↔ Website integration | **EXPLICITLY NONE, BY DESIGN** | GBP contract §37.1: *"Website Slice A is not a dependency, and GBP does not read `websites`, `website_pages`, `website_revisions` or `website_assets`."* Also: *"Never auto-publish `/sites/{public_id}` to GBP"* — the noindexed platform-path URL must never be published as a customer's public website URL. |
| Generic audit-log/change-log package or model | **MISSING** | `NOT FOUND` (`spatie/laravel-activitylog` absent from `composer.json`; no `AuditLog`/`ActivityLog`/`ChangeLog` model). Established repository pattern is a **bespoke per-feature ledger table** — e.g. GBP's own `business_google_operations`, justified explicitly: *"is the audit table. No separate GBP events table is created; one would duplicate it"* (`GOOGLE-BUSINESS-PROFILE-CONTRACT.md` §27). This contract follows the same established pattern (§5.3) rather than inventing a generic mechanism. |
| `WebsitePublished` event listeners | **ZERO, CONFIRMED** | `grep -rn "WebsitePublished" app/Providers/` returns nothing; matches the Slice A contract's own claim that it "ships zero listeners for this event" |
| B5 Analytics / Website page-view data | **NOT INTEGRATED, KNOWN STALE DOC** | `docs/automation/B5-BUSINESS-ANALYTICS-CONTRACT.md:507` still reads *"Page views, site conversion \| Website Generation ships page-view data (`PlatformFeature::WebsiteGeneration` is `Planned`)"* — stale relative to Slice A's actual Available flip; not corrected by this contract (out of B5's lane, flagged only) |
| GoHighLevel template exports/screenshots/assets | **ABSENT, CONFIRMED** | `grep -rli "gohighlevel"` across the repository (excluding `vendor`/`node_modules`) returns exactly two files, both prose: `docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md` (one sentence positioning the *product*, not a template, against GoHighLevel as a competitor) and this contract's own reference in `docs/rfcs/RFC-003-WORKSPACE-AND-BUSINESS-ACCOUNT-CORE.md` (same kind of prose mention). No export file, screenshot, HTML/CSS bundle, or asset of any kind from GoHighLevel exists anywhere in the repository. §6 defines the import boundary precisely because of this. |
| Root-relative internal path acceptance in URL validation | **MISSING, ADDED VIA A BACKWARD-COMPATIBLE OPTIONAL PARAMETER** | `App\Library\Website\WebsiteUrlRules::isValid()` today accepts only `tel:`, `mailto:`, and `https://` (`app/Library/Website/WebsiteUrlRules.php`), single-argument signature. §8.6 adds one optional `bool $allowInternalPath = false` parameter — every existing caller (still invoked with one argument) is behaviorally untouched; only `GuidedGenerationOutputValidator` ever passes `true`. |

---

## 3. TENANCY, AUTHORIZATION, AND BOUNDARY DECISIONS

### 3.1 Reused, not reinvented

Guided Generation is a mode of the *existing* Website Slice A resource, not a new tenancy surface. Every guided-generation route runs through the identical chain Slice A's own controller already uses (`app/Http/Controllers/Customer/Business/WebsiteController.php:414-443`, `resolveEntitledBusiness()`): Workspace-by-uid → Business-in-workspace → `WorkspaceManager::userCanAccessBusiness()` → fresh `EntitlementManager::decide(..., PlatformFeature::WebsiteGeneration->value, (int) Auth::id())` → 404 on any failure. No new permission key is introduced beyond the existing `website` customer permission (`config/customer-permissions.php:30-35`).

### 3.2 Business Knowledge Profile tenancy

The Business Knowledge Profile is **Business-scoped** (one row per `business_id`, unique), reachable only through the same `WorkspaceManager::userCanAccessBusiness()` chain used everywhere else in this codebase for Business-scoped data — it introduces **no** parallel authorization path. `SeoBasicVisibility`, `SeoModule`, `GoogleBusinessProfileModule`, `AiCooBasic`, `Automations`, and `Crm` are all already `PlatformFeature::Business`-scoped (default scope per `PlatformFeatureRegistry::SCOPE`, `app/Library/Entitlement/PlatformFeatureRegistry.php:70-72,87`) — so every consumer of the Profile shares the identical Business-scoped authorization primitive already in use platform-wide. Reading the Profile itself requires no new `PlatformFeature` gate (it is not a billable feature; it is shared data), but **writing** it always happens through the entitled Website guided-setup surface (gated by `PlatformFeature::WebsiteGeneration`, already Core+Growth+Agency) via the new Profile write surface described in §3.3.

### 3.3 Business Settings tenancy — LOCKED (Option A)

`App\Http\Controllers\Customer\BusinessController@edit`/`@update` (`app/Http/Controllers/Customer/BusinessController.php:27-59`, routes `customer.business.edit`/`update`, `routes/customer.php:536-539`) is the only existing "Business Settings" surface, and it operates on `BusinessRepository::findPrimaryByCustomer()` — **the customer's single primary Business**, with no Workspace/Business-uid parameters at all. This predates RFC-003's multi-Business-per-Workspace tenancy model that Website Slice A, Automations, and every other B-lane feature already use.

**§22 decision 2 locks Option A for this contract's slices:** Business Knowledge Profile fields get their own dedicated, Workspace/Business-uid-scoped write surface reusing the `resolveEntitledBusiness()` pattern exactly as Website Slice A does — a new, narrow controller action set under the Website guided-setup flow (§11) — leaving the legacy flat `BusinessController` completely untouched. Migrating `BusinessController@edit`/`@update` itself onto the Workspace/Business-uid tenancy chain (the larger, RFC-003/RFC-001-boundary "Option B") is **not part of this contract** and is not silently reconsidered.

### 3.4 Closing the page-count gap

Because the merged §2 evidence shows the "20 pages per Website" bound is enforced only inside `WebsiteAiDraftGenerator`'s batch check, and Guided Generation will create multiple pages deterministically (not exclusively through the AI path), this contract requires (Slice 1, §16) adding an explicit `WebsitePage::count()` ceiling of 20 directly inside `WebsiteDraftPageService::createPage()` — the single seam every page-creation path (manual, AI-generated, guided-generation) already funnels through. This is a **narrow extension of an existing, already-locked bound**, not a new product decision, and requires no entitlement or contract change beyond stating it here. A regression test proving the general ceiling (not just the AI-batch ceiling) is required in Slice 1's test plan (§18).

### 3.5 What this contract explicitly does not touch

Per the Slice A contract's own stop-list and this task's instructions: no change to `app/Library/Website/WebsiteSectionValidator.php`'s 8-type enum or its `match()` field rules; no new Website-facing form/survey/booking component; no B4 (Automations), B5 (Analytics), or Lane C (GBP) file is read as authoritative or modified; no Slice B custom-domain code. The **one** narrow, cited exception to "no other Slice A file changes" is `App\Library\Website\WebsiteUrlRules::isValid()`, which gains a bounded internal-path acceptance rule for cross-page navigation only (§8.6) — stated honestly here rather than folded silently into an "unmodified" claim.

---

## 4. CENTRAL BUSINESS KNOWLEDGE PROFILE — SCHEMA AND DATA OWNERSHIP

### 4.1 Ownership principle

The canonical facts already living on `businesses`, `business_locations`, `business_services`, and `customer_onboardings` are **never duplicated**. The Business Knowledge Profile owns only the facts §2's evidence table marks `MISSING` or the specific sub-fields marked `PARTIAL`. Every consumer (Website, COO, SEO, GBP, CRM, Ads, Automations) reads identity/contact/location/service facts from their existing tables directly, and reads the *new* facts from the tables below. No feature is permitted to cache or fork a private copy of any of these facts (mirrors the existing repository convention already enforced for Branding: `app/Library/Branding/BrandingPresenter.php`'s own single `Cache::rememberForever` seam is the only place platform branding is read from). **Business media assets and per-location hours are described in §13 and §5.5 respectively, not here** — this section owns exactly the profile-row facts in §4.2.

### 4.2 `business_knowledge_profiles` (one row per Business, 1:1)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid, unique | `HasUid` trait, `Str::uuid()` override (mirrors `Website::generateUid()`, `app/Models/Website.php:47-50`) |
| `business_id` | FK → `businesses.id`, unique, cascade delete | one profile per Business |
| `vertical_key` | string(40), nullable | **Correction (§6):** an operator-catalog-controlled, customer-confirmed specialty tag (e.g. `roofing`) — never a free-text value, never a new `BusinessIndustry` enum case. Validated on write against `business_verticals.key` (§6.2). |
| `pricing_method` | string(24), nullable | **Correction (§9):** enum-backed, exactly `fixed`, `hourly`, `quote_only`, `package_tiers`. `financing_available` is **not** a value of this enum (moved to its own field below). |
| `financing_available` | boolean, nullable | **Correction (§9):** null = unasked, `true`/`false` once answered. Independent of `pricing_method` — a fixed-price business may still offer financing. |
| `offers` | json, nullable | bounded array, max 12 entries, each `{name: string≤80, description: string≤300, price_label: string≤40, pricing_method_override: enum(same 4 values)|null}` — **Correction (§9):** the optional per-offer `pricing_method_override` lets one offer (e.g. "financing-eligible installation") diverge from the Business's general `pricing_method` without inventing a checkout/product entity. Copy-only, never a sellable/bookable entity (no `Offer` model exists or is created; §2). |
| `differentiators` | json, nullable | bounded array of strings, max 6, each ≤120 chars |
| `ideal_customers` | text, nullable | ≤500 chars |
| `customer_problems` | json, nullable | bounded array of strings, max 6, each ≤160 chars |
| `credentials` | json, nullable | bounded array, max 10, each `{label: string≤120, verified: bool}` |
| `years_operating` | unsigned smallint, nullable | |
| `warranties_guarantees` | text, nullable | ≤500 chars |
| `primary_conversion_goal` | string(24), nullable | enum-backed: `call`, `quote_request`, `consultation_booking`, `calendar_booking`, `external_booking_link` |
| `conversion_target` | string(255), nullable | a `tel:`, `mailto:`, or `https://` value only, validated by calling `App\Library\Website\WebsiteUrlRules::isValid($value)` **with the new `$allowInternalPath` parameter left at its default `false`** (§8.6) — a conversion target is always an outward call-to-action, never internal page navigation, so it never opts into the internal-path extension |
| `brand_voice` | text, nullable | ≤500 chars |
| `prohibited_claims` | json, nullable | bounded array of strings, max 15, each ≤160 chars — hard-filtered out of every AI generation/rewrite request and re-checked in deterministic post-validation (§8.5) |
| `growth_priority_service_ids` | json, nullable | ordered array of `business_services.id` values belonging to this Business only (validated on write) |
| `growth_priority_location_ids` | json, nullable | ordered array of `business_locations.id` values belonging to this Business only |
| `testimonials` | json, nullable | **Correction (§8, replacing the old bare `reviews_source` design):** bounded array, **max 5 entries**, each `{quote: string≤400, author_name: string≤80(required), author_title: string≤80|null}` — this shape is deliberately within `WebsiteSectionType::Testimonials`'s own outer limits (`items max:10`, `quote max:400`, `author_name max:80` required, `author_title max:80` nullable — `app/Library/Website/WebsiteSectionValidator.php`), so a fully-populated Profile can never itself produce an invalid Testimonials section. No rating/stars field exists. |
| `reviews_source` | string(16), **derived, not independently writable** | `'none'` when `testimonials` is empty, `'manual_verified'` when it is not — computed by `BusinessKnowledgeProfileManager` itself on every write, never accepted as a direct input field. No `gbp_future` value exists (§22 decision 4 — locked, not reserved). |
| `created_at`, `updated_at` | timestamps | |

Every field above is tracked by `business_knowledge_profile_field_states` (§5.1) **except** `reviews_source` (derived, no independent provenance) and `vertical_key`/`testimonials`, which **are** tracked (a customer must confirm both explicitly, since both are sensitive/identity-adjacent facts per §8.4).

### 4.3 The single write seam

**`App\Library\Business\BusinessKnowledgeProfileManager`** (new class, mirrors `BusinessManager`'s existing shape exactly — `app/Library/Business/BusinessManager.php:57-192`) is the **only** code path permitted to write to `business_knowledge_profiles`, `business_knowledge_profile_field_states`, or `business_knowledge_profile_changes`. Its public surface:

- `getOrCreate(Business $business): BusinessKnowledgeProfile` — idempotent, creates an empty row if none exists.
- `updateFields(Business $business, array $fields, string $source, int $actorUserId, bool $markVerified = false): BusinessKnowledgeProfile` — validates every key against the closed `BusinessKnowledgeProfileFieldKey` allowlist (§5.1) **excluding `hours`, which this method explicitly rejects** (hours writes go through the dedicated `updateLocationHours()`, §5.5, since hours is location-scoped, not profile-scoped); validates each value's shape (bounded array counts/string lengths per §4.2); writes the profile row; recomputes the derived `reviews_source` when `testimonials` changes; upserts one `field_states` row per changed key; and appends one `business_knowledge_profile_changes` row per changed key — all inside one `DB::transaction()` (mirrors `WebsiteDraftPageService::createPage()`'s transactional shape exactly).
- `updateLocationHours(Business $business, BusinessLocation $location, array $hoursByDay, string $source, int $actorUserId, bool $markVerified = false): BusinessLocation` — §5.5.
- `completenessCheck(Business $business, ?Website $website = null): BusinessKnowledgeProfileCompleteness` — a plain read-side DTO (never persisted) computed by inspecting the canonical Business/Location/Service tables **and** the profile/field-state/location-hours tables together, returning exactly which of the fixed set of tracked facts (§5.1) are missing, stale (§5.4), or present. When `$website` is supplied, location-scoped facts (hours) are evaluated **only for the locations that Website actually features** — for v1 this is exactly `Business::primaryLocation()`, matching the existing, unmodified `WebsiteSnapshotBuilder::formatAddress()` precedent (`app/Library/Website/WebsiteSnapshotBuilder.php:112`) of reading only the primary location; a future multi-location Website feature is not built here.

No controller, job, or other service is authorized to `Model::create()`/`update()` these tables directly — mirrors the exact seam discipline already established and mechanically tested for `WebsiteDraftPageService` (`tests/Feature/Website/WebsiteDraftPageServiceSeamTest.php`).

---

## 5. PROVENANCE, VERIFICATION, FRESHNESS, AND AUDIT

### 5.1 `BusinessKnowledgeProfileFieldKey` — the closed allowlist

A new PHP enum (`app/Enums/Business/BusinessKnowledgeProfileFieldKey.php`) with one case per column in §4.2's `business_knowledge_profiles` table (excluding the derived `reviews_source`), plus `hours` — `hours` is a valid **question-pack and completeness-check** key (customers are asked about it, and its freshness is tracked) but is **not** a valid key for `BusinessKnowledgeProfileManager::updateFields()`; its write path is exclusively `updateLocationHours()` (§5.5), and `updateFields()` throws a `ValidationException` if `hours` is passed to it. No other string is ever accepted as a `field_key` anywhere — mirrors `WebsiteSectionValidator`'s closed-enum rejection behavior for unknown section types.

### 5.2 Verification status semantics

- `unverified`: the value was written by AI inference, an import, or a system default — never shown to a customer as an established fact without a review prompt.
- `customer_confirmed`: the value was explicitly entered or affirmed by an authenticated Business-accessible user (`WorkspaceManager::userCanAccessBusiness()` — owner/admin/staff-with-scope, same population as everyone else in this codebase who can mutate Business data).

**Correction (§8): only `customer_confirmed` sensitive facts are ever sent to any AI call.** "Sensitive facts" are exactly: `credentials`, `years_operating`, `warranties_guarantees`, `testimonials`, `pricing_method`, `financing_available`, `offers`, `hours`, and any location/service-area claim. An `unverified` sensitive fact is **excluded entirely** from `GuidedWebsiteGenerationClient`'s context (§8.4) — it is never sent even labeled as unverified. Non-sensitive, lower-risk copy-direction facts (`differentiators`, `ideal_customers`, `customer_problems`, `brand_voice`, `primary_conversion_goal`, `conversion_target`, `vertical_key`) may be sent regardless of verification status, since they shape tone/direction rather than assert a checkable fact — but the completeness UI still surfaces their verification state to the customer (§11 step 3), and `vertical_key`/`testimonials` themselves are always tracked and shown for confirmation before first use (§4.2) even though they are not in the "excluded when unverified" sensitive list.

### 5.3 Audit/change-log behavior

Every `BusinessKnowledgeProfileManager::updateFields()` and `updateLocationHours()` call appends one `business_knowledge_profile_changes` row per changed `field_key` (a location-scoped `hours` change additionally JSON-encodes the `business_location_id` inside `old_value`/`new_value`, since the table itself stays `business_id`-scoped, not `business_location_id`-scoped — no schema change needed for this), following the exact established repository precedent (`business_google_operations`, quoted in §2) of a bespoke, append-only, per-domain ledger rather than a generic activity-log package (none exists in `composer.json`, confirmed). Admin inspection of this ledger is out of this contract's implementation slices (§16).

### 5.4 Freshness — field-sensitive, not plan-tier-sensitive (§22 decision 5, locked)

Freshness is a property of **which fact it is**, never of the Business's plan tier. Each `BusinessKnowledgeProfileFieldKey` case carries its own `reconfirmAfterDays()` constant (a fixed method on the enum, not configurable per-Business or per-tier in v1) reflecting how often that specific kind of fact realistically changes:

| Field-key group | `reconfirmAfterDays()` default | Reasoning |
|---|---|---|
| `hours`, `offers`, `pricing_method`, `financing_available` | 90 | frequently-changing operational facts |
| `credentials`, `years_operating`, `warranties_guarantees`, `testimonials` | 365 | slow-changing, higher-stakes claims |
| `differentiators`, `ideal_customers`, `customer_problems`, `brand_voice`, `primary_conversion_goal`, `conversion_target`, `vertical_key`, `growth_priority_service_ids`, `growth_priority_location_ids` | 180 | identity/direction facts, moderate change rate |

A human may retune these exact day-counts later (they are constants, not a schema decision) — see §23. **Core vs Growth/Agency only controls *when/how often* a completeness re-check runs** (§10.2's Growth-tier scheduled Opportunity-engine cadence); it never changes what counts as stale.

Freshness is derived, at `completenessCheck()` time, from: (a) the relevant `verified_at` (either `field_states.verified_at` for profile-row facts, or `business_locations.hours_verified_at` for hours, §5.5) compared against that field-key's `reconfirmAfterDays()`, and (b) whichever owning row's own `updated_at` is more recent for platform-native columns the Profile doesn't itself track (e.g. `businesses.phone` — the check reads `businesses`/`business_locations`/`business_services`' own timestamps directly for those).

### 5.5 Hours — owned per-location, multi-period, never duplicated on the Profile

**Correction:** hours are a `business_locations` fact, not a `business_knowledge_profiles` fact, and support multiple daily periods (not one open/close pair). New columns on `business_locations` (added by Slice 1's migration, §16):

| Column | Type | Notes |
|---|---|---|
| `hours` | json, nullable | `{"monday": [{"open": "HH:MM", "close": "HH:MM"}, ...], ..., "sunday": [...], "notes": string≤200|null}` — an **empty array** for a day means closed that day; a `null` top-level value means "not yet answered" (distinct from "closed every day," which is every day present as `[]`) |
| `hours_source` | string(24), nullable | same enum as `field_states.source`: `onboarding`, `website_setup`, `manual_edit`, `imported` |
| `hours_verification_status` | string(24), default `'unverified'` | same enum as `field_states.verification_status` |
| `hours_verified_by_user_id` | FK → `users.id`, nullable | |
| `hours_verified_at` | timestamp, nullable | |

**Deterministic, documented period rules** (enforced by `BusinessKnowledgeProfileManager::updateLocationHours()`, never left to the customer's raw input unchecked):
1. Each day accepts at most 4 periods (covers a split-shift/lunch-break business with room to spare).
2. Within a day, periods must be sorted by `open` ascending and **non-overlapping**: for consecutive periods `i`, `i+1` in the sorted list, `periods[i].close <= periods[i+1].open` must hold, or the write is rejected.
3. Within a single day, `close` must be **strictly greater than** `open` (`HH:MM` compared as same-day clock times) — **no overnight wraparound is represented within one day's array.** A business operating past midnight (e.g. a bar open 20:00–02:00) represents this as two entries: `{"open": "20:00", "close": "24:00"}` on the starting day, and `{"open": "00:00", "close": "02:00"}` on the following day. `"24:00"` is the one accepted sentinel meaning "end of day" as a `close` value; it is never a valid `open` value.
4. Malformed time strings, a period where `open`/`close` fall outside `00:00`–`24:00`, or more than 4 periods for one day all fail closed with a `ValidationException` — zero periods are ever silently dropped or coerced.

This is the same "validate the complete shape before writing anything" discipline `WebsiteSectionValidator` already applies to sections (§2) — `updateLocationHours()` validates every day's period list before writing any of them, inside one `DB::transaction()`.

`completenessCheck()` (§4.3) evaluates hours-staleness per the specific location(s) a Website features (v1: `primaryLocation()` only, per §4.3's note) using `hours_verified_at`/`reconfirmAfterDays()` exactly as any other field, but reads/writes go through `business_locations`, never `business_knowledge_profile_field_states`.

---

## 6. VERTICAL/SPECIALTY CLASSIFICATION AND QUESTION-PACK DESIGN

### 6.1 `business_verticals` — an operator-controlled catalog, not a PHP enum

**Correction:** adding a new supported trade (e.g. "roofing," "hvac," "wedding-photography") must be a data-seeding operation, never a PHP enum case plus migration. New table:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `key` | string(40), unique | e.g. `roofing`, `hvac`, `wedding_photography` — stable, lowercase, hyphen/underscore only |
| `display_name` | string(80) | |
| `broad_industry` | string(40), nullable | the `BusinessIndustry` enum value this vertical is typically associated with (for filtering an operator's or customer's picker UI) — informational only, not a hard constraint (a `HomeServices` business may still pick a vertical whose `broad_industry` says `ProfessionalServices` if that turns out to fit better) |
| `is_active` | boolean, default true | |
| `created_at`, `updated_at` | timestamps | |

`business_knowledge_profiles.vertical_key` (§4.2) is validated on write against `business_verticals.key` (`is_active = true`) by `BusinessKnowledgeProfileManager::updateFields()` — an unknown or inactive key is rejected. Selecting a vertical is always an explicit, customer-confirmed action (never inferred/auto-set by AI), tracked via `field_states` like any other tracked profile field.

### 6.2 `question_packs` — targets broad industry, vertical, or general

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `key` | string(40) | e.g. `general_v1`, `home_services_v1`, `roofing_v1`, `photobooth_v1` |
| `applies_to_industry` | string(40), nullable | a `BusinessIndustry` enum value, or null |
| `applies_to_vertical_key` | string(40), nullable | a `business_verticals.key` value, or null — **at most one of `applies_to_industry`/`applies_to_vertical_key` is non-null per row**; a pack never targets both at once, keeping resolution order (§6.3) unambiguous |
| `version` | unsigned int | packs are immutable once referenced by any completed generation; a new question or changed wording ships as a new `version` row under the same `key`, never an in-place edit — mirrors `WebsiteRevision`'s own immutability discipline |
| `questions` | json | ordered array of `{field_key: BusinessKnowledgeProfileFieldKey value, prompt: string, input_type: 'text'|'textarea'|'select'|'multi_select'|'boolean', options: string[]|null, required: bool}` |
| `is_active` | boolean | |
| `created_at`, `updated_at` | timestamps | |

Every `field_key` in a pack's `questions` array is validated at seed time against `BusinessKnowledgeProfileFieldKey` (§5.1) — a question pack can only ever collect facts the Profile already knows how to store.

### 6.3 Pack resolution order — locked

`BusinessKnowledgeProfileManager::completenessCheck()` resolves exactly one pack, in this order, each step filtered to `is_active = true` and taking the highest `version`:
1. The pack where `applies_to_vertical_key === $profile->vertical_key` (only reachable once a vertical has been confirmed, §6.1).
2. Else, the pack where `applies_to_industry === $business->industry`.
3. Else, the `general_v1` pack (`applies_to_industry` and `applies_to_vertical_key` both null).

This is a plain, deterministic lookup — no AI involved in pack selection.

### 6.4 Worked examples (illustrative content only — not seeded by this contract; an operator authors the real copy)

- **Roofing** (§22 decision 3, locked: stays under the existing `BusinessIndustry::HomeServices` case **plus** a confirmed `vertical_key = 'roofing'` — no new `BusinessIndustry` case is ever added): a `roofing_v1` question pack (`applies_to_vertical_key = 'roofing'`) targets `pricing_method`/`financing_available` separately (§9), `credentials` (license number, insurance), `offers` (repair/replacement/inspection as separate offer entries), `primary_conversion_goal` defaulting to `quote_request`, and location `hours` including an emergency-availability note in the `notes` field (§5.5) rather than inventing a new column.
- **Photobooth** (`BusinessIndustry::PhotoBoothService`, already exists; no vertical key needed since the broad industry is already narrow): questions targeting `offers` (package tiers with `price_label`), `differentiators` (booth styles), `service_area_cities` (already exists on `business_locations`, reused not duplicated), `primary_conversion_goal` defaulting to `calendar_booking` or `external_booking_link`, deposit/travel-fee facts captured as `offers[].description` text rather than new columns.

### 6.5 Extension cost

Adding a fifth vertical is: one `business_verticals` catalog row plus one seeded `question_packs` row (or a version bump) — **zero PHP enum changes and zero migrations**, unless a genuinely new fact shape is needed (rare, and even then confined to `BusinessKnowledgeProfileFieldKey` plus one column). This is the "inexpensive to extend" property the product direction requires.

---

## 7. TEMPLATE MANIFEST AND RENDERER BOUNDARY

### 7.1 Four-template evidence status — explicit, not invented

**Confirmed absent.** No GoHighLevel export, screenshot, HTML/CSS bundle, or design asset of any kind exists anywhere in this repository (§2 evidence row). This contract does **not** claim to have inspected, and does not describe the visual content of, the four templates the product direction references. Implementing the four templates is **blocked** until the human supplies the exact materials listed in §7.2.

### 7.2 Required import materials (blocking prerequisite, §21)

For each of the four approved templates, the human must supply:
1. A static export or a set of full-page screenshots (desktop + mobile breakpoint) of every distinct page type the template uses.
2. The exact page list and, per page, the exact ordered list of visual sections it contains, each one mapped by a human (not inferred by this contract) onto one of Website Slice A's existing 8 `WebsiteSectionType` cases — or flagged as **unsupported and requiring a future, separately-contracted section-type extension** if no existing type fits. This contract does not invent a 9th section type to fit unseen material.
3. The template's color palette, font pairing, and button/spacing conventions, translated into Slice A's existing bounded `websites.theme` JSON shape (`WEBSITE-GENERATION-HOSTING-CONTRACT.md` §18 — font-family allowlist, primary/secondary color, button style, content width, header/footer variant; no arbitrary CSS).
4. Any imagery the template itself supplies as stock/placeholder art, with an explicit license/usage statement — this contract's own image policy (§13) never fabricates business imagery, so template-supplied stock art must be either (a) genuinely license-cleared for reuse across customers, or (b) excluded, with the missing-image checklist (§13.4) covering the gap per-customer.

### 7.3 Template manifest schema (once materials exist)

**`website_templates`** (new table; deliberately not customer-editable — an operator/platform-seeded catalog):

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `key` | string(40), unique | e.g. `template_a`, `template_b` — stable identifier, never the display name |
| `display_name` | string(80) | |
| `theme` | json | a value conforming exactly to Slice A's existing `websites.theme` shape — no new theme dimension |
| `page_manifest` | json | ordered list of `{page_type: string, is_home: bool, allowed_section_types: string[8-enum-subset], default_section_order: string[], image_slots: [{section_type, field, purpose: BusinessMediaAsset purpose value (§13.2), classification: 'informative'|'decorative'}]}` — every `allowed_section_types`/`default_section_order` entry **must** be one of the existing 8 `WebsiteSectionType` values; the manifest is validated against that enum at seed time, not at request time, so an invalid template can never reach a customer. **Correction (§13):** `image_slots` is new relative to the original draft — it is what lets the deterministic media-binding phase (§8.7) and the accessibility validator (§8.5) know, per image field, whether it is informative (requires alt text) or decorative (may be empty, explicitly, never silently). |
| `manifest_version` | unsigned int, default 1 | **Correction (§10):** incremented by the operator whenever `page_manifest` or `theme` changes; a generation's idempotency key (§10.1) includes this value so an edited template never silently reuses a stale cached draft. |
| `preview_image_path` | string(255), nullable | a platform-owned static asset, never customer-uploaded |
| `is_active` | boolean, default true | operator-controlled retirement switch |
| `created_at`, `updated_at` | timestamps | |

### 7.4 Renderer boundary

The renderer (`resources/views/public/website/page.blade.php` and its 8 component partials — all pre-existing, unmodified) already accepts exactly the `{type, data}` section shape `WebsiteSectionValidator` validates. A template's `page_manifest` is consumed **only** at generation time (§8) to decide which pages/sections/order/image-slot-classification to create via the existing `WebsiteDraftPageService::createPage()` — it is never read at render time, and the renderer itself gains no new template-awareness. This keeps the render path exactly as narrow and already-tested as it is today (`tests/Feature/Website/Public/WebsitePublicRenderingTest.php`).

---

## 8. GENERATION ARCHITECTURE, AI REQUEST/OUTPUT SCHEMAS, AND ATOMICITY

### 8.1 The 80/20 split, mapped onto existing code

| Owned by deterministic code (existing or narrow extension) | Owned by AI (bounded) |
|---|---|
| Allowed page/section structures — `website_templates.page_manifest` (§7.3) validated against the existing `WebsiteSectionType` enum | Draft copy for each section, from structured facts |
| Responsive layout, heading hierarchy, CTA placement — existing Blade partials, unmodified | FAQ suggestions (bounded count, from `WebsiteSectionType::Faq`'s existing `items max:20` rule) |
| Metadata constraints (`seo_title max:70`, `meta_description max:160` — `WebsiteDraftPageService::validateAttributes()`, unchanged) | Titles/meta descriptions within those existing limits |
| Schema envelope — Slice A's existing snapshot/publish model, unmodified | Recommending which `primary_conversion_goal` fits the collected facts (a suggestion the customer confirms, never silently applied) |
| Business identifiers/contact facts, map/location facts — read directly from `businesses`/`business_locations`, never AI-authored | |
| Accessibility/internal-link/technical-SEO validation (§8.5) | Targeted single-section rewrite on demand (§8.9) |
| Template styling/rendering — existing renderer, unmodified | |
| **Deterministic media binding — asset selection, creation/reuse, alt-text assignment (§8.7)** | **Never touches images at all — the AI output contains no asset UID or alt-text field, ever (§8.3)** |

### 8.2 Full generation is atomic — corrected, single behavior, spans the complete pipeline through page creation

**Correction:** a full-generation attempt is **all-or-nothing**, end to end — from the AI-authored text batch, through the deterministic media-binding plan, through the final combined validation, to page creation. The prior draft's claim that "the overall batch may partially succeed by omitting invalid pages" is removed and replaced with one rule: the complete text-only output batch is validated first (§8.5); if any required page or section fails that validation, exactly one bounded corrective retry (§8.4) is attempted against the whole batch; if the retry also fails, the attempt is marked `failed` and **zero pages are created**. Once the text batch passes §8.5, the deterministic media-binding plan is computed and any needed `WebsiteAsset` rows are materialized (§8.7) — still with no page created or modified. The complete final section JSON, including every planned asset reference, is then validated in full (§8.8); only if that final validation also passes does a single orchestration transaction (§8.7 step 4) create or update the complete page batch and mark the attempt `succeeded`. **A failure at any point in this pipeline — text validation, media-binding-plan computation, final combined validation, or the commit transaction itself — leaves zero pages created or modified**; no page is ever created, updated, or left partially generated as a side effect of a later step's failure. No required template page is ever silently omitted. `warnings` (§8.3) may describe *intentionally optional* missing facts/assets (e.g. "no credentials confirmed; omitted from copy," or "no eligible image for this slot; rendered without one") — it may never be used to explain away a required page or section that failed validation, because that case never reaches persistence at all.

### 8.3 One bounded structured generation request

**`App\Library\Website\GuidedGeneration\GuidedWebsiteGenerationClient`** (new class, deliberately mirroring `WebsiteAiGenerationClient`'s exact fail-closed shape — `app/Library/Website/WebsiteAiGenerationClient.php:21-43` — reusing the identical `config('services.openai.*')` seam, never a new config key) issues **one** request per generation attempt (plus the one bounded retry, §8.4): a single chat completion with `response_format: {type: 'json_object'}`, containing the selected template's `page_manifest` (minus `image_slots`, which the AI never sees or acts on), every `customer_confirmed` non-sensitive-or-sensitive Profile fact per §5.2's exclusion rule, and the `prohibited_claims` list as a hard instruction. **The AI never receives any `business_media_assets` row, any `WebsiteAsset` uid, or any instruction to populate an image field** — `WebsiteSectionValidator::validate($sections, [], allowAssetReferences: false)` is the enforcement (identical `allowAssetReferences: false` discipline `WebsiteAiDraftGenerator` already enforces, §2), and the system prompt explicitly instructs the model never to emit `background_image`/`image` keys at all. Image population happens exclusively in the deterministic media-binding phase (§8.7), after the AI text batch passes §8.5's validation and strictly before any page is created (§8.2).

### 8.4 Request/output schema, retry

Request messages: `[{role: 'system', content: <fixed instruction text, ≤4000 tokens, includes the exact allowed section-type list minus image fields, the field-length limits from §4.2, and the prohibited_claims list>}, {role: 'user', content: <JSON-encoded {template_key, pages: [{page_type, is_home}], facts: {field_key: value}[] — customer_confirmed-only for sensitive keys per §5.2}>}]`.

Expected output:
```json
{
  "pages": [
    {
      "page_type": "home",
      "is_home": true,
      "seo_title": "string ≤70",
      "meta_description": "string ≤160",
      "sections": [{"type": "hero", "data": { /* exact existing WebsiteSectionType::Hero shape, no image fields */ }}, ...]
    }
  ],
  "warnings": ["string — describes only intentionally-omitted optional content, never a validation failure"]
}
```
Exactly one corrective retry on schema-validation failure (identical to `WebsiteAiDraftGenerator::generate()`'s existing "at most one bounded automatic retry," `app/Library/Website/WebsiteAiDraftGenerator.php`). If the retry's output also fails §8.5, the attempt is `failed` per §8.2 — no unbounded retry loop, and no partial commit.

Token/output ceilings: request-side, the system prompt is capped at a fixed ≤4000-token budget (enforced by truncating the lowest-priority Profile facts first, deterministic order, never AI-decided); response-side, `max_tokens` is passed explicitly on the `OpenAI::client()->chat()->create()` call, sized to the template's page count. **Cheap-model-first**: `config('services.website_guided_generation.model')` is a new, narrowly-scoped config value defaulting to `env('OPENAI_MODEL', 'gpt-4o')`, letting an operator point guided generation at a cheaper model independently of other AI seams **without inventing a general routing policy** — the one retry reuses the same configured model, never an escalation to a stronger one.

### 8.5 Deterministic post-validation (new, beyond Slice A's existing `WebsiteSectionValidator`)

A new `GuidedGenerationOutputValidator` runs, in order, after the AI responds and before any page is created:
1. Every `WebsiteSectionValidator::validate()` call (reused, unmodified) — malformed sections fail closed exactly as today.
2. **Prohibited-claims scan**: case-insensitive substring match of every `prohibited_claims` entry against every generated string field; any match fails the whole attempt (§8.2).
3. **Confirmed-fact-allowlist check** — corrected from the prior, mechanically-unprovable "states as settled truth" claim: for the sensitive-fact categories that were actually sent (only `customer_confirmed` ones ever are, §5.2), the validator checks that any specific literal value appearing in the AI's output for that category (a credential label, a stated year count, an offer price label, a testimonial quote/author) is a **literal member of the confirmed set that was sent** — not a semantic judgment about certainty, a plain membership/substring check the code can actually prove. Testimonials specifically: any `testimonials`-type section item's `quote`/`author_name`/`author_title` must exact-string-match one of the confirmed `business_knowledge_profiles.testimonials` entries; the AI may select a subset and choose their order, but never paraphrase, embellish, or invent one.
4. **No asset fields present** — the output must contain zero `background_image`/`image`/`items[].image` keys anywhere (enforced by `allowAssetReferences: false`, §8.3); their presence is itself a validation failure, not merely ignored.
5. **Internal-link validation** — any CTA URL passes through `WebsiteUrlRules::isValid($url, allowInternalPath: true)` (§8.6) — the **only** call site anywhere in the codebase that ever passes `true`; an internal-path target must resolve to an actual `page_type` present in the same batch, or the attempt fails.
6. **Technical SEO validation** — exactly one page per batch has `is_home = true` (reuses `WebsitePublisher::validateDraft()`'s existing exactly-one-homepage rule by construction), and no two pages in the batch share a slug (reuses `WebsiteDraftPageService`'s existing per-Website slug-uniqueness check).

Any failure at any step is a **whole-attempt** failure subject to the one retry (§8.2/§8.4) — never a per-page omission.

### 8.6 Internal-link handling — a mechanically scoped, backward-compatible extension of `WebsiteUrlRules`

**Correction:** the prior draft called `WebsiteUrlRules` "existing, unmodified" in one place (§4.2) while requiring it to gain internal-link validation elsewhere — an outright contradiction, since the existing rule (`app/Library/Website/WebsiteUrlRules.php`) accepts only `tel:`, `mailto:`, and `https://`. This contract resolves it with one exact, backward-compatible signature change, not a silent behavior change:

```php
public static function isValid(string $url, bool $allowInternalPath = false): bool
```

- **Default parameter preserves every existing caller exactly**: `$allowInternalPath` defaults to `false`, and with it `false`, `isValid()`'s behavior is byte-for-byte identical to today's — only `tel:`, `mailto:`, and `https://` ever pass. **No existing call site anywhere in the codebase is modified** — every caller that invokes `WebsiteUrlRules::isValid($url)` today (a single argument) keeps compiling and behaving identically, since the new parameter simply takes its default.
- **Exactly one call site in this entire contract ever passes `true`**: `GuidedGenerationOutputValidator`'s internal-link validation step (§8.5 step 5), via `WebsiteUrlRules::isValid($url, allowInternalPath: true)`. `business_knowledge_profiles.conversion_target` (§4.2) explicitly calls it with the default `false` — a conversion target is always an outward call-to-action, never internal navigation.
- **Accepted internal shape** (only reachable with `$allowInternalPath = true`): a root-relative path matching `^/[a-z0-9]+(-[a-z0-9]+)*(/[a-z0-9]+(-[a-z0-9]+)*)*$` — lowercase, hyphenated path segments only, no trailing slash requirement. This regex is unchanged from the original correction pass.
- **Forbidden, explicitly, even with `$allowInternalPath = true`**: protocol-relative `//...`, any `..` path-traversal segment, control characters, backslashes, a `#` fragment or `?` query string anywhere in the value, and any scheme prefix other than the three already-accepted ones (`javascript:`, `data:`, `file:`, `vbscript:`, and bare `http://` remain rejected exactly as today). These forbidden shapes are unchanged from the original correction pass.
- **Scope of the extension**: internal-path acceptance is reachable **only** through `GuidedGenerationOutputValidator`'s explicit `true` call, applied to CTA fields used for cross-page Website navigation (`hero.primary_cta.url`/`secondary_cta.url`, `cta.buttons[].url`). The platform never stores a platform-domain **absolute** URL for internal navigation (no `https://<platform-host>/sites/...` value is ever written by this contract — only the bare relative path).
- **Validation against the batch**: `GuidedGenerationOutputValidator` (§8.5 step 5) resolves an internal-path target against the `page_type`/slug list of the current generation batch; a path that doesn't resolve to a real page in the batch fails the attempt.

This is added to Slice 4's implementation allowlist (§16) as `app/Library/Website/WebsiteUrlRules.php` (one additive parameter with a backward-compatible default; zero lines of any existing caller are touched), with its own regression test proving every pre-existing call site (still invoked with one argument) continues to accept only the three previously-accepted shapes, and that `GuidedGenerationOutputValidator` is the only caller in the codebase that ever supplies `allowInternalPath: true`.

### 8.7 Deterministic media-binding phase — corrected alt-text ownership and commit ordering

**Correction:** the prior draft incorrectly claimed a generated section "carries" `alt_text` (it does not, confirmed against the actual renderer, §0), and separately described media binding as writing into an "already-committed" page — which contradicted §8.2's all-or-nothing guarantee, since §8.8's validation could still fail *after* a page already existed. The corrected design computes and validates the complete binding **before** any page is created:

1. `BusinessMediaAsset` (§13.2) owns the **reusable source** `alt_text` a customer sets once per uploaded image.
2. After §8.5's text-batch validation passes, a new **`App\Library\Website\GuidedGeneration\MediaBindingService`** computes a deterministic **binding plan**, once per attempt, entirely in memory — no page exists yet, and none is read or modified:
   - For each `image_slots` entry in the template's `page_manifest` (§7.3) — each carrying a `purpose` and an `informative`/`decorative` classification — it selects the customer's confirmed (`usage_confirmed = true`) `business_media_assets` row matching that `purpose` (deterministic: most-recently-confirmed first if more than one matches; a customer-specified preference, when the setup UI offers one, wins).
   - An **informative** slot whose only candidate asset lacks non-empty `alt_text` is **ineligible**: it is left unbound and recorded as a `warnings` entry (§13.4/§8.8) — never bound anyway, and never a validation failure. A **decorative** slot may bind an asset with empty `alt_text`.
   - For each slot the plan does bind, the needed `WebsiteAsset` row is created (or, if a `WebsiteAsset` with the identical `content_hash` already exists for this Website, reused) via the **existing, unmodified** `WebsiteAssetUploadService::store()` — **outside any database transaction**, since this step may perform filesystem I/O. The created/reused `WebsiteAsset.alt_text` is set from `business_media_assets.alt_text` at copy time (or left empty for an explicitly `decorative` slot, §8.8).
3. The plan's resulting `WebsiteAsset` uids are merged, in memory, into the AI-authored section JSON, producing the complete final proposed section JSON for every page — still with no page row created or modified. This final JSON, including every planned asset reference, is exactly what §8.8 validates.
4. Only once §8.8's validation of that complete final JSON passes does a new orchestration service (`App\Library\Website\GuidedGeneration\GuidedGenerationCommitService`) open **one** `DB::transaction()` and call `WebsiteDraftPageService::createPage()` (or the equivalent update path for an existing page) once per page with the fully-resolved final data, then mark the `website_guided_generation_attempts` row `succeeded` — all inside that same transaction, so either every page is created/updated and the attempt succeeds, or none are and it does not. Provider/AI calls and step 2's filesystem operations happen strictly **before** this transaction opens, never inside it.
5. If §8.8's validation or the commit transaction fails for any reason, **zero pages are created or modified** (§8.2). A `WebsiteAsset` row materialized in step 2 for a slot that never reaches a committed page is referenced by no page and rendered nowhere; it is either deleted as part of failure-handling cleanup or left as an explicitly harmless, unreferenced row — it is never attached to a partially generated page, because no page is ever partially generated.
6. `MediaBindingService` performs no AI call and its planning step is fully deterministic; it does not describe itself as modifying an "already-committed" page anywhere, since no page exists until step 4's single commit. It is Slice 5 work (§16), since it depends on `business_media_assets` existing at all.

### 8.8 Final combined validation (accessibility and asset references), corrected to run before any page exists

`GuidedGenerationOutputValidator`'s asset-related check (formerly, incorrectly, "sections must carry alt_text") is corrected to run against the **complete final proposed section JSON** produced by §8.7's binding plan — the AI-authored text merged with the plan's resolved `WebsiteAsset` uids — strictly **before** any page is created or updated:
- **Informative** image slots (per the template's `image_slots` classification, §7.3 — e.g. `image_text.image`, `services.items[].image`, both rendered as real `<img>` tags per §0's evidence) must resolve to a bound `WebsiteAsset` with non-empty `alt_text`, or the slot must be left unbound (§13.4/§8.7) — an informative `<img>` is never rendered with an empty `alt` attribute silently, and this check runs against the plan, never against an already-existing page.
- **Decorative** image slots — concretely, `hero.background_image` (§0: rendered as a CSS `background-image`, which has no HTML `alt` attribute at all; the hero section's required `heading`/`subheading` text already carries the informative content Slice A's renderer displays) — are explicitly classified `decorative` in the template manifest and are **exempt** from the non-empty-`alt_text` requirement; their bound `WebsiteAsset` row may legitimately carry empty `alt_text`, and this is a documented, intentional classification, never a silently-skipped check.
- This check is not "keyword-filled alt text" enforcement — an empty, explicitly-decorative alt text passes; a non-empty but present `alt_text` on an informative image passes; only an *informative* slot with *no* alt text at all fails to bind (§8.7) — it is never bound and then separately rejected after the fact, since no page exists for anything to be rejected from.
- A direct unit test on `GuidedGenerationOutputValidator` continues to prove that a **manually constructed** informative binding lacking `alt_text` (built directly against the validator, bypassing `MediaBindingService`) is rejected — this remains valid defense-in-depth even though `MediaBindingService`'s normal automatic binding (§8.7) never produces such a binding, because it treats a source asset without `alt_text` as ineligible for an informative slot before any binding is ever proposed.

### 8.9 Targeted section rewrite

A customer may request a rewrite of exactly one existing, already-created **text** section (identified by `website_pages.uid` + section index) through a new, narrow endpoint — image-bearing fields are never part of a rewrite request (media binding, §8.7, is never re-run by a rewrite). The rewrite reuses the identical `GuidedWebsiteGenerationClient`/`GuidedGenerationOutputValidator` pipeline scoped to one section's schema only, and commits through `WebsiteDraftPageService::updatePage()` (unmodified) — never a whole-site regeneration.

### 8.10 `website_guided_generation_attempts` (new table — corrected idempotency/retry/lease fields, see §10)

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid | |
| `website_id` | FK, cascade delete | |
| `idempotency_key` | string(64), indexed | sha256 of the canonical input object, §10.1 |
| `attempt_type` | string(24) | exactly `full_generation`, `section_rewrite` — no `quality_pass` value exists; no quality-pass mechanism is authorized anywhere in this contract (removed from §8.1, §12, and this table) |
| `status` | string(24) | **Correction (§10):** exactly `pending`, `succeeded`, `failed`, `cancelled` — `retried` is removed as a terminal status |
| `retry_count` | unsigned tinyint, default 0 | **Correction (§10):** the bounded retry (§8.4) is data on the row, not a vague status value; capped at 1 |
| `lease_expires_at` | timestamp | **Correction (§10):** set to `created_at + 5 minutes` at row creation; a `pending` row past this is treated as abandoned |
| `warnings` | json, nullable | the `warnings` array from §8.4's output schema |
| `failure_reason`, `created_at`, `completed_at` | | `failure_reason` includes `'lease_expired'`, `'cancelled'`, plus validation-failure reasons |

No raw AI prompt/response body is stored here (mirrors Slice A's own AI seam, confirmed by `WebsiteAiSeamUnchangedTest`'s existing no-secret-leakage assertion, §2) — only the bounded metadata needed for idempotency, retry accounting, and count-based caps.

---

## 9. COST/BUDGET CONTROLS AND USAGE-LEDGER INTEGRATION

### 9.1 What exists and what this contract does with it

RFC-005's usage wallet infrastructure (§2 evidence row) is real, generic, and keyed by `PlatformFeature` — but every feature remains `is_metered = false`, and the only activation command is hardcoded to `Conversations`. This contract does **not** activate metering for AI generation (that would require a new, separately-authorized command and a real numeric rate — an RFC-005 M5-style operator step, explicitly out of this contract's slice order). Instead:

- **v1 (this contract's implementation slices):** count-based caps only (§9.2), enforced in application code by counting rows in `website_guided_generation_attempts` — no wallet, no dollar amount, no `UsageAuthorizationGateway` change.
- **Future (explicitly not this contract):** once RFC-005 metering is genuinely activated for a `website_guided_generation` (or reused `ai_coo_basic`) feature classification, the count-based cap is replaced by a real `UsageWalletManager::evaluateCoarseCapacity()`-backed reservation, and `EntitlementManager::decide()`'s existing usage-authorization step (`app/Library/Entitlement/EntitlementManager.php:180-184`) starts returning real `usage_unauthorized` denials for this feature — no code path in this contract needs to change for that future flip.

### 9.2 Exact v1 caps — corrected (§22 decision 6, locked)

**Full generation is an initial-empty-Website operation, not a recurring monthly allowance.** The prior draft's "4/8/unlimited full generations per calendar month" row is removed entirely. `GuidedWebsiteGenerationClient`'s full-generation endpoint is only reachable while `$website->pages()->count() === 0` (the same restriction `WebsiteAiDraftGenerator::generate()` already enforces today, §2, now made explicit for Guided Generation too) — once any page exists, only §8.9's targeted rewrite or a future, separately-authorized "replace/rebuild" workflow (not designed here) apply.

| Cap | Working default | Enforced by |
|---|---|---|
| Full generations per Website | exactly 1, ever (until pages are cleared by a future, separately-authorized rebuild workflow) | `$website->pages()->count() === 0` precondition, checked before any AI call |
| Section rewrites per Business per rolling 30 days | **Core 20, Growth 40, Agency 100** (§22 decision 6, locked as working defaults, adjustable via config later) | count of `attempt_type = 'section_rewrite'` `succeeded`/`pending`-non-expired rows |
| Concurrent in-flight attempts per Website | 1 | a non-expired `pending`-status row for the same `website_id` blocks a new attempt (§10.2) |

---

## 10. IDEMPOTENCY AND CONCURRENCY — CORRECTED

### 10.1 Canonical idempotency key

**Correction:** the prior key (`sha256(business_id . template_key . profile_updated_at . field_states_max_updated_at)`) omitted material inputs, and the follow-up correction's "keys recursively sorted" instruction left every **array's** element order unspecified — two requests reading the identical underlying facts back from the database in a different row order (a real possibility with no `ORDER BY` guarantee) could otherwise hash differently. This pass closes that gap explicitly. The corrected key is `sha256()` of a canonical JSON object built by this exact, deterministic procedure:

1. Every **object** (associative/keyed structure) has its keys sorted lexicographically before encoding.
2. Every **set-like array** — an array whose real-world meaning is an unordered collection of rows, not a sequence — is sorted by its stated numeric or string sort key, ascending, before encoding:
   - `selected_locations`: sorted by numeric `location_id` ascending.
   - `active_services`: sorted by numeric `service_id` ascending.
   - `confirmed_profile_facts`: encoded as an object (not an array) keyed by `field_key`, so lexicographic key sorting (rule 1) already makes its order deterministic.
3. Every **intentionally-ordered array** — one whose stored order is itself meaningful product data — retains its **exact stored order**, never re-sorted: `growth_priority_service_ids`/`growth_priority_location_ids` (their ranking IS the fact being hashed, so re-sorting them would hash away the very thing that makes two profiles different), and the template's page/section order (never independently re-serialized into the key at all — see point 4 below).
4. The template's page/section order is **not** duplicated into the canonical object as a separate ordered array; it is fully and deterministically captured by the pair `template_key` + `template_manifest_version` (§7.3) — any edit to a template's `page_manifest` (including a reorder) bumps `manifest_version`, which is already in the key. Encoding the manifest's page/section order a second time would be redundant, not more correct.
5. `JSON_UNESCAPED_SLASHES` is used for encoding; no other normalization (whitespace, key casing) is needed since every value going in is already a plain scalar, a lexicographically-sorted object, or a numerically-sorted array per rules 1-3.

The resulting canonical object:

```
{
  "business_identity": {"name", "industry", "description", "phone", "email", "website_url", "updated_at"},
  "selected_locations": [{"location_id", "updated_at", "hours", "hours_verified_at"}, ...] — sorted by location_id ascending; v1: primaryLocation() only, §4.3,
  "active_services": [{"service_id", "updated_at"}, ...] — sorted by service_id ascending; every Active business_service,
  "confirmed_profile_facts": {field_key: {"value", "verified_at"}, ...} — a keyed object, not an array; `value` for growth_priority_service_ids/growth_priority_location_ids is encoded in its stored (meaningful) order, never re-sorted,
  "vertical_key": "... or null",
  "template_key": "...",
  "template_manifest_version": <website_templates.manifest_version, §7.3>,
  "question_pack_version": <the resolved pack's version, §6.3>,
  "generator_schema_version": <a fixed constant on GuidedWebsiteGenerationClient, bumped whenever the request/output JSON schema itself changes>,
  "model_policy_identifier": "config('services.website_guided_generation.model') value"
}
```

A repeated request whose canonical object hashes identically to a **`succeeded`** prior attempt returns that stored draft rather than issuing a new AI call. **Correction:** a `failed` attempt never short-circuits a later request with the same key — the code looks up `where('idempotency_key', $key)->where('status', 'succeeded')->first()`; if none exists, a new attempt proceeds regardless of how many prior attempts with that key failed. This directly prevents "a failed attempt returns a nonexistent stored draft as a successful response."

### 10.2 Concurrency and lease

Before creating a new `pending` attempt row, the code locks the `Website` row (`Website::where('id', $website->id)->lockForUpdate()`, inside the same `DB::transaction()` that inserts the new attempt row) and checks for an existing `pending` attempt for that `website_id` whose `lease_expires_at` has not passed — exactly the same `lockForUpdate()`-inside-`DB::transaction()` discipline `WebsiteDraftPageService::clearExistingHomepage()` and `WebsitePublisher::publish()` already use (§2), applied here to prevent duplicate-attempt races rather than reusing a nonexistent unique-partial-index (not portable across this codebase's MySQL target).

A `pending` row whose `lease_expires_at` (`created_at + 5 minutes`, fixed constant — one bounded AI call plus one retry never legitimately takes longer) has passed is treated as abandoned: the **next** request touching that Website transitions it to `failed` (`failure_reason = 'lease_expired'`) before evaluating whether a new attempt may start. No new cron job is introduced; this is a lazy check on next access, matching this codebase's existing preference for request-time invariant checks over background sweepers wherever one is sufficient.

---

## 11. COO AND SEO SAFETY BOUNDARY

### 11.1 No competing "Website Worker" — the exact existing seam to use instead, deferred

RFC-002's Opportunity Engine (§2 evidence row) already reserves `OpportunityWorkerKey::Website = 'website'` and already states its own intent (*"Enables: SEO, Content, Sales, Reputation, and Website workers (future RFCs)"*) precisely so that no future feature invents a second recommendation brain. **§22 decision 7 (locked): implementing `App\Library\Opportunity\WebsiteOpportunityProducer implements OpportunityProducer` is not authorized by this contract.** The seam is documented — reusing `BusinessKnowledgeProfileManager::completenessCheck()` as its fact source exactly the way `BusinessAdvisorOpportunityProducer` already reuses `InitialBusinessSnapshotBuilder` (`app/Library/Opportunity/BusinessAdvisorOpportunityProducer.php:59-89`) — so a future, separately-authorized contract can build it without re-deriving the integration point, but no code for it ships as part of this contract's slices (§16, Slice 6 is recorded as **not implementation-authorized**, not merely "deferred" in the sense of "later in this same authorization").

### 11.2 Core vs Growth/Agency behavior

| Tier | Website Guided Generation behavior |
|---|---|
| **Core** | Generation, correct bounded structure, basic metadata, accessibility checks (§8.8), bounded schema, publishing/revisions/rollback (all pre-existing Slice A), and on-demand single-section rewrite (§8.9). No scheduled/ongoing analysis. |
| **Growth** | Everything in Core, at the §9.2 rewrite-cap tier. Scheduled Opportunity-engine analysis (missing/incomplete Profile facts affecting the live published Website, GBP/Website consistency once GBP Slice A ships, service/location content-gap detection) becomes available **only once and if** a future, separately-authorized contract implements `WebsiteOpportunityProducer` (§11.1) — this row describes intended future behavior, not something this contract's slices activate. |
| **Agency** | Everything in Growth, at the §9.2 Agency rewrite-cap tier, plus (recorded for completeness, not built here) White Label consumption of the same generation pipeline for agency-managed sub-businesses — no new tenancy model needed, since Website Slice A's existing multi-Business-per-Workspace chain already supports this. |

No new `PlatformFeatureRegistry` entry or packaging-migration change ships in this contract's slices.

### 11.3 SEO boundary — draft-only, human-approved, never fabricated

Everything this contract's AI touches produces a **draft** subject to the exact same human-approved publish gate Slice A already enforces (`WebsitePublisher::publish()`, requiring an explicit customer action — no code path in this contract calls `publish()` automatically). SEO-specific claims — rankings, reviews, credentials, prices, hours, locations, warranties, service areas — are never AI-fabricated: they are either excluded from AI context entirely when unverified (§5.2) or checked against a literal confirmed-value allowlist (§8.5 step 3), never trusted to prompt wording alone. Page content, uploaded filenames, alt text, and any future imported template material (§7.2) are treated as **untrusted input** to any AI call. No silent auto-publishing exists anywhere in this design.

---

## 12. GENERATION STATE MACHINE

1. **Select Business** — existing `resolveEntitledBusiness()` chain (§3.1); no change.
2. **COO completeness evaluation** — `BusinessKnowledgeProfileManager::completenessCheck()` (§4.3) runs synchronously (indexed reads only, no AI call).
3. **Ask missing questions** — the resolved `question_pack` (§6.3), filtered to only the fields `completenessCheck()` flagged; already-`customer_confirmed`, non-stale fields are never re-asked; unverified sensitive facts are surfaced here specifically for confirmation, since §5.2 means they otherwise never reach generation at all.
4. **Request missing assets** — driven by the missing-image checklist (§13.4), never a blocking hard-stop.
5. **Recommend/show four template choices** — reads `website_templates` (§7.3), filtered to `is_active = true`; if the templates do not yet exist (§7.1's current blocked state), this step cannot ship (§21).
6. **Customer selects template** — writes a new nullable `template_key` column on `websites` (the only Slice-A-table column this contract adds).
7. **Generate structured draft** — one bounded AI request plus at most one retry (§8.3-8.4), atomic (§8.2).
8. **Deterministic text-batch validation** — §8.5; no page exists yet.
9. **Deterministic media-binding plan and asset preparation** — §8.7 steps 1-3 (computes which slots bind to which asset, materializes any needed `WebsiteAsset` rows outside any database transaction, and merges the resulting uids into the section JSON in memory); still no page created or modified.
10. **Final combined validation of the complete proposed section JSON, including every planned asset reference** — §8.8; still before any page exists.
11. **Atomic page commit** — §8.7 step 4: one orchestration transaction creates/updates the complete page batch and marks the attempt `succeeded`; a failure at this step or any earlier one leaves zero pages created or modified (§8.2).
12. **Customer reviews warnings and claims** — the `warnings` array (§8.4) surfaced in the existing page-form/preview UI, extended with a warnings panel.
13. **Preview** — Slice A's existing `WebsiteController::preview()`, unmodified.
14. **Human-approved publish** — Slice A's existing `WebsitePublisher::publish()`, unmodified.
15. **Immutable revision and rollback** — Slice A's existing `WebsiteRevision`/`WebsitePublisher::rollback()`, unmodified.
16. **Later COO recommendations triggered only by meaningful events** — §11.1; not activated by this contract's slices (documented seam only).

### Failure, cancellation, retry, supersession, stale-data behavior — corrected for atomicity

- **Failure**: the attempt is marked `failed`; **zero pages are created**, with no exception (§8.2) — this replaces the prior, contradictory "partial success by omission" language entirely.
- **Cancellation**: a customer may abandon a `pending` attempt; the **next** request for that Website transitions it to `failed` (`failure_reason = 'cancelled'`) per §10.2's lazy-lease-check pattern.
- **Retry**: exactly the one bounded retry (§8.4), tracked via `retry_count`, never a new attempt row for the same triggering request; a customer-initiated "try again" **after** a terminal `failed` status is a genuinely new attempt with a freshly-computed idempotency key (§10.1) — which will differ from the failed one only if some input actually changed, otherwise it is identical and will simply attempt generation again (a failed attempt never blocks a retry, §10.1).
- **Supersession**: not applicable to full generation in this contract's slices, since full generation is a one-time, zero-pages-only operation (§9.2) — there is nothing to supersede. A "replace this content" need is served exclusively by §8.9's per-section rewrite.
- **Stale-Business-data**: if `completenessCheck()` detects a tracked field past its `reconfirmAfterDays()` (§5.4) for a fact actually used in the *live published* snapshot, the next completeness evaluation (state 2, on next visit) surfaces a "please confirm this hasn't changed" prompt — it never silently re-generates or silently re-publishes.

---

## 13. IMAGE AND ALT-TEXT POLICY (owns `business_media_assets` — Slice 5)

### 13.1 No standard AI-image generation

Confirmed nowhere in scope (§2); this contract does not add an image-generation API call anywhere.

### 13.2 `business_media_assets` — the Business-scoped upload inventory (Slice 5, deferred from Slice 1)

**Correction:** this table, its model, and its upload service belong entirely to Slice 5, not Slice 1 — Slice 1 owns only the Business Knowledge Profile foundation (§16).

| Column | Type | Notes |
|---|---|---|
| `id` | bigint PK | |
| `uid` | uuid, unique | |
| `business_id` | FK, cascade delete | |
| `disk`, `path`, `mime_type`, `size`, `width`, `height`, `content_hash` | same shapes as `website_assets` | reuses the identical magic-byte-validated upload pattern from `App\Library\Website\WebsiteAssetUploadService` — a new, Slice-5-owned `BusinessMediaUploadService` mirrors it method-for-method, never modifying or extending the existing Website-scoped service |
| `purpose` | string(24) | enum-backed: `logo`, `hero`, `team`, `location`, `work_sample`, `other` |
| `alt_text` | string(160), nullable | **this is the one and only place reusable source alt text is authored** — copied into a `WebsiteAsset.alt_text` at media-binding time (§8.7), never stored a second time anywhere else |
| `usage_confirmed` | boolean, default false | customer must affirmatively confirm ownership/license before the asset becomes eligible for generation to reference |
| `usage_confirmed_by`, `usage_confirmed_at` | FK/timestamp, nullable | |
| `created_at`, `updated_at` | timestamps | |

### 13.3 The copy-to-WebsiteAsset seam (Slice 5) — never a cross-table reference

A `business_media_assets` row's **content** is copied into Website scope by `MediaBindingService` (§8.7) calling the existing, unmodified `WebsiteAssetUploadService::store()` — creating a normal `WebsiteAsset` row scoped to that Website, or reusing one with a matching `content_hash`. There is **never** a foreign key from `website_pages.sections` (or any other Website-scoped table) into `business_media_assets` — this preserves Slice A's existing invariant that a `WebsiteAsset`'s lifecycle (including the permanent-once-published `first_published_at` retention rule, `app/Models/WebsiteAsset.php`) is entirely Website-scoped and untouched by this contract.

### 13.4 Missing-image checklist and deterministic fallback

`completenessCheck()` computes, per selected template's `page_manifest.image_slots` (§7.3), which slots have no eligible (`usage_confirmed = true`) `business_media_assets` row of the matching `purpose`. Surfaced at generation-journey step 4 (§12) as a plain list. A slot with no eligible asset — or an informative slot whose only candidate lacks `alt_text` — is left unbound by `MediaBindingService`'s binding plan (§8.7) and the page is generated **without** that image field populated — the existing, unmodified renderer already handles an absent image gracefully (a themed background/gradient per the existing bounded `websites.theme`). No stock photo, no AI-generated image, no silently-substituted business imagery is ever inserted.

### 13.5 Informative vs decorative — explicit classification, never inferred

Per §8.8: every `image_slots` entry in a template's manifest is authored by the operator as either `informative` (requires non-empty alt text once bound) or `decorative` (may be empty, by design — concretely `hero.background_image`, which has no HTML `alt` attribute at all since it renders as a CSS background, §0). This classification lives in `website_templates.page_manifest`, never inferred at runtime from the image content itself.

---

## 14. EVENT/PUBLISH INTEGRATION

No change to `WebsitePublished`'s payload (`app/Events/Website/WebsitePublished.php` — `websiteId`, `websiteRevisionId`, `businessId`, unmodified) or dispatch sites (`WebsitePublisher::publish()`/`rollback()`, unmodified). Per §11.1 (locked), this contract does **not** register any listener for `WebsitePublished` — that remains a documented, future, separately-authorized seam, not work this contract's slices perform.

---

## 15. TRIAL AND DOMAIN BOUNDARIES

### 15.1 Domain rules — settled

Bring-your-own-domain only. The customer owns and pays their own registrar. The platform never registers, purchases, renews, warehouses, or takes ownership of any domain. The customer only *connects* an owned domain to their generated Website. Ownership verification, routing, automatic SSL, detach, and expiry behavior all belong to a future custom-domain/hosting slice and are **not implemented here** — this matches Slice A's own already-locked §40 boundary (*"CUSTOM DOMAINS — SLICE B BOUNDARY (future contract, not designed here)"*) exactly; this contract adds nothing to that boundary and does not touch `routes/public.php`'s hostname-agnostic routing.

### 15.2 Trial policy — LOCKED (§22 decision 1)

| Trial parameter | Locked value | Cost-control reasoning |
|---|---|---|
| Duration | 14 days | bounds exposure to a fixed, predictable window |
| Businesses | 1 | matches the existing one-Website-per-Business invariant exactly |
| Full generations | 1 (matches §9.2's general rule — full generation is a one-time, initial-empty-Website operation for every tier, not a trial-specific restriction) | bounds AI spend to exactly one attempt plus its one built-in retry — the single largest-token AI call in this whole design |
| Generated pages | ≤6 | small enough that even a worst-case 40-section-per-page trial draft stays within a bounded, predictable token/output cost, while large enough to demonstrate a real multi-page site |
| Targeted rewrites | 3 | lets a trial customer meaningfully iterate without opening the door to unlimited AI spend per trial account |
| Platform preview | one temporary preview, tied to trial expiry | reuses Slice A's existing `preview()` action unmodified |
| Custom domain connection | optional, at most 1, only once a future domain slice exists | this contract does not build domain connection; a trial account simply never exercises that step until it does |
| AI-generated images | 0 (none) | matches §13.1's platform-wide rule — not trial-specific, applies to every tier |
| Recurring/scheduled SEO monitoring | none | matches §11.1's locked non-authorization of the Opportunity-engine seam — not activated for any tier by this contract |
| Expiry behavior | the draft (and any published revision) is retained; public serving stops via the existing `EntitlementManager`/`WebsitePublicEntitlementGate` denial chain (§2); restoration is immediate on payment because nothing was deleted, only entitlement-gated | zero data-recovery engineering needed — this is a direct, free consequence of Slice A's existing entitlement design |

This table is now a locked working policy for this contract's slices, not an open recommendation.

---

## 16. IMPLEMENTATION SLICES AND DEPENDENCY ORDER — CORRECTED

| Slice | Scope | Depends on | Allowlist |
|---|---|---|---|
| **Slice 1 — Business Knowledge Profile foundation** | **5 migrations** (`create_business_knowledge_profiles_table`, `add_hours_and_provenance_to_business_locations_table`, `create_business_knowledge_profile_field_states_table`, `create_business_knowledge_profile_changes_table`, `backfill_business_knowledge_profiles_for_existing_businesses`); `BusinessKnowledgeProfile`/`BusinessKnowledgeProfileFieldState`/`BusinessKnowledgeProfileChange` models; `BusinessKnowledgeProfileManager` (§4.3, §5.5) including `updateLocationHours()`; `BusinessKnowledgeProfileFieldKey` enum (§5.1); the §3.4 page-count-ceiling fix inside `WebsiteDraftPageService::createPage()`. **Correction: `business_media_assets` is not part of Slice 1 — it is Slice 5, §13.** | none (pure additive schema + one narrow existing-file extension) | `database/migrations/*business_knowledge_profile*`, `database/migrations/*add_hours*business_locations*`, `app/Models/BusinessKnowledgeProfile*.php`, `app/Library/Business/BusinessKnowledgeProfileManager.php`, `app/Enums/Business/BusinessKnowledgeProfileFieldKey.php`, `app/Library/Website/WebsiteDraftPageService.php` (page-count check only) |
| **Slice 2 — Verticals + question packs + completeness UI** | `business_verticals` and `question_packs` tables/models (§6); `completenessCheck()`; the guided-setup controller actions/views (Option A, §3.3, locked) | Slice 1 | `database/migrations/*business_verticals*`, `database/migrations/*question_packs*`, new controller under `app/Http/Controllers/Customer/Business/`, new views under `resources/views/customer/business/website/` |
| **Slice 3 — Template manifest + selection** | `website_templates` table/model (including `manifest_version`, §7.3), template-selection UI, `websites.template_key` column | Slice 2, **and** §7.2's human-supplied import materials (blocked otherwise) | `database/migrations/*website_templates*`, `database/migrations/*add_template_key_to_websites*` |
| **Slice 4 — Generation pipeline (text batch)** | `GuidedWebsiteGenerationClient`, `GuidedGenerationOutputValidator`'s text-only checks (§8.5), `website_guided_generation_attempts` table (with `retry_count`/`lease_expires_at`, §8.10), the full-generation request endpoint (produces a validated text batch, handed to Slice 5's binding-and-commit pipeline, §8.7) and the section-rewrite endpoint (§8.9 — commits directly via the existing `WebsiteDraftPageService::updatePage()`, no media binding involved), §9.2 count-based caps, §10's idempotency/concurrency logic, **the backward-compatible `WebsiteUrlRules::isValid(string $url, bool $allowInternalPath = false)` parameter addition (§8.6) and its regression test** | Slice 3 | `app/Library/Website/GuidedGeneration/GuidedWebsiteGenerationClient.php`, `app/Library/Website/GuidedGeneration/GuidedGenerationOutputValidator.php`, `database/migrations/*guided_generation_attempts*`, narrow additions to `app/Http/Controllers/Customer/Business/WebsiteController.php` for the two new actions, `app/Library/Website/WebsiteUrlRules.php` (adds one optional parameter with a default that preserves every existing call site; no existing line is modified) |
| **Slice 5 — Business media assets, media-binding plan, and atomic commit** | `business_media_assets` table/model, `BusinessMediaUploadService`, `MediaBindingService` (§8.7's binding-plan and asset-materialization steps), `GuidedGenerationOutputValidator`'s final combined validation (asset-inclusive, §8.8), the new `GuidedGenerationCommitService` that opens the one orchestration `DB::transaction()`, commits the complete page batch via `WebsiteDraftPageService`, and marks the attempt `succeeded` (§8.7 step 4), and the missing-image checklist (§13.4) | Slice 1 (Profile), Slice 4 (validated text batches to bind media into and commit) | `database/migrations/*business_media_assets*`, `app/Library/Business/BusinessMediaUploadService.php`, `app/Library/Website/GuidedGeneration/MediaBindingService.php`, `app/Library/Website/GuidedGeneration/GuidedGenerationCommitService.php` |
| **Slice 6 (documented seam only — NOT implementation-authorized, §22 decision 7)** | `WebsiteOpportunityProducer`, its job, `WebsitePublished` listener | Slice 4 | none — this contract authorizes no files for Slice 6 |

No slice touches `app/Http/Controllers/Public/WebsiteController.php`, `app/Library/Website/WebsitePublisher.php`, `app/Library/Website/WebsiteSnapshotBuilder.php`, `app/Library/Website/WebsitePublicEntitlementGate.php`, `app/Enums/Website/WebsiteSectionType.php`'s case list, or any B3/B4/B5/GBP file. `app/Library/Website/WebsiteUrlRules.php` (Slice 4) and `app/Library/Website/WebsiteDraftPageService.php` (Slice 1, page-count check only) are the two narrow, cited exceptions to "no other Slice A file changes."

---

## 17. ACCEPTANCE CRITERIA

1. A Business with an empty Knowledge Profile completes `completenessCheck()` and receives exactly the vertical/industry-appropriate question set (§6.3's resolution order), never a generic unbounded prompt.
2. Answering questions writes through `BusinessKnowledgeProfileManager::updateFields()`/`updateLocationHours()` only, verified by a mechanical seam test asserting no other code path writes the tracked tables.
3. Selecting a template and generating produces a Website whose every page/section passes the exact existing `WebsiteSectionValidator` rules, with zero new section types introduced, and **zero asset/alt-text fields present in the AI-authored output itself** (§8.3).
4. A full-generation batch that fails validation at any pipeline stage — the text-batch validation (after exactly one retry), the final combined validation of the bound section JSON, or the commit transaction itself — creates **zero pages**, never a partial set and never a page visible before the commit transaction completes (§8.2).
5. A generation batch referencing an unverified sensitive fact never happens, because unverified sensitive facts are never sent to the AI at all (§5.2) — this is verified by inspecting the exact request payload in a test, not by inferring intent from output.
6. A repeated generation request whose canonical idempotency inputs (§10.1) are unchanged returns the stored `succeeded` draft, never a second AI call; a repeated request after a `failed` attempt always tries again.
7. Publishing a guided-generated Website behaves identically, under test, to publishing a manually-built one — same `WebsitePublisher`, same revision/rollback guarantees.
8. A trial Business past its 14-day expiry (§15.2) is denied publicly by the existing `EntitlementManager`/`WebsitePublicEntitlementGate` chain, with its draft and any prior published revision intact and immediately restorable.
9. An informative image slot whose only candidate asset lacks alt text is never bound by `MediaBindingService`'s plan and is recorded only as a warning (§8.7); a manually constructed informative binding without alt text is independently rejected by `GuidedGenerationOutputValidator` (§8.8); a decorative slot with no alt text passes in either case.
10. A testimonial rendered in generated output exact-string-matches a confirmed Profile testimonial entry; no invented or paraphrased testimonial content ever appears (§8.5 step 3).
11. No test, migration, or code path in this contract's slices references a domain-registration API, a `website_domains` table, a form-builder table/route, a calendar/booking table, an `Offer`/`Product` sellable-item table, a new `BusinessIndustry` enum case, or a `WebsiteOpportunityProducer` implementation.

---

## 18. FOCUSED TEST PLAN

- **Profile seam**: mechanical test proving only `BusinessKnowledgeProfileManager` writes the tracked tables, and that `updateFields()` rejects `hours` as a key.
- **Field-key allowlist**: an unknown `field_key` is rejected; every `BusinessKnowledgeProfileFieldKey` case round-trips correctly.
- **Financing/pricing separation**: `pricing_method = 'fixed'` and `financing_available = true` coexist without validation conflict; `financing_available` is never accepted as a `pricing_method` value.
- **Hours — multi-period validation**: 4 non-overlapping periods on one day pass; a 5th is rejected; overlapping periods are rejected; a period with `close <= open` within the same day is rejected; an overnight business's two-entry (`24:00` sentinel + next-day `00:00`) representation round-trips correctly; hours provenance/freshness is read from `business_locations`, never from `business_knowledge_profile_field_states`.
- **Vertical/question-pack resolution**: an active vertical-targeted pack wins over a broad-industry pack, which wins over `general_v1`; an inactive/older-version pack is never selected; adding a new vertical requires zero PHP/migration changes (a pure-data test).
- **Completeness check**: fixtures with fully-populated, partially-populated, and empty profiles each return the exact expected missing/stale field set; a fact past its field-specific `reconfirmAfterDays()` is flagged stale even when technically present; tier does not affect staleness.
- **Page-count ceiling** (Slice 1 fix, §3.4): the 21st manual `pages.store` call is rejected with the same error shape as the existing AI-batch ceiling.
- **Template manifest validation**: seeding a `website_templates` row with a section type outside the 8-enum allowlist fails at seed time; `image_slots` classification (`informative`/`decorative`) is required per slot.
- **Idempotency**: two identical canonical-input requests produce exactly one `succeeded` attempt and one set of pages; changing any one input (a service, a location's hours, the template, the model policy) produces a different idempotency key and a fresh attempt; a `failed` attempt never short-circuits a subsequent identical request.
- **Idempotency key ordering stability**: fixtures with identical underlying facts (same services, same confirmed profile fields, same locations) but constructed/retrieved from the database in deliberately reversed or randomized row order produce the **exact same** idempotency key, proving `selected_locations`/`active_services`/`confirmed_profile_facts` are canonically sorted before hashing regardless of retrieval order; a fixture where only `growth_priority_service_ids`' stored ranking order changes (same set of IDs, different order) produces a **different** key, proving intentionally-ordered fields are never re-sorted away.
- **Concurrency**: a second full-generation request while a non-expired `pending` attempt exists for the same Website is rejected without creating a second row; a `pending` attempt past its 5-minute lease is transitioned to `failed` on the next request and a new attempt is then allowed.
- **Atomicity**: a batch where one required page fails text-batch validation results in zero pages created after the one retry also fails; a batch that passes text validation but fails the final combined validation (§8.8), or a simulated failure inside the commit transaction (§8.7 step 4), also results in zero pages created or modified — never a partial set, and never a page visible before the commit transaction completes.
- **Sensitive-fact exclusion**: constructing the AI request payload for a Business with unconfirmed credentials/warranties/hours/offers/testimonials never includes them in any form (not even labeled unverified); confirming them makes them eligible.
- **Confirmed-fact-allowlist check**: a generated credential/year/price value not present in the confirmed set fails; the same value once actually confirmed passes.
- **Testimonial verbatim check**: a generated testimonial section item matching a confirmed entry exactly passes; any deviation (reworded quote, altered author name) fails.
- **Media-binding plan**: an unconfirmed (`usage_confirmed = false`) upload is never selected; confirming it makes it eligible; a repeat binding for the same content reuses an existing `WebsiteAsset` by `content_hash` rather than duplicating it; an informative slot whose only candidate lacks `alt_text` is left unbound with a `warnings` entry, never bound and never a validation failure; a decorative slot may bind an asset with empty `alt_text`.
- **Final combined validation (accessibility)**: a direct unit test proves `GuidedGenerationOutputValidator` rejects a manually constructed informative binding with empty `alt_text` (bypassing `MediaBindingService`); a decorative slot (hero background) with empty `alt_text` passes explicitly; this validation is proven to run against the complete proposed section JSON before any page exists.
- **Atomic commit**: a failure in the final combined validation, or a simulated failure inside the commit transaction, leaves zero pages created; any `WebsiteAsset` materialized during the binding-plan step for such a failed attempt is proven either cleaned up or left unreferenced — never attached to a partially generated page; provider/AI calls and filesystem asset writes are proven to occur strictly outside the `DB::transaction()` boundary.
- **Internal link extension**: every pre-existing call site of `WebsiteUrlRules::isValid($url)` (single argument, default `$allowInternalPath = false`) continues to accept only `tel:`, `mailto:`, `https://`, with zero behavioral change; a valid root-relative path resolving to a real batch page is accepted **only** when called with `allowInternalPath: true`; `//`, `..`, backslashes, fragments, and non-allowlisted schemes are all rejected even with `allowInternalPath: true`; `conversion_target` calls `isValid()` with the default `false` and never accepts the internal-path shape; `GuidedGenerationOutputValidator` is confirmed to be the only call site in the codebase that ever passes `true`.
- **Rewrite caps**: the 21st Core-tier rewrite within a rolling 30 days is rejected; Growth/Agency use their own locked ceilings (§9.2).
- **Trial expiry**: a trial-tier Business past 14 days is denied via the existing entitlement chain; nothing is deleted; restoration after payment requires no data-recovery step.
- **Boundary regression**: proves this contract introduces no form-builder, no calendar/booking table, no `Offer`/`Product` sellable-item table, no reviews/ratings table beyond the bounded `testimonials` field, no new `BusinessIndustry` case, no domain-registration code, and no `WebsiteOpportunityProducer` implementation.
- **Cross-lane regression**: full existing `tests/Feature/Website/**`, `tests/Feature/Entitlement/**`, and `tests/Feature/Business/**` suites remain green with zero modification required to any existing test file.

---

## 19. MIGRATION/ROLLBACK PLAN

Slice 1's **five** migrations (§16) are purely additive (new tables, new columns on `business_locations`) — every `down()` drops exactly what its `up()` created, in reverse dependency order, mirroring Slice A's own proven migrate/rollback discipline (`tests/Feature/Website/WebsiteMigrationsTest.php`'s pattern). The backfill migration is idempotent and safe to re-run. No existing table's existing column is altered, renamed, or dropped anywhere in this contract's slices. Later slices' migrations (`business_verticals`, `question_packs`, `website_templates` + `manifest_version`, `add_template_key_to_websites`, `website_guided_generation_attempts`, `business_media_assets`) follow the identical additive-only discipline, each owned by the exact slice named in §16.

---

## 20. EXPLICIT EXCLUSIONS

- Custom domains, DNS, TLS/ACME, CDN, `website_domains` table (§15.1) — Slice B, not this contract.
- A generic free-canvas editor, arbitrary HTML/JSON-LD, or arbitrary scripts anywhere in the customer-facing editing surface.
- A blog CMS or a native form/lead-capture component.
- A calendar/booking/appointment engine — conversion journeys resolve to an external `tel:`/`mailto:`/`https://` target only (`conversion_target`, unchanged, §4.2); internal navigation links (a genuinely different field/purpose) may use the narrow root-relative extension in §8.6, never a booking system.
- An `Offer`/`Product`/`Package` sellable-item/checkout model — `offers` in §4.2 is copy-only text, never a cart/checkout/booking entity.
- A public reviews platform, a rating/stars concept, or any GBP-review ingestion — `testimonials` (§4.2, §13) is a small, bounded, self-attested, verbatim-rendered field only.
- Standard AI image generation (§13.1).
- A generic AI model-routing/escalation policy beyond the single narrow config override in §8.4.
- Real RFC-005 usage-wallet metering activation for AI generation (§9.1) — count-based caps only.
- A new `BusinessIndustry` enum case for any vertical/trade (§6) — verticals are catalog data.
- A `WebsiteOpportunityProducer` implementation, its job, or its `WebsitePublished` listener (§11.1, §16 Slice 6) — documented seam only, not implementation-authorized.
- Any modification to `app/Http/Controllers/Public/WebsiteController.php`, the render path, or the 8-type component enum's case list.
- Any B3 (Platform Settings), B4 (Automations), B5 (Analytics), or GBP (Lane C) file.
- Recurring/monthly full-generation allowances of any kind (§9.2) — full generation is a one-time, initial-empty-Website operation.

---

## 21. STOP CONDITIONS

- If `origin/main` advances with product code (not documentation) touching `app/Models/Website*.php`, `app/Library/Website/*.php`, or the entitlement/usage files this contract cites, before implementation begins, re-verify every cited line number and evidence claim before proceeding — do not assume staleness is cosmetic.
- If the four template import materials (§7.2) are not supplied, Slice 3 (and therefore Slice 4's template-dependent context) cannot begin; Slices 1-2 may still proceed independently since they do not depend on template content.
- If any implementation step would require weakening `WebsiteSectionValidator`, introducing a 9th section type, or lifting the 40-section/20-page ceilings rather than merely closing the existing enforcement gap (§3.4), stop and treat that as a new contract decision, not an in-flight adjustment.
- If any implementation step would widen `WebsiteUrlRules`'s internal-path acceptance beyond the exact shape in §8.6 (e.g. accepting query strings, fragments, or protocol-relative URLs), stop — that is a new security decision, not part of this contract.
- If any implementation step would send an unverified sensitive fact to an AI call, or allow a section to carry its own `alt_text` field, stop — both are corrected, locked behaviors in this document (§5.2, §8.7), not open to silent reinterpretation.

---

## 22. LOCKED DECISIONS (this correction pass)

The following were open human-review items in the prior draft and are now settled for this contract's implementation slices:

1. **Trial policy** — the exact 14-day/1-Business/1-generation/≤6-page/3-rewrite/1-preview/optional-1-domain/0-images/no-scheduled-SEO/retain-and-restore policy in §15.2 is locked, not a recommendation.
2. **Business Settings tenancy** — Option A is approved (§3.3): a new, narrow, Workspace/Business-scoped Profile write surface; the legacy flat `BusinessController` is not touched by this contract.
3. **Roofing** stays under the existing `BusinessIndustry::HomeServices` case, classified further by the new, confirmed `vertical_key = 'roofing'` (§6) — no new `BusinessIndustry` enum case is added, now or by implication later, for any single trade.
4. **`gbp_future`** is not reserved as a `reviews_source`/testimonial-source value now. A later GBP reviews contract must add its own authorized source behavior from scratch if it ever ships.
5. **Freshness is field-sensitive, not plan-tier-sensitive** (§5.4) — each `BusinessKnowledgeProfileFieldKey` carries its own `reconfirmAfterDays()`; Growth/Agency only controls *when* a scheduled check runs (§11.2), never the staleness definition itself.
6. **Full generation is a one-time, initial-empty-Website operation**, not a recurring monthly allowance (§9.2) — the prior "4/8/unlimited full generations per month" language is removed. Targeted rewrite caps are locked as working defaults: Core 20, Growth 40, Agency 100 per rolling 30 days.
7. **`WebsiteOpportunityProducer` is not implementation-authorized** in this contract's slices (§11.1, §16 Slice 6) — the seam is documented for a future, separately-authorized contract, and nothing more.

---

## 23. HUMAN-REVIEW DECISIONS (genuinely remaining after §22)

1. **The four template import materials (§7.2)** — a hard blocking prerequisite for Slice 3/4, not merely a preference. Slices 1-2 can proceed without it.
2. **Exact per-field `reconfirmAfterDays()` day-counts (§5.4)** — the 90/180/365-day defaults are this contract's reasoned starting values, not independently re-approved per field; an operator may retune the constants later without a schema change.
3. **Whether to seed real `business_verticals`/`question_packs`/`website_templates` catalog *content*** (the actual question wording, vertical list, template copy) is an editorial/operational task for whoever runs Slices 2-3, not a decision this contract makes — it only fixes the schema and resolution rules those seeds must conform to.

---

`WEBSITE GUIDED GENERATION + BUSINESS KNOWLEDGE PROFILE CONTRACT — READY FOR HUMAN/CHATGPT REVIEW`
