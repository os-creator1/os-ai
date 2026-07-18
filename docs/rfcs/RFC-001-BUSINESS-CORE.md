# RFC-001 — Business Core

**Status:** Ready for implementation  
**Version:** 1.0  
**Priority:** P0  
**Target framework:** Laravel 12 / PHP 8.2  
**Architecture constraint:** Extend the existing Ultimate SMS controller → repository → library → model structure. Do not introduce a new generic service-layer convention.  
**Initial vertical:** Photo booth businesses  
**Domain scope:** Industry-agnostic  
**Depends on:** Existing Customer, authentication, authorization, queue, and dashboard infrastructure  
**Enables:** UX-001 First-Time User Experience, RFC-002 Opportunity Engine, RFC-003 AI Workforce

---

## 1. Executive summary

Ultimate SMS understands customers, contacts, campaigns, messaging, plans, and subscriptions, but it does not have a durable domain model for the actual business being operated.

RFC-001 introduces the **Business Core**: the canonical source of truth for business identity, primary location, services, public profile links, onboarding state, and the first asynchronous business-completeness analysis.

The implementation must:

1. Preserve the existing Ultimate SMS architecture.
2. Support one primary business in the initial interface.
3. Permit multiple businesses per customer at the database level.
4. Store locations and services as structured records.
5. Persist onboarding progress and resume state.
6. Generate a truthful initial analysis from data the platform actually possesses.
7. Avoid depending on the Opportunity Engine before RFC-002 exists.
8. Publish stable events that future modules can consume.
9. Avoid forcing existing customers through onboarding on deployment.
10. Be safe to release behind configuration flags.

---

## 2. Problem statement

Without a Business domain, future modules will independently collect and duplicate business identity, contact details, website, public profile URLs, country, timezone, location, service area, services, primary service, and onboarding goals.

That creates conflicting data, repeated onboarding, brittle AI prompts, and module-specific assumptions.

The Business Core establishes one aggregate that other modules reference.

---

## 3. Goals

### 3.1 Product goals

A new customer must be able to:

- Start onboarding after registration.
- Select one or two primary outcomes.
- Create a business profile.
- Add one primary location or service area.
- Add at least one structured service.
- Add available public asset links.
- Request an asynchronous initial analysis.
- See an explainable business-completeness result.
- Complete one safe first-value setup action.
- Finish onboarding and enter a populated dashboard.

An existing customer must be able to:

- Continue using the application without being forcibly redirected after deployment.
- Open Business setup voluntarily.
- Create and edit a primary business.
- Complete onboarding later.

### 3.2 Engineering goals

- Follow existing repository conventions.
- Use string-backed PHP enums and model casts.
- Use transactions for aggregate writes.
- Enforce customer ownership everywhere.
- Make onboarding idempotent.
- Prevent duplicate primary businesses, locations, and services through application-level invariants.
- Emit domain events after successful commits.
- Queue analysis through Laravel’s queue system and remain Horizon-compatible.
- Support incremental expansion without destructive schema redesign.

---

## 4. Non-goals

RFC-001 does not implement:

- Full Opportunity Engine persistence or prioritization.
- AI-generated recommendations.
- AI Worker chat or routing.
- Website crawling or external SEO auditing.
- Google OAuth or Google Business Profile API access.
- Google Analytics or Search Console connections.
- Review ingestion.
- Multi-location management UI.
- Business switching UI.
- Public REST API versioning.
- Website publishing.
- Advanced packages, products, taxes, or inventory.
- Growth Score or domain health scores beyond profile completeness.
- Automatic public changes.
- Destructive business deletion.

---

## 5. Architectural decisions

### AD-001 — Use `Business`, not `Organization`

The internal model and user-facing label are both `Business`.

### AD-002 — Customer has many businesses

```text
Customer
└── hasMany Business
```

The initial interface exposes one primary business. The database must not enforce one business per customer.

**Tenant key convention.** In Ultimate SMS, customer-owned domain records key ownership off the authenticated user's ID (`users.id`), not `customers.id` — `customers` is a secondary 1:1 profile table keyed by `user_id`, not the tenant-identity table. `businesses.customer_id` and `customer_onboardings.customer_id` follow this existing convention and reference `users.id`. The column name stays `customer_id` for readability; only what it references changes. Accordingly, `Customer` model relationships to `Business` and `CustomerOnboarding` use `user_id` as the local key:

```php
$this->hasMany(Business::class, 'customer_id', 'user_id');
$this->hasOne(Business::class, 'customer_id', 'user_id')->where('is_primary', true);
$this->hasOne(CustomerOnboarding::class, 'customer_id', 'user_id');
```

And the inverse relationships resolve back through `user_id`:

```php
$this->belongsTo(Customer::class, 'customer_id', 'user_id');
```

### AD-003 — Structured children

```text
Business
├── hasMany BusinessLocation
└── hasMany BusinessService
```

Location and service data must not exist only as free-form text on `businesses`.

### AD-004 — String columns, PHP enums

Do not use database-native ENUM columns. Store type and status values as indexed strings and cast them to string-backed PHP enums.

### AD-005 — Existing architecture remains authoritative

Use:

```text
Controller
↓
Repository contract / Eloquent repository
↓
Library orchestration
↓
Model
```

Do not introduce a new application-wide service pattern.

### AD-006 — Initial analysis uses owned data only

RFC-001’s analysis is a profile-completeness analysis. It must not claim to have scanned websites, rankings, reviews, or Google assets.

### AD-007 — Existing customers are not forced through onboarding

Mandatory onboarding applies only when an onboarding row exists with `is_required = true`. The registration flow creates that row for new customers only when the release flag is enabled.

---

## 6. Domain model

```text
Customer
├── hasMany Business
└── hasOne CustomerOnboarding

Business
├── belongsTo Customer
├── hasMany BusinessLocation
└── hasMany BusinessService

CustomerOnboarding
├── belongsTo Customer
└── belongsTo Business (nullable until business creation)
```

