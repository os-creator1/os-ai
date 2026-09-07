# GOOGLE BUSINESS PROFILE — IMPLEMENTATION CONTRACT (SLICE A)

Status: **CONTRACT ONLY — no product code exists.** This document is the
single authority for a future Google Business Profile (GBP) Slice A
implementation pass. It contracts behaviour, schema, boundaries, and tests.
It does not implement them.

---

## 1. VERIFIED BASE AND EVIDENCE

### 1.1 Base

| Item | Value |
|---|---|
| Repository | `https://github.com/os-creator1/os-ai.git` |
| Verified `origin/main` at drafting | **`5e149f04714a776aa8377840da4b1621deedd973`** |
| Branch this contract is written on | `agent/google-business-profile-contract`, created directly from that SHA |
| Merged at that SHA | PR #207 (B4 Business Automations), PR #208 (Website Generation + Hosting contract) |

**The older GBP reconnaissance base `b2bedfc91848c90be2f1fc4e8e0ac440c6d4d892`
is an ancestor of the verified base and is NOT current.** No statement in
this contract treats it as current. It is named here once, only to record
that the GBP repository surface was re-verified as unchanged between that
SHA and the verified base: no path matching `google|gbp|gmb|oauth|socialite`
changed in the 44 files between them, and `mybusiness`, `business.manage`
and `businessprofileperformance` remain zero hits across `origin/main`.

### 1.2 Evidence hierarchy actually used

1. Lane C's completed report **GOOGLE BUSINESS PROFILE MODULE — DEEP
   RECONNAISSANCE REPORT** (repository facts).
2. Lane C's completed report **GOOGLE BUSINESS PROFILE — OFFICIAL GOOGLE
   API GATE VERIFICATION** (external Google facts, first-party Google
   sources only). **Every external Google fact in this contract comes from
   that report.** No Google API was called, no OAuth flow started, no
   credential used, no Cloud project touched and no access application
   submitted during this pass.
3. `docs/automation/WEBSITE-GENERATION-HOSTING-CONTRACT.md` (merged at the
   verified base).
4. `docs/automation/B4-BUSINESS-AUTOMATIONS-CONTRACT.md` and
   `docs/automation/B5-BUSINESS-ANALYTICS-CONTRACT.md` (merged at the
   verified base), plus the RFC-003/RFC-004/RFC-005 contracts for tenancy,
   entitlement, packaging, usage classification, ledger, retention and job
   precedents.
5. Current repository sources at the verified base, read directly, for every
   named path, class, enum, route, permission, migration, event and test in
   this document.

### 1.3 Recorded evidence gaps — declared, not silently resolved

**G-A. There is no merged SEO contract.** `docs/automation/` at the verified
base contains no SEO contract file, and no SEO implementation exists — `seo`
matches only `BusinessGoal`, `PlatformFeature`, `OpportunityWorkerKey`,
`InitialBusinessSnapshotBuilder`, `PlatformFeatureRegistry`,
`OpportunityTypeRegistry`, `EloquentSendingServerRepository`,
`config/app.php` and the plan-catalog seed migration, all incidental. The
task brief referred to "the current merged SEO contract"; it does not exist
at the verified base. The SEO boundary in §37 is therefore derived from (a)
Lane C's completed SEO reconnaissance and (b)
`WEBSITE-GENERATION-HOSTING-CONTRACT.md` §20, which *is* merged and
explicitly lists "GBP integration" among SEO-Module concerns that Website
Slice A does not touch. **No SEO contract section number is cited anywhere
in this document, because none exists to cite.**

**G-B. There is no B3 contract document.** B3 Settings precedents are taken
from code only. The one B3-derived rule this contract needs — do not apply
blank-preserve credential-form behaviour to OAuth tokens — is stated as a
prohibition (§9.9), not as a citation.

**G-C. There is no permission migration mechanism in this repository.** The
brief asked for a "permissions migration". Mechanically, customer
permissions are declared in `config/customer-permissions.php` and turned
into Gates by `app/Providers/AuthServiceProvider.php:54-58`; they are
*persisted per customer* as a JSON list in `customers.permissions`, written
once at customer creation from `App\Models\Customer::customerPermissions()`
(`app/Models/Customer.php:359-380`), and read back by
`EloquentAccountRepository::hasPermission()`
(`app/Repositories/Eloquent/EloquentAccountRepository.php:203-227`). No
migration has ever added a permission. §29 and §33 therefore contract the
real mechanism — a config addition plus a **data-operation backfill
migration** — and state exactly why the backfill is required. This is a
correction to the brief's assumed mechanism, recorded here rather than
silently applied.

**G-D. There is no entitlement cache.**
`app/Library/Entitlement/EntitlementManager.php` contains zero `Cache::`
calls; `decide()` is a pure database read
(`EntitlementManager.php:111-186`). The brief's requirement that
"entitlement cache keys include the complete Business/Workspace identity"
has no existing artefact to apply to. §31 therefore contracts the stronger,
mechanically checkable rule: **Slice A introduces no cross-request cache at
all**, and a test asserts the `Cache` facade is unreachable from GBP code.

None of G-A…G-D is a conflict between the completed reports and merged code.
**No such conflict was found.** Every conflict-class fact in the
reconnaissance report — no GBP integration, `google_business_profile_url` as
nullable free text, sign-in-only Google OAuth, `public_address` with zero
readers, the `PlatformFeature` case list, and the two idempotent/throwing
migrations — was re-verified against the verified base and still holds.

---

## 2. PRODUCT DECISION AND ENTITLEMENT MATRIX

### 2.1 Binding human decision

| Plan tier | Google Business Profile |
|---|---|
| **Core** | **Excluded** |
| **Growth** | **Included** |
| **Agency** | **Included** |
| Separately priced add-on | **Not used** |

This is final. No alternative packaging may be proposed, inferred or
implemented. No price is invented anywhere in this contract, consistent with
`database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php`,
which seeds `price => null` and `currency_id => null` for every tier.

### 2.2 New PlatformFeature identity — LOCKED

Derived mechanically from the existing enum
(`app/Enums/Entitlement/PlatformFeature.php`), whose module-level features
are `SeoModule => 'seo_module'`, `GoogleAdsModule => 'google_ads_module'`
and `MetaAdsModule => 'meta_ads_module'`:

```php
case GoogleBusinessProfileModule = 'google_business_profile_module';
```

| Concern | Value |
|---|---|
| PHP case name | `GoogleBusinessProfileModule` |
| Persisted feature key | `google_business_profile_module` |
| `PlatformFeatureRegistry::AVAILABILITY` | `PlatformFeatureAvailability::Available`, added **only when Slice A implementation lands** |
| `PlatformFeatureRegistry::SCOPE` | **No entry.** Business scope is the default — `isWorkspaceScoped()` returns Business for every key absent from `SCOPE`. Adding one would be wrong and is forbidden |

The `Planned → Available` flip follows the exact evidentiary bar recorded in
`PlatformFeatureRegistry`'s class docblock and reused by
`WEBSITE-GENERATION-HOSTING-CONTRACT.md` §26.1: flip only once "a real,
executable… controller/routes/persistence" exists. Slice A supplies exactly
that.

**The enum case, the AVAILABILITY entry, the packaging migration and the
classification migration all land in the same implementation pass.**
Shipping the case without the classification row makes
`database/migrations/2026_08_16_120008_backfill_platform_feature_usage_classifications.php`
throw `PlatformFeatureUsageClassificationBackfillIncompleteException` on a
fresh migrate — that migration counts `PlatformFeature::cases()` against
persisted rows and throws when any case is unclassified (lines 51-58).

### 2.3 Why new migrations are mechanically required

| Migration | Why an existing one cannot be edited |
|---|---|
| Plan packaging | `2026_08_13_120007` has already run in every environment and is insert-if-missing; editing its `$growthFeatures`/`$agencyFeatures` constants would never re-run. Its `down()` is a deliberate non-destructive no-op |
| Usage classification | `2026_08_16_120008` has already run and skips `feature_key`s already present; editing it cannot add a new row |

Neither file may be edited. Both new migrations must copy their idempotent,
query-builder-only, insert-if-missing idiom exactly.

### 2.4 Agency unlimited slots do not weaken anything

`workspace_plan_catalog` seeds Agency with `unlimited_business_slots => true`
and `business_slot_max => null`. That governs **how many Businesses a
Workspace may hold**, and nothing else. It grants no cross-Business
visibility, no cross-Workspace visibility and no Google authority. Every GBP
request still runs the full §15 chain per Business, and every Google call is
still bounded by the single OAuth grant that the specific Business's own
connection holds. An Agency Workspace with 400 Businesses has 400
independent connections, 400 independent entitlement decisions and no
shared token.

---

## 3. PROBLEM AND GOAL

**Problem.** The platform stores `businesses.google_business_profile_url` as
nullable free text (`string(2048)`, normalized by
`App\Library\Business\UrlNormalizer` as one of `BusinessManager`'s four URL
fields, validated `['nullable','string','max:2048']` with no URL-format and
no Google-domain check). It is never parsed, never resolved to an identifier
and never used to contact Google. The platform therefore cannot tell a
customer whether their Google listing exists, is verified, is suspended, is
a duplicate, or disagrees with the CRM record they maintain here.

**Goal of Slice A.** Let a Business owner connect their own Google account,
explicitly choose which Google location corresponds to which
`BusinessLocation`, and see a deterministic, field-by-field comparison of
platform data against Google data — **without the platform writing anything
to Google and without the platform overwriting anything on the platform.**

**Non-goal of Slice A.** Improving the listing. Slice A tells the truth
about divergence; it never resolves it.

---

## 4. EXISTING-STATE INVENTORY (re-verified at `5e149f0`)

### 4.1 What exists and is kept unchanged

| Artefact | Path | Disposition |
|---|---|---|
| `businesses.google_business_profile_url` | `database/migrations/2026_07_18_120001_create_businesses_table.php` | `string(2048)` nullable. **Keep, unchanged** |
| URL normalization | `App\Library\Business\UrlNormalizer` via `BusinessManager` | **Keep, unchanged** |
| `missing_gbp_url` Opportunity type | `App\Library\Opportunity\OpportunityTypeRegistry` | **Keep, unchanged** |
| `add_gbp_url` Opportunity action (`approval_required: true`) | `App\Library\Opportunity\OpportunityActionRegistry` | **Keep, unchanged** |
| `gbp_url_blank` snapshot fact | `App\Library\Business\InitialBusinessSnapshotBuilder` | **Keep, unchanged** |
| Onboarding GBP-URL behaviour and its three FormRequests | onboarding surfaces | **Keep, unchanged** |

Slice A **adds** a structured connection alongside the free-text URL. It
does not deprecate, migrate, backfill or read that column, and it never
writes to it.

### 4.2 Platform facts Slice A compares against

| Table | Columns used |
|---|---|
| `businesses` | `name`, `description`, `phone`, `email`, `website_url`, `country_code`, `timezone`, `currency_code`, `industry` |
| `business_locations` | `name`, `service_mode`, `address_line_1/2`, `city`, `region`, `postal_code`, `country_code`, `latitude`, `longitude`, **`public_address`**, `service_radius_km`, `service_area_cities`, `is_primary` |
| `App\Enums\Business\BusinessServiceMode` | `Storefront`, `ServiceArea`, `Hybrid`, `Online` |

`business_locations.public_address` (boolean, default false) currently has
**zero readers** —
`InitialBusinessSnapshotBuilder::locationMeetsServiceModeRequirements()`
checks address completeness by service mode without consulting it. **GBP is
its first consumer** (§23).

### 4.3 House precedents this contract reuses

| Concern | Precedent |
|---|---|
| Encryption at rest | `App\Models\PaymentProviderEvent` — `'payload_encrypted' => 'encrypted'`, the **only** existing use of Laravel's `encrypted` cast |
| Fail-closed retention | `App\Jobs\Usage\PurgeExpiredWebhookPayloads` + `config/usage_billing.php:16-20` |
| External-mutation ledger | `2026_08_16_140003_create_business_funding_attempts_table.php` — `local_idempotency_key` unique, `provider_session_or_intent_reference` unique, `state` |
| Business-scoped controller | `App\Http\Controllers\Customer\Business\AutomationsController` |
| Bare-route chooser | `App\Http\Controllers\Customer\Business\MessagingChannelsController::entry()` — 0 → view, 1 → redirect, many → chooser |
| Provider contract + Fake | `App\Library\Usage\Contracts\PaymentProviderGateway` / `App\Library\Usage\FakePaymentProviderGateway`; `App\Library\AgencyProspecting\FakeAgencyProspectingAiClient` |
| Job base | `App\Jobs\Base` — `tries = 1`, `maxExceptions = 1`, `failOnTimeout = true` |
| Scheduler | `App\Console\Kernel::schedule()` — `$schedule->job(new X())->hourly()` |
| Event | `App\Events\Business\BusinessPrimaryLocationUpdated` — `implements ShouldDispatchAfterCommit`, `use Dispatchable`, readonly promoted scalars |
| Throttling | inline route middleware, e.g. `->middleware('throttle:5,60')` in `routes/customer.php:547` |

### 4.4 What does not exist

Zero occurrences, repository-wide, at the verified base: any Google account
id, Google location id, `place_id`, `store_code`, opening hours, GBP
attribute, GBP category, review, photo, verification state, `refresh_token`,
`->scopes(`, `mybusiness`, `business.manage`, `businessprofileperformance`,
Google SDK, service account, GBP route, GBP controller, GBP provider
service, GBP job, GBP permission, GBP entitlement key, GBP config block or
GBP test. `Crypt::` / `encrypt(` / `decrypt(` are also zero hits — the
`encrypted` cast is the only precedent.

### 4.5 Existing Google OAuth is sign-in only and is out of scope

`App\Http\Controllers\Auth\LoginController::handleProviderCallback()` calls
`Socialite::driver($provider)->user()`, then
`EloquentAccountRepository::findOrCreateSocial()` — which **creates a
platform `User`** when `config('account.can_register')` — then
`auth()->login($user, true)` and force-sets `email_verified_at`. It requests
**no scopes** and obtains **no refresh token**, and its callback route in
`routes/auth.php` is unauthenticated with no Business context.

**Forbidden to modify in the GBP implementation pass:**
`app/Http/Controllers/Auth/LoginController.php`, `routes/auth.php`,
`EloquentAccountRepository::findOrCreateSocial()`, and the existing
`services.google` block in `config/services.php`.

---

## 5. EXPLICIT SLICE A SCOPE

Slice A delivers exactly, and only:

1. `PlatformFeature::GoogleBusinessProfileModule`, Available, Business
   scope, packaged to Growth + Agency (§2).
2. Two new customer permissions (§16).
3. A dedicated, Business-scoped Google OAuth connection with its own client
   (§9).
4. Encrypted refresh-token lifecycle: connect, callback, revoked, reconnect,
   disconnect (§9, §10).
5. Google account enumeration (Account Management v1).
6. Google location enumeration (Business Information v1), request-scoped.
7. Explicit user selection and binding to one `BusinessLocation` (§8).
8. A read-only, bounded, TTL-capped Google profile mirror (§21).
9. A deterministic, never-persisted platform-vs-Google comparison (§22).
10. Manual refresh, plus at most one daily staggered background refresh
    (§24).
11. A retention purge job (§13).
12. An operation/audit ledger (§27).
13. M2-compatible Business UI (§25) and one navigation entry (§26).
14. A Fake provider client and the full test matrix (§14, §32).

---

## 6. EXPLICIT EXCLUSIONS

Slice A **writes nothing to Google.** It performs no `locations.patch`, no
category, attribute, hours, address, website or service mutation, no review
reply, no post, no media upload, no verification initiation, no
notification-setting write and no Ads operation. §36.1 (Slice B) and §36.2
(Slice C) record what is deliberately not built; §31 is the global
stop-list.

---

## 7. API / VERSION MAP — LOCKED

Every row is taken from the completed official verification report.
**Slice A is 100 % current-v1. It introduces no v4/v4.9 dependency.**

| Purpose | API (official name) | Version | Service endpoint | Method(s) Slice A may call |
|---|---|---|---|---|
| Account enumeration | My Business **Account Management** API | v1 | `mybusinessaccountmanagement.googleapis.com` | `GET /v1/accounts` (`accounts.list`) |
| Location enumeration | My Business **Business Information** API | v1 | `mybusinessbusinessinformation.googleapis.com` | `GET /v1/{parent=accounts/*}/locations` (`accounts.locations.list`) |
| Bound-location read | My Business **Business Information** API | v1 | same | `GET /v1/{name=locations/*}` (`locations.get`) |
| Read-only verification state | My Business **Verifications** API | v1 | `mybusinessverifications.googleapis.com` | `GET /v1/{name=locations/*}/VoiceOfMerchantState` (`locations.getVoiceOfMerchantState`) |
| OAuth | Google OAuth 2.0 | — | Google identity endpoints | authorization URL, code exchange, refresh exchange |

**Explicitly not used by Slice A** (all v4.9, `mybusiness.googleapis.com`):
`accounts.locations.reviews`, `accounts.locations.batchGetReviews`,
`accounts.locations.media`, `accounts.locations.localPosts`,
`accounts.locations.questions`. Also unused: Business Profile Performance
API v1, My Business Notification Settings API v1, Place Actions, Lodging.

**Permanently excluded — the My Business Q&A API.** Support ended
2025-09-15; discontinued **2025-11-03** per Google's published Deprecation
schedule. It is never to be built, notwithstanding that the v4 reference
still lists a `questions` resource and the Notifications discovery document
still defines `NEW_QUESTION`/`NEW_ANSWER`. **The Deprecation schedule is
authoritative over reference-page presence.** Also confirmed discontinued
and never to be used: My Business Calls API (2023-05-30) and
`accounts.locations.reportInsights` (2023-03-30).

**Lifecycle note.** Google publishes **no sunset date** for the Google My
Business API v4.9 as a whole, and it is still actively changed (review
fields added 2026-04-01, 2026-04-20, 2026-07-01 and 2026-07-24). Its
*accounts* sub-resources — `accounts`, `accounts.admins`,
`accounts.invitations`, `accounts.locations.admins` — **are** deprecated in
favour of Account Management v1, which is exactly the API Slice A uses.

### 7.1 Field-level facts Slice A relies on

