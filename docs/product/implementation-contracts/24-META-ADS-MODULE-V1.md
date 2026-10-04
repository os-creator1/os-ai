# 24 — Meta Ads Module V1 contract

Status: implemented in lane `agent/meta-ads-module-v1` (base `b353a736`, the final Google Ads V1 head).
Product goal: **"See where your Meta ad budget is turning into results — and what needs attention."**

Contract 23 (Google Ads) remains authoritative for Google. This document records the Meta decisions,
the provider facts that were verified, the data model, the rules and everything deliberately deferred.
Where this contract says "mirrors Google X", the Meta class copies the Google class's *behaviour and
safety philosophy* under Meta names; it is a deliberate copy, never an import, because the Google
classes are hard-wired to Google tables and products.

---

## 1. Decisions

| # | Decision |
|---|---|
| M1 | **One customer-facing module: Ads.** Google and Meta are providers inside it. No sidebar item named Meta/Facebook/Instagram Ads. |
| M2 | Meta has its **own connection authority** (`business_meta_connections`), its own operation ledger (`business_meta_operations`) and its own signed-state/CSRF machinery. Nothing is added to `business_google_connections`, `business_google_operations`, `GoogleConnectionProduct` or `GoogleOAuthStateSigner`. Reason: those are product-keyed Google rows, the Google call budget sums across *all* ledger rows of a Business (Meta rows would eat Google's budget), and the signer verifies a closed Google product list. |
| M3 | Built against a **Fake provider** for tests and browser acceptance. **Live Meta acceptance is PENDING** (no app credentials / test ad account). The HTTP client is coded only from the verified facts in §2. |
| M4 | Core (`ads_basic_visibility`): connect Meta, account selection, Meta Overview, Settings. Growth/Agency (full Ads module capability): campaigns, ad sets, ads, recommendations, leads/attribution, pause/resume. Gating is entitlement-driven, never plan-name checks. |
| M5 | **No Meta data goes to AI.** Recommendations are deterministic facts (same stance as D5 in contract 23). |
| M6 | **Targets are provider-specific in V1** (`meta_ads_accounts.monthly_budget_target_micros`, `target_cost_per_result_micros`). Google's `google_ads_accounts` targets are untouched and keep their semantics. A $250 Google target never becomes a $250 Meta target, and the Ads Overview never sums or splits targets. A shared Business-level total with provider allocations is deferred (§17). |
| M7 | **No Meta Pixel, no Conversions API, no visitor tracking, no `fbclid`/`fbc`/`fbp` capture in V1.** There is no canonical visitor-consent seam (§10). No schema for Meta click ids is added. |
| M8 | **Lead Ads ingestion is deferred** (§11). |
| M9 | Mutations: pause/resume of campaigns, ad sets and ads, through the same ledger-first, never-replay, reconcile-from-sync philosophy as Google. No creation, no budget, bid, targeting or creative edits. |
| M10 | Entitlements gain a provider-neutral key `ads_module`; `google_ads_module` and `meta_ads_module` are kept as legacy synonyms resolved by one mapper (§8). |
| M11 | Results are an **explicit, owner-chosen result type** per Ads account, never a flattened pile of Meta actions (§5). Unconfigured ⇒ results are *unavailable* (NULL), not zero. |

## 2. Provider authority (verified 2026-10-04)

* **API version: Marketing/Graph API v26.0** (released 2026-07-29; expiry "TBD" per Meta's versions page; v25.0 expires 2028-07-29). Pinned in `config/meta_ads.php` (`api_version`), never hard-coded in a client. v24 is not used. Meta's changelog states v26.0 breaking changes apply to every version from 2026-10-27; none affects the read fields or the `status` update used here.
* **Transport**: REST over `https://graph.facebook.com/{version}/`. No SDK. No scraping.
* **OAuth**: authorisation dialog `https://www.facebook.com/{version}/dialog/oauth` (`client_id`, `redirect_uri`, `state`, `scope`, `response_type=code`); code exchange `GET https://graph.facebook.com/{version}/oauth/access_token` (`client_id`, `redirect_uri`, `client_secret`, `code`); long-lived exchange the same endpoint with `grant_type=fb_exchange_token` (`client_id`, `client_secret`, `fb_exchange_token`) — server side only.
* **Token lifecycle** (verified): a long-lived user token lasts about 60 days; the response carries `expires_in` (seconds); **an expired token cannot be exchanged for a new long-lived token**, so the owner must re-authorise. Meta has no refresh token. See §3.
* **Permissions**: `ads_read` (read ad accounts and reports), `ads_management` (read **and manage** ads), `business_management` (Business Manager / catalog APIs, access-level dependent). **V1 requests `ads_read` and `ads_management` only.** `business_management` is *not* requested: nothing in V1 needs Business Manager APIs, and the account list comes from `/me/adaccounts`. Consequence (documented limitation): ad accounts reachable only through a Business Manager *role* and not listed by `/me/adaccounts` are not offered.
* **Access levels / App Review** (verified): Standard access is granted when the Marketing API product is added and is enough for development and for accounts whose admins are app roles; **Advanced access requires App Review per permission** and is needed to serve other people's businesses. Maintaining Advanced/Full access needs ≥500 successful API calls in 15 days with <15% errors. *Live use for customers therefore requires App Review for `ads_read` and `ads_management`, plus Meta Business Verification — external gates, not done in this lane.*
* **Rate limits** (verified): Marketing API quota is separate from Graph quota. Development tier max 60 points / 300 s decay; Full Access 9,000 points. Reads cost 1 point, writes 3. Mutation endpoints are capped at 100 QPS per app + ad account. Throttle errors: codes 4, 17, 32, 613 and the 80000 series; headers `X-Ad-Account-Usage` and `X-Business-Use-Case-Usage` carry usage and `estimated_time_to_regain_access`. Handling: §6.
* **Ad account node** (verified): id `act_{account_id}`; `account_id`, `name`, `currency` (ISO 4217), `timezone_name`, `account_status` (1 ACTIVE, 2 DISABLED, 3 UNSETTLED, 7 PENDING_RISK_REVIEW, 8 PENDING_SETTLEMENT, 9 IN_GRACE_PERIOD, 100 PENDING_CLOSURE, 101 CLOSED, 201 ANY_ACTIVE, 202 ANY_CLOSED), `business`. Accounts for the user: `GET /me/adaccounts`.
* **Campaign** (verified): statuses ACTIVE, PAUSED, DELETED, ARCHIVED; `status` and `configured_status` are identical (use `status`); pausing a campaign pauses its active child ad sets (their `effective_status` becomes `CAMPAIGN_PAUSED`); daily *or* lifetime budget; update via `POST /{campaign_id}`; every campaign has `special_ad_categories`. **Ad** (verified): `effective_status` (ACTIVE, PAUSED, PENDING_REVIEW, …); only fields used at creation can be updated; deleted ads only allow name/status changes; `adset_id` is immutable.
* **Insights** (the official field-reference page was not retrievable in this session — 404 — so the field names below are the long-documented ones and are re-checked by the Fake/HTTP contract tests only): `GET /{object_id}/insights` with `level` (`campaign|adset|ad`), `time_increment=1`, `time_range={"since","until"}`, `fields=spend,impressions,reach,frequency,clicks,inline_link_clicks,actions,action_values,account_currency,date_start,date_stop,<entity id field>`, `limit`, cursor paging (`paging.cursors.after`). `spend` is a decimal string in the account currency (major units). `actions` / `action_values` are typed arrays `[{action_type, value}]`.
* **Lead Ads retrieval** (verified): needs `ads_management`, `leads_retrieval`, `pages_show_list`, `pages_read_engagement`, `pages_manage_ads` and a long-lived **Page** access token; webhooks deliver a `leadgen_id` to be read. **Conversions API** (verified): needs a Pixel/dataset id and an access token; no App Review.

Unverified and therefore **not used**: `business_management`-only account discovery, async insight reports, per-placement/age/gender breakdowns, attribution-window overrides (insights use Meta's default attribution setting), webhook subscriptions.

## 3. Connection and token lifecycle

* Table `business_meta_connections` (one per Business, `unique(business_id)`, `unique(id, business_id)`): `uid`, `business_id`, `state` (`pending|active|expired|revoked|disconnected`), `access_token_encrypted` (Laravel `encrypted` cast, hidden), `token_expires_at`, `granted_scopes`, `meta_user_id`, `meta_user_name`, `oauth_state_nonce` (unique) + `oauth_state_expires_at`, `connected_at`, `disconnected_at`, `revoked_at`, `last_verified_at`, `failure_classification`, `connected_by_user_id`, `lock_version`.
* **The token is a long-lived user token stored encrypted at rest.** It is exchanged server-side; neither the short-lived nor the long-lived token ever reaches a response, redirect, flash, log or other table. Every Graph call also sends `appsecret_proof` (HMAC-SHA256 of the token with the app secret).
* `MetaOAuthStateSigner` (own class, own HMAC domain prefix `meta_ads:`, own table, product claim `meta_ads`): single-use, TTL-bound state bound to Business and initiating user. The fixed tenant-free callback `ads/meta/oauth/callback` resolves the Business **only from the signed state**; the actor must be the initiator, must still be allowed on the Business, and needs `manage_meta_ads`; the nonce is consumed atomically *after* those checks and *before* the code exchange.
* After code exchange the manager calls `GET /me?fields=id,name` and `GET /me/permissions`. It **fails closed** unless `ads_read` is `granted` (and `ads_management` for mutations; a connection without it is `active` read-only and the UI says "reconnect to allow pause/resume").
* **Expiry**: `token_expires_at = now + expires_in`. Within `token.reauth_warning_days` (7) the UI shows "Reconnect Meta before …". Past expiry, or on Graph error code 190 (subcodes 458/460/463/467 and any 190), the connection transitions to `expired` (or `revoked` for 458 "app not authorized"), the token is wiped, syncs stop, history is kept, the UI shows a reconnect state. Meta has no refresh; **re-authorising is the renewal path** and is allowed while `active` (re-auth) as well as `expired`/`revoked`/`disconnected`.
* **Disconnect** nulls the token, UNSELECTS the account (`selected_at` NULL) exactly like Google (contract 23 §3), keeps history and ledger. Provider-side revoke (`DELETE /me/permissions`) is **not** called (Google precedent).
* **No cross-Business token reuse**: the connection row is keyed by Business; `meta_ads_accounts` has a composite FK `(business_meta_connection_id, business_id)`; every sync/mutation re-reads the account, its connection and `meta_user_id` and refuses on mismatch (`account_changed` / `connection_mismatch`).

## 4. Account selection

Same rules as Google (contract 23 §3): candidates are **derived server-side** from `GET /me/adaccounts?fields=account_id,name,currency,timezone_name,account_status` on every render and **re-derived on the POST**; the POST carries only `account_id` (digits, an `act_` prefix is tolerated and stripped); a candidate not in the fresh set fails closed (404); currency and time zone come from the fresh candidate, never the request; **never auto-selected, even with exactly one account**; only `ACTIVE` accounts are selectable (others shown with their status); selection is refused while a sync is live (`SYNC_RUNNING`); changing the selected account purges that Business's Meta facts first (two currencies never mix); re-selecting the same account only re-stamps `selected_at`. Another Business's ad account id fails closed because it is never in this Business's candidate set.

## 5. Data model and metric semantics

All tables carry `business_id`; user-addressable rows carry `uid`; provider ids are kept (`external_*`), never shown in markup or URLs.

| Table | Purpose |
|---|---|
| `business_meta_connections` | §3 |
| `business_meta_operations` | ledger: durable operation key, status (`pending|succeeded|failed|unknown|deferred`), ambiguity, deferral, actor, `provider_call_count` (the Meta call budget sums only this table). No FK to a location. |
| `meta_ads_accounts` | the selected ad account + Business-level Meta config: `ad_account_id`, `name`, `currency_code`, `time_zone`, `account_status`, `result_action_type` (nullable, §5.2), `monthly_budget_target_micros`, `target_cost_per_result_micros`, sync bookkeeping (as Google). One row per Business. |
| `meta_ads_campaigns` | `external_campaign_id`, `name`, `status`, `effective_status`, `objective`, `daily_budget_minor`, `lifetime_budget_minor`, `budget_remaining_minor`, `start_time`, `stop_time`. |
| `meta_ads_ad_sets` | campaign FK, `external_ad_set_id`, `name`, `status`, `effective_status`, `daily_budget_minor`, `lifetime_budget_minor`, `optimization_goal`, `bid_strategy`, `targeting_summary` (server-built, ≤255), `reach_7d`, `frequency_7d`, `frequency_window_end`. |
| `meta_ads_ads` | campaign + ad set FKs, `external_ad_id`, `name`, `status`, `effective_status`, `creative_title`, `creative_body`, `creative_thumbnail_url`, `creative_object_type`. |
| `meta_ads_daily_insights` | per-day facts at `level` `campaign|ad_set|ad` + local `entity_id`: `spend_micros`, `impressions`, `clicks`, `link_clicks`; unique `(account, level, entity_id, metric_date)`. |
| `meta_ads_daily_results` | per-day typed results: `(account, level, entity_id, metric_date, action_type)` → `results`, `result_value`. **Only action types in `config('meta_ads.result_types')` are stored.** |
| `meta_ads_sync_runs` | as Google. |
| `meta_ads_mutations` | 1:1 detail for a ledger row: `target_type` (`campaign|ad_set|ad`), `target_local_id`, `requested_state`, `dedupe_key`. No status/idempotency truth. |

Absence is `NULL`, never `0`. Account KPIs sum **campaign-level rows only** (ad-set and ad rows never added to campaign rows).

### 5.1 Money
`spend` arrives as a decimal string in the account currency's *major* unit. It is parsed **as a string** (no float) into BIGINT **micros** by `MetaAdsMoney`; more than six fractional digits is rejected as an unexpected response, never rounded silently. Budgets arrive as integer **minor units** (cents; whole units for zero-decimal currencies) and are stored as `*_minor`; display uses `CurrencyExponent`/`DashboardMoney`. Aggregates never mix currencies; the UI shows the account currency and says so when it differs from the Business currency; Ads amounts are never combined with CRM `value_minor`.

### 5.2 Results — typed, never one fake "conversion"
Meta `actions` is a typed array and different objectives produce different action types, so there is **no universal "conversions" number**. The owner chooses, in Settings, **one result type per ad account** from a short allow-list (`config('meta_ads.result_types')`, each with an owner-facing label): *Leads (on-Facebook forms)*, *Leads (website)*, *Messaging conversations started*, *Link clicks*… exactly the action types listed in config, nothing else. Sync stores only those types. Cost per result = spend ÷ results for the **chosen** type. If none is chosen: results and cost per result are **unavailable** (shown as "Choose a result type") and *no result-based rule fires*. Switching the type re-reads already-stored typed rows; it never reinterprets another type. `action_values` of the chosen type give an optional "Meta-reported value" shown only when positive and labelled as Meta's, never CRM revenue.

### 5.3 Other definitions
* **Spend** = Σ campaign-level `spend_micros`. **Impressions / link clicks** likewise. **Reach and frequency are not additive** and are never summed across days or entities; they appear only as the ad set's own trailing-7-day figures (`reach_7d`, `frequency_7d`) fetched with one non-daily insights call, labelled "last 7 days".
* **Cost per result** = spend ÷ chosen-type results; NULL when results ≤ 0 or no type chosen. **Result rate** is not offered. **CTR/CPC/CPM** are computed from stored sums, NULL when the divisor is 0.
* **Projected month-end spend**, **pacing**, **periods** (`last_7`, `last_30`, `this_month`, `previous_month`, account time zone), **cost-per-result status** vs target: same arithmetic as Google (`GoogleAdsPacingCalculator`, `GoogleAdsPeriod` are provider-neutral and reused) with `min_days` 7 and tolerance 15%.
* Every page shows "Updated {relative}" and "Data through {date}".

## 6. Sync, quota and safety

Mirrors contract 23 §5/§14 under Meta names: daily sweep (`SweepMetaAdsSyncs`, ≥20 h floor), `SyncMetaAdsAccount` job (`tries=1`), per-account claim in `meta_ads_accounts.sync_claimed_at` with heartbeat per stage and `claim_lost`, 15-minute stale claim, manual refresh throttle (60 min), eligibility (Business/workspace active, entitlement, connection `active`, account selected), per-Business hourly call budget over `business_meta_operations.provider_call_count`, bounded pages (`max_pages_per_report`) and rows, circuit breaker over the last N `meta_ads_sync` ledger operations, fetch-then-persist with **no provider call inside a DB transaction**, idempotent upserts on natural keys, truncated reports ⇒ `partial` + `row_cap`, failure keeps last good data, never zeros.

Meta-specific tuning: window 62 days (`metrics_lookback_days`); stages account → campaigns → ad sets → ads → campaign insights → ad-set insights → ad insights → 7-day frequency. Insights are paged by the `after` cursor *built by us* (the `paging.next` URL is never followed: it embeds the token). Throttling: error codes 4/17/32/613/80000-80014 ⇒ `rate_limited` (deferrable, ledger `deferred`, run `skipped`, **no inline retry**); `X-Business-Use-Case-Usage` / `X-Ad-Account-Usage` at ≥ `sync.usage_stop_percent` (85) stops the run cleanly as `partial`/`usage_high`; the next sweep is the retry. Error 190 ⇒ connection `expired`/`revoked` (above). Constants are Meta-tuned (calls budget 120/h/Business — reads cost 1 point; sweeps stagger over an hour), not copied from Google.

## 7. Mutations (Growth/Agency, `manage_meta_ads`, View As prohibited)

`MetaAdsMutationService` mirrors `GoogleAdsMutationService` (contract 23 §6): resolve the target by `uid` + `business_id` + selected account only; lock the account row; dedupe key; open ledger operation **before** the provider call; short transaction; provider call outside any transaction; `POST /{id}` with `status=PAUSED|ACTIVE`; success ⇒ local status + ledger `succeeded`; definite rejection (HTTP 400/403/404, codes 100/10/200-299) ⇒ `failed`; throttle ⇒ `deferred`; **timeout / dropped connection / 5xx / `is_transient` / codes 1,2 after send ⇒ `unknown`, never replayed**; `MetaAdsMutationReconciler` settles `unknown` rows from the next sync of that entity type (state equals the request ⇒ applied; not equal after a complete post-mutation sync ⇒ `not_applied`), never calling the provider. Valid transitions only: pause `ACTIVE→PAUSED`, resume `PAUSED→ACTIVE`; `DELETED`/`ARCHIVED` targets and ads whose `effective_status` is `DISAPPROVED`/`PENDING_REVIEW` cannot be resumed; the UI states that pausing a campaign also stops its ad sets. Nothing else is ever written.

## 8. Entitlements, permissions, navigation

* **Provider-neutral entitlement.** New `PlatformFeature::AdsModule = 'ads_module'` (Available) is the canonical *full Ads capability*. `google_ads_module` and `meta_ads_module` remain valid enum values and existing plan rows/overrides keep working: one class, `App\Library\Ads\AdsFeatureAccess`, answers "does this Business have the full Ads capability" as `ads_module` OR `google_ads_module` OR `meta_ads_module`, and every Ads gate (menu, tenancy trait, sync eligibility, mutation services — Google's included) calls it instead of testing a provider-named key. A migration inserts `ads_module` for every plan catalog row that holds `google_ads_module` (idempotent). `ads_basic_visibility` is already neutral and unchanged. Compatibility is pinned by tests (old-key-only plan, new-key-only plan, override under the old key). Known edge: an explicit workspace override that *disables* `ads_module` does not remove a still-granted legacy key (documented; no such override exists today).
* **Permissions**: `view_meta_ads` (default true, backfilled) and `manage_meta_ads` (credential-class, default false, never backfilled to true), parallel to Google's. Missing capability ⇒ 401 after tenancy (existing convention).
* **Navigation**: one sidebar parent **Ads** with children **Overview** (cross-channel), **Google**, **Meta**. Provider pages carry a provider sub-navigation (`_header` pills): Google keeps its existing pages; Meta has Overview, Campaigns, Ad sets, Ads, Recommendations, Leads, Settings. Core sees only Overview/Settings in each provider; everything else 404 without the full capability.
* Route names: cross-channel `customer.workspaces.businesses.ads.overview`; Google keeps every existing name (`...ads.index` etc.); Meta `customer.workspaces.businesses.ads.meta.*`; fixed callback `customer.ads.meta.oauth.callback` (`/ads/meta/oauth/callback`).
* **View As**: Meta business routes sit under the existing `customer.workspaces.businesses.ads.` non-GET-prohibited prefix, so every Meta POST is prohibited on registration; the callback prefix `customer.ads.meta.oauth.` is added to the prohibited and denied lists. Reads stay viewable. A client's account/token never reaches another Business's page.

## 9. Ads Overview (cross-channel)

Compact: per connected channel its own spend (this month), its own results with its own definition, its own cost per result, issue count and freshness; **total spend** shown only when every connected channel reports in the same currency, otherwise per-provider spend only; **no blended conversions, no blended CPL, no blended target**. Not-connected channels show a connect call-to-action. A "What needs attention" list merges the deterministic facts of both providers, each labelled with its provider.

## 10. Attribution, Pixel/CAPI, privacy — hard boundary

* No canonical public/visitor **marketing-consent** seam exists in this repository (contract 23 §10: capture is on by default with GPC/DNT opt-outs only — a *known release blocker* for EU deployments). Business opt-in, Business country and absence of DNT/GPC are **not** visitor consent.
* Therefore: **no Meta Pixel injection, no Conversions API forwarding, no Meta click-id (`fbclid`/`fbc`/`fbp`) capture, no new visitor identifier, no schema change to `lead_attribution_touches`.** Option B (disabled/deferred) is taken. The architectural seam is the existing provider-neutral Business OS touch model (append-only, first/last, evidence strength, unknown stays unknown); a later lane that obtains a real consent signal adds `fbclid` there, not a parallel Meta attribution table.
* The Meta **Leads** page reads *existing* touches only: leads whose first-touch `utm_source` is a Meta source tag (`facebook`, `fb`, `meta`, `instagram`, `ig`) appear as **"Campaign tags only"** with the tag text. It never names a Meta campaign, ad set or ad, and states that a tag is not proof of a paid click. Everything else is "Source not captured".
* No Meta data is imported into Contacts.

## 11. Lead Ads decision — deferred

Lead Ads retrieval needs `leads_retrieval`, Page permissions (`pages_show_list`, `pages_read_engagement`, `pages_manage_ads`), a long-lived Page token and its own App Review, plus webhook subscription and a consent/retention decision for lead PII. None of that is justified for a read-visibility V1, and a half-built connector would be worse than none. **Not built, not stubbed.** Ordinary Business OS attribution (§10) is unaffected.

## 12. Recommendations (facts, not a second product)

`MetaAdsRecommendationFactReader` returns `MetaAdsRecommendationFact` DTOs from deterministic rules; nothing is stored, nothing has a lifecycle (Opportunity Engine owns that later). Thresholds in `config/meta_ads.php` (money in account-currency micros). A rule fires only with enough evidence and never asserts causation. Result-based rules require a chosen result type.

| Type | Rule |
|---|---|
| `zero_result_spend` | campaign spend ≥ `zero_result_min_spend_micros` in the period and results = 0 (result type chosen, data present) |
| `cost_per_result_above_target` | cost per result > target × `cpr_over_factor` (needs target and results) |
| `pacing_over` / `pacing_under` | pacing status vs monthly target with ≥ `pacing.min_days` days |
| `high_frequency_weak_results` | ad set `frequency_7d` ≥ `frequency_threshold` (3.0) **and** its last-7-day cost per result is ≥ `fatigue_cpr_worsening_factor` (1.3) × the preceding 7 days (or results fell to 0 with spend ≥ min) — both windows with ≥ `fatigue_min_results` |
| `delivery_issue` | an entity whose `status` is `ACTIVE` but whose provider-reported `effective_status` is `WITH_ISSUES`, `DISAPPROVED` or `PENDING_BILLING_INFO` (a fact quoted from Meta, no inference) |
| `strong_performer` | campaign cost per result ≤ target and results ≥ `strong_min_results` (positive insight, no required action) |

No search terms, keywords or negative keywords exist in Meta. Facts carry `provider = 'meta'`; the Google fact DTO gains `provider = 'google'` additively.

## 13. Growth Center seam (documented, not built)

Growth is not on this base. `MetaAdsRecommendationFactReader` is the canonical Meta fact reader: Growth consumes facts, never calls Meta, recomputes metrics or mutates campaigns. Provider-aware identity is `{provider, type, subject_type, subject_uid, period_key}`; proposed Opportunity types `meta_ads_zero_result_spend`, `meta_ads_cost_per_result_above_target`, `meta_ads_pacing_over`, `meta_ads_pacing_under`, `meta_ads_high_frequency_weak_results`, `meta_ads_delivery_issue`, `meta_ads_strong_performer`; shared concepts map to Google's `zero_conversion_campaign` / `cpl_above_target` / `pacing_over`; `wasted_search_terms` stays Google-only. Registering them needs an RFC-002 amendment and is a separate lane. Home is not modified.

## 14. Fake provider

`FakeMetaClient` (implements the auth, read and mutation contracts) with `MetaPhotoBoothFixture`: account discovery (including a disabled and a foreign-currency account), selected account, campaigns, ad sets, ads (with a creative), 62 days of insights with paging, typed actions including types outside the allow-list, a missing-metrics campaign, pause/resume with applied-before-failing ambiguity, scripted `failNext` (429/80004, 5xx, timeout, 190 expired token), row caps, token-exchange failures. `driver=fake` is refused in production. All browser acceptance uses it; there are no live mutations.

## 15. Google coexistence

Google tables, routes, permissions, sync and tests are untouched except: (a) Google gates call `AdsFeatureAccess` (superset of the previous check); (b) the Ads sidebar and the Google header gain the provider switcher; (c) Google's own overview keeps its route and content; (d) `GoogleAdsRecommendationFact::toArray()` gains `provider`. The full existing Google Ads suite is re-run and reported by name.

## 16. Live-acceptance status

**PENDING.** No Meta app, app secret, test user or test ad account were available. Nothing in this document claims a live call succeeded. Before launch: create the Meta app, add the Marketing API product, configure the fixed redirect URI, complete Business Verification and App Review (`ads_read`, `ads_management`), then run a read-only sync against a real account and one pause/resume on a test campaign.

## 17. Deferred

Campaign creation, budget/bid/targeting/creative edits, Lead Ads retrieval, Pixel/CAPI, `fbclid` capture, `business_management` discovery, async insight reports, placement/demographic breakdowns, attribution-window selection, webhooks, per-Business shared target with provider allocations, cross-client Agency reporting, Meta-data AI features, Growth/Opportunity integration, Home exposure, provider-side token revoke, live provider acceptance.

## 18. UI shell notes (lane U1)

* **Routes** (`customer.workspaces.businesses.ads.*`): `overview` (cross-channel); `meta.index|series|campaigns.index|campaigns.show|ad-sets.index|ads.index|recommendations.index|leads.index|settings|accounts`; POST `meta.connect|settings.update|accounts.select|disconnect|refresh` and `meta.{campaigns|ad-sets|ads}.{pause|resume}`; the tenant-free `customer.ads.meta.oauth.callback`. `meta.accounts` (a GET that asks Meta for the client's ad accounts) is View-As prohibited by name, like Google's `accounts`.
* **Access**: Overview / series / Settings / connect / accounts / select / refresh need `ads_basic_visibility` OR the full module (Core connects and reads); disconnect needs no entitlement; Campaigns, Ad sets, Ads, Recommendations and Leads need the full module (Core 404). Capability after tenancy: `view_meta_ads` (reads) / `manage_meta_ads` (every state change).
* **Callback order** is fixed: signed state (404) -> product claim `meta_ads` (404) -> Business from the state only -> workspace / Business-access / active / entitlement / `manage_meta_ads` (404) -> initiator check (404) -> single-use nonce consumption -> only then a denial (`error` / `error_reason`) flash or the code exchange. A denial is a calm "Meta access was not granted" message; success lands on the account chooser, which never preselects (the first sync is queued only after an explicit selection).
* **Settings** store targets in micros of the ad-account currency (blank / zero clears to NULL) and the result type from `config('meta_ads.result_types')` only (blank = unset). A changed result type is a pointer change; stored typed rows are never rewritten.
* **Cross-channel Overview** (`AdsChannelOverviewReader`): per-channel this-month spend, own results label / definition, own cost per result, pacing, issue count and freshness; total spend only for two or more shown channels in one currency; no blended results, cost per result or targets. The merged attention list round-robins Google / Meta facts (spend ranks are in different currencies and are never compared) and is capped at 5. Google facts need the full module; Meta facts need only `view_meta_ads`; a provider block needs that provider's read capability.
* **Meta Leads** reads existing first-touch `lead_attribution_touches` only (case-insensitive exact `utm_source` in facebook / fb / meta / instagram / ig), by composition over the Google lead reader's joins (`LeadAttributionReader::page(..., $firstTouchSources)` / `leadCount()`); it states that Pixel / Conversions API / click-id capture are not enabled and why.

## 19. Verification record and corrections

**Corrections to earlier sections (the code is authoritative):**
* §3 re-authorisation: `MetaAdsConnectionManager::mayBegin()` allows a new dialog while `active` only when the token is inside the re-auth warning window (or `ads_management` was not granted); an `expired` / `revoked` / `disconnected` connection always may. A healthy, far-from-expiry connection answers "already connected".
* §6 sync: only a COMPLETE (`succeeded`) run advances `last_successful_sync_at` / `data_through_date` (Google also advances on `partial`). A partial first sync therefore reads as "never synced" with its failure code, and the next sweep retries.
* §7: pausing a campaign never rewrites child ad-set / ad rows; the next sync reports them (`CAMPAIGN_PAUSED`).
* §1 M4 / §8 navigation: the sidebar parent **Ads** has exactly Overview, Google, Meta; Google's own pages are reached through the provider sub-navigation. `customer.ads.index` (bare entry) now lands on the cross-channel Overview.
* Entitlement edge: `meta_ads_module` is now `Available` as a legacy synonym of the full Ads capability.
* Provider-specific permissions `view_meta_ads` / `manage_meta_ads` exist beside Google's; "View As" hides (not merely refuses) every control.

**Automated verification (local disposable MySQL, Fake provider only):**
* Google Ads suite after Meta: `tests/Feature/GoogleAds` 555 passed, `tests/Unit/GoogleAds` 57 passed; Google HTTP re-run after the final UI fix: 164 passed. Attribution consumers (`CooInsightAttributionTest`, `InboundAttributionTest`) 48 passed.
* Meta Ads: `tests/Feature/MetaAds` 696 + `tests/Unit/MetaAds` 51 (+3 Growth-seam) tests passed; `tests/Feature/MetaAds/Http` 198 passed after the last browser-driven fixes.
* Known baseline failures, identical on the untouched Google head `b353a736` (re-proved by name where this lane touched neighbouring code): Navigation 3 (opportunities ordering + two query-count tests), DesignSystem 4 (`ContactsCrm…AlertMarkers`, `CustomerShellNavigation…Landmarks`, `WorkspaceBusiness…` ×2), ViewAsRouteBoundary 1 (calendar-connection.* + agency.stripe.connect-existing.callback unclassified), Entitlement 19 (slot capacity 9, concurrency 4, backfill 5, raw-table scan 1). No Meta route is unclassified.

**Browser acceptance (Fake provider, `localhost`, Photo Booth Business):** Core, Growth, Agency own Business and Agency View As client; desktop and 375 px mobile (no horizontal page scroll on 12 pages), one forced dark-layout pass of the Overview. Verified: cross-channel Overview (no blended figures; total spend only when currencies match; mixed currency shows per-channel only), Google still works, Meta not-connected state, fake connect flow (v26.0 dialog, scopes `ads_read,ads_management`, no `business_management`, signed state; replay / tampered / wrong-user state → 404; denial handled), account chooser with nothing pre-selected, result-type prompt, Meta Overview / Campaigns / detail / Ad sets / Ads / Recommendations / Leads / Settings, pause and resume of a campaign, an ad set and an ad (6 ledger rows, one provider call each, no provider call on any GET), a scripted ambiguous outcome ("Pending confirmation", second click does nothing, settled by the next sync), expired-token state, exactly one "Ads" sidebar item and no Meta/Facebook/Instagram/Google Ads sidebar item in any scenario. Four UI defects found and fixed in the run (stale status chip after pause/resume; View As showing controls that could only bounce; duplicate chart-axis ticks; blank gap for a broken thumbnail), with regression tests for the first two.

**Not verified:** live Meta behaviour of any kind (§16); the real `redirect_uri` of the dialog URL beyond the HTTP-client tests (the Fake's URL builder omits it); a Meta Leads table with real tagged leads; a mobile dark pass. The shared KPI `h2` colour has poor contrast in the forced dark layout (same markup as Google; platform-level fix, not changed here).
