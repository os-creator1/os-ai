# Photo Booth reference templates — visual acceptance matrix

**This is fixture-based wizard testing, NOT authentic client-account acceptance.** Every generated page below came from an isolated test
tenant (`ReferenceTemplatesGuidedPathTest`, real v3 guided questionnaire, no AI call) with synthetic fixture photographs (coloured-ellipse
JPEGs). Nothing was generated in, or read from, the real Kaleb Hill / Booths To The Max / Greenbelt MotionGrove accounts, and no real
customer website was touched. **No claim of pixel-perfect parity is made.** No pixel-difference metric is reported: the fixture photographs
differ from the references' photographs, so any such number would measure the images, not the design.

## How it was captured (reproducible)
- Reference pages: Playwright/Chromium, full-page, 1440 / 768 / 390 / 320 px, images loaded (reference CDN hosts allowed), lazy content scrolled in.
- Generated pages: `DUMP_OUT=<dir> php artisan test tests/Feature/Website/Acceptance/ReferenceTemplatesGuidedPathTest.php` writes each page's
  preview HTML + images; the same Chromium configuration screenshots it at the same four widths (`scrollWidth <= viewport` checked per shot).
- Committed evidence: `screenshots/<template>-<page>-<width>.jpg`, left = reference, right = generated (cropped to the top of long pages, compressed).

## Review coverage — what a person actually looked at vs. what was only measured
| Check | Coverage |
|---|---|
| Horizontal overflow, all 3 templates × 12 generated pages × 1440/768/390/320 (144 captures) | **Automated, 0 overflows** |
| Visual side-by-side review at 1440 | Home (full length), Gallery, Backdrops, Event, Service, Check Availability for Kaleb and Booths; Home, Gallery, Event, Check Availability for Greenbelt |
| Visual side-by-side review at 390 | Home (first 2600 px) for all three. Other mobile pages: overflow check only |
| Visual review at 768 and 320 (home, all three templates, side by side with the references) | **Reviewed.** 768: side-by-side heroes, two-column rows and card rows now follow the references. 320: Kaleb booth rows fixed (image had collapsed to a dot). Other pages at 768/320: overflow check only |
| Packages / About / FAQ pages | Rendered, no reference counterpart exists (the references put these on the homepage); not compared |
| Location pages (reference has 6–7 per site) | **Not generated in the fixture** (they require area details with real local content); not compared |
| Legal pages | Not generated; owner-supplied URLs only (unchanged behaviour) |

## Page-by-page matrix
Status: ✅ structure and styling match the reference within the stated differences · 🟡 matches in structure, notable differences remain · ⛔ not implemented / not compared.

### Kaleb Hill (`photo_booth_reference_kaleb`)
| Page | Reference | Screenshots | Observed differences remaining | Status |
|---|---|---|---|---|
| Home | `/` | `kaleb-home-{1440,768,390,320}.jpg`, `kaleb-signature-1440.jpg` | **Done:** awards/recognition strip from the owner's own approved badge images (new "Award or press badge" gallery category; whole, uncropped, wrapping; omitted when none are uploaded; never in the hero or gallery); photo-led Magic Mirror presentation (the booth's own main + one extra photo, text centred against them; single image when the booth has no extra photo). **Remaining:** camera icon above the intro; Google logo/avatars on the reviews badge; per-feature rows other than backdrops (Stanchions/Props/Red Carpet/Private Gallery need owner images per feature); "Secure My Booth!" is a dark band, not a photo background; header nav shows the site's own pages in 2 rows, the reference its own 5 labels. Badge/photo content in the screenshots is fixture imagery. | 🟡 |
| Gallery | `/gallery` | `kaleb-gallery-{1440,390}.jpg` | Reference is a borderless 5-column mosaic of 40+ photos; generated uses owner's photos in the same grid (fixture has 6). | 🟡 |
| Backdrops | `/backdrops` | `kaleb-backdrops-{1440,390}.jpg` | Reference: icon + 3-column captioned grid; generated: 3-column captioned grid from the owner's backdrops. Icon absent. | 🟡 |
| Event (wedding) | `/weddingphotoboothrental` | `kaleb-event-{1440,390}.jpg` | Reference has awards strip and dark script band; generated has event copy + inquiry panel + related booths. | 🟡 |
| Service (booth) | `/magicmirrorPhotoboothrental` | `kaleb-service-{1440,390}.jpg` | As above. | 🟡 |
| Check Availability | `/checkavailability` | `kaleb-check-{1440,390}.jpg` | Fixture tenant has no live Forms deployment, so the page shows the fail-closed contact block (by design). With a live form the module form is embedded (proven in `ReferenceTemplatesFormsFunnelTest`; not screenshotted). | 🟡 |
| Location (7) / Legal | — | — | Not generated / not compared. | ⛔ |