| Fact | Consequence for this contract |
|---|---|
| `accounts.list` `pageSize` "default and maximum is **20**" | Enumeration must page |
| `accounts.locations.list` `pageSize` default 10, **maximum 100** | Enumeration must page |
| `accounts.locations.list` **`readMask` is Required** | §20 read mask, and Slice A's strongest privacy control (§23) |
| `Account.type` is one of `PERSONAL, LOCATION_GROUP, USER_GROUP, ORGANIZATION`; a `PERSONAL` parent returns only directly-owned locations, other types return all accessible locations "either directly or indirectly" | Enumerate **all** accounts; never pick one |
| `Account.role` is one of `PRIMARY_OWNER, OWNER, MANAGER, SITE_MANAGER` | Shown at bind time |
| The location resource name is `locations/{locationId}` and is addressed **without an account prefix** by `locations.get`, `locations.patch`, `getVoiceOfMerchantState` and every Performance method | Provider location identity is **global** → the platform-wide unique constraint C-3 (§12) |
| Reviews, media and local posts (v4.9) are addressed as `accounts/{accountId}/locations/{locationId}/…` | **The account resource name must also be persisted** (§8.4) |
| `ServiceAreaBusiness.businessType` is one of `BUSINESS_TYPE_UNSPECIFIED`, `CUSTOMER_LOCATION_ONLY` ("Offers service only in the surrounding area (not at the business address)"), `CUSTOMER_AND_BUSINESS_LOCATION` ("Offers service at the business address and the surrounding area") | §22.4 service-mode comparison |
| `Places.placeInfos` is "Limited to a maximum of **20** places"; `PlaceInfo.placeId` "Must correspond to a region" | Google uses **region place IDs**, not radii → `Not comparable` (§22.4) |
| `Location.storefrontAddress` is **Optional**; there is **no** `showAddress` / `addressVisibility` field anywhere in the v1 Location schema (discovery revision `20260902`) | §23; and §36.1.2 records address suppression as unresolved for Slice B |
| `Location.metadata` is Output only and includes `hasVoiceOfMerchant`, `hasPendingEdits`, `duplicateLocation`, `placeId`, `mapsUri`, `newReviewUri`, `canDelete`, `canModifyServiceList`, `hasGoogleUpdated`, `isParticularlyPersonalPlace` | §21 mirror fields |
| `OpenInfo.status` is one of `OPEN_FOR_BUSINESS_UNSPECIFIED, OPEN, CLOSED_PERMANENTLY, CLOSED_TEMPORARILY` | §21 mirror field |
| `VoiceOfMerchantState` exposes `hasVoiceOfMerchant` ("in good standing and has control over the business on Google"), `hasBusinessAuthority`, `verify.hasPendingVerification`, `waitForVoiceOfMerchant`, `resolveOwnershipConflict` (an **empty object** — presence is the signal) and `complyWithGuidelines.recommendationReason` ∈ `BUSINESS_LOCATION_SUSPENDED`, `BUSINESS_LOCATION_DISABLED` | §21.3 derived state; §36.1.2 write gate |
| `Category.name` is "A stable ID (provided by Google)"; `attributes.list.categoryName` "Must be of the format `categories/{category_id}`"; `categories.batchGet` calls them "GConcept ids" | Store the opaque string verbatim; **never parse it** |
| `categories.list` requires `regionCode`, `languageCode` and `view`; `filter` supports only `displayName`, and "Search only matches the front of a category name" | No global cached category list; **no authoritative mapping primitive exists** (§22.5) |
| Quota is **300 QPM per project** across every API; over-quota returns `429 Too Many Requests` / `RESOURCE_EXHAUSTED`; Google prescribes "exponential backoff with jitter"; increases are denied for "a highly spiky request pattern rather than a smooth distribution" | §24 |
| Business Information v1 discovery (revision `20260902`) contains **no `google.longrunning` Operations resource** | Slice A needs no long-running-operation handling |
| Google publishes **no** rate-limit header, error taxonomy or provider request-id for these APIs | §24.6 — the `unknown` status is mandatory, and no header-driven backoff may be written |

---

## 8. DOMAIN OWNERSHIP AND CARDINALITY — LOCKED

### 8.1 Ownership

| Concept | Owner | Rationale |
|---|---|---|
| OAuth connection (grant, refresh token, scopes, state) | **`Business`** | One Google grant can expose many accounts and many locations; tokens must live one level above the binding |
| Location binding | **`BusinessLocation`** | Google's own unit is a location; `business_locations` is already one-to-many with per-row address, geo and service mode |
| Operation / audit record | **`Business`** (denormalized), optionally referencing a binding | History must survive disconnect and unbind |

### 8.2 Cardinality — LOCKED

* One `Business` has **at most one** `business_google_connections` row
  (`business_id` UNIQUE).
* One `BusinessLocation` has **at most one** `business_google_locations` row
  (`business_location_id` UNIQUE).
* One Google location resource name is claimable by **at most one**
  `BusinessLocation` **platform-wide** (`provider_location_resource_name`
  UNIQUE, with no tenant qualifier).
* A binding's connection and its `BusinessLocation` must belong to the
  **same** `Business`. Enforced in the database (C-4, C-5) and re-asserted
  in the service layer.

Most Businesses have a single `is_primary` location, so the experience
collapses to 1:1. **The schema must not.** Relaxing a unique constraint on a
provider identity later is a painful migration; tightening the UI is not.

### 8.3 Enumeration is request-scoped

Candidate accounts and candidate locations are fetched, ranked, rendered and
discarded within a single request. **No candidate row is ever persisted.**
Only the location the user explicitly confirms becomes a
`business_google_locations` row.

### 8.4 Both resource names are persisted — MANDATORY

`business_google_locations` stores **both**:

* `provider_account_resource_name` — `accounts/{account_id}`
* `provider_location_resource_name` — `locations/{location_id}`

The location name alone is sufficient for every Slice A call, because all
four Slice A read methods are v1 and address `locations/*` directly. The
account name is stored because every future v4.9 surface (reviews, media,
local posts) addresses `accounts/{a}/locations/{l}`, and re-deriving the
account later would require a full re-enumeration under a grant that may no
longer be valid. **This is one of the three corrections this contract
carries forward from the official verification pass.**

### 8.5 Nothing is ever auto-selected

Deterministic matching signals — exact or case/whitespace-normalized title
match, postal-code match, `country_code` match, `is_primary` position — may
**order** the candidate list and may render a non-binding "likely match"
hint. They may never pre-select a radio button, never pre-tick a checkbox
and never submit a binding. **The bind action is a separate, explicit POST
carrying the chosen `provider_location_resource_name`.**

---

## 9. OAUTH LIFECYCLE — LOCKED

### 9.1 Dedicated client — mandatory

A new `services.google_business_profile` block in `config/services.php`,
additive only, leaving `services.google` (Socialite sign-in) untouched:

```php
'google_business_profile' => [
    'client_id'     => env('GOOGLE_BUSINESS_PROFILE_CLIENT_ID'),
    'client_secret' => env('GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET'),
    'redirect'      => env('GOOGLE_BUSINESS_PROFILE_REDIRECT'),
],
```

Laravel Socialite is **not** used for GBP. The GBP client is a direct HTTP
client (§14), so that no Socialite driver — and therefore no
`findOrCreateSocial()` path — is reachable from GBP code.

### 9.2 The scope, and why read-only must be structural

Google exposes exactly one Business Profile scope:

```text
https://www.googleapis.com/auth/business.manage
```

**There is no narrower read-only GBP scope.** Every read *and* write method
page cites the identical string. Therefore:

> **The read-only property of Slice A is enforced by our own code, not by
> OAuth.** The provider interface (§14) exposes no mutation method; the real
> client implements no mutation method; and test T-PROV-1 proves no
> mutation-capable method is reachable. Dormant "for later" write methods
> are forbidden.

Incremental authorization (`include_granted_scopes=true`) is of no practical
use here — there is only one scope to grant — so **no staged-consent flow is
designed**. The consent screen the user sees will read **"Manage your
Business Profile on Google"**; §25.9 requires the UI to disclose this before
the user is sent to Google.

### 9.3 Connect initiation

`GET /workspaces/{workspaceUid}/businesses/{businessUid}/gbp/connect`
(§17), authenticated, running the full §15 chain plus
`manage_google_business_profile`. It:

1. Generates a nonce with `Str::uuid()`. **`uniqid()` is forbidden.**
   `App\Library\Traits\HasUid::generateUid()` uses `uniqid()` and is
   therefore not usable for a security nonce; `Workspace` and
   `PlatformThemePreset` already override it with `Str::uuid()`, which is
   the house precedent this follows.
2. Persists the nonce as a single-use, short-lived record (§9.4).
3. Builds the authorization URL with `access_type=offline`,
   `include_granted_scopes=false`, `scope=https://www.googleapis.com/auth/business.manage`,
   `state=<signed state>` and the configured `redirect`.
4. Sends `prompt=consent` **only** when this Business has no stored refresh
   token, or when its connection is in state `revoked`. A reconnect of a
   healthy connection does not force consent. (Google returns a refresh
   token "only… the first time that your application exchanges an
   authorization code for tokens" under `access_type=offline`; forcing
   consent on every connect is unnecessary noise, and never forcing it makes
   an intentional reconnect unable to recover a lost token.)
5. Writes a `connect_initiated` ledger row (§27) **before** redirecting.

### 9.4 Signed state — exact contents

The state carries the **minimum** identifiers only:

```text
{ "b": <business_id:int>, "n": "<nonce uuid>", "e": <expires_at unix ts> }
```

serialized, then signed with the application key. **It carries no
`redirect_to`, no `return`, no URL of any kind and no user id.**

The nonce is stored in a single-use record whose consumption is atomic: the
callback consumes it with a conditional delete/update that must affect
exactly one row; a second callback carrying the same nonce affects zero rows
and fails closed. TTL is
`config('google_business_profile.oauth.state_ttl_seconds')`, default **600
seconds**, resolved fail-closed — an absent or invalid value means every
state is treated as already expired.

### 9.4b Actor binding — CORRECTED (correction item 2)

The state payload stays `{b, n, e}` — it needs no actor field, because the
actor is bound through the connection row instead:

* **Every newly issued attempt re-stamps `connected_by_user_id`**, including
  when the connection row is ALREADY `pending`. The original implementation
  skipped that case, leaving a stale actor bound to a fresh nonce.
* **A second initiation replaces the nonce and the actor binding together**,
  so an older actor's older state dies with it.
* The callback compares the authenticated user against
  `connected_by_user_id` **before consuming the nonce**, so a mismatched
  actor cannot burn the rightful actor's still-valid attempt.

Cross-tenant tests cannot reach this failure: both actors may legitimately
pass the whole §15 chain. The test matrix therefore uses **two separately
authorized manage users inside the same Business** (§32.3).

### 9.5 Callback — revalidation, in this order, all failures 404

`GET /gbp/oauth/callback` — the ONE fixed, tenant-free route (§17.1b),
registered in the authenticated customer route file so it inherits
`['web','auth','can:access_backend','ValidProduct','twofactor']` from
`RouteServiceProvider::mapWebRoutes()`.

**The order is corrected (item 1): the state is verified FIRST, and every
piece of tenant data is then resolved FROM it.** The route carries no
Workspace or Business parameter, so there is nothing caller-supplied left to
spoof.

1. State parameter present, else 404.
2. Signature valid, else 404.
3. Not expired, else 404.
4. Business resolved **exclusively from `state.b`**, else 404; the
   connection resolved from that Business, else 404.
5. That Business's Workspace resolved **from the database**, else 404.
6. Workspace `is_active`, else 404.
7. The Business is inside that Workspace, else 404.
8. `WorkspaceManager::userCanAccessBusiness((int) Auth::id(), $business)`,
   else 404.
9. `$business->status === BusinessStatus::Active`, else 404.
10. `EntitlementManager::decide($workspace, $business, PlatformFeature::GoogleBusinessProfileModule->value, (int) Auth::id())`
    allowed, else 404.
11. `manage_google_business_profile` granted, else **404** — deliberately
    not the platform's usual 401, because a permission-shaped response on a
    tenant-free route would itself disclose that the signed Business exists
    and is reachable.
12. **The callback actor equals `connected_by_user_id`** (§9.4b), else 404.
    Checked BEFORE consumption, so a mismatched actor cannot burn the
    rightful actor's nonce.
13. **Only now** is the nonce consumed atomically, affecting exactly one
    row, else 404.

**Only after all thirteen pass is the authorization code exchanged.** A
missing, altered, expired, replayed, foreign-Business, wrong-actor or
inaccessible state returns 404 **before any token exchange**, and none of
those responses reveals whether the Business exists.

The final redirect is built from the **canonical Workspace and Business
UIDs resolved server-side**, never from anything the caller supplied.

Google may return `error=access_denied`. That renders as a neutral
"connection not completed" state with a `connect_failed` ledger row — never
an exception page, never with the provider payload echoed.

### 9.6 The callback must never touch identity

Forbidden in the callback and everywhere in GBP code: `auth()->login()`,
`Auth::login()`, `Auth::loginUsingId()`, any `User` creation, any write to
`email_verified_at`, any call to `findOrCreateSocial()`, and any
`Socialite::driver()` call.

### 9.7 Token storage

* `refresh_token_encrypted` uses Laravel's built-in `'encrypted'` cast — the
  same, and only, precedent as `PaymentProviderEvent.payload_encrypted`.
* **No durable access token by default.** An access token is derived from
  the encrypted refresh token at the start of a unit of work and held for
  the lifetime of that request or job only. `business_google_connections`
  has **no access-token column**. If a future verified provider requirement
  proves a durable access token necessary, that is a schema change under its
  own contract, never an implementation-time choice.
* Tokens are never rendered, never exported, never logged, never placed in
  exception context, never included in an audit summary, never returned by
  any endpoint and never flashed to the session.

### 9.8 Failure and revocation

`invalid_grant` from a refresh exchange transitions the connection to
`revoked` (§10.1) and **is never retried automatically**. Google documents
its causes as: the user revoked access; the refresh token has not been used
for **six months**; a password change with Gmail scopes; the account
exceeding its maximum live refresh tokens (limit **100 per Google Account
per OAuth 2.0 client ID**); time-based access expiry; an admin restricting a
requested service; and Cloud session-length expiry.

Handling:

* **Revocation** → state `revoked`, the §25.7 reconnect UI, and no further
  background refresh for that connection until a successful reconnect.
* **Six-month idle expiry** → the same reconnect path. **No keep-alive
  traffic is invented.** The bounded daily refresh (§24) already touches an
  active connection far more often than every six months; a connection idle
  that long has by definition not been refreshed, and the correct answer is
  a reconnect prompt, not synthetic requests.

### 9.9 Explicitly not applicable

* **B2 messaging credentials are not reused.** A Twilio or Telnyx API key is
  a static secret the customer owns; a Google refresh token is delegated,
  revocable, expiring, third-party access. Different lifecycle, different
  storage, different failure modes.
* **B3's blank-preserve credential-form behaviour does not apply.** That
  convention exists so an operator can re-submit a settings form without
  clearing a secret. A refresh token is never typed into a form. The GBP UI
  has exactly two states — *connected* and *not connected* — and one action
  each way.

---

## 10. CONNECTION AND BINDING STATE MACHINES — LOCKED

### 10.1 `App\Enums\GoogleBusinessProfile\GoogleConnectionState`

| Value | Meaning |
|---|---|
| `pending` | Connect initiated, state issued, no token yet |
| `active` | Refresh token held and the last exchange succeeded |
| `revoked` | `invalid_grant` observed, or the user revoked access at Google |
| `disconnected` | The customer disconnected; authorization material destroyed |

Valid transitions — **and no others**:

```text
(none)       -> pending          connect initiation
pending      -> active           successful code exchange
pending      -> disconnected     callback abandoned / state expired
active       -> revoked          invalid_grant on refresh
active       -> disconnected     explicit disconnect
revoked      -> pending          explicit reconnect initiation
revoked      -> disconnected     explicit disconnect
disconnected -> pending          explicit reconnect initiation
```

`pending -> active` is the only transition that may store a refresh token.
A transition not in this table is a programming error and must throw, never
silently no-op.

### 10.1b The refresh-token state invariant — CORRECTED (correction item 4)

The contract already said the token is null in every state except `active`.
The original implementation broke that on `revoke()`, which retained it.
The invariant is now mechanical:

* **Entering `pending`, `revoked` or `disconnected` nulls
  `refresh_token_encrypted` and `granted_scopes` in the SAME conditional
  update that sets the state.** No non-active row may retain authorization
  material of any kind.
* **Completing a connection REQUIRES a newly returned refresh token.**
  Google returns one only on a fresh consent; if it returns none, the
  connection **fails closed**, stays `pending`, holds no token, and reports
  a safe error. It must never fall back to a token that is already known to
  be revoked.
* **There is no "reconnect a healthy connection" path.** This resolves the
  contradiction in the original §9.3, which both forced consent only
  sometimes and contemplated reactivating without a new token.
  `beginConnect()` refuses outright when the connection is already
  `active` — no state change, no provider call — and the UI offers
  Disconnect instead. Consequently **every reachable starting state holds no
  usable token, so consent is ALWAYS forced**, and a recovery from
  `revoked`/`disconnected` always yields a new refresh token or fails.

### 10.2 Binding lifecycle

A `business_google_locations` row exists only in the bound state. There is
no `pending_bind`. Bind creates the row inside a transaction; unbind deletes
it. Both write a ledger row. A binding whose parent connection leaves
`active` is **not** deleted — it renders as "connection needs attention", so
the user does not silently lose the mapping they chose and have to
re-select it after a reconnect.

---

## 11. EXACT PROPOSED SCHEMA

All three tables are **proposed** — none exists. Column types follow the
existing `business_*` and `payment_provider_*` conventions.

### 11.1 `business_google_connections`

| Column | Type | Notes |
|---|---|---|
| `id` | `id()` | |
| `uid` | `uuid()->unique()` | generated with `Str::uuid()`, not `HasUid` |
| `business_id` | `foreignId()->constrained('businesses')->onDelete('cascade')` | **UNIQUE** |
| `state` | `string(16)` default `'pending'` | `GoogleConnectionState` cast |
| `refresh_token_encrypted` | `text()->nullable()` | `'encrypted'` cast. **Null in every state except `active`** |
| `granted_scopes` | `string(512)->nullable()` | space-separated scope string exactly as returned |
| `google_account_email` | `string(191)->nullable()` | see §11.1.1 |
| `connected_at` | `timestamp()->nullable()` | |
| `disconnected_at` | `timestamp()->nullable()` | |
| `revoked_at` | `timestamp()->nullable()` | |
| `last_refreshed_at` | `timestamp()->nullable()` | last successful token or data refresh |
| `failure_classification` | `string(32)->nullable()` | closed vocabulary only (§11.1.2) |
| `connected_by_user_id` | `foreignId()->nullable()->constrained('users')->nullOnDelete()` | audit actor |
| `lock_version` | `unsignedInteger()->default(0)` | §11.1.3 |
| `created_at` / `updated_at` | `timestamps()` | |

**11.1.1 `google_account_email` — justified and bounded.** Stored because a
user with several Google accounts must be able to see *which one* is
connected, and because a support conversation is otherwise impossible. It is
the authenticated grantor's email, capped at 191 characters, shown only to
users holding `view_google_business_profile` inside that Business, never
logged and never exported. It is a property of our own authorization record
rather than profile Content, and is retained for the connection lifetime; it
is nulled by disconnect together with the token (§13.5).

**11.1.2 `failure_classification` — closed set:** `invalid_grant`,
`access_denied`, `rate_limited`, `provider_unavailable`, `timeout`,
`unexpected_response`. **No raw provider error string, no HTTP body and no
exception message is ever stored in this column.**

**11.1.3 Concurrency — GENUINELY OPTIMISTIC (correction item 3).** Every
state transition and every refresh-token write is a **single conditional
UPDATE** carrying `WHERE lock_version = ?` with the version the caller
loaded, setting `lock_version + 1`:

* the update must affect **exactly one row**;
* **zero affected rows throws
  `GoogleBusinessProfileConcurrencyException`** — the caller lost the race
  and must not proceed;
* the in-memory model is refreshed after success;
* a lost race emits **no success event and no successful ledger outcome**,
  and is never blindly retried;
* **token storage and the transition to `active` are ONE conditional
  update** — they cannot be separated;
* disconnect and revoke obey the same rule.

Incrementing the attribute on a possibly-stale in-memory model and calling
`save()` — the behaviour this contract originally allowed — is NOT
optimistic locking: the loser silently overwrites the winner. It is
forbidden.

Because that write goes through the query builder, it bypasses the model's
`encrypted` cast, so the token is encrypted with `Crypt::encryptString()` —
exactly what the cast itself uses. The round trip is asserted by test: the
manager writes, the model reads back plaintext, and a raw column read does
not.

### 11.2 `business_google_locations`

