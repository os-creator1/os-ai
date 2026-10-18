# Photo booth template review (generated sites)

Four fully generated test websites, one per design, built through the real guided setup
(`tests/e2e/guided-setup.mjs`, `TEMPLATE_KEY=<key>`) from the same confirmed fixture data and local
placeholder photographs (no reference-site text or images). Each has 3 booths with their own photos,
3 packages (two with a photo, one text-only fallback), 2 add-ons, 2 backdrops with distinct photos,
6 gallery pictures, 3 event types, 6 safe default FAQs, About copy and a working quote form.

Per design, 11 published pages x desktop (1280px) + mobile (390px) = 22 full-page screenshots
(88 in total): `home`, `services`, `packages`, `gallery`, `backdrops`, `photo-booth-about`,
`photo-booth-contact`, `photo-booth-faq`, and one detail page per booth (`service-*`).
`home-desktop.jpg` / `home-mobile.jpg` are the full-page homepage captures.
Every walkthrough is checked automatically on each page and width for broken images, horizontal
overflow, empty sections, text/button contrast (WCAG AA) and, across the site, broken internal links;
`walkthrough.log` in each folder is the raw pass/fail output (129 checks per design, all passing).

## Reference sites: NOT inspected

The four reference websites could not be opened from the build environment. Its network policy
answers every connection to them with `403` (curl: `CONNECT tunnel failed, response 403`; headless
Chromium: `ERR_TUNNEL_CONNECTION_FAILED`) for boothstothemax.com, kalebhillphotobooth.com,
ritzybooths.com and greenbeltphotography.com. No claim is made about how closely any design matches its
reference. **Please supply full-page desktop and mobile screenshots of each reference** (or allow those
hosts) so the comparison can be done properly.

## Results

| Design (template key) | Reference | Match assessment | Defects corrected | Remaining gaps | Screenshots |
|---|---|---|---|---|---|
| Bold Event (`photo_booth_modern`) | boothstothemax.com | Not assessable (reference unreachable). Distinct identity confirmed: navy/yellow, full-width gradient hero, ruled yellow-top cards, centred bands. | None specific to this design this round | Compare against reference | [folder](photo_booth_modern/) |
| Classic Gold (`photo_booth_editorial`) | kalebhillphotobooth.com | Not assessable. Distinct identity confirmed: cream/serif, centred wordmark header, image-below-title hero, hairline rows, dark closing band. | Footer link columns were bunched at the left under a centred brand; now centred as a block (scoped to the centred footer) | Package cards of unequal height sit top-aligned; compare against reference | [folder](photo_booth_editorial/) |
| Midnight Gold (`photo_booth_luxury`) | ritzybooths.com | Not assessable. Distinct identity confirmed: near-black, gold accent, split hero with portrait photo, dark stacked cards. | Breadcrumb (`Home / Services`) was near-black on near-black on phones (contrast 1.12:1); now light, scoped to this design | Compare against reference | [folder](photo_booth_luxury/) |
| Party Luxe (`photo_booth_conversion`) | greenbeltphotography.com | Not assessable. Distinct identity confirmed: lilac/coral, pill nav, rounded split hero, white tiles on a plum band. | Decorative arrow badge on every tile overlapped the card text; tiles now reserve room for it (scoped to this design) | Tiles carry an arrow but are not links; compare against reference | [folder](photo_booth_conversion/) |

Shared (all designs, earlier commits): booth/package photo framing, centred backdrops, three-step
"How booking works", advantage descriptions, design-coloured form submit button, package photos
shown on the card-style package layouts (still hidden on the hairline layout).

## Known, accepted

* Photos are generated gradient placeholders (some carry a tiny caption baked into the file); judge
  layout and image assignment, not photographic quality.
* Subpage heroes reuse the cover / booth photo, so a booth detail page shows its photo twice.
* Only booth cards with a generated detail page are links (round 2).

## Review round 2 (PR #456 review of `a1f657fd`) — what changed

* **Booth cards are real links.** A booth card on the Home / Services page links to that booth's own
  detail page, matched by the booth's service id against the published pages of the same revision
  (never a guessed path). The whole card is the target (stretched heading link) with a visible
  keyboard focus ring; Enter opens the right page. A booth without a detail page stays plain text.
  Package cards keep their quote button; events, "How booking works" steps and advantages are plain.
* **Arrows only on links.** Party Luxe's arrow badge is now drawn only on cards that link somewhere.
* **Privacy-notice links (owner-configured).** The owner enters the address of their own published
  privacy notice (and optionally terms) in Studio -> Settings -> "Your website's look" (and on the
  setup Review look card). Only absolute `https://` addresses are accepted. They render in the footer
  legal row and under the quote form. Nothing is written, copied or implied for the owner, nothing is
  shown when blank, and Studio's health check warns ("Privacy notice link") that an inquiry site has none.
* **Hero headline.** The Home headline now states the service and confirmed city, worded per design
  (Bold Event / Classic Gold: "Photo booth rentals in <city>"; Midnight Gold: "Photo booths for events in
  <city>"; Party Luxe: "Book a photo booth in <city>"); the saved service areas appear in the supporting
  line ("Serving Irvine, Northwood and Santa Ana."). The brand name stays in the header. It is ordinary
  editable page text.

Still open (not addressed here): a review with realistic, licensed or owner-provided photography
before public launch; the owner must supply a real privacy notice before real customers use the form.
