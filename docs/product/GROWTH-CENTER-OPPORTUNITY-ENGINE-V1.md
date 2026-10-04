# Growth Center / Opportunity Engine — V1

Branch `agent/growth-center-opportunity-engine-v1`, from `origin/main`
`6ac3e19cceaaeb8cd453b37d29ba7e4f6567319b`. Governing documents read first:
`V1-MASTER-PRODUCT-BLUEPRINT`, `V1-IMPLEMENTATION-ROADMAP`,
`V1-ACCEPTANCE-MATRIX`, RFC-002 (Opportunity Engine) and its deployment/worker
guides, Implementation Contract 19 (AI COO), 02 (Location ACL), 15–19 for every
domain read.

> **Promise.** "Tell me what is stopping my business from getting more
> customers, prove it with real data, and help me fix it."

It is **not** a generic dashboard, a fake AI score, an LLM guessing at advice, a
second analytics warehouse, or a list of disconnected warnings.

---

## 1. Philosophy: fact → finding → opportunity → action

The Growth Center **creates no raw data**. It reads what the other modules
already own and produces, in this order:

| Layer | Example | Where it lives |
|---|---|---|
| **Fact** | "3 open deals are marked *No contact* and are older than 24 h; their values sum to $2,100." | `Growth*FactReader` → plain-array fact sets, read **once** per evaluation |
| **Finding** | "Lead follow-up is delayed." | a pure `GrowthRule` evaluates the facts → `GrowthFinding` |
| **Opportunity** | "Follow up with these 3 leads." | the **canonical RFC-002 `opportunities` row** (one per problem per Location) |
| **Action** | "View leads" (hand-off to the CRM board) | `OpportunityActionRegistry` navigation action + safety class |

Every recommendation traces back to a stored, closed, PII-free evidence record.
**Detection is deterministic code. AI never decides that an opportunity exists**;
it may only explain (§12). With AI off the whole product still works.

## 2. One canonical Opportunity Engine (no second engine)

`opportunities`, runs, candidates, transitions and action executions already
existed (RFC-002) with one producer (`business_advisor`, 11 profile types). The
Growth Center **extends** it:

* **Workers.** No generic `growth` worker. Rules are owned by the existing
  domain workers reserved in `OpportunityWorkerKey`:
  `sales` (CRM, conversations, documents, payments, booking, automations),
  `website`, `seo` (SEO + citations), `reputation` (reviews). One engine run
  per worker per evaluation.
* **Type registry.** `OpportunityTypeRegistry` resolves `business_advisor`
  exactly as before; every other worker resolves the Growth rules
  `GrowthRuleRegistry` registers for it. Titles/summaries stay fixed
  registry templates (RFC-002 §13.2 holds); evidence is validated by the
  unchanged `OpportunityEvidenceValidator`.
* **Rule version = the type key** (`crm.unanswered_new_leads:v1`). A semantic
  change is a new key; old Opportunities simply stop being re-confirmed and
  keep their old explanation. No second version concept was added.
* **Source module / category** are registry metadata (`growth` block of the type
  definition), **not** persisted columns. **Action tracking** reuses
  `opportunity_action_executions`; no `action_started/completed` columns exist.

### Schema additions (every one justified)

| Change | Why |
|---|---|
| `opportunities.location_id` (nullable FK → `business_locations`, index `(business_id, location_id)`) | Location ACL must filter list/count queries **in SQL before aggregation** (Contract 02). `NULL` = Business-wide. Existing `business_advisor` rows stay `NULL`. |
| `growth_score_snapshots` (new) | Score **history**: opportunities are current-state, so "71 → 78 and why" has no other home. Unique `(business_id, snapshot_date, algorithm_version)`. Holds no opportunity truth. |

### Engine amendments (all additive; `business_advisor` behaviour unchanged)

1. **Location context** (`OpportunityContext`): a type may declare
   `context_validator = 'location'`; its context is `null` or exactly
   `['business_location_id' => id]`. `context_key = 'location:<id>'`; the
   fingerprint includes the context, so each Location is a separate
   Opportunity. The manager verifies the Location belongs to the run's Business.
   Types without the validator still require `context = null`.