| Column | Type | Notes |
|---|---|---|
| `id` | `id()` | |
| `uid` | `uuid()->unique()` | `Str::uuid()` |
| `business_google_connection_id` | `unsignedBigInteger()` | part of composite FK C-5 |
| `business_id` | `unsignedBigInteger()` | denormalized; part of composite FKs C-4 and C-5 |
| `business_location_id` | `unsignedBigInteger()` | **UNIQUE**; part of composite FK C-4 |
| `provider_account_resource_name` | `string(191)` | `accounts/{account_id}` (§8.4) |
| `provider_location_resource_name` | `string(191)` | `locations/{location_id}` — **UNIQUE platform-wide** |
| `bound_title_snapshot` | `string(191)->nullable()` | bind-time Google `title` (§11.2.1) |
| `bound_locality_snapshot` | `string(120)->nullable()` | bind-time `storefrontAddress.locality` **only** (§11.2.1) |
| `bound_region_code_snapshot` | `char(2)->nullable()` | bind-time `storefrontAddress.regionCode` |
| `verification_state` | `string(32)->nullable()` | derived enum (§21.3) |
| `has_voice_of_merchant` | `boolean()->nullable()` | |
| `has_pending_edits` | `boolean()->nullable()` | |
| `open_status` | `string(32)->nullable()` | `OpenInfo.status` verbatim |
| `duplicate_of_resource_name` | `string(191)->nullable()` | `metadata.duplicateLocation` |
| `profile_mirror` | `json()->nullable()` | bounded (§21.2) |
| `mirror_fetched_at` | `timestamp()->nullable()` | |
| `mirror_expires_at` | `timestamp()->nullable()` | **always ≤ `mirror_fetched_at` + 30 days** (§13) |
| `last_synced_at` | `timestamp()->nullable()` | completion of the last successful sync |
| `bound_by_user_id` | `foreignId()->nullable()->constrained('users')->nullOnDelete()` | |
| `created_at` / `updated_at` | `timestamps()` | |

**11.2.1 Why the bind-time snapshot is three narrow fields and never an
address.** Google's API policy permits storing "limited amounts of Content
only to improve the performance of your project", stored "temporarily for no
more than 30 calendar days". A bind-time snapshot that outlived the mirror
would breach that. The three fields kept are the minimum needed to answer
"what did the user actually pick?" when a listing is later renamed or
becomes a duplicate, and they are themselves purged by §13.2. **A street
address is never snapshotted, for any location, regardless of
`public_address`** (§23).

**No `etag` or provider-version column is contracted.** No etag, `If-Match`
or optimistic-concurrency token is documented for the v1 read methods Slice
A uses, and Slice A never writes. Adding a speculative column would be
inventing a provider fact.

### 11.3 `business_google_operations`

| Column | Type | Notes |
|---|---|---|
| `id` | `id()` | |
| `uid` | `uuid()->unique()` | `Str::uuid()` |
| `business_id` | `unsignedBigInteger()` **NOT NULL** | **Deliberately denormalized, deliberately no FK** — §11.3.1 |
| `business_google_location_id` | `unsignedBigInteger()->nullable()` | `nullOnDelete()` |
| `operation_type` | `string(40)` | `GoogleOperationType` cast (§11.3.2) |
| `local_operation_key` | `string(191)` | **UNIQUE**; generated *before* any provider call |
| `request_fingerprint` | `string(64)->nullable()` | SHA-256 of the canonicalized request shape: method + resource name + read-mask field list. **Never of anything containing Content** |
| `provider_operation_reference` | `string(191)->nullable()` | **UNIQUE when populated**; only ever a provider-supplied request or operation id, if one is present |
| `status` | `string(16)` default `'pending'` | `GoogleOperationStatus` (§11.3.3) |
| `actor_user_id` | `foreignId()->nullable()->constrained('users')->nullOnDelete()` | null for scheduled jobs |
| `summary` | `string(255)->nullable()` | bounded and safe (§11.3.4) |
| `failure_classification` | `string(32)->nullable()` | same closed set as §11.1.2 |
| `started_at` | `timestamp()->nullable()` | |
| `completed_at` | `timestamp()->nullable()` | |
| `created_at` / `updated_at` | `timestamps()` | |

**11.3.1 Why `business_id` is denormalized, NOT NULL, and has no foreign
key.** Disconnect deletes the connection and cascades the binding. If the
ledger reached the Business only through the connection, disconnect would
erase the audit trail of the disconnect itself. `business_id` is written
directly and carries **no** foreign key to `businesses`, so it is unaffected
by that cascade; it is removed only by §13.6's own rule or by the
`businesses` row itself being removed under §33.7.

**11.3.2 `GoogleOperationType` — closed set:** `connect_initiated`,
`connect_completed`, `connect_failed`, `token_refreshed`, `disconnected`,
`accounts_enumerated`, `locations_enumerated`, `location_bound`,
`location_unbound`, `mirror_refreshed`, `mirror_purged`.

**11.3.3 `GoogleOperationStatus` — closed set:** `pending`, `succeeded`,
`failed`, `unknown`, `deferred`. `unknown` is written when a provider call
times out or the connection drops after the request was sent (§24.6).
`deferred` is written on HTTP 429 / `RESOURCE_EXHAUSTED` — a rate-limited
call is **not** a failure (§24.5).

**11.3.4 `summary` is a safe, bounded string in our own vocabulary.**
Permitted: the operation-type label, the count of accounts or candidates
enumerated, the provider location resource name, and the list of *field
names* compared or refreshed. **Forbidden: any token, authorization code,
raw state, raw provider request or response, raw provider error text, any
Google Content value (title, description, phone, category, attribute, review
text), any street address, and any reviewer PII.**

---

## 12. CONSTRAINTS AND INDEXES — LOCKED

| # | Constraint | Table | Enforced how |
|---|---|---|---|
| C-1 | `business_id` UNIQUE | `business_google_connections` | `$table->unique('business_id')` — one connection row per Business |
| C-2 | `business_location_id` UNIQUE | `business_google_locations` | `$table->unique('business_location_id')` |
| C-3 | `provider_location_resource_name` UNIQUE **platform-wide** | `business_google_locations` | `$table->unique('provider_location_resource_name', 'bgl_provider_location_unique')` — no tenant qualifier, so no two Businesses can claim one Google listing |
| C-4 | A binding's Business must equal its bound location's Business | `business_google_locations` | composite FK `(business_location_id, business_id)` → `business_locations (id, business_id)`, requiring a supporting unique index on `business_locations (id, business_id)` added by the same migration. Mirrors `bfa_wallet_business_foreign` in `2026_08_16_140003` |
| C-5 | A binding's connection must belong to the same Business | `business_google_locations` | composite FK `(business_google_connection_id, business_id)` → `business_google_connections (id, business_id)`, requiring a supporting unique index on `business_google_connections (id, business_id)` |
| C-6 | `local_operation_key` UNIQUE | `business_google_operations` | `$table->unique('local_operation_key')` |
| C-7 | `provider_operation_reference` UNIQUE | `business_google_operations` | `$table->unique('provider_operation_reference', 'bgo_provider_reference_unique')` — nullable, so uniqueness applies only once populated, exactly like `bfa_provider_session_or_intent_reference_unique` |
| C-8 | `mirror_expires_at <= mirror_fetched_at + 30 days` | `business_google_locations` | **Application invariant**, asserted by T-RET-1. Not a CHECK constraint: this repository's migrations use none, and MySQL support is version-dependent |

Indexes: `business_google_connections (state)`;
`business_google_locations (business_id)`,
`(business_google_connection_id)`, `(mirror_expires_at)` for the purge
sweep, `(last_synced_at)` for staggering; `business_google_operations
(business_id, created_at)`, `(status, created_at)`,
`(business_google_location_id)`.

Deletion conventions follow `business_locations`
(`foreignId(...)->constrained(...)->onDelete('cascade')` on the owning
tenant edge) and `business_funding_attempts` (`nullOnDelete()` for actor
references). Because C-4 and C-5 are composite FKs, the cascade from
`businesses` reaches `business_google_locations` through
`business_google_connections`; the migration must additionally declare
`business_google_locations.business_location_id` behaviour as part of C-4 so
that deleting a `BusinessLocation` deletes its binding rather than orphaning
it.

---

## 13. RETENTION AND DELETION — MANDATORY CORRECTION

This section carries the single largest correction produced by the official
verification pass. Google's API policy states, verbatim:

> "You cannot pre-fetch, cache, index, or store any content provided through
> the Business Profile APIs ("Content") for use outside of your Business
> Profile project except for limited amounts of Content. You can store
> limited amounts of Content only to improve the performance of your
> project. Stored Content must meet the following requirements: • It must be
> stored temporarily for **no more than 30 calendar days**. • It must be
> stored securely. • **It cannot be manipulated or aggregated in any way.**
> At no time may you store Content in order to prevent Google from tracking
> usage of your Business Profile project."

### 13.1 Mirror TTL — hard ceiling of 30 calendar days

`mirror_expires_at` is written on every mirror write as:

```text
mirror_expires_at = mirror_fetched_at + min(configured_days, 30) days
```

**No code path may produce a `mirror_expires_at` more than 30 calendar days
after `mirror_fetched_at`.** T-RET-1 asserts this directly against the
database.

### 13.2 Purge job

`App\Jobs\GoogleBusinessProfile\PurgeExpiredGoogleBusinessProfileMirrors`,
extending `App\Jobs\Base`, scheduled `->hourly()` in `App\Console\Kernel`,
mirroring `PurgeExpiredWebhookPayloads`. For every
`business_google_locations` row with `mirror_expires_at <= now()` it nulls,
in one update: `profile_mirror`, `bound_title_snapshot`,
`bound_locality_snapshot`, `bound_region_code_snapshot`,
`duplicate_of_resource_name`, `mirror_fetched_at` and `mirror_expires_at`,
and writes one `mirror_purged` ledger row.

It does **not** null `provider_account_resource_name`,
`provider_location_resource_name`, `business_location_id` or the
verification/state booleans — see §13.3 and §13.7.

### 13.3 The comparison is computed at read time and never persisted

There is no comparison table, no comparison column and no cached comparison.
`GoogleBusinessProfileComparator` produces it on each render from (a) live
platform models and (b) a non-expired mirror. A persisted comparison would
be a derived artefact of Content and would sit uneasily with "cannot be
manipulated or aggregated in any way"; it is also unnecessary.

**If the mirror is absent or expired, every row's Google column renders
`Not comparable` with the reason "refresh required".** It never falls back
to a stale mirror and never silently shows an old value.

### 13.4 Retention configuration — fail closed, in the correct direction

New `config/google_business_profile.php`:

```php
'mirror' => [
    'retention_days' => env('GOOGLE_BUSINESS_PROFILE_MIRROR_RETENTION_DAYS'),
],
```

Resolution mirrors
`PurgeExpiredWebhookPayloads::resolvedRetentionDays()`'s validation idiom —
an int, or a digit-only string; nothing else is trusted — but
**deliberately inverts its fail-closed direction, and the implementation
docblock must say so.** For webhook payloads, failing closed means *do not
purge*, because purging destroys evidence. For Google Content the policy
risk runs the other way: keeping Content too long breaches Google's terms.
Therefore:

| Configured value | Effective TTL |
|---|---|
| Absent, blank, non-digit, `0`, negative | **0 days** — every mirror is treated as expired on read and purged on the next sweep |
| `1`–`30` | that many days |
| `> 30` | **0 days** — a value above the policy ceiling is a misconfiguration, not a request. It fails closed; it is **not** clamped |

**A 0-day effective TTL leaves the product usable — and the implementation
must actually make that true (correction item 8).** Storing an
already-expired mirror and then REDIRECTING does not: the redirected request
can never render what was fetched, so the documented default would leave the
feature unusable. The corrected behaviour is:

* **Nothing reusable is persisted by ANY path** — refresh *and* bind.
  `profile_mirror`, the three bind-time snapshots,
  `duplicate_of_resource_name`, `mirror_fetched_at` and `mirror_expires_at`
  are all left null; only non-Content operational state
  (`verification_state`, `has_voice_of_merchant`, `has_pending_edits`,
  `open_status`, `last_synced_at`) is written — exactly what §13.2's purge
  itself preserves.
* **The manual refresh RESPONSE renders the freshly fetched comparison
  ephemerally**, in that same response, from the in-memory result. It does
  not redirect.
* **The following request correctly reports "refresh required"**, because
  nothing was stored.
* The purge and read-time rules are unchanged.
* **No session flash, cache, log, queue payload or ledger field carries the
  Google Content** — rendering directly rather than redirecting is precisely
  what keeps it out of the session.

With a positive TTL the manual refresh still redirects and the mirror is
rendered normally on the next request.

### 13.5 Disconnect destroys authorization material

Disconnect, in one transaction:

1. Sets `state = disconnected`, `disconnected_at = now()`, increments
   `lock_version`.
2. Nulls `refresh_token_encrypted`, `granted_scopes` and
   `google_account_email`.
3. Deletes every `business_google_locations` row for that connection —
   guaranteed by the FK cascade, and performed explicitly in the service so
   it is directly testable.
4. Writes one `disconnected` ledger row, which survives because
   `business_id` is denormalized (§11.3.1).

The stored refresh token is **not** presented to Google for revocation in
Slice A, because that is an outbound mutation against the authorization
server and the Slice A provider interface exposes no such method. The UI
states plainly that the customer may also revoke access in their Google
Account settings. *Recorded for Slice B: adding a token-revocation call is a
deliberate, separately contracted addition.*

### 13.6 Ledger retention

`business_google_operations` rows are retained for
`config('google_business_profile.ledger.retention_days')`, resolved with the
same validation idiom but with two differences: absent or invalid means
**retain** (the ledger contains no Google Content, so the webhook-payload
direction applies), and rows with status `failed` or `unknown` are **never**
auto-deleted. **No ledger purge job is scheduled in Slice A**; the rule is
recorded so that a later operations pass has an unambiguous target.

### 13.7 Provider identifiers — conservative treatment plus an open legal gate

`provider_account_resource_name` and `provider_location_resource_name` are
treated as **operational binding metadata**, not as a Google-content
archive:

* Retained only while the binding and its connection exist.
* Deleted when the binding is unbound or the connection disconnected
  (§13.5), with no separate archive copy anywhere.
* Never used to reconstruct, index or aggregate Google Content.
* The only exception is the ledger's own `summary`, which may name a
  resource identifier for audit purposes, bounded by §13.6.

**Open gate G-LEGAL-1 — must be closed before production implementation
approval.** Google's caching clause contains no explicit carve-out for
operational identifiers. A careful reading supports retention: the same
policy's Authorized Use section contemplates ongoing management of listings,
which is impossible without a durable binding. But the official
documentation does not say so, and **this contract does not convert that
silence into a permission.** An operator/legal confirmation that retaining
`accounts/{id}` and `locations/{id}` for the active binding lifecycle
complies with Google's caching policy is required (§34.1).

**If legal review requires shorter retention, only retention behaviour may
tighten.** Table ownership, cardinality and the constraints in §12 do not
change — the binding row would simply be re-established more often. No
schema redesign is authorized by that outcome.

### 13.8 What is never stored, anywhere

No durable history of Google-sourced categories, attributes, profile values,
ratings, reviews, review replies, reviewer PII, photos, media URLs or
performance metrics. No change log of Google values. No aggregation of
Google Content of any kind, in any table, including B5's (§37.3).

---

## 14. PROVIDER INTERFACE — STRUCTURALLY READ-ONLY

### 14.1 The contract

Proposed:
`App\Library\GoogleBusinessProfile\Contracts\GoogleBusinessProfileReadClient`

It exposes **exactly these seven methods and no others**:

```php
public function authorizationUrl(string $signedState, bool $forceConsent): string;
public function exchangeAuthorizationCode(string $code): GoogleTokenGrant;
public function exchangeRefreshToken(string $refreshToken): GoogleAccessGrant;
public function listAccounts(string $accessToken): array;                                  // GoogleAccountSummary[]
public function listLocations(string $accessToken, string $accountResourceName, array $readMask): array; // GoogleLocationCandidate[]
public function getLocation(string $accessToken, string $locationResourceName, array $readMask): GoogleLocationProfile;
public function getVoiceOfMerchantState(string $accessToken, string $locationResourceName): GoogleVoiceOfMerchantState;
```

### 14.2 Methods that must not exist

The interface, the real client and the Fake must all be free of: any
`patch`, `update`, `create`, `delete`, `set`, `publish`, `reply`, `upload`
or `verify` method; any category, attribute, hours, address, website,
service, review, review-reply, local-post, media, verification-initiation,
notification-setting or Ads operation; and any method that issues an HTTP
verb other than `GET` against a `googleapis.com` host. The two `POST`s the
client does issue are the OAuth token endpoints, which are not Business
Profile resources.

**T-PROV-1** asserts this by reflection over the interface and both
implementations, matched against a forbidden-verb list, so a future
"harmless" addition fails the suite rather than silently widening Slice A.

### 14.3 Implementations

| Class | Purpose |
|---|---|
| `App\Library\GoogleBusinessProfile\HttpGoogleBusinessProfileReadClient` | Real client, bound in `AppServiceProvider` |
| `App\Library\GoogleBusinessProfile\FakeGoogleBusinessProfileReadClient` | Deterministic in-memory double, mirroring `FakePaymentProviderGateway` / `FakeAgencyProspectingAiClient` |

Tests swap it exactly as the existing suites do:

```php
$this->fake = new FakeGoogleBusinessProfileReadClient();
app()->instance(GoogleBusinessProfileReadClient::class, $this->fake);
```

The Fake must be able to produce, on demand: multiple accounts across
several pages; multiple locations across several pages; a location whose
response contains a `storefrontAddress` even though the read mask omitted
it (to prove §23.4); an `invalid_grant` refresh failure; an HTTP 429; and a
timeout.

### 14.4 Transport rules

* **No network call inside a database transaction, ever.** Every provider
  call happens outside `DB::transaction()`; the transaction opens only to
  persist the already-fetched result. T-SYNC-3 asserts this.
* Explicit connect and request timeouts, from
  `config('google_business_profile.http')`.
* No redirect following to a non-`googleapis.com` host.
* **The client never fetches a URL supplied by a user, by a Google
  response, or by any database row.** Every endpoint it contacts is built
  from a compile-time constant host plus a validated resource name matching
  `^accounts/[A-Za-z0-9_-]+$` or `^locations/[A-Za-z0-9_-]+$`.

### 14.5 Google content is untrusted input

Every response passes through DTO construction (§20.3) which:

* schema-validates — unknown keys dropped, missing keys become null;
* type-validates — a non-string where a string is expected becomes null,
  never a cast;
* length-caps every scalar to its column width before persistence;
* applies a **URL scheme allowlist** of `https` only to `mapsUri`,
  `newReviewUri` and `websiteUri`; anything else becomes null;
* is escaped on render — `{{ }}` only. **`{!! !!}` is forbidden in every
  GBP Blade view** (T-XSS-2 greps for it);
* is never stored as a raw provider payload (§11.3.4).

---

## 15. TENANCY AND AUTHORIZATION — LOCKED

### 15.1 The mandatory chain

Every GBP action, without exception, runs this chain, mirroring
`Business\AutomationsController::resolveEntitledBusiness()` verbatim:

1. `WorkspaceRepository::findByUid($workspaceUid)`; null or
   `! $workspace->is_active` → `abort(404)`.
2. `WorkspaceRepository::businessesForWorkspace($workspace)->firstWhere('uid', $businessUid)`;
   null → `abort(404)`.
3. `WorkspaceManager::userCanAccessBusiness((int) Auth::id(), $business)`
   false → `abort(404)`.
