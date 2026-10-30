# Local SEO website generator — rules, upgrade safety and owner obligations

Status: implemented on branch `claude/upbeat-johnson-b9bvdc`. Benchmark: the live Transparent Photo Booths site (a customer site
moved from GoHighLevel and rendered by MotionGrove). Related: `24-SEO-CONTENT-ENGINE-V1.md`, `25-SEO-CONTENT-AUTOPILOT-V1.md`,
`DEPLOYMENT-READINESS.md` §6.4 / §6.6.

This is a framework for what the generator can control. It is not a promise of rankings, and Whitespark's expert survey is not an
official Google algorithm. Google's documented requirements (Search Essentials, spam policies, helpful-content guidance) win over any
third-party recommendation.

## 1. What MotionGrove controls and what it cannot

| Whitespark local-organic theme | Controlled by MotionGrove | Where |
|---|---|---|
| Dedicated pages for distinct services | Yes — a booth gets a page only with its own description beyond the first five | `WebsitePageStrategy` |
| Genuine city relevance | Yes, by refusing the fake kind — a city page needs an owner-confirmed local fact | `LocalAreaUsefulness` |
| Sitewide internal linking | Yes — booth cards, area entries (by place identity), booth↔booth, place→booths, article↔page | `MediaBindingService`, `services.blade`, `WebsitePageComposer` |
| Relevant headings and titles | Yes — intent-first titles, brand once, finished descriptions, one H1 | `PhotoBoothBlueprint`, `WebsiteHeadMeta`, `WebsiteSeoText` |
| Topical focus / page intent | Yes — the persisted page-intent map | `WebsitePageIntent`, `WebsitePageIntentClassifier` |
| Website relevance to the GBP landing page | Partly — consistent name, phone, services and areas; the owner chooses the GBP link | `WebsiteLocalBusinessStructuredData` |
| Useful content for the search intent | Partly — only from confirmed facts; the owner supplies the substance | blueprint, claim guards |
| Proximity, review volume, backlinks, citations, GBP eligibility | **No** — see the owner checklist (§5) | — |

## 2. The page-intent map

Every page the generator plans records `page_role` (home, services_hub, service, location, area_hub, packages, contact, about, faq,
gallery, backdrops, legal, other) and `page_intent` (`place`, `service_uid`). It is frozen into each published revision. The blog's
cannibalization guard, the article support-page resolver, the blog call to action and the publish-time review all read it. A page
without a stored role (built earlier, written by the owner, or moved from another builder) is classified from the Business's own saved
services and places matched against its address, then the universal page words (contact, privacy, about…). A page that matches
nothing stays `other` — never a guess.

## 3. Rules the generator now applies

* A service area earns a page only with an owner-confirmed local fact (venues worked, how serving it works, event photos). Everything
  else — and the base city, which the home page owns — stays in the visible "Areas we serve" list. The Review screen says exactly what
  would earn a page.
* **Local area details (setup).** In the existing "Where do you provide your services?" step each area row has an optional, collapsed
  "Add local details" panel: venues the owner really worked at (one per line), travel/setup/service details, and event photos picked
  from the website's own uploaded gallery. Grey text is an example placeholder only — never saved; an example submitted back verbatim
  is rejected (`LocalAreaUsefulness`), markup is stripped, and a photo uid that is not one of this website's gallery assets is dropped
  (no cross-customer images). Details are stored as the companion answer `_service_area_details` (keyed by area slug, submitted
  index-aligned with the names), so `service_area_cities` stays a plain list; reordering, renaming, removing or re-adding an area
  cannot move details to another area, and a removed area's details are deleted with it. Only questionnaire versions whose step carries
  `area_details: true` show the panel; older versions behave as before. An area with a confirmed fact becomes a page (the base city,
  written "City, ST" or not, never does); one that qualifies but does not fit the page budget is listed on the Review screen with the
  reason. Saving details never touches a published revision: pages change only when the owner generates/rebuilds and publishes.
