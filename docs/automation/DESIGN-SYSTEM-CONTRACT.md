# Design System Contract — Milestone 1: Tokens, Component Library, Shared Chrome

**Status: DRAFT — CONTRACT PREPARATION ONLY. No visual implementation is authorized by this document.**

This contract authorizes drafting this one document only. It does not authorize any SCSS, Blade, JS, font, icon, or dependency change. A separate, explicit human instruction is required before any file named in §9 below may be created or modified. Merging this contract does not automatically start implementation — identical governance to every RFC-004/RFC-005 contract in this repository.

---

## 0. Governance

- Standalone design-system contract — not tied to an RFC number, per explicit instruction (this is a cross-cutting visual/UX initiative, not a business capability like RFC-004/RFC-005).
- Verified base SHA: `9fd554073` (`origin/main`, confirmed current at drafting time — includes the merged RFC-005 M3 Stripe billing implementation, PR #82).
- Branch: `chore/design-system-contract`, drafted in an isolated worktree (`../design-system-contract-worktree`), independent of any other in-progress branch.
- `maximum_correction_rounds: 2`, identical discipline to every other contract in this repository.
- No RFC document, no automation-state file, no other contract is modified by this document.
- **This is explicitly Milestone 1 of a multi-milestone initiative.** The locked requirement covers the *entire* customer and admin UI (360 Blade views at drafting time). Exhaustively and accurately allowlisting per-page adoption for all 360 views in one contract, without first proving the token/component pattern against real pages, would produce an allowlist neither this drafting pass nor any future implementation pass could trust — mirroring exactly why RFC-004 and RFC-005 were each split into M1–M4 / M1–M3 rather than drafted as one document. Milestone 1 delivers the complete, centralized foundation (tokens, component library, shared chrome, font/icon infrastructure) plus two fully-adopted reference pages (one customer, one admin) that prove the pattern end-to-end. Full page-by-page rollout across the remaining ~358 views is **explicitly deferred** to Milestone 2+ contracts, scoped by module, once Milestone 1's foundation is implemented, tested, and confirmed stable.

---

## 1. Mandatory preflight — verified

1. `git fetch origin` → `git worktree add -b chore/design-system-contract ../design-system-contract-worktree origin/main` — clean, no collision (`git branch --list`/`git ls-remote --heads origin` both empty for this branch name before creation).
2. Primary M3 implementation branch (`agent/rfc-005-m3`) confirmed untouched — this worktree is fully independent, based on `origin/main` (which already includes the merged M3 work).
3. `git status --short` in the new worktree — empty (clean checkout).

---

## 2. This contract's own exact file scope

This branch changes **exactly one file**: `docs/automation/DESIGN-SYSTEM-CONTRACT.md`. No SCSS, Blade, JS, font, icon, or `package.json`/`composer.json` file is touched by drafting this contract.

---

## 3. Mandatory repository audit — findings

All findings below are from direct repository inspection at the base SHA, this drafting pass.

### 3.1 Build stack and asset pipeline

- **Laravel Mix 6** (`webpack.mix.js`, `package.json`), not Vite. Compiles SCSS → CSS and bundles JS via `mix()`/webpack under the hood.
- **Bootstrap 5.1** (`package.json` `dependencies.bootstrap: "~5.1.0"`), jQuery 3.6, Popper.js, no Vue/React/Alpine in `package.json`.
- The compiled base template is a commercial theme — **Vuexy Bootstrap Laravel Admin Template** (confirmed by a commented-out `publicPath: '/demo/vuexy-bootstrap-laravel-admin-template-new/demo-2/'` in `webpack.mix.js`, and by the theme's own file/folder naming conventions throughout `resources/scss/`). RTL support, dark/bordered/semi-dark theme-variant CSS bundles, and a horizontal/vertical menu-type switch all ship as part of this base template.
- `webpack.mix.js` compiles **two parallel top-level SCSS entry points**, both ultimately loaded on every page (§3.4 confirms the load order):
  - `resources/scss/core.scss` → `public/css/core.css` — imports `base/bootstrap` → `base/bootstrap-extended` → `base/colors` → `base/components`, in that order. This is the Vuexy theme's own primary styling layer.
  - `resources/scss/overrides.scss` → `public/css/overrides.css` — the theme vendor's own designated "place your overrides here" file (13 lines currently, one dark-layout file-input rule).
  - `resources/assets/scss/style.scss` → `public/css/style.css` — a **second, separate** "place your own SCSS here" file (per its own header comment, attributed to the original Ultimate SMS/Codeglen vendor, not Vuexy), currently 61 lines (a `required` field-marker rule, switch-toggle SCSS variables).
  - Three theme-variant bundles (`dark-layout.scss`, `bordered-layout.scss`, `semi-dark-layout.scss`) and an RTL bundle (`custom-rtl.scss`, `style-rtl.scss`).

### 3.2 Centralized token source — already exists, partially

- **`resources/scss/base/bootstrap-extended/_variables.scss`** (643 lines) is the Vuexy theme's own Bootstrap-variable override layer, imported first in `core.scss`'s chain. It is genuinely the closest existing thing to a "centralized token file" in this repository:
  - `$purple: #7367f0 !default;` (line 24) → `$primary: $purple !default;` (line 30) — the single point every Bootstrap component (links, buttons, dropdowns, pagination, nav-tabs, form focus rings, badges, etc.) derives its primary-accent color from, via SCSS `!default` cascading.
  - `$font-family-sans-serif: 'Montserrat', Helvetica, Arial, serif !default;` / `$font-family-monospace: 'Montserrat', Helvetica, Arial, serif !default;` (lines 146–147) — the single point every Bootstrap typographic rule derives its font stack from.
  - Grid breakpoints already defined (`xs:0, sm:576px, md:768px, lg:992px, xl:1200px, xxl:1440px`) — the existing responsive foundation the "desktop/tablet/mobile" requirement can build on directly, no new breakpoint system needed.
- **`resources/assets/scss/variables/_variables.scss`** exists as a second variable file for `style.scss`'s own chain, but its own `$primary` line is commented out (`// $primary: #00cfe8;`) — it does not currently override anything; `style.scss`'s cascade-final position (§3.4) is a placeholder for such overrides, not currently exercised for color.
- **No existing centralized spacing/radius/shadow/motion token file** — Bootstrap's own defaults are used directly wherever `_variables.scss` doesn't override them (e.g., `$border-radius` is untouched from Bootstrap's own default at drafting time).
- Confirmed by direct grep: hardcoded inline hex colors in Blade view `style="..."` attributes are rare — exactly **one** match repository-wide (`resources/views/admin/settings/AllSettings/_background_jobs.blade.php`) — meaning the vast majority of existing styling already flows through the centralized SCSS/Bootstrap-class layer rather than being scattered inline. This substantially de-risks "changing a primary token later must update the entire application consistently" for Milestone 1's own scope.

### 3.3 Typography — current state

- **Montserrat**, loaded via a Google Fonts `<link>` (`https://fonts.googleapis.com/css2?family=Montserrat:...`) in exactly three master layout files: `resources/views/layouts/contentLayoutMaster.blade.php`, `detachedLayoutMaster.blade.php`, `fullLayoutMaster.blade.php`.
- **Geist Sans is not distributed via Google Fonts.** It ships as the npm package `geist` (static WOFF2 files) or can be self-hosted from Vercel's own font files. This is a genuine open technical decision this contract does not silently resolve — see §7 open item 1.

### 3.4 Layout and shared-chrome structure

- Seven master layout files in `resources/views/layouts/`: `contentLayoutMaster`, `detachedLayoutMaster`, `fullLayoutMaster`, `horizontalLayoutMaster`, `verticalLayoutMaster`, `horizontalDetachedLayoutMaster`, `verticalDetachedLayoutMaster` — the Vuexy theme's own layout-variant switcher (selected at runtime via `\App\Helpers\Helper::applClasses()`), shared identically across admin and customer portals (no separate admin-only/customer-only master layout exists).
- **`resources/views/panels/styles.blade.php`** is the single authoritative stylesheet-loading partial, `@include`d by every master layout's `<head>`. Confirmed exact cascade order (highest-priority/last-loaded at the bottom):
  1. Vendor CSS (`vendors.min.css` or RTL variant)
  2. `core.css` (compiled from `core.scss` — the primary Vuexy theme layer, §3.1/§3.2)
  3. Theme-variant CSS (dark/bordered/semi-dark layout)
  4. Toastr vendor + extension CSS
  5. Per-page CSS (`@yield('page-style')`)
  6. `overrides.css` (compiled from `overrides.scss`)
  7. `style.css` (compiled from `resources/assets/scss/style.scss`) — **the true final-cascade-authority stylesheet**, loaded last of all.
- **`resources/views/panels/*.blade.php`** — nine shared partials (`navbar`, `sidebar`, `footer`, `breadcrumb`, `submenu`, `horizontalMenu`, `horizontalSubmenu`, `styles`, `scripts`) included by every page through the master layouts. This is the highest-leverage, smallest file set in the entire application: polishing these nine files touches the chrome ("sidebar, navigation... polished") of literally every one of the 360 views without individually touching any of them.

### 3.5 Icon system — current state

- **Feather Icons**, loaded as an icon font (`resources/fonts/feather/` — `.eot`/`.svg`/`.ttf`/`.woff` + `iconfont.css`), invoked throughout Blade views via the `<i data-feather="icon-name"></i>` attribute convention, swapped to inline SVG at runtime by a `feather.replace()` JS call.
- Confirmed by direct grep: **222 of 360 Blade files** use `data-feather="..."`. Because every one of these 222 files shares the identical `data-feather`-attribute convention rather than each hand-authoring its own icon markup, the icon system is **already effectively centralized at the rendering-convention level** — the actual icon *library* (Feather → Lucide) can be swapped by changing how that one attribute convention is resolved (the runtime JS call, or a new centralized Blade component §9 introduces), without requiring 222 individual file edits in Milestone 1.
- **Lucide is directly relevant here, not coincidentally**: Lucide is a community-maintained continuation of the same Feather Icons visual family (MIT-licensed, forked from Feather in 2022 to keep the icon set actively maintained). This makes a Feather → Lucide migration unusually low-risk visually — the two sets share design lineage, not just "compatible style."
- Exact Lucide integration mechanism (self-hosted SVG sprite vs. a Composer/npm package) is an open technical decision — see §7 open item 2.

### 3.6 Existing Blade component reuse

- `resources/views/components/` exists but contains **exactly one** file: `switch-toggle.blade.php`. There is no existing reusable button/card/input/select/badge/table/tabs/menu/dialog/alert/pagination/empty-state component anywhere in this repository — the "reusable Blade/UI components" requirement is genuinely greenfield work, not a refactor of existing components.

### 3.7 Test-suite impact surface

- 232 test files total (`find tests -name "*.php"`). Confirmed by direct grep: **zero** test assertions depend on a specific Bootstrap/Vuexy CSS class (`assertSee('btn-...')`-shaped patterns, `->class()` checks) anywhere in the suite. Every HTTP-level test this engagement has written this session (RFC-005 M1–M3) and every pre-existing test asserts on **text content, route behavior, element IDs, and data attributes** — never on CSS class names or computed styles.
- **No Laravel Dusk, no browser/screenshot-based test tooling exists in this repository** (`composer.json` confirmed — no `laravel/dusk`; no `tests/Browser` directory). This directly shapes what a "visual-regression verification plan" can honestly promise for Milestone 1 (§10) — there is no existing automated pixel-diffing capability to lean on, and introducing one (e.g., BackstopJS, Percy) is itself a new dependency this contract does not authorize adding silently (§8).
- **Practical implication, confirmed by this same finding**: because no test depends on CSS classes or visual appearance, a correctly-scoped visual/token/component change is very unlikely to break the existing automated suite by itself — the real regression risk is in element IDs, `name=` attributes on form fields, `data-action-url`/`data-*` attributes, and rendered text content, all of which Milestone 1's own allowlist (§9) must preserve byte-for-byte in meaning even while restyling the markup around them.

---

## 4. Locked visual requirements (verbatim scope, from the human instruction)

Reproduced here as the contract's own governing checklist, not summarized, so Milestone 1's allowlist can be checked against it line by line:

- Warm layered backgrounds (not cold grey); white/off-white surfaces; restrained depth via borders, tonal layering, soft shadows; generous but efficient spacing; smooth navigation/hover/focus/pressed/dropdown/modal/loading transitions; calm typography hierarchy with readable line height; rounded cards/inputs/buttons/menus/overlays; thin rounded consistent icons; quiet default states with emphasis reserved for selected/primary actions; polished sidebar, navigation, tables, forms, pagination, empty states, alerts, tooltips, dialogs; responsive rhythm preserved at desktop/tablet/mobile.
- Design tokens: primary `#B5524C`, dark/active `#980E0E`, hover `#A83D38`, soft accent bg `#F4E6E4`, accent border `#E3C3BF`, canvas `#F7F6F2`, sidebar bg `#F2F0EB`, primary surface `#FFFFFF`, secondary surface `#FBFAF7`, neutral border `#E5E1DA`, primary text `#262522`, muted text `#6F6D67`.
- Typography: Geist Sans throughout; centralized type tokens for page titles, section headings, body, labels, captions, numeric values; comfortable line height; no oversized SaaS-dashboard type.
- Shape/spacing: 4px-based spacing scale; ~10px controls, 14px cards, 16–18px overlays; avoid excessive pill shapes; subtle shadows, borders/layering carry most depth.
- Motion: ~140ms immediate feedback, 180ms standard, 240ms overlays; `cubic-bezier(0.2, 0.8, 0.2, 1)`; animate opacity/transform/color, never layout-heavy properties; respect `prefers-reduced-motion`.
- Icons: audit Claude-like icons against an openly-licensed library; prefer Lucide or equivalent; closest semantic equivalents; never extract/trace/redistribute Anthropic's proprietary assets; centralize icon rendering for future global replacement.
- Legal/identity boundary: no Claude/Anthropic name, logo, wordmark, proprietary illustrations, or branded assets; no pixel-for-pixel reproduction; product must remain clearly identifiable as AI Business OS via its red palette, product name, logo, and information architecture.
- Architecture: centralize colors/typography/spacing/radii/shadows/motion/icon rules as design tokens; reusable Blade/UI components for buttons/cards/inputs/selects/badges/tables/tabs/menus/dialogs/alerts/pagination/empty-states; no page-specific copies of global styling; changing a primary token later must update the whole app consistently; preserve all existing behavior, authorization, routes, forms, accessibility semantics, and automated tests.

**Legal/identity boundary — self-check, drafting time.** Nothing in this contract's own scope, token set, or component plan references Anthropic, Claude, any Claude Desktop asset, wordmark, or proprietary illustration by name or by extracted/traced asset. The red accent palette (`#B5524C`/`#980E0E` family) and every visual token are supplied directly by the human instruction as this product's own identity, not derived from or copied out of any Anthropic asset. This contract explicitly forbids introducing any Anthropic/Claude-named file, asset, or string anywhere in its own allowlist (§12 mechanical search 1 enforces this at implementation time).

---

## 5. Exact Milestone 1 scope

Derived from §4's locked requirements, narrowed to what §0's phasing rationale supports doing safely and verifiably in one contract:

1. **A single centralized token layer** — colors, typography scale, spacing scale, radii, shadow scale, motion tokens — expressed both as SCSS variables (so Bootstrap's own component SCSS consumes them at compile time, exactly matching the existing `_variables.scss` mechanism, §3.2) and as CSS custom properties on `:root` (so new components and any future JS can consume them at runtime without a rebuild).
2. **Font infrastructure** — Geist Sans loading resolved and wired into the three master layouts that currently load Montserrat, plus the `$font-family-sans-serif`/`$font-family-monospace` token remap.
3. **Icon infrastructure** — a single centralized `<x-icon>` Blade component as the one rendering seam for icons going forward (§4's own "centralize icon rendering so the library can later be replaced globally"), backed by Lucide, with the exact backing mechanism resolved per §7 open item 2. Existing `data-feather` call sites are **not** touched in Milestone 1 (222 files, explicitly deferred — §0) — this item ships the seam, not the full migration.
4. **A reusable Blade/UI component library** — button, card, input, select, badge, table, tabs, menu (dropdown), dialog (modal), alert, pagination, empty-state, tooltip — covering every component category §4 names, built against the token layer, following Laravel's own Blade component convention (`resources/views/components/*.blade.php`, `<x-name>` auto-discovery — the same mechanism the existing `switch-toggle.blade.php` already uses).
5. **Shared-chrome polish** — the nine `panels/*.blade.php` partials (navbar, sidebar, footer, breadcrumb, submenu, horizontal menu/submenu) restyled against the new tokens/components, since these are shared by literally every page (§3.4) and are explicitly named in §4 ("polished sidebar, navigation").
6. **Motion infrastructure** — the three named durations, the named easing curve, and a `prefers-reduced-motion` guard, expressed as reusable CSS custom properties/utility classes new components consume, applied to the shared chrome's own existing hover/dropdown/modal transitions.
7. **Two adopted reference pages, one per portal** — proving the full token → component → page pattern end-to-end before any further page is touched:
   - Customer: `resources/views/customer/business/usage-billing/show.blade.php` (the newest, most representative AI Business OS customer surface, built in this same engagement).
   - Admin: `resources/views/admin/usage-billing/provider-events/index.blade.php` (the newest, most representative AI Business OS admin surface, same engagement).
8. **Responsive verification**, using the existing Bootstrap breakpoints (§3.2) — no new breakpoint system.

---

## 6. Explicit Milestone 1 exclusions

Excluded from this contract, deferred to Milestone 2+ (a future, separately-drafted and separately-authorized contract):

- Page-by-page adoption of the token/component layer across the remaining **~358** Blade views (every module: SMS, voice, MMS, WhatsApp, Viber, OTP, reports, automations, chat-box, campaigns, plugins, every other admin/customer screen not named in §5 item 7).
- The full `data-feather` → `<x-icon>` migration across all 222 existing call sites (Milestone 1 ships the component seam only, per §5 item 3).
- Introducing any automated visual-regression/screenshot-diffing tool (BackstopJS, Percy, Dusk, or equivalent) — a worthwhile future enhancement, explicitly not silently added as a side effect of this contract (§3.7, §10).
- Dark/bordered/semi-dark theme-variant bundle updates (`dark-layout.scss`, `bordered-layout.scss`, `semi-dark-layout.scss`) — these remain on the pre-existing palette in Milestone 1; reconciling them with the new token set (especially the dark-theme variant, which likely needs its own dark-appropriate token mapping, not a blind reuse of the light tokens §4 specifies) is Milestone 2+ scope, named here so it is not silently forgotten.
- RTL bundle updates (`custom-rtl.scss`, `style-rtl.scss`) — same reasoning, explicitly deferred, named so it is not silently forgotten.
- Any change to `EntitlementManager.php`, RFC-004/RFC-005 business logic, routes, controllers, authorization rules, or any non-visual behavior of any kind. This is a pure presentation-layer contract — no `.php` file outside `resources/views/**/*.blade.php` is authorized to change, with the sole exception of the two new/near-empty helper classes named in §9 if the icon-component mechanism requires one (resolved exactly, not "TBD," at implementation time per §7 item 2's own resolution).
- Any change to `EntitlementManager.php`'s own 15 denial-key strings or any RFC-004/RFC-005 mechanical-search invariant already locked by a prior contract in this repository.

---

## 7. Open technical decisions (category-3, resolved at implementation time within the constraints below — never silently guessed)

1. **Geist Sans distribution mechanism.** Recommended default: self-host via the `geist` npm package's static WOFF2 files (already a supported, official distribution channel for this exact font), copied into `resources/fonts/geist/` and served via `@font-face` in the new typography token file — never a Google Fonts substitution of a visually-different font under the "Geist Sans" name. If the `geist` npm package is unavailable or its license terms conflict with this repository's own distribution model, implementation must stop and report rather than silently substitute a different typeface.
2. **Lucide integration mechanism.** Recommended default: vendor the specific Lucide SVG icon files this contract's own icon audit (§9, mechanical search 3) determines are needed, into `resources/vendor/lucide/`, resolved by the new `<x-icon>` Blade component via a simple name → file lookup (no new PHP package dependency, no new JS runtime icon-replacement library beyond what already exists for Feather's own `feather.replace()` pattern, which the new component replaces rather than extends). If a maintained, appropriately-licensed Lucide-for-Laravel/Blade package exists and is a better fit, implementation may propose it instead — but must state the package name, license, and version explicitly in its own implementation report, never silently vendor an unverified source.
3. **Exact numeric type scale, spacing scale, radius scale, and shadow scale values.** §4 specifies target *ranges* (10px controls, 14px cards, 16–18px overlays; 4px-based spacing) rather than a complete enumerated scale. Implementation must derive the complete scale from these anchors using the existing 4px base unit consistently (e.g., 4/8/12/16/20/24/32/40/48px spacing steps; a small/medium/large radius tier landing on or near 10/14/18px) and state the derived scale explicitly and completely in its own implementation report — never leave any token's exact value implicit or inconsistent between files.

---

## 8. Dependency boundary

- **No new Composer package** is authorized by this contract unless §7 item 2's resolution genuinely requires one, in which case it must be named, licensed, and justified explicitly in the implementation report before `composer.json` is touched — mirroring this repository's own "every migration must be justified" discipline extended to dependencies.
- **No new npm package** beyond what §7 items 1–2 resolve to (at most: `geist` for font files, and only if the vendored-SVG path for Lucide is rejected in favor of an actual npm-distributed icon package) — `package.json` changes, if any, must be the minimum needed and stated explicitly.
- **Laravel Mix, not Vite** — this contract does not authorize a build-tool migration. All new SCSS/CSS is compiled through the existing `webpack.mix.js` pipeline, extended additively (new `mix.sass(...)` entries or new files folded into the existing `core.scss`/`style.scss` import chains), never replacing the existing pipeline.

---

## 9. Exact implementation allowlist (Milestone 1)

**Closed, numbered, path-level. Any additional path required during implementation is a STOP-and-report condition (§13).**

### New centralized token files (7 new)

1. `resources/scss/base/tokens/_colors.scss` — the 12 locked hex tokens (§4) as SCSS variables, plus a `:root { --color-*: ...; }` CSS custom-property emission block; remaps `$primary`/`$purple`, `$body-bg`, `$border-color`, and every other Bootstrap color variable `bootstrap-extended/_variables.scss` currently derives from the old palette.
2. `resources/scss/base/tokens/_typography.scss` — Geist Sans `@font-face` declarations (or an `@import` of the vendored font CSS, per §7 item 1's resolution), the derived type scale (page title/section heading/body/label/caption/numeric — §7 item 3), remaps `$font-family-sans-serif`/`$font-family-monospace`.
3. `resources/scss/base/tokens/_spacing.scss` — the derived 4px-based spacing scale (§7 item 3), as SCSS variables and CSS custom properties.
4. `resources/scss/base/tokens/_radii.scss` — the derived radius scale (§7 item 3), remaps `$border-radius`/`$border-radius-sm`/`$border-radius-lg`.
5. `resources/scss/base/tokens/_shadows.scss` — the soft-shadow scale (§4 "restrained depth... very soft shadows"), remaps `$box-shadow`/`$box-shadow-sm`/`$box-shadow-lg`.
6. `resources/scss/base/tokens/_motion.scss` — the three named durations, the named easing curve, as CSS custom properties; a `@media (prefers-reduced-motion: reduce)` guard collapsing all token-driven transition/animation durations to near-zero.
7. `resources/scss/base/tokens.scss` — aggregator importing items 1–6 in dependency order (colors → typography → spacing → radii → shadows → motion).

### Modified existing SCSS files (4 modified, all narrow/additive)

8. `resources/scss/core.scss` — modified: one new `@import './base/tokens';` line, positioned before `base/bootstrap-extended` so Bootstrap's own SCSS consumes the token values. No existing import line removed or reordered relative to each other.
9. `resources/scss/base/bootstrap-extended/_variables.scss` — modified: `$primary`/`$purple` and the font-family variables (§3.2) now reference the new token file's variables instead of their own hardcoded values; `$border-radius*`/`$box-shadow*` likewise. No unrelated variable in this 643-line file changes.
10. `resources/assets/scss/style.scss` — modified: token-derived overrides only where the cascade-final position (§3.4) genuinely requires a late override; no unrelated existing rule in this file changes.
11. `resources/scss/overrides.scss` — modified: same narrow scope as item 10, only if a specific existing rule in this 13-line file conflicts with the new tokens; if no conflict exists, this file is confirmed unmodified in the implementation report rather than touched speculatively.

### Icon infrastructure (1–2 new, exact count resolved by §7 item 2)

12. `resources/views/components/icon.blade.php` — new: the centralized `<x-icon name="..." size="..." />` component.
13. *(Conditional on §7 item 2's resolution)* the vendored Lucide SVG asset directory (`resources/vendor/lucide/*.svg`, exact file list determined by mechanical search 3, §12) — counted and named exhaustively in the implementation report, not as a single opaque "assets" line item.

### New reusable Blade/UI component library (13 new)

14. `resources/views/components/button.blade.php`
15. `resources/views/components/card.blade.php`
16. `resources/views/components/input.blade.php`
17. `resources/views/components/select.blade.php`
18. `resources/views/components/badge.blade.php`
19. `resources/views/components/table.blade.php`
20. `resources/views/components/tabs.blade.php`
21. `resources/views/components/menu.blade.php`
22. `resources/views/components/dialog.blade.php`
23. `resources/views/components/alert.blade.php`
24. `resources/views/components/pagination.blade.php`
25. `resources/views/components/empty-state.blade.php`
26. `resources/views/components/tooltip.blade.php`

### Shared-chrome polish (9 modified)

27. `resources/views/panels/navbar.blade.php`
28. `resources/views/panels/sidebar.blade.php`
29. `resources/views/panels/footer.blade.php`
30. `resources/views/panels/breadcrumb.blade.php`
31. `resources/views/panels/submenu.blade.php`
32. `resources/views/panels/horizontalMenu.blade.php`
33. `resources/views/panels/horizontalSubmenu.blade.php`
34. `resources/views/panels/styles.blade.php` — modified: exactly the new `<link>`/`@font-face` wiring items 1–7 require; no existing stylesheet `<link>` removed or reordered relative to each other.
35. `resources/views/panels/scripts.blade.php` — modified: only if the new icon component (item 12) requires removing the old `feather.replace()` call; if Feather's own runtime call is left in place for the still-untouched 222 legacy call sites (likely, given §6's own deferral), this file is confirmed unmodified in the implementation report rather than touched speculatively.

### Master layout font wiring (3 modified)

36. `resources/views/layouts/contentLayoutMaster.blade.php` — modified: Montserrat `<link>` replaced with the resolved Geist Sans loading mechanism (§7 item 1).
37. `resources/views/layouts/detachedLayoutMaster.blade.php` — modified: same.
38. `resources/views/layouts/fullLayoutMaster.blade.php` — modified: same.

### Reference pages (2 modified)

39. `resources/views/customer/business/usage-billing/show.blade.php` — modified: adopts the new components/tokens throughout, preserving every existing element `id`, form field `name`, `data-action-url`/`data-*` attribute, and rendered text string the existing test suite (§3.7, and specifically `NoFakePaymentControlsRenderedTest.php`, `UsageBillingDashboardStripeIntegrationTest.php`) depends on.
40. `resources/views/admin/usage-billing/provider-events/index.blade.php` — modified: same discipline.

### Composer/npm (0–2, exact count resolved by §7)

41. *(Conditional)* `package.json` — modified only if §7 item 1 or item 2 resolves to a new npm dependency; exact package name/version stated in the implementation report.
42. *(Conditional)* `composer.json` — modified only if §7 item 2 resolves to a new Composer package; exact package name/version/license stated in the implementation report.

**Counts:** 7 new token files + 1–2 new icon-infrastructure files + 13 new component files + 2 reference-page modifications = **23–24 new/modified presentation files** at minimum, plus 4 modified SCSS integration files + 9 modified shared-chrome files + 3 modified master-layout files = 16 further modified files, plus 0–2 conditional dependency-manifest files. **Total: 39–42 paths**, exact final count stated in the implementation report once §7's conditional items resolve. Any path beyond this list is a required-114th-path-shaped stop condition (§13), identical in kind to every prior contract's own discipline in this repository.

---

## 10. Visual-regression verification plan

No automated visual-diffing tool exists in this repository today (§3.7), and this contract does not authorize adding one (§6, §8). The verification plan for Milestone 1 is therefore a combination of automated behavioral proof and a structured manual visual review:

**Automated (must pass, zero tolerance):**
1. The full existing test suite (`php artisan test`) must pass with an unchanged pass count for every test file not named in §9 — proving zero behavioral regression from the presentation-layer change.
2. `tests/Feature/Usage/NoFakePaymentControlsRenderedTest.php` and `tests/Feature/Usage/UsageBillingDashboardStripeIntegrationTest.php` specifically re-run and pass unmodified — these are the two tests with the deepest assertion coverage against the exact reference page (item 39) this milestone restyles.
3. A new mechanical search (§12) confirms no element `id`, form field `name`, or `data-*` attribute referenced by any existing test was renamed or removed from either reference page.

**Manual (structured, screenshot-evidenced in the implementation report):**
4. Both reference pages (items 39–40), captured at three widths matching the existing Bootstrap breakpoints (§3.2) — **375px** (mobile, below `sm`), **768px** (tablet, `md`), **1440px** (desktop, `xxl`) — in both light-mode-only (Milestone 1 does not touch the dark-theme bundle, §6) states: default and, where applicable, an interactive state (hover on a primary button, an open dropdown menu, an open dialog).
5. A token-conformance checklist: for each of the 12 locked colors, confirm by direct CSS inspection (not visual judgement alone) that it is applied via the token file (item 1) and not hardcoded a second time anywhere in items 14–40.
6. A component-conformance checklist: for each of the 13 new components (items 14–26), confirm it is actually used at least once on one of the two reference pages (proving it is not dead code) and that it respects `prefers-reduced-motion` (§9 item 6) by direct testing with the OS/browser setting enabled.
7. An accessibility spot-check: keyboard-only navigation through both reference pages' new interactive components (buttons, menu, dialog, tabs) confirming focus is visible and logically ordered, and that no existing `aria-*`/semantic HTML attribute was removed in the restyle (§6's own "preserve... accessibility semantics" requirement).
8. Explicit confirmation, stated affirmatively in the implementation report (never merely absence of contrary evidence, matching this repository's own established M3-preview-report convention): no Anthropic/Claude name, logo, wordmark, or asset appears anywhere in the diff.

**Deferred to Milestone 2+ (named here so it is not silently forgotten, §6):** introducing an automated screenshot-diffing tool once a stable Milestone 1 baseline exists to diff against.

---

## 11. Test contract

- No new automated test file is authorized by Milestone 1's own allowlist — this is a pure presentation-layer contract, and per §3.7's own finding, the existing suite's assertions do not depend on styling. Re-running the existing suite unmodified is the test contract, not writing new ones.
- If implementation discovers a genuine gap (e.g., an existing test that *does* implicitly depend on now-changed markup structure in a way this audit's mechanical searches did not catch), that is itself a stop-and-report condition (§13), identical in kind to the M1-boundary-test discoveries this repository's own RFC-005 M3 engagement already encountered and resolved via direct human authorization rather than silent workaround.

---

## 12. Mechanical searches

Run from repository root once implementation exists, against the two reference pages and the new token/component files:

1. `grep -rniE "anthropic|claude" resources/scss/base/tokens resources/views/components resources/views/panels resources/views/layouts/*LayoutMaster.blade.php resources/views/customer/business/usage-billing/show.blade.php resources/views/admin/usage-billing/provider-events/index.blade.php` → zero matches (§4's own legal/identity boundary, enforced mechanically, not by memory alone).
2. `grep -c "^\$primary:\|^\$purple:" resources/scss/base/bootstrap-extended/_variables.scss` cross-checked against `resources/scss/base/tokens/_colors.scss` → the color value flows from the new token file, never duplicated as a second hardcoded hex literal.
3. `grep -o 'data-feather="[a-z-]*"' resources/views/**/*.blade.php | sort -u` → the exact, exhaustive list of distinct Feather icon names actually in use repository-wide, used to determine item 13's exact vendored-SVG file list (never guessed).
4. `git diff --stat -- app database routes` (against this milestone's own base) → empty — confirms zero non-presentation-layer file touched, per §6's own exclusion of all business-logic/routing/authorization changes.
5. `git diff --stat -- resources/scss/base/themes resources/scss/base/custom-rtl.scss resources/assets/scss/style-rtl.scss` → empty — confirms the dark/bordered/semi-dark and RTL bundles are genuinely untouched (§6).
6. Final changed-path set (`git diff --name-only` + `git ls-files --others --exclude-standard`) equals §9's exact allowlist.
7. `php artisan test` full-suite pass count compared against the pre-implementation baseline, reported exactly, never estimated.

---

## 13. Stop conditions

Future implementation must stop, leave the working tree unstaged, and report rather than proceed, if:

- Any path beyond §9's allowlist is required.
- Geist Sans cannot be obtained through a licensed, legitimate distribution channel (§7 item 1) — implementation must not silently substitute a visually-different font under the same name.
- No appropriately-licensed Lucide source can be resolved (§7 item 2) — implementation must not silently fall back to extracting icons from an unlicensed or proprietary source.
- Any existing test fails for a reason not fixable within this milestone's own allowlist.
- Any change to `app/`, `database/`, `routes/`, or any non-`resources/views`/non-`resources/scss`/non-`resources/assets/scss` path appears necessary.
- The dark-theme or RTL bundles appear to require touching to keep the application visually coherent (a real possibility once the light-mode palette changes — named explicitly here as the most likely trigger for this stop condition, not a hypothetical one).
- Any Anthropic/Claude name, logo, wordmark, or proprietary asset is found necessary to reference, extract, or approximate pixel-for-pixel.

---

## 14. Contract self-audit

1. **No implementation is authorized** — stated in the document's own opening line and restated in §0, §2, §8.
2. **Every locked requirement in §4 is traced to at least one §9 allowlist item or an explicit §6/§7 deferral** — spacing/radii/shadows/motion → items 1–7; typography → items 2, 36–38; icons → items 12–13; components → items 14–26; chrome → items 27–35; reference proof → items 39–40; dark/RTL variants → explicitly deferred (§6); full-app rollout → explicitly deferred (§0/§6).
3. **No open decision is silently resolved** — §7's three items each state a recommended default, the exact fallback behavior if that default fails, and an explicit stop condition (§13) rather than a guess.
4. **No new dependency is silently added** — §8 states the exact boundary and requires explicit naming/licensing/justification in the implementation report for any conditional addition.
5. **The phasing rationale is stated, not assumed** — §0's own paragraph explains why "everything" is not attempted as one allowlist, citing this repository's own RFC-004/RFC-005 M1–M4 precedent directly.
6. **Test-suite impact is evidence-based, not assumed** — §3.7's own direct grep confirms zero CSS-class-dependent test assertions exist, which is what makes §10/§11's verification plan honest rather than optimistic.
7. **The legal/identity boundary is self-checked at drafting time and mechanically enforced at implementation time** — §4's own closing paragraph plus §12 mechanical search 1.
8. **Every path in §9 is individually numbered** — 1–42 (with items 13, 41, 42 explicitly conditional and exactly resolved, not left vague), matching this repository's own numbered-allowlist convention exactly.
9. **The verification plan is honest about current tooling limits** — §10 states plainly that no automated visual-diffing tool exists, rather than implying one will be used when it will not.
10. **No production/test file changed by drafting this contract** — confirmed by §15's own verification commands before staging.

---

## 15. Verification and publication (this document only)

- `git diff --check` — clean.
- `git status --short` — exactly `?? docs/automation/DESIGN-SYSTEM-CONTRACT.md`.
- `git diff --cached --name-only` — empty before staging.
- Stage the one file by its exact path only (never `git add -A`/`git add .`).
- Commit exactly: `docs: prepare design system contract`.
- Push normally to `origin chore/design-system-contract`. No force push. Do not push `main`.
- If `gh` is available, open a PR into `main`. Otherwise report the exact GitHub comparison URL.
- PHP/JS tests are not required for this one-file docs-only change and are not run — reported honestly as not run, no count fabricated.

---

*End of Design System Contract, Milestone 1. Implementation requires a separate, explicit human instruction. This contract's own merge does not start or resume it.*
