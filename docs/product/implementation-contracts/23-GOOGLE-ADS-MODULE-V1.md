# 23 — Google Ads Module V1 contract

Status: implemented in lane `agent/google-ads-module-v1` (base `origin/main` 6ac3e19c).
Product goal: **"See where your ad budget is turning into leads — and what is wasting money."**

This document is the contract for the lane. It records decisions, the provider
facts that were verified from Google's official sources, the data model, the
rules, and everything deliberately deferred.

---

## 1. Decisions (owner-approved)

| # | Decision |
|---|---|
| D1 | Build against a `Fake` client for tests and browser acceptance. Live provider acceptance is **pending** unless real credentials exist. The real HTTP client is coded only from the verified facts in §2. |
| D2 | Google Ads reuses the canonical `business_google_connections` token authority via a new `GoogleConnectionProduct::GoogleAds` (`google_ads`) case. Ads-specific manager / OAuth config / client classes are added. The shipped GBP manager is **not** generalised or refactored. |
| D3 | Core (`ads_basic_visibility`): connect, read-only Overview, Settings. Growth/Agency (`google_ads_module`): full module + approved safe mutations. Gating is entitlement-driven (`EntitlementManager` / `MenuEntitlements`), never plan-name checks. |
| D4 | A minimal canonical, append-only attribution foundation is built now (click ids + UTM + landing page + entry surface). First-touch is preserved; the model supports last-touch / multi-event history without destructive replacement. |
| D5 | **No Ads provider data goes to AI in V1.** Recommendations are deterministic. (Consistent with the Search Console "never to AI, never used for ads" precedent.) |
| D6 | Offline conversion upload is **deferred**; the click-id foundation is built so it needs no attribution redesign. |
| D7 | No standalone recommendation table or lifecycle. Google Ads owns deterministic recommendation **facts** (`GoogleAdsRecommendationFactReader`). The RFC-002 Opportunity Engine (Growth Center) owns lifecycle / snooze / dismiss / priority / actions / presentation. Growth Center is not in this base, so §12 documents the exact later integration. |
| D8 | Mutations reuse the product-neutral `business_google_operations` ledger for durable operation key, status, ambiguity (`unknown`), deferral and actor. A thin 1:1 detail table (`google_ads_mutations`) holds only Ads-domain fields (target, requested state, parameters, dedupe key) that cannot cleanly live in the ledger. It stores **no** status or idempotency truth of its own. |

## 2. Provider authority (verified, Google Ads API **v25**)

Verified 2026-10-04 from Google's official documentation and the
`googleapis/googleapis` v25 proto definitions. Anything not listed here is not
used.

* **API version**: current is **v25** (released 2026-07-22). Pinned in `config/google_ads.php` (`api_version`), never hard-coded in clients.
* **Transport**: REST/JSON. Base `https://googleads.googleapis.com/{version}/`. (No PHP SDK is installed; no gRPC extension is required.) JSON identifiers are lowerCamelCase; GAQL is snake_case.
* **OAuth scope**: `https://www.googleapis.com/auth/adwords` (sensitive scope — production use requires Google OAuth verification, an external gate). Token exchange uses the same `https://oauth2.googleapis.com/token` endpoint pattern as the GBP client. A dedicated OAuth client is configured (`services.google_ads`), never the Socialite or GBP client.
* **Developer token**: Google's developer-token policy page states developer tokens were **sunset on 2026-09-09**; access levels now derive from the Google Cloud project that owns the OAuth client; the header is "optional and ignored" and will be rejected in a future major version. An older REST-auth page still describes the header as required. **Decision:** the header is optional and config-driven (`GOOGLE_ADS_DEVELOPER_TOKEN`); it is sent only if configured. Brand verification is required for new Basic/Standard access. Access levels: Test, Explorer (2,880 ops/day prod), Basic (15,000), Standard (unlimited). Test-level access works against test accounts only.
* **Headers**: `Authorization: Bearer <access token>`; `login-customer-id` (digits only, no hyphens) is **required whenever access to the operating customer is through a manager account** (omitting it yields `USER_PERMISSION_DENIED`); `developer-token` only if configured. Responses carry `request-id` (logged, never a secret).
* **Endpoints**
  * `GET /{v}/customers:listAccessibleCustomers` → `{ "resourceNames": ["customers/123…"] }`.
  * `POST /{v}/customers/{customerId}/googleAds:search` body `{ "query": "...", "pageToken": "..." }` → `{ "results": [...], "nextPageToken": "...", "fieldMask": "..." }`; fixed pages of 10,000 rows. Each search request counts as one operation against quota.
  * Mutates `POST /{v}/customers/{customerId}/{resource}:mutate` with `{ "operations": [ { "update": {...}, "updateMask": "status" } | { "create": {...} } ], "validateOnly": false, "partialFailure": false }` → `{ "results": [ { "resourceName": "…" } ] }`. A mutate targets exactly one customer id.
  * Resources used: `campaigns:mutate`, `adGroupCriteria:mutate`, `campaignCriteria:mutate`.
