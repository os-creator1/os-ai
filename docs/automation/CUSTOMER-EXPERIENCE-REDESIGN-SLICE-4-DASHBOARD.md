# Customer Experience Redesign — Slice 4: dashboard rebuild

**Contract only.** No product code, no migration, no route change and no test
is implemented by this lane. One path changes: this document.

**Parent:** `docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md`
§13 (dashboard redesign), §16 Slice 4, §17 (T-DASH-1..4, T-PERF-1), §14
(states), §9 (screen placement).

**Base:** `origin/main` at `634ff2b0d4840ecb4cdd1304d8083647cfcd16b9`
(PR #238, the Legacy Provider webhook-measurement contract — documentation
only, one new file, no product code). Originally written against
`823448994c2586d3818ad8333088e4976bcbc309` (PR #237, the Slice 2A navigation
contract) and merged forward normally. The theme-asset predecessor PR #236 is
merged and its consequences are re-verified in §2.

**This correction does not modify the S0/S1 measurement contract** that
PR #238 landed; it is inherited unchanged through the merge.

**Authorises nothing to run.** This contract may merge; §17 states exactly
what must merge before a single line of Slice 4 is written.

**Correction 1 (this revision)** closes one concrete defect: the first
revision required a period comparison on every headline while authorising no
implementation seam for one. `BusinessAnalyticsPresenter::buildOverview()`
accepts a single `AnalyticsDateRange` and assembles a single period, and no
previous-period DTO, presenter or service exists anywhere in the repository.
§4.2–§4.6 lock the architecture instead of dropping the requirement.
Correction 1 also converts Conversations from "not available" to a locked
Slice 2B read seam, and replaces the single query ceiling with three.

---

## 1. The defect this slice exists to fix

`routes/auth.php:56` registers `GET /dashboard` → `User\UserController@index`,
name `user.home`, middleware `auth`, `verified`, `business.onboarding`, view
`resources/views/customer/dashboard.blade.php`.

> **There is exactly one customer dashboard, and it is neither Account-frame
> nor Business-frame. It is user-scoped.**

Every count on it resolves through `Auth::id()` or `Auth::user()->customer`.
`CustomerShellComposer` supplies the resolved `CustomerContext` to
`panels.sidebar`, `panels.navbar`, `panels.breadcrumb` and two components —
**never to the dashboard body**. A Core owner, a Growth owner, an Agency
owner and restricted staff all receive the same page. There is no tier
branch and no frame branch anywhere in the controller or the view.

Three consequences, each verified:

* **View-as-client is actively misleading.** The shell renders the
  view-as banner while the body renders the *agent's* own legacy counts,
  because `Auth::id()` under view-as is still the agent. Nothing
  cross-tenant is disclosed; the frame is simply misrepresented. This is
  the single worst behaviour on the current page.
* **Six inline Eloquent queries run inside the Blade template**, exactly the
  parent's D-2. Two of them mutate one query builder and execute it twice.
* **Zero entitlement resolution.** Tiles and actions gate on legacy
  permissions only — the D-20 shape.

---

## 2. Facts re-verified against this base