4. `$business->status !== BusinessStatus::Active` → `abort(404)`.
5. `EntitlementManager::decide($workspace, $business, PlatformFeature::GoogleBusinessProfileModule->value, (int) Auth::id())`;
   `WorkspaceBusinessNotFoundException` or
   `BusinessWorkspaceMismatchException` → `abort(404)`;
   `! $decision->allowed` → `abort(404)`.
6. The permission gate for the action (§16).
7. Any connection or binding is resolved **through** the already-resolved
   Business — never by uid alone.

### 15.2 Non-negotiables

* **`abort(404)`, never 403,** for every tenant mismatch, inaccessible
  resource, foreign connection, foreign binding, malformed state and
  cross-Business IDOR. A foreign resource must be indistinguishable from a
  nonexistent one.
* **No `LegacyBusinessResolver`.** It is not used and must not be
  introduced.
* **No primary-Business inference.** `businesses.is_primary` is never
  consulted to choose a Business.
* **`Auth::id()` is never tenancy.** It appears only as the
  capability-check subject and the audit actor — exactly as the B4
  controller's own docblock states.
* **`business.customer_id === Auth::id()` is never an authorization test.**
  `userCanAccessBusiness()` already covers direct ownership, Workspace
  ownership, `All`-scope membership and `Selected`-scope assignment.

### 15.3 Resolving a connection and a binding

```text
resolveConnection(Business $b): ?BusinessGoogleConnection
    -> BusinessGoogleConnection::query()->where('business_id', $b->id)->first()

resolveBinding(Business $b, string $bindingUid): BusinessGoogleLocation
    -> BusinessGoogleLocation::query()
         ->where('business_id', $b->id)
         ->where('uid', $bindingUid)
         ->first()  ... abort_unless(!== null, 404)
```

Neither is ever looked up by uid alone, and neither uses implicit route
model binding — deliberately, exactly as B4 resolves `{automationUid}`
inside the Business.

---

## 16. PERMISSIONS — LOCKED

### 16.1 The two new keys

Added to `config/customer-permissions.php`, in the existing shape:

```php
//google business profile
'view_google_business_profile'   => [
    'display_name' => 'read_google_business_profile',
    'category'     => 'Google Business Profile',
    'default'      => true,
],
'manage_google_business_profile' => [
    'display_name' => 'manage_google_business_profile',
    'category'     => 'Google Business Profile',
    'default'      => false,
],
```

Gates are defined automatically by
`AuthServiceProvider::boot()` (`app/Providers/AuthServiceProvider.php:54-58`),
which iterates `config('customer-permissions')`. No provider edit is needed.

### 16.2 Defaults, and why

* `view_google_business_profile` → **`default => true`**, following the
  existing safe view-capability precedent (`view_reports`, `view_contact`,
  `view_numbers`, `view_sender_id`, `view_blacklist` are all `true`).
  Reading your own Business's connection status is a view capability.
* `manage_google_business_profile` → **`default => false`**. There is no
  existing administrator/owner precedent in this repository that would
  require granting a management capability by default — permissions are
  per-customer JSON, not role-derived — so the conservative default stands.
  A Workspace owner who needs it grants it explicitly through the existing
  permission UI.

### 16.3 Which permission each action needs

| Action | Permission |
|---|---|
| Bare `/gbp` entry, overview, comparison view, settings view | `view_google_business_profile` |
| Connect, callback, disconnect, candidate enumeration, bind, unbind, manual refresh | `manage_google_business_profile` |

Candidate enumeration requires **manage**, not view: it issues real
provider calls against the customer's Google account and consumes project
quota.

### 16.4 No reuse, and one deferral

**No reuse** of `view_reports` (B5 owns it), `automations`, `view_numbers`,
`chat_box`, or any SEO, Ads, messaging or generic settings permission.

**Recorded as deferred, and explicitly NOT added in Slice A:** a third
capability, provisionally `publish_google_business_profile`, separating
"manage our connection to Google" from "write to our public Google
listing". Slice A writes nothing, so adding it now would create a dead
capability. It is recorded here so Slice B does not silently overload
`manage_google_business_profile`.

### 16.5 The backfill requirement — mechanically necessary

`EloquentAccountRepository::hasPermission()` reads the customer's stored
JSON list (`customers.permissions`), written once at creation from
`Customer::customerPermissions()`. **Adding a key to the config does not
grant it to any existing customer.** Without a backfill, every existing
customer on Growth or Agency would see the navigation entry (or not) and
then be refused by the gate.

Migration 6 (§33) therefore performs an idempotent data backfill that adds
**only** `view_google_business_profile` to (a) the `AppConfig` row
`setting = 'customer_permissions'` when it exists, and (b) each existing
`customers.permissions` JSON list that does not already contain it.
`manage_google_business_profile` is **never** backfilled — it defaults
false and must be granted deliberately.

---

## 17. ROUTES — LOCKED

Registered in `routes/customer.php`, inheriting
`['web','auth','can:access_backend','ValidProduct','twofactor']`, namespace
`App\Http\Controllers\Customer`, name prefix `customer.`.

### 17.1 Bare entry route

Placed beside the existing `channels` / `prospecting` entry routes:

```php
Route::get('gbp', 'Business\GoogleBusinessProfileController@entry')->name('gbp.index');
```

`entry()` mirrors `MessagingChannelsController::entry()` exactly:

* **zero** accessible Businesses → render the entry view with an empty list;
* **exactly one** → `redirect()->route('customer.workspaces.businesses.gbp.index', [$workspace->uid, $business->uid])`;
* **more than one** → render the chooser.

**It never infers a primary Business.** "Accessible" means the Business
passed the full §15 chain, entitlement included, so a Core-tier Business
never appears in the chooser.

### 17.1b The ONE fixed OAuth callback — CORRECTED (correction item 1)

```php
Route::get('gbp/oauth/callback', 'Business\GoogleBusinessProfileController@callback')
    ->middleware('throttle:20,1')
    ->name('gbp.oauth.callback');
```

**This supersedes the tenant-nested callback this contract originally
specified.** That route was `/{workspaceUid}/businesses/{businessUid}/gbp/callback`,
which cannot work: Google matches `redirect_uri` **exactly** against the
OAuth client's registered authorized redirect URIs, and there is exactly
one configured `GOOGLE_BUSINESS_PROFILE_REDIRECT`. Arbitrary Workspace and
Business path segments can never be registered as one reusable callback, so
every tenant would have needed its own registered URI.

The corrected callback therefore carries **no Workspace and no Business URL
parameter at all**. It is still registered inside the authenticated
customer route file and still inherits
`['web','auth','can:access_backend','ValidProduct','twofactor']`.

`GOOGLE_BUSINESS_PROFILE_REDIRECT` **must equal this URL exactly**, and
`GoogleBusinessProfileOAuthConfig` refuses to start a flow unless it does
(§28.4).

### 17.2 Business-scoped group

Inside the existing `Route::prefix('workspaces')->name('workspaces.')`
group, placed immediately after the B4 automations group:

```php
Route::prefix('{workspaceUid}/businesses/{businessUid}/gbp')->name('businesses.gbp.')->group(function () {
    Route::get('/',            'Business\GoogleBusinessProfileController@overview')->name('index');
    Route::get('/comparison',  'Business\GoogleBusinessProfileController@comparison')->name('comparison');
    Route::get('/settings',    'Business\GoogleBusinessProfileController@settings')->name('settings');
    Route::post('/connect',    'Business\GoogleBusinessProfileController@connect')->middleware('throttle:10,1')->name('connect');
    Route::get('/locations',   'Business\GoogleBusinessProfileController@candidates')->middleware('throttle:20,1')->name('locations');
    Route::post('/bind',       'Business\GoogleBusinessProfileController@bind')->middleware('throttle:20,1')->name('bind');
    Route::post('/unbind',     'Business\GoogleBusinessProfileController@unbind')->name('unbind');
    Route::post('/disconnect', 'Business\GoogleBusinessProfileController@disconnect')->name('disconnect');
    Route::post('/refresh',    'Business\GoogleBusinessProfileController@refresh')->middleware('throttle:10,1')->name('refresh');
});
```

Resulting names: `customer.workspaces.businesses.gbp.{index,comparison,settings,connect,locations,bind,unbind,disconnect,refresh}` — **nine** Business-scoped routes, plus the bare `customer.gbp.index` chooser and the one fixed `customer.gbp.oauth.callback`.

**Two corrections to this contract's original route table:**

* **`connect` is a POST, not a GET (correction item 9).** It mutates
  connection state, the OAuth nonce, actor attribution and the ledger, so it
  takes normal CSRF protection. A plain navigation GET must never be able to
  create or alter OAuth state. Every Connect and Reconnect control in the UI
  is a `@csrf` form, not a link.
* **`callback` is no longer in this group** — see §17.1b. It is one fixed,
  tenant-free route, because Google matches the registered `redirect_uri`
  exactly.

`comparison` and `settings` are stated explicitly here; the original table
omitted their routes even though §25.6 and §25.8 require both surfaces.

### 17.3 Route rules

* The callback is **inside the authenticated customer route file** (though
  no longer inside the Business-scoped group — §17.1b). It is not in
  `routes/auth.php`, not in `routes/web.php`, and not in `routes/public.php`.
* No route accepts a `redirect_to`, `return`, `next`, `continue` or `url`
  parameter. **There is no open redirect anywhere in GBP** (T-SEC-12).
* Every redirect target is built with `route()` from the two already-
  resolved uids.
* No implicit route model binding for a connection or binding; both are
  resolved inside the Business (§15.3).
* Throttle values are inline route middleware, matching the existing house
  usage at `routes/customer.php:547-548,598`. Rationale: connect and refresh
  consume Google project quota, so 10/minute; callback and bind are cheap
  but security-sensitive, so 20/minute limits brute-forcing of state values
  while leaving a legitimate retry comfortable.
* Navigation visibility is **never** an authorization mechanism (T-NAV-1
  posts directly to every route with navigation hidden).

---

## 18. CONTROLLERS AND REQUESTS — LOCKED

### 18.1 Controller

`App\Http\Controllers\Customer\Business\GoogleBusinessProfileController`,
extending `App\Http\Controllers\Customer\CustomerBaseController`, in the
same namespace as `AutomationsController` and `MessagingChannelsController`.

| Method | Route | Permission | Behaviour |
|---|---|---|---|
| `entry()` | `customer.gbp.index` | view | 0/1/many chooser (§17.1) |
| `overview(string $workspaceUid, string $businessUid)` | `.index` | view | Connection state, binding state, comparison (§25) |
| `connect(...)` | `.connect` | manage | §9.3, then `redirect()->away($authorizationUrl)` |
| `callback(...)` | `.callback` | manage | §9.5 |
| `disconnect(...)` | `.disconnect` | manage | §13.5 |
| `candidates(...)` | `.locations` | manage | Request-scoped enumeration (§8.3) |
| `bind(GoogleBusinessProfileBindRequest $request, ...)` | `.bind` | manage | §8.5, §19.2 |
| `unbind(...)` | `.unbind` | manage | Delete the binding row + ledger row |
| `refresh(...)` | `.refresh` | manage | Dispatch or run a bounded mirror refresh (§24.1) |

**The listing method is named `overview()`, not `index()`** — for the same
reason B4 names its listing `listing()`: `CustomerBaseController::index()`
takes zero parameters, so an `index(string, string)` override is a fatal LSP
error.

Each mutating action carries the same demo guard as B4
(`config('app.stage') !== 'demo'` → proceed; otherwise redirect back with an
error), so demo installations cannot initiate a real OAuth flow.

### 18.2 FormRequests

`App\Http\Requests\GoogleBusinessProfile\GoogleBusinessProfileBindRequest`
— **CORRECTED (correction item 5)**

```php
'candidate_token'       => ['required','string','max:1024'],
'business_location_uid' => ['required','string','max:64'],
```

**The request no longer accepts provider resource names at all.** Regex
validation proved only that a string was well shaped, and a successful
`locations.get` proved only that the grant could read *some* location —
neither proved the account/location pair had been offered to this actor for
this connection.

Instead, the candidates surface issues a short-lived HMAC **candidate
token** per rendered pair, and the controller derives BOTH provider resource
names from the verified token. There is deliberately **no parallel raw
field** to trust, so pair substitution is impossible by construction.

Token contents, all covered by the signature:

| Field | Meaning |
|---|---|
| `b` | Business id |
| `c` | connection id |
| `u` | the actor the candidate was shown to |
| `a` | account resource name |
| `l` | location resource name |
| `e` | expiry (unix seconds; 15 minutes) |

Rejected **before any provider read and before any write**: a tampered
token, an expired token, a token issued to another actor, for another
Business, for another connection, or naming a substituted pair.

**No candidate row is persisted and no fourth GBP table is added** — the
proof travels in the form and is verified on the way back (§8.3, §12).

`App\Http\Requests\GoogleBusinessProfile\GoogleBusinessProfileUnbindRequest`

```php
'binding_uid' => ['required','string','max:64'],
```

`authorize()` returns `true` in both; **authorization is the controller's
§15 chain**, because a FormRequest cannot see the resolved Business. The
`business_location_uid` is resolved **inside** the already-resolved Business
in the controller, and a foreign or unknown uid is a 404.

Both requests validate *shape* only. **Validation never proves ownership** —
a syntactically valid `locations/{id}` the grant cannot actually read fails
at the provider call and is recorded as a `failed` operation, not as a
binding.

---

## 19. SERVICES, REPOSITORIES, JOBS, EVENTS — LOCKED

### 19.1 Services (`app/Library/GoogleBusinessProfile/`)

| Class | Single responsibility |
|---|---|
| `GoogleOAuthStateSigner` | Build, sign, verify, and atomically consume the §9.4 state. Owns nonce generation (`Str::uuid()`) and expiry |
| `GoogleBusinessProfileConnectionManager` | The §10.1 state machine: initiate, complete, refresh, revoke, disconnect. The **only** writer of `refresh_token_encrypted` |
| `GoogleBusinessProfileEnumerator` | Paged account and location enumeration, candidate ranking (§8.5). Returns DTOs; **persists nothing** |
| `GoogleBusinessProfileBindingManager` | Create and delete bindings; enforce C-2..C-5 in the service layer as well as the database |
| `GoogleBusinessProfileMirrorService` | Fetch, normalize, bound, and persist the mirror; compute `mirror_expires_at` (§13.1) |
| `GoogleBusinessProfileComparator` | Pure function: platform models + mirror → `GoogleComparisonRow[]`. **No I/O, no persistence** |
| `GoogleBusinessProfileReadMask` | The single source of the §20 read mask, including the §23 conditional omission of `storefrontAddress` |
| `GoogleBusinessProfileOperationLedger` | The **only** writer of `business_google_operations` |

`GoogleBusinessProfileComparator` being pure is a contract requirement, not
a style note: it is what makes §13.3 (never persisted) and the comparison
tests cheap and total.

### 19.2 Bind — exact sequence

1. §15 chain; `manage` permission.
2. Resolve the connection for this Business; state must be `active`, else
   redirect with an error (no provider call).
3. Resolve `business_location_uid` **inside** this Business, else 404.
4. Validate the two resource names' shape (§18.2).
5. **Outside any transaction**, call `getLocation()` with the §20 read mask
   to prove the grant can actually read the chosen location, and
   `getVoiceOfMerchantState()`.
6. Ledger row `location_bound` with `status = pending`, `local_operation_key`
   generated **before** step 5.
7. **Inside one transaction**, insert the `business_google_locations` row.
   A `provider_location_resource_name` unique-constraint violation (C-3) is
   caught and rendered as "this Google location is already connected to
   another business profile on this platform" — **never** as a 500, and
   never revealing which Business holds it.
8. Update the ledger row to `succeeded`.
9. Dispatch `GoogleBusinessProfileLocationBound` (after commit).

### 19.3 Repositories

`App\Repositories\Contracts\{BusinessGoogleConnectionRepository,
BusinessGoogleLocationRepository, BusinessGoogleOperationRepository}` with
`App\Repositories\Eloquent\Eloquent*` implementations, bound in
`AppServiceProvider`'s existing binding array. Every finder takes a
`Business` or a `business_id` — **there is no `findByUid($uid)` without a
Business** anywhere in these repositories.

### 19.4 Jobs (`app/Jobs/GoogleBusinessProfile/`)

| Job | Schedule | Notes |
|---|---|---|
| `RefreshGoogleBusinessProfileMirror` | Dispatched by the daily sweep and by manual refresh | Per binding. Extends `App\Jobs\Base` |
| `PurgeExpiredGoogleBusinessProfileMirrors` | `->hourly()` | §13.2 |
| `SweepGoogleBusinessProfileRefreshes` | `->daily()` | Selects due bindings and dispatches `RefreshGoogleBusinessProfileMirror` with a per-binding stagger delay (§24.2) |

Every job re-fetches the `Business` and re-runs the §15 entitlement and
access checks **before** any provider access (§24.7). `App\Jobs\Base` sets
`tries = 1` and `maxExceptions = 1`, so **no automatic provider retry
exists**; a retry is only ever a deliberate, ledger-checked re-dispatch.

### 19.5 Events (`app/Events/GoogleBusinessProfile/`)

House naming checked against `app/Events/Business/*` and
`app/Events/Usage/*`: `{Subject}{PastTenseVerb}`, `implements
ShouldDispatchAfterCommit`, `use Dispatchable`, readonly promoted scalars
only — never a model instance.

| Event | Constructor |
|---|---|
| `GoogleBusinessProfileConnected` | `int $businessId, int $connectionId, ?int $actorUserId` |
| `GoogleBusinessProfileDisconnected` | `int $businessId, int $connectionId, ?int $actorUserId` |
| `GoogleBusinessProfileConnectionRevoked` | `int $businessId, int $connectionId` |
| `GoogleBusinessProfileLocationBound` | `int $businessId, int $businessLocationId, int $bindingId, ?int $actorUserId` |
| `GoogleBusinessProfileLocationUnbound` | `int $businessId, int $businessLocationId, ?int $actorUserId` |
| `GoogleBusinessProfileMirrorRefreshed` | `int $businessId, int $bindingId` |

**No listener is registered in Slice A.** `EventServiceProvider::$listen` is
**not** modified. These events exist as a seam; adding a listener without a
purpose would be dead code.

---

## 20. READ MASKS AND DTO VALIDATION — LOCKED

### 20.1 The enumeration read mask

`accounts.locations.list` requires `readMask`. Slice A sends exactly:

```text
name,title,storeCode,phoneNumbers,websiteUri,categories,latlng,
openInfo,metadata,serviceArea,storefrontAddress
```

with `storefrontAddress` **conditionally removed** per §23.2. Nothing else
is requested: no `profile`, no `regularHours`, no `specialHours`, no
`moreHours`, no `serviceItems`, no `labels`, no `adWordsLocationExtensions`,
no `relationshipData`. A field not needed by §21 or §22 is not requested.

### 20.2 The bound-location read mask

`locations.get` uses the same mask. `getVoiceOfMerchantState` takes no mask.

### 20.3 DTOs (`app/DTO/GoogleBusinessProfile/`)

| DTO | Fields |
|---|---|
| `GoogleTokenGrant` | `refreshToken`, `accessToken`, `expiresInSeconds`, `grantedScopes` |
| `GoogleAccessGrant` | `accessToken`, `expiresInSeconds` |
| `GoogleAccountSummary` | `resourceName`, `accountName`, `type`, `role`, `verificationState` |
| `GoogleLocationCandidate` | `resourceName`, `accountResourceName`, `title`, `storeCode`, `localityHint`, `regionCode`, `matchScore` |
| `GoogleLocationProfile` | the §21.2 mirror fields |
| `GoogleVoiceOfMerchantState` | `hasVoiceOfMerchant`, `hasBusinessAuthority`, `hasPendingVerification`, `isWaitingForVoiceOfMerchant`, `hasOwnershipConflict`, `complianceReason` |
| `GoogleComparisonRow` | `field`, `platformValue`, `googleValue`, `status`, `reason` |

