# Website V1 — templates, look, navigation, planning and health

Branch `agent/website-v1-final`, stacked on `agent/website-onboarding-smart-inputs-v1`
(`07beed82`). The Website backend (generation, publishing, hosting, SEO) is
unchanged; this lane makes the **visual product** customer-ready.

## 1. The four canonical templates

The original supplied designs were not in the repository or on the machine, so
the work stopped and the user supplied four live reference URLs, in order. Each
became one original design. **Only layout / visual grammar was translated —
no copy, photographs, logos, reviews, markup or CSS was copied, and no
third-party font is loaded** (every stack falls back to a system font, so there
is no render-blocking request and no visitor data leaves the site).

| # | Source (grammar only) | Template key (stable) | Design | Signature |
|---|---|---|---|---|
| 1 | boothstothemax.com | `photo_booth_modern` | **Bold Event** | navy + gold, full-width photo hero, uppercase headlines, split header with announcement bar, ruled package columns |
| 2 | kalebhillphotobooth.com | `photo_booth_editorial` | **Classic Gold** | white/black/gold, centered logo header, serif display type, hairline service rows, hairline package list, centered footer |
| 3 | ritzybooths.com | `photo_booth_luxury` | **Midnight Gold** | near-black + one champagne-gold accent, sticky header, pill buttons, split hero, dark stacked service cards, gold-bordered featured package |
| 4 | greenbeltphotography.com | `photo_booth_conversion` | **Party Luxe** | lavender/peach + plum + coral, floating pill menu, event-style service tiles, lifted featured package, soft footer |

## 2. Architecture (one renderer, template-owned structure)

* `App\Library\Website\Design\WebsiteDesigns` / `WebsiteDesign` — the registry.
  A design owns: header layout, hero layout, services / packages / testimonials /
  gallery / footer presentation, the band tone (light/dark/tint/accent) of each
  section type, and the **order of a Home page's sections**. A published site
  resolves its design from the theme frozen into its revision
  (`theme.header_variant`), so a later change never re-skins a live site.
* `public/css/website-design.css` — all four designs, scoped to `.wd-<key>`.
* `resources/views/public/website/page.blade.php` (shared by preview, platform
  path and custom domain) → `design/header`, `design/hero`, `design/footer` and the
  component partials. A site with no template (legacy) keeps the original
  generic chrome untouched.
* `App\Http\View\Composers\WebsitePageComposer` computes `$design`, `$nav`,
  `$siteCta`, `$brandStyle`, `$logo`, `$siteContact` for all three renderers.
* New component partials `backdrops` and `custom_section` (the two section types
  that previously had no public view).

Owners customise only: logo, **one** brand colour, hero image, images, text,
services, packages, areas, CTA and contact. Never layout, fonts or a free
palette. `BrandColors` derives every readable colour (text on the accent, accent
as text on light and on dark) at WCAG AA, whatever colour is typed; anything that
is not a plain hex is ignored.

## 3. Template selection

* Setup starts on the first real question with **Template 1** (the niche
  default) — no up-front style question.
* The **Review** screen shows four *real* previews (the actual renderer fed the
  owner's own business, embedded so each card styles itself), the brand colour,
  logo and hero image controls, and the **Website plan**. Changing the template
  there never touches an answer.
* After generation the layout changes only through **Change template or
  rebuild** (`/website/rebuild`): four previews, an unmissable layout-change
  warning, "change the look only" (keeps every page, no AI call) or a full
  rebuild, and an explicit confirmation. The published site is untouched until
  the owner publishes.
* `WebsiteLookService` stores the owner's tokens in `websites.theme`
  (`brand_color`, `logo_asset_uid`, `hero_asset_uid`); they survive a template
  switch and a rebuild and are frozen into every revision. Logo / hero are
  ordinary Website assets with their own `purpose` (`logo`, `hero`), so they never
  count toward the gallery.

## 4. Navigation

`WebsiteNavigationBuilder` keeps the **compact primary navigation separate from
the page tree**: Home · Services ▾ · Packages · Gallery ▾ · Locations ▾ · About ·
Contact (+ the main button). Fixed pages use canonical labels (never AI page
titles); dropdowns hold the service and area pages; the footer lists every
top-level page, the services and the areas we serve — so 14 pages never become 14
header items and no page is orphaned. A real mobile menu (button, overlay,
Escape to close, keyboard-reachable dropdowns) replaces the old wrapping list.

## 5. The main button (CTA)

`WebsiteCtaResolver`: **booking → quote form → contact page → phone → email →
nothing.** Booking mirrors `PublicBookingController`'s guards (active type, active
location, an eligible staff member, Calendar entitled, active business) and is read
live, so a deactivated booking type never leaves a dead link on a published page.
A hero or CTA-band button that goes nowhere falls back to the site button; with
no way to reach the business no button is rendered at all.

## 6. Page planning (14-page budget) and why a page is in or out

`WebsitePageStrategy::planWithExplanation()` (buildPlan() is exactly its `plan`).
Priority, highest first: Home/Contact/About/Services overview/Packages → the
owner's first **5** services (in *their* order) while **2** slots stay reserved for
their top areas → Gallery, Backdrops, FAQ → further services → saved-location
pages → more areas (≤ 8). Every candidate gets a decision with a reason; the
Review screen shows "Service areas saved N / local pages planned M" and why each
other area is not a page (it stays listed as a service area).

Two defects fixed:

* **Missing 360 / mirror pages.** The two service steps (booth types, event types)
  each numbered their rows from 0, so event types interleaved with booth types and
  pushed real booths past the cut-off. `WebsiteSetupAnswerApplier` now continues
  positions across steps and the planner tie-breaks by id.
* **Oak Park excluded.** It was simply beyond the area budget with no explanation;
  the plan now says so, and the owner's order is the priority order.

## 7. SEO & Website Health (Studio)

`WebsiteHealthChecker` — real checks from the site's own pages/assets/domain/
business: published, own domain, titles, descriptions, photo alt text, contact
details, main button, service pages, package prices in sync, local details. No
score, no ranking promise. The deep technical audit stays in the SEO module and is
linked as an advanced view.

## 8. Other behaviour changes

* Generation failures show one calm message (`GenerationFailureMessage`); internal
  reasons stay on the attempt for support.
* FAQ step: niche **question** suggestions (never answers) from
  `NicheFaqSuggestions`.
* Snapshot: `website.contact` (phone/email the owner chose to display) and the
  logo/hero assets are carried into the immutable revision.

## 9. Tests

`Unit\Website\WebsiteDesignSystemTest`, `WebsiteDesignContrastTest`,
`GenerationFailureMessageTest`; `Feature\Website\WebsiteTemplateRenderingTest`,
`WebsitePlanExplanationTest`, `WebsiteLookAndReviewTest`, `WebsiteCtaResolutionTest`,
`WebsiteCtaBookingTest`, `WebsiteHealthTest`, `WebsiteQualityGateTest`.
