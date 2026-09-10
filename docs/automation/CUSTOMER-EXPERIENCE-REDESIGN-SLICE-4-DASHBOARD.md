# Customer Experience Redesign — Slice 4: dashboard rebuild

**Contract only.** No product code, no migration, no route change and no test
is implemented by this lane. One path changes: this document.

**Parent:** `docs/automation/AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md`
§13 (dashboard redesign), §16 Slice 4, §17 (T-DASH-1..4, T-PERF-1), §14
(states), §9 (screen placement).

**Base:** `origin/main` at `823448994c2586d3818ad8333088e4976bcbc309`
(PR #237, the Slice 2A navigation contract). The theme-asset predecessor
PR #236 is merged and its consequences are re-verified in §2.

**Authorises nothing to run.** This contract may merge; §17 states exactly
what must merge before a single line of Slice 4 is written.

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
3  Recent / headline      B5 headline figures, each with a period comparison
4  Spend / account health payer-authorised actors only
5  Quick actions          at most four
```

**No charts.** Detailed analysis stays in Results (B5). The dashboard links
to `…businesses.analytics.overview`; it never renders a series.

### 4.1 Headline metrics — B5 only, never re-implemented

Every headline figure comes from `BusinessAnalyticsPresenter` or the existing
`BusinessAnalyticsQueries` service seam. **No SQL against `reports`,
`campaigns`, `contacts`, `contact_groups` or `automation_executions` may be
written inside `app/Library/Dashboard/**`.** If a needed headline is not
already a B5 method, the correct move is to add it to B5 under B5's own
contract — not to write a parallel query here.

Permitted concepts, and only these:

| Concept | B5 source |
|---|---|
| Messages out / in | `messageKpis()` |
| **Provider accepted** | `messageKpis()` |
| **Confirmed failed** | `messageKpis()` |
| New contacts | `contactKpis()` |
| Campaigns created | `campaignKpis()` |
| Automation executions and failures | `automationKpis()` — **absent, not zeroed, when it returns null** (F10) |
| Advisor open / completed | `advisorKpis()` |

**The vocabulary is B5's, verbatim.** The word for a message the provider
took is **"provider accepted"**. The word **"delivered"** must not appear as
a customer-facing dashboard metric, because F9's predicate proves the
repository does not know handset delivery.

**Forbidden outright**, because no authoritative source exists anywhere in
this repository: **revenue, ROI, reply rate, handset delivery, pipeline
value, bookings, conversion.** A test asserts their absence from the rendered
response (§18 #23).

**Not available, and not this slice's to solve:** any conversation metric
(F7, §9).

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

## 9. Conversations

**No conversation metric and no Inbox quick action in Slice 4.**

`chat_boxes` has no `business_id` (F7), so a Business-scoped conversation
figure is not computable. **Slice 4 must not solve that** — Slice 2A records
it as Slice 2B's debt, to be paid "as one atomic move: route, controller,
links, tests", and Slice 2A is itself forbidden from touching ChatBox.

If Slice 2B has merged before implementation begins, Slice 4 **consumes** the
resulting Business-scoped route and seam, adding the Inbox quick action and,
if 2B exposes one, a conversation headline. Otherwise Slice 4 remains
blocked, because **2B is a hard predecessor** under the parent redesign order
(§17).

Under no circumstance does Slice 4 link the account-scoped
`customer.chatbox.index` from a Business Home and call it Business-scoped.

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
| Inbox | the Business-scoped Conversations route **2B delivers** | only after 2B (§9) |
| One high-value setup action | e.g. publish the website, connect Google — only where the same status column that raises the matching attention item proves it | mechanically justified only |

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

**Budget, excluding the shared shell** — the same exclusion convention
`AnalyticsPerformanceTest` already uses (F18):

| Home | Ceiling |
|---|---|
| Business Home | **≤ 10** dashboard-owned queries |
| Agency Account Home | **≤ 12** dashboard-owned queries |

**No growth with the number of Businesses. No growth with the number of
campaigns or contacts.** Asserted by doubling the fixture and asserting an
identical count (§18 #27).

Reuse B5's cache and service (`CACHE_TTL_SECONDS = 300`); do not add a second
cache layer. **No aggregate tables, no warehouse, no dashboard-specific
denormalisation.** The budget is achievable because each B5 KPI is one query
and the wallet, website and GBP reads are all `business_id`-unique single
rows.

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

### May consume, never modify

B5 Analytics services · Slice 2A's `MenuEntitlements` and the navigation
context · usage-wallet models and read APIs · Website and GBP status models ·
the Opportunity repository · `EntitlementManager::decideBusinessSlotCapacity()`

### Stop-list — not authorised

`routes/**` · `database/migrations/**` · any ChatBox route, controller or
model · `app/Library/Usage/UsageWalletManager.php` and
`BillingProfileManager.php` · `app/Library/Analytics/**` (including changing
a B5 query "for convenience") · `app/Library/Navigation/**` ·
`app/Library/Entitlement/**` · `resources/lang/en/locale.php` (Slice 1) ·
`resources/views/components/empty-state.blade.php` and
`layouts/partials/empty-state.blade.php` · `resources/views/panels/**` ·
`resources/views/layouts/**` · provider or messaging runtime · Website or GBP
behaviour · mobile navigation · `public/**` and `resources/scss/**` beyond
§14.1's proven set · `docs/automation/AI-AUTONOMY-STATE.json`

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
   parent redesign order (§9).

**Chat A is not otherwise a hard predecessor.** If the final Slice 3
messaging identity exists at implementation time, add **only** the
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
| 25 | **Business Home budget** | ≤ **10** dashboard-owned queries, counted with `DB::listen`, shell excluded |
| 26 | **Agency Account Home budget** | ≤ **12**, same method |
| 27 | **No N+1 growth** | doubling Businesses, contacts and campaigns leaves both counts identical |
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
