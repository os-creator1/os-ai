# Contract 24 — SEO Content / Blog Engine V1

Status: implemented on `agent/seo-content-engine-v1` (base `6c42beb3` + Website V1 final `94bc4c1c`).
Related: `18-SEO-EXPANSION.md`, `23-SEO-KEYWORD-RANK-TRACKING-V1.md`, `20-NICHE-BLUEPRINT-VERSIONING.md`,
`docs/product/NICHE-BLUEPRINT-V2.md`, `docs/product/GROWTH-CENTER-OPPORTUNITY-ENGINE-V1.md`,
`docs/automation/WEBSITE-TEMPLATES-V1.md`.

The goal is organic traffic for a local business, not volume: find useful topics from the Business's real
services, packages and areas; never compete with its own money pages; write grounded drafts the owner reviews;
publish through the Website it already has; and surface only deterministic facts to Growth Center.

## 1. Authority (who owns what)

| Concern | Owner |
|---|---|
| Article record, status, slugs, redirects | `ArticleManager` + `website_articles` (this contract) |
| Public rendering, canonical host, template, header/footer/CTA, responsive images, sitemap | **The Website renderer** (`public.website.page` layout + `WebsiteBlogRenderer`). There is no second renderer, sitemap, canonical or robots system. |
| "Is the site open to search" | `WebsiteSearchVisibility::siteOpenToSearch()` — the published Home page's `noindex` flag (the owner's Website-wide choice) |
| Recommendations lifecycle | Growth Center. The content engine owns facts only (`GrowthContentFactReader`); there is no content recommendation table |
| Rank data | Existing rank tracking. Articles read cached observations only; publishing never creates a rank target |
| Pricing truth | Packages & Products (`ArticleCatalogFacts`) |

### Why articles are not Website pages or snapshot content

A Website page is a section document frozen into an immutable revision and published/rolled back as a whole site. A
blog is many small documents each published, scheduled and archived on its own. Forcing every post through a
whole-site republish would put posts in revision history, bloat every snapshot and let a rollback silently
unpublish posts. So an article is its own publication record, **rendered by the one Website renderer**. The published
Website revision still decides whether the site is live at all and supplies navigation, brand and the pages an
article may link to. Editing a *published* article never changes the public page: Save writes a pending draft (website_articles.draft_payload,
validated like any edit) that Preview shows, and **Publish update** deliberately replaces the live version (an old slug
leaves its redirect only then). Discard removes the draft; Archive stays an explicit action. One pending draft per article,
not a revision history.

## 2. Model

`website_articles` — `uid` (stable UUID), `business_id`, `website_id`, optional `business_location_id`, `title`,
`slug` (unique per Website), `excerpt`, `body` (safe Markdown), `status` (`draft|scheduled|published|archived`),
`featured_asset_id` (a `WebsiteAsset` of the same Website), `seo_title`, `meta_description`, `noindex` (owner choice),
`author_name`, `primary_topic`, `topic_signature`, `search_intent`, `supports_page_uid` (the whole content-cluster
concept: the money page an article supports), `opportunity_key`, `source` (`manual|opportunity|ai_draft`),
`ai_generated`, `referenced_catalog_uids`, `published_at`, `scheduled_at`, `archived_at`, `content_updated_at`,
`last_reviewed_at`, audit user ids.

`website_article_slug_history` — every slug a published article has had; unique per Website; rows are only added.
It is the redirect authority for articles (no Website-level redirect authority exists).

Nothing is ever deleted. Archiving removes an article from the index and sitemap; its slug stays reserved.
Opportunities are **computed, never stored**.

### Status rules (`ArticleStatus::allowedNext`)
Draft → Scheduled/Published/Archived; Scheduled → Draft/Scheduled/Published/Archived; Published → Archived only;
Archived → Draft. The original `published_at` is never rewritten, including after archive + republish.

## 3. Body and internal links

Body is Markdown, rendered only by `ArticleMarkdown`: raw HTML stripped, unsafe schemes refused, no images (the
featured image is the only picture and goes through `ResponsiveImage`), a body `#` is demoted to `##` (the page owns
the one H1). Internal links are **stable references** `[anchor](page:<uid>)` / `[anchor](article:<uid>)` resolved per
surface at render time. A reference to an unpublished, noindex, deleted, archived or other-Business target renders as
plain text — never a dead or stale link, never a Preview URL.

## 4. Public routes and SEO

- Custom domain: `/blog`, `/blog/{slug}` (`ResolveCustomDomainWebsite`). Platform path: `/sites/{public_id}/blog`,
  `/blog/{slug}` (`Public\WebsiteBlogController`). Both call `WebsiteBlogRenderer`. `blog` is a reserved page slug.
