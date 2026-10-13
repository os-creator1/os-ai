# Launch Home readiness (Launch → Growth)

Home shows the **Launch** journey (`HomeSetupJourney`, "Let's get your business ready") according to one of two
policies. Which one governs a Business is a durable, explicit fact — not a date comparison and not a guess from
which fields happen to be missing.

## Which policy governs a Business

| Business | Policy |
| --- | --- |
| Completed the SEO-first onboarding (Business → Location → Services → Website → Finish), including a partial legacy onboarding finished in the new flow | `seo_first_v1` |
| Established before the policy: completed the earlier onboarding, or never had one | legacy |

`OnboardingManager::complete()` writes `readiness_policy = seo_first_v1` into `customer_onboardings.metadata`, once, in the
completion transaction. Nothing else writes it, so visiting `/onboarding` never reclassifies an established Business and a
legacy completed onboarding is never retro-stamped. The status statement Home already issues reads it with the other
readiness facts (no extra query).

**Legacy Businesses keep exactly the Home-stage eligibility they always had**: Calendar setup (a Location, a booking type,
bookability) is essential when Calendar is in play and the website is a non-essential growth step. A broken integration or
critical attention item keeps its treatment (a legacy Business with a revoked Google connection stays in the *broken* state).

## `seo_first_v1`: foundation + reach

Home stays in Launch while EITHER is false. Both are Business-wide and identical for every viewer.

1. **Foundation** (what onboarding collects), from canonical records: Business name and niche; an active primary
   Location; an active primary Service. Once present Home never asks for them again.
2. **Reach** (customers can find or contact the business), at least one of:
   - a **published** MotionGrove website;
   - an **existing** website (`website_mode = external`) whose **latest usable check** — completed, with pages actually
     fetched — is of the Business's *current* address (both normalised by `UrlNormalizer`: lower-case host, no `www.`; never the
     stored `canonical_domain`) and no older than two re-check intervals (`external_site_audit.recrawl_interval_days`).
     A saved address, an old address's check, or a failed / queued / running / zero-page check counts for nothing;
   - a **working enquiry path**: a form visitors can actually submit — active Form, its current version, an enabled
     deployment from a supported source, at an active Location of the same Business (`FormDeploymentResolver`'s
     rules) — or an active website quote form on a published site bound to an active Location of the Business
     (`FormLocationResolver`'s rules); or a booking type whose own readiness verdict is ready, anywhere in the Business.

Reach is waived only when the **plan** gives the Business no route to it at all (Website, Forms and Calendar all
unentitled). It is never waived because a viewer lacks permission.

## Readiness versus actions

Readiness is derived from Business-wide facts and entitlements only. Permissions and Location access decide only whether a step
carries a **link**; a viewer without permission sees the same stage and steps without actions. The Calendar summary is
actor-scoped (the viewer's own Locations) and is display-only; the Business-wide booking boolean carries no Location, type,
name or reason, and is read only when needed (no other reach evidence) and only when the actor's summary does not already cover
every active Location.

## Unknown is not ready

If the status could not be read, Home shows a neutral "Setup progress could not be loaded" notice. No step is marked
done, the Business is not called ready, and it is never presented as Growth. Real attention / broken handling is unchanged.

## What never gates Home

Google Business Profile, Search Console, mailbox, texting, Stripe and Ads are never Launch steps. Calendar is optional.
Completing onboarding proves the foundation only; leaving Launch proves foundation + reach — not growth. Growth
opportunities, rankings and activity come from their own modules' measurements.

## Cost

The readiness facts are `EXISTS` / scalar probes inside the single `DashboardStatusReader` statement, each on an existing index
(`business_locations (business_id,is_primary)` and `(business_id,lifecycle_state)`, `business_services (business_id,is_primary)`,
`forms (business_id,lifecycle_state)`, `form_deployments (form_id,…)` unique, `form_versions (form_id,version)` unique,
`website_forms (website_id)`, `external_site_crawls (business_id,id)`, `customer_onboardings (business_id)`). No migration or
index was needed.