| # | Fact | Evidence |
|---|---|---|
| F1 | One dashboard route, user-scoped | `routes/auth.php:56`; `UserController@index` |
| F2 | Dashboard body never reads `CustomerContext` | `MenuServiceProvider` composes only `panels.sidebar`, `panels.navbar`, `panels.breadcrumb`, `components.customer-context-switcher`, `components.view-as-banner` |
| F3 | `soleAccessibleBusiness()` is a nested N+1 | 1 × `allForUser`, then 1 per Workspace, then `WorkspaceManager::userCanAccessBusiness()` (`app/Library/Workspace/WorkspaceManager.php:97`) at **2–4 queries per Business** — it re-fetches the Business it was handed, plus its Workspace, plus membership, plus assignment |
| F4 | The Opportunity panel is sole-Business-only | `UserController::opportunityPanel()`; an Agency owner pays F3's cost for a panel that then renders nothing |
| F5 | Current cost ≈ **21–24 queries** for a one-Business Core customer | static derivation; 6 of them inline in Blade |
| F6 | `invoices` carries only `user_id` — no `business_id`, no `workspace_id` | `Invoices` usage in `UserController@index`; `InvoiceController` scopes all four of its reads by `user_id` |
| F7 | `chat_boxes` has **no `business_id`** | `2021_03_31_125855_create_chat_boxes_table.php` plus the five later chat-box migrations; it was not among the 11 tables in `2026_09_05_120001_add_nullable_business_id_to_tenancy_tables.php` |
| F8 | B5 is Business-scoped, honest and one query per KPI | `BusinessAnalyticsQueries::messageKpis/campaignKpis/contactKpis/advisorKpis/automationKpis`; `BusinessAnalyticsPresenter::CACHE_TTL_SECONDS = 300` |
| F9 | B5's acceptance vocabulary is exact | `ACCEPTED_SQL = "(customer_status = 'Delivered' OR customer_status LIKE 'Delivered|%')"` — never `LIKE '%Delivered%'`, which also matches `Undelivered`. `CONFIRMED_FAILURE_STATUSES = ['Undelivered','Expired','Rejected','Failed','Skipped']` |
| F10 | `automationKpis()` returns **null**, not zeros, when the B4 table is absent | MySQL 1146 branch; any other error propagates |
| F11 | Wallet health is one row | `business_usage_wallets.business_id` is UNIQUE; `billing_status`, `debt_balance_micro`, `paid_activity_paused_at`, `available_balance_micro`, `auto_recharge_threshold_micro`, `consecutive_recharge_failures`, `committed_spend_this_period_micro`, `reserved_balance_micro`, `spend_period_start/end_utc`, `monthly_spend_cap_micro` |
| F12 | Website status is one row | `websites.business_id` UNIQUE, `status` default `draft` |
| F13 | GBP status is one row | `business_google_connections.business_id` UNIQUE, `state ∈ {pending, active, revoked, disconnected}`; `GoogleLocationHealth` carries nine cases |
| F14 | Advisor vocabulary exists | `OpportunityStatus{open, awaiting_approval, in_progress, snoozed, completed, dismissed}`, `OpportunityFreshness{current, stale}`, `UserController::OPPORTUNITY_PANEL_LIMIT = 5` |
| F15 | **No loading primitive exists.** Still true after PR #236 | `resources/views/components/` holds 22 components — alert, badge, branding-×4, button, card, customer-context-switcher, customer-nav-item, dialog, ds-icon, empty-state, input, menu, pagination, select, switch-toggle, table, tabs, tooltip, view-as-banner. No spinner, no skeleton, no loading. PR #236 published assets, not components |
| F16 | Two empty-state implementations exist | `components/empty-state.blade.php` (icon/title/description + action slot) and the richer `layouts/partials/empty-state.blade.php` (title/explanation/`state ∈ {empty, unconfigured, locked}`/primary/secondary/ownerHint/icon/iconLabel, each state carrying a visible word and a data attribute) |
| F17 | ApexCharts is present but the dashboard is chart-free | `public/vendors/js/charts/apexcharts.js` is published and manifested; `DashboardRenderTest` already pins `assertDontSee('apexcharts')` and `assertDontSee('id="sms-reports"')` |
| F18 | The query-budget house pattern is established | `DB::listen` via `tests/Feature/Analytics/Concerns/CreatesAnalyticsFixtures::capturedSql()`; `AnalyticsPerformanceTest` asserts ≤ 7 KPI and ≤ 12 tenancy+KPI; `AnalyticsCampaignTest` asserts ≤ 9 |
| F19 | Slice 2A defines the entitlement seam this slice must consume | `App\Library\Navigation\MenuEntitlements` (immutable, request-local, `allows(string $featureKey): bool`, **no policy of its own**) built from `EntitlementManager::snapshotBusinessFeatureDecisions()`, contracted at **≤ 6 queries per request** and **0** in the Account frame |
| F20 | `dashboard-ecommerce.css` has **exactly one caller** | `resources/views/customer/dashboard.blade.php:8`. The only other tracked references are the `public/mix-manifest.json` entry and the SCSS source `resources/scss/base/pages/dashboard-ecommerce.scss`. `webpack.mix.js:43` compiles `scss/base/pages/**/!(_)*.scss` by glob |
| F21 | **There is no previous-period seam.** `buildOverview()` takes one `AnalyticsDateRange` and `assemble()` builds one period from it | `BusinessAnalyticsPresenter::buildOverview()`, `::assemble()`. No comparison DTO, presenter or service exists in `app/DTO/Analytics/**` or `app/Library/Analytics/**` |
| F22 | `assemble()` costs 7 queries | `messageKpis`, `messageVolumeSeries`, `campaignKpis`, `contactKpis`, `contactGrowthSeries`, `advisorKpis` (only when `opportunity.enabled`), `automationKpis` — matching `AnalyticsPerformanceTest`'s ≤ 7 |
| F23 | B5's cache is already Business-scoped, never global | `BusinessAnalyticsPresenter::cacheKey()` = `'b5_analytics_' . business_id . '_' . $range->cacheKey()`, TTL 300 s. `AnalyticsDateRange::cacheKey()` returns the preset name, or `custom_<start>_<end>` for a custom range |
| F24 | `AnalyticsDateRange` already carries correct Business-local, DST-safe semantics | `PRESET_LAST_30_DAYS`; `DEFAULT_PRESET = PRESET_LAST_30_DAYS`; `MAX_CUSTOM_DAYS = 92`; `build()` derives `startUtc`/`endUtc` through `localDayStartInStorageTz()` as a **half-open** interval `>= startUtc AND < endUtc`; each daily bucket boundary is derived from its own local date, "never by adding a fixed 86 400-second offset". Its docblock forbids `whereDate()`, `DATE()/DAY()`, date-only `whereBetween`, `strtotime()` fallback and `CONVERT_TZ()` in the range filter |
| F25 | The exact DTO fields the headlines need | `MessageKpis{outbound, api, inbound, accepted, confirmedFailed}` with `acceptedRate(): ?float` and `confirmedFailedRate(): ?float` — **both already null when `outbound` is 0**. `ContactKpis{totalNow, newInRange, subscribedNow, unsubscribedNow, groupCount}`. `AutomationKpis{executionsInRange, byStatus, byTrigger}` with `succeeded()`, `failed()`, `skipped()`, `pending()` |
| F26 | B5 states its own acceptance vocabulary | `MessageKpis` docblock: *"'Accepted' means accepted by the provider at send time (M4), never handset delivery."* |

---

## 3. Locked product architecture

**There is no longer one generic user-scoped dashboard.** `user.home` renders
by **`CustomerContext` frame**, and by nothing else:

| Resolved frame | Renders |
|---|---|
| Business frame | **Business Home** (§4) |
| Account frame, Agency tier | **Agency Account Home** (§7) |
| Account frame, no Business | **Zero-Business account state** (§12) |

**`Auth::id()` is never the dashboard's data-scope decision.** It remains the
capability actor — the identity whose permissions and access are evaluated —
and it is never a tenant key. Every figure, flag and link resolves from the
Business or Workspace the context supplies.

**View-as-client renders the viewed client's resolved Business context** —
the client's Business, the client's name in the header, the client's data —
never the agent's legacy counts. The view-as banner remains visible on the
dashboard in every layout branch.

**No second context resolver, and no primary-Business guessing.**
`soleAccessibleBusiness()` and `opportunityPanel()` are **deleted**: once the
frame supplies the Business, both the guess and its N+1 disappear.

---

## 4. Business Home

Five bands, in the parent's §13.1 order. **No raw count appears without an
interpretation beside it.**

```
1  Attention              only when non-empty; ordered by severity then scope
2  Recommended next steps Advisor only; absent when there is nothing to say
3  Recent / headline      a small fixed set of figures, each with a
                          current-vs-previous comparison and an honest
                          interpretation (§4.2 – §4.6)
4  Spend / account health payer-authorised actors only
5  Quick actions          at most four
```

**No charts.** Detailed analysis stays in Results (B5). The dashboard links
to `…businesses.analytics.overview`; it never renders a series.

### 4.1 Headline metrics — B5 only, never re-implemented

Every headline figure comes from the existing `BusinessAnalyticsQueries`
methods, composed by the Analytics-owned seam of §4.3. **No SQL against
`reports`, `campaigns`, `contacts`, `contact_groups` or
`automation_executions` may be written inside `app/Library/Dashboard/**`.**
If a needed headline is not already a B5 method, the correct move is to add
it to B5 under B5's own contract — not to write a parallel query here.

**The headline row is deliberately restrained.** These, and only these:

