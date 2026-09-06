# B5 — BUSINESS ANALYTICS IMPLEMENTATION CONTRACT

Status: **CONTRACT ONLY** — no product code is authorized by this document.
Base SHA: `2425b9f1b4415a6b1dbeab99070191cc3d178b35` (the merged B3 Simplified
Platform Settings result).
Branch: `agent/b5-business-analytics-contract`.
Predecessor: **B4 must merge before B5 product implementation begins** (§20).
Revision: **Correction 1** — human review of the first draft; all three open
decisions resolved (§22), the legacy route count corrected to 29 (§12.3), and
the foreign-campaign test rule replaced with one that matches the contracted
routes (§21).

Every claim below is backed by mechanical inspection of the tree at the base
SHA. Line numbers are post-B3 and were re-verified for this document.
**No product decision is left open** — §22 records all three as resolved.

---

## 0. B3 RE-VERIFICATION

The B5 reconnaissance was performed at `e7fad48`. B3 merged three commits
(`b7c244e`, `483e810`, `2425b9f`) touching 55 files. Every reconnaissance
claim was re-checked against `2425b9f`:

| Recon claim | Status after B3 |
|---|---|
| `routes/customer.php` reports group | **Unchanged.** `git diff e7fad48 2425b9f -- routes/customer.php` is empty. Group is `:382-420` (opener `:382`, closing `});` `:420`); `/view-charts` is `:422` (recon said `:423` — **corrected**). |
| `Customer\ReportsController` | **Unchanged file.** `viewReports` `:174`, `destroy` `:185`, `campaignDelete` `:1444`, `analyze` `:1543`, `postAnalyze` `:1604`, `dlrReports` `:1653`. |
| `User\UserController` (customer dashboard) | **Unchanged.** |
| `Admin\AdminBaseController`, `Admin\ReportsController` | **Unchanged.** |
| `Helper.php` nav | **Changed by B3** (admin menu). Fresh customer-menu line numbers: Hot Leads `:1032-1039`, AI Analytics `:1041-1048`, Automations `:1053`, **customer Reports submenu `:1060-1091`**. Admin Reports nav (`:827+`) is B3/admin territory and is **not** touched by B5. |
| Ghost schema (`ai_stage`, `ai_box_campaign_map`, `called`, `website_sent_at`, `followup_sent`, `ai_replied`) | **Still zero migrations.** Seven `chat_box*` migrations exist; none adds any of these columns. |
| AI Analytics / Hot Leads routes | **Survived B3 intact.** B3 removed only the *separate* "AI Brain" orphan (`AiSettingsController`, `ai_settings`) — see `routes/web.php:82-90` comment. |
| `DLRController::updateDLR` `%Delivered%` / `whereLike` defects | **Unchanged.** `customer_status = match` at `:49`; `whereLike(['status'], $message_id)` at **`:76` and `:2717`** (recon cited only `:76` — **corrected, two call sites**). |
| Campaign resend deletion | **Unchanged.** `EloquentCampaignRepository:2140-2144`. |
| `Campaigns::deliveredCount` `:251`, `contactCount` `:235`, `track_message` `:723` | **Unchanged.** |

**No B5 reconnaissance conclusion is invalidated by B3.** Two line-number
corrections and one additional `whereLike` call site are recorded above.

**One new B3 artefact B5 must honour:** `tests/Feature/Theme/ChartTokenContentTest.php`
(§13.3).

---

## 1. LOCKED PRODUCT DEFINITION

B5 v1 is **one dedicated, Business-scoped Analytics product**.

Canonical address:

```
/workspaces/{workspaceUid}/businesses/{businessUid}/analytics
```

B5 is **not**: the inherited SMS delivery log; a cross-Workspace Agency
Prospecting dashboard; a billing dashboard; a revenue/ROI dashboard; a
generic data warehouse; a reporting/export framework.

The legacy **customer** Reports product is **replaced, not preserved
alongside** B5.

Governing precedent already locked in this repository:
`docs/automation/PRODUCT-SURFACE-RETENTION-AUDIT.md:169` — *"Old Design
System M2 Slice 4 is permanently cancelled in its current form. Do not
redesign the legacy SMS-delivery Reports module. … Classification: REBUILD
FROM SCRATCH."*

---

## 2. TENANCY — LOCKED

### 2.1 Canonical address

```php
Route::prefix('{workspaceUid}/businesses/{businessUid}/analytics')
    ->name('businesses.analytics.')->group(...)
```

Registered inside the existing `workspaces` group in `routes/customer.php`,
mirroring B1 Outreach and B4 Automations exactly.

### 2.2 Mandatory per-request resolution order

Every HTTP action, without exception, reproducing
`Customer\Business\UsageBillingController::resolveViewableBusiness()`
(`:163`) verbatim:

1. `workspaceRepository->findByUid($workspaceUid)` → `abort(404)` if null.
2. `workspaceRepository->businessesForWorkspace($workspace)->firstWhere('uid', $businessUid)` → `abort(404)` if null.
3. `workspaceManager->userCanAccessBusiness((int) Auth::id(), $business)` → `abort(404)` if false.

`abort(404)` — **never 403** — so no route can be used to probe existence.

4. Every analytics query then applies `business_id = $business->id`.

### 2.3 Forbidden tenancy mechanics

- `Auth::id()` as a tenant key (capability/actor only).
- `businesses.customer_id` as HTTP authorization.
- `BusinessRepository::findPrimaryByCustomer()`.
- `LegacyBusinessResolver`.
- `OR business_id IS NULL` in any KPI predicate.
- Cross-Business aggregation of any kind.
- Any `agency_prospect*` table.

### 2.4 Bare entry route

```php
Route::get('analytics', 'Business\AnalyticsController@entry')->name('analytics.entry');
```

Standard chooser convention, verbatim from `OutreachController::entry()`
(`:70-85`) and mandated identically by B4 contract §15.2:

- **0** accessible Businesses → empty-state view.
- **exactly 1** → `redirect()->route('customer.workspaces.businesses.analytics.overview', [$workspace->uid, $business->uid])`.
- **more than 1** → chooser view.

Never guesses or infers a primary Business. With exactly one accessible
Business the redirect is a fact, not an inference.

### 2.5 Entitlement — RESOLVED MECHANICALLY

`App\Enums\Entitlement\PlatformFeature` has **no analytics case**. The 15
cases are `crm`, `conversations`, `calendar`, `forms`, `automations`,
`website_generation`, `ai_coo_basic`, `seo_basic_visibility`,
`ads_basic_visibility`, `seo_module`, `google_ads_module`,
`meta_ads_module`, `white_label`, `agency_package_capabilities`,
`prospect_outreach`.

**Decision: B5 introduces no new `PlatformFeature` case and no entitlement
gate of its own.** Reasons, both mechanical:

1. Adding a case would require a matching `workspace_plan_features` seed
   migration (`2026_08_13_120007`) and a `platform_feature_usage_classifications`
   row (`2026_08_16_120008`) — cross-cutting entitlement/billing changes that
   are on this contract's stop-list (§19).
2. Analytics is a read-only view over data the Business already owns and
   already paid to produce. The producing features are already gated;
   double-gating the view adds no authorization and one more failure mode.

**Capability:** reuse the existing `view_reports`
(`config/customer-permissions.php`, `category: 'Reports'`, `default: true`).
It already exists, is already granted, and B5 replaces the product it was
named for. **No `config/customer-permissions.php` change is authorized.**

**Capability authorization is not tenant authorization.** Every request still
performs the full §2.2 chain; `$this->authorize('view_reports')` is never
sufficient on its own.

### 2.6 Conditional sections and their gates

- **Recommendations panel (O1–O4):** rendered only when
  `config('opportunity.enabled', false)` is true — the same first-statement
  guard `UserController::opportunityPanel()` already uses.
- **Automations panel (A1–A4):** rendered only when the
  `automation_executions` table exists. The panel must degrade to absent, not
  to zeros or an error.

---

## 3. DATA SOURCES — LOCKED