### 6.1 Aggregate root

`Business` is the aggregate root for identity, locations, and services.

Writes affecting more than one aggregate record must be coordinated through `BusinessManager` inside a database transaction.

### 6.2 Primary invariants

For each customer:

- Zero or one business may have `is_primary = true`.
- Creating the first business makes it primary.
- Setting a business primary unsets all sibling businesses in the same transaction.

For each business:

- Zero or one location may have `is_primary = true`.
- Completed onboarding requires exactly one primary location.
- Zero or one active service may have `is_primary = true`.
- Completed onboarding requires at least one active service and exactly one primary active service.

---

## 7. Enumerations

Create string-backed enums under the project’s existing enum namespace. When none exists, use `app/Enums/Business`.

### 7.1 `BusinessStatus`

```php
enum BusinessStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Inactive = 'inactive';
}
```

### 7.2 `BusinessIndustry`

```php
enum BusinessIndustry: string
{
    case PhotoBoothService = 'photo_booth_service';
    case EventServices = 'event_services';
    case Photographer = 'photographer';
    case WeddingVendor = 'wedding_vendor';
    case HomeServices = 'home_services';
    case ProfessionalServices = 'professional_services';
    case Other = 'other';
}
```

### 7.3 `BusinessServiceStatus`

```php
enum BusinessServiceStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
}
```

### 7.4 `BusinessServiceMode`

```php
enum BusinessServiceMode: string
{
    case Storefront = 'storefront';
    case ServiceArea = 'service_area';
    case Hybrid = 'hybrid';
    case Online = 'online';
}
```

### 7.5 `OnboardingStatus`

```php
enum OnboardingStatus: string
{
    case NotStarted = 'not_started';
    case Started = 'started';
    case AnalysisPending = 'analysis_pending';
    case ResultsReady = 'results_ready';
    case Completed = 'completed';
    case Dismissed = 'dismissed';
    case Failed = 'failed';
}
```

### 7.6 `OnboardingStep`

```php
enum OnboardingStep: string
{
    case Goals = 'goals';
    case Business = 'business';
    case Location = 'location';
    case Services = 'services';
    case Assets = 'assets';
    case Analysis = 'analysis';
    case Results = 'results';
    case Complete = 'complete';
}
```

### 7.7 `BusinessGoal`

```php
enum BusinessGoal: string
{
    case LeadGeneration = 'lead_generation';
    case LocalSeo = 'local_seo';
    case WebsiteConversion = 'website_conversion';
    case Reputation = 'reputation';
    case SalesFollowup = 'sales_followup';
    case Automation = 'automation';
}
```

---

## 8. Database schema

Use existing migration and foreign-key conventions. All tables use `id`, `created_at`, and `updated_at`.

### 8.1 `businesses`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `uid` | uuid | no | auto | Public identifier for route binding (`App\Library\Traits\HasUid`) |
| `customer_id` | bigint unsigned FK | no | — | Owner. References `users.id` (Ultimate SMS's established tenant convention), not `customers.id` |
| `name` | varchar(255) | no | — | Trading/display name |
| `industry` | varchar(64) | no | — | `BusinessIndustry` |
| `industry_other` | varchar(255) | yes | null | Required when industry is `other` |
| `description` | text | yes | null | Plain text |
| `email` | varchar(255) | yes | null | Public business email |
| `phone` | varchar(50) | yes | null | Preserve entered international format |
| `website_url` | varchar(2048) | yes | null | Normalized absolute URL |
| `canonical_domain` | varchar(255) | yes | null | Derived lower-case host without `www.` |
| `google_business_profile_url` | varchar(2048) | yes | null | Public profile URL |
| `facebook_url` | varchar(2048) | yes | null | Public profile URL |
| `instagram_url` | varchar(2048) | yes | null | Public profile URL |
| `country_code` | char(2) | no | — | ISO alpha-2 |
| `timezone` | varchar(64) | no | — | IANA timezone |
| `currency_code` | char(3) | no | — | ISO 4217 |
| `status` | varchar(32) | no | `draft` | `BusinessStatus` |
| `is_primary` | boolean | no | false | Application-enforced |
| `activated_at` | timestamp | yes | null | First activation time |
| timestamps | — | no | — | |

Indexes:

- `customer_id`
- `status`
- `canonical_domain`
- Composite: `customer_id, is_primary`
- Composite: `customer_id, status`

Foreign key:

- `customer_id` references `users.id`, matching Ultimate SMS's established tenant-ownership convention (every other customer-owned table keys ownership off the authenticated user's ID, not the `customers` profile table). Cascade on delete.

Do not globally unique `canonical_domain`. Do not accept it from requests. No soft deletes in RFC-001; deactivation uses status.

### 8.2 `business_locations`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `uid` | uuid | no | auto | Public identifier for route binding (`App\Library\Traits\HasUid`) |
| `business_id` | bigint unsigned FK | no | — | Parent |
| `name` | varchar(255) | no | `Primary Location` | Internal label |
| `service_mode` | varchar(32) | no | — | `BusinessServiceMode` |
| `address_line_1` | varchar(255) | yes | null | |
| `address_line_2` | varchar(255) | yes | null | |
| `city` | varchar(120) | yes | null | Required except online-only |
| `region` | varchar(120) | yes | null | State/province/region |
| `postal_code` | varchar(32) | yes | null | |
| `country_code` | char(2) | no | — | ISO alpha-2 |
| `latitude` | decimal(10,7) | yes | null | Not populated in RFC-001 |
| `longitude` | decimal(10,7) | yes | null | Not populated in RFC-001 |
| `public_address` | boolean | no | false | Public-display permission |
| `service_radius_km` | unsigned small integer | yes | null | 1–1000 |
| `service_area_cities` | json | yes | null | Normalized city strings |
| `is_primary` | boolean | no | false | Application-enforced |
| timestamps | — | no | — | |

