# Launch Home readiness (Launch → Growth)

Home shows the **Launch** journey (`HomeSetupJourney`, "Let's get your business ready") until the
Business has met two conditions. Both are read from canonical records on every request; no
onboarding step flag, browser state or stored percentage is consulted.

## 1. Foundation (what onboarding collects)

| Fact | Canonical source |
| --- | --- |
| Business identity | `businesses.name` and `businesses.industry` |
| Primary Location | an **active** `business_locations` row with `is_primary` |
| Primary Service | an **active** `business_services` row with `is_primary` |

Once these exist Home never asks for them again. A missing one links to the matching onboarding step
(owner only, never while viewing as a client).

## 2. Reach (customers can find or contact the business)

At least one of:

- a **published** MotionGrove website (`websites.status = published`);
- an **existing** website (`businesses.website_mode = external`) for which the Website module has a
  **completed** check (`external_site_crawls.status = completed`). A saved address alone is *not*
  evidence: it is never described as verified, published, indexed or editable;
- a **working enquiry path**: a booking type whose own `BookingTypeManager` readiness verdict is
  ready, a live form (`forms.lifecycle_state = active`), or an active website form on a published site.

Reach is required only while some route to it is open to the actor (Website, Forms or Calendar
entitled and permitted). An account with none is never held in Launch for something it cannot do.

## What never gates Home

Google Business Profile, Search Console, mailbox, texting, Stripe and Ads are never Launch steps and never
block access. Calendar is optional: its steps appear only once a booking type exists, and are never
essential. Completing onboarding proves the **foundation only**; leaving Launch proves *foundation +
reach* — not growth. Growth opportunities, rankings and activity come only from their own modules'
measurements, which Home reads but never estimates.

## Cost

The five readiness facts are `EXISTS` probes inside the single `DashboardStatusReader` statement Home
already issues, so they add **no query** per Home request. `HomeCommandCenter::setup()` issues no query of
its own.