Every DTO is `final readonly`, constructed only through a static
`fromProviderArray(array $raw)` that applies §14.5. **A DTO never holds a
raw provider array**, and `GoogleLocationCandidate` deliberately carries
`localityHint` rather than an address (§23.3).

Rules that must not be paraphrased in the implementation:

* `title` capped at 191 characters before persistence.
* `localityHint` capped at 120.
* `regionCode` must match `^[A-Z]{2}$`, else null.
* `resourceName` must match `^locations/[A-Za-z0-9_-]+$`; `accountResourceName`
  `^accounts/[A-Za-z0-9_-]+$`. A non-matching value makes the whole candidate
  **discarded**, not repaired.
* `openInfo.status` must be one of the four documented values, else null.
* `websiteUri`, `mapsUri`, `newReviewUri` must parse as absolute `https`
  URLs, else null.
* Every array is capped: at most 100 candidates per page and at most 10
  Google categories retained (primary + up to 9 additional).

---

## 21. PROFILE MIRROR — LOCKED

### 21.1 What the mirror is for

The mirror exists **only** so that a comparison can be rendered without a
provider call on every page view. It is a performance cache in the exact
sense Google's policy permits, and it is bounded by §13.

### 21.2 Bounded mirror contents

`profile_mirror` is a JSON object with **exactly these keys and no others**:

```text
title                  string|null
phone_primary          string|null
website_uri            string|null
store_code             string|null
primary_category_id    string|null      // opaque "categories/{id}", never parsed
primary_category_name  string|null      // display name, display only
additional_category_ids   string[]      // max 9
additional_category_names string[]      // max 9
service_area_type      string|null      // ServiceAreaBusiness.businessType verbatim
service_area_place_count int|null       // count only, max 20
latitude               float|null
longitude              float|null
maps_uri               string|null
new_review_uri         string|null
locality               string|null      // §23.3 — locality ONLY, never a street address
region_code            string|null
```

**Deliberately absent:** `address_line_1`, any street-level address
component, `profile.description`, hours of any kind, attributes,
`serviceItems`, reviews, ratings, media, and metrics. A key not listed here
must not be written.

The scalar columns `verification_state`, `has_voice_of_merchant`,
`has_pending_edits`, `open_status` and `duplicate_of_resource_name` live
outside the JSON because they drive UI state and queries.

### 21.3 Derived `verification_state` — our own vocabulary, from two products

Google spreads this across `VoiceOfMerchantState` (Verifications v1) and
`Location.metadata` / `Location.openInfo` (Business Information v1).
**Slice A does not invent one unified Google enum**; it derives one small
*platform* enum, `App\Enums\GoogleBusinessProfile\GoogleLocationHealth`,
and always shows the underlying Google signals alongside it:

| Derived value | Derivation (first match wins) |
|---|---|
| `ownership_conflict` | `VoiceOfMerchantState.resolveOwnershipConflict` present |
| `suspended` | `complyWithGuidelines.recommendationReason === BUSINESS_LOCATION_SUSPENDED` |
| `disabled` | `complyWithGuidelines.recommendationReason === BUSINESS_LOCATION_DISABLED` |
| `duplicate` | `Location.metadata.duplicateLocation` non-empty |
| `verification_pending` | `VoiceOfMerchantState.verify.hasPendingVerification === true` |
| `awaiting_review` | `VoiceOfMerchantState.waitForVoiceOfMerchant` present |
| `verified` | `hasVoiceOfMerchant === true` |
| `unverified` | `hasVoiceOfMerchant === false` and none of the above |
| `unknown` | the state could not be read |

`has_voice_of_merchant`, `has_pending_edits`, `open_status` and
`duplicate_of_resource_name` remain individually visible. **No state is ever
auto-remediated**; Slice A initiates no verification and resolves no
duplicate.

### 21.4 Ownership of truth

| Data | Authoritative source | Slice A behaviour |
|---|---|---|
| Business name, phone, website, description, address and its visibility choice, service-area facts, platform services | **Platform** | Displayed as the platform column. **Never overwritten by Google** |
| Google identifiers, Google categories, Google attributes, verification and location state, Google-provided geo | **Google** | Mirrored read-only |
| Opening hours | **Neither** — absent from the platform | Comparison status `Not set on platform` (§22.6) |

**Sync must leave `businesses` and `business_locations` byte-identical.**
T-SYNC-1 and T-SYNC-2 assert this by full-row comparison before and after a
refresh. Slice A contains no write path to either table; this is enforced by
the absence of such code and proven by those tests.

---

## 22. COMPARISON RULES — LOCKED

### 22.1 Columns and statuses

Exactly four columns: **Field**, **Platform value**, **Google value**,
**Status**. Exactly five statuses, and no others:

`Match` · `Mismatch` · `Not set on platform` · `Not set on Google` ·
`Not comparable`

There is **no** score, percentage, grade, star rating, "profile strength",
recommendation engine, priority ordering by severity, or AI interpretation.
A *Proposed change* column does not exist and must not be added until a
mutation slice contracts one.

### 22.2 Status resolution — in this exact order

1. The field is on the not-comparable list (§22.4), or the mirror is absent
   or expired → **`Not comparable`** (with a short reason).
2. Platform value is null/empty **and** Google value is null/empty →
   **`Not set on platform`**. *Absence is never `Match`.*
3. Platform value is null/empty, Google value present → **`Not set on
   platform`**.
4. Platform value present, Google value null/empty → **`Not set on
   Google`**.
5. Normalized values equal → **`Match`**.
6. Otherwise → **`Mismatch`**.

**Rule 2 is the one implementers get wrong.** Two empties are *not*
agreement; they are two absences, and the row must say so.

### 22.3 Per-field normalization — exact

| Field | Platform source | Google source | Normalization |
|---|---|---|---|
| Business name | `businesses.name` | `title` | Trim; collapse internal whitespace; Unicode NFC; case-insensitive compare. No punctuation stripping, no legal-suffix stripping |
| Phone | `businesses.phone` | `phoneNumbers.primaryPhone` | Strip every character outside `0-9` and a single leading `+`; compare the result. **No region inference, no libphonenumber dependency** |
| Website | `businesses.website_url` | `websiteUri` | Lowercase scheme and host; strip a trailing `/`; strip a leading `www.`; **keep** path, query and fragment; compare exactly. `http` vs `https` is a **`Mismatch`**, not a match |
| Primary category | *(none)* | `categories.primaryCategory.displayName` | Always **`Not comparable`** (§22.5) |
| Additional categories | *(none)* | display names, up to 9 | Always **`Not comparable`** |
| Locality | `business_locations.city` | `storefrontAddress.locality` | Trim, NFC, case-insensitive. **Only ever compared when `public_address = true`** (§23) |
| Region code | `business_locations.country_code` | `storefrontAddress.regionCode` | Uppercase, exact |
| Street address | `business_locations.address_line_1` | `storefrontAddress.addressLines` | **`Not comparable` always** (§23.5) |
| Service model | `business_locations.service_mode` | `serviceArea.businessType` + presence of `storefrontAddress` | §22.4 |
| Service radius | `business_locations.service_radius_km` | *(no Google equivalent)* | Always **`Not comparable`** |
| Service-area cities | `business_locations.service_area_cities` | `serviceArea.places.placeInfos` | Always **`Not comparable`** |
| Opening hours | *(none)* | *(not requested)* | Always **`Not set on platform`** |
| Latitude / longitude | `business_locations.latitude/longitude` | `latlng` | Round both to 4 decimal places (~11 m) and compare; a difference beyond that is a `Mismatch`, not an error |
| Open status | *(none)* | `openInfo.status` | Display-only row, status **`Not comparable`** |

### 22.4 Service model — display, never a verdict

| Platform `service_mode` | Nearest Google shape | Comparison |
|---|---|---|
| `Storefront` | `storefrontAddress` present, no `serviceArea` | `Match` / `Mismatch` on that structural test only |
| `ServiceArea` | `serviceArea.businessType = CUSTOMER_LOCATION_ONLY` | `Match` / `Mismatch` on `businessType` only |
| `Hybrid` | `serviceArea.businessType = CUSTOMER_AND_BUSINESS_LOCATION` | `Match` / `Mismatch` on `businessType` only |
| `Online` | *(no Google equivalent)* | **`Not comparable`** |

Service **areas themselves** are never compared. Google models them as up to
20 **region place IDs**; the platform models them as an integer radius plus
free-text city names. **There is no correct conversion**, Slice A takes no
Places API dependency, and inventing one would produce confident wrong
answers. The row reads `Not comparable`, with the reason "Google uses
regions; the platform uses a radius and city names".

### 22.5 Categories are never mapped

`App\Enums\Business\BusinessIndustry`'s seven platform-invented values are
**not** Google categories, and the official verification pass confirmed — as
a verified negative — that **Google publishes no mapping primitive** from
any external taxonomy. Google category identifiers are country- and
language-scoped and opaque.

Therefore Slice A **displays** Google's category display names beside
`businesses.industry` with status **`Not comparable`**, and never computes a
match or a mismatch between them. Building an automatic mapping is on the
stop-list (§31). T-CMP-4 asserts that no comparison row ever pairs
`BusinessIndustry` with a Google category as `Match` or `Mismatch`.

### 22.6 Hours

Hours are absent from the platform entirely. Slice A shows one row,
`Not set on platform`, and does not request `regularHours` in the read mask.
Adding per-location opening hours is a **Business Profile Foundation**
prerequisite for future *mutations* (§36.1.3). **It is not a reason to block or
delay Slice A**, which compares what exists.

---

## 23. PRIVATE-ADDRESS CONTROLS — BLOCKING INVARIANT

### 23.1 The invariant

> **No GBP surface may display, transmit, log, persist, compare, or send to
> Google a street address when `business_locations.public_address = false`.**

Google's own guidance agrees ("If you're a service-area business, you should
hide your business address from customers… clear the address from your
Business Profile"), but the invariant is a **platform** rule and does not
depend on Google's.

### 23.2 Enforcement point 1 — provider request construction

Because `accounts.locations.list` makes `readMask` **Required**, the
strongest control available is to never ask for the field.
`GoogleBusinessProfileReadMask` omits `storefrontAddress` from the read mask
whenever **any** of the following holds for the location being read:

* `public_address === false`; or
* `service_mode === BusinessServiceMode::ServiceArea`; or
* `service_mode === BusinessServiceMode::Online`; or
* `service_mode === BusinessServiceMode::Hybrid` **and**
  `public_address === false`.

For enumeration, where no `BusinessLocation` is yet chosen, the mask omits
`storefrontAddress` **unless every** `BusinessLocation` in the Business has
`public_address === true`. **The default when in doubt is omission.**

### 23.3 Enforcement point 2 — response normalization

If a response contains `storefrontAddress` despite the mask, the normalizer
**discards it before a DTO is constructed**. Only `locality` and
`regionCode` may survive, and only when §23.2 permitted the field at all.
`addressLines`, `sublocality`, `postalCode`, `sortingCode`, `organization`
and `recipients` are dropped unconditionally, in every case, for every
location. Whether Google redacts a suppressed address for an authorized
manager is not documented; **the safe assumption is that it does not**, and
this control makes the answer irrelevant.

### 23.4 Enforcement points 3–7

| # | Point | Rule |
|---|---|---|
| 3 | DTO construction | `GoogleLocationCandidate` and `GoogleLocationProfile` have **no street-address field to populate**. The invariant is structural, not conditional |
| 4 | Persistence | `business_google_locations` has **no street-address column**. `bound_locality_snapshot` is locality only, and is written only when §23.2 permitted the field |
| 5 | Comparison | The street-address row is **`Not comparable` in every case** (§23.5). The locality row is emitted only when `public_address = true`; otherwise it is emitted as `Not comparable` with the reason "address withheld by consent" — the value is never rendered, and no verdict is implied |
| 6 | Controller and view serialization | No GBP response body, JSON payload, view model, or session flash may contain `address_line_1`, `address_line_2` or `postal_code`. T-PRIV-3 asserts the response body never contains the fixture's `address_line_1` |
| 7 | Logs, exceptions, audit | The ledger `summary` and `failure_classification` are closed vocabularies (§11.3.2, §11.1.2). No address can reach either. No exception context may carry a DTO or a provider payload |

The check runs at **send time and at render time**, not only when the UI is
built: a `public_address` toggled to false between page load and refresh
must take effect on the very next provider call.

### 23.5 Street address is never compared, even when public

Even with `public_address = true`, the street-address row is
**`Not comparable`**. Slice A does not request `addressLines`, so it has no
Google value to compare; and address equality is a normalization problem
(abbreviations, ordering, unit designators) that produces confident wrong
answers. Locality and region code carry the useful signal without the risk.

### 23.6 The storefront contradiction is surfaced, never resolved

A location with `service_mode = Storefront` and `public_address = false` is
internally contradictory. Slice A **surfaces** it as an informational notice
on the comparison view and continues to apply §23.2 (omit the address). It
never silently publishes, never silently suppresses, and never edits either
field.

### 23.7 `isParticularlyPersonalPlace`

`Location.metadata.isParticularlyPersonalPlace` may be **displayed** as a
Google-provided signal. It must **never** be used to infer, override or
satisfy `public_address` — its semantics are undocumented ("Output only.").

---

## 24. SYNC, IDEMPOTENCY AND QUOTA BEHAVIOUR — LOCKED

### 24.1 Manual refresh is primary

The refresh button is the main mechanism. It runs one bounded refresh for
the Business's bindings, throttled to `throttle:10,1`, always through the
ledger, and always outside a transaction.

### 24.2 Background refresh — at most daily, staggered

`SweepGoogleBusinessProfileRefreshes`, scheduled `->daily()`, selects
bindings whose `last_synced_at` is null or older than
`config('google_business_profile.sync.min_interval_hours')` (default 24,
minimum 24 — **a configured value below 24 is rejected and 24 is used**),
and dispatches one `RefreshGoogleBusinessProfileMirror` per binding with a
deterministic per-binding delay:

```text
delay_seconds = (binding_id * 7) % 3600
```

so a large tenant's bindings spread across the hour rather than arriving as
a burst. **No higher-frequency polling of any kind is permitted.** Google
explicitly denies quota increases to applications showing "a highly spiky
request pattern rather than a smooth distribution", so staggering is a
quota-preservation requirement, not a nicety.

### 24.3 Chunking and concurrency

* The sweep processes bindings in chunks (`chunkById`, 100 rows), never
  loading all bindings into memory.
* **Per-connection concurrency is one.** A refresh acquires a lock keyed by
  `business_google_connection_id`; a second concurrent refresh for the same
  connection returns immediately without a provider call and without an
  error (T-SYNC-4 asserts exactly one provider call results from two
  simultaneous refreshes).
* **A per-Business budget caps OUTBOUND REQUESTS per Business per rolling
  hour — and it is ENFORCED, not merely configured (correction item 6).**
  `config('google_business_profile.sync.max_calls_per_business_per_hour')`,
  default 60, validated with the house idiom.

  **How it works.** `business_google_operations` carries a bounded
  `provider_call_count`. Before **every** outbound request the provider
  client calls `GoogleBusinessProfileCallBudget::reserve()`, which opens a
  short transaction, takes a row lock on the Business's connection row,
  recomputes the rolling one-hour total, refuses or increments the current
  operation's counter, and commits. **The network call then happens outside
  that transaction** (§24.9).

  **It counts ACTUAL REQUESTS, not operations** — every pagination page and
  every OAuth token exchange included. One refresh (token exchange +
  location read + VoiceOfMerchant read) consumes three.

  **When exhausted:** zero provider calls are made; the ledger records
  status `deferred` with the distinct classification `budget_exhausted`
  (Google did not throttle us — we throttled ourselves); `last_synced_at` is
  left unchanged so the next sweep retries; background work exits cleanly;
  the manual path shows a plain "hourly limit" message; and no secret or raw
  provider response is stored.

  **No Laravel Cache and no fourth GBP table** (§12, §31): an operation row
  already exists before every provider call, is Business-scoped,
  time-stamped, and indexed on `(business_id, created_at)` — exactly the
  rolling-window query this needs.

  **This does not replace the route throttles or the circuit breaker, and
  they do not replace it.** Route throttles bound one user on one route; the
  breaker reacts after the project is already under pressure; only this
  bounds what a single Business can consume.
* A **project-level circuit breaker** trips after N consecutive `deferred`
  (429) or `provider_unavailable` outcomes across all tenants
  (`config('google_business_profile.sync.breaker_threshold')`, default 20)
  and suppresses background refreshes for a cool-down window. Manual refresh
  during a tripped breaker returns a clear "temporarily unavailable"
  message. The 300 QPM ceiling is **per Cloud project and shared across
  every customer**, so a per-tenant limit alone cannot protect it.

The cron reality on shared hosting is
`queue:work --queue=automation,default,batch --timeout=120 --tries=1 --max-time=180 --stop-when-empty`
every minute, so all of the above must survive a worker that exits after
180 seconds. Chunking and per-binding jobs satisfy that; a single long
"refresh everything" job would not, and is forbidden.

### 24.4 Idempotency — mandatory, and not relieved by anything external

Google publishes no idempotency key for these APIs. The ledger is therefore
the whole mechanism:

1. Generate `local_operation_key` and insert the ledger row with
   `status = pending` **before** the provider call.
2. Make the call **outside** any transaction.
3. Record `succeeded` / `failed` / `deferred` / `unknown`, plus
   `provider_operation_reference` **only if the provider actually supplied
   one** — no synthetic value is ever invented.
4. Every retry is a **deliberate, ledger-checked re-dispatch**. `App\Jobs\Base`
   sets `tries = 1` and `maxExceptions = 1`, so no automatic retry exists to
   suppress.

### 24.5 Rate limiting

HTTP `429` / `RESOURCE_EXHAUSTED` → ledger `deferred`, connection
`failure_classification = rate_limited`, **not** `failed`. The binding's
`last_synced_at` is left unchanged so the next sweep naturally retries.
Backoff is **exponential with jitter**, as Google prescribes. Because Google
documents **no** rate-limit header and no `Retry-After` for these APIs, the
backoff is time-based only; **no header-driven backoff may be written**.

### 24.6 Timeout ambiguity → `unknown`

A call that times out, or whose connection drops after the request was sent,
is recorded as `unknown`. **An `unknown` operation is never blindly
replayed.** Since every Slice A operation is a `GET`, a subsequent read is
safe *after* the ledger and concurrency checks in §24.3 — but the safety
comes from those checks, not from an assumption that GET is harmless. The
`unknown` row is retained permanently (§13.6).

### 24.7 Jobs re-check entitlement and access

Before any provider access, every job re-fetches the `Business` by id and
re-evaluates: the Business still exists; `status === BusinessStatus::Active`;
its Workspace exists and `is_active`; and
`EntitlementManager::decide(...)->allowed` for
`google_business_profile_module`. Any failure ends the job quietly with a
ledger row and **no provider call**. A Business that is disabled,
downgraded from Growth/Agency to Core, made inactive, or deleted therefore
receives **no background refresh** (T-ENT-6).

### 24.8 Notification settings — never touched

Google's notification settings are **per Google account**
(`accounts/{account_id}/notificationSetting`), not per location and not per
Business. Writing one would mutate state shared with locations we were never
authorized for. **Slice A never reads and never writes notification
settings**, adds no Pub/Sub topic, no subscriber and no webhook endpoint
(T-NOTIF-1). Google documents no delivery guarantee for these
notifications, so they could not replace bounded refresh even if they were
in scope.

### 24.9 No network call inside a transaction