Indexes:

- `business_id`
- Composite: `business_id, is_primary`
- Composite: `country_code, region, city`

Foreign key:

- `business_id` → `businesses.id`, cascade delete.

### 8.3 `business_services`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `uid` | uuid | no | auto | Public identifier for route binding (`App\Library\Traits\HasUid`) |
| `business_id` | bigint unsigned FK | no | — | Parent |
| `name` | varchar(255) | no | — | Display name |
| `slug` | varchar(255) | no | — | Unique per business |
| `description` | text | yes | null | Plain text |
| `is_primary` | boolean | no | false | One active primary |
| `starting_price` | decimal(12,2) | yes | null | Non-negative |
| `currency_code` | char(3) | yes | null | Falls back to business currency |
| `status` | varchar(32) | no | `active` | `BusinessServiceStatus` |
| `sort_order` | unsigned small integer | no | 0 | |
| timestamps | — | no | — | |

Indexes and constraints:

- `business_id`
- Composite: `business_id, status`
- Composite: `business_id, is_primary`
- Unique: `business_id, slug`

Foreign key:

- `business_id` → `businesses.id`, cascade delete.

### 8.4 `customer_onboardings`

| Column | Type | Null | Default | Notes |
|---|---|---:|---|---|
| `id` | bigint unsigned | no | auto | Primary key |
| `customer_id` | bigint unsigned FK | no | — | Unique account onboarding. References `users.id`, same convention as `businesses.customer_id` |
| `business_id` | bigint unsigned FK | yes | null | Set after business creation |
| `is_required` | boolean | no | false | Redirect requirement |
| `status` | varchar(32) | no | `not_started` | `OnboardingStatus` |
| `current_step` | varchar(32) | no | `goals` | `OnboardingStep` |
| `primary_goals` | json | yes | null | Maximum two goals |
| `completed_steps` | json | yes | null | Unique ordered step keys |
| `metadata` | json | yes | null | Non-authoritative UI metadata |
| `analysis_version` | unsigned integer | no | 0 | Increment each run |
| `analysis_payload` | json | yes | null | Versioned snapshot |
| `analysis_error` | text | yes | null | Safe error summary |
| `analysis_started_at` | timestamp | yes | null | |
| `analysis_completed_at` | timestamp | yes | null | |
| `first_value_action_key` | varchar(100) | yes | null | Whitelisted action |
| `first_value_action_completed_at` | timestamp | yes | null | |
| `started_at` | timestamp | yes | null | |
| `completed_at` | timestamp | yes | null | |
| `dismissed_at` | timestamp | yes | null | |
| `last_activity_at` | timestamp | yes | null | |
| timestamps | — | no | — | |

Indexes and constraints:

- Unique: `customer_id`
- `business_id`
- Composite: `status, is_required`
- `last_activity_at`

`customer_onboardings` does not have a `uid` column. It is an internal state record, not a routable/shareable entity, and is not addressed by future controllers/routes the way `businesses`, `business_locations`, and `business_services` are.

Foreign keys:

- `customer_id` → `users.id`, matching Ultimate SMS's established tenant-ownership convention. Cascade on delete.
- `business_id` → `businesses.id`, null on delete.

Permitted `metadata` keys in RFC-001:

```json
{
  "referrer": null,
  "industry_suggestions_version": 1,
  "last_results_viewed_at": null
}
```

Canonical business identity data must not be stored in `metadata`.

---

## 9. Models and relationships

Create:

- `Business`
- `BusinessLocation`
- `BusinessService`
- `CustomerOnboarding`

### `Business`

```php
public function customer(): BelongsTo; // belongsTo(Customer::class, 'customer_id', 'user_id')
public function locations(): HasMany;
public function primaryLocation(): HasOne;
public function services(): HasMany;
public function primaryService(): HasOne;
```

`primaryLocation()` constrains `is_primary = true`. `primaryService()` constrains both `is_primary = true` and active status.

