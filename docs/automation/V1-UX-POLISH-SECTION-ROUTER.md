# V1 UX polish — shared section router, MotionGrove loader, Website tabs

Branch `agent/v1-website-ux-polish`, based on `agent/v1-ui-polish-header-calendar`.
No SPA, no framework migration, no backend refactor: a small progressive enhancement over
ordinary server-rendered pages.

## What was found first

- **Automations has no smooth tab/view transitions.** It is ordinary full-page navigation, so there was
  nothing there to reuse.
- **The Calendar section router** (inline in `calendar/_scripts.blade.php`) was the existing pattern:
  the same controller action answers `?fragment=1` with just the centre region, and the script swaps it in.
  It was generalised rather than copied.
- `resources/js/core/async-region.js` (opt-in `data-async-region`) is a different, form/list-oriented tool and
  needs the mix build; it was left alone.

## The shared mechanism

Two Blade partials, so there is no build step and no `mix-manifest.json` churn:

| File | Purpose |
|---|---|
| `resources/views/partials/section-router/_script.blade.php` | `window.SectionRouter.mount({...})` |
| `resources/views/partials/section-router/_styles.blade.php` | region + MotionGrove CSS |
| `resources/views/components/motiongrove.blade.php` | `<x-motiongrove />` for server-rendered use |

Contract with the server:

1. A tab link is an ordinary `<a href>` (works with no JavaScript, deep links and middle-click).
2. The router requests `href` + `?fragment=1`. The **same controller action** answers with only the centre region
   (`_fragment` partial). Authorisation, tenancy, entitlements and routing stay server-side.
3. The response must contain the region by `id`. Anything else (non-2xx, a redirect to login, missing region,
   network error) falls back to a real `window.location.assign`.
4. The region carries `data-section-key` and `data-section-title`; the router updates the active tab
   (class + `aria-current`), `document.title`, optionally the visible heading (`headingSelector`) and a polite
   live-region announcement.

Behaviour: `pushState` on click, `popstate` for Back/Forward, newest request wins (generation ticket +
`AbortController`), modified clicks / `target` / downloads / cross-origin links are left to the browser,
and a `section-router:updated` event fires after each swap so a page can re-initialise its own widgets.

Only three surfaces opt in; there is **no global link interception**:

- Calendar internal tabs (`a[data-calendar-nav]`)
- Website internal tabs (`a[data-website-nav]`), plus in-page shortcuts marked `data-website-go="<tab>"`
- Content tabs (`a[data-content-nav]`) and in-section links (`a[data-content-local]`) - see "Content" below

## MotionGrove loader

Three thick vertical bars alternating height, drawn in CSS only (`--color-primary`, so it follows the
active theme and any White Label colour; the only literal is the token's fallback). It is inserted as the first
child of the swapped region, centred, only after **150 ms** (fast responses never flash it), with
`aria-busy="true"` on the region. `prefers-reduced-motion` shows static bars. No text, no full-screen spinner.

## Website

- Tabs: **Website · Packages · Forms · Questionnaires · Settings** (`/website`, `/website/studio/{tab}`;
  `settings` is new). Packages/Forms/Questionnaires reuse their existing tab content unchanged.
- **Overview**: a small real preview of the live site (labelled *Live*, opens the live site in a new tab) beside a
  *Draft / Preview* card (opens Preview). With no unpublished changes the draft card says so quietly.
  Actions: Preview, Publish (primary when there are unpublished changes), Edit (→ Settings). Previews are the same
  renderers visitors and the owner already see, framed and scaled client-side; no screenshot service.
  "Unpublished changes" = not yet published, or the existing health `draft_changes` check, or packages out of sync.
- **Connect your domain** card appears under the previews only when published and no domain is active
  (a pending domain shows "Finish connecting"). It disappears once a domain is active.
- **Settings** lists the existing canonical screens — Manage pages, Edit setup answers, Change template or
  rebuild, History, Domain — followed by *Your website's look*.
- **Look** keeps its title, Brand colour, Logo, Hero image and *Save changes*; the second heading and the layout /
  fonts / helper prose are gone (the wizard step keeps its own heading).
- **Template / rebuild** keeps its title, *Choose a template*, the cards and the mode choice. The standing
  warning banners are replaced by a confirmation dialog at the moment of action. The server still refuses an
  unconfirmed request (`confirm_rebuild`).

## Content (SEO -> Content)

Content is the third module on the same mechanism (no second router):

- Shell: `seo/content/_frame.blade.php` (title, subtitle, header action, tab strip, `#seo-content-region`) and
  `_fragment.blade.php` (the region alone, with the header action in an inert `<template data-content-action>`). A section view
  picks one with `request()->query('fragment') === '1'`, exactly like Calendar, and supplies `content-active`,
  `content-subtitle`, optional `content-action` and `content-section`.
- Tabs: `a[data-content-nav]` with `data-content-key` (`autopilot|articles|plan|opportunities`); in-section links that should stay
  in the region (Articles filter pills, "Browse topic ideas", "Content plan") carry `data-content-local`. The Articles search is
  a GET form marked `data-content-form`; `_scripts.blade.php` turns its submit into the same navigation and restores the caret.
- `beforeSwap` lifts the incoming subtitle and header action into the stable header; the title and tabs are never replaced, no
  scroll is touched, and Back/Forward re-fetch the region. The router is mounted once (`region.__contentRouterBound`).
- Sections must not define their own `page-script` / `page-style`, and pagination links must not carry `fragment=1`
  (`SeoContentController::articlesView` appends everything but `page` and `fragment`).
- Sidebar: one `Content` leaf under SEO; the four sections are tabs only.

## Website Settings (hosted)

`studio/settings.blade.php` is the Domain callout (state from `Website::activePrimaryDomain()` and the domain rows: *Live* or
*Not live yet*) above two columns: *Manage your website* (page count, setup answers, current template, History - all links to
the canonical screens) and *Your website's look* (`studio/_look.blade.php`: brand colour, logo, hero, alt text and *Save changes*
to `website.look.update`, the same media pipeline as the Review screen). External-mode Businesses never reach it: the Studio
redirects them to the external overview.

## Deliberately not changed

Generation, publish/revision semantics, SEO / robots / sitemap, Content and Forms engines, Packages and
Questionnaire behaviour, domain provisioning, AI, entitlements, tenancy, payments, and Automations.