**Authorized:** `reports` · `tracking_logs` · `campaigns` · `contacts` ·
`contact_groups` · `opportunities` · `opportunity_runs` ·
`automation_executions` (**after B4 merges only**).

**Forbidden as B5 v1 sources:**

| Source | Reason |
|---|---|
| `chat_boxes`, `chat_box_messages` | No `business_id` column; not in the tenancy-foundation list. Also produces duplicate rows (inbound `updateOrCreate(['user_id','from','to'])` in `DLRController:530` vs outbound `firstOrNew(['user_id','from','to','sending_server_id'])` in `EloquentCampaignRepository:492`). |
| `agency_prospect*` (6 tables) | `workspace_id` NOT NULL, no Business dimension. Different subject (§16). |
| `business_usage_*` (13 tables) | Billing administration, already presented by `UsageBillingPresenter`. Also effectively empty: every `platform_feature_usage_classifications.is_metered = false` and all three `config('usage_billing.conversations_metering.*')` keys are null by default. |
| `invoices`, `subscriptions`, `subscription_transactions`, `payment_*` | SaaS billing. `Invoices` types are `senderid`, `keyword`, `subscription`, `number` — the customer paying the platform, never the Business's own income. |
| Website / form / booking tables | Do not exist. |

### 3.1 NULL-business policy

Every KPI predicate is `business_id = :businessId`. **Never**
`OR business_id IS NULL`, never a `user_id` fallback, never
`LegacyBusinessResolver`.

Known coverage gaps the implementation must not paper over:

| Source | Gap |
|---|---|
| `reports` (inbound) | `business_id` set from `LegacyBusinessResolver` at write time (`DLRController:516`); NULL when the owner maps to no single Business. Inbound rows also never carry `campaign_id`. |
| `reports` (automation-produced, pre-B4) | `automations` has no `business_id` before B4, so `$this->business_id` on `Automation extends SendCampaignSMS` is NULL. |
| `reports`/`campaigns` (legacy builder, API) | `$outreachBusinessId ?? LegacyBusinessResolver::…` (`EloquentCampaignRepository:910, 1086`). |
| All 11 tenancy-foundation tables (historical) | `BusinessDataTenancyBackfillV1` resolves via `LegacyBusinessResolver`, which returns the **primary** Business when a customer has several. Historical `business_id` is deterministic but not necessarily semantically correct. |

### 3.2 Coverage notice — MANDATORY

Because exclusion is silent by construction, the overview **must** render an
explicit coverage notice whenever the selected Business's owning customer has
rows with `business_id IS NULL` in a source B5 charts.

Locked wording shape (exact copy is an implementation detail; the *presence*
and the *facts* are not):

> *"N message records and M contact records created before Business tenancy
> could not be attributed to a Business and are not included in these
> figures."*

The notice is computed with one bounded `COUNT` per affected source, scoped to
`user_id`/`customer_id` = `$business->customer_id` **and**
`business_id IS NULL`, and is cached with the rest of the payload (§11.3).
Under-reporting without saying so is worse than not reporting.

---

## 4. DATE RANGE AND TIMEZONE — LOCKED

### 4.1 Presets

| Preset | Meaning |
|---|---|
| `last_7_days` | the 7 local dates ending today |
| `last_30_days` | **DEFAULT** — the 30 local dates ending today |
| `last_90_days` | the 90 local dates ending today |
| `custom` | an explicit local start and end date |

### 4.2 Custom-range hard cap — **92 days inclusive**

Justification, not preference: the largest preset is 90 days, and 92 is the
longest possible three-calendar-month selection (Jul + Aug + Sep = 92 days).
A wider custom range would create a query class the presets never exercise
and the tests never cover, over `reports` — the largest table in the schema
(one row per recipient per send, dual-written alongside `tracking_logs`).
A request exceeding the cap is a **validation failure**, never a silent clamp.

### 4.3 Timezone

**All grouping and range boundaries are computed in the Business's own
timezone (`businesses.timezone`), converted to UTC in PHP, and applied as an
explicit half-open UTC interval.**

```
created_at >= :startUtc  AND  created_at < :endUtc
```

where `:endUtc` is the start of the local day **after** the final selected
local date.

`businesses.timezone` is `string(64)`, required at Business creation, and
validated by Laravel's `timezone` rule
(`UpsertBusinessIdentityRequest`). `workspaces` has no timezone column;
`config('app.timezone')` (default `UTC`) is the storage timezone and is
**not** the grouping timezone.

Boundaries are computed as:

```php
CarbonImmutable::parse($localDate, $business->timezone)->startOfDay()->utc()
```

### 4.4 Forbidden date mechanics

- `whereDate()` on `created_at` (non-sargable; defeats the §11.1 indexes).
- `DATE(created_at)`, `DAY(created_at)`.
- `whereBetween` with date-only strings — this is the existing off-by-one that
  silently drops the final day (`Reports::scopeFilterByInputDateRange`,
  `ReportsController::postAnalyze`).
- Any silent `strtotime()` fallback (an unparseable fragment becomes
  `1970-01-01` in `ReportsController::parseDates`).
- **`CONVERT_TZ()` in the core range filter.** It returns `NULL` unless
  `mysql.time_zone_name` is populated, which is routinely false on the
  cron-driven shared hosting this application actually targets.

### 4.5 DST behaviour — LOCKED

- A local day is **not** assumed to be 24 hours. A spring-forward day maps to
  a 23-hour UTC interval and a fall-back day to a 25-hour interval; both are
  correct, and each bucket is still exactly one local date.
- **Every bucket boundary is computed independently from its own local date.**
  Deriving later boundaries by adding a fixed 86 400-second offset to the
  first is forbidden — it is the defect this rule exists to prevent.
- Where a zone shifts across local midnight, `startOfDay()` can be a
  non-existent or ambiguous instant. The implementation uses Carbon's own
  resolution (non-existent → shifted forward; ambiguous → the first,
  pre-transition occurrence) and **must not** implement its own.
- Both directions are covered by required tests (§21).

---

## 5. MESSAGE KPIs

Source `reports`. All scoped `business_id = :b` and the §4.3 interval.

**M1 — OUTBOUND MESSAGES ATTEMPTED**
`COUNT(*)` WHERE `direction = 'outgoing'` AND range.
Time basis: `created_at` (send time).
Limitations: excludes `direction = 'api'` (that is M2) and NULL-business rows;
rows removed by campaign resend or campaign deletion are permanently absent
(§9).

**M2 — API MESSAGES ATTEMPTED**
Identical with `direction = 'api'`. **Displayed separately; never merged into
M1.**

**M3 — INBOUND MESSAGES RECEIVED**
`COUNT(*)` WHERE `direction = 'incoming'` AND range.
Limitations: resolver-derived tenancy can **under-report** (§3.1); inbound
rows always carry `customer_status = 'Delivered'` and never a `campaign_id`.

**M4 — PROVIDER-ACCEPTED RATE**

- numerator: M1 rows WHERE `customer_status = 'Delivered'` **OR** `customer_status LIKE 'Delivered|%'`
- denominator: M1

**`LIKE '%Delivered%'` is forbidden.** Under MySQL's default
case-insensitive collation `'Undelivered' LIKE '%Delivered%'` is TRUE, so the
contains-match counts undelivered messages as delivered. That defect is
currently live in `Campaigns::deliveredCount()` (`:251`),
`UserController::index()` `$smsCounts`, `AdminBaseController` `sms_history`,
and `ReportsController::analyze()`/`postAnalyze()`. The `Delivered|%` prefix
arm is required because `SendCampaignSMS:338` writes
`'Delivered|' . $message_id` into `customer_status` itself.

**The user-facing label is provider acceptance, never handset delivery.**
`SendCampaignSMS:477,532` sets `customer_status = 'Delivered'` for Twilio
`queued`/`accepted` at send time, before any receipt exists. Only rows a DLR
callback later rewrote mean real delivery.

**M5 — CONFIRMED FAILURES (terminal non-acceptance)**

- numerator: M1 rows WHERE `customer_status IN ('Undelivered','Expired','Rejected','Failed','Skipped')`
- denominator: M1