- Only `Published` articles are ever loaded by a public path (`WebsiteArticle::published()`).
- **Indexable** = custom domain surface AND site released for search (the Website's own canonical state, `websites.indexing_released_at`, set by "Let search engines find these pages" and kept through a rebuild; there is no second switch) AND article not `noindex` AND published. The platform
  path and Preview are always noindex; an owner `noindex` can only narrow indexing.
- Canonical always points at the Website's Active primary custom domain (`WebsiteBlogSurface`); none without one.
- Social: `og:type=article`, published/modified times, featured image via `WebsiteSocialMetadata::forArticle`.
- Structured data (indexable articles only, real data only): `BlogPosting` (headline, datePublished, dateModified,
  author = owner display name as `Person` else the Business as `Organization`, publisher = the Business, image, logo if
  the Website has one, canonical) and `BreadcrumbList` (Home → Blog → article). No ratings, awards or invented dates.
- Sitemap: blog index (when it has an indexable article) + every published, indexable article join the existing
  sitemaps (custom domain and platform path). None while the site is closed to search.
- Slug change on a once-published article records the old slug; `/blog/{old}` 301s to the current address while the
  article is published and 404s after archive.
- Navigation: a "Blog" item appears (primary + footer) only once a published article exists.

## 5. Opportunities, cannibalization, clusters

- `ArticleOpportunityEngine` — deterministic; inputs: Niche Blueprint `seo_strategy.content_topics`, the Business's
  services/packages/locations, active informational tracked keywords, existing articles. Capped (24), expanded only for
  the top three services and the primary city. **No search volume, CPC or competition is ever shown.**
- `ArticleTopicSignature` splits a phrase into *core* subject tokens and informational *modifier* tokens (cost, how,
  vs, ideas, ...). `ArticleCannibalizationGuard`: a topic with no modifier whose subject is contained in a Home /
  service / location / packages page's target phrase is a **strong money-page conflict** and is never recommended or
  drafted; same-subject-same-modifiers article overlap is a **strong duplicate** needing explicit owner
  acknowledgement; same subject, different angle is only "related".
- `ArticleContentPlan` — clusters = money page → supporting articles (`supports_page_uid`) + gaps.

## 6. AI drafting

`ArticleDraftGenerator`, one explicit owner POST, one AI call through `WebsiteAiGenerationClient` (`website_generation`
category/budget). The prompt carries only `ArticleGroundingFacts` (name, services, real packages and prices, areas,
FAQs, niche topics) and the allowed link references, and explicitly forbids invented prices, years, awards, statistics,
counts, celebrity clients, ratings, locations, guarantees and quotations. Output is normalised (no H1, no images, no
external links, no unlisted references). A topic that would cannibalize a money page is refused **before** any AI call.
The result is only ever a **Draft**. `ArticleClaimGuard` (deterministic) flags invented figures live; hard findings
block publishing/scheduling. No AI call exists on any list, page render, sidebar, Growth read or public page.

## 7. Entitlements (no new platform feature)

`SeoBasicVisibility` (Core, Growth, Agency): Content area, Articles, editor, preview, schedule/publish/archive,
checklist, manual articles. `SeoModule` (Growth, Agency): Opportunities, Content Plan clusters, AI drafting,
freshness/rank panels, Growth content facts. Failures are 404; a Core Business sees Articles with an honest note.
Capabilities: `view_seo` reads, `manage_seo` writes.

## 8. Freshness, rank, Growth facts

`ArticleFreshness` (old > 365 days, changed/removed package, supported page gone or noindex), `ArticleRankSignals`
(position 8–20, material decline, stale + declined, top-5 as a positive fact; linked by normalized phrase equality of
`primary_topic` and a tracked keyword; cached observations only). Wording is "may help", never causation.
`GrowthContentFactReader` (domain `content`, feature `SeoModule`) feeds two rules in `ContentRules`
(`content.topics_not_covered:v1`,
`content.article_stale:v1`); target `seo.content`. Rank movement stays with the canonical `seo.*` rank rules (no duplicate).

## 9. Scheduling

`articles:publish-due` (every minute, `withoutOverlapping`) calls `ArticleManager::publishDue()`. Each due article is
re-checked first; one that no longer passes returns to Draft instead of going live. Auto-publish of AI content never
happens without an explicit owner publish or owner-chosen schedule.

## 10. Out of scope (V1)

Autonomous/mass publishing (Content Autopilot, Contract 25, adds validated, policy-gated self-publishing and maintenance on top of this engine, never mass publishing), backlinks, WordPress/external CMS, social posting, plagiarism checks, real search-volume
estimates, automatic rewriting, AI case studies or reviews, programmatic city pages.

## 11. Tests

`tests/Feature/Seo/Content/*` — `PublicBlogTest`, `ArticleManagerTest`, `ArticleCannibalizationGuardTest`,
`ArticleOpportunityEngineTest`, `ArticleDraftGeneratorTest`, `ArticleOperationsTest`, `ArticleRankSignalsTest`,
`ContentHttpTest`, `ContentTenancyEntitlementTest` (fixture: `Concerns/CreatesContentFixtures`, the Chicago photo booth
company). The Niche Blueprint content-topic tests live with the adapter tests.