* **Resource facts used** (all verified in v25 protos)
  * `Customer`: `id`, `descriptive_name`, `currency_code`, `time_zone`, `manager`, `test_account`, `status`.
  * `CustomerClient` (manager hierarchy): `id`, `level` (0 = self), `manager`, `descriptive_name`, `currency_code`, `time_zone`, `status`, `test_account`, `hidden`, `client_customer`.
  * `Campaign`: `id`, `name`, `status` (`ENABLED|PAUSED|REMOVED`), `serving_status`, `primary_status`, `advertising_channel_type`, `campaign_budget`, `bidding_strategy_type` (output-only, display fact only), `resource_name`.
  * `CampaignBudget`: `id`, `name`, `amount_micros` (daily when period is DAILY), `explicitly_shared` (a shared budget can span campaigns), `status`, `period`, `delivery_method`. **Campaign daily budget ≠ Business monthly target.**
  * `AdGroupCriterion`: `criterion_id`, `status` (`ENABLED|PAUSED|REMOVED`), `negative` (**immutable**: switching requires remove + re-add), `keyword.text` (≤ 80 chars, ≤ 10 words), `keyword.match_type` (`EXACT|PHRASE|BROAD`), `quality_info.quality_score`, `ad_group`, resource name `customers/{c}/adGroupCriteria/{adGroupId}~{criterionId}`. `ad_group_criterion` is a resource **without metrics**: keyword metrics are read from `keyword_view`.
  * `CampaignCriterion`: `criterion_id`, `negative` (immutable), `keyword.{text,match_type}`, `status`, resource name `customers/{c}/campaignCriteria/{campaignId}~{criterionId}`. Used for campaign-level negative keywords.
  * `SearchTermView`: `search_term`, `ad_group`, `status` (`ADDED|EXCLUDED|ADDED_EXCLUDED|NONE`); resource has metrics and `segments.date`.
  * `Metrics`: `impressions`, `clicks`, `cost_micros`, `conversions`, `conversions_value`, `all_conversions`, `all_conversions_value`, `interactions`. `conversions` counts only conversion actions with `include_in_conversions_metric = true`; `all_conversions` counts everything. **V1 uses `conversions` / `conversions_value` only.**
* **Quota behaviour**: `RESOURCE_EXHAUSTED` (HTTP 429) → ledger status `deferred`, never a failure, never an immediate retry; per-request response cap 64 MB.
* **Not verified / therefore not used**: per-conversion-action breakdown (`segments.conversion_action_name` compatibility), `segments.keyword.info.*` field compatibility on `search_term_view` (the keyword association is read as an optional extra and tolerated as absent), intraday refresh. These are listed under §17.

### Verification status of the real client
Everything above is verified from documentation only. **Provider live acceptance is pending**: no developer-project credentials or Google Ads test account were available in this lane. The automated suite and the browser acceptance run entirely on `FakeGoogleAdsClient`.

## 3. Connection and account model

* Table `business_google_connections` (existing) gains rows with `product = 'google_ads'`. One per Business (existing unique `(business_id, product)`), encrypted refresh token, state machine, optimistic `lock_version`, `connected_by_user_id`. **No access token is ever persisted**; no token is copied into any Ads table.
* `GoogleConnectionProduct::GoogleAds` → scope `https://www.googleapis.com/auth/adwords`.
* New classes (all under `App\Library\GoogleAds`): `GoogleAdsOAuthConfig`, `GoogleAdsConnectionManager` (parallel to, and not touching, the GBP manager; reuses `GoogleOAuthStateSigner`, `BusinessGoogleConnection`, `GoogleOperationLedger` types), `GoogleAdsCallBudget`.
* Fixed tenant-free callback route `ads/oauth/callback` (Google matches redirect URI exactly); the Business is resolved from the signed, single-use state.
* Table `google_ads_accounts` (new; purpose: the *selected* Ads customer and Business-level Ads configuration — nothing else stores it). One row per Business. Composite FK `(business_google_connection_id, business_id)` → `business_google_connections (id, business_id)` so an account can never bind another Business's connection.
  Columns: `uid`, `business_id`, `business_google_connection_id`, `customer_id` (10 digits), `login_customer_id` (nullable), `descriptive_name`, `currency_code`, `time_zone`, `is_test_account`, `selected_at`, `selected_by_user_id`, `monthly_budget_target_micros` (nullable), `target_cpl_micros` (nullable), `last_sync_started_at`, `last_successful_sync_at`, `data_through_date`, `last_sync_failure_code`, `sync_claimed_at`, `manual_refresh_requested_at`, timestamps.
* After OAuth the owner is taken to an **account selection** page. Candidates are produced **server-side** on each render and re-derived on the POST: `listAccessibleCustomers` → per customer `customer` details → for manager customers the non-manager clients via `customer_client` (login-customer-id = that manager). A candidate carries `{customerId, name, currency, timeZone, loginCustomerId|null, isManager, isTest}`. Managers are shown but not selectable. **Never auto-selected.** The POST accepts only a `customer_id`; `login_customer_id`, currency and timezone are taken from the freshly derived candidate, never from the request. A customer id not in the fresh candidate set fails closed (404).
* Disconnect: the existing connection-state machine; token nulled; the Ads account row, normalised facts and ledger rows are retained for history but the sync stops and the UI shows the disconnected empty state. (Provider-side revoke is not called, matching the GBP precedent.)
* **Disconnect and revoke UNSELECT the account** (`google_ads_accounts.selected_at` becomes NULL; migration `2026_10_28_100002` makes it nullable). A NULL `selected_at` means "no account" everywhere (`resolveAdsAccount` -> account-selection empty state, sync eligibility `account_not_selected`, the sweep, the mutation service), so a reconnect with a different Google identity never shows the old data as ready. Re-selecting the SAME customer only stamps `selected_at` again (facts and targets kept); a different customer purges as above.
* **Selection is refused while a sync is live** for the existing account (`GoogleAdsAccountSelectionException::SYNC_RUNNING`, checked again under the account row lock): the owner sees "An update is running; try again in a minute." Independently, the sync coordinator re-reads the account's customer id, currency and `selected_at` before every stage persist and stops with `account_changed`, writing nothing more.
* Reconnecting to a *different* customer id keeps history rows keyed by `google_ads_account_id`; changing the selected customer replaces the account row's customer and **purges** that Business's normalised Ads facts first so two currencies/customers never mix.

