# Website onboarding — smart inputs

Branch `agent/website-onboarding-smart-inputs-v1` (stacked on the Website
creation-flow fix, `WEBSITE-CREATION-FLOW.md`). The wizard, the guided
generator and Website Studio are unchanged in shape; this lane makes setup
shorter and easier for a local-business owner and makes sure everything the
owner enters lands in the canonical Business OS models, never a Website-only
copy.

## Rollout: a new questionnaire version, side by side

Questionnaire versions are immutable and every session is pinned to the
version it started on. The improved setup is therefore **published as v2**
by `PhotoboothWebsiteSetupQuestionnaireV2Seeder` (it ensures v1 first on a
fresh database, then supersedes it; re-running is a no-op):

```
php artisan db:seed --class=PhotoboothWebsiteSetupQuestionnaireV2Seeder
```

* New setups start on v2.
* In-progress sessions, completed responses, "Edit setup answers" and
  generated websites stay on the version they started on. **No answers are
  migrated.** Every v1 input shape (comma-separated areas, fixed-slot rows,
  `features_text`, inline packages) is still parsed, validated and applied.
* The repeatable/upload UI improvements apply to both versions (v1 keeps its
  own fields, but its rows are add/remove-able and start with one row).

## What v2 changes

Definition data (never hardcoded in views):

* **9 wizard screens** instead of 17 one-question screens (11 steps counting
  the style step and the review). A step may carry an optional `screen`;
  consecutive steps sharing it render on one screen. Steps stay atomic (own
  key, answer, validation, application path). A screen saves all its steps
  in one revision-checked write and advances once.
* **Service areas** — `string_list`: one area per row, add/remove/re-order,
  whitespace normalized, case-insensitive duplicates dropped, entered order
  preserved, no comma parsing. Help text: "Recommended to start: 5–10
  important service areas. You can add more later." — a product
  recommendation, not a claim about any search engine.
* **Services** — start with one row; add as many as needed; per-row
  "Generate description with AI" (service name + business context in; the
  owner edits; never replaces hand-written text without a confirmation).
* **Packages** — `catalog_selection` over the canonical Packages & Products
  catalog (below).
* **Features** — one per row, per package.
* **Backdrops / gallery** — one repeatable component with category (from the
  step's niche `categories` vocabulary), image, alt text, optional
  description. `categories` is an ordered `{value,label}` list because the
  database does not preserve JSON object key order.
* **Reviews** — a boundary (below).
* **Contact form** — the multi-select now actually selects the form's
  fields (it was ignored), and "Message" is "Message / additional details".

## Packages: one pricing truth

Website setup owns no package model.

* The answer to the packages step is only `[{uid}]` — which canonical
  packages to show. No name or price is stored in the answer.
* Existing packages are listed live ("We found your existing packages"),
  selected by default; "Show on my website" unchecked means *removed from the
  website*, never archived. Editing a package on the screen is the canonical
  edit (`CatalogItemManager`); "Add package" creates a real catalog package
  that appears in Packages & Products immediately.
* "Edit setup answers" re-reads the catalog; it can never push an old copy
  back over a Packages & Products edit.
* Website package blocks carry `catalog_item_uid`. Name and price are
  resolved live from the catalog for **the editor preview** and for **every
  publish** (`WebsiteCatalogReferences`, same precedent as `contact_details`).
  Archived packages are dropped. The AI-written description stays page copy.
* A published revision is immutable by contract, so it cannot change later.
  Instead Studio reports (compute-on-read, no schema) when the catalog has
  moved on since the last publish — naming the packages — and offers
  **Publish update**, the existing safe sync path (publishing re-resolves).
* Remaining seam: a site published *before* this lane has no uid references
  and cannot be flagged automatically; one rebuild stamps them.

## Service-area pages

The owner's cities are SEO/service-area targets, **not** `BusinessLocation`s
(none is ever created per city). `WebsitePageStrategy` plans an `area:` page
per chosen area:

* only for areas the owner chose, in their priority order, capped at
  `MAX_AREA_PAGES` (8) and by the total page budget (service pages cannot
  consume every slot); the full list stays saved on the primary location so
  more pages can be generated later without asking again;
* only when the Business has real services/packages to write about;
* distinct slug (`serving-<area>`; colliding slugs are skipped), title, meta
  and local facts (the area, the owner's other areas as "nearby", their real
  services) — nothing about an area is invented;
* the AI is told to write each area page differently and never invent local
  facts; `GuidedGenerationOutputValidator` rejects an area page that does not
  name its area or that is nearly identical to another (the existing
  corrective retry applies); a rejected batch is never committed;
* `MediaBindingService` guarantees internal links (contact, services, up to
  two nearby area pages); the sitemap already lists every page.

## Reviews: boundary only

GBP has no reviews/ratings read seam and its contract forbids storing review
content, so setup shows only: owner-confirmed testimonials, and Google
Business Profile *connection state* through the existing read-only
`GoogleBusinessProfileStatusReader` ("Connect Google Business Profile"
optional link; skippable). No rating, count or review text is ever shown or
invented; nothing is copied from GBP. `WebsiteReviewSourceStatus` lists
sources as separate entries so a future canonical GBP Reviews seam plugs in
by supplying items.

## Images and alt text

* Pick a file → upload starts immediately with progress, thumbnail,
  Replace/Remove, inline errors. No second "Upload" click. Gallery photos use
  the existing `WebsiteGalleryManager` (JSON branch); backdrop/package
  pictures use `BusinessImageStore` (Business-owned, `images/business/<uid>/`,
  same magic-byte/size/dimension checks), so a rebuilt website never loses
  them. Only a file nothing references is ever deleted.
* Every image gets a safe default alt text (`ImageAltText`: "{name} —
  {category}", else "{Business} photo") the owner may edit; an auto value
  never counts as custom. No vision model is used (the AI layer is
  text-only).

## Review screen

Entry-by-entry lists (service areas, services, packages with live price,
backdrop thumbnails, photos), grouped under four headings, each block with
its own **Edit** link to the screen that owns it.

## Local acceptance

Website AI is off by default (`OPENAI_ACTIVE=false`) and stays that way.
For local browser acceptance only, bind a fake `WebsiteAiGenerationClient`
(as the tests do) — never committed.

## Tests

`WebsiteOnboardingSetupFlowTest`, `WebsiteServiceAreaPagesTest`,
`WebsiteCatalogReferencesTest`, `SmartWizardVersionCompatibilityTest`,
`Setup/SmartInputsFoundationTest`; existing wizard/structure/alt-text tests
updated where the layout or the never-null alt default changed.
