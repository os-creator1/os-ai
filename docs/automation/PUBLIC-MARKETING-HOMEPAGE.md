# Public Marketing Homepage

## What this is

The platform's own public front door at `/`, replacing the previous
unconditional `redirect('login')`. It shows the genuine product workflow
(publish → capture an inquiry → reply in Conversations → book → propose →
get paid), a plan comparison sourced live from the same catalog the signup
flow uses, a Photo Booth example, an FAQ, and (once the owner supplies real
media) video testimonials. It leads into the existing, unmodified V1 signup
and hosted Stripe Checkout flow.

This is **not** a customer's own generated website — that is the separate
Website builder (`app/Http/Controllers/Public/WebsiteController`,
`resources/views/public/website/**`), untouched by this work.

## Routes

- `GET /` → `App\Http\Controllers\Marketing\HomeController@index`
  (`routes/web.php`). Still redirects to `/install` first when
  `config('app.stage') == 'new'` — that check is unchanged.
- Signup (`/register`, `/signup/plan`, etc. in `routes/auth.php`) and its
  controller/manager are **unchanged** — only the two Blade views
  (`resources/views/auth/v1-signup.blade.php`,
  `resources/views/auth/v1-signup-plan.blade.php`) were restyled onto the
  same layout as the homepage.
- Admin: `admin/marketing-content/*` (`routes/admin.php`) →
  `App\Http\Controllers\Admin\MarketingContentController`, gated by the
  existing `'general settings'` ability (same as branding — no new
  permission string).

## Data sources — nothing new is invented

- Plans: `App\Library\PlatformBilling\PlatformPlanPresenter::sellablePlans()`
  — the exact same read model the signup page uses. The homepage never
  computes or hard-codes a price, currency, cycle, trial, or capability.
- Branding (app name, logo, footer): the existing
  `App\Library\Branding\BrandingPresenter` via the existing
  `<x-branding-logo>` / `<x-branding-favicon>` / `<x-branding-footer>`
  components. No second brand identity.
- Theme accent color: `App\Library\Theme\PlatformThemeManager::currentStyleBlock()`
  — the same CSS-variable injection the authenticated app shell already
  uses (`resources/views/panels/styles.blade.php`). `public/css/marketing.css`
  references `var(--color-primary)`, `var(--color-canvas)`,
  `var(--color-surface)`, `var(--color-text-primary)`, etc. — never a
  hardcoded hex — so the homepage always reflects whichever Theme Preset is
  active.
- Product capabilities: `App\Library\Entitlement\PlatformFeatureCopy`.
- Photo Booth example: the real, shipped
  `App\Library\Website\WebsiteFormPresets::photoBoothQuoteRequest()` field
  list, rendered as a labelled example ("Example data shown"), not live
  customer data.

## New, small, owner-editable Marketing Content surface

Three new tables (`database/migrations/2026_10_11_120000...120002`):

- `marketing_content_settings` — a singleton row for the hero headline and
  supporting copy.
- `marketing_faqs` — question/answer/position/visibility.
- `marketing_testimonials` — name, `business_context_label` (always makes
  clear this is feedback from an earlier, unrelated business — never a
  review of this software), optional poster image, optional external
  video URL, optional transcript text, position, visibility.

**No testimonial rows are seeded.** The testimonials section is entirely
omitted from the homepage until the Platform Owner adds a real entry (name,
poster image, and either a video URL or approved wording) through
`/admin/marketing-content` and marks it visible.

Poster image uploads are validated by `App\Rules\ValidMarketingImageRule`
(magic-byte content check, reusing
`ValidBrandingImageRule::detectExtension()`) and stored by
`App\Library\Marketing\MarketingTestimonialAssetService` under
`public/images/marketing/testimonials/` with content-hashed filenames —
mirroring `BrandingUploadService`'s pattern without touching branding's own
field map.

This is deliberately not a page builder: every field is a plain typed
column, and no view ever renders unescaped/raw HTML from these tables.

## What is still needed from the business owner

- The 5 real video testimonials mentioned in the original request: for
  each, an approved name, an approved `business_context_label` wording,
  a poster image, and either a video file/link or a place to host one.
  None of this is invented or seeded — add each through
  `/admin/marketing-content` once supplied.
- Optionally: a homepage headline/subheadline and FAQ entries, if the
  bundled defaults should be replaced.

## Explicitly out of scope / untouched

Phone module, `app/Library/Seo/*` (SEO audit module), Proposals, the
customer Website builder, and the payment backend
(`V1SignupManager`, `PlatformStripeGateway`, webhook handlers).

## Tests

- `tests/Feature/Marketing/PublicHomepageTest.php` — the install-stage
  redirect is preserved, plans/FAQ/testimonials only ever show
  owner-approved, complete, visible rows.
- `tests/Feature/Marketing/MarketingContentAdminTest.php` — permission
  gating, hero copy save, FAQ CRUD, testimonial CRUD including the
  required-poster-image rule and file cleanup on delete.
- `tests/Feature/PlatformBilling/V1SignupHttpTest.php` and
  `V1SignupTest.php` (existing, unmodified) — re-run to confirm the
  signup/resume view restyle changed no behavior.