This is exactly the terminal subset of the eight values
`DLRController::updateDLR()` (`:49-72`) normalises. `Skipped` is included
because it is terminal and not an acceptance; the UI footnote must say
`Skipped` means the send was skipped, not that it failed downstream.
Non-normalised values — arbitrary provider statuses (`:484,539`) and raw
exception messages (`:447`) — fall into M6, not M5.

**M6 — UNRESOLVED / IN FLIGHT**

`M6 = M1 − M4numerator − M5numerator`.

**Must always be visible.** It is the honest home for `Enroute`, `Accepted`,
provider-specific strings and exception text, and its size tells the reader
how far to trust M4 and M5. The identity **M4num + M5num + M6 = M1** is a
required test (§21).

**M7 — MESSAGE VOLUME OVER TIME**
Daily series over M1 ∪ M3, bucketed by Business-timezone local date (§4.3),
split by `direction`.

---

## 6. CAMPAIGN KPIs

Source `campaigns`, `reports`, `tracking_logs`.

**C1 — CAMPAIGNS CREATED IN RANGE** — `COUNT(*)` WHERE `business_id = :b` AND `created_at` in range.

**C2 — CAMPAIGN STATUS DISTRIBUTION** — `COUNT(*)` WHERE `business_id = :b` GROUP BY `status`. **Explicitly labelled a snapshot**: `campaigns.status` is a nullable string holding one of the 12 `Campaigns::STATUS_*` constants and reflects the current state only.

**C3 — CAMPAIGN PERFORMANCE TABLE** — paginated, campaigns in range, most recent first:

| Column | Definition |
|---|---|
| name | `campaigns.campaign_name` |
| created_at | Business timezone (§4.3) |
| status | `campaigns.status` |
| attempted | `COUNT(reports)` WHERE `campaign_id = c.id` AND `business_id = :b` |
| accepted | the **exact M4 numerator predicate**, restricted to that `campaign_id` |
| failures | the **exact M5 predicate**, restricted to that `campaign_id` |
| contacts targeted | `COUNT(DISTINCT tracking_logs.contact_id)` WHERE `campaign_id = c.id` AND `business_id = :b` |

**Forbidden for every C3 column:** `campaigns.cache`, `readCache()`,
`deliveredCount()`, `failedCount()`, `notDeliveredCount()`, `contactCount()`.
Mechanical reasons: `deliveredCount()` uses the `%Delivered%` contains-match;
`notDeliveredCount()` counts `%Sent%` while the DLR map sends provider `SENT`
to `Delivered`, so the two overlap; and `contactCount()` counts *currently
subscribed* contacts in the campaign's lists, so it drifts as contacts
unsubscribe and lets percentages exceed 100%.

**Query shape is locked (§11.2):** the two aggregates are computed as two
grouped queries over the page's campaign ids — never one query per row.

---

## 7. CONTACT KPIs

Source `contacts`, `contact_groups`, scoped `business_id = :b`.

- **K1 — TOTAL CONTACTS**, point in time, **not** range-filtered, labelled "as of now".
- **K2 — NEW CONTACTS IN PERIOD**, `created_at` in range.
- **K3 — NEW-CONTACT GROWTH OVER TIME**, K2 bucketed by Business-timezone local date.
- **K4 — SUBSCRIBED / UNSUBSCRIBED SPLIT**, point in time, GROUP BY `status` (`Contacts::STATUS_SUBSCRIBE` / `STATUS_UNSUBSCRIBE`).
- **K5 — CONTACT GROUP COUNT**, `COUNT(*)` of `contact_groups`.

**Forbidden:** any historical unsubscribe rate, unsubscribe trend, or churn
series. There is **no contact status-history table**, so period-over-period
status change is not computable. Do not manufacture it.

**Also unavailable:** lead source / acquisition channel. `contacts` has no
`source`, `created_by`, `campaign_id`, `origin` or `utm_*` column, and the
public subscribe form (`ContactsController::insertContactBySubscriptionForm`)
writes an ordinary row with no provenance marker.

---

## 8. AI BUSINESS ADVISOR KPIs

Source `opportunities`, `opportunity_runs` — both with `business_id` as a
**NOT NULL** FK, the cleanest tenancy in the candidate set.

- **O1 — OPEN CURRENT RECOMMENDATIONS**, `status = 'open'` AND `freshness = 'current'`, point in time.
- **O2 — COMPLETED IN PERIOD**, `completed_at` in range.
- **O3 — DISMISSED IN PERIOD**, `dismissed_at` in range.
- **O4 — LAST SUCCESSFUL ADVISOR RUN**, `MAX(completed_at)` of `opportunity_runs` WHERE `status = 'succeeded'`.

**These are AI Business Advisor recommendations, not sales opportunities.**
The table has no `value`, `amount`, `currency`, `probability`, `stage`,
`won`/`lost` or `contact_id`; `impact`, `urgency`, `effort`, `confidence` and
`priority_score` are recommendation weights (`unsignedTinyInteger` /
`decimal(3,2)`), and the implemented types are profile-completeness advice
(`missing_website`, `add_phone`, `add_email`, `add_description`,
`add_location`, `complete_location`) from the single implemented producer,
`BusinessAdvisorOpportunityProducer`.

**Forbidden:** rendering any of those scores as revenue, deal value, pipeline
value or conversion. The panel is labelled "recommendations".

Rendered only when `config('opportunity.enabled', false)` is true (§2.6).

---

## 9. AUTOMATION KPIs — AFTER B4 ONLY

Source `automation_executions`. **B5 never creates or alters this table.**

Verified read-only against `origin/agent/b4-business-automations`
(`014d407`, merge-base `e7fad48`),
`database/migrations/2026_09_07_120003_create_automation_executions_table.php`:

```
id · uid (uuid, unique) · business_id (foreignId → businesses, restrictOnDelete, NOT NULL)
automation_id (foreignId → automations, cascadeOnDelete) · contact_id (foreignId → contacts, cascadeOnDelete)
trigger_type string(32) · idempotency_key string(191) UNIQUE · status string(16) default 'pending'
action_claimed_at · started_at · completed_at · safe_result_summary(255) · safe_error_summary(255) · timestamps
indexes: business_id · automation_id · (automation_id, created_at) · status
```

`App\Enums\Automation\AutomationExecutionStatus` = `pending | succeeded |
failed | skipped`, and exposes `label()` and **`badgeVariant()`** (accent /
success / danger / warning) which B5's `x-badge` usage should reuse rather
than re-deriving.
`App\Enums\Automation\AutomationTriggerType` = `contact_date_reached |
contact_created`.

**KPIs**

- **A1 — EXECUTIONS IN PERIOD**, `COUNT(*)` WHERE `business_id = :b` AND `created_at` in range.
- **A2 — SUCCESS RATE** = `succeeded ÷ (succeeded + failed)`. `skipped` and `pending` are **excluded from both sides** and displayed separately — B4's enum docblock defines `skipped` as "authoritative state changed between claim and action … nothing was sent", a deliberate at-most-once outcome, not a failure.
- **A3 — FAILED / SKIPPED / PENDING DISTRIBUTION**, GROUP BY `status`.
- **A4 — TRIGGER-TYPE DISTRIBUTION**, GROUP BY `trigger_type`.

**The implementation pass MUST re-verify B4's actually-merged schema before
coding** — this section describes an unmerged branch and B4 may change before
merge.

### 9.1 `automation_executions` index — RESOLVED, OWNED BY B4

At the time of the read-only inspection, the B4 branch indexed `business_id`
alone plus `(automation_id, created_at)`, and shipped no
`(business_id, created_at)` — which is exactly the shape A1–A4 need.

**Human decision (Correction 1): B4 owns `automation_executions`, so B4
supplies the index.** Lane A has been instructed to amend the B4 contract,
the `automation_executions` migration, and the B4 schema test accordingly.

Locked consequences for B5:

- B5 **expects** the post-B4 `automation_executions` table to carry
  `(business_id, created_at)`.
- The B5 implementation pass **must mechanically re-verify** its presence
  after B4 merges, before writing any A1–A4 query.