Absolute. Asserted by T-SYNC-3, which fails the test if a provider call is
observed while `DB::transactionLevel() > 0`. This mirrors the payments
docblock rule already established in this repository.

---

## 25. UI STATES — LOCKED

M2 primitives only. **No new frontend framework**, no chart, no new CSS
system, no new JS build step. Bootstrap 5.1 + jQuery + Laravel Mix, exactly
as the rest of the customer area.

Views live at `resources/views/customer/business/GoogleBusinessProfile/`,
matching `customer/business/MessagingChannels/`.

| # | State | View | Contents |
|---|---|---|---|
| 25.1 | Business chooser | `entry.blade.php` | 0 → "no eligible business" copy; 1 → redirect (never rendered); many → list |
| 25.2 | Not connected | `index.blade.php` | What connecting does, what Slice A reads, §25.9 consent disclosure, **Connect** button |
| 25.3 | Connected, not bound | `index.blade.php` | Connected Google account email, **Choose location** action |
| 25.4 | Location chooser | `locations.blade.php` | Grouped by Google account, showing account name, `Account.role`, location title, store code, locality hint (§23.3), a non-binding "likely match" hint, and an explicit radio + confirm |
| 25.5 | Connected and bound | `index.blade.php` | Google location health (§21.3), `has_pending_edits`, `open_status`, duplicate notice, links to `mapsUri` / `newReviewUri` |
| 25.6 | Comparison | `comparison.blade.php` | The four-column §22 table |
| 25.7 | Revoked / reconnect | `index.blade.php` | Plain explanation, **Reconnect** action. The binding is retained and shown |
| 25.8 | Connection settings | `settings.blade.php` | Connected account, granted scopes (display only), `connected_at`, `last_refreshed_at`, recent operations (§27), **Disconnect** and **Unbind** |
| 25.9 | Consent disclosure | shown before every Connect | See below |
| 25.10 | Refresh status | inline | "Last refreshed <time>", or "Refresh required" when the mirror is absent or expired (§13.3) |
| 25.11 | Safe error | inline alert | One of the closed `failure_classification` values, mapped to plain language. **Never a raw provider message** |

### 25.9 Required consent disclosure — exact obligation

Before redirecting to Google, the UI must state, in the user's own view:

> Google's permission screen will say **"Manage your Business Profile on
> Google"**. That is the only permission Google offers for Business
> Profile — there is no read-only option. This platform only **reads** your
> profile; it will not change anything on Google.

This is a factual disclosure of a Google constraint, not marketing copy, and
it must not be softened or removed.

### 25.10 No dead tabs, no scores

There is **no** Reviews tab, Posts tab, Media tab, Performance tab or Q&A
tab — not even disabled or "coming soon". There is **no** GBP score, profile
strength percentage, grade, chart or AI-generated analysis anywhere.

### 25.11 Attribution — production requirement

Where Google Brand Features are supplied, they must be displayed exactly as
provided and must not be deleted or altered. Slice A displays no Google logo
by default; if one is introduced, this obligation applies. Recorded as a
production gate, §35 P-8.

---

## 26. NAVIGATION — ONE NARROW INSERTION

`app/Helpers/Helper.php`'s `menuData()` is shared by B4, B5, Website, SEO
and now GBP. The implementation agent must:

* add **exactly one** entry to the `'customer'` array, immediately after the
  existing `channels` entry (both are "connect an external provider"
  surfaces);
* **preserve every entry already present on the implementation base**,
  including any entry merged by Lane A or Lane B after this contract was
  written;
* make **no** structural change, no reordering, no reformatting, and no
  whitespace-only edit to any other line.

```php
[
    'url'    => url('gbp'),
    'slug'   => 'gbp',
    'name'   => 'Google Business Profile',
    'i18n'   => 'Google Business Profile',
    'icon'   => 'map-pin',
    'access' => 'view_google_business_profile',
],
```

**Navigation visibility is presentation only.** `access` hides the link; it
does not authorize anything. Every route enforces §15 and §16
independently (T-NAV-1).

---

## 27. AUDITING — LOCKED

`business_google_operations` **is** the audit table. No separate GBP events
table is created; one would duplicate it.

Every record answers, without exception:

| Question | Column |
|---|---|
| Who | `actor_user_id` (null for scheduled jobs, which is itself the answer) |
| Which Workspace | derived from `business_id` at read time — never denormalized twice |
| Which Business | `business_id` |
| Which BusinessLocation | via `business_google_location_id` when applicable |
| What operation | `operation_type` |
| Against what | `summary`, which may name the provider resource identifier |
| Result | `status` + `failure_classification` |
| When | `started_at`, `completed_at`, `created_at` |

Connection lifecycle events are operation types in this same ledger.

**Never present in the ledger:** any token, authorization code, raw state,
raw provider request or response, raw provider error text, any Google
Content value, any street address, any reviewer PII, any exception payload.
T-AUDIT-2 asserts a ledger dump after a full connect → bind → refresh →
disconnect cycle contains none of the fixture's secrets or address strings.

**Transparency obligation.** Google's policy requires that "If your tool
makes any changes to an end-client's account… provide notice to the
end-client of the change within 48 hours after the change is made." Slice A
makes **no** changes to a Google account, so the obligation is not triggered
today. The §25.8 recent-operations panel already satisfies it structurally,
which is why it is in Slice A rather than deferred: Slice B inherits a
customer-visible change log rather than having to build one.

---

## 28. CONFIGURATION AND SECRETS — LOCKED

### 28.1 `config/services.php` — additive only

The `google_business_profile` block in §9.1. **The existing `google` block
is not touched.**

### 28.2 New `config/google_business_profile.php`

```php
return [
    'oauth' => [
        'state_ttl_seconds' => env('GOOGLE_BUSINESS_PROFILE_STATE_TTL_SECONDS', 600),
    ],
    'http' => [
        'connect_timeout_seconds' => env('GOOGLE_BUSINESS_PROFILE_CONNECT_TIMEOUT', 5),
        'request_timeout_seconds' => env('GOOGLE_BUSINESS_PROFILE_REQUEST_TIMEOUT', 20),
    ],
    'mirror' => [
        'retention_days' => env('GOOGLE_BUSINESS_PROFILE_MIRROR_RETENTION_DAYS'),
    ],
    'sync' => [
        'min_interval_hours'               => env('GOOGLE_BUSINESS_PROFILE_SYNC_MIN_INTERVAL_HOURS', 24),
        'max_calls_per_business_per_hour'  => env('GOOGLE_BUSINESS_PROFILE_MAX_CALLS_PER_BUSINESS_PER_HOUR', 60),
        'breaker_threshold'                => env('GOOGLE_BUSINESS_PROFILE_BREAKER_THRESHOLD', 20),
        'breaker_cooldown_minutes'         => env('GOOGLE_BUSINESS_PROFILE_BREAKER_COOLDOWN_MINUTES', 30),
    ],
    'ledger' => [
        'retention_days' => env('GOOGLE_BUSINESS_PROFILE_LEDGER_RETENTION_DAYS'),
    ],
];
```

Every value is validated at the point of use with the house idiom (int or
digit-only string, then range check), never trusted as an env string.
`mirror.retention_days` fails closed toward **purging** (§13.4);
`ledger.retention_days` fails closed toward **retaining** (§13.6). The
divergence is deliberate and must be documented in both docblocks.

### 28.3 Configuration must be validated before anything happens — CORRECTED (correction item 7)

`GoogleBusinessProfileOAuthConfig::assertUsable()` runs **before** a
pending connection, a nonce or a ledger row exists, and before any provider
call. It requires:

* a non-empty client id;
* a non-empty client secret;
* a non-empty redirect;
* the redirect to use **HTTPS** — except `http://localhost`,
  `http://127.0.0.1` and `http://[::1]`, which Google itself exempts;
* the redirect to **exactly equal the one fixed callback URL this
  application serves** (§17.1b), compared on scheme, host, port and path,
  with any query string or fragment rejected outright.

That last rule is not cosmetic: Google matches `redirect_uri` exactly
against a registered URI, so a mismatch produces a dead flow *after* state
has been written. Validating first means a misconfigured deployment leaves:

* **no database state change**;
* **no provider call**;
* **no credential value anywhere** — the operator message names the setting
  that is wrong, never its contents;
* a safe operator-configuration message on the GBP page.

### 28.4 Secrets

* `GOOGLE_BUSINESS_PROFILE_CLIENT_SECRET` lives only in `.env`. It is never
  committed, never rendered, never logged.
* No GBP credential is ever exposed through B3's settings UI. GBP
  credentials are **operator/platform** credentials, not per-customer
  settings.
* `.env.example` may gain the new keys with **empty values** — that is the
  only permitted edit to it, and it is optional.

---

## 29. MIGRATIONS — LOCKED

**Seven** new migrations (M7 added by correction item 6). **No already-run migration may be edited.**

| # | Proposed name | Type | Purpose |
|---|---|---|---|
| M1 | `create_business_google_connections_table` | schema | §11.1, C-1, plus the supporting unique index for C-5 |
| M2 | `create_business_google_locations_table` | schema | §11.2, C-2..C-5, plus the supporting unique index on `business_locations (id, business_id)` for C-4 |
| M3 | `create_business_google_operations_table` | schema | §11.3, C-6, C-7 |
| M4 | `seed_google_business_profile_plan_packaging` | data | Insert `google_business_profile_module` into `workspace_plan_features` for the **`growth`** and **`agency`** catalog tiers only |
| M5 | `backfill_google_business_profile_usage_classification` | data | Insert the `platform_feature_usage_classifications` row: `is_metered = false`, `active_rate_id = null`, `updated_by_user_id = null` |
| M6 | `backfill_google_business_profile_view_permission` | data | §16.5 |
| M7 | `add_provider_call_count_to_business_google_operations_table` | schema | Adds the bounded `provider_call_count` counter that makes the per-Business provider-call budget enforceable (§24.3, correction item 6). Rollback disables budget accounting only — it destroys no authorization and no Google Content |

Timestamps must sort after `2026_09_07_120003`, the newest migration on the
verified base.

### 29.1 M4 — exact behaviour

Query-builder only, no Eloquent, copying `2026_08_13_120007`'s
insert-if-missing idiom:

* Look up `workspace_plan_catalog.id` for tier `growth`, then `agency`.
* For each, insert `workspace_plan_features (workspace_plan_catalog_id,
  feature_key = 'google_business_profile_module', created_at, updated_at)`
  **only if** that pair is absent.
* **The `core` tier is never touched.** No row is inserted for it, and no
  row is deleted from it.
* `down()` is a **non-destructive no-op**, matching `2026_08_13_120007`'s
  documented rationale: a `workspace_plan_assignments` foreign key
  RESTRICTs catalog deletion, so a partial rollback would leave the database
  half-migrated.

T-PKG-1 asserts, against the migrated schema, that `growth` and `agency`
have the row and **`core` does not**.

### 29.2 M5 — exact behaviour

Copies `2026_08_16_120008`'s idiom exactly, including the completeness check
that throws `PlatformFeatureUsageClassificationBackfillIncompleteException`.
Insert-if-missing; `down()` is a non-destructive no-op.

### 29.3 M6 — exact behaviour

Idempotent data migration:

* If an `app_configs` row with `setting = 'customer_permissions'` exists,
  JSON-decode its value; if `view_google_business_profile` is absent, append
  it and write back. If the row does not exist, do nothing (the config
  default already applies to future customers).
* For each `customers` row whose `permissions` is a decodable JSON array not
  containing `view_google_business_profile`, append it and write back.
  A null, empty or non-decodable `permissions` value is **left untouched** —
  the migration never invents a permission list where none exists.
* `manage_google_business_profile` is **never** written.
* `down()` is a non-destructive no-op, consistent with M4 and M5.

### 29.4 Rollback descriptions — required in every docblock

| Migration | `down()` | Docblock must state |
|---|---|---|
| M1 | `Schema::dropIfExists('business_google_connections')` | **"Rolling back this migration DESTROYS every stored Google authorization. Every connected Business must re-run the full OAuth consent flow. The refresh tokens are not recoverable."** |
| M2 | `Schema::dropIfExists('business_google_locations')` | "Destroys every location binding and every mirrored profile. Bindings must be re-selected by hand." |
| M3 | `Schema::dropIfExists('business_google_operations')` | "Destroys the GBP audit trail." |
| M4 | no-op | Why (§29.1) |
| M5 | no-op | Why (§29.2) |
| M6 | no-op | Why (§29.3) |

M1's warning is mandatory and must appear verbatim in intent.

### 29.5 Deletion behaviour

* `businesses` deleted → `business_google_connections` cascades → through
  C-5, `business_google_locations` cascades. `business_google_operations`
  rows survive (§11.3.1) and are removed only under §13.6.
* `business_locations` deleted → through C-4, its binding row is deleted.
  The connection survives — deleting one location must not disconnect the
  Business.
* `users` deleted → `connected_by_user_id`, `bound_by_user_id`,
  `actor_user_id` become null (`nullOnDelete()`); no GBP row is destroyed by
  a user deletion.

---

## 30. EXACT IMPLEMENTATION ALLOWLIST

Every path below is **proposed** unless marked *(exists)*. Every
existing-file modification was verified against `origin/main` at
`5e149f0` and is one-purpose only.

### 30.1 New enums — `app/Enums/GoogleBusinessProfile/`

* `GoogleConnectionState.php`
* `GoogleOperationType.php`
* `GoogleOperationStatus.php`
* `GoogleLocationHealth.php`
* `GoogleComparisonStatus.php`

### 30.2 New models — `app/Models/`

* `BusinessGoogleConnection.php` — casts `state`,
  `refresh_token_encrypted => 'encrypted'`, timestamps
* `BusinessGoogleLocation.php` — casts `profile_mirror => 'array'`, booleans,
  timestamps
* `BusinessGoogleOperation.php` — casts `operation_type`, `status`,
  timestamps

### 30.3 New DTOs — `app/DTO/GoogleBusinessProfile/`

`GoogleTokenGrant`, `GoogleAccessGrant`, `GoogleAccountSummary`,
`GoogleLocationCandidate`, `GoogleLocationProfile`,
`GoogleVoiceOfMerchantState`, `GoogleComparisonRow`.

### 30.4 New provider client contract and Fake

* `app/Library/GoogleBusinessProfile/Contracts/GoogleBusinessProfileReadClient.php`
* `app/Library/GoogleBusinessProfile/HttpGoogleBusinessProfileReadClient.php`
* `app/Library/GoogleBusinessProfile/FakeGoogleBusinessProfileReadClient.php`

### 30.5 New domain services — `app/Library/GoogleBusinessProfile/`

Plus, from the review corrections: `GoogleBusinessProfileOAuthConfig`
(item 7), `GoogleBusinessProfileCallBudget` (item 6, bound as a container
SINGLETON so the provider client shares the reservation context) and
`GoogleBusinessProfileCandidateTokenSigner` (item 5); the exceptions
`GoogleBusinessProfileConcurrencyException` (item 3) and
`GoogleBusinessProfileConfigurationException` (item 7); and the DTO
`GoogleMirrorRefreshResult` (item 8).

`GoogleOAuthStateSigner`, `GoogleBusinessProfileConnectionManager`,
`GoogleBusinessProfileEnumerator`, `GoogleBusinessProfileBindingManager`,
`GoogleBusinessProfileMirrorService`, `GoogleBusinessProfileComparator`,
`GoogleBusinessProfileReadMask`, `GoogleBusinessProfileOperationLedger`.

### 30.6 New repositories

`app/Repositories/Contracts/BusinessGoogleConnectionRepository.php`,
`BusinessGoogleLocationRepository.php`,
`BusinessGoogleOperationRepository.php`, plus the three
`app/Repositories/Eloquent/Eloquent*.php` implementations.

### 30.7 New controller and FormRequests

* `app/Http/Controllers/Customer/Business/GoogleBusinessProfileController.php`
* `app/Http/Requests/GoogleBusinessProfile/GoogleBusinessProfileBindRequest.php`
* `app/Http/Requests/GoogleBusinessProfile/GoogleBusinessProfileUnbindRequest.php`

### 30.8 New jobs — `app/Jobs/GoogleBusinessProfile/`

`RefreshGoogleBusinessProfileMirror.php`,
`SweepGoogleBusinessProfileRefreshes.php`,
`PurgeExpiredGoogleBusinessProfileMirrors.php`.

### 30.9 New events — `app/Events/GoogleBusinessProfile/`

The six classes in §19.5.

### 30.10 New migrations — `database/migrations/`

M1–M6 (§29).

### 30.11 New views — `resources/views/customer/business/GoogleBusinessProfile/`

`entry.blade.php`, `index.blade.php`, `locations.blade.php`,
`comparison.blade.php`, `settings.blade.php`.

### 30.12 New config

`config/google_business_profile.php` (§28.2).

### 30.13 New tests

`tests/Feature/GoogleBusinessProfile/**`,
`tests/Unit/GoogleBusinessProfile/**`,
`tests/Feature/Security/GoogleBusinessProfileSecurityTest.php` (§32).

### 30.14 Narrow modifications to existing files — each one purpose only

| File *(exists)* | The single change | Verified precedent |
|---|---|---|
| `app/Enums/Entitlement/PlatformFeature.php` | Add **one** case, `GoogleBusinessProfileModule = 'google_business_profile_module'` | The enum's own naming for `SeoModule` / `GoogleAdsModule` |
| `app/Library/Entitlement/PlatformFeatureRegistry.php` | Add **one** `AVAILABILITY` line, `=> PlatformFeatureAvailability::Available`. **No `SCOPE` entry** | The `ProspectOutreach` flip documented in its own docblock |
| `config/services.php` | Add the `google_business_profile` block. **`google` untouched** | The additive `stripe.mode`/`api_version` precedent |
| `config/customer-permissions.php` | Add **two** keys (§16.1) | The file's own existing shape |
| `routes/customer.php` | Add the bare `gbp` entry route and the one Business-scoped group (§17) | The B4 automations group |
| `app/Console/Kernel.php` | Add **two** `$schedule->job(...)` lines — `PurgeExpiredGoogleBusinessProfileMirrors` hourly, `SweepGoogleBusinessProfileRefreshes` daily — plus their `use` statements | `PurgeExpiredWebhookPayloads` / `ReconcileSlotAgreementAllocation` |
| `app/Providers/AppServiceProvider.php` | Add the provider-client binding and the three repository bindings to the existing binding array | The `PaymentProviderGateway` binding at line 177 |
| `app/Helpers/Helper.php` | Add **one** `menuData()['customer']` entry (§26) | The `channels` entry |
| `tests/Feature/Entitlement/PlatformFeatureRegistryTest.php` | Extend the existing expectations to include the new case | The file's own structure |

**`config/customer-permissions.php.demo` is deliberately NOT modified.** It
is a separate demo-install artefact, and touching it is outside this
contract's purpose; a demo install without the GBP permission simply does
not show the surface, which is the correct behaviour for a demo (§18.1's
demo guard blocks the flow anyway).

### 30.15 Explicitly forbidden modifications

`app/Http/Controllers/Auth/LoginController.php` · `routes/auth.php` ·
`EloquentAccountRepository::findOrCreateSocial()` · the existing
`services.google` block · any B2-owned file (`MessagingChannelsController`,
`SendingServer`, `CustomerBasedSendingServer`) · any B3-owned settings file ·
any B4-owned file except the shared `routes/customer.php`,
`app/Helpers/Helper.php` and `app/Console/Kernel.php` seams, and there only
by addition · **any unfinished Lane A (B5) code** · **any unfinished Lane B
(Website implementation) code** · Website implementation files · SEO
provider or storage boundaries · Ads authentication · any generic OAuth
infrastructure · any already-run migration.

### 30.16 Shared-seam discipline

