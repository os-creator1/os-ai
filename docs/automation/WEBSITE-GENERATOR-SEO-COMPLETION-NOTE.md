# Website Generator + Local SEO Completion — Implementation Note

**Lane:** `agent/website-generator-full-seo-completion`
**Depends on (read-only):** `docs/automation/WEBSITE-GENERATION-HOSTING-CONTRACT.md`, `docs/automation/WEBSITE-GUIDED-GENERATION-CONTRACT.md`, `docs/product/implementation-contracts/18-SEO-EXPANSION.md`

This note records, per the task's own instruction, exactly which *current* SEO
recommendations this engine operationalizes, and which it deliberately does
not — so a future reader never has to re-derive this from the diff alone.

## 1. Research performed before coding

Fetched and read the live, current versions of:

- Google Search Central — FAQPage structured data documentation. **Result:**
  Google restricted FAQ rich results to government/health sites in September
  2023, announced full deprecation effective **May 7, 2026**, and removed the
  documentation entirely by June 2026. **Applied:** this codebase never emits
  `FAQPage` JSON-LD (it never did) and this lane adds none. FAQ content stays
  on the page as plain, useful HTML for visitors and for Google's ordinary
  crawling/understanding of the page — never promised as a rich result.
- Google Search Central — Review snippet / AggregateRating structured data
  documentation. **Result:** *"If the entity that's being reviewed controls
  the reviews about itself, their pages that use `LocalBusiness` or any other
  type of `Organization` structured data are ineligible for star review
  feature."* **Applied:** `WebsiteLocalBusinessStructuredData` (pre-existing,
  unmodified by this lane) already never emits `aggregateRating`/`review`;
  this lane's own `WebsiteBreadcrumbStructuredData` follows the same
  no-self-serving-rating discipline and no code path in this lane ever
  constructs one.
- Google Search Central — title-link guidance. **Result:** descriptive,
  concise, distinct titles; no keyword stuffing; no boilerplate repeated
  across pages; Google may still override a bad title. **Applied:**
  `WebsiteStarterDraftService`'s per-page `seo_title` construction (unchanged
  by this lane except for the new service/location pages, which follow the
  identical `"{Thing} | {Business Name}"` pattern already established for
  Services/Packages/About/FAQ/Contact) produces a distinct title per page,
  never a copy of Home's.
- Whitespark — 2026 Local Search Ranking Factors survey. **Result (read
  honestly, not cherry-picked):** the survey's own top-ranked themes are a
  dedicated page per service, geographic/city relevance in on-page content,
  inbound link quality, and on-page keyword placement; **internal linking
  from the GBP landing page specifically is reported far lower** in the same
  survey (a materially different, narrower claim than "internal linking in
  general is a top local-organic factor"). **Applied honestly:** this lane
  still builds a real, deterministic internal-link contribution (global
  navigation to every page, plus a service/location page's own CTA links
  back to the Services hub and to Contact) because Google's own crawling/
  no-orphan-pages guidance independently justifies it, and because the task
  itself directs implementing every legitimately website-controllable
  factor — but this note does not claim Whitespark's survey ranks that
  specific mechanism as highly as the task's own paraphrase suggested.

## 2. What this engine controls vs. does not

Per the task's own instruction, this software never claims to guarantee
rankings. It controls the *website's own, on-page and technical* factors
only:

**Controlled, and implemented:**
- A dedicated page for each genuinely offered service (`WebsitePageStrategy`
  + `WebsiteStarterDraftService::createServiceDetailPage()`).
- A location page only where a real, saved, distinguishing local fact exists
  (anti-doorway gate, §3 below) — never a bare city-token swap.
- Unique title/meta/H1 per page (reused, unmodified per-page validation:
  `seo_title max:70`, `meta_description max:160`, `heading` is a section's
  own required field).
- Canonical URLs, sitemap, noindex/index behavior (pre-existing from an
  earlier lane on this same branch history, verified unchanged and correct
  for the new page types by this lane's own tests).
- `LocalBusiness` structured data from confirmed facts only, gated by the
  same live address-privacy check regardless of which revision or template
  renders it (pre-existing, unmodified).
- New in this lane: `BreadcrumbList` structured data
  (`WebsiteBreadcrumbStructuredData`) — a Google-supported rich-result
  signal, built deterministically from the same page/URL facts already used
  for canonicalization.
- A deterministic internal-link contribution: every page appears in the
  global navigation (pre-existing — this already prevented orphan pages
  before this lane), and every new service/location page's own CTA links
  back to the Services hub and to Contact.
- No self-serving `AggregateRating`/`Review` markup, ever (verified absent
  by `WebsiteBoundaryTest`/`WebsiteComponentValidationTest`'s continued
  passing, and by inspection of every structured-data class in
  `app/Library/Website/Seo/`).

**Explicitly NOT controlled, and not faked:**
- Proximity, GBP category/configuration, review profile, backlinks/domain
  authority, citations, competition, business prominence. These belong to
  the existing GBP/SEO/Reviews/Opportunity systems (per the Google Business
  Profile contract and the reserved-but-unimplemented
  `OpportunityWorkerKey::Website`, `App\Library\Opportunity\
  WebsiteOpportunityProducer` — confirmed still absent by
  `BusinessKnowledgeProfileBoundaryTest::test_no_opportunity_producer_for_website_exists`,
  deliberately not built by this lane).
- Google Search Console integration — explicitly out of scope per the task;
  no code in this lane depends on it.

## 3. Anti-doorway design (Google spam-policy safety)

A location page is generated only when `WebsitePageStrategy::eligibleLocations()`
finds a real, active, non-primary, genuinely-served saved location that
*already* carries a service-area city list or a stated travel radius — a
concrete fact the page's own body copy states verbatim
(`WebsiteStarterDraftService::createLocationPage()` calls the same
`serviceAreaAnswer()` helper the FAQ page already used). A location that
exists but lacks that fact is never silently turned into a thin page; it is
surfaced via `WebsitePageStrategy::locationsNeedingMoreInfo()` — rendered as
a checklist item on the setup screen — instead. No list of place names is
ever iterated to mass-produce pages; every page traces to one specific,
already-saved `BusinessLocation` row.

## 4. Template presentation vs. SEO architecture

The four visual templates (`WebsiteTemplateSeeder`) share **byte-identical**
`page_manifest` values — proven by
`WebsiteTemplateCatalogTest::test_seeded_templates_share_the_same_page_manifest_regardless_of_visual_theme()`.
Only `theme` and the template's own **design** differ between them. The renderer's
`{type, data}` section contract is unchanged.

*Updated in Website V1 final:* a template is now a real design
(`App\Library\Website\Design\WebsiteDesigns`, `public/css/website-design.css`,
`WEBSITE-TEMPLATES-V1.md`) that owns the header, hero, services/packages
presentation, footer, typography and the order of a Home page's sections. That is
still presentation only: template selection never changes what pages exist, what
they contain, or how they are linked — the page set and the SEO architecture are
decided by `WebsitePageStrategy`, not by the template, and a template switch
("change the look only") keeps every page, word, photo and price.