### Booths To The Max (`photo_booth_reference_booths`)
| Page | Reference | Screenshots | Observed differences remaining | Status |
|---|---|---|---|---|
| Home | `/home` | `booths-home-{1440,768,390,320}.jpg`, `booths-signature-1440.jpg` | **Done:** overlapping two-photo intro collage (two distinct non-hero photos placed on a grid inside their own box, so it cannot overlap the text or overflow; a single photo when two distinct photos are not available, never a duplicate) and the `#BUSINESSNAME` tag derived from the canonical business name. **Hero:** with images loaded the reference hero is a dark photograph under an overlay (not literally text-only black); generated is dark-photo-overlay when the owner supplies a hero photo and plain black otherwise. **Remaining:** coloured punctuation in headings, numbered "#1 #2 #3" suffixes and the Google logo card are not reproduced. Sections "Event Types / Areas We Serve" appear as event cards + areas text rather than blue link lists. | 🟡 |
| Gallery | `/gallery` | `booths-gallery-{1440,390}.jpg` | Reference has no hero (compact title on white then a 4-wide mosaic); generated compact title + grid. | 🟡 |
| Backdrops | `/backdrops` | `booths-backdrops-{1440,390}.jpg` | Reference: 3×3 captioned grid; generated: owner's backdrops in the same pattern (fixture has 1). | 🟡 |
| Event / Service | `/weddingphotoboothrental`, `/photoboothrental` | `booths-event-*`, `booths-service-*` | Reference hero photo + two-photo collage intro; generated hero overlay + copy + panel. | 🟡 |
| Check Availability | `/Check-availability` | `booths-check-{1440,390}.jpg` | Same fail-closed note as Kaleb. | 🟡 |
| Location (7) / Legal | — | — | Not generated / not compared. | ⛔ |

