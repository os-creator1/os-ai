# Blog compatibility closeout

Reference: the Kaleb Hill v3 `BLOG-COMPATIBILITY.md` "Required integration tests in MotionGrove" (items 1–8). That file ships in the reference ZIP, not in this repository.
Result: **every item is already implemented and covered; no production code changed.**

## Checklist → evidence

| # | Requirement | Evidence (all in `tests/Feature/...`) |
|---|---|---|
| 1 | 0 published articles → `/blog` 404, no nav entry, no blog URLs in sitemap | `Seo/Content/PublicBlogTest`: `test_the_blog_index_is_a_404_until_there_is_a_published_article_and_nav_only_then_links_it`, `test_without_a_published_article_the_blog_is_404_with_no_nav_entry_and_no_sitemap_blog_urls`; `Website/FaqAndGuideLinksTest`: `test_a_site_without_published_guides_has_no_guide_band_and_no_blog_links` |
| 2 | Draft / scheduled / archived not public, not in sitemap, even when addressed directly | `PublicBlogTest::test_drafts_scheduled_and_archived_articles_are_never_public`; `FaqAndGuideLinksTest::test_only_published_indexable_guides_of_this_site_are_ever_linked` |
| 3 | Published indexable article on the custom domain: routes, canonical, breadcrumbs, `BlogPosting` (real author/dates), sitemap | `PublicBlogTest::test_a_published_article_renders_through_the_website_layout_with_full_seo`, `test_author_defaults_to_the_business_and_is_never_a_person`, `test_a_published_eligible_article_is_on_the_blog_routes_and_in_the_sitemap` |
| 4 | Platform / preview: `noindex`, canonical rebased or omitted, no preview URL in sitemap | `PublicBlogTest::test_the_platform_path_serves_the_blog_noindex_with_the_custom_domain_canonical`, `test_a_platform_only_site_has_no_canonical_and_no_preview_urls_leak`, `test_the_preview_shows_a_draft_noindex_with_a_banner_and_never_a_canonical`, `test_the_blog_cannot_bypass_a_website_the_owner_has_not_opened_to_search` |
| 5 | Article `noindex`: out of indexable URLs and sitemap without noindexing the site | `PublicBlogTest::test_an_owner_noindex_article_is_noindex_and_out_of_the_sitemap_and_schema`, `test_an_article_noindex_never_makes_the_rest_of_the_website_noindex` |
| 6 | Nine per page, ordered, unique canonical per `?page=N`, prev/next links | `PublicBlogTest::test_the_blog_index_paginates`, `test_blog_pages_are_ordered_newest_first_without_overlap_and_have_their_own_canonicals_and_links` |
| 7 | Slug change → 301 from the old slug; no stale service cross-links | `PublicBlogTest::test_changing_a_published_slug_301s_the_old_address_and_archiving_ends_it`, `test_a_draft_slug_change_leaves_no_redirect`; `FaqAndGuideLinksTest::test_a_published_slug_change_updates_service_page_guide_links_and_the_old_address_redirects` |
| 8 | Brand, typography, responsive behaviour, CTA and approved service links | `PublicBlogTest::test_every_template_renders_the_index_and_article`, `test_the_blog_call_to_action_never_links_to_a_packages_page_the_site_does_not_have`, `test_the_blog_call_to_action_finds_the_page_that_lists_the_packages_whatever_its_address`, `test_the_blog_index_call_to_action_uses_the_same_page_identity`, `test_internal_references_resolve_only_to_live_indexable_targets`; `Website/GeneratorAcceptanceTest::test_the_native_blog_uses_the_same_design_links_to_services_and_keeps_the_inquiry_journey` (same stylesheets, header navigation, service links, inquiry journey); visual: below |
| — | Related guides from commercial pages / home: published, indexable, same site, current URLs | `FaqAndGuideLinksTest` (12 tests, incl. relevance ranking and the three-card cap) |
| — | `blog` is reserved; generation/rebuild never writes a blog page or touches articles | `PublicBlogTest::test_blog_is_a_reserved_slug_for_pages`; `Website/WebsiteRebuildTest::test_rebuild_never_creates_a_blog_page_or_touches_existing_articles`; `Website/NicheSafeUrlsTest::test_guided_generation_leaves_existing_blog_articles_alone_and_makes_no_blog_page` |
| — | Reading the blog never calls an AI provider | `PublicBlogTest::test_reading_the_blog_makes_no_ai_or_provider_call` |

## Visual check (item 8): index and article, all four templates, 390 px and 1440 px

Rendered by `GeneratorAcceptanceTest::test_the_native_blog_uses_the_same_design_links_to_services_and_keeps_the_inquiry_journey` (fake AI) once per template
(`ACCEPTANCE_TEMPLATE=photo_booth_{modern,editorial,luxury,conversion}`), served statically with the site's real CSS and measured in Chromium.
Left to right in every image: modern, editorial, luxury, conversion.

* `blog-article-4templates-390.jpg`, `blog-article-4templates-1440.jpg`
* `blog-index-4templates-390.jpg`, `blog-index-4templates-1440.jpg` (1440 px images are scaled to 40%)

Measured (all 16 page/width/template combinations): no horizontal overflow (0 px), exactly one `h1`, article body 17.2 px / 30 px line height at both widths,
reading column 358 px at 390 and 759 px at 1440, brand typeface and colours from each template, the site header and navigation, an inquiry call to action
("Contact us", the supported service, "See our services") and the full footer on every page.

## Not covered / limits

* The fixture article has no in-body service links; service links come from the call to action and "Helpful guides" on the service page, which are tested.
* Fake AI only; no live customer site was rendered, deployed or regenerated.
