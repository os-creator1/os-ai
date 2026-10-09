# 26 — External Website Audit Mode V1 contract

Status: implemented in lane `agent/niches-ads-external-website-v1`.
Product goal: Website means **"MotionGrove understands and improves your website"**, not "you must host it with us".

This amends contract 18 §8.7 (which said the audit never crawls and has no crawler). The hosted audit is unchanged; an
external website is now audited by **the same engine** from a safe, bounded crawl.

---

## 1. Website mode (primary website source)

`businesses.website_mode`: `NULL` (not chosen) · `hosted` · `external` · `none` ("do this later").

* The **address** is not duplicated. It stays in `businesses.website_url` (with its derived `canonical_domain`) and is written only through
  `BusinessManager::updateOwnBusinessProfile`, so Documents, the GBP comparison and Citations keep agreeing with it. Only the account owner may change it.
* `WebsiteModeManager::resolve()` – the stored mode; a Business that already has a `websites` row and no stored mode is `hosted` (backfilled by the
  migration and re-derived defensively), so **existing hosted Businesses see no change at all**.
* The mode describes the **primary** site and is not exclusive: an `external` Business may later own MotionGrove-hosted campaign landing pages
  (no rule forbids a `websites` row for it; hosted routes are not blocked, merely not offered).

First screen (`Website`, `mode = null` or `none`): **Build with MotionGrove** → *Build my website* (the existing guided creation) ·
**Use my existing website** → address + *Connect existing website* · **Do this later** (nothing is set up; the choices stay available).
Hosted mode is exactly the pre-existing flow (wizard, templates, preview, Studio, publish, domains, package sync, health).

## 2. External Website UX

Sidebar stays **one** Website module. Tabs: **Overview · Audit · Pages · Settings**. There is no Publish, Change template, Website look,
Connect domain, Rebuild, Edit-page or "Fix"; a page link opens the owner's own page in a new tab (`rel="noopener noreferrer"`).

* **Overview** – URL, "External website", last checked, pages found, critical issues, search issues, broken links, search-visibility status, and
  **What should you fix next?** (the most severe, most widespread issue, with *Review affected pages*). A *Check again now* button (throttled).
* **Audit** – every issue grouped by rule (severity then pages affected), the affected pages, *Open page*, *Show me how to fix this* (plain steps
  for any editor – WordPress, Wix, Squarespace, a developer), filterable by rule.
* **Pages** – URL, title, search visibility (Can be found / Hidden from search / Could not load), issue count, last checked; a page detail shows its
  facts and findings, with *Copy suggested title* (a mechanical "Topic | Business name", ≤ 60 characters, never a claim).
* **Settings** – address, recent checks, switch to a hosted website or stop checking. Weekly re-check and re-check on address change.

Language: *Open page · Show me how to fix this · Copy suggested title · Review issue*. "Apply fix" belongs to a future CMS integration and is **not built**.

### 2.1 Technical findings vs business recommendations

Kept apart on purpose. Technical findings come only from the one audit engine over crawled facts. **Acquisition recommendations** come from the
Business's goals (contract 25): *"Student Enrollment has no dedicated landing page"* / *"Teacher Recruitment has no dedicated landing page"* appear in a
separate card ("these are not problems with your website"), never as a fabricated technical error, together with the niche's suggested pages. Each
goal's destination (hosted page or the owner's external URL) is configured separately under Ads > Goals & economics.

## 3. One audit engine

```
HOSTED   website revision snapshot --HostedRevisionAuditSource--> SeoAuditSiteFacts --\
                                                                                     >-- SeoAuditEvaluator --> SeoAuditRuleRegistry (words)
EXTERNAL crawler --> external_site_pages --ExternalSiteAuditSource--> SeoAuditSiteFacts -/
```

* `SeoAuditPage` is the **read DTO** of the hosted audit screen, not evaluator input, so it was not extended. The source-neutral evaluator input is the new
  `SeoAuditSiteFacts` / `SeoAuditPageFacts` (only values the rules need – never a DOM, body, header or snapshot section). `SeoAuditEvaluator::evaluate()` keeps its
  original hosted signature and normalises first; `evaluateFacts()` is the one rule path.
* **One evaluator, one registry, one finding vocabulary.** There is no `ExternalSeoEvaluator`, `ExternalSeoRules` or `ExternalWebsiteAuditEngine` (a test pins this).
* **External-only rules** (`page_not_reachable` critical, `broken_internal_link`, `h1_missing`, `h1_multiple`, `canonical_missing`, `open_graph_missing`,
  `structured_data_missing`) live in the same registry in a separate table (`EXTERNAL_RULES`). They fire only from facts a **crawl** supplies; the hosted source never
  supplies them, so §8.7's G-2/G-3 stands (the platform emits canonical / Open Graph / structured data on a hosted site, so they can never be findings against a hosted customer).
  `ruleKeys()` – the closed hosted set – is unchanged; rule-set `VERSION` stays 1 and hosted stored runs are untouched. 108 hosted audit tests pass unchanged.
