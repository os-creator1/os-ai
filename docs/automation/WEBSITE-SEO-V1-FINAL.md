# SEO V1 final — product-wide SEO audit, repair and acceptance

Branch `agent/seo-v1-final`, based on `origin/agent/v1-completion-integration` (6c42beb3).
No PR, no merge to main.

This document records what the audit found, what was changed, what the behaviour now is (the
contract later work must keep), and what is deliberately left open. The audit covered everything
that can materially change a customer's organic or local search presence: the generated Website,
its crawl files, structured data and images, Website Health, the SEO module (Overview, Keywords,
Rank Tracking, Citations, Reviews, Site Audit), Google Business Profile seams, Forms, Packages,
Niche Blueprint defaults, Growth SEO facts, tenancy, entitlements and provider cost safety.

## 0. Base correction

The integration head did **not** contain the four canonical templates, Website Health, the CTA and
navigation work or the Website acceptance crawler. They existed only on the unmerged
`qa/website-v1-full-site-acceptance` branch (241fcf73). That branch was merged first (two
conflicts: `AppServiceProvider` keeps both the Blueprint safety mode and `WebsitePageComposer`;
`public/website/page.blade.php` keeps the template header and carries the integration branch's
"More" navigation menu into the legacy header). Everything below is built on that merged tree.

## 1. Public Website contract

### 1.1 One address per page

* A page is served at exactly one URL. `/about/` answers a **301** to `/about` (query string kept).
  Slug matching is strict (`/010` and `/1e1` never answer the page whose slug is `10`).
* A published snapshot records `redirects` (old path → new path), computed at publish time by
  `WebsiteRedirectMap` from the live revision and matched by the page's permanent uid. A renamed
  page, or a page promoted to homepage, answers a **301** from its old address instead of a 404.
  Chains collapse to the final address; entries whose target no longer exists, or whose source is
  a live page again, are dropped; the map is capped at 200 entries. Pages deleted outright, or
  recreated by a rebuild (new uid), answer a plain 404. **Rollback limit:** a rollback serves the
  older revision's own stored map, so an address created only by a rename made *after* that
  revision answers 404 until the owner publishes again (and the next publish is computed from the
  rolled-back revision, so that intermediate address is not re-recorded).
* A demoted homepage gets a real, valid, unique slug (it used to keep `NULL`, which made every
  platform-path URL throw and put `/` in the sitemap twice). Publishing refuses any non-home page
  without a valid, unique slug.
* The real `public/` directories and files (`images`, `css`, `js`, `fonts`, `vendors`, `voice`,
  `installer`, `main`, `storage`, …) are reserved slugs: the web server answers those before PHP.
* Generated slugs are bounded (`WebsiteSlugRules::bounded()`): at most 80 characters, never empty,
  never ending in a hyphen; two saved locations in one city get `-2`; the owner's custom section
  can no longer collide with a fixed page (`customSectionSlug()`). One bad name no longer fails a
  whole generation.

### 1.2 Hosts

| Surface | Behaviour |
| --- | --- |
| Custom domain (Active, primary) | Serves the site, `index, follow` unless the page is noindex. |
| Alias domain | `301` to the primary. The first domain that goes Active while no Active domain is primary becomes primary (otherwise a stuck first domain left the whole site 404). |
| Platform path `/sites/{id}` with an Active primary domain | `301` to the same page on the domain. No noindex-plus-canonical mixed signal. |
| Platform path with no domain | Renders, `noindex, follow`, no canonical, no `og:url`, no sitemap (`/sitemap` 404s). |
| Owner Preview | Behind login, `noindex`, no canonical, no sitemap. |

A custom domain's 404 is the customer's own minimal page (their name, a link to their home, `noindex`),
not the platform error view whose links point at the platform login. A maintenance window keeps its
`503` and `Retry-After` (the global handler used to turn every `HttpException` into a 404).

### 1.3 robots.txt and sitemap.xml

The static `public/robots.txt` was removed. A checked-in file picked up CRLF on a Windows checkout and a
static file can never carry a per-domain `Sitemap:` line, because the web server answers it for every host.
`WebsiteCrawlFiles` now generates both files for every host:

* Platform host `/robots.txt`: `User-agent: *` / `Disallow:` (blocks nothing — CSS, JS, images and
  noindex pages must stay crawlable), LF line endings, `text/plain`.
* Custom domain `/robots.txt`: the same, plus `Sitemap: https://<domain>/sitemap.xml` **only when at
  least one page is indexable**.