### Greenbelt Photography (`photo_booth_reference_greenbelt`)
| Page | Reference | Screenshots | Observed differences remaining | Status |
|---|---|---|---|---|
| Home | `/` | `green-home-{1440,768,390,320}.jpg`, `green-signature-1440.jpg` | **Done:** event-category ticker from the owner's real event types (two or more; transform-only motion, pauses on hover/focus, stops and wraps as a static centred list under `prefers-reduced-motion`, loop copy hidden from assistive technology; no horizontal overflow at 320 px); "popular dates" strip with a photo tile (a non-hero uploaded photo, none when there is no spare one) beside the owner's own announcement text and a working CTA, no availability claim added. **Remaining:** the strip's arrow control; the ticker's per-category icons.  Packages: owner's real packages render as tiers (fixture has 2, reference 4 incl. a featured plum card). "Turn your rental into a marketing machine" corporate panel is owner-supplied text only. Four-photo row under add-ons and "See the deets" step rows (Tell us / Reserve / Enjoy with photos) are simplified to the three step cards. Header nav on mobile is a Menu button; the reference shows its links expanded over the hero. | 🟡 |
| Gallery | `/gallery` | `green-gallery-{1440,390}.jpg` | Dark plum page with rounded photo grid; generated matches in structure (owner photos). | 🟡 |
| Event (wedding) | `/events/wedding-photo-booth-rental` | `green-event-{1440,390}.jpg` | Reference has "Why choose us for your wedding" 3 icon cards; generated has the event copy + panel + related booths. | 🟡 |
| Check Availability | `/check-availability` | `green-check-{1440,390}.jpg` | Same fail-closed note. Reference form page is a plum page with a photo strip. | 🟡 |
| Location (6) / Venues page | — | — | Venue cards on home are implemented (owner's venues only). Location pages not generated / not compared. | ⛔ |

## Deliberate deviations from the reference (accessibility), found by Codex review and measured
| Where | Reference | Generated | Why |
|---|---|---|---|
| Kaleb yellow pill buttons | white label on `#fac815` (~1.6:1) | `#111` label (~12:1) | WCAG AA |
| Kaleb script words/headings on white | `#fac815` | `#b58900` on light bands (`#fac815` kept on dark bands) | ≥3:1 for large text |
| Booths cyan→blue buttons | white label (~1.9:1 on the cyan end) | `#061633` label (≥5:1) | WCAG AA |
| Greenbelt peach / lavender buttons | (peach: plum label, as reference) | lavender strip button label darkened to plum | was white on `#a79bc8` (2.6:1) |
Button contrast was measured from computed styles on the rendered pages (every `.wd-btn-primary`, all three templates): all ≥5:1 after the fix.

## Text-contrast audit (computed styles, all generated pages)
A Playwright pass computed foreground vs effective background for every text element on all 36 generated page renders at 1440 px (4.5:1 body, 3:1 large text). The first pass found 18 distinct failures
(body text inside dark/gradient CTA bands, footer legal text and headings, headings on pastel bands, founder panel, add-on and booth-card text, review stars); all were corrected in CSS. The final pass reports one item:
Kaleb's decorative review-star glyph (`aria-hidden`) at 3.2:1, above the 3:1 non-text threshold. The audit cannot measure text over photographs (hero backgrounds); those were not audited.

## Behaviour fixes from independent review (this round)
- A Check Availability claim now requires that the PUBLIC form would accept a visitor (account active, not locked, **Forms entitlement**, version), not just deployment/form/location state.
- The inquiry-label normalisation (authored "Check Availability" → the resolved site CTA) applies only to the three reference designs; canonical templates keep the button as written.

## Signature components (this round)
Implemented from the reference screenshots using only owner-supplied media; every one degrades to nothing (or a single image) when the media is missing, and no photo is ever repeated to fill a composition.

| Component | Template | Data source | Missing-data behaviour | Tests |
|---|---|---|---|---|
| Awards / recognition strip | Kaleb | gallery photos the owner categorised "Award or press badge" (max 8) | no strip; the plain facts strip stays | `ReferenceSignatureComponentsTest` (badges only; out of hero and gallery; none → none) |
| Photo-led booth presentation | Kaleb | the booth's own cover + one extra photo (`BusinessServiceImage`) | single image | extras are the booth's own, never gallery photos |
| Intro collage + hashtag | Booths | two distinct non-hero gallery photos; business name | one photo / one image only, no hashtag when the name has no letters or digits | two photos distinct; one and two-photo fallbacks |
| Event-category ticker | Greenbelt | the owner's event types (2–12) | fewer than two → no ticker | names verbatim, aria-hidden loop copy; CSS reduced-motion guard |
| Promo strip photo tile | Greenbelt | a non-hero gallery photo; owner's announcement text | no tile with no spare photo | tile ≠ hero; none without a spare photo |

Canonical templates are untouched (test: none of the new markup, classes or data keys appear on a canonical template, and badge-category photos never become its hero). The publish snapshot and health checker include the new image references (test), so published pages keep their images.

Rendered-component review (fixture imagery) found and fixed: the Greenbelt strip shrink-wrapped to a third of its width and sat on the wrong band colour; the ticker was pinned under the hero by the home-ordering rule meant for the facts strip; with only two event types the ticker loop would have shown a gap (each list now repeats until wider than any screen); Kaleb's description appeared above its heading after centring (auto-placement); a three-photo stack was far taller than the reference's pair (now main + one extra).

## Tablet and narrow-phone findings (visual review at 768 / 320 px)
- **Fixed:** at 768 the references keep their desktop structure (side-by-side hero, three event cards per row, two-column rows) while the shared base stacked them below 900px; reference designs now keep that structure from 700px.
- **Fixed:** at 320 Kaleb's booth rows collapsed their image to a dot (desktop grid placement leaking into the single-column layout).
- **Fixed:** Greenbelt's header overflowed at 960px (1,039px wide); tightened, no overflow at 920/960/1024/1100/1140.
- **Deliberate deviation:** the inline header starts at 920px; at 768px mine is the hamburger menu. The nav lists need 624–675px plus logo and CTA, and the references themselves overflow horizontally at 768px (measured 818px), so matching them there would reproduce a defect.
- Overflow check now covers 1440, 1100, 960, 920, 768, 390 and 320: 252 captures, 0 overflows.

## Cross-cutting facts verified (automated)
- Answer → section mapping and fixed order for all three templates through the real v3 questionnaire (`ReferenceTemplatesGuidedPathTest`).
- Forms funnel with a live Forms-module lead form, and its fail-closed counterpart (`ReferenceTemplatesFormsFunnelTest`).
- Blog navigation and preserved article URLs/sitemap with Content Articles on/off (existing `PublicBlogTest` case for reference templates).
- No horizontal overflow at 1440/768/390/320 on any generated page.

## Known environment facts
- Web fonts (Montserrat, Lato, Corinthia, Sen, Comforter, Titillium Web: SIL OFL) are self-hosted under `public/fonts/website-reference/` and loaded only for the three reference designs. Latin subset only.
- Reference images are the references' own property and are **not** copied into the product or the repository; fixtures use synthetic images.
