# Implementation Contract 23 — SEO KEYWORD RANK TRACKING V1

Status: implemented on `agent/seo-keyword-rank-tracking-v1` (no PR, no merge).
Extends Contract 18 (SEO Expansion, Sub-slice D keywords). Preserves all of it.

This is a **real paid-provider integration**. Cost containment and idempotency are
first-class requirements, not afterthoughts: no provider call can happen unless
one central budget authority has just reserved the spend, and every retry path is
designed so a retry cannot buy the same answer twice.

---------------------------------------------------------------------------

## 1. Concepts: SeoKeyword vs SeoRankTarget vs observations

| Concept | Table | Meaning |
|---|---|---|
| `SeoKeyword` | `seo_keywords` (Contract 18) | What the Business wants to rank for. Max 50 active per Business. Used for planning, Website coverage and (later) Search Console matching. **Never** holds a third-party observation. |
| `SeoRankTarget` | `seo_rank_targets` | Keyword + provider search geography + device. The **unit of paid tracking**. |
| `SeoRankCheckRun` | `seo_rank_check_runs` | One provider task (organic *or* local) for one target: the idempotency and state anchor. |
| `SeoRankObservation` | `seo_rank_observations` | One normalized fact per completed run. Append-only history. |
| `SeoRankLocation` | `seo_rank_locations` | Local cache of the provider's free location catalogue. |
| provider ledger | `seo_rank_provider_ledger` | Internal **platform cost** ledger. |

The 50 SEO keywords are **not** all provider-checked. A *tracked target* is
keyword + search geography + device: "photo booth rental / Chicago / mobile" and
"photo booth rental / Naperville / mobile" are **two** tracked targets and consume
two slots. The Location attribution of a target is its keyword's; there is no
second Location column. **A search geography is never a BusinessLocation** and no
BusinessLocation is ever created for one.

Stopping tracking sets `tracking_state = stopped`: history is kept, future paid
calls stop, the slot is freed. Restarting flips the same row back (same uid, no
duplicated keyword or target) and history resumes.

## 2. Provider boundary

`App\Library\Seo\Rank\Provider\SeoRankProvider` is the only seam:
`submit()`, `fetch()`, `readyTasksByTag()` (recovery only) and `locations()`
(free catalogue). Controllers, jobs and the budget never reference a vendor class.
Implementations: `DataForSeoRankProvider` (production) and `FakeSeoRankProvider`
(all automated tests; **no paid or any network request exists in the test suite**
— `tests/TestCase.php` also forbids stray HTTP).

Secrets live only in environment/config (`DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`,
`DATAFORSEO_BASE_URL`; see `config/seo.php` `rank_tracking.dataforseo`). Provider
errors are reduced to a closed code vocabulary (`SeoRankProviderException`); bodies,
URLs and credentials never reach logs, the database or a customer.

## 3. DataForSEO first implementation — endpoints actually used

Verified against `docs.dataforseo.com/v3` at implementation time (2026-10-04).

| Purpose | Endpoint | Cost |
|---|---|---|
| Organic submit | `POST /v3/serp/google/organic/task_post` | $0.0006 per 10 results (Standard) |
| Organic collect | `GET /v3/serp/google/organic/task_get/regular/{id}` | free |
| Local submit | `POST /v3/serp/google/local_finder/task_post` | $0.0006 per 10-result page (Standard) |
| Local collect | `GET /v3/serp/google/local_finder/task_get/advanced/{id}` | free |
| Recovery | `GET /v3/serp/google/{organic\|local_finder}/tasks_ready` | free, 20 calls/min |
| Locations | `GET /v3/serp/google/locations/us` | free |

Request: `priority: 1` (normal/Standard queue), `device: mobile`, `depth` (organic ≤ 100,
local = 10), `tag` = our run uid. **Never sent:** live endpoints, `priority: 2`,
`postback_url`/`pingback_url`, AI-overview or pixel options, `max_crawl_pages`.
Keywords containing search operators (quotes, `site:`, `-word`, `OR`) are refused
locally before any HTTP call because the vendor bills operators at 5×.

Collection uses task-get on the stored task id (free). Callbacks/postbacks were
inspected and **not** used: they would require a public unauthenticated-by-default
endpoint; polling is free and enough for this volume. `tasks_ready` is used only to
recover a run whose submit outcome was ambiguous.

Result structure used: organic items `type: organic` with `rank_group`, `domain`,
`url`; local items `type: local_pack` with `rank_group`, `domain`, `url`, `phone`,
`cid`. Status codes: `20000` ok, `20100` task created, `40602`/`40601` still queued
(pending), `40102` no results (completed, empty). Provider-reported `cost` (USD) is
converted to integer micro-USD.

