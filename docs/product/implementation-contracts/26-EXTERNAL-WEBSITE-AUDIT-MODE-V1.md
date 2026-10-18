# 26 — External Website Audit Mode contract (V1 + Audit Overhaul / rule set 2)

Status: V1 implemented in lane `agent/niches-ads-external-website-v1`; **audit overhaul (rule set 2)** implemented in lane
`agent/external-website-audit-overhaul` on top of `origin/staging` 7c22c5a8. Phase 1 of two: **function and information architecture**. The
visual redesign (Phase 2) is deliberately not part of it; the screens use the existing design system and plain structure.

Product goal: Website means **"MotionGrove understands and improves your website"**, not "you must host it with us". For an externally hosted site that
means a **trustworthy technical audit and a prioritised, evidence-backed Website Improvement Plan** — never impressive-looking but unreliable numbers.

This amends contract 18 §8.7 (which said the audit never crawls and has no crawler). The hosted audit is unchanged; an external website is audited from a
safe, bounded crawl by its **own deterministic evaluator** (§3).

---

## 1. Website mode (primary website source)

`businesses.website_mode`: `NULL` (not chosen) · `hosted` · `external` · `none` ("do this later").

* The **address** is not duplicated. It stays in `businesses.website_url` (with its derived `canonical_domain`) and is written only through
  `BusinessManager::updateOwnBusinessProfile`, so Documents, the GBP comparison and Citations keep agreeing with it. Only the account owner may change it.
* `WebsiteModeManager::resolve()` – the stored mode; a Business that already has a `websites` row and no stored mode is `hosted`, so **existing hosted Businesses see no change at all**.
* The mode describes the **primary** site and is not exclusive.

First screen (`Website`, `mode = null` or `none`): **Build with MotionGrove** · **Use my existing website** (address, prefilled from the Business profile) · **Do this later**.

## 2. The five screens

Sidebar stays **one** Website module. Tabs: **Overview · Audit · Improvement plan · Pages · Settings**. There is no Publish, Change template, Website look,
Connect domain, Rebuild, Edit-page or "Fix" button (a test pins the words); a page link opens the owner's own page in a new tab (`rel="noopener noreferrer"`).
MotionGrove cannot edit an external site: every action is a real one — *View page, Copy recommendation, How to fix, Copy a brief, Check again, Cancel check*.

Every tab shows, above its content, the state of the check (partial or failed results are never hidden): header bar (latest completed audit time + status, current check,
*Settings*, *Check again now* / *Cancel check*) and a banner — not started · queued/running with stage, "N found, M checked" and a progress bar · failed with the cause
(access vs website vs address) · cancelled · partial with the reason. **While a new check runs the previous report stays visible**, labelled "Showing results from … A new check is running."

* **Overview** — *What should I fix first?* (top ≤ 5 plan items: severity, type, pages, what, why-it-matters, *View affected pages*, *View recommended fix*); KPIs (pages
  audited of N found, critical, high, other actionable, broken internal links as destinations + links to fix, **technical indexability** — never "indexed"); **What was checked**
  (the coverage sentence, §5); **Since the previous check** (new / resolved / unchanged on the pages both checks audited); **Not measured**; recent checks; the acquisition ideas card (contract 25).
  No universal SEO score exists; none is invented.
* **Audit** — issues grouped by rule, with filters (severity, category, type, since-last-check, search, page). Each issue expands to: what the problem is · why it matters ·
  affected URLs with **what was observed on each** (a broken link names the source page, the link text and the destination with its status) · proposed change · how to fix · how MotionGrove verifies · *Copy recommendation*.
  Duplicates (titles, descriptions, H1) are ONE group listing the real URLs; links to one broken destination are one group naming every source.