2. **Dismiss cooldown.** A type may declare `dismiss_cooldown_days`; a dismissed
   Opportunity re-confirmed by a later successful run after the cooldown reopens
   as a **new occurrence** (`occurrence_number + 1`, transition
   `dismiss_cooldown_elapsed`). Types without it stay dismissed.
3. **Dismiss reason.** `OpportunityManager::dismiss()` takes an optional key from
   the closed `DISMISS_REASONS` list; it becomes a fixed server-side
   `safe_note`.
4. **Advisor-queue isolation.** `paginateForCustomer` / `topForCustomer` (the
   Business Advisor / COO / Home read path, which has no Location filter) are
   restricted to `worker_key = business_advisor`, so Growth Opportunities can
   never leak into a surface that does not apply Location ACL.

## 3. The rule registry

`GrowthRuleRegistry` is a **closed, explicit list** — no class discovery, no DB
rules. Each `GrowthRule` supplies: stable versioned key, worker, category,
source module, fact domain, scope (`location`|`business`), copy (title, summary,
headline, why, expected), evidence fact key + summary, action key/label/target,
safety class, score weight, **minimum sample**, goal keys. A rule is a **pure
function** of a `GrowthFactSnapshot`: it performs no query, no write, no
provider call and no AI call.

Verdict per rule (`GrowthRuleOutcome`): **Finding** (per Location) · **Passing**
· **Not applicable** (domain unavailable / not connected / not entitled) ·
**Insufficient** (population below the rule's minimum). Only Finding/Passing are
scored.

### Implemented rules (18 of the 25 requested)

| # | Key | Worker | Category | Impact · urgency · effort | Conf. | Min sample |
|---|---|---|---|---|---|---|
| 1 | `crm.unanswered_new_leads:v1` | sales | Lead response | 4 (5 if ≥3 leads or canonical value ≥ high-value) · 4 · 1 | 1.0 | 3 deals |
| 4 | `conversations.inbound_awaiting_reply:v1` | sales | Lead response | 4 · 4 · 1 | 0.8 | 3 conversations |
| 2 | `crm.stale_opportunities:v1` | sales | Sales pipeline | 3 · 2 · 2 | 1.0 | 3 deals |
| 3 | `crm.high_value_stale_opportunities:v1` | sales | Sales pipeline | 5 · 3 · 2 | 1.0 | 3 deals |
| 21 | `documents.proposal_unsigned:v1` | sales | Proposals & sales | 4 (5 if high value) · 3 · 1 | 1.0 | 3 sent docs |
| 22 | `documents.signed_unpaid:v1` | sales | Payments | 4 (5 if high value) · 3 · 1 | 1.0 | 3 sent docs |
| 24 | `payments.overdue_balance:v1` | sales | Payments | 5 · 4 · 1 | 1.0 | 3 sent docs |
| 23 | `payments.failed_payment:v1` | sales | Payments | 5 · 5 · 1 | 1.0 | 3 sent docs |
| 5 | `booking.type_not_ready:v1` | sales | Bookings | 4 · 3 · 2 | 1.0 | 1 active type |
| 6 | `booking.low_near_term_availability:v1` | sales | Bookings | 3 · 3 · 2 | 1.0 | 1 Location with ready staff |
| 7 | `website.not_published:v1` | website | Website | 5 · 3 · 3 | 1.0 | always judgeable |
| 9 | `seo.technical_findings:v1` | seo | SEO | 4 if any critical else 2 · 2 · 3 | 1.0 | an audit has run |
| 10 | `seo.keywords_not_covered:v1` | seo | SEO | 3 · 2 · 3 | 0.8 | ≥1 keyword **and** a published site |
| 19 | `citations.needs_attention:v1` | seo | Local presence | 3 · 2 · 2 | 1.0 | a Location with ≥1 recorded listing |
| 20 | `citations.directories_not_checked:v1` | seo | Local presence | 2 · 1 · 2 | 1.0 | ≥1 Location |
| 17 | `reviews.no_review_link:v1` | reputation | Reviews | 3 · 2 · 1 | 1.0 | ≥1 Location |
| 18 | `reviews.no_recent_requests:v1` | reputation | Reviews | 3 · 2 · 2 | 1.0 | ≥1 Location **with a link** |
| 25 | `automations.repeated_failures:v1` | sales | Automations (not scored) | 3 · 4 · 2 | 1.0 | 5 workflow attempts |

(# = position in the original 25‑rule list.) Business-wide rules are
`website.not_published`, `seo.technical_findings` and
`automations.repeated_failures`; all others are one Opportunity **per Location**
(Location-less records collapse to a Business-wide one).

**Notes on semantics.** A deal is counted in **at most one** bucket (unanswered
takes precedence over stale; stale below the high-value threshold vs. at/above
it are separate rules) so one record is never presented as two problems.
`website.not_published` is silent for a Business whose profile already has an
external `website_url`. Citations separate **"not checked"** (no row /
`not_started`, never a mismatch) from **"needs attention"** (owner-marked
`needs_correction`, or the canonical `SeoNapComparator` reports a *Mismatch* on
a field the owner actually recorded). Reviews are workflow-only (link + request
ledger): no rating, count, sentiment, gating or reward is read or implied.

### Rules deferred because the canonical data is not on main (exact seams)

| Rule | Missing seam |
|---|---|
| 8 `website.package_out_of_sync` | The published Website snapshot carries no catalog/package prices; there is no catalog-sync state to read. (Activates with the website package-staleness branch.) |
| 11 rank 11–20, 12 meaningful rank drop | No rank-observation store on main. |
| 13 ads zero-conversion spend, 14 CPL above target, 15 budget over-pacing, 16 search-term waste | No Google Ads module/facts on main. |
| Search Console CTR | No Search Console data on main. |
| Slow response trend / first-response time | Not implemented: needs per-conversation first-inbound/first-outbound pairing; the awaiting-reply rule is. |
| Forms rules | No canonical relation proves a form's intent or its follow-up automation; titles are never used. |
| Booking funnel drop-off, richer readiness | Needs `agent/public-booking-experience-v1` / `agent/booking-type-settings-v1` (view/start instrumentation; buffers, notice windows). |
| "Eligible customer not asked for a review" | No deterministic eligibility on main. |

They are **not** faked and **no customer-facing "Connect Ads" opportunity is
created**: the readers for those domains report `unavailable`
(`GrowthUnavailableFactReader`), which excludes their rules and score category.

## 4. Opportunity lifecycle

All lifecycle is the engine's (`OpportunityManager`); Growth adds nothing to it
except the cooldown above.

* **Dedupe.** One row per `(business, worker, type, context)` fingerprint.
  Re-evaluation updates evidence/scoring on the same row.
* **Resolve.** Evidence disappears → the next successful run marks it
  `freshness = stale`; the owner sees **Resolved** ("no longer detected"). A
  button click **never** resolves anything (`go` writes nothing).
* **Reopen.** Condition returns → the same row is re-confirmed (`current`).
* **Dismiss** (optional closed reason) → returns as a new occurrence after
  `growth.thresholds.dismiss_cooldown_days` (30) if still true.
* **Snooze** (tomorrow / 3 days / 1 week / custom date ≤ 1 year) → the engine's
  `opportunity:sweep-expired-snoozes` reopens it at wake time; the next
  evaluation decides, by evidence, whether it still counts.
* **In progress** is wired (engine state) but unused in V1 because every V1
  action is a hand-off with no execution record (§10).
* **Expired** is represented by `stale`/Resolved.

Owner-facing state mapping: `GrowthOpportunityReader::applyState` /
`GrowthOpportunityPresenter::state`.

## 5. Evidence schema

One evidence fact per Opportunity, `source_type` = fact domain,
`source_identifier` = `business:<id>[:location:<id>]`, `fact_key` per rule, and a
**closed** `observed_value`, e.g.

```json
{ "count": 3, "value_minor": 210000, "currency": "USD",
  "uids": ["…","…","…"], "threshold_hours": 24 }
```

Bounded (≤ 10 uids, ≤ 4 KB), PII-free (no names/phones: names are looked up at
**view** time, ACL-scoped, by `GrowthAffectedRecords`), no HTML. Money appears
**only** from canonical rows and **only** in a single currency (mixed-currency
buckets report no value). Summaries are fixed registry strings; the one-line
"headline" is rendered from the stored numbers by the rule's own `headline()`.

## 6. Priority (deterministic; the engine's `OpportunityScorer`, version 1)

```
priority = 35·(impact/5) + 20·(urgency/5) + 15·(goal_relevance/5)
         + 15·confidence + 10·(evidence_freshness/5) + 5·((5 − effort)/5)
```

| Requested factor | Where it comes from |
|---|---|
| impact | rule impact 0–5; raised to 5 only when a **canonical** value reaches the high-value threshold (never estimated) |
| confidence | rule confidence (1.0 direct fact · 0.8 strong inference · 0.6 limited) → *High confidence / Moderate confidence / Limited data* |
| urgency | rule urgency (age/overdue/failed) |
| actionability | `5 − effort` term |
| recency | evidence freshness rank (the evidence is read now) |
| goal fit | owner's onboarding goals vs the rule's `goalKeys` |

AI never re-ranks. Impact words: **High** ≥ 4, **Medium** 3, **Low** ≤ 2.

## 7. Growth Score (algorithm version 1)

`GrowthScoreCalculator` — a pure function of rule outcomes (no AI, no DB).

```
rule health   Passing = 1.0
              Finding = max(0, 1 − impact/5 × confidence)   (worst finding per rule)
              Not applicable / Insufficient = EXCLUDED (never 0, never 100)
category      round(100 × Σ weight·health / Σ weight) over its scored rules;
              no scored rule → "Not enough data"
overall       round(mean of the scored categories); null if none;
              always shown as "Based on N of 9 categories"
```

Nine categories: lead generation, lead response, sales conversion (pipeline +
proposals + payments), booking readiness, website, SEO, ads, reviews &
reputation, local presence. **On this main**, lead generation and ads have no
rules (so "Not enough data"), forms/automations never feed the score. The Score
page prints the formula, the per-rule status/weight/health, the thresholds and
the movement contributors ("+4 lead response …"). The score is hidden from a
**Location-restricted** actor (it is a Business-wide aggregate).

**Versioning.** `growth_score_snapshots.algorithm_version` is part of the unique
key; a new formula writes rows beside the old ones and **old rows are never
recomputed**; movement is only computed between snapshots of the same version.
Retention ≥ 13 months (`growth.score.retention_days`, floor 400 days), pruned by
the daily sweep and the recorder. Each snapshot also stores per-rule status, the
bounded "what's working" facts, the new-lead period figures and which domains
were unavailable.

## 8. Fact readers (domain snapshots, not cross-domain queries)

Each reader runs its bounded queries **once per evaluation**; rules never query
(`test_evaluating_the_rules_runs_no_query_at_all`). Total queries per full
evaluation are independent of deal/conversation/document/Location counts.

| Reader | Domain | Reads | Entitlement |
|---|---|---|---|
| `GrowthCrmFactReader` | `crm` | open `crm_opportunities` (+ last history), 7-day new-lead windows | `crm` |
| `GrowthConversationFactReader` | `conversations` | `chat_boxes` ⨝ `chat_box_messages` (direction + `send_status`) | `conversations` |
| `GrowthBookingFactReader` | `booking` | booking types, staff, availability rules, next-7-day appointments | `calendar` |
| `GrowthWebsiteFactReader` | `website` | `websites` | `website_generation` |
| `GrowthSeoFactReader` | `seo` | `SeoKeywordCoverageReader`, `SeoPublishedContentReader`, `SeoAuditPageReader` | `seo_module` |
| `GrowthReputationFactReader` | `reviews` | review links + request ledger | `seo_module` |
| `GrowthCitationFactReader` | `citations` | directories + citations via `SeoNapComparator` | `seo_module` |
| `GrowthDocumentFactReader` | `documents` | documents, current-version schedule items, failed payments | `payments_contracts` |
| `GrowthAutomationFactReader` | `automations` | failed `automation_step_runs` / `automation_executions` (7 d) | `automations` |
| `GrowthUnavailableFactReader` | `ads`, `rank`, `search_console` | nothing | — |

Reader state: `available` · `unavailable` (module not on this platform) ·
`not_connected` · `not_entitled` — **never zero**. A reader that **throws** is a
*failure*, not "unavailable": the workers that read it fail their run (visible
to the Platform Owner) and existing Opportunities are untouched.

**What the conversation facts can prove:** a customer message and its time
(`direction = incoming`), a Business reply and its time (`outgoing` whose
`send_status` is NULL / sending / sent / delivered — a *failed* send is not a
reply), and ownership (`business_id`, `location_id`). Rows with a NULL
direction are ignored. Known limit: a courtesy ("thanks") or opt-out ("STOP")
still counts as awaiting — there is no canonical "needs a reply" flag.

## 9. Thresholds

All in `config/growth.php`, read **only** through `GrowthThresholds` (each
value clamped): unanswered lead 24 h · stale deal 7 d · high-value deal 50 000
minor · conversation awaiting 24 h (30-day lookback) · low availability 240 open
minutes in 7 d · proposal unsigned 3 d · signed unpaid 3 d · failed-payment
lookback 14 d · review-request window 30 d · 5 priority directories · dismiss
cooldown 30 d · min sample 8 · automation failures 3. No per-Business override
in V1.

## 10. Actions and safety classes

Every V1 action is a **hand-off** into the owning module's own screen (CRM board,
Conversations, Calendar/booking types, Website, SEO keywords/audit/reviews/
citations, Documents, Automations) via the closed `GrowthNavigation` map. The
Growth Center **never duplicates a module write**. Registered in
`OpportunityActionRegistry` as non-executable navigation actions
(`mutates_business_data = false`, `paid_effect = false`,
`approval_required = false`, no handler) — the engine can never "execute" one
(tested).

`GrowthActionSafetyClass` — `read_only` · `safe_local_write` · `external_message`
· `external_provider_mutation` · `financial` · `publication`. The **class**
decides confirmation (`requiresConfirmation()`; the last four are
`alwaysExplicit()` — never pre-approved, batched or AI-run). All V1 actions are
`read_only`. A secondary hand-off ("Build a follow-up automation") is offered for
the lead-response findings; the automation is never created or published for
the owner. `opportunities/{uid}/go` records a structured `growth.action_opened`
log line (acted-upon telemetry) and writes nothing else. "Did this help?"
outcome measurement is **deferred**: it needs a durable record of when an owner
acted, which V1 hand-offs do not create.

Executable one-click fixes (start an existing automation for selected contacts,
add a negative keyword, publish a synchronised package) are **deferred** with
their modules: each needs its module's service seam plus an approval/cost path
through the existing action executor.

## 11. Evaluation and scheduling

`GrowthEvaluationService`: build one fact snapshot → evaluate every rule →
one engine run per worker (sales, website, seo, reputation) → record the score.
**Isolation:** a worker whose reader failed *fails its run* and writes nothing;
other workers proceed; no score is written from a half-read evaluation. It honours
`opportunity.enabled` and requires the AI COO entitlement the engine already
sits behind.

* **Daily sweep:** `growth:evaluate` (03:40, after the Business Advisor sweep) —
  keyset-paged, bounded `--limit/--page`, idempotent per Business per day via the
  engine's `opportunity_producer_dispatches` (claim identity: the `sales` worker
  key). Also prunes old score snapshots.