| Headline | B5 source | Field |
|---|---|---|
| **Messages sent** | `messageKpis()` | `MessageKpis::$outbound` — the honest outbound-attempted count. **Never called "delivered"** |
| **New contacts** | `contactKpis()` | `ContactKpis::$newInRange` |
| **Automation runs** | `automationKpis()` | `AutomationKpis::$executionsInRange` — only when Automations data exists and the feature is entitled; **absent, not zeroed, when the method returns null** (F10) |
| **Conversations started** | Slice 2B's read seam (§9) | not a B5 concept — Conversations owns `chat_boxes` |
| Provider accepted *(optional)* | `messageKpis()` | `MessageKpis::$accepted` / `acceptedRate()` |
| Confirmed failed *(optional)* | `messageKpis()` | `MessageKpis::$confirmedFailed` / `confirmedFailedRate()` |

**Campaigns created and Advisor counts do not occupy the headline row**,
even though B5 exposes both. Campaigns has a better destination in Results,
and Advisor has its own band (§6). Exposure is not a reason to display.

**The vocabulary is B5's, verbatim.** The word for a message the provider
took is **"provider accepted"** — B5 states it itself: *"'Accepted' means
accepted by the provider at send time (M4), never handset delivery"* (F26).
The word **"delivered"** must not appear as a customer-facing dashboard
metric, because F9's predicate proves the repository does not know handset
delivery.

