# Photo Booth reference templates — measured comparison (work in progress)

**Status: NOT complete.** This records what was actually measured and rendered. It is not a pixel-fidelity certificate.

## What was done (real, reproducible)
- Reference homepages captured with Playwright/Chromium at 1440, 768, 390, 320 px. Computed styles read from the live DOM at 1440 px.
- Generated drafts for all three templates through the actual guided owner journey (`ThreeReferenceWizardJourneyTest` helpers, disposable tenant and **fixture** photos, not real customer data), 12 pages each, rendered with the product's own preview route, screenshotted at the same four widths.
- No horizontal overflow at any of the 12 template/width combinations (`scrollWidth == viewport`).

## Measured reference values now encoded in CSS
| | Kaleb | Booths | Greenbelt |
|---|---|---|---|
| Headings | Montserrat 700, script accent words (Corinthia/Comforter) | Montserrat 700 | system UI sans, 900, UPPERCASE, 72/48px, 1:1 leading |
| Body | Lato 400/500, 22px lead | Lato | system UI sans 18-20px |
| Buttons | `#fac815` pill, radius 75px, 15/40px padding | cyan→blue gradient, skewed, 2px tracking | `#f4a28f` pill, 14px/700, .7px tracking |
| Bands | `#232323` feature band, white hero | black hero, `#061633` navy | `#2a1d45` plum, lavender→peach hero |
| Announcement bar | yellow, black text | navy, 1px tracking | plum with peach highlight |

## Corrected after comparison
- Heading weight was forced to 900 by the shared Modern rule; reference is 700 (Kaleb, Booths).
- Kaleb hero was a full-bleed dark photo; reference is a split hero (centred copy left, photo right) on white. Now a split hero on a white field.

## Remaining known differences (not fixed)
1. **Web fonts are not loaded** (no third-party requests). Montserrat/Lato/Corinthia only apply where installed; script accent words fall back.
2. **Reference images could not be loaded** (their CDNs are blocked here), so image crops/aspect ratios were compared from computed CSS only, not pixels.
3. **Pages other than the homepage** (About, services, packages, gallery, FAQ, contact, mobile menu, footer) were rendered but **not yet compared** with their reference pages.
4. Booths hero: reference is a black, text-only hero; generated hero uses the owner's photo behind the headline.
5. Greenbelt: sticky announcement bar highlight, Google-reviews badge, partner/venue strips and the "see the deets" section are not implemented as components.
6. Kaleb/Booths sections with script accents, the founder block and Booths' skewed `#BOOTHSTOTHEMAX` tag are not reproduced.
7. Checks against the page body used a coarse luminance diff only (Kaleb 90, Booths 110, Greenbelt 79 of 255 mean-abs). These numbers are dominated by differing images and page heights and must **not** be read as a similarity score.
8. CTA reads "CONTACT US" in the fixture because the fixture tenant has no live Forms-module deployment; that is the designed fail-closed behaviour. A tenant with a live form shows "CHECK AVAILABILITY".

## Questionnaire additions (all optional, one question per page)
`hero_headline`, `hero_subtitle`, `hero_focus` (center/top/bottom/left/right), `intro_heading`, `services_heading`, `features_heading`, `events_heading`, `packages_heading`, `gallery_heading`, `reviews_heading`, `areas_heading`, `faq_heading`, `closing_heading`. A blank answer uses the design's deterministic default. Published as a new questionnaire version by migration; existing responses stay pinned.

## Not performed
Production-account generation for the three real businesses, real Forms submissions (Contact/Opportunity/consent/duplicate), Blog on/off in a browser, independent Codex review of the final head, and CI on the final head.

## Test status in this environment
Focused suites pass (`ThreeReferenceTemplatesTest`, `ThreeReferenceWizardJourneyTest`, `tests/Feature/Website/Setup`). Across `tests/Unit/Website`, `tests/Feature/Website`, `tests/Feature/NicheBlueprint`, 64 of 1723 tests fail **identically on PR #726's head** (no new failures from this change); they were not diagnosed.
