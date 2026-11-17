# Existing website -> MotionGrove-hosted website

Owner-reviewed path for a Business whose website is hosted elsewhere (`website_mode = external`).

## Safety contract
- The external site stays live and untouched. Nothing in this flow crawls, calls AI, publishes, connects/switches a domain, changes `businesses.website_url`, or deletes external audit history.
- The plan (`external_rebuild_plans`, one row per Business, always looked up by `business_id`) is built from the newest finished audit (`ExternalRebuildPlanner`: pure, no network). Rows are the old site's real paths only.
- Gathered facts (event types, GTA service areas) are UNCONFIRMED until the owner ticks them. Titles/descriptions containing claims (ratings, "best", awards, years, prices, guarantees) are flagged and never copied. Reviews and images are never imported (the crawl does not store them).
- Redirects are a reviewable 301 plan (`ExternalRebuildRedirectPlan`): `kept` (same path exists), `redirect` (one unambiguous draft page), `needs_decision` (legal/blog/other/ambiguous), `conflict`, `skipped`, `unsupported`. A target is never invented or defaulted to the home page.
- `Apply` (`ExternalRebuildApplier`) merges confirmed redirects into the DRAFT `custom_redirects` (never overwriting owner-defined ones) and copies chosen titles/descriptions into draft pages. Redirects only go live inside a revision the owner publishes.
- `Start` only switches the primary-site mode to hosted (as the Website screen already offers) and opens the setup wizard. Wizard generation now runs on the queue (`RunGuidedWebsiteGenerationJob`) and returns to the polled progress screen; the job completes or unfreezes the setup response.

## Rank tracking identity
`SeoRankIdentity` uses the hosted site's active primary domain first (unchanged). Only when the primary website mode is `external` and no hosted domain is active does it use the Business's website host, and only when the newest finished audit of that exact host reached the site (`connection_state` reachable). Matching stays exact-domain.

## Queued wizard safety
`RunGuidedWebsiteGenerationJob` completes or unfreezes the setup response only through `WebsiteGenerationCoordinator::settleOwned()`: one transaction under the Website row lock that requires the job's own lease token to still be current, and the response to be in progress and frozen. A timed-out or superseded job can therefore never settle a newer generation.

## Routes
`customer.workspaces.businesses.website.existing.{show,prepare,confirm,start,apply}`

## Known limitations
- Confirmed facts are offered in the wizard as display-only prefill (`ExternalConfirmedFacts` -> `WizardPrefill`): confirmed GTA areas follow the Business's own service areas, and confirmed event kinds appear as editable rows built from the questionnaire's own event presets. The owner's saved answers always win, nothing is saved until the owner submits the screen, and no text, price, claim, review or image from the old site is used.
- Legal pages are listed for the owner; legal text is never copied. Link the owner's privacy/terms address from the site footer.
- Old internal links are not rewritten; the 301 plan carries the old addresses.
- Slugs of generated pages are the generator's own; the plan maps old paths to them rather than renaming them.