* **Event-driven (debounced 15 min):** `DocumentPaymentFailed`,
  `DocumentFullyPaid`, `WebsitePublished`, `CrmOpportunityWon/Lost` — existing
  events only; **no new event vocabulary**. A burst costs one evaluation.
* **Manual:** "Check again" (throttled 6/min) queues a `RunGrowthEvaluation`.

## 12. AI Advisor and context policy

Questions are a **closed list** (not free text): what to do today · which issue
first · what changed · why leads are down · where am I wasting money · how to get
5 more bookings. **Layer 1 (always)** composes the whole answer
deterministically from the open Opportunities in engine order
(Today / This week / Later; every item *is* an Opportunity with a link). **Layer
2 (optional AI)** may only rewrite the lead sentence and add one short note per
planned item, via the existing `AiGateway` (`coo_interactive`, interactive lane,
entitlement/budget/ledger enforced there).

`GrowthAdvisorContext` is all the AI sees: score + category numbers, ≤ 12
open Opportunities as fixed headline + figures (**no names, deal titles, phone
numbers or real uids** — items are handles `o1…`), stored change/positive lines,
and which modules are unavailable. Opportunities whose rule reads a
provider domain (`ads`, `search_console`, `gbp`, `rank`) are **dropped before
the AI sees anything — today and after those modules merge**. The reply is parsed
as strict JSON and **rejected whole** if it names an item outside the plan,
contains markup/links/extra keys, is too long, or states **any number not in the
digest**; the deterministic answer then stands. The page says whether AI was
used, disabled, refused or rejected.

