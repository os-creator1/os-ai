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

## Gaps found by the acceptance, closed in the final closure pass

`og:image`, `og:url`, `og:type`, `twitter:card` (now emitted on a published page; `og:image` only when a
Business-owned hero file really exists); `FAQPage` structured data (now built from the very FAQ the page
renders, only on an indexable page, only for complete question + answer pairs); `robots.txt` with a
`Sitemap:` line (per-site, see below). The audit now **requires** all of them (`faq_schema_matches_visible_faq`,
`social_*`, `robots_sitemap_line`) instead of reporting a GAP.

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

## Final closure pass (branch `agent/website-v1-final-closure`, based on `agent/v1-completion-integration`)

The integration branch did not contain Website V1 final, this acceptance lane or the Forms builder final; they were
merged in first (conflicts: `AppServiceProvider` — both kept; `public/website/page.blade.php` — the template-aware
layout, with the integration's "More" nav menu carried into the legacy header branch).

### Forms placement (Website consumer of the Forms embed seam)

* Section `forms_module_form` = `{heading?, forms_module_deployment_uid}`; the uid is the stable public uid of a
  Website-source `FormDeployment` (never a raw id, never a URL). Chosen in the page editor ("Form from the Forms
  module"), from `WebsiteFormsModuleReferences::options()` — enabled references of THIS Business at Locations the
  owner can see; a stale selection stays visible as "Unavailable" instead of silently vanishing.
* Draft and publish validate the uid against this Business's references (`knownUids`); a foreign / unknown / direct-link
  uid is refused, and the guided AI can never write the section.
* Publish freezes `FormWebsiteEmbed::resolve()` into the snapshot (`data.resolved`, MySQL key order); a reference that no
  longer resolves is frozen as null and renders nothing (no empty band). Preview re-resolves live. Studio Health warns
  ("Forms on your pages") when a placed form no longer resolves.
* The renderer includes `public.forms._embed` — an iframe whose `src` is the platform's own `public.forms.show` route for
  that reference; content can never supply a URL or markup. The Location travels with the reference; a submission is an
  ordinary Forms submission (`source=website`, Contact resolved). The native Website quote form is untouched.

### robots.txt

`public/robots.txt` became the route `GET /robots.txt` (same bytes) because a static file is served by the web server
ahead of Laravel and so can never carry a per-site line (confirmed on the live dev server: the static file shadowed the
site's own response). A published site on its active primary custom domain now gets `User-agent: *` / `Disallow:` /
`Sitemap: https://<domain>/sitemap` from `ResolveCustomDomainWebsite`; Preview, the platform path and an alias never serve
one. The CRLF baseline failure of `test_robots_txt_is_untouched…` is moot: the test now asserts the served bytes.
**Deployment requirement:** the web server must not serve a stale static `robots.txt` for these hosts.

Also fixed (found with curl on the live dev server): `routes/web.php` began with three blank lines that Laravel printed
at the top of **every** response — the sitemap started with whitespace before `<?xml`. A test now guards every
routes / config / bootstrap / provider file against output before `<?php`.

### Structured data and social tags

`WebsiteFaqStructuredData` (FAQPage — only the FAQ the page shows, only complete Q&A, only indexable pages) and
`WebsiteSocialMetadata` (`og:type`, `og:site_name`, `og:url` = canonical, `og:image` + size/alt + `twitter:image` only for a
page hero / owner hero whose file exists, `twitter:card` = `summary_large_image` with an image, else `summary`). None in Preview.

### Presentation

Location pages no longer get a second "Planning an event in X?" band under their own call to action; adjacent CTA bands
read as one block (CSS). The mobile menu's label-only dropdown (Locations) puts its chevron where a linked dropdown's toggle
(Services) does (`.wd-nav .wd-dd-label`, specificity above Template 1's `.wd-header-split .wd-nav-link`).

### Search visibility

Generated pages stay `noindex` until the owner acts. `website_pages.noindex_explicit` (new, additive) records a hide the
owner chose (ticking the box — merely saving a pre-ticked generated page is not a choice). "Let search engines find these
pages" (`WebsiteSearchVisibility::release`) releases only platform-hidden pages that have content beyond a hero, reports what
it kept, and changes nothing live until Publish (the sitemap follows the next publish). Health states the count and how many
the owner hid.

### Blueprint defaults

Verified: a Blueprint's preferred template only ever applies to a new / style-less Website; `generationHints` only add prompt
hints; nothing writes to an existing Website. **Bug found and fixed:** after the V1 flow stopped opening on a style question
(setup starts on the niche default), the Blueprint's template was no longer applied anywhere in a normal setup — it now starts
the new Website (a shell that already has a style keeps it).