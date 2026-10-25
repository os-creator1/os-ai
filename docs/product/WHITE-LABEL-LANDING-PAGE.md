# White Label — Branding and Landing Page

One Agency-account module, two tabs, one brand. An Agency brands the product its customers use, publishes a
marketing website built from the real MotionGrove public design, sells its own subscription plans through its own
Stripe account, and every customer who pays becomes a managed Business account of that Agency.

This document is the map for reviewers and operators. Nothing here is a second engine: each part extends or calls
the module that already owns the concern.

## Where things live

| Concern | Reused (unchanged authority) | Added |
|---|---|---|
| Brand identity | `AgencyWhiteLabelSetting`, `AgencyWhiteLabelManager` (owner-only, audited, idempotent), `ClientWorkspaceBrandResolver`, `AuthBrandPresenter` | Extra fields and image slots (dark/compact logo, favicon, secondary colour, support link, legal name, social links); `AgencyThemeStyle` (design tokens); `DatabaseAgencyBrandSource` (verified host → brand); hosted sign-in |
| Domain proof | `DnsVerifier` (exact TXT match) | `AgencyAppDomainService` (record + verify only) |
| Marketing site | the `resources/views/marketing` views and `MarketingSite` read model | `AgencyMarketingSite`, `SiteContentBuilder`, `SiteSchema`, `SiteDefaults`, `SiteRenderer`, `AgencySiteManager` (draft/publish) |
| Booking | `BookingTypeManager`, `StaffAvailabilityService`, public `/book/{uuid}` | `AgencySiteBookingService` (list / create / readiness) |
| Plans and Stripe | `AgencySaasPlan(Manager)`, `AgencyStripeConnection`, `AgencyClientSubscriptionManager`, finalizer, Connect webhook, `FakeAgencyStripeGateway` | none — the site only reads them |
| Customer provisioning | `AgencyClientProvisioningManager`, `AgencyClientRelationshipManager`, `UserRepository` | `AgencySiteSignupService`, `provisionClientWorkspace()` (extracted from the invitation flow) |

Migrations (`2026_11_26_100000`, `2026_11_26_110000`) are additive: nullable columns on `agency_white_label_settings`
and three new tables (`agency_sites`, `agency_site_revisions`, `agency_site_signups`). `down()` drops them.

## Branding tab

