# Slice 5 — Visual quality of the generated website vs the Kaleb Hill v3 benchmark

Side-by-side Chromium captures (reference **left**, MotionGrove generated **right**) of the real Slice 4 fixture, at 1280 px and 390 px.
Reference: the attached `Kaleb-Hill-SEO-Blog-Benchmark-v3.zip` (its actual `index.html`, `assets/styles.css`, `BENCHMARK.md`), served statically.
Generated: the Slice 4 acceptance site (real generation + publish + custom-domain render; **fake AI**), served statically with the real CSS/JS.

Reproduce: `ACCEPTANCE_REPORT_DIR=/tmp/accsite [ACCEPTANCE_TEMPLATE=photo_booth_editorial] php artisan test tests/Feature/Website/GeneratorAcceptanceTest.php`
then serve `/tmp/accsite` with `public/css` + `public/js` and drive Chromium. Files here: `before-*`, `after-*` (template `photo_booth_modern`) and
`editorial-*` (template `photo_booth_editorial`, "Classic Gold", the look of Kaleb Hill's own live site).

## How to read the differences

Two things are **not** generator defects and were left alone:

* **Template identity.** `photo_booth_modern` is bold navy/yellow all-caps sans; the reference is cream, serif and muted gold. That is a different
  template, not a bug. `photo_booth_editorial` is much closer (cream, serif, centred header) — see `editorial-*`.
* **Content richness.** The fixture's AI is fake, so pages have two or three short sections; the reference has hand-written copy, illustrations,
  numbered feature rows, testimonials, FAQ accordions and blog teasers. Real AI prose quality is **not** assessed here.

## Genuine defects found by the comparison (all template-independent) and fixed

| # | Difference seen | Why it matters | Fix |
|---|---|---|---|
| 1 | A hero with no photo was a full-viewport block of empty gradient (about 470 px on a phone) holding only a headline and button | first screen shows little; pushes the content below the fold | typographic hero sizes to its content (about 310 px on a phone) |
| 2 | Text sections centred the heading over a left-aligned body: two different left edges | looks unfinished on every service, About and area page | heading shares the body column's left edge |
| 3 | Consecutive text sections were separated by up to 12 rem of white | reads as disconnected fragments | consecutive text bands read as one article |
| 4 | Linked service cards (home, services) gave no visible sign they are links | visitors miss the dedicated service pages | arrow affordance on linked cards (hit area and hover already existed) |
| 5 | Blog article headings and card titles were ALL CAPS although `blog.css` asks for sentence case: `body.wd h2` out-specified it | long-form readability, "same design" consistency | `.wd .blog-prose h2/h3`, `.wd .blog-card-title` (verified: "What to plan first", not "WHAT TO PLAN FIRST") |
| 6 | Service/event pages ended with "Also available" links and no booking call to action (the reference closes with "Let's make your event memorable") | the page where intent peaks had no inquiry prompt after the content | a closing, page-specific inquiry band ("Ready to book {service}?", the niche's own contact wording) — skipped when the page already has an inquiry band or two CTA bands |
| 7 | Two CTA bands in a row butted together as one muddled block | | divider + tighter spacing between consecutive CTA bands |

The closing CTA initially used identical wording on every page; the repo's own site audit (`no_duplicate_cta_bands`,
`service_pages_not_duplicated_copy`) flagged it, so it is now page-specific and capped at two bands.

## Results

* Chromium, widths **320 / 390 / 768 / 1440**, all 15 generated pages (home, services, 3 booth, 3 event, About, FAQ, Contact, 2 area pages, blog index, blog
  article): **60 renders per template, 0 problems** — no horizontal overflow, exactly one H1, no failed requests. The reference gives the same.
* Both `photo_booth_modern` and `photo_booth_editorial` render the acceptance site cleanly (9 of 9 acceptance tests pass with each).
* Slice 4 behaviour (Forms-module lead form, CRM contact + opportunity, tracking, SEO, blog) is unchanged: the 9 acceptance tests, the 3B/3C suites, the
  repo's full-site acceptance audit and the Forms boundary test pass.

## Still differs from the benchmark

* **Imagery and illustration.** No hero visual or card photos: the fixture has no uploads and nothing is invented. The reference's decorative fallback
  illustration (an accessible, clearly decorative graphic) has no generic equivalent yet.
* **Trust strip and proof sections** (four confirmed facts under the hero, numbered "details" row, attributed testimonials): need confirmed/owner-approved
  content; the generator has no section type for a fact strip.
* **Persistent inquiry card beside service copy** ("Have a date in mind? / Check availability / Call …"): not a section type.
* **FAQ accordion, blog teasers on the home page, "keep reading" on articles**: not generated.
* **Accent colour.** "Classic Gold" ships a saturated yellow (`#fac815`); the reference uses a muted gold. Owners can set a brand colour; the template default
  was not changed (that would be a template redesign).
* **Typography.** The modern template's all-caps heavy sans differs from the reference's serif; use the editorial template for that look.
* Keyboard/focus behaviour was not re-tested here; mobile navigation (menu button) renders and is unchanged.
* Real-AI content quality and the live Forms iframe inside a live custom domain are not covered (static serving; fake AI).
