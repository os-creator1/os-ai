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
| Visual review at 768 and 320 | **Not visually reviewed** (overflow check only); screenshots are in the committed set for home |
| Packages / About / FAQ pages | Rendered, no reference counterpart exists (the references put these on the homepage); not compared |
| Location pages (reference has 6–7 per site) | **Not generated in the fixture** (they require area details with real local content); not compared |
| Legal pages | Not generated; owner-supplied URLs only (unchanged behaviour) |

## Page-by-page matrix
Status: ✅ structure and styling match the reference within the stated differences · 🟡 matches in structure, notable differences remain · ⛔ not implemented / not compared.

### Kaleb Hill (`photo_booth_reference_kaleb`)
| Page | Reference | Screenshots | Observed differences remaining | Status |
|---|---|---|---|---|
| Home | `/` | `kaleb-home-{1440,768,390,320}.jpg` | Awards/press badge strip shows the owner's text facts, not badge images (no badge-image question). Camera icon above intro absent. Reviews badge lacks Google logo/avatars. Per-feature rows exist only for backdrops (Stanchions/Props/Red Carpet/Private Gallery rows need owner images per feature). "Secure My Booth!" closing band is a dark band, not a photo background. Header nav shows the site's own pages (2 rows), reference shows its own 5 labels. | 🟡 |
| Gallery | `/gallery` | `kaleb-gallery-{1440,390}.jpg` | Reference is a borderless 5-column mosaic of 40+ photos; generated uses owner's photos in the same grid (fixture has 6). | 🟡 |
| Backdrops | `/backdrops` | `kaleb-backdrops-{1440,390}.jpg` | Reference: icon + 3-column captioned grid; generated: 3-column captioned grid from the owner's backdrops. Icon absent. | 🟡 |
| Event (wedding) | `/weddingphotoboothrental` | `kaleb-event-{1440,390}.jpg` | Reference has awards strip and dark script band; generated has event copy + inquiry panel + related booths. | 🟡 |
| Service (booth) | `/magicmirrorPhotoboothrental` | `kaleb-service-{1440,390}.jpg` | As above. | 🟡 |
| Check Availability | `/checkavailability` | `kaleb-check-{1440,390}.jpg` | Fixture tenant has no live Forms deployment, so the page shows the fail-closed contact block (by design). With a live form the module form is embedded (proven in `ReferenceTemplatesFormsFunnelTest`; not screenshotted). | 🟡 |
| Location (7) / Legal | — | — | Not generated / not compared. | ⛔ |

### Booths To The Max (`photo_booth_reference_booths`)
| Page | Reference | Screenshots | Observed differences remaining | Status |
|---|---|---|---|---|
| Home | `/home` | `booths-home-{1440,768,390,320}.jpg` | **Hero:** with images loaded the reference hero is a dark photograph under an overlay (not literally text-only black); generated is dark-photo-overlay when the owner supplies a hero photo and plain black otherwise. Coloured punctuation in headings, two-photo overlapping intro collage, `#BOOTHSTOTHEMAX` tag, numbered "#1 #2 #3" suffixes and the Google logo card are not reproduced. Sections "Event Types / Areas We Serve" appear as event cards + areas text rather than blue link lists. | 🟡 |
| Gallery | `/gallery` | `booths-gallery-{1440,390}.jpg` | Reference has no hero (compact title on white then a 4-wide mosaic); generated compact title + grid. | 🟡 |
| Backdrops | `/backdrops` | `booths-backdrops-{1440,390}.jpg` | Reference: 3×3 captioned grid; generated: owner's backdrops in the same pattern (fixture has 1). | 🟡 |
| Event / Service | `/weddingphotoboothrental`, `/photoboothrental` | `booths-event-*`, `booths-service-*` | Reference hero photo + two-photo collage intro; generated hero overlay + copy + panel. | 🟡 |
| Check Availability | `/Check-availability` | `booths-check-{1440,390}.jpg` | Same fail-closed note as Kaleb. | 🟡 |
| Location (7) / Legal | — | — | Not generated / not compared. | ⛔ |

### Greenbelt Photography (`photo_booth_reference_greenbelt`)
| Page | Reference | Screenshots | Observed differences remaining | Status |
|---|---|---|---|---|
| Home | `/` | `green-home-{1440,768,390,320}.jpg` | Reference "popular dates" strip has a photo tile and arrow control; generated has the peach text tile + button (owner's announcement text). Scrolling marquee of event categories absent. Packages: owner's real packages render as tiers (fixture has 2, reference 4 incl. a featured plum card). "Turn your rental into a marketing machine" corporate panel is owner-supplied text only. Four-photo row under add-ons and "See the deets" step rows (Tell us / Reserve / Enjoy with photos) are simplified to the three step cards. Header nav on mobile is a Menu button; the reference shows its links expanded over the hero. | 🟡 |
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

## Cross-cutting facts verified (automated)
- Answer → section mapping and fixed order for all three templates through the real v3 questionnaire (`ReferenceTemplatesGuidedPathTest`).
- Forms funnel with a live Forms-module lead form, and its fail-closed counterpart (`ReferenceTemplatesFormsFunnelTest`).
- Blog navigation and preserved article URLs/sitemap with Content Articles on/off (existing `PublicBlogTest` case for reference templates).
- No horizontal overflow at 1440/768/390/320 on any generated page.

## Known environment facts
- Web fonts (Montserrat, Lato, Corinthia, Sen, Comforter, Titillium Web: SIL OFL) are self-hosted under `public/fonts/website-reference/` and loaded only for the three reference designs. Latin subset only.
- Reference images are the references' own property and are **not** copied into the product or the repository; fixtures use synthetic images.