**Payload shapes (per current official docs).** `tasks_ready` rows carry `tag` as a
**top-level** field of each `tasks[0].result[]` row (not under `data`); the same holds
for the `local_finder/tasks_ready` endpoint. Local Finder items are `type: local_pack`
with `rank_group`, `rank_absolute`, `title`, `domain`, `phone`, `url`, `cid` and `rating`;
there is no `place_id` or `feature_id`. These shapes are pinned by sanitized regression
fixtures (`SeoRankClosureTest`). They have NOT yet been confirmed against a live
response — see §17 for the live acceptance gate.

## 4. Cost model and ceilings

Provider cost **facts** are config, not business logic (`seo.rank_tracking.cost_per_page_micros`,
default 600). One check = organic (depth 100 → 10 pages → 6 000 µUSD) + local (depth
10 → 1 page → 600 µUSD) = **6 600 µUSD ($0.0066)**. The estimate is reserved; the
provider-reported cost replaces it when known.

Money everywhere is integer **micro-USD** (1 USD = 1 000 000).

## 5. Tracked-target limits and plan tiers

Numeric limits are in `config/seo.php` read through bounded `SeoConfig::rankTier()`
accessors; the entitlement is the dedicated `PlatformFeature::SeoRankTracking`
(packaged Core, Growth, Agency; `Available`). `SeoRankEntitlement` is the **only**
place that maps a Business to numbers — there are no plan-name checks in SEO code.

| Tier | Tracked targets | Automatic cadence | Device | Manual paid refresh | Business hard spend cap |
|---|---|---|---|---|---|
| Trial (`workspace_plan_assignments.trial_ends_at` set) | 5 | every 3 days | mobile | 1 per target / 24 h | $0.50 for the trial window |
| Core | 5 | daily | mobile | 1 per target / 24 h | $1.50 / calendar month |
| Growth | 20 | daily | mobile | 1 per target / 24 h | $4.50 / calendar month |
| Agency | each **client Business follows its own Workspace's plan** (an Agency-tier Workspace itself uses Growth limits) | per that plan | mobile | per that plan | per that plan **plus** an Agency/Workspace aggregate cap |