## 4. Normalised data (all rows carry `business_id`; `uid` where user-addressable)

| Table | Purpose |
|---|---|
| `google_ads_campaigns` | `external_campaign_id`, `name`, `status`, `channel_type`, `bidding_strategy_type`, `budget_external_id`, `budget_amount_micros`, `budget_shared`, `last_synced_at`. Unique `(account, external_campaign_id)`. |
| `google_ads_ad_groups` | campaign FK, `external_ad_group_id`, `name`, `status`. |
| `google_ads_keywords` | campaign FK, nullable ad-group FK, `external_criterion_id`, `text`, `match_type`, `status`, `is_negative`, `level` (`ad_group`/`campaign`), `quality_score` (nullable, only when the API returns it). Positive and negative keywords. |
| `google_ads_search_terms` | per-day rows: campaign/ad-group, `search_term`, `term_hash`, `metric_date`, impressions, clicks, `cost_micros`, conversions, conversions_value, `targeting_status`, optional matched keyword text/match type, `review_state` (`unreviewed`/`ignored`). |
| `google_ads_daily_metrics` | per-day facts at `level` `campaign` or `keyword` (`entity_key`): impressions, clicks, interactions, `cost_micros`, conversions, conversions_value. Account KPIs sum **campaign-level rows only** (never keyword rows) to avoid double counting. |
| `google_ads_sync_runs` | `state` (`queued/running/succeeded/partial/failed/skipped`), `trigger` (`scheduled/manual/connect`), `scope`, started/completed, `data_through_date`, rows counted, `failure_code` (safe code only), link to the ledger operation that carries call counts. No provider payloads. |
| `google_ads_mutations` | 1:1 detail for a mutation ledger row (D8). |
| `lead_attribution_touches` | §10. |

Absence is stored as `NULL` (e.g. no conversions data), never as `0`.

### Money
* All money is stored as BIGINT **micros in the Ads account currency**. `conversions` / `conversions_value` are `DECIMAL(20,6)` in the account currency.
* One helper, `App\Library\GoogleAds\GoogleAdsMoney`, owns micros ↔ decimal ↔ display. Display reuses `DashboardMoney` / `CurrencyExponent`.
* Aggregates never mix currencies: every query is scoped to one account (one currency). The UI shows the **account** currency. If the account currency differs from `businesses.currency_code` the UI says so and never combines Ads amounts with CRM `value_minor` totals.

## 5. Sync architecture

* Scheduler: `SweepGoogleAdsSyncs` (daily, `app/Console/Kernel.php`), modelled on `SweepGoogleBusinessProfileRefreshes`: active `google_ads` connections with a selected account whose `last_successful_sync_at` is older than `sync.min_interval_hours` (floored at 20), `chunkById`, deterministic stagger, project-level circuit breaker read from the operation ledger.
* `SyncGoogleAdsAccount` job (extends `Jobs\Base`, `tries=1`): re-checks Business active, workspace active, entitlement, connection active, claims the account (`sync_claimed_at` DB claim, stale after 15 min), opens one ledger operation (`ads_sync`), runs the stage syncers through the call budget, always releases the claim.
* Stages (`GoogleAdsSyncCoordinator`): account summary → campaigns (+ budgets) → ad groups → keywords (positive + negatives) → campaign daily metrics → keyword daily metrics → search terms → account `data_through_date`.
* Windows (config): daily metrics `sync.metrics_lookback_days` (62 — covers this + previous month), search terms `sync.search_term_lookback_days` (30). First sync is the same bounded window.
* Idempotent: every write is an upsert on a natural unique key; re-running the same window changes nothing. Window replace is by key, never delete-and-insert across a failure.
* No network call inside a DB transaction. Results are fetched fully (bounded by `sync.max_pages_per_report`, `sync.max_rows_per_report`) and then written in short transactions.
* A report that hits its cap marks the run `partial` and records `failure_code = row_cap`; truncated data is never presented as complete.
* On failure the last successful facts remain; `last_sync_failure_code` is set; pages show a freshness warning. Failure never turns data into zeros.
* No retry storm: `tries=1`; `deferred` (429) ends the run and the next sweep is the retry; breaker suppresses sweeps when the most recent N ledger operations are all `deferred`/`provider_unavailable`.
* Per-Business hourly call cap (`sync.max_calls_per_business_per_hour`) via `GoogleAdsCallBudget`; manual refresh limited to once per `sync.manual_refresh_min_minutes` (default 60) and skipped when fresher than that.
* **Claim ownership**: the claimant holds the stamp it wrote to `sync_claimed_at` (`GoogleAdsSyncClaim`), refreshes it at the start of every stage (heartbeat) and releases it only while the stored value is still its own. A claim taken over after going stale makes the old job stop with `claim_lost` and is never released by it.
* Trial safety: one account per Business, one daily read sync, manual refresh throttled; no separate trial mode.
* Freshness: every data page shows "Updated {relative}" and "Data through {date}"; a failed latest sync shows the last good data with a warning.