* A booth beyond the first five has its own page only when the owner described it.
* **Event-type pages.** Each saved event type (`BusinessService` kind `event`: weddings, corporate events, parties…) gets its own
  page (`event_detail`, role `event`) at a clean address (`/wedding-photo-booth-rental`) only when the owner described it in their
  own words — an empty description, or the setup's unchanged example sentence, does not count (`EventPageUsefulness`) — and no booth
  page already targets the same search. The page states that description, lists the booths (linked), shows only the owner's FAQ
  answers and customer quotes that mention the event, and points on to packages and the quote; it never adds venues, past events,
  reviews, prices or guarantees. Home "Events we cover" cards link to them by event identity; they sit under Services in the
  navigation and the breadcrumb, and link to each other ("Other occasions").
* **Page budget (14), in the order it is given out:** Home/Services/Packages/About/Contact/extra section → first five booths (with a
  small reserve for areas that have local details) → event pages (up to 4, in the owner's order) → Gallery, Backdrops, FAQ → more
  described booths → place pages. The Review screen lists every page left out with its reason and the order; a booth page is never
  displaced by an event page.
* Place pages state saved cities, the travel radius and confirmed local details only, and list booths by name with links.
* Titles lead with the page's purpose and carry the brand once; descriptions are written per page from confirmed facts and end on a
  full sentence (no ellipsis, no mid-word cut).
* `LocalBusiness` JSON-LD: one stable `@id`, confirmed phone/email/address (as visible), `areaServed` only for places the site names.
  No ratings, review counts, prices or geo. `Service` markup is deliberately not added (no confirmed benefit; risk of mismatch).
* Articles: a topic that is the same search as a money page, or the same article for another city, needs the owner's explicit
  acknowledgement; an article about a place without a place page is flagged as thin.

## 4. Upgrade safety for published sites

| Class | What | Effect on existing sites |
|---|---|---|
| **A. Shared renderer code** | brand-variant title dedupe; legacy one-word/punctuation slug 301s; footer legal link resolved to the real page; footer column titles no longer `<h2>`; page-intent classification for blog/article logic; blog call to action found by role/content, not by a `/packages` address; `areaServed`; "Helpful guides" on supported pages | applies on deploy, nothing republished, no content changed |
| **B. Generator** | usefulness gate, areas list, per-page metadata, booth/place/event linking, role storage, event pages | new generations and explicit rebuilds only; a published site never gains event pages until the owner rebuilds and publishes |
| **C. Published content** | truncated descriptions, missing legal descriptions, repeated alt text, name-swapped city pages, unlinked cards, "booking out for 2026" claims | owner reviews and republishes; the publish-time review names each page |
| **D. Operator configuration** | `/robots.txt` must answer 200 (nginx); `X-Frame-Options` for form frames (§6.6) | operator action; `deploy/forge/production/post-deploy-check.sh` now fails a 404 robots.txt |

A rebuild that stops creating a previously generated place page records a 301 from its old address to the home page (which lists the
area). Old addresses from another builder are covered by the legacy-spelling redirect and by `websites.custom_redirects` (set through
`WebsiteRedirectManager`, as the Toronto and Lumina migrations do). The generator never edits an existing page's text.

## 5. Owner checklist — what MotionGrove cannot do for you

* Verify the Google Business Profile, choose accurate categories, and keep the profile name equal to the real business name (no
  keywords added). Use the same name, phone and service areas on the site and the profile.
* Earn genuine reviews from real customers; nothing here requests, buys or edits reviews.
* Keep business information consistent across directories; add citations yourself — none are created automatically.
* Earn local links and mentions: venue and vendor partners, local event pages, community sponsorships.
* Add real event photographs and describe each in a few specific words.
* Tell us one real local fact per area you want a page for (venue, travel/logistics, photo).

## 6. Limitations and follow-ups (priority order)

1. **Event pages need the owner's words.** An event type described only by the setup's example sentence stays a card. Event-specific
   requirements (space, power, lead time) and event photos are used only if the owner entered them; there is no event-specific
   field for them yet. Template manifests gained an `event_detail` entry; a template not re-seeded plans event pages with the
   booth-page section list, so no re-seed is required.
2. **Area details are collected in the setup wizard only.** Studio has no editor for them yet; an existing site gains them by re-opening
   setup on a questionnaire version that carries `area_details`, then rebuilding. Venue/photo claims are the owner's own and are not
   verified by MotionGrove.
3. Sitemap `<lastmod>` is not emitted: pages carry no accurate last-significant-edit timestamp, and a publish time would be misleading.
4. Core Web Vitals are targets (LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1), not measurements; none was measured here.
5. The questionnaire does not yet ask SEO-specific questions beyond what onboarding already collects.

## 7. Operator runbook — activating local-area details (`area_details`)

The service-area panel exists only on questionnaire versions whose `service_area_cities` step carries `area_details: true`. Versions are
immutable and every setup session is pinned to the version it started on, so activation = publishing ONE new version. Nobody mid-setup,
no saved answer and no published website changes; only setups started afterwards use the new version. Run in this order, per environment
(never against a database you have not looked at first):

1. **Deploy the code, then migrate:** `php artisan migrate --force`. The only migration (`2026_12_06_100001_add_page_intent_to_website_pages`)
   adds two nullable columns to `website_pages` and sorts after the newest migration already on `main` (`2026_12_05_100001…`).
2. **Look before seeding (read-only):**
   `php artisan tinker --execute="App\Models\QuestionnaireDefinition::where('key','photobooth_website_setup')->first()->versions()->orderBy('version_number')->get(['id','version_number','state'])->each(fn(\$v)=>print(\$v->id.' v'.\$v->version_number.' '.(\$v->state->value??\$v->state).' area_details='.json_encode(collect(\$v->storedSteps())->firstWhere('key','service_area_cities')['area_details']??null).PHP_EOL));"`
   Expect exactly one `published` row without `area_details`. A `draft` row will make step 3 refuse (see below) unless it is exactly the new tree — decide about it first.
3. **Activate** (one database transaction, so no setup can start on an intermediate v2 on a database that still needs it; a draft that already exists is used only if its steps are exactly the seeder's tree; any other draft makes the seeder REFUSE with a diagnostic naming its version id, rolls everything back and leaves the draft untouched — nothing proves who wrote it, so publish or discard it deliberately and run the seeder again): `php artisan db:seed --class=PhotoboothWebsiteSetupQuestionnaireV3Seeder --force`. It is idempotent: if the version that is PUBLISHED
   NOW already has exactly this step tree it does nothing; otherwise it publishes one new version and marks the previous one superseded. A
   superseded version that happens to match the tree does not count (A → B → A publishes a new version again); no published or
   superseded row is ever edited.
4. **Verify:** repeat the step 2 command — expect the new highest version `published` with `area_details=true`, the previous one
   `superseded` and unchanged, and no `draft`. Running step 3 again must change nothing. In the app, start a NEW setup and open
   "Where do you provide your services?": each row has "Add local details (optional)". An in-progress setup keeps its old screen.
5. **Existing websites:** nothing regenerates. To use the details on an existing site the owner opens setup (a new setup starts on the new
   version), fills the panels, then explicitly rebuilds and publishes. Transparent Photo Booths is not touched by any step above.
6. **Rollback / reactivation:** versions are never edited or re-published, so there is no "restore". To withdraw the panel, ship a code
   change that removes `area_details` from the seeder's step and run the seeder: it publishes a new version without the flag
   (setups started in between stay pinned to the version they began on). To bring the panel back later, restore the flag and run the
   seeder again: because a matching but superseded version is not the live one, it publishes a fresh version with the flag. Running
   the seeder when the live version already matches changes nothing.

Browser walkthrough: `tests/e2e/area-details.mjs` (opt-in, see `tests/e2e/README.md`).
