# Slice 4 — End-to-end acceptance of the website generator

What this proves, how to reproduce it, and what it does **not** prove.

## Method

`tests/Feature/Website/GeneratorAcceptanceTest.php` builds a realistic photo booth business (Harbor Booths, Petal MS: three booths,
three event types, two qualified service areas plus one confirmed area with no local evidence) with the **real seeded Photo Booth
blueprints (v1, v2, v3)**, activates and deploys the installed `pb3_form_lead` form as an owner would, runs the **real** guided
generation commit with a fake AI, publishes, and then reads the real custom-domain responses. The only fake is the AI.

Reproduce the rendered-browser evidence:

```bash
ACCEPTANCE_REPORT_DIR=/tmp/accsite php artisan test tests/Feature/Website/GeneratorAcceptanceTest.php --filter=benchmarks
```

writes every rendered page and `inventory.json`; serve them statically with the real `public/css` and `public/js`, then drive Chromium at
1280 px and 390 px. Screenshots here: `home-*`, `photo-booth-contact-*`, `service-magic-mirror-photo-booth-mobile`.

## Reference used

The Kaleb Hill v3 ZIP was **not available**, so nothing here claims visual fidelity to it. The structural reference is
`docs/migrations/customer-websites/kaleb-hill/website-content.json` (13 pages: Home, a booth page, Backdrops, Gallery, three event pages,
Service Areas, FAQ, an inquiry page with a Forms-module form + contact details, three legal pages).

## Generated page inventory (13 published pages, all indexable)

`/`, `/services`, `/service-magic-mirror-photo-booth`, `/service-open-air-photo-booth`, `/service-360-video-booth`,
`/wedding-photo-booth-rental`, `/corporate-photo-booth-rental`, `/birthday-party-photo-booth-rental`, `/photo-booth-about`,
`/photo-booth-faq`, `/photo-booth-contact`, `/serving-hattiesburg-ms`, `/serving-laurel-ms`. (`page-inventory.json`.)
Biloxi is a confirmed service area with no local evidence: listed on the home page, never a page.

| Kaleb Hill v3 requirement | Generated | Note |
|---|---|---|
| Home with local focus, services list, areas | yes | headline names service + market; services cards link to their pages |
| Booth service page(s) | yes, one per booth | |
| Event pages (wedding / corporate / party) | yes, one per described occasion | |
| Service areas | per-place pages only with confirmed venues/logistics + home "Areas we serve" | no single "Service Areas" hub page |
| FAQ, About | yes | |
| Inquiry page = Forms-module form + contact details | yes (`pb3_form_lead` + phone/email) | |
| Gallery, Backdrops | **not in this fixture** | need owner photos / backdrops; planned only when they exist |
| Real testimonials | none | intentionally never invented |
| Privacy / Terms pages | **no** | the generator does not write legal pages |

## Results (all automated, all passing)

8 acceptance tests: page structure; every internal link and embedded form resolves (crawl), one H1, viewport meta, no empty heading / dead
link; pairwise page distinctness and no invented credentials/reviews/awards; navigation + contextual links (home → every service and the
inquiry page; every service/event/area page → inquiry page; area pages → services); canonical, robots, sitemap, LocalBusiness and
BreadcrumbList on every page; the inquiry journey (public form → submission → CRM contact → opportunity in **Inquiries / New Lead**, **no
appointment**, thank-you announces only the stored submission to the site's own origin, no personal data); Google Ads + Meta lead tracking
on every published page; native blog index + article with the same stylesheets/header, article → service link, canonical, BlogPosting, sitemap.

Browser (Chromium, 13 pages × 2 viewports = 26 renders): no horizontal overflow, exactly one H1, no failed requests or script errors.
The embedded lead form frame measured 1,319 px (desktop) / 1,571 px (mobile) tall with no nested scrollbar and the submit button visible.

## Defects found and corrected

1. **`pb3_form_lead` never installed** when v2 and v3 were seeded together (the real order): v3's multi-select "Event type" reused v2's
   Dropdown definition, and the whole form install aborted. The form adapter now leaves an unmappable question unmapped instead of failing
   the form (the answer stays on the submission).
2. **No visible phone/email on the generated site**, so LocalBusiness `telephone` was never published: the inquiry page now carries
   `contact_details` beside the form (as the benchmark's does).
3. **Hero-only services overview / About / FAQ pages passed the quality gate**: they now need at least one content section.
4. **Long unbroken text widened mobile pages** (measured 400–1,100 px of overflow): `overflow-wrap` on the page body.
5. **The lead form was clipped** by the fixed 760 px frame (submit button under a nested scrollbar): an origin-validated height bridge
   (`public/js/website-form-frame.js`); the fixed height stays as the no-script fallback.

## Limitations

- AI prose quality is **not** assessed (fake AI). Real-world quality needs a live run.
- Visual fidelity to the Kaleb Hill v3 design is **not** assessed (ZIP unavailable).
- Gallery/Backdrops/testimonials pages depend on owner uploads and real reviews, not covered by this fixture.
- No legal pages are generated.
- The 760 px frame height is now only a fallback; a browser without JavaScript still clips the tall form.
- Contact-field mapping of "Event type" is lost when an earlier pack already made it a Dropdown (see defect 1).

## Live-AI procedure (not run — it costs money and publishes nothing by default)

Use a dedicated test workspace on a staging environment, never a customer account: create a business with the facts above and install
the Photo Booth blueprints; run one guided generation; review the draft in Preview; **do not publish**. Compare the draft against this report's
checklist (links, quality gate warnings, tracking off). Delete the workspace afterwards.