## 13. Provider-cost boundary

Growth evaluation reads platform tables and cached module facts only. It never
calls DataForSEO, Google Ads, Search Console, Business Profile or any paid
provider, and never an AI (`GrowthViewAsAndAdvisorTest` proves this at run time
and by scanning `app/Library/Growth`). When Ads / rank / Search Console merge,
their **normalized cached** facts are read through their reader, not their
providers.

## 14. ACL, tenancy, View As, Agency

Gate chain (404 on failure): Workspace → Business → accessible → Active → AI COO
entitlement → `business_advisor` capability. **Location:** `GrowthViewer`
(`LocationAccessGuard` bulk method) is resolved once; `GrowthOpportunityReader`
applies `location_id IS NULL OR location_id IN (accessible)` **in SQL before any
list, count or sum** (header counts, tab count, tiles, money). Direct-uid access
to an inaccessible Location's Opportunity is 404 (show/dismiss/snooze/go). A
Selected-Location actor never sees the Business-wide score, categories,
positives or changes. **View As** shows the viewed client's own Growth Center;
actions are attributed to the real actor and the server-side session. An
**Agency** sees its own Business normally; another client's Opportunity is
unreachable through the viewed one. No cross-client Agency score (deferred).

## 15. Surface

Sidebar **Growth** (one item; "Opportunities" stays the CRM board). Tabs:
**Overview** (score ring + "based on N of 9", movement, tiles, "What should I do
today?", what's working, recent changes, nine category cards) · **Opportunities**
(All open / High impact / New / In progress / Snoozed / Resolved / Dismissed,
search, category, source, location) · **Opportunity detail** (why, evidence,
affected records, what to do, what to expect, history, snooze/dismiss/reopen) ·
**Score** (formula, per-rule table, thresholds) · **Insights** (Daily Brief,
working, changed) · **Advisor**. States: first run explains what Growth can
learn from; a healthy Business sees "You're in good shape"; engine off says so.
Daily Brief is in-app only and is the same canonical set presented as a brief
(no external digest in V1). Mobile: cards, no horizontal overflow (checked on all
six pages at 375 px).

## 16. Platform Owner / telemetry

The existing admin Opportunity run/opportunity views list the Growth workers and
types unchanged (tested). Telemetry is structured log lines
(`growth.action_opened`, `growth.opportunity_snoozed`,
`growth.opportunity_dismissed`) plus the engine's own domain events; no external
vendor.

## 17. Deferred / future integrations required

Growth funnel view and **leak detection** (lead → contacted → booked → proposal → signed → paid
conversion drop-offs: no canonical attribution/visitor instrumentation, and the
sample-size and baseline rules in the brief need a period-over-period history the
snapshots only just begin to accumulate) · attribution ("Ads campaign → leads →
bookings → $") · Home "Top 3 Growth opportunities" (Home lane not stacked; reader is ready) ·
AI COO next-best-move consuming Growth Opportunities (needs a Location-ACL-aware
seam in `CooInsightFactsReader`; today the COO is intentionally isolated from
Growth rows) · sidebar badge · email/SMS digest · executable one-click fixes ·
"Did this help?" outcome tracking · Ads rules · rank rules · Search Console rules
· package-sync rule · slow-response trend · forms rules · per-Business thresholds
and category preferences · cross-client Agency Portfolio score · competitor
intelligence, causal attribution, forecasting.

**Branches whose merge activates deferred rules:** the Ads module lane
(`agent/ads-visibility-v1-completion` is audit-only), a rank-tracking lane,
Search Console, `agent/public-booking-experience-v1`,
`agent/booking-type-settings-v1`, the website package-staleness branch (Website
onboarding smart inputs lane), Business Home lanes.

## 18. Tests

`tests/Unit/Growth` (rules, score calculator, advisor) and `tests/Feature/Growth`
(engine integration, fact readers incl. query budgets, HTTP/ACL, evaluation
lifecycle, View As/Agency/Advisor/provider-cost, actions + admin). Existing
engine suites (`tests/Feature/Opportunity`, `tests/Unit/Opportunity`) pass except
the failures that are **identical on pristine `origin/main`** (listed in the lane
report).