* Storage: `external_site_crawls`, `external_site_pages` (normalised values only), `external_site_findings` (rule key + severity + ≤ 8 scalar facts – no prose column).
  A hosted audit is keyed by an immutable revision; an external site has none, so the tables are separate, the vocabulary shared.

## 4. Crawler security (SSRF)

This is server-side fetching of a customer-supplied address. Layers, each tested:

1. **`UrlGuard`** – every URL (start page, each redirect hop, each discovered link, robots.txt, each sitemap) is validated from scratch: http/https only;
   no credentials; **no IP literal in any spelling** (127.0.0.1, `[::1]`, decimal `2130706433`, hex `0x7f000001`, octal, short `127.1`, even a public IP – a customer site is reached by name);
   no `localhost` / `.localhost` / `.local` / `.internal` / `.lan` / `.home` / `.corp` / single-label / `metadata.google.internal`; ports 80 and 443 only; the name must resolve and
   **every** resolved address (A and AAAA) must be public – one private record among public ones is a refusal.
2. **`IpClassifier`** – deny list: 0/8, 10/8, 100.64/10, 127/8, 169.254/16 (cloud metadata), 172.16/12, 192.0.0/24, 192.0.2/24, 192.88.99/24, 192.168/16, 198.18/15, 198.51.100/24,
   203.0.113/24, 224/4, 240/4 (broadcast); IPv6 `::`, `::1`, mapped (`::ffff:`) and NAT64 judged by the embedded IPv4, 100::/64, 2001::/23 (Teredo), 2001:db8::/32, 2002::/16, fc00::/7, fe80::/10, fec0::/10, ff00::/8.
3. **Destination pinning (TOCTOU-safe).** The validated address, not the hostname, is what the transport connects to: `CurlExternalSiteTransport` sets `CURLOPT_RESOLVE host:port:ip`
   so cURL never asks DNS again, while the URL keeps the hostname (Host header, TLS SNI and certificate verification stay on the real name; peer and host verification are on). After the
   transfer the connected address (`CURLINFO_PRIMARY_IP`) is compared with the pinned one. A real-socket test proves a name that resolves nowhere connects only through the pin.
4. **Redirects are manual**, each target re-validated, bounded by `max_redirects`, and may stay only on the audited site (start host and its www / non-www twin); a hop to another domain is `foreign_domain`.
   A public-looking URL redirecting to `127.0.0.1`, `169.254.169.254` or a name resolving privately is refused **at the hop** and never contacted (tested).
5. **Bounded while reading**: response bytes are counted on the decoded stream and the transfer aborted at the limit (a compression bomb is cut at the same cap); content type is checked
   before the body is read; connect and total timeouts; header bytes capped; sitemap XML refuses any DOCTYPE/ENTITY and never touches the network; HTML is parsed with the network disabled and only
   normalised values leave the extractor.
6. **No side effects**: GET only (never a form submission, never an authenticated request), no cookies sent or kept, no proxy, protocols restricted to http/https, no credentials,
   one request at a time with a politeness delay, `robots.txt` obeyed (our token's group first, else `*`), the Blueprint Safety Mode `http_request` guard honoured.
7. **Failures** store a short reason code (`private_destination`, `dns_failed`, `too_large`, `timeout`…) only – never an exception message, header or body; nothing remote reaches a log.

`config/external_site_audit.php` (documented conservative V1 values): 40 pages per crawl, 1.5 MB per response, 5 s connect / 10 s request, 5 redirects, 120 s total per crawl, 250 ms between pages,
sitemap ≤ 3 files / 2 MB / 200 URLs, ports 80/443, weekly re-check (`website:recrawl-external`, daily 04:20, `--limit` per run), manual re-check throttled to once per 15 minutes, 5 crawls retained.
**Abuse bounds:** one active crawl per Business; a request never carries a URL (only the stored `website_url` is crawled); a stuck crawl is failed after 30 minutes; the queued job has one try.
`EXTERNAL_SITE_AUDIT_DRIVER=fake` serves a built-in fixture site for tests and browser acceptance and is refused in production.

## 5. Deferred (deliberate)

* **Editing the external site / "Apply fix"** – needs per-CMS integrations (WordPress, Shopify…); not built.
* JavaScript-rendered pages (the crawler reads server HTML only); authenticated or staging sites.
* Rank / Search Console data for the external site (the SEO module's own seams).
* An allow-list of acceptable certificate authorities beyond the system store; per-Business crawl budgets by plan.
* A Growth *rule* over the new external facts (the facts are exposed, contract 25 §9).