* Custom domain `/sitemap.xml` (and the older `/sitemap`): the canonical `https://<domain>/…` URL of
  every published, indexable page, de-duplicated; no preview URLs, no noindex pages, no redirects.
  A site with nothing indexable answers **404** (an empty `<urlset>` is invalid). No `lastmod`: a
  snapshot knows when the whole site was published, not when each page changed. The protocol limit
  (50,000 URLs) is enforced in code; a Website is capped at 20 pages, far below it.

### 1.4 Head: title, description, social, canonical

One class, `WebsiteHeadMeta`, decides the head for the owner preview, the platform path and the
custom domain (the single `public.website.page` layout is shared by all four templates).

* **Title:** the page's `seo_title` (or its name), plus ` | <Business>` **only when the business name
  is not already part of it and the whole title still fits in 70 characters**. It used to render
  `Services | Acme — Acme`. The starter generator now shortens the *page* part, never the business
  name, and cuts descriptions at a word boundary with an ellipsis.
* **Description:** present only when the page has one. An empty `content=""` is never emitted and no
  site-wide fallback is invented. Website Health flags the missing ones.
* **Social:** `og:title` (== title), `og:description` (== description), `og:type=website`,
  `og:site_name`, `og:url` (== canonical, only where a canonical exists), `og:image` (the owner's own
  hero, else logo, widest derivative up to 1280 px, rebased to the customer's domain — never a
  placeholder), `og:image:alt`, `twitter:card` (`summary_large_image` with an image, else `summary`).
* **Canonical:** exactly one on a custom-domain page, absolute https on the primary domain, built from
  the slug (a query string never changes it); none on Preview or the platform path.
* **Photos on a custom domain are same-origin.** A snapshot freezes photo URLs on whichever host
  published it; `WebsiteAssetUrls::rebase()` rewrites them per request to the customer's own origin, so
  pages do not hot-link the platform host and a platform host change cannot break an old revision.

### 1.5 Structured data

JSON-LD is emitted only on indexable pages of an Active custom domain.

* **LocalBusiness** — one site entity: the same `@id` (`https://<domain>/#business`) and the site root
  `url` on every page (it used to repeat with each page's own URL). Name, telephone, email and
  address only when the owner shows them and the privacy gate allows; the logo is the owner's
  published logo. Never `aggregateRating`, `review`, `geo`, `priceRange` or `sameAs` (nothing real
  feeds them).
* **BreadcrumbList** — built from the **same trail** as the new visible breadcrumb
  (`WebsiteBreadcrumbStructuredData::trail()`), with the navigation's own labels (`Austin`, never
  `Serving Austin` or a headline). Location pages have no hub page, so their trail is Home › Location.
* **FAQPage** — only on a page that renders an FAQ section, listing exactly its questions and answers
  (blank entries skipped, duplicates once). Never site-wide.
* No Service/Product/Offer schema: packages show prices in the page text and no price is in the
  JSON-LD, so there is nothing to mismatch. Adding it was judged semantically unsupported by the
  current data.

### 1.6 Indexing intent

* Generated pages start hidden from search (noindex) until the owner has read them.
* `website_pages.noindex_by_owner` records a page the owner hid on purpose (the Pages form). The one-click
  "let search engines find these pages" action releases only generated-default pages and never touches
  an owner-hidden one.
* `websites.indexing_released_at` records that action. A rebuild keeps the pages open once the owner
  has done it, and keeps owner-hidden pages hidden (matched by address). A first generation, or a site
  never released, stays hidden. Migration `2026_11_05_090001`.

### 1.7 Generated content grounding

AI copy may describe the business but may not invent social proof or prices:

* A `testimonials` section carries only the owner's confirmed testimonials, verbatim, or is dropped.
* A service card's `price_label` survives only when that exact label is already in the facts the plan
  was built from (or the card is a catalog item).
* The prohibited-claims check now matches against unescaped text (`24/7`, accented words).
* An address reaches the AI only when the published site would also show it (the privacy gate).
* Page ceiling (14 pages, 8 area pages) and the area-page distinctness gate are unchanged.

### 1.8 Conversion links

The "Book now" button points at the platform host (`config('app.url')`); `route()` used to build it on
the *current* host, which on a custom domain served a 404 on every page.

## 2. Website Health (owner-facing)

`WebsiteHealthChecker` reads the **live published snapshot** for anything search engines see and the
draft for what the owner is about to publish, and never reports good for something that is not true on
the live site. Wording is *Good / Needs attention / Action*; each check can list the affected pages or
photos behind "Show details".