* **Improvement plan** — see §6.
* **Pages** — the audited HTML pages (path, title, HTTP, indexing assessment, canonical status, issues by priority, last successful crawl) with search, filters and sort (severity, issue count,
  title, URL, status); *not-audited* addresses (errors, blocked, redirects, robots-disallowed, excluded, not checked) listed **separately with the reason**. A page detail separates **observations**
  (final URL + redirects, status, title/description with lengths, robots directives, canonical, H1s, heading outline, images, structured data, links in and out with each destination's result) from
  **recommendations**, and states its limits (one HTML request; no JavaScript).
* **Settings** — connection (address, normalised address, effective address after redirects, last connection result, *Save and verify*, *Test connection*, *Use X as my address* when the address
  redirects to another website); audit controls (state, last started/finished, budget, frequency); audit history (every check incl. earlier addresses, with coverage and changes); management (*Stop monitoring*, switch to hosted). History is never deleted.

### 2.1 Technical findings vs business recommendations

Kept apart. Acquisition recommendations come from the Business's goals (contract 25) and appear in their own card: "these are not problems with your website".

## 3. Audit engine (changed in the overhaul)

```
HOSTED   website revision snapshot --HostedRevisionAuditSource--> SeoAuditSiteFacts ---> SeoAuditEvaluator ---> SeoAuditRuleRegistry          (UNCHANGED, §8.7 closed set)
EXTERNAL ExternalSiteCrawler --> CrawlOutcome (every URL decided on) --> ExternalAuditEvaluator --> ExternalAuditCatalog (words, severity table)
```

**Why a second evaluator.** V1 reused `SeoAuditEvaluator` for external sites. That engine's contract (≤ 8 scalar facts per finding, three severities, one sentence per rule,
no URL lists) cannot carry what an external audit must show — a broken link's source **and** destination, the pages sharing a title, redirect chains, per-page importance — and extending it would have
risked the hosted §8.7 guarantees (108 hosted audit tests). The infrastructure is reused (the crawler, `SafeFetcher`, `UrlGuard`, the three `external_site_*` tables, the runner, manager, job, scheduler);
what is separate is the *rule engine*, because it is a different product. The hosted evaluator never sees a crawl and the runner never calls it (a test pins both). `ExternalSiteAuditSource` (the V1 adapter) is
retained, unused by the runner, so earlier-format data stays interpretable; it may be removed once earlier-format crawls have aged out.

`ExternalAuditEvaluator` is **pure** (no database, clock, randomness, network or AI): the same crawl always yields the same findings in the same order. Its decisions:

