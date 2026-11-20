# Slice 6A — Service / event page inquiry panel

Captures (`cmp-1440`, `cmp-768`, `cmp-390`, `cmp-320`): the Kaleb Hill v3 reference service page (where shown), the generated page **before** this slice (genuine
clean `main`, isolated autoloader), and **after** in `photo_booth_modern` and `photo_booth_editorial`. Same Slice 4 deterministic fixture, fake AI, static
serving with the real CSS/JS. Reproduce: `ACCEPTANCE_REPORT_DIR=… [ACCEPTANCE_TEMPLATE=photo_booth_editorial] [ACCEPTANCE_FACTS="a|b|c"] php artisan test tests/Feature/Website/GeneratorAcceptanceTest.php --filter=benchmarks`.

## What was built

* **`inquiry_panel` section** (new, closed-set type; never AI-authored and never offered to the AI): service-specific heading ("Ask about {service}"), ONE call to
  action to the contact page — which carries the installed Forms-module lead form — in the **niche's own contact wording**, the business's **verified phone**
  (frozen at publish like `contact_details`), and up to three **owner-confirmed facts** (the knowledge profile's confirmed differentiators, verbatim; none if none
  are confirmed). No availability, pricing or booking claim is ever generated.
* **Layout:** beside the page's first copy section on a wide screen (sticky), stacked directly under it on a phone/tablet; no overlay. Colours, radius and shadow
  come from the template tokens, so the modern and editorial templates (and any niche) render it in their own style.
* **No competing prompts:** a page with the panel gets no second closing inquiry band (this replaces Slice 5's "Ready to book" band); at most two CTA bands.
* Owner editor can edit or remove it. Stamped by `MediaBindingService` for `service_detail` and `event_detail` pages only (not home, areas, About, FAQ, Contact).

## Rendered-browser results (Chromium, service page)

| width | before | after (modern & editorial) |
|---|---|---|
| 1440 | no panel, 2 CTA bands | panel beside copy, sticky, no overlap, 1 CTA band, 0 overflow |
| 768 / 390 / 320 | no panel, 2 CTA bands | panel stacked under first copy, static, no overlap, 1 CTA band, 0 overflow, CTA button 51–53 px tall |

The panel's CTA resolves to the contact page, the phone is a `tel:` link, three confirmed facts render when supplied.

## Tests

`GeneratorAcceptanceTest` (11, enforced in CI): one panel per service/event page and none elsewhere, niche CTA to the contact page, tel link, no pricing/availability
claim, laid out beside the copy, no duplicate band, ≤2 CTA bands; confirmed facts shown verbatim (max 3); editorial template; layout CSS rules present. `NicheSafeUrlsTest`:
a tutoring site gets the same panel pointing at `/contact`. The repo's full-site audit (duplicate CTA bands / duplicated copy), validators, Forms, tracking, SEO and
blog suites pass. `WebsiteBoundaryTest` (closed section-type set) updated 14 → 15 deliberately.

## Limitations

* Dog Boarding follows the same `bind()` step by code, but its end-to-end journey tests already fail locally on clean `main`, so its panel was not browser-verified.
* The panel links to the contact page (where the form is) rather than embedding the form beside the copy.
* Facts appear only when the owner has confirmed differentiators; most new sites will show phone and button only.
* Reference extras not built: the reference's numbered "how it works" and FAQ accordion; the panel shows no availability/pricing text by design.
* Fake AI only; real-AI copy quality is not assessed.