"Unlimited locations/clients" never implies unlimited paid queries: every client's
spend also counts against the Agency aggregate (`rankWorkspaceMonthlyCapMicros`,
default $25/month) which is owned by the **Agency Workspace** (resolved through an
active `agency_client_workspace_relationships` row, else the Business's own Workspace).

Trial conversion keeps keyword definitions, targets and history; the Business
simply adopts the paid tier's limits and cadence on the next decision. A trial's
spend window reaches back 90 days from `trial_ends_at` (cap = lifetime-in-trial).

The 50-active-keyword technical ceiling is unchanged and independent of the tracked
allowance. If all slots are used, adding a keyword still succeeds (saved as an SEO
keyword, rank tracking off) with "N of N rank-tracked keywords are in use"; stopping
another target frees a slot.

## 6. The budget authority — `SeoRankTrackingBudget`

The only creator of a run's ledger reservation and the only writer of
`seo_rank_provider_ledger`. `reserveRun()` runs in one transaction under a row lock
on the Business, inside a short cache lock that serializes the cross-Business sums,
and performs **no network I/O**. It fails closed in this order:

1. master switch (`seo.rank_tracking.enabled`, default **off**);
2. idempotency key already used → `already_scheduled`, no new reservation;
3. target still `tracking` and its keyword active;
4. entitlement present;
5. slot allowance (targets beyond the plan after a downgrade are never checked);
6. no open run for this target and check type (`pending`);
7. manual only: a completed observation < cooldown → `recent_result` (shown, not re-bought); a prior manual run < cooldown → `cooldown`;
8. Business cap → 9. Workspace/Agency aggregate cap → 10. global daily cap → 11. global monthly cap.

A ledger row counts as `COALESCE(actual, reserved)`; `released` counts 0; `held` keeps
counting. Caps count rows (not runs), so retries and manual refreshes cannot bypass
accounting. When refused, **no provider call is made** and the UI says "Rank checks
paused until your usage period resets."

Global config guards (all lower-only, fail-closed, hard documented maxima; a malformed
value yields the default and can never switch a guard off or raise it):
`enabled`, `workspace_monthly_cap_micros` ($25), `global_daily_cap_micros` ($10),
`global_monthly_cap_micros` ($150).

## 7. Async Standard-queue pipeline and idempotency

```
ScheduleSeoRankChecks (hourly)  → planner → budget->reserveRun()      (DB only)
SubmitSeoRankCheck(run)         → executor->submit()                  (network outside any txn)
ProcessSeoRankChecks (5 min)    → hold crashed, reconcile held, re-queue due submits, poll due
PollSeoRankCheck(run)           → executor->poll() → recorder         (free fetch by stored task id)
PruneSeoRankObservations (daily)
```

Run states: `scheduled → submitting → submitted → completed | failed_terminal`, or
`held`. Idempotency key = `target:check_type:period` where scheduled period is the
cadence bucket (`s<floor(utcDays/cadenceDays)>`) and manual is `m<utcDay>`; it is
`UNIQUE`, so a duplicate scheduler tick, retried job or double click can never create
a second paid task for the period. Targets are staggered deterministically
(`crc32(uid) % cadenceWindow`) so midnight does not spike. Queue jobs use `$tries = 1`.

- **Rejected** (explicit vendor refusal; nothing created): bounded retry (3) with the
  *same* reservation and backoff, then the reservation is released.
- **Ambiguous** (timeout, 5xx, unreadable): the run is **held**; the reservation keeps
  counting; nothing is resubmitted. The 5-minute sweep tries to recover the task by our
  run-uid tag via `tasks_ready`; otherwise after `max_poll_hours` (24) it is closed
  with its cost left counted.
- A crash between claim and answer is treated as ambiguous (held after 15 min).
- The task id is stored the moment the provider returns it; polling never submits.
  A pending/failed poll only waits (5–60 min backoff, 24 h ceiling → `poll_timeout`).
- Stopping tracking or switching the provider off before submit cancels the run and
  releases its reservation.
- No local identity = no spend: if no canonical domain exists the organic call is
  skipped; if no domain, phone or CID exists the local call is skipped.

## 8. Matching our Business

Organic: the Business's canonical domain is the **active primary `WebsiteDomain`** of
its Website (`Website::activePrimaryDomain()`); scheme, credentials, port and leading
`www.` are normalized away. The first (lowest-position) result within depth whose host
equals it matches; sub-pages match; a sibling subdomain or look-alike host does not;
title text is never used. No match within depth → `not_found`, `position = NULL`
("Not in top 100"). We never store or display #101 or 0.

Local: identity precedence is CID (reserved, no seam supplies one today) → exact
canonical domain → exact normalized canonical phone (`businesses.phone`; 11-digit US
numbers drop the leading 1; < 10 digits is not an identity). Our identity absent →
`not_matched` (distinct from `not_found` = identity known, listing not returned within
depth 10). The Business name is **never** compared. Adding CID/Place identity later
changes `SeoRankIdentity` only, not the schema.

## 9. Search geography

One provider location per target, English, mobile only (the schema carries `device`
for a later desktop). V1 supports United States cities. A typed string is never sent
to the provider: the picker queries `seo_rank_locations`, the form submits the chosen
**provider location code**, and the manager re-resolves it (unknown/unsupported →
refused before any write). The cache is filled by `php artisan seo:rank-sync-locations`
(free endpoint, weekly schedule); it does not require the paid switch.

## 10. History, change and best

Current = latest completed observation of a check type; previous = the one before it.
Rank 1 is best: previous 12 → current 7 = **↑ 5**; previous 5 → current 9 = **↓ 4**.
Found after not-found = "Newly ranking"; not-found after found = "Dropped out"; any
other involvement of `not_matched`/null = no change shown. Null is never turned into 0
or 101. Best = the lowest **found** position, organic and local separately. History is
append-only. The chart is plain inline SVG: rank 1 at the top, lines join only
consecutive found checks, not-found checks are hollow baseline markers, nothing is
interpolated.

## 11. Search Console

No Search Console integration exists in this codebase (Contract 18 Sub-slice C is not
built). `SeoSearchConsoleReader` is the seam (bound to a null implementation); when a
real reader returns clicks/impressions/average position/as-of date the keyword detail
shows them in a **separate, labelled block**. Search Console average position is a
different fact from a provider rank and is never blended, averaged or stored as one.
Rank tracking works with or without it.

## 12. Website coverage

`SeoKeywordCoverageReader` is unchanged. The table shows Covered / Missing / No
published website with the "Found in" title/description/body detail; coverage is
independent of rank and untracked keywords still show it.

## 13. UI

`Search keywords` is a rank-tracking dashboard: summary cards (Tracked n / limit,
Average organic position, Top 10, Improved, Local top 3 — real data or "—", never a
fake 0), an add-keyword card (keyword, scope, "Track Google rank", search-location
picker), and a responsive table (cards on mobile): Keyword, Search location, Organic,
Local, Change, Website, Last checked, Actions. States: `#4`, `Not in top 100`,
`Local #2`, `Not matched`, `Waiting for first check`, `Checking…`, `Paused`,
`Budget paused`, `Temporarily unavailable`. "SEO target" and "Rank tracked" are
distinguished. A row opens the detail page (current/previous/best, first tracked, last
checked, location, device, two history charts, coverage, Search Console block when
available, recent checks). Provider errors and secrets are never shown; the last
successful observation stays visible with its timestamp.

## 14. Retention

13 months of observations (`rank_tracking.retention_months`, lower-only), pruned daily
in bounded batches. Full provider payloads are never stored. The cost ledger is
platform spend history and is kept.

## 15. Security and tenancy

Chain: Workspace → Business → `userCanAccessBusiness` → active Business →
`SeoRankTracking` entitlement (404) → `view_seo` / `manage_seo`. Targets are resolved
only by uid inside the Business and behind the keyword's Location ACL
(`SeoKeywordManager::findAccessible`); forged, foreign and inaccessible ids are the
same 404. The manager re-derives authority under a Business row lock; two simultaneous
starts cannot both take the last slot. View As follows the existing boundaries.
Provider credentials and task ids never leave the server.

Known, accepted aggregates: the "Tracked n / limit" card counts the Business-wide
allowance (it is a commercial limit, not Location data), so a Selected-Location member
can infer how many slots exist in total; their rows, averages and uids stay filtered.
A Business that loses the entitlement keeps its stored results visible read-only (no
controls, rank routes 404).

## 16. Operator visibility

`GET /admin/seo-rank-cost` (platform admin only, read-only) answers "how much did rank
tracking cost this month?": month and day totals vs caps, by operation, status,
Workspace/Agency and Business, from the ledger.

## 16a. Production activation (deployment state)

Paid tracking is **fail-closed and OFF by default**. It runs only when BOTH hold:

1. `SEO_RANK_TRACKING_ENABLED=true` (master switch; anything other than a literal
   true/1 is off), and
2. the provider holds credentials: `DATAFORSEO_LOGIN` (the DataForSEO account login,
   normally the account email) and `DATAFORSEO_PASSWORD` (the **API password**, which is
   generated in the DataForSEO dashboard under *API Access* — it is NOT the website
   login password). `DATAFORSEO_BASE_URL` defaults to `https://api.dataforseo.com`.

`SeoRankTrackingBudget::providerState()` reports `enabled`, `disabled` (switch off) or
`not_configured` (switch on, credentials missing). Anything but `enabled` means: no
reservation, no submit, no provider call; existing history stays readable; the customer
sees "Rank checks are not available right now" (never a spend-pause message and never a
provider detail); the Platform Owner page shows `Provider: Disabled` (with "credentials
not configured" when applicable). No secret is ever displayed.

Activation checklist: set the three env values; run `php artisan config:clear`; run
`php artisan seo:rank-sync-locations` once (free endpoint) so the city picker has
locations; ensure the scheduler (`schedule:run` every minute) and a queue worker run; open
`/admin/seo-rank-cost` and confirm `Provider: Enabled`. To use the DataForSEO **Sandbox**
(free, dummy results, identical response structure, same credentials) set
`DATAFORSEO_BASE_URL=https://sandbox.dataforseo.com`. Sandbox proves integration shape
only; ranks it returns are not real.

Agency aggregate cap: the default $25/month (`rankWorkspaceMonthlyCapMicros`) is an
**operator safety ceiling**, not a commercial promise of $25 of usage and not a price.
It can only be lowered by config. V1 does not redesign billing around it.

## 17. Verification status and deferred work

Automated: fake provider only. Live acceptance (sandbox first, then the minimum paid
requests: one "photo booth rental" check in one US city) requires `DATAFORSEO_*`
credentials and is recorded in the lane report; if credentials are absent it is
**pending**, never assumed.

Worst-case provider spend (checks of 6 600 µUSD, daily = 30/31-day month): Trial
≈ $0.33 at cadence (hard cap $0.50); Core ≈ $0.99–$1.02 (hard cap $1.50); Growth ≈
$3.96–$4.09 (hard cap $4.50); every Agency client is bounded by its own plan cap and
all clients together by the Agency aggregate (default $25/month); the platform by
$10/day and $150/month.

Deferred (explicitly not built): geo-grid/local heatmaps, competitor tracking, backlink
checking, AI Overview tracking, simultaneous desktop+mobile, Bing, YouTube, keyword
research/search volume/CPC, unlimited manual refresh, postback/pingback callbacks,
CID/Place-ID identity, billing-period (rather than calendar-month) caps, customer
charging for rank tracking, automatic stop of targets when a keyword is archived
(archived keywords are simply never scheduled and free their slot).