## 6. Mutations (Growth/Agency, `manage_google_ads`)

Operations: add negative keyword (campaign scope default; ad-group scope when the term has an ad group), pause/resume campaign, pause/resume keyword.

Flow for every mutation (`GoogleAdsMutationService`):
1. Resolve everything **inside** the Business's selected account: the target campaign/keyword is loaded by `uid` + `business_id` + `google_ads_account_id`; external ids from the request are never trusted. Foreign ids → 404.
2. Actor permission `manage_google_ads` + entitlement `google_ads_module`; View As **prohibited** (credential-class).
3. Lock the account row; compute a deterministic `dedupe_key`; refuse if the same target+requested state is already `pending`/`unknown`, or (negatives) already present as a synced/succeeded negative → returns the existing result, no second mutate.
4. Open a ledger operation (type `ads_*`) **before** the provider call; create the `google_ads_mutations` detail row in the same transaction. Commit. Then call the provider (no transaction open).
5. Outcome: success → ledger `succeeded`, local row updated (status / inserted negative), page reflects it. Definite provider rejection → `failed` with a safe classification. **Timeout / dropped connection after send → `unknown`; never replayed automatically**; the next sync reconciles the local row from Google's state and the UI says "Awaiting confirmation".
   * **Ambiguous provider answers**: for a mutate, a 5xx, a 408, or Google status `ABORTED` / `UNKNOWN` / `DEADLINE_EXCEEDED` / `INTERNAL` / `UNAVAILABLE` is treated like a timeout after send (ledger `unknown`, never replayed). 400, 401, 403, 404 stay definite failures; 429 / `RESOURCE_EXHAUSTED` is deferred. Reads keep `provider_unavailable` for 5xx.
   * **Reconciliation timing**: the sync observer reports the stage and whether its report was truncated. Campaign-status operations may be settled after the `campaigns` stage; keyword-status and negative operations only after `keywords`, and a negative's ABSENCE (`not_applied`) only when that keywords report was complete. "State equals the request" settles an operation as applied at any time.
   * **Negative ledger fallback**: a `succeeded` negative-keyword ledger row suppresses a re-add ("already excluded") only until a sync run that SUCCEEDED (complete, not partial) started after that operation completed; from then on the synced keywords are the sole truth, so a negative removed in Google Ads can be added again.