Name, three image slots plus favicon (PNG/JPG/WebP/GIF, ≤ 2 MB, dimension-bounded, magic-byte checked, SVG refused,
content-hashed names under the Agency's own directory), primary and accent colours, support email and link, legal
name, social links (https, host-restricted). The page shows a live preview of the sidebar, top bar, buttons, sign-in
screen and browser tab, and the generated palette. Save, "Reset changes", and the existing audit history.

Propagation is by shared layers, not per page: `panels/styles` appends the Agency's colours as the existing
`--color-*` design tokens for a managed client (brand and interaction tokens only; status colours stay semantic and
links are darkened until they are readable on white); the layout identity (title, favicon, logo, footer/support),
error pages, branded reset/verification mail, and the sign-in screen. A direct MotionGrove customer, the Agency's own
workspace and another Agency's clients never see it.

App domain (`app.agency.com`): the Agency adds a hostname and a TXT record
(`_platform-verify.<host> = <token>`); **Check DNS** marks it `verified`. Verified means *ownership proven*, not
*live* — see "Not completed".

## Landing Page tab

Opening the tab as the owner creates the starter site (a complete draft). Ten screens: Business & website, Home (15
sections), Product, Solutions, How it works, Customers, Pricing, Book a call, Signup & payment, Review & publish.
The schema (`SiteSchema`) declares every question, its bounds and its path; the same declaration renders the form and
validates the save, so an undeclared setting can never be written. Saves are JSON and in place; the preview iframe
renders the saved draft; Desktop/Mobile toggles the frame width; the current screen is stored so Back/Next/reload keep
the place. Every section supports edit, show/hide (where hiding is allowed), reset to default and preview; home
sections, product features, FAQ, industries, plans and testimonials reorder.

* **Saving**: every autosave goes through one queue (`public/js/agency-site-saver.js`). What counts as saved is what
  was submitted, requests are applied in order, and every save returns an explicit success or failure. **Save draft**
  says "Draft saved" only after the server confirms, and **Publish** first saves every pending edit and is refused
  (edits kept on screen, error shown) if any save fails. Tests: `node --test tests/Js/agency-site-saver.test.cjs` and
  `AgencySiteEditorScriptTest` (real editor in Chromium against a simulated server).
* **Starter copy** reuses the MotionGrove wording for what the *software* does and replaces everything only the Agency
  can promise (support, onboarding, price, billing cadence, results, customer stories). The two sections that exist
  to make such promises (Support & onboarding, Setup commitment) start hidden.
* **Testimonials** show only when marked "I have permission to publish this"; none approved → the page and the home
  section stay hidden. No MotionGrove or Jazmin Media story is reachable from an Agency site.
* **Pricing** shows the Agency's published plans with the plan's own price, currency and interval. The editor stores
  only presentation (visibility, order, badge, button text, description, bullet text). Tier — and therefore what the
  purchase unlocks — and the Stripe price come from the plan and cannot be edited here. The comparison table is
  generated from the platform's own tier packaging (`workspace_plan_features`).
* **Modes**: subscriptions, book a call (prices hidden, buttons open the calendar), or both (default).
* **Draft vs live**: edits change the draft only. **Publish** freezes a revision; the public site reads revisions.
  Prices, plan availability and the booking link are read live so an advertised price always matches the charge.

Publishing is refused (with the reasons) when: a subscription funnel has no published plan, a shown plan is not
sellable, Stripe cannot take payments, privacy/terms links are missing, the selected booking type is missing or not
bookable, a button has no working destination, or a page fails to render.

## Customer journey

1. Visitor opens `/w/{publicId}` (hosted site), chooses a plan, opens `/w/{publicId}/signup/{planUid}`.
2. Name, business, email, password, terms. The Agency is the one that owns that site; nothing about the Agency, plan
   price or Stripe account is a form field.
3. In one transaction: user + customer, Client Workspace, Business, primary location and the **active relationship to
   that Agency**; then the plan is offered, and Stripe Checkout opens **on the Agency's connected account** with the
   payer's consent.
4. The visitor is signed in; Stripe returns them to the existing `agency-plan.return`, which re-reads Stripe. The
   Connect webhook converges the same state. Only a paid state assigns the plan's tier (existing finalizer).
5. The customer enters the Agency-branded app. The Agency sees them in its Clients list.

Retries resume the same account (proof: its password); an existing platform account is never attached; failed,
incomplete or abandoned payments grant nothing; webhook replays and out-of-order events are ignored by the existing
event ledger.

## Manual configuration

* **Stripe**: the Agency must have completed Connect onboarding, created a plan, bound/verified its Stripe price and
  published it (existing "SaaS" screens). The platform's canonical Core/Growth catalog rows must be priced (existing
  prerequisite of lane C).
* **Calendar**: the Agency Business needs a location and Calendar entitlement; the editor creates the booking type,
  makes the owner bookable and can give standard weekday hours.
* **Email**: account mail is sent from the platform's authenticated sender with the Agency's display name and its
  support address as Reply-To. A custom sender domain is **not** offered.
* **DNS (app domain)**: add the TXT record shown, then **Check DNS**. To serve traffic an operator must additionally
  point a CNAME at the platform, add the host to the trusted-host list and issue a certificate.

## Not completed (and why)

* **Custom marketing domain** (`www.agency.com`) and **live app domain** (`app.agency.com`): ownership verification and
  hostname → Agency resolution exist and are tested, but the platform does not answer on those hosts. That needs
  TrustHosts, session-cookie domain, CNAME and certificate provisioning, which are server decisions outside this
  change. The UI says "verified, not live". The hosted `/w/{publicId}` site and its branded sign-in are the working
  equivalent today.
