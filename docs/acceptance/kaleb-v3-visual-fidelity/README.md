# Kaleb v3 visual fidelity — Emerald Gold (opt-in Photo Booth design)

Template `photo_booth_emerald` / design key `emerald`, rendered by the existing Website renderer. It is **opt-in**: it is not part of
`WebsiteDesigns::all()` (the four canonical designs), a site only gets it when its owner picks that template, and its CSS is scoped to
`.wd-emerald`. Classic Gold and the other three designs are unchanged (asserted by the acceptance test).

Evidence: `side-by-side-{1440,768,390,320}.jpg` (reference | before = Classic Gold | after = Emerald Gold), `before-/after-hero-1440.jpg`,
`sparse-client-1440.jpg` (a client with few facts), `booth-feature-{1440,390}.png` (the booth feature layout with a photo).
The "before/after" pages are the real public pages of the same fixture site, published through the owner's rebuild + publish routes. The
fixture photographs are generated placeholders (the reference's photographs are remote and not used); text is the fixture's own data.

## What maps to what
| Reference block | Renderer |
|---|---|
| deep-green split hero, eyebrow, H1, two CTAs, photo | `hero` section, variant `editorial-split` (eyebrow = business name; photo = section/hero image, else a composed green plate) |
| proof bar | existing `trust_strip` (needs ≥ 2 owner-confirmed facts; absent otherwise) |
| three event cards | `services` section "Events we cover" (role `events`); a numbered plate when there is no photo of that event |
| Magic Mirror feature | the first booth that has a photo in "Our photo booths" (role `booths`) becomes an image/text feature |
| four-column booking process | "How booking works" (role `process`): dark numbered band |
| service areas | "Areas we serve" (role `areas`): link-tile grid |
| testimonials / FAQ / CTA / footer | `testimonials`, `faq`, single closing `cta`, compact footer (≤ 6 links per column) |
| blog cards | existing guide cards, only when published relevant articles exist |

Roles are read from the section itself (`SectionRole`: explicit `data.role`, else the blueprint's own headings); no new section type, no
business text in shared components or CSS.

## Remaining visual differences (honest list)
1. Fonts: the reference loads Playfair Display + DM Sans from Google Fonts; MotionGrove loads no third-party fonts, so the same families are
   named with Georgia / system fallbacks. Headline widths and weights differ slightly until a site self-hosts them.
2. Hero artwork: the reference has a decorative mirror illustration; here the right column is the owner's photo, or a plain green plate.
3. Header: the reference brand has a small-caps sub-line and a top bar of location text; MotionGrove shows the wordmark/logo and a phone + CTA bar.
4. Section order follows the generator's order (booths → packages → events → process → advantages → areas → gallery → …), not the reference's
   (events first, then the Magic Mirror feature). Packages/gallery/backdrops blocks have no reference counterpart.
5. Event cards use a number plate instead of photographs unless the owner has a photo for that event; booth cards likewise.
6. The reference has a section intro ("One experience. A hundred moments…") and a "See all service areas" link; the generator does not invent intro copy.
7. Proof bar / "Designed for guests" copy only appear when the owner has confirmed those facts; the fixture has one differentiator, so no proof bar shows.
8. Blog cards are not in this fixture (no published article); the existing guide-card styling is restyled but not screenshotted here.