- If B4 merges **without** that promised index, the B5 implementation
  **STOPS and reports the predecessor-contract discrepancy**. It must not
  silently add the index, and must not otherwise alter a B4-owned table —
  doing so would contradict both this contract and §19.
- B5's own migration remains **exactly the six indexes** in §11.1 on
  `reports`, `campaigns`, `contacts` and `tracking_logs`.
  **`automation_executions` is never added to the B5 migration.**

---

## 10. EXPLICIT NON-METRICS

None of the following may be built, computed, or labelled in B5 v1. Each row
records the dependency that would make it honest.

| Non-metric | Would become honest when |
|---|---|
| Handset delivery rate | Provider DLR coverage is normalised and a send-time acceptance state is distinguishable from a confirmed receipt |
| Reply rate, conversation count, average response time | ChatBox gains Business tenancy and a deterministic contact/campaign link (separate contract) |
| Revenue, profit, earnings, ROI | A Payments/Invoicing module records money the **Business** received |
| Close rate, won/lost, pipeline value | A real CRM pipeline model exists (none: whole-word search for `deal`, `pipeline`, `won`, `lost`, `deal_value`, `opportunity_value` over `app/Models` and `database/migrations` returns zero) |
| Bookings, show rate | The Calendar module exists (`PlatformFeature::Calendar` is `Planned`; no bookings table) |
| Form conversion | The Forms module records submission events (`PlatformFeature::Forms` is `Planned`) |
| Page views, site conversion | Website Generation ships page-view data (`PlatformFeature::WebsiteGeneration` is `Planned`) |
| SEO / GBP metrics | The SEO module ships |
| Ad spend, CPC, CPA | The Ads modules ship |
| Lead source attribution | `contacts` gains a provenance field |
| Cost / spend by period | A real usage meter is activated. `reports.cost` is an SMS **credit unit** in a `string` column with no currency (`EloquentCampaignRepository:1710-1741`), and `business_usage_ledger_entries` is empty until `usage:activate-conversations-rate` is run |
| Any "engagement score" | Never, unless a specific numerator and denominator are contracted |

**Currency policy:** B5 v1 sums no monetary value at all. If one is ever
added, it may be summed only within a single `currency_id`/`currency_code`,
must exclude rows with an unknown currency, and must report those exclusions.

---

## 11. PERFORMANCE — LOCKED

### 11.1 No aggregation table. One additive index migration.

**Locked: B5 creates no analytics warehouse, no rollup, no daily aggregate
table.** Every v1 KPI is a single `COUNT`/`GROUP BY` over one table filtered
by `business_id` plus a range capped at 92 days (§4.2); a rollup would need
its own backfill, a timezone baked into stored rows (invalidated whenever a
Business changes `businesses.timezone`), its own retention, and reconciliation
against a source that is mutable and deletable (§9 of the reconnaissance).
There is no aggregate-table precedent in this repository; the established
pattern for expensive reads is `Cache::remember`.

**Authorized: exactly one additive index migration**, after the
implementation re-verifies each index does not already exist on the post-B4
tree:

| Table | Index |
|---|---|
| `reports` | `(business_id, created_at)` |
| `reports` | `(business_id, direction, created_at)` |
| `campaigns` | `(business_id, created_at)` |
| `contacts` | `(business_id, created_at)` |
| `contacts` | `(business_id, status)` |
| `tracking_logs` | `(business_id, campaign_id)` |

Justification: `2025_10_13_144953_add_performance_indexes_to_reports_and_others.php`
already established `(user_id, created_at)` on `reports` as necessary for the
legacy scope. The Business-scoped equivalent does not exist — the
tenancy foundation added only single-column `business_id` indexes. `contacts`,
`contact_groups`, `campaigns` and `tracking_logs` have **no** explicit indexes
at all beyond FK-implied ones and that single `business_id`.

`down()` drops exactly those six. **No new column, no NOT NULL enforcement,
no backfill, no type change.**

### 11.2 Bounded query count

- **Overview:** at most **12** database queries in total, including the §2.2
  tenancy chain (which itself costs up to 4). The KPI reads must be batched:
  M1–M6 as one conditional-aggregate query; M7 as one; C1+C2 as one;
  K1+K2+K4 as one; K5 as one; O1–O3 as one; O4 as one; A1–A4 as one.
- **Campaign table:** the two C3 aggregates are **two grouped queries over the
  current page's campaign ids** — one over `reports` (conditional sums), one
  over `tracking_logs` (`COUNT(DISTINCT contact_id)`). **A query per row is
  forbidden** and is a required test.
- Both counts are asserted with `DB::listen`-style assertions (§21).

### 11.3 Caching

Business-scoped key only:

```
b5_analytics_{$business->id}_{$rangeKey}
```

TTL **5 minutes**, locked (the existing convention is 10–15 minutes on the
customer dashboard and 1 minute on the legacy Reports DataTables).
`$rangeKey` incorporates the preset name and, for custom, both local dates.

**A global analytics cache key is forbidden.** The admin dashboard's
untenanted keys (`revenue`, `customers`, `sms_history`,
`current_month_reports`) are an anti-precedent for B5.

### 11.4 Pagination and throttling

- C3 paginates at **25 per page** (`UsageBillingPresenter`'s
  `->paginate(25)` precedent).
- `GET /series` carries `throttle:60,1`, matching
  `opportunities/{opportunity}/execution-status` and the onboarding
  analysis-status route.

---

## 12. ROUTES

Subject to fresh verification against the post-B4 tree (§20).

### 12.1 Added — `routes/customer.php`, inside the `workspaces` group

```php
Route::prefix('{workspaceUid}/businesses/{businessUid}/analytics')
    ->name('businesses.analytics.')->group(function () {
        Route::get('/',          'Business\AnalyticsController@overview')->name('overview');
        Route::get('/campaigns', 'Business\AnalyticsController@campaigns')->name('campaigns');
        Route::get('/series',    'Business\AnalyticsController@series')
            ->name('series')->middleware('throttle:60,1');
    });
```

Route names: `customer.workspaces.businesses.analytics.{overview,campaigns,series}`.

### 12.2 Added — bare entry

```php
Route::get('analytics', 'Business\AnalyticsController@entry')->name('analytics.entry');
```

Placed as a sibling of B4's `Route::get('automations', 'AutomationsController@entry')->name('automations.index');`.

### 12.3 Removed — legacy customer Reports

**Exact count, mechanically enumerated at `2425b9f`.** The
`Route::prefix('reports')` group at `routes/customer.php:382-420` contains
**28 route declarations** — 25 `ReportsController` and 3
`CampaignController` — and `routes/customer.php:422` carries one further
legacy `ReportsController` route. **29 legacy customer reporting-path route
declarations are removed in total.**

**A. The 25 `ReportsController` routes inside the group**

```
 1  POST   /reports/{uid}/destroy                     ReportsController@destroy
 2  GET    /reports/all                               @reports
 3  POST   /reports/{uid}/view                        @viewReports
 4  POST   /reports/export                            @export
 5  GET    /reports/export/sent                       @exportSent
 6  GET    /reports/export/receive                    @exportReceive
 7  GET    /reports/export/{campaign}                 @exportCampaign
 8  GET    /reports/received                          @received
 9  GET    /reports/sent                              @sent
10  GET    /reports/campaigns                         @campaigns
11  POST   /reports/search                            @searchAllMessages
12  POST   /reports/search/received                   @searchReceivedMessage
13  POST   /reports/search/sent                       @searchSentMessage
14  POST   /reports/search/campaigns                  @searchCampaigns
15  POST   /reports/batch_action                      @batchAction
16  GET    /reports/campaigns/{campaign}/edit         @editCampaign
17  POST   /reports/campaigns/{campaign}/edit         @postEditCampaign
18  GET    /reports/campaigns/{campaign}/overview     @campaignOverview
19  POST   /reports/campaigns/{campaign}/reports      @campaignReports
20  POST   /reports/campaigns/{campaign}/delete       @campaignDelete
21  POST   /reports/campaign/batch_action             @campaignBatchAction
22  GET    /reports/campaign/export                   @campaignExport
23  GET    /reports/analyze                           @analyze
24  POST   /reports/analyze                           @postAnalyze
25  POST   /reports/{uid}/dlr                         @dlrReports
```