`routes/customer.php`, `app/Helpers/Helper.php`, `app/Console/Kernel.php`
and `app/Providers/AppServiceProvider.php` are shared with B4, B5 (Lane A,
in flight) and Website (Lane B, in flight). The implementation agent must
**append** to each and must preserve every entry present on its own
implementation base, including entries merged by Lane A or Lane B after this
contract was written. No reordering, no reformatting, no whitespace-only
change, no "tidying".

---

## 31. EXPLICIT STOP-LIST

The implementation pass must not build, and must not begin to build:

**Architecture.** A generic OAuth framework · a generic provider-credential
framework · a generic social scheduler · a generic review platform · a
generic media library · a generic metrics warehouse · a new frontend
framework · route-domain or wildcard-host work · custom domains, DNS, TLS,
ACME or CDN work.

**Data.** Google-content aggregation of any kind · durable Google-content
history · a stored comparison result · a persisted candidate list ·
plaintext tokens · a durable access token · raw provider payload storage ·
raw provider error storage · reviewer PII storage · any street address
stored, logged, compared or transmitted when `public_address = false`.

**Behaviour.** Automatic candidate selection · automatic binding · automatic
Google mutation of any kind · automatic platform overwrite · a bidirectional
background reconciler · auto-publishing a platform-path Website URL to GBP ·
arbitrary URL ingestion · server-side fetching of any user- or
provider-supplied URL · blind provider retry · high-frequency polling ·
Pub/Sub or webhook infrastructure · notification-setting writes · Google Ads
scopes.

**Presentation.** A GBP score · a profile-strength percentage · a grade · a
chart · AI-generated analysis · dead Reviews, Posts, Media, Performance or
Q&A tabs.

**Security anti-patterns.** `Auth::id()` as tenancy · 403 for tenant
mismatch · raw Blade output (`{!! !!}`) in any GBP view · open redirects ·
network calls inside DB transactions · `uniqid()` for any security value.

**Scope.** Any implementation beyond Slice A as defined in §5.

---

## 32. TEST MATRIX

Paths follow the repository's own conventions
(`tests/Feature/{Domain}/`, `tests/Unit/{Domain}/`,
`tests/Feature/Security/{Domain}SecurityTest.php`). The suite runs against
**`ultimatesms_testing`** only. Automated tests use the Fake client
exclusively; **no real HTTP to any Google host may occur in any test**
(T-FAKE-2).

Shared concerns mirror B4's: `tests/Feature/GoogleBusinessProfile/Concerns/CreatesGoogleBusinessProfileFixtures.php`,
and B4's `UsesFreshSchema` trait pattern where a second database session or
DDL is required.

### 32.1 Entitlement and packaging

| ID | Assertion |
|---|---|
| T-ENT-1 | A **Growth** Workspace's Business reaches every GBP route |
| T-ENT-2 | An **Agency** Workspace's Business reaches every GBP route |
| T-ENT-3 | A **Core** Workspace's Business gets **404** on every GBP route, including POSTs |
| T-ENT-4 | A forged direct POST to `bind`, `refresh`, `disconnect`, `unbind` from a Core Business is refused server-side, with navigation irrelevant |
| T-ENT-5 | `decide()` denies with `platform_feature_unavailable` if the AVAILABILITY entry is absent — guarding the §2.2 coupling |
| T-ENT-6 | A Business that becomes inactive, whose Workspace becomes inactive, whose plan drops to Core, or which is deleted receives **no background refresh** and its job makes **no provider call** |
| T-PKG-1 | After migration, `growth` and `agency` have the `google_business_profile_module` packaging row and **`core` does not** |
| T-PKG-2 | `migrate:fresh` completes without `PlatformFeatureUsageClassificationBackfillIncompleteException` |

### 32.2 Tenancy and authorization

| ID | Assertion |
|---|---|
| T-TEN-1 | Zero / one / many accessible Businesses on bare `/gbp` behave per §17.1, with **no primary-Business inference** |
| T-TEN-2 | Two-UID addressing: a valid `workspaceUid` with a `businessUid` from another Workspace → **404** |
| T-TEN-3 | Cross-Workspace access denied → 404 |
| T-TEN-4 | Cross-Business access denied → 404 |
| T-TEN-5 | A foreign `business_google_connections` row is unreachable → 404 |
| T-TEN-6 | A foreign `business_google_locations` binding uid → 404 |
| T-TEN-7 | Inactive Workspace → 404 on every route |
| T-TEN-8 | Inactive membership → 404 |
| T-TEN-9 | A `Selected`-scope member **without** an assignment to this Business → 404 |
| T-TEN-10 | `view_google_business_profile` absent → view routes refused |
| T-TEN-11 | `manage_google_business_profile` absent → connect, callback, disconnect, locations, bind, unbind, refresh all refused |
| T-TEN-12 | Every tenant mismatch returns **404, never 403** — asserted on the status code, for every route |

### 32.3 OAuth, actor binding, locking, token invariant and secrets

The review corrections add four families, all proved with the Fake client:

| Family | Proves |
|---|---|
| Fixed callback (item 1) | the callback route is tenant-free, equals the configured redirect, and resolves the Business only from the signed state |
| Actor binding (item 2) | **two separately authorized manage users in the SAME Business**: A begins and B is refused with zero exchange; A's nonce survives and A can still finish; a newer attempt by B invalidates A's older state and re-stamps the actor; a mismatch creates no User and changes no authentication |
| Optimistic locking (item 3) | two writers holding the same version cannot both succeed; the loser throws and writes no successful ledger row; token storage and activation are one conditional update; each success increments the version by exactly one |
| Token invariant (item 4) | every non-active state holds no token in the raw row; a reconnect that returns no refresh token fails closed and stays pending; one that returns a new token activates |

### 32.3a Original OAuth and secret coverage

| ID | Assertion |
|---|---|
| T-OAUTH-1 | Missing `state` → 404, **and the Fake records zero token-exchange calls** |
| T-OAUTH-2 | Tampered signature → 404, zero token exchange |
| T-OAUTH-3 | Expired state → 404, zero token exchange |
| T-OAUTH-4 | **Replayed nonce**: the first callback succeeds, the second returns 404 and performs zero token exchange |
| T-OAUTH-5 | State naming a Business the current user cannot access → 404, zero token exchange |
| T-OAUTH-6 | A Business identifier supplied in Google's callback payload is **ignored**; only the signed state's Business is used |
| T-OAUTH-7 | Connect and callback are throttled at the §17.2 limits |
| T-OAUTH-8 | The callback **never authenticates and never creates a `User`**: `users` row count is unchanged and the authenticated id is unchanged |
| T-OAUTH-9 | `email_verified_at` is byte-identical before and after a full connect cycle |
| T-SEC-1 | A **raw database read** of `refresh_token_encrypted` does not equal the plaintext token |
| T-SEC-2 | The token, the authorization code and the raw state appear in **no** response body, view, session flash, log file, exception context or ledger row |
| T-SEC-3 | `invalid_grant` on refresh → state `revoked`, **exactly one** provider attempt, no retry storm, and a subsequent sweep makes zero calls for that connection |
| T-SEC-12 | No GBP route accepts or honours a `redirect_to` / `return` / `next` / `url` parameter — **no open redirect** |

### 32.4 Enumeration and binding

| ID | Assertion |
|---|---|
| T-BIND-1 | Binding requires an explicit chosen `provider_location_resource_name`; a request without it fails validation |
| T-BIND-2 | Rendering the chooser creates **zero** `business_google_locations` rows — candidates are never persisted |
| T-BIND-3 | **No automatic binding** ever occurs, including when exactly one candidate is returned |
| T-BIND-4 | `provider_location_resource_name` is globally unique: a second Business in a **different Workspace** binding the same Google location is refused, with a user-facing message and no 500 |
| T-BIND-5 | `provider_account_resource_name` is persisted and equals the enumerating account |
| T-BIND-6 | `provider_location_resource_name` is persisted verbatim |
| T-BIND-7 | Binding a `business_location_uid` from another Business → 404 |
| T-BIND-8 | Unbind deletes the binding row, leaves the connection `active`, and writes a `location_unbound` ledger row |

### 32.5 Read mask, privacy and XSS

| ID | Assertion |
|---|---|
| T-MASK-1 | Every enumeration and read call sends a **non-empty `readMask`** |
| T-PRIV-1 | When `public_address = false`, the read mask sent to the Fake **does not contain `storefrontAddress`** |
| T-PRIV-2 | When the Fake returns a `storefrontAddress` **despite** the mask, no DTO, model, view, log or ledger row contains any of its `addressLines` |
| T-PRIV-3 | The response body of every GBP view **never contains** the fixture's `address_line_1` when `public_address = false` |
| T-PRIV-4 | `service_mode = ServiceArea` or `Online` omits `storefrontAddress` regardless of `public_address` |
| T-PRIV-5 | `service_mode = Storefront` with `public_address = false` surfaces the §23.6 contradiction notice and still omits the address |
| T-XSS-1 | Google-supplied `title` containing `<script>` is HTML-escaped in every rendered view |
| T-XSS-2 | **No GBP Blade view contains `{!!`** (asserted by scanning the view directory) |
| T-URL-1 | A Google-supplied `websiteUri` / `mapsUri` with a non-`https` scheme is nulled, not rendered |
| T-URL-2 | No GBP code path performs a server-side fetch of a URL taken from a provider response, a database row, or user input |

### 32.6 Comparison correctness

| ID | Assertion |
|---|---|
| T-CMP-1 | Each of the five statuses is produced by at least one fixture, with the §22.2 ordering respected |
| T-CMP-2 | Platform empty **and** Google empty yields **`Not set on platform`**, never `Match` |
| T-CMP-3 | `http://` vs `https://` on the same host is a **`Mismatch`** |
| T-CMP-4 | `BusinessIndustry` is **never** compared to a Google category as `Match` or `Mismatch` — the category rows are always `Not comparable` |
| T-CMP-5 | `service_radius_km` and `service_area_cities` are always `Not comparable` |
| T-CMP-6 | Opening hours always render `Not set on platform` |
| T-CMP-7 | The street-address row is always `Not comparable`, even when `public_address = true` |
| T-CMP-8 | Phone normalization matches `+1 (555) 010-1234` against `+15550101234` |

### 32.7 Read-only guarantee, mirror and retention

| ID | Assertion |
|---|---|
| T-PROV-1 | Reflection over `GoogleBusinessProfileReadClient` and both implementations finds **no** method matching the §14.2 forbidden list, and no non-OAuth `POST`/`PATCH`/`PUT`/`DELETE` |
| T-SYNC-1 | Every `businesses` row is **byte-identical** before and after a refresh |
| T-SYNC-2 | Every `business_locations` row is **byte-identical** before and after a refresh |
| T-SYNC-3 | **No provider call occurs while `DB::transactionLevel() > 0`** |
| T-SYNC-4 | Two concurrent refreshes for one connection produce **exactly one** provider call |
| T-SYNC-5 | HTTP 429 is recorded as **`deferred`**, not `failed`, and `last_synced_at` is unchanged |
| T-SYNC-6 | A timeout is recorded as **`unknown`** and is not automatically replayed |
| T-SYNC-7 | The daily sweep dispatches at most one refresh per binding per `min_interval_hours`, with staggered delays |
| T-RET-1 | No persisted `mirror_expires_at` is more than **30 calendar days** after `mirror_fetched_at`, for any configured value including `9999` |
| T-RET-2 | Absent, blank, non-digit, `0`, negative and `> 30` configuration all yield an effective TTL of 0 — mirrors are treated as expired and purged |
| T-RET-3 | The purge job nulls `profile_mirror` and the three bind-time snapshot fields for expired rows and writes a `mirror_purged` ledger row |
| T-RET-4 | **No comparison result is persisted anywhere** — asserted by a full schema scan for any comparison-shaped column plus a row-count check after rendering |
| T-RET-5 | No table accumulates Google-content history: rendering the comparison N times leaves row counts unchanged |
| T-FAKE-1 | The Fake is bound in every automated test |
| T-FAKE-2 | **No real HTTP request to any `googleapis.com` host occurs in the suite** |

### 32.7a Budget, configuration and zero-TTL rendering (review corrections)

| ID | Assertion |
|---|---|
| T-BUDGET-1 | the budget counts every outbound request, not operations: one refresh consumes three, attributed to the operation that caused them |
| T-BUDGET-2 | each PAGINATION page consumes one unit |
| T-BUDGET-3 | the exact boundary is permitted |
| T-BUDGET-4 | exceeding it makes ZERO further provider calls, records `deferred`/`budget_exhausted`, and leaves `last_synced_at` untouched |
| T-BUDGET-5 | an exhausted budget makes NO provider call at all |
| T-BUDGET-6 | two concurrent reservations cannot both take the last unit |
| T-BUDGET-7 | a provider call outside any operation context fails closed |
| T-BUDGET-8 | the manual path shows a safe message that names no classification |
| T-CONF-1 | each missing credential, a redirect pointing elsewhere, the OLD tenant-nested redirect, and a non-HTTPS production redirect each refuse with NO row, NO ledger entry, NO provider call and NO credential value disclosed |
| T-CONF-2 | the validator classifies each fault, permits localhost http, and rejects a redirect carrying a query string |
| T-EPH-1 | with a zero TTL the manual refresh RESPONSE renders the fetched data, nothing reusable is written, the session carries nothing, and the NEXT request asks for a refresh |
| T-EPH-2 | with a positive TTL the manual refresh still redirects and the next request renders the stored mirror |

### 32.8 Audit, cache, notifications and regression

| ID | Assertion |
|---|---|
| T-AUDIT-1 | A full connect → bind → refresh → unbind → disconnect cycle produces one ledger row per operation, each with actor, Business, operation, status and timestamps |
| T-AUDIT-2 | A ledger dump after that cycle contains **no** token, authorization code, raw state, provider payload, provider error text, Google Content value, street address or reviewer PII |
| T-AUDIT-3 | The `disconnected` ledger row **survives** the disconnect that deleted the connection |
| T-CACHE-1 | GBP code reaches no `Cache` facade and no cross-request cache (§31; asserted by scanning the GBP namespaces) |
| T-CACHE-2 | Business A's comparison never renders Business B's Google data, including in the same request lifecycle |
| T-NOTIF-1 | No GBP code path reads or writes a Google notification setting, and no Pub/Sub or webhook artefact exists |
| T-NAV-1 | With the navigation entry hidden, direct requests to every GBP route are still authorized server-side and still return 404 for an unentitled Business |
| T-DEL-1 | Disconnect nulls the refresh token, scopes and account email, deletes bindings, and leaves no recoverable authorization material |
| T-DEL-2 | Deleting a `BusinessLocation` deletes its binding and leaves the connection `active` |
| T-REG-1 | Google **sign-in** is unchanged: `services.google`, `LoginController` and `routes/auth.php` are byte-identical, and the existing social-login tests still pass |
| T-REG-2 | Existing B2, B4, Website, Entitlement, Usage, Workspace, Opportunity and Security suites pass unchanged |
| T-REG-3 | **Full regression** against `ultimatesms_testing` |

**Coverage of the brief's B5 item.** B5 is Lane A and is in flight. This
contract touches no B5 file and adds no B5 test. T-REG-2 will include B5's
suite once B5 merges; asserting against unfinished Lane A code now is
forbidden (§30.15).

---

## 33. SECURITY ACCEPTANCE CRITERIA

Slice A is not accepted unless every one of these holds, each with the named
test:

| # | Criterion | Test |
|---|---|---|
| G-1 | No cross-Business or cross-Workspace IDOR; every failure is 404 | T-TEN-1…12 |
| G-2 | OAuth state is signed, expiring and single-use; every invalid case 404s **before** token exchange | T-OAUTH-1…7 |
| G-3 | **The callback never authenticates a user, never creates a user, never touches `email_verified_at`, never calls `findOrCreateSocial()`** | T-OAUTH-8, T-OAUTH-9 |
| G-4 | No plaintext token anywhere: database, response, view, log, exception, ledger | T-SEC-1, T-SEC-2 |
| G-5 | No Google location can be claimed by two platform locations or two Businesses | T-BIND-4 |
| G-6 | **A private street address never leaves the platform, never enters a DTO/model/view/log/ledger, and never appears in a response body** | T-PRIV-1…5, T-CMP-7 |
| G-7 | Google-supplied content cannot inject script; no raw Blade output exists | T-XSS-1, T-XSS-2 |
| G-8 | No duplicate external operation; every call is ledger-keyed before it is made | T-SYNC-4…6, T-AUDIT-1 |
| G-9 | Entitlement and permission are enforced server-side against forged requests, independent of navigation | T-ENT-3, T-ENT-4, T-NAV-1 |
| G-10 | No provider payload or provider error text is ever logged or stored | T-AUDIT-2 |
| G-11 | No cache leakage between tenants; in fact no cross-request cache exists | T-CACHE-1, T-CACHE-2 |
| G-12 | No open redirect on any GBP route | T-SEC-12 |
| G-13 | **The provider interface is structurally incapable of mutating Google** | T-PROV-1 |
| G-14 | The platform's own data is never modified by a sync | T-SYNC-1, T-SYNC-2 |
| G-15 | Google Content retention never exceeds 30 calendar days, and fails closed | T-RET-1, T-RET-2, T-RET-3 |
| G-16 | Existing Google sign-in is untouched | T-REG-1 |

Fix-with rules, carried from the reconnaissance and re-affirmed:
`Str::uuid()` never `uniqid()` for security values; a URL scheme allowlist
that **never** results in a server-side fetch; and bounded error summaries
in place of raw provider errors.

---

## 34. OPERATOR PREREQUISITES — RECORDED, NOT PERFORMED

**None of the following was performed during this contract pass.** No form
was submitted, no Cloud project touched, no OAuth flow started, no
credential used, no Google API called.

### 34.1 Before implementation is approved to start

1. **Close G-LEGAL-1** (§13.7): a legal reading confirming that retaining
   `accounts/{id}` and `locations/{id}` for the active binding lifecycle
   complies with Google's caching policy. If the reading requires shorter
   retention, only retention behaviour tightens (§13.7).
2. Confirm which Google Business Profile will be used, and that it is
   **verified and active for 60+ days** — Google's published prerequisite,
   and the longest-lead item. Start the clock now if it has not started.
3. Confirm a **website representing the business** is listed on that GBP.
4. Confirm whether a Google **Cloud Organization** exists. Google's
   prerequisites require a Cloud **project** and a Business Profile
   **Organization account** — two different things — and say nothing about a
   Cloud Organization. Do not conflate them.

### 34.2 Before local implementation

5. Create a **dedicated Google Cloud project** for GBP, separate from the
   sign-in project, and record its **Project Number**.
6. Create a **Business Profile Organization account**; if acting for
   clients, register it **as an agency**.
7. Enable the seven APIs Google names for Business Profile access on that
   project.
8. Create a **dedicated OAuth 2.0 Web application client**. Never reuse the
   sign-in client. Record the client id and secret for
   `services.google_business_profile`.
9. Configure **redirect URIs**: HTTPS only, no raw IP hosts. Register the
   production and staging callbacks. **Do not** register Google's OAuth
   Playground URI on the production client.
10. Configure the **OAuth consent screen** and record whether Google marks
    `https://www.googleapis.com/auth/business.manage` as **sensitive** or
    **restricted** — the indicator is shown in the Cloud Console. This is
    the one open OAuth question, and it feeds §35 gate P-2.
11. Submit the **access application** via Google's GBP API contact form,
    selecting **"Application for Basic API Access"**, from an email listed
    as an **owner/manager** on the target GBP, supplying the Project Number.

### 34.3 Before integration testing against Google

