# Website V1 — full-site acceptance bot

Branch `qa/website-v1-full-site-acceptance`, based exactly on final Website V1
`a8cbd8a95d1306287b239b9e8574697661be253b`. Nothing here is a new Website
feature: it is a deterministic bot that builds one realistic site through the real
customer flow and judges **every page** of the result, plus the handful of
production defects the bot found (each fixed minimally, each with a regression test).

The question it answers: *if a real Photo Booth company signed up today and created
its website, would the whole site be complete, coherent, technically correct,
responsive and SEO-ready?*

## What it drives (no Website row is ever written by hand)

Fixture Business (`Jazmin Photo Booth Co.`, Chicago; second served location Naperville;
12 service areas; 4 booths + 5 event types; Essential / Signature / Luxe packages with
features; 6 FAQs; 3 sample testimonials; 8 gallery photos; a transparent logo; a
3600×2250 hero) → setup template → gallery upload → one POST per wizard screen →
logo + large hero through the Review look form → Generate (fake AI) → Preview + Studio +
Health → crawl every preview page → Publish → crawl every public page (custom domain
and platform path) → the owner lets search engines find the reviewed pages (one click) →
Publish update → the **same content in all four templates** (rebuild "look only") →
legacy-asset fallback → package-sync regression.

## Files

| Path | Role |
|---|---|
| `tests/Support/WebsiteAcceptance/PhotoBoothFixture.php` | the one realistic fixture (no lorem ipsum) and its images |
| `tests/Support/WebsiteAcceptance/AcceptanceFakeAi.php` | deterministic stand-in for the **existing** AI seam; copy built only from the request's own facts |
| `tests/Support/WebsiteAcceptance/SiteCrawler.php`, `PageDoc.php` | crawler over the app's HTTP kernel; one parsed page |
| `tests/Support/WebsiteAcceptance/SeoAudit.php`, `AuditContext.php` | the strict PASS/FAIL audit (no score) |
| `tests/Support/WebsiteAcceptance/AcceptanceReport.php` | human-readable report + machine-readable JSON (`{surface, template, page, check, status, evidence}`) |
| `tests/Feature/Website/Acceptance/WebsiteFullSiteAcceptanceTest.php`, `RunsOwnerJourney.php` | the end-to-end test |
| `tests/Unit/Website/Acceptance/SeoAuditSelfTest.php` | the audit audited: a clean control page + one deliberately broken page per rule |
| `tests/Feature/Website/WebsiteAcceptanceRegressionTest.php` | regression tests for the production defects below |