6. Pause/resume uses `updateMask: "status"` with `PAUSED`/`ENABLED`. `REMOVED` is never written. Negative keywords use `create` (a negative's `negative` flag is immutable).
7. UI confirmation precedes every mutation, showing the exact term / scope / match type or the exact entity and target state. Match type is chosen explicitly (default exact); no broad negatives are guessed.

Never changed by this module: bidding strategies, budgets, targeting, campaign creation, `REMOVED`.

## 7. Entitlement, navigation, permissions

* `PlatformFeatureRegistry`: `AdsBasicVisibility` and `GoogleAdsModule` → `Available`; `MetaAdsModule` stays `Planned`.
* Both keys are added to `CustomerMenuBuilder::ENTITLEMENT_GATED_FEATURES`.
* Sidebar parent **Ads** with children Overview, Campaigns, Keywords, Search terms, Leads & conversions, Budget, Recommendations, Settings. A Business with only `ads_basic_visibility` sees Overview and Settings; every other route fails closed (404) without `google_ads_module`.
* Permissions (`config/customer-permissions.php` + idempotent backfill migration): `view_google_ads` (default true), `manage_google_ads` (credential-class, default **false**, not backfilled to true).
  * `view_google_ads`: read every entitled page.
  * `manage_google_ads`: connect, select account, disconnect, save settings, manual refresh, all mutations.
* Core behaviour: connect + Overview + Settings only. Growth/Agency: everything. No controller checks plan names.

## 8. Agency / View As

* Business-scoped routes only (`{workspaceUid}/businesses/{businessUid}/ads/...`), resolved by the existing tenancy trait; a View As session is pinned to the viewed Business, so a client sees only that client's account and the customer id of another client never reaches the page.
* View As may **read** Ads pages; every connect/select/disconnect/settings/refresh/mutation route is listed in `ViewAsProhibitedActions`. The bare entry `customer.ads.index` is `REDIRECT_TO_VIEWED`.
* No cross-client aggregate dashboard in V1.

## 9. Metric definitions (deterministic)

* **Spend** = Σ `cost_micros` (campaign-level rows) for the period.
* **Google conversions** = Σ `conversions` (Google's primary-action count, can be fractional). It is labelled **"Google conversions"**, never "Leads".
* **Business OS leads** = distinct attributed contacts (§10) in the period; shown only when attribution exists, labelled by attribution level.
* **CPL (cost per conversion)** = spend ÷ conversions; `NULL` (shown —) when conversions ≤ 0 or data absent. Never $0.
* **Conversion rate** = conversions ÷ clicks; `NULL` when clicks = 0.
* **Conversion value / revenue** shown only when Σ value > 0 and the account has any value-bearing conversion; labelled "Conversion value (Google)". It is Google's value, not CRM revenue.
* **Projected month-end spend** = (month-to-date spend ÷ days elapsed with data) × days in month, in the account time zone. Low-confidence flag when fewer than `pacing.min_days` (default 7) days of data; omitted when zero days.
* **Pacing status** vs the Business monthly target: elapsed proportion vs spend proportion; `on_pace` within ±`pacing.tolerance` (default 15%), `ahead` (overspending), `behind`, or `no_target` / `insufficient_data`.
* **CPL status** vs `target_cpl_micros`: `better`, `on_target`, `worse`, or `no_target`.
* Periods: 7 days, 30 days, this month, previous month — all computed from the account time zone over cached daily facts; changing the period range does not call Google.
* Charts: spend, conversions, CPL series from `google_ads_daily_metrics` (campaign level), Apex line/bar via the existing theme helpers; JSON series endpoint like Analytics.

## 10. Attribution foundation

Nothing existed before this lane (no click ids, UTMs, referrers, first/last touch, or call tracking).

* Table `lead_attribution_touches` — **append-only, never updated**, so first-touch, last-touch and multi-event history all fit later without migration of existing rows.
  Columns: `uid`, `business_id`, `business_location_id` (nullable), `contact_id` (nullable), `subject_type` (`form_submission` | `website_form_submission` | `appointment`), `subject_id`, `entry_surface` (`public_form` | `website_form` | `booking`), `touch_role` (`first` | `last`), `gclid`, `gbraid`, `wbraid`, `utm_source`, `utm_medium`, `utm_campaign`, `utm_term`, `utm_content`, `landing_page` (path only, query string stripped), `captured_at` (when the visitor arrived), `recorded_at` (when the conversion happened).
* **First-touch preservation**: a first-party cookie pair is set by `CaptureAttributionTouch` middleware on public GET pages (public website, public form page, public booking page) only when the URL carries at least one click id or UTM parameter: `bos_at_first` is written once (never overwritten while valid, 90 days); `bos_at_last` is rewritten on each new tagged arrival. At submission, `LeadAttributionRecorder` writes one `first` row and, if it differs, one `last` row for that conversion event, linked to the contact once resolved. A contact's first touch is the oldest `first` row; its last touch the newest `last` row. Repeat visits never overwrite anything.
* Cookie values are signed (Laravel encrypter), contain only the attribution fields above, are `HttpOnly`, `SameSite=Lax`, `Secure` on https. No visitor identifier is created.
* Business scoping: a row's `business_id` is taken from the server-resolved Business of the public page being submitted, **never** from the cookie. A forged cookie can only place attacker-chosen values on that Business's own row, and values are length-limited and character-validated.
* Failure to record attribution never fails a submission or booking.
* **Attribution levels** shown to the owner (no false precision):
  * `Google click ID captured` — a gclid / gbraid / wbraid exists. This proves the lead arrived from a Google Ads click, **not which campaign or keyword**. (Campaign/keyword resolution needs `click_view` / offline conversion import: deferred.)
  * `Campaign tags only` — UTM data without a click id; shown as the tag text, not matched to a Google campaign by name.
  * `Source not captured` — none.
* Privacy / consent: collection is first-party, only for visitors arriving with tracking parameters, and honours `Sec-GPC: 1` and `DNT: 1` (nothing is captured). The Business is the controller of its own site visitors' data and must reflect this in its privacy policy; the platform provides no consent banner in V1. Capture can be disabled with `attribution.capture_enabled`. No IP address or user agent is stored on touches. Click ids are retained with the lead's life (needed later for conversion upload) and are removed with the contact via cascade/cleanup.
* **Capture is ON by default** (`google_ads.attribution.capture_enabled`), with `Sec-GPC` / `DNT` opt-outs only and NO consent-banner hook. Deployments subject to ePrivacy / EU rules must set it to `false`, or add a consent decision before enabling it.
* Not user-editable custom fields.

## 11. Leads & conversions page

Shows two clearly separated blocks: **Google conversions** (from `google_ads_daily_metrics`, by campaign) and **Business OS outcomes** (from `lead_attribution_touches` joined to contact, CRM opportunity stage/value/status, and appointment existence): lead, contact, attribution level, entry surface, landing page, first-touch tags, current CRM stage, booked?, opportunity value (CRM `value_minor`, in the Business currency, never summed with Ads currency amounts). It states plainly that the two numbers are different measurements and why they may not match.

## 12. Recommendations (facts, not a second product)

`App\Library\GoogleAds\Recommendations\GoogleAdsRecommendationFactReader` returns `GoogleAdsRecommendationFact` DTOs (`type`, `evidence` map, `subject`, `suggested_action`) from deterministic rules; nothing is stored, nothing has a lifecycle. Types:

| Type | Rule (thresholds in `config/google_ads.php`) |
|---|---|
| `wasted_search_terms` | search terms with spend ≥ `recommendations.waste_min_spend_micros` and conversions = 0 (and data present), aggregated in the period |
| `zero_conversion_campaign` | campaign spend ≥ threshold with 0 conversions in the period |
| `cpl_above_target` | CPL > target × `cpl_over_factor` (needs target and conversions) |
| `strong_campaign` | CPL ≤ target and conversions ≥ `strong_min_conversions` |
| `pacing_over` / `pacing_under` | pacing status with enough days |

Insufficient evidence ⇒ **no fact**. Search-term classification on the Search terms page (`Potential waste` / `Converting` / `Unreviewed`) uses the same rules. The only locally persisted state is the owner's per-search-term `review_state` (ignored) — it is a classification of the term, not a recommendation lifecycle.

The Recommendations page is a **read-only presentation** of these facts with links to the owner-confirmed action (e.g. Add negative drawer). No dismiss/snooze is built here (owned by the Opportunity Engine).

### Later Opportunity Engine integration (documented, not built)
Growth Center is not in this base and `OpportunityTypeRegistry` is a closed, source-controlled registry (RFC-002 §13.2, only `business_advisor` types today). The integration is: add `OpportunityWorkerKey::Ads`; register types `ads_wasted_search_terms`, `ads_zero_conversion_campaign`, `ads_cpl_above_target`, `ads_pacing_over`, `ads_strong_campaign` with `allowed_evidence_fact_keys` mapped 1:1 from `GoogleAdsRecommendationFact::evidence` keys; an `OpportunityProducer` whose `produce(Business)` maps `GoogleAdsRecommendationFactReader` facts to `OpportunityCandidateData` (fact data only — titles/summaries come from the registry templates); `recommended_action` keys map to the existing owner-confirmed flows (`add_negative_keyword` → the Add negative confirmation). That requires an RFC-002 amendment and is a separate lane.

## 13. Data policy and AI

No Google Ads data is sent to any AI provider, the COO envelope, or `AiGateway`. The COO/Home surfaces receive no Ads facts in V1. (A later lane must make that decision explicitly.)

## 14. API quota safeguards

Per-Business sync claim; daily cadence; manual refresh throttle; per-Business hourly call budget (ledger `provider_call_count`); bounded pages/rows; project-level circuit breaker (all of the last N ledger operations `deferred`/`provider_unavailable` ⇒ sweeps suppressed for the cooldown); `deferred` is never a failure and never retried inline; mutations never auto-retried.

## 15. Operation ledger

`business_google_operations` is extended additively (`GoogleOperationType`): `ads_accounts_listed`, `ads_account_selected`, `ads_sync`, `ads_campaign_status_changed`, `ads_keyword_status_changed`, `ads_negative_keyword_added` (all ≤ 40 chars). Connect/refresh/disconnect reuse the existing product-neutral types.

## 16. Freshness and empty states

* Not connected: "Connect Google Ads to see where your ad budget is generating results." + Connect button.
* Connected, no account selected: selection page.
* Connected, no campaigns: explanatory empty state. No conversions: CPL shown as —. No search terms: "No search-term data yet."
* A sync failure shows the last successful data with a warning; absent data is never 0.

## 17. Deferred

Meta/Microsoft Ads, campaign creation, automatic budget/bidding changes, PMax assets, Shopping/Merchant Center, conversion-action management, AI features, competitor research, keyword planner, cross-client Agency Ads reporting, multi-touch attribution *modelling*, call tracking, **offline conversion upload** (click-id foundation is in place; needs consent model, conversion-action mapping UI, dedupe, and verification of `uploadClickConversions`), per-conversion-action breakdown, campaign→Location mapping (campaign data is Business-wide; no inference from names), intraday refresh, Quality Score beyond showing the API value when present, "Add as keyword", Growth Center / Opportunity Engine integration (§12), Ads facts on Home/COO, provider-side token revoke, live provider acceptance.

## 18. UI shell (Phase 3A) — routes, access matrix, extension points

**Routes** (`routes/customer.php`). Bare entry `customer.ads.index` (`/ads`; 404 while neither Ads feature is Available; zero / one / many entitled Businesses behave like `seo.index`). Fixed tenant-free callback `customer.ads.oauth.callback` (`/ads/oauth/callback`; signed state, product claim `google_ads`, actor must be the initiator, nonce consumed before the code exchange). Business-scoped group `{workspaceUid}/businesses/{businessUid}/ads` named `customer.workspaces.businesses.ads.*`: `index` (Overview), `series` (JSON, throttle 60/min), `budget`, `settings` (GET) + `settings.update` (POST), `connect` (POST), `accounts` (GET) + `accounts.select` (POST), `disconnect` (POST), `refresh` (POST). The data pages and mutations added in Phase 3B (`campaigns.*`, `keywords.*`, `search-terms.*`, `leads.*`, `recommendations.*`) are specified in §19.

**Access matrix.** Tenancy and entitlement failures are 404. A missing capability is the application-wide authorization failure (401), checked only after tenancy.

| Route | Entitlement | Capability |
|---|---|---|
| Overview, series, Settings (GET) | `ads_basic_visibility` OR `google_ads_module` | `view_google_ads` |
| connect, accounts (GET), accounts.select, settings.update, refresh | `ads_basic_visibility` OR `google_ads_module` | `manage_google_ads` |
| disconnect | none (stored credentials are never trapped by a plan change; GBP precedent) | `manage_google_ads` |
| Budget and every later page | `google_ads_module` only (Core gets 404) | `view_google_ads` (mutations: `manage_google_ads`) |

**Behaviour.** Overview, series and Budget read cached normalised data only; changing the period never calls Google. Refresh only queues a sync (`GoogleAdsSyncRequester`). Targets are stored in micros of the account currency; a blank or zero value clears to NULL. The callback leaves the framework's server-side session "previous URL" holding the callback URL (as the GBP callback does); the code and state are single-use and never appear in a response, redirect, flash or log.

**View As.** Reads stay viewable. `connect`, `accounts`, `accounts.select`, `disconnect`, `settings.update`, `refresh` and the callback are prohibited by name, and EVERY non-GET route under `customer.workspaces.businesses.ads.` is prohibited by pattern (`ViewAsProhibitedActions::NON_GET_PROHIBITED_PREFIXES`), so mutation routes added later are covered on registration. `customer.ads.index` redirects to the viewed Business's Overview.

**Extension points for later pages.** `ResolvesAdsBusinessTenancy` (`resolveAdsTenancy`, `resolveAdsModuleTenancy`, `resolveAdsAccount`, `adsViewData`), the shared partials `customer.business.ads._header` (title, currency, sub-navigation, freshness) / `_freshness` / `_empty-state`, and `CustomerMenuBuilder::adsMenuItem()` which shows each child only when its route exists.

## 19. Data pages and actions (Phase 3B)

All pages below are **google_ads_module only** (a Core Business gets 404), then `view_google_ads`; every action needs `manage_google_ads`. They read **cached normalised tables only**: no GET calls Google, and changing the period, sort, filter or page never does. Absent figures are a dash, never 0; provider strings (campaign / ad group / keyword / search-term text) are escaped; Google's own ids never appear in markup or URLs (rows are addressed by `uid`, or by a local search-term row id). Each page renders the shared header (title, currency, sub-navigation, freshness line) and the standard empty state when `adsState` is not `ready`.

### 19.1 Routes (`customer.workspaces.businesses.ads.*`)

| Name | Method + path (under `.../ads`) | Controller | Notes |
|---|---|---|---|
| `campaigns.index` | GET `/campaigns` | `AdsCampaignsController@listing` | period, sort, dir, status, page |
| `campaigns.show` | GET `/campaigns/{campaignUid}` | `AdsCampaignsController@detail` | unknown / foreign / malformed uid => 404 |
| `keywords.index` | GET `/keywords` | `AdsKeywordsController@listing` | period, campaign, status, sort, dir, page |
| `search-terms.index` | GET `/search-terms` | `AdsSearchTermsController@listing` | period, class, campaign, sort, dir, page |
| `leads.index` | GET `/leads` | `AdsLeadsController@listing` | period, page |
| `recommendations.index` | GET `/recommendations` | `AdsRecommendationsController@listing` | period |
| `campaigns.pause` / `campaigns.resume` | POST `/campaigns/{campaignUid}/pause` and `/resume` | `AdsMutationController` | throttle 20/min |
| `keywords.pause` / `keywords.resume` | POST `/keywords/{keywordUid}/pause` and `/resume` | `AdsMutationController` | throttle 20/min |
| `search-terms.negative.preview` | POST `/search-terms/negative/preview` | `AdsMutationController@previewNegative` | server-rendered confirmation; no provider call, no write |
| `search-terms.negative.store` | POST `/search-terms/negative` | `AdsMutationController@storeNegative` | the confirmed mutation |
| `search-terms.ignore` / `search-terms.unignore` | POST `/search-terms/ignore` and `/unignore` | `AdsMutationController` | local classification only |

Controller methods are named `listing` / `detail` because `CustomerBaseController` already declares `index` / `show`. Every non-GET route is View-As prohibited by the existing `customer.workspaces.businesses.ads.` prefix rule (§18); nothing was added to `ViewAsProhibitedActions`.

### 19.2 Query input

`GoogleAdsListInput` validates `period`, `sort`, `dir`, `page`, `status`, `class` and `campaign` against the readers' own whitelists; anything invalid, unknown, an array, or a campaign uid that is not one of the account's own campaigns silently becomes the default (period `last_30`, sort `spend` descending, text sorts ascending, no filter, page 1). A hand-edited URL never errors and never reaches SQL. The query a page was viewed with is carried through an action as a re-whitelisted `q` field and the origin as `from` (`campaigns|keywords|search-terms|campaign`); the redirect after an action is always a route generated from those, never a URL from the request.

### 19.3 Pages

* **Campaigns**: Campaign (links to detail) | Status | Daily budget (+ Shared badge) | Spend | Clicks | Conversions | Cost per conversion | Conv. rate | Value (Google; only when some row has a positive value) | Action (Pause / Resume when the actor can manage). The action opens a dialog stating exactly what changes (`Pause campaign X? It will stop showing ads in Google Ads until resumed.`).
* **Campaign detail**: KPI cards, a trend chart (server data embedded in the page, the same Apex pattern as the Overview, with a "Show daily figures" table fallback), the campaign **daily** budget facts (average daily spend, utilisation, shared flag; explicitly separate from the Business monthly target, which lives on Budget), ad groups, the top keywords and search terms with links to the full pages filtered to the campaign, and a Conversion data card (Google conversions + Google value). Ad-group sums are sums of keyword-level rows (the sync stores no ad-group level) and the page says so.
* **Keywords**: Keyword | Match type | Campaign / ad group | Status | Spend | Clicks | Conversions | Cost per conversion | Conv. rate | Quality Score (only when a shown row has a stored non-null score) | Action. Campaign and status filters. A separate collapsed, read-only **Negative keywords** list. Pause / resume apply only to positive ad-group keywords (the service validates).
* **Search terms**: Search term | Campaign / ad group | Matched keyword | Spend | Clicks | Conversions | Cost per conversion | State | Actions. State chips: Potential waste (amber) / Converting (green) / Unreviewed / Excluded / Ignored, from the single classifier in `GoogleAdsSearchTermReader`. Class tabs with a count each (one aggregate read per class), a campaign filter, and the waste summary card (`USD 43.00 spent across 6 search terms with no conversions`) from `wasteSummary` (hidden when there is none; terms an enabled negative already covers are reported separately and not counted).
* **Leads & conversions**: an explainer card, then two separate blocks. *Google conversions*: by campaign, in the Ads account currency. *Business OS outcomes*: summary chips (leads, with a Google click ID, campaign tags only, source not captured) and a paginated table Lead (links to the existing contact page `businesses.people.show`) | How they arrived | Source tags (first-touch `utm_campaign` / `utm_term` as tag text) | Entry surface | Landing page | CRM stage | Booked? | Opportunity value (CRM money in the **Business** currency, never summed or compared with Ads-currency amounts). No row ever names a Google campaign or keyword: a click id proves a Google click, not the campaign; matching arrives with offline conversion import (§17).
* **Recommendations**: cards from `GoogleAdsRecommendationFactReader` worded by `GoogleAdsRecommendationPresenter` (title, evidence lines, "Based on your cached Google Ads data for <period>; deterministic rule." (pacing facts say "this month"), and one link to the owning page: `review_search_terms` -> Search terms with `class=potential_waste`, `review_campaign` -> campaign detail, `review_budget` -> Budget). Neutral / amber / green styling only, never red. **No dismiss / snooze / apply and nothing stored**: recommendation lifecycle belongs to the Opportunity Engine (D7, §12). No facts shows "Nothing to flag right now" with the insufficient-data explanation.
* **Overview teaser**: the "Money wasted?" card (hidden by 3A until the page existed) now appears for a Business entitled to the module when there is actionable waste, and links to Search terms.

### 19.4 Mutations over HTTP

Order: tenancy + `google_ads_module` entitlement (404), then `manage_google_ads` (401), then **one** `GoogleAdsMutationService` call. A request carries only local identifiers (campaign / keyword uid, a local `google_ads_search_terms.id`); the service re-resolves them inside the Business's selected account, so a foreign uid, a Google id, or a term id of another Business is a 404 with zero provider calls. The negative-keyword text is **never** read from the request: it is the cached term row's text.

**Add negative** is two steps. `negative.preview` (a POST, so a refresh cannot re-send anything) renders a confirmation from `NegativeKeywordPreview`: `Search term: "<term>"`, `Will be excluded from: <Campaign or Ad group name>`, `Match: exact|phrase`, radios for match (exact default; **broad is never offered or accepted**) and scope (campaign default and safest; ad group when the term has one), a warning that disables confirming when the negative already exists, and "Nothing changes in Google Ads until you press Add negative keyword". Changing a radio re-submits to the preview (automatically with JavaScript, via an "Update preview" button without), so what is shown is what is confirmed. `negative.store` then calls `addNegativeKeyword`. Not built: "Add as keyword".

Outcome flashes (fixed copy; provider text never reaches the page): succeeded -> success; **awaiting confirmation** -> "Google didn't confirm this change. We'll verify it on the next update. Nothing was changed twice." (title "Pending confirmation"); deferred -> warning; failed -> error; already in that state / already excluded / already in progress -> info with no provider call. A refused request that is not a 404 (removed entity, keyword that cannot be paused, invalid text, connection not active) is a calm error flash.

**Pending confirmation marker.** `GoogleAdsPendingConfirmations` marks a campaign, keyword or search-term row "Pending confirmation" while its ledger operation (`business_google_operations`, joined through `google_ads_mutations`, scoped by Business and account) is `unknown`; at most two small queries per page. The reconciler clears it by resolving the operation.

**Double submit.** The forms disable their buttons on the first submit (a convenience); the real guarantee is the service's dedupe: a resubmit is answered from the ledger and never sent twice.

**Ignore / Un-ignore** (`GoogleAdsSearchTermReview`) is not a provider mutation and has no ledger row. It sets `review_state` of that term's cached rows within one campaign and ad group of the selected account (Business + account scoped; another campaign's identical term is untouched) and is reversible. Because the sync stores new days as `unreviewed`, the classifier treats a term as ignored when ANY cached row of that term / campaign / ad group is ignored, so days arriving later do not silently un-ignore it. It needs `manage_google_ads`.

### 19.5 Decisions

* `GoogleAdsSearchTermRow::internal` gained `search_term_id` (one cached row of that term / campaign / ad group) so a confirmation can name the cached row; `LeadAttributionReader` rows gained `contact.uid` (for the contact link). Both additive.
* The campaign detail trend uses server data embedded in the page rather than a new series route (the Overview's `series` route remains the only chart endpoint).
* Search-term class counts are computed per tab with the existing reader (six small aggregate reads) rather than a new counting query.
* Known limit carried from §9 / §12: the `potential waste` classification uses whole-period totals, so a very short period can flag a term that a longer one would not.
