# Public marketing website

The public website for MotionGrove (`/`, `/product`, `/solutions`, `/how-it-works`,
`/customers`, `/pricing`) lives inside this Laravel application. There is no second
frontend and no separate hosting. The source of truth for the look and the copy is the
approved design hand-off (`HANDOFF.md` and the `*.dc.html` files); this document describes
how it is wired.

## Where things are

| What | Where |
| --- | --- |
| All copy, links, FAQs, plans, testimonials, switches | `config/marketing.php` |
| Read model (URLs, visibility, testimonials, SEO) | `app/Library/Marketing/MarketingSite.php` (`$mg` in views) |
| Controller | `app/Http/Controllers/Public/MarketingController.php` |
| Routes | `routes/marketing.php`; `/` in `routes/web.php`; `/pricing` in `routes/auth.php` (see below) |
| Views (layout + section components) | `resources/views/marketing/` |
| CSS / JS / logos | `public/marketing/{css,js,img}` (no build step) |
| Tests | `tests/Feature/Marketing/` |
| Pricing guard | `php artisan marketing:verify-pricing` |

The views contain layout and the drawn product illustrations only. Everything a person
reads that is likely to change comes from `config/marketing.php`, so an owner-only content
editor can later replace that one file with database rows (change `MarketingSite`, not the views).

## Hosts and the two shared URLs

`APP_URL` is the platform host: `getmotiongrove.com` in production and `staging.getmotiongrove.com` on staging.
No separate `app.` host exists anywhere in this repository (only `notify.getmotiongrove.com` for mail), so none is assumed.
With `MARKETING_HOSTS` blank the site answers on the `APP_URL` host **only**: not on `www.`, not on other
subdomains `TrustHosts` admits, and not on a customer domain that is not yet Active. List extra hosts explicitly
(for example `getmotiongrove.com,www.getmotiongrove.com`). Active custom customer domains are unaffected:
`ResolveCustomDomainWebsite` runs first and only ever serves that customer's own website.

Two marketing URLs already belonged to the application, and the router silently replaces a
second route on the same URI, so neither is simply re-registered:

* `/` keeps its legacy closure in `routes/web.php`. For a guest it now renders the marketing
  homepage. A signed-in customer, an uninstalled instance (`APP_STAGE=new`), a host outside
  `MARKETING_HOSTS` and `MARKETING_SITE_ENABLED=false` all behave exactly as before.
* `/pricing` is the signed-in customer's own pricing page (`user.account.pricing`, behind
  `auth` + `verified`). `ServeMarketingPricingToGuests` is attached to that route and answers a
  guest with the public page. A signed-in customer reaches the unchanged page.

## Book a demo

Every "Book a demo" / "Talk to us" control uses one destination, `MarketingSite::bookDemoUrl()`:

1. `MARKETING_BOOK_DEMO_URL`, if set (for example a verified Calendly link);
2. otherwise the native `/book-demo` page (`demo.booking.show`), **only** when that route exists
   and is provisioned: `DEMO_BOOKING_TYPE_UUID` plus both Turnstile keys (see `docs/product/BOOK-DEMO.md`);
3. otherwise the buttons are not rendered. The site never links to a booking page that would 404.

## Pricing

Public prices are Core $97, Growth $297, Agency $497 per 28 days, Growth featured. The private
$197 offer, the private 28-day trial, sales-call checkout links and any trial wording are never
public, and a test fails if they appear. "Choose a plan" buttons go to the application's own
catalog-validated signup (`/register?plan=core|growth|agency`), or to `MARKETING_CHECKOUT_<TIER>_URL`.
The site does not change Stripe, entitlements, registration eligibility or backend pricing.
`php artisan marketing:verify-pricing` (read-only) compares the website prices with `workspace_plan_catalog` and the
plan-comparison table with `workspace_plan_features`. Run it against each environment before launch.

## Placeholders, unconfirmed terms and unverified features

* A value that is not confirmed is `null` in the config. It renders nothing in production.
* `MARKETING_SHOW_PLACEHOLDERS` (default: on outside production) renders clearly labelled
  placeholders: video slots, setup-specialist card, "[Confirm]" billing terms.
* Copy for a feature that is not confirmed production-ready is flagged `'unverified' => true`
  and hidden unless `MARKETING_SHOW_UNVERIFIED_FEATURES=true`. Currently: missed-call text-back,
  win-back messages and automated review requests (no code in this repository sends them).
* Customer logos stay hidden until `sections.home.trust_logos` is on and `trust_logos` lists approved logos.
* Social, help and legal links render only when a valid `http(s)` URL is configured.

## Testimonials

Five slots (one featured, four supporting), shaped for a future CMS:
`{ id, youtubeId, customerName, businessName, category, location, quote, context, featured, order, visible }`.
`context` is `motiongrove` or `jazmin_media`; a Jazmin Media item carries its own tag and the disclaimer
renders whenever one is visible. Players are click-to-load (`youtube-nocookie.com`, autoplay only after the
click) with a plain "Watch on YouTube" fallback link. Nothing is invented: a slot without a valid 11-character
YouTube ID and a customer name is a placeholder.
