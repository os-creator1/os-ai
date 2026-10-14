# Launch Home readiness (Launch → Growth)

Home shows the **Launch** journey (`HomeSetupJourney`, "Let's get your business ready") according to one of two
policies. Which one governs a Business is a durable, explicit fact — not a date comparison and not a guess from
which fields happen to be missing.

## Which policy governs a Business

The policy is a fact of the **Business**: `businesses.readiness_policy` (nullable, additive, no backfill).

| Business | `readiness_policy` | Policy |
| --- | --- | --- |
| Created from now on, by any path (signup, onboarding, Workspace management, Agency provisioning), first or secondary, in any Workspace | `seo_first_v1` | SEO-first |
| A Draft Business that predates the column and finishes the SEO-first onboarding | `NULL` → `seo_first_v1` at completion | SEO-first from then on |
| Established before the policy (completed the earlier onboarding, or never onboarded) | `NULL` | legacy |

There is exactly one creation seam, `BusinessRepository::createForCustomerInWorkspace()`, and it writes the marker, so no creation
path can forget it and none duplicates the logic. The only other writer is `OnboardingManager::complete()`, which calls
`BusinessRepository::adoptReadinessPolicy()` (NULL → policy only) in the completion transaction. Visiting `/onboarding`, completing a
legacy onboarding again, moving a Business to another Workspace, or a different user acting on it never changes the column, so
the policy is durable and independent of the customer who created the Business. Home reads it inside the status statement it
already issues (no extra query). The one-per-customer `customer_onboardings` row carries no policy.

**Legacy Businesses keep exactly the Home-stage eligibility they always had**: Calendar setup (a Location, a booking
type, bookability) is essential when Calendar is in play and the website is a non-essential growth step. A broken integration or
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

## Setup links belong to the Business being viewed

A missing foundation step opens the editor of **that** Business. The onboarding wizard is offered only when the customer's
one onboarding is bound to this very Business (so it edits it) and the viewer is its owner; a secondary Business, or a
viewer who is not the owner, is sent to that Business's own scoped Settings (and Locations). The primary Business's details
page is used for the primary Business only. Every link passes `DashboardLinkGate` (permission, view-as and entitlement).

A missing primary Service always opens the Business-scoped **Settings → Services** editor
(`customer.workspaces.businesses.services.*`), for every Business, so a secondary Business never needs (or is sent into)
the onboarding wizard of another Business. It is offered only to a Workspace owner or active Admin; anyone else sees the
step without an action. The editor adds, renames, turns off/on and picks the primary of `BusinessService` rows (not catalog
items) through `BusinessServiceManager`, which writes only via the canonical `BusinessManager::syncServices` seam under the
Business row lock: services are never deleted (turned off instead), slugs are stable, and exactly one active service is
primary whenever any is active.

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
`website_forms (website_id)`, `external_site_crawls (business_id,id)`, `customer_onboardings (business_id)`); the policy itself is a column of the
Business row the statement already reads. The only schema change is the additive nullable `businesses.readiness_policy` (migration
`2026_11_21_100001`, reversible, no backfill, no index: it is never a filter). No index was needed.