* **Setup fees, location allowances and usage limits** on resale plans: `AgencySaasPlan` has no such fields and the
  entitlement system packages by tier. They are not simulated.
* **Plan creation inside the editor**: plans are created and priced in the existing Plans screen (which owns Stripe
  price binding); the editor links to it and shows each plan's sell-readiness.
* **Per-recipient brand for mail a client Business sends to its own customers** stays the Business's brand by design;
  only account mail (reset, verification) and Agency invitations carry the Agency's.
* **Video upload** for testimonials: a YouTube link or ID is supported; other media is not.

## Invariants worth knowing

* **One conversion mode.** `x.goal` (subscriptions / book a call / both) is the only setting. The old
  `x.pricing_mode` is accepted as an alias on save, never stored; a stored legacy value wins on read, because it is
  what visitors were shown. The publish checklist has a `conversion` item that fails when nothing can be bought or booked.
* **One payable Checkout per customer.** Re-offering identical terms is a no-op; a changed plan keeps the old session
  id so the next checkout expires it at Stripe before opening the new one. Double submits, returning to signup and
  simultaneous retries share one Stripe idempotency key. If the old session cannot be proven expired (expire refused and
  a re-read still shows it open, or cannot be read) no new session is opened and the customer is asked to retry.
* **Availability is only ever added.** Creating a booking type seeds Mon–Fri 09:00–17:00 only when the owner has no
  availability at that location; existing weekly hours are never replaced.
* **One hostname, one owner.** `domain_reservations` (unique `domain`) is claimed in the same transaction that creates a
  website domain or sets an Agency app domain, and released on delete/remove/re-point. Platform hostnames are refused.

## Acceptance on staging

1. As an Agency owner open **White label → Branding**; set a name, logo, favicon and colour; watch the preview update
   without saving; save; open a managed client's account in another browser and confirm the name, logo, favicon,
   colour, and footer support line. Open a direct customer: unchanged.
2. **Landing Page**: walk screens 1–10; hide a home section, move another, rename a product feature, reload the page
   and confirm everything persisted. Toggle Desktop/Mobile in the preview.
3. Create a booking type from screen 8; confirm the preview's booking button opens its public booking page and a
   test booking appears in the Agency calendar.
4. With Stripe test mode connected and a plan published, confirm screen 9/10 show green, add privacy/terms links, and
   **Publish**. Open `/w/{publicId}` while logged out. Edit the headline and confirm the live site is unchanged until
   **Update website**.
5. On the live site choose a plan, sign up, pay with a Stripe test card; confirm the new workspace appears under
   **Clients**, is on the plan's tier only after payment, and signs in to the Agency-branded app. Repeat the form with
   the same email: no second account.
6. Use a declined/incomplete test card: no plan is assigned.
7. Add `app.<your-domain>` under Branding, add the TXT record, **Check DNS**: status becomes "Ownership verified —
   not live".

## Applying an industry preset to one Agency (operator procedure)

`php artisan agency-site:apply-preset {agencyWorkspaceUid}` writes the **dog training and boarding** content into that
Agency's own Landing Page **draft** through the editor's validated save path. It is a dry run unless `--apply` is given,
refuses the production environment, never publishes, and never touches other Agencies, shared defaults, the brand, plans
or Stripe.

1. `php artisan agency-site:apply-preset <uid>` — review the table: sellable plans, bookable booking type, chosen
   conversion goal, and which already-edited values would be replaced.
2. Optional: `--create-booking-type` adds a 30-minute "MotionGrove Demo — Dog Training & Boarding" type when none is
   bookable. It is created **without working hours**; add availability in the Calendar before it can be booked.
3. `php artisan agency-site:apply-preset <uid> --apply` — saves the draft and lists what still blocks publishing.
4. In the editor: add privacy and terms links (Footer), add availability if needed, check desktop and mobile in the
   preview, then publish from the Review screen.

Left unwritten on purpose: testimonials and the Customers page, "Support & onboarding", "Setup commitment", legal
links, brand identity. The How-it-works page holds at most four steps, so the five-stage journey is condensed into four.