`Business`, `BusinessLocation`, and `BusinessService` use `App\Library\Traits\HasUid` (the project's existing public-identifier trait) so each row gets an auto-generated, unique `uid` for future route binding, consistent with how the rest of Ultimate SMS exposes routable entities. `CustomerOnboarding` does not use `HasUid` — it is an internal state record, not independently routable.

### Existing `Customer`

Add without renaming existing relationships. Because `businesses.customer_id` and `customer_onboardings.customer_id` reference `users.id` (see AD-002), these relationships use `user_id` as the local key:

```php
public function businesses(): HasMany; // hasMany(Business::class, 'customer_id', 'user_id')
public function primaryBusiness(): HasOne; // hasOne(Business::class, 'customer_id', 'user_id')->where('is_primary', true)
public function onboarding(): HasOne; // hasOne(CustomerOnboarding::class, 'customer_id', 'user_id')
```

### Casts

`Business`:

```php
'status' => BusinessStatus::class,
'industry' => BusinessIndustry::class,
'is_primary' => 'boolean',
'activated_at' => 'datetime',
```

`BusinessLocation`:

```php
'service_mode' => BusinessServiceMode::class,
'public_address' => 'boolean',
'is_primary' => 'boolean',
'service_area_cities' => 'array',
'latitude' => 'decimal:7',
'longitude' => 'decimal:7',
```

`BusinessService`:

```php
'status' => BusinessServiceStatus::class,
'is_primary' => 'boolean',
'starting_price' => 'decimal:2',
```

`CustomerOnboarding`:

```php
'is_required' => 'boolean',
'status' => OnboardingStatus::class,
'current_step' => OnboardingStep::class,
'primary_goals' => 'array',
'completed_steps' => 'array',
'metadata' => 'array',
'analysis_payload' => 'array',
'analysis_started_at' => 'datetime',
'analysis_completed_at' => 'datetime',
'first_value_action_completed_at' => 'datetime',
'started_at' => 'datetime',
'completed_at' => 'datetime',
'dismissed_at' => 'datetime',
'last_activity_at' => 'datetime',
```

Follow existing guarded/fillable convention. Never pass unfiltered request data to model `create()` or `update()`.

---

## 10. Repository contracts

Use the exact naming pattern already present in Ultimate SMS. Logical contracts are mandatory even when filenames differ slightly.

### 10.1 `BusinessRepositoryInterface`

`$customerId` throughout this contract is the tenant key stored in `businesses.customer_id`, i.e. `users.id` (`Customer::$user_id`), per AD-002 — not `customers.id`.

```php
public function findById(int $id): ?Business;
public function findOwnedByCustomer(int $businessId, int $customerId): ?Business;
public function findPrimaryByCustomer(int $customerId): ?Business;
public function createForCustomer(Customer $customer, array $attributes): Business;
public function update(Business $business, array $attributes): Business;
public function setPrimary(Business $business): Business;
public function updateStatus(Business $business, BusinessStatus $status): Business;
```

### 10.2 `BusinessLocationRepositoryInterface`

```php
public function findPrimary(Business $business): ?BusinessLocation;
public function upsertPrimary(Business $business, array $attributes): BusinessLocation;
public function setPrimary(BusinessLocation $location): BusinessLocation;
```

### 10.3 `BusinessServiceRepositoryInterface`

```php
public function activeForBusiness(Business $business): Collection;
public function findOwned(Business $business, int $serviceId): ?BusinessService;
public function syncForBusiness(Business $business, array $services): Collection;
public function setPrimary(BusinessService $service): BusinessService;
```

### 10.4 `CustomerOnboardingRepositoryInterface`

`customer_onboardings.customer_id` follows the same `users.id` tenant convention as `businesses.customer_id` (see AD-002); implementations resolve it via `Customer::$user_id`.

```php
public function findByCustomer(Customer $customer): ?CustomerOnboarding;
public function startForCustomer(Customer $customer, bool $required): CustomerOnboarding;
public function attachBusiness(CustomerOnboarding $onboarding, Business $business): CustomerOnboarding;
public function markStepComplete(CustomerOnboarding $onboarding, OnboardingStep $step, OnboardingStep $nextStep): CustomerOnboarding;
public function startAnalysis(CustomerOnboarding $onboarding, int $version): CustomerOnboarding;
public function completeAnalysis(CustomerOnboarding $onboarding, int $version, array $payload): CustomerOnboarding;
public function failAnalysis(CustomerOnboarding $onboarding, int $version, string $safeError): CustomerOnboarding;
public function recordFirstValueAction(CustomerOnboarding $onboarding, string $actionKey): CustomerOnboarding;
public function complete(CustomerOnboarding $onboarding): CustomerOnboarding;
public function dismiss(CustomerOnboarding $onboarding): CustomerOnboarding;
```

Bind all interfaces to Eloquent implementations in the existing repository provider. Do not create a competing provider when an appropriate one already exists.

---

## 11. Library orchestration

Create under `app/Library/Business` or the nearest existing convention:

- `BusinessManager`
- `OnboardingManager`
- `InitialBusinessSnapshotBuilder`
- `OnboardingActionExecutor`
- `UrlNormalizer`

### 11.1 `BusinessManager`

Responsibilities:

- Create the first or additional business.
- Make the first business primary.
- Update identity.
- Upsert the primary location.
- Sync services.
- Enforce one-primary invariants.
- Normalize URLs and derive canonical domain.
- Dispatch domain events after commit.

Required methods:

```php
public function createOrUpdateOnboardingBusiness(Customer $customer, ?Business $business, array $attributes): Business;
public function updateBusiness(Customer $customer, Business $business, array $attributes): Business;
public function upsertPrimaryLocation(Customer $customer, Business $business, array $attributes): BusinessLocation;
public function syncServices(Customer $customer, Business $business, array $services): Collection;
```

Every method re-checks ownership even when the controller already authorized the request.

### 11.2 `OnboardingManager`

Responsibilities:

- Start or resume onboarding.
- Resolve the current allowed step.
- Prevent forward-step skipping.
- Mark steps complete idempotently.
- Attach the business.
- Request analysis.
- Complete onboarding.
- Track first-value action.

Required methods:

```php
public function start(Customer $customer, bool $required = false): CustomerOnboarding;
public function resolveStep(CustomerOnboarding $onboarding, ?OnboardingStep $requested = null): OnboardingStep;
public function completeStep(CustomerOnboarding $onboarding, OnboardingStep $completed, OnboardingStep $next): CustomerOnboarding;
public function requestAnalysis(CustomerOnboarding $onboarding): CustomerOnboarding;
public function recordFirstValueAction(CustomerOnboarding $onboarding, string $actionKey): CustomerOnboarding;
public function complete(CustomerOnboarding $onboarding): CustomerOnboarding;
```

### 11.3 `UrlNormalizer`

Behavior:

- Trim whitespace.
- Convert blank to null.
- Prepend `https://` when scheme is missing.
- Permit only HTTP and HTTPS.
- Lowercase the host.
- Preserve path/query for profile URLs.
- Remove a trailing slash only when path is `/`.
- Derive canonical domain by stripping `www.`.
- Reject malformed or credential-bearing URLs.
- Perform no remote request.

### 11.4 `InitialBusinessSnapshotBuilder`

Return structure:

```php
[
    'version' => 1,
    'generated_at' => now()->toIso8601String(),
    'profile_completeness_percent' => 0,
    'facts' => [],
    'findings' => [],
]
```

Finding structure:

```php
[
    'fingerprint' => 'business:123:missing_phone',
    'title' => 'Add your business phone number',
    'reason' => 'Customers and future platform modules need a reliable way to contact the business.',
    'impact' => 'medium',
    'effort' => 'low',
    'confidence' => 1.0,
    'worker_key' => 'business_advisor',
    'can_ai_prepare' => false,
    'action_key' => 'add_phone',
    'action_step' => 'business',
]
```

Never include a finding not proven by current stored data.

---

## 12. Profile-completeness calculation

This is setup completeness, not Growth Score.

| Fact | Weight |
|---|---:|
| Business name | 10 |
| Industry | 10 |
| Public email | 8 |
| Phone | 10 |
| Website URL | 10 |
| Description of at least 50 characters | 8 |
| Country and timezone | 8 |
| Primary location | 12 |
| Required location fields for service mode | 8 |
| At least one active service | 8 |
| Active primary service | 8 |

Total: 100. Round to nearest integer.

### 12.1 Initial finding rules

| Suffix | Trigger | Impact | Action |
|---|---|---|---|
| `missing_phone` | Phone blank | medium | `add_phone` |
| `missing_email` | Email blank | low | `add_email` |
| `missing_website` | Website blank | high when local SEO/website goal; otherwise medium | `add_website` |
| `missing_description` | Description blank or under 50 chars | medium | `add_description` |
| `missing_primary_location` | No primary location | high | `add_location` |
| `incomplete_primary_location` | Required location fields missing | high | `complete_location` |
| `missing_services` | No active service | high | `add_service` |
| `missing_primary_service` | Active services but no primary | high | `confirm_primary_service` |
| `missing_gbp_url` | GBP URL blank and local SEO/reputation goal selected | high | `add_gbp_url` |
| `missing_facebook_url` | Facebook blank | low | `add_facebook_url` |
| `missing_instagram_url` | Instagram blank for event/photo/wedding industries | low | `add_instagram_url` |

Return at most five initial findings, sorted by impact, goal relevance, effort, then fingerprint. Direct missing-field findings have confidence `1.0`.

---

## 13. Queue job

Create `BuildInitialBusinessSnapshot` implementing `ShouldQueue`.

```php
public int $tries = 3;
public array $backoff = [10, 60, 300];
```

Queue name:

```php
config('business.onboarding.analysis_queue')
```

### Idempotency

The job receives onboarding ID and expected analysis version. Before writing:

- Reload onboarding.
- Exit when `analysis_version` differs.
- Exit when onboarding is completed or dismissed.
- Verify attached business still belongs to the customer.

### Success

- Store payload.
- Set status `results_ready`.
- Set step `results`.
- Clear error.
- Set completion timestamp.
- Dispatch `InitialBusinessAnalysisCompleted`.

### Failure

In `failed(Throwable $exception)`:

- Store a safe error summary without stack trace.
- Set status `failed`.
- Keep step `analysis`.
- Dispatch `InitialBusinessAnalysisFailed`.
- Log the full exception internally.

---

## 14. First-value actions

`OnboardingActionExecutor` uses an explicit allowlist:

- `add_phone`
- `add_email`
- `add_website`
- `add_description`
- `add_location`
- `complete_location`
- `add_service`
- `confirm_primary_service`
- `add_gbp_url`
- `add_facebook_url`
- `add_instagram_url`

Each action either redirects to the relevant step or processes a tightly validated inline form.

After successful completion:

- Rebuild the snapshot synchronously.
- Confirm the selected finding no longer exists.
- Record the action key and completion time.
- Set current step to `complete`.

A click alone never marks an action complete.

---

## 15. Domain events

Create immutable events carrying IDs and scalar metadata:

- `BusinessCreated`
- `BusinessUpdated`
- `BusinessPrimaryLocationUpdated`
- `BusinessServicesSynced`
- `CustomerOnboardingStarted`
- `CustomerOnboardingStepCompleted`
- `InitialBusinessAnalysisRequested`
- `InitialBusinessAnalysisCompleted`
- `InitialBusinessAnalysisFailed`
- `CustomerOnboardingCompleted`

Rules:

- Dispatch after successful commit.
- `BusinessUpdated` includes changed field names, not sensitive old/new values.
- Events must be safe for queued listeners.
- Do not create empty listeners solely to make events appear used.

---

## 16. Authorization

### Customer

May view/create/update owned businesses, locations, services, and onboarding.

May not:

- Access another customer’s records.
- Delete businesses.
- Set another customer’s business primary.
- Directly set analysis payload/status.
- Directly activate through mass assignment.

### Admin

Authorized backend administrators may list, view, edit identity, change status, and inspect onboarding state/errors. Use existing backend-access middleware and gates. Do not create another role system.

---

## 17. Form requests and validation

Create separate Form Request classes.

### `UpdateOnboardingGoalsRequest`

```text
primary_goals: required array min:1 max:2
primary_goals.*: distinct valid BusinessGoal
```

### `UpsertBusinessIdentityRequest`

```text
name: required string min:2 max:255
industry: required valid BusinessIndustry
industry_other: nullable string max:255; required_if industry=other
description: nullable string max:5000
email: nullable email:rfc max:255
phone: nullable string max:50
website_url: nullable string max:2048
country_code: required string size:2 valid supported country
timezone: required string max:64 valid IANA timezone
currency_code: required string size:3 valid supported currency
```

Preparation:

- Uppercase country/currency.
- Trim strings.
- Empty optional strings become null.
- Normalize website URL.
- Do not use DNS email validation.

### `UpsertBusinessLocationRequest`

```text
name: nullable string max:255
service_mode: required valid BusinessServiceMode
address_line_1: nullable string max:255
address_line_2: nullable string max:255
city: nullable string max:120
region: nullable string max:120
postal_code: nullable string max:32
country_code: required string size:2
public_address: required boolean
service_radius_km: nullable integer min:1 max:1000
service_area_cities: nullable array max:50
service_area_cities.*: string max:120 distinct
```

Conditional requirements:

- storefront/hybrid: street, city, region, country required.
- service area: city, region, country required; street optional.
- online: country required; radius null; address optional.

### `SyncBusinessServicesRequest`

```text
services: required array min:1 max:50
services.*.id: nullable integer
services.*.name: required string min:2 max:255
services.*.description: nullable string max:5000
services.*.is_primary: required boolean
services.*.starting_price: nullable numeric min:0 max:99999999.99
services.*.currency_code: nullable string size:3
services.*.status: nullable valid BusinessServiceStatus
services.*.sort_order: nullable integer min:0 max:65535
```

Additional rules:

- At most one submitted active primary.
- Submitted IDs must belong to the business.
- If none is primary, manager makes first active service primary.
- At least one active service remains.

### `UpdateBusinessAssetsRequest`

```text
website_url: nullable string max:2048
google_business_profile_url: nullable string max:2048
facebook_url: nullable string max:2048
instagram_url: nullable string max:2048
```

### `UpdateBusinessRequest`

May update identity/contact/profile fields but not customer ID, primary flag, canonical domain, status, or activation timestamp.

### `UpdateBusinessStatusRequest`

Admin only:

```text
status: required valid BusinessStatus
```

---

## 18. Service synchronization semantics

Execute in a transaction.

1. Normalize names.
2. Generate unique slug per business.
3. Update existing submitted IDs only when owned.
4. Create new entries.
5. Omitted active services become inactive, not deleted.
6. Exactly one active submitted service is primary.
7. If primary becomes inactive, choose first active by sort order.
8. Preserve stable IDs.
9. Dispatch one aggregate sync event after commit.

Slug collisions:

```text
digital-photo-booth
digital-photo-booth-2
digital-photo-booth-3
```

Do not change an existing slug merely because its name changes in RFC-001.

---

## 19. Controllers

Controllers validate, authorize, call managers, and return responses.

### Customer `BusinessOnboardingController`

```php
show(Request $request, ?string $step = null)
storeGoals(UpdateOnboardingGoalsRequest $request)
storeBusiness(UpsertBusinessIdentityRequest $request)
storeLocation(UpsertBusinessLocationRequest $request)
storeServices(SyncBusinessServicesRequest $request)
storeAssets(UpdateBusinessAssetsRequest $request)
requestAnalysis(Request $request)
analysisStatus(Request $request)
completeAction(CompleteOnboardingActionRequest $request)
complete(Request $request)
```

### Customer `BusinessController`

```php
edit(Request $request)
update(UpdateBusinessRequest $request)
```

V1 resolves the primary business; if none exists, redirect to onboarding.

### Admin `BusinessController`

```php
index(Request $request)
show(Business $business)
edit(Business $business)
update(AdminUpdateBusinessRequest $request, Business $business)
updateStatus(UpdateBusinessStatusRequest $request, Business $business)
```

Admin index supports search, status filter, industry filter, and pagination. No deletion action.

---

## 20. Routes

Adapt middleware/prefixes to existing route files. Required route semantics and names:

### Customer

```php
Route::get('/onboarding/{step?}', [BusinessOnboardingController::class, 'show'])->name('onboarding.show');
Route::post('/onboarding/goals', [BusinessOnboardingController::class, 'storeGoals'])->name('onboarding.goals.store');
Route::post('/onboarding/business', [BusinessOnboardingController::class, 'storeBusiness'])->name('onboarding.business.store');
Route::post('/onboarding/location', [BusinessOnboardingController::class, 'storeLocation'])->name('onboarding.location.store');
Route::post('/onboarding/services', [BusinessOnboardingController::class, 'storeServices'])->name('onboarding.services.store');
Route::post('/onboarding/assets', [BusinessOnboardingController::class, 'storeAssets'])->name('onboarding.assets.store');
Route::post('/onboarding/analysis', [BusinessOnboardingController::class, 'requestAnalysis'])->middleware('throttle:5,60')->name('onboarding.analysis.request');
Route::get('/onboarding/analysis/status', [BusinessOnboardingController::class, 'analysisStatus'])->middleware('throttle:60,1')->name('onboarding.analysis.status');
Route::post('/onboarding/action', [BusinessOnboardingController::class, 'completeAction'])->name('onboarding.action.complete');
Route::post('/onboarding/complete', [BusinessOnboardingController::class, 'complete'])->name('onboarding.complete');
Route::get('/business', [BusinessController::class, 'edit'])->name('business.edit');
Route::put('/business', [BusinessController::class, 'update'])->name('business.update');
```

Do not duplicate an existing `customer.` name prefix.

### Admin

```php
Route::resource('businesses', BusinessController::class)->only(['index', 'show', 'edit', 'update']);
Route::patch('businesses/{business}/status', [BusinessController::class, 'updateStatus'])->name('businesses.status.update');
```

### Registration integration

At the existing successful customer-registration completion point:

```php
if (config('business.onboarding.enabled')
    && config('business.onboarding.require_for_new_customers')) {
    $onboardingManager->start($customer, required: true);
}
```

Do not duplicate customer creation logic.

---

## 21. Middleware

Create `EnsureRequiredBusinessOnboardingIsComplete`.

Behavior:

1. Ignore unauthenticated requests.
2. Resolve current customer using existing tenant conventions.
3. Continue when no onboarding row exists.
4. Continue when `is_required = false`.
5. Continue when completed or dismissed.
6. Otherwise redirect to resolved onboarding step.
7. Avoid loops for onboarding, logout, verification, subscription, and support routes.

Initially apply only to the customer dashboard route, not every customer route.

---

## 22. Onboarding state machine

Allowed status transitions:

```text
not_started → started
started → analysis_pending
analysis_pending → results_ready
analysis_pending → failed
failed → analysis_pending
results_ready → completed
started → dismissed
results_ready → dismissed
```

Step order:

```text
goals → business → location → services → assets → analysis → results → complete
```

Rules:

- Completed earlier steps remain revisitable.
- Users cannot jump beyond first incomplete prerequisite.
- Re-submission updates same records.
- `completed_steps` contains unique values.
- Editing prior steps after results may invalidate the snapshot.
- Completion requires business, primary location, active primary service, analysis payload, and one completed first-value action unless no findings exist.

---

## 23. Views

Use existing customer/admin Blade layouts and components.

Recommended customer onboarding structure:

```text
resources/views/customer/onboarding/
├── layout.blade.php
├── show.blade.php
└── steps/
    ├── goals.blade.php
    ├── business.blade.php
    ├── location.blade.php
    ├── services.blade.php
    ├── assets.blade.php
    ├── analysis.blade.php
    ├── results.blade.php
    └── complete.blade.php
```

Required behavior:

- Responsive.
- Step progress, not fake percentage.
- Validation does not lose input.
- Required/optional fields are clear.
- Only assets can be skipped.
- Analysis has accessible status text.
- Poll status with backoff.
- Results show only stored claims.
- No fake SEO, review, ranking, or revenue data.
- Escape all user-entered output.

Customer business page:

```text
resources/views/customer/business/edit.blade.php
```

Admin:

```text
resources/views/admin/businesses/index.blade.php
resources/views/admin/businesses/show.blade.php
resources/views/admin/businesses/edit.blade.php
```

---

## 24. Configuration

Create `config/business.php`:

```php
return [
    'onboarding' => [
        'enabled' => env('BUSINESS_ONBOARDING_ENABLED', false),
        'require_for_new_customers' => env('BUSINESS_ONBOARDING_REQUIRE_NEW_CUSTOMERS', false),
        'analysis_queue' => env('BUSINESS_ONBOARDING_ANALYSIS_QUEUE', 'default'),
    ],
];
```

Add variables to `.env.example`. Defaults remain disabled and non-mandatory.

---

## 25. Logging and observability

Use structured context with customer, business, onboarding, analysis version, and duration IDs. Never log full request payloads, credentials, tokens, or user-visible stack traces.

If a metrics system already exists, record onboarding started/completed and analysis requested/completed/failed. Do not introduce a new vendor.

---

## 26. Security requirements

- Tenant-scope every customer business lookup.
- Route model binding alone is insufficient.
- Requests authorize target resources.
- Managers re-check ownership.
- Status endpoint exposes only current customer state.
- URLs are untrusted strings.
- No URL fetching in RFC-001, preventing SSRF.
- CSRF protects all mutations.
- Only HTTP/HTTPS schemes.
- No arbitrary action keys.
- User input cannot control class/job/event names.

---

## 27. Performance requirements

- Eager-load primary location/service for results.
- Avoid per-service queries.
- Paginate admin index.
- Analysis should normally complete under one second with no external I/O.
- Status response stays compact.
- Use `exists()`/`first()` instead of unnecessary `get()`.
- Keep transactions limited to related writes.

---

## 28. Analysis status response

Pending:

```json
{
  "status": "analysis_pending",
  "analysis_version": 2,
  "current_step": "analysis",
  "completed": false,
  "redirect_url": null,
  "error": null
}
```

Complete:

```json
{
  "status": "results_ready",
  "analysis_version": 2,
  "current_step": "results",
  "completed": true,
  "redirect_url": "/customer/onboarding/results",
  "error": null
}
```

Failed:

```json
{
  "status": "failed",
  "analysis_version": 2,
  "current_step": "analysis",
  "completed": false,
  "redirect_url": null,
  "error": "We could not finish the analysis. Please retry."
}
```

Never return stack traces or raw exception messages.

---

## 29. Existing customer release strategy

Existing customers without onboarding rows:

- Are not redirected.
- Keep current dashboard access.
- May voluntarily open setup.

When they open onboarding:

- Create `is_required = false` onboarding.
- Prefill only from unambiguous Customer fields.
- Never overwrite existing customer data.
- Present values for confirmation.

Do not create guessed Business records for every customer in this RFC.

---

## 30. Migration and deployment

Migration order:

1. businesses
2. business_locations
3. business_services
4. customer_onboardings

Rollback in reverse.

Deployment:

1. Deploy with flags disabled.
2. Run migrations.
3. Refresh config cache.
4. Restart Horizon/workers.
5. Run tests.
6. Test voluntary onboarding internally.
7. Enable onboarding.
8. Test new registration with mandatory flag false.
9. Enable mandatory onboarding for new customers.
10. Monitor failures and drop-off.

Prefer forward fixes over dropping populated production tables.

---

## 31. Tests

Use the project’s existing PHPUnit or Pest convention; do not mix.

### Unit

`UrlNormalizerTest`:

- Adds HTTPS.
- Preserves HTTP/HTTPS.
- Lowercases host.
- Strips `www.` from canonical domain.
- Rejects schemes and credentials.
- Converts blank to null.

`InitialBusinessSnapshotBuilderTest`:

- Complete profile scores 100.
- Partial scoring is deterministic.
- Findings are evidence-backed.
- Goal relevance changes impact.
- Maximum five findings.
- Stable fingerprints.
- No external-analysis claims.

`OnboardingManagerTest`:

- Idempotent start.
- Prevents step skipping.
- Unique completed steps.
- Analysis version increments.
- Completion prerequisites enforced.
- Zero-finding completion works.
- First-value action required when findings exist.

### Repository

- First business becomes primary.
- New primary unsets sibling.
- Primary location upsert does not duplicate.
- Service sync creates/updates/inactivates.
- Cross-business service IDs rejected.
- Exactly one active primary remains.
- One onboarding per customer.
- Stale analysis cannot overwrite current result.

### Feature — customer

- New registration starts onboarding when flags enabled.
- Registration unchanged when disabled.
- Existing customer without row not redirected.
- Required incomplete onboarding redirects dashboard.
- Completed onboarding permits dashboard.
- Resume works.
- Identity submission is idempotent.
- Canonical domain normalization persists.
- Conditional location validation covers all modes.
- At least one active service required.
- Tenant isolation enforced.
- Analysis request rate limited.
- Status is tenant-safe.
- First-value action changes real data.
- Completion prerequisites enforced.
- Successful completion activates valid draft business.
- Validation preserves input.

### Feature — admin

- Authorized admin can list/filter.
- Customer cannot access admin route.
- Admin can edit identity/status.
- No delete route.

### Job/events

- Snapshot stored.
- Stale version ignored.
- Missing business handled.
- Failure stores safe error.
- Completion event dispatched.
- No external HTTP.
- Events fire only after commit.
- Failed transaction dispatches nothing.

---

## 32. Acceptance criteria

### Database/domain

- Four migrations roll back cleanly.
- Models and relationships work.
- Enum casts are in place.
- Multiple businesses per customer supported.
- Primary invariants are transactional.

### Customer experience

- New-customer onboarding can be enabled by configuration.
- Existing customers are not forced.
- Progress persists.
- Identity, location, and services do not duplicate.
- Assets can be added or skipped.
- Analysis is asynchronous.
- Results use owned data only.
- First-value action changes real data.
- Completion redirects to dashboard.

### Architecture/quality

- Existing repository/library conventions followed.
- No new generic service layer.
- Thin controllers.
- Events after commit.
- Idempotent queue job.
- Tenant authorization in request/controller/manager layers.
- Tests and project lint/static analysis pass.
- No N+1 issue in primary screens.
- Analysis failure does not destroy onboarding data.
- Feature can be disabled without rollback.

---

## 33. File implementation map

Logical equivalents of:

```text
app/
├── Enums/Business/...
├── Events/Business/...
├── Http/Controllers/Customer/BusinessController.php
├── Http/Controllers/Customer/BusinessOnboardingController.php
├── Http/Controllers/Admin/BusinessController.php
├── Http/Middleware/EnsureRequiredBusinessOnboardingIsComplete.php
├── Http/Requests/Business/...
├── Jobs/Business/BuildInitialBusinessSnapshot.php
├── Library/Business/
│   ├── BusinessManager.php
│   ├── OnboardingManager.php
│   ├── InitialBusinessSnapshotBuilder.php
│   ├── OnboardingActionExecutor.php
│   └── UrlNormalizer.php
├── Models/
│   ├── Business.php
│   ├── BusinessLocation.php
│   ├── BusinessService.php
│   └── CustomerOnboarding.php
└── Repositories/
    ├── Contracts/...
    └── Eloquent/...

config/business.php
database/migrations/*business*.php
resources/views/customer/onboarding/...
resources/views/customer/business/edit.blade.php
resources/views/admin/businesses/...
tests/Feature/Business/...
tests/Unit/Business/...
```

Follow established singular/plural namespaces rather than duplicating structures.

---

## 34. Implementation sequence

### Milestone 1 — Domain and persistence

- Enums
- Migrations (including `customer_onboardings`)
- Models/relationships (including `CustomerOnboarding`)
- Repository contracts/implementations (including `CustomerOnboardingRepositoryInterface`)
- Provider bindings
- Repository tests (including onboarding-repository tests)

Onboarding **persistence** (the `customer_onboardings` table, the `CustomerOnboarding` model, and its repository's data-access methods — start/attach/mark-step/analysis state transitions/complete/dismiss) belongs to Milestone 1, alongside `Business`, `BusinessLocation`, and `BusinessService`. These are pure data-access operations with no step-order validation.

Stop and report.

### Milestone 2 — Business orchestration

- URL normalizer
- Business manager
- Location/service invariants
- Domain events
- Unit/transaction tests

Stop and report.

### Milestone 3 — Onboarding state

Milestone 3 begins with onboarding **orchestration/state behavior**, not repository creation (the repository already exists from Milestone 1):

- `OnboardingManager` (state-machine step validation, step-skip prevention, resolving the current allowed step)
- Registration hook
- Middleware
- Requests/routes
- State-machine tests

Stop and report.

### Milestone 4 — Customer UI

- Wizard
- Business edit page
- Resume/validation behavior
- Customer feature tests

Stop and report.

### Milestone 5 — Initial analysis

- Snapshot builder
- Queue job
- Status endpoint
- Results
- First-value actions
- Job/feature tests

Stop and report.

### Milestone 6 — Admin and release hardening

- Admin screens
- Filters/status
- Config flags
- `.env.example`
- Documentation
- Regression suite
- Final report

Do not implement all milestones in one uncontrolled pass.

---

## 35. Claude implementation prompt

```text
You are implementing RFC-001 — Business Core in the existing Ultimate SMS Laravel repository.

Read the RFC completely before changing code.

Constraints:
1. Follow the repository’s existing Laravel 12 architecture, naming conventions, repository pattern, route files, middleware, Blade components, test framework, and coding style.
2. Do not redesign the product or substitute a different architecture.
3. Do not introduce a new generic service layer. Use the existing repository + app/Library pattern.
4. Do not perform a broad repository audit.
5. Inspect only the files required to determine exact conventions for this milestone.
6. Implement only Milestone 1 first: Domain and persistence.
7. Do not begin Milestone 2.
8. Use transactions and tenant-safe ownership constraints as specified.
9. Do not use database-native ENUM columns.
10. Do not modify unrelated features.
11. Run the relevant tests and report exact results.
12. Stop after Milestone 1 and provide:
   - files created
   - files modified
   - migrations added
   - tests added
   - test results
   - assumptions
   - deviations from the RFC, if any
   - blockers requiring a product decision

RFC path:
docs/rfcs/RFC-001-BUSINESS-CORE.md

Start by reading the RFC and the minimum existing repository/model/provider files needed to match established conventions. Then implement Milestone 1 only.
```

---

## 36. Product decisions locked by this RFC

- The aggregate is `Business`.
- A customer may own multiple businesses.
- V1 operates on one primary business.
- Locations and services are structured child records.
- Onboarding has explicit persistent state.
- Existing customers are not forced into onboarding.
- Mandatory onboarding is configuration-controlled.
- Initial analysis is local profile completeness, not simulated external intelligence.
- AI cannot make public changes in RFC-001.
- Future modules consume Business data/events rather than duplicating it.