12. **Confirm approval:** the Cloud Console quota for the Business Profile
    APIs must read **300 QPM**, not **0 QPM**. Google's published
    expectation is that requests are **reviewed within 14 days**. **No API
    call of any kind is possible before approval.**
13. Move the OAuth client to **"In production"** publishing status. A client
    in "Testing" is issued refresh tokens that **expire in 7 days**, which
    would silently break every customer connection weekly.
14. Nominate **one operator-owned, verified Business Profile** for a
    documented manual integration pass. **There is no sandbox** and no way
    to create a fake listing for testing. Automated tests use the Fake
    client (T-FAKE-1, T-FAKE-2); the real profile is for one manual pass
    only.
15. Verify, against that real profile, the two Slice B unknowns before any
    write is contracted: how clearing `storefrontAddress` via `updateMask`
    behaves, and whether a read returns a suppressed address to an
    authorized manager (§36.1.2).

**Architectural implementation may be built and tested against the Fake
before steps 11–14 complete.** Product implementation must remain
provider-faked until those prerequisites are met.

---

## 35. PRODUCTION GATES

Each gate is pass/fail and blocks production release of Slice A. None is
satisfied by this contract; each names its owner.

| Gate | Condition | Owner |
|---|---|---|
| **P-1** | GBP API access approved: Cloud Console quota reads **300 QPM**, not 0 | Operator |
| **P-2** | If Google marks `business.manage` **sensitive** or **restricted**, **OAuth app verification is complete** (§34.2 step 10). Google's published rule: apps requesting sensitive or restricted scopes must complete verification. Google does not publish this scope's classification — the Console indicator is authoritative | Operator |
| **P-3** | The OAuth client is in **"In production"** publishing status, so refresh tokens do not expire after 7 days | Operator |
| **P-4** | **G-LEGAL-1 closed** (§13.7) | Legal + operator |
| **P-5** | Legal review of the Business Profile Terms and the Google APIs Terms of Service referenced by Google's GBP API Terms of Service page | Legal |
| **P-6** | The **≤30-day mirror purge** job is scheduled, running and monitored, and T-RET-1…3 are green in CI | Engineering |
| **P-7** | The **§25.9 consent disclosure** is live on every Connect surface | Engineering |
| **P-8** | **Google attribution** requirements are satisfied wherever Google Brand Features are displayed (§25.11) | Engineering |
| **P-9** | The **≤48-hour end-client change notice** capability exists (§27). Slice A triggers no Google change, so the obligation is not live; the §25.8 panel must nonetheless be in place before Slice B | Engineering |
| **P-10** | One documented **manual integration pass** against a real operator-owned verified profile has been completed and recorded (§34.3 step 14) | Operator + engineering |
| **P-11** | The full §32 matrix and the full suite pass against `ultimatesms_testing` | Engineering |
| **P-12** | No environment holds a GBP client secret outside `.env`, and no secret is present in any commit | Operator |

---

## 36. SLICE B AND SLICE C — RECORDED BOUNDARIES, EXPLICITLY NOT BUILT

### 36.1 Slice B — Google mutations

**36.1.1 Excluded from Slice A entirely.** Any Google profile mutation;
hours publishing; address or service-area publishing; category mutation;
attribute mutation; website-URL mutation; service mutation; verification
initiation or completion; automatic consistency repair; bidirectional sync.

**36.1.2 Facts recorded for the future Slice B contract.**

| Fact | Source | Consequence |
|---|---|---|
| Edits are limited to **10 per minute per Google Business Profile, and that limit cannot be increased** | Google's published usage limits | A hard write ceiling; batch editing must be paced per profile |
| Edits propagate only when `hasVoiceOfMerchant` is true — "Any edits made to the location will propagate to Maps after passing the review phase" | Verifications v1 | The write gate is `hasVoiceOfMerchant === true` |
| `locations.patch` requires `updateMask` | Business Information v1 | A **hard-coded write allowlist** of maskable fields; never a deny-list |
| `locations.patch` supports `validateOnly` | Business Information v1 | Use it before every real write |
| **`storefrontAddress` is forbidden in every `updateMask`** | §23, plus the unresolved item below | Enforced by allowlist, asserted by test |
| There is **no** `showAddress`/`addressVisibility` API field; suppression appears to be structural — omit `storefrontAddress` and set `businessType = CUSTOMER_LOCATION_ONLY` — and `locations.patch` clear-field semantics are **undocumented** | Business Information v1 discovery, revision `20260902` | **Address suppression must not be written until live behaviour is verified against a real listing (§34.3 step 15) and separately contracted** |
| Google's policy: "You must not automate or trigger review replies, Q&As, listing creations, listing edits, or other actions without the user's prior specific and express consent" | Google's API policies | Per-item human approval is a **policy obligation**, not a preference |
| Google's policy: notify the end-client of any change to their account **within 48 hours** | Google's API policies | The §25.8 panel must remain and must show mutations |
| Whether a read returns a suppressed address to an authorized manager is **undocumented** | — | Assume it does; §23 already makes the answer irrelevant |

**36.1.3 Business Profile Foundation prerequisite.** Per-location **opening
hours** do not exist on the platform. They are a general Business fact that
Website, SEO and GBP all eventually need, and they belong on
`business_locations`, **not** in a GBP table. They are a hard prerequisite
for **hours mutation only**. **They are not a prerequisite for Slice A**,
which reports `Not set on platform` (§22.6).

### 36.2 Slice C — reviews, posts, media, performance

**36.2.1 Excluded from Slice A entirely.** Reviews; review replies; local
posts; media; performance metrics; durable metrics history; B5 aggregation;
AI drafting; public publishing automation.

**36.2.2 Facts recorded for whoever contracts Slice C.**

* **Q&A is permanently excluded** — the API was discontinued 2025-11-03
  (§7).
* Reviews, media and local posts exist **only** on Google My Business API
  v4.9. There is no v1 replacement. Reviews are readable only for
  **verified** locations (`batchGetReviews`: "up to 50 specified, verified
  locations"), carry reviewer PII gated by `isAnonymous`, and replies are
  capped at 4,096 bytes and subject to moderation (`reviewReplyState`, plus
  a `PolicyViolation` field added 2026-07-01).
* **A durable review or metrics archive is not permitted.** Google's 30-day
  storage cap and its "cannot be manipulated or aggregated in any way" rule
  remove most of the value from a generic "review platform", which is why
  none is contracted.
* **Local-post media is `sourceUrl`-only** — "sourceUrl is the only
  supported data field for a LocalPost MediaItem" — and `sourceUrl` is "A
  publicly accessible URL where the media item can be retrieved from". A
  post with an image therefore requires **deliberately publishing an asset
  at a public, unauthenticated URL**. That is a separate publication and
  privacy decision, not an implementation detail.
* **Photo uploads should prefer the byte/resumable path**
  (`media.startUpload` → `dataRef`) precisely to avoid that public
  exposure.
* **Google-hosted media URLs are not stable** — `googleUrl` is "not static
  since it may change over time" — so no `googleUrl` may ever be persisted
  as a durable reference. The same applies to review-media thumbnails.
* **No GBP-to-B5 metrics pipeline** unless legal or policy review explicitly
  overturns the aggregation prohibition (§37.3).
* Media size limits, accepted MIME types, processing states and duplicate
  behaviour are **not documented** on the pages Google publishes, and must
  be verified before any media slice is contracted.

**Do not collapse these slices merely because Google exposes additional
endpoints.**

---

## 37. WEBSITE, SEO, B5 AND ADS BOUNDARIES

### 37.1 Website

* GBP compares `businesses.website_url`. **Website Slice A is not a
  dependency**, and GBP does not read `websites`, `website_pages`,
  `website_revisions` or `website_assets`.
* **Never auto-publish `/sites/{public_id}` to GBP.** This is now stronger
  than a preference: `WEBSITE-GENERATION-HOSTING-CONTRACT.md` §20 (merged at
  the verified base) locks "a mandatory `noindex` response directive on
  every public Website response" for the platform-path hosting phase, and
  states that Slice A "never promotes a platform-path URL as indexable".
  Publishing a deliberately non-indexed UUID path as a customer's Google
  website URL would be actively harmful.
* Website-to-GBP URL publishing waits for **Website custom domains** (that
  contract's §40, explicitly a future Slice B) **and** a later explicit GBP
  mutation contract.
* Website assets are **never** used as durable GBP-linked media. **No public
  asset exposure for GBP exists in Slice A**, because Slice A uploads
  nothing.

### 37.2 SEO

* GBP owns the Google connection and all Google state.
* SEO may **later** consume a read-only, Business-scoped query service — is
  a connection present, is a location bound, what is the location health, is
  there a NAP mismatch summary. That service is **not built in Slice A**; it
  is recorded so SEO does not invent its own path.
* SEO never stores GBP credentials, never calls Google directly, never
  mutates Google, and must obey the same §13 Google-content retention limits
  for anything it receives.
* SEO's own NAP consistency work functions without GBP. **No circular
  dependency exists in either direction.**
* Note evidence gap G-A (§1.3): there is no merged SEO contract to cite.

### 37.3 B5 — the seam is closed, not merely deferred

* **No GBP metrics feed exists in Slice A.** Slice A does not call the
  Business Profile Performance API at all.
* Previously this was recorded as "a future seam if the API supplies
  metrics". The API **does** supply them, but Google's policy forbids stored
  Content being "manipulated or aggregated in any way" and caps it at 30
  days, and Google performs **no multi-location aggregation** itself — the
  v1 migration eliminated batch calls by `locationNames`. Any useful B5 feed
  would therefore be aggregation by us. **Do not contract a GBP → B5 metrics
  pipeline.**
* If legal or policy review ever overturns that, display remains
  **per-location and GBP-owned**, never an aggregate in B5's store.
* **Lane A's B5 code is not modified, not read as authoritative, and not
  depended upon by this contract.**

### 37.4 Ads

Google Ads and GBP are separate products. **No Ads scopes** are requested,
no `adWordsLocationExtensions` mutation occurs — the field is not even in
the §20 read mask — no Ads credential is reused, and no Ads module file is
modified. `PlatformFeature::GoogleAdsModule` is untouched.

---

## 38. ROLLBACK BEHAVIOUR

| Scenario | Behaviour |
|---|---|
| M1 rolled back | **Every stored Google authorization is destroyed.** Every connected Business must re-run the full OAuth consent flow. Refresh tokens are not recoverable. The docblock must say so (§29.4) |
| M2 rolled back | Every binding and every mirror is destroyed; bindings must be re-selected by hand |
| M3 rolled back | The GBP audit trail is destroyed |
| M4 / M5 / M6 rolled back | Non-destructive no-ops (§29.1–§29.3) |
| Feature disabled at the Workspace level | Every GBP route 404s and background refresh stops (T-ENT-6). **Stored authorization is retained**, so re-enabling does not force a reconnect |
| Business downgraded Growth → Core | As above. Credentials must not be trapped — see §39.4 |
| Provider outage | Operations record `deferred` or `provider_unavailable`; the mirror ages out normally under §13; the comparison degrades to `Not comparable` rather than showing stale data |

---

## 39. IMPLEMENTATION SEQUENCING

Slice A is deliberately **not** micro-sliced: a connection without a binding
is useless, and a binding without a comparison shows nothing. The order
below is the build order within one pass, not a set of separate
deliverables.

1. **Foundation.** Enum case, registry AVAILABILITY entry, M4, M5, config
   files, permissions config, M6. Verify `migrate:fresh` passes and T-PKG-1
   and T-PKG-2 are green **before** writing any product code.
2. **Schema.** M1, M2, M3, models, repositories, container bindings.
3. **Provider seam.** The interface, the Fake,
   `GoogleBusinessProfileReadMask`, the DTOs. **Write T-PROV-1 first**, so
   the read-only guarantee exists before any client code does.
4. **OAuth.** `GoogleOAuthStateSigner`,
   `GoogleBusinessProfileConnectionManager`, the real client's OAuth
   methods, the connect/callback/disconnect routes and actions, plus the
   whole of §32.3.
5. **Enumeration and binding.** `GoogleBusinessProfileEnumerator`,
   `GoogleBusinessProfileBindingManager`, the FormRequests, the chooser
   view, plus §32.4.
6. **Mirror and comparison.** `GoogleBusinessProfileMirrorService`,
   `GoogleBusinessProfileComparator`, the comparison view, plus §32.5 and
   §32.6.
7. **Sync and retention.** The three jobs, the Kernel entries, the circuit
   breaker, plus §32.7.
8. **UI, navigation, audit.** Remaining views, the one `Helper.php` entry,
   the operations panel, plus §32.8.
9. **Regression.** T-REG-1, T-REG-2, T-REG-3 against `ultimatesms_testing`.

### 39.4 Credentials must never be trapped

If entitlement is removed while a connection exists, the customer must still
be able to disconnect. The `disconnect` and `unbind` routes therefore run
the §15 chain **without** the entitlement step — steps 1–4, 6 and 7 only —
and are the **only** two GBP routes with that exemption. They make **no
provider call**, so an unentitled Business can destroy its own stored
authorization but cannot use Google. This is an explicit, bounded exception;
it must be documented in the controller and asserted by a test in the §32.2
family.

---

## 40. DEFINITION OF DONE

Slice A is done when **all** of the following are true:

1. `PlatformFeature::GoogleBusinessProfileModule = 'google_business_profile_module'`
   exists, is `Available` in `PlatformFeatureRegistry::AVAILABILITY`, and has
   **no** `SCOPE` entry.
2. `google_business_profile_module` is packaged to **Growth and Agency
   only**; **Core does not have it**; there is no add-on; no price was
   invented.
3. `migrate:fresh` completes with no
   `PlatformFeatureUsageClassificationBackfillIncompleteException`.
4. Exactly **three** GBP tables exist, with C-1…C-7 enforced in the
   database.
5. Both `provider_account_resource_name` and
   `provider_location_resource_name` are persisted for every binding.
6. Slice A calls **only** Account Management v1, Business Information v1 and
   Verifications v1. No v4.9 call exists anywhere.
7. The provider interface exposes **no** Google mutation method, and
   T-PROV-1 proves it.
8. `mirror_expires_at` is never more than **30 calendar days** after
   `mirror_fetched_at`, retention fails closed toward purging, and the purge
   job is scheduled.
9. **No comparison result is persisted**, and no durable Google-content
   history exists in any table.
10. `businesses` and `business_locations` are byte-identical before and
    after a sync.
11. The private-address invariant holds at all seven enforcement points, and
    a GBP response body never contains a private `address_line_1`.
12. The OAuth callback never authenticates, never creates a `User`, and
    never touches `email_verified_at`.
13. `refresh_token_encrypted` is unreadable as plaintext in a raw database
    read, and no secret appears in any response, view, log, exception or
    ledger row.
14. Every tenant mismatch returns **404, never 403**, on every route.
15. Both permissions exist with the §16.2 defaults, and
    `view_google_business_profile` is backfilled to existing customers.
16. Exactly **one** navigation entry was added, and every pre-existing entry
    — including anything Lane A or Lane B merged in the meantime — survives.
17. Google sign-in is byte-identical: `LoginController`, `routes/auth.php`,
    `findOrCreateSocial()` and `services.google` unchanged.
18. The §32 test matrix passes in full, using the Fake client, with **no
    real HTTP to any Google host**, and the full suite passes against
    `ultimatesms_testing`.
19. Every operator prerequisite in §34 is closed before a real Google
    integration test is attempted, and every §35 production gate is closed
    before release.
20. This document is updated in the same pass if any behaviour it specifies
    changes.

---

## APPENDIX C — POST-IMPLEMENTATION REVIEW CORRECTIONS

Nine defects were found in review AFTER the Slice A implementation landed.
Some originated in this contract itself; those sections have been corrected
in place above, and are indexed here so the document and the product tell
the same truth.

| # | Defect | Origin | Corrected in |
|---|---|---|---|
| 1 | The callback was nested under `/{workspaceUid}/businesses/{businessUid}/gbp/callback` while the OAuth client has ONE configured redirect. Google matches `redirect_uri` exactly, so no per-tenant path can be registered. | **This contract** (§17.2) | §17.1b, §9.5 |
| 2 | No OAuth attempt was bound to its initiating actor, and `beginConnect()` did not re-stamp the actor on an already-`pending` row. | Implementation, and a contract silence | §9.4b, §32.3 |
| 3 | `lock_version` was incremented on a possibly-stale model and saved — not a conditional update, so a loser could overwrite a winner. | Implementation | §11.1.3 |
| 4 | `revoke()` retained the refresh token, and a revoked reconnect could reactivate on it. The contract also contradicted itself about consent on a "healthy reconnect". | **This contract** (§9.3) and implementation | §10.1b |
| 5 | Bind accepted caller-supplied raw resource names; regex plus a successful read never proved the pair had been enumerated for that connection. | **This contract** (§18.2) | §18.2, §19.2 |
| 6 | `max_calls_per_business_per_hour` existed only in configuration and was never enforced. | Implementation | §24.3, M7 |
| 7 | Incomplete or mismatched OAuth configuration was discovered only after state had been written. | Implementation, and a contract silence | §28.3 |
| 8 | A zero effective mirror TTL stored an expired mirror and redirected, so the fetched data could never be shown — the documented default was unusable. | **This contract** (§13.4) | §13.4 |
| 9 | Connect initiation was a GET even though it mutates connection state, the nonce, actor attribution and the ledger. | **This contract** (§17.2) | §17.2 |

---

## APPENDIX A — THE THREE CORRECTIONS THIS CONTRACT CARRIES

The official Google API gate verification produced three changes to the
earlier reconnaissance design. All three are binding.

**A-1. Mirror TTL ≤ 30 calendar days, and the comparison is never
persisted.** Google's API policy caps stored Content at 30 calendar days,
requires secure storage, and forbids Content being "manipulated or
aggregated in any way". The earlier design proposed a "bounded profile
mirror" with no retention rule. §13 now specifies a hard 30-day ceiling, an
hourly purge job, fail-closed configuration that errs toward purging, and a
read-time-only comparison. The same policy line is what closes the B5
metrics seam (§37.3).

**A-2. The Google account resource name is persisted alongside the location
resource name.** Location identity is global for every v1 method Slice A
uses, which validated the platform-wide unique constraint — but reviews,
media and local posts are all addressed as `accounts/{a}/locations/{l}`.
Without the account name, a future slice could not address the location at
all, and re-deriving it would need a re-enumeration under a grant that may
have lapsed (§8.4).

**A-3. Read-only is enforced structurally, because the OAuth scope is
broad.** Google exposes exactly one Business Profile scope,
`https://www.googleapis.com/auth/business.manage`, cited identically by
every read *and* write method. **There is no read-only scope.** The
read-only property of Slice A therefore cannot be delegated to OAuth: it is
enforced by a provider interface with no mutation method, a real client that
implements none, a prohibition on dormant write methods, and T-PROV-1
(§9.2, §14.2).

---

## APPENDIX B — LANE DISCIPLINE FOR THE IMPLEMENTATION AGENT

* Lane A (B5 Business Analytics) and Lane B (Website Generation + Hosting
  Slice A) were **still running** when this contract was written. Neither
  was inspected, waited for, merged, or assumed about.
* The implementation agent must branch from `origin/main` **as it stands at
  implementation time**, not from `5e149f0`, and must re-verify every
  existing-file citation in §30.14 against that base before editing.
* If Lane A or Lane B has merged by then, their entries in
  `routes/customer.php`, `app/Helpers/Helper.php`, `app/Console/Kernel.php`
  and `app/Providers/AppServiceProvider.php` **must be preserved in full**.
  Additive edits only (§30.16).
* If any citation in this contract no longer matches the implementation
  base, **stop and report the exact discrepancy** rather than silently
  adapting.