**B. The 3 `CampaignController` routes inside the group — also removed**

```
26  POST   /reports/campaigns/{campaign}/pause        CampaignController@campaignPause
27  POST   /reports/campaigns/{campaign}/restart      CampaignController@campaignRestart
28  POST   /reports/campaigns/{campaign}/resend       CampaignController@campaignResend
```

**C. One further legacy route outside the group**

```
29  GET    /view-charts                               ReportsController@viewCharts   (routes/customer.php:422)
```

No route is counted twice: 25 + 3 = 28 inside the group, plus 1 at `:422`.

#### 12.3.1 Why the three `CampaignController` routes are deleted — RESOLVED

Human decision (Correction 1), on mechanical evidence at `2425b9f`: **B1
already provides canonical Business-scoped replacements**, so the legacy
routes are superseded, not merely duplicated.

Replacements — `routes/customer.php:695-697`:

```
POST /workspaces/{workspaceUid}/businesses/{businessUid}/outreach/campaigns/{campaign}/pause    OutreachController@pause
POST /workspaces/{workspaceUid}/businesses/{businessUid}/outreach/campaigns/{campaign}/restart  OutreachController@restart
POST /workspaces/{workspaceUid}/businesses/{businessUid}/outreach/campaigns/{campaign}/resend   OutreachController@resend
```

Each of the three `OutreachController` methods — `pause()` (`:514`),
`restart()` (`:528`), `resend()` (`:542`) — performs, in order:

1. `resolveAccessibleBusiness($workspaceUid, $businessUid)` (`:645`) — the
   full §2.2 Workspace → Business → `userCanAccessBusiness()` → `abort(404)`
   chain;
2. `resolveOwnedCampaign($campaign, $business)` (`:662`) —
   `abort_unless($campaign->business_id === $business->id, 404)`;
3. the same `config('app.stage') == 'demo'` restriction the legacy methods
   carry;
4. the identical `CampaignRepository::pause()`/`restart()`/`resend()` core.

The legacy methods, by contrast, are **unscoped**:
`CampaignController::campaignPause()` (`:1870`), `campaignRestart()`
(`:1910`) and `campaignResend()` (`:1949`) each perform only the demo check
and then call the repository on a route-model-bound `Campaigns` — **no
`authorize()`, no `user_id` check, no `business_id` check**. Deleting them is
therefore security-positive on the same grounds as S-3; they are recorded as
S-16 in §15.2.

**LOCKED: delete all three with the surrounding group. No preservation, no
redirect, no compatibility shim, no renaming.** B5 does not modify
`OutreachController` or `CampaignController`.

### 12.4 Removed — ghost analytics surfaces (`routes/web.php`)

See §14. Five routes: `GET /admin/ai-analytics`, `GET /admin/hot-leads`,
`POST /admin/hot-leads/mark-called`, `POST /admin/ai-variants/update`,
`POST /admin/ai-analytics/book/{id}`.

### 12.5 Untouched

`routes/admin.php:447-471` — the entire admin Reports group. Admin operational
reporting is platform ops and is **kept exactly as it is**.

---

## 13. UI

### 13.1 Structure

One M2 Analytics area at the canonical address.

1. **Header** — Business name · the Business timezone the page is grouping in
   (shown, not implied) · the range control.
2. **Range control** — the four §4.1 options; custom bounded by §4.2.
3. **Coverage notice** — conditional, per §3.2.
4. **Stat cards (7)** — K1 total contacts · K2 new contacts · M1 outbound
   messages · M3 inbound messages · M4 provider-accepted rate · C1 campaigns
   created · O1 open recommendations (conditional).
5. **Charts (2)** — K3 contact growth (daily) · M7 message volume by direction
   (daily, stacked).
6. **Message outcome breakdown** — M4 / M5 / M6 in one labelled control, with
   **M6 always visible** and a footnote stating that "accepted" means accepted
   by the provider and that `Skipped` sits inside M5.
7. **Campaign performance table** — C3, paginated at 25.
8. **Automations panel** — A1–A4, conditional on the table existing.
9. **Cross-links, not duplicates** — "Usage & Billing" →
   `customer.workspaces.businesses.usage-billing.show`; "Conversations" →
   the ChatBox surface. **Agency Prospecting is not linked** from a Business
   analytics page — wrong scope (§16).

### 13.2 Components

Existing M2 primitives only: `x-card`, `x-table`, `x-badge`, `x-empty-state`,
`x-ds-icon`, plus the established form/button/select primitives. Consistent
with the Prospecting/Workspace/Usage-Billing views.

### 13.3 Charts — binding constraint

**ApexCharts only**, already bundled at
`public/vendors/js/charts/apexcharts.min.js`. **No Chart.js, ECharts,
Highcharts, Vue, React, or any new frontend framework.**

`tests/Feature/Theme/ChartTokenContentTest.php` maintains a `CHART_VIEWS`
inventory and asserts that every chart-bearing view (a) contains no hardcoded
legacy purple `7367F0` and (b) references the shared `window.PlatformTheme`
namespace. B5 therefore **must**:

- read colours from `PlatformTheme.chartPalette()` (8 colours from
  `--color-chart-1..8`), plus `chartNeutral()`, `chartGrid()`, `chartAxis()`,
  `chartTooltipBg()`, `chartTooltipText()` — defined in
  `resources/js/core/theme-tokens.js`;
- **remove** `resources/views/customer/Reports/charts.blade.php` and
  `resources/views/customer/Reports/analyze.blade.php` from `CHART_VIEWS`
  (both are deleted by B5), carrying an explanatory comment exactly as B4 did
  when it removed `customer/Automations/overview.blade.php`;
- **add** the new analytics view to `CHART_VIEWS`.

The test also asserts `theme-tokens.js` is registered before `app.js` in
`webpack.mix.js` — unchanged by B5.

---

## 14. GHOST ANALYTICS SURFACES — DECISION

The task authorized including these **if** a fresh audit still proves a
complete caller set and the absence of live supported schema. Both were
re-proven at `2425b9f`.

### 14.1 Schema proof

`ai_stage`, `ai_box_campaign_map`, `called`, `website_sent_at`,
`followup_sent`, `ai_replied` have **zero migrations**. Seven `chat_box*`
migrations exist (`2021_03_31_125855`, `2021_03_31_130224`,
`2021_12_08_163656`, `2023_05_07_163338`, `2023_07_10_182045`,
`2024_09_26_152505`, `2025_05_29_134253`); none adds any of them.
`tests/Feature/Dashboards/DashboardRenderTest.php` states the same conclusion
in its own docblock and fabricates the columns through an ephemeral
`security_test_ddl` connection.

### 14.2 Complete caller set (re-enumerated, larger than the reconnaissance stated)

| Kind | Path |
|---|---|
| Controller | `app/Http/Controllers/Admin/AiAnalyticsController.php` |
| Controller | `app/Http/Controllers/Admin/HotLeadController.php` |
| FormRequest | `app/Http/Requests/Admin/MarkHotLeadCalledRequest.php` |
| View | `resources/views/admin/ai_analytics.blade.php` |
| View | `resources/views/admin/hot_leads.blade.php` |
| Routes | `routes/web.php:11-12` (imports), `:14-15`, `:18-19`, `:20-21`, `:26-27`, `:68-70` |
| Nav | `app/Helpers/Helper.php:1032-1039` (Hot Leads), `:1041-1048` (AI Analytics) |
| Test | `tests/Feature/Security/AiAnalyticsSecurityTest.php` (399 lines) |
| Test | `tests/Feature/Security/HotLeadsSecurityTest.php` (337 lines) |
| Test (modify) | `tests/Feature/Dashboards/DashboardRenderTest.php` |
| Test (modify) | `tests/Feature/Dashboards/DashboardComponentAdoptionTest.php` |
| Test (modify) | `tests/Feature/Dashboards/DashboardExistingBehaviorPreservedTest.php` |