* **Per page, never aggregated.** "2 H1 headings" is the number of `<h1>` elements on that one page. (V1's bug: the group description was the *first page's* sentence shown above every page — "17 main headings"
  over 17 pages; the group now says what the rule says and each row carries its own page's evidence.)
* **HTML rules run only on parsed HTML pages.** A 404, a firewall refusal, a timeout, a redirect or a PDF cannot have a "missing title".
* **A firewall's refusal is "not checked", never an outage** (§4a).
* **One group per issue.** Counts shown are issue groups (`5 pages without a description` = one issue); broken links report distinct destinations and links-to-fix separately.
* **Severity is a table** (`ExternalAuditCatalog` `priority` by page importance, below), with three explicit, commented adjustments in the evaluator for broken links.
* **Closed vocabulary, no AI.** Every sentence a person reads comes from the catalogue; a finding stores a rule key and bounded evidence. Crawled text appears only as quoted evidence. A test checks no rule promises traffic, ranking or revenue.

**Page importance** (`ExternalPageImportance`, from the address alone): `home` · `key` (contact, booking, appointment, quote, pricing, services, locations, order …) · `standard` · `utility` (privacy, terms, thank-you, search, tags …).

**Severity model.** critical — confirmed severe crawl/accessibility failure on an important page (home noindex or erroring, broken link to a contact/booking page from the home page or sitewide, redirect loop on a key page);
high — significant evidenced problems (key page noindex, mixed content on home, key page title missing, broken link on the home page); medium — meaningful optimisation (missing description on a key page, no H1 on a key page, broken link on an ordinary page);
low/opportunity — advisories (title length, alt text, extra H1, redirects, canonical absent); info/not measured — observations (crawler blocked, utility-page noindex). Each rule's table is in `ExternalAuditCatalog` (54 rules).

**Four kinds, never mixed in one count:** *problem* (confirmed technical), *improvement* (SEO), *opportunity* (growth, labelled as interpretation where it is one), *info*. Categories: Crawlability & Indexing · Metadata · Headings & Content Structure · Internal Links · Images · Structured Data · Local SEO · Performance / Not Measured.

### 3.1 What is checked (supported)

Crawl & indexing: HTTP status, redirect chains and loops, internal links to redirects, broken internal links (source + destination), HTTPS (home served over http; http→https upgrade probe), robots.txt reachability and the pages it disallows, meta robots and `X-Robots-Tag` noindex, canonical (missing / conflicting / off-site / pointing at an error, redirect or noindex page / pointing at another page), sitemap (found, parseable, URLs that error / redirect / are noindex / are blocked by robots.txt), mixed content, orphan candidates (only when coverage is complete), click depth.
Metadata: title missing / multiple / long / short / duplicate; description missing / short / long / duplicate; viewport tag; Open Graph.
Headings: H1 missing / multiple / duplicate across pages, empty headings, skipped levels. Images: alt attribute missing (empty `alt=""` is decorative, not an error), broken images and large files **only where the server states a size** (bounded probes of ≤ 25 same-site images, status + `Content-Length`, body never read).
Links: empty anchor text. Structured data: JSON-LD parsed; malformed blocks; documented required properties for LocalBusiness-family, BreadcrumbList, FAQPage, Product, Event — never a rich-result eligibility claim.
Local SEO (opportunities, heuristic, site-level — a city is never demanded of every page): no contact/booking path from the home page; phone on the pages vs the profile; LocalBusiness data when a location exists; location mentioned anywhere; thin location pages (candidates).

### 3.2 Not measured (shown as "Not measured" / "Not verified", never as a number)

Google indexing and search performance (no Search Console integration exists); Core Web Vitals / page speed / Lighthouse (no performance provider integrated); real mobile rendering or usability (HTML only; JavaScript is not run); rankings, traffic, revenue and competitor benchmarks; rich-result eligibility; microdata/RDFa; third-party image hosts; per-image weight without a stated size.

## 4. Crawl and connection behaviour

* **Discovery** (bounded): the home page → robots.txt → sitemap(s) (named in robots.txt, else `/sitemap.xml`, `/sitemap_index.xml`, `/wp-sitemap.xml`; an index is followed one level) → internal links breadth-first → canonical targets. Links are fetched before sitemap-only addresses.
* **Equivalent addresses count once** (`UrlResolver::pageKey`): scheme, `www.`, trailing slash, fragments and campaign parameters (`utm_*`, `fbclid`, `gclid` …) are ignored; path case is kept (the server may differ).
* **Not a page** (`UrlClassifier`, decided before any request): files (`.pdf .jpg .js …`), technical endpoints (`/cdn-cgi/…` including `email-protection`, `/wp-json`, feeds, `/api`), private/transactional areas (`/wp-admin`, login, account, cart, checkout) and search/sort/filter query variants. Recorded as *excluded* with the reason; never fetched, never audited.
* **Every URL decided on is recorded** (`CrawledPage.kind`): audited `html` · `redirect` · `non_html` · `error` · `blocked` · `unreachable` · `robots_disallowed` · `excluded` · `skipped` (budget/time/cancel). Each URL is in exactly one bucket, so the coverage buckets add up to "addresses found".
* **4a. Bot protection** (`BotProtectionDetector`): 401/403/429, `cf-mitigated: challenge`, a 503 with `Retry-After`, or a short page carrying a strong challenge marker is **`blocked`** — reported as "our crawler was turned away", counted apart, never judged, never a failure of the website. A blocked **home page** ends the check as *failed — crawler blocked* (`failure_code=crawler_blocked`, indexability *Not verified*) with the fix (allow the `MotionGroveSiteAudit` user agent). The crawler never tries to get past protection, never submits forms, never signs in.
* **Connection result** (`ConnectionResult`, stored on the crawl and shown in Settings): `reachable` · `redirects_elsewhere` (the other website is **not** contacted; *Use X as my address*) · `crawler_blocked` · `robots_blocked` · `http_error` (confirmed after one retry) · `tls_error` · `network_error` (DNS/timeout — "may be temporary or specific to our servers", never "visitors cannot see you") · `invalid_address`.
* **Indexability wording.** "No obvious indexing block detected" — never "indexed" and never "search engines can find your site". "Not verified" without Search Console.

### 4.1 Lifecycle and reliability

`queued → running → completed | partial | failed | cancelled`, with `stage` (connecting · discovering · crawling · analyzing), live counters and a heartbeat refreshed after every URL.
* *completed* — everything eligible within the budget was audited; *partial* — useful results, incomplete coverage (`finish_reason`: `page_limit`, `time_limit`, `some_pages_blocked`, `some_pages_unreachable`); *failed* — nothing useful, with `failure_code` and `connection_state`.
* **One active crawl per Business, enforced by the database**: `external_site_crawls.active_lock` (= business id while queued/running, NULL after) under a UNIQUE index. Two simultaneous "Check again" clicks cannot both insert; the loser is handed the winner's crawl. Changing the address cancels a check still running for the previous address.
* **Recovery**: a queued crawl nobody picked up is failed after 15 minutes; a running crawl with no heartbeat for 10 minutes is failed (`stale`); a failed/timed-out job (`failed()`) releases the lock; every failure path releases it.
* A manual check is throttled to once per 5 minutes (so a fix can be verified promptly); scheduled (weekly, `website:recrawl-external`) and address-change checks are not.
* **Live progress without reloads or hammering**: the page follows a running check by fetching `GET …/external/status` (one small JSON row) every 4 s, backing off to 15 s, paused while the tab is hidden, abandoned after 15 minutes; when the check ends the same page is fetched once and `#external-live` swapped in place. Without JavaScript every button still works as a normal form.
* **Never overwrite history.** A new check writes its own pages, links and findings. Full detail is kept for the latest 10 checks; older checks keep their **summary row** (date, address, status, counts) forever.
* **Address scoping.** "Current results" are read only from crawls of the Business's *current* host (www and bare are one site); a crawl of an earlier address stays in the history, labelled "earlier address", and is never shown as the current site's results nor compared with them.

## 5. Coverage numbers

`pages_discovered` is every address found (including addresses recorded but not audited and `omitted` ones beyond the storage bound). The distinct buckets: **audited · redirected · errors · blocked · unreachable · robots-disallowed · excluded (files, technical, private, parameter variants) · not checked (limit reached)**. The Overview sentence reads, for example: "We found 35 addresses and audited 19 web pages. The rest: 4 addresses that redirect to another page, 2 pages that answered with an error, 6 files or technical addresses that are not web pages, 3 pages not checked because the page limit was reached." Orphan candidates are never reported from an incomplete crawl.

## 6. Website Improvement Plan

Built deterministically from the findings (`ExternalImprovementPlan`); every item carries a **proposed change**, the **exact affected URLs** with what was observed, **why it matters for a local business** (the catalogue reason plus which contact/booking/home pages it touches — no traffic, ranking or revenue projections), **how to implement** (for the owner's own editor or developer), **how the next check verifies it**, and tracking.
* **Sections keep known apart from suggested:** Confirmed technical problems · SEO improvements · Growth opportunities · Observations (nothing to fix on the site).
* **Order** (`ExternalWebsiteReader::rank`, shared with the Audit list): priority → touches a contact/booking page → touches the home page → problems before improvements before opportunities → area (crawl access, links, metadata …) → pages affected. One booking-page problem outranks thirty blog-page issues of the same priority (tested).
* **Tracking** (`external_site_plan_items`, per Business and rule, surviving across checks): Not started · In progress · Waiting on my developer or helper · I made this change · I will not do this, with a note. "Done" only means the owner says so; **MotionGrove confirms a fix only when the next *completed* check no longer finds the rule** (`resolved_in_crawl_id`; a partial check verifies nothing; the confirmation is withdrawn if the rule returns).
* **Asking for help without pretending to control the CMS:** *Copy a brief for my web developer* (a plain-text work order) on every item, *Copy the whole plan*, and — when the Business sits under a white-label Agency with a support email — *Email this to my support contact* (`mailto:`, nothing is sent by MotionGrove). No fake "Fix now".

## 7. Data model (additive migration `2026_11_21_100001`)

`external_site_crawls` + `final_url, connection_state, connection_http_status, stage, finish_reason, page_budget, pages_audited, progress_discovered/checked, coverage(json), summary(json), active_lock(unique), heartbeat_at, details_pruned_at`;
`external_site_pages` + `kind, final_url, redirect_chain, depth, source, in_sitemap, meta_robots, x_robots, indexable, importance, inlink_count, evidence(json, bounded, no HTML)`;
`external_site_findings` + `finding_key (stable: rule + page key + scope), group_key, category, kind, priority, confidence, evidence(json)`;
new `external_site_links` (source page → destination, anchor; ≤ 150 per page, ≤ 6000 per crawl) and `external_site_plan_items`. Nothing existing is altered or removed; an earlier-format crawl is labelled as such and offers *Check again*.
Legacy `critical_count / warning_count / info_count` are kept: critical, high + medium, low + info issue groups.

## 8. Crawler security (SSRF) — unchanged guarantees

This is server-side fetching of a customer-supplied address. Layers, each tested:

1. **`UrlGuard`** — every URL (start page, each redirect hop, each discovered link, robots.txt, each sitemap, each probed image) is validated from scratch: http/https only; no credentials; **no IP literal in any spelling**; no `localhost` / `.local` / `.internal` / `.lan` / `.home` / `.corp` / single-label / `metadata.google.internal`; ports 80 and 443 only; the name must resolve and **every** resolved address (A and AAAA) must be public.
2. **`IpClassifier`** — deny list (RFC 1918, loopback, link-local incl. the 169.254.169.254 metadata endpoint, CGNAT, reserved/documentation ranges, multicast, IPv6 equivalents, mapped/NAT64 judged by the embedded IPv4).
3. **Destination pinning (TOCTOU/DNS-rebinding-safe).** The validated address is what the transport connects to (`CURLOPT_RESOLVE`), Host/SNI/certificate verification stay on the real name, and the connected address is compared with the pinned one afterwards. Because **every** request re-validates, a DNS answer that turns private mid-crawl is refused before the request (tested).
4. **Redirects are manual**, each hop re-validated, bounded, repeat-detected (`redirect_loop`), and may stay only on the audited site (start host + www/non-www twin). A redirect to another domain is reported, not followed; a hop to a private address is refused and never contacted.
5. **Bounded while reading**: bytes counted on the decoded stream; content type checked before the body; connect/total timeouts; header bytes capped; sitemap XML refuses DOCTYPE/ENTITY; HTML parsed with the network disabled; only normalised values leave the extractor. Size probes read at most a few bytes.
6. **No side effects**: GET only, no cookies, no proxy, no forms, no authentication, no bot-protection evasion, one request at a time with a politeness delay, `robots.txt` obeyed (images too), Blueprint Safety Mode honoured.
7. **Failures** store a short reason code only — never an exception message, header or body.

`config/external_site_audit.php`: 40 pages per check · 1.5 MB per response · 5 s connect / 10 s request · 5 redirects · 120 s total · 250 ms between URLs · sitemap ≤ 3 files / 2 MB / 200 URLs · ports 80/443 ·
weekly re-check (`website:recrawl-external`, daily 04:20, `--limit` per run) · manual re-check every ≥ 5 min · 10 checks' detail kept · stale windows 15/10 min · ≤ 400 URLs recorded per check · ≤ 25 image probes ·
deep-page advisory at 4 clicks. `EXTERNAL_SITE_AUDIT_DRIVER=fake` serves a built-in fixture site for tests and browser acceptance and is refused in production.

## 9. Deferred (deliberate)

* **Editing the external site / "Apply fix"** — needs per-CMS integrations; not built. No fake buttons exist.
* **Search Console data and a performance provider** — none is integrated; shown as *Not verified* / *Not measured*. A separate task.
* JavaScript-rendered pages; authenticated or staging sites; microdata/RDFa; a user-adjustable crawl budget (the value is shown, fixed per environment).
* Browsing the *detail* of older checks (only the latest report is browsable; older checks keep their summary); comparing non-comparable checks beyond the pages both audited.
* Phase 2: the visual redesign of these screens.