**Forbidden outright**, because no authoritative source exists anywhere in
this repository: **revenue, ROI, reply rate, handset delivery, pipeline
value, bookings, conversion.** A test asserts their absence from the rendered
response (§18 #23).

### 4.2 The dashboard period — exactly last 30 days, both windows

**Current period: B5's `AnalyticsDateRange::PRESET_LAST_30_DAYS`** — the 30
Business-local calendar dates ending today. This is already B5's
`DEFAULT_PRESET` (F24), so Dashboard and Results agree by construction rather
than by coincidence.

**There is no Dashboard range picker.** Range selection stays in
Results/Analytics. One fixed period keeps the cache key small, the budget
predictable and the comparison meaningful.

**Previous period: the immediately preceding 30 Business-local calendar
dates.** With a current window of 12 Aug – 10 Sep, the previous window is
13 Jul – 11 Aug.

Constructed **only** from local calendar dates, reusing
`AnalyticsDateRange`'s own semantics:

```
previousEndLocal   = current.startLocal->subDay()
previousStartLocal = previousEndLocal->subDays(29)
previousRange      = AnalyticsDateRange::fromInput(
                         ['range' => 'custom',
                          'start' => previousStartLocal->format('Y-m-d'),
                          'end'   => previousEndLocal->format('Y-m-d')],
                         $businessTimezone,
                     )
```

Thirty days is far inside `MAX_CUSTOM_DAYS = 92`, and routing the previous
window through `fromInput()` means its `startUtc`/`endUtc` come from
`AnalyticsDateRange::localDayStartInStorageTz()` — the same half-open
`>= startUtc AND < endUtc` interval B5 already uses.

**Prohibited, explicitly:**

* subtracting `30 * 86400` seconds, or any fixed-second offset — a
  spring-forward day is 23 hours and a fall-back day is 25, and B5's own
  docblock already forbids this mechanic;
* `whereDate()`, `DATE()`, `DAY()`, date-only `whereBetween`, a `strtotime()`
  fallback, or `CONVERT_TZ()` in a range filter;
* **any new timezone implementation.** Carbon's own DST resolution, through
  `AnalyticsDateRange`, is the only one.

### 4.3 The comparison seam — Analytics-owned, narrow, composing only

One new class is authorised, and it belongs to **Analytics, not Dashboard**:

```
app/Library/Analytics/BusinessDashboardAnalyticsPresenter.php
```

That exact name, unless a mechanically stronger existing naming convention in
`app/Library/Analytics/**` requires an equivalent one — in which case the
implementation records why.

**Its only purpose** is to provide the bounded Business Home
current-versus-previous headline dataset.

**It composes the existing B5 query methods and writes no KPI formula of its
own.** It may call, once per range:

* `BusinessAnalyticsQueries::messageKpis()`
* `BusinessAnalyticsQueries::contactKpis()`
* `BusinessAnalyticsQueries::automationKpis()`

**It must not** independently query `reports`, `campaigns`, `contacts`,
`contact_groups` or `automation_executions` with newly written formulas.

**It must not load** message volume series, contact-growth series, campaign
performance pages or any other chart payload merely to compute a headline
delta. `messageVolumeSeries()`, `contactGrowthSeries()`, `campaignKpis()`,
`advisorKpis()` and `campaignPerformancePage()` are **not** called by this
seam.

**It must not alter B5's public Results behaviour.** `buildOverview()`,
`buildSeries()`, `buildCampaignsPage()` and every existing query method keep
their present signatures and semantics.

**No generic comparison engine. No generic metrics registry.** This seam
serves one page's one row.

If either period's `automationKpis()` returns null (F10), the automation
headline is **absent for both periods** — never zeroed on one side and
populated on the other, which would fabricate a delta.

### 4.4 The comparison value shape

Each headline carries one bounded, immutable comparison value:

| Field | Rule |
|---|---|
| `current` | integer, from the current range |
| `previous` | integer, from the previous range |
| `absoluteDelta` | `current - previous`, always computed |
| `percentDelta` | **nullable.** Computed **only** when `previous != 0` |
| `trend` | `up`, `down` or `unchanged` — derived from `absoluteDelta`, never from `percentDelta` |

**When `previous == 0`, `percentDelta` is null and the page renders plain
wording** — *"Up from 0 in the previous 30 days"*, or equivalent. It must
**never** render infinity, `NaN`, a division-by-zero artefact, or a
fabricated 100%.

**No arbitrary floating precision.** `percentDelta` is rounded to one decimal
place, matching `MessageKpis::acceptedRate()`, which already returns `?float`
rounded to one decimal and **already returns null when its denominator is
zero** (F25). That is the repository's own precedent and this contract
follows it rather than inventing a second rule.

When both periods are 0 the comparison is `unchanged`, `absoluteDelta = 0`,
`percentDelta = null`, and the copy says so plainly.

### 4.5 Interpretation — code-backed polarity, never a blanket "up is good"

The parent requires a sentence saying whether a change is good. That is
implemented **honestly**, from a code-backed polarity per metric — not as
`up = good, down = bad` for everything.

| Metric | Polarity | Rule |
|---|---|---|
| Provider-accepted **rate** | **directional** | higher = positive · lower = negative · same = neutral |
| Confirmed failures | **directional, inverted** | lower = positive · higher = negative · same = neutral |
| Messages sent | **descriptive only** | never claims higher volume is good |
| Conversations started | **descriptive only** | same |
| Automation runs | **descriptive only** | same |
| New contacts | **descriptive growth** | may describe growth precisely; must not imply revenue or lead quality |

Volume and activity metrics get neutral, descriptive copy — *"Message
activity increased from the previous 30 days"* — and, where it helps,
*"Volume is activity, not a success measure."*

**No AI determines polarity.** No generated business-health judgement, no
success score, no composite index. Polarity is a property of the metric,
declared in code, and a test pins it.

Every raw number still carries context beside it, which satisfies the
parent's "no raw count without an interpretation" principle without lying
about what the number means.

### 4.6 Cache policy for the comparison seam

The seam caches **per Business and per range**, reusing B5's own shape rather
than inventing a second strategy. B5's key is
`'b5_analytics_' . business_id . '_' . $range->cacheKey()` at a 300-second
TTL (F23), and `AnalyticsDateRange::cacheKey()` already yields a distinct
value for the current preset (`last_30_days`) and the previous custom window
(`custom_<start>_<end>`).

**Locked:**

* the key **must** carry the Business id and the range key;
* **current and previous periods have distinct keys** — never one blended
  entry;
* **never a global key** such as `dashboard_headlines`. Business A must not
  be able to receive Business B's comparison, and a tenant-isolation test
  asserts it (§18 #45);
* **no persistent aggregate table**, no denormalisation, no warehouse;
* the existing five-minute Analytics cache strategy is reused where it is
  safe to do so, rather than recomputing on every render.

The narrowest correct cache implementation is chosen mechanically during
product work; this contract fixes the isolation properties, not the class.

---

## 5. Attention

**No new table. No generic notification centre.** `app/Notifications/**`
holds 22 event-triggered Laravel notifications and `Announcements` is an
admin broadcast; neither is an attention model, and neither may be
repurposed into one.

A **bounded, computed list**, derived per request from status columns that
already exist. Nothing is persisted.

### 5.1 The item shape, locked

| Field | Rule |
|---|---|
| `type` | a case of a **code-defined enum**, `App\Enums\Dashboard\AttentionType`. Never a free string |
| `severity` | a case of `App\Enums\Dashboard\AttentionSeverity` — `blocking`, `warning`, `informational`. Rendered as a **word**, never colour alone |
| `scope` | the Business or the Account the item belongs to |
| `text` | one plain customer sentence. No configuration key, no enum value, no internal noun, no provider name |
| `route` | a **real remediation destination** that the actor is authorised to reach. An item with no reachable fix is not rendered |

### 5.2 Initial supported types — mechanically proven sources only

| Type | Source | Verified |
|---|---|---|
| `WalletSuspended` | `business_usage_wallets.billing_status = suspended` | F11 |
| `OutstandingDebt` | `debt_balance_micro > 0` | F11 |
| `PaidActivityPaused` | `paid_activity_paused_at IS NOT NULL` | F11 |
| `LowBalance` | `available_balance_micro` below `auto_recharge_threshold_micro` | F11 |
| `AutoRechargeFailing` | `consecutive_recharge_failures > 0` | F11 |
| `WebsiteUnpublished` | `websites.status = draft` | F12 |
| `GoogleConnectionLost` | `business_google_connections.state ∈ {revoked, disconnected}` | F13 |
| `GoogleLocationUnhealthy` | `GoogleLocationHealth` in its unhealthy cases | F13 |
| `AutomationFailing` | failure counts from `automationKpis()`; **absent when it returns null** | F8, F10 |

**Optional tenth type — `BusinessPhoneMissing`.** Included **only if** the
final merged Slice 3 messaging schema provides an authoritative identity
state. If it does, add the one enum case and its source; if it does not, ship
the other nine. **Chat A is not a hard predecessor of this slice** and must
not be made one for this optional type (§17).

Anything not in that table is **not** an attention item. In particular:
"payment method expiring" is **excluded** — no verified source was found, and
a status claim without a proven source is a fabricated alert.

### 5.3 Dismissal

**No persistent dismissal in Slice 4.** An item appears while its condition
holds and disappears when the condition clears. Dismissal is durable state;
durable state needs a table, an owner and a policy, and this slice adds none.

---

## 6. Advisor

**Deterministic prerequisite failures are Attention. AI recommendations are
recommendations. The two never mix.**

A billing suspension, an outstanding debt, a paused wallet, a missing sender,
an unpublished website, a lost Google connection or an unavailable feature
has a known cause, a known fix and a certain truth value. None of them may
ever be rendered as an AI suggestion.

An Advisor opportunity renders in **Recommended next steps** only when **all
three** hold:

1. `config('opportunity.enabled')` is true;
2. `status = open`;
3. `freshness = current`.

**A stale opportunity is never shown**, and a stale opportunity is never
promoted into the Attention band. Bounded at a **maximum of 5**, aligned with
the existing source-controlled `OPPORTUNITY_PANEL_LIMIT` (F14) — never
request input. The band is **absent**, not empty, when there is nothing to
recommend.

The current sole-Business restriction disappears: the selected Business is
the scope.

---

## 7. Agency Account Home

**No aggregation of client messages or client contacts.**

The boundary is stated honestly: it is **not** a tenancy prohibition.
`WorkspaceManager::userCanAccessBusiness()` grants a Workspace owner access
to every Business in the Workspace, so aggregating client content would be
*authorised*. It is refused for two other reasons, and the contract says so
rather than dressing a product decision as a security one:

1. **Budget.** Aggregating B5 across N clients costs N × 7 queries. At twelve
   clients that is 84 queries for one page — non-viable regardless of
   permissions. **No N × B5 analytics fan-out.**
2. **Product.** An Agency owner's question at the Account frame is *which
   client needs me*, not *what did all my clients send*. Content belongs
   inside the client's own Business frame (§4).

**The rule, locked: flags and counts at the Account frame; content and KPIs
inside the selected client's Business frame.**

Rendered bands:

| Band | Source | Verified |
|---|---|---|
| Client accounts | the Businesses already carried by `CustomerContextSnapshot` | F2 |
| Per-client attention **flags and counts** | **one** bounded query joining `business_usage_wallets`, `websites` and `business_google_connections` across the Workspace's Businesses — never one query per client | F11–F13 |
| Business-slot capacity | `EntitlementManager::decideBusinessSlotCapacity()` → `BusinessSlotCapacityDecision{currentBusinessCount, includedSlots, additionalSlotsAllocated, effectiveCapacity, unlimited, allowed, denialReason}` | verified |
| Agency Prospecting summary | `agency_prospect_campaigns`, `agency_prospects`, `agency_prospect_campaign_members`, `agency_prospect_messages` — Workspace-scoped | verified |
| Account plan / billing health | plan assignment and `workspace_usage_controls` | verified |

**Aggregate Workspace spend and cap may appear**, because the Account owner
is genuinely accountable for the Workspace aggregate spend cap and aggregate
recharge cap, which already exist as Workspace-level controls. That is the
one permitted aggregate, and it is an account-level control the owner set,
not client content.

---

## 8. The invoice tile

**Removed from Business Home.** `invoices` is keyed to a paying user (F6),
which is neither a Business nor an Account. On a Business Home it is a
category error; on an Agency Account Home it would show the agency owner's
own invoices under a heading implying a client's.

**This slice must not** re-key invoices, add `business_id`, migrate invoice
schema, or invent an Agency aggregate invoice meaning. **Slice 5 owns
billing and settings placement**; Slice 2A §3 already locks invoice history
to `customer.subscriptions.index` with `customer.invoices.*` as an active
prefix, and creates no standalone Invoices leaf.

### 8.1 Security coverage must not shrink

`tests/Feature/Security/DashboardInvoiceScopeTest.php` holds **four** tests
guarding the D-19 fix — the former ungrouped `OR` that disclosed every
tenant's unpaid/pending count. Three assert the scoped computation; the
fourth asserts the rendered response never contains another tenant's totals.

**Deleting that file is prohibited.** The contracted re-point, exactly:

1. The file is **re-pointed, not removed**, onto the surface that still
   renders invoice figures. That surface is proven to exist:
   `InvoiceController` scopes **all four** of its reads by
   `user_id` (`:36`, `:46`, `:53`, `:59`), and
   `resources/views/customer/Accounts/_subscriptions.blade.php:184` renders
   invoice rows.
2. Every existing assertion's **property** survives: with three unrelated
   tenants each holding paid, unpaid and pending invoices, a viewer sees
   only their own; a viewer with zero pending inherits none; a viewer with
   zero unpaid inherits none; and no rendered response contains another
   tenant's totals.
3. **One assertion is added**, not substituted: the rebuilt Business Home
   renders **no** invoice figure at all.
4. The file must end with **at least four** tests and must still fail if an
   unscoped invoice query is reintroduced anywhere.

If implementation finds that no surface renders invoice figures, the
isolation assertions are retained as a repository-level query-shape guard
rather than dropped. **Coverage may move; it may not decrease.**

---

## 9. Conversations — consumed from Slice 2B, not solved here

**Slice 2B is a hard predecessor of Slice 4 (§17).** By the time Slice 4 can
begin, Business-scoped Conversations is not hypothetical, so this contract
states runtime behaviour outright rather than conditionally.

`chat_boxes` has no `business_id` today (F7). **Slice 4 must not solve
that** — Slice 2A records it as Slice 2B's debt, to be paid "as one atomic
move: route, controller, links, tests".

### 9.1 The read seam Slice 2B must expose

Slice 2B delivers a narrow, Business-scoped read seam answering, for one
Business and one half-open UTC range:

```
conversationsStarted(Business $business, CarbonImmutable $startUtc, CarbonImmutable $endUtc): int
```

**Exact semantics**, once 2B has established authoritative Business tenancy:

```
COUNT(chat_boxes)
WHERE business_id = <selected Business>.id
  AND created_at >= range.startUtc
  AND created_at <  range.endUtc
```

Dashboard calls it twice — once for the current range, once for the previous
range (§4.2) — and the two counts feed the same comparison value shape as
every other headline (§4.4). Polarity is **descriptive only** (§4.5).

**This method does not belong in B5.** Conversations owns `chat_boxes`;
putting a chat-box query in `BusinessAnalyticsQueries` would give one table
two owners. The Slice 2B implementation contract is being prepared
concurrently and carries the obligation to provide this exact bounded read.

### 9.2 What Dashboard must never do

* **Dashboard never queries `chat_boxes` directly** — not in
  `app/Library/Dashboard/**`, not in a view, not anywhere. A test asserts it
  (§18 #44).
* Dashboard never adds `business_id` to `chat_boxes`, never edits
  `ChatBoxController` or any ChatBox model, and never attempts tenancy
  remediation.
* Dashboard never links the account-scoped `customer.chatbox.index` and calls
  it Business-scoped. After 2B, **no account-scoped chat-box link survives on
  the dashboard at all** (§18 #47).

If the seam 2B lands differs in name or signature from §9.1, Slice 4 consumes
what 2B actually shipped and records the difference — it does not build its
own.

---

## 10. Entitlement

**Slice 2A provides the authoritative bulk snapshot. Slice 4 reuses it.**

`MenuEntitlements` is an immutable, request-local value object that carries
`EntitlementManager`'s snapshot and answers `allows(string $featureKey)`,
performing no policy of its own (F19). Slice 4 **consumes exactly that
object** and makes **no second entitlement decision**.

A dashboard tile, band or action renders only when **all four** hold — the
same rule 2A locks for the menu:

1. the route is registered;
2. the actor holds the required permission;
3. the context allows it (frame, Business access);
4. **feature entitlement permits it.**

**No N-per-feature `EntitlementManager` loop**, and no per-feature query of
any kind. The snapshot is built once per request. Slice 4 adds **zero**
entitlement queries of its own; 2A's ≤ 6 already covers the request.

**Hiding is not authorization.** Every destination controller stays
independently fail-closed on its own check, aborting **404, never 403**.
Slice 4 weakens no controller gate.

---

## 11. Quick actions

**At most four.** Each shown only under §10's four-way rule.

| Action | Route | Condition |
|---|---|---|
| Send | `customer.workspaces.businesses.outreach.index` | outreach permission |
| Add contact | `customer.workspaces.businesses.contacts.index` | contact permission |
| Add funds | the Business-scoped usage-billing top-up surface | **payer only** |
| Inbox | **the canonical Business-scoped Conversations route Slice 2B lands** | normal permission, entitlement and view-as rules |
| One high-value setup action | e.g. publish the website, connect Google — only where the same status column that raises the matching attention item proves it | mechanically justified only |

Inbox is **not conditional**: Slice 2B is a hard predecessor (§17), so its
route exists before Slice 4 begins. `customer.chatbox.index` — the
account-scoped legacy route — must not appear on the dashboard.

**Only Business-scoped canonical routes.** Linking
`customer.sms.quick_send` or `customer.sms.campaign_builder` is **prohibited** —
both are legacy, account-scoped, and both are exactly what the current
dashboard links today.

**During view-as: no cost-producing, funding, provider or admin action.**
Add funds, plan, billing and provider actions are **absent**, not disabled —
consistent with `ViewAsRouteClassification::allowsMenuEntry()`, which 2A
preserves.

---

## 12. Empty, locked and failure states

**No spinner or loading framework.** The dashboard is server-rendered inside
its budget, so there is nothing to spin for. F15 confirms no loading
primitive exists; Slice 4 does not create one, and Slice 8 owns the loading
vocabulary if a future async band ever needs it.

**Use the richer `layouts.partials.empty-state`** (F16) as it stands.
**Slice 4 consolidates nothing** — the two empty-state systems are Slice 8's
to merge, and touching `resources/views/components/empty-state.blade.php` is
on the stop-list.

| Situation | Behaviour |
|---|---|
| Zero Businesses | `state: empty`, one clear primary action: **Create your first Business**. Nothing else. No zeroed Business tiles — a zero is a claim, and it would be false |
| No Business selected, several available | the Account frame's existing chooser, unchanged from Slice 1B |
| Locked feature | a visible explanatory `state: locked` with a reason and an `ownerHint`, **only where that product surface is itself being shown**. The dashboard must not become an upsell wall: an unentitled feature that has no band on this page is simply **absent** |
| Partial setup | not an empty state — an **Attention item** with a remediation route (§5) |
| A band fails to load | that band degrades **locally** to one plain line and the rest of the page renders. **One failing band must never blank the dashboard.** No generic retry framework, no retry button, no background poll |

---

## 13. Performance

**Every inline Eloquent call is deleted from the Blade template.** The
controller becomes thin: resolve the context, call the presenter, return the
view. `soleAccessibleBusiness()` and `opportunityPanel()` are deleted with it.

**Contracted layer:**

* `App\Library\Dashboard\DashboardSnapshot` — an immutable DTO carrying
  everything one render needs.
* `App\Library\Dashboard\BusinessHomePresenter` and `AccountHomePresenter` —
  assemble the snapshot from the context, the B5 presenter, the wallet,
  website and GBP rows, the Opportunity repository and `MenuEntitlements`.
* `App\Enums\Dashboard\AttentionType` and `AttentionSeverity`.

**Budgets are ceilings, not targets.** Use fewer whenever cache or reuse
permits. The Analytics comparison seam and the Conversations read seam are
product read services *consumed by* the dashboard, so a dashboard-owned
figure alone would understate the real cost of the page. Three ceilings are
locked, and a total.

| Layer | Ceiling |
|---|---|
| Dashboard-owned status and assembly queries | **≤ 10** |
| Analytics current + previous headline queries (§4.3) | **≤ 6** |
| Conversations current + previous counts (§9.1) | **≤ 2** |
| **Total Business Home product-data queries** | **≤ 18** |
| Agency Account Home, dashboard-owned | **≤ 12** |

The Analytics ceiling of 6 is three query methods × two ranges (F22 shows
each is one query); the Conversations ceiling of 2 is one count per range.

**Excluded from every figure above**, following the exclusion convention
`AnalyticsPerformanceTest` already uses (F18):

* the shared shell (auth, `CustomerContextSnapshot`, view-as, menu);
* Slice 2A's entitlement snapshot, which is already amortised across the
  request at its own contracted ≤ 6 (F19) and to which Slice 4 adds nothing.

**The two B5 periods are not free.** They are counted, in full, against the
Analytics ceiling — a service call is still a query.

**If the mechanical implementation proves the existing B5 methods perform
fewer queries than these ceilings allow, the stricter observed number is
what the test asserts.** A ceiling is permission to cost that much, never an
instruction to.

**No N+feature. No N+conversation. No N+campaign. No N+Business.** Asserted
by doubling the fixture and asserting an identical count (§18 #27).

Reuse B5's cache and service (`CACHE_TTL_SECONDS = 300`, §4.6); do not add a
second cache layer. **No aggregate tables, no warehouse, no
dashboard-specific denormalisation.** The dashboard-owned ceiling is
achievable because the wallet, website and GBP reads are all
`business_id`-unique single rows.

**Correctness outranks the budget.** If a required figure cannot be produced
within the ceiling, the implementation stops and reports rather than
dropping the scope predicate or skipping a check to stay under it.

---

## 14. Visual and CSS

M2 primitives only: `x-card` for every band, `x-badge` for severity words,
`x-button` for actions, `x-table` for the Agency client list only,
`x-ds-icon` for icons, `x-alert` for a degraded band,
`layouts.partials.empty-state` for empty and locked states.

**No ApexCharts on the dashboard, no new chart library, no new frontend
framework.** `DashboardRenderTest` already pins the absence of `apexcharts`
(F17) and that pin stays.

### 14.1 `dashboard-ecommerce.css` — proven, not assumed

The caller count was established mechanically across `resources/`, `app/`,
`routes/`, `config/`, `tests/` and the manifest (F20):

| Reference | Kind |
|---|---|
| `resources/views/customer/dashboard.blade.php:8` | **the one and only caller** |
| `public/mix-manifest.json:39` | manifest entry |
| `resources/scss/base/pages/dashboard-ecommerce.scss` | compiled by the `webpack.mix.js:43` glob `scss/base/pages/**/!(_)*.scss` |

Removing the include therefore takes the caller count to **zero**, and
deletion is **authorised** — as one atomic set, because deleting the compiled
CSS alone would leave a dangling manifest entry, and deleting both without
the SCSS source would let the next build re-publish them:

1. the `@section('page-style')` include in the rebuilt view;
2. `resources/scss/base/pages/dashboard-ecommerce.scss`;
3. `public/css/base/pages/dashboard-ecommerce.css`;
4. its `public/mix-manifest.json` entry.

**Two gates on that authorisation.**

* **Re-prove at implementation time.** Re-run the same repository-wide
  search. If any new caller has appeared, **remove only the include and
  leave every asset in place.** Do not delete by assumption.
* **`ThemeAssetPublicationTest` must stay green.** Its
  `test_every_mix_reference_in_a_tracked_blade_view_resolves()` checks
  blade → manifest, so removing a `mix()` call is safe; the deletion set
  above keeps manifest and disk consistent. If any of its assertions turn
  red, the deletion is reverted and only the include is removed.

The wider retirement of `css/base/pages/*` remains **Slice 10's**; this is
one file whose sole caller this slice removes, not a sweep.

---

## 15. Accessibility and responsive

Acceptance criteria, exactly:

* **375 px: no horizontal page scroll.** Bands stack single-column.
* The **Agency client list is usable at 375 px** — the table degrades to a
  stacked list rather than scrolling sideways.
* **One `<main>`. One `<h1>`**, naming the frame and the Business or Account.
* Each band is a `<section>` with `aria-labelledby` pointing at its own
  heading; **heading levels never skip**.
* Every action is a **real focusable link or button** — never a clickable
  `div`. Focus order follows visual order, with a **visible focus indicator**
  throughout.
* **No status conveyed by colour alone.** Every severity and every empty
  state carries a word.
* The **view-as banner renders on the dashboard in every layout branch.**

**No mobile bottom-tab navigation.** That is a later slice's, and Slice 2A
§11 already locks it out of the navigation lane too.

---

## 16. Implementation boundary

### Allowed

* `app/Http/Controllers/User/UserController.php` — `index()` thinned;
  `opportunityPanel()` and `soleAccessibleBusiness()` deleted
* `resources/views/customer/dashboard.blade.php` — replaced
* `resources/views/customer/dashboard/**` *(new)* — band partials
* `app/Library/Dashboard/**` *(new)*
* `app/DTO/Dashboard/**` *(new, only if the snapshot genuinely warrants a
  separate namespace from `app/Library/Dashboard/`)*
* `app/Enums/Dashboard/**` *(new)*
* `tests/Feature/Dashboards/**`
* `tests/Feature/Security/DashboardInvoiceScopeTest.php` — **re-point only,
  per §8.1; deletion prohibited**
* The four `dashboard-ecommerce` artefacts of §14.1 — **only** after the
  zero-caller proof is re-run
* **Correction 1 delta — exactly two additions:**
  * `app/Library/Analytics/BusinessDashboardAnalyticsPresenter.php` *(new,
    §4.3)*
  * its focused Analytics test

  These are **exact paths, not a directory grant.**
  `app/Library/Analytics/**` and `app/Library/Conversations/**` are **not**
  broadly authorised for this redesign.

### May consume, never modify

B5 Analytics query methods and DTOs · Slice 2A's `MenuEntitlements` and the
navigation context · **the exact Conversations read interface Slice 2B
lands (§9.1)** · usage-wallet models and read APIs · Website and GBP status
models · the Opportunity repository ·
`EntitlementManager::decideBusinessSlotCapacity()`

**No modification to a B5 KPI formula is authorised.** If an existing B5
formula proves wrong, the implementation lane **stops and returns that defect
to B5 ownership** rather than correcting it here — a KPI fixed in two places
is a KPI that will disagree with itself.

### Stop-list — not authorised

`routes/**` · `database/migrations/**` · any ChatBox route, controller or
model, and **any direct `chat_boxes` query** · `app/Library/Usage/UsageWalletManager.php` and
`BillingProfileManager.php` · `app/Library/Analytics/**` **except** the one
new file named above — including changing a B5 query "for convenience", and
including `BusinessAnalyticsQueries`, `BusinessAnalyticsPresenter`,
`AnalyticsDateRange` and every `app/DTO/Analytics/**` type ·
`app/Library/Navigation/**` · `app/Library/Entitlement/**` ·
`resources/lang/en/locale.php` (Slice 1) ·
`resources/views/components/empty-state.blade.php` and
`layouts/partials/empty-state.blade.php` · `resources/views/panels/**` ·
`resources/views/layouts/**` · provider or messaging runtime · Website or GBP
behaviour · mobile navigation · `public/**` and `resources/scss/**` beyond
§14.1's proven set · `docs/automation/AI-AUTONOMY-STATE.json` ·
`docs/automation/LEGACY-PROVIDER-WEBHOOK-MEASUREMENT-CONTRACT.md` and the
S0/S1 measurement design it carries

If any stop-listed change looks mechanically unavoidable, the implementation
lane **stops and reports the exact consumer and the necessary path** rather
than widening scope.

---

## 17. Dependencies

**This contract may merge now.**

**Implementation may begin only after all three have merged:**

1. **Slice 1 — terminology** implementation. The dashboard is rebuilt copy;
   rebuilding it before Slice 1 guarantees reintroducing forbidden terms.
2. **Slice 2A — entitlement-aware navigation** implementation. Slice 4
   consumes `MenuEntitlements`; without it, Slice 4 would have to build the
   second decision engine §10 forbids.
3. **Slice 2B — Business-scoped Conversations.** A hard predecessor under the
   parent redesign order. Slice 4 consumes its read seam (§9.1) and its
   canonical route (§11); neither is optional and neither is conditional.

**Because 2B is hard, no runtime behaviour in this contract is written as
"if 2B has merged".** The conversations-started headline and the Inbox quick
action are unconditional features of Slice 4 at implementation time.

**Chat A remains an indirect dependency only.** Slice 2B itself waits on the
final Slice 3 messaging/provider state; Slice 4 inherits that ordering
through 2B and adds **no** direct Chat A dependency of its own. If the final
Slice 3 messaging identity exists at implementation time, add **only** the
`BusinessPhoneMissing` attention type (§5.2). Nothing else in Slice 4 waits
on it, and the attention list is designed so that adding a type is additive.

---

## 18. Test contract

| # | Test | Asserts |
|---|---|---|
| 1 | Core Business Home | renders the five bands, Business-scoped |
| 2 | Growth Business Home | identical to Core except where entitlement differs |
| 3 | Agency Account Home | client list, per-client flags, capacity, prospecting, account health — **and no client content** |
| 4 | Agency inside a selected client account | Business Home for that client, with the client's name in the header |
| 5 | Restricted staff | their scoped Business only; no Account frame |
| 6 | Zero / one / many Businesses | empty state / Business Home / Account frame chooser |
| 7 | **View-as-client** | the **client's** data and the client's name render; no figure derives from the agent; the banner is present |
| 8 | Foreign-Business isolation, per band | a foreign Business's wallet, website, GBP, attention item or headline never appears |
| 9 | Invoice isolation preserved | §8.1's re-pointed file, ≥ 4 tests, still fails on an unscoped invoice query |
| 10 | Invoice tile absent | the Business Home renders **no** invoice figure |
| 11 | Entitlement visibility | an unentitled feature's tile and action are **absent**, not disabled |
| 12 | Permission alone cannot expose an unentitled feature | permission granted + entitlement denied ⇒ absent |
| 13 | Attention condition → item | each of the nine types appears exactly on its condition |
| 14 | Attention clear → no item | clearing the condition removes it, with no persisted dismissal |
| 15 | Attention item shape | every item carries type, severity **word**, scope, plain text and a reachable route |
| 16 | Advisor separation | a deterministic prerequisite failure never renders as a recommendation |
| 17 | Advisor filtering | only `open` **and** `current`, maximum 5; stale never shown |
| 18 | Advisor band absent when empty | absent, not an empty card |
| 19 | Headline KPI equality | each headline equals the B5 method it claims to reuse, against the same fixture |
| 20 | B5 reuse, not duplication | no SQL against `reports`, `campaigns`, `contacts`, `contact_groups` or `automation_executions` inside `app/Library/Dashboard/**` |
| 21 | `automationKpis()` null | the automation band is **absent**, never zeroed |
| 22 | Provider-accepted vocabulary | the rendered response uses "provider accepted"; the bare word "delivered" never labels a metric |
| 23 | **No fake metrics** | no revenue, ROI, reply rate, handset delivery, pipeline value, bookings or conversion string |
| 24 | No inline Blade queries | the dashboard views execute zero Eloquent or query-builder calls |
| 25 | **Business Home dashboard-owned budget** | ≤ **10**, counted with `DB::listen`, shell and 2A snapshot excluded |
| 26 | **Agency Account Home budget** | ≤ **12**, same method |
| 27 | **No N+1 growth** | doubling Businesses, contacts, campaigns and conversations leaves every count identical |
| 28 | No charts | no `apexcharts` and no chart markup |
| 29 | No legacy quick-send links | `customer.sms.quick_send` and `customer.sms.campaign_builder` absent |
| 30 | Quick actions bounded | at most four; none cost-producing during view-as |
| 31 | 375 px structure | no fixed-width container forces horizontal scroll; the Agency list degrades |
| 32 | Landmark structure | one `<main>`, one `<h1>`, `<section>` + `aria-labelledby` per band, no skipped heading level |
| 33 | Focus and severity | every action focusable; every severity carries a word, not colour alone |
| 34 | Band-level degradation | a forced failure in one band leaves the rest of the page rendered |
| 35 | Empty and locked states | zero-Business shows one create action; a locked band shows a reason and an owner hint, never a 404 |
| 36 | No raw locale keys | no rendered string matches `locale.` |
| 37 | `dashboard-ecommerce` | the rebuilt view loads no page stylesheet; if §14.1's deletion ran, `ThemeAssetPublicationTest` is still green |

**Correction 1 additions — every row above is preserved.**

| # | Test | Asserts |
|---|---|---|
| 38 | Current range | Business Home uses `PRESET_LAST_30_DAYS`, and offers no range picker |
| 39 | Previous range | exactly the 30 Business-local calendar dates immediately preceding the current window (12 Aug – 10 Sep ⇒ 13 Jul – 11 Aug) |
| 40 | **DST boundary** | across a spring-forward and a fall-back transition in a non-UTC Business timezone, both windows still cover 30 local dates and the half-open UTC bounds stay correct; no fixed-second arithmetic is used |
| 41 | Messages sent equality | current and previous both equal `MessageKpis::$outbound` from the same B5 fixture |
| 42 | New contacts equality | current and previous both equal `ContactKpis::$newInRange` from the same B5 fixture |
| 43 | Automation runs equality | current and previous both equal `AutomationKpis::$executionsInRange`; when either period is null the headline is **absent for both** |
| 44 | Conversations equality **and** no direct query | the figure equals Slice 2B's read seam for the same range, **and** no `chat_boxes` query originates in `app/Library/Dashboard/**` or a dashboard view |
| 45 | **Comparison cache is tenant-isolated** | Business A's rendered comparison can never be served to Business B; current and previous periods use distinct keys; no global `dashboard_headlines`-style key exists |
| 46 | **Total product-data ceiling** | Business Home total ≤ **18** — dashboard-owned ≤ 10, Analytics ≤ 6, Conversations ≤ 2 — counted with `DB::listen`, shell and 2A snapshot excluded |
| 47 | Inbox route | the Inbox quick action targets Slice 2B's canonical Business-scoped route, and **no account-scoped `customer.chatbox.index` link survives anywhere on the dashboard** |
| 48 | Previous = 0 | `percentDelta` is null and the copy reads plainly; no `INF`, no `NaN`, no fabricated 100% appears in the rendered response |
| 49 | Both = 0 | `unchanged`, `absoluteDelta = 0`, `percentDelta = null` |
| 50 | Increase / decrease / unchanged | `absoluteDelta` and `trend` correct in all three directions for every headline |
| 51 | Directional polarity | a rising provider-accepted rate reads positive; a rising confirmed-failure count reads negative; equal reads neutral |
| 52 | **Volume is never "good"** | an increase in messages sent, conversations started or automation runs is never labelled good, successful or healthy; the copy is descriptive |
| 53 | Acceptance vocabulary | the rendered response uses B5's "provider accepted" wording verbatim and never labels a metric "delivered" |
| 54 | **B5 SQL is not duplicated** | no query against `reports`, `campaigns`, `contacts`, `contact_groups` or `automation_executions` originates in `app/Library/Dashboard/**` |
| 55 | Seam restraint | the comparison seam calls only `messageKpis()`, `contactKpis()` and `automationKpis()` — never `messageVolumeSeries()`, `contactGrowthSeries()`, `campaignKpis()`, `advisorKpis()` or `campaignPerformancePage()` |
| 56 | B5 Results unchanged | `buildOverview()`, `buildSeries()` and `buildCampaignsPage()` behave identically before and after; the existing Analytics suite is green |

Focused suites run first, then `tests/Feature/Dashboards`,
`tests/Feature/Security`, `tests/Feature/Analytics`, `tests/Feature/Assets`
and `tests/Feature/Navigation`, head-to-head against a pristine checkout of
the same base on a validated `TestDatabaseSafety` sibling.

---

## 19. Size and coherence

Slice 4 is **one coherent pull request**: one controller thinned, one view
replaced, one presenter layer added, two enums, band partials, and a focused
test file. No migration, no route, no money, no provider, no tenancy change.

It has a single acceptance question — **does the dashboard answer "what needs
attention, what happened, what next" for the frame the context resolved,
inside its query budget, without inventing a metric?** — and everything in
§16's allowlist serves it.

The risk is scope creep into Slices 2B, 5 and 8. The stop-list is what keeps
it one pull request.