| Check | What it reports |
| --- | --- |
| Published / Unpublished changes | Which pages were edited, added or removed since the live revision. |
| Your own web address | Active primary domain or not. |
| Pages visible to search engines | `N of M live pages are hidden` (live, not draft), noting when the owner already released more but has not published. |
| Titles / Descriptions | Missing, duplicate and over-long (as rendered, with the business name). |
| Page headings | Exactly one main heading per page. |
| Photos / Photo descriptions | Photos used on a page but missing from the library; missing alt text. |
| Links between pages | Buttons pointing at a page that does not exist (they are hidden on the live site). |
| Contact details / Main button | Phone and email actually shown (the toggles), a working main button. |
| Service pages / Package prices / Local details | Unchanged semantics; package prices are not "matching" before the first publish. |
| What search engines are told | Live sitemap and robots state (needs a domain and at least one indexable page). |
| Business details for search engines | The live structured data has a name and contact details. |

## 3. Automated SEO audit

The existing harness (`tests/Support/WebsiteAcceptance/*`) was extended, not duplicated. It crawls the
published custom domain in each of Bold Event, Classic Gold, Midnight Gold and Party Luxe, and the
owner Preview, and asserts for every page: status, canonical, robots meta and `X-Robots-Tag`, sitemap
membership, title and description uniqueness, brand-once and title length, one H1 and heading order,
structured data (valid JSON, one site entity, no invented ratings or ids, breadcrumb equals the
visible trail, FAQPage only where an FAQ renders and equal to it), social tags (`og:url` equals the
canonical, `og:image` is an owner photo that exists), internal links, image dimensions/alt/derivatives,
near-duplicate content, redirects (trailing slash, query string, real 404, no loops) and that the
platform path redirects once to its canonical page. `tests/Unit/Website/Acceptance/SeoAuditSelfTest.php`
proves each rule fails on a deliberately broken page. Result: **0 FAIL, 0 GAP** (the former
`og:*`, `FAQPage` and `robots Sitemap` gaps are now assertions).

## 4. Known limits (documented, not defects)

* `www` / apex pairing is not automatic (`www_redirect_type` is `none` and the domain screen asks for
  one hostname). Each hostname the owner attaches is served; an unattached variant does not resolve.
* HTTP → HTTPS is expected from the web server / Forge, not the application.
* No `Cache-Control` / ETag on public pages.
* Legacy (non-template) sites keep their original hero as a CSS background image.
* Gallery photos without a title share one generated alt text; owners can edit each.
* Real-AI copy quality cannot be verified without credentials (the acceptance bot uses a deterministic fake).

## 5. Reconciliation with the canonical completion head (2888c567)

The completion head and this lane had independently built overlapping pieces. One implementation won
for each, the other was removed, and callers and tests were moved to the winner:

| Concern | Canonical implementation (kept) | Removed |
| --- | --- | --- |
| Owner-intent indexing | `website_pages.noindex_explicit` + `WebsiteSearchVisibility::release()` (head). `websites.indexing_released_at` (migration `2026_11_05_090001`) stays as a *different* fact: it records that the owner released indexing, so a rebuild keeps pages open and keeps `noindex_explicit` pages hidden. | `website_pages.noindex_by_owner` |
| robots.txt / sitemap | `WebsiteCrawlFiles` is the one generator. Platform host: `Public\RobotsController` (route `public.robots`). Custom domain: `ResolveCustomDomainWebsite` serves `/robots.txt` (Sitemap line only when something is indexable) and `/sitemap.xml`; `/sitemap` 301s to `/sitemap.xml`. No static `public/robots.txt`. | `WebsiteController@robots`, the head's always-on `renderRobots`, the extensionless sitemap authority |
| FAQPage | `Website\Seo\WebsiteFaqStructuredData` (injected instance): every visible Q/A pair in order, whitespace-normalised. One JSON-LD block in the layout. | the static builder and the second layout block |
| Head metadata | `Website\Seo\WebsiteHeadMeta` (title brand once and <= 70 characters, description only when real, og/twitter incl. image size, alt and `twitter:image`, image only if the file exists, none of the extras in Preview). | `WebsiteSocialMetadata` |
| Growth rank facts | The head's `GrowthRankFactReader` + `RankRules`. Growth Center stays the lifecycle authority; this lane keeps only the deterministic SEO facts readers. | this lane's rank reader, `seo.meaningful_rank_drop` / `seo.rank_just_outside_top_10` rules and their tests |

### Secondary Location address

A Website has one physical-address authority, the primary Location. `WebsiteLocationPageAddress` allows a
`serving-*` page to show an address only when it is the primary Location's own page. Every other
`serving-*` page (a secondary Location, or a service-area page that implies no storefront) carries **no**
street address in the page or in its LocalBusiness data. Applied when publishing (snapshot), at render
(custom domain and platform path) and in the owner Preview, so Preview equals the published page.
Regression test: `WebsiteSeoV1FinalTest::test_a_secondary_location_page_never_shows_the_primary_locations_address`.
