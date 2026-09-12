# Unified Business Home and COO Decision Engine — Contract

| | |
|---|---|
| **Status** | **Contract only.** Authorizes no product code. Each slice in §17 needs its own implementation authorization that cites this document. |
| **Base** | `origin/main` at `4e1445f14a5e4ba9ddd79854cfc5de926f56e315` (PR #254), 2026-09-12 |
| **Owner decisions** | All six were answered by the owner on 2026-09-12 in PR #255 and are recorded in **§0.3**. **No owner decision remains open.** §21 holds the launch gates those answers created, which are execution steps, not further decisions |
| **Workflow** | Route 3 manual lane (`AGENTS.md`) |
| **Owns** | The Business Home layout; the Agency Account Home distinction; folding Results into Home; the deterministic COO pipeline; the architecture for AI escalation, caching, the AI budget ledger and model routing |
| **Amends** | Parent redesign `AI-BUSINESS-OS-CUSTOMER-EXPERIENCE-AND-NAVIGATION-REDESIGN.md` §13.1 question 5 and the §13.2 "Spend" band; the Spend band in `CUSTOMER-EXPERIENCE-REDESIGN-SLICE-4-DASHBOARD.md`. Nothing else. |
| **Does not amend** | RFC-002 (Opportunity Engine), RFC-005 (usage billing and wallets), B5 Analytics, Slice 2B Conversations, and the managed-messaging contract's §20 cost invariants |
| **Requires one amendment elsewhere** | RFC-004 gains the canonical trial state the owner approved in D-1 (`trialing` plus `trial_ends_at`, §11.1a). This contract specifies only what the AI budget depends on; the amendment itself is written and reviewed with slice T-1 |

Throughout this document, **canonical** means "read from a persisted source that
this repository writes today, with a stated meaning". A figure without a
canonical source is **absent** from the page. It is never shown as zero, never
estimated, and never shown as a "coming soon" placeholder.

---

## 0. Locked decisions and the conflict check

### 0.1 Owner decisions this contract implements (locked)

| # | Decision |
|---|---|
| L-1 | An ordinary Core or Growth Business has **one Home**. Home and Results do not both survive long term. |
| L-2 | Home = facts + performance + recent change + **one** highest-value next move. COO = the intelligence that decides and ranks what happens next. |
| L-3 | A metric is shown only once a canonical data source exists. No bookings, Google metrics, SEO rankings, newsletter metrics, form metrics or attribution may be invented. |
| L-4 | Contacts are not renamed to Leads until a real Lead lifecycle and source model exists. |
| L-5 | Billing lives in Settings. The Spend and billing band leaves the ordinary Business Home. Home may show **only a billing exception**. Raw spend is never a success KPI. |
| L-6 | Core/Growth KPI priority: Visibility → Leads → Incoming conversations → Response → Conversion/booking (once canonical). Outbound SMS volume is secondary, and "more messages sent" is never a win. |
| L-7 | The Agency account has its own portfolio Home. A Business opened by an Agency uses the same unified Business Home. |
| L-8 | The COO is deterministic first. AI is **never called on a Home load**. Obvious actions never call an LLM. AI is an escalation layer, used only for real multi-signal reasoning. |
| L-9 | The Opportunity Engine is reused. Its deterministic scoring and evidence stay authoritative and are never replaced by an opaque AI priority score. No second recommendation or work-queue domain may be created unless this contract proves Opportunity cannot serve the need. |
| L-10 | AI output is persisted with Business identity, a fingerprint of the facts and signals it used, a timestamp, model and category provenance, and an invalidation rule. It is regenerated only on a material signal change, staleness, relevant work completing or failing, a scheduled deeper review, or an explicit customer request. |
| L-11 | Internal AI provider-cost budgets (never customer wallets): trial **$1.50 total** over 28 days; Core **$5/month**; Growth **$10/month**; Agency **$25/month** hard cap across the Workspace plus at most **$6/month per Business**, whichever limit is reached first. Amounts come from configuration or catalog policy. |
| L-12 | Customers never see tokens, provider prices, dollar AI costs or alarming meters. Settings → Billing → AI usage shows "Included AI usage — Normal", a simple approximate warning near the limit, and the trial line "Your trial includes a smaller AI allowance." When the budget runs out, deterministic Home, Results and COO keep working, automations keep running unless they need new AI themselves, cached AI stays visible, and the product does not stop. |
| L-13 | Budget protection covers: a Workspace monthly hard cap; a Business sub-budget; attribution by feature and category; a protected interactive allowance (about 70% product and 30% interactive, not irreversibly locked); per-request limits on cost, context and output; context compaction; model routing; a provider-cost ledger; caching and reuse; a trial allowance. |
| L-14 | AI claims are labelled KNOWN, LIKELY or UNKNOWN. A sequence of events is never presented as cause and effect. |

### 0.2 Conflict check — result: **no mechanical conflict**

Reconnaissance checked each locked decision against the code and the merged
contracts. Nothing in the repository forces a policy change. There are three
missing primitives and one deliberate owner supersession, and each is recorded
here rather than silently absorbed.

| # | What was checked | Finding | Verdict |
|---|---|---|---|
| K-1 | Spend band | Parent §13.1 question 5 ("What is costing money?"), parent §13.2 "Spend", and Slice 4 all ship a Spend band for the payer only (`app/Library/Dashboard/BusinessHomePresenter.php:170–186`, `resources/views/customer/dashboard/bands/spend.blade.php`). | **An owner supersession, not a conflict.** No code or contract depends on the band. All billing detail remains in Settings → Billing. §5 records the change. |
| K-2 | AI charging vs. wallets | Managed-messaging contract §11.5: a **separately authorized** AI generation charge is reserved and debited regardless of transport. §20 C-5: "AI allowances use their own meters and are never funded from the telecom balance". C-6: Agency Businesses do not multiply free AI allowance. C-9: provider cost is shown only to admins. | **Compatible.** Included AI is not a separately authorized charge, so it never touches the wallet. It uses its own meter (§10). A future paid AI overage would be a new, separately authorized RFC-005 meter and is out of scope here. |
| K-3 | RFC-002 and AI | RFC-002 treats worker and AI output as untrusted input and validates it the same way. AI may supply only facts and template parameters. Priority is never produced by AI. Titles and summaries come from the registry. | **Identical to L-9.** §7 and §8 keep it. |
| K-4 | Trial state | RFC-004 has no trial. `WorkspacePlanAssignmentStatus` is `active \| inactive \| suspended`. No migration has a trial column. "Trial" appears only in comments in the legacy `app/Models/Subscription.php`. | **A missing primitive, not a conflict — and the owner has approved adding it (D-1).** §11.1a specifies the canonical state, `status = trialing` with `trial_ends_at`, which slice T-1 adds. Trial is never inferred from account age or any other field, and until T-1 lands no Workspace is on trial, so no customer's allowance changes silently. |
| K-5 | Opportunity producer trigger | No production code dispatches `App\Jobs\Opportunity\RunBusinessAdvisorOpportunityProducer`. The deployment guide dispatches it from `tinker`. `config('opportunity.enabled')` defaults to `false`. | **A gap, not a conflict.** Slice C-1 adds the trigger and must prove it is safe before the engine is enabled through the existing config boundary (D-3). No production behaviour may depend on a manual or `tinker` dispatch. |
| K-6 | `ai_coo_basic` | `PlatformFeature::AiCooBasic` is `Planned` (`app/Library/Entitlement/PlatformFeatureRegistry.php:65`), yet the seed migration `2026_08_13_120007` packages it into core, growth and agency. | **Compatible.** Slice AI-3 changes it to `Available`. The deterministic COO does not need it. |
| K-7 | The "Leads & conversations" title vs L-4 | There is no Lead model. | **Resolved within the locked set.** The band is titled **"Conversations"** until a Lead model exists (§2.6). |

### 0.3 Owner decisions, answered 2026-09-12 (PR #255)

These are now locked direction, with the same force as §0.1. They are no longer
open questions, and no slice may reopen one without a further owner decision.

| # | Decision, as given | Where it binds |
|---|---|---|
| **D-1 Trial** | **Approved.** Add a canonical trial state to the plan assignment: status `trialing` plus `trial_ends_at`. **Trial is never inferred from account age or any other field.** The 28-day trial AI allowance is a **$1.50 provider-cost hard cap**. | §11.1a (the state), §11.1 (the policy), slice T-1, K-4 |
| **D-2 One shared AI cap** | **Yes.** *All* first-party AI counts against the same included Workspace AI budget: COO, website generation, SEO reasoning, prospecting and reply assistance, future newsletter/email AI, and interactive COO conversation. Every call is also attributed by category. Agency keeps **$25** Workspace-wide per month **and $6 per Business**, whichever is reached first. A protected system/product share must remain, so chat spam cannot consume all useful product AI. | §10.2 (categories), §10.3, §10.4 (the protected share), §11.1 |
| **D-3 Opportunity Engine** | **Yes, but not enabled merely because this contract merges.** C-1 must first implement and prove **safe automatic** producer triggering; only then is the engine enabled through the existing rollout/config boundary. **No production behaviour may depend on a manual or `tinker` dispatch.** | §7.3, slice C-1 and its exit criteria, §19.6, T-C1-1…4 |
| **D-4 Models** | **Do not lock concrete provider model names into this contract.** Three configurable routing classes are locked instead: `routine`, `reasoning`, `compaction`. AI-1 chooses the concrete models from a current cost and quality evaluation, and changing one later must need no domain or schema change. `routine` and `compaction` take the cheapest model that passes the quality threshold; `reasoning` takes the stronger model **only when deterministic rules escalate the case**. | §13 |
| **D-5 "Ask your COO" chat** | **Deferred.** Do not build interactive COO chat yet. Ship the deterministic chain first: facts → deterministic metrics → Opportunity and rules → next best move → "Why this?". Add a conversational COO later only if it clearly adds value. | §12 (kept as binding design for any future surface), slice AI-4 (deferred, unscheduled) |
| **D-6 Enforcement** | New COO AI is **hard-budgeted from its very first production call**. AI-1 must route the existing AI call sites through the same gateway and ledger **before broader product launch**, with **no uncapped side doors around the gateway**. Hard enforcement must exist **before** 28-day trials launch. The Results redirect and removal happen **only after** Home reaches Results feature and data parity and H-6 proves the migration and redirect behaviour. | §19.3, §10.1, §4.1, slices AI-1, T-1, H-6, §21 |

---

## 1. Current architecture and reusable seams

### 1.1 The Business Home today (Slice 4, merged in #248)

`App\Library\Dashboard\BusinessHomePresenter::present()` builds a
`DashboardSnapshot` from these bands, in this order:

1. **Attention.** `DashboardStatusReader::forBusiness()` loads the wallet, website, Google connection and unhealthy-location status in one statement. It adds `AttentionType::AutomationFailing` from the B5 comparison. An item appears only when `remediationUrl()` resolves for the actor.
2. **Recommended next steps.** Shown only when `config('opportunity.enabled')`. It lists open, current opportunities from `OpportunityRepository`, at most 5.
3. **Headlines.** Requires `view_reports`. Figures come from `App\Library\Analytics\BusinessDashboardAnalyticsPresenter::comparison()`, which runs the fixed last 30 days against the previous 30 using three B5 queries per period. They include outbound "Message activity", provider-accepted, confirmed failed, contacts, conversations from the Slice 2B seam, and automations.
4. **Spend.** Payer only, via `actorManagesPayerControls()`.
5. **Quick actions.**

Each band is isolated by `try/catch` into `failedBands`. The product-data
query budget is ≤ 18: dashboard ≤ 10, Analytics ≤ 6, Conversations ≤ 2
(Slice 4 §18 #25 and #46).

### 1.2 Seams this contract reuses

| Seam | Location | What it provides | Reuse |
|---|---|---|---|
| Home presenters and snapshot | `app/Library/Dashboard/{BusinessHomePresenter,AccountHomePresenter,DashboardSnapshot,DashboardLinkGate}.php` | Frame-driven bands, failure isolation, link gating | **Extend.** This remains the one Home. |
| Status reader | `app/Library/Dashboard/DashboardStatusReader.php` | One statement: wallet status, `websites.status`, `business_google_connections.state`, unhealthy Google location count | **Reuse as-is.** Supplies the Visibility band, billing exceptions and status rules at no extra query cost. |
| Attention vocabulary | `app/Enums/Dashboard/AttentionType.php` (9 cases, severity, sentence, action label) | Status-derived exceptions that clear themselves | **Reuse and extend** (§7.2). |
| B5 queries | `app/Library/Analytics/BusinessAnalyticsQueries.php` | Message, contact, campaign, advisor and automation KPIs and series | **Reuse.** One additive instant-window method (§2.3). |
| B5 date ranges | `app/Library/Analytics/AnalyticsDateRange.php` | Half-open, DST-safe windows in the Business's local time | **Reuse.** The calendar presets come from the Results redesign branch (§4.4). |
| Period comparison | `app/Library/Analytics/BusinessDashboardAnalyticsPresenter.php`, `app/Library/Dashboard/HeadlineComparison.php`, `app/Enums/Dashboard/{HeadlineTrend,HeadlinePolarity}.php` | Current vs previous period with a stable cache key | **Generalize** from a fixed 30 days to the selected period (Slice H-3). |
| Conversations read model | `app/Library/Conversations/BusinessConversationReadModel.php` (`startedCount(Business, startUtc, endUtc)`, `unreadCount`) | The only permitted reader of `chat_boxes` outside Conversations | **Extend** with three counts (§2.6). B5 must still never query `chat_boxes` (`AnalyticsSeparationTest`). |
| Opportunity Engine | `app/Library/Opportunity/**`, the `opportunities` / `opportunity_runs` / `opportunity_transitions` tables, `OpportunityScorer`, `OpportunityTypeRegistry`, `OpportunityProducer`, 16 domain events in `app/Events/Opportunity/` | Deterministic candidates, evidence, scoring, lifecycle, work-queue ordering | **Reuse as the COO's improvement backbone** (§7). |
| Business Advisor producer | `app/Library/Opportunity/BusinessAdvisorOpportunityProducer.php`, 11 profile-gap types | Deterministic "missing profile fact" moves | **Reuse.** Add a trigger (C-1). |
| Domain events | `app/Events/{Business,Website,GoogleBusinessProfile,Opportunity,Usage,Entitlement}/**` | After-commit signals | **Reuse** as invalidation and trigger signals (§9, C-1). |
| Activity sources | `automation_executions` (business_id, created_at), `business_google_operations` (business_id, created_at), `business_knowledge_profile_changes` (business_id, created_at), `website_revisions` (a row is written only on publish, `WebsitePublisher::publish()`), `opportunity_transitions` | Persisted, append-only or status ledgers | **Reuse** for Recent work (§14). No new activity table is needed. |
| Agency prospecting data | `agency_prospects` (status, booked_at), `agency_prospect_messages` (direction, status, sent_at, received_at), `agency_prospect_campaigns` | Outreach facts | **Reuse** for the Agency Home (§3). |
| AI credentials and toggle | `config('services.openai.*')`, admin `SettingsController::postAiSettings` / `OpenAISettingsRequest`, env `OPENAI_ACTIVE` | The one credential store and the platform kill switch | **Reuse.** Never create a second credential store. |
| AI call sites | `app/Library/AgencyProspecting/OpenAiAgencyProspectingClient.php` (behind `Contracts\AgencyProspectingAiClient`, bound at `AppServiceProvider:218`, with a `FakeAgencyProspectingAiClient`); `app/Library/Website/WebsiteAiGenerationClient.php` (concrete); `CampaignController::generateAIMessage()` (`:2944`, inline) | Three thin `complete(array $messages): ?string` calls on one model, `services.openai.model` (default `gpt-4o`) | **Wrap** behind the AI gateway (Slice AI-1). All three currently discard usage and set no maximum on output tokens. |
| Entitlement snapshot | `EntitlementManager::snapshotBusinessFeatureDecisions()` → `MenuEntitlements` | Per-request feature decisions (≤ 6 queries) | **Reuse.** `ai_coo_basic` gates AI escalation only. |
| Test guards | `Http::preventStrayRequests()` in `tests/TestCase.php`, `CreatesAnalyticsFixtures::capturedSql()` / `analyticsOwnedSql()` | No accidental HTTP; query budget counting | **Reuse.** |

### 1.3 What does not exist, so Home must not show it

| Missing | Evidence | Consequence |
|---|---|---|
| Website visitors or page views | No visitor, page-view, impression or insight table or column in any migration | No "Visitors" tile |
| Google views, calls, direction requests | Google tables hold connection state, a profile mirror and operations only | No "Google views" tile |
| SEO rankings or issues | No SEO table and no SEO code. `OpportunityWorkerKey::Seo` is reserved and has no producer | No SEO tile and no SEO move |
| Bookings or appointments | No such table | No "Bookings" tile |
| Leads, lead sources, forms, attribution | No such table. Contacts have no lifecycle or source model | Contacts stay **Contacts** |
| Email or newsletter metrics | No such table | Absent |
| Persisted "positive reply" classification | `AgencyProspectAiDecision::INTENTS` includes `positive`, but no column stores the intent | No "Positive replies" on the Agency Home until it is stored (Slice A-2) |
| A "last visit" marker | `users.last_access_at` is written only at login (`EloquentAccountRepository.php:296`) and on a portal switch (`AccountController::switchView()`, `:222`/`:259`), per user, not per Business. `CustomerContextPreference` is session-only. `SESSION_DRIVER` defaults to `file` | A new marker is needed (§2.3) |
| An "awaiting reply" count | `BusinessConversationReadModel` has no such method. `chat_boxes.notification` counts **unread** messages, not **unanswered** ones | A new 2B method is needed (§2.6) |
| An AI usage or cost ledger | No table. No call site captures usage | A new ledger is needed (§10) |
| A trial state | K-4 | Approved (D-1). Slice T-1 adds `trialing` and `trial_ends_at` (§11.1a) |
| An automatic producer trigger | K-5 | Slice C-1 |
| A customer AI chat | Nothing in the repository | **Deferred by the owner (D-5).** The interactive lane and its protected share still exist (§10.4, D-2); §12 binds any future surface, and none is scheduled |

### 1.4 Do not reuse

| Artifact | Why |
|---|---|
| `cg_ai_conversations` / `cg_ai_messages` (written from `app/Models/ChatBoxMessage.php:34–48`) | No migration creates them. They are keyed by **phone only**, with no Business or Workspace, which is a cross-tenant hazard. The `created` hook checks `direction === 'inbound'` while the column's values are `incoming` and `outgoing` (`2025_05_29_134253`), so it never fires. They must never become COO or conversation memory. Retiring them belongs to a separate contract. |
| `users.last_access_at` as "last visit" | It records login and portal-switch time, per user and across all Businesses. Refreshing an open session never moves it. A value can be days old while the user is active. |
| The RFC-005 wallet ledger or `usage_meters` as the AI cost ledger | They record **retail money funded by the customer** against an active rate and a payer. Putting included provider cost there would present included AI as a charge. That breaks the managed-messaging terminology rule ("The wallet is always funded by the customer … never by the platform") and C-9. |
| `chat_boxes.ai_replied` / `ai_stage` / `ai_box_campaign_map` | Legacy AI prospecting state, on the B5 stop-list (`LEGACY-MESSAGING-SCHEMA-COMPLETION.md`) |

---

## 2. Target unified Business Home

### 2.1 Layout

```
┌─ Billing exception strip (only when one exists and the actor can act) ────┐
│  Messaging balance exhausted — automated replies may stop.  [Review]       │
└────────────────────────────────────────────────────────────────────────────┘
 Since your last visit (Tue 9 Sep)
   3 new contacts · 2 new conversations · 1 automation failed · Website published

 Your next best move
   Reply to 2 customers waiting for an answer          [Reply]  [Why this?]

 Business performance                                   [ This month ▼ ]
   New contacts 41 (↑ 9)   New conversations 18 (↓ 2)   Messages received 63
   [ chart: new contacts per day / week / month ]
   What we notice (AI, labelled, only when cached) ·········· optional line

 Visibility            Website  Published  ·  Google  Connected  ·  (SEO: absent)
 Conversations         Incoming 18  ·  Replied 15  ·  Awaiting reply 2
 Automations           Completed 212  ·  Failed 3 [Review]
 Recent work           (last 10 real events)
```

The order is fixed. A band with nothing true to say is **omitted**, except
that "Your next best move" is always shown (§2.4). Failure isolation keeps
Slice 4's per-band `try/catch` → `band-failed` pattern.

### 2.2 Band table

| # | Band | Canonical source (owner) | Audience / gate | Absent when |
|---|---|---|---|---|
| 0 | **Billing exception strip** | `AttentionType` billing cases from `DashboardStatusReader` (Dashboard) | The actor for whom Slice 4's `remediationUrl()` resolves | There is no billing exception |
| 1 | **Since your last visit** | Marker (§2.3) plus instant-window counts from the owning seams | Any actor who may see the Business Home | This is the first visit, or nothing canonical changed ("Nothing new since your last visit on {date}." shows instead, when a marker exists) |
| 2 | **Your next best move** | `NextBestMoveSelector` (§6.4) over non-billing Attention and the Opportunity work queue | Everyone. The action link goes through `DashboardLinkGate` | Never. When no move exists: "You're all caught up." |
| 3 | **Business performance** | B5 comparison for the selected period; chart through the existing async series endpoint | `view_reports` | Band hidden without `view_reports` |
| 4 | **Visibility** | `DashboardStatusReader` row (0 extra queries) | Website tile needs the website entitlement; Google tile needs the Google entitlement | Both tiles hidden. The SEO tile is never rendered until an SEO domain exists |
| 5 | **Conversations** | `BusinessConversationReadModel` (2B) | The Conversations permission Slice 2B enforces | No permission |
| 6 | **Automations** | B5 `automationKpis()` (owned by V2-H, unchanged) | `automations` entitlement and gate | No runs in the period and none failing |
| 7 | **Recent work** | `RecentWorkReader` (§14) | Each item is individually link-gated; an item whose destination the actor cannot open shows as plain text | No events |

**KPI priority (L-6)** decides the tile order within bands and the order of the
"Since your last visit" line: visibility facts → contacts (in the place Leads
will take) → incoming conversations → response → conversion (absent). Outbound
volume, provider-accepted and failed-send figures leave Home completely. They
remain in Results and Messages as secondary operational detail, and no tile ever
treats "more messages sent" as a win.

### 2.3 Since your last visit — deterministic, no AI

**The marker.** A new table, `business_home_visits`:

| Column | Type | Meaning |
|---|---|---|
| `id` | bigint | |
| `user_id` | FK users, cascade | The authenticated actor |
| `business_id` | FK businesses, cascade | The Business whose Home was viewed |
| `window_start_at` | timestamp, nullable | End of the **previous** visit. The band's lower bound |
| `current_visit_started_at` | timestamp | Start of the current visit |
| `current_visit_last_seen_at` | timestamp | Latest Home view in the current visit |
| `timestamps` | | |
| unique | `(user_id, business_id)` | |

**Visit rule** (clock `t`, gap `G = config('home.visit_gap_minutes', 30)`):

1. **No row:** insert `(window_start_at = null, current_visit_started_at = t, current_visit_last_seen_at = t)`. The band is absent on the first visit.
2. **`t − current_visit_last_seen_at < G` (same visit):** the window stays `[window_start_at, t)`. Refreshing does not reset it. Write `current_visit_last_seen_at = t` only if at least 60 s have passed since the last write.
3. **Otherwise (a new visit):** set `window_start_at = current_visit_last_seen_at`, then `current_visit_started_at = current_visit_last_seen_at = t`.
4. **Cap.** If `t − window_start_at > 30 days`, the window becomes the last 30 days and the heading says so: "In the last 30 days". A figure is never labelled "since your last visit" when it covers something else.
5. The window is read **before** the write, so one request never computes its window from its own write. The write is a single conditional upsert. Two tabs racing produce the same window.

**Why this avoids misleading counts:** the marker is per user and per
Business, so another team member's visit never resets yours. It is stored in
the database rather than the session or a cookie, so phone and laptop share
one marker. A **view-as** session and admin impersonation (`auth.loggedAs`)
**never write** it, so looking at a client never consumes that client's
"since your last visit".

**Content** (canonical, instant window `[window_start_at, t)`). Only non-zero
items appear, at most 5, in KPI-priority order:

| Item | Source (owner) |
|---|---|
| Website published | `website_revisions.created_at` in the window (Website, read through `RecentWorkReader`) |
| Google connected or disconnected | `business_google_operations` `connect_completed` / `disconnected` in the window |
| N new contacts | B5, new `BusinessAnalyticsQueries::countsBetween(Business, startUtc, endUtc)` (one statement, with incoming messages below) |
| N new conversations | 2B `startedCount()` (exists; takes instants) |
| N messages received | The same B5 `countsBetween()` statement |
| N automations completed / N failed | A new V2-H-owned instant-window method beside `automationKpis()`. It covers `automation_executions` today and also `automation_step_runs` once V2-H lands (Automations V2 §15.4). Home never reads either table directly |
| N recommendations done | `opportunity_transitions` to `completed` in the window (Opportunity repository) |

Visitors, rankings and bookings do not appear (L-3). No AI is involved at any
point.

### 2.4 Your next best move

Exactly **one** move, picked by `NextBestMoveSelector` (§6.4). The two buttons:

- **Primary action** — the move's own verb (`AttentionType::actionLabel()` or the Opportunity action label), linked through `DashboardLinkGate`. Opportunity moves link to the existing customer `opportunities.show` page, where the existing configure, approve and execute flow runs unchanged.
- **Why this?** — a deterministic disclosure with no AI (§6.5).

A secondary link, "See all recommendations (N)", goes to the existing
`customer.opportunities.index`. It replaces Slice 4's "Recommended next
steps" list of up to 5.

### 2.5 Business performance

- **Period selector:** This month (default), Last month, Last 7 / 30 / 90 days, and a custom range of up to 92 days. These are the same presets and validation as Results (§4.4).
- **Tiles**, in L-6 order and canonical only: **New contacts**, **New conversations** (2B), **Messages received**. Each is compared with the previous equal-length period using `HeadlineComparison`, and `HeadlinePolarity` stays descriptive: a rise is never styled as a win unless the metric's own polarity says so.
- **Chart:** new contacts per bucket, from the existing async B5 series endpoint with `AnalyticsChartBuckets` grouping. The Home request itself loads no series.
- **"What we notice":** one optional line, rendered **only** from a displayable cached `coo_insights` row (§9). It is labelled "AI summary", carries KNOWN/LIKELY/UNKNOWN wording, and shows its "Updated {date}".
- **Future tiles:** Visitors, Google views and Bookings join the tile row only when their canonical sources exist, each under its own contract.

### 2.6 Visibility, Conversations, Automations

- **Visibility.** Website: Published / Draft / Not created, from `websites.status`. Google: Connected / Connection lost / Not connected, plus "N listings need attention" from `google_state` and `unhealthy_google_locations`. Each tile links to its settings. **No metric appears in this band until one is canonical.**
- **Conversations** (titled "Conversations" until a Lead model exists, K-7). Three new 2B methods, each a single statement scoped by `chat_boxes.business_id`:
  - `incomingCount(Business, startUtc, endUtc)`: conversations with at least one `incoming` `chat_box_messages` row in the window.
  - `repliedCount(Business, startUtc, endUtc)`: of those, conversations where an `outgoing` message followed the first `incoming` message in the window. A manual reply and an automated reply both count, and the tooltip says so.
  - `awaitingReplyCount(Business)`: current state, not tied to the period. Conversations whose latest message is `incoming` and older than `config('conversations.awaiting_reply_grace_minutes', 5)`. The scan is limited to boxes with `updated_at` in the last 30 days, using the existing `chat_boxes.updated_at` index and the per-box `MAX(id)` over the `box_id` index. `EXPLAIN` proof is required. If it fails, the slice adds `chat_box_messages(box_id, id)`.
- **Automations.** Completed and Failed come from B5 `automationKpis()` for the selected period. The "Review" action on Failed follows the same remediation route as `AttentionType::AutomationFailing`.

---

## 3. The Agency Account Home vs. a Business opened by an Agency

### 3.1 The Agency Account Home (portfolio)

This stays `AccountHomePresenter::agency()`, a separate presenter with its own
bands. Target bands:

| Band | Source | Status |
|---|---|---|
| **Businesses needing attention** | Existing `clients()` flags from `DashboardStatusReader::forBusinesses()`, ranked by severity | Exists. Unchanged |
| **Client health** | The same rows: Active/Not active, flags, switch control | Exists |
| **Cross-client performance** | New: per client, new contacts and new conversations for the period, as grouped aggregates over the client ids. At most 2 queries (B5 one grouped statement; 2B one grouped statement). Never a per-client query loop | New (A-1) |
| **Outreach** | `agency_prospect_*`: **prospects contacted** (distinct campaign members with an outbound message `sent_at` in the period), **replies** (inbound `received_at` in the period), **booked calls** (`agency_prospects.booked_at` in the period), **failures** (outbound `status = failed`). **Positive replies are absent** until the intent is stored (A-2) | Partly new (A-1). Extends the existing `prospecting()` band |
| **Capacity** | Existing `capacity()` | Shown **only when an action is needed** (all slots used, plan unassigned, inactive or suspended). Otherwise it moves to Settings → Account |
| **Account billing** | Existing account health | Shown **only for an actionable billing problem** (L-5) |

The Agency Account Home never calls AI in v1. A cross-client AI summary would
send several clients' facts in one prompt, which §15 prohibits.

### 3.2 A Business opened by an Agency

This is the **same** `BusinessHomePresenter` output as §2, under the existing
"Client account home" frame label, with the persistent "← All client accounts"
control from parent §13.4. No Agency outbound KPI ever appears on a Business
Home. Billing exceptions on a client Business follow Slice 4's payer and
remediation rules unchanged.

---

## 4. Results: fold into Home, then retire it

### 4.1 Phases

| Phase | Behaviour | Gate |
|---|---|---|
| **R0 (now)** | Results (`customer.workspaces.businesses.analytics.*`, entry `customer.analytics.entry`) is unchanged. The Home Performance band's "See details" links to it. | — |
| **R1 (parity)** | Home reaches every parity item in §4.2, with a test for each. | H-3, H-4 merged |
| **R2 (redirect)** | With `config('results.redirect_to_home') = true`, `…analytics.overview` returns **302** to `user.home`, preserving `range`/`start`/`end` as the Home period. The navigation item "Results" is removed in the same change. The series JSON endpoint and the campaigns detail page stay at their URLs. Each redirect is logged with the route name only. | Owner switches the flag after R1 is verified |
| **R3 (retire view)** | After one release with the redirect on, delete the overview Blade view. The route stays as a permanent **301** so bookmarks keep working. The campaigns detail page becomes "Messages → Campaign results", with its URL kept or redirected. | One release of R2 without a parity defect |

Routes are never removed while bookmarks may still point at them. No route is
deleted before R3, and the overview route is never deleted.

**D-6 gate.** The redirect is switched on **only after** Home reaches Results
feature and data parity (every P-1…P-10 item asserted) **and** H-6 has proved
the migration and redirect behaviour, including the range mapping and the
surviving endpoints. Parity is demonstrated by tests, not by judgement.

### 4.2 Parity checklist

| # | Results capability | Where it lives after the fold |
|---|---|---|
| P-1 | Range presets and custom range (≤ 92 days), Business-timezone caption | Performance period selector |
| P-2 | New contacts · New conversations · Messages received | Performance tiles |
| P-3 | New contacts chart with day/week/month bucketing | Performance chart |
| P-4 | Messages: Sent (M4, never "delivered"), Failed, Processing, "What counts as failed?" | **Messages** page, a secondary "Message outcomes" panel. Not Home (L-6) |
| P-5 | Automations Runs/Completed/Failed with trigger labels | Automations band, with runs detail on the Automations page |
| P-6 | Contacts panel | Performance "See details" drawer, or the Contacts page |
| P-7 | Campaigns table | Campaign results page (R3) |
| P-8 | Advisor panel | Replaced by Next best move plus "See all recommendations" |
| P-9 | API-message note | Message outcomes panel |
| P-10 | Tenancy: a foreign Business returns 404, never a figure | Same guards on Home |

### 4.3 What does not change

Every B5 formula, cache key, `PAYLOAD_VERSION`, and the separation rule that
B5 never reads `chat_boxes`.

### 4.4 Dependency on the Results redesign branch

The calendar presets (`PRESET_THIS_MONTH`, `PRESET_LAST_MONTH`,
`SELECTABLE_PRESETS`), `AnalyticsChartBuckets` and the `accepted` series
column come from the Results redesign branch
`agent/results-analytics-customer-ux-redesign` at `1fab6fa`, which **is not
merged yet**. Slice H-3 depends on that merge. If the branch is abandoned, H-3
must carry the same pieces with the same tests.

---

## 5. Billing off the ordinary Business Home

1. **Remove** `DashboardSnapshot::BAND_SPEND` from the Business kind (Core/Growth and Agency-opened), delete `bands/spend.blade.php`, and drop the funding quick action ("Add funds") from Business Home quick actions.
2. **Keep** the billing `AttentionType` cases (`WalletSuspended`, `PaidActivityPaused`, `OutstandingDebt`, `LowBalance`, `AutoRechargeFailing`) and show them **only** as the exception strip (§2.2 row 0), under Slice 4's audience rules. They never enter the next-best-move pool, so one problem never appears twice.
3. **Strip copy** is the type's own `sentence()` with a consequence clause, for example `PaidActivityPaused`: "Messaging balance exhausted — automated replies may stop." The copy change belongs to Slice H-1's allowlist.
4. **Settings → Billing** keeps the balance, spend, top-ups, invoices and the new AI usage state (§11.3). Nothing billing-related is lost. It moves.
5. Spend is never a Performance tile, never a comparison, and never a "since your last visit" item.

---

## 6. The deterministic COO pipeline

```
S0 canonical data & events ─► S1 deterministic metrics ─► S2 comparisons / trends / anomalies
        │                                                        │
        ▼                                                        ▼
S3a status rules → Attention (render-time)        S3b improvement rules → Opportunity producers
        └───────────────────────┬────────────────────────────────┘
                                ▼
                 S4 NextBestMoveSelector → one move + "Why this?"
                                │
                  (only if §8 eligibility holds, off-request)
                                ▼
                 S5 AI escalation → coo_insights (cached, labelled)
```

### 6.1 S0 — sources

Only the tables and events in §1.2. A COO stage never reads a table that no
seam owns.

### 6.2 S1 — `BusinessSignals` (new, read-only DTO)

`App\Library\Coo\BusinessSignalReader::read(Business, AnalyticsDateRange current, AnalyticsDateRange previous): BusinessSignals`
**composes** existing seams: the `DashboardStatusReader` row, the B5 comparison,
2B counts and the Opportunity work-queue head. It contains **no SQL formula of
its own**. Home and the AI eligibility check (§8) use the same DTO, so the page
and the COO never disagree about a number.

### 6.3 S2 — `SignalComparator` (pure functions, no I/O)

For each metric pair it returns one of:

- `material_increase` / `material_decrease`: both hold — `max(current, previous) ≥ config('coo.materiality.min_volume', 10)`, and `|Δ| / max(previous, 1) ≥ config('coo.materiality.min_relative_change', 0.30)`;
- `stable`: neither threshold is met;
- `insufficient_data`: the volume floor is not met, or the metric is absent.

Anomalies are fixed rules, for example failed automations `≥ 3` and `≥ 2×` the
previous period. Every threshold lives in `config/coo.php`. None appears as a
literal in a class.

### 6.4 S3/S4 — rules and `NextBestMoveSelector`

`App\Library\Coo\NextBestMoveSelector::select(array $attention, ?Opportunity $queueHead): ?NextBestMove`
is a pure function. There is no persistence and no queue.

**Pool and order.** It stops at the first match:

1. `AttentionType::ConversationsAwaitingReply` (new, §7.2). A customer is waiting, and that is the most time-sensitive thing a local business has.
2. `GoogleConnectionLost`
3. `AutomationFailing`
4. `GoogleLocationUnhealthy`
5. **Opportunity work-queue head**, using RFC-002 ordering exactly (current, actionable statuses, `priority_score` desc, impact, urgency, `first_detected_at`, id)
6. `WebsiteUnpublished` (Informational)
7. Nothing → "You're all caught up."

Billing types are excluded (§5.2). The order is fixed in code and tested.
Changing it needs an amendment to this contract. It is not configuration.

Every "obvious action" in L-8 maps to an existing or planned deterministic
rule, and **none calls an LLM**:

| Obvious action | Rule |
|---|---|
| Google disconnected | `AttentionType::GoogleConnectionLost` (exists) |
| Website unpublished | `AttentionType::WebsiteUnpublished` (exists) |
| Unanswered lead | `AttentionType::ConversationsAwaitingReply` (new, over a 2B count) |
| Automation failed | `AttentionType::AutomationFailing` (exists) |
| Missing profile fact | Business Advisor opportunity types (exist; 11 types) |
| Known SEO issue | A future `seo` producer under its own contract (no SEO domain exists) |

### 6.5 "Why this?" — deterministic

- **Opportunity move:** the registry-rendered `evidence_summary_templates` (for example "The business has no primary location on file.") plus score components in words: "High impact · Quick to do", derived from impact ≥ 4 and effort ≤ 2. Never the raw score.
- **Attention move:** the type's `sentence()` plus its fact, for example "2 conversations have waited more than 5 minutes; the oldest since 10:42."
- **No AI.** If a cached, displayable `coo_insights` row exists for this move's subject, it may appear under a separate heading, "AI summary", labelled per §8.4. It is never merged into the deterministic explanation.

---

## 7. Reusing the Opportunity Engine

### 7.1 What the COO uses unchanged

Candidates, evidence validation, fingerprints, scoring v1, freshness,
lifecycle (open, awaiting approval, in progress, snoozed, completed,
dismissed), completion policies, transitions, domain events, the customer
routes under `opportunities.*`, and the admin run surfaces. The COO **reads**
the work-queue head. It never writes an opportunity except through a
producer and `OpportunityManager`, exactly as RFC-002 requires.

### 7.2 Why status exceptions stay as Attention and do not become Opportunities

L-9 requires proof. Here it is:

1. **Freshness.** An opportunity exists only after a producer run and goes stale only on a later run. A broken Google connection or a waiting customer must show on the **next render**, not the next run. `DashboardStatusReader` reads the status column at render time, so it can never be stale.
2. **Lifecycle semantics.** Opportunities can be snoozed and dismissed by design. A customer must not be able to dismiss "your Google connection is broken" or "a customer is waiting", and those clear themselves the moment the status changes.
3. **Completion policies.** The `system_verified`, `customer_attested` and `external_verified` policies describe *doing an improvement*. A status exception has nothing to complete. It stops being true.
4. **Existing precedent.** Slice 4 (merged) already established Attention as this status-derived vocabulary. Using it is reuse, not a new domain.

So Attention is **not a work queue**: it has no persistence, no lifecycle and no
ranking of its own beyond the fixed order in §6.4. Every *improvement* move goes
through Opportunity.

**New attention type:** `ConversationsAwaitingReply`, severity Warning,
sentence "{N} customers are waiting for a reply.", action "Reply", linking to
Conversations. It is raised when 2B `awaitingReplyCount() > 0`.

### 7.3 The producer trigger (closing K-5)

- **Event-driven.** After commit, on `BusinessUpdated`, `BusinessPrimaryLocationUpdated`, `BusinessServicesSynced` and `CustomerOnboardingCompleted`, dispatch `RunBusinessAdvisorOpportunityProducer` for that Business. `beginRun()`'s concurrency protocol already stops duplicate runs.
- **Sweep.** A new command, `opportunity:dispatch-business-advisor`, runs daily for active Businesses whose last successful `business_advisor` run is older than 24 h.
- **Gates.** Both no-op unless `config('opportunity.enabled')`. The existing job class is **not** turned into a generic dispatcher (worker guide §"intentionally current scope"). Each future worker gets its own job.

**D-3 — enabling is earned, not automatic.** Merging this contract enables
nothing. `config('opportunity.enabled')` stays at its current default until
C-1 has shipped and **proved** safe automatic triggering, and it is then turned
on through the existing rollout and config boundary, never by a code default.
C-1 must demonstrate all of:

1. **No run storm.** A burst of profile edits for one Business produces at most one run per debounce window (`config('opportunity.trigger_debounce_minutes', 15)`), proven with a burst test.
2. **Bounded sweep.** The daily sweep processes Businesses in bounded batches, dispatches at most one job per Business per day, and is idempotent when it runs twice.
3. **Concurrency.** A trigger arriving while a healthy run is active is skipped by `beginRun()`'s existing protocol, not queued behind it.
4. **Fail-safe.** With the engine disabled, both paths no-op and write nothing. A failed run never blocks the next one.
5. **No manual dependency.** No production behaviour relies on a `tinker` or otherwise manual dispatch. The deployment guide's manual command remains a diagnostic only, and a test asserts the automatic paths alone keep the queue populated.

### 7.4 AI and Opportunity in v1

AI **does not** create, rank, reorder or hide opportunities in v1. A later
slice (C-4, separately contracted) may add a `coo` worker whose producer turns
the *structured facts* of a persisted diagnosis into candidates for existing
registry types. That output is untrusted input, validated identically, and
scored only by `OpportunityScorer` (RFC-002).

---

## 8. AI escalation rules

### 8.1 Never

- **On a Home render.** `BusinessHomePresenter`'s dependency graph contains no AI client, and a test asserts it (T-COO-1). Home reads at most one cached `coo_insights` row.
- **For any obvious action** in §6.4.
- **For a dormant Business**, meaning no new contact, conversation, incoming message, automation run or member login in `config('coo.dormant_after_days', 30)` days. This also meets managed-messaging C-8: a dormant Business costs nothing externally.
- **When not allowed.** `services.openai.active` is false, `ai_coo_basic` is not allowed for the Business, the plan is unassigned, inactive or suspended, or the budget gateway refuses (§10).
- **Inside a web request.** All COO AI runs in a queued job, except future interactive asks (§12), which are the customer's own explicit request.

### 8.2 Eligible triggers (product lane)

| # | Trigger | Condition |
|---|---|---|
| E-1 | Multi-signal change | At least 2 canonical metrics are `material_*` in the same period, **and** no deterministic rule explains them (no Attention item and no Opportunity created in the period touching those metrics) |
| E-2 | Relevant work finished | A move the COO surfaced completes or fails (`OpportunityCompleted`, `OpportunityExecutionFailed`, and so on), **and** E-1 still holds afterwards |
| E-3 | Scheduled deeper review | Monthly, per non-dormant Business, **only if** the signal fingerprint differs from the last insight's |
| E-4 | Explicit customer request | "Explain this change" on the Performance band. Rate-limited to one per subject per 24 h, and charged to the **interactive** lane |

### 8.3 The prompt contract

- **Inputs are aggregates and registry facts only:** counts, trends, attention types, opportunity types and their evidence keys. **No contact names, phone numbers, email addresses or message bodies** in v1. This also removes the prompt-injection surface.
- **Output** is a JSON object validated against a fixed schema: `statements[]` of `{class: known|likely|unknown, text, fact_refs[]}`, with at most 3 statements and at most 280 characters each. Anything invalid is discarded and nothing is shown.

### 8.4 Trust and causality (L-14)

- A `known` statement must reference at least one input fact in `fact_refs`, and its text must restate that fact.
- A `likely` statement must use hedged wording ("may", "likely", "could"). A deterministic validator rejects causal verbs ("caused", "because of", "led to", "resulted in", "drove", "thanks to") in `likely` and `unknown` statements, and rejects them in `known` statements unless the cited fact is itself causal. No canonical fact is causal today, so causal wording is effectively always rejected.
- **Sequence is never cause.** The template for a before/after pair is fixed: "{Metric} rose after {event}. We can't tell whether {event} caused it."
- Presentation: "Known", "Likely" and "Unknown" appear as plain words. They are never colour-only.

---

## 9. Caching and invalidating AI output

### 9.1 `coo_insights` (new)

| Column | Type | Meaning |
|---|---|---|
| `id`, `uid` | | |
| `business_id` | FK businesses, cascade | Owner. Every read is scoped by it |
| `workspace_id` | FK workspaces | For budget attribution and admin audit |
| `kind` | string(24), enum `CooInsightKind` | `performance_diagnosis` (v1); `move_explanation` (reserved) |
| `subject_type` / `subject_id` | string(24) / bigint, nullable | `business` or `opportunity` plus id |
| `signal_fingerprint` | char(64) | sha256 of the canonical JSON of the **bucketed** input facts, `prompt_version` and `policy_version` |
| `facts_snapshot` | json | The exact facts sent, for audit and reproducibility |
| `output` | json | The validated statements (§8.3) |
| `prompt_version` | unsigned smallint | Bumping it invalidates older rows |
| `model_route` | string(16) | The logical route (§13) |
| `provider_model` | string(64) | Resolved model id. Provenance, shown only to admins |
| `ai_usage_ledger_entry_id` | FK, nullable | Cost provenance |
| `generated_at`, `expires_at` | timestamps | `expires_at = generated_at + config('coo.insight_ttl_days', 7)` |
| `invalidated_at`, `invalidation_reason` | nullable | Soft invalidation. Rows are never deleted on invalidation |
| unique | `(business_id, kind, subject_type, subject_id, signal_fingerprint, prompt_version)` | Identical facts are never paid for twice |

**Bucketing.** Counts go into fixed bands (0, 1–4, 5–9, 10–24, 25–49, 50–99,
100+) before fingerprinting, so a one-contact change does not create a new
paid insight. A material change (§6.3) always changes the fingerprint,
because it changes the `material_*` classification that is part of the facts.

### 9.2 Displayability

- **Displayable** means `invalidated_at IS NULL` and `expires_at > now()`.
- **Expired only** (not invalidated), with the budget exhausted or AI off: still displayable, with "Updated {date}" (L-12, "cached AI stays visible").
- **Invalidated:** never displayed. Its facts no longer hold, and showing it could mislead.

### 9.3 Invalidation, which queues nothing by itself

| Signal | Effect |
|---|---|
| The fingerprint changes on the next signal read | Invalidate (`signal_changed`) |
| `OpportunityCompleted` / `Dismissed` / `ExecutionSucceeded` / `ExecutionFailed` for the subject | Invalidate (`subject_work_changed`) |
| `WebsitePublished`, `GoogleBusinessProfileConnected` / `Disconnected` / `ConnectionRevoked`, `BusinessUpdated` | Invalidate the Business's `performance_diagnosis` (`context_changed`) |
| `prompt_version` or `policy_version` bump | Older rows are never selected |
| Plan loses `ai_coo_basic` | Stop displaying. Rows stay for audit |

Regeneration happens **only** through §8.2's triggers, in the queued
`GenerateCooInsight` job. Invalidation alone never spends money.

---

## 10. AI usage ledger and budget architecture

### 10.1 One gateway for every AI call

`App\Library\Ai\AiGateway::complete(AiRequest $request): AiResult` becomes
the **only** way the application reaches a model. `AiRequest` carries
`workspace`, `business` (nullable for Workspace-level work such as agency
prospecting), `category` (`AiUsageCategory`), `lane` (`product | interactive`),
`route` (`AiModelRoute`), `messages`, `maxOutputTokens` (clamped to the route's
cap), `idempotencyKey` and `actorUserId`.

Sequence:

1. **Gates.** `services.openai.active`; the category's entitlement (`ai_coo_basic` for COO categories); plan status active; the dormancy rule for scheduled product categories.
2. **Policy.** `AiBudgetPolicyResolver` → Workspace cap, Business sub-cap, interactive share, per-request caps (§11).
3. **Estimate the upper bound.** `ceil(input_chars / 3)` tokens at the input price, plus `maxOutputTokens` at the output price, using the route's price row. If this exceeds the route's `max_request_cost_microusd`, refuse (`request_too_expensive`).
4. **Reserve** in a transaction with `lockForUpdate` on the Workspace period row and, when present, the Business period row. Check `committed + reserved + estimate ≤ cap` at Workspace level, at Business level, and at interactive-lane level (`interactive_committed + interactive_reserved + estimate ≤ cap × share`). If any check fails, refuse with `budget_exhausted` and make **no provider call**.
5. **Call** `AiCompletionClient` for the route's provider and model.
6. **Commit** the actual cost from the provider's reported usage at the price row's version, and release the difference. On provider failure, release everything unless the provider reported billable usage, in which case commit that.
7. **Expire.** Reservations older than 15 minutes are released by a scheduled job, following the pattern of `ExpireStaleUsageReservations`.

Callers must handle `AiResult::refused(reason)`. §11.4 lists what each caller does.

### 10.2 Tables (new, all internal, never shown to customers)

**`ai_usage_periods`**: one row per scope and period, used as the lock and counter row.

| Column | Meaning |
|---|---|
| `scope_type` (`workspace \| business`), `scope_id` | Scope. Avoids a nullable unique key |
| `workspace_id` | Always set |
| `period_key` | `YYYY-MM` (UTC calendar month) or `trial:{assignment id}` |
| `policy_key`, `policy_version` | Which policy set the cap |
| `cap_microusd` | Snapshot of the cap for this period |
| `reserved_microusd`, `committed_microusd` | Totals |
| `interactive_reserved_microusd`, `interactive_committed_microusd` | Lane totals |
| unique | `(scope_type, scope_id, period_key)` |

**`ai_usage_ledger`**: append-only entries.

| Column | Meaning |
|---|---|
| `uid`, `workspace_id`, `business_id` (nullable) | Attribution |
| `category` | `AiUsageCategory`. Live at AI-1: `coo_diagnosis`, `coo_interactive`, `conversation_compaction`, `website_generation`, `campaign_message_draft`, `agency_prospect_reply`. Reserved for the features that will follow, added case by case as each ships: `seo_reasoning`, `email_newsletter`. **Every first-party AI call carries a category, and every category draws on the same Workspace budget (D-2).** A call with no category is a bug, not a free call |
| `lane` | `product \| interactive` |
| `model_route`, `provider`, `provider_model`, `price_version` | Provenance |
| `status` | `AiUsageEntryStatus`: `reserved \| committed \| released \| failed \| refused` |
| `refusal_reason` | nullable, for example `budget_exhausted`, `request_too_expensive`, `ai_disabled` |
| `input_tokens`, `cached_input_tokens`, `output_tokens` | From the provider's usage |
| `estimated_cost_microusd`, `actual_cost_microusd` | Integers in micro-USD (1 USD = 1 000 000). No floats |
| `period_key`, `idempotency_key` (unique), `actor_user_id` | |
| `created_at`, `settled_at` | |
| indexes | `(workspace_id, period_key)`, `(business_id, period_key)`, `(workspace_id, period_key, category)` |

`AiUsageEntryStatus` is deliberately **not** RFC-005's `UsageReservationStatus`.
The values look alike, but one is customer money and the other is platform
cost, and sharing the enum would couple the two domains.

### 10.3 Managed-messaging §20 compliance

C-1 holds: there is no provider call without authorization and an available
budget. C-5: the meter is separate and never the telecom balance. C-6: the
Workspace-wide cap means Agency Businesses do not multiply the allowance. C-7:
each category declares a meter (`ai_usage_ledger.category`) and a payer (the
platform's included allowance). C-8: dormancy is a gate. C-9: provider cost and
tokens are admin-only. C-10: every refusal degrades gracefully (§11.4).

### 10.3a One budget for all first-party AI (D-2)

There is **one** included AI budget per Workspace, and every first-party AI
call draws on it: COO diagnosis and explanation, website generation, SEO
reasoning, prospecting and reply assistance, future newsletter and email AI,
and interactive COO conversation. There is no second allowance and no
per-feature free pool. Attribution by category exists for reporting and
diagnosis, never as a separate cap.

For an Agency both limits apply and the first one reached wins: **$25 per
month across the Workspace** and **$6 per month for any one Business**. A
Workspace-level call with no Business (agency prospecting) is checked against
the Workspace cap only, and still consumes the shared budget.

### 10.4 Lanes (L-13)

- The **interactive** lane is hard-capped at `interactive_share_bps` of the Workspace cap (default `3000` = 30%).
- The **product** lane may use everything the interactive lane has not committed or reserved, so unused interactive allowance is not wasted.
- The result: chat spam can burn at most 30%, and product AI always keeps at least 70%.
- The interactive lane also has per-actor rate limits (`config('ai.interactive.max_requests_per_hour', 20)`) and per-thread limits (§12).

---

## 11. Plan AI policies

### 11.1 Policy source

`config/ai.php` → `budgets`, keyed by policy. Amounts are integer micro-USD and
can be overridden by env. `AiBudgetPolicyResolver` is the **only** reader, so
moving the numbers into catalog columns later changes one class. Admin editing
through a UI is out of scope for v1. Changing an amount bumps
`policy_version`, and a period already opened keeps the cap it snapshotted.

| Policy | Period | Workspace cap | Per-Business cap | Interactive share |
|---|---|---|---|---|
| `trial` | `trial:{assignment}` for the whole trial (28 days) | 1 500 000 ($1.50 total) | = Workspace cap | 3000 bps |
| `core` | UTC calendar month | 5 000 000 ($5) | = Workspace cap | 3000 bps |
| `growth` | UTC calendar month | 10 000 000 ($10) | = Workspace cap | 3000 bps |
| `agency` | UTC calendar month | 25 000 000 ($25) | 6 000 000 ($6) | 3000 bps |

**Resolution:**

- A Workspace in the canonical trial state (§11.1a) uses `trial`, and only that state selects it.
- Otherwise the active assignment's `WorkspacePlanTier` decides. A complimentary Workspace uses its tier's policy.
- An unassigned, inactive or suspended plan gets **zero**: every call is refused and deterministic behaviour continues.
- "Whichever first" (L-11): each Business-scoped call must pass both the Business row and the Workspace row. Workspace-level calls with no Business (agency prospecting) pass only the Workspace row.

### 11.1a The canonical trial state (D-1, slice T-1)

Approved by the owner. It belongs to RFC-004's plan assignment and needs an
RFC-004 amendment, which slice T-1 carries. This contract specifies only what
the AI budget depends on.

| Piece | Specification |
|---|---|
| Status | A new case `WorkspacePlanAssignmentStatus::Trialing = 'trialing'`, beside the existing `active`, `inactive` and `suspended`. The column `workspace_plan_assignments.status` already stores a string, so no column changes |
| End date | A new nullable `workspace_plan_assignments.trial_ends_at` timestamp. It is **required** whenever the status is `trialing`, and a check in the writing service enforces that pairing |
| Length | 28 days, set when the trial starts. The cap is for the **whole** trial, not per month |
| Entitlements | A trialing assignment grants the same features as its tier. Only the AI budget policy differs. Slice 5's rule that a trial grants no messaging balance is unchanged |
| Budget period | `period_key = trial:{assignment id}`, one period for the whole trial, capped at 1 500 000 micro-USD ($1.50). When the trial ends or converts, the next call opens the tier's ordinary monthly period. Unspent trial allowance never carries over |
| Detection | **Only** `status === trialing`. Never account age, `created_at`, the absence of a payment method, `is_complimentary`, or any other proxy. An architecture test asserts no such inference exists |
| Expiry | A trial that has passed `trial_ends_at` but has not been transitioned is treated as **exhausted**, not unlimited: AI calls are refused while deterministic behaviour continues |

**Launch gate (D-6):** hard enforcement must be working before any 28-day
trial is offered. T-1 therefore depends on enforcement being on, not merely
on the ledger recording.

### 11.2 Per-request guards (config, per route)

`max_input_tokens`, `max_output_tokens` and `max_request_cost_microusd`.
`reasoning` is also used only when the remaining Workspace budget is at least
`config('ai.routes.reasoning.min_headroom_multiple', 3)` × its request cap.
Otherwise the request is downgraded to `routine` (§13).

### 11.3 What customers see (Settings → Billing → AI usage only)

| State | Condition (Workspace scope; Agency owners also see per-Business rows) | Copy |
|---|---|---|
| Normal | committed < 80% | "Included AI usage — Normal." |
| Nearing limit | 80% ≤ committed < 100% | "You've used most of this month's included AI. Everything else keeps working." |
| Limit reached | ≥ 100%, or any refusal for `budget_exhausted` this period | "This month's included AI is used up. Your Home, results and automations keep working; AI summaries return on {first day of the next period}." On the trial policy, which has no next period: "Your trial's included AI is used up. Everything else keeps working." |
| Trial (added to any state) | Trial policy | "Your trial includes a smaller AI allowance." |

The page shows no tokens, no dollar amounts, no provider or model names and no
percentage meter. The threshold (`0.8`) is config. Home never shows AI usage.

### 11.4 Behaviour at exhaustion, per caller (L-12)

| Caller | On `refused(budget_exhausted)` |
|---|---|
| COO insight job | Skip. Any cached insight stays displayable per §9.2 |
| Explain-this-change (interactive) | Plain message. No retry loop |
| Website AI draft | "AI drafting is paused until {date}. You can keep editing your website." Manual editing is unaffected |
| Campaign AI message draft | The button is disabled with the same sentence |
| Agency prospect AI reply | Falls back to the existing non-AI path: no automatic reply, and the conversation is flagged for a human. Enrolment, STOP handling and sending of already-approved messages continue |
| Everything deterministic | Unaffected |

---

## 12. Conversation compaction (interactive lane)

**Deferred by the owner (D-5).** Interactive COO chat is **not** built now, and
slice AI-4 is unscheduled. What ships first is the deterministic chain: facts →
deterministic metrics → Opportunity and rules → next best move → "Why this?".
A conversational COO is revisited only if it clearly adds value on top of that.

This section stays in force as the binding design for any future "Ask" surface,
so that one is never improvised later. Two things survive the deferral: the
protected product share in §10.4, which D-2 requires regardless, and E-4
"Explain this change" in §8.2 — a **single-shot, rate-limited** explanation of
one period's movement, charged to the interactive lane. E-4 is not a
conversation: it keeps no thread, carries no history, and needs none of the
tables below.

- **Tables** (created only when a future owner decision authorizes a chat surface): `coo_threads` (`business_id`, `user_id`, `summary`, `summary_through_message_id`, `summary_version`) and `coo_thread_messages` (`thread_id`, `role`, `content`, `token_estimate`). Both are scoped to a Business, never keyed by phone (contrast §1.4).
- **Context assembly**, in this order, cut to the route's `max_input_tokens`: system prompt → **retrieved Business facts** (the `BusinessSignals` DTO plus knowledge-profile fields, built deterministically) → the durable **summary** → the last `K = config('ai.interactive.recent_turns', 6)` turns. Older turns are never replayed verbatim.
- **Compaction:** when unsummarized turns exceed `config('ai.interactive.compact_after_turns', 10)`, a `conversation_compaction` call on the `compaction` route folds them into `summary`, charged to the interactive lane. When the lane is exhausted, the oldest turns are simply dropped. Nothing is compacted for free.
- **Per-thread cap:** `config('ai.interactive.max_thread_cost_microusd')`. At the cap the thread asks the user to start a new one, which is seeded with the summary.
- **Security:** retrieved content and customer text are treated as data. Output is validated before it is stored. Nothing the model writes can trigger an action. Actions stay behind the existing Opportunity approval flow.

---

## 13. Model routing

- **`AiModelRoute` enum (logical):** `routine`, `reasoning`, `compaction`. Domain code asks for a **route** and never names a model.
- **Config**, `config/ai.php` → `routes.{route}`: `provider`, `model`, `input_price_microusd_per_mtok`, `cached_input_price_microusd_per_mtok`, `output_price_microusd_per_mtok`, `max_input_tokens`, `max_output_tokens`, `max_request_cost_microusd`, plus `price_version`. **This contract locks the three routing classes and never a provider model name (D-4).** Slice AI-1 picks each route's concrete model from a current cost and quality evaluation and records it in config only. `routine` and `compaction` take the cheapest model that passes the quality threshold; `reasoning` takes the stronger model and is reached **only when a deterministic rule escalates the case** (§8.2 E-3, §11.2). Changing any model later is a config edit: no domain code, no schema, no migration, and no change to a stored insight's meaning. `provider_model` is still stored per call, as provenance for what actually ran.
- **Provider seam:** `App\Library\Ai\Contracts\AiCompletionClient::complete(AiCompletionRequest): AiCompletionResult` (text, finish reason, provider model, token usage). `OpenAiCompletionClient` uses the already-installed `openai-php` client and credentials from `services.openai.*`. `FakeAiCompletionClient` serves tests. Adding another provider means one adapter plus config. No domain change.
- **Selection policy:**

  | Category | Route |
  |---|---|
  | `coo_diagnosis` | `routine`; `reasoning` only for E-3 with ≥ 3 material signals and enough headroom |
  | `coo_interactive` | `routine` |
  | `conversation_compaction` | `compaction` |
  | `website_generation`, `campaign_message_draft`, `agency_prospect_reply` | `routine` |

  If a route is unaffordable, downgrade one step. If `routine` is unaffordable, refuse.
- **Architecture test:** no string matching `/\b(gpt-|o\d-|claude-|gemini-)/` appears in `app/` outside `app/Library/Ai/Providers/**`.

---

## 14. Factual activity timeline (Recent work)

`App\Library\Dashboard\RecentWorkReader::recent(Business, int $limit = 10): array<RecentWorkItem>`
runs one bounded query per source (`ORDER BY created_at DESC LIMIT 10` on the
indexes already present), merges the results in PHP by timestamp and keeps the
top 10.

| Source | Item text (factual, past tense) | Index |
|---|---|---|
| `website_revisions` joined to `websites` (`business_id` is unique) | "Website version {n} published" | `websites.business_id` unique, `website_revisions(website_id, version_number)` |
| `opportunity_transitions` joined to `opportunities` (`to_status = completed`) | "Completed: {registry title}" | `opportunities(business_id, …)`, `opportunity_transitions(opportunity_id, created_at)` |
| Automation failures (`status = failed`, grouped per automation per day), read **only through the V2-H-owned automation reader**. Today that reader covers `automation_executions`. After Automations V2 slice V2-H it also unions `automation_step_runs` (`AUTOMATIONS-V2-WORKFLOW-ENGINE-CONTRACT.md` §1.3, §15.4, merged in #250). Recent work never queries either table directly | "{Automation name} failed {n} times" | `(business_id, created_at)` on each ledger |
| `business_google_operations` (`connect_completed`, `disconnected`, `location_bound`, `location_unbound`) | "Google connected", "Google listing linked", and so on | `bgo_business_created_index` |
| `business_knowledge_profile_changes` (grouped per actor per day) | "Business details updated" | `(business_id, created_at)` |

**Rules:**

- Real persisted rows only. No inferred event, no "AI did X" unless a ledger row proves it.
- Noise is excluded: `token_refreshed` and `mirror_refreshed` never appear.
- A website **rollback** creates no revision row, so it is not listed. That is a known gap, not something to invent.
- Every link goes through `DashboardLinkGate`.
- Agency prospecting and billing events never appear on a Business Home.

A dedicated `business_activity_events` projection is **not** created. It would
only be justified if the five-source union exceeded its budget in §16, and it
would need its own contract.

---

## 15. Security and tenancy

1. **Scope.** Every Home, COO and AI read is scoped to the `CustomerContext`-resolved Business id, never to request input. `coo_insights`, `business_home_visits`, `ai_usage_*` and any future `coo_threads` all carry `business_id` or `workspace_id` foreign keys. A foreign or unauthorized Business returns 404, never a figure, and a test covers it.
2. **One Business per prompt.** A prompt contains facts from exactly one Business. There is no cross-client prompt, including for the Agency Account Home.
3. **PII minimization in prompts** (§8.3). Message bodies never enter COO prompts in v1.
4. **Admin-only visibility.** Tokens, provider cost, provider and model names, `facts_snapshot` and the ledger are admin-only. Customers see only the states in §11.3.
5. **View-as and impersonation.** They never write the visit marker, never start an interactive AI request, and never trigger explain-this-change. They are reads only, consistent with `ViewAsProhibitedActions`.
6. **Logging.** Prompt and response bodies are never logged. The ledger stores token counts only. Audit content lives only in the insight row, which is scoped to the Business.
7. **Credentials.** Only `services.openai.*`. No credential appears in config files or tests. The fake client serves tests, and `Http::preventStrayRequests()` is already global.
8. **Output safety.** Schema validation plus the causal-wording validator. Output is escaped on render and never rendered as HTML.
9. **Destructive paths.** Invalidation is soft. The ledger is append-only. Deleting a Business cascades its insights and markers. Ledger rows keep `workspace_id` for platform audit (`business_id` is set to null on delete).

---

## 16. Query and performance budgets

These are counted with `DB::listen`. The shell and the Slice 2A entitlement
snapshot are excluded, using the existing `analyticsOwnedSql()` caller-based
method. Every count must stay flat when the fixture data is doubled.

| Owner | Business Home, cold cache | Warm (B5 caches hit) |
|---|---|---|
| Dashboard: Slice 4's existing dashboard-owned reads (≤ 10: status row, remediation gates, opportunity head, actions), marker read and write (≤ 2), insight read (1), Recent work (≤ 5) | ≤ 18 | ≤ 18 |
| Analytics: current and previous period (≤ 6) plus `countsBetween` (1, instant window, not cached) | ≤ 7 | ≤ 1 |
| Conversations: started ×2, incoming/replied (1), awaiting (1), since-visit started (1) | ≤ 5 | ≤ 5 |
| Automations instant counts (V2-H, since-visit) | ≤ 1 | ≤ 1 |
| **Total product data** | **≤ 31** | **≤ 25** |

Removing the Spend band (H-1) and replacing the list of 5 recommendations with
one move (C-2) should lower the dashboard-owned count. Each slice nevertheless
asserts the ceiling above for its own state and never a looser one. Every read
is an indexed, Business-scoped statement with a `LIMIT` where it returns rows.

- **AI calls on Home: 0. HTTP requests on Home: 0.** Both are asserted.
- The chart series is loaded through the async endpoint, with its own existing B5 budget.
- The Agency Account Home stays within its current ≤ 12, plus ≤ 2 for cross-client performance and ≤ 2 for outreach, **≤ 16** in total.
- `GenerateCooInsight` job: at most 1 AI call, and its signal read uses the same budget as Home.
- **Correctness outranks the budget** (Slice 4 rule). If a canonical figure cannot fit, the slice stops and asks rather than approximating.

---

## 17. Implementation slices

None of these slices is authorized by this document.

| # | Slice | Scope (allowlist summary) | Depends on |
|---|---|---|---|
| **H-1** | Home cleanup | Remove the Spend band and the "Add funds" quick action from the Business Home; billing exception strip (§5); remove outbound volume, provider-accepted and failed-send headlines from Home; retitle. `BusinessHomePresenter`, `DashboardSnapshot`, `business-home.blade.php`, `bands/{spend,headlines,attention}.blade.php`, `tests/Feature/Dashboards/**` | — |
| **H-2** | Since your last visit | Migration `business_home_visits`; `HomeVisitMarker`; B5 `countsBetween()`; V2-H instant automation counts; band view; `config/home.php` | H-1 (same presenter) |
| **H-3** | Business performance | Generalize `BusinessDashboardAnalyticsPresenter` to the selected period plus the previous equal period; period selector; chart via the async series; "See details" link to Results | H-2; the Results redesign merge (§4.4) |
| **H-4** | Visibility, Conversations, Automations | Status-row tiles; 2B `incomingCount` / `repliedCount` / `awaitingReplyCount`, plus an index if `EXPLAIN` requires one; Automations band | H-3 |
| **H-5** | Recent work | `RecentWorkReader`; band | H-4 |
| **H-6** | Results fold | Parity tests P-1…P-10; `results.redirect_to_home`; navigation change; later, R3 view removal | H-3, H-4, owner flag |
| **A-1** | Agency Account Home | Cross-client performance; outreach metrics; capacity and billing shown only when actionable. `AccountHomePresenter`, `agency-home.blade.php`, `tests/Feature/Dashboards/**` (agency files) | — |
| **A-2** | Store prospect reply intent | Additive nullable `agency_prospect_messages.intent`, written from the existing `AgencyProspectAiDecision`; then "Positive replies" | A-1 |
| **C-1** | Producer trigger | Listeners and the daily command for `RunBusinessAdvisorOpportunityProducer`; `app/Console/Kernel.php` (one line); the five §7.3 safety proofs (T-C1-1…4). **Exit criterion: safe automatic triggering is proven, after which the owner enables the engine through the existing config boundary — the slice never flips a code default** | — |
| **C-2** | Next best move | `NextBestMoveSelector`; `AttentionType::ConversationsAwaitingReply`; "Why this?"; replace the list of 5 with one move and a link | H-1, H-4, C-1 |
| **C-3** | Signals and materiality | `BusinessSignalReader`, `SignalComparator`, `config/coo.php`. Pure, no UI | — |
| **AI-1** | Gateway, routing, ledger | `app/Library/Ai/**`, `config/ai.php`, migrations `ai_usage_periods` / `ai_usage_ledger`, reservation-expiry job; **route all 3 existing call sites through the gateway** (capturing usage and setting max tokens) with unchanged behaviour while the budget allows; choose each route's concrete model from a cost and quality evaluation (D-4); prove no bypass path survives (T-AI-GATE-1) | — |
| **AI-2** | AI usage in Settings | Settings → Billing → AI usage (§11.3); admin ledger summary | AI-1 |
| **AI-3** | COO insight | `coo_insights`; eligibility (§8.2); `GenerateCooInsight`; invalidation listeners; output validator; Home "What we notice"; `ai_coo_basic` → `Available` | AI-1, C-3, H-3 |
| **AI-4** | Interactive lane and compaction | §12 tables and flow. **Deferred and unscheduled (D-5).** E-4 "Explain this change" is single-shot and ships inside AI-3, so nothing here blocks the deterministic chain | Not scheduled. Building it later would take a fresh decision; nothing is open now |
| **C-4** | AI-assisted candidates | A `coo` worker under RFC-002 conformance | AI-3, its own contract |
| **T-1** | Trial state and activation | The RFC-004 amendment in §11.1a (`trialing` status, `trial_ends_at`), the resolver branch, trial copy, and the expiry rule. **No trial may be offered until hard enforcement is on (D-6)** | AI-2, enforcement on |

---

## 18. Parallelization boundaries

- **Serial chain on `BusinessHomePresenter` and `business-home.blade.php`:** H-1 → H-2 → H-3 → H-4 → H-5 → C-2 → AI-3 (Home line only). These never run concurrently.
- **Can run in parallel with that chain and with each other:** A-1 (Agency presenter and view only); C-1 (jobs, listeners, Kernel line); C-3 (new `app/Library/Coo/**`, pure); AI-1 (new `app/Library/Ai/**`, `config/ai.php`, its own migrations, plus the three call-site files `OpenAiAgencyProspectingClient.php`, `WebsiteAiGenerationClient.php` and `CampaignController::generateAIMessage` only).
- **Shared-file conflicts to watch:**
  - `AppServiceProvider` bindings: AI-1 and C-1 each add lines only.
  - `app/Console/Kernel.php`: C-1 and AI-1 each add one line.
  - `BusinessConversationReadModel`: H-4 is the only writer.
  - `BusinessAnalyticsQueries`: H-2 is the only writer.
  - `AttentionType`: C-2 is the only writer.
  - Automation readers: the Automations V2 contract (#250) gives V2-H ownership of every `automation_executions` / `automation_step_runs` reader. H-2's instant automation counts and H-5's failure feed are added **as V2-H-owned methods**, coordinated with that lane. They are never parallel readers.
- **Databases:** every lane that writes uses its own `TestDatabaseSafety`-approved sibling.

---

## 19. Migration and backward compatibility

1. **All schema is additive:** `business_home_visits`, `coo_insights`, `ai_usage_periods`, `ai_usage_ledger`, the optional `chat_box_messages(box_id, id)` index, the nullable `agency_prospect_messages.intent`, and later `coo_threads` / `coo_thread_messages`. Each `down()` drops only what its `up()` created. No existing column changes meaning.
2. **No backfill is required.** The visit marker starts empty (the band is absent on the first visit). The ledger starts at zero, so the current period's cap is fully available.
3. **Enforcement (D-6).** Three rules, in force together:
   - **The gateway is the only path.** AI-1 routes all three existing call sites through it. **No uncapped side door may survive**: after AI-1, no code outside `app/Library/Ai/Providers/**` may construct a provider client or call a provider endpoint, and T-AI-GATE-1 asserts it. Every call is recorded and counts toward the caps from that moment.
   - **New COO AI is hard-budgeted from its very first production call.** `coo_diagnosis`, `coo_interactive` and `conversation_compaction` are refused at the cap unconditionally. There is no observation mode for them.
   - **The pre-existing categories** (`website_generation`, `campaign_message_draft`, `agency_prospect_reply`) may run for one observation window with `config('ai.enforce_budgets_for_existing_categories')` false: recorded, counted, not refused — so adopting the gateway changes no existing behaviour on day one. That flag **must be true before broader product launch**, and §21 tracks it as a launch gate, not an open decision.
   Existing AI features otherwise keep working after AI-1 with identical prompts.
4. **The Results URL** is kept (§4.1). The redirect is behind a flag, and the route is never deleted.
5. **Spend band removal** affects only the UI. Billing data, routes and Settings pages are untouched.
6. **Opportunity:** no schema change. `opportunity.enabled` keeps its default until C-1 proves safe automatic triggering; the owner then enables it through the existing config boundary (D-3).
7. **Slice 4 tests** that assert the Spend band or the list of 5 recommendations are **rewritten in the same slice** that changes the behaviour (H-1, C-2). They are never deleted without a replacement assertion.

---

## 20. Test contract

Test IDs are grouped by area. Each slice lists which IDs it must satisfy. Every
test must report a positive count.

### Home layout and billing

| ID | Assertion |
|---|---|
| T-HOME-1 | Band order is exactly §2.1. Empty bands are absent, not rendered empty |
| T-HOME-2 | No Visitors, Google views, SEO, Bookings, Leads, Email or attribution text renders anywhere on Home |
| T-HOME-3 | The word "Leads" never appears. The band is titled "Conversations" |
| T-HOME-4 | No outbound volume, provider-accepted or failed-send tile appears on Home |
| T-BILL-1 | The Spend band and "Add funds" are absent for payer and non-payer, Core/Growth and Agency-opened |
| T-BILL-2 | Each billing `AttentionType` renders only as the strip, only for an actor whose remediation URL resolves, and never as the next move |

### Since your last visit

| ID | Assertion |
|---|---|
| T-SLV-1 | The band is absent on the first visit |
| T-SLV-2 | A refresh within G keeps the window. A view after G moves `window_start_at` to the previous `current_visit_last_seen_at` |
| T-SLV-3 | Another user's visit does not change your window. Two devices for the same user share it |
| T-SLV-4 | View-as and impersonation write nothing |
| T-SLV-5 | A window longer than 30 days is labelled "In the last 30 days" |
| T-SLV-6 | Each count equals its owning seam's figure for the same instant window |
| T-SLV-7 | At most one write per request, and none within 60 s |

### Performance

| ID | Assertion |
|---|---|
| T-PERF-HOME-1 | Every tile equals the matching B5 or 2B figure for the selected period. The previous period has equal length in local dates and is DST-safe |
| T-PERF-HOME-2 | Polarity: a rise in a descriptive metric is never styled as a win |

### Conversations

| ID | Assertion |
|---|---|
| T-CONV-1 | `incomingCount`, `repliedCount` and `awaitingReplyCount` pass fixture truth tables, including a manual reply, an automated reply, an outgoing-only box, and grace-boundary cases |
| T-CONV-2 | `AnalyticsSeparationTest` still passes. B5 never touches `chat_boxes` |

### Next best move and "Why this?"

| ID | Assertion |
|---|---|
| T-NBM-1 | The selector follows the §6.4 order exhaustively (a pairwise table) and excludes billing types |
| T-NBM-2 | The Opportunity head follows RFC-002 work-queue ordering exactly |
| T-NBM-3 | "Why this?" renders the registry evidence and makes zero AI calls |
| T-NBM-4 | Every move link passes `DashboardLinkGate`. An unauthorized destination is never linked |

### COO and AI escalation

| ID | Assertion |
|---|---|
| T-COO-1 | Home's container graph resolves no `AiCompletionClient`. N Home loads → the fake client records 0 calls and 0 HTTP requests |
| T-COO-2 | Each obvious action (§6.4 table) produces its move with no AI call |
| T-COO-3 | `SignalComparator` boundaries: the volume floor and relative threshold, exactly at and just either side of each |
| T-COO-4 | A dormant Business: no eligible trigger fires, and the ledger stays empty |
| T-COO-5 | E-1…E-4 each fire only under their conditions. E-3 with an unchanged fingerprint makes no call |

### Producer triggering (D-3)

| ID | Assertion |
|---|---|
| T-C1-1 | A burst of profile edits for one Business yields at most one run per debounce window |
| T-C1-2 | The daily sweep is batched, dispatches at most one job per Business per day, and is idempotent across two runs |
| T-C1-3 | A trigger during a healthy active run is skipped, not queued. A failed run never blocks the next |
| T-C1-4 | With the engine disabled both paths no-op and write nothing. With it enabled, the automatic paths alone populate the queue: no test and no production path needs a manual dispatch |

### Trial (D-1)

| ID | Assertion |
|---|---|
| T-TRIAL-1 | `trialing` selects the trial policy; `active`, `inactive`, `suspended` and complimentary never do |
| T-TRIAL-2 | Trial is never inferred: account age, `created_at`, a missing payment method and `is_complimentary` each leave the tier policy in force (architecture test plus behaviour test) |
| T-TRIAL-3 | `trialing` requires `trial_ends_at`. A trial past its end date is treated as exhausted, not unlimited |
| T-TRIAL-4 | The $1.50 cap spans the whole trial, not a month, and unspent allowance does not carry into the first paid period |

### Insights

| ID | Assertion |
|---|---|
| T-INS-1 | Identical bucketed facts reuse the row. No second ledger entry |
| T-INS-2 | Each §9.3 signal invalidates. Invalidation alone makes no call |
| T-INS-3 | Displayability matrix (§9.2), including expired but shown during budget exhaustion, and invalidated never shown |
| T-INS-4 | Output-schema rejection; causal-verb rejection; a `known` statement without `fact_refs` is rejected; a rejected output renders nothing |
| T-INS-5 | The prompt contains no contact name, phone number, email address or message body (asserted on the fake client's captured request) |

### AI budget and ledger

| ID | Assertion |
|---|---|
| T-BUD-1 | Reservation at the exact cap boundary: equal to the cap passes, one micro-USD over refuses, with no provider call |
| T-BUD-2 | Agency "whichever first": the Business cap is hit before the Workspace cap, and the Workspace cap before any Business cap |
| T-BUD-3 | Interactive lane at 30%: interactive is refused, and product still succeeds up to the remainder |
| T-BUD-4 | Concurrent reservations never overshoot (two parallel reservations against one remaining slot) |
| T-BUD-5 | Commit uses actual usage × price version. The remainder is released. Stale reservations expire |
| T-BUD-6 | An unassigned, inactive or suspended plan refuses everything, and deterministic Home is unaffected |
| T-BUD-7 | The policy comes only from `config/ai.php` through the resolver. There are no amount literals elsewhere (architecture test) |
| T-BUD-8 | `enforce_budgets_for_existing_categories=false` records the three pre-existing categories and counts them toward the caps but never refuses them. `coo_*` and `conversation_compaction` are refused at the cap regardless of the flag |
| T-BUD-9 | Each caller's exhaustion behaviour matches the §11.4 table |
| T-AI-GATE-1 | **No bypass.** No code outside `app/Library/Ai/Providers/**` constructs a provider client or calls a provider endpoint (architecture test over `app/`), and every AI-reaching path in the app records a ledger entry |
| T-AI-GATE-2 | Every first-party AI category draws on the same Workspace budget: spending in one category reduces what another may use, and no category has a private pool |

### Routing

| ID | Assertion |
|---|---|
| T-ROUTE-1 | Categories map to routes. Downgrade on insufficient headroom. No model literal outside provider adapters |
| T-ROUTE-2 | The existing three call sites send the same prompts through the gateway and now set `max_tokens` |

### Customer copy

| ID | Assertion |
|---|---|
| T-UX-AI-1 | Settings AI usage shows only the §11.3 copy. No token count, AI dollar amount, provider or model name, or AI percentage appears in the AI usage section, on Home, or in any AI-summary block |
| T-UX-AI-2 | The trial line renders only for the trial policy, which is unreachable until T-1 |

### Recent work

| ID | Assertion |
|---|---|
| T-RW-1 | Each source yields its item. Noise operations are excluded. Merge order is by timestamp. Top 10 |
| T-RW-2 | No item is invented. A rollback produces no item. A foreign Business's rows never appear |

### Agency Home

| ID | Assertion |
|---|---|
| T-AGY-1 | Outreach figures match the `agency_prospect_*` truth tables. Positive replies are absent before A-2 |
| T-AGY-2 | Cross-client performance uses grouped statements, and the count stays flat as clients double |
| T-AGY-3 | An Agency-opened Business Home equals the ordinary Business Home plus the frame control, with no Agency KPI |

### Results fold

| ID | Assertion |
|---|---|
| T-RES-1 | With the flag off, Results is byte-for-byte unaffected by Home slices |
| T-RES-2 | With the flag on: 302 to Home with the range mapped; series and campaigns endpoints intact; the navigation item removed |
| T-RES-3 | Parity P-1…P-10 each have an assertion |

### Security

| ID | Assertion |
|---|---|
| T-SEC-1 | A foreign Business returns 404 on every new route and read. Insight, marker and ledger rows are never readable across tenants |
| T-SEC-2 | View-as prohibitions (§15.5) |
| T-SEC-3 | No prompt or response body appears in logs (log spy) |

### Query budgets

| ID | Assertion |
|---|---|
| T-QB-1 | §16 ceilings, cold and warm, flat when data doubles |
| T-QB-2 | Agency Home ≤ 16 |

---

## 21. Launch gates

**No owner decision remains open.** All six were answered on 2026-09-12 and are
recorded in §0.3 as locked direction. What follows is not a decision list: each
row is an execution step whose condition the repository itself proves, and
whoever runs the slice checks it off.

| Gate | Condition that must hold first | Proved by |
|---|---|---|
| Enable the Opportunity Engine through the existing config boundary | C-1 shipped and all five §7.3 safety properties demonstrated. No production path depends on a manual dispatch | T-C1-1…4 |
| Route every existing AI call site through the gateway | AI-1 shipped, with no bypass path left in `app/` | T-AI-GATE-1 |
| Hard enforcement for the three pre-existing AI categories (`ai.enforce_budgets_for_existing_categories` → true) | One observation window of real ledger data. **Required before broader product launch** | T-BUD-8 |
| Offer 28-day trials | §11.1a's state shipped (T-1) **and** hard enforcement already on | T-TRIAL-1…4 |
| Switch on the Results redirect (`results.redirect_to_home`) | Home at full Results feature and data parity, and H-6 proving the migration and redirect behaviour | T-RES-1…3, P-1…P-10 |
| Remove the Results view (R3) | One release with the redirect on and no parity defect. The route itself is never deleted | T-RES-2 |

New COO AI needs no gate of its own: it is hard-budgeted from its first
production call (§19.3).

---

## Appendix A — evidence index

- **Home:** `app/Library/Dashboard/BusinessHomePresenter.php` (bands 95–200; `recommendations()` 282; `spend()` 516), `DashboardStatusReader.php` (40–116), `app/Enums/Dashboard/AttentionType.php`
- **Analytics:** `app/Library/Analytics/BusinessDashboardAnalyticsPresenter.php`; B5 and `AnalyticsSeparationTest`
- **Conversations:** `app/Library/Conversations/BusinessConversationReadModel.php:36,48`; `chat_box_messages.direction` enum `incoming|outgoing` (`2025_05_29_134253`)
- **Opportunity:** `app/Library/Opportunity/{BusinessAdvisorOpportunityProducer,OpportunityTypeRegistry,OpportunityScorer,OpportunityProducer}.php`; `app/Jobs/Opportunity/RunBusinessAdvisorOpportunityProducer.php`; `config/opportunity.php`; `docs/rfcs/RFC-002-OPPORTUNITY-ENGINE{,-DEPLOYMENT,-WORKER-GUIDE}.md`
- **AI:** `config/services.php` (`openai`); `app/Library/AgencyProspecting/{OpenAiAgencyProspectingClient,AgencyProspectAiDecision}.php`; `app/Library/Website/WebsiteAiGenerationClient.php`; `app/Http/Controllers/Customer/CampaignController.php:2944`; `app/Providers/AppServiceProvider.php:218`
- **Entitlements:** `app/Enums/Entitlement/PlatformFeature.php:20`; `app/Library/Entitlement/PlatformFeatureRegistry.php:65`; `database/migrations/2026_08_13_120007_seed_workspace_plan_catalog_and_features.php:93`
- **Legacy AI tables:** `app/Models/ChatBoxMessage.php:20–54`
- **Activity sources:** migrations `2026_09_07_120003` (automation_executions), `2026_09_09_120003` (business_google_operations), `2026_09_09_120004` (business_knowledge_profile_changes), `2026_09_07_130003` / `130004` (website_revisions, published_revision_id), `2026_07_19_120005` (opportunity_transitions); `app/Library/Website/WebsitePublisher.php`
- **Cost invariants:** `docs/automation/CUSTOMER-EXPERIENCE-MANAGED-MESSAGING-AUTOMATIONS-CONTRACT.md` §11.5, §20

---

## Appendix B — implementation record: H-1 and H-2

Delivered on `agent/unified-business-home-h1-h2`, from `origin/main`
`3158260`. Slices H-3 to H-6, A-*, C-*, AI-* and T-1 are untouched, and no
interactive COO surface exists.

### H-1 — billing off the ordinary Business Home (§5)

| Promise | Delivered by |
|---|---|
| Spend band and "Add funds" removed from the Business Home | `BusinessHomePresenter` (the band, its builder and the funding quick action are gone), `DashboardSnapshot::BAND_SPEND` removed, `bands/spend.blade.php` deleted |
| Billing only as one actionable exception strip (§2.2 row 0) | `DashboardSnapshot::BAND_BILLING_EXCEPTION` + `bands/billing-exception.blade.php`; the five billing `AttentionType` cases are split out of the attention band and the most severe one renders, under Slice 4's unchanged audience rule (`remediationUrl()` must resolve for the actor) |
| Strip copy is the type's sentence plus a consequence | `AttentionType::consequence()` (billing cases only); `sentence()` is unchanged, so every other surface reads as before |
| No alert without something to do | A balance under the customer's own automatic top-up threshold is not an exception while automatic top-up is on: either it tops up, or `AutoRechargeFailing` — the thing the customer can actually fix — is raised instead (`BusinessHomePresenter::isActionable()`) |
| Outbound volume, provider-accepted and failed-send figures leave Home | `headlines()` keeps only new contacts, conversations started and automation runs; the band is retitled **Business performance** |
| Settings → Billing unchanged | No file under `app/Library/Usage/**`, `usage-billing` views or routes is touched |

### H-2 — Business activity (§2.3, with the owner's 2026-09-12 correction)

| Promise | Delivered by |
|---|---|
| A real per-user, per-Business visit baseline | Additive migration `2026_09_16_100001_create_business_home_visits_table`; `HomeVisitMarker` reads the window **before** writing, treats a view within `home.visit_gap_minutes` as the same visit, rewrites the "last seen" stamp at most once a minute, and writes through single conditional statements so two tabs agree |
| An adaptive frame, not a hard-wired "Since your last visit" | `HomeActivityWindow`: a previous visit earlier today covers the whole **Business-local** day ("Today so far"), 1–6 days counts from that visit ("Since your last visit"), 7+ days becomes a bounded catch-up ("Last 7 days"). An hourly visitor therefore still sees a useful day |
| Canonical figures only | New contacts and messages received: `BusinessAnalyticsQueries::countsBetween()` (ONE statement); new conversations: Slice 2B `startedCount()`; automations completed/failed: `BusinessAnalyticsQueries::automationCountsBetween()`, beside `automationKpis()` so Home never becomes a second reader of `automation_executions` (it moves with V2-H ownership) |
| Nothing invented | No leads, bookings, revenue, visitors, rankings, SEO or conversion wording renders; zero-value items are omitted; a first visit shows no band at all |
| Never consumed by someone else | View-as and impersonated sessions read the window and write nothing |

**Deferred to their own slices, deliberately:** "Website published" and
"Google connected/disconnected" items (H-5 owns `RecentWorkReader`, and Home
must not open a parallel reader of `website_revisions` or
`business_google_operations`), and "N recommendations done"
(`opportunity_transitions`, C-2).

**Tests:** `tests/Feature/Dashboards/BusinessHomeBillingTest.php` (T-BILL-1,
T-BILL-2) and `BusinessHomeActivityTest.php` (T-SLV-1…7, the adaptive frame,
per-seam equality, tenancy, and the band's query budget). The Slice 4 tests
that asserted the Spend band, the outbound headlines or the old attention
list are rewritten in this slice, never deleted without a replacement
assertion (§19.7).

---

## Appendix C — implementation record: H-3

Delivered on `agent/unified-business-home-h3-performance`, from `origin/main`
`917b5f0e`. Slices H-4 to H-6, A-*, C-*, AI-* and T-1 are untouched; Results
is neither redirected nor removed, and no interactive COO surface exists.

### H-3 — Business performance (§2.5)

| Promise | Delivered by |
|---|---|
| The customer's own period, through Results' range infrastructure and no second one | `bands/headlines.blade.php` includes Results' own `customer.business.analytics._range` partial; `BusinessHomePresenter::selectedRange()` validates the query string with `AnalyticsRangeRequest::ruleSet()` and then `AnalyticsDateRange::fromInput()` — the same presets, the same `MAX_CUSTOM_DAYS = 92`, the same Business-local calendar dates converted once by `localDayStartInStorageTz()` |
| Home opens on This month | `BusinessDashboardAnalyticsPresenter::DEFAULT_PRESET`; Results keeps its own default, and a period chosen on either page means the same window on the other |
| An unusable range is refused, never approximated | `selectedRange()` catches `ValidationException`, falls back to the default window and returns `rangeRejected`, which the band states in words (`data-role="range-rejected"`) |
| The comparison is the equal-length window immediately before the selected one | `BusinessDashboardAnalyticsPresenter::ranges($timezone, $today, $current)` builds it from LOCAL DATES — it ends the day before the selection starts and covers the same number of dates — so a 23-hour spring-forward day and a 25-hour fall-back day are each still exactly one date, and a month, year or custom boundary is crossed by calendar arithmetic. The seam still adds no seconds and owns no timezone code (`AnalyticsSeparationTest`'s own forbidden-token test still passes) |
| One cache entry per Business and per exact window | `cacheKey()` carries the Business id, the range key and both bounds; two equally long custom windows, and a calendar preset and the custom range covering its dates, are all distinct entries |
| Exactly three canonical figures, in KPI-priority order | `headlines()` builds `new_contacts` (B5 `contactKpis()`), `new_conversations` (Slice 2B `startedCount()`, entitlement- and `chat_box`-gated) and `messages_received` (B5 `messageKpis()->inbound`) — and nothing else. Automation runs left Home with this slice: Automations keeps its own band and its own canonical source, and the legacy `automation_executions` activity definition is unchanged |
| Nothing about sending, providers, leads, bookings, revenue, conversions, Google or SEO | Asserted over the rendered `<main>`, not merely over the band payload |
| Every comparison is descriptive | `HeadlinePolarity::DescriptiveGrowth` / `Descriptive`, `judgement: null` throughout; `HeadlineComparison::sentence($previousNoun)` now takes the period it compared against, so the line can never claim a length the figures do not cover ("the previous 10 days" for a 10-day window) |
| The chart costs the initial request nothing | `bands/headlines.blade.php` renders a placeholder carrying `data-series-url`; the script in `customer/dashboard.blade.php` fetches B5's **existing** `customer.workspaces.businesses.analytics.series` endpoint (`throttle:60,1`) for the same range and charts `charts.new_contacts` from `AnalyticsChartBuckets`. No second endpoint, no synchronous series, and a failed or malformed payload says so instead of drawing |
| Results stays where it is | "See details" (`data-role="results-link"`) links to `analytics.overview` carrying the selected range; no redirect, no route or view removal (H-6 owns that) |
| `view_reports` gates the whole band | The band is built only when the gate allows it, and the series URL is issued through `DashboardLinkGate::url(..., ['view_reports'])`, so an actor without it sees no band, no figure and no endpoint |
| H-1 and H-2 unchanged | The billing exception strip, the absence of a routine spend figure, the adaptive activity window, the visit marker's write rules, and "a first visit synthesizes nothing" all hold for every selected period — the performance period never reaches the activity window |

**Tests:** `tests/Feature/Dashboards/BusinessHomePerformanceTest.php` (the
period selector including every preset, custom, the 92-day maximum, refusal
of an unusable range, the year and DST boundaries, the three figures against
their own seams, the 2B read-model origin, descriptive copy, cache identity,
the async chart, "See details", `view_reports`, and H-1/H-2 preservation),
plus the generalized `tests/Feature/Analytics/BusinessDashboardAnalyticsPresenterTest.php`
and one added case in `DashboardQueryBudgetTest` proving no daily-bucket
aggregate runs on a Home request for any period. The Slice 4 and H-1 tests
that asserted a fixed 30-day window, the old headline keys or the absent
chart are rewritten in this slice, never deleted without a replacement
assertion (§19.7).
