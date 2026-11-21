# Slice 7 — end-to-end generator acceptance

Question: can a business owner generate, preview, edit and publish a complete, useful website through the real application flow — beyond the Photo Booth fixture?
Answer: **yes; the one uncovered area (a non-photo-booth niche through the owner flow) now has a test and passes. No production code changed.**
Fake AI only (copy such as "Book SAT Preparation for your event" and the hash suffixes in titles are the fake's output, so this proves structure, not copy quality).

## Acceptance matrix

| Criterion | Photo Booth (existing proof) | Non-photo-booth niche (Tutoring) |
|---|---|---|
| Confirmed facts only, never invented locations / services / reviews / claims | `Acceptance/WebsiteOwnerJourneyToCrmTest` (per page: no invented-claim phrases), `GeneratorAcceptanceTest` (distinct pages, no invented credentials) | **new** `Acceptance/NonPhotoBoothNicheAcceptanceTest` (every Preview page; one owner-confirmed fact) |
| Page plan and niche-safe URLs | `GeneratorAcceptanceTest` (13-page inventory), `NicheSafeUrlsTest` | **new** (≥ 8 pages, no `photo-booth` address, unique slugs, `contact`, `service-sat-preparation`); `NicheSafeUrlsTest` |
| Real owner flow: wizard → Generate → Preview → edit → publish → reload | `Acceptance/WebsiteFullSiteAcceptanceTest` (wizard, preview crawl, publish, public crawl), `WebsiteOwnerJourneyToCrmTest` (edit, publish, unpublished edit stays draft) | **new**: Generate (fake AI), Preview of every page, a headline + CTA edit over the Studio route, publish over the Studio route, public reload; a later unpublished edit does not leak |
| Edits survive; public output = published snapshot | `WebsiteDraftPublishTest`, `WebsiteOwnerJourneyToCrmTest` | **new** (look-only rebuild in all four templates keeps the edit and every page) |
| Four templates | `WebsiteFullSiteAcceptanceTest::…in_every_template` | **new**: modern, editorial, luxury, conversion, each republished and re-read |
| Mobile layout (390 / 1440) | `docs/acceptance/slice-4|5|6*`, `blog-compatibility` captures | `tutoring-*-4templates-*.jpg` here (0 px overflow, one H1, all 16 renders) |
| Internal links and CTAs | `GeneratorAcceptanceTest` crawl, `GuidedInternalLinkPathsTest` | **new** (owner CTA → `/contact`; nav/footer, tel and mailto on every template) ; `NicheSafeUrlsTest` (every service page → `/contact`) |
| Correct niche form / module | `GeneratorAcceptanceTest` (`pb3_form_lead`), `WebsiteLeadFormResolverTest` | **new**: the Tutoring blueprint's own `tutoring_form_student_inquiry`, switched on by the owner, embedded on `/contact` (right deployment, lead-tracking frame) beside phone and email; no legacy photo-booth quote form, no `WebsiteForm` row |
| Booking / contact paths | `WebsiteOwnerJourneyToCrmTest` (inquiry → Contact → Opportunity) | phone and email on every template; the form submission pipeline is the shared Forms module (covered by `Forms/*`, `GuidedGenerationContactFormSubmissionTest`) |
| Rendered SEO: titles, descriptions, canonical, robots, structured data, sitemap | `TechnicalLocalSeoTest`, `WebsiteSeoAcceptanceAuditTest`, `GeneratorAcceptanceTest` | **new**: every published page has title, description, self canonical, `index`, `BreadcrumbList` (non-home), `LocalBusiness` on home, all in the sitemap with no photo-booth address |
| Preview vs published | `WebsiteDraftPublishTest`, `WebsiteOwnerJourneyToCrmTest` | **new**: Preview pages are `noindex`; the domain 404s before publish and serves the snapshot after |
| Blog cross-check | `docs/acceptance/blog-compatibility` (all 8 items) | not repeated |

## New test

`tests/Feature/Website/Acceptance/NonPhotoBoothNicheAcceptanceTest.php` — 1 test, 253 assertions; added to the "Guided Website Generation Regression" workflow with discovery enforced (exactly 1, no skips).

## Visual evidence (this slice)

`tutoring-home-4templates-390.jpg`, `tutoring-contact-4templates-390.jpg`, `tutoring-contact-4templates-1440.jpg` (modern, editorial, luxury, conversion left to right; 1440 px at 40%).
Rendered by the test (`ACCEPTANCE_REPORT_DIR=/tmp/x`), served statically with the real CSS/JS and measured in Chromium.
**The "Error response 404" box on the contact captures is the static server lacking the embedded Forms frame, not a site defect**: the iframe, its deployment uid and the "Open it here" fallback are asserted by the test.

## Remaining gaps

* Only Tutoring was driven as the non-booth niche, through the generation service plus the owner's HTTP routes — not through the questionnaire wizard screens, which exist only for Photo Booth (and Dog Boarding, whose end-to-end tests already fail on a clean `main` in this environment).
* No copy-quality claim: fake AI.
* The embedded form's own page and a visitor submission for a non-photo-booth niche were not re-run here (shared Forms module; photo-booth submission is proven end to end).