**Decision (Correction 1): DELETE all of the above, INSIDE the B5 product
PR.** The human reviewed the scope and locked it there; it is **not** split
into a separate cleanup PR. It remains a clearly labelled, self-contained
section of the B5 change set, but it ships with B5.

They are customer surfaces mis-filed under `Admin\` and `/admin/` URLs, gated
by the *customer* permission `can:access_backend` and the *customer*
capability `chat_box`, and they run on schema that does not exist in a freshly
migrated database. Removing them also removes an unauthenticated route
(`POST /admin/ai-variants/update`, `routes/web.php:26-27`) that has **no
middleware at all** and points at `AiAnalyticsController@updateVariants`, a
method that does not exist.

### 14.3 What is NOT deleted, and the defect this leaves — RECORDED

Two **producers** of that same untracked schema are on this contract's
stop-list and are **not touched**:

- `app/Repositories/Eloquent/EloquentCampaignRepository.php:1196` inserts
  `chat_boxes.ai_stage => 1` and `:1215` inserts into `ai_box_campaign_map`.
  This is the legacy AI-prospecting hook, guarded by
  `$outreachBusinessId === null` (`:1182`), so it fires only for legacy
  non-Business campaign-builder calls — reachable today via
  `routes/customer.php:270-271` and the other `/{channel}/campaign-builder`
  routes.
- `app/Http/Controllers/Customer/DLRController.php:615-620` updates
  `chat_boxes.ai_replied` on every inbound message.

**Recorded finding, not a B5 work item:** on a freshly migrated database both
writes reference columns that do not exist, so the legacy campaign-builder
path and the inbound-SMS path would throw. This predates B5, is unchanged by
B5, and belongs to a separate contract covering `EloquentCampaignRepository`
and `DLRController` (B1/B2 sending core — stop-listed here). Deleting the
read surfaces neither creates nor worsens it, and strictly improves the
security posture.

---

## 15. LEGACY REPORTS SECURITY DISPOSITION

### 15.1 The decision

**DELETE the legacy customer `/reports/*` product surface as part of B5.**
No transitional parallel legacy Reports UI is retained. Fixing the controller
in place and keeping it alive is explicitly **rejected**.

### 15.2 What deletion resolves

Each of the following was found mechanically and is on a route B5 removes:

| Ref | Defect |
|---|---|
| S-1 | `viewReports(Reports $uid)` (`:174`) — **no `authorize()`, no tenant scope**; returns the whole row as JSON, including `to` (recipient phone) and `message` (full body). Cross-tenant read. |
| S-2 | `destroy(Reports $uid)` (`:185`) — no authorize, no scope. Cross-tenant delete. |
| S-3 | `campaignDelete(Campaigns $campaign)` (`:1444`) — no authorize, no scope. Because `reports.campaign_id` and `tracking_logs.campaign_id` are `onDelete('cascade')`, this destroys another tenant's entire message history. |
| S-4 | `dlrReports(Reports $uid)` (`:1653`) — no authorize, no scope; issues an outbound HTTP call using the sending server's stored credentials on another tenant's behalf. |
| S-5 | The ids for S-1…S-4 are **predictable**: `reports.uid` is declared `uuid` but `Reports` uses `HasUid` without overriding `generateUid()`, so `uniqid()` is written — 13 hex chars from the current microsecond, monotonically ordered — and `reports.uid` carries **no unique index**. (`Workspace.php:32` and `PlatformThemePreset.php:55` both *do* override it.) |
| S-8 | `postAnalyze()` (`:1604`) has no `authorize('view_reports')`; `analyze()` (`:1543`) does. |
| S-9 | `DashboardRequest` validates only `'dateRange' => 'required|min:10'`; `parseDates()` then `strtotime()`s raw fragments. Any range; unparseable input becomes `1970-01-01`. |
| S-10 | All five exports write `storage_path('Reports_' . time() . '.xlsx')` / `Campaign_…`. The name depends only on the second, so concurrent exports by different tenants collide. Files are never deleted. |
| S-11 | `exportData()` and `campaignReportsGenerator()` call `->get()` on the full set inside `Generator`-typed methods. |
| S-12 | Exports emit `from`, `to`, `message` verbatim into XLSX — spreadsheet formula injection. |
| S-13 | `searchCampaigns()` (`:568`) — `$columns[$request->input('order.0.column')]` with no `??` fallback. |
| S-14 | Five DataTables methods end in `echo json_encode(); exit();`, bypassing the response pipeline. |
| S-16 | `POST /reports/campaigns/{campaign}/{pause,restart,resend}` → `CampaignController::campaignPause()` (`:1870`), `campaignRestart()` (`:1910`), `campaignResend()` (`:1949`) — **no `authorize()`, no `user_id` scope, no `business_id` scope**; only a demo-mode check before calling the repository on a route-model-bound campaign. Cross-tenant campaign control. `campaignResend()` is additionally destructive: `EloquentCampaignRepository::resend()` (`:2140-2144`) deletes every non-Delivered `Reports` and `TrackingLog` row for that campaign, so it is a cross-tenant **history destruction** IDOR of the same class as S-3. |

### 15.3 Export

**Removed entirely. B5 v1 ships no export, and none is rebuilt.**

If export ever returns it requires its own explicit contract covering:
random/unguessable filenames; private storage, never `storage_path()` root; a
`Content-Disposition` name unrelated to the stored name; deletion after
download or by a scheduled sweep; chunked reads via `cursor()`; spreadsheet
formula-injection neutralisation; Business scope; capability gating; and a
range cap.

### 15.4 Kept

- **`reports` table and `Reports` model** — B5's primary data source.
- **`tracking_logs` table and `TrackingLog` model** — the only deterministic
  campaign → contact link.
- **`Admin\ReportsController` and `routes/admin.php:447-471`** — real admin
  operational reporting, untouched.
- **`Reports` query scopes** — retained for legacy/API callers; B5 writes its
  own bounded queries and uses none of them.

### 15.5 Deferred hardening — NOT a B5 blocker

**S-5 (`reports.uid` predictability and non-uniqueness) becomes a
lower-severity backend-only concern once the vulnerable public customer
routes are removed**, because no unauthenticated or mis-scoped route resolves
a Report by uid any more. It is recorded here as a **separate future
hardening item** (`Reports::generateUid()` override + a unique index +
backfill of colliding rows) and must **not** block B5.

**ChatBox Business tenancy remains a separate contract** and is not smuggled
into B5 (§3).

---

## 16. AGENCY PROSPECTING — KEEP SEPARATE

`Customer\Workspace\AgencyProspectingController::overview()` (`:97-146`) stays
exactly as it is. B5 must not read, aggregate, link to, or restyle it.

Mechanical reasons: it counts *external businesses the agency is acquiring as
clients*, not a client Business's performance; every `agency_prospect*` table
is `workspace_id` NOT NULL with no `business_id`, so no join to a Business
exists; and `PlatformFeatureRegistry::SCOPE` makes `ProspectOutreach` the sole
Workspace-scoped feature, so blending would require `decideForWorkspace()` and
`decide()` in one view — which those registry docblocks forbid mixing. The
route tree already states the separation: *"Deliberately a sibling of the
{workspaceUid}/businesses/... routes above, never nested under them."*

**No cross-scope aggregation of any kind is authorized.**

What B5 should borrow is the *method*: that overview's Correction-1 comment
(`:108-111`) restricts the "initial messages sent" denominator to
`purpose = initial` so the reply-rate denominator cannot drift. §5 and §9
apply the same discipline.

---

## 17. BILLING — KEEP SEPARATE

`UsageBillingPresenter::buildDashboardViewModel()` already presents balance,
reserved balance, debt, spend period, spend cap, remaining headroom, per-feature
limits, payer, billing contact, a paginated ledger, payment method and
auto-recharge. **B5 duplicates none of it** and shows no balance, spend or
cost figure. The correct affordance is the cross-link in §13.1.9.

---

## 18. IMPLEMENTATION ALLOWLIST

### New

- `app/Http/Controllers/Customer/Business/AnalyticsController.php`
- `app/Library/Analytics/BusinessAnalyticsPresenter.php` — read-assembly only, modelled on `UsageBillingPresenter`: no write authority, no transaction, no lock
- `app/Library/Analytics/AnalyticsDateRange.php` — presets, cap, Business-timezone → half-open UTC boundaries (§4)
- `app/Library/Analytics/BusinessAnalyticsQueries.php` — the batched KPI reads (§11.2)
- `app/DTO/Analytics/BusinessAnalyticsViewModel.php` and per-section DTOs
- `app/Http/Requests/Analytics/AnalyticsRangeRequest.php`
- `resources/views/customer/business/analytics/{overview,entry,campaigns}.blade.php`
- `database/migrations/*_add_analytics_indexes_to_business_scoped_tables.php` — the six indexes in §11.1, additive only
- `tests/Feature/Analytics/**`, `tests/Unit/Analytics/**`, `tests/Feature/Security/AnalyticsSecurityTest.php`

### Modified — each single-purpose

1. `routes/customer.php` — add §12.1 + §12.2; remove §12.3.
2. `routes/web.php` — remove the five §12.4 routes and their two imports.
3. `app/Helpers/Helper.php` — replace the customer Reports submenu (`:1060-1091`) with one Analytics entry; remove the Hot Leads (`:1032-1039`) and AI Analytics (`:1041-1048`) entries. **Customer menu only — the admin menu is untouched.**
4. `app/Http/Controllers/User/UserController.php` — remove the seven per-SMS-type charts and the delivered/undelivered pie; re-point `opportunityPanel()` off `findPrimaryByCustomer()`.
5. `resources/views/customer/dashboard.blade.php` — remove the corresponding markup; add the Analytics entry point.
6. `tests/Feature/Theme/ChartTokenContentTest.php` — update `CHART_VIEWS` per §13.3.
7. `tests/Feature/Dashboards/{DashboardRenderTest,DashboardComponentAdoptionTest,DashboardExistingBehaviorPreservedTest}.php` — drop the ghost-surface cases and their ephemeral-schema fixtures.

### Deleted

- `app/Http/Controllers/Customer/ReportsController.php`
- `app/Http/Requests/Reports/DashboardRequest.php`
- `resources/views/customer/Reports/{all_messages,analyze,campaigns,charts,received_messages,sent_messages}.blade.php`
- `app/Http/Controllers/Admin/{AiAnalyticsController,HotLeadController}.php`
- `app/Http/Requests/Admin/MarkHotLeadCalledRequest.php`
- `resources/views/admin/{ai_analytics,hot_leads}.blade.php`
- `tests/Feature/Security/{AiAnalyticsSecurityTest,HotLeadsSecurityTest}.php`

`resources/views/customer/Campaigns/{overview,_overview}.blade.php` are **not**
deleted — they belong to B1's campaign surface. Their `contactCount()`
denominator defect (§6) is recorded for B1, not fixed here.

---

## 19. IMPLEMENTATION STOP-LIST

**Must not modify:** B3 Settings implementation (`SettingsController`,
`EloquentSettingsRepository`, `AppConfig`, `PlatformSettingsEnvWriter`,
`resources/views/admin/settings/**`, admin settings routes/requests/nav) ·
B4 product implementation (`Business\AutomationsController`,
`Customer\AutomationsController`, `App\Library\Automation\**`,
`App\Enums\Automation\**`, `Automation`, `AutomationExecution`,
`EloquentAutomationsRepository`, `automations` / `automation_executions`
schema, `resources/views/customer/Automations/**`) · Agency Prospecting
(controllers, jobs, library, all `agency_prospect*` tables) · B1/B2 sending
core (`OutreachController`, `Campaigns`, `SendCampaignSMS`,
`EloquentCampaignRepository`, `CampaignController`, `SendingServer`,
`CustomerBasedSendingServer`) · `DLRController` · Usage Wallet/Billing
internals and `Admin\UsageBillingController` · `WorkspaceManager` ·
`EntitlementManager` · `PlatformFeature` / `PlatformFeatureRegistry` ·
`LegacyBusinessResolver` and `BusinessDataTenancyBackfillV1` ·
`chat_boxes` / `chat_box_messages` schema · `Admin\AdminBaseController`,
`Admin\ReportsController`, `WarmDashboardCache` ·
`config/customer-permissions.php` · `webpack.mix.js`.

**Must not build:** a generic event warehouse · a rollup or daily aggregate
table · an export · any metric in the §10 non-metrics table · a new
`PlatformFeature` case · any cross-scope Workspace/Business aggregation · a
`business_id` backfill, new `business_id` column, or NOT NULL tenancy
enforcement · website / SEO / GBP / Ads / Calendar / Forms / Payments
functionality · a new frontend framework or charting library.

**Must not use:** `Auth::id()` as a tenant key · `findPrimaryByCustomer()` ·
`LegacyBusinessResolver` · `OR business_id IS NULL` · `campaigns.cache`,
`readCache()`, `deliveredCount()`, `failedCount()`, `notDeliveredCount()`,
`contactCount()` · `LIKE '%Delivered%'` · `whereDate()`, `DAY()`, `DATE()` on
`created_at` · `whereBetween` with date-only strings · `CONVERT_TZ()` in the
range filter · global cache keys · `abort(403)` on a tenancy mismatch ·
`echo json_encode(); exit();`.

---

## 20. B4 DEPENDENCY AND MERGE COORDINATION

**B5 product implementation starts only AFTER B4 merges.**

Verified read-only at `origin/agent/b4-business-automations` (`014d407`,
merge-base `e7fad48`, one commit, 39 files):

- B4 **does** change `routes/customer.php`: it collapses the flat
  `automations` group to a single `Route::get('automations', 'AutomationsController@entry')->name('automations.index');`
  and inserts a `{workspaceUid}/businesses/{businessUid}/automations` group
  inside the `workspaces` group, immediately **before** the B1 Outreach group.
- B4 **does not** change `app/Helpers/Helper.php` at all
  (`git diff e7fad48 origin/agent/b4-business-automations -- app/Helpers/Helper.php`
  is empty). The customer nav "Automations" entry at `:1053` still points at
  `url('automations')` and resolves through B4's chooser.
  *(The reconnaissance predicted a second coordination point here; that
  prediction is corrected — `routes/customer.php` is the only shared file.)*
- B4's branch is based on **pre-B3** main and will need rebasing or merging
  onto `2425b9f` before it lands.

**Binding rules for the B5 implementation pass:**

1. Branch from the **post-B4** `origin/main`, never from this contract branch's base.
2. Apply the §12 route and §18.3 nav changes to the **post-B4 tree**. Never
   replay a diff produced against this base SHA.
3. Re-verify B4's **actually merged** `automation_executions` schema, status
   enum, and trigger enum before writing any A1–A4 query (§9).
4. Re-verify that none of the six §11.1 indexes was already added by B4.
5. Re-verify that B4 shipped `automation_executions (business_id, created_at)`
   as §9.1 requires. **If it is absent, STOP and report the
   predecessor-contract discrepancy** — never add it to the B5 migration and
   never alter a B4-owned table.

---

## 21. TEST CONTRACT

`tests/Feature/Analytics/**`, `tests/Unit/Analytics/**`,
`tests/Feature/Security/AnalyticsSecurityTest.php`. Run only against the
disposable `ultimatesms_testing` database. Focused tests before regression.

**Tenancy chain** — unknown `workspaceUid` → 404 · unknown `businessUid` →
404 · Business belonging to a different Workspace → 404 · a second Business's
rows never appear in any KPI · `LegacyBusinessResolver` and
`findPrimaryByCustomer()` are never invoked (container spy) · every response
on a mismatch is 404, never 403.

**Workspace membership** — inactive Workspace → 404 even for the direct owner
· Workspace owner reaches a Business they do not own · staff with
`business_access_scope = all` allowed · staff with `selected` and no
assignment → 404 · inactive membership → 404.

**Foreign campaign exclusion** — corrected in Correction 1. The §12.1 routes
carry **no `{campaign}` parameter**: `GET /analytics/campaigns` is a
Business-scoped **list**, and `GET /analytics/series` returns aggregate chart
data. There is no per-campaign B5 endpoint, so there is no campaign-id 404 to
test. The coherent rules are exclusion rules, not authorization-of-an-id
rules:

- every campaign the page selects satisfies `campaigns.business_id = :b`;
- the two grouped C3 aggregates over `reports` and `tracking_logs` are
  **additionally** constrained by the same `business_id`, never by
  `campaign_id` alone;
- a campaign belonging to another Business must never appear, even if its id
  is injected into internal or query input;
- **no public campaign-id parameter is accepted anywhere in B5**, because the
  contracted surface needs none.

Tests: create campaigns for Business A and Business B under the same
customer; render A's analytics campaign page; assert only A's campaigns
appear; assert the C3 aggregate rows and the `/series` payload contain no row
attributable to B; assert that injecting B's campaign id into any accepted
request input changes nothing; and assert by route enumeration that **no B5
route declares a `{campaign}` parameter**.

**Adding a campaign-detail endpoint is out of scope.** If the implementation
concludes a campaign filter is genuinely necessary, it **STOPS and reports**
rather than inventing one outside this contract.

**NULL-business exclusion** — a `reports` row with `business_id IS NULL`
owned by the same customer is excluded from M1–M7 · the same for `contacts`
in K1–K5 · the coverage notice renders when such rows exist and does not
render when they do not.

**Cache isolation** — two Businesses never share a cached payload · the key
contains the Business id and the range key · no global key is written.

**Date-range validation** — each preset resolves to the expected boundaries ·
a custom range of 93 days is **rejected** · 92 days is accepted · a reversed
range is rejected · unparseable input is rejected and never becomes
`1970-01-01`.

**Final-day inclusion** — a row created at `23:59:59` local on the last
selected date **is** included (the half-open regression for the existing
`whereBetween` off-by-one).

**Timezone** — a row created at `23:30` local on day N buckets into day N for
a **positive**-offset Business and, separately, for a **negative**-offset
Business · two Businesses in different timezones bucket the same UTC row into
different local dates.

**DST** — a spring-forward local date produces exactly one bucket spanning 23
hours · a fall-back local date produces exactly one bucket spanning 25 hours ·
bucket boundaries are not derived by adding 86 400-second offsets.

**M4 regression** — `customer_status = 'Undelivered'` is **not** counted as
accepted · `customer_status = 'Delivered|12345'` **is** counted as accepted ·
`customer_status = 'Delivered'` is counted as accepted.

**M5 / M6** — each of `Undelivered`, `Expired`, `Rejected`, `Failed`,
`Skipped` lands in M5 · `Enroute` and `Accepted` land in M6 · a raw provider
status and a raw exception-message status land in M6 and in neither M4 nor M5.

**Identity** — `M4numerator + M5numerator + M6 = M1` exactly, over a fixture
containing every status class.

**Campaign KPIs** — C3 `attempted` comes from `reports`, and the test asserts
`campaigns.cache` is untouched and `deliveredCount()`/`contactCount()` are
never called · `contacts targeted` uses `COUNT(DISTINCT tracking_logs.contact_id)`
and is not inflated by two sends to the same contact · a campaign with zero
reports renders zeros and **no divide-by-zero** · M4/M5 percentages never
exceed 100%.

**Contact identities** — K1 ignores the range · K2 respects it · K4 sums to
K1 · K3's daily series sums to K2.

**No ChatBox** — a static assertion that no file under
`app/Library/Analytics/**`, `app/Http/Controllers/Customer/Business/AnalyticsController.php`
or `resources/views/customer/business/analytics/**` references `chat_box`,
`ChatBox` or `ChatBoxMessage`.

**No fake revenue/ROI** — a rendered-content assertion that the overview
contains none of: revenue, ROI, profit, earnings, deal, pipeline, won, lost,
close rate, booking, conversion rate, lead source, cost, spend.

**Automations (after B4)** — A2's denominator excludes `skipped` and
`pending` · a foreign Business's executions are excluded · the panel is
absent when the table is absent, and renders neither zeros nor an error.

**Agency Prospecting separation** — no analytics query touches any
`agency_prospect*` table · the Business analytics page renders no prospecting
figure and no link to the prospecting overview.

**Billing separation** — no analytics query touches `business_usage_*`,
`invoices`, `subscriptions` or `payment_*` · the page shows no balance, spend
or cost figure · it links to Usage & Billing rather than duplicating it.

**Query count / no N+1** — the overview issues at most **12** queries in total
· the campaign page issues at most **2** aggregate queries regardless of page
size, asserted by rendering a page of 25 campaigns and counting.

**Pagination** — C3 paginates at 25 · page 2 returns a disjoint set.

**Throttle** — `GET /series` returns 429 after 60 requests in one minute.

**M2 component adoption** — the overview returns 200 and uses `x-card`,
`x-table`, `x-badge`, `x-empty-state` · a Business with no data renders the
empty state · the new chart view satisfies `ChartTokenContentTest` (references
`PlatformTheme`, contains no `7367F0`).

**Removed legacy Reports routes → 404** — **all 29 §12.3 declarations**,
asserted individually for an authenticated customer holding `view_reports`:
the 25 `ReportsController` routes (§12.3 A), the 3 `CampaignController`
routes (§12.3 B), and `/view-charts` (§12.3 C).

**Removed exports → 404** — `/reports/export`, `/reports/export/sent`,
`/reports/export/receive`, `/reports/export/{campaign}`,
`/reports/campaign/export`.

**Removed legacy campaign controls → 404** — `POST /reports/campaigns/{campaign}/pause`,
`/restart` and `/resend`, asserted for the campaign's **own** owner, so the
test proves the route is gone rather than merely scoped.

**Canonical Business-scoped campaign controls still work** — `POST` to
`customer.workspaces.businesses.outreach.campaigns.{pause,restart,resend}`
succeeds for an authorized actor on their own Business's campaign. Foreign
Workspace / Business / campaign already fails closed through B1's existing
coverage, `tests/Feature/Security/OutreachSecurityTest.php:348`
(`test_campaign_show_pause_restart_resend_destroy_deny_a_different_business`),
which B5 must leave passing and must not modify. **B5 does not modify
`OutreachController` or `CampaignController`.**

**Removed ghost routes → 404** — `/admin/ai-analytics`, `/admin/hot-leads`,
`/admin/hot-leads/mark-called`, `/admin/ai-variants/update`,
`/admin/ai-analytics/book/{id}`.

**Full regression** — the complete suite after the focused set, per
`AGENTS.md`.

---

## 22. HUMAN DECISIONS — RESOLVED

All three decisions raised by the first draft were resolved by human review
(Correction 1). **No product decision remains open.**

1. **`automation_executions` `(business_id, created_at)`** — **B4 supplies
   it.** B4 owns the table, so Lane A amends the B4 contract, migration and
   schema test before B4 merges. B5 expects the index, must re-verify it
   after B4 merges, and **stops and reports** if it is absent rather than
   altering a B4-owned table. B5's own migration stays at exactly the six
   indexes in §11.1. See §9.1.

2. **`POST /reports/campaigns/{campaign}/{pause,restart,resend}`** —
   **deleted with the surrounding group.** B1 already ships canonical
   Business-scoped replacements at
   `customer.workspaces.businesses.outreach.campaigns.{pause,restart,resend}`
   (`routes/customer.php:695-697`), each running the full tenancy chain,
   the same demo restriction and the same repository core, while the legacy
   methods are entirely unscoped (S-16). No preservation, redirect or shim.
   See §12.3.1.

3. **Ghost-surface cleanup scope** — **stays inside the B5 product PR.** Not
   split. The live producer defects in `EloquentCampaignRepository` and
   `DLRController` remain out of B5 and stop-listed, and get their own future
   contract; B5 must not touch those paths to accommodate them. See §14.

The only thing the implementation pass must still confirm is the ordinary
predecessor check: re-verify the **actually merged** B4 tree (§20) — its
`automation_executions` schema, its status and trigger enums, its
`routes/customer.php` shape, and that none of the six §11.1 indexes already
exists.

Nothing in this document authorizes implementation. B5 product work requires
B4 to merge first and its own explicit human authorization.
