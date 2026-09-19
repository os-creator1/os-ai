# Implementation Contract 18 — SEO Expansion

**Status:** Planning contract only. Does not authorize implementation.
Written on `main` @ `30ad21c7b33034618f3ccba0d9098035133983ff` (Contracts
1–14 complete; Slice 16 contract merged as #329). Eight independently
mergeable sub-slices (§15/§21, A–H) implement this contract in dependency
order; **no sub-slice may start without its own separate, explicit human
authorization** (route-3 governance, `CLAUDE.md`). Rated Low risk / L
complexity by the Roadmap. **That rating is corrected here for exactly one
sub-slice:** Sub-slice B modifies the merged, security-critical Google
Business Profile (GBP) connection layer and is rated **Medium**. It is
isolated so the rest of the slice does not depend on it (§7.4).

Two findings in §3.2 correct statements in the authority documents and
must be read before anything else: the "existing Keywords module" the
Roadmap says to extend is **not an SEO feature**, and the Traceability
Matrix's "no GBP found" is stale.

## 1. Objective

Design and, across eight dependency-ordered sub-slices, build the V1 SEO
module: **Overview, Keywords, Google Business Profile, Website SEO,
Citations, Reviews** (Blueprint §15). GBP already exists and is **reused,
not rebuilt**. Everything else is net-new. The contract resolves, from
evidence, what Core sees versus what Growth/Agency unlock, which data is
Business-wide versus Location-bound, how Search Console connects without a
second Google OAuth authority, and how each module behaves when no
provider automation exists — separating internal workflow/tracking from any
future vendor integration.

## 2. Governing authority

- `docs/product/V1-MASTER-PRODUCT-BLUEPRINT.md` §15 (SEO), §5 (Business-wide
  vs Location-bound matrix: **Search Console property = Business-wide;
  Google Business Profile = Location-bound; Website project = Business-wide**),
  §14 (Website: one Business = one website; Location pages), §21 (Plan Model:
  *Basic SEO/Ads visibility* Core+; *Full SEO (rankings, reviews, citations,
  technical SEO)* Growth+), §26 (feature permission × `location_access_scope`),
  §31 (domain-event envelope), §32 (security/audit), §36 (sensible defaults
  for unstated minor choices).
- `docs/product/V1-IMPLEMENTATION-ROADMAP.md` "Slices 15–18" (Slice 18: L,
  Low, independent; Wave 2 Lane F).
- `docs/product/V1-AUTHORITY-TRACEABILITY-MATRIX.md` row 12 (**two
  statements in it are corrected by §3.2**).
- `docs/product/V1-ACCEPTANCE-MATRIX.md` "Manage SEO": *"See
  rankings/keywords; Growth+ gets full local SEO"*; tier *"Basic: Core+;
  Full: Growth+"*; scope *"BW (Search Console) / LB (GBP)"*; permission
  *"Owner + staff per feature permission"*; acceptance *"Owner sees keyword
  visibility at minimum on Core"*.
- `docs/rfcs/V1-ARCHITECTURE-DECISION-ADDENDUM.md` §4 (Location ACL), §5
  (operational Location ownership), §13 (one website per Business).
- `docs/automation/GOOGLE-BUSINESS-PROFILE-CONTRACT.md` — binding on this
  slice where stated: §13 (Google-content retention), §31 (stop-list), §36
  (Slice B/C boundaries), §37.2 (**"SEO may later consume a read-only,
  Business-scoped query service … SEO never stores GBP credentials, never
  calls Google directly, never mutates Google"**).
- `docs/automation/WEBSITE-GENERATION-HOSTING-CONTRACT.md` §9.2 (`WebsitePublished`
  seam), §17.1 (sole page-write seam), §20 (SEO core boundary), §21
  (platform-wide `noindex`), §40 (custom domains — unbuilt).
- `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE.md` §12/§13/§27 (workers never write
  live data; closed evidence-template registry).
- `docs/rfcs/RFC-004` (a `Planned` `PlatformFeature` is never
  customer-executable), and Contract 16 as the format/convention precedent.

## 3. Current repository reality — recon findings (on `main` @ `30ad21c7`)

### 3.1 Authority text this slice implements

Blueprint §15, verbatim: *"Covers Overview, Keywords, Google Business
Profile, Website SEO, Citations, and Reviews. Search Console integration is
Business-level (one property, matching the one-website rule in §14); Google
Business Profile is Location-level, since each Location is a distinct
physical/service-area presence with its own GBP listing. Full local-SEO
depth (citations, review management workflows, technical SEO detail) is a
Growth capability; Core sees basic SEO/Ads visibility only (§21)."*

### 3.2 Corrections to the authority documents (recorded, not silently resolved)

**F1 — The "existing Keywords module" is not SEO.** Roadmap Slice 18 ("Existing
Keywords module extended") and Traceability row 12 ("Only `Keywords.php`/
`KeywordController` found") treat legacy `Keywords` as the seed of SEO
keywords. Reading it disproves that. `app/Models/Keywords.php` and
`database/migrations/2020_05_27_084347_create_keywords_table.php` define an
**inbound-SMS keyword product**: `keyword_name`, `sender_id`,
`reply_text`/`reply_voice`/`reply_mms`, `price`, `billing_cycle`,
`validity_date`, status `available|assigned|expired`; `CheckKeywords` expires
them daily; the customer routes purchase and release them (`customer.keywords.*`);
`CustomerMenuBuilder` labels it *"Words people can text in to reach you"* under
permission `view_keywords`; the Product Surface Retention Audit folds it into
the simplified number/channel connect (Slice 10 there). **It has no
relationship to search engines.** Consequences, all binding:

1. SEO keywords are **net-new**. Legacy `Keywords` is neither extended nor
   renamed nor migrated by this slice.
2. Two "Keywords" concepts will coexist in the product. SEO must use distinct
   tables (`seo_keywords`), models (`SeoKeyword`), permissions (`view_seo`/
   `manage_seo`, never `view_keywords`), route names
   (`customer.workspaces.businesses.seo.keywords.*`, never `customer.keywords.*`
   — that prefix is on `ViewAsProhibitedActions::PREFIXES`) and UI copy that
   cannot be confused with text-in keywords (§14.3).
3. The Roadmap/Traceability/Index statements are wrong and are corrected in
   Sub-slice H (documentation edits are **not** made by this contract).

**F2 — GBP is built.** Traceability row 12 predates it. GBP Slice A is merged:
Growth + Agency only, read-only, Business-scoped connection, Location-bound
bindings (§3.4). This contract reuses it and does not duplicate any part.

**F3 — The Website contract promises more than the code renders.** Website
contract §20 lists "canonical URL generation" and Open Graph as Website Core.
`resources/views/public/website/page.blade.php` renders `<title>`, meta
description, `robots` and `og:title`/`og:description` **but no `<link
rel="canonical">`, and no JSON-LD/structured data exists anywhere in `app/`
or `resources/`** (grep-verified on this commit). These are Website-module
gaps (§3.6), not customer-actionable SEO findings, and this slice must never
present them as the customer's to-do.

### 3.3 Exists vs absent (grep-verified on this commit)

| Concern | State |
|---|---|
| Search Console (any file/name/API) | **Absent** — zero matches for `searchconsole`, `webmasters`, `searchanalytics` |
| Citations (any) | **Absent** |
| Reviews / review requests / reputation | **Absent** (matches are only `BusinessGoal`, `OpportunityWorkerKey`, registry copy) |
| Rank tracking / SERP | **Absent** |
| Technical-SEO audit, sitemap submission, structured data | **Absent**; a platform-path sitemap route exists (`public.website.sitemap`) |
| SEO keywords | **Absent** (legacy `Keywords` is unrelated, §3.2 F1) |
| `PlatformFeature::SeoBasicVisibility` | Exists, **Planned**, packaged Core+Growth+Agency (`2026_08_13_120007`) |
| `PlatformFeature::SeoModule` | Exists, **Planned**, packaged **Growth+Agency only** (same migration) |
| `PlatformFeature::GoogleBusinessProfileModule` | Exists, **Available**, Growth+Agency only (`2026_09_09_120004`) |
| `PlatformFeature::AdsBasicVisibility` / `GoogleAdsModule` | Exist, Planned — **out of scope** (§18) |
| `OpportunityWorkerKey::Seo`/`Reputation`/`Website` | Reserved enum cases; only `BusinessAdvisor` is implemented |
| `WebsitePublished` event | Exists, **zero listeners** — the documented SEO predecessor seam |
| `WebsiteDraftPageService` | Exists — the **sole** authorized seam for changing a draft page's `seo_title`/`meta_description`/`noindex`/`slug`/`sections`; docblock names "a future SEO module" as forbidden from writing `website_pages` directly |
| Website Location pages | **Absent** — `website_pages` has no `location_id`; no Location concept anywhere in Website code except reading the primary Location's address for the snapshot |
| Website custom domains | **Absent** — `websites` has no domain column; every public response carries `noindex` (Website §21) |
| Per-Location phone number | **Absent** — `business_locations` has no phone; canonical phone is `businesses.phone` |
| Location "Locked" state | **Not in code** — `BusinessLocationLifecycleState` has only `Active`/`Archived` (Blueprint §4 describes Locked) |
| `LocationAccessGuard` wired into GBP/Website | **No** — wired into Contacts, Conversations, CRM only; Contract 08B explicitly defers "Automations, Website, GBP, Messaging Channels, Outreach" |
| Automation review triggers | **None** (`WorkflowTriggerType`: contact_created, contact_date_reached, manual_enrollment, message_received, opportunity_created/stage_changed/won/lost); `send_sms` exists, **no email node** |
| Home "Visibility" band | Shows website status + Google connection state, and states *"no SEO score"* (`resources/views/customer/dashboard/bands/visibility.blade.php`) |

### 3.4 What GBP already provides (reuse map, evidence)

| Fact | Evidence |
|---|---|
| One Google connection per Business | `business_google_connections`: `unique(business_id)` = `bgc_business_unique`; `unique(id,business_id)` = `bgc_id_business_unique` |
| Refresh token encrypted; only credential stored | `refresh_token_encrypted`, `granted_scopes`, `state` ∈ `pending|active|revoked|disconnected` |
| **Single hard-wired scope, no incremental consent** | `HttpGoogleBusinessProfileReadClient::SCOPE = …/auth/business.manage`; `include_granted_scopes => 'false'` |
| Dedicated OAuth client, one fixed tenant-free callback | `services.google_business_profile`; route `customer.gbp.oauth.callback`; `GoogleBusinessProfileOAuthConfig` |
| Location-bound binding | `business_google_locations.unique(business_location_id)`, composite FK `(business_location_id, business_id)` |
| Mirror ≤ 30 days, default **unset = nothing stored** | `config/google_business_profile.php` `mirror.retention_days`; contract §13 |
| Mirror already carries the Google write-a-review link and Maps link | `profile_mirror.new_review_uri`, `maps_uri` (contract §21.2) |
| Derived health, verification, comparator, budget, breaker, daily staggered sweep, hourly purge | `GoogleLocationHealth`, `GoogleBusinessProfileComparator`, `GoogleBusinessProfileCallBudget`, `SweepGoogleBusinessProfileRefreshes`, `PurgeExpiredGoogleBusinessProfileMirrors` |
| Private-address predicate | `GoogleBusinessProfileReadMask::addressPermittedForLocation()` — public, pure |
| Operation/audit ledger | `business_google_operations` (`business_google_location_id` nullable) |
| Permissions, per-customer JSON + backfill | `view_google_business_profile` (default true), `manage_google_business_profile` (default false); backfill `2026_09_09_120006` |
| View As closed inventory | connect/disconnect/refresh/bind/unbind and the callback are in `ViewAsProhibitedActions` |
| **No read-only status query service** | GBP §37.2 promised one "not built in Slice A"; none exists in `app/Library/GoogleBusinessProfile/` |

### 3.5 Inherited constraints (binding on every sub-slice)

1. **GBP §13 / §31 / §36.2:** no durable Google review, rating, reviewer-PII or
   metrics archive; no aggregation of Google content; no "generic review
   platform"; **no dead Reviews/Posts/Media/Performance tabs**; reviews exist
   only on Google My Business API v4.9, only for verified locations.
2. **GBP §37.2:** SEO never stores GBP credentials, never calls Google directly
   *for GBP data*, never mutates Google; SEO's own NAP work functions without GBP;
   no circular dependency.
3. **Website §17.1:** SEO never writes `website_pages`, directly or through any
   SEO-specific method on `WebsiteDraftPageService`.
4. **Website §21:** no platform-path URL is ever presented as indexed, ranked or
   driving traffic.
5. **RFC-002:** an SEO producer, if ever built, is a closed-registry,
   evidence-templated worker that cannot write live data.
6. **Location ACL (Addendum §4, Contract 02):** knowing an ID never grants access;
   `LocationAccessGuard` is the only Location authority.
7. **Lifecycle (Contract 03):** a Locked account is redirected from every route not
   on the gate's allowlist — this slice adds **nothing** to that allowlist.
8. **View As (Contract 04):** route classification is a closed inventory enforced by
   `tests/Feature/Security/ViewAsRouteBoundaryTest.php`.
9. **RFC-004:** no customer-executable route while its `PlatformFeature` is `Planned`.
10. **Permissions are per-customer JSON** (`customers.permissions`); a config key
    grants nothing to existing customers without a data-backfill migration
    (GBP contract §1.3 G-C).

### 3.6 Verified external facts (official Google documentation, fetched for this contract)

| Fact | Source | Used in |
|---|---|---|
| Search Console API scopes: `https://www.googleapis.com/auth/webmasters` (read/write) and `https://www.googleapis.com/auth/webmasters.readonly` (read-only). **Unlike GBP, a genuine read-only scope exists.** | developers.google.com/webmaster-tools/v1/how-tos/authorizing | §7 |
| Search Analytics quota: **1,200 QPM per site, 1,200 QPM per user, 40,000 QPM and 30,000,000 QPD per project**; URL Inspection is a separate, much smaller quota; "all other resources" 20 QPS/200 QPM per user | …/webmaster-tools/limits | §11 |
| `searchanalytics.query`: `startDate`/`endDate` in PT; `dimensions` include `query`,`page`,`date`; **`rowLimit` 1–25,000 (default 1,000)**; `startRow`; `dataState` `final` (default) / `all` / `hourly_all`; response `metadata.first_incomplete_date`; API "does not guarantee to return all data rows but rather top ones" | …/searchanalytics/query | §8.3, §11 |
| `sites.list` returns `siteUrl` and `permissionLevel`; URL-prefix (`https://example.com/`) and domain (`sc-domain:example.com`) property forms | …/sites/list | §8.2 |
| Google API Services User Data Policy (Limited Use): data may be used only for prominent user-facing features; **no transfer/sale to third parties or ad platforms; no use for ads**; human access restricted; **no maximum storage duration stated** | developers.google.com/terms/api-services-user-data-policy | §13 |
| Google Maps review policy: **prohibits review gating ("selectively solicit positive reviews"), incentives for reviews, and staff review quotas**; permits soliciting genuine-experience reviews without incentives | support.google.com/contributionpolicy/answer/7400114 | §8.6 |

**Explicitly NOT verified — the implementing session must verify before coding,
and this contract does not assert them:** the exact enumeration of
`permissionLevel` values; Search Console's own historical-data window and data
freshness delay (the fetched pages did not state them); whether
`webmasters.readonly` is classified sensitive and what OAuth-app verification it
needs; the Google Cloud project prerequisites (enabling the Search Console API,
consent-screen scope list). None of these changes the design; each is an
operator/verification gate (§19, OD-4).

### 3.7 Known gaps this slice must not paper over

| Gap | Consequence recorded in this contract |
|---|---|
| G-1 No Website Location pages / no page↔Location association | Website SEO audits **Business-wide pages only**; Location-page findings are deferred and specified as a future dependency (§8.7, §18) |
| G-2 No custom domains; platform-wide `noindex` | Hosted-site audit reports an **indexability status**, never rank/traffic; Search Console binds to the Business's own real domain (`businesses.website_url`) (§8.2) |
| G-3 No canonical link tag, no JSON-LD | Website-module gaps; listed for the Website roadmap, never surfaced as customer findings (§8.7) |
| G-4 No per-Location phone | NAP comparison uses `businesses.phone` until a Location phone exists (§8.5) |
| G-5 Location "Locked" not representable | Location-bound writes require `BusinessLocation::isActive()`; when Locked lands it is consumed through that same predicate (§10.5) |
| G-6 GBP/Website controllers not Location-ACL-wired | SEO applies `LocationAccessGuard` itself to every Location-bound read/write and to its GBP read model; it does **not** retrofit GBP/Website (§10.4) |
| G-7 No review ingestion | Reviews module is workflow/tracking only; Google review content is out of scope until a separately contracted GBP Slice C (§8.6) |
| G-8 No SEO→Home/COO/B5/Activity Center feed | Non-goal (§18); documented seams only |

## 4. Delta from current state to target

Additive except Sub-slice B, which changes one merged table's uniqueness and
every GBP lookup of it (behavior-preserving, §7.3). Adjacent legacy tables
(`keywords`, `contact_groups_optin_keywords`, SMS keyword purchase) are a
different bounded context and are untouched (§3.2 F1, §18).

## 5. Product boundary — Core vs Growth/Agency (resolved)

### 5.1 The rule

| Tier | Feature key | What it unlocks |
|---|---|---|
| **Core** (also Growth, Agency) | `seo_basic_visibility` | **SEO Overview, platform-derived only** — zero calls to Google or any provider, zero provider data stored (§5.2) |
| **Growth, Agency** | `seo_module` | Search Console connection + performance/ranking data; keyword position alignment; Citations workflow; Reviews workflow; technical/Website SEO audit; the GBP section (already gated by `google_business_profile_module`) |

Agency has exactly Growth's SEO depth (Blueprint §21 matrix is identical for
the two). This slice adds **no** Agency-only SEO capability and **no**
cross-client SEO rollup (§18). Pricing is commercial configuration (§21 of
the Blueprint) and is not touched.

### 5.2 What Core sees — "useful basic SEO visibility", concretely

All computed from platform-owned data at read time; no external call:

1. **Setup readiness** — a closed, deterministic checklist (registry
   `SeoReadinessRuleRegistry`, ≤ 8 items): Business website URL set; Business
   phone set; each accessible Location has a usable address **or** service area;
   website published; Google Business Profile URL present (the existing
   free-text field — informational, Core cannot connect GBP); target keywords
   defined. Each item states a fact and, where actionable, links to the
   existing screen that fixes it.
2. **Published-content basics** — counts from the latest published Website
   snapshot: pages with/without meta description, pages with/without SEO
   title, pages set `noindex`. No findings list (that is Growth's audit, §8.7).
3. **Keyword visibility (Acceptance Matrix: "keyword visibility at minimum on
   Core")** — the owner's target keywords (Business-wide, optional Location
   attribution) each with **on-page coverage**: whether the phrase appears in
   the published titles/descriptions/body, and where. **Not rankings.**
4. **Indexability status** — an honest one-line state: while the site is served
   from the platform path it is not indexable by design (Website §21).

### 5.3 What Growth/Agency add

Search Console totals/top queries/top pages with as-of dates; keyword position
and impressions from Search Console; the GBP section (existing pages, plus a
Location-filtered status summary); Citations; Reviews; the technical/Website
audit with a findings list; latest-audit summary on the Overview.

### 5.4 Deliberate default and how to change it (OD-1)

Search Console totals are **Growth+**, not Core. Reasons: the Blueprint places
"rankings" in the Growth-only parenthetical; Core is the high-volume tier and
Search Console/OAuth-token load should not scale with it; Core still has a
real, provider-free "what should I fix" surface. If the owner wants a
totals-only tile on Core, the contained change is: gate that one panel's
reader on `seo_basic_visibility` and add the Core connect path — recorded as
owner decision OD-1, **not** built.

## 6. Canonical scopes

| Data | Scope | Canonical owner | Location ACL |
|---|---|---|---|
| Search Console connection & selected property | **Business-wide** (one property per Business) | Google connection authority (§7), SEO property binding | Not Location-filtered |
| Search Console performance cache (site totals, queries, pages) | **Business-wide** | SEO | Not Location-filtered (a website reporting fact, not a Location operational record — Blueprint §5 rule) |
| SEO keyword definition | **Business-wide** | SEO | Definition visible Business-wide |
| SEO keyword **Location attribution** (optional) | **Location-bound** attribution on a Business-wide definition | SEO | A keyword with a Location is visible only to actors who can access that Location; Business-wide keywords (no Location) visible to all with `view_seo` |
| Google Business Profile connection | **Business-wide** (one Google account per Business, existing) | GBP | Existing behavior unchanged |
| Google Business Profile binding / health / mirror | **Location-bound** | GBP; SEO reads via the read model | **SEO filters by `LocationAccessGuard`** |
| Citation directory catalog | Platform reference data (global) | Platform | n/a |
| Citation record | **Location-bound** (each Location has its own NAP) | SEO | Filtered |
| Review link | **Location-bound** | SEO | Filtered |
| Review request record | **Location-bound** | SEO | Filtered |
| Website audit run/findings | **Business-wide** (one website per Business) | SEO (reads immutable Website revisions) | Not Location-filtered until page↔Location exists (G-1) |

**Aggregation rule (load-bearing):** any Business-wide surface that summarizes
Location-bound records (Overview citation progress, review-request counts, GBP
status) **filters to the actor's accessible Location ids first and aggregates
second — never the reverse.** A Selected-scope staff member must not be able to
infer the existence or state of an inaccessible Location from a count. Tested
(§16, T-SEO-LOC-*).

## 7. Google connection authority and provider model

### 7.1 The problem, from evidence

Search Console needs `webmasters.readonly`. The GBP connection holds exactly one
scope (`business.manage`), requests it with `include_granted_scopes=false`, and
the GBP contract records that "no staged-consent flow is designed" (§9.2). A GBP
refresh token **cannot** read Search Console. The instruction is *no duplicate
Google OAuth authority*: SEO must not grow a second OAuth stack (state signer,
encrypted token store, callback, revoke lifecycle, ledger) beside GBP's.

### 7.2 Decision — one authority, product-discriminated connection rows

`business_google_connections` remains **the** Google connection authority.
Sub-slice B adds a `product` discriminator and makes the uniqueness
`(business_id, product)`:

| `GoogleConnectionProduct` | Scope requested | Consumer | Entitlement checked at the controller/job |
|---|---|---|---|
| `business_profile` (default for every existing row) | `…/auth/business.manage` | GBP (unchanged) | `google_business_profile_module` |
| `search_console` | `…/auth/webmasters.readonly` | SEO | `seo_module` |

Every property below follows from this and is why it beats the alternatives:

- **One authority.** One table, one manager family, one signer, one encryption
  path, one client-credential block, one fixed callback route, one operation
  ledger. No parallel stack.
- **Least privilege.** A customer who wants Search Console never grants the
  write-capable GBP scope, and vice versa.
- **Independent lifecycle.** Revoking or disconnecting one product does not touch
  the other; each row keeps its own state machine and token invariant.
- **Independent Google account.** The account that manages the GBP listing need
  not be the account that owns the Search Console property.
- **No cross-Workspace reuse.** Exactly GBP §2.4: an Agency with 400 Businesses
  has 400 independent connections per product and no shared token.
- **Structural read-only for Search Console.** The read-only scope is enforced
  by OAuth itself, and additionally by the provider interface (§7.5).

### 7.3 What Sub-slice B changes in merged GBP code (and the proof it is behavior-preserving)

- Migration: `product varchar(24) not null default 'business_profile'`; drop
  `bgc_business_unique`; add `unique(business_id, product)`; keep
  `bgc_id_business_unique`. Existing rows are `business_profile` by default.
- `GoogleConnectionProduct` enum; scope constant per product (the GBP constant
  is unchanged, `HttpGoogleBusinessProfileReadClient::SCOPE`).
- Every access to `business_google_connections` (model statics, repository,
  `GoogleBusinessProfileConnectionManager`, `…BindingManager`, `…Enumerator`,
  `…MirrorService`, `…CandidateTokenSigner`, `GoogleOAuthStateSigner`, the three
  jobs, the controller) becomes **product-scoped**. **The danger being closed:**
  after Search Console rows exist, an unscoped `where('business_id',…)->first()`
  in GBP code could return the Search Console row.
- The signed OAuth state carries the product; the one fixed callback branches on
  it after full state revalidation. `include_granted_scopes` stays `false`.
- `GoogleOperationType` is extended **additively**; the ledger is shared.
- `business_google_locations` (GBP bindings) may only ever reference a
  `business_profile` connection — enforced in the manager and by test, because
  the composite FK `(business_google_connection_id, business_id)` does not
  encode product.
- **Proof obligations (hard merge gate):** the entire existing GBP test suite
  (`tests/Feature/GoogleBusinessProfile/*`, `tests/Feature/Security/
  GoogleBusinessProfileSecurityTest.php`) passes with **no assertion edits**;
  plus a source-boundary test asserting no production code touches
  `business_google_connections` without a product predicate (§16, T-SEO-B-*).

### 7.4 Isolation — Search Console is separable from the rest of the slice

Sub-slice B is the only piece that modifies merged GBP code. If owner decision
OD-2 (approve this amendment) is refused or delayed, **only Sub-slice C (Search
Console) and the Search Console halves of D/H are blocked**. A, D (on-page
coverage), E, F, G and H's non-Search-Console work are independent of it. The
degradation is explicit: Keywords show on-page coverage but no position; the
Overview has no performance tiles.

### 7.5 Provider model

- `SearchConsoleReadClient` interface — **structurally read-only**: methods
  `listSites()`, `querySearchAnalytics(...)` only; a real HTTP client and a
  deterministic Fake; no mutation method may exist (mirrors GBP §14.2; a source
  test proves none is reachable). Never `webmasters` (read/write) scope.
- Direct HTTP client (no Socialite, no SDK), request timeouts and https-only
  URLs per GBP §14.4, provider strings treated as untrusted (escaped, length- and
  scheme-validated; an `https`-only allowlist for any URL Google returns).
- **SEO never calls Google for GBP data.** GBP data reaches SEO only through the
  read model in §9.2.
- **No Google Ads, Places API, SERP vendor, citation vendor or review vendor.**
  None has authority in this repository; each is a future contract with its own
  owner approval (§18).

### 7.6 Rejected alternatives (recorded so they are not re-proposed)

| Alternative | Why rejected |
|---|---|
| A. Add `webmasters.readonly` to the single GBP connection via incremental consent | Forces the write-capable GBP scope on customers who only want Search Console; forces one Google account for both; contradicts GBP §9.2's single-scope invariant and changes GBP's consent copy; a connection with only the SC scope would still read as an *Active GBP connection* |
| B. A separate `search_console_connections` table with its own signer/manager/callback | Precisely the duplicate OAuth authority this slice must not create |
| C. A platform service account added as a user on each customer's property | Requires manual customer action per site, a platform-held Google credential (none exists; GBP §4.4 records none), and does not scale across tenants |
| D. Reuse the Socialite Google sign-in | Sign-in creates platform Users and requests no scopes (GBP §4.5); forbidden to touch |

## 8. Domain model

All new tables: `uid` uuid unique; FKs to `business_locations` use
`restrictOnDelete` (Location lifecycle is archive, never delete — the
`contacts.location_id`/`chat_boxes.location_id` convention); customer-authored
workflow tables use `restrictOnDelete` to `businesses`, provider/derived cache
tables use `cascadeOnDelete`. Composite FKs `(business_location_id, business_id)
→ business_locations(id, business_id)` reuse the existing `bl_id_business_unique`
index so a row can never pair a Location with a different Business. Timestamps
are UTC. No soft deletes anywhere (the codebase uses lifecycle columns).

### 8.1 `business_google_connections` (modified, Sub-slice B)

As §7.3. No other column changes.

### 8.2 `seo_search_console_properties` (Sub-slice C) — one property per Business

| Column | Type | Notes |
|---|---|---|
| `id`, `uid` | | |
| `business_id` | unsignedBigInteger | `unique` — **one property per Business** (Blueprint §5); `cascadeOnDelete` |
| `business_google_connection_id` | unsignedBigInteger | composite FK `(id, business_id)` → `business_google_connections`; must reference a `search_console` product row (manager-enforced + tested) |
| `site_url` | varchar(191) | verbatim from `sites.list` (`https://…/` or `sc-domain:…`) |
| `property_type` | varchar(12) | `url_prefix` \| `domain` |
| `permission_level` | varchar(32) | stored verbatim; levels that cannot read data are refused at bind (list to be verified, §3.6) |
| `bound_by_user_id` | FK users, nullOnDelete | |
| `last_synced_at`, `last_synced_data_through` | timestamp / date nullable | as-of facts (§11.3) |
| `last_sync_status` | varchar(16) | `ok`\|`failed`\|`unknown` |
| `sync_failure_classification` | varchar(32) nullable | closed vocabulary (§11.2) |
| `timestamps` | | |

**Binding rules (fail-closed):**
1. Enumeration is **request-scoped and never persisted** (GBP §8.3); nothing is
   auto-selected (GBP §8.5).
2. **Domain-match guard** (tenant/client-leak protection — an Agency
   consultant's Google account sees many clients' properties): the property's
   host, canonicalized by the existing `UrlNormalizer::canonicalDomain()`
   semantics (lower-case, no `www.`), must equal — or be a parent domain of —
   the canonical domain of `businesses.website_url`. A Business with **no**
   website URL cannot bind. Mismatch is refused with a customer-facing reason.
3. One binding per Business; rebinding replaces the row inside one transaction
   and purges the previous property's cached data.
4. No network call inside a database transaction.

### 8.3 Search Console cache (Sub-slice C)

`seo_search_console_daily_metrics` — site totals per day:
`(business_id, seo_search_console_property_id, metric_date date, clicks
unsignedInteger, impressions unsignedInteger, ctr decimal(7,6), position
decimal(6,2))`, `unique(property_id, metric_date)`, indexed
`(business_id, metric_date)`. Web search type only. Retention: our own cap,
config `seo.search_console.daily_retention_days` (default **400**, hard
ceiling **480**; fails closed toward the lower default). This is **our design
cap, not a Google-stated limit**.

`seo_search_console_snapshots` — top queries/pages per weekly snapshot:
`(business_id, property_id, snapshot_week_start date, dimension varchar(8)
[query|page], key_hash char(64), key_display varchar(1024), clicks, impressions,
ctr, position)`, `unique(property_id, snapshot_week_start, dimension, key_hash)`.
Per sync: one query for date totals, one for top queries (`rowLimit` ≤ 500), one
for top pages (`rowLimit` ≤ 200), 28-day window, `dataState=final`, trimmed to
`metadata.first_incomplete_date`. Retention: **12 weekly snapshots** (config,
ceiling 26) so "position change" and "ranking gained" are computable without a
per-query daily series. Anonymized queries are not returned by Google; the UI
never implies the list is complete.

### 8.4 `seo_keywords` (Sub-slice D)

| Column | Type | Notes |
|---|---|---|
| `id`, `uid` | | |
| `business_id` | FK, `restrictOnDelete` | |
| `business_location_id` | nullable | composite FK with `business_id`; **null = Business-wide**; set = local-intent attribution |
| `phrase` | varchar(120) | as typed, trimmed |
| `phrase_normalized` | varchar(120) | NFKC → lower-case → collapse whitespace → trim; **no stemming**; the single normalization used for on-page and Search Console matching |
| `location_key` | generated stored `COALESCE(business_location_id,0)` | backs the unique index below (the codebase already uses a generated-column unique backstop for the Agency relationship) |
| `lifecycle_state` / `archived_at` | enum / nullable | **not mass-assignable**; `archive()`/`reactivate()` are the only writers (mirrors `BusinessLocation`) |
| `source` | varchar(16) | `manual` \| `search_console_suggestion` (accepted by the user) |
| `created_by_user_id`, `updated_by_user_id` | FK users, nullOnDelete | |

`unique(business_id, location_key, phrase_normalized)`. **Technical safety
ceiling** (not a commercial plan limit — none is authorized): 50 active keywords
per Business, all tiers, config-backed, fails closed. No stored position, rank or
"score": position/impressions are a **read-time join** of the keyword's
`phrase_normalized` (exact) to the latest snapshot's query rows; no match = "no
data yet", never zero. Coverage is computed at read time from the immutable
published snapshot (§9.1).

### 8.5 Citations (Sub-slice E) — internal tracking, no vendor

`seo_citation_directories` — platform-owned reference data (global; changed only
by migration/seeder or a future platform-admin surface, never by customers):
`key` (stable slug, unique), `name`, `claim_url` (https), `country_scope`
(nullable ISO-2 = global), `is_active`, `sort_order`. **Seeding rule:** ≤ 10
universally applicable directories; every `claim_url` is verified by the
implementer against the directory's own site at implementation time; no
affiliate or tracking parameters. Inventing entries or URLs is forbidden (OD-3).

`seo_citations` — one per (Location, directory):
`business_id`, `business_location_id` (NOT NULL, composite FK),
`seo_citation_directory_id`, `status` ∈ `not_started|in_progress|listed|
needs_correction|not_applicable`, `listing_url` (https, nullable, ≤ 2048),
`listed_name`, `listed_phone`, `listed_address` (nullable — **must be null unless
`GoogleBusinessProfileReadMask::addressPermittedForLocation()` is true; the write
is rejected otherwise**), `last_verified_at` (**user-asserted** date),
`verification_source` (`user_asserted` — a single-value column, the seam that
keeps a future vendor's observations out of this table), `notes` (≤ 500),
`updated_by_user_id`. `unique(business_location_id, seo_citation_directory_id)`.

**NAP consistency is a deterministic comparator, computed at read time, never
persisted, never auto-applied.** Canonical NAP: name = `businesses.name`;
phone = `businesses.phone` (G-4); address = the Location's address **only when
the private-address predicate permits** (GBP §23: when it does not, the street
address is never stored, compared, logged or transmitted). Per-field result:
`consistent | mismatch | not_comparable | unchecked`. Normalization must agree
with GBP contract §22.3 for the same inputs; a shared conformance fixture table
asserts agreement (SEO does **not** import GBP's comparator internals — GBP §37.2
"no circular dependency"; it reuses only the one pure, public address predicate,
a documented one-directional SEO→GBP-library dependency).

**GBP appears in Citations as a synthetic, read-only row** derived from the
read model (§9.2) — never stored as a citation.

**The platform never fetches `listing_url`** (GBP §31: no server-side fetching
of user-supplied URLs). It is rendered as an `https`-validated link with
`rel="noopener noreferrer nofollow"` only. Directory submission, claiming and
scraping are manual, off-platform, and out of scope.

### 8.6 Reviews (Sub-slice F) — workflow and tracking, no review content

**Honest scope:** GBP contract §13/§36.2 forbids a durable review archive,
review PII storage and aggregation of Google content, and reviews exist only on
API v4.9 for verified locations. "Reviews aggregation" therefore **cannot** mean
aggregating Google review content in this slice. The Reviews module aggregates
only **platform-owned review-request activity**, and shows Google-sourced
ratings/counts **not at all** until a separately contracted GBP Slice C. There is
no dead "Ratings" tab (GBP §31).

`seo_location_review_links` — one manual link per Location:
`business_id`, `business_location_id` (`unique`, composite FK), `review_url`
(https only, ≤ 2048, validated with the `WebsiteUrlRules`-style allowlist),
`set_by_user_id`. Effective link precedence at read time: manual link, else the
**unexpired** GBP mirror `new_review_uri` via the read model (never copied into
this table). **No redirect/short-link/click-tracking endpoint** (open-redirect
rule; GBP §17).

`seo_review_requests` — a Location-bound ledger of "we asked this person":
`business_id`, `business_location_id` (NOT NULL), `contact_id` (nullable,
`nullOnDelete`; the Contact's own `location_id` must equal the request's Location,
else rejected), `crm_opportunity_id` (nullable — the objective trigger, e.g. a
won opportunity), `channel` ∈ `sms|email|in_person|other` (recorded fact; **SEO
sends nothing**), `status` ∈ `requested|reviewed|declined` (`reviewed` is
**self-reported**, labelled as such), `requested_at`, `resolved_at`,
`created_by_user_id`. No message body, no Google reviewer identity, no rating.
**Cooldown:** at most one non-declined request per (contact, Location) inside a
window, default **90 days** (config; product default flagged OD-3).

**Boundary versus Messaging/Automations (locked):**

| Concern | Owner |
|---|---|
| Who is eligible to be asked; the review link; recording that a request was made and its outcome | **SEO Reviews** |
| Actually sending an SMS/email (consent, STOP/DND, quiet hours, wallet/payer authority, metering, retries, dead-letter) | **Messaging / Conversations** (interactive) or **Automations** (unattended `send_sms`) — unchanged |
| A recurring "ask after a job is won" flow | An ordinary customer-authored **Automation** (`opportunity_won` → `wait` → `send_sms`) whose body contains the link the customer pastes. SEO never creates, edits, publishes or enrolls workflows |

SEO **adds no Automation trigger, node, merge field or executor.** The only
sanctioned hand-offs are read-only deep links from a review-request row to the
existing conversation/automation screens. Because no clean "request sent" signal
exists in Messaging (`send_sms` stores only a body; no workflow-tagged
send event), request records are created by an explicit user action, not
inferred from message traffic.

**Google policy invariants (verified, §3.6) — enforced as product rules and
tested:** (1) **no review gating** — eligibility criteria are objective
(completed job/won opportunity), never sentiment, rating or "happy customer"
filters, and no UI or API accepts one; (2) **no incentive** fields, copy or
templates; (3) **no staff quotas or goal-setting** — Overview shows a plain
count of requests, never a target, leaderboard or per-staff ranking.

### 8.7 Website SEO / technical audit (Sub-slice G)

Reads **only** the immutable `website_revisions.snapshot` of the published
revision. It never crawls, never fetches a URL, and never audits an external
site (no crawler exists and server-side fetching is forbidden). For a Business
whose real site is external, Search Console is the only technical signal.

`seo_audit_runs` (immutable, `UPDATED_AT = null`): `business_id`, `website_id`,
`website_revision_id`, `rule_set_version`, `status` (`completed|failed`),
`page_count`, `critical_count`, `warning_count`, `info_count`, `created_at`;
`unique(website_revision_id, rule_set_version)` (idempotent per revision).
`seo_audit_findings`: `seo_audit_run_id` (`cascadeOnDelete`), `page_uid`
(snapshot page uid, null = site-level), `rule_key`, `severity`
(`info|warning|critical`), `facts` (scalar JSON, ≤ 8 keys). Retention: latest 5
runs per Website (config); older runs pruned.

**Closed rule registry v1** (`SeoAuditRuleRegistry`; text is registry template +
validated facts, never free text — the RFC-002 discipline). Only customer-
**actionable** fields are findable: `seo_title_blank` (info — falls back to the
page title), `seo_title_over_recommended` (warning, > 60 chars),
`meta_description_blank` (warning), `meta_description_short` (info, < 70 chars),
`duplicate_seo_title` (warning), `duplicate_meta_description` (warning),
`page_marked_noindex` (info), `asset_missing_alt` (warning). The 60/70 thresholds
are conventional guidance, **not** Google-specified requirements, are labelled
"recommended", and live as config constants. Platform limitations (no canonical
tag, no JSON-LD, platform-path `noindex`, sitemap scope — G-2/G-3) are **never**
findings; the platform-path state is a separate **indexability status**.

**Trigger:** a queued listener on `WebsitePublished` (after-commit already;
ids-only; the listener must not fail a publish) plus a throttled manual re-run.
**Location-page findings are deferred (G-1)** until Website provides a
page↔Location association; the audit is specified so that adding it later needs
no rewrite (findings already carry `page_uid`).

**No fix path writes anywhere.** A finding deep-links to the existing Website
page editor; the customer edits through `WebsiteDraftPageService` under the
Website's own authorization, and publishes through `WebsitePublisher` — both
unchanged (§12).

## 9. Read models

### 9.1 `SeoPublishedContentReader` (Sub-slice A)

The single reader of the published snapshot for SEO: page SEO fields, alt-text
coverage and keyword-coverage text extraction over an **allowlist** of
`WebsiteSectionType` text fields (never URLs or asset uids). Used by the Core
counts (§5.2), keyword coverage (§8.4) and the audit (§8.7) so the logic exists
once. Cache key, if any, is per published revision **and** includes Workspace and
Business identity (GBP §1.3 G-D: no entitlement caching; only immutable-revision-
keyed derived data).

### 9.2 `GoogleBusinessProfileStatusReader` (Sub-slice A) — the GBP §37.2 seam

Additive new class in the GBP namespace (GBP owns Google state; no existing GBP
file is modified). Read-only, Business-scoped, **no provider call, no write**:
per accessible Location returns `{location_id, bound, connection_state,
health (GoogleLocationHealth), mirror_is_fresh, new_review_uri (only while the
mirror is unexpired), nap_mismatch_count}`. It (a) requires the GBP module
entitlement and `view_google_business_profile`, (b) filters by
`LocationAccessGuard` before reading, (c) treats an expired mirror as absent
(GBP §13), (d) computes mismatch counts through the existing comparator at read
time, and (e) never persists anything it reads. SEO consumes GBP **only** through
this class.

### 9.3 `SeoOverviewReader` (Sub-slice A, extended by later sub-slices)

Composes sections by entitlement × permission × Location access, using bulk
queries. Sections are registered with the sub-slice that builds them; **a
section that is not built does not render** (no placeholder tabs).

## 10. Authority / security contract

### 10.1 Mandatory chain (every SEO action, mirrors GBP §15.1)

Workspace by uid (404 if missing/inactive) → Business inside it (404) →
`WorkspaceManager::userCanAccessBusiness()` (404) → Business `Active` (404) →
`resolveEntitledBusinessTenancy()` with the section's feature (404) → the
capability gate → any child resource resolved **through** the Business, never by
uid alone. `abort(404)`, never 403, for every mismatch. No
`LegacyBusinessResolver`, no primary-Business inference, `Auth::id()` never
tenancy, no implicit route-model binding. Disconnect/unbind of Google
credentials **skip the entitlement step** (as GBP §39.4) so a plan downgrade can
never trap stored credentials.

### 10.2 Capabilities (new keys, `config/customer-permissions.php`)

| Key | Default | Grants |
|---|---|---|
| `view_seo` | **true** | Read the Overview and every SEO section the plan allows |
| `manage_seo` | **true** (same as `website`, `automations`) | Keyword CRUD/archive; citation status/NAP-listing edits; review link and review-request ledger writes; re-run audit |
| `manage_search_console` | **false** (credential-class, as `manage_google_business_profile`) | Search Console connect, property bind/unbind, disconnect, manual refresh |

A capability answers "may this actor use this feature", tenancy answers "may
this actor reach this Business/Location" — neither substitutes for the other.
**No reuse** of `view_keywords`, `view_reports`, `website`, or any GBP key.
Config keys grant nothing to existing customers, so Sub-slice A ships a
**data-backfill migration** adding `view_seo` and `manage_seo` (never
`manage_search_console`) to the `customer_permissions` `AppConfig` row and each
existing `customers.permissions` list lacking them — the GBP `2026_09_09_120006`
idiom. Applying `website` (existing) is additionally required for any future
draft-write hand-off (§12).

### 10.3 Entitlement

Two independent decisions, never merged: `seo_basic_visibility` gates the
Overview, Core content counts and keyword coverage; `seo_module` gates Search
Console, Citations, Reviews, the audit, position alignment. The GBP section stays
gated by `google_business_profile_module`. Jobs re-check entitlement and Location
access at execution time (GBP §24.7). No new `PlatformFeature` case is
introduced — both cases already exist, are already packaged, and already have
usage-classification rows (`platform_feature_usage_classifications` backfill
counts `PlatformFeature::cases()`); **no new packaging or classification
migration is needed**. Neither SEO feature is metered: Search Console reads are
free, and any review-request send is metered by Messaging, not SEO.

### 10.4 Location ACL (G-6)

`LocationAccessGuard::userCanAccessLocation()` is the only Location authority.
It is applied to: keyword Location attribution, citations, review links and
requests, the GBP read model, and every Overview rollup (§6 aggregation rule).
Location writes additionally require the Location `isActive()` (§10.5).
`ResolvesBusinessTenancy` gains **no** Location-scoped sibling method in this
slice (that is Contract 08B's deferred, separately authorized work); SEO adds its
own small `SeoLocationScope` helper (Sub-slice A) that resolves the actor's
accessible Location ids once per request. The existing GBP and Website
controllers are **not** retrofitted here; that pre-existing gap is recorded (G-6).

### 10.5 Archived / Locked Locations

A Location-bound **write** requires `BusinessLocation::isActive()`. Archived
Locations keep history visible read-only. When the Blueprint's over-limit
"Locked" state becomes representable (G-5) it is consumed through the same
predicate, so no SEO change is needed.

### 10.6 View As and lifecycle

Read routes are reachable inside a View As session. Added to
`ViewAsProhibitedActions::EXACT` (credential entry/provisioning class, as GBP):
`…seo.search-console.connect|disconnect|bind|unbind|refresh` (the OAuth callback
is the existing `customer.gbp.oauth.callback`, already prohibited). SEO route
names must never begin with `customer.keywords.`. Ordinary SEO edits (keywords,
citations, review links/requests, audit re-run) are the client's own data and are
not prohibited, exactly as Website edits are; they are audited under the real
acting person's identity (Blueprint §32). A bare entry route `customer.seo.index`
is registered and aliased in `ViewAsRouteClassification` like `customer.gbp.index`.
**Nothing is added to the Locked-account allowlist** — every SEO route is
redirected while the account is Locked.

### 10.7 Google-content and data-handling rules

- GBP §13 applies to anything from GBP; SEO stores none of it.
- Search Console data is stored under Google's Limited Use policy (§3.6): only
  for the prominent user-facing SEO features, **never transferred to a third
  party, never sent to any LLM/AI provider, never used for ads**. The COO
  insight facts reader and any AI gateway must not receive Search Console
  queries, pages or metrics in this slice (OD-5 — legal confirmation).
- Provider strings are untrusted input: escaped output only, no `{!! !!}`, URL
  scheme allowlists, length caps. Provider identifiers are treated
  conservatively (GBP §13.7).

## 11. Refresh, staleness, errors, rate limits, budgets

### 11.1 Scheduling (shared-hosting reality: `queue:work --stop-when-empty --max-time=180` each minute)

Jobs live in `app/Jobs/Seo/`, each short and chunked; no long "sync everything":
`SweepSearchConsoleSyncs` (**daily**, staggered deterministically per property
across an hour, run offset from the GBP sweep), `SyncSearchConsoleProperty`
(one property, its ≤ 3 calls), `PurgeExpiredSearchConsoleData` (daily),
`RunSeoAuditForRevision` (queued on `WebsitePublished`), `PruneSeoAuditRuns`
(daily). Manual refresh is primary (route throttle `10,1`, plus a per-Business
minimum interval, default **15 minutes**); background is at most daily. No
higher-frequency polling and no webhooks/Pub-Sub.

### 11.2 Provider errors and rate limits

Failure vocabulary (closed, additive to GBP's where a member exists):
`auth_revoked` (→ connection `revoked`), `permission_lost` (property permission
gone → `last_sync_status=failed`, prompt to rebind), `quota`, `transient`,
`not_found`, `invalid_request`, `unknown`. No blind retry; timeout ambiguity is
recorded, never assumed success (GBP §24.6); no network in a DB transaction; no
raw provider payload or error body stored. A per-Business hourly call ceiling
(config default **30**) and a **project-level circuit breaker** (threshold 20,
cooldown 30 min — GBP's values) protect the shared project quota (verified:
40,000 QPM/project, 1,200 QPM/site; normal load is ~3 calls per Business per day
so headroom is large, but the guard is mandatory and tested). Enumeration/bind
calls are throttled route-level.

### 11.3 Stale-data behavior

Every Search Console figure is shown **with its as-of date**
(`last_synced_data_through`), never bare. Fresh = synced within 2 days; older
than 3 days shows a visible "last updated N days ago" notice. On `revoked` or
`permission_lost` the cached data stays readable, labelled "connection lost —
data as of …", and is purged after `seo.search_console.stale_purge_days` (default
**90**) without a successful sync. **Disconnect purges that Business's cached
Search Console rows immediately** (privacy-first; reconnecting re-syncs). A
figure is never rendered as zero when data is absent — it is "no data".

### 11.4 Query budgets (asserted by tests, house pattern `capturedSql()` / `DB::listen`)

Invariant, contract-locked: **query count is independent of the number of
keywords, Locations, citations, requests and findings** (an N+1 fails the test;
each surface is asserted at 1 vs 25 rows with identical counts). Initial
ceilings, to be calibrated by each sub-slice and never raised without amending
this contract: Overview ≤ 24 (tenancy chain included), each section index ≤ 14.
The Overview issues **zero** provider calls in any state.

## 12. No unsafe automatic mutation (locked, structural)

1. SEO code has **no write path** to `websites`, `website_pages`,
   `website_revisions`, `website_assets`, `businesses`, `business_locations`, or
   to Google (GBP or Search Console). Enforced by a source-boundary test (the
   technique of `WebsiteBoundaryTest` / `BusinessLocationBoundaryTest`) scanning
   SEO code for those writes and for any HTTP client method other than the two
   read methods.
2. Sync never overwrites a platform column (GBP §21.4 ownership of truth
   applies unchanged); citations, NAP results and audit findings are reports, not
   actions.
3. **AI and recommendations may only suggest.** Slice 18 ships **no LLM call and
   no AI-generated content**; audit findings are deterministic. Any later slice
   that proposes text changes must (a) require an explicit per-item human action,
   (b) write only a **draft** through `WebsiteDraftPageService` (the sole seam)
   under the Website's own tenancy/entitlement/`website` permission, (c) leave
   publishing to `WebsitePublisher` as a separate human action, and (d) for any
   Google write, satisfy GBP §36.1 and Google's policy that automated actions
   need the user's "prior specific and express consent." None of (a)–(d) is built
   here.
4. No automatic GBP or website publication of any SEO suggestion, ever.

## 13. Retention and privacy summary

| Data | Retention | Notes |
|---|---|---|
| Search Console refresh token | until disconnect/revoke; encrypted; destroyed on disconnect | GBP §13.5 idiom |
| Daily metrics | ≤ 400 days (config, ceiling 480) | our cap |
| Weekly snapshots | 12 (config, ceiling 26) | |
| Cached data after `revoked`/`permission_lost` | purged after 90 days without success | |
| Cached data on disconnect | purged immediately | |
| GBP-derived facts | **never stored by SEO** | GBP §13 |
| Citations, keywords, review links/requests | until archived/deleted by the customer; contact reference nulled if the Contact is deleted | customer-authored |
| Audit runs | latest 5 per Website | derived, reproducible |

## 14. Entitlement, packaging and navigation

### 14.1 Sequencing (RFC-004 ordering rule)

Both `PlatformFeature` cases stay **`Planned`** from Sub-slice A until
Sub-slice H. Sub-slices A–G may contain controllers/routes/views because
`resolveEntitledBusinessTenancy()` denies a `Planned` feature (404) — they are
unreachable to customers until H flips the registry. H flips **both** after A–G
that are built are merged and verified. **Exactly two** existing tests pin these
features `Planned` and are **expected to be edited in H**, deliberately, in the
same commit as the flip: `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php`
(`test_every_other_feature_is_planned_not_available` lists `SeoBasicVisibility` and
`SeoModule` among nine Planned features and asserts `assertCount(9, …)` — both the
list and the count change) and `tests/Feature/GoogleBusinessProfile/
GoogleBusinessProfileEntitlementTest.php` (~line 105 asserts `SeoModule` "must
remain Planned"). `WorkspaceEntitlementSchemaTest`, `EntitlementEnumsTest` and
`WorkspacePlanFeatureRepositoryTest` pin only enum existence and plan packaging
(verified) and need **no** edit, because H changes availability, not packaging.

### 14.2 Navigation

`CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES` gains `seo_basic_visibility` and
`seo_module`. One **SEO** entry (Blueprint §7 lists "Website · SEO · Forms …")
appears when `seo_basic_visibility` is allowed and `view_seo` is held; its
sections show only when built **and** entitled/permitted: Overview, Keywords,
Google Business Profile (existing pages, URLs unchanged, linked — not rebuilt),
Website SEO, Citations, Reviews. The existing GBP nav entry ("Get found") is
folded into SEO in H; GBP routes keep their names and URLs. Final label copy is
owner-editable (OD-6; default "SEO").

### 14.3 Copy that prevents the two-Keywords confusion

The SEO surface says "Search keywords" / "Keywords people search for" and never
"text in"; the legacy surface keeps "Words people can text in to reach you". A
test asserts the two never share a route-name prefix, permission key, or table.

## 15. Exact implementation allowlist — eight dependency-ordered sub-slices

Mapping to the provisional A–F in the request: A = SEO overview + data model;
B/C = Search Console (split so the GBP-touching part is isolated); D = Keywords;
E/F = Citations/Reviews (split — different data, tests and risk); G = technical +
Website SEO; H = entitlement/nav/integration.

### Sub-slice A — Foundation, Core Overview, and the two reader seams

- **Files/domains:** `config/seo.php` (ceilings, fail-closed idiom);
  `config/customer-permissions.php` (+ `view_seo`, `manage_seo`,
  `manage_search_console`); backfill migration for `view_seo`/`manage_seo`;
  `app/Library/Seo/{SeoLocationScope,SeoReadinessRuleRegistry,SeoPublishedContentReader,SeoOverviewReader}`;
  `app/Library/GoogleBusinessProfile/GoogleBusinessProfileStatusReader.php`
  (**additive**); `SeoController@overview` + route group + view (Overview only,
  Core-level sections); bare entry route.
- **Prerequisites:** none beyond Contracts 1–14.
- **Schema:** none except the permission backfill (data operation).
- **Tenancy/security:** §10.1–10.4; features stay `Planned`, so unreachable.
- **Tests:** chain 404 matrix; capability × tenancy × entitlement independence;
  backfill idempotence (view/manage added, `manage_search_console` never);
  Overview with zero provider calls; Location-filtered rollups (Selected-scope
  actor cannot infer an inaccessible Location); GBP reader (expired mirror = absent;
  no provider call; ACL-filtered; no persistence); query budget 1 vs 25 Locations.
- **Risk:** Low. **Model:** Sonnet 5 sufficient.

### Sub-slice B — Google connection product discriminator (GBP behavior-preserving refactor)

- **Files/domains:** the migration in §7.3; `GoogleConnectionProduct`; every GBP
  file listed in §7.3 (product-scoped lookups only, **no behavior change**);
  additive `GoogleOperationType` members.
- **Prerequisites:** none technically; **gated on OD-2 and OD-4**. Must merge
  before C.
- **Schema:** §8.1.
- **Tests (hard gate):** entire existing GBP suite passes with zero assertion
  edits; source-boundary test — no `business_google_connections` access without a
  product predicate; a `business_google_locations` row can never reference a
  non-`business_profile` connection; two connection rows (one per product) coexist
  and revoke independently; the OAuth state cannot be replayed across products.
- **Risk:** **Medium** — it edits merged, security-critical code. **Model:** Opus-class
  review recommended.

### Sub-slice C — Search Console (connection, binding, sync, cache, reader)

- **Files/domains:** migrations §8.2/§8.3; models; `SearchConsoleReadClient` +
  Http + Fake; `SearchConsoleConnectionManager` (uses the B authority),
  `SearchConsolePropertyBinder` (domain-match guard), `SearchConsoleSyncService`,
  the four jobs (§11.1), `config/seo.php` additions, controller actions for
  connect/callback-branch/enumerate/bind/unbind/disconnect/refresh + view;
  `ViewAsProhibitedActions` entries; Overview performance tiles.
- **Prerequisites:** A, B (hard).
- **Tests:** OAuth (state, actor binding, product, replay) reusing GBP's matrix
  shape; enumeration never persisted; **domain-match guard** adversarial set
  (foreign property, sibling client's property in the same Google account, no
  website URL, `www` variants, subdomain/parent); provider Fake for every failure
  class; budget/breaker; stale-data notices; disconnect purge; disconnect skips
  entitlement; no LLM/AI reachability; read-only client (no mutation method).
- **Risk:** Medium. **Model:** Sonnet 5 sufficient with B merged.

### Sub-slice D — Keywords

- **Files/domains:** migration §8.4; `SeoKeyword`; `SeoKeywordManager`
  (create/update/archive/reactivate, normalization, ceiling, Location ACL);
  controller + views; Core coverage (§5.2.3) via `SeoPublishedContentReader`.
- **Prerequisites:** A (hard). Search Console alignment is added in H, so D does
  **not** depend on B/C.
- **Tests:** normalization table; unique/generated-column backstop; ceiling;
  Location attribution ACL; legacy-Keywords non-collision test; coverage
  correctness; `lifecycle_state`/`archived_at` not mass-assignable.
- **Risk:** Low.

### Sub-slice E — Citations (internal workflow; no vendor)

- **Files/domains:** migrations §8.5; directory seeder (verified URLs);
  `SeoCitationManager`, `SeoNapComparator`; controller + views; GBP synthetic row
  via the reader.
- **Prerequisites:** A (hard).
- **Tests:** private-address invariant (write rejected when the predicate denies);
  comparator vs GBP §22.3 conformance fixtures; **no outbound fetch of
  `listing_url`** (HTTP-fake proves zero requests); link rendering (`https` only,
  `rel`); per-Location ACL; unique constraint; no auto-status change on mismatch.
- **Risk:** Low.

### Sub-slice F — Reviews (workflow/tracking only)

- **Files/domains:** migrations §8.6; `SeoReviewLinkManager`,
  `SeoReviewRequestManager` (cooldown, Contact/Location equality); controller +
  views; deep links only to Conversations/Automations.
- **Prerequisites:** A (hard).
- **Tests:** Contact PII exposure limited to what the actor could already see in
  Contacts; cooldown; Contact/Location mismatch rejected; **no review-gating /
  incentive / quota surface exists** (structural tests over routes, requests and
  views); SEO sends nothing (Messaging/Automation classes unreachable from SEO
  code); no Google review content stored (schema test: no rating/reviewer/text
  columns); no dead Ratings tab.
- **Risk:** Low–Medium (Contact linkage).

### Sub-slice G — Technical / Website SEO audit

- **Files/domains:** migrations §8.7; `SeoAuditRuleRegistry`, `SeoAuditRunner`;
  `RunSeoAuditForRevision` job + `WebsitePublished` listener registration
  (`AppServiceProvider`/event mapping, additive); controller + views; indexability
  status.
- **Prerequisites:** A (hard).
- **Tests:** each rule on fixture snapshots; idempotent per revision; listener
  failure never fails a publish; **source-boundary proof of no write to Website
  tables**; no URL fetched; platform limitations never appear as findings;
  registry-only text (no free-text echo); prune keeps 5.
- **Risk:** Low.

### Sub-slice H — Entitlement flip, navigation, integration, documentation

- **Files/domains:** `PlatformFeatureRegistry` flips `SeoBasicVisibility` and
  `SeoModule` to `Available`; `CustomerMenuBuilder` (+ gated features, SEO entry,
  fold the GBP entry); `PlatformFeatureCopy` entries if the plan presenter needs
  them (verify at implementation); the Search Console ↔ Keywords position join
  (only if C is merged); Overview final composition; `ViewAsRouteClassification`
  aliases; the deliberate edits to the two Planned-pinning tests (§14.1); **documentation
  corrections** — Roadmap Slice 18 row, Traceability row 12, Acceptance Matrix
  "Manage SEO" status, Contract Index.
- **Prerequisites:** A plus every built sub-slice among B–G (hard for each one it
  fronts). If B/C are not authorized, H proceeds without Search Console.
- **Tests:** full entitlement matrix (Core/Growth/Agency × Planned→Available ×
  capability × tenancy × Location ACL); nav; View As route-boundary test;
  Locked-account redirect; the end-to-end Core-vs-Growth boundary (Core reaches
  Overview/keyword coverage only; Search Console/Citations/Reviews/audit 404 on
  Core).
- **Risk:** Low.

## 16. Required tests (adversarial, beyond each sub-slice's own)

- **T-SEO-TEN-\*** capability without tenancy, tenancy without capability, both
  present but unentitled, guessed foreign Business/Location/keyword/citation/
  request/property uid → all 404.
- **T-SEO-LOC-\*** Selected-scope staff: inaccessible Location's citations,
  review links/requests, GBP status and rollup counts are neither listed nor
  countable; aggregate-after-filter proven with differing counts.
- **T-SEO-B-\*** (§15.B) and **T-SEO-C-\*** (§15.C).
- **T-SEO-BOUND-1** no SEO write path to Website/Business/Location/Google; **-2**
  no HTTP method beyond `listSites`/`querySearchAnalytics`; **-3** no Search
  Console data reachable from any LLM/AI class.
- **T-SEO-NAMING-1** SEO and legacy Keywords share no table, permission, route
  prefix; **-2** no SEO route starts with `customer.keywords.`.
- **T-SEO-BUDGET-\*** §11.4.
- **T-SEO-LOCK-1** every SEO route redirects for a Locked account; nothing on the
  allowlist.
- No cross-domain regression suite beyond Location-ACL, entitlement and GBP: this
  slice touches no Workspace/Agency/Conversations/Contacts/CRM code (Sub-slice B's
  GBP suite is its own gate).

## 17. Acceptance criteria

1. Core reaches the Overview (readiness, content counts, keyword on-page
   coverage, indexability status) with **zero** provider calls and no provider
   data stored (§5.2).
2. Growth/Agency reach Search Console, Citations, Reviews, the audit and position
   alignment; Core reaches none of them (404) (§5.1).
3. Exactly one Google connection authority exists; no second signer/token
   store/callback; the two products revoke independently (§7).
4. Search Console is bound only to a property matching the Business's own website
   domain; no property is ever auto-selected (§8.2).
5. Every Search Console figure carries an as-of date; absent data is never
   zero; disconnect purges cached data (§11.3).
6. GBP is reused only through the read-only status reader; SEO stores no GBP
   content and calls no Google GBP API (§9.2, §10.7).
7. Location-bound SEO data is filtered by `LocationAccessGuard` before any
   aggregation (§6, §10.4).
8. No SEO code writes Website, Business, Location, or Google data; no LLM call
   exists; suggestions are reports (§12).
9. Citations use no vendor and fetch no URL; private-address rules hold (§8.5).
10. Reviews store no Google review content; no gating, incentive or quota
    surface exists; SEO sends no message (§8.6).
11. The audit reads only immutable published snapshots and reports only
    customer-actionable findings (§8.7).
12. No SEO route is customer-reachable before H's flip; no SEO route is on the
    Locked allowlist; View As inventory updated and its boundary test green (§10.6,
    §14.1).
13. Legacy SMS Keywords is unchanged and cannot be confused with SEO keywords
    (§3.2, §14.3).
14. Query counts are independent of row counts (§11.4).
15. `git diff --check` clean and a clean working tree at the end of each
    sub-slice's own commit.

## 18. Non-goals

- **Extending or migrating legacy SMS Keywords** (§3.2 F1).
- **Any paid third-party vendor** — citation, review, SERP/rank, Places API,
  backlink or keyword-research. No authority exists; each needs its own contract,
  owner approval and privacy review. A vendor's observations must land in a
  **separate** future table, never in `seo_citations`.
- **Local-pack / geo-grid rank tracking, competitor analysis, content scoring,
  keyword research, internal-link recommendations, backlink analysis** (Website
  §20 lists these as later SEO Module work).
- **Google review ingestion, review replies, ratings display, Q&A, posts, media,
  performance metrics** (GBP Slice C; Q&A permanently discontinued — GBP §7).
- **Any Google mutation** — GBP profile edits (GBP Slice B) or Search Console
  writes (sitemap submit, site add). The `webmasters` read/write scope is never
  requested.
- **URL Inspection API, sitemap submission, crawling or fetching any site.**
- **Custom domains, Website Location pages, canonical tags, JSON-LD**, platform-path
  indexing (Website Slice B / future Website work); the audit's Location-page
  rules wait for them (G-1–G-3).
- **A per-Location phone model** (G-4) and **the Location "Locked" state** (G-5).
- **Retrofitting Location ACL into GBP/Website controllers** (Contract 08B's
  deferred work) and a `ResolvesBusinessTenancy` Location sibling.
- **Cross-client / Agency-wide SEO rollups**, and any GBP- or Search Console-derived
  feed into B5 Analytics, the Activity Center, Home or the COO (G-8; Google-content
  aggregation is forbidden for GBP data, and Search Console data may not reach any
  AI provider). B5's own deferral row ("SEO / GBP metrics — the SEO module ships")
  needs a separate B5 amendment.
- **An Opportunity-Engine `seo` worker** — requires its own registry additions and
  conformance tests (RFC-002 §13.2).
- **SEO domain events** — none is required by an authority and none has a
  consumer; the durable audit is the shared Google operation ledger plus actor
  columns. (`WebsitePublished` is consumed, not extended.)
- **Ads visibility** (`AdsBasicVisibility`/`GoogleAdsModule` stay `Planned`); the
  Blueprint's "Basic SEO/Ads visibility" Ads half is a separate slice.
- **A Business-level toggle for SEO** in `BusinessFeatureSettings::CUSTOMER_TOGGLEABLE`.
- **Review-request automation authoring, click tracking, short links, incentives,
  quotas, sentiment gating.**
- **A commercial keyword/citation cap** — only technical safety ceilings exist.

## 19. Merge prerequisites and owner decisions

**Hard prerequisites:** none at whole-slice level beyond Contracts 1–14 (merged).
Per-sub-slice prerequisites are in §15.

| # | Decision / gate | Default if unanswered | Blocks |
|---|---|---|---|
| OD-1 | Search Console totals tile on Core? | **No** (Growth+) | nothing |
| OD-2 | Approve the GBP connection product-discriminator amendment (Sub-slice B) | — | **B, C, and the Search Console half of H** |
| OD-3 | Confirm defaults: review-request cooldown 90 days; ≤ 10 seeded directories and the list itself | as stated | E/F content only |
| OD-4 | Operator prerequisites for Search Console: enable the API in the Cloud project, add the scope to the consent screen, complete any required OAuth-app verification (scope sensitivity **unverified**, §3.6); verify permission-level values and data-window/freshness | — | C (integration testing / production) |
| OD-5 | Legal confirmation that Search Console data handling satisfies Limited Use, and that no AI provider receives it | conservative: none does | C |
| OD-6 | Navigation label copy | "SEO" | H copy only |

## 20. Conflict map

| Other work | Shared file/table | Posture |
|---|---|---|
| Slices 15/16/17 | `CustomerMenuBuilder.php` (`ENTITLEMENT_GATED_FEATURES` + item), `config/customer-permissions.php` | additive lines, ordinary low-conflict merge; Roadmap says none touch `WorkspaceManager`/`CustomerAccountAccessResolver`/`EntitlementManager` |
| GBP (merged) | `business_google_connections`, GBP controller/managers/jobs/signers | **Sub-slice B only** — serialize B against any concurrent GBP change; all others parallel-safe |
| Website (merged) | `AppServiceProvider` event mapping (additive listener); read-only use of `website_revisions` | Low |
| Contract 08B (Location ACL wave) | `ResolvesBusinessTenancy` | SEO adds no sibling method; if 08B later wires GBP/Website, SEO's own filtering remains correct and is not removed |
| Product/legacy Keywords cleanup (Retention Audit Slice 10) | `customer.keywords.*`, `keywords` table | untouched here; naming rules in §3.2/§14.3 keep the two independent |
| `ViewAsProhibitedActions`, `ViewAsRouteClassification` | closed inventories | additive in C and H; `ViewAsRouteBoundaryTest` must pass in each |
| Two tests pinning SEO features `Planned` (`PlatformFeatureRegistryTest`, `GoogleBusinessProfileEntitlementTest`) | §14.1 | edited only in H, deliberately |

## 21. Implementation prompts

Each sub-slice is handed to a fresh session independently, once explicitly
authorized. Every prompt assumes Contracts 1–14 and every lower-lettered
sub-slice it depends on (§15) already merged to `main`. Every prompt ends with:
run the new tests, run `git diff --check`, commit, push to the fresh branch,
**do NOT create a pull request, do NOT merge**, and return starting/final SHA,
exact files, exact tests and counts, and confirmation of the sub-slice's
acceptance criteria.

### 21.A — Foundation, Core Overview, reader seams

```
Implement Sub-slice A of Slice 18 (SEO expansion) for os-creator1/os-ai per
docs/product/implementation-contracts/18-SEO-EXPANSION.md §5.2, §6, §9, §10,
§15.A. Fetch origin/main; create a fresh worktree/branch (e.g.
agent/v1-slice18a-seo-foundation). Re-read §3.2 (legacy Keywords is NOT SEO),
§5, §6, §9, §10 in full first.

Build: config/seo.php (fail-closed ceilings); the three permission keys
(view_seo, manage_seo, manage_search_console) plus a data-backfill migration
adding ONLY view_seo and manage_seo to the customer_permissions AppConfig row
and each customers.permissions list (copy 2026_09_09_120006's idiom); SeoLocation
Scope; SeoReadinessRuleRegistry; SeoPublishedContentReader; SeoOverviewReader;
the additive GoogleBusinessProfileStatusReader in the GBP namespace (read-only,
no provider call, ACL-filtered, expired mirror = absent, persists nothing, does
NOT modify any existing GBP file); SeoController@overview, the route group
{workspaceUid}/businesses/{businessUid}/seo (name businesses.seo.), the bare
entry route, and an Overview view with Core sections only.

PlatformFeature::SeoBasicVisibility and SeoModule MUST stay Planned; do not edit
PlatformFeatureRegistry, CustomerMenuBuilder, ViewAs inventories, or any GBP/
Website file. No provider calls, no writes to Website/Business/Location tables.
Tests per §15.A. Never reuse view_keywords or the customer.keywords.* namespace.
```

### 21.B — Google connection product discriminator (GBP refactor)

```
Implement Sub-slice B per §7 and §15.B. PREREQUISITE: the owner has approved
OD-2 in writing. Behavior-preserving only: after this change GBP must behave
byte-for-byte as before. Fetch origin/main; fresh worktree (e.g.
agent/v1-slice18b-google-connection-product).

Add product varchar(24) default 'business_profile'; replace bgc_business_unique
with unique(business_id, product); keep bgc_id_business_unique. Add
GoogleConnectionProduct. Make EVERY access to business_google_connections
product-scoped (list in §7.3); carry the product in the signed OAuth state and
branch the one fixed callback on it after full revalidation; keep
include_granted_scopes=false; extend GoogleOperationType additively; guarantee a
business_google_locations row can only reference a business_profile connection.

Do NOT add Search Console code. HARD GATE: the full existing GBP suite passes
with ZERO assertion edits, plus the source-boundary test (no unscoped access)
and the coexistence/independent-revoke/replay tests in §15.B. If any existing
GBP assertion must change, STOP and report.
```

### 21.C — Search Console

```
Implement Sub-slice C per §7, §8.2, §8.3, §10, §11, §15.C. Prereqs: A and B
merged; OD-2/OD-4/OD-5 resolved. Verify BEFORE coding (contract §3.6 lists them
as unverified): permissionLevel values, Search Console data window/freshness,
scope sensitivity/OAuth verification. Fresh worktree (e.g.
agent/v1-slice18c-search-console).

Build the read-only SearchConsoleReadClient (listSites, querySearchAnalytics
only; scope webmasters.readonly only; Http + Fake), connection manager using B's
authority, property binder with the domain-match guard (§8.2 rule 2), sync
service (3 calls/property, dataState=final, trim first_incomplete_date), the four
jobs, purge, controller/routes/views, View As prohibited entries, Overview
tiles. No SDK, no Socialite, no network in transactions, no raw payload/error
storage, no LLM/AI reachability, no mutation method on the client.
Features stay Planned. Tests per §15.C.
```

### 21.D — Keywords

```
Implement Sub-slice D per §8.4, §5.2.3, §15.D. Prereq: A. Fresh worktree (e.g.
agent/v1-slice18d-seo-keywords). Tables/models/routes/permissions/copy must be
distinct from legacy SMS Keywords (§3.2, §14.3); no reuse of view_keywords or the
customer.keywords.* prefix. Normalization is exactly §8.4. Ceiling 50 active,
config-backed. lifecycle_state/archived_at not mass-assignable. Location
attribution filtered by LocationAccessGuard. Coverage via
SeoPublishedContentReader. Do not depend on Search Console; the position join is
Sub-slice H. Features stay Planned. Tests per §15.D.
```

### 21.E — Citations

```
Implement Sub-slice E per §8.5, §15.E. Prereq: A. Fresh worktree (e.g.
agent/v1-slice18e-seo-citations). No vendor, no URL fetching, no auto-status
changes. Seed <= 10 directories, verifying every claim_url on the directory's own
site; do not invent entries. The comparator is deterministic, read-time, never
persisted; listed_address is rejected unless
GoogleBusinessProfileReadMask::addressPermittedForLocation() permits. Add the
GBP synthetic row through GoogleBusinessProfileStatusReader only. Features stay
Planned. Tests per §15.E.
```

### 21.F — Reviews

```
Implement Sub-slice F per §8.6, §15.F. Prereq: A. Fresh worktree (e.g.
agent/v1-slice18f-seo-reviews). Workflow/tracking ONLY: manual per-Location
review link, review-request ledger with cooldown and Contact/Location equality.
SEO sends nothing and adds no Automation trigger/node/merge field. Store no
Google review content, rating, or reviewer identity. Build NO review-gating,
incentive, quota, goal, leaderboard, click-tracking or short-link surface, and NO
dead Ratings tab. Contact details shown only as far as the actor could already
see them in Contacts. Features stay Planned. Tests per §15.F.
```

### 21.G — Technical / Website SEO audit

```
Implement Sub-slice G per §8.7, §12, §15.G. Prereq: A. Fresh worktree (e.g.
agent/v1-slice18g-seo-audit). Read only the immutable published
website_revisions snapshot. Closed rule registry v1 (customer-actionable fields
only); text = registry template + validated facts. Register a queued listener on
WebsitePublished that cannot fail a publish. NO write to any Website table, NO
URL fetch, NO crawler, NO AI. Platform limitations (no canonical, no JSON-LD,
platform-path noindex) are never findings; expose an indexability status
instead. Location-page rules are deferred (G-1). Features stay Planned.
Tests per §15.G.
```

### 21.H — Entitlement flip, navigation, integration, documentation

```
Implement Sub-slice H per §14, §15.H. Prereqs: A and every built sub-slice
among B-G. Fresh worktree (e.g. agent/v1-slice18h-seo-entitlement-nav).

Flip PlatformFeature::SeoBasicVisibility and SeoModule to Available in ONE
commit together with the deliberate edits to the two Planned-pinning tests (§14.1). Add
both keys to ENTITLEMENT_GATED_FEATURES; add the SEO nav entry (sections shown
only when built AND entitled AND permitted); fold the GBP nav entry into SEO
without changing GBP routes/URLs. Add PlatformFeatureCopy entries only if the
plan presenter requires them (verify). If C is merged, add the Search Console <->
keyword position join (exact phrase_normalized match; no match = "no data").
Update ViewAsRouteClassification aliases; run ViewAsRouteBoundaryTest. Correct
the Roadmap Slice 18 row, Traceability row 12, Acceptance Matrix "Manage SEO"
status and the Contract Index (§3.2). Do not add anything to the Locked-account
allowlist. Run the full entitlement/Core-vs-Growth matrix in §15.H.
```
