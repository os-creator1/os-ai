# Results — customer experience redesign

**Base:** `origin/main` at `f6cfd8897b45a4be63073561a9050f080fdbee05` (PR #249).
**Branch:** `agent/results-analytics-customer-ux-redesign`.
**Surface:** `customer.workspaces.businesses.analytics.*` — the Business-scoped
B5 page, named **Results** in the navigation since Slice 2A.

B5 was technically correct and read like an engineering dashboard. This pass
changes what the page says, in what order, and how readable its charts are.
It does **not** change what any figure means: every number on the page is the
number B5 already computed, and the tests prove it.

---

## 1. What a local Business sees first

Results answers the questions a local Business actually has, in the order the
product can honestly answer them:

```
Visibility → Leads → Conversations → Response → Customer outcome
```

Only the middle of that funnel has a canonical data source today, so only the
middle is shown. The overview is:

| Figure | Source | Meaning, as the page states it |
|---|---|---|
| **New contacts** | B5 `ContactKpis::$newInRange` | Contacts added during this period. |
| **New conversations** | Slice 2B `BusinessConversationReadModel::startedCount()` | Conversations started during this period. |
| **Messages received** | B5 `MessageKpis::$inbound` | Messages people sent to you. |

Then one chart: **New contacts**, "Contacts added during this period."

**Outgoing message volume is not in the overview.** It is operational health,
not a local-Business outcome, and a higher count is not a better result. It
lives in a secondary **Messages** section:

```
Messages
  Sent 32 · Failed 1 · Processing 0      Out of 33 outgoing messages.
  [Messages chart: Received, Sent]
```

No figure on the page is presented as a win because its raw count rose. There
is no period-over-period arrow, no success colour on volume, and no score.

### Why "New conversations", not "people who contacted you"

A `chat_boxes` row is created by an inbound message **and** by an outbound
two-way send. `startedCount()` counts both. The label says "started", which is
true either way; "people who contacted you" would claim a direction the data
does not record.

## 2. Vocabulary — plain words over unchanged figures

| On the page | B5 figure | Stated beside it |
|---|---|---|
| **Sent** | M4 `accepted` — `customer_status = 'Delivered' OR LIKE 'Delivered\|%'`, outgoing only | "Accepted by the messaging provider." (tooltip) and "It doesn't confirm the message reached the phone." (visible) |
| **Failed** | M5 `confirmedFailed` — Undelivered, Expired, Rejected, Failed, Skipped | a "What counts as failed?" disclosure, including that Skipped is not a downstream failure |
| **Processing** | M6 `unresolved()` | "Anything still waiting for a final answer." |
| "Out of N outgoing messages." | M1 `outbound` | — |

`accepted + confirmedFailed + unresolved === outbound` still holds by
construction and is re-asserted.

**Sent never means delivered.** The repository has no reliable handset-delivery
signal in the `reports` model B5 reads — the `Delivered` status is written at
send-time provider acceptance — so no Delivered figure is shown, and "Sent" is
never widened into one. If a genuine handset-delivery source is added later it
must appear as its own figure, not be folded into Sent.

**API sends** are a separate channel (`direction = 'api'`) and never part of
Sent. When a Business has any, one line says so: "Another N messages were sent
through your API and are counted separately." They are kept out of the headline
chart.

Retired from the customer page: *Provider-accepted rate*, *Message volume by
direction*, *Contact growth*, *Campaigns created*, *Outbound message outcomes*,
*Unresolved / in flight*, *handset delivery*, and the page title *Analytics*.
The campaign-performance detail page uses the same words (Messages, Sent,
Failed), so the two pages never contradict each other.

## 3. Dates and charts

### Range control

`Last 7 days · Last 30 days · Last 90 days · This month · Last month · Custom range`

The two calendar presets are new (`AnalyticsDateRange::CALENDAR_PRESETS`).
Both come from the Business's own calendar through Carbon's month arithmetic:
`This month` runs from the 1st to today; `Last month` is the whole previous
month, using `subMonthNoOverflow()` so 31 March never overflows back into March.
A month containing a DST change is still exactly its own dates.

Their cache key carries the resolved month (`this_month_2026-06`), so an entry
cached on the last day of one month can never be served as "this month" on the
first of the next. The rolling presets keep their bare key, which existing B5
and Dashboard cache keys depend on.

The caption reads **"Showing Last 30 days · Aug 13 – Sep 11"**. The Business
timezone is used internally throughout and stated once, in a focusable tooltip:
"Days follow Snap Booth Co's local time (America/New_York)." The main line no
longer says "30 local days in the America/New_York timezone".

### Chart axis

`AnalyticsChartBuckets` (new, pure, zero queries) turns B5's daily series into
a readable chart:

| Local dates in range | Grouping | Axis label | Tooltip |
|---|---|---|---|
| up to 14 | per day | `Mon 7` | `Mon, Sep 7, 2026` |
| up to 45 | per day | `Sep 5` | `Sat, Sep 5, 2026` |
| up to 120 | per 7 days from the start | `Jun 13` | `Jun 13 – 19, 2026` |
| longer | per calendar month | `Jun` | `Jun 1 – 30, 2026` |

The axis never shows a full `YYYY-MM-DD`; the tooltip always shows the exact
date or span. The monthly tier is not reachable through the range control today
(its longest window is 92 days) and exists so the rule stays correct if that cap
is raised.

**Labels are thinned, never clipped or overlapped.** ApexCharts' `trim` sizes
every label to one category's width, which rendered a 30-day axis as `Aug…`,
`.` and `Se…`; it is off. The number of labels is instead set from the chart's
own width — about one per 110 px — so a 375 px phone shows three dates
(`Aug 13 · Aug 23 · Sep 2`) and a full-width desktop chart ten
(`Aug 13 · Aug 16 … Sep 9`). Lines are straight rather than smoothed, because a
curve between two daily counts implies values that were never measured.

Both were found by rendering the real page, not by the test suite: a
server-rendered snapshot of an authenticated Results page, seeded with a
month of data, opened in a browser at 1280 px and 375 px, with label positions
measured for overlap and truncation.

It groups dates that are **already Business-local** — B5 bucketed them — so
grouping can never move a message into a neighbouring day, and it performs no
timezone arithmetic of its own. Values are summed, never re-derived: the
grouped series always totals exactly the daily series, at every granularity.

The `/series` JSON gains a `charts` key beside the unchanged `contact_growth`
and `message_volume` arrays. It is built from the cached daily series in PHP
and costs no query.

## 4. Sections appear only when they have something to say

| Section | Shown when |
|---|---|
| Overview figures | always — zeros included, because a zero is information |
| New contacts chart | there is any activity in the period; otherwise one calm empty state, "Nothing to show for this period yet" |
| Messages | the Business sent or received anything |
| Automations | automations actually ran in the period |
| Contacts (all / subscribed / unsubscribed / groups) | the Business has contacts |
| Campaigns | the Business has campaigns |
| AI Business Advisor | the Opportunity engine is enabled (unchanged) |

**Campaigns are supporting detail.** No *Campaigns created* figure anywhere.
SMS campaigns remain fully supported in Messages → Campaigns; the Results card
shows the status breakdown and links to Campaign performance only when
campaigns exist.

**Automations** use human outcomes over the B4 ledger — **Runs**, **Completed**,
**Failed** — with skipped ("skipped on purpose, not a failure") and pending
("waiting to run") explained on one quiet line instead of folded into a rate.
Trigger names come from `AutomationTriggerType::label()`; an unknown stored
value renders as "Other trigger", never as itself. "Contacts currently in
workflows" is **not** shown: it needs Automations V2's enrollment ledger, which
does not exist yet (§7).

## 5. What is deliberately not shown, and exactly what would change that

Nothing below is invented as a placeholder, a zero, or a "coming soon" card.

### Visibility — website visitors, Google views and actions, ranking

No source. `websites` records no visits. `business_google_locations` stores a
profile mirror, verification and open status, and **no** performance insight
(impressions, calls, direction requests, website clicks). Showing any of these
needs a stored, Business-scoped, dated performance feed — for Google, a synced
insights table populated from the provider; for the website, a visit/event
ledger.

### Leads, lead source, lead conversion, new customers

`contacts.status` is only `subscribe` / `unsubscribe`. There is no lifecycle
stage and no acquisition source, so "New contacts" stays "New contacts" — it is
**not** renamed "New leads". The upgrade needs, at minimum:

| Capability | Why |
|---|---|
| `contacts.lifecycle_stage` — a code-backed enum (for example `lead`, `customer`, `lost`), Business-scoped, indexed with `business_id` | "New leads" and "New customers" become counts of a real stage |
| An append-only `contact_lifecycle_transitions` ledger (`contact_id`, `business_id`, `from_stage`, `to_stage`, `occurred_at`, actor/source) | conversion is a transition *in the period*, which a current-state column cannot answer; B5 already refuses to derive trends from a snapshot for exactly this reason (see `ContactKpis`) |
| `contacts.acquisition_source` — a code-backed enum or FK to a source table, set once at creation | "Lead sources" |
| A write path that sets them (form, import, inbound, campaign, manual) | without writers the columns stay null and the figures are fiction |

With those, the overview cards re-label to New leads / New customers, a Lead
sources breakdown joins Results, and "Lead conversion" becomes transitions
`lead → customer` in the period.

### Response and conversion — first-response time, unanswered leads, bookings, form submissions

No canonical source. First-response time needs a per-conversation first-inbound
and first-reply timestamp pair; `chat_box_messages` could derive one, but no
contract defines the rule and it is not computed anywhere. "Unanswered" is not
`unreadCount()` — unread means the Business has not opened it, not that nobody
replied. `calendar` and `forms` are packaged into plans with no implementation
(parent redesign H-7). Revenue, ROI, reply rate, pipeline value and bookings
have no authoritative source at all.

### Email (reserved)

A clean section slot is marked in `overview.blade.php`. It renders **nothing**
until a canonical Business → contact email transport and event domain exists,
recording per message: `business_id`, contact, sent, delivered, opened,
clicked, unsubscribed and failed events with timestamps. Then Results gains an
**Email** section — Sent, Delivered, Opened, Clicked, Unsubscribed, Failed —
under the same rules as Messages: plain words, no win framing, secondary to
Business outcomes.

A test asserts that none of these — leads, sources, bookings, revenue, visibility,
email — appears on the page.

## 6. Agency

Results is Business-scoped. An Agency's client account is a local Business, so
it gets the local-Business Results above. **Agency outbound growth metrics —
prospects contacted, replies, positive replies, response rate, calls booked,
failed sends — never appear in a Business's Results.**

That separation already holds at the data layer: Agency prospecting persists
only to `agency_prospect_messages`, never to `reports`, and
`AnalyticsSeparationTest` forbids B5 from reading any `agency_prospect*` table.
Agency outreach reporting belongs at the Agency Account frame, where the Slice 4
Agency Account Home already shows a prospecting summary; a dedicated Agency
results view is a separate slice.

## 7. Ownership boundaries kept

**B5 never queries `chat_boxes`.** `AnalyticsSeparationTest` forbids it. The
New conversations figure is therefore composed in `AnalyticsController`, beside
the B5 overview, from Slice 2B's own read seam — the same arrangement the Slice 4
Dashboard uses. A first attempt composed it inside `BusinessAnalyticsPresenter`;
that test caught it, and it was moved.

**Automations V2 owns the automation read.** Its V2-H slice will extend
`BusinessAnalyticsQueries::automationKpis()` and `AutomationKpis` to union
`automation_step_runs`. Neither is touched here: the Automations section is
presentation only over the existing result, so V2-H lands without a conflict.

**One B5 query gained one column.** `messageVolumeSeries()` now also returns a
daily `accepted` series — same single statement, same grouping, the M4
predicate restricted to outgoing — so the chart line labelled "Sent" is the
same number the page calls Sent, rather than every outgoing attempt. The
`outgoing`, `incoming` and `api` series are computed exactly as before. This is
a different method from `automationKpis()`, so it does not collide with V2-H.

**A cached payload from the previous release is recomputed, not shown.**
`BusinessAnalyticsPresenter::PAYLOAD_VERSION` marks the cached shape; an entry
without it is rebuilt on read, so the five minutes after a deploy can never
render "Sent: 0" from a payload that predates the `accepted` series.

## 8. Query cost

| Read | Cold overview |
|---|---|
| B5 KPI reads | **≤ 7**, unchanged |
| B5 tenancy + KPI | **≤ 12**, unchanged |
| New conversations (Slice 2B) | **exactly 1** — one indexed `COUNT(*)` on `chat_boxes_business_id_created_at_index` |
| Total | **≤ 13** |

The only new read is the one for the one new figure. No read grows with the
number of contacts, messages, conversations or campaigns; the chart grouping is
PHP over already-fetched data.

### A pre-existing budget-accounting defect, corrected

On `f6cfd88`, before any change here, two B5 budget tests were already red:
`AnalyticsPerformanceTest::test_overview_issues_at_most_twelve_queries_including_the_tenancy_chain`
(13 > 12) and
`AnalyticsCampaignTest::test_pagination_is_25_per_page_with_disjoint_pages_and_two_aggregate_queries`
(10 > 9).

The extra statement in both is `select * from businesses where businesses.id = ? limit 1`,
issued by `CustomerShellComposer::currentMenuEntitlements()` →
`EntitlementManager::snapshotBusinessFeatureDecisions()` — Slice 2A's
menu-entitlement snapshot (PR #246), which re-reads the Business on every
Business-frame page. It is shared page chrome, already budgeted at six queries
by `MenuEntitlementsRequestSnapshotTest`, and the B5 tests predate it; their
`businesses` pattern counted it as B5 tenancy.

It cannot be excluded by SQL, because the tenancy chain's own
`WorkspaceManager::userCanAccessBusiness()` issues the character-identical
statement. `CreatesAnalyticsFixtures::analyticsOwnedSql()` excludes it **by
caller** — only statements issued from inside `snapshotBusinessFeatureDecisions()`
— and the two tests use it with their ceilings **unchanged** (12 and 9). A new
test proves, in one request, that every excluded statement is an
entitlement-snapshot read, that none is a B5 read, and that exactly one of them
touches a tenancy table.

## 9. Accessibility

The page owns one `<h1>` ("Results"). The shared title bar is switched off
(`pageConfigs => ['pageHeader' => false]`, the same arrangement as the customer
home) so its `<h2>` never precedes it — and both banners that bar used to carry,
the view-as banner and the admin/parent impersonation notice, are rendered by
the page itself. Every section is labelled by its own `<h2>`. Both helpers
(Sent, timezone) are focusable, carry an `aria-label`, and the Sent meaning is
also stated in visible text so it never depends on hovering. Charts carry a
`role="img"` description.

## 10. Verification

All runs on `ultimatesms_testing_results_ux` (260 migrations, 0 pending),
validated by `Tests\Support\TestDatabaseSafety`. Baseline on a pristine
`f6cfd88` worktree against `ultimatesms_testing_results_ux_base`.

| Suite | Pristine `f6cfd88` | This branch |
|---|---|---|
| `tests/Feature/Analytics` | 74 tests, **2 failures** | **98 tests, 0 failures** |
| `tests/Unit/Analytics` | 10 tests | **29 tests** |
| `tests/Feature/Dashboards` | 64 tests, 1199 assertions, 1 failure | identical |
| `tests/Feature/Security` | 290 tests, 2281 assertions, 1 failure | identical |
| `tests/Feature/Navigation` | 43 tests | identical |
| `tests/Feature/Conversations` | 5 tests | identical |
| `tests/Feature/Assets` | 18 tests | identical |
| `tests/Feature/DesignSystem` | 215 tests | identical |
| six further files that reference these routes | 2 failures | identical |

**No failure is introduced.** The two Analytics failures on pristine are the
budget-accounting defect of §8, fixed. Every other failure reproduces
identically on pristine and is outside this surface:

* `DashboardHeadlinesTest::test_conversations_are_counted_by_the_slice_2b_seam_with_the_ranges_bounds_unconverted`
  hard-codes a current window of 12 Aug – 10 Sep without freezing the clock. It
  passed on 10 Sep and fails from 11 Sep, when the windows slide a day. A
  `$this->travelTo('2026-09-10 12:00:00')` fixes it.
* `OutreachSecurityTest::test_legacy_template_store_forces_authenticated_user_id_regardless_of_input`
* `CustomerShellTranslationTest::test_every_navigation_label_has_an_english_translation`
  (expects "Google Business Profile", renamed "Get found" by Slice 2A)
* `CustomerContextResolutionTest::test_back_links_and_slot_pages_use_account_vocabulary_not_workspace`
  (500 on a slot page)

Each new guard was also proven to fail when the thing it guards breaks: "Sent"
showing every outgoing attempt, an ISO axis, the timezone in the visible
caption, and outgoing volume in the overview each turn the matching test red.

## 11. Follow-ups outside this slice

1. **Dashboard vocabulary.** The Slice 4 Dashboard labels M1 (every outgoing
   attempt) as "Messages sent" and shows "Provider accepted" as a rate. Results
   now uses "Sent" for M4. The same word should not mean two numbers on two
   pages; the Dashboard should adopt Sent/Failed or rename its attempt figure.
2. **Dashboard banners.** The customer home switches the title bar off and
   re-renders the view-as banner, but not `auth.loggedAs`, so the admin/parent
   impersonation notice does not appear there. Results renders both.
3. **`DashboardHeadlinesTest` clock dependency** — see §10.
