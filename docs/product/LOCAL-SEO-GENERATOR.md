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
* A booth beyond the first five has its own page only when the owner described it.
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
| **B. Generator** | usefulness gate, areas list, per-page metadata, booth/place linking, role storage | new generations and explicit rebuilds only |
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

1. **Event-type pages** (wedding / corporate / party) are not a generated page type: they need a new plan page type and template
   manifest entries (seeded data). Until then they are cards on the home page, or pages the owner builds.
2. **Per-area local details have no owner UI yet.** `LocalAreaUsefulness` accepts them (`details.venues|logistics|photos`) through the
   plan; the wizard/Studio field is the follow-up that lets an area be upgraded to a page.
3. Sitemap `<lastmod>` is not emitted: pages carry no accurate last-significant-edit timestamp, and a publish time would be misleading.
4. Core Web Vitals are targets (LCP ≤ 2.5 s, INP ≤ 200 ms, CLS ≤ 0.1), not measurements; none was measured here.
5. The questionnaire does not yet ask SEO-specific questions beyond what onboarding already collects.