Run: `php vendor/bin/phpunit tests/Feature/Website/Acceptance tests/Unit/Website/Acceptance tests/Feature/Website/WebsiteAcceptanceRegressionTest.php`
(disposable `ultimatesms_testing_*` database only; uploads go to a throwaway public root).
Environment switches: `WEBSITE_ACCEPTANCE_REPORT_DIR` (default: system temp),
`WEBSITE_ACCEPTANCE_SAVE_HTML` (keep every crawled page), `WEBSITE_ACCEPTANCE_EXPORT_DIR`
(write each template's published site as a static, browsable copy for browser review).

## What is audited (every page, every surface)

Surfaces: **preview** (owner login), **custom domain** (the real public site), **platform
path** (`/sites/{id}`). Per page: renders (no exception / raw Blade / `undefined` /
unresolved token / placeholder / malformed punctuation / unformatted phone) · exactly one
non-empty `<title>`, useful and unique · one non-empty relevant meta description, unique ·
canonical correct (none in Preview) · robots directive consistent with the page setting ·
exactly one non-empty H1, no empty heading, no skipped level · no empty or duplicated
section · location pages carry their location, service pages their service · near-duplicate
copy between sibling pages · prices well-formed and equal to the catalog · every image
loads, is Business-owned, has declared dimensions matching the file, a meaningful alt
(decorative hero background excepted), `srcset`/`sizes` whose files exist and match their
widths, a derivative smaller than the original, hero eager + `fetchpriority=high`, logo
eager without priority, everything else lazy, no image requested twice eagerly · every
internal link resolves, no redirect loop, no UID in a public URL · slug quality and
uniqueness · JSON-LD parses, `@context`, Business identity, canonical URLs, no rating or
review markup, no internal id · Open Graph title/description · HTML weight. Site level:
every generated page crawled, none unexpected, every page reachable from header/footer,
`sitemap` valid / exactly the indexable pages / canonical host only / every URL resolves,
`robots.txt` syntax and "does not block the site". Preview and published content are
compared page by page (the inert Preview quote form is the one deliberate difference).

`GAP` means *Website V1 does not implement this* — reported, never faked, never a pass.

## Defects the bot found in the real pipeline output (all fixed, all with a regression test)

| # | Defect (where it showed) | Root cause | Minimal fix |
|---|---|---|---|
| 1 | **Broken internal links** on Preview and the platform path (location pages: "Get in touch", "See our services", "Also serving …") | the generator stores site-relative links (`/services`); the renderer left them as written, so off a custom domain they pointed outside the site (404) | `WebsiteCtaResolver::resolveLink()` + composer `pageUrls`: a site-relative link is resolved to that page's real address on the rendering surface; an unknown slug or a `//host` URL is never rendered |
| 2 | **Every generated page is noindex; sitemap empty; Studio Health silent** (it even said "search engines can index it") | documented conservative default ("noindex until the owner reviews"), but no signal and the only remedy was editing each page | Health check `indexing` ("N of M pages are hidden from search engines"); one owner action on the Pages screen (`pages.allowIndexing`) clears the flag under the Website lock; nothing goes live until Publish. The default itself is unchanged |
| 3 | Package features rendered as a **run-on paragraph** ("… - 2 hours - Digital Photo Booth - …") | the description's trailing `- ` bullets were printed as text | `services` view splits them with the existing `CatalogFeatureList` into a real list; CSS for all four templates |
| 4 | **Gallery photos after the contact block** (hero → contact → photos) | `MediaBindingService` appended the gallery / backdrops after whatever the AI wrote | built sections are inserted before the closing contact / CTA / form blocks |
| 5 | **Phone shown as `3125550147`** (announcement bar, footer, contact) | stored digits printed verbatim | `PhoneDisplay::format()` ("(312) 555-0147"); `tel:` keeps the digits; a non-NANP number is never reformatted |
| 6 | **The Hero image the owner chose on Review was ignored on Home** when gallery photos existed (Home showed a gallery photo, every other page the chosen hero) | `MediaBindingService` bound the gallery cover to the Home hero before the Brand & look hero existed | the owner's hero asset (this Website's `hero` purpose only) now wins |
| 7 | **Mobile / tablet hero photo covered only a strip** of the hero (found in the browser at 390px) | `.wd img { height: auto }` (0,1,1) beat `.wd-hero-bg { height: 100% }` (0,1,0) | `.wd .wd-hero-bg` |

## Known gaps (Website V1 does not implement them — reported, not added)

`og:image`, `og:url`, `og:type`, `twitter:card`; `FAQPage` structured data (the FAQ section
renders but emits no schema — correct to omit rather than fake); `robots.txt` has no
`Sitemap:` line (one static platform-wide file; each site's sitemap is at `/sitemap`).

## Baseline failures and SEO acceptance

The five known failures do not compromise this acceptance:
`WebsiteIndexingTest::test_robots_txt_is_untouched…` asserts the file's bytes; the committed
blob is LF (`User-agent: *\nDisallow:\n`, proven with `git show HEAD:public/robots.txt | od -c`)
and only the Windows `core.autocrlf=true` working copy is CRLF. The served rules are
semantically correct (allow all, parsed and checked by the audit with line endings
normalised). The two `WebsiteDraftPageServiceSeamTest` and the rollback failures are MySQL
JSON key-order comparisons; `test_suspended_plan_denies_website_show` is a 302 to
`/account-locked`. None touches page output, canonicals, sitemap, robots or schema.

## Live AI

This run uses a **deterministic fake AI**. It proves the pipeline and the output
architecture, not live-AI copy quality, which remains unverified until real production AI
credentials exist.
